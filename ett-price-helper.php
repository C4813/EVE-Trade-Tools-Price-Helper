<?php
/**
 * Plugin Name: EVE Trade Tools Price Helper
 * Description: Admin-only tool to import the EVE Static Data Export (SDE) and pull hub prices from ESI into an external database.
 * Version: 1.7.0.1
 * Author: C4813
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ett-price-helper
 */

if (!defined('ABSPATH')) exit;

define('ETT_PH_PATH', plugin_dir_path(__FILE__));
define('ETT_PH_URL', plugin_dir_url(__FILE__));

require_once ETT_PH_PATH . 'includes/class-ett-crypto.php';
require_once ETT_PH_PATH . 'includes/class-ett-extdb.php';
require_once ETT_PH_PATH . 'includes/class-ett-sde.php';
require_once ETT_PH_PATH . 'includes/class-ett-market.php';
require_once ETT_PH_PATH . 'includes/class-ett-typeids.php';
require_once ETT_PH_PATH . 'includes/class-ett-esi.php';
require_once ETT_PH_PATH . 'includes/class-ett-jobs.php';
require_once ETT_PH_PATH . 'includes/class-ett-runner.php';
require_once ETT_PH_PATH . 'includes/class-ett-admin.php';

// WP-CLI commands (only loaded in CLI context)
if (defined('WP_CLI') && WP_CLI) {
    require_once ETT_PH_PATH . 'includes/class-ett-cli.php';
}

add_action('plugins_loaded', function () {
    ETT_Admin::init();
    ETT_Jobs::init_ajax();
    ETT_Runner::init_ajax();
});

// Handle direct HTTP runner requests as early as possible
add_action('init', [ETT_Runner::class, 'maybe_handle_request'], 1);
