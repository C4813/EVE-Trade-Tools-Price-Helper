<?php
if (!defined('ABSPATH')) exit;

class ETT_Admin {
	const SLUG = 'ett-price-helper';
	const CAP  = 'manage_options';

	const OPT_SELECTED_GROUPS = 'ett_selected_market_groups';
	const OPT_SELECTED_HUBS   = 'ett_selected_hubs';

	const OPT_SECONDARY_STRUCTURES = 'ett_secondary_structures';
	const OPT_TERTIARY_STRUCTURES  = 'ett_tertiary_structures';

	const OPT_SSO_CLIENT_ID     = 'ett_sso_client_id';
	const OPT_SSO_CLIENT_SECRET = 'ett_sso_client_secret';

	const OPT_SSO_ACCESS_TOKEN    = 'ett_sso_access_token';
	const OPT_SSO_REFRESH_TOKEN   = 'ett_sso_refresh_token';
	const OPT_SSO_EXPIRES_AT      = 'ett_sso_expires_at';
	const OPT_SSO_CHARACTER_ID    = 'ett_sso_character_id';
	const OPT_SSO_CHARACTER_NAME  = 'ett_sso_character_name';

	const OPT_SSO_STRUCTURES_CACHE    = 'ett_sso_structures_cache';
	const OPT_SSO_STRUCTURES_CACHE_AT = 'ett_sso_structures_cache_at';
	const OPT_SSO_CORP_CACHE          = 'ett_sso_corp_cache';

	const OPT_LAST_IMPORT = 'ett_fuzzwork_last_import_meta';

	const OPT_LAST_PRICE_RUN   = 'ett_last_price_run_completed_at';
	const OPT_SCHED_START_TIME = 'ett_sched_start_time';
	const OPT_SCHED_FREQ_HOURS = 'ett_sched_freq_hours';

	const OPT_BATCH_MAX_PAGES   = 'ett_batch_max_pages';
	const OPT_BATCH_MAX_SECONDS = 'ett_batch_max_seconds';

	public static function hubs() : array{
		return [
			'jita' => [
				'label'      => 'Jita IV - Moon 4 - Caldari Navy Assembly Plant',
				'region_id'  => 10000002,
				'system_id'  => 30000142,
				'station_id' => 60003760,
			],
			'amarr' => [
				'label'      => 'Amarr VIII (Oris) - Emperor Family Academy',
				'region_id'  => 10000043,
				'system_id'  => 30002187,
				'station_id' => 60008494,
			],
			'rens' => [
				'label'      => 'Rens VI - Moon 8 - Brutor Tribe Treasury',
				'region_id'  => 10000030,
				'system_id'  => 30002510,
				'station_id' => 60004588,
			],
			'dodixie' => [
				'label'      => 'Dodixie IX - Moon 20 - Federation Navy Assembly Plant',
				'region_id'  => 10000032,
				'system_id'  => 30002659,
				'station_id' => 60011866,
			],
			'hek' => [
				'label'      => 'Hek VIII - Moon 12 - Boundless Creation Factory',
				'region_id'  => 10000042,
				'system_id'  => 30002053,
				'station_id' => 60005686,
			],
		];
	}

	public static function secondary_pairs() : array{
		return [
			'jita' => [
				'label'     => 'Perimeter',
				'system_id' => 30000144,
			],
			'amarr' => [
				'label'     => 'Ashab',
				'system_id' => 30003491,
			],
			'rens' => [
				'label'     => 'Frarn',
				'system_id' => 30002526,
			],
			'dodixie' => [
				'label'     => 'Botane',
				'system_id' => 30002661,
			],
			'hek' => [
				'label'     => 'Hek',
				'system_id' => 30002053,
			],
		];
	}

	public static function init(){
		add_action('admin_menu', [__CLASS__, 'menu']);
        add_filter('allowed_redirect_hosts', function($hosts){
        	$hosts[] = 'login.eveonline.com';
        	return $hosts;
        });
		add_action('admin_init', [__CLASS__, 'maybe_disable_cache_for_page']);
		add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);

		add_action('admin_post_ett_save_db', [__CLASS__, 'handle_save_db']);
		add_action('admin_post_ett_save_selection', [__CLASS__, 'handle_save_selection']);
		add_action('wp_ajax_ett_save_selection_ajax', [__CLASS__, 'ajax_save_selection']);
		add_action('wp_ajax_ett_save_hubs_ajax', [__CLASS__, 'ajax_save_hubs']);
		add_action('wp_ajax_ett_save_db_ajax', [__CLASS__, 'ajax_save_db']);
        add_action('wp_ajax_ett_save_schedule_ajax', [__CLASS__, 'ajax_save_schedule']);
        add_action('wp_ajax_ett_next_run_ajax', [__CLASS__, 'ajax_next_run']);
        add_action('wp_ajax_ett_last_price_run_ajax', [__CLASS__, 'ajax_last_price_run']);
        add_action('wp_ajax_ett_cancel_schedule_ajax', [__CLASS__, 'ajax_cancel_schedule']);
		add_action('admin_post_ett_save_sso', [__CLASS__, 'handle_save_sso']);
		add_action('wp_ajax_ett_save_sso_ajax', [__CLASS__, 'ajax_save_sso']);
		add_action('admin_post_ett_sso_start', [__CLASS__, 'handle_sso_start']);
		add_action('admin_post_ett_sso_callback', [__CLASS__, 'handle_sso_callback']);
		add_action('admin_post_ett_sso_disconnect', [__CLASS__, 'handle_sso_disconnect']);
		add_action('wp_ajax_ett_sso_refresh_structures', [__CLASS__, 'ajax_sso_refresh_structures']);
		add_action('admin_post_ett_import_fuzzwork', [__CLASS__, 'handle_import_fuzzwork']);
		add_action('wp_ajax_ett_import_fuzzwork_ajax', [__CLASS__, 'ajax_import_fuzzwork']);
		add_action('admin_post_ett_save_schedule', [__CLASS__, 'handle_save_schedule']);
		add_action('admin_post_ett_save_perf', [__CLASS__, 'handle_save_perf']);
		add_action('wp_ajax_ett_save_perf_ajax', [__CLASS__, 'ajax_save_perf']);
		add_action('wp_ajax_ett_db_status_ajax', [__CLASS__, 'ajax_db_status']);
	}

    private static function clamp_int($v, int $min, int $max, int $fallback) : int {
        $v = is_numeric($v) ? (int)$v : $fallback;
        if ($v < $min) return $min;
        if ($v > $max) return $max;
        return $v;
    }

	public static function ajax_save_selection(){
		if (!current_user_can(self::CAP)) wp_send_json_error('Insufficient permissions', 403);
		check_ajax_referer('ett_admin');

		$groups = isset($_POST['groups']) ? array_map('intval', (array) wp_unslash($_POST['groups'])) : [];
        update_option(self::OPT_SELECTED_GROUPS, $groups, false);

		wp_send_json_success([
			'saved'        => true,
			'groups_count' => count($groups),
			'hubs_count'   => null,
		]);
	}

	private static function debug_log(string $msg) : void {
		if (defined('WP_DEBUG') && WP_DEBUG) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log($msg);
		}
	}


	public static function ajax_save_hubs(){
		if (!current_user_can(self::CAP)) wp_send_json_error('Insufficient permissions', 403);
		check_ajax_referer('ett_admin');

		$hubs = isset($_POST['hubs'])
            ? array_map('sanitize_key', (array) wp_unslash($_POST['hubs']))
            : [];
		$valid_hubs = array_keys(self::hubs());
		$hubs       = array_values(array_intersect($hubs, $valid_hubs));
		if (empty($hubs)) $hubs = $valid_hubs;

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per element via absint() below
        $secondary_in = isset($_POST['secondary_structure']) ? (array) wp_unslash($_POST['secondary_structure']) : [];
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per element via absint() below
        $tertiary_in  = isset($_POST['tertiary_structure']) ? (array) wp_unslash($_POST['tertiary_structure']) : [];

		$secondary_out = [];
		$tertiary_out  = [];

		foreach ($valid_hubs as $hub_key){
			$secondary_out[$hub_key] = isset($secondary_in[$hub_key]) ? absint($secondary_in[$hub_key]) : 0;
			$tertiary_out[$hub_key]  = isset($tertiary_in[$hub_key]) ? absint($tertiary_in[$hub_key]) : 0;

			if ($tertiary_out[$hub_key] > 0 && $tertiary_out[$hub_key] === $secondary_out[$hub_key]){
				$tertiary_out[$hub_key] = 0;
			}
		}

        update_option(self::OPT_SELECTED_HUBS, $hubs, false);
        update_option(self::OPT_SECONDARY_STRUCTURES, $secondary_out, false);
        update_option(self::OPT_TERTIARY_STRUCTURES, $tertiary_out, false);

		wp_send_json_success([
			'saved'     => true,
			'hubs_count'=> count($hubs),
		]);
	}

    public static function ajax_save_db(){
    	if (!current_user_can(self::CAP)){
    		wp_send_json_error('Insufficient permissions', 403);
    	}
    	check_ajax_referer('ett_admin');
    
        $host   = sanitize_text_field(wp_unslash($_POST['host'] ?? ''));
        $port   = self::clamp_int(absint(wp_unslash($_POST['port'] ?? 3306)), 1, 65535, 3306);
        $dbname = sanitize_text_field(wp_unslash($_POST['dbname'] ?? ''));
        $user   = sanitize_text_field(wp_unslash($_POST['user'] ?? ''));
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- password is stored encrypted; do not mangle characters
        $pass   = (string) wp_unslash($_POST['pass'] ?? '');

    	$existing = ETT_ExternalDB::get();
    
    	if ($pass === ''){
    		update_option(ETT_ExternalDB::OPT, [
    			'host'     => $host,
    			'port'     => $port,
    			'dbname'   => $dbname,
    			'user'     => $user,
    			'pass_enc' => $existing['pass_enc'],
    			'pass_iv'  => $existing['pass_iv'],
    			'pass_mac' => $existing['pass_mac'] ?? '',
    		], false);
    	} else {
    		ETT_ExternalDB::save($host, $port, $dbname, $user, $pass);
    	}
    
    	try {
    		if (ETT_ExternalDB::is_configured()){
    			ETT_ExternalDB::ensure_schema();
    		}
    	} catch (Exception $e){
    		wp_send_json_error($e->getMessage(), 400);
    	}
    
    	wp_send_json_success(['saved' => true]);
    }
    
    public static function ajax_db_status(){
    	if (!current_user_can(self::CAP)){
    		wp_send_json_error('Insufficient permissions', 403);
    	}
    	check_ajax_referer('ett_admin');
    
    	$db_test   = null;
    	$schema_ok = false;
    
    	if (ETT_ExternalDB::is_configured()){
    		$db_test = ETT_ExternalDB::test_connection();
    
    		if (!empty($db_test['ok'])){
    			try {
    				ETT_ExternalDB::ensure_schema();
    				$schema_ok = true;
    			} catch (Exception $e){
    				// If schema fails but DB connects, surface it as schema not ok
    				$schema_ok = false;
    				$db_test = [
    					'ok'      => false,
    					'message' => 'Schema error: ' . $e->getMessage(),
    				];
    			}
    		}
    	}
    
    	wp_send_json_success([
    		'db_test'   => $db_test,   // null or ['ok'=>bool,'message'=>string]
    		'schema_ok' => $schema_ok,
    	]);
    }

    public static function ajax_save_schedule(){
    	if (!current_user_can(self::CAP)){
    		wp_send_json_error('Insufficient permissions', 403);
    	}
    	check_ajax_referer('ett_admin');
    
    	self::save_schedule_from_request($_POST);
    
    	ETT_Jobs::reschedule_prices_start();
    
    	$tz = wp_timezone_string();
    	$next_ts  = ETT_Jobs::next_scheduled_timestamp('ett_ph_prices_scheduled_start');
    	$next_ts  = $next_ts > 0 ? $next_ts : false;
    	$next_txt = 'Not scheduled';
    
    	if ($next_ts){
    		try {
    			$dt = new DateTime('@' . (int)$next_ts);
    			$dt->setTimezone(wp_timezone());
    			$next_txt = $dt->format('Y-m-d H:i:s') . " ({$tz})";
    		} catch (Exception $e){
    			$next_txt = gmdate('Y-m-d H:i:s', (int)$next_ts) . " ({$tz})";
    		}
    	}
    
    	wp_send_json_success([
    		'saved'    => true,
    		'next_txt' => $next_txt,
    	]);
    }

    public static function ajax_cancel_schedule(){
    	if (!current_user_can(self::CAP)){
    		wp_send_json_error('Insufficient permissions', 403);
    	}
    	check_ajax_referer('ett_admin');
    
    	ETT_Jobs::cancel_prices_schedule();
    
    	wp_send_json_success([
    		'cancelled' => true,
    		'next_txt'  => 'Not scheduled',
    	]);
    }

    public static function ajax_next_run(){
    	if (!current_user_can(self::CAP)){
    		wp_send_json_error('Insufficient permissions', 403);
    	}
    	check_ajax_referer('ett_admin');
    
    	$tz = wp_timezone_string();
    	$next_ts  = ETT_Jobs::next_scheduled_timestamp('ett_ph_prices_scheduled_start');
    	$next_ts  = $next_ts > 0 ? $next_ts : false;
    	$next_txt = 'Not scheduled';
    
    	if ($next_ts){
    		try {
    			$dt = new DateTime('@' . (int)$next_ts);
    			$dt->setTimezone(wp_timezone());
    			$next_txt = $dt->format('Y-m-d H:i:s') . " ({$tz})";
    		} catch (Exception $e){
    			$next_txt = gmdate('Y-m-d H:i:s', (int)$next_ts) . " ({$tz})";
    		}
    	}
    
    	wp_send_json_success([
    		'next_txt' => $next_txt,
    	]);
    }

    public static function ajax_last_price_run(){
    	if (!current_user_can(self::CAP)){
    		wp_send_json_error('Insufficient permissions', 403);
    	}
    	check_ajax_referer('ett_admin');
    
    	$last = get_option(self::OPT_LAST_PRICE_RUN, '');
    	$tz = wp_timezone_string();
    	$tz = $tz ? $tz : 'UTC';
    
    	if ($last){
    		$txt = $last . ' (' . $tz . ')';
    	} else {
    		$txt = 'Never';
    	}
    
    	wp_send_json_success([
    		'last_txt' => $txt,
    		'last' => $last,
    	]);
    }

	public static function menu(){
		add_menu_page(
			'ETT Price Helper',
			'ETT Prices',
			self::CAP,
			self::SLUG,
			[__CLASS__, 'render'],
			'dashicons-database'
		);
	}

	public static function maybe_disable_cache_for_page(){
		if (!is_admin()) return;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- admin routing param, not an action
		$page = sanitize_key(wp_unslash($_GET['page'] ?? ''));
        if ($page !== self::SLUG) return;

		if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
		if (!defined('DONOTCACHEDB')) define('DONOTCACHEDB', true);
		if (!defined('DONOTCACHEOBJECT')) define('DONOTCACHEOBJECT', true);

        if (function_exists('nocache_headers')) nocache_headers();
        
        if (!headers_sent()) {
        	header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        	header('Pragma: no-cache');
        	header('Expires: 0');
        }
	}

	public static function enqueue($hook){
		if (!is_admin()) return;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- admin routing param, not an action
		$page = sanitize_key(wp_unslash($_GET['page'] ?? ''));
        if ($page !== self::SLUG) return;

		$css_file = ETT_PH_PATH . 'assets/admin.css';
		$js_file  = ETT_PH_PATH . 'assets/admin.js';
		$css_ver  = file_exists($css_file) ? (string)filemtime($css_file) : '1';
		$js_ver   = file_exists($js_file) ? (string)filemtime($js_file) : '1';

		wp_enqueue_style('ett-admin', ETT_PH_URL . 'assets/admin.css', [], $css_ver);
		wp_enqueue_script('ett-admin', ETT_PH_URL . 'assets/admin.js', ['jquery'], $js_ver, true);

		$last = get_option(self::OPT_LAST_PRICE_RUN, '');
		$last_iso = '';
        if (is_string($last) && $last !== '') {
            try {
                $dt = DateTime::createFromFormat('Y-m-d H:i:s', $last, wp_timezone());
                if ($dt instanceof DateTime) $last_iso = $dt->format(DATE_ATOM);
            } catch (Exception $e) {}
        }
		$tz   = wp_timezone_string();
		$tz   = $tz ? $tz : 'UTC';

		$access = self::decrypt_secret(
			(string)get_option(self::OPT_SSO_ACCESS_TOKEN, ''),
			(string)get_option(self::OPT_SSO_ACCESS_TOKEN . '_iv', ''),
			(string)get_option(self::OPT_SSO_ACCESS_TOKEN . '_mac', '')
		);
		$refresh = self::decrypt_secret(
			(string)get_option(self::OPT_SSO_REFRESH_TOKEN, ''),
			(string)get_option(self::OPT_SSO_REFRESH_TOKEN . '_iv', ''),
			(string)get_option(self::OPT_SSO_REFRESH_TOKEN . '_mac', '')
		);
		$expires_at = (int)get_option(self::OPT_SSO_EXPIRES_AT, 0);

		$sso_authed = (!empty($access) && $expires_at > (time() + 30));
		if (!$sso_authed && !empty($refresh)){
			$tok = self::ensure_access_token();
			if (!empty($tok['ok'])){
				$access     = $tok['access'] ?? $access;
				$expires_at = (int)get_option(self::OPT_SSO_EXPIRES_AT, 0);
				$sso_authed = (!empty($access) && $expires_at > (time() + 30));
			}
		}

		$char_name = get_option(self::OPT_SSO_CHARACTER_NAME, '');
		$cache_at  = (int)get_option(self::OPT_SSO_STRUCTURES_CACHE_AT, 0);

		wp_localize_script('ett-admin', 'ETT_ADMIN', [
			'ajax_url'                   => admin_url('admin-ajax.php'),
			'nonce'                      => wp_create_nonce('ett_admin'),
			'last_price_run_completed_at'=> $last,
			'wp_timezone_string'         => $tz,
			'sso_authed'                 => $sso_authed,
			'sso_character_name'         => $char_name,
			'sso_cache_at'               => $cache_at,
			'secondary_pairs'            => self::secondary_pairs(),
			'last_price_run_completed_at_iso' => $last_iso,
		]);
	}

	public static function render(){
		if (!current_user_can(self::CAP)) wp_die('Insufficient permissions.');

		$db                  = ETT_ExternalDB::get();
		$selected_groups      = get_option(self::OPT_SELECTED_GROUPS, []);
		$selected_hubs        = get_option(self::OPT_SELECTED_HUBS, array_keys(self::hubs()));
		$secondary_structures = get_option(self::OPT_SECONDARY_STRUCTURES, []);
		$tertiary_structures  = get_option(self::OPT_TERTIARY_STRUCTURES, []);

		$client_id = get_option(self::OPT_SSO_CLIENT_ID, '');
		$client_secret = self::decrypt_secret(
			(string)get_option(self::OPT_SSO_CLIENT_SECRET, ''),
			(string)get_option(self::OPT_SSO_CLIENT_SECRET . '_iv', ''),
			(string)get_option(self::OPT_SSO_CLIENT_SECRET . '_mac', '')
		);

		$access = self::decrypt_secret(
			(string)get_option(self::OPT_SSO_ACCESS_TOKEN, ''),
			(string)get_option(self::OPT_SSO_ACCESS_TOKEN . '_iv', ''),
			(string)get_option(self::OPT_SSO_ACCESS_TOKEN . '_mac', '')
		);

		$refresh = self::decrypt_secret(
			(string)get_option(self::OPT_SSO_REFRESH_TOKEN, ''),
			(string)get_option(self::OPT_SSO_REFRESH_TOKEN . '_iv', ''),
			(string)get_option(self::OPT_SSO_REFRESH_TOKEN . '_mac', '')
		);

		$expires_at  = (int)get_option(self::OPT_SSO_EXPIRES_AT, 0);
		$sso_authed  = (!empty($access) && $expires_at > (time() + 30));

		if (!$sso_authed && !empty($refresh)){
			$tok = self::ensure_access_token();
			if (!empty($tok['ok'])){
				$access     = $tok['access'] ?? $access;
				$expires_at = (int)get_option(self::OPT_SSO_EXPIRES_AT, 0);
				$sso_authed = (!empty($access) && $expires_at > (time() + 30));
			}
		}

		$has_token  = (!empty($access) || !empty($refresh));
		$is_expired = $has_token && !$sso_authed;

		$char_name = get_option(self::OPT_SSO_CHARACTER_NAME, '');
		$cache     = get_option(self::OPT_SSO_STRUCTURES_CACHE, []);
		if (!is_array($cache)) $cache = [];
		$cache_at  = (int)get_option(self::OPT_SSO_STRUCTURES_CACHE_AT, 0);

		$import_meta       = get_option(self::OPT_LAST_IMPORT, []);
		$sched_start_time  = get_option(self::OPT_SCHED_START_TIME, '03:00');
		$sched_freq_hours  = (int)get_option(self::OPT_SCHED_FREQ_HOURS, 24);

		$batch_max_pages   = (int)get_option(self::OPT_BATCH_MAX_PAGES, 5);
		$batch_max_seconds = (int)get_option(self::OPT_BATCH_MAX_SECONDS, 10);

		if ($batch_max_pages < 1) $batch_max_pages = 1;
		if ($batch_max_pages > 50) $batch_max_pages = 50;

		if ($batch_max_seconds < 1) $batch_max_seconds = 1;
		if ($batch_max_seconds > 25) $batch_max_seconds = 25;

		$tz = wp_timezone_string();
		$tz = $tz ? $tz : 'UTC';

		$job_history     = [];
		$job_history_err = null;

		try {
			if (ETT_ExternalDB::is_configured()){
				$pdo = ETT_ExternalDB::pdo();
				$stmt = $pdo->query(
					"SELECT job_id, job_type, status, started_at, finished_at, heartbeat_at, last_error, progress_json
					FROM ett_jobs
					WHERE job_type = 'prices'
						AND status IN ('done','error','cancelled')
					ORDER BY finished_at DESC
					LIMIT 25"
				);
				$job_history = $stmt ? $stmt->fetchAll() : [];
			}
		} catch (Exception $e){
			$job_history_err = $e->getMessage();
			$job_history = [];
		}

		$pdo          = null;
		$tree         = [];
		$typeid_count = null;
		$db_test      = null;
		$schema_ok    = false;

		if (ETT_ExternalDB::is_configured()){
			$db_test = ETT_ExternalDB::test_connection();
			if ($db_test['ok']){
				try {
					$pdo = ETT_ExternalDB::pdo();
					ETT_ExternalDB::ensure_schema();
					$schema_ok = true;

					try { $tree = ETT_Market::get_tree($pdo); } catch (Exception $e){ $tree = []; }
					try { $typeid_count = ETT_TypeIDs::count($pdo); } catch (Exception $e){ $typeid_count = null; }
				} catch (Exception $e){
					$schema_ok = false;
					$db_test = ['ok' => false, 'message' => 'Schema error: ' . $e->getMessage()];
				}
			}
		}

		$typeid_display = ($typeid_count !== null) ? number_format((int)$typeid_count) : '—';
		?>
		<div class="wrap ett-wrap">
			<h1>ETT Price Helper</h1>

			<div class="ett-grid">
				<div class="ett-card">
					<h2>External Database</h2>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="ett-db-form">
						<?php wp_nonce_field('ett_save_db'); ?>
						<input type="hidden" name="action" value="ett_save_db"/>

						<div class="ett-row">
							<label>Host</label>
							<input type="text" name="host" value="<?php echo esc_attr($db['host']); ?>"/>
						</div>

						<div class="ett-row">
							<label>Port</label>
							<input type="number" name="port" value="<?php echo esc_attr($db['port']); ?>"/>
						</div>

						<div class="ett-row">
							<label>Database Name</label>
							<input type="text" name="dbname" value="<?php echo esc_attr($db['dbname']); ?>"/>
						</div>

						<div class="ett-row">
							<label>Database User Name</label>
							<input type="text" name="user" value="<?php echo esc_attr($db['user']); ?>"/>
						</div>

						<div class="ett-row">
							<label>Password</label>
							<input type="password" name="pass" value="" placeholder="(leave blank to keep existing)"/>
							<p class="description">Password is stored encrypted in wp_options. Leave blank to keep current.</p>
						</div>

						<?php submit_button('Save DB Settings', 'primary', 'submit', false); ?>
					</form>

                    <div class="ett-status">
                    	<p><strong>Status:</strong>
                    		<?php if (!$db_test): ?>
                    			<span id="ett-db-status-text" class="ett-bad">Not configured.</span>
                    		<?php else: ?>
                    			<span id="ett-db-status-text" class="<?php echo esc_attr($db_test['ok'] ? 'ett-ok' : 'ett-bad'); ?>">
                    				<?php echo esc_html($db_test['message']); ?>
                    			</span>
                    		<?php endif; ?>
                    	</p>
                    
                    	<p><strong>Schema:</strong>
                    		<span id="ett-db-schema-text" class="<?php echo esc_attr($schema_ok ? 'ett-ok' : 'ett-bad'); ?>">
                    			<?php echo $schema_ok ? 'Ready' : 'Not ready'; ?>
                    		</span>
                    	</p>
                    </div>
				</div>

				<div class="ett-card">
					<h2>Fuzzwork Import</h2>

                    <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice flag
                    if (!empty($_GET['db_err'])): ?>
                    	<div class="notice notice-error">
                    		<p><strong>DB Error:</strong>
                    			<?php
                    			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice content
                    			echo esc_html(sanitize_text_field(wp_unslash($_GET['db_err'])));
                    			?>
                    		</p>
                    	</div>
                    <?php endif; ?>
                    
                    <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice flag
                    if (!empty($_GET['err'])): ?>
                    	<div class="notice notice-error">
                    		<p><strong>Error:</strong>
                    			<?php
                    			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice content
                    			echo esc_html(sanitize_text_field(wp_unslash($_GET['err'])));
                    			?>
                    		</p>
                    	</div>
                    <?php endif; ?>

					<p>Imports the following tables from Fuzzwork (<code>/dump/latest/</code>) into the external DB:</p>
					<ul class="ett-list-disc">
						<li><code>invMarketGroups</code></li>
						<li><code>invTypes</code> (nodescription CSV)</li>
						<li><code>invMetaGroups</code></li>
						<li><code>invMetaTypes</code></li>
						<li><code>industryActivityProducts</code> (blueprint activity outputs)</li>
						<li><code>invTypeMaterials</code> (CSV bz2)</li>
					</ul>

					<p class="description">
						This data is used to build market group selection, generate the typeID list, and persist
						<code>meta_tier</code> (T1/Meta/T2/Faction/Deadspace/Officer/Other).
					</p>

					<p><i>
						It is only necessary to run this once after plugin activation, and thereafter when
						<a href="https://www.fuzzwork.co.uk/dump/latest/">fuzzwork.co.uk/dump/latest/</a> is updated.
					</i></p>

                    <?php
                    $last_import_txt = !empty($import_meta['imported_at']) ? (string)$import_meta['imported_at'] : 'Never';
                    
                    $parts = [];
                    foreach (['invMarketGroups','invTypes','invMetaGroups','invMetaTypes','industryActivityProducts','invTypeMaterials'] as $k){
                    	if (isset($import_meta[$k])) $parts[] = $k . ': ' . number_format((int)$import_meta[$k]);
                    }
                    $details_txt = !empty($parts) ? implode(' | ', $parts) : '';
                    ?>
                    
                    <p><strong>Last import:</strong> <span id="ett-last-import"><?php echo esc_html($last_import_txt); ?></span></p>
                    
                    <div class="ett-muted ett-mt-6<?php echo $details_txt ? '' : ' ett-hidden'; ?>" id="ett-last-import-details-wrap">
                    	<p><strong>Last import details:</strong> <span id="ett-last-import-details"><?php echo esc_html($details_txt); ?></span></p>
                    </div>

					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="ett-fuzzwork-form">
						<?php wp_nonce_field('ett_import_fuzzwork'); ?>
						<input type="hidden" name="action" value="ett_import_fuzzwork"/>
						<?php submit_button('Import from Fuzzwork (latest)', 'secondary', 'submit', false); ?>
					</form>
				</div>
			</div>

			<div class="ett-grid">
				<div class="ett-card">
					<h2>EVE SSO</h2>

					<div class="ett-muted ett-muted-block">
						<p><strong>Create an EVE Developers application</strong></p>
						<ol class="ett-list-decimal">
							<li>Go to <a href="https://developers.eveonline.com">https://developers.eveonline.com</a> and log in.</li>
							<li>Create a new application.</li>
							<li>Set the application <strong>Callback URL</strong> to the value shown below (exact match required).</li>
							<div class="ett-row">
								<label>Callback URL</label>
								<input type="text" readonly value="<?php echo esc_attr(admin_url('admin-post.php?action=ett_sso_callback')); ?>"/>
							</div>
							<li>Set the application <strong>Scopes</strong> to the following (required by this plugin):</li>
						</ol>

						<ul class="ett-list-disc ett-tight">
							<li><code>esi-universe.read_structures.v1</code></li>
							<li><code>esi-markets.structure_markets.v1</code></li>
							<li><code>esi-search.search_structures.v1</code></li>
						</ul>

						<p class="description ett-mt-8">
							After creating the app, copy the Client ID and Secret into this page and click “Save SSO Settings”, then “Connect EVE SSO”.
						</p>
					</div>

					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="ett-sso-form">
						<?php wp_nonce_field('ett_save_sso'); ?>
						<input type="hidden" name="action" value="ett_save_sso"/>

						<div class="ett-row">
							<label>Client ID</label>
							<input type="text" name="ett_sso_client_id" value="<?php echo esc_attr($client_id); ?>" placeholder="SSO application Client ID"/>
						</div>

						<div class="ett-row">
							<label>Client Secret</label>
							<input type="password" name="ett_sso_client_secret" value="<?php echo esc_attr($client_secret); ?>" placeholder="SSO application Secret Key"/>
						</div>

						<?php submit_button('Save SSO Settings', 'secondary', 'submit', false); ?>
					</form>

					<div class="ett-mt-10">
						<?php if ($sso_authed): ?>
							<div class="ett-status ett-sso-status ett-ok">
								<strong>Status:</strong>
								Authenticated<?php echo $char_name ? ' as ' . esc_html($char_name) : ''; ?>.
							</div>

                        <div class="ett-actions ett-mt-10">
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ett-inline-form">
                                <?php wp_nonce_field('ett_sso_disconnect'); ?>
                                <input type="hidden" name="action" value="ett_sso_disconnect"/>
                                <button type="submit" class="button">Disconnect</button>
                            </form>
                        </div>

						<?php else: ?>
							<div class="ett-status ett-sso-status ett-bad">
								<strong>Status:</strong>
								Not authenticated. Secondary Market dropdowns are disabled.
							</div>

							<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ett-mt-10">
								<?php wp_nonce_field('ett_sso_start'); ?>
								<input type="hidden" name="action" value="ett_sso_start"/>
                                <button id="ett-btn-sso-connect" type="submit" class="button button-primary" <?php disabled(empty($client_id) || empty($client_secret)); ?>>
                                	Connect EVE SSO
                                </button>
								<?php if (empty($client_id) || empty($client_secret)): ?>
									<p class="description" id="ett-sso-connect-help">Enter Client ID and Secret, save, then connect.</p>
								<?php endif; ?>
							</form>
						<?php endif; ?>
					</div>
				</div>

				<div class="ett-card">
					<h2>Market Groups</h2>
					<p>Select market groups to define which typeIDs will be generated.</p>

					<div class="ett-warning-box">
						<strong>Warning:</strong> Selecting a large number of market groups — especially all groups — can generate a very large typeID list and significantly increase database load and price run duration.
						<br><br>
						Only select the market groups you actually require.
					</div>

					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="ett-selection-form">
						<?php wp_nonce_field('ett_save_selection'); ?>
						<input type="hidden" name="action" value="ett_save_selection"/>

						<div class="ett-grid">
							<div>
								<label><strong>Filter</strong></label>
								<p class="ett-mt-6 ett-mb-0">
									<input type="text" id="ett-mg-filter" placeholder="Type to filter market groups..."/>
								</p>
							</div>
						</div>

						<div class="ett-tree" id="ett-mg-tree">
							<?php
							if (!$schema_ok){
								echo '<p class="ett-muted">Configure external DB + import Fuzzwork to load market groups.</p>';
							} else {
								self::render_tree($tree, $selected_groups);
							}
							?>
						</div>

						<?php
						$btn_attrs = ['id' => 'ett-save-selection'];
						if (empty($selected_groups)) $btn_attrs['disabled'] = 'disabled';
						submit_button('Save Selection', 'primary', 'submit', false, $btn_attrs);
						?>
					</form>

					<div class="ett-actions ett-mg-actions">
						<button class="button button-secondary" id="ett-btn-generate" type="button" <?php disabled(!$schema_ok); ?>>Generate TypeIDs</button>
					</div>

					<p class="description ett-mt-8">
						<strong>Generate TypeIDs</strong> saves a static list of typeIDs for the currently selected market groups.
					</p>

					<div class="ett-typeid-count">
						<strong>Currently Stored TypeIDs:</strong>
						<span id="ett-current-typeids"><?php echo esc_html($typeid_display); ?></span>
					</div>
				</div>
			</div>

			<div class="ett-card">
				<h2>Trade Hubs</h2>
				<p>Select trade hubs to call market data from.</p>
				<p>Secondary/Tertiary Market dropdown is filtered to the paired system and requires SSO + refreshed structures.</p>
				<p>Paired Systems: Jita/Perimeter, Amarr/Ashab, Rens/Frarn, Dodixie/Botane, Hek/Hek</p>
				<p><i>If you cannot find the structure you are looking for, <b>the character you have authed with above requires docking access to that structure.</b></i></p>

				<div class="ett-hub-row ett-hub-head">
					<div class="ett-hub-check"><strong>Hub</strong></div>
					<div class="ett-hub-secondary"><strong>Secondary Market</strong></div>
					<div class="ett-hub-tertiary"><strong>Tertiary Market</strong></div>
				</div>

				<form method="post" action="#" id="ett-hubs-form">
					<div class="ett-hubs">
						<?php
						$pairs = self::secondary_pairs();
						foreach (self::hubs() as $key => $hub):
							$is_checked        = in_array($key, $selected_hubs, true);
							$selected_structure = isset($secondary_structures[$key]) ? (int)$secondary_structures[$key] : 0;
							$selected_tertiary  = isset($tertiary_structures[$key]) ? (int)$tertiary_structures[$key] : 0;

							$paired_system_id = isset($pairs[$key]['system_id']) ? (int)$pairs[$key]['system_id'] : 0;

							$choices = [];
							if ($paired_system_id && !empty($cache)){
								foreach ($cache as $st){
									if (!is_array($st)) continue;
									if (empty($st['structure_id']) || empty($st['name']) || empty($st['solar_system_id'])) continue;
									if ((int)$st['solar_system_id'] !== $paired_system_id) continue;
									$choices[] = $st;
								}
							}

							$disable_secondary = (!$is_checked) || (!$sso_authed) || empty($cache);
							$disable_tertiary  = $disable_secondary;
							?>
							<div class="ett-hub-row">
								<label class="ett-hub-check">
									<input type="checkbox" name="ett_hubs[]" value="<?php echo esc_attr($key); ?>" <?php checked($is_checked); ?> />
									<?php echo esc_html($hub['label']); ?>
								</label>

								<select name="ett_secondary_structure[<?php echo esc_attr($key); ?>]" class="ett-hub-secondary" <?php disabled($disable_secondary); ?>>
									<option value="0" <?php selected($selected_structure, 0); ?>>
										<?php
										if (!$sso_authed) echo 'Authenticate to load structures';
										else if (empty($cache)) echo 'Click “Refresh structures”';
										else echo 'No secondary market';
										?>
									</option>

									<?php foreach ($choices as $st):
										$sid    = (int)$st['structure_id'];
										$nm     = (string)$st['name'];
										$ticker = isset($st['owner_ticker']) ? trim((string)$st['owner_ticker']) : '';
										$owner  = isset($st['owner_name']) ? trim((string)$st['owner_name']) : '';

										$suffix = '';
										if ($ticker !== '' && $owner !== '') $suffix = ' — [' . $ticker . '] ' . $owner;
										else if ($owner !== '') $suffix = ' — ' . $owner;

										$label = $nm . $suffix;
										?>
										<option value="<?php echo esc_attr($sid); ?>" <?php selected($selected_structure, $sid); ?>>
											<?php echo esc_html($label); ?>
										</option>
									<?php endforeach; ?>
								</select>

								<select name="ett_tertiary_structure[<?php echo esc_attr($key); ?>]" class="ett-hub-tertiary" <?php disabled($disable_tertiary); ?>>
									<option value="0" <?php selected($selected_tertiary, 0); ?>>
										<?php
										if (!$sso_authed) echo 'Authenticate to load structures';
										else if (empty($cache)) echo 'Click “Refresh structures”';
										else echo 'No tertiary market';
										?>
									</option>

									<?php foreach ($choices as $st):
										$sid    = (int)$st['structure_id'];
										$nm     = (string)$st['name'];
										$ticker = isset($st['owner_ticker']) ? trim((string)$st['owner_ticker']) : '';
										$owner  = isset($st['owner_name']) ? trim((string)$st['owner_name']) : '';

										$suffix = '';
										if ($ticker !== '' && $owner !== '') $suffix = ' — [' . $ticker . '] ' . $owner;
										else if ($owner !== '') $suffix = ' — ' . $owner;

										$label = $nm . $suffix;
										?>
										<option value="<?php echo esc_attr($sid); ?>" <?php selected($selected_tertiary, $sid); ?>>
											<?php echo esc_html($label); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
						<?php endforeach; ?>
					</div>
					
                    <?php
                    $refresh_disabled = !$sso_authed;
                    ?>
                    <div class="ett-actions ett-mt-10">
                        <button type="button" class="button button-secondary" id="ett-btn-refresh-structures" <?php disabled($refresh_disabled); ?>>Refresh structures</button>
                    </div>
                    
                    <p class="description" id="ett-structures-cache-meta">
                        <?php
                        if (!$sso_authed){
                            echo 'Authenticate first to refresh structures.';
                        } else if ($cache_at){
                            echo 'Last refreshed: ' . esc_html(gmdate('Y-m-d H:i:s', $cache_at)) . ' UTC. Cached structures: ' . esc_html((string)count($cache)) . '.';
                        } else {
                            echo 'Structures have not been refreshed yet.';
                        }
                        ?>
                    </p>

					<?php submit_button('Save Trade Hubs', 'primary', 'submit', false, ['id' => 'ett-save-hubs']); ?>
				</form>
			</div>

			<?php $lastRun = get_option(self::OPT_LAST_PRICE_RUN, ''); ?>
			<div class="ett-card">
				<h2>Actions</h2>

				<p><strong>Run Prices</strong> pulls prices only for the already generated typeID list.</p>

				<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice flag
					if (!empty($_GET['perf_saved'])): ?>
					<div class="notice notice-success">
						<p><strong>Saved:</strong> Performance settings updated.</p>
					</div>
				<?php endif; ?>
                
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="ett-perf-form">
                    <?php wp_nonce_field('ett_perf', 'ett_perf_nonce'); ?>
                    <input type="hidden" name="action" value="ett_save_perf"/>

                    <details class="ett-details">
                        <summary class="ett-summary">Advanced performance</summary>

						<div class="ett-row ett-mt-10">
							<label>Max pages per tick/call</label>
							<input
								type="number"
								class="ett-sched-input"
								name="batch_max_pages"
								min="1"
								max="50"
								value="<?php echo esc_attr($batch_max_pages); ?>"
							/>
							<p class="description">Higher = faster, but increases timeout risk and ESI load. Start at 5–10.</p>
						</div>

						<div class="ett-row">
							<label>Max seconds per tick/call</label>
							<input
								type="number"
								class="ett-sched-input"
								name="batch_max_seconds"
								min="1"
								max="25"
								value="<?php echo esc_attr($batch_max_seconds); ?>"
							/>
							<p class="description">Time budget per tick/call. Keep conservative on shared hosting (8–12s).</p>
						</div>

						<p class="ett-mt-10">
							<button type="submit" class="button button-secondary">Save performance settings</button>
						</p>
					</details>
				</form>

				<div class="ett-actions">
					<button class="button button-primary" id="ett-btn-run" <?php disabled(!$schema_ok); ?>>Run Prices</button>
					<button class="button" id="ett-btn-cancel" disabled>Cancel</button>
				</div>

				<div class="ett-last-run">
					<strong>Last price run completed:</strong>
					<span id="ett-last-price-run">
						<?php
						if ($lastRun){
							$tz2 = wp_timezone_string();
							$tz2 = $tz2 ? $tz2 : 'UTC';
							echo esc_html($lastRun . ' (' . $tz2 . ')');
						} else {
							echo 'Never';
						}
						?>
					</span>
				</div>

				<div class="ett-confirm ett-hidden" id="ett-run-confirm">
					<div class="ett-confirm-box">
						<div class="ett-confirm-text" id="ett-run-confirm-text"></div>
						<div class="ett-confirm-actions">
							<button type="button" class="button button-primary" id="ett-run-confirm-yes">Yes</button>
							<button type="button" class="button" id="ett-run-confirm-no">No</button>
						</div>
					</div>
				</div>

				<div class="ett-progress">
					<div class="ett-progress-head">
						<div>
							<div class="ett-title">Job Progress</div>
							<div class="ett-sub" id="ett-job-phase">Idle.</div>
							<div class="ett-sub" id="ett-job-msg">Idle.</div>
							<div class="ett-sub ett-hidden" id="ett-job-warn"></div>
						</div>

						<div class="ett-status-stack">
							<div class="ett-heartbeat" id="ett-heartbeat">
								<span class="ett-dot"></span>
								<span class="ett-hb-text">No heartbeat</span>
							</div>

							<div class="ett-heartbeat" id="ett-esi">
								<span class="ett-dot"></span>
								<span class="ett-hb-text" id="ett-esi-text">ESI: Checking...</span>
							</div>
						</div>
					</div>

					<div class="ett-kpis">
						<div class="ett-kpi"><div class="ett-k">Elapsed</div><div class="ett-v" id="ett-kpi-elapsed">—</div></div>
						<div class="ett-kpi"><div class="ett-k">Hub</div><div class="ett-v" id="ett-kpi-hub">—</div></div>
						<div class="ett-kpi"><div class="ett-k">Page</div><div class="ett-v" id="ett-kpi-page">—</div></div>
						<div class="ett-kpi"><div class="ett-k">Orders Seen</div><div class="ett-v" id="ett-kpi-orders">—</div></div>
						<div class="ett-kpi"><div class="ett-k">Matched Orders</div><div class="ett-v" id="ett-kpi-matched">—</div></div>
						<div class="ett-kpi"><div class="ett-k">Rows Written</div><div class="ett-v" id="ett-kpi-written">—</div></div>
					</div>

					<pre class="ett-json" id="ett-progress-json">{}</pre>

					<div class="ett-warning ett-hidden" id="ett-stalled">
						Heartbeat has not updated recently — job may be stalled (PHP timeout, network issue, or rate limiting).
					</div>
				</div>
			</div>

			<div class="ett-card">
				<h2>Schedule</h2>
				<p>Automatic runs use the site timezone: <strong><?php echo esc_html($tz); ?></strong></p>

				<?php
				$next_ts  = ETT_Jobs::next_scheduled_timestamp('ett_ph_prices_scheduled_start');
				$next_ts  = $next_ts > 0 ? $next_ts : false;
				$next_txt = 'Not scheduled';

				if ($next_ts){
					try {
						$dt = new DateTime('@' . (int)$next_ts);
						$dt->setTimezone(wp_timezone());
						$next_txt = $dt->format('Y-m-d H:i:s') . " ({$tz})";
					} catch (Exception $e){
						$next_txt = gmdate('Y-m-d H:i:s', (int)$next_ts) . " ({$tz})";
					}
				}
				?>

                <p><strong>Next scheduled run:</strong> <span id="ett-next-run"><?php echo esc_html($next_txt); ?></span></p>
				<p><i>Scheduled runs rely on WP-Cron, which is triggered by website traffic. If you are overshooting a scheduled run, or runs are taking several hours, use <a href="https://cron-job.org">https://cron-job.org</a> to simulate traffic and to keep the process ticking over.</i></p>

				<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice flag
					if (!empty($_GET['sched_saved'])): ?>
					<div class="notice notice-success"><p><strong>Saved:</strong> Schedule updated.</p></div>
				<?php endif; ?>

				<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice flag
					if (!empty($_GET['sched_cancelled'])): ?>
					<div class="notice notice-warning"><p><strong>Cancelled:</strong> Automatic schedule disabled. (You can re-enable by saving a schedule again.)</p></div>
				<?php endif; ?>

				<div id="ett-sched-rate-warning" class="ett-sched-warning ett-hidden">
					<strong>Warning:</strong> Running every 1–2 hours may trigger ESI rate limiting. It is recommended to use 4 hours or more unless you understand the load implications.
				</div>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="ett-sched-form">
					<?php wp_nonce_field('ett_save_schedule'); ?>
					<input type="hidden" name="action" value="ett_save_schedule"/>

					<div class="ett-row">
						<label>Start time</label>
						<input type="time" class="ett-sched-input" name="start_time" value="<?php echo esc_attr($sched_start_time); ?>" required />
					</div>

					<div class="ett-row">
						<label>Run every (hours)</label>
						<select name="freq_hours" class="ett-sched-input">
							<?php
							$options = [1,2,3,4,6,8,12,24,48,72,168];
							if (!in_array($sched_freq_hours, $options, true)) $options[] = $sched_freq_hours;
							sort($options);

							foreach ($options as $h){
								echo '<option value="' . esc_attr($h) . '" ' . selected($sched_freq_hours, $h, false) . '>' . esc_html($h) . '</option>';
							}
							?>
						</select>
					</div>

                    <details id="ett-history-details" class="ett-details">
                        <summary class="ett-summary">Run history</summary>

						<div id="ett-run-history" class="ett-history-wrap">
							<?php if (!$job_history && !$job_history_err): ?>
								<p class="description">No price runs found yet.</p>
							<?php elseif ($job_history_err): ?>
								<p class="description">Unable to load history: <?php echo esc_html($job_history_err); ?></p>
							<?php else: ?>
								<table class="widefat striped ett-history-table"
									<thead>
										<tr>
											<th>Started</th>
											<th>Finished</th>
											<th>Status</th>
											<th>Driver</th>
											<th>Last message</th>
											<th>Error</th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ($job_history as $row):
											$prog = [];
											try { $prog = json_decode($row['progress_json'] ?? '', true) ?: []; } catch (Exception $e){}
											$driver   = $prog['driver'] ?? 'browser';
											$last_msg = $prog['last_msg'] ?? '';
											?>
											<tr>
												<td><?php echo esc_html(($row['started_at'] ?? '') . " ({$tz})"); ?></td>
												<td><?php echo esc_html(($row['finished_at'] ?? '') . " ({$tz})"); ?></td>
												<td><?php echo esc_html($row['status'] ?? ''); ?></td>
												<td><?php echo esc_html($driver); ?></td>
												<td><?php echo esc_html($last_msg); ?></td>
												<td><?php echo esc_html($row['last_error'] ?? ''); ?></td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							<?php endif; ?>
						</div>
					</details>

					<?php submit_button('Save Schedule', 'primary', 'submit', false); ?>
					<?php submit_button('Cancel Schedule', 'secondary', 'cancel_schedule', false); ?>
				</form>
			</div>
		</div>
		<?php
	}

	private static function render_tree(array $nodes, array $selected_ids){
		echo '<ul class="ett-ul">';
		foreach ($nodes as $n){
			$id          = (int)$n['id'];
			$name        = (string)$n['name'];
			$checked     = in_array($id, $selected_ids, true);
			$hasChildren = !empty($n['children']);

			echo '<li class="ett-li" data-ett-name="' . esc_attr(strtolower($name)) . '">';
			echo '<div class="ett-node">';

			if ($hasChildren){
				echo '<button type="button" class="ett-toggle" aria-label="Toggle">▸</button>';
			} else {
				echo '<span class="ett-toggle-spacer"></span>';
			}

			echo '<label class="ett-label">';
			echo '<input type="checkbox" class="ett-mg" name="ett_market_groups[]" value="' . esc_attr($id) . '" ' . checked($checked, true, false) . '/> ';
			echo esc_html($name) . ' <span class="ett-id">(' . esc_html((string)$id) . ')</span>';
			echo '</label>';
			echo '</div>';

			if ($hasChildren){
				echo '<div class="ett-children">';
				self::render_tree($n['children'], $selected_ids);
				echo '</div>';
			}

			echo '</li>';
		}
		echo '</ul>';
	}

	public static function handle_save_db(){
		if (!current_user_can(self::CAP)) wp_die('Insufficient permissions.');
		check_admin_referer('ett_save_db');

        $host   = sanitize_text_field(wp_unslash($_POST['host'] ?? ''));
        $port   = self::clamp_int(absint(wp_unslash($_POST['port'] ?? 3306)), 1, 65535, 3306);
        $dbname = sanitize_text_field(wp_unslash($_POST['dbname'] ?? ''));
        $user   = sanitize_text_field(wp_unslash($_POST['user'] ?? ''));
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- password is stored encrypted; do not mangle characters
        $pass   = (string) wp_unslash($_POST['pass'] ?? '');

		$existing = ETT_ExternalDB::get();

		if ($pass === ''){
			update_option(ETT_ExternalDB::OPT, [
				'host'     => $host,
				'port'     => $port,
				'dbname'   => $dbname,
				'user'     => $user,
				'pass_enc' => $existing['pass_enc'],
				'pass_iv'  => $existing['pass_iv'],
				'pass_mac' => $existing['pass_mac'] ?? '',
			], false);
		} else {
			ETT_ExternalDB::save($host, $port, $dbname, $user, $pass);
		}

		try {
			if (ETT_ExternalDB::is_configured()){
				ETT_ExternalDB::ensure_schema();
			}
		} catch (Exception $e){
            $url = add_query_arg(
            	[
            		'page'   => self::SLUG,
            		'db_err' => rawurlencode(sanitize_text_field($e->getMessage())),
            	],
            	admin_url('admin.php')
            );
            wp_safe_redirect($url);
			exit;
		}

		$url = add_query_arg(['page' => self::SLUG, 'db_saved' => 1], admin_url('admin.php'));
        wp_safe_redirect($url);
		exit;
	}

	public static function handle_save_selection(){
		if (!current_user_can(self::CAP)) wp_die('Insufficient permissions.');
		check_admin_referer('ett_save_selection');

		$groups = isset($_POST['ett_market_groups'])
        	? array_map('intval', (array) wp_unslash($_POST['ett_market_groups']))
        	: [];
		update_option(self::OPT_SELECTED_GROUPS, $groups, false);

		$url = add_query_arg(['page' => self::SLUG, 'saved' => 1], admin_url('admin.php'));
        wp_safe_redirect($url);
		exit;
	}

    public static function handle_save_schedule(){
    	if (!current_user_can(self::CAP)) wp_die('Insufficient permissions.');
    	check_admin_referer('ett_save_schedule');
    
    	if (isset($_POST['cancel_schedule'])){
    		ETT_Jobs::cancel_prices_schedule();
    		$url = add_query_arg(['page' => self::SLUG, 'sched_cancelled' => 1], admin_url('admin.php'));
            wp_safe_redirect($url);
    		exit;
    	}
    
    	self::save_schedule_from_request($_POST);
    
    	// The schedule form includes these fields too, so keep saving them here.
    	self::save_perf_from_request($_POST);
    
    	ETT_Jobs::reschedule_prices_start();
    
    	$url = add_query_arg(['page' => self::SLUG, 'sched_saved' => 1], admin_url('admin.php'));
        wp_safe_redirect($url);
    	exit;
    }

    public static function handle_save_perf(){
    	if (!current_user_can(self::CAP)) wp_die('Insufficient permissions.');
    	check_admin_referer('ett_perf', 'ett_perf_nonce');
    
    	self::save_perf_from_request($_POST);
    
    	$url = add_query_arg(['page' => self::SLUG, 'perf_saved' => 1], admin_url('admin.php'));
        wp_safe_redirect($url);
    	exit;
    }

    public static function ajax_save_perf(){
    	if (!current_user_can(self::CAP)){
    		wp_send_json_error('Insufficient permissions', 403);
    	}
    	check_ajax_referer('ett_admin');
    
    	[$batch_max_pages, $batch_max_seconds] = self::save_perf_from_request($_POST);
    
    	wp_send_json_success([
    		'pages'   => $batch_max_pages,
    		'seconds' => $batch_max_seconds,
    	]);
    }

    private static function save_schedule_from_request(array $src) : array {
    	$start_time = sanitize_text_field(wp_unslash($src['start_time'] ?? '03:00'));
    	$freq_hours = (int) wp_unslash($src['freq_hours'] ?? 24);
    
    	if (!preg_match('/^\d{2}:\d{2}$/', $start_time)) $start_time = '03:00';
    	if ($freq_hours < 1) $freq_hours = 1;
    	if ($freq_hours > 168) $freq_hours = 168;
    
    	update_option(self::OPT_SCHED_START_TIME, $start_time, false);
    	update_option(self::OPT_SCHED_FREQ_HOURS, $freq_hours, false);
    
    	return [$start_time, $freq_hours];
    }

    private static function save_perf_from_request(array $src) : array {
    	$batch_max_pages   = (int) wp_unslash($src['batch_max_pages'] ?? 5);
    	$batch_max_seconds = (int) wp_unslash($src['batch_max_seconds'] ?? 10);
    
    	if ($batch_max_pages < 1) $batch_max_pages = 1;
    	if ($batch_max_pages > 50) $batch_max_pages = 50;
    
    	if ($batch_max_seconds < 1) $batch_max_seconds = 1;
    	if ($batch_max_seconds > 25) $batch_max_seconds = 25;
    
    	update_option(self::OPT_BATCH_MAX_PAGES, $batch_max_pages, false);
    	update_option(self::OPT_BATCH_MAX_SECONDS, $batch_max_seconds, false);
    
    	return [$batch_max_pages, $batch_max_seconds];
    }

	public static function handle_import_fuzzwork(){
		if (!current_user_can(self::CAP)) wp_die('Insufficient permissions.');
		check_admin_referer('ett_import_fuzzwork');

		if (function_exists('ignore_user_abort')) @ignore_user_abort(true);
        // Large imports can exceed typical hosting execution limits; best-effort only.
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
        if (function_exists('set_time_limit')) { @set_time_limit(0); }
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
        @ini_set('max_execution_time', '0');
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
        @ini_set('memory_limit', '1024M');

		register_shutdown_function(function(){
			$e = error_get_last();
			if (!$e) return;

			$fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
			if (!in_array($e['type'], $fatalTypes, true)) return;

			$msg = sprintf(
				'Fatal error [%d]: %s in %s:%d',
				$e['type'],
				$e['message'],
				$e['file'],
				$e['line']
			);

			if (!headers_sent()){
				$url = add_query_arg(
            	[
            		'page'     => self::SLUG,
            		'imported' => 0,
            		'err'      => rawurlencode(sanitize_text_field($msg)),
            	],
            	admin_url('admin.php')
            );
            wp_safe_redirect($url);
				exit;
			}
		});

		try {
			ETT_ExternalDB::ensure_schema();
			$pdo = ETT_ExternalDB::pdo();
		} catch (Exception $e){
			$url = add_query_arg(
            	[
            		'page'     => self::SLUG,
            		'imported' => 0,
            		'db_err'   => rawurlencode(sanitize_text_field($e->getMessage())),
            	],
            	admin_url('admin.php')
            );
            wp_safe_redirect($url);
			exit;
		}

		try {
            $t0 = microtime(true);
            $meta = ETT_Fuzzwork::import_all($pdo);
            $elapsed_s = microtime(true) - $t0; // keep if you want for debug, but don't store/display
            
            // Ensure old installs can't keep showing it if you ever re-add display logic later
            unset($meta['elapsed_s']);
            
            update_option(self::OPT_LAST_IMPORT, $meta, false);

			$url = add_query_arg(['page' => self::SLUG, 'imported' => 1], admin_url('admin.php'));
            wp_safe_redirect($url);
			exit;
		} catch (Exception $e){
			$url = add_query_arg(
            	[
            		'page'     => self::SLUG,
            		'imported' => 0,
            		'err'      => rawurlencode(sanitize_text_field($e->getMessage())),
            	],
            	admin_url('admin.php')
            );
            wp_safe_redirect($url);
			exit;
		}
	}

    public static function ajax_import_fuzzwork(){
    	if (!current_user_can(self::CAP)){
    		wp_send_json_error('Insufficient permissions', 403);
    	}
    	check_ajax_referer('ett_admin');
    
    	// Best-effort: allow long-running import
    	if (function_exists('ignore_user_abort')) @ignore_user_abort(true);
        // Large imports can exceed typical hosting execution limits; best-effort only.
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
        if (function_exists('set_time_limit')) { @set_time_limit(0); }
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
        @ini_set('max_execution_time', '0');
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
        @ini_set('memory_limit', '1024M');

    	try {
    		ETT_ExternalDB::ensure_schema();
    		$pdo = ETT_ExternalDB::pdo();
    	} catch (Exception $e){
    		wp_send_json_error('DB error: ' . $e->getMessage(), 400);
    	}
    
    	try {
            $t0 = microtime(true);
            $meta = ETT_Fuzzwork::import_all($pdo);
            $elapsed_s = microtime(true) - $t0; // optional local debug only
            
            unset($meta['elapsed_s']);
            update_option(self::OPT_LAST_IMPORT, $meta, false);
            
            $parts = [];
            foreach (['invMarketGroups','invTypes','invMetaGroups','invMetaTypes','industryActivityProducts','invTypeMaterials'] as $k){
            	if (isset($meta[$k])) $parts[] = $k . ': ' . number_format((int)$meta[$k]);
            }
            $details_txt = !empty($parts) ? implode(' | ', $parts) : '';

            wp_send_json_success([
            	'imported'    => true,
            	'imported_at' => $meta['imported_at'] ?? current_time('mysql'),
            	'details_txt' => $details_txt,
            	'meta'        => $meta,
            ]);
            
    	} catch (Exception $e){
    		wp_send_json_error($e->getMessage(), 400);
    	}
    }

	private static function sso_callback_url() : string{
		return admin_url('admin-post.php?action=ett_sso_callback');
	}

	private static function sso_scopes() : string{
		return 'esi-universe.read_structures.v1 esi-markets.structure_markets.v1 esi-search.search_structures.v1';
	}

	private static function b64url_decode($data){
		$remainder = strlen($data) % 4;
		if ($remainder) $data .= str_repeat('=', 4 - $remainder);
		return base64_decode(strtr($data, '-_', '+/'));
	}

	private static function jwt_claims($jwt) : array{
		$parts = explode('.', (string)$jwt);
		if (count($parts) < 2) return [];
		$payload = self::b64url_decode($parts[1]);
		$json = json_decode($payload, true);
		return is_array($json) ? $json : [];
	}

    private static function encrypt_secret(string $plaintext) : array{
    	return ETT_Crypto::encrypt_triplet($plaintext);
    }

    private static function decrypt_secret(string $ciphertext, string $iv_b64, string $mac_b64) : string{
        return ETT_Crypto::decrypt_triplet($ciphertext, $iv_b64, $mac_b64);
    }

	private static function sso_token_request(array $body){
		$client_id = get_option(self::OPT_SSO_CLIENT_ID, '');
		$client_secret = self::decrypt_secret(
			(string)get_option(self::OPT_SSO_CLIENT_SECRET, ''),
			(string)get_option(self::OPT_SSO_CLIENT_SECRET . '_iv', ''),
			(string)get_option(self::OPT_SSO_CLIENT_SECRET . '_mac', '')
		);

		if (empty($client_id) || empty($client_secret)) return ['ok' => false, 'err' => 'Missing SSO client id/secret'];

		$auth = base64_encode($client_id . ':' . $client_secret);
       
        // EVE SSO commonly expects redirect_uri on auth-code exchange
        if (($body['grant_type'] ?? '') === 'authorization_code') {
        	$body['redirect_uri'] = self::sso_callback_url();
        }

		$resp = wp_remote_post('https://login.eveonline.com/v2/oauth/token', [
			'timeout' => 20,
			'headers' => [
				'Authorization' => 'Basic ' . $auth,
				'Content-Type'  => 'application/x-www-form-urlencoded',
			],
			'body' => http_build_query($body, '', '&'),
		]);

		if (is_wp_error($resp)) return ['ok' => false, 'err' => $resp->get_error_message()];
		$code = (int)wp_remote_retrieve_response_code($resp);
		$raw  = wp_remote_retrieve_body($resp);
		$json = json_decode($raw, true);

		if ($code < 200 || $code >= 300 || !is_array($json) || empty($json['access_token'])){
			return ['ok' => false, 'err' => 'Token request failed', 'http' => $code, 'body' => $raw];
		}

		return ['ok' => true, 'data' => $json];
	}

	private static function ensure_access_token() : array{
		$access = self::decrypt_secret(
			(string)get_option(self::OPT_SSO_ACCESS_TOKEN, ''),
			(string)get_option(self::OPT_SSO_ACCESS_TOKEN . '_iv', ''),
			(string)get_option(self::OPT_SSO_ACCESS_TOKEN . '_mac', '')
		);
		$refresh = self::decrypt_secret(
			(string)get_option(self::OPT_SSO_REFRESH_TOKEN, ''),
			(string)get_option(self::OPT_SSO_REFRESH_TOKEN . '_iv', ''),
			(string)get_option(self::OPT_SSO_REFRESH_TOKEN . '_mac', '')
		);
		$expires_at = (int)get_option(self::OPT_SSO_EXPIRES_AT, 0);

		if (!empty($access) && $expires_at > (time() + 30)){
			return ['ok' => true, 'access' => $access];
		}

		if (empty($refresh)) return ['ok' => false, 'err' => 'Not authenticated'];

		$r = self::sso_token_request([
			'grant_type'    => 'refresh_token',
			'refresh_token' => $refresh,
		]);

		if (!$r['ok']) return $r;

		$tok = $r['data'];

		$encA = self::encrypt_secret((string)$tok['access_token']);
		update_option(self::OPT_SSO_ACCESS_TOKEN, $encA['ciphertext'], false);
		update_option(self::OPT_SSO_ACCESS_TOKEN . '_iv', $encA['iv'], false);
		update_option(self::OPT_SSO_ACCESS_TOKEN . '_mac', $encA['mac'], false);

		if (!empty($tok['refresh_token'])){
			$encR = self::encrypt_secret((string)$tok['refresh_token']);
			update_option(self::OPT_SSO_REFRESH_TOKEN, $encR['ciphertext'], false);
			update_option(self::OPT_SSO_REFRESH_TOKEN . '_iv', $encR['iv'], false);
			update_option(self::OPT_SSO_REFRESH_TOKEN . '_mac', $encR['mac'], false);
		}

		$expires_in = isset($tok['expires_in']) ? (int)$tok['expires_in'] : 1200;
		update_option(self::OPT_SSO_EXPIRES_AT, time() + max(60, $expires_in) - 30);

		$claims = self::jwt_claims((string)$tok['access_token']);
		if (!empty($claims['name'])) update_option(self::OPT_SSO_CHARACTER_NAME, (string)$claims['name']);
		if (!empty($claims['sub']) && preg_match('/^CHARACTER:EVE:(\d+)$/', (string)$claims['sub'], $m)){
			update_option(self::OPT_SSO_CHARACTER_ID, (int)$m[1]);
		}

		return ['ok' => true, 'access' => (string)$tok['access_token']];
	}

	public static function get_access_token_for_jobs() : array{
		if (!(is_admin() || (defined('DOING_CRON') && DOING_CRON) || (defined('WP_CLI') && WP_CLI))){
			return ['ok' => false, 'error' => 'forbidden_context'];
		}
		return self::ensure_access_token();
	}

    private static function save_sso_from_request(array $src) : void {
        $client_id     = isset($src['ett_sso_client_id']) ? trim((string) wp_unslash($src['ett_sso_client_id'])) : '';
        $client_secret = isset($src['ett_sso_client_secret']) ? trim((string) wp_unslash($src['ett_sso_client_secret'])) : '';
    
        update_option(self::OPT_SSO_CLIENT_ID, $client_id);
    
        $enc = self::encrypt_secret($client_secret);
        update_option(self::OPT_SSO_CLIENT_SECRET, $enc['ciphertext'], false);
        update_option(self::OPT_SSO_CLIENT_SECRET . '_iv', $enc['iv'], false);
        update_option(self::OPT_SSO_CLIENT_SECRET . '_mac', $enc['mac'], false);
    }

	public static function handle_save_sso(){
        if (!current_user_can(self::CAP)) wp_die('Insufficient permissions.');
        check_admin_referer('ett_save_sso');
        
        self::save_sso_from_request($_POST);
        
        $url = add_query_arg(['page' => self::SLUG], admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
	}

    public static function ajax_save_sso(){
        if (!current_user_can(self::CAP)) wp_send_json_error('Insufficient permissions', 403);
        check_ajax_referer('ett_admin');
        
        self::save_sso_from_request($_POST);
        
        wp_send_json_success(['saved' => true]);
    }

	public static function handle_sso_start(){
		if (!current_user_can(self::CAP)) wp_die('Insufficient permissions.');
		check_admin_referer('ett_sso_start');

		$client_id = get_option(self::OPT_SSO_CLIENT_ID, '');
		$client_secret = self::decrypt_secret(
			(string)get_option(self::OPT_SSO_CLIENT_SECRET, ''),
			(string)get_option(self::OPT_SSO_CLIENT_SECRET . '_iv', ''),
			(string)get_option(self::OPT_SSO_CLIENT_SECRET . '_mac', '')
		);

		if (empty($client_id) || empty($client_secret)){
			$url = add_query_arg(['page' => self::SLUG, 'sso_err' => 'missing_app'], admin_url('admin.php'));
            wp_safe_redirect($url);
			exit;
		}

		$state = wp_generate_password(24, false, false);
		set_transient('ett_sso_state_' . $state, 1, 10 * MINUTE_IN_SECONDS);

		$url = add_query_arg([
			'response_type' => 'code',
			'redirect_uri'  => self::sso_callback_url(),
			'client_id'     => $client_id,
			'scope'         => self::sso_scopes(),
			'state'         => $state,
		], 'https://login.eveonline.com/v2/oauth/authorize/');

		wp_safe_redirect($url);
		exit;
	}

	public static function handle_sso_callback(){
		if (!current_user_can(self::CAP)) wp_die('Insufficient permissions.');

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback from EVE SSO cannot use WP nonces
		$code  = isset($_GET['code'])  ? sanitize_text_field(wp_unslash($_GET['code']))  : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback from EVE SSO cannot use WP nonces
		$state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';

		if (empty($code) || empty($state) || !get_transient('ett_sso_state_' . $state)){
			$url = add_query_arg(['page' => self::SLUG, 'sso_err' => 'bad_state'], admin_url('admin.php'));
            wp_safe_redirect($url);
			exit;
		}

		delete_transient('ett_sso_state_' . $state);

		$r = self::sso_token_request([
			'grant_type' => 'authorization_code',
			'code'       => $code,
		]);

		if (!$r['ok']){
			self::debug_log('[ETT] token request failed.');
			$url = add_query_arg(['page' => self::SLUG, 'sso_err' => 'token'], admin_url('admin.php'));
            wp_safe_redirect($url);
			exit;
		}

        $tok = $r['data'];
        
        $encA = self::encrypt_secret((string)$tok['access_token']);
        update_option(self::OPT_SSO_ACCESS_TOKEN, $encA['ciphertext'], false);
        update_option(self::OPT_SSO_ACCESS_TOKEN . '_iv', $encA['iv'], false);
        update_option(self::OPT_SSO_ACCESS_TOKEN . '_mac', $encA['mac'], false);
        
        $encR = self::encrypt_secret((string)$tok['refresh_token']);
        update_option(self::OPT_SSO_REFRESH_TOKEN, $encR['ciphertext'], false);
        update_option(self::OPT_SSO_REFRESH_TOKEN . '_iv', $encR['iv'], false);
        update_option(self::OPT_SSO_REFRESH_TOKEN . '_mac', $encR['mac'], false);

		$expires_in = isset($tok['expires_in']) ? (int)$tok['expires_in'] : 1200;
		update_option(self::OPT_SSO_EXPIRES_AT, time() + max(60, $expires_in) - 30);

		$claims = self::jwt_claims((string)$tok['access_token']);
		if (!empty($claims['name'])) update_option(self::OPT_SSO_CHARACTER_NAME, (string)$claims['name']);
		if (!empty($claims['sub']) && preg_match('/^CHARACTER:EVE:(\d+)$/', (string)$claims['sub'], $m)){
			update_option(self::OPT_SSO_CHARACTER_ID, (int)$m[1]);
		}

		$url = add_query_arg(['page' => self::SLUG, 'sso_ok' => 1], admin_url('admin.php'));
        wp_safe_redirect($url);
		exit;
	}

	public static function handle_sso_disconnect(){
		if (!current_user_can(self::CAP)) wp_die('Insufficient permissions.');
		check_admin_referer('ett_sso_disconnect');
		// Also remove the stored app credentials (client id/secret)
        delete_option(self::OPT_SSO_CLIENT_ID);
        
        delete_option(self::OPT_SSO_CLIENT_SECRET);
        delete_option(self::OPT_SSO_CLIENT_SECRET . '_iv');
        delete_option(self::OPT_SSO_CLIENT_SECRET . '_mac');

		delete_option(self::OPT_SSO_ACCESS_TOKEN);
		delete_option(self::OPT_SSO_ACCESS_TOKEN . '_iv');
		delete_option(self::OPT_SSO_ACCESS_TOKEN . '_mac');

		delete_option(self::OPT_SSO_REFRESH_TOKEN);
		delete_option(self::OPT_SSO_REFRESH_TOKEN . '_iv');
		delete_option(self::OPT_SSO_REFRESH_TOKEN . '_mac');

		delete_option(self::OPT_SSO_EXPIRES_AT);
		delete_option(self::OPT_SSO_CHARACTER_ID);
		delete_option(self::OPT_SSO_CHARACTER_NAME);
		delete_option(self::OPT_SSO_STRUCTURES_CACHE);
		delete_option(self::OPT_SSO_STRUCTURES_CACHE_AT);
		delete_option(self::OPT_SSO_CORP_CACHE);

		$url = add_query_arg(['page' => self::SLUG], admin_url('admin.php'));
        wp_safe_redirect($url);
		exit;
	}

	public static function ajax_sso_refresh_structures(){
		if (!current_user_can(self::CAP)) wp_send_json_error('Insufficient permissions', 403);
		check_ajax_referer('ett_admin');

		$char_id = (int)get_option(self::OPT_SSO_CHARACTER_ID, 0);
		if (!$char_id) wp_send_json_error('No character ID. Re-authenticate.', 400);

		$tok = self::ensure_access_token();
		if (!$tok['ok']) wp_send_json_error($tok['err'] ?? 'Auth error', 400);
		$access = $tok['access'];

		$pairs = self::secondary_pairs();

		$all_ids          = [];
		$id_src           = [];
		$src_counts       = [];
		$src_resolve_ok   = [];
		$src_resolve_fail = [];

		foreach ($pairs as $hub_key => $p){
			$system_label = isset($p['label']) ? trim((string)$p['label']) : '';
			if ($system_label === '') continue;

			$needle = $system_label . ' -';

			$search_url = add_query_arg([
				'categories' => 'structure',
				'search'     => $needle,
				'strict'     => 'false',
				'datasource' => 'tranquility',
			], 'https://esi.evetech.net/latest/characters/' . $char_id . '/search/');

			$sresp = wp_remote_get($search_url, [
				'timeout' => 25,
				'headers' => [
					'Authorization' => 'Bearer ' . $access,
					'Accept'        => 'application/json',
				],
			]);

			if (is_wp_error($sresp)) continue;

			$scode = (int)wp_remote_retrieve_response_code($sresp);
			$sraw  = wp_remote_retrieve_body($sresp);

			if ($scode < 200 || $scode >= 300){
				self::debug_log('[ETT] structure search failed http=' . $scode . ' needle=' . $needle . ' body=' . substr($sraw, 0, 300));
				continue;
			}

			$sjson = json_decode($sraw, true);
			if (!is_array($sjson)){
				self::debug_log('[ETT] structure search bad json needle=' . $needle . ' body=' . substr($sraw, 0, 300));
				continue;
			}

			$found = (!empty($sjson['structure']) && is_array($sjson['structure'])) ? count($sjson['structure']) : 0;
			self::debug_log('[ETT] structure search ok needle=' . $needle . ' found=' . $found);

			if ($found){
				if (!isset($src_counts[$needle])) $src_counts[$needle] = 0;

				foreach ($sjson['structure'] as $sid){
					$sid = (int)$sid;
					if ($sid <= 0) continue;

					$all_ids[$sid] = true;
					$id_src[$sid]  = $needle;
					$src_counts[$needle]++;
				}
			}
		}

		$ids = array_keys($all_ids);

		foreach ($src_counts as $n => $c){
			self::debug_log('[ETT] collected ids needle=' . $n . ' count=' . (int)$c);
		}
		self::debug_log('[ETT] total unique ids=' . count($ids));

		if (empty($ids)){
			update_option(self::OPT_SSO_STRUCTURES_CACHE, [], false);
			update_option(self::OPT_SSO_STRUCTURES_CACHE_AT, time(), false);
			$at = time();
            wp_send_json_success(['count' => 0, 'cache_at' => $at, 'structures' => []]);
		}

		$ids = array_slice($ids, 0, 250);

		$arr = [];
		foreach ($ids as $sid){
			$rurl = 'https://esi.evetech.net/latest/universe/structures/' . (int)$sid . '/?datasource=tranquility';
			$rresp = wp_remote_get($rurl, [
				'timeout' => 20,
				'headers' => [
					'Authorization' => 'Bearer ' . $access,
					'Accept'        => 'application/json',
				],
			]);

			if (is_wp_error($rresp)) continue;

			$rcode = (int)wp_remote_retrieve_response_code($rresp);
			$src   = isset($id_src[(int)$sid]) ? $id_src[(int)$sid] : 'unknown';

			if (!isset($src_resolve_ok[$src]))   $src_resolve_ok[$src] = 0;
			if (!isset($src_resolve_fail[$src])) $src_resolve_fail[$src] = 0;

			if ($rcode < 200 || $rcode >= 300){
				$src_resolve_fail[$src]++;
				self::debug_log('[ETT] resolve FAIL needle=' . $src . ' http=' . $rcode . ' sid=' . (int)$sid);
				continue;
			}

			$rraw  = wp_remote_retrieve_body($rresp);
			$rjson = json_decode($rraw, true);
			if (!is_array($rjson)) continue;

			$src_resolve_ok[$src]++;
			$ss = isset($rjson['solar_system_id']) ? (int)$rjson['solar_system_id'] : 0;
			self::debug_log('[ETT] resolve OK needle=' . $src . ' sid=' . (int)$sid . ' solar_system_id=' . $ss);

			$arr[] = [
				'structure_id'    => (int)$sid,
				'name'            => isset($rjson['name']) ? (string)$rjson['name'] : '',
				'solar_system_id' => isset($rjson['solar_system_id']) ? (int)$rjson['solar_system_id'] : 0,
				'owner_id'        => isset($rjson['owner_id']) ? (int)$rjson['owner_id'] : 0,
			];
		}

		foreach ($src_resolve_ok as $n => $c){
			self::debug_log('[ETT] resolve summary needle=' . $n . ' ok=' . (int)$c . ' fail=' . (int)($src_resolve_fail[$n] ?? 0));
		}

		$corp_cache = get_option(self::OPT_SSO_CORP_CACHE, []);
		if (!is_array($corp_cache)) $corp_cache = [];

		$owner_ids = [];
		foreach ($arr as $st){
			if (is_array($st) && !empty($st['owner_id'])) $owner_ids[(int)$st['owner_id']] = true;
		}

		$now = time();

		foreach (array_keys($owner_ids) as $corp_id){
			$corp_id = (int)$corp_id;
			$needs   = true;

			if (isset($corp_cache[$corp_id]) && is_array($corp_cache[$corp_id])){
				$at = isset($corp_cache[$corp_id]['at']) ? (int)$corp_cache[$corp_id]['at'] : 0;
				if ($at > ($now - 30 * DAY_IN_SECONDS)) $needs = false;
			}

			if (!$needs) continue;

			$cresp = wp_remote_get('https://esi.evetech.net/latest/corporations/' . $corp_id . '/?datasource=tranquility', [
				'timeout' => 20,
				'headers' => ['Accept' => 'application/json'],
			]);

			if (is_wp_error($cresp)) continue;

			$craw  = wp_remote_retrieve_body($cresp);
			$cjson = json_decode($craw, true);
			if (!is_array($cjson) || empty($cjson['name'])) continue;

			$corp_cache[$corp_id] = [
				'name'   => (string)$cjson['name'],
				'ticker' => isset($cjson['ticker']) ? (string)$cjson['ticker'] : '',
				'at'     => $now,
			];
		}

		update_option(self::OPT_SSO_CORP_CACHE, $corp_cache, false);

		$out = [];
		foreach ($arr as $st){
			if (!is_array($st)) continue;
			if (empty($st['structure_id']) || empty($st['name']) || empty($st['solar_system_id']) || empty($st['owner_id'])) continue;

			$corp_id      = (int)$st['owner_id'];
			$owner_name   = isset($corp_cache[$corp_id]['name']) ? (string)$corp_cache[$corp_id]['name'] : '';
			$owner_ticker = isset($corp_cache[$corp_id]['ticker']) ? (string)$corp_cache[$corp_id]['ticker'] : '';

			$out[] = [
				'structure_id'    => (int)$st['structure_id'],
				'name'            => (string)$st['name'],
				'solar_system_id' => (int)$st['solar_system_id'],
				'owner_id'        => $corp_id,
				'owner_name'      => $owner_name,
				'owner_ticker'    => $owner_ticker,
			];
		}

		update_option(self::OPT_SSO_STRUCTURES_CACHE, $out, false);
		update_option(self::OPT_SSO_STRUCTURES_CACHE_AT, time(), false);

        $at = time();
        
        wp_send_json_success([
          'count'      => count($out),
          'cache_at'   => $at,
          'structures' => $out,
        ]);

	}
}
