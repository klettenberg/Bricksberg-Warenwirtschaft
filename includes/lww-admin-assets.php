<?php
/**
 * Modul: Admin Assets Management (v30.0)
 * 
 * Update: Laden der Mobile-App und Reporting Assets.
 */
if (!defined('ABSPATH')) exit;

function lww_enqueue_admin_assets($hook) {
    $screen = get_current_screen();
    $is_lww_page = (strpos($hook, 'bricksberg_wawi') !== false) || (strpos($hook, 'lww_') !== false);
    $is_lww_cpt = (isset($screen->post_type) && strpos($screen->post_type, 'lww_') === 0);
    
    if (!$is_lww_page && !$is_lww_cpt) return;

    // --- Globales Admin CSS --- 
    wp_enqueue_style('lww-admin-styles', LWW_PLUGIN_URL . 'assets/css/lww-admin-styles.css', [], LWW_PLUGIN_VERSION);

    // Standard-Abhängigkeiten
    $deps = ['jquery', 'jquery-ui-sortable', 'jquery-ui-autocomplete', 'wp-util', 'thickbox'];
    $current_page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';

    // --- Dashboard ---
    if ($current_page === 'bricksberg_wawi_dashboard') {
        wp_enqueue_script('lww-admin-dashboard', LWW_PLUGIN_URL . 'assets/js/lww-admin-dashboard.js', $deps, LWW_PLUGIN_VERSION, true);
        wp_localize_script('lww-admin-dashboard', 'lww_dashboard_data', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('lww_dashboard_nonce')
        ]);
    }

    // --- Inventory UI & Image Fetching ---
    if ($current_page === 'lww_inventory_ui' || $is_lww_cpt) {
        wp_enqueue_script('lww-admin-inventory', LWW_PLUGIN_URL . 'assets/js/lww-admin-inventory.js', $deps, LWW_PLUGIN_VERSION, true);
        wp_enqueue_script('lww-admin-images', LWW_PLUGIN_URL . 'assets/js/lww-admin-images.js', $deps, LWW_PLUGIN_VERSION, true);
        wp_localize_script('lww-admin-inventory', 'lww_inventory_data', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('lww_inventory_ajax_nonce')
        ]);
    }

    // --- Jobs UI ---
    if ($current_page === 'lww_jobs_ui') {
        wp_enqueue_script('lww-admin-jobs', LWW_PLUGIN_URL . 'assets/js/lww-admin-jobs.js', $deps, LWW_PLUGIN_VERSION, true);
        wp_localize_script('lww-admin-jobs', 'lww_jobs_data', ['ajax_url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('lww_job_list_nonce')]);
    }
    // --- Settings UI ---
    if ($current_page === 'lww_settings_ui') {
        wp_enqueue_script('lww-admin-settings', LWW_PLUGIN_URL . 'assets/js/lww-admin-settings.js', $deps, LWW_PLUGIN_VERSION, true);
        wp_localize_script('lww-admin-settings', 'lww_settings_data', ['ajax_url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('lww_settings_ajax_nonce')]);
    }
    // --- Minifigs UI ---
    if ($current_page === 'lww_minifigs_ui') {
        wp_enqueue_media();
        wp_enqueue_script('lww-admin-minifigs', LWW_PLUGIN_URL . 'assets/js/lww-admin-minifigs.js', array_merge($deps, ['media-upload']), LWW_PLUGIN_VERSION, true);
        wp_localize_script('lww-admin-minifigs', 'lww_minifigs_data', ['ajax_url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('lww_minifigs_ajax_nonce')]);
    }
    // --- Storage UI ---
    if ($current_page === 'lww_storage_ui') {
        wp_enqueue_script('lww-admin-storage', LWW_PLUGIN_URL . 'assets/js/lww-admin-storage.js', $deps, LWW_PLUGIN_VERSION, true);
        $locations = get_terms(['taxonomy' => 'lww_inventory_location', 'fields' => 'names', 'hide_empty' => false]);
        wp_localize_script('lww-admin-storage', 'lww_storage_data', ['locations' => !is_wp_error($locations) ? array_values($locations) : []]);
    }
    
    // --- Stock Take UI (Scanner & Mobile App) ---
    if ($current_page === 'lww_stock_take_ui') {
        wp_enqueue_script('lww-admin-stock-take', LWW_PLUGIN_URL . 'assets/js/lww-admin-stock-take.js', $deps, LWW_PLUGIN_VERSION, true);
        wp_localize_script('lww-admin-stock-take', 'lww_stock_take_data', ['ajax_url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('lww_stock_take_nonce')]);
        
        // NEU: Mobile App CSS laden wenn Mode aktiv
        if (isset($_GET['mode']) && $_GET['mode'] === 'app') {
            wp_enqueue_style('lww-mobile-styles', LWW_PLUGIN_URL . 'assets/css/lww-mobile-styles.css', [], LWW_PLUGIN_VERSION);
        }
    }
    
    // --- Reporting UI (NEU) ---
    if ($current_page === 'lww_reporting_ui') {
        wp_enqueue_script('lww-admin-reporting', LWW_PLUGIN_URL . 'assets/js/lww-admin-reporting.js', [], LWW_PLUGIN_VERSION, true);
    }
    
    // --- Data Correction UI ---
    if ($current_page === 'lww_data_correction_ui') {
        wp_enqueue_script('lww-admin-data-correction', LWW_PLUGIN_URL . 'assets/js/lww-admin-data-correction.js', $deps, LWW_PLUGIN_VERSION, true);
        wp_localize_script('lww-admin-data-correction', 'lww_correction_data', ['ajax_url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('lww_correction_ajax_nonce')]);
    }
}
add_action('admin_enqueue_scripts', 'lww_enqueue_admin_assets');
