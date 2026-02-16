<?php
/**
 * Uninstall cleanup for EVE Trade Tools Price Helper.
 *
 * IMPORTANT: This file intentionally does NOT touch the external database.
 * It only removes WordPress-side options/transients and scheduled cron events.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Unschedule all cron events for a hook, regardless of arguments.
 */
$ett_ph_unschedule_all = function (string $hook) {
    $cron = _get_cron_array();
    if (!is_array($cron)) return;

    foreach ($cron as $ts => $hooks) {
        if (empty($hooks[$hook]) || !is_array($hooks[$hook])) continue;

        foreach ($hooks[$hook] as $event) {
            $args = (isset($event['args']) && is_array($event['args'])) ? $event['args'] : [];
            wp_unschedule_event((int)$ts, $hook, $args);
        }
    }
};

$ett_ph_unschedule_all('ett_ph_prices_scheduled_start');
$ett_ph_unschedule_all('ett_ph_prices_tick');

/**
 * Delete plugin options.
 */
$options = [
    // External DB connection settings (stored in WP options; uninstall should remove them)
    'ett_extdb_settings',

    // External DB password triplet (if stored as separate options in this or older versions)
    'ett_extdb_pass_enc',
    'ett_extdb_pass_iv',
    'ett_extdb_pass_mac',

    // Admin selections / config
    'ett_selected_market_groups',
    'ett_selected_hubs',
    'ett_secondary_structures',
    'ett_tertiary_structures',

    // SSO config + tokens + caches
    'ett_sso_client_id',
    'ett_sso_client_secret',
    'ett_sso_access_token',
    'ett_sso_refresh_token',
    'ett_sso_expires_at',
    'ett_sso_character_id',
    'ett_sso_character_name',
    'ett_sso_structures_cache',
    'ett_sso_structures_cache_at',
    'ett_sso_corp_cache',

    // SSO secret + tokens (iv/mac stored separately)
    'ett_sso_client_secret_iv',
    'ett_sso_client_secret_mac',

    'ett_sso_access_token_iv',
    'ett_sso_access_token_mac',

    'ett_sso_refresh_token_iv',
    'ett_sso_refresh_token_mac',

    // Performance settings
    'ett_batch_max_pages',
    'ett_batch_max_seconds',

    // Fuzzwork import meta
    'ett_fuzzwork_last_import_meta',

    // Scheduler + last run meta
    'ett_last_price_run_completed_at',
    'ett_sched_start_time',
    'ett_sched_freq_hours',

    // Cron state
    'ett_ph_cron_prices_job_id',
];

foreach ($options as $opt) {
    delete_option($opt);
}