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

	const OPT_LAST_IMPORT = 'ett_sde_last_import_meta';

	// Private hub storage: serialised array indexed by hub_index (1-based)
	const OPT_PRIVATE_HUBS = 'ett_private_hubs';

	/**
	 * Per-private-hub SSO tokens are stored under keys:
	 *   ett_priv_access_{idx}, ett_priv_access_{idx}_iv, ett_priv_access_{idx}_mac
	 *   ett_priv_refresh_{idx}, etc.
	 *   ett_priv_expires_{idx}
	 *   ett_priv_char_id_{idx}
	 *   ett_priv_char_name_{idx}
	 */

	/**
	 * GitHub repo URLs for known ETT plugins, keyed by plugin slug (directory name).
	 * Used by the Changelog tab. New plugins need not be listed here if they
	 * include a 'GitHub URI' header in their main plugin file.
	 */
	const PLUGIN_GITHUB_URLS = [
		'ett-price-helper'      => 'https://github.com/C4813/EVE-Trade-Tools-Price-Helper',
		'ett-reprocess-trading' => 'https://github.com/C4813/EVE-Trade-Tools-Reprocess-Trading',
		'ett-eve-login'         => 'https://github.com/C4813/EVE-Trade-Tools-EVE-Login',
		'eve-login'             => 'https://github.com/C4813/EVE-Trade-Tools-EVE-Login',
	];

	/** @var array<array{slug:string,label:string,callback:callable}> */
	private static array $tabs = [];

	/**
	 * Register a tab on the master EVE Trade Tools admin page.
	 * Call this from a hook on 'ett_admin_tabs'.
	 *
	 * @param string   $slug     Unique tab identifier, e.g. 'reprocess-trading'
	 * @param string   $label    Tab label shown in the nav
	 * @param callable $callback Renders the tab content
	 */
	public static function register_tab(string $slug, string $label, callable $callback): void {
		self::$tabs[] = ['slug' => $slug, 'label' => $label, 'callback' => $callback];
	}

	const OPT_LAST_PRICE_RUN   = 'ett_last_price_run_completed_at';
	const OPT_SCHED_START_TIME = 'ett_sched_start_time';
	const OPT_SCHED_FREQ_HOURS = 'ett_sched_freq_hours';
	const OPT_SCHED_ENABLED    = 'ett_sched_enabled';

	const OPT_BATCH_MAX_PAGES   = 'ett_batch_max_pages';
	const OPT_BATCH_MAX_SECONDS = 'ett_batch_max_seconds';

	const OPT_HISTORY_BATCH_SIZE = 'ett_history_batch_size';

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
        add_action('wp_ajax_ett_last_price_run_ajax', [__CLASS__, 'ajax_last_price_run']);
        add_action('wp_ajax_ett_next_run_ajax', [__CLASS__, 'ajax_next_run']);
        add_action('wp_ajax_ett_cancel_schedule_ajax', [__CLASS__, 'ajax_cancel_schedule']);
        add_action('wp_ajax_ett_clear_history_ajax',   [__CLASS__, 'ajax_clear_history']);
		add_action('admin_post_ett_save_sso', [__CLASS__, 'handle_save_sso']);
		add_action('wp_ajax_ett_save_sso_ajax', [__CLASS__, 'ajax_save_sso']);
		add_action('admin_post_ett_sso_start', [__CLASS__, 'handle_sso_start']);
		add_action('admin_post_ett_sso_callback',        [__CLASS__, 'handle_sso_callback']);
		add_action('admin_post_ett_eve_callback',         [__CLASS__, 'handle_eve_callback']);
		add_action('admin_post_nopriv_ett_eve_callback',  [__CLASS__, 'handle_eve_callback']);
		add_action('admin_post_ett_sso_disconnect', [__CLASS__, 'handle_sso_disconnect']);
		add_action('wp_ajax_ett_sso_refresh_structures', [__CLASS__, 'ajax_sso_refresh_structures']);
		add_action('admin_post_ett_import_sde', [__CLASS__, 'handle_import_sde']);
		add_action('wp_ajax_ett_sde_prepare',     [__CLASS__, 'ajax_sde_prepare']);
		add_action('wp_ajax_ett_sde_import_step', [__CLASS__, 'ajax_sde_import_step']);
		add_action('wp_ajax_ett_sde_cleanup',     [__CLASS__, 'ajax_sde_cleanup']);
		add_action('wp_ajax_ett_market_tree_ajax', [__CLASS__, 'ajax_market_tree']);
		add_action('admin_post_ett_save_schedule', [__CLASS__, 'handle_save_schedule']);
		add_action('admin_post_ett_save_perf', [__CLASS__, 'handle_save_perf']);
		add_action('wp_ajax_ett_save_perf_ajax', [__CLASS__, 'ajax_save_perf']);
		add_action('wp_ajax_ett_db_status_ajax', [__CLASS__, 'ajax_db_status']);

		// Private hub actions
		add_action('admin_post_ett_priv_sso_start',      [__CLASS__, 'handle_priv_sso_start']);
		add_action('admin_post_ett_priv_sso_disconnect', [__CLASS__, 'handle_priv_sso_disconnect']);
		add_action('wp_ajax_ett_priv_save_hub',          [__CLASS__, 'ajax_priv_save_hub']);
		add_action('wp_ajax_ett_priv_add_hub',           [__CLASS__, 'ajax_priv_add_hub']);
		add_action('wp_ajax_ett_priv_remove_hub',        [__CLASS__, 'ajax_priv_remove_hub']);
		add_action('wp_ajax_ett_priv_fetch_structures',  [__CLASS__, 'ajax_priv_fetch_structures']);
		add_action('wp_ajax_ett_system_search',          [__CLASS__, 'ajax_system_search']);
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
		// Allow empty selection — user may be running with private hubs only.

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

	// ── Changelog tab ─────────────────────────────────────────────────────

	/**
	 * Renders the Changelog tab content.
	 * Auto-detects all active WordPress plugins that start with 'ett-' or 'eve-'
	 * and have a readme.txt, then renders each plugin's changelog section.
	 */
	public static function render_changelog_tab(): void {
		if (!function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins    = get_plugins();
		$active_plugins = (array) get_option('active_plugins', []);

		// Collect active ETT plugins: directories starting with 'ett-', or literally 'eve-login'
		$ett_plugins = [];
		foreach ($active_plugins as $plugin_file) {
			$slug = explode('/', $plugin_file)[0];
			if (!preg_match('/^(ett-|eve-login$)/i', $slug)) continue;
			if (!isset($all_plugins[$plugin_file])) continue;
			$ett_plugins[$slug] = [
				'file' => $plugin_file,
				'data' => $all_plugins[$plugin_file],
			];
		}

		// Price Helper should always be first even if slug sort differs
		uksort($ett_plugins, function ($a, $b) {
			if ($a === 'ett-price-helper') return -1;
			if ($b === 'ett-price-helper') return 1;
			return strcmp($a, $b);
		});

		echo '<div style="max-width:900px;">';

		if (empty($ett_plugins)) {
			echo '<p>No EVE Trade Tools plugins detected.</p>';
			echo '</div>';
			return;
		}

		foreach ($ett_plugins as $slug => $info) {
			$plugin_data = $info['data'];
			$plugin_name = esc_html($plugin_data['Name'] ?? $slug);

			// Resolve readme.txt path
			$plugin_dir  = WP_PLUGIN_DIR . '/' . $slug;
			$readme_path = $plugin_dir . '/readme.txt';

			// GitHub URL: check plugin header first, then our built-in map
			$github_url = '';
			if (!empty($plugin_data['GitHub Plugin URI'])) {
				$github_url = esc_url($plugin_data['GitHub Plugin URI']);
			} elseif (isset(self::PLUGIN_GITHUB_URLS[$slug])) {
				$github_url = esc_url(self::PLUGIN_GITHUB_URLS[$slug]);
			}

			echo '<div class="ett-card" style="margin-bottom:16px;">';
			echo '<h2 style="margin-top:0;display:flex;align-items:center;gap:10px;">';
			echo esc_html( $plugin_name );
			if ($github_url) {
				echo ' <a href="' . esc_url( $github_url ) . '" target="_blank" rel="noopener noreferrer"'
					. ' style="font-size:0.75em;font-weight:600;padding:2px 10px;background:#24292e;color:#fff;border-radius:3px;text-decoration:none;">'
					. 'GitHub</a>';
			}
			echo '</h2>';

			if (!file_exists($readme_path)) {
				echo '<p class="description">No readme.txt found for this plugin.</p>';
				echo '</div>';
				continue;
			}

			$changelog_html = self::parse_readme_changelog($readme_path);
			if ($changelog_html === '') {
				echo '<p class="description">No changelog section found in readme.txt.</p>';
			} else {
				echo '<div style="max-height:400px;overflow-y:auto;font-size:0.9em;">';
				echo $changelog_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped in parse_readme_changelog
				echo '</div>';
			}

			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Parse a readme.txt file and return the == Changelog == section as HTML.
	 */
	private static function parse_readme_changelog(string $path): string {
		$text = (string) file_get_contents($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if (!preg_match('/== Changelog ==\r?\n(.*?)(?:\r?\n== |\z)/s', $text, $m)) {
			return '';
		}
		$lines  = preg_split('/\r?\n/', trim($m[1]));
		$html   = '';
		$in_ul  = false;
		foreach ($lines as $line) {
			$line = trim($line);
			if ($line === '') {
				if ($in_ul) { $html .= '</ul>'; $in_ul = false; }
				continue;
			}
			if (preg_match('/^= (.+) =\s*$/', $line, $vm)) {
				if ($in_ul) { $html .= '</ul>'; $in_ul = false; }
				$html .= '<p style="margin:10px 0 4px;font-weight:700;">' . esc_html($vm[1]) . '</p>';
			} elseif (str_starts_with($line, '* ')) {
				if (!$in_ul) { $html .= '<ul style="margin:0 0 4px 18px;list-style:disc;">'; $in_ul = true; }
				$html .= '<li>' . esc_html(substr($line, 2)) . '</li>';
			} else {
				if ($in_ul) { $html .= '</ul>'; $in_ul = false; }
				$html .= '<p style="margin:4px 0;">' . esc_html($line) . '</p>';
			}
		}
		if ($in_ul) $html .= '</ul>';
		return $html;
	}

	// ── Private hub helpers ───────────────────────────────────────────────

	/** Return all saved private hubs as an array indexed by hub_index. */
	public static function get_private_hubs(): array {
		$hubs = get_option(self::OPT_PRIVATE_HUBS, []);
		if (!is_array($hubs)) return [];
		return $hubs;
	}

	/** Return the next available hub_index (gaps filled first, then max+1). */
	private static function next_private_hub_index(): int {
		$hubs = self::get_private_hubs();
		$used = array_column($hubs, 'hub_index');
		for ($i = 1; $i <= 20; $i++) {
			if (!in_array($i, $used, true)) return $i;
		}
		return count($used) + 1;
	}

	/**
	 * Get (and auto-refresh) the access token for a private hub character.
	 * Returns ['ok'=>true,'access'=>string] or ['ok'=>false,'err'=>string].
	 */
	public static function get_private_hub_access_token(int $idx): array {
		$access = ETT_Crypto::decrypt_triplet(
			(string) get_option('ett_priv_access_' . $idx, ''),
			(string) get_option('ett_priv_access_' . $idx . '_iv', ''),
			(string) get_option('ett_priv_access_' . $idx . '_mac', '')
		);
		$refresh = ETT_Crypto::decrypt_triplet(
			(string) get_option('ett_priv_refresh_' . $idx, ''),
			(string) get_option('ett_priv_refresh_' . $idx . '_iv', ''),
			(string) get_option('ett_priv_refresh_' . $idx . '_mac', '')
		);
		$expires_at = (int) get_option('ett_priv_expires_' . $idx, 0);

		if (!empty($access) && $expires_at > (time() + 30)) {
			return ['ok' => true, 'access' => $access];
		}
		if (empty($refresh)) {
			return ['ok' => false, 'err' => 'Private hub ' . $idx . ' not authenticated'];
		}

		$r = self::sso_token_request([
			'grant_type'    => 'refresh_token',
			'refresh_token' => $refresh,
		]);
		if (!$r['ok']) return $r;

		$tok  = $r['data'];
		$encA = ETT_Crypto::encrypt_triplet((string) $tok['access_token']);
		update_option('ett_priv_access_' . $idx, $encA['ciphertext'], false);
		update_option('ett_priv_access_' . $idx . '_iv', $encA['iv'], false);
		update_option('ett_priv_access_' . $idx . '_mac', $encA['mac'], false);

		if (!empty($tok['refresh_token'])) {
			$encR = ETT_Crypto::encrypt_triplet((string) $tok['refresh_token']);
			update_option('ett_priv_refresh_' . $idx, $encR['ciphertext'], false);
			update_option('ett_priv_refresh_' . $idx . '_iv', $encR['iv'], false);
			update_option('ett_priv_refresh_' . $idx . '_mac', $encR['mac'], false);
		}

		$exp = isset($tok['expires_in']) ? (int) $tok['expires_in'] : 1200;
		update_option('ett_priv_expires_' . $idx, time() + max(60, $exp) - 30);

		return ['ok' => true, 'access' => (string) $tok['access_token']];
	}

	/** Start OAuth flow for a private hub character. */
	public static function handle_priv_sso_start(): void {
		if (!current_user_can(self::CAP)) wp_die('Insufficient permissions.');
		$idx = max(1, absint( wp_unslash( $_POST['hub_index'] ?? 0 ) ));
		check_admin_referer('ett_priv_sso_start_' . $idx);

		$client_id = get_option(self::OPT_SSO_CLIENT_ID, '');
		$client_secret = ETT_Crypto::decrypt_triplet(
			(string) get_option(self::OPT_SSO_CLIENT_SECRET, ''),
			(string) get_option(self::OPT_SSO_CLIENT_SECRET . '_iv', ''),
			(string) get_option(self::OPT_SSO_CLIENT_SECRET . '_mac', '')
		);

		if (empty($client_id) || empty($client_secret)) {
			wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'sso_err' => 'missing_app'], admin_url('admin.php')));
			exit;
		}

		$state = wp_generate_password(24, false, false);
		set_transient('ett_priv_state_' . $idx . '_' . $state, $idx, 10 * MINUTE_IN_SECONDS);

		$url = add_query_arg([
			'response_type' => 'code',
			'redirect_uri'  => self::unified_callback_url(),
			'client_id'     => $client_id,
			'scope'         => self::sso_scopes(),
			'state'         => $state,
		], 'https://login.eveonline.com/v2/oauth/authorize/');

		wp_safe_redirect($url);
		exit;
	}

	/** OAuth callback for a private hub character. */
	public static function handle_priv_sso_callback(int $idx): void {
		if (!current_user_can(self::CAP)) wp_die('Insufficient permissions.');

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback
		$code  = isset($_GET['code'])  ? sanitize_text_field(wp_unslash($_GET['code']))  : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback
		$state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';

		if (empty($code) || empty($state) || !get_transient('ett_priv_state_' . $idx . '_' . $state)) {
			wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'sso_err' => 'bad_state'], admin_url('admin.php')));
			exit;
		}
		delete_transient('ett_priv_state_' . $idx . '_' . $state);

		$r = self::sso_token_request(['grant_type' => 'authorization_code', 'code' => $code]);
		if (!$r['ok']) {
			wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'sso_err' => 'token'], admin_url('admin.php')));
			exit;
		}

		$tok  = $r['data'];
		$encA = ETT_Crypto::encrypt_triplet((string) $tok['access_token']);
		update_option('ett_priv_access_' . $idx, $encA['ciphertext'], false);
		update_option('ett_priv_access_' . $idx . '_iv', $encA['iv'], false);
		update_option('ett_priv_access_' . $idx . '_mac', $encA['mac'], false);

		$encR = ETT_Crypto::encrypt_triplet((string) $tok['refresh_token']);
		update_option('ett_priv_refresh_' . $idx, $encR['ciphertext'], false);
		update_option('ett_priv_refresh_' . $idx . '_iv', $encR['iv'], false);
		update_option('ett_priv_refresh_' . $idx . '_mac', $encR['mac'], false);

		$exp = isset($tok['expires_in']) ? (int) $tok['expires_in'] : 1200;
		update_option('ett_priv_expires_' . $idx, time() + max(60, $exp) - 30);

		$claims = self::jwt_claims((string) $tok['access_token']);
		if (!empty($claims['name'])) {
			update_option('ett_priv_char_name_' . $idx, (string) $claims['name']);
		}
		if (!empty($claims['sub']) && preg_match('/^CHARACTER:EVE:(\d+)$/', (string) $claims['sub'], $m)) {
			update_option('ett_priv_char_id_' . $idx, (int) $m[1]);
		}

		// Ensure the hub entry has char_source=private and char_id set
		$hubs = self::get_private_hubs();
		foreach ($hubs as &$hub) {
			if ((int) ($hub['hub_index'] ?? 0) === $idx) {
				$hub['char_source'] = 'private';
				$hub['char_id']     = (int) get_option('ett_priv_char_id_' . $idx, 0);
				$hub['char_name']   = (string) get_option('ett_priv_char_name_' . $idx, '');
				break;
			}
		}
		unset($hub);
		update_option(self::OPT_PRIVATE_HUBS, $hubs, false);

		wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'sso_ok' => 1], admin_url('admin.php')));
		exit;
	}

	/** Disconnect a private hub character. */
	public static function handle_priv_sso_disconnect(): void {
		if (!current_user_can(self::CAP)) wp_die('Insufficient permissions.');
		$idx = max(1, absint( wp_unslash( $_POST['hub_index'] ?? 0 ) ));
		check_admin_referer('ett_priv_disconnect_' . $idx);

		foreach (['access', 'refresh'] as $part) {
			delete_option('ett_priv_' . $part . '_' . $idx);
			delete_option('ett_priv_' . $part . '_' . $idx . '_iv');
			delete_option('ett_priv_' . $part . '_' . $idx . '_mac');
		}
		delete_option('ett_priv_expires_' . $idx);
		delete_option('ett_priv_char_id_' . $idx);
		delete_option('ett_priv_char_name_' . $idx);

		$hubs = self::get_private_hubs();
		foreach ($hubs as &$hub) {
			if ((int) ($hub['hub_index'] ?? 0) === $idx) {
				$hub['char_source'] = 'primary';
				$hub['char_id']     = 0;
				$hub['char_name']   = '';
				break;
			}
		}
		unset($hub);
		update_option(self::OPT_PRIVATE_HUBS, $hubs, false);

		wp_safe_redirect(add_query_arg(['page' => self::SLUG], admin_url('admin.php')));
		exit;
	}

	/** AJAX: create a new private hub slot and return its index. */
	public static function ajax_priv_add_hub(): void {
		if (!current_user_can(self::CAP)) wp_send_json_error('Insufficient permissions', 403);
		check_ajax_referer('ett_admin');

		$hubs = self::get_private_hubs();
		$idx  = self::next_private_hub_index();
		$hubs[] = [
			'hub_index'   => $idx,
			'system_name' => '',
			'system_id'   => 0,
			'region_id'   => 0,
			'char_source' => 'primary',
			'char_id'     => 0,
			'char_name'   => '',
			'structures'  => [],
		];
		update_option(self::OPT_PRIVATE_HUBS, $hubs, false);

		wp_send_json_success([
			'hub_index'       => $idx,
			'sso_authed'      => (bool) !empty(ETT_Crypto::decrypt_triplet(
				(string) get_option(self::OPT_SSO_ACCESS_TOKEN, ''),
				(string) get_option(self::OPT_SSO_ACCESS_TOKEN . '_iv', ''),
				(string) get_option(self::OPT_SSO_ACCESS_TOKEN . '_mac', '')
			)),
			'primary_char_name' => (string) get_option(self::OPT_SSO_CHARACTER_NAME, ''),
			'priv_start_url'  => esc_url(admin_url('admin-post.php')),
			'nonce'           => wp_create_nonce('ett_priv_sso_start_' . $idx),
		]);
	}

	/** AJAX: remove a private hub slot. */
	public static function ajax_priv_remove_hub(): void {
		if (!current_user_can(self::CAP)) wp_send_json_error('Insufficient permissions', 403);
		check_ajax_referer('ett_admin');

		$idx  = max(1, absint( wp_unslash( $_POST['hub_index'] ?? 0 ) ));
		$hubs = self::get_private_hubs();
		$hubs = array_values(array_filter($hubs, fn($h) => (int)($h['hub_index'] ?? 0) !== $idx));
		update_option(self::OPT_PRIVATE_HUBS, $hubs, false);

		// Clean up token options
		foreach (['access', 'refresh'] as $part) {
			delete_option('ett_priv_' . $part . '_' . $idx);
			delete_option('ett_priv_' . $part . '_' . $idx . '_iv');
			delete_option('ett_priv_' . $part . '_' . $idx . '_mac');
		}
		delete_option('ett_priv_expires_' . $idx);
		delete_option('ett_priv_char_id_' . $idx);
		delete_option('ett_priv_char_name_' . $idx);

		wp_send_json_success(['removed' => $idx]);
	}

	/** AJAX: save a private hub's settings (system, char_source, structures). */
	public static function ajax_priv_save_hub(): void {
		if (!current_user_can(self::CAP)) wp_send_json_error('Insufficient permissions', 403);
		check_ajax_referer('ett_admin');

		$idx         = max(1, absint( wp_unslash( $_POST['hub_index'] ?? 0 ) ));
		$system_name = sanitize_text_field(wp_unslash($_POST['system_name'] ?? ''));
		$system_id   = absint( wp_unslash( $_POST['system_id'] ?? 0 ) );
		$region_id   = absint( wp_unslash( $_POST['region_id'] ?? 0 ) );
		// If region_id or system_id are not posted (page reload before system was changed),
		// preserve the existing saved values rather than overwriting with 0.
		if ($region_id <= 0 || $system_id <= 0) {
			foreach (self::get_private_hubs() as $existing_hub) {
				if ((int) ($existing_hub['hub_index'] ?? 0) === $idx) {
					if ($region_id <= 0) $region_id = (int) ($existing_hub['region_id'] ?? 0);
					if ($system_id <= 0) $system_id  = (int) ($existing_hub['system_id']  ?? 0);
					break;
				}
			}
		}
		$char_source = in_array(wp_unslash($_POST['char_source'] ?? ''), ['primary', 'private'], true)
			? sanitize_key(wp_unslash($_POST['char_source']))
			: 'primary';

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$structures_in = isset($_POST['structures']) ? (array) wp_unslash($_POST['structures']) : [];
		$structures = [];
		foreach ($structures_in as $key => $st) {
			if (is_array($st)) {
				// JS-built format: structures sent as array of objects {id, name, enabled}
				$structures[] = [
					'id'      => (int) ($st['id'] ?? 0),
					'name'    => sanitize_text_field((string) ($st['name'] ?? '')),
					'enabled' => !empty($st['enabled']),
				];
			} else {
				// PHP checkbox format: structures[STRUCT_ID] = '1' (checked) or absent (unchecked).
				// $key is the structure_id, $st is the checkbox value ('1').
				$struct_id = (int) $key;
				if ($struct_id <= 0) continue;
				// Look up the name from the existing saved hub config so we don't lose it.
				$existing_name = '';
				foreach (self::get_private_hubs() as $existing_hub) {
					if ((int) ($existing_hub['hub_index'] ?? 0) !== $idx) continue;
					foreach ((array) ($existing_hub['structures'] ?? []) as $existing_st) {
						if ((int) ($existing_st['id'] ?? 0) === $struct_id) {
							$existing_name = (string) ($existing_st['name'] ?? '');
							break 2;
						}
					}
				}
				$structures[] = [
					'id'      => $struct_id,
					'name'    => $existing_name,
					'enabled' => true, // present in POST means checked
				];
			}
		}

		// Any structure that was saved previously but is absent from POST (unchecked checkbox)
		// needs to be preserved as enabled=false so it stays in the list.
		foreach (self::get_private_hubs() as $existing_hub) {
			if ((int) ($existing_hub['hub_index'] ?? 0) !== $idx) continue;
			foreach ((array) ($existing_hub['structures'] ?? []) as $existing_st) {
				$existing_id = (int) ($existing_st['id'] ?? 0);
				if ($existing_id <= 0) continue;
				$already_present = false;
				foreach ($structures as $s) {
					if ((int) $s['id'] === $existing_id) { $already_present = true; break; }
				}
				if (!$already_present) {
					$structures[] = [
						'id'      => $existing_id,
						'name'    => (string) ($existing_st['name'] ?? ''),
						'enabled' => false,
					];
				}
			}
			break;
		}

		$hubs = self::get_private_hubs();
		$found = false;
		foreach ($hubs as &$hub) {
			if ((int) ($hub['hub_index'] ?? 0) === $idx) {
				$hub['system_name'] = $system_name;
				$hub['system_id']   = $system_id;
				$hub['region_id']   = $region_id;
				$hub['char_source'] = $char_source;
				$hub['structures']  = $structures;
				$found = true;
				break;
			}
		}
		unset($hub);

		if (!$found) {
			$hubs[] = [
				'hub_index'   => $idx,
				'system_name' => $system_name,
				'system_id'   => $system_id,
				'region_id'   => $region_id,
				'char_source' => $char_source,
				'char_id'     => 0,
				'char_name'   => '',
				'structures'  => $structures,
			];
		}

		update_option(self::OPT_PRIVATE_HUBS, $hubs, false);
		wp_send_json_success(['saved' => true]);
	}

	/**
	 * AJAX: search for a solar system by name fragment (from ett_mapSolarSystems).
	 * Returns up to 10 matching systems with their IDs and region IDs.
	 */
	public static function ajax_system_search(): void {
		if (!current_user_can(self::CAP)) wp_send_json_error('Insufficient permissions', 403);
		check_ajax_referer('ett_admin');

		$q = sanitize_text_field(wp_unslash($_POST['q'] ?? ''));
		if (strlen($q) < 1) {
			wp_send_json_success(['systems' => []]);
			return;
		}

		if (!ETT_ExternalDB::is_configured()) {
			wp_send_json_error('External database not configured.', 400);
			return;
		}

		try {
			$pdo  = ETT_ExternalDB::pdo();
			$stmt = $pdo->prepare(
				'SELECT solar_system_id, name, region_id
				 FROM ett_mapSolarSystems
				 WHERE name LIKE :q
				 ORDER BY name ASC
				 LIMIT 10'
			);
			$stmt->execute([':q' => $q . '%']);
			$rows = $stmt->fetchAll();
			// Also search for contains if prefix returns < 5 results
			if (count($rows) < 5) {
				$stmt2 = $pdo->prepare(
					'SELECT solar_system_id, name, region_id
					 FROM ett_mapSolarSystems
					 WHERE name LIKE :q AND name NOT LIKE :pq
					 ORDER BY name ASC
					 LIMIT 10'
				);
				$stmt2->execute([':q' => '%' . $q . '%', ':pq' => $q . '%']);
				$rows = array_merge($rows, $stmt2->fetchAll());
				// Deduplicate by solar_system_id
				$seen = [];
				$rows = array_filter($rows, function ($r) use (&$seen) {
					$id = $r['solar_system_id'];
					if (isset($seen[$id])) return false;
					$seen[$id] = true;
					return true;
				});
				$rows = array_slice(array_values($rows), 0, 10);
			}
		} catch (Exception $e) {
			wp_send_json_error('DB error: ' . $e->getMessage(), 500);
			return;
		}

		wp_send_json_success(['systems' => $rows]);
	}

	/**
	 * AJAX: fetch accessible structures in a given solar system for a private hub character.
	 * Uses ESI character search to find structures by system name prefix, then resolves each.
	 */
	public static function ajax_priv_fetch_structures(): void {
		if (!current_user_can(self::CAP)) wp_send_json_error('Insufficient permissions', 403);
		check_ajax_referer('ett_admin');

		$idx         = max(1, absint( wp_unslash( $_POST['hub_index'] ?? 0 ) ));
		$system_id   = absint( wp_unslash( $_POST['system_id'] ?? 0 ) );
		$system_name = sanitize_text_field(wp_unslash($_POST['system_name'] ?? ''));
		$char_source = sanitize_key(wp_unslash($_POST['char_source'] ?? 'primary'));

		if ($system_id <= 0 || $system_name === '') {
			wp_send_json_error('Invalid system.', 400);
			return;
		}

		// Get token
		if ($char_source === 'private') {
			$tok = self::get_private_hub_access_token($idx);
		} else {
			$tok = self::get_access_token_for_jobs();
		}

		if (empty($tok['ok'])) {
			wp_send_json_error($tok['err'] ?? 'Not authenticated.', 400);
			return;
		}
		$access = $tok['access'];

		// We need the character ID for the search endpoint
		$char_id = ($char_source === 'private')
			? (int) get_option('ett_priv_char_id_' . $idx, 0)
			: (int) get_option(self::OPT_SSO_CHARACTER_ID, 0);

		if (!$char_id) {
			wp_send_json_error('Character ID not found. Re-authenticate.', 400);
			return;
		}

		// Search for structures in this system using the system name as prefix
		$needle     = $system_name . ' -';
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

		if (is_wp_error($sresp)) {
			wp_send_json_error('ESI search failed: ' . $sresp->get_error_message(), 500);
			return;
		}

		$sjson = json_decode(wp_remote_retrieve_body($sresp), true);
		$ids   = (is_array($sjson) && !empty($sjson['structure']) && is_array($sjson['structure']))
			? array_map('intval', $sjson['structure'])
			: [];

		if (empty($ids)) {
			wp_send_json_success(['structures' => []]);
			return;
		}

		$ids = array_slice($ids, 0, 100);
		$out = [];

		foreach ($ids as $sid) {
			$rurl  = 'https://esi.evetech.net/latest/universe/structures/' . $sid . '/?datasource=tranquility';
			$rresp = wp_remote_get($rurl, [
				'timeout' => 20,
				'headers' => [
					'Authorization' => 'Bearer ' . $access,
					'Accept'        => 'application/json',
				],
			]);

			if (is_wp_error($rresp)) continue;
			if ((int) wp_remote_retrieve_response_code($rresp) !== 200) continue;

			$rjson = json_decode(wp_remote_retrieve_body($rresp), true);
			if (!is_array($rjson)) continue;

			// Only keep structures in the requested system
			if ((int) ($rjson['solar_system_id'] ?? 0) !== $system_id) continue;

			$out[] = [
				'id'   => $sid,
				'name' => (string) ($rjson['name'] ?? 'Structure ' . $sid),
			];
		}

		wp_send_json_success(['structures' => $out]);
	}

    public static function ajax_save_schedule() {
        if (!current_user_can(self::CAP)) wp_send_json_error('Insufficient permissions', 403);
        check_ajax_referer('ett_admin');
        self::save_schedule_from_request($_POST);
        wp_send_json_success(['saved' => true]);
    }

    public static function ajax_cancel_schedule() {
        if (!current_user_can(self::CAP)) wp_send_json_error('Insufficient permissions', 403);
        check_ajax_referer('ett_admin');
        $enabled = (bool) absint( wp_unslash( $_POST['enabled'] ?? '1' ) );
        update_option(self::OPT_SCHED_ENABLED, $enabled ? '1' : '0', false);
        wp_send_json_success(['enabled' => $enabled]);
    }

    public static function ajax_clear_history() {
        if (!current_user_can(self::CAP)) wp_send_json_error('Insufficient permissions', 403);
        check_ajax_referer('ett_admin');
        try {
            $pdo = ETT_ExternalDB::pdo();
            $pdo->exec("DELETE FROM ett_jobs WHERE status IN ('done','error','cancelled')");
            wp_send_json_success(['cleared' => true]);
        } catch (\Throwable $e) {
            wp_send_json_error('Failed: ' . $e->getMessage());
        }
    }

    public static function ajax_next_run() {
        if (!current_user_can(self::CAP)) wp_send_json_error('Insufficient permissions', 403);
        check_ajax_referer('ett_admin');

        $debug = ETT_Runner::get_due_debug();
        $enabled = get_option(self::OPT_SCHED_ENABLED, '1') !== '0';

        if (!$enabled) {
            $next_txt = 'Schedule paused';
        } elseif (!empty($debug['next_slot'])) {
            $next_txt = $debug['next_slot'];
        } else {
            $next_txt = 'Unknown';
        }

        wp_send_json_success([
            'next_txt' => $next_txt,
            'debug'    => $debug,
            'enabled'  => $enabled,
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
			'EVE Trade Tools',
			'EVE Trade Tools',
			self::CAP,
			self::SLUG,
			[__CLASS__, 'render'],
			'dashicons-database'
		);

		// Allow other plugins to register tabs
		do_action('ett_admin_tabs');

		// Changelog tab — always last, registered by Price Helper itself
		self::register_tab('changelog', 'Changelog', [__CLASS__, 'render_changelog_tab']);
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

		$access = ETT_Crypto::decrypt_triplet(
			(string)get_option(self::OPT_SSO_ACCESS_TOKEN, ''),
			(string)get_option(self::OPT_SSO_ACCESS_TOKEN . '_iv', ''),
			(string)get_option(self::OPT_SSO_ACCESS_TOKEN . '_mac', '')
		);
		$refresh = ETT_Crypto::decrypt_triplet(
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

		$sched_enabled = get_option(self::OPT_SCHED_ENABLED, '1') !== '0';

		// Build a hub_key → display label map for private hubs so JS can
		// translate both the internal 'private_hub_N' key (used as current_hub)
		// and the sanitized system-name key (used as current_region / hub_key in DB).
		$private_hub_labels = [];
		$ph_configs = self::get_private_hubs();
		if (!empty($ph_configs) && ETT_ExternalDB::is_configured()) {
			// Gather system_ids that need canonical name lookup from the SDE table.
			$system_id_map = []; // system_id => hub_index
			foreach ($ph_configs as $ph) {
				$idx       = (int) ($ph['hub_index'] ?? 0);
				$system_id = (int) ($ph['system_id']  ?? 0);
				if ($idx > 0 && $system_id > 0) {
					$system_id_map[$system_id] = $idx;
				}
			}
			$canonical_names = []; // system_id => canonical_name
			if (!empty($system_id_map)) {
				try {
					$pdo        = ETT_ExternalDB::pdo();
					$ids_safe   = implode(',', array_map('intval', array_keys($system_id_map)));
					$name_rows  = $pdo->query("SELECT solar_system_id, name FROM ett_mapSolarSystems WHERE solar_system_id IN ({$ids_safe})")->fetchAll();
					foreach ($name_rows as $row) {
						$canonical_names[(int) $row['solar_system_id']] = (string) $row['name'];
					}
				} catch (\Throwable $e) {
					// DB unavailable — fall back to stored system_name below
				}
			}
		}

		foreach (($ph_configs ?? []) as $ph) {
			$idx         = (int) ($ph['hub_index'] ?? 0);
			$system_name = (string) ($ph['system_name'] ?? '');
			$system_id   = (int)  ($ph['system_id']   ?? 0);
			if ($idx <= 0 || $system_name === '') continue;
			// Use the canonical SDE name if available, otherwise the user-entered name as-is.
			$display             = (isset($canonical_names[$system_id]) && $canonical_names[$system_id] !== '')
				? $canonical_names[$system_id]
				: $system_name;
			$internal_key        = 'private_hub_' . $idx;
			$price_key           = sanitize_key($system_name);
			$private_hub_labels[$internal_key] = $display;
			$private_hub_labels[$price_key]    = $display;
		}

		wp_localize_script('ett-admin', 'ETT_ADMIN', [
			'ajax_url'                        => admin_url('admin-ajax.php'),
			'nonce'                           => wp_create_nonce('ett_admin'),
			'last_price_run_completed_at'     => $last,
			'wp_timezone_string'              => $tz,
			'sso_authed'                      => $sso_authed,
			'sso_character_name'              => $char_name,
			'sso_cache_at'                    => $cache_at,
			'secondary_pairs'                 => self::secondary_pairs(),
			'last_price_run_completed_at_iso' => $last_iso,
			'sched_enabled'                   => $sched_enabled,
			'home_url'                        => trailingslashit(home_url()),
			'private_hub_labels'              => $private_hub_labels,
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
		$client_secret = ETT_Crypto::decrypt_triplet(
			(string)get_option(self::OPT_SSO_CLIENT_SECRET, ''),
			(string)get_option(self::OPT_SSO_CLIENT_SECRET . '_iv', ''),
			(string)get_option(self::OPT_SSO_CLIENT_SECRET . '_mac', '')
		);

		$access = ETT_Crypto::decrypt_triplet(
			(string)get_option(self::OPT_SSO_ACCESS_TOKEN, ''),
			(string)get_option(self::OPT_SSO_ACCESS_TOKEN . '_iv', ''),
			(string)get_option(self::OPT_SSO_ACCESS_TOKEN . '_mac', '')
		);

		$refresh = ETT_Crypto::decrypt_triplet(
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

		// Private hubs
		$private_hubs = get_option(self::OPT_PRIVATE_HUBS, []);
		if (!is_array($private_hubs)) $private_hubs = [];
		// Inject char_name from stored options into each hub for template display
		foreach ($private_hubs as &$ph) {
			$idx = (int) ($ph['hub_index'] ?? 0);
			if ($idx > 0 && ($ph['char_source'] ?? '') === 'private') {
				$ph['char_name'] = (string) get_option('ett_priv_char_name_' . $idx, '');
				$ph['char_id']   = (int) get_option('ett_priv_char_id_' . $idx, 0);
			}
		}
		unset($ph);

		$import_meta       = get_option(self::OPT_LAST_IMPORT, []);
		$sched_start_time  = get_option(self::OPT_SCHED_START_TIME, '03:00');
		$sched_freq_hours  = (int)get_option(self::OPT_SCHED_FREQ_HOURS, 24);
		$sched_enabled     = get_option(self::OPT_SCHED_ENABLED, '1') !== '0';

		$batch_max_pages   = (int)get_option(self::OPT_BATCH_MAX_PAGES, 5);
		$batch_max_seconds = (int)get_option(self::OPT_BATCH_MAX_SECONDS, 10);
		$history_batch_size = (int)get_option(self::OPT_HISTORY_BATCH_SIZE, 20);

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
					WHERE job_type IN ('prices','history')
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
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab routing param, not an action
			$active_tab = sanitize_key(wp_unslash($_GET['tab'] ?? 'price-helper'));

		// ── Pre-compute template variables ─────────────────────────────────────
		// SDE import details
		$last_import_txt = !empty($import_meta['imported_at']) ? (string)$import_meta['imported_at'] : 'Never';
		$_sde_parts      = [];
		foreach (['invMarketGroups','invMetaGroups','invTypes','invMetaTypes','invTypeMaterials','industryActivityProducts','mapSolarSystems'] as $_k){
			if (isset($import_meta[$_k])) $_sde_parts[] = $_k . ': ' . number_format((int)$import_meta[$_k]);
		}
		$details_txt = !empty($_sde_parts) ? implode(' | ', $_sde_parts) : '';

		// Schedule
		$runner_token     = ETT_Runner::get_or_create_token();
		$_site_url        = trailingslashit(home_url());
		$curl_cmd         = 'curl -s "' . $_site_url . '?ett_ph_run=' . $runner_token . '"';
		$_wp_path         = ABSPATH;
		$cli_cmd          = 'wp --path=' . escapeshellarg(rtrim($_wp_path, '/')) . ' ett-prices run --quiet';
		$_due_debug       = ETT_Runner::get_due_debug();
		$next_slot_display = $sched_enabled ? ($_due_debug['next_slot'] ?? 'Unknown') : 'Schedule paused';

		// Actions card last run
		$lastRun = get_option(self::OPT_LAST_PRICE_RUN, '');

		// Market-group tree HTML (render_tree is private, so capture it here)
		ob_start();
		self::render_tree($tree, $selected_groups);
		$market_tree_html = ob_get_clean();
		?>
		<div class="wrap ett-wrap">
			<h1>EVE Trade Tools</h1>

			<nav class="nav-tab-wrapper ett-tab-nav">
				<a href="<?php echo esc_url(add_query_arg(['page' => self::SLUG, 'tab' => 'price-helper'], admin_url('admin.php'))); ?>"
				   class="nav-tab<?php echo $active_tab === 'price-helper' ? ' nav-tab-active' : ''; ?>">
					Price Helper
				</a>
				<?php foreach (self::$tabs as $tab): ?>
				<a href="<?php echo esc_url(add_query_arg(['page' => self::SLUG, 'tab' => $tab['slug']], admin_url('admin.php'))); ?>"
				   class="nav-tab<?php echo $active_tab === $tab['slug'] ? ' nav-tab-active' : ''; ?>">
					<?php echo esc_html($tab['label']); ?>
				</a>
				<?php endforeach; ?>
			</nav>

            <?php if ($active_tab === 'price-helper'): ?>
			<div class="ett-tab-panel">
				<div class="ett-grid">
					<?php self::render_template('card-db.php',
						compact('db', 'db_test', 'schema_ok')); ?>
					<?php self::render_template('card-sde.php',
						compact('import_meta', 'last_import_txt', 'details_txt', 'schema_ok')); ?>
				</div>
				<div class="ett-grid">
					<?php self::render_template('card-sso.php',
						compact('client_id', 'client_secret', 'sso_authed', 'char_name', 'cache', 'cache_at')); ?>
					<?php self::render_template('card-market-groups.php',
						compact('tree', 'selected_groups', 'schema_ok', 'typeid_display', 'market_tree_html')); ?>
				</div>
				<?php self::render_template('card-hubs.php',
					compact('selected_hubs', 'secondary_structures', 'tertiary_structures',
					        'sso_authed', 'cache', 'cache_at')); ?>
				<?php self::render_template('card-private-hubs.php',
					array_merge(compact('private_hubs', 'sso_authed'), ['primary_char_name' => $char_name])); ?>
				<?php self::render_template('card-actions.php',
					compact('schema_ok', 'lastRun', 'tz',
					        'batch_max_pages', 'batch_max_seconds', 'history_batch_size')); ?>
				<?php self::render_template('card-schedule.php',
					compact('sched_start_time', 'sched_freq_hours', 'sched_enabled', 'tz',
					        'runner_token', 'curl_cmd', 'cli_cmd', 'next_slot_display')); ?>
				<?php self::render_template('card-run-history.php',
					compact('job_history', 'job_history_err', 'tz')); ?>
			</div><!-- /.ett-tab-panel -->
			<?php endif; // price-helper tab ?>

			<?php foreach (self::$tabs as $tab): ?>
			<?php if ($active_tab === $tab['slug']): ?>
			<div class="ett-tab-panel">
				<?php call_user_func($tab['callback']); ?>
			</div><!-- /.ett-tab-panel -->
			<?php endif; ?>
			<?php endforeach; ?>

		</div><!-- /.wrap -->
		<?php
	}

	/**
	 * Include a template file, extracting the provided variables into local scope.
	 *
	 * @param string $template Path relative to the plugin's templates/ directory.
	 * @param array  $vars     Variables to expose inside the template.
	 */
	private static function render_template(string $template, array $vars = []): void {
		if ($vars) extract($vars, EXTR_SKIP); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- intentional template variable injection
		include ETT_PH_PATH . 'templates/' . $template;
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

    public static function handle_save_schedule() {
        if (!current_user_can(self::CAP)) wp_die('Insufficient permissions.');
        check_admin_referer('ett_save_schedule');

        self::save_schedule_from_request($_POST);
        self::save_perf_from_request($_POST);

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

    	$history_batch_size = (int) wp_unslash($src['history_batch_size'] ?? 5);
    	if ($history_batch_size < 1)  $history_batch_size = 1;
    	if ($history_batch_size > 50) $history_batch_size = 50;
    	update_option(self::OPT_HISTORY_BATCH_SIZE, $history_batch_size, false);
    
    	return [$batch_max_pages, $batch_max_seconds];
    }

	/**
	 * Handles the SDE ZIP import form submission (both upload and server-path).
	 * Registered as: admin_post_ett_import_sde
	 */
	public static function handle_import_sde(): void {
		if (!current_user_can(self::CAP)) wp_die('Insufficient permissions.');
		check_admin_referer('ett_import_sde');

		// Best-effort: allow long-running import.
		if (function_exists('ignore_user_abort')) @ignore_user_abort(true);
		// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		if (function_exists('set_time_limit')) { @set_time_limit(0); }
		// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		@ini_set('max_execution_time', '0');
		// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		@ini_set('memory_limit', '1024M');

		// Fatal-error safety net: redirect with an error message rather than a blank page.
		register_shutdown_function(function () {
			$e = error_get_last();
			if (!$e) return;
			$fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
			if (!in_array($e['type'], $fatalTypes, true)) return;
			$msg = sprintf('Fatal error [%d]: %s in %s:%d', $e['type'], $e['message'], $e['file'], $e['line']);
			if (!headers_sent()) {
				wp_safe_redirect(add_query_arg(
					['page' => self::SLUG, 'imported' => 0, 'err' => rawurlencode(sanitize_text_field($msg))],
					admin_url('admin.php')
				));
				exit;
			}
		});

		// Resolve the ZIP path from whichever source was chosen.
		$source        = sanitize_key(wp_unslash($_POST['ett_sde_source'] ?? 'upload'));
		$zip_path      = '';
		$delete_after  = false; // only true for temp-uploaded files

		if ($source === 'path') {
			$zip_path = sanitize_text_field(wp_unslash($_POST['sde_zip_path'] ?? ''));
			if ($zip_path === '' || !file_exists($zip_path) || !is_readable($zip_path)) {
				wp_safe_redirect(add_query_arg(
					['page' => self::SLUG, 'imported' => 0, 'err' => rawurlencode('Server path not found or not readable: ' . $zip_path)],
					admin_url('admin.php')
				));
				exit;
			}
		} else {
			// Upload source.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tmp_name is a server-generated path; security is enforced by is_uploaded_file() below, not sanitization.
			$upload = $_FILES['sde_zip'] ?? null;
			if (
				empty($upload) ||
				!isset($upload['error'], $upload['tmp_name']) ||
				$upload['error'] !== UPLOAD_ERR_OK ||
				!is_uploaded_file($upload['tmp_name'])
			) {
				$upload_err = $upload['error'] ?? -1;
				wp_safe_redirect(add_query_arg(
					['page' => self::SLUG, 'imported' => 0, 'err' => rawurlencode('File upload failed (error code ' . (int)$upload_err . '). Check upload_max_filesize and post_max_size in php.ini.')],
					admin_url('admin.php')
				));
				exit;
			}
			$zip_path     = (string) $upload['tmp_name'];
			$delete_after = true;
		}

		// Connect to the external DB.
		try {
			ETT_ExternalDB::ensure_schema();
			$pdo = ETT_ExternalDB::pdo();
		} catch (Exception $e) {
			if ($delete_after && file_exists($zip_path)) @wp_delete_file($zip_path);
			wp_safe_redirect(add_query_arg(
				['page' => self::SLUG, 'imported' => 0, 'db_err' => rawurlencode(sanitize_text_field($e->getMessage()))],
				admin_url('admin.php')
			));
			exit;
		}

		// Run the SDE import.
		try {
			$meta = ETT_SDE::import_from_zip($zip_path, $pdo);
			update_option(self::OPT_LAST_IMPORT, $meta, false);
			wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'imported' => 1], admin_url('admin.php')));
			exit;
		} catch (Exception $e) {
			wp_safe_redirect(add_query_arg(
				['page' => self::SLUG, 'imported' => 0, 'err' => rawurlencode(sanitize_text_field($e->getMessage()))],
				admin_url('admin.php')
			));
			exit;
		} finally {
			if ($delete_after && file_exists($zip_path)) @wp_delete_file($zip_path);
		}
	}

	// ── AJAX: chunked SDE import ───────────────────────────────────────────

	/**
	 * Step 0 of the chunked import.
	 * Upload source: receives the ZIP via multipart upload, moves it to a
	 *   protected temp dir, stores the path in a user-scoped transient.
	 * Path source: validates the server-side path and stores it in the transient.
	 *
	 * Returns: { token: string, filename: string }
	 */
	public static function ajax_sde_prepare(): void {
		if (!current_user_can(self::CAP)) wp_send_json_error('Insufficient permissions', 403);
		check_ajax_referer('ett_admin');

		$source = sanitize_key(wp_unslash($_POST['source'] ?? 'upload'));

		if ($source === 'path') {
			$zip_path = sanitize_text_field(wp_unslash($_POST['zip_path'] ?? ''));
			if ($zip_path === '' || !file_exists($zip_path) || !is_readable($zip_path)) {
				wp_send_json_error('Server path not found or not readable: ' . esc_html($zip_path), 400);
			}
			$token    = bin2hex(random_bytes(16));
			$filename = basename($zip_path);
			set_transient(
				'ett_sde_tmp_' . get_current_user_id() . '_' . $token,
				['path' => $zip_path, 'is_upload' => false],
				2 * HOUR_IN_SECONDS
			);
			wp_send_json_success(['token' => $token, 'filename' => $filename]);
			return;
		}

		// Upload source.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tmp_name is a server-generated path; security is enforced by is_uploaded_file() inside wp_handle_upload(), not sanitization.
		$upload = $_FILES['sde_zip'] ?? null;
		if (
			empty($upload) ||
			!isset($upload['error'], $upload['tmp_name']) ||
			$upload['error'] !== UPLOAD_ERR_OK ||
			!is_uploaded_file($upload['tmp_name'])
		) {
			$code = (int) ($upload['error'] ?? -1);
			wp_send_json_error('File upload failed (error code ' . $code . '). Check upload_max_filesize and post_max_size in php.ini.', 400);
		}

		// Move to a protected temp dir inside the WP uploads folder using the
		// WP filesystem abstraction. We temporarily redirect the upload dir so
		// wp_handle_upload() places the file in our sde-tmp subdirectory.
		$token    = bin2hex(random_bytes(16));
		$tmp_name = $token . '.zip';

		$uploads = wp_upload_dir();
		$tmp_dir = trailingslashit($uploads['basedir']) . 'ett-price-helper/sde-tmp/';
		if (!file_exists($tmp_dir)) wp_mkdir_p($tmp_dir);

		// Deny web access.
		$ht = $tmp_dir . '.htaccess';
		if (!file_exists($ht)) @file_put_contents($ht, "Deny from all\n");

		// Override upload dir for this call only.
		$set_upload_dir = function () use ($tmp_dir) {
			return [
				'path'   => untrailingslashit($tmp_dir),
				'url'    => '',
				'subdir' => '',
				'basedir'=> untrailingslashit($tmp_dir),
				'baseurl'=> '',
				'error'  => false,
			];
		};
		add_filter('upload_dir', $set_upload_dir);

		// Some server configurations return application/octet-stream or x-zip-compressed
		// for ZIP files, which fails WordPress's server-side MIME verification. Force-allow
		// ZIP regardless of what the server's finfo/mime_content_type reports.
		$allow_zip_type = function ($checked, $file, $filename) {
			if (!$checked['ext']) {
				$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
				if ($ext === 'zip') {
					$checked['ext']  = 'zip';
					$checked['type'] = 'application/zip';
				}
			}
			return $checked;
		};
		add_filter('wp_check_filetype_and_ext', $allow_zip_type, 10, 3);

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$moved = wp_handle_upload($_FILES['sde_zip'], [
			'test_form' => false,
			'mimes'     => ['zip' => 'application/zip|application/octet-stream|application/x-zip|application/x-zip-compressed'],
		]);

		remove_filter('wp_check_filetype_and_ext', $allow_zip_type, 10);
		remove_filter('upload_dir', $set_upload_dir);

		if (!empty($moved['error']) || empty($moved['file'])) {
			wp_send_json_error('Failed to move uploaded file: ' . esc_html($moved['error'] ?? 'unknown error'), 500);
		}

		$tmp_path = $moved['file'];
		$filename = sanitize_file_name($upload['name'] ?? 'sde.zip');
		set_transient(
			'ett_sde_tmp_' . get_current_user_id() . '_' . $token,
			['path' => $tmp_path, 'is_upload' => true],
			2 * HOUR_IN_SECONDS
		);
		wp_send_json_success(['token' => $token, 'filename' => $filename]);
	}

	/**
	 * Steps 1–5 of the chunked import.
	 * Parses one YAML file from the stored ZIP and writes its data to the DB.
	 *
	 * Returns: { label: string, count: int, meta_count?: int, imported_at?: string, details_txt?: string }
	 */
	public static function ajax_sde_import_step(): void {
		if (!current_user_can(self::CAP)) wp_send_json_error('Insufficient permissions', 403);
		check_ajax_referer('ett_admin');

		// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		if (function_exists('set_time_limit')) @set_time_limit(0);
		// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		@ini_set('max_execution_time', '0');
		// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		@ini_set('memory_limit', '1024M');

		$step  = absint(wp_unslash($_POST['step']  ?? 0));
		$token = sanitize_key(wp_unslash($_POST['token'] ?? ''));

		$max_step = count(ETT_SDE::STEPS);
		if ($step < 1 || $step > $max_step || $token === '') {
			wp_send_json_error('Invalid step or token.', 400);
		}

		$transient_key = 'ett_sde_tmp_' . get_current_user_id() . '_' . $token;
		$stored        = get_transient($transient_key);
		if (empty($stored['path'])) {
			wp_send_json_error('Session expired or invalid token. Please restart the import.', 400);
		}

		$zip_path = $stored['path'];
		if (!file_exists($zip_path) || !is_readable($zip_path)) {
			wp_send_json_error('ZIP file is no longer readable. Please restart the import.', 400);
		}

		try {
			ETT_ExternalDB::ensure_schema();
			$pdo = ETT_ExternalDB::pdo();
		} catch (Exception $e) {
			wp_send_json_error('DB error: ' . $e->getMessage(), 500);
		}

		try {
			$result = ETT_SDE::import_step($step, $zip_path, $pdo);
		} catch (Exception $e) {
			wp_send_json_error($e->getMessage(), 500);
		}

		// Accumulate counts in a short-lived meta transient regardless of step.
		$meta_key     = 'ett_sde_meta_' . get_current_user_id() . '_' . $token;
		$running_meta = get_transient($meta_key) ?: [];
		if (empty($result['skipped'])) {
			$running_meta[$result['label']] = $result['count'];
		}
		set_transient($meta_key, $running_meta, 2 * HOUR_IN_SECONDS);

		// On the final step, persist the import meta and return the full summary.
		if ($step === $max_step) {
			$final_meta = [
				'invMarketGroups'          => (int) ($running_meta['invMarketGroups']          ?? 0),
				'invMetaGroups'            => (int) ($running_meta['invMetaGroups']            ?? 0),
				'invTypes'                 => (int) ($running_meta['invTypes + invMetaTypes']  ?? 0),
				'invMetaTypes'             => (int) ($running_meta['invTypes + invMetaTypes']  ?? 0),
				'invTypeMaterials'         => (int) ($running_meta['invTypeMaterials']         ?? 0),
				'industryActivityProducts' => (int) ($running_meta['industryActivityProducts'] ?? 0),
				'imported_at'              => gmdate('Y-m-d H:i:s') . ' UTC',
			];
			// Include mapSolarSystems if it was imported
			if (isset($running_meta['mapSolarSystems'])) {
				$final_meta['mapSolarSystems'] = (int) $running_meta['mapSolarSystems'];
			}
			update_option(self::OPT_LAST_IMPORT, $final_meta, false);
			delete_transient($meta_key);

			$parts = [];
			foreach (['invMarketGroups','invMetaGroups','invTypes','invMetaTypes','invTypeMaterials','industryActivityProducts','mapSolarSystems'] as $k) {
				if (isset($final_meta[$k])) $parts[] = $k . ': ' . number_format((int) $final_meta[$k]);
			}

			$result['imported_at'] = $final_meta['imported_at'];
			$result['details_txt'] = implode(' | ', $parts);
		}

		wp_send_json_success($result);
	}

	/**
	 * Cleanup step: deletes the temp ZIP (upload source only) and transients.
	 */
	public static function ajax_sde_cleanup(): void {
		if (!current_user_can(self::CAP)) wp_send_json_error('Insufficient permissions', 403);
		check_ajax_referer('ett_admin');

		$token = sanitize_key(wp_unslash($_POST['token'] ?? ''));
		if ($token === '') {
			wp_send_json_success(); // nothing to do
			return;
		}

		$uid           = get_current_user_id();
		$transient_key = 'ett_sde_tmp_' . $uid . '_' . $token;
		$meta_key      = 'ett_sde_meta_' . $uid . '_' . $token;

		$stored = get_transient($transient_key);
		if (!empty($stored['path']) && !empty($stored['is_upload']) && file_exists($stored['path'])) {
			wp_delete_file($stored['path']);
		}

		delete_transient($transient_key);
		delete_transient($meta_key);

		wp_send_json_success();
	}

	/**
	 * Returns the rendered market-group tree HTML for the currently selected groups.
	 * Called by JS after a successful SDE import to refresh the card without a page reload.
	 */
	public static function ajax_market_tree(): void {
		if (!current_user_can(self::CAP)) wp_send_json_error('Insufficient permissions', 403);
		check_ajax_referer('ett_admin');

		if (!ETT_ExternalDB::is_configured()) {
			wp_send_json_error('Database not configured.', 400);
		}

		try {
			$pdo  = ETT_ExternalDB::pdo();
			$tree = ETT_Market::get_tree($pdo);
		} catch (Exception $e) {
			wp_send_json_error($e->getMessage(), 500);
		}

		$selected_groups = get_option(self::OPT_SELECTED_GROUPS, []);
		if (!is_array($selected_groups)) $selected_groups = [];

		ob_start();
		self::render_tree($tree, $selected_groups);
		$html = (string) ob_get_clean();

		wp_send_json_success(['html' => $html]);
	}




/**
	 * The single unified EVE SSO callback URL for all ETT plugins.
	 * Register this URL in your EVE developer application.
	 */
	public static function unified_callback_url() : string {
		return admin_url('admin-post.php?action=ett_eve_callback');
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
		// Note: the JWT signature is intentionally not verified here. The token
		// was received directly from EVE SSO over HTTPS in exchange for an auth
		// code we initiated, so it cannot have been tampered with in transit.
		// The claims (character name/ID) are used only for display, not for
		// access control decisions.
		$parts = explode('.', (string)$jwt);
		if (count($parts) < 2) return [];
		$payload = self::b64url_decode($parts[1]);
		$json = json_decode($payload, true);
		return is_array($json) ? $json : [];
	}


	private static function sso_token_request(array $body){
		$client_id = get_option(self::OPT_SSO_CLIENT_ID, '');
		$client_secret = ETT_Crypto::decrypt_triplet(
			(string)get_option(self::OPT_SSO_CLIENT_SECRET, ''),
			(string)get_option(self::OPT_SSO_CLIENT_SECRET . '_iv', ''),
			(string)get_option(self::OPT_SSO_CLIENT_SECRET . '_mac', '')
		);

		if (empty($client_id) || empty($client_secret)) return ['ok' => false, 'err' => 'Missing SSO client id/secret'];

		$auth = base64_encode($client_id . ':' . $client_secret);
       
        // EVE SSO commonly expects redirect_uri on auth-code exchange
        if (($body['grant_type'] ?? '') === 'authorization_code') {
        	$body['redirect_uri'] = self::unified_callback_url();
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
		$access = ETT_Crypto::decrypt_triplet(
			(string)get_option(self::OPT_SSO_ACCESS_TOKEN, ''),
			(string)get_option(self::OPT_SSO_ACCESS_TOKEN . '_iv', ''),
			(string)get_option(self::OPT_SSO_ACCESS_TOKEN . '_mac', '')
		);
		$refresh = ETT_Crypto::decrypt_triplet(
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

		$encA = ETT_Crypto::encrypt_triplet((string)$tok['access_token']);
		update_option(self::OPT_SSO_ACCESS_TOKEN, $encA['ciphertext'], false);
		update_option(self::OPT_SSO_ACCESS_TOKEN . '_iv', $encA['iv'], false);
		update_option(self::OPT_SSO_ACCESS_TOKEN . '_mac', $encA['mac'], false);

		if (!empty($tok['refresh_token'])){
			$encR = ETT_Crypto::encrypt_triplet((string)$tok['refresh_token']);
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
		$is_system_cron = isset($_REQUEST['ett_ph_run']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- cron trigger token verified separately via get_access_token_for_jobs()
		if (!(is_admin() || (defined('DOING_CRON') && DOING_CRON) || (defined('WP_CLI') && WP_CLI) || $is_system_cron)){
			return ['ok' => false, 'error' => 'forbidden_context'];
		}
		return self::ensure_access_token();
	}

    private static function save_sso_from_request(array $src) : void {
        $client_id     = isset($src['ett_sso_client_id']) ? trim((string) wp_unslash($src['ett_sso_client_id'])) : '';
        $client_secret = isset($src['ett_sso_client_secret']) ? trim((string) wp_unslash($src['ett_sso_client_secret'])) : '';

        update_option(self::OPT_SSO_CLIENT_ID, $client_id);

        // Only overwrite the stored secret when a new value is explicitly provided.
        // Leaving the field blank preserves the existing secret — same pattern as
        // the DB password field. The form never pre-fills the decrypted secret, so
        // blank always means 'keep existing'.
        if ($client_secret !== '') {
            $enc = ETT_Crypto::encrypt_triplet($client_secret);
            update_option(self::OPT_SSO_CLIENT_SECRET, $enc['ciphertext'], false);
            update_option(self::OPT_SSO_CLIENT_SECRET . '_iv', $enc['iv'], false);
            update_option(self::OPT_SSO_CLIENT_SECRET . '_mac', $enc['mac'], false);
        }
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
		$client_secret = ETT_Crypto::decrypt_triplet(
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
			'redirect_uri'  => self::unified_callback_url(),
			'client_id'     => $client_id,
			'scope'         => self::sso_scopes(),
			'state'         => $state,
		], 'https://login.eveonline.com/v2/oauth/authorize/');

		wp_safe_redirect($url);
		exit;
	}

/**
	 * Unified EVE SSO callback dispatcher.
	 * Routes to the correct handler based on which plugin initiated the OAuth flow,
	 * identified by the state transient prefix set at auth initiation time.
	 */
	public static function handle_eve_callback() : void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback
		$state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth callback
		$code  = isset($_GET['code'])  ? sanitize_text_field(wp_unslash($_GET['code']))  : '';

		if (empty($state) || empty($code)) {
			wp_die('Invalid EVE SSO callback: missing state or code.');
		}

		// Price Helper admin flow: transient is set by handle_sso_start()
		if (get_transient('ett_sso_state_' . $state)) {
			self::handle_sso_callback();
			return;
		}

		// Reprocess Trading per-user flow: transient is set by ETT_RT_OAuth::connect_button()
		if (get_transient('ett_rt_state_' . $state)) {
			if (!class_exists('ETT_RT_OAuth')) {
				wp_die('ETT Reprocess Trading is not active.');
			}
			ETT_RT_OAuth::handle_callback();
			return;
		}

		// Private hub per-hub flow: transient prefix is ett_priv_state_{idx}_
		// Scan for a matching transient (hub indices are small integers).
		for ($i = 1; $i <= 20; $i++) {
			if (get_transient('ett_priv_state_' . $i . '_' . $state)) {
				self::handle_priv_sso_callback($i);
				return;
			}
		}

		wp_die('Invalid or expired EVE SSO state.');
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
        
        $encA = ETT_Crypto::encrypt_triplet((string)$tok['access_token']);
        update_option(self::OPT_SSO_ACCESS_TOKEN, $encA['ciphertext'], false);
        update_option(self::OPT_SSO_ACCESS_TOKEN . '_iv', $encA['iv'], false);
        update_option(self::OPT_SSO_ACCESS_TOKEN . '_mac', $encA['mac'], false);
        
        $encR = ETT_Crypto::encrypt_triplet((string)$tok['refresh_token']);
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
