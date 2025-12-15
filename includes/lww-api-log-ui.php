<?php
/**
 * Modul: API-Log UI (v14.0)
 *
 * Rendert die Seite "API-Log" und zeigt eine WP_List_Table
 * des 'lww_api_log' CPTs sowie eine Kostenübersicht.
 */
if (!defined('ABSPATH')) exit;

// Stellt sicher, dass die WP_List_Table Klasse geladen ist
if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

// AJAX Handler für das Log-Modal
add_action('wp_ajax_lww_get_api_log_content', 'lww_ajax_get_api_log_content_handler');

/**
 * Holt aggregierte Statistiken über die API-Kosten.
 *
 * @return array Ein Array mit Kostenstatistiken, aufgeschlüsselt nach Zeitraum und Dienst.
 */
function lww_get_api_cost_stats() {
    $stats = get_transient('lww_api_cost_stats');

    if (false === $stats) {
        global $wpdb;
        $stats = [
            'today' => 0.0,
            'last_7_days' => 0.0,
            'last_30_days' => 0.0,
            'by_service_30_days' => [],
        ];

        $base_query_from = "
            FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE p.post_type = 'lww_api_log'
            AND pm.meta_key = '_lww_cost' AND p.post_date > %s
        ";

        // Kosten für Zeiträume berechnen
        $stats['today'] = (float) $wpdb->get_var($wpdb->prepare("SELECT SUM(CAST(pm.meta_value AS DECIMAL(10,5))) " . $base_query_from, date('Y-m-d H:i:s', strtotime('-24 hours'))));
        $stats['last_7_days'] = (float) $wpdb->get_var($wpdb->prepare("SELECT SUM(CAST(pm.meta_value AS DECIMAL(10,5))) " . $base_query_from, date('Y-m-d H:i:s', strtotime('-7 days'))));
        $stats['last_30_days'] = (float) $wpdb->get_var($wpdb->prepare("SELECT SUM(CAST(pm.meta_value AS DECIMAL(10,5))) " . $base_query_from, date('Y-m-d H:i:s', strtotime('-30 days'))));

        // Kosten nach Dienst für die letzten 30 Tage
        $thirty_days_ago = date('Y-m-d H:i:s', strtotime('-30 days'));
        $results_by_service = $wpdb->get_results($wpdb->prepare("
            SELECT 
                pm_service.meta_value AS service, 
                SUM(CAST(pm_cost.meta_value AS DECIMAL(10,5))) as total_cost
            FROM {$wpdb->postmeta} pm_cost
            JOIN {$wpdb->posts} p ON p.ID = pm_cost.post_id
            JOIN {$wpdb->postmeta} pm_service ON p.ID = pm_service.post_id AND pm_service.meta_key = '_lww_service'
            WHERE p.post_type = 'lww_api_log'
            AND p.post_date > %s
            AND pm_cost.meta_key = '_lww_cost'
            GROUP BY pm_service.meta_value
            ORDER BY total_cost DESC
        ", $thirty_days_ago));

        if ($results_by_service) {
            foreach ($results_by_service as $row) {
                $stats['by_service_30_days'][ucfirst($row->service)] = (float) $row->total_cost;
            }
        }

        // Ergebnis für 1 Stunde zwischenspeichern
        set_transient('lww_api_cost_stats', $stats, HOUR_IN_SECONDS);
    }

    return $stats;
}

/**
 * LWW_API_Log_List_Table Klasse
 */
class LWW_API_Log_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct([
            'singular' => __('API-Log-Eintrag', 'lego-wawi'),
            'plural'   => __('API-Log-Einträge', 'lego-wawi'),
            'ajax'     => false
        ]);
    }

    public function get_primary_column_name() {
        return 'title';
    }

    public function get_columns() {
        return [
            'title'        => __('Aktion / Endpunkt', 'lego-wawi'),
            'service'      => __('Dienst', 'lego-wawi'),
            'status'       => __('Status', 'lego-wawi'),
            'cost'         => __('Kosten (Simuliert)', 'lego-wawi'),
            'date'         => __('Datum', 'lego-wawi'),
        ];
    }

    public function get_sortable_columns() {
        return [
            'title'     => ['title', false],
            'service'   => ['service', false],
            'status'    => ['status', false],
            'cost'      => ['cost', false],
            'date'      => ['date', true],
        ];
    }

    public function prepare_items() {
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];

        $per_page = 25;
        $current_page = $this->get_pagenum();

        $args = [
            'post_type' => 'lww_api_log',
            'posts_per_page' => $per_page,
            'paged' => $current_page,
            'orderby' => $_REQUEST['orderby'] ?? 'date',
            'order' => $_REQUEST['order'] ?? 'desc',
        ];

        $orderby = $args['orderby'];
        if (in_array($orderby, ['service', 'status', 'cost'])) {
            $args['meta_key'] = '_lww_' . $orderby;
            $args['orderby'] = ($orderby === 'cost') ? 'meta_value_num' : 'meta_value';
        }

        $query = new WP_Query($args);
        $this->items = $query->posts;

        $this->set_pagination_args([
            'total_items' => $query->found_posts,
            'per_page'    => $per_page
        ]);
    }

    function column_default($item, $column_name) {
        $value = get_post_meta($item->ID, '_lww_' . $column_name, true);
        return esc_html($value);
    }

    function column_title($item) {
        $nonce_action = 'lww_view_api_log_details_' . $item->ID;
        $details_url = add_query_arg([
            'action' => 'lww_get_api_log_content',
            'api_log_id' => $item->ID,
            '_wpnonce' => wp_create_nonce($nonce_action),
            'TB_iframe' => 'true',
            'width' => 750,
            'height' => 550,
        ], admin_url('admin-ajax.php'));

        $actions = [
            'details' => sprintf('<a href="%s" class="thickbox">%s</a>', esc_url($details_url), __('Details anzeigen', 'lego-wawi'))
        ];
        return '<strong>' . esc_html($item->post_title) . '</strong>' . $this->row_actions($actions);
    }

    function column_status($item) {
        $status = get_post_meta($item->ID, '_lww_status', true);
        if ($status === 'Success') {
            return '<span style="color: #2a9d8f; font-weight: 600;">' . __('Erfolgreich', 'lego-wawi') . '</span>';
        } else {
            return '<span style="color: #e76f51; font-weight: 600;">' . __('Fehlgeschlagen', 'lego-wawi') . '</span>';
        }
    }
    
    function column_service($item) {
        $service = get_post_meta($item->ID, '_lww_service', true);
        return '<strong>' . esc_html(strtoupper($service)) . '</strong>';
    }

    function column_cost($item) {
        $cost = (float) get_post_meta($item->ID, '_lww_cost', true);
        return number_format_i18n($cost, 5) . ' €';
    }

    function column_date($item) {
        return mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $item->post_date);
    }

    public function no_items() {
        _e('Keine API-Log-Einträge gefunden.', 'lego-wawi');
    }
}

/**
 * Rendert den Inhalt der Seite "API-Log".
 */
function lww_render_api_log_ui_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Sie haben keine Berechtigung, auf diese Seite zuzugreifen.', 'lego-wawi'));
    }

    add_thickbox(); // ThickBox für die Log-Modals laden

    $stats = lww_get_api_cost_stats();
    $api_log_table = new LWW_API_Log_List_Table();
    $api_log_table->prepare_items();
    ?>
    <div class="wrap lww-wrap">
        <h1><img src="<?php echo esc_url(LWW_PLUGIN_URL . 'assets/img/bricksberg-logo.png'); ?>" alt="Bricksberg Logo" class="lww-header-logo" /> <?php _e('API-Nutzungs-Log', 'lego-wawi'); ?></h1>
        <p><?php _e('Hier siehst du ein Protokoll aller ausgehenden API-Anfragen an externe Dienste sowie eine Kostenübersicht.', 'lego-wawi'); ?></p>

        <h2 class="lww-mt-20"><?php _e('Kostenübersicht (Simuliert)', 'lego-wawi'); ?></h2>
        <div class="lww-dashboard-grid">
            <div class="lww-stat-card">
                <h3><span class="dashicons dashicons-money-alt"></span><?php _e('Kosten (Heute)', 'lego-wawi'); ?></h3>
                <div class="stat-number"><?php echo esc_html(number_format_i18n($stats['today'], 5)); ?> €</div>
            </div>
            <div class="lww-stat-card">
                <h3><span class="dashicons dashicons-money-alt"></span><?php _e('Kosten (7 Tage)', 'lego-wawi'); ?></h3>
                <div class="stat-number"><?php echo esc_html(number_format_i18n($stats['last_7_days'], 5)); ?> €</div>
            </div>
            <div class="lww-stat-card">
                <h3><span class="dashicons dashicons-money-alt"></span><?php _e('Kosten (30 Tage)', 'lego-wawi'); ?></h3>
                <div class="stat-number"><?php echo esc_html(number_format_i18n($stats['last_30_days'], 5)); ?> €</div>
            </div>
        </div>

        <?php if (!empty($stats['by_service_30_days'])):
        ?>
            <h2 style="margin-top: 40px;"><?php _e('Kosten nach Dienst (Letzte 30 Tage)', 'lego-wawi'); ?></h2>
            <div class="lww-dashboard-grid">
                <?php foreach ($stats['by_service_30_days'] as $service => $cost): ?>
                    <div class="lww-stat-card">
                        <h3><span class="dashicons dashicons-cloud"></span><?php echo esc_html($service); ?></h3>
                        <div class="stat-number"><?php echo esc_html(number_format_i18n($cost, 5)); ?> €</div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="lww-card lww-mt-20">
            <h2 style="margin-top: 0;"><?php _e('Detailliertes Protokoll', 'lego-wawi'); ?></h2>
            <form id="api-log-filter" method="get">
                <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page'] ?? ''); ?>" />
                <?php $api_log_table->display(); ?>
            </form>
        </div>
    </div>
    <?php
}

/**
 * AJAX-Handler zum Abrufen des Log-Inhalts für das ThickBox-Modal.
 */
function lww_ajax_get_api_log_content_handler() {
    $api_log_id = isset($_GET['api_log_id']) ? absint($_GET['api_log_id']) : 0;
    if (!$api_log_id) {
        wp_die(__('Ungültige Log-ID.', 'lego-wawi'));
    }

    if (!check_ajax_referer('lww_view_api_log_details_' . $api_log_id) || !current_user_can('edit_post', $api_log_id)) {
        wp_die(__('Sicherheitsüberprüfung fehlgeschlagen oder keine Berechtigung.', 'lego-wawi'));
    }

    lww_render_api_log_modal_content($api_log_id);
    wp_die();
}

/**
 * Rendert den HTML-Inhalt für das API-Log-Modal.
 */
function lww_render_api_log_modal_content($api_log_id) {
    $log_post = get_post($api_log_id);
    if (!$log_post || $log_post->post_type !== 'lww_api_log') {
        wp_die(__('Log-Eintrag nicht gefunden.', 'lego-wawi'));
    }

    $details = get_post_meta($api_log_id, '_lww_details', true);
    $response_body = $log_post->post_content;

    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="<?php bloginfo( 'charset' ); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <style>
            body {
                background: #f0f0f1;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
                font-size: 14px;
                color: #3c434a;
                margin: 0;
                padding: 0;
            }
            .lww-api-log-modal-content {
                padding: 20px;
            }
            h2, h3 { margin-top: 0; margin-bottom: 10px; }
            h3 { font-size: 1.1em; }
            pre, textarea {
                width: 100%;
                box-sizing: border-box;
                font-family: monospace;
                font-size: 12px;
                white-space: pre-wrap;
                word-break: break-all;
                background: #fff;
                border: 1px solid #ddd;
                padding: 10px;
            }
            pre {
                max-height: 150px;
                overflow-y: auto;
            }
            textarea {
                height: 250px;
            }
        </style>
    </head>
    <body>
        <div class="lww-api-log-modal-content">
            <h2><?php echo esc_html($log_post->post_title); ?></h2>

            <h3><?php _e('Details / Zusammenfassung', 'lego-wawi'); ?></h3>
            <pre><code><?php echo esc_html(wp_json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></code></pre>

            <h3><?php _e('Roh-Antwort vom Server', 'lego-wawi'); ?></h3>
            <textarea readonly="readonly"><?php echo esc_textarea($response_body); ?></textarea>
        </div>
    </body>
    </html>
    <?php
}

?>
