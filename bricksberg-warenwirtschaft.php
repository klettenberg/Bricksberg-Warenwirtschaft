<?php
/**
 * Plugin Name:       Bricksberg Warenwirtschaft (WaWi)
 * Plugin URI:        https://bricksberg.eu/
 * Description:       Verwaltet LEGO Katalogdaten (Rebrickable CSV) & Inventar (BrickOwl CSV) mit OO-Handlern.
 * Version:           1.0.0
 * Author:            Olaf Ziörjen
 * Author URI:        https://bricksberg.eu/
 * License:           GPL v2 or later
 * Text Domain:       lego-wawi
 * Domain Path:       /languages
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Plugin-Konstanten
define('LWW_PLUGIN_VERSION', '1.0.0');
define('LWW_PLUGIN_SLUG', 'lego_wawi_admin_page');
define('LWW_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('LWW_PLUGIN_URL', plugin_dir_url(__FILE__));

// --- Laden der Module ---

// 1. Grundstruktur & Datenhaltung
require_once LWW_PLUGIN_PATH . 'includes/lww-cpts.php';
require_once LWW_PLUGIN_PATH . 'includes/lww-taxonomies.php';
require_once LWW_PLUGIN_PATH . 'includes/lww-job-statuses.php';

// 2. Admin UI & Einstellungsseiten
require_once LWW_PLUGIN_PATH . 'includes/lww-settings.php';
require_once LWW_PLUGIN_PATH . 'includes/lww-admin-page.php';
require_once LWW_PLUGIN_PATH . 'includes/lww-dashboard.php';
require_once LWW_PLUGIN_PATH . 'includes/lww-jobs.php';
require_once LWW_PLUGIN_PATH . 'includes/lww-inventory-ui.php';
require_once LWW_PLUGIN_PATH . 'includes/lww-admin-columns.php';

// 3. Import-Logik (Klassen) - v10.0
// Lade alle Klassendateien (Interface, Base, Handlers)
// Stellt sicher, dass alle .php-Dateien, die mit "class-" oder "interface-" beginnen, geladen werden.
$lww_class_files = glob(LWW_PLUGIN_PATH . 'includes/{interface-*,class-*}*.php', GLOB_BRACE);
if ($lww_class_files !== false) {
    foreach ($lww_class_files as $file) {
        require_once $file;
    }
}

// 4. Prozess-Steuerung
require_once LWW_PLUGIN_PATH . 'includes/lww-import-handlers.php';  // Upload-Verarbeitung
require_once LWW_PLUGIN_PATH . 'includes/lww-batch-processor.php';  // Cron-Jobs (v10.0)

// 5. WooCommerce-Integration (NEU)
require_once LWW_PLUGIN_PATH . 'includes/lww-woocommerce-integration.php';


/**
 * Lädt Admin-Stylesheets und Javascripts.
 */
function lww_enqueue_admin_assets($hook) {
    // CSS laden
    $is_lww_page = (strpos($hook, LWW_PLUGIN_SLUG) !== false || strpos($hook, 'lww_inventory_ui') !== false);
    $screen = get_current_screen();
    $is_lww_list_table = $screen && (
        (isset($screen->post_type) && in_array($screen->post_type, ['lww_part', 'lww_set', 'lww_minifig', 'lww_color', 'lww_inventory_item', 'lww_job'])) ||
        (isset($screen->taxonomy) && in_array($screen->taxonomy, ['lww_theme', 'lww_part_category']))
    );

    if ($is_lww_page || $is_lww_list_table) {
        $css_file_url = LWW_PLUGIN_URL . 'assets/css/lww-admin-styles.css';
        if (file_exists(LWW_PLUGIN_PATH . 'assets/css/lww-admin-styles.css')) {
             wp_enqueue_style('lww-admin-styles', $css_file_url, [], LWW_PLUGIN_VERSION);
        }
    }
    
    $current_page = $_GET['page'] ?? '';
    $current_tab = $_GET['tab'] ?? '';

    // JavaScript laden (nur für Job-Seite)
    if ($current_page === LWW_PLUGIN_SLUG && $current_tab === 'tab_jobs') {
        $js_file_url = LWW_PLUGIN_URL . 'assets/js/lww-admin-jobs.js';
        if (file_exists(LWW_PLUGIN_PATH . 'assets/js/lww-admin-jobs.js')) {
            wp_enqueue_script('lww-admin-jobs', $js_file_url, ['jquery'], LWW_PLUGIN_VERSION, true);
            wp_localize_script('lww-admin-jobs', 'lww_jobs_data', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('lww_job_list_nonce')
            ]);
        }
    }
    
    // NEU: JavaScript für Inventar UI-Seite
    if ($current_page === 'lww_inventory_ui') {
        $js_file_url = LWW_PLUGIN_URL . 'assets/js/lww-admin-inventory.js';
        if (file_exists(LWW_PLUGIN_PATH . 'assets/js/lww-admin-inventory.js')) {
            wp_enqueue_script('lww-admin-inventory', $js_file_url, ['jquery'], LWW_PLUGIN_VERSION, true);
            // Übergib AJAX-URL und Nonce an das Skript
            wp_localize_script('lww-admin-inventory', 'lww_inventory_data', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('lww_inventory_ajax_nonce')
            ]);
        }
    }
}
add_action('admin_enqueue_scripts', 'lww_enqueue_admin_assets');

/**
 * Aktionen bei Plugin-Aktivierung.
 */
function lww_activate_plugin() {
    if(function_exists('lww_register_cpts')) lww_register_cpts();
    if(function_exists('lww_register_taxonomies')) lww_register_taxonomies();
    if(function_exists('lww_register_job_post_statuses')) lww_register_job_post_statuses();
    flush_rewrite_rules();
    if(function_exists('lww_start_cron_job')) {
        lww_start_cron_job();
        if(function_exists('lww_log_system_event')) { lww_log_system_event('Plugin aktiviert - Cron Job Start versucht.'); }
    }
}
register_activation_hook(__FILE__, 'lww_activate_plugin');

/**
 * Aktionen bei Plugin-Deaktivierung.
 */
function lww_deactivate_plugin() {
    if(function_exists('lww_stop_cron_job')) {
        lww_stop_cron_job();
        if(function_exists('lww_log_system_event')) { lww_log_system_event('Plugin deaktiviert - Cron Job gestoppt.'); }
    }
    delete_option('lww_current_running_job_id');
    if(function_exists('lww_log_system_event')) { lww_log_system_event('Plugin deaktiviert - Job-Sperre aufgehoben.'); }
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'lww_deactivate_plugin');
?>
