<?php
/**
 * Modul: Core Plugin Klasse (v14.0)
 * 
 * Diese Klasse ist der zentrale Einstiegspunkt des Plugins. Sie ist als Singleton implementiert,
 * um sicherzustellen, dass sie nur einmal geladen wird. Sie definiert Konstanten, lädt alle
 * benötigten Module und registriert die primären Action-Hooks.
 */
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

final class LWW_Core {
    
    /**
     * Plugin-Version.
     * @var string
     */
    public $version = '0.45.1';

    /**
     * Die einzige Instanz der Klasse.
     * @var LWW_Core|null
     */
    private static $_instance = null;

    /**
     * Stellt sicher, dass nur eine Instanz der Klasse geladen wird (Singleton-Muster).
     * @return LWW_Core
     */
    public static function instance() {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Privater Konstruktor, um direkte Instanziierung zu verhindern.
     */
    private function __construct() {
        $this->define_constants();
        $this->includes();
        $this->init_hooks();
    }

    /**
     * Definiert die globalen Konstanten des Plugins.
     */
    private function define_constants() {
        define('LWW_PLUGIN_VERSION', $this->version);
        define('LWW_PLUGIN_SLUG', 'bricksberg_wawi_dashboard'); // Geändert für die neue Hauptseite
        define('LWW_PLUGIN_PATH', plugin_dir_path(dirname(__FILE__)));
        define('LWW_PLUGIN_URL', plugin_dir_url(dirname(__FILE__)));
    }

    /**
     * Lädt alle notwendigen PHP-Module des Plugins.
     */
    private function includes() {
        // API-Klassen
        require_once LWW_PLUGIN_PATH . 'includes/api/class-lww-brickowl-api.php';
        require_once LWW_PLUGIN_PATH . 'includes/api/class-lww-ebay-api.php';

        // Lade die Handler-Schnittstelle und die Basisklasse zuerst
        require_once LWW_PLUGIN_PATH . 'includes/import-handlers/interface-lww-import-handler.php';
        require_once LWW_PLUGIN_PATH . 'includes/import-handlers/class-lww-import-handler-base.php';
        
        // Lade alle spezifischen Import-Handler
        $handler_files = glob(LWW_PLUGIN_PATH . 'includes/import-handlers/class-lww-import-*-handler.php');
        foreach ($handler_files as $handler_file) {
            require_once $handler_file;
        }

        // Lade die restlichen Module
        require_once LWW_PLUGIN_PATH . 'includes/lww-functions.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-cpts.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-taxonomies.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-job-statuses.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-settings.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-admin-page.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-dashboard.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-jobs.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-inventory-ui.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-api-log-ui.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-woocommerce-integration.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-admin-columns.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-admin-meta-boxes.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-import-handlers.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-batch-processor.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-tools.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-demand-analyzer.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-description-generator.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-analysis.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-storage-management.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-data-correction.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-ebay.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-sync.php';
    }

    /**
     * Registriert die primären Action- und Filter-Hooks.
     */
    private function init_hooks() {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_filter('admin_footer_text', [$this, 'admin_footer_branding']);
    }

    /**
     * Lädt Admin-Stylesheets und JS.
     * @param string $hook Die aktuelle Admin-Seite.
     */
    public function enqueue_admin_assets($hook) {
        $screen = get_current_screen();
        if (!$screen) return;

        // Prüfen, ob wir auf einer LWW-Seite oder CPT-Liste sind
        $is_lww_page = (strpos($screen->id, 'lww_') !== false || strpos($screen->id, 'bricksberg_wawi') !== false);
        $is_lww_cpt_list = in_array($screen->post_type, ['lww_part', 'lww_set', 'lww_minifig', 'lww_color', 'lww_inventory_item', 'lww_job', 'lww_api_log']);
        $is_lww_tax_list = in_array($screen->taxonomy, ['lww_theme', 'lww_part_category', 'lww_inventory_location']);

        // 1. CSS laden, wenn es eine LWW-Seite ist
        if ($is_lww_page || $is_lww_cpt_list || $is_lww_tax_list) {
            $css_file_path = LWW_PLUGIN_PATH . 'assets/css/lww-admin-styles.css';
            $css_file_url = LWW_PLUGIN_URL . 'assets/css/lww-admin-styles.css';
            if (file_exists($css_file_path)) {
                 wp_enqueue_style('lww-admin-styles', $css_file_url, [], LWW_PLUGIN_VERSION);
            }
        }

        // 2. JS für die Job-Seite laden
        if ($screen->id === 'bricksberg-wawi_page_lww_jobs_ui') {
            $js_file_path = LWW_PLUGIN_PATH . 'assets/js/lww-admin-jobs.js';
            $js_file_url = LWW_PLUGIN_URL . 'assets/js/lww-admin-jobs.js';
            if (file_exists($js_file_path)) {
                wp_enqueue_script('lww-admin-jobs', $js_file_url, ['jquery'], LWW_PLUGIN_VERSION, true);
                wp_localize_script('lww-admin-jobs', 'lww_jobs_data', [
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce'    => wp_create_nonce('lww_job_list_nonce')
                ]);
            }
        }

        // 3. JS für die Inventar-UI-Seite laden
        if ($screen->id === 'bricksberg-wawi_page_lww_inventory_ui') {
            $js_file_path = LWW_PLUGIN_PATH . 'assets/js/lww-admin-inventory.js';
            $js_file_url = LWW_PLUGIN_URL . 'assets/js/lww-admin-inventory.js';
            if (file_exists($js_file_path)) {
                wp_enqueue_script('lww-admin-inventory', $js_file_url, ['jquery'], LWW_PLUGIN_VERSION, true);
                wp_localize_script('lww-admin-inventory', 'lww_inventory_data', [
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce'    => wp_create_nonce('lww_inventory_ajax_nonce'),
                    'reset_nonce' => wp_create_nonce('lww_inventory_reset_filters_nonce')
                ]);
            }
        }

        // 4. JS für die Einstellungs-Seite laden
        if ($screen->id === 'bricksberg-wawi_page_lww_settings_ui') {
            $js_file_path = LWW_PLUGIN_PATH . 'assets/js/lww-admin-settings.js';
            $js_file_url = LWW_PLUGIN_URL . 'assets/js/lww-admin-settings.js';
            if (file_exists($js_file_path)) {
                wp_enqueue_script('lww-admin-settings', $js_file_url, ['jquery'], LWW_PLUGIN_VERSION, true);
                wp_localize_script('lww-admin-settings', 'lww_settings_data', [
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce'    => wp_create_nonce('lww_settings_ajax_nonce')
                ]);
            }
        }
    }

    /**
     * Fügt ein Branding zum Footer auf Plugin-Seiten hinzu.
     * @param string $footer_text Der ursprüngliche Footer-Text.
     * @return string Der modifizierte Footer-Text.
     */
    public function admin_footer_branding($footer_text) {
        $screen = get_current_screen();
        if ($screen && (strpos($screen->id, 'lww_') !== false || strpos($screen->id, 'bricksberg_wawi') !== false)) {
            return sprintf(
                __('Vielen Dank für den Einsatz der %1$sBricksberg Warenwirtschaft%2$s. Version %3$s.', 'lego-wawi'),
                '<strong>',
                '</strong>',
                LWW_PLUGIN_VERSION
            );
        }
        return $footer_text;
    }

    /**
     * Aktionen bei Plugin-Aktivierung.
     */
    public static function activate() {
        // Die inkludierten Dateien sind an dieser Stelle bereits geladen, wenn das Plugin läuft.
        // Wir müssen sicherstellen, dass die Funktionen verfügbar sind.
        if(function_exists('lww_register_cpts')) lww_register_cpts();
        if(function_exists('lww_register_taxonomies')) lww_register_taxonomies();
        if(function_exists('lww_register_job_post_statuses')) lww_register_job_post_statuses();
        if(function_exists('lww_start_cron_job')) {
            lww_start_cron_job();
            if(function_exists('lww_log_system_event')) { lww_log_system_event('Plugin aktiviert - Cron Job Start versucht.'); }
        } else {
            // Dies ist ein Fallback-Log, falls lww-functions.php nicht geladen wäre
            error_log('FEHLER bei LWW Aktivierung: lww_start_cron_job() nicht gefunden!');
        }
    }

    /**
     * Aktionen bei Plugin-Deaktivierung.
     */
    public static function deactivate() {
        if(function_exists('lww_stop_cron_job')) {
            lww_stop_cron_job();
            if(function_exists('lww_log_system_event')) { lww_log_system_event('Plugin deaktiviert - Cron Job gestoppt.'); }
        } else {
             error_log('FEHLER bei LWW Deaktivierung: lww_stop_cron_job() nicht gefunden!');
        }
        delete_option('lww_current_running_job_id');
        if(function_exists('lww_log_system_event')) { lww_log_system_event('Plugin deaktiviert - Job-Sperre aufgehoben.'); }
    }
}
?>