<?php
/**
 * Plugin Name:       Bricksberg Warenwirtschaft (WaWi)
 * Plugin URI:        https://bricksberg.eu/
 * Description:       Synchronisiert WooCommerce mit BrickOwl (Master), BrickLink und Rebrickable über CSV-Import.
 * Version:           0.9.6
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
define('LWW_PLUGIN_VERSION', '0.9.6'); // Version erhöht
define('LWW_PLUGIN_SLUG', 'lego_wawi_admin_page');
define('LWW_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('LWW_PLUGIN_URL', plugin_dir_url(__FILE__));

// Laden der Module (Reihenfolge wichtig)
require_once LWW_PLUGIN_PATH . 'includes/lww-cpts.php';             // CPTs
require_once LWW_PLUGIN_PATH . 'includes/lww-taxonomies.php';       // Taxonomien
require_once LWW_PLUGIN_PATH . 'includes/lww-job-statuses.php';     // Job-Status
require_once LWW_PLUGIN_PATH . 'includes/lww-settings.php';         // Einstellungen
require_once LWW_PLUGIN_PATH . 'includes/lww-admin-page.php';       // Admin-Seite Struktur
require_once LWW_PLUGIN_PATH . 'includes/lww-dashboard.php';        // Dashboard-Tab
require_once LWW_PLUGIN_PATH . 'includes/lww-jobs.php';             // Job-Queue-Tab
require_once LWW_PLUGIN_PATH . 'includes/lww-inventory-ui.php';     // Inventar UI Seite
require_once LWW_PLUGIN_PATH . 'includes/lww-admin-columns.php';    // Admin Spalten (Farbvorschau etc.)
require_once LWW_PLUGIN_PATH . 'includes/lww-import-functions.php'; // NEU: Import-Logik ausgelagert
require_once LWW_PLUGIN_PATH . 'includes/lww-import-handlers.php';  // Upload-Verarbeitung
require_once LWW_PLUGIN_PATH . 'includes/lww-batch-processor.php'; // Cron-Jobs / Hintergrund-Verarbeitung

/**
 * Lädt die Admin-Stylesheets.
 */
function lww_enqueue_admin_styles($hook) {
    // Optimierte Prüfung für LWW Seiten und Listenansichten
    $is_lww_page = (strpos($hook, LWW_PLUGIN_SLUG) !== false || strpos($hook, 'lww_inventory_ui') !== false);
    $screen = get_current_screen();
    $is_lww_list_table = $screen && (
        (isset($screen->post_type) && in_array($screen->post_type, ['lww_part', 'lww_set', 'lww_minifig', 'lww_color', 'lww_inventory_item', 'lww_job'])) || // Job hinzugefügt
        (isset($screen->taxonomy) && in_array($screen->taxonomy, ['lww_theme', 'lww_part_category']))
    );

    if ($is_lww_page || $is_lww_list_table) {
        $css_file_path = LWW_PLUGIN_PATH . 'assets/css/lww-admin-styles.css';
        $css_file_url = LWW_PLUGIN_URL . 'assets/css/lww-admin-styles.css';
        if (file_exists($css_file_path)) {
             wp_enqueue_style('lww-admin-styles', $css_file_url, [], LWW_PLUGIN_VERSION);
        }
    }
}
add_action('admin_enqueue_scripts', 'lww_enqueue_admin_styles');

/**
 * Aktionen bei Plugin-Aktivierung.
 */
function lww_activate_plugin() {
    // Sicherstellen, dass alles registriert ist
    if(function_exists('lww_register_cpts')) lww_register_cpts();
    if(function_exists('lww_register_taxonomies')) lww_register_taxonomies();
    if(function_exists('lww_register_job_post_statuses')) lww_register_job_post_statuses();
    flush_rewrite_rules(); // Wichtig nach CPT/Taxonomie Änderungen

    // Cron Job starten
    if(function_exists('lww_start_cron_job')) {
        lww_start_cron_job();
        if(function_exists('lww_log_system_event')) lww_log_system_event('Plugin aktiviert - Cron Job Start versucht.');
    } elseif(function_exists('lww_log_system_event')) {
         lww_log_system_event('FEHLER bei Aktivierung: lww_start_cron_job() nicht gefunden!');
    } else { error_log('FEHLER bei LWW Aktivierung: lww_start_cron_job() nicht gefunden!'); }
}
register_activation_hook(__FILE__, 'lww_activate_plugin');

/**
 * Aktionen bei Plugin-Deaktivierung.
 */
function lww_deactivate_plugin() {
    // Cron Job stoppen
    if(function_exists('lww_stop_cron_job')) {
        lww_stop_cron_job();
        if(function_exists('lww_log_system_event')) lww_log_system_event('Plugin deaktiviert - Cron Job gestoppt.');
    } elseif(function_exists('lww_log_system_event')) {
         lww_log_system_event('FEHLER bei Deaktivierung: lww_stop_cron_job() nicht gefunden!');
    } else { error_log('FEHLER bei LWW Deaktivierung: lww_stop_cron_job() nicht gefunden!'); }

    // Aktive Job-Sperre aufheben
    delete_option('lww_current_running_job_id');
    if(function_exists('lww_log_system_event')) lww_log_system_event('Plugin deaktiviert - Job-Sperre aufgehoben.');

    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'lww_deactivate_plugin');

?>