<?php
/**
 * Modul: UI für Bestellungsanalyse (v1.1)
 * 
 * UPDATE: Analyse umfasst nun ALLE Bestellungen, nicht nur abgeschlossene.
 */
if (!defined('ABSPATH')) exit;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Rendert den Inhalt des "Bestellungsanalyse"-Tabs.
 */
function lww_render_order_analysis_ui_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Sie haben keine Berechtigung, auf diese Seite zuzugreifen.', 'lego-wawi'));
    }

    $analysis_table = new LWW_Order_Analysis_List_Table();
    $analysis_table->prepare_items();
    ?>
    <div class="wrap lww-wrap">
        <h1><img src="<?php echo esc_url(LWW_PLUGIN_URL . 'assets/img/bricksberg-logo.png'); ?>" alt="Bricksberg Logo" class="lww-header-logo" /> <?php _e('Bestellungsanalyse', 'lego-wawi'); ?></h1>
        <p><?php _e('Analysiere deine Verkaufsdaten (alle Bestellungen), um Bestseller zu identifizieren und deinen Lagerbestand zu optimieren.', 'lego-wawi'); ?></p>

        <?php settings_errors('lww_messages'); ?>

        <div class="lww-card lww-mt-20">
            <form action="admin-post.php" method="post">
                <input type="hidden" name="action" value="lww_refresh_order_analysis">
                <?php wp_nonce_field('lww_refresh_order_analysis_nonce'); ?>
                <?php submit_button(__('Analyse aktualisieren', 'lego-wawi'), 'primary'); ?>
                <p class="description"><?php _e('Die Analyseergebnisse werden für eine Stunde zwischengespeichert. Klicke hier, um die Daten sofort neu zu berechnen.', 'lego-wawi'); ?></p>
            </form>
        </div>

        <div class="lww-card lww-mt-20">
            <h2 style="margin-top: 0;"><?php _e('Meistverkaufte Artikel', 'lego-wawi'); ?></h2>
            <form id="order-analysis-filter" method="get">
                <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page'] ?? ''); ?>" />
                <?php $analysis_table->display(); ?>
            </form>
        </div>
    </div>
    <?php
}

class LWW_Order_Analysis_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct([
            'singular' => __('Bestseller-Artikel', 'lego-wawi'),
            'plural'   => __('Bestseller-Artikel', 'lego-wawi'),
            'ajax'     => false
        ]);
    }

    public function get_primary_column_name() {
        return 'item';
    }

    public function get_columns() {
        return [
            'thumbnail'   => __('Bild', 'lego-wawi'),
            'item'        => __('Artikel', 'lego-wawi'),
            'color'       => __('Farbe', 'lego-wawi'),
            'order_count' => __('Anzahl Bestellungen', 'lego-wawi'),
            'total_sold'  => __('Verkaufte Menge', 'lego-wawi'),
            'current_stock' => __('Akt. Lagerbestand', 'lego-wawi'),
        ];
    }

    public function get_sortable_columns() {
        return [
            'item'          => ['item_title', false],
            'order_count'   => ['order_count', true],
            'total_sold'    => ['total_sold', false],
            'current_stock' => ['current_stock', false],
        ];
    }

    public function prepare_items() {
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];
        $data = lww_get_order_analysis_data();

        // Sortierung
        $orderby = $_REQUEST['orderby'] ?? 'order_count';
        $order = $_REQUEST['order'] ?? 'desc';

        if (!empty($data)) {
            usort($data, function($a, $b) use ($orderby, $order) {
                $a_val = $a[$orderby] ?? 0;
                $b_val = $b[$orderby] ?? 0;
                if ($a_val == $b_val) return 0;
                if ($order === 'asc') {
                    return $a_val < $b_val ? -1 : 1;
                } else {
                    return $a_val > $b_val ? -1 : 1;
                }
            });
        }

        $per_page = 25;
        $current_page = $this->get_pagenum();
        $total_items = count($data);

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page
        ]);

        $this->items = array_slice($data, (($current_page - 1) * $per_page), $per_page);
    }

    function column_default($item, $column_name) {
        return $item[$column_name] ?? '---';
    }

    function column_thumbnail($item) {
        if (!empty($item['catalog_post_id'])) {
            return get_the_post_thumbnail($item['catalog_post_id'], [60, 60]);
        }
        return '';
    }

    function column_item($item) {
        if (!empty($item['catalog_post_id'])) {
            return sprintf('<a href="%s"><strong>%s</strong></a>', get_edit_post_link($item['catalog_post_id']), esc_html($item['item_title']));
        }
        return '<strong>' . esc_html($item['item_title']) . '</strong>';
    }

    function column_color($item) {
        if (!empty($item['color_post_id'])) {
             return sprintf('<a href="%s">%s</a>', get_edit_post_link($item['color_post_id']), esc_html($item['color_title']));
        }
        return $item['color_title'] ?? '---';
    }

    function column_current_stock($item) {
        if ($item['inventory_item_id']) {
             return sprintf('<a href="%s">%d</a>', get_edit_post_link($item['inventory_item_id']), (int)$item['current_stock']);
        }
        return $item['current_stock'] ?? 'N/A';
    }

    public function no_items() {
        _e('Keine Bestelldaten zur Analyse gefunden.', 'lego-wawi');
    }
}

/**
 * Sammelt und analysiert die Bestelldaten.
 */
function lww_get_order_analysis_data() {
    $transient_key = 'lww_order_analysis_data';
    $cached_data = get_transient($transient_key);
    if (false !== $cached_data) {
        return $cached_data;
    }

    $query = new WP_Query([
        'post_type' => 'lww_order',
        // KORREKTUR: Alle Status erlauben für Analyse
        'post_status' => 'any',
        'posts_per_page' => -1,
    ]);

    $aggregated_data = [];

    if ($query->have_posts()) {
        foreach ($query->posts as $order_post) {
            $items = get_post_meta($order_post->ID, '_lww_order_items', true);
            if (!is_array($items)) continue;

            foreach ($items as $item) {
                $item_num = $item['boid'] ?? $item['item_no'] ?? null;
                if (!$item_num) continue;

                $catalog_item_info = LWW_Import_Handler_Base::find_catalog_item_by_boid($item_num);
                if (!$catalog_item_info) continue;

                $catalog_post_id = $catalog_item_info['id'];
                $color_post_id = 0;

                if ($catalog_item_info['type'] === 'lww_part') {
                    $color_name = $item['color_name'] ?? null;
                    $color_id_rebrickable = $item['color_id'] ?? null; // For BrickLink

                    if ($color_name) {
                        $color_post_id = LWW_Import_Handler_Base::find_color_by_name($color_name);
                    } elseif ($color_id_rebrickable) {
                        $color_post_id = LWW_Import_Handler_Base::find_color_by_rebrickable_id($color_id_rebrickable);
                    }
                    if (!$color_post_id) continue; // Skip parts without valid color
                }

                $unique_key = $catalog_post_id . '-' . $color_post_id;
                if (!isset($aggregated_data[$unique_key])) {
                    $aggregated_data[$unique_key] = [
                        'catalog_post_id' => $catalog_post_id,
                        'color_post_id'   => $color_post_id,
                        'item_title'      => get_the_title($catalog_post_id),
                        'color_title'     => $color_post_id ? get_the_title($color_post_id) : '',
                        'total_sold'      => 0,
                        'order_count'     => 0,
                    ];
                }

                $aggregated_data[$unique_key]['total_sold'] += (int) $item['quantity'];
                $aggregated_data[$unique_key]['order_count']++;
            }
        }
    }

    // Aktuellen Lagerbestand hinzufügen
    foreach ($aggregated_data as $key => &$data) {
        $inv_query_args = [
            'post_type' => 'lww_inventory_item',
            'posts_per_page' => 1,
            'meta_query' => [
                'relation' => 'AND',
            ],
        ];

        if ($data['color_post_id']) { // Es ist ein Teil
            $inv_query_args['meta_query'][] = ['key' => '_lww_part_id', 'value' => $data['catalog_post_id']];
            $inv_query_args['meta_query'][] = ['key' => '_lww_color_id', 'value' => $data['color_post_id']];
        } else { // Set oder Minifig
            $post_type = get_post_type($data['catalog_post_id']);
            $meta_key = ($post_type === 'lww_set') ? '_lww_set_id' : '_lww_minifig_id';
            $inv_query_args['meta_query'][] = ['key' => $meta_key, 'value' => $data['catalog_post_id']];
        }

        $inventory_item_query = new WP_Query($inv_query_args);
        if ($inventory_item_query->have_posts()) {
            $inventory_item_id = $inventory_item_query->posts[0]->ID;
            $data['inventory_item_id'] = $inventory_item_id;
            $data['current_stock'] = (int) get_post_meta($inventory_item_id, '_quantity', true);
        } else {
            $data['inventory_item_id'] = 0;
            $data['current_stock'] = 0;
        }
    }

    set_transient($transient_key, $aggregated_data, HOUR_IN_SECONDS);
    return $aggregated_data;
}

/**
 * Handler zum Leeren des Analyse-Caches.
 */
function lww_handle_refresh_order_analysis() {
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'lww_refresh_order_analysis_nonce')) {
        wp_die(__('Sicherheitsüberprüfung fehlgeschlagen.', 'lego-wawi'));
    }
    if (!current_user_can('manage_options')) {
        wp_die(__('Keine Berechtigung.', 'lego-wawi'));
    }

    delete_transient('lww_order_analysis_data');
    add_settings_error('lww_messages', 'analysis_refreshed', __('Die Bestellungsanalyse wurde erfolgreich aktualisiert.', 'lego-wawi'), 'success');
    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect(wp_get_referer());
    exit;
}
add_action('admin_post_lww_refresh_order_analysis', 'lww_handle_refresh_order_analysis');

?>
