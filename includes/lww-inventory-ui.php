<?php
/**
 * Modul: Inventar UI (v27.0)
 * UPDATE: Anzeige von Marktplatz-spezifischen Preisen (wenn vorhanden).
 */
if (!defined('ABSPATH')) exit;

if (!class_exists('WP_List_Table')) require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

// Handler
add_action('admin_init', 'lww_process_inventory_bulk_actions');

class LWW_Inventory_List_Table extends WP_List_Table {
    public function __construct() {
        parent::__construct(['singular' => __('Item', 'lego-wawi'), 'plural' => __('Items', 'lego-wawi'), 'ajax' => false]);
    }

    public function get_columns() {
        return [
            'cb' => '<input type="checkbox" />',
            'main_info' => __('Details', 'lego-wawi'),
            'stock_loc' => __('Lager', 'lego-wawi'),
            'pricing' => __('Preise (Lokal | BL | BO)', 'lego-wawi'),
            'source_status' => __('Sync', 'lego-wawi'), 
            'actions' => __('Aktionen', 'lego-wawi'),
        ];
    }

    // ... (Sortable columns etc wie gehabt)
    public function get_sortable_columns() {
        return [
            'main_info' => ['title', false],
            'stock_loc' => ['_quantity', false],
            'pricing' => ['_price', false],
        ];
    }

    public function get_bulk_actions() {
        return [
            'delete' => __('Löschen', 'lego-wawi'),
            'create_wc_product' => __('Sync zu WC', 'lego-wawi'),
            'sync_ebay' => __('Sync zu eBay', 'lego-wawi'),
            'sync_bricklink' => __('Sync zu BL', 'lego-wawi'),
            'sync_brickowl' => __('Sync zu BO', 'lego-wawi')
        ];
    }

    function column_cb($item) { return sprintf('<input type="checkbox" name="inventory_item[]" value="%s" />', $item->ID); }
    
    function column_main_info($item) {
        $catalog_id = get_post_meta($item->ID, '_lww_part_id', true) ?: get_post_meta($item->ID, '_lww_minifig_id', true) ?: get_post_meta($item->ID, '_lww_set_id', true);
        $img_html = '<div class="lww-thumbnail-wrapper"><button class="button button-small lww-fetch-image" data-id="'.esc_attr($item->ID).'">Bild</button></div>';
        
        $is_stub = get_post_meta($catalog_id, '_lww_is_stub', true);
        if($catalog_id && (has_post_thumbnail($catalog_id) || get_post_meta($catalog_id, '_lww_sideload_image_url', true))) {
            $img_html = lww_render_catalog_columns('thumbnail', $catalog_id, true);
        }

        $title = get_the_title($item->ID);
        if ($is_stub) $title .= ' <span class="lww-status-badge">STUB</span>';

        $condition = get_post_meta($item->ID, '_condition', true);
        $cond_label = ($condition === 'new') ? '<b>NEU</b>' : 'GEB';
        
        return sprintf(
            '<div class="lww-inv-card-main">%s<div class="lww-inv-card-content"><strong><a href="%s">%s</a></strong> %s</div></div>',
            $img_html,
            get_edit_post_link($item->ID),
            $title,
            $cond_label
        );
    }

    function column_stock_loc($item) {
        $qty = (int) get_post_meta($item->ID, '_quantity', true);
        $terms = get_the_term_list($item->ID, 'lww_inventory_location', '', ', ');
        $loc_html = $terms ? '<span class="lww-loc-badge">' . $terms . '</span>' : '<span style="color:red;">-</span>';
        return sprintf('<div><strong>%d</strong> Stk.</div><div style="margin-top:2px;">%s</div>', $qty, $loc_html);
    }

    function column_pricing($item) {
        $price = (float)get_post_meta($item->ID, '_price', true);
        // Optionale spezifische Preise
        $price_bl = get_post_meta($item->ID, '_price_bricklink', true);
        $price_bo = get_post_meta($item->ID, '_price_brickowl', true);
        
        $html = '<div style="font-size:1.2em; font-weight:bold;">' . number_format($price, 3) . ' €</div>';
        
        if ($price_bl || $price_bo) {
            $html .= '<div style="font-size:0.8em; color:#666;">';
            if ($price_bl) $html .= 'BL: ' . number_format((float)$price_bl, 3) . ' €<br>';
            if ($price_bo) $html .= 'BO: ' . number_format((float)$price_bo, 3) . ' €';
            $html .= '</div>';
        }
        
        return $html;
    }

    function column_source_status($item) {
        $bl_id = get_post_meta($item->ID, '_lww_bricklink_inventory_id', true);
        $bo_lot = get_post_meta($item->ID, '_lww_lot_id', true);
        $wc_id = get_post_meta($item->ID, '_lww_wc_product_id', true);
        $ebay_id = get_post_meta($item->ID, '_lww_ebay_listing_id', true);

        $html = '';
        $style_bl = $bl_id ? 'color:green;' : 'color:#ccc;';
        $html .= sprintf('<span title="BL: %s" style="%s font-weight:bold; margin-right:4px;">BL</span>', esc_attr($bl_id), $style_bl);

        $style_bo = $bo_lot ? 'color:green;' : 'color:#ccc;';
        $html .= sprintf('<span title="BO: %s" style="%s font-weight:bold; margin-right:4px;">BO</span>', esc_attr($bo_lot), $style_bo);

        $style_wc = $wc_id ? 'color:#96588a;' : 'color:#ccc;';
        $html .= sprintf('<span title="WC: %s" style="%s font-weight:bold; margin-right:4px;">WC</span>', esc_attr($wc_id), $style_wc);

        $style_ebay = $ebay_id ? 'color:#e53238;' : 'color:#ccc;';
        $html .= sprintf('<span title="EB: %s" style="%s font-weight:bold;">EB</span>', esc_attr($ebay_id), $style_ebay);

        return $html;
    }

    function column_actions($item) {
        return sprintf('<a href="%s" class="button button-small">Edit</a>', get_edit_post_link($item->ID));
    }
    
    public function prepare_items() {
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];
        $paged = $this->get_pagenum();
        
        // Multitenancy Filter via lww-multitenancy.php (pre_get_posts) greift automatisch
        $args = [
            'post_type' => 'lww_inventory_item',
            'post_status' => 'publish',
            'posts_per_page' => 20,
            'paged' => $paged,
            'orderby' => 'date',
            'order' => 'DESC'
        ];
        
        $query = new WP_Query($args);
        $this->items = $query->posts;
        $this->set_pagination_args(['total_items' => $query->found_posts, 'per_page' => 20, 'total_pages' => $query->max_num_pages]);
    }
}

// Render und Bulk Action Logic wie gehabt...
function lww_render_inventory_ui_page() {
    $table = new LWW_Inventory_List_Table();
    $table->prepare_items();
    echo '<div class="wrap"><h1>Inventar</h1>';
    settings_errors('lww_messages');
    echo '<form id="inventory-filter" method="post"><input type="hidden" name="page" value="lww_inventory_ui">';
    $table->search_box('Suchen', 'search_id');
    $table->display();
    echo '</form></div>';
}

function lww_process_inventory_bulk_actions() {
    if (!isset($_POST['inventory_item']) || empty($_POST['action'])) return;
    if (!current_user_can('manage_options')) return;
    // ... (Bulk Logic wie zuvor, nur gekürzt für Kontext) ...
    // Wenn action 'create_wc_product', dann Batch Job erstellen.
}
?>