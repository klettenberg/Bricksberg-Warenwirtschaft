<?php
/**
 * Modul: Admin-Seite & Navigation (v30.2-FIX)
 * 
 * FIX: 404 Fehler im Hauptmenü durch korrekte Slug- und Capability-Zuordnung behoben.
 */
if (!defined('ABSPATH')) exit;

function lww_add_admin_menu() {
    $cap_ops = 'edit_posts'; 
    $cap_admin = 'manage_options';
    $menu_slug = 'bricksberg_wawi_dashboard';

    // 1. Hauptmenü - Zeigt auf Dashboard
    add_menu_page(
        'Bricksberg WaWi',
        'Bricksberg WaWi',
        $cap_ops,
        $menu_slug,
        'lww_render_dashboard_ui_page',
        'dashicons-layout',
        26
    );
    
    // Erstes Submenü MUSS denselben Slug haben wie Hauptmenü, um Default zu sein
    add_submenu_page(
        $menu_slug,
        __('Cockpit', 'lego-wawi'),
        __('Cockpit', 'lego-wawi'),
        $cap_ops,
        $menu_slug,
        'lww_render_dashboard_ui_page'
    );

    lww_add_separator($menu_slug, '--- OPERATIV ---');
    
    add_submenu_page($menu_slug, __('Inventar', 'lego-wawi'), __('Inventar & Preise', 'lego-wawi'), $cap_ops, 'lww_inventory_ui', 'lww_render_inventory_ui_page');
    add_submenu_page($menu_slug, __('Bestellungen', 'lego-wawi'), __('Bestellungen', 'lego-wawi'), $cap_ops, 'lww_orders_ui', 'lww_render_orders_ui_page');
    add_submenu_page($menu_slug, __('Pick-Listen', 'lego-wawi'), __('Pick-Listen', 'lego-wawi'), $cap_ops, 'lww_pick_list_ui', 'lww_render_pick_list_ui_page');
    add_submenu_page($menu_slug, __('Inventur', 'lego-wawi'), __('Inventur (Scanner)', 'lego-wawi'), $cap_ops, 'lww_stock_take_ui', 'lww_render_stock_take_ui_page');
    add_submenu_page($menu_slug, __('Lagerverwaltung', 'lego-wawi'), __('Lager & Karte', 'lego-wawi'), $cap_ops, 'lww_warehouse_map_ui', 'lww_render_warehouse_map_ui_page');
    add_submenu_page($menu_slug, __('Pakete', 'lego-wawi'), __('Pakete & Bundles', 'lego-wawi'), $cap_ops, 'lww_bundles_ui', 'lww_render_bundles_ui_page');
    add_submenu_page($menu_slug, __('Marktanalyse', 'lego-wawi'), __('Pricing', 'lego-wawi'), $cap_ops, 'lww_market_analysis_ui', 'lww_render_market_analysis_ui_page');
    add_submenu_page($menu_slug, __('Minifiguren', 'lego-wawi'), __('Minifiguren', 'lego-wawi'), $cap_ops, 'lww_minifigs_ui', 'lww_render_minifigs_ui_page');

    lww_add_separator($menu_slug, '--- VERWALTUNG ---');
    
    add_submenu_page($menu_slug, __('Katalog: Teile', 'lego-wawi'), __('Katalog: Teile', 'lego-wawi'), $cap_ops, 'lww_parts_ui', 'lww_render_parts_ui_page');
    add_submenu_page($menu_slug, __('Teile-Kategorien', 'lego-wawi'), __('Teile-Kategorien', 'lego-wawi'), $cap_ops, 'edit-tags.php?taxonomy=lww_part_category&post_type=lww_part');
    add_submenu_page($menu_slug, __('Sets', 'lego-wawi'), __('Katalog: Sets', 'lego-wawi'), $cap_ops, 'edit.php?post_type=lww_set');
    add_submenu_page($menu_slug, __('LEGO Themen', 'lego-wawi'), __('LEGO Themen', 'lego-wawi'), $cap_ops, 'edit-tags.php?taxonomy=lww_theme&post_type=lww_set');
    add_submenu_page($menu_slug, __('Farben', 'lego-wawi'), __('Katalog: Farben', 'lego-wawi'), $cap_ops, 'edit.php?post_type=lww_color');

    if (current_user_can($cap_admin)) {
        lww_add_separator($menu_slug, '--- SYSTEM ---');
        
        add_submenu_page($menu_slug, __('Berichte', 'lego-wawi'), __('Berichte & Grafiken', 'lego-wawi'), $cap_admin, 'lww_reporting_ui', 'lww_render_reporting_ui_page');
        add_submenu_page($menu_slug, __('Import & Sync', 'lego-wawi'), __('Import & Sync', 'lego-wawi'), $cap_admin, 'lww_import_ui', 'lww_render_import_ui_page');
        add_submenu_page($menu_slug, __('Jobs', 'lego-wawi'), __('Jobs & Logs', 'lego-wawi'), $cap_admin, 'lww_jobs_ui', 'lww_render_tab_jobs');
        add_submenu_page($menu_slug, __('Einstellungen', 'lego-wawi'), __('Einstellungen', 'lego-wawi'), $cap_admin, 'lww_settings_ui', 'lww_settings_page_html');
        add_submenu_page($menu_slug, __('Werkzeuge', 'lego-wawi'), __('Werkzeuge', 'lego-wawi'), $cap_admin, 'lww_tools_ui', 'lww_render_tools_ui_page');
        add_submenu_page($menu_slug, __('Daten-Korrektur', 'lego-wawi'), __('Daten-Korrektur', 'lego-wawi'), $cap_admin, 'lww_data_correction_ui', 'lww_render_data_correction_ui_page');
        add_submenu_page($menu_slug, __('Marketing', 'lego-wawi'), __('Marketing', 'lego-wawi'), $cap_admin, 'lww_marketing_ui', 'lww_render_marketing_ui_page');
        add_submenu_page($menu_slug, __('Handbuch', 'lego-wawi'), __('Handbuch', 'lego-wawi'), $cap_admin, 'lww_manual_ui', 'lww_render_manual_ui_page');
    }

    // Hidden Pages
    add_submenu_page(null, 'API-Log', 'API-Log', $cap_admin, 'lww_api_log_ui', 'lww_render_api_log_ui_page');
    add_submenu_page(null, 'Setup Wizard', 'Setup Wizard', $cap_admin, 'lww_setup_wizard', 'lww_render_setup_wizard_ui_page');
}
add_action('admin_menu', 'lww_add_admin_menu');

function lww_add_separator($parent, $title) {
    add_submenu_page(
        $parent,
        $title,
        '<span class="lww-menu-separator" style="display:block;margin:5px 0;border-bottom:1px solid rgba(255,255,255,0.1);padding-bottom:5px;color:rgba(255,255,255,0.5);font-size:10px;text-transform:uppercase;cursor:default;">' . esc_html($title) . '</span>',
        'read',
        '#',
        '__return_false'
    );
}

if (!function_exists('lww_settings_page_html')) { function lww_settings_page_html() { } }
if (!function_exists('lww_render_dashboard_ui_page')) { function lww_render_dashboard_ui_page() { echo 'Dashboard loaded via Fallback.'; } }
?>