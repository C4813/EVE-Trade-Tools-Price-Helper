<?php
if (!defined('ABSPATH')) exit;

class ETT_Jobs {
	const JOB_RETENTION_DAYS = 90;

    private static function debug_log(string $msg) : void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log($msg);
        }
    }

    private static function get_batch_limits() : array {
    	$pages   = (int)get_option(ETT_Admin::OPT_BATCH_MAX_PAGES, 5);
    	$seconds = (int)get_option(ETT_Admin::OPT_BATCH_MAX_SECONDS, 25);
    
    	if ($pages < 1) $pages = 1;
    	if ($pages > 50) $pages = 50;
    
    	if ($seconds < 1) $seconds = 1;
    	if ($seconds > 25) $seconds = 25;
    
    	return [$pages, (float)$seconds];
    }

	public static function init_ajax() {
		add_action('wp_ajax_ett_job_start', [__CLASS__, 'ajax_start']);
		add_action('wp_ajax_ett_job_step', [__CLASS__, 'ajax_step']);
		add_action('wp_ajax_ett_job_status', [__CLASS__, 'ajax_status']);
		add_action('wp_ajax_ett_job_cancel', [__CLASS__, 'ajax_cancel']);
		add_action('wp_ajax_ett_job_active', [__CLASS__, 'ajax_active']);
		add_action('wp_ajax_ett_job_history', [__CLASS__, 'ajax_history']);
		add_action('wp_ajax_ett_esi_status', [__CLASS__, 'ajax_esi_status']);
	}

	public static function ajax_esi_status() {
		if (!current_user_can('manage_options')) wp_send_json_error('Forbidden', 403);
		check_ajax_referer('ett_admin');
		self::send_no_cache();

		$st = ETT_ESI::meta_status();

		wp_send_json_success([
			'overall' => $st['overall'],
			'color' => $st['color'],
			'note' => $st['note'],
		]);
	}

	private static function send_no_cache() {
        if (function_exists('nocache_headers')) nocache_headers();
        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
        }
	}

	public static function ajax_start() {
		if (!current_user_can('manage_options')) wp_send_json_error('Forbidden', 403);
		check_ajax_referer('ett_admin');
		self::send_no_cache();

		$job_type = sanitize_key($_POST['job_type'] ?? '');
		if (!in_array($job_type, ['typeids', 'prices', 'history', 'contracts'], true)) wp_send_json_error('Bad job_type', 400);

		// prices_only=1 means: run prices but do NOT auto-create a history job on completion.
		$prices_only = ($job_type === 'prices' && !empty($_POST['prices_only']));
		// history_only=1 means: run history but do NOT auto-create a contracts job on completion
		// (used when History is started standalone, not as part of Fetch All's prices -> history -> contracts chain).
		$history_only = ($job_type === 'history' && !empty($_POST['history_only']));

		ETT_ExternalDB::ensure_schema();
		$pdo = ETT_ExternalDB::pdo();

        if ($job_type === 'prices') {
        	$stmt = $pdo->query("
        		SELECT job_id
        		FROM ett_jobs
        		WHERE job_type='prices' AND status IN ('queued','running')
        		ORDER BY started_at DESC
        		LIMIT 1
        	");
        	$active = $stmt ? $stmt->fetchColumn() : false;
        	if ($active) {
        		wp_send_json_error('A prices job is already running', 409);
        	}
        }

        if ($job_type === 'history') {
        	$stmt = $pdo->query("
        		SELECT job_id
        		FROM ett_jobs
        		WHERE job_type='history' AND status IN ('queued','running')
        		ORDER BY started_at DESC
        		LIMIT 1
        	");
        	$active = $stmt ? $stmt->fetchColumn() : false;
        	if ($active) {
        		wp_send_json_error('A history job is already running', 409);
        	}
        }

        if ($job_type === 'contracts') {
        	$stmt = $pdo->query("
        		SELECT job_id
        		FROM ett_jobs
        		WHERE job_type='contracts' AND status IN ('queued','running')
        		ORDER BY started_at DESC
        		LIMIT 1
        	");
        	$active = $stmt ? $stmt->fetchColumn() : false;
        	if ($active) {
        		wp_send_json_error('A contracts job is already running', 409);
        	}
        }

        $job_id = self::create_job($pdo, $job_type, 'browser', $prices_only, $history_only);

		wp_send_json_success([
			'job_id' => $job_id,
		]);
	}

	public static function ajax_step() {
		if (!current_user_can('manage_options')) wp_send_json_error('Forbidden', 403);
		check_ajax_referer('ett_admin');
		self::send_no_cache();

		$job_id = sanitize_text_field(wp_unslash($_POST['job_id'] ?? ''));
		if ($job_id === '') wp_send_json_error('Missing job_id', 400);

		$pdo = ETT_ExternalDB::pdo();
		$job = self::get_job($pdo, $job_id);
		if (!$job) wp_send_json_error('Job not found', 404);

		if (in_array($job['status'], ['done', 'error', 'cancelled'], true)) {
			wp_send_json_success([
				'status' => $job['status'],
				'progress' => json_decode($job['progress_json'], true),
				'last_error' => $job['last_error'],
			]);
		}

		self::update_status($pdo, $job_id, 'running');
		$progress = json_decode($job['progress_json'], true) ?: [];

        try {
                    [$max_pages_per_call, $max_call_seconds] = self::get_batch_limits();
                    $deadline = microtime(true) + $max_call_seconds;
        
                    $pages_done = 0;

            do {
            	if ($job['job_type'] === 'typeids') {
            		$progress = self::step_typeids($pdo, $progress);
            	} elseif ($job['job_type'] === 'history') {
            		$progress = self::step_history($pdo, $progress);
            	} elseif ($job['job_type'] === 'contracts') {
            		$progress = self::step_contracts($pdo, $progress);
            	} else {
            		$progress = self::step_prices($pdo, $progress, $job_id);
            	}
            
            	$hb = self::heartbeat($pdo, $job_id, $progress);
            	$pages_done++;
            
            	// stop if finished
            	if (($progress['phase'] ?? '') === 'done') break;
            
            	// stop if backoff active (rate limit or any sleep)
            	$sleep_until = (int)($progress['sleep_until'] ?? 0);
            	if ($sleep_until > time()) break;
            
            } while ($pages_done < $max_pages_per_call && microtime(true) < $deadline);
            
            // Record batch info without corrupting last_msg
            if (!isset($progress['details']) || !is_array($progress['details'])) $progress['details'] = [];
            $progress['details']['batch'] = [
            	'pages_done' => (int)$pages_done,
            	'max_pages'  => (int)$max_pages_per_call,
            	'max_seconds'=> (float)$max_call_seconds,
            ];
            $hb = self::heartbeat($pdo, $job_id, $progress);

			if (($progress['phase'] ?? '') === 'done') {
				self::finish($pdo, $job_id, 'done', $progress);
				wp_send_json_success(['status' => 'done', 'progress' => $progress, 'heartbeat_at' => $hb]);
			}

			wp_send_json_success(['status' => 'running', 'progress' => $progress, 'heartbeat_at' => $hb]);
		} catch (\Throwable $e) {
			$progress = is_array($progress) ? $progress : [];
			$progress['phase'] = 'error';
			$progress['last_msg'] = 'Error: ' . $e->getMessage();
			$progress['error'] = [
				'message' => $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine(),
			];

			self::finish($pdo, $job_id, 'error', $progress, $e->getMessage());

			wp_send_json_error([
				'status' => 'error',
				'message' => $e->getMessage(),
				'progress' => $progress,
			], 500);
		}
	}

	public static function ajax_active() {
		if (!current_user_can('manage_options')) wp_send_json_error('Forbidden', 403);
		check_ajax_referer('ett_admin');
		self::send_no_cache();

		if (!ETT_ExternalDB::is_configured()) {
			wp_send_json_success(['job' => null]);
		}

		$pdo = ETT_ExternalDB::pdo();

		$stmt = $pdo->query("
			SELECT *
			FROM ett_jobs
			WHERE status IN ('queued','running')
			ORDER BY started_at DESC
			LIMIT 1
		");
		$job = $stmt->fetch();

		if (!$job) {
			wp_send_json_success(['job' => null]);
		}

		wp_send_json_success(['job' => [
			'job_id' => $job['job_id'],
			'job_type' => $job['job_type'],
			'status' => $job['status'],
			'started_at' => $job['started_at'],
			'heartbeat_at' => $job['heartbeat_at'],
			'progress' => json_decode($job['progress_json'], true),
			'last_error' => $job['last_error'],
		]]);
	}

	public static function ajax_history() {
		if (!current_user_can('manage_options')) wp_send_json_error('Forbidden', 403);
		check_ajax_referer('ett_admin');
		self::send_no_cache();

		if (!ETT_ExternalDB::is_configured()) {
			wp_send_json_success(['rows' => []]);
		}

		$limit = isset($_GET['limit']) ? absint(wp_unslash($_GET['limit'])) : 25;
		if ($limit < 1) $limit = 1;
		if ($limit > 100) $limit = 100;

		try {
			$pdo = ETT_ExternalDB::pdo();
            $sql = "
            	SELECT job_id, job_type, status, started_at, finished_at, heartbeat_at, last_error, progress_json
            	FROM ett_jobs
            	WHERE job_type IN ('prices','history')
            	ORDER BY started_at DESC
            	LIMIT " . (int)$limit;
            
            $rows = $pdo->query($sql)->fetchAll() ?: [];

			$out = [];
			foreach ($rows as $r) {
				$prog = [];
				try {
					$prog = json_decode($r['progress_json'] ?? '', true) ?: [];
				} catch (\Throwable $e) {
				}

				$out[] = [
					'job_id' => $r['job_id'] ?? '',
					'job_type' => $r['job_type'] ?? 'prices',
					'status' => $r['status'] ?? '',
					'started_at' => $r['started_at'] ?? '',
					'finished_at' => $r['finished_at'] ?? '',
					'heartbeat_at' => $r['heartbeat_at'] ?? '',
					'last_error' => $r['last_error'] ?? '',
					'driver' => $prog['driver'] ?? 'browser',
					'last_msg' => $prog['last_msg'] ?? '',
				];
			}

			wp_send_json_success(['rows' => $out]);
		} catch (\Throwable $e) {
			wp_send_json_success(['rows' => [], 'note' => $e->getMessage()]);
		}
	}

	public static function ajax_status() {
		if (!current_user_can('manage_options')) wp_send_json_error('Forbidden', 403);
		check_ajax_referer('ett_admin');
		self::send_no_cache();

		$job_id = sanitize_text_field(wp_unslash($_GET['job_id'] ?? ''));
		if ($job_id === '') wp_send_json_error('Missing job_id', 400);

		$pdo = ETT_ExternalDB::pdo();
		$job = self::get_job($pdo, $job_id);
		if (!$job) wp_send_json_error('Job not found', 404);

		wp_send_json_success([
			'status' => $job['status'],
			'heartbeat_at' => $job['heartbeat_at'],
			'progress' => json_decode($job['progress_json'], true),
			'last_error' => $job['last_error'],
		]);
	}

	public static function ajax_cancel() {
		if (!current_user_can('manage_options')) wp_send_json_error('Forbidden', 403);
		check_ajax_referer('ett_admin');
		self::send_no_cache();

		$job_id = sanitize_text_field(wp_unslash($_POST['job_id'] ?? ''));
		if ($job_id === '') wp_send_json_error('Missing job_id', 400);

		$pdo = ETT_ExternalDB::pdo();
		$job = self::get_job($pdo, $job_id);
		if (!$job) wp_send_json_error('Job not found', 404);

		$progress = json_decode($job['progress_json'], true) ?: [];
		$progress['phase'] = 'cancelled';
		$progress['last_msg'] = 'Cancelled by user';
        self::finish($pdo, $job_id, 'cancelled', $progress);
        
        wp_send_json_success(['status' => 'cancelled']);

	}


	private static function step_typeids(PDO $pdo, array $progress) : array {
		$selected_groups = get_option(ETT_Admin::OPT_SELECTED_GROUPS, []);
		$count = ETT_TypeIDs::generate($pdo, $selected_groups);

		$progress['phase'] = 'done';
		$progress['last_msg'] = "Generated {$count} typeIDs";
		$progress['details'] = [
			'generated_typeids' => $count,
		];

		return $progress;
	}

	private static function step_prices(PDO $pdo, array $progress, string $job_id) : array {
		$hubs = ETT_Admin::hubs();

		$selected_hubs = get_option(ETT_Admin::OPT_SELECTED_HUBS, array_keys($hubs));
		$selected_hubs = array_values(array_intersect($selected_hubs, array_keys($hubs)));
		// Intentionally no fallback here — if the user has deselected all standard hubs,
		// respect that. Private hubs (appended below) may still produce a valid run.

		$secondary_map = get_option(ETT_Admin::OPT_SECONDARY_STRUCTURES, []);
		if (!is_array($secondary_map)) $secondary_map = [];

		$tertiary_map = get_option(ETT_Admin::OPT_TERTIARY_STRUCTURES, []);
		if (!is_array($tertiary_map)) $tertiary_map = [];

		$secondary_pairs = ETT_Admin::secondary_pairs();

		// Private hubs: build a list of hub entries keyed by 'private_hub_N'
		$private_hub_configs = ETT_Admin::get_private_hubs();
		$private_hubs_map    = []; // hub_key => ['system_name','system_id','region_id','structure_ids','char_source','hub_index']
		foreach ($private_hub_configs as $ph) {
			$idx         = (int) ($ph['hub_index'] ?? 0);
			$system_id   = (int) ($ph['system_id'] ?? 0);
			$system_name = (string) ($ph['system_name'] ?? '');
			$region_id   = (int) ($ph['region_id'] ?? 0);
			$structures  = is_array($ph['structures'] ?? null) ? $ph['structures'] : [];
			$enabled_ids = [];
			foreach ($structures as $st) {
				if (!empty($st['enabled']) && !empty($st['id'])) {
					$enabled_ids[] = (int) $st['id'];
				}
			}
			if ($idx <= 0 || $system_id <= 0 || $region_id <= 0 || empty($enabled_ids)) continue;
			$hub_key = 'private_hub_' . $idx;
			$private_hubs_map[$hub_key] = [
				'hub_index'      => $idx,
				'system_name'    => $system_name,
				'system_id'      => $system_id,
				'region_id'      => $region_id,
				'structure_ids'  => $enabled_ids,
				'char_source'    => (string) ($ph['char_source'] ?? 'primary'),
			];
		}

		if (($progress['phase'] ?? '') === 'init') {
			$type_count = ETT_TypeIDs::count($pdo);
			if ($type_count <= 0) throw new Exception('No generated typeIDs found. Run "Generate TypeIDs" first.');

			$type_ids = ETT_TypeIDs::all($pdo);
			set_transient('ett_typeids_' . $job_id, $type_ids, 6 * HOUR_IN_SECONDS);

			// Prices are written to ett_prices_staging during the run and atomically swapped
			// into ett_prices only on successful completion. This means:
			//   - The reprocess tool always reads from a complete, consistent snapshot.
			//   - A failed or cancelled run leaves ett_prices untouched.
			//   - GREATEST/LEAST aggregation in the upsert correctly merges data across ESI
			//     pages within a single run (same item can appear on multiple pages), but
			//     never accumulates drift across scheduled runs.
			$pdo->exec('CREATE TABLE IF NOT EXISTS ett_prices_staging LIKE ett_prices');
			$pdo->exec('TRUNCATE TABLE ett_prices_staging');

			$all_hubs_for_run = $selected_hubs;
			foreach (array_keys($private_hubs_map) as $ph_key) {
				$all_hubs_for_run[] = $ph_key;
			}

			$progress = [
			    'driver' => $progress['driver'] ?? 'browser',
				'phase' => 'hub',
				'source' => 'primary',
				'secondary_map' => $secondary_map,
				'tertiary_map' => $tertiary_map,
				'private_hubs_map' => $private_hubs_map,
				'job_type' => 'prices',
				'hubs' => $all_hubs_for_run,
				'hub_index' => 0,
				'page' => 1,
				'type_ids_total' => $type_count,
				'orders_seen' => 0,
				'matched_orders' => 0,
				'rows_written' => 0,
				'current_hub' => $all_hubs_for_run[0] ?? null,
				'last_msg' => 'Starting price pull',
				'warning_msg' => null,
				'rate_limited_seen' => false,
				'sleep_until' => 0,
				'details' => [
					'note' => 'Processing 1 ESI page per step for stability.',
				],
			];

			return $progress;
		}

		if (($progress['phase'] ?? '') === 'adjusted'){
			return self::step_adjusted_prices($pdo, $progress, $job_id);
		}

		if (($progress['phase'] ?? '') !== 'hub') return $progress;

		$now = time();
		if (!empty($progress['sleep_until']) && $now < (int)$progress['sleep_until']) {
			$wait = (int)$progress['sleep_until'] - $now;
			$progress['last_msg'] = "Backoff active: waiting {$wait}s";
			return $progress;
		}

        $hub_key = $progress['hubs'][$progress['hub_index']] ?? null;
        if (!$hub_key) {
        	// All hub data fetched — move to adjusted prices phase before marking done.
        	$progress['phase']    = 'adjusted';
        	$progress['last_msg'] = 'Hub data complete. Fetching adjusted prices…';
        	return $progress;
        }

        // ── Private hub branch ────────────────────────────────────────────
        $private_hubs_map = is_array($progress['private_hubs_map'] ?? null) ? $progress['private_hubs_map'] : [];
        if (isset($private_hubs_map[$hub_key])) {
        	return self::step_prices_private_hub($pdo, $progress, $job_id, $hub_key, $private_hubs_map[$hub_key]);
        }
        // ── Standard hub branch ───────────────────────────────────────────

		$hub = $hubs[$hub_key];
		$region_id = (int)$hub['region_id'];
		$station_id = (int)$hub['station_id'];
		$page = max(1, (int)($progress['page'] ?? 1));

		$progress['current_hub'] = $hub_key;

		$source = (string)($progress['source'] ?? 'primary');

		$secondary_structure_id = (int)($progress['secondary_map'][$hub_key] ?? 0);
		$tertiary_structure_id = (int)($progress['tertiary_map'][$hub_key] ?? 0);

		$secondary_label = isset($secondary_pairs[$hub_key]['label']) ? (string)$secondary_pairs[$hub_key]['label'] : null;
		$tertiary_label = $secondary_label;

		if ($source === 'secondary' || $source === 'tertiary') {
			$struct_id = ($source === 'secondary') ? $secondary_structure_id : $tertiary_structure_id;
			$label = ($source === 'secondary') ? $secondary_label : $tertiary_label;

			if ($struct_id <= 0) {
				if ($source === 'secondary' && $tertiary_structure_id > 0) {
					$progress['source'] = 'tertiary';
					$progress['page'] = 1;
					$progress['last_msg'] = "Finished secondary for {$hub_key}; switching to tertiary";
					return $progress;
				}

				$progress['source'] = 'primary';
				$progress['hub_index']++;
				$progress['page'] = 1;
				$progress['last_msg'] = "Finished hub {$hub_key}";
				return $progress;
			}

			$tok = ETT_Admin::get_access_token_for_jobs();
			if (empty($tok['ok'])) {
				$progress['source'] = 'primary';
				$progress['hub_index']++;
				$progress['page'] = 1;

				if (!isset($progress['warnings']) || !is_array($progress['warnings'])) $progress['warnings'] = [];
				$progress['warnings'][] = "Skipped {$source} for {$hub_key}" . ($label ? " ({$label})" : "") . ' because EVE SSO is not connected/refreshable.';

				$progress['last_msg'] = "Finished hub {$hub_key} ({$source} skipped: SSO not connected)";
				return $progress;
			}

			$progress['secondary_label'] = ($source === 'secondary') ? $secondary_label : null;
			$progress['secondary_structure_id'] = ($source === 'secondary') ? $secondary_structure_id : 0;

			$progress['tertiary_label'] = ($source === 'tertiary') ? $tertiary_label : null;
			$progress['tertiary_structure_id'] = ($source === 'tertiary') ? $tertiary_structure_id : 0;

			$esi = ETT_ESI::structure_orders_page($struct_id, $page, (string)$tok['access']);
		} else {
			$progress['secondary_label'] = null;
			$progress['secondary_structure_id'] = 0;
			$progress['tertiary_label'] = null;
			$progress['tertiary_structure_id'] = 0;

			$esi = ETT_ESI::region_orders_page($region_id, $page);
		}

		if (!empty($esi['rate_limited'])) {
			$retry = max(1, (int)($esi['retry_after'] ?? 5));

			$progress['rate_limited_seen'] = true;
			$progress['warning_msg'] = 'Rate limiting was encountered during this run. The job will back off and continue, but if it is cancelled/interrupted the resulting dataset may be incomplete.';
			$progress['sleep_until'] = time() + $retry;
			$progress['last_msg'] = "ESI rate limited (HTTP {$esi['code']}), backing off {$retry}s";
			$progress['details']['esi'] = [
				'code' => $esi['code'] ?? null,
				'remain' => $esi['remain'] ?? null,
				'reset' => $esi['reset'] ?? null,
				'note' => $esi['note'] ?? null,
			];

			return $progress;
		}
		
        $code = (int)($esi['code'] ?? 0);
        
        // For structure markets, 401/403 are usually not transient.
        // Skip this source/hub instead of retry-looping forever.
        if (($source === 'secondary' || $source === 'tertiary') && ($code === 401 || $code === 403)) {
        	$label = ($source === 'secondary') ? $secondary_label : $tertiary_label;
        
        	if (!isset($progress['warnings']) || !is_array($progress['warnings'])) $progress['warnings'] = [];
        	$progress['warnings'][] = "Skipped {$source} for {$hub_key}" . ($label ? " ({$label})" : "") . " due to ESI HTTP {$code}.";
        
        	// advance to next source/hub
        	if ($source === 'secondary' && $tertiary_structure_id > 0) {
        		$progress['source'] = 'tertiary';
        		$progress['page'] = 1;
        		$progress['last_msg'] = "Secondary access denied for {$hub_key}; switching to tertiary";
        		return $progress;
        	}
        
        	$progress['source'] = 'primary';
        	$progress['hub_index']++;
        	$progress['page'] = 1;
        	$progress['last_msg'] = "Finished hub {$hub_key} ({$source} skipped: HTTP {$code})";
        	return $progress;
        }

		if (empty($esi['ok'])) {
			$progress['warning_msg'] = 'A transient ESI error occurred during this run. The job will retry, but if it is cancelled/interrupted the resulting dataset may be incomplete.';
			$progress['sleep_until'] = time() + 5;
			$progress['last_msg'] = 'ESI transient error (HTTP ' . (int)($esi['code'] ?? 0) . '), retrying in 5s';
			$progress['details']['esi'] = [
				'code' => $esi['code'] ?? null,
				'remain' => $esi['remain'] ?? null,
				'reset' => $esi['reset'] ?? null,
				'note' => $esi['note'] ?? null,
			];

			return $progress;
		}

		$orders = $esi['orders'] ?? [];
		$progress['sleep_until'] = 0;

		// ── Extension hooks (additive – no existing behaviour changed) ──────
		// Fires once at the start of a hub's primary fetch so listeners can
		// clear stale data before new pages arrive.
		if ($source === 'primary' && $page === 1) {
			/**
			 * @param string $hub_key    ETT hub key, e.g. 'jita'.
			 * @param int    $region_id  EVE region ID.
			 * @param int    $station_id Primary station ID.
			 */
			do_action('ett_prices_hub_start', $hub_key, $region_id, $station_id);
		}
		// Fires for every successful page of raw ESI orders so listeners can
		// capture individual order rows (e.g. per-type order books).
		if (!empty($orders)) {
			/**
			 * @param string $hub_key    ETT hub key.
			 * @param int    $region_id  EVE region ID.
			 * @param int    $station_id Primary station ID.
			 * @param int    $page       ESI page number (1-based).
			 * @param string $source     'primary', 'secondary', or 'tertiary'.
			 * @param array  $orders     Raw ESI order objects for this page.
			 */
			do_action('ett_prices_raw_orders_page', $hub_key, $region_id, $station_id, $page, $source, $orders);
		}
		// ── End extension hooks ─────────────────────────────────────────────

		if (empty($orders)) {
			if ($source === 'primary') {
				if ($secondary_structure_id > 0) {
					$progress['source'] = 'secondary';
					$progress['page'] = 1;
					$progress['last_msg'] = "Finished primary for {$hub_key}; switching to secondary";
					return $progress;
				}

				if ($tertiary_structure_id > 0) {
					$progress['source'] = 'tertiary';
					$progress['page'] = 1;
					$progress['last_msg'] = "Finished primary for {$hub_key}; switching to tertiary";
					return $progress;
				}
			}

			if ($source === 'secondary' && $tertiary_structure_id > 0) {
				$progress['source'] = 'tertiary';
				$progress['page'] = 1;
				$progress['last_msg'] = "Finished secondary for {$hub_key}; switching to tertiary";
				return $progress;
			}

			$progress['source'] = 'primary';
			$progress['hub_index']++;
			$progress['page'] = 1;
			$progress['last_msg'] = "Finished hub {$hub_key}";
			return $progress;
		}

        $allow = get_transient('ett_typeids_set_' . $job_id);
        if (!is_array($allow) || empty($allow)) {
            $type_ids = get_transient('ett_typeids_' . $job_id);
            if (!is_array($type_ids) || empty($type_ids)) {
                $type_ids = ETT_TypeIDs::all($pdo);
                set_transient('ett_typeids_' . $job_id, $type_ids, 6 * HOUR_IN_SECONDS);
            }
            $allow = array_fill_keys(array_map('intval', $type_ids), true);
            set_transient('ett_typeids_set_' . $job_id, $allow, 6 * HOUR_IN_SECONDS);
        }

		$sellMin = [];
		$buyMax = [];
		$sellVol = [];
		$buyVol = [];

		foreach ($orders as $o) {
			$progress['orders_seen']++;

			if ($source === 'primary') {
                $is_buy_order = (bool)($o['is_buy_order'] ?? false);
                if ($is_buy_order) {
                    $range = $o['range'] ?? 'station';
                    if ($range === 'station') {
                        if ((int)($o['location_id'] ?? 0) !== $station_id) continue;
                    } elseif ($range === 'solarsystem') {
                        if ((int)($o['system_id'] ?? 0) !== (int)$hub['system_id']) continue;
                    } elseif ($range === 'region') {
                        // always fulfillable at any station in the region — include
                    } else {
                        // jump range (1–40) — would need a jump map to resolve correctly
                        // conservative fallback: exclude, same as station-only
                        if ((int)($o['location_id'] ?? 0) !== $station_id) continue;
                    }
                } else {
                    if ((int)($o['location_id'] ?? 0) !== $station_id) continue;
                }
            }

            $type_id = (int)($o['type_id'] ?? 0);
			if (!isset($allow[$type_id])) continue;

			$progress['matched_orders']++;

			$is_buy = (bool)($o['is_buy_order'] ?? false);
			$price = (float)($o['price'] ?? 0);
			$volrem = (int)($o['volume_remain'] ?? 0);

			if ($is_buy) {
				if (!isset($buyMax[$type_id]) || $price > $buyMax[$type_id]) $buyMax[$type_id] = $price;
				$buyVol[$type_id] = ($buyVol[$type_id] ?? 0) + max(0, $volrem);
			} else {
				if (!isset($sellMin[$type_id]) || $price < $sellMin[$type_id]) $sellMin[$type_id] = $price;
				$sellVol[$type_id] = ($sellVol[$type_id] ?? 0) + max(0, $volrem);
			}
		}

		$now = current_time('mysql');

        $touched = array_unique(array_merge(array_keys($sellMin), array_keys($buyMax)));
        $touched = array_values($touched);
        
        $chunk_size = 200;
        
        for ($offset = 0; $offset < count($touched); $offset += $chunk_size) {
        	$chunk = array_slice($touched, $offset, $chunk_size);
        	if (empty($chunk)) continue;
        
        	$values = [];
        	$params = [];
        
        	foreach ($chunk as $tid) {
        		$values[] = "(?,?,?,?,?,?,?,?,?)";
        
        		$params[] = (string)$hub_key;
        		$params[] = (int)$region_id;
        		$params[] = (int)$station_id;
        		$params[] = (int)$tid;
        		$params[] = $sellMin[$tid] ?? null;
        		$params[] = $buyMax[$tid] ?? null;
        		$params[] = $sellVol[$tid] ?? null;
        		$params[] = $buyVol[$tid] ?? null;
        		$params[] = $now;
        	}
        
        	$sql = "
        		INSERT INTO ett_prices_staging
        			(hub_key, region_id, station_id, type_id, sell_min, buy_max, sell_volume, buy_volume, fetched_at)
        		VALUES
        			" . implode(",\n\t\t\t", $values) . "
        		ON DUPLICATE KEY UPDATE
        			sell_min = LEAST(COALESCE(sell_min, 999999999999.99), COALESCE(VALUES(sell_min), 999999999999.99)),
        			buy_max  = GREATEST(COALESCE(buy_max, 0), COALESCE(VALUES(buy_max), 0)),
        			sell_volume = COALESCE(sell_volume,0) + COALESCE(VALUES(sell_volume),0),
        			buy_volume  = COALESCE(buy_volume,0)  + COALESCE(VALUES(buy_volume),0),
        			fetched_at = VALUES(fetched_at)
        	";
        
        	$stmt = $pdo->prepare($sql);
        	$stmt->execute($params);
        
        	$progress['rows_written'] += count($chunk);
        }

		$progress['page'] = $page + 1;

		$src_txt = 'Primary';
		if ($source === 'secondary') {
			$src_txt = 'Secondary' . ($secondary_label ? ' (' . $secondary_label . ')' : '');
		} elseif ($source === 'tertiary') {
			$src_txt = 'Tertiary' . ($tertiary_label ? ' (' . $tertiary_label . ')' : '');
		}

		$progress['last_msg'] = "Hub {$hub_key} {$src_txt}: processed page {$page}";
		$progress['details'] = [
			'hub' => $hub_key,
			'source' => $source,
			'source_label' => $src_txt,
			'page' => $page,
			'touched_types' => count($touched),
			'station_id' => $station_id,
			'region_id' => $region_id,
			'secondary_structure_id' => (int)($progress['secondary_structure_id'] ?? 0),
			'warnings_count' => (isset($progress['warnings']) && is_array($progress['warnings'])) ? count($progress['warnings']) : 0,
			'last_warning' => (isset($progress['warnings']) && is_array($progress['warnings']) && count($progress['warnings'])) ? end($progress['warnings']) : null,
		];

		return $progress;
	}

	private static function get_job(PDO $pdo, string $job_id) {
		$stmt = $pdo->prepare('SELECT * FROM ett_jobs WHERE job_id = :id');
		$stmt->execute([':id' => $job_id]);
		return $stmt->fetch();
	}

	private static function update_status(PDO $pdo, string $job_id, string $status) {
		$stmt = $pdo->prepare('UPDATE ett_jobs SET status=:s WHERE job_id=:id');
		$stmt->execute([':s' => $status, ':id' => $job_id]);
	}

    private static function heartbeat(PDO $pdo, string $job_id, array $progress) : string {
        $now = current_time('mysql');
        $stmt = $pdo->prepare('UPDATE ett_jobs SET progress_json=:pj, heartbeat_at=:hb WHERE job_id=:id');
        $pj = json_encode($progress, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $stmt->execute([':pj' => $pj === false ? '{}' : $pj, ':hb' => $now, ':id' => $job_id]);
        return $now;
    }

	private static function finish(PDO $pdo, string $job_id, string $status, array $progress, ?string $err = null) {
		if ($status === 'done') {
			$progress['phase'] = 'done';
			$progress['error'] = null;
			if (!isset($progress['last_msg']) || $progress['last_msg'] === '') {
				$progress['last_msg'] = 'Completed successfully';
			}
		} elseif ($status === 'error') {
			$progress['phase'] = 'error';
			if (!isset($progress['last_msg']) || $progress['last_msg'] === '' || $progress['last_msg'] === '—') {
				$progress['last_msg'] = $err ? ('Error: ' . $err) : 'Error';
			}
			if (!isset($progress['error']) || !is_array($progress['error'])) {
				$progress['error'] = [
					'message' => $err ?: 'Unknown error',
				];
			}
		} elseif ($status === 'cancelled') {
			$progress['phase'] = 'cancelled';
			if (!isset($progress['last_msg']) || $progress['last_msg'] === '') {
				$progress['last_msg'] = 'Cancelled';
			}
		}

		// Auto-create history job when a prices job completes successfully,
		// UNLESS the job was started with prices_only=true (e.g. via the "Run Prices" button).
		if ($status === 'done' && isset($progress['job_type']) && $progress['job_type'] === 'prices' && empty($progress['prices_only'])) {
			try {
				$history_job_id = self::create_job($pdo, 'history', $progress['driver'] ?? 'browser');
				$progress['history_job_id'] = $history_job_id;
			} catch (\Throwable $e) {
				self::debug_log('[ETT] Could not create history job: ' . $e->getMessage());
			}
		}

		// Same chain, one step further: auto-create a contracts job when a
		// history job completes successfully, UNLESS it was started with
		// history_only=true (e.g. via a standalone "Run History" button
		// rather than as part of Fetch All's prices -> history -> contracts
		// sequence).
		if ($status === 'done' && isset($progress['job_type']) && $progress['job_type'] === 'history' && empty($progress['history_only'])) {
			try {
				$contracts_job_id = self::create_job($pdo, 'contracts', $progress['driver'] ?? 'browser');
				$progress['contracts_job_id'] = $contracts_job_id;
			} catch (\Throwable $e) {
				self::debug_log('[ETT] Could not create contracts job: ' . $e->getMessage());
			}
		}

		$now = current_time('mysql');
		$stmt = $pdo->prepare("
			UPDATE ett_jobs
			SET status=:s, progress_json=:pj, heartbeat_at=:hb, finished_at=:fa, last_error=:e
			WHERE job_id=:id
		");
		$stmt->execute([
			':s' => $status,
            ':pj' => (function($p){
                $j = json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                return $j === false ? '{}' : $j;
            })($progress),
			':hb' => $now,
			':fa' => $now,
			':e' => $err,
			':id' => $job_id,
		]);

		try {
			delete_transient('ett_typeids_' . $job_id);
			delete_transient('ett_typeids_set_' . $job_id);
        } catch (Exception $e) {
        	self::debug_log('[ETT] finish() housekeeping failed: ' . $e->getMessage());
        }

		try {
			self::prune_old_jobs($pdo);
		} catch (\Throwable $e) {
		}

		try {
			if ($status === 'done' && isset($progress['job_type']) && $progress['job_type'] === 'prices') {
				update_option('ett_last_price_run_completed_at', current_time('mysql'), false);
			}
        } catch (Exception $e) {
        	self::debug_log('[ETT] finish() housekeeping failed: ' . $e->getMessage());
        }
	}

	private static function create_job(PDO $pdo, string $job_type, string $driver, bool $prices_only = false, bool $history_only = false) : string {
		$job_id = self::uuid4();
		$now = current_time('mysql');

		// Base progress fields shared by all job types
		$progress = [
			'job_type' => $job_type,
			'driver'   => $driver,
			'phase'    => 'init',
			'last_msg' => 'Queued',
			'error'    => null,
			'rows_written' => 0,
		];

		// Flag to suppress auto-creation of a history job on completion
		if ($prices_only) {
			$progress['prices_only'] = true;
		}
		// Flag to suppress auto-creation of a contracts job on completion
		if ($history_only) {
			$progress['history_only'] = true;
		}

		// Prices/typeids-specific fields — not relevant to history jobs
		if ($job_type !== 'history') {
			$progress['current_hub']    = null;
			$progress['page']           = null;
			$progress['orders_seen']    = 0;
			$progress['matched_orders'] = 0;
		}

		$stmt = $pdo->prepare("
			INSERT INTO ett_jobs (job_id, job_type, status, progress_json, heartbeat_at, started_at)
			VALUES (:id,:type,'queued',:pj,:hb,:st)
		");
		$stmt->execute([
			':id' => $job_id,
			':type' => $job_type,
			':pj' => json_encode($progress),
			':hb' => $now,
			':st' => $now,
		]);

		return $job_id;
	}

	/**
	 * Handle one price-fetch step for a private hub (a user-defined citadel system).
	 * Iterates through each enabled structure_id for this hub, fetching all pages.
	 * Uses 'priv_struct_index' and 'page' in progress to track position.
	 * Prices are written to ett_prices using the sanitized system name as hub_key
	 * (e.g. 'c-n4od') so the key is human-readable and consistent with what
	 * ETT Reprocess Trading reads from DISTINCT hub_key in that table.
	 *
	 * @param array $ph_config ['hub_index','system_id','system_name','region_id','structure_ids','char_source']
	 */
	private static function step_prices_private_hub(PDO $pdo, array $progress, string $job_id, string $hub_key, array $ph_config): array {
		$idx          = (int) $ph_config['hub_index'];
		$region_id    = (int) $ph_config['region_id'];
		$structure_ids = array_values(array_map('intval', $ph_config['structure_ids'] ?? []));
		$char_source  = (string) ($ph_config['char_source'] ?? 'primary');

		// The key written to ett_prices uses the system name (e.g. 'c-n4od'), not 'private_hub_N',
		// so it's human-readable and matches what ETT Reprocess Trading will display.
		$price_hub_key = sanitize_key((string) ($ph_config['system_name'] ?? $hub_key));

		// Always update current_hub so the job progress card reflects the active hub.
		// Use the internal 'private_hub_N' key — JS hubLabel() maps it to the display name.
		$progress['current_hub'] = $hub_key;

		// Label used in last_msg — internal key so JS hubLabel() translates it.
		$hub_label_for_msg = $hub_key;

		if (empty($structure_ids)) {
			$progress['hub_index']++;
			$progress['page']   = 1;
			$progress['source'] = 'primary';
			$progress['last_msg'] = "Hub {$hub_label_for_msg}: skipped — no enabled structures";
			return $progress;
		}

		// Get SSO token
		if ($char_source === 'private') {
			$tok = ETT_Admin::get_private_hub_access_token($idx);
		} else {
			$tok = ETT_Admin::get_access_token_for_jobs();
		}

		if (empty($tok['ok'])) {
			if (!is_array($progress['warnings'] ?? null)) $progress['warnings'] = [];
			$progress['warnings'][] = "Skipped {$price_hub_key}: SSO not connected/refreshable.";
			$progress['hub_index']++;
			$progress['page']   = 1;
			$progress['source'] = 'primary';
			$progress['last_msg'] = "Hub {$hub_label_for_msg}: skipped (SSO not connected)";
			return $progress;
		}
		$access = $tok['access'];

		// Track which structure we're on within this hub
		$struct_index = (int) ($progress['priv_struct_index'] ?? 0);
		$page         = max(1, (int) ($progress['page'] ?? 1));

		if ($struct_index >= count($structure_ids)) {
			// Done all structures for this private hub
			$progress['hub_index']++;
			$progress['page']             = 1;
			$progress['priv_struct_index'] = 0;
			$progress['source']           = 'primary';
			$progress['last_msg']         = "Hub {$hub_label_for_msg}: finished";
			return $progress;
		}

		$structure_id = $structure_ids[$struct_index];

		$esi          = ETT_ESI::structure_orders_page($structure_id, $page, $access);

		if (!empty($esi['rate_limited'])) {
			$retry = max(1, (int) ($esi['retry_after'] ?? 5));
			$progress['rate_limited_seen'] = true;
			$progress['sleep_until']       = time() + $retry;
			$progress['last_msg']          = "Hub {$hub_label_for_msg}: rate limited, backing off {$retry}s";
			return $progress;
		}

		$code = (int) ($esi['code'] ?? 0);
		if ($code === 401 || $code === 403) {
			if (!is_array($progress['warnings'] ?? null)) $progress['warnings'] = [];
			$progress['warnings'][] = "Structure {$structure_id} in {$price_hub_key} returned HTTP {$code} — skipped.";
			$progress['priv_struct_index'] = $struct_index + 1;
			$progress['page']              = 1;
			$progress['last_msg']          = "Hub {$hub_label_for_msg}: structure {$structure_id} access denied (HTTP {$code}), skipping";
			return $progress;
		}

		if (empty($esi['ok'])) {
			$progress['sleep_until'] = time() + 5;
			$progress['last_msg']    = "Hub {$hub_label_for_msg}: ESI error (HTTP {$code}), retrying in 5s";
			return $progress;
		}

		$orders = $esi['orders'] ?? [];
		$progress['sleep_until'] = 0;

		if (empty($orders)) {
			// End of pages for this structure — move to next
			$progress['priv_struct_index'] = $struct_index + 1;
			$progress['page']              = 1;
			$progress['last_msg']          = "Hub {$hub_label_for_msg}: finished structure {$structure_id}";
			return $progress;
		}

		// Load allowed type IDs
		$allow = get_transient('ett_typeids_set_' . $job_id);
		if (!is_array($allow) || empty($allow)) {
			$type_ids = get_transient('ett_typeids_' . $job_id);
			if (!is_array($type_ids) || empty($type_ids)) {
				$type_ids = ETT_TypeIDs::all($pdo);
				set_transient('ett_typeids_' . $job_id, $type_ids, 6 * HOUR_IN_SECONDS);
			}
			$allow = array_fill_keys(array_map('intval', $type_ids), true);
			set_transient('ett_typeids_set_' . $job_id, $allow, 6 * HOUR_IN_SECONDS);
		}

		$sellMin = [];
		$buyMax  = [];
		$sellVol = [];
		$buyVol  = [];

		foreach ($orders as $order) {
			if (!is_array($order)) continue;
			$type_id  = (int) ($order['type_id'] ?? 0);
			$price    = (float) ($order['price'] ?? 0);
			$is_buy   = (bool) ($order['is_buy_order'] ?? false);
			$volrem   = (int) ($order['volume_remain'] ?? 0);
			if (!isset($allow[$type_id])) continue;

			if ($is_buy) {
				if (!isset($buyMax[$type_id]) || $price > $buyMax[$type_id]) $buyMax[$type_id] = $price;
				$buyVol[$type_id] = ($buyVol[$type_id] ?? 0) + max(0, $volrem);
			} else {
				if (!isset($sellMin[$type_id]) || $price < $sellMin[$type_id]) $sellMin[$type_id] = $price;
				$sellVol[$type_id] = ($sellVol[$type_id] ?? 0) + max(0, $volrem);
			}
		}

		$now     = current_time('mysql');
		$touched = array_unique(array_merge(array_keys($sellMin), array_keys($buyMax)));

		// station_id for private hubs is the structure_id itself
		$chunk_size = 200;
		for ($offset = 0; $offset < count($touched); $offset += $chunk_size) {
			$chunk = array_slice($touched, $offset, $chunk_size);
			if (empty($chunk)) continue;

			$values = [];
			$params = [];
			foreach ($chunk as $tid) {
				$values[] = '(?,?,?,?,?,?,?,?,?)';
				$params[]  = $price_hub_key;
				$params[]  = $region_id;
				$params[]  = $structure_id;
				$params[]  = $tid;
				$params[]  = $sellMin[$tid] ?? null;
				$params[]  = $buyMax[$tid]  ?? null;
				$params[]  = $sellVol[$tid] ?? null;
				$params[]  = $buyVol[$tid]  ?? null;
				$params[]  = $now;
			}

			$sql  = "INSERT INTO ett_prices_staging
				(hub_key, region_id, station_id, type_id, sell_min, buy_max, sell_volume, buy_volume, fetched_at)
				VALUES " . implode(",\n\t\t\t", $values) . "
				ON DUPLICATE KEY UPDATE
				sell_min = LEAST(COALESCE(sell_min, 999999999999.99), COALESCE(VALUES(sell_min), 999999999999.99)),
				buy_max  = GREATEST(COALESCE(buy_max, 0), COALESCE(VALUES(buy_max), 0)),
				sell_volume = COALESCE(sell_volume,0) + COALESCE(VALUES(sell_volume),0),
				buy_volume  = COALESCE(buy_volume,0)  + COALESCE(VALUES(buy_volume),0),
				fetched_at = VALUES(fetched_at)";

			$stmt = $pdo->prepare($sql);
			$stmt->execute($params);
			$progress['rows_written'] += count($chunk);
		}

		$progress['page']     = $page + 1;
		$progress['last_msg'] = "Hub {$hub_label_for_msg} Primary: processed page {$page}";
		return $progress;
	}

    private static function format_duration(int $secs) : string {
    	$secs = max(0, (int)$secs);
    	$h = intdiv($secs, 3600);
    	$m = intdiv($secs % 3600, 60);
    	$s = $secs % 60;
    	if ($h > 0) return sprintf('%dh %dm %ds', $h, $m, $s);
    	if ($m > 0) return sprintf('%dm %ds', $m, $s);
    	return sprintf('%ds', $s);
    }

    private static function prune_old_jobs(PDO $pdo) : void {
    	$days = max(1, (int) self::JOB_RETENTION_DAYS);
    
    	try {
    		$sql = sprintf("
    			DELETE FROM ett_jobs
    			WHERE started_at < DATE_SUB(NOW(), INTERVAL %d DAY)
    			  AND status IN ('done','error','cancelled')
    		", $days);
    		$pdo->exec($sql);
    	} catch (\Throwable $e) {
        	self::debug_log('[ETT] prune_old_jobs failed: ' . $e->getMessage());
    	}
    }

	private static function uuid4() : string {
		$d = random_bytes(16);
		$d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
		$d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
	}


	// ── Adjusted prices step ───────────────────────────────────────────────

	/**
	 * Fetch ESI /markets/prices/ (full list, no pagination), filter to our
	 * selected type IDs, and upsert into ett_adjusted_prices.
	 * Called as a phase of the prices job immediately after all hubs complete.
	 */
	private static function step_adjusted_prices(PDO $pdo, array $progress, string $job_id) : array {

		$now = time();

		// Respect any backoff that was set on a previous attempt of this phase.
		if (!empty($progress['sleep_until']) && $now < (int)$progress['sleep_until']) {
			$wait = (int)$progress['sleep_until'] - $now;
			$progress['last_msg'] = "Adjusted prices: backoff active, waiting {$wait}s";
			return $progress;
		}

		$progress['last_msg'] = 'Fetching adjusted prices from ESI…';

		$esi = ETT_ESI::market_prices();

		if (!empty($esi['rate_limited'])) {
			$retry = max(1, (int)($esi['retry_after'] ?? 5));

			$progress['rate_limited_seen'] = true;
			$progress['warning_msg']       = 'Rate limiting was encountered during this run. The job will back off and continue, but if it is cancelled/interrupted the resulting dataset may be incomplete.';
			$progress['sleep_until']       = time() + $retry;
			$progress['last_msg']          = "Adjusted prices: ESI rate limited (HTTP {$esi['code']}), backing off {$retry}s";
			return $progress;
		}

		if (empty($esi['ok'])) {
			// Transient error — retry after a short backoff, same as hub phase.
			$progress['warning_msg'] = 'A transient ESI error occurred fetching adjusted prices. The job will retry.';
			$progress['sleep_until'] = time() + 5;
			$progress['last_msg']    = 'Adjusted prices: ESI transient error (HTTP ' . (int)($esi['code'] ?? 0) . '), retrying in 5s';
			return $progress;
		}

		// Clear any previous backoff now that the request succeeded.
		$progress['sleep_until'] = 0;

		$raw_prices = $esi['prices'] ?? [];

		// Build a fast lookup of our selected type IDs.
		$selected = [];
		try {
			$rows = $pdo->query('SELECT type_id FROM ett_selected_typeids ORDER BY type_id ASC')->fetchAll(PDO::FETCH_COLUMN);  // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO -- External DB: wpdb cannot connect to a separate MySQL server; PDO is required here.
			foreach ($rows as $tid) {
				$selected[(int)$tid] = true;
			}
		} catch (\Throwable $e) {
			$progress['last_msg'] = 'Adjusted prices: failed to load selected typeIDs — ' . $e->getMessage();
			$progress['phase']    = 'done';
			return $progress;
		}

		// Filter ESI response to our selected type IDs only and build upsert params.
		$now_mysql    = current_time('mysql');
		$placeholders = [];
		$params       = [];

		foreach ($raw_prices as $row) {
			$type_id = (int)($row['type_id'] ?? 0);
			if ($type_id <= 0 || !isset($selected[$type_id])) continue;

			$adj = isset($row['adjusted_price']) ? (float)$row['adjusted_price'] : null;
			$avg = isset($row['average_price'])  ? (float)$row['average_price']  : null;

			$placeholders[] = '(?,?,?,?)';
			$params[]       = $type_id;
			$params[]       = $adj;
			$params[]       = $avg;
			$params[]       = $now_mysql;
		}

		$written = 0;

		if (!empty($placeholders)) {
			$chunk_size = 500;
			$total_rows = count($placeholders);

			for ($offset = 0; $offset < $total_rows; $offset += $chunk_size) {
				$chunk_ph     = array_slice($placeholders, $offset, $chunk_size);
				$chunk_params = array_slice($params, $offset * 4, $chunk_size * 4);

				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$sql = 'INSERT INTO ett_adjusted_prices (type_id, adjusted_price, average_price, fetched_at) VALUES '
				     . implode(',', $chunk_ph)
				     . ' ON DUPLICATE KEY UPDATE adjusted_price = VALUES(adjusted_price), average_price = VALUES(average_price), fetched_at = VALUES(fetched_at)';

				$pdo->prepare($sql)->execute($chunk_params);
				$written += count($chunk_ph);
			}
		}

		// Include elapsed time in completion message.
		$elapsed_s = null;
		try {
			$stmt = $pdo->prepare('SELECT started_at FROM ett_jobs WHERE job_id=:id LIMIT 1');
			$stmt->execute([':id' => $job_id]);
			$started_at = (string)($stmt->fetchColumn() ?: '');
			if ($started_at !== '') {
				$dt0 = DateTime::createFromFormat('Y-m-d H:i:s', $started_at, wp_timezone());
				if ($dt0 instanceof DateTime) {
					$elapsed_s = max(0, (new DateTime('now', wp_timezone()))->getTimestamp() - $dt0->getTimestamp());
				}
			}
		} catch (\Throwable $e) {
			// Non-fatal — fall back to message without timing.
		}

		$progress['phase']        = 'done';
		$progress['rows_written'] = ($progress['rows_written'] ?? 0) + $written;
		$progress['last_msg']     = $elapsed_s !== null
			? sprintf('All hubs and adjusted prices complete — %d adjusted prices written (took %s)', $written, self::format_duration((int)$elapsed_s))
			: sprintf('All hubs and adjusted prices complete — %d adjusted prices written', $written);

		// Atomically promote the staging table to live. The reprocess tool always reads
		// from ett_prices and never sees a partially-populated table.
		//
		// IMPORTANT: We must DROP ett_prices_old BEFORE the RENAME. MySQL's RENAME TABLE
		// is atomic — if the target name already exists, the ENTIRE statement fails.
		// Without this pre-DROP, a leftover ett_prices_old (from a previous crash, timeout,
		// or error after a successful RENAME but before its DROP) would cause every
		// subsequent RENAME to fail silently, leaving ett_prices permanently stale while
		// ett_adjusted_prices continues to update — gradually eroding calculated profits.
		try {
			$pdo->exec('DROP TABLE IF EXISTS ett_prices_old');
			$pdo->exec('
				RENAME TABLE ett_prices         TO ett_prices_old,
				             ett_prices_staging TO ett_prices
			');
			$pdo->exec('DROP TABLE IF EXISTS ett_prices_old');
		} catch (\Throwable $e) {
			if (!is_array($progress['warnings'] ?? null)) $progress['warnings'] = [];
			$progress['warnings'][] = 'Price table swap failed: ' . $e->getMessage() . ' — live table unchanged.';
		}

		return $progress;
	}

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
	private static function step_history(PDO $pdo, array $progress) : array {
		$batch_size = (int) get_option(ETT_Admin::OPT_HISTORY_BATCH_SIZE, 20);
		if ($batch_size < 1)  $batch_size = 1;
		if ($batch_size > 50) $batch_size = 50;

		// ── Init phase: resolve regions + count type_ids ──────────────────
		if (($progress['phase'] ?? 'init') === 'init') {
			$selected_hubs = get_option(ETT_Admin::OPT_SELECTED_HUBS, []);
			if (!is_array($selected_hubs)) $selected_hubs = [];
			// No fallback — if all standard hubs are deselected, only private hub
			// regions (added below) will be included in the history fetch.

			$all_hubs    = ETT_Admin::hubs();
    		$regions     = [];
    		$seen_region = [];
    		foreach ($selected_hubs as $hub_key) {
    			if (!isset($all_hubs[$hub_key])) continue;
    			$region_id = (int) $all_hubs[$hub_key]['region_id'];
    			if (isset($seen_region[$region_id])) continue; // Rens and Hek share Heimatar — fetch once
    			$seen_region[$region_id] = true;
    			$regions[] = [
    				'hub_key'   => $hub_key,
    				'region_id' => $region_id,
    			];
    		}

    		// Also add regions for enabled private hubs
    		$private_hub_configs = ETT_Admin::get_private_hubs();
    		foreach ($private_hub_configs as $ph) {
    			$idx         = (int) ($ph['hub_index'] ?? 0);
    			$region_id   = (int) ($ph['region_id'] ?? 0);
    			$system_name = (string) ($ph['system_name'] ?? '');
    			$structures  = is_array($ph['structures'] ?? null) ? $ph['structures'] : [];
    			$has_enabled = false;
    			foreach ($structures as $st) {
    				if (!empty($st['enabled'])) { $has_enabled = true; break; }
    			}
    			if ($idx <= 0 || $region_id <= 0 || !$has_enabled) continue;
    			if (isset($seen_region[$region_id])) continue;
    			$seen_region[$region_id] = true;
    			$price_hub_key = sanitize_key($system_name ?: ('private_hub_' . $idx));
    			$regions[] = [
    				'hub_key'   => $price_hub_key,
    				'region_id' => $region_id,
    			];
    		}

			if (empty($regions)) {
				$progress['phase']    = 'done';
				$progress['last_msg'] = 'No hubs configured.';
				return $progress;
			}

			$type_count = (int) ($pdo->query('SELECT COUNT(*) FROM ett_selected_typeids')->fetchColumn() ?: 0);

			if ($type_count === 0) {
				$progress['phase']    = 'done';
				$progress['last_msg'] = 'No type IDs found. Run Generate TypeIDs first.';
				return $progress;
			}

			$progress['phase']          = 'fetching';
			$progress['regions']        = $regions;
			$progress['region_idx']     = 0;
			$progress['type_idx']       = 0;
			$progress['type_count']     = $type_count;
			$progress['items_done']     = 0;
			$progress['items_total']    = $type_count * count($regions);
			$progress['rows_written']   = 0;
			$progress['current_region'] = $regions[0]['hub_key'];
			$progress['last_msg']       = 'Initialised. Starting fetch…';
			return $progress;
		}

		// ── Fetch phase ───────────────────────────────────────────────────
		$regions    = $progress['regions']    ?? [];
		$region_idx = (int) ($progress['region_idx'] ?? 0);
		$type_idx   = (int) ($progress['type_idx']   ?? 0);
		$type_count = (int) ($progress['type_count'] ?? 0);

		if ($region_idx >= count($regions)) {
			$progress['phase']    = 'done';
			$progress['last_msg'] = 'Market history fetch complete.';
			return $progress;
		}

		$region    = $regions[$region_idx];
		$hub_key   = $region['hub_key'];
		$region_id = (int) $region['region_id'];

		// Pull next batch of type_ids from DB by offset (avoids storing the full list in progress_json)
		$stmt = $pdo->prepare('SELECT type_id FROM ett_selected_typeids ORDER BY type_id ASC LIMIT ? OFFSET ?');
		$stmt->bindValue(1, $batch_size, PDO::PARAM_INT);  // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO -- External DB: wpdb cannot connect to a separate MySQL server; PDO is required here.
		$stmt->bindValue(2, $type_idx,   PDO::PARAM_INT);  // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO -- External DB: wpdb cannot connect to a separate MySQL server; PDO is required here.
		$stmt->execute();
		$batch = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);  // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO -- External DB: wpdb cannot connect to a separate MySQL server; PDO is required here.

		if (empty($batch)) {
			// Region exhausted — advance
			$region_idx++;
			$progress['region_idx'] = $region_idx;
			$progress['type_idx']   = 0;

			if ($region_idx >= count($regions)) {
				$progress['phase']    = 'done';
				$progress['last_msg'] = 'Market history fetch complete.';
			} else {
				$progress['current_region'] = $regions[$region_idx]['hub_key'];
				$progress['last_msg']       = 'Starting hub: ' . $regions[$region_idx]['hub_key'];
			}
			return $progress;
		}

		// Concurrency = batch_size: all items fire in one parallel group, no sub-group gaps.
		// The error_remain guard inside curl_multi_history handles ESI rate-limit safety.
		$results = self::curl_multi_history($region_id, $batch, max(1, min($batch_size, 50)));

		// ── Extension hook (additive – no existing behaviour changed) ──────
		// Fires with the raw per-day history data before it is aggregated into
		// avg_daily_volume.  Listeners can capture lowest/highest per day for
		// trend calculations without needing to make their own ESI calls.
		/**
		 * @param string $hub_key    ETT hub key, e.g. 'jita'.
		 * @param int    $region_id  EVE region ID.
		 * @param array  $results    Map of type_id → ['code' => int, 'data' => [...daily rows...]]
		 *                           Each daily row: date, lowest, highest, average, volume, order_count.
		 */
		do_action('ett_prices_history_results', $hub_key, $region_id, $results);
		// ── End extension hook ─────────────────────────────────────────────

		// Track rate limiting and errors
		if (!isset($progress['rate_limited_seen'])) $progress['rate_limited_seen'] = false;
		if (!isset($progress['warnings']))          $progress['warnings']          = [];
		if (!isset($progress['error_count']))       $progress['error_count']       = 0;

		$has_429 = false;
		foreach ($results as $type_id => $result) {
			$code = (int) ($result['code'] ?? 0);
			if ($code === 429) {
				$has_429 = true;
			} elseif ($code !== 200 && $code !== 0) {
				$progress['error_count']++;
				if ($progress['error_count'] <= 10) {
					$progress['warnings'][] = "HTTP {$code} for type_id {$type_id} (region {$region_id})";
				}
			}
		}

		if ($has_429) {
			$progress['rate_limited_seen'] = true;
			$progress['warning_msg']       = 'Rate limiting encountered during history fetch. Backing off and retrying.';
			$progress['sleep_until']       = time() + 60;
			return $progress;
		}

		// Compute 30-day rolling average and bulk-insert
		$now    = current_time('mysql');
		$cutoff = gmdate('Y-m-d', strtotime('-30 days'));

		$placeholders = [];
		$params       = [];

		foreach ($results as $type_id => $result) {
			$code = (int) ($result['code'] ?? 0);
			// Skip failed requests entirely — do not write a 0.0 avg that would
			// overwrite a previously valid avg_daily_volume and silently remove
			// the item from profit results on subsequent runs.
			if ($code !== 200 && $code !== 0) {
				continue;
			}
			$data = $result['data'] ?? [];
			$avg  = 0.0;
			if (!empty($data)) {
				$recent = array_filter($data, fn($d) => isset($d['date']) && $d['date'] >= $cutoff);
				if (!empty($recent)) {
					$total = array_sum(array_column($recent, 'volume'));
					$days  = count($recent);
					$avg   = $days > 0 ? round($total / $days, 2) : 0.0;
				}
			}
			$placeholders[] = '(?,?,?,?)';
			$params[]       = $hub_key;
			$params[]       = (int) $type_id;
			$params[]       = $avg;
			$params[]       = $now;
		}

		if (!empty($placeholders)) {
			$sql = 'INSERT INTO ett_market_history (hub_key, type_id, avg_daily_volume, fetched_at) VALUES ' .
			       implode(',', $placeholders) .
			       ' ON DUPLICATE KEY UPDATE avg_daily_volume = VALUES(avg_daily_volume), fetched_at = VALUES(fetched_at)';
			$pdo->prepare($sql)->execute($params);
		}

		$fetched = count($batch);
		$type_idx += $fetched;

		$progress['type_idx']     = $type_idx;
		$progress['items_done']   = ($progress['items_done'] ?? 0) + $fetched;
		$progress['rows_written'] = ($progress['rows_written'] ?? 0) + $fetched;
		$progress['current_region'] = $hub_key;
		$progress['concurrency']  = max(1, min($batch_size, 50));

		$done  = (int) ($progress['items_done'] ?? 0);
		$total = (int) ($progress['items_total'] ?? 1);
		$pct   = $total > 0 ? round($done / $total * 100, 1) : 0.0;
		$progress['last_msg'] = "Hub {$hub_key}: {$done}/{$total} ({$pct}%)";

		// Rate limiting was previously encountered but the batch completed successfully.
		// Strip the "Backing off and retrying" suffix — the persistent "rate limiting
		// encountered" note remains, but the action clause is no longer accurate.
		if (!empty($progress['rate_limited_seen'])) {
			$progress['warning_msg'] = 'Rate limiting encountered during history fetch.';
		}

		// Check if this region is now exhausted
		if ($type_idx >= $type_count) {
			$region_idx++;
			$progress['region_idx'] = $region_idx;
			$progress['type_idx']   = 0;

			if ($region_idx >= count($regions)) {
				$progress['phase']    = 'done';
				$progress['last_msg'] = 'Market history fetch complete.';
			} else {
				$progress['current_region'] = $regions[$region_idx]['hub_key'];
			}
		}

		return $progress;
	}
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- table/column names below are fixed literals, never user input; only bound values use placeholders.
	/**
	 * Contract Fetch — third scheduled step, after Prices and History.
	 * Always Jita specifically (ETT_Admin::hubs()['jita']), matching the
	 * same "always Jita" choice already made for margin's own market-value
	 * side. Phases:
	 *
	 *   init        — resolve region/station, truncate the candidates table.
	 *   listing      — walk every page of the region's public contracts,
	 *                  keeping only item_exchange contracts at the Jita
	 *                  station (both real structured fields, free to filter
	 *                  on — no per-contract lookup needed for this part).
	 *   checking     — for candidates never seen before (LEFT JOIN against
	 *                  ett_contract_resolved), check contents. Confirmed
	 *                  single-item BPC matches go into ett_contract_bpc_active
	 *                  AND ett_contract_resolved; everything else (multi-item,
	 *                  not a blueprint, a BPO not a BPC, or a blueprint we
	 *                  don't track) goes into ett_contract_resolved only, so
	 *                  it's never re-checked again — a contract's contents
	 *                  can't change once created.
	 *   aggregating  — per blueprint, per-run price = that listing's own
	 *                  price ÷ its own actual runs (read directly from ESI,
	 *                  no guessing). Reject anything under 50% of the median
	 *                  per-run price as a likely mistake/troll listing, take
	 *                  the minimum of what survives, store it.
	 *   pruning      — remove anything from ett_contract_bpc_active and
	 *                  ett_contract_resolved that's no longer in this run's
	 *                  candidates table (accepted/expired/cancelled) — using
	 *                  the same candidates table listing already built, no
	 *                  extra fetch needed.
	 */
	private static function step_contracts(PDO $pdo, array $progress): array {
		$batch_size = (int) get_option(ETT_Admin::OPT_HISTORY_BATCH_SIZE, 20);
		if ($batch_size < 1)  $batch_size = 1;
		if ($batch_size > 50) $batch_size = 50;

		$phase = $progress['phase'] ?? 'init';

		// ── Init ────────────────────────────────────────────────────────
		if ($phase === 'init') {
			$hubs = ETT_Admin::hubs();
			$jita = $hubs['jita'] ?? null;
			if (!$jita) {
				$progress['phase']    = 'done';
				$progress['last_msg'] = 'Jita hub configuration not found — cannot fetch contracts.';
				return $progress;
			}

			$pdo->exec('TRUNCATE TABLE ett_contract_candidates');

			$progress['phase']           = 'listing';
			$progress['region_id']       = (int) $jita['region_id'];
			$progress['station_id']      = (int) $jita['station_id'];
			$progress['list_page']       = 1;
			$progress['list_total_pages'] = 1;
			$progress['candidates_found'] = 0;
			$progress['checked_count']    = 0;
			$progress['matched_count']    = 0;
			$progress['last_msg']        = 'Initialised. Listing Jita contracts…';
			return $progress;
		}

		// ── Listing ─────────────────────────────────────────────────────
		if ($phase === 'listing') {
			$region_id  = (int) ($progress['region_id'] ?? 0);
			$station_id = (int) ($progress['station_id'] ?? 0);
			$page       = (int) ($progress['list_page'] ?? 1);

			$result = ETT_ESI::public_contracts_page($region_id, $page);

			if (!$result['ok'] && $result['rate_limited']) {
				$progress['sleep_until'] = time() + max(5, (int) $result['retry_after']);
				$progress['last_msg']    = 'Rate limited while listing contracts — backing off.';
				return $progress;
			}
			if (!$result['ok']) {
				// A single failed page doesn't abort the whole run — retry
				// the same page next step call rather than skipping it
				// (skipping would silently under-count candidates).
				$progress['last_msg'] = "HTTP {$result['code']} listing contracts page {$page} — retrying.";
				return $progress;
			}

			$progress['list_total_pages'] = max(1, (int) $result['pages']);

			$now = gmdate('Y-m-d H:i:s');
			$stmt = $pdo->prepare(
				'INSERT IGNORE INTO ett_contract_candidates (contract_id, price, seen_at) VALUES (:cid, :price, :seen)'
			);
			$found_this_page = 0;
			foreach ($result['data'] as $contract) {
				if (($contract['type'] ?? '') !== 'item_exchange') continue;
				if ((int) ($contract['start_location_id'] ?? 0) !== $station_id) continue;
				$stmt->execute([
					':cid'   => (int) ($contract['contract_id'] ?? 0),
					':price' => (float) ($contract['price'] ?? 0),
					':seen'  => $now,
				]);
				$found_this_page++;
			}
			$progress['candidates_found'] = (int) ($progress['candidates_found'] ?? 0) + $found_this_page;

			$page++;
			$progress['list_page'] = $page;
			$progress['last_msg']  = "Listing contracts: page " . ($page - 1) . "/{$progress['list_total_pages']}, {$progress['candidates_found']} candidates so far.";

			if ($page > (int) $progress['list_total_pages']) {
				$progress['phase']    = 'checking';
				$progress['last_msg'] = "Listing complete — {$progress['candidates_found']} candidate contracts found. Checking contents…";
			}
			return $progress;
		}

		// ── Checking ────────────────────────────────────────────────────
		if ($phase === 'checking') {
			$stmt = $pdo->prepare(
				'SELECT c.contract_id, c.price
				 FROM ett_contract_candidates c
				 LEFT JOIN ett_contract_resolved r ON r.contract_id = c.contract_id
				 WHERE r.contract_id IS NULL
				 LIMIT ' . (int) $batch_size
			);
			$stmt->execute();
			$batch = $stmt->fetchAll();

			if (empty($batch)) {
				$progress['phase']    = 'aggregating';
				$progress['last_msg'] = "Contents check complete — {$progress['matched_count']} confirmed BPC listings out of {$progress['checked_count']} checked. Aggregating prices…";
				return $progress;
			}

			$now = gmdate('Y-m-d H:i:s');
			$resolve_stmt = $pdo->prepare(
				'INSERT INTO ett_contract_resolved (contract_id, matched_blueprint_type_id, checked_at)
				 VALUES (:cid, :bpid, :now)
				 ON DUPLICATE KEY UPDATE matched_blueprint_type_id = VALUES(matched_blueprint_type_id), checked_at = VALUES(checked_at)'
			);
			$active_stmt = $pdo->prepare(
				'INSERT INTO ett_contract_bpc_active (contract_id, blueprint_type_id, price, runs, material_efficiency, time_efficiency, checked_at)
				 VALUES (:cid, :bpid, :price, :runs, :me, :te, :now)
				 ON DUPLICATE KEY UPDATE blueprint_type_id = VALUES(blueprint_type_id), price = VALUES(price), runs = VALUES(runs), material_efficiency = VALUES(material_efficiency), time_efficiency = VALUES(time_efficiency), checked_at = VALUES(checked_at)'
			);
			$tracked_stmt = $pdo->prepare(
				'SELECT 1 FROM ett_blueprint_products
				 WHERE blueprint_type_id = :bpid
				 LIMIT 1'
			);

			foreach ($batch as $row) {
				$contract_id = (int) $row['contract_id'];
				$price       = (float) $row['price'];
				$progress['checked_count'] = (int) ($progress['checked_count'] ?? 0) + 1;

				$items_result = ETT_ESI::public_contract_items($contract_id);
				if (!$items_result['ok'] && $items_result['rate_limited']) {
					// Bail out of this batch entirely rather than burn through
					// the rest of it while rate-limited — unresolved rows
					// stay unresolved and get retried next step call.
					$progress['sleep_until'] = time() + max(5, (int) $items_result['retry_after']);
					$progress['last_msg']    = 'Rate limited while checking contract contents — backing off.';
					return $progress;
				}

				$included = array_values(array_filter($items_result['data'] ?? [], fn($it) => !empty($it['is_included'])));
				$matched_blueprint_id = null;

				// Multi-item contracts are deliberately skipped, not
				// partially matched — a bundle's price doesn't correspond
				// cleanly to any single item inside it.
				if (count($included) === 1) {
					$item = $included[0];
					if (!empty($item['is_blueprint_copy']) && (int) ($item['runs'] ?? 0) > 0) {
						$bpid = (int) ($item['type_id'] ?? 0);
						$tracked_stmt->execute([':bpid' => $bpid]);
						if ($tracked_stmt->fetch()) {
							$matched_blueprint_id = $bpid;
							$active_stmt->execute([
								':cid'   => $contract_id,
								':bpid'  => $bpid,
								':price' => $price,
								':runs'  => (int) $item['runs'],
								':me'    => (int) ($item['material_efficiency'] ?? 0),
								':te'    => (int) ($item['time_efficiency'] ?? 0),
								':now'   => $now,
							]);
							$progress['matched_count'] = (int) ($progress['matched_count'] ?? 0) + 1;
						}
					}
				}

				$resolve_stmt->execute([
					':cid'  => $contract_id,
					':bpid' => $matched_blueprint_id,
					':now'  => $now,
				]);
			}

			$progress['last_msg'] = "Checked {$progress['checked_count']}/{$progress['candidates_found']} contracts, {$progress['matched_count']} confirmed BPC listings.";
			return $progress;
		}

		// ── Aggregating ─────────────────────────────────────────────────
		if ($phase === 'aggregating') {
			self::aggregate_bpc_prices($pdo);
			$progress['phase']    = 'pruning';
			$progress['last_msg'] = 'Prices computed. Pruning stale contract records…';
			return $progress;
		}

		// ── Pruning ─────────────────────────────────────────────────────
		if ($phase === 'pruning') {
			$pdo->exec(
				'DELETE a FROM ett_contract_bpc_active a
				 LEFT JOIN ett_contract_candidates c ON c.contract_id = a.contract_id
				 WHERE c.contract_id IS NULL'
			);
			$pdo->exec(
				'DELETE r FROM ett_contract_resolved r
				 LEFT JOIN ett_contract_candidates c ON c.contract_id = r.contract_id
				 WHERE c.contract_id IS NULL'
			);
			$progress['phase']    = 'done';
			$progress['last_msg'] = "Contract fetch complete. {$progress['matched_count']} confirmed BPC listings across this run.";
			return $progress;
		}

		$progress['phase'] = 'done';
		return $progress;
	}

	/**
	 * Per blueprint: per-run price = that listing's own price ÷ its own
	 * actual runs (never guessed — read directly from the confirmed
	 * contract data). Outlier rejection happens WITHIN each distinct run
	 * count separately, not across all listings pooled together — a 30-run
	 * copy being cheaper per-run than a 2-run copy is normal bulk pricing
	 * (fixed listing overhead spread across more runs), not a mistake, and
	 * comparing them directly would incorrectly reject a legitimately
	 * cheaper bulk listing as a "troll". Within each run-count bucket,
	 * anything under 50% of that bucket's own median is discarded as a
	 * likely mistake/troll listing; the final price is the minimum across
	 * whatever survives from every bucket.
	 */
	private static function aggregate_bpc_prices(PDO $pdo): void {
		$stmt = $pdo->query('SELECT DISTINCT blueprint_type_id FROM ett_contract_bpc_active');
		$blueprint_ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

		$upsert = $pdo->prepare(
			'INSERT INTO ett_contract_bpc_prices (blueprint_type_id, per_run_price, winning_price, winning_runs, material_efficiency, time_efficiency, sample_count, computed_at)
			 VALUES (:bpid, :price, :wprice, :wruns, :me, :te, :n, :now)
			 ON DUPLICATE KEY UPDATE per_run_price = VALUES(per_run_price), winning_price = VALUES(winning_price), winning_runs = VALUES(winning_runs), material_efficiency = VALUES(material_efficiency), time_efficiency = VALUES(time_efficiency), sample_count = VALUES(sample_count), computed_at = VALUES(computed_at)'
		);
		$now = gmdate('Y-m-d H:i:s');

		foreach ($blueprint_ids as $bpid) {
			$rows_stmt = $pdo->prepare('SELECT price, runs, material_efficiency, time_efficiency FROM ett_contract_bpc_active WHERE blueprint_type_id = :bpid');
			$rows_stmt->execute([':bpid' => $bpid]);

			// Rows are bucketed by run count and carried through as full
			// records (not just a bare per-run float) so that whichever
			// specific listing ends up winning can have its own real price,
			// runs, and ME%/TE% recorded — not just a derived rate. What
			// you'd actually pay is the full price of one whole contract,
			// not a fraction of it, and its ME%/TE% is real research data
			// worth using directly rather than assuming an unresearched copy.
			$by_runs = [];
			$total_sample_count = 0;
			foreach ($rows_stmt->fetchAll() as $row) {
				$runs = (int) $row['runs'];
				if ($runs <= 0) continue; // shouldn't happen (already filtered at checking time), guarded anyway
				$row['per_run'] = (float) $row['price'] / $runs;
				$by_runs[$runs][] = $row;
				$total_sample_count++;
			}
			if (empty($by_runs)) continue;

			$all_survivors = [];
			foreach ($by_runs as $rows) {
				usort($rows, fn($a, $b) => $a['per_run'] <=> $b['per_run']);
				$n = count($rows);

				// A single listing at a given run count has nothing within
				// its own bucket to compare against — it survives by
				// default rather than being (incorrectly) compared against
				// listings of a different run count.
				if ($n === 1) {
					$all_survivors[] = $rows[0];
					continue;
				}

				$per_run_values = array_column($rows, 'per_run');
				$median = ($n % 2 === 1)
					? $per_run_values[intdiv($n, 2)]
					: ($per_run_values[$n / 2 - 1] + $per_run_values[$n / 2]) / 2;

				$cutoff = $median * 0.5;
				$survivors = array_values(array_filter($rows, fn($r) => $r['per_run'] >= $cutoff));
				if (empty($survivors)) $survivors = $rows; // shouldn't happen (median itself always survives), guarded anyway

				foreach ($survivors as $s) $all_survivors[] = $s;
			}

			if (empty($all_survivors)) continue;

			usort($all_survivors, fn($a, $b) => $a['per_run'] <=> $b['per_run']);
			$winner = $all_survivors[0];

			$upsert->execute([
				':bpid'   => $bpid,
				':price'  => $winner['per_run'],
				':wprice' => (float) $winner['price'],
				':wruns'  => (int) $winner['runs'],
				':me'     => (int) $winner['material_efficiency'],
				':te'     => (int) $winner['time_efficiency'],
				':n'      => $total_sample_count,
				':now'    => $now,
			]);
		}
	}
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

	// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_multi_init,WordPress.WP.AlternativeFunctions.curl_curl_init,WordPress.WP.AlternativeFunctions.curl_curl_setopt_array,WordPress.WP.AlternativeFunctions.curl_curl_multi_add_handle,WordPress.WP.AlternativeFunctions.curl_curl_multi_exec,WordPress.WP.AlternativeFunctions.curl_curl_multi_select,WordPress.WP.AlternativeFunctions.curl_curl_multi_getcontent,WordPress.WP.AlternativeFunctions.curl_curl_getinfo,WordPress.WP.AlternativeFunctions.curl_curl_multi_remove_handle,WordPress.WP.AlternativeFunctions.curl_curl_close,WordPress.WP.AlternativeFunctions.curl_curl_multi_close -- wp_remote_get() has no parallel/concurrent mode; curl_multi is required to fire N ESI requests simultaneously.
	/**
	 * Fetch market history for multiple type_ids in parallel, but in small
	 * staggered sub-groups to avoid hammering ESI. Reads X-Esi-Error-Limit-Remain
	 * from response headers and returns early with a 429-like signal if the error
	 * budget is nearly exhausted.
	 *
	 * @param int   $region_id
	 * @param int[] $type_ids
	 * @param int   $concurrency  Max simultaneous connections (default 15)
	 * @param int   $gap_ms       Milliseconds to pause between sub-groups (default 150)
	 * @return array  map of type_id => ['code'=>int, 'data'=>array, 'error_remain'=>int|null]
	 */
	private static function curl_multi_history(int $region_id, array $type_ids, int $concurrency = 3, int $gap_ms = 150) : array {
		$results       = [];
		$chunks        = array_chunk($type_ids, $concurrency);
		$error_remain  = null; // track lowest seen X-Esi-Error-Limit-Remain

		foreach ($chunks as $chunk_idx => $chunk) {
			// If our error budget is critically low, stop early and signal 429 for
			// remaining type_ids so the caller backs off.
			if ($error_remain !== null && $error_remain < 10) {
				foreach ($chunk as $tid) {
					$results[$tid] = ['code' => 429, 'data' => []];
				}
				continue;
			}

			$mh      = curl_multi_init();
			$handles = [];
			$headers = []; // per-handle response headers

			foreach ($chunk as $type_id) {
				$url = "https://esi.evetech.net/latest/markets/{$region_id}/history/?type_id={$type_id}&datasource=tranquility";
				$ch  = curl_init($url);
				$headers[$type_id] = '';
				// Capture response headers via CURLOPT_HEADERFUNCTION
				curl_setopt_array($ch, [
					CURLOPT_RETURNTRANSFER  => true,
					CURLOPT_TIMEOUT         => 30,
					CURLOPT_CONNECTTIMEOUT  => 10,
					CURLOPT_HTTPHEADER      => [
						'Accept: application/json',
						'User-Agent: ETT-Price-Helper/WordPress',
					],
					CURLOPT_HEADERFUNCTION  => function($ch_inner, $header) use ($type_id, &$headers) {
						$headers[$type_id] .= $header;
						return strlen($header);
					},
				]);
				$handles[$type_id] = $ch;
				curl_multi_add_handle($mh, $ch);
			}

			do {
				$status = curl_multi_exec($mh, $active);
				if ($active) curl_multi_select($mh, 1.0);
			} while ($active && $status === CURLM_OK);

			foreach ($handles as $type_id => $ch) {
				$body = curl_multi_getcontent($ch);
				$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
				curl_multi_remove_handle($mh, $ch);
				curl_close($ch);

				// Parse X-Esi-Error-Limit-Remain from captured headers
				if (preg_match('/X-Esi-Error-Limit-Remain:\s*(\d+)/i', $headers[$type_id] ?? '', $m)) {
					$remain = (int) $m[1];
					if ($error_remain === null || $remain < $error_remain) {
						$error_remain = $remain;
					}
				}

				if ($code === 200 && $body !== '') {
					$decoded           = json_decode($body, true);
					$results[$type_id] = [
						'code'         => 200,
						'data'         => is_array($decoded) ? $decoded : [],
						'error_remain' => $error_remain,
					];
				} else {
					$results[$type_id] = [
						'code'         => $code,
						'data'         => [],
						'error_remain' => $error_remain,
					];
				}
			}

			curl_multi_close($mh);

			// Pause between sub-groups (except after the last one)
			if ($chunk_idx < count($chunks) - 1) {
				usleep($gap_ms * 1000);
			}
		}

		return $results;
	}
	// phpcs:enable
}
