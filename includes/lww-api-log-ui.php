<?php
/**
 * Modul: API-Log UI (v13.0)
 *
 * Rendert die Seite "API-Log" und zeigt eine WP_List_Table
 * des 'lww_api_log' CPTs.
 */
if (!defined('ABSPATH')) exit;

// Stellt sicher, dass die WP_List_Table Klasse geladen ist
if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
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
        $details = esc_html($item->post_content);
        $actions = [
            'details' => '<a href="#" onclick="alert(this.dataset.details); return false;" data-details="' . esc_attr($details) . '">' . __('Details anzeigen', 'lego-wawi') . '</a>'
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
        return '$' . number_format($cost, 5, ',', '.');
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

    $api_log_table = new LWW_API_Log_List_Table();
    $api_log_table->prepare_items();
    ?>
    <div class="wrap lww-wrap">
        <h1><img src="<?php echo esc_url(LWW_PLUGIN_URL . 'assets/img/bricksberg-logo.png'); ?>" alt="Bricksberg Logo" class="lww-header-logo" /> <?php _e('API-Nutzungs-Log', 'lego-wawi'); ?></h1>
        <p><?php _e('Hier siehst du ein Protokoll aller ausgehenden API-Anfragen an externe Dienste.', 'lego-wawi'); ?></p>

        <div class="lww-card lww-mt-20">
            <form id="api-log-filter" method="get">
                <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page'] ?? ''); ?>" />
                <?php $api_log_table->display(); ?>
            </form>
        </div>
    </div>
    <?php
}


?>