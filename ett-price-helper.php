<?php
/**
 * Plugin Name: EVE Trade Tools Price Helper
 * Description: Admin-only tool to import Fuzzwork market groups/types and pull hub prices from ESI into an external database.
 * Version: 1.0.1
 * Author: C4813
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ett-price-helper
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

define('ETT_PH_PATH', plugin_dir_path(__FILE__));
define('ETT_PH_URL', plugin_dir_url(__FILE__));

require_once ETT_PH_PATH . 'includes/class-ett-crypto.php';
require_once ETT_PH_PATH . 'includes/class-ett-extdb.php';
require_once ETT_PH_PATH . 'includes/class-ett-fuzzwork.php';
require_once ETT_PH_PATH . 'includes/class-ett-market.php';
require_once ETT_PH_PATH . 'includes/class-ett-typeids.php';
require_once ETT_PH_PATH . 'includes/class-ett-esi.php';
require_once ETT_PH_PATH . 'includes/class-ett-jobs.php';
require_once ETT_PH_PATH . 'includes/class-ett-admin.php';

add_action('plugins_loaded', function () {

    ETT_Admin::init();
    ETT_Jobs::init_ajax();
    ETT_Jobs::init_cron();
});

register_activation_hook(__FILE__, function () {
	ETT_Jobs::activate_cron();
});

register_deactivation_hook(__FILE__, function () {
	ETT_Jobs::deactivate_cron();
});
