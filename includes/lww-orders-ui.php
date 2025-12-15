<?php
/**
 * Modul: Bestellungen UI (v18.6-CONVERT)
 * 
 * UPDATE: Button zur Konvertierung in WC Orders hinzugefügt.
 */
if (!defined('ABSPATH')) exit;

if (!class_exists('WP_List_Table')) require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

class LWW_Orders_List_Table extends WP_List_Table {
    public function __construct() {
        parent::__construct(['singular' => __('Bestellung', 'lego-wawi'), 'plural' => __('Bestellungen', 'lego-wawi'), 'ajax' => false]);
    }

    public function get_columns() {
        return [
            'cb'            => '<input type="checkbox" />',
            'title'         => __('Bestellung', 'lego-wawi'),
            'items_preview' => __('Inhalt', 'lego-wawi'), 
            'order_status'  => __('Status', 'lego-wawi'),
            'source'        => __('Quelle', 'lego-wawi'),
            'wc_order'      => __('WooCommerce', 'lego-wawi'),
            'total'         => __('Gesamtbetrag', 'lego-wawi'),
            'date'          => __('Datum', 'lego-wawi'),
            'actions'       => __('Aktionen', 'lego-wawi')
        ];
    }

    public function prepare_items() {
        $this->_column_headers = [$this->get_columns(), [], []];
        $per_page = 20;
        $args = [
            'post_type' => 'lww_order',
            'posts_per_page' => $per_page,
            'paged' => $this->get_pagenum(),
            'orderby' => 'date',
            'order' => 'DESC'
        ];
        $query = new WP_Query($args);
        $this->items = $query->posts;
        $this->set_pagination_args(['total_items' => $query->found_posts, 'per_page' => $per_page]);
    }
    
    function column_cb($item) { return sprintf('<input type="checkbox" name="order[]" value="%s" />', $item->ID); }

    function column_title($item) {
        $actions = ['edit' => sprintf('<a href="%s">%s</a>', get_edit_post_link($item->ID), __('Bearbeiten', 'lego-wawi'))];
        return '<strong>' . esc_html($item->post_title) . '</strong>' . $this->row_actions($actions);
    }
    
    function column_items_preview($item) {
        $order_items = get_post_meta($item->ID, '_lww_order_items', true);
        if (empty($order_items) || !is_array($order_items)) return '<span style="color:#ccc;">-</span>';
        $count = count($order_items);
        $preview = [];
        $i = 0;
        foreach ($order_items as $oi) {
            $name = $oi['name'] ?? $oi['item_name'] ?? 'Unbekannt';
            $qty = $oi['quantity'] ?? 1;
            if ($i < 2) $preview[] = sprintf('<strong>%dx</strong> %s', $qty, mb_strimwidth($name, 0, 25, '...'));
            $i++;
        }
        $html = implode('<br>', array_map('esc_html', $preview));
        if ($count > 2) $html .= '<br><small>+' . ($count - 2) . ' weitere</small>';
        return $html;
    }

    function column_order_status($item) {
        $status_object = get_post_status_object($item->post_status);
        return $status_object ? esc_html($status_object->label) : '';
    }

    function column_total($item) {
        $total = (float) get_post_meta($item->ID, '_lww_order_total', true);
        return number_format_i18n($total, 2) . ' ' . (get_post_meta($item->ID, '_lww_order_currency', true) ?: 'EUR');
    }
    
    function column_source($item) {
        return esc_html(ucfirst(get_post_meta($item->ID, '_lww_order_source', true)));
    }

    function column_wc_order($item) {
        $wc_order_id = get_post_meta($item->ID, '_lww_wc_order_id', true);
        if ($wc_order_id && get_post($wc_order_id)) {
            $url = get_edit_post_link($wc_order_id);
            return sprintf('<a href="%s" target="_blank">#%d <span class="dashicons dashicons-external"></span></a>', esc_url($url), (int)$wc_order_id);
        }
        return '---';
    }

    function column_actions($item) {
        $wc_order_id = get_post_meta($item->ID, '_lww_wc_order_id', true);
        if (!$wc_order_id) {
            $url = wp_nonce_url(admin_url('admin-post.php?action=lww_convert_order_to_wc&lww_order_id=' . $item->ID), 'lww_convert_order_nonce');
            return sprintf('<a href="%s" class="button button-small" onclick="return confirm(\'Möchten Sie eine WooCommerce Bestellung daraus erstellen?\')">%s</a>', esc_url($url), __('Zu WC', 'lego-wawi'));
        }
        return '';
    }
}

function lww_render_orders_ui_page() {
    if (!current_user_can('manage_options')) wp_die('Access denied');
    $orders_table = new LWW_Orders_List_Table();
    $orders_table->prepare_items();
    ?>
    <div class="wrap lww-wrap">
        <h1><img src="<?php echo esc_url(LWW_PLUGIN_URL . 'assets/img/bricksberg-logo.png'); ?>" class="lww-header-logo" /> <?php _e('Bestellungen', 'lego-wawi'); ?></h1>
        <p><?php _e('Übersicht aller importierten Bestellungen.', 'lego-wawi'); ?></p>
        <div class="lww-card lww-mt-20">
            <form id="orders-filter" method="get">
                <input type="hidden" name="page" value="lww_orders_ui" />
                <?php $orders_table->display(); ?>
            </form>
        </div>
    </div>
    <?php
}
?>