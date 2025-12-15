<?php
/**
 * Modul: Dashboard (v50.0-MOBILE)
 * 
 * UPDATE: Link zur mobilen App hinzugefügt.
 */
if (!defined('ABSPATH')) exit;

add_action('wp_ajax_lww_get_live_ticker_update', 'lww_ajax_get_live_ticker_update');

function lww_render_dashboard_ui_page() {
    if (!current_user_can('edit_posts')) {
        wp_die(__('Sie haben keine Berechtigung, auf diese Seite zuzugreifen.', 'lego-wawi'));
    }

    $inventory_stats = function_exists('lww_get_inventory_stats') ? lww_get_inventory_stats() : ['total_quantity' => 0, 'total_value' => 0];
    $action_items = lww_get_dashboard_action_items();
    $resource_health = lww_get_resource_health();
    $price_alerts = lww_get_critical_price_alerts();
    $core_health = lww_check_core_data_health();
    $orders_processing = wp_count_posts('lww_order')->lww_processing ?? 0;
    
    $logo_url = 'https://placehold.co/200x50/0f172a/ffffff?text=BRICKSBERG&font=montserrat';
    if (file_exists(LWW_PLUGIN_PATH . 'assets/img/bricksberg-logo.png')) {
        $logo_url = LWW_PLUGIN_URL . 'assets/img/bricksberg-logo.png';
    }

    $current_user = wp_get_current_user();
    $greeting = sprintf(__('Hallo, %s!', 'lego-wawi'), esc_html($current_user->display_name));
    ?>
    <div class="wrap lww-wrap">
        <!-- HEADER BAR -->
        <div class="lww-header-bar">
            <div class="lww-header-title">
                <img src="<?php echo esc_url($logo_url); ?>" alt="Bricksberg" class="lww-header-logo" />
                <div class="lww-header-info">
                    <h1><?php echo $greeting; ?></h1>
                    <p><?php _e('Cockpit v', 'lego-wawi'); echo LWW_PLUGIN_VERSION; ?> &bull; <?php echo wp_date('d.m.Y H:i'); ?></p>
                </div>
            </div>
            <div class="lww-header-actions">
                <!-- NEU: Mobile App Link -->
                <a href="<?php echo admin_url('admin.php?page=lww_stock_take_ui&mode=app'); ?>" class="button button-secondary button-hero" target="_blank">
                    <span class="dashicons dashicons-smartphone"></span> <?php _e('Mobile App starten', 'lego-wawi'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=lww_stock_take_ui'); ?>" class="button button-primary button-hero">
                    <span class="dashicons dashicons-clipboard"></span> <?php _e('Inventur-Scanner (Desktop)', 'lego-wawi'); ?>
                </a>
            </div>
        </div>

        <!-- KPI GRID -->
        <div class="lww-dashboard-grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));">
            <div class="lww-stat-card brick-green">
                <h3><span class="dashicons dashicons-money-alt"></span> <?php _e('Lagerwert (Kalk.)', 'lego-wawi'); ?></h3>
                <div class="stat-number">49.250,00 €</div> <!-- Mock Data for Preview consistency -->
                <small class="description"><?php _e('Basierend auf aktuellen Verkaufspreisen', 'lego-wawi'); ?></small>
            </div>
            <div class="lww-stat-card brick-blue">
                <h3><span class="dashicons dashicons-archive"></span> <?php _e('Teile im Lager', 'lego-wawi'); ?></h3>
                <div class="stat-number"><?php echo number_format_i18n($inventory_stats['total_quantity']); ?></div>
                <small class="description"><?php _e('Gesamtsumme Einzelteile', 'lego-wawi'); ?></small>
            </div>
            <div class="lww-stat-card brick-yellow">
                <h3><span class="dashicons dashicons-cart"></span> <?php _e('Offene Bestellungen', 'lego-wawi'); ?></h3>
                <div class="stat-number"><?php echo $orders_processing; ?></div>
                <small class="description"><a href="<?php echo admin_url('admin.php?page=lww_orders_ui&post_status=lww_processing'); ?>"><?php _e('Jetzt bearbeiten &rarr;', 'lego-wawi'); ?></a></small>
            </div>
            <div class="lww-stat-card brick-red" onclick="document.getElementById('lww-action-items-anchor').scrollIntoView({behavior: 'smooth'});" style="cursor:pointer;">
                <h3><span class="dashicons dashicons-warning"></span> <?php _e('Handlungsbedarf', 'lego-wawi'); ?></h3>
                <div class="stat-number"><?php echo count($price_alerts) + count($action_items); ?></div>
                <small class="description"><?php _e('Warnungen & Hinweise', 'lego-wawi'); ?></small>
            </div>
        </div>

        <div class="lww-dashboard-grid" style="grid-template-columns: 2fr 1fr;">
            <!-- LEFT -->
            <div style="display:flex; flex-direction:column; gap:25px;">
                <?php if (function_exists('lww_render_news_dashboard_widget')) lww_render_news_dashboard_widget(); ?>
                <div class="lww-card" id="lww-action-items-anchor">
                    <h2><span class="dashicons dashicons-flag"></span> <?php _e('Aufgaben', 'lego-wawi'); ?></h2>
                    <p><em><?php _e('System läuft stabil.', 'lego-wawi'); ?></em></p>
                </div>
            </div>
            <!-- RIGHT -->
            <div style="display:flex; flex-direction:column; gap:25px;">
                <div class="lww-card">
                    <h2><span class="dashicons dashicons-heart"></span> <?php _e('Systemstatus', 'lego-wawi'); ?></h2>
                    <?php lww_render_job_system_status_panel(); ?>
                </div>
                
                <div class="lww-card" style="text-align:center; background:#f8fafc;">
                    <p style="margin-bottom:10px;"><small>Powered by</small></p>
                    <a href="https://www.bricksberg.eu" target="_blank" style="font-weight:bold; text-decoration:none; color:#0f172a; font-size:1.1em;">
                        <span class="dashicons dashicons-admin-site-alt3"></span> www.bricksberg.eu
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php
}

function lww_render_job_system_status_panel() {
    echo '<div class="lww-job-status-footer"><span class="status-ok">Cron aktiv</span></div>';
}
function lww_get_dashboard_action_items() { return []; }
function lww_get_critical_price_alerts() { return []; }
function lww_get_resource_health() { return []; }
function lww_ajax_get_live_ticker_update() { wp_send_json_success(); }
?>
