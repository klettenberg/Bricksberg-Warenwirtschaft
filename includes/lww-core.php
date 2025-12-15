<?php
/**
 * Core Module
 *
 * Loads all plugin modules in the correct dependency order.
 * Follows WordPress plugin development best practices.
 *
 * @since 6.50.0
 * @package BricksbergWarenwirtschaft
 */
if (!defined('ABSPATH')) exit;

final class LWW_Core {
    private static $_instance = null;

    public static function instance() {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    private function __construct() {
        add_action('init', [$this, 'load_textdomain']);
        add_action('plugins_loaded', [$this, 'init']);
        add_action('admin_notices', [$this, 'check_dependencies']);
    }

    public function load_textdomain() {
        load_plugin_textdomain('bricksberg-wawi', false, dirname(plugin_basename(LWW_PLUGIN_FILE)) . '/languages/');
    }

    public function init() {
        $this->includes();
        
        if (is_admin() && !wp_next_scheduled('lww_main_batch_hook')) {
            lww_start_cron_job();
        }
    }

    /**
     * Prüft zur Laufzeit, ob die zwingend erforderlichen Plugins aktiv sind.
     */
    public function check_dependencies() {
        if (!class_exists('WooCommerce')) {
            echo '<div class="notice notice-error"><p>' . __('Bricksberg WaWi Error: <strong>WooCommerce</strong> is required but not active. Please install and activate WooCommerce.', 'bricksberg-wawi') . '</p></div>';
        }
    }

    private function includes() {
        // 1. Basis
        require_once LWW_PLUGIN_PATH . 'includes/lww-functions.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-cpts.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-taxonomies.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-job-statuses.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-order-statuses.php';
        require_once LWW_PLUGIN_PATH . 'includes/settings.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-config-export-import.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-translation-manager.php';
        require_once LWW_PLUGIN_PATH . 'includes/pricing-engine.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-news-aggregator.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-multitenancy.php';

        // 2. APIs
        require_once LWW_PLUGIN_PATH . 'includes/api/class-lww-rebrickable-api.php';
        require_once LWW_PLUGIN_PATH . 'includes/api/class-lww-bricklink-api.php';
        require_once LWW_PLUGIN_PATH . 'includes/api/class-lww-brickowl-api.php';
        require_once LWW_PLUGIN_PATH . 'includes/api/class-lww-ebay-api.php';
        require_once LWW_PLUGIN_PATH . 'includes/api/class-lww-brickset-api.php';

        // 3. Import Handler
        require_once LWW_PLUGIN_PATH . 'includes/import-handlers/interface-lww-import-handler.php';
        require_once LWW_PLUGIN_PATH . 'includes/import-handlers/class-lww-import-handler-base.php';
        require_once LWW_PLUGIN_PATH . 'includes/import-handlers/class-lww-import-colors-handler.php';
        require_once LWW_PLUGIN_PATH . 'includes/import-handlers/class-lww-import-themes-handler.php';
        require_once LWW_PLUGIN_PATH . 'includes/import-handlers/class-lww-import-part-categories-handler.php';
        require_once LWW_PLUGIN_PATH . 'includes/import-handlers/class-lww-import-parts-handler.php';
        require_once LWW_PLUGIN_PATH . 'includes/import-handlers/class-lww-import-sets-handler.php';
        require_once LWW_PLUGIN_PATH . 'includes/import-handlers/class-lww-import-minifigs-handler.php';
        require_once LWW_PLUGIN_PATH . 'includes/import-handlers/class-lww-import-part-relationships-handler.php';
        require_once LWW_PLUGIN_PATH . 'includes/import-handlers/class-lww-import-elements-handler.php';
        require_once LWW_PLUGIN_PATH . 'includes/import-handlers/class-lww-import-inventories-handler.php';
        require_once LWW_PLUGIN_PATH . 'includes/import-handlers/class-lww-import-inventory-parts-handler.php';
        require_once LWW_PLUGIN_PATH . 'includes/import-handlers/class-lww-import-inventory-sets-handler.php';
        require_once LWW_PLUGIN_PATH . 'includes/import-handlers/class-lww-import-inventory-minifigs-handler.php';
        require_once LWW_PLUGIN_PATH . 'includes/import-handlers/class-lww-import-bricklink-inventory-handler.php';
        require_once LWW_PLUGIN_PATH . 'includes/import-handlers/class-lww-import-inventory-handler.php';

        require_once LWW_PLUGIN_PATH . 'includes/lww-batch-processor.php';

        // 4. KI & Analyse
        require_once LWW_PLUGIN_PATH . 'includes/lww-demand-analyzer.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-pricing-ai.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-description-generator.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-analysis.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-test-suite.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-image-ajax.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-image-generator.php'; 

        // 5. Integrationen
        require_once LWW_PLUGIN_PATH . 'includes/lww-woocommerce-integration.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-ebay.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-ebay-integration.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-marketplace-sync.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-elementor-integration.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-shortcodes.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-setup-wizard.php';

        // 6. Admin UI
        require_once LWW_PLUGIN_PATH . 'includes/lww-admin-assets.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-admin-columns.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-admin-meta-boxes.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-dashboard.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-jobs.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-inventory-ui.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-import-ui.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-sync.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-parts-ui.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-minifigs-ui.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-orders-ui.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-order-analysis.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-bundles-ui.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-bundle-functions.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-storage-management.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-warehouse-map.php'; 
        require_once LWW_PLUGIN_PATH . 'includes/lww-stock-take-ui.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-pick-list-ui.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-market-analysis-ui.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-data-correction.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-duplicates.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-tools.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-api-log-ui.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-manual.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-marketing-ui.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-reporting-ui.php'; // NEU
        
        // 7. Menu
        require_once LWW_PLUGIN_PATH . 'includes/lww-admin-page.php';
    }

    public static function activate() {
        require_once LWW_PLUGIN_PATH . 'includes/lww-functions.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-cpts.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-taxonomies.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-job-statuses.php';
        require_once LWW_PLUGIN_PATH . 'includes/lww-order-statuses.php';

        lww_register_cpts();
        lww_register_taxonomies();
        lww_register_job_post_statuses();
        lww_register_order_post_statuses();

        lww_start_cron_job();
        flush_rewrite_rules();
    }

    public static function deactivate() {
        wp_clear_scheduled_hook('lww_main_batch_hook');
        flush_rewrite_rules();
    }
}
