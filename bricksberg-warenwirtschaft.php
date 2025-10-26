<?php
/**
 * Plugin Name:       Bricksberg Warenwirtschaft (WaWi)
 * Plugin URI:        https://bricksberg.eu/
 * Description:       Synchronisiert WooCommerce mit BrickOwl (Master), BrickLink und Rebrickable über CSV-Import.
 * Version:           0.9.9
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
define('LWW_PLUGIN_VERSION', '0.9.9'); // Version erhöht
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
// require_once LWW_PLUGIN_PATH . 'includes/lww-import-functions.php'; // VERALTET
require_once LWW_PLUGIN_PATH . 'includes/lww-import-handlers.php';  // Upload-Verarbeitung
require_once LWW_PLUGIN_PATH . 'includes/lww-batch-processor.php'; // Cron-Jobs / Hintergrund-Verarbeitung

// --- NEU: Lade alle Import-Handler-Klassen ---
require_once LWW_PLUGIN_PATH . 'includes/import-handlers/interface-lww-import-handler.php';
require_once LWW_PLUGIN_PATH . 'includes/import-handlers/class-lww-import-handler-base.php';

// Lade automatisch alle 'class-lww-import-*.php' Dateien aus dem Verzeichnis
$handler_files = glob(LWW_PLUGIN_PATH . 'includes/import-handlers/class-lww-import-*.php');
if ($handler_files) {
    foreach ($handler_files as $handler_file) {
        require_once $handler_file;
    }
}
// --- Ende Lade-Handler ---


// Lade jeden einzelnen Handler (oder verwende einen Autoloader)
$handler_files = glob(LWW_PLUGIN_PATH . 'includes/import-handlers/class-lww-import-*-handler.php');
foreach ($handler_files as $handler_file) {
    require_once $handler_file;
}
// --- Ende Lade-Handler ---

/**
 * Lädt die Admin-Stylesheets UND Scripte.
 */
function lww_enqueue_admin_assets($hook) {
    $screen = get_current_screen();
    if (!$screen) return;

    // 1. STYLES (Laden auf allen LWW-Seiten)
    $is_lww_page_or_list = (
        strpos($hook, LWW_PLUGIN_SLUG) !== false || 
        strpos($hook, 'lww_inventory_ui') !== false ||
        (isset($screen->post_type) && strpos($screen->post_type, 'lww_') === 0) ||
        (isset($screen->taxonomy) && strpos($screen->taxonomy, 'lww_') === 0)
    );

    if ($is_lww_page_or_list) {
        $css_file_path = LWW_PLUGIN_PATH . 'assets/css/lww-admin-styles.css';
        $css_file_url = LWW_PLUGIN_URL . 'assets/css/lww-admin-styles.css';
        if (file_exists($css_file_path)) {
             wp_enqueue_style('lww-admin-styles', $css_file_url, [], LWW_PLUGIN_VERSION);
        }
    }
    
    // 2. SCRIPTE (Nur auf der Job-Seite laden)
    // Finde die korrekte "hook_suffix" (ID) der Haupt-Adminseite
    // Sie ist meist 'toplevel_page_PLUGIN_SLUG'
    $main_page_hook = 'toplevel_page_' . LWW_PLUGIN_SLUG;
    
    // Prüfe, ob wir auf der Hauptseite sind UND der 'tab_jobs' aktiv ist
    if ($hook === $main_page_hook && isset($_GET['tab']) && $_GET['tab'] === 'tab_jobs') {
        
        $js_file_path = LWW_PLUGIN_PATH . 'assets/js/lww-admin-jobs.js';
        $js_file_url = LWW_PLUGIN_URL . 'assets/js/lww-admin-jobs.js';
        
        if (file_exists($js_file_path)) {
            // Lade das Script
             wp_enqueue_script(
                'lww-admin-jobs',
                $js_file_url,
                ['jquery'], // Abhängigkeit von jQuery
                LWW_PLUGIN_VERSION,
                true // Lade im Footer
             );
             
             // Übergebe Daten an das Script (AJAX-URL und Sicherheits-Nonce)
             wp_localize_script(
                'lww-admin-jobs',
                'lww_jobs_data', // Dieses Objekt ist im JS verfügbar
                [
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce'    => wp_create_nonce('lww_job_list_nonce') // Eindeutige Nonce für Sicherheit
                ]
             );
        }
    }
}
// Wichtig: Den Action-Hook auf den neuen Funktionsnamen aktualisieren
add_action('admin_enqueue_scripts', 'lww_enqueue_admin_assets');

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