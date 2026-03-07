<?php
if (!defined('ABSPATH')) exit;

class ETT_Jobs {
	const JOB_RETENTION_DAYS = 90;
	const OPT_CRON_ACTIVE_JOB_ID         = 'ett_ph_cron_prices_job_id';
	const OPT_CRON_ACTIVE_HISTORY_JOB_ID = 'ett_ph_cron_history_job_id';

	public static function init_cron() {
		add_action('ett_ph_prices_scheduled_start', [__CLASS__, 'cron_prices_scheduled_start'], 10, 1);
		add_action('ett_ph_prices_tick', [__CLASS__, 'cron_prices_tick'], 10, 1);
		add_action('ett_ph_history_tick', [__CLASS__, 'cron_history_tick'], 10, 1);
	}

	public static function activate_cron() {
		self::reschedule_prices_start();
	}

	public static function deactivate_cron() {
		self::unschedule_all('ett_ph_prices_scheduled_start');
		self::unschedule_all('ett_ph_prices_tick');
		self::unschedule_all('ett_ph_history_tick');
		delete_option(self::OPT_CRON_ACTIVE_JOB_ID);
		delete_option(self::OPT_CRON_ACTIVE_HISTORY_JOB_ID);
	}

	private static function unschedule_all(string $hook) : void {
		$cron = _get_cron_array();
		if (!is_array($cron)) return;

		foreach ($cron as $ts => $hooks) {
			if (empty($hooks[$hook]) || !is_array($hooks[$hook])) continue;

			foreach ($hooks[$hook] as $event) {
				$args = (isset($event['args']) && is_array($event['args'])) ? $event['args'] : [];
				wp_unschedule_event((int)$ts, $hook, $args);
			}
		}
	}

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

	public static function next_scheduled_timestamp(string $hook) : int {
		$cron = _get_cron_array();
		if (!is_array($cron)) return 0;

		$next = 0;
		foreach ($cron as $ts => $hooks) {
			if (!isset($hooks[$hook]) || !is_array($hooks[$hook])) continue;
			$ts = (int)$ts;
			if ($next === 0 || $ts < $next) $next = $ts;
		}

		return $next;
	}

	public static function reschedule_prices_start() {
		self::unschedule_all('ett_ph_prices_scheduled_start');

		$start_time = get_option(ETT_Admin::OPT_SCHED_START_TIME, '03:00');
		$next_ts = self::next_occurrence_timestamp($start_time);

		wp_schedule_single_event($next_ts, 'ett_ph_prices_scheduled_start', [$next_ts]);
	}

	public static function cancel_prices_schedule() {
		self::unschedule_all('ett_ph_prices_scheduled_start');
	}

	private static function next_occurrence_timestamp(string $hhmm) : int {
		$tz = wp_timezone();
		$now = new DateTime('now', $tz);

		$parts = explode(':', $hhmm);
		$hh = isset($parts[0]) ? (int)$parts[0] : 3;
		$mm = isset($parts[1]) ? (int)$parts[1] : 0;

		$next = clone $now;
		$next->setTime($hh, $mm, 0);
		if ($next <= $now) $next->modify('+1 day');

		return $next->getTimestamp();
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
		if (!in_array($job_type, ['typeids', 'prices'], true)) wp_send_json_error('Bad job_type', 400);

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
        
        $job_id = self::create_job($pdo, $job_type, 'browser');

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

		$active_id = (string)get_option(self::OPT_CRON_ACTIVE_JOB_ID, '');
		if ($active_id !== '') {
			$job = self::get_job($pdo, $active_id);
			if ($job && in_array($job['status'], ['queued', 'running'], true)) {
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
		}

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
            	WHERE job_type = 'prices'
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
        
        // If this was the cron-active job, stop further cron ticks
        $active = (string)get_option(self::OPT_CRON_ACTIVE_JOB_ID, '');
        if ($active === $job_id) {
        	delete_option(self::OPT_CRON_ACTIVE_JOB_ID);
        }
        
        wp_send_json_success(['status' => 'cancelled']);

	}

	public static function cron_prices_scheduled_start($scheduled_ts) {
		$freq_hours = (int)get_option(ETT_Admin::OPT_SCHED_FREQ_HOURS, 24);
		if ($freq_hours < 1) $freq_hours = 1;
		if ($freq_hours > 168) $freq_hours = 168;

        $next_scheduled = (int)$scheduled_ts;
        $step = $freq_hours * 3600;
        
        // advance in fixed steps until it’s in the future
        do {
        	$next_scheduled += $step;
        } while ($next_scheduled <= time());
        
        wp_schedule_single_event($next_scheduled, 'ett_ph_prices_scheduled_start', [$next_scheduled]);

		$active = (string)get_option(self::OPT_CRON_ACTIVE_JOB_ID, '');
		if ($active !== '') return;

		if (!ETT_ExternalDB::is_configured()) return;

		try {
			ETT_ExternalDB::ensure_schema();
			$pdo = ETT_ExternalDB::pdo();

            $stmt = $pdo->query("
            	SELECT job_id
            	FROM ett_jobs
            	WHERE job_type='prices' AND status IN ('queued','running')
            	ORDER BY started_at DESC
            	LIMIT 1
            ");
            $already = $stmt ? $stmt->fetchColumn() : false;
            if ($already) {
              update_option(self::OPT_CRON_ACTIVE_JOB_ID, (string)$already, false);
              wp_schedule_single_event(time() + 1, 'ett_ph_prices_tick', [(string)$already]);
              return;
            }

            $job_id = self::create_job($pdo, 'prices', 'cron');

			update_option(self::OPT_CRON_ACTIVE_JOB_ID, $job_id, false);

			wp_schedule_single_event(time() + 1, 'ett_ph_prices_tick', [$job_id]);
		} catch (\Throwable $e) {
			delete_option(self::OPT_CRON_ACTIVE_JOB_ID);
		}
	}

	public static function cron_prices_tick($job_id) {
		if (!$job_id) return;

		$active = (string)get_option(self::OPT_CRON_ACTIVE_JOB_ID, '');
		if ($active !== $job_id) return;

		try {
			$pdo = ETT_ExternalDB::pdo();
			$job = self::get_job($pdo, $job_id);
			if (!$job) {
				delete_option(self::OPT_CRON_ACTIVE_JOB_ID);
				return;
			}

			if (in_array($job['status'], ['done', 'error', 'cancelled'], true)) {
				delete_option(self::OPT_CRON_ACTIVE_JOB_ID);
				return;
			}

			self::update_status($pdo, $job_id, 'running');
			$progress = json_decode($job['progress_json'], true) ?: [];

            [$max_pages_per_tick, $max_tick_seconds] = self::get_batch_limits();
            $deadline = microtime(true) + $max_tick_seconds;

            $pages_done = 0;
            $hb = $job['heartbeat_at'] ?? '';

            do {
            	if ($job['job_type'] === 'typeids') {
            		$progress = self::step_typeids($pdo, $progress);
            	} elseif ($job['job_type'] === 'history') {
            		$progress = self::step_history($pdo, $progress);
            	} else {
            		$progress = self::step_prices($pdo, $progress, $job_id);
            	}
            
            	// checkpoint after every page/step so we don't lose progress on a fatal/timeout
            	self::heartbeat($pdo, $job_id, $progress);
            
            	$pages_done++;
            
            	// stop if finished
            	if (($progress['phase'] ?? '') === 'done') break;
            
            	// stop if backoff was set (rate limit or other sleep)
            	$sleep_until = (int)($progress['sleep_until'] ?? 0);
            	if ($sleep_until > time()) break;
            
            } while ($pages_done < $max_pages_per_tick && microtime(true) < $deadline);

            // Record batch info (mirrors ajax_step) to diagnose cron throughput
            if (!isset($progress['details']) || !is_array($progress['details'])) $progress['details'] = [];
            $progress['details']['batch'] = [
                'pages_done'  => (int)$pages_done,
                'max_pages'   => (int)$max_pages_per_tick,
                'max_seconds' => (float)$max_tick_seconds,
                'stopped_by'  => ((int)($progress['sleep_until'] ?? 0) > time()) ? 'sleep_until'
                              : ((microtime(true) >= $deadline) ? 'deadline' : 'pages'),
            ];
            self::heartbeat($pdo, $job_id, $progress);

			if (($progress['phase'] ?? '') === 'done') {
				self::finish($pdo, $job_id, 'done', $progress);
				delete_option(self::OPT_CRON_ACTIVE_JOB_ID);
				return;
			}

			$sleep_until = (int)($progress['sleep_until'] ?? 0);
			$next = time() + 1;
			if ($sleep_until > time()) $next = $sleep_until;

			wp_schedule_single_event($next, 'ett_ph_prices_tick', [$job_id]);
		} catch (\Throwable $e) {
			try {
				$pdo = ETT_ExternalDB::pdo();
				$job = self::get_job($pdo, $job_id);
				$progress = $job ? (json_decode($job['progress_json'], true) ?: []) : [];
				$progress['phase'] = 'error';
				$progress['last_msg'] = 'Error: ' . $e->getMessage();
				$progress['error'] = ['message' => $e->getMessage()];
				self::finish($pdo, $job_id, 'error', $progress, $e->getMessage());
			} catch (\Throwable $e2) {
			}

			delete_option(self::OPT_CRON_ACTIVE_JOB_ID);
		}
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

        if (empty($selected_hubs)) {
            $selected_hubs = array_keys($hubs);
        }

		$secondary_map = get_option(ETT_Admin::OPT_SECONDARY_STRUCTURES, []);
		if (!is_array($secondary_map)) $secondary_map = [];

		$tertiary_map = get_option(ETT_Admin::OPT_TERTIARY_STRUCTURES, []);
		if (!is_array($tertiary_map)) $tertiary_map = [];

		$secondary_pairs = ETT_Admin::secondary_pairs();

		if (($progress['phase'] ?? '') === 'init') {
			$type_count = ETT_TypeIDs::count($pdo);
			if ($type_count <= 0) throw new Exception('No generated typeIDs found. Run "Generate TypeIDs" first.');

			$type_ids = ETT_TypeIDs::all($pdo);
			set_transient('ett_typeids_' . $job_id, $type_ids, 6 * HOUR_IN_SECONDS);

			// NOTE: we do NOT wipe ett_prices here.
			// Prices are written via INSERT … ON DUPLICATE KEY UPDATE (keyed on hub_key + type_id),
			// so each row is overwritten in-place as new data arrives. Rows for types that return
			// no orders in this run are left untouched with their previous fetched_at timestamp,
			// which consumers can use to detect stale data. This avoids a window where the table
			// is empty/incomplete while a run is in progress.

			$progress = [
			    'driver' => $progress['driver'] ?? 'browser',
				'phase' => 'hub',
				'source' => 'primary',
				'secondary_map' => $secondary_map,
				'tertiary_map' => $tertiary_map,
				'job_type' => 'prices',
				'hubs' => $selected_hubs,
				'hub_index' => 0,
				'page' => 1,
				'type_ids_total' => $type_count,
				'orders_seen' => 0,
				'matched_orders' => 0,
				'rows_written' => 0,
				'current_hub' => $selected_hubs[0] ?? null,
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

			if ($source === 'primary' && (int)($o['location_id'] ?? 0) !== $station_id) continue;

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
        		INSERT INTO ett_prices
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

		// Auto-create history job when a prices job completes successfully
		if ($status === 'done' && isset($progress['job_type']) && $progress['job_type'] === 'prices') {
			try {
				$history_job_id = self::create_job($pdo, 'history', $progress['driver'] ?? 'browser');
				$progress['history_job_id'] = $history_job_id;
			} catch (\Throwable $e) {
				self::debug_log('[ETT] Could not create history job: ' . $e->getMessage());
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

				// For cron-driven runs, schedule the history job tick immediately
				$h_id = $progress['history_job_id'] ?? '';
				if ($h_id !== '' && ($progress['driver'] ?? '') === 'cron') {
					update_option(self::OPT_CRON_ACTIVE_HISTORY_JOB_ID, $h_id, false);
					wp_schedule_single_event(time() + 1, 'ett_ph_history_tick', [$h_id]);
				}
			}
        } catch (Exception $e) {
        	self::debug_log('[ETT] finish() housekeeping failed: ' . $e->getMessage());
        }
	}

	private static function create_job(PDO $pdo, string $job_type, string $driver) : string {
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

	// ── History job ────────────────────────────────────────────────────────

	public static function cron_history_tick(string $job_id) : void {
		if (!$job_id) return;

		$active = (string) get_option(self::OPT_CRON_ACTIVE_HISTORY_JOB_ID, '');
		if ($active !== $job_id) return;

		try {
			$pdo = ETT_ExternalDB::pdo();
			$job = self::get_job($pdo, $job_id);

			if (!$job) {
				delete_option(self::OPT_CRON_ACTIVE_HISTORY_JOB_ID);
				return;
			}

			if (in_array($job['status'], ['done', 'error', 'cancelled'], true)) {
				delete_option(self::OPT_CRON_ACTIVE_HISTORY_JOB_ID);
				return;
			}

			self::update_status($pdo, $job_id, 'running');
			$progress = json_decode($job['progress_json'], true) ?: [];

			[$max_pages, $max_seconds] = self::get_batch_limits();
			$deadline   = microtime(true) + $max_seconds;
			$pages_done = 0;

			do {
				$progress = self::step_history($pdo, $progress);
				self::heartbeat($pdo, $job_id, $progress);
				$pages_done++;

				if (($progress['phase'] ?? '') === 'done') break;
			} while ($pages_done < $max_pages && microtime(true) < $deadline);

			if (($progress['phase'] ?? '') === 'done') {
				self::finish($pdo, $job_id, 'done', $progress);
				delete_option(self::OPT_CRON_ACTIVE_HISTORY_JOB_ID);
				return;
			}

			self::heartbeat($pdo, $job_id, $progress);

			$sleep_until = (int)($progress['sleep_until'] ?? 0);
			$next_tick   = ($sleep_until > time()) ? $sleep_until : time() + 1;
			wp_schedule_single_event($next_tick, 'ett_ph_history_tick', [$job_id]);

		} catch (\Throwable $e) {
			self::debug_log('[ETT] cron_history_tick error: ' . $e->getMessage());
			try {
				$pdo = ETT_ExternalDB::pdo();
				$job = self::get_job($pdo, $job_id);
				$progress = $job ? (json_decode($job['progress_json'], true) ?: []) : [];
				$progress['phase']    = 'error';
				$progress['last_msg'] = 'Error: ' . $e->getMessage();
				$progress['error']    = ['message' => $e->getMessage()];
				self::finish($pdo, $job_id, 'error', $progress, $e->getMessage());
			} catch (\Throwable $e2) {}
			delete_option(self::OPT_CRON_ACTIVE_HISTORY_JOB_ID);
		}
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

		return $progress;
	}

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
	private static function step_history(PDO $pdo, array $progress) : array {
		$batch_size = (int) get_option(ETT_Admin::OPT_HISTORY_BATCH_SIZE, 15);
		if ($batch_size < 1)  $batch_size = 1;
		if ($batch_size > 50) $batch_size = 50;

		// ── Init phase: resolve regions + count type_ids ──────────────────
		if (($progress['phase'] ?? 'init') === 'init') {
			$selected_hubs = get_option(ETT_Admin::OPT_SELECTED_HUBS, []);
			if (!is_array($selected_hubs) || empty($selected_hubs)) {
				$selected_hubs = array_keys(ETT_Admin::hubs());
			}

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

		// Parallel ESI fetch
		$results = self::curl_multi_history($region_id, $batch);

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

	// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_multi_init,WordPress.WP.AlternativeFunctions.curl_curl_init,WordPress.WP.AlternativeFunctions.curl_curl_setopt_array,WordPress.WP.AlternativeFunctions.curl_curl_multi_add_handle,WordPress.WP.AlternativeFunctions.curl_curl_multi_exec,WordPress.WP.AlternativeFunctions.curl_curl_multi_select,WordPress.WP.AlternativeFunctions.curl_curl_multi_getcontent,WordPress.WP.AlternativeFunctions.curl_curl_getinfo,WordPress.WP.AlternativeFunctions.curl_curl_multi_remove_handle,WordPress.WP.AlternativeFunctions.curl_curl_close,WordPress.WP.AlternativeFunctions.curl_curl_multi_close -- wp_remote_get() has no parallel/concurrent mode; curl_multi is required to fire N ESI requests simultaneously.
	private static function curl_multi_history(int $region_id, array $type_ids) : array {
		$mh      = curl_multi_init();
		$handles = [];

		foreach ($type_ids as $type_id) {
			$url = "https://esi.evetech.net/latest/markets/{$region_id}/history/?type_id={$type_id}&datasource=tranquility";
			$ch  = curl_init($url);
			curl_setopt_array($ch, [
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 30,
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_HTTPHEADER     => [
					'Accept: application/json',
					'User-Agent: ETT-Price-Helper/WordPress',
				],
			]);
			$handles[$type_id] = $ch;
			curl_multi_add_handle($mh, $ch);
		}

		do {
			$status = curl_multi_exec($mh, $active);
			if ($active) curl_multi_select($mh, 1.0);
		} while ($active && $status === CURLM_OK);

		$results = [];
		foreach ($handles as $type_id => $ch) {
			$body = curl_multi_getcontent($ch);
			$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_multi_remove_handle($mh, $ch);
			curl_close($ch);

			if ($code === 200 && $body !== '') {
				$decoded           = json_decode($body, true);
				$results[$type_id] = [
					'code' => 200,
					'data' => is_array($decoded) ? $decoded : [],
				];
			} else {
				$results[$type_id] = [
					'code' => $code,
					'data' => [],
				];
			}
		}

		curl_multi_close($mh);
		return $results;
	}
	// phpcs:enable
}
