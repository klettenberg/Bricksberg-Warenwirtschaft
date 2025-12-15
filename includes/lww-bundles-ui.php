<?php
/**
 * Modul: Pakete & Bundles UI (v1.0)
 *
 * Rendert die Seite "Pakete & Bundles" und stellt die UI
 * zur Verwaltung von `lww_bundle` CPTs bereit.
 */
if (!defined('ABSPATH')) exit;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class LWW_Bundles_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct([
            'singular' => __('Paket', 'lego-wawi'),
            'plural'   => __('Pakete', 'lego-wawi'),
            'ajax'     => false
        ]);
    }

    public function get_primary_column_name() {
        return 'title';
    }

    public function get_columns() {
        return [
            'cb'                => '<input type="checkbox" />',
            'title'             => __('Paket-Titel', 'lego-wawi'),
            'base_item'         => __('Basis-Artikel', 'lego-wawi'),
            'bundle_quantity'   => __('Stück pro Paket', 'lego-wawi'),
            'calculated_stock'  => __('Verfügbare Pakete', 'lego-wawi'),
            'prices'            => __('Preise (WC/eBay)', 'lego-wawi'),
            'marketplaces'      => __('Marktplätze', 'lego-wawi'),
            'actions'           => __('Aktionen', 'lego-wawi'),
        ];
    }

    public function prepare_items() {
        $this->_column_headers = [$this->get_columns(), [], []];
        $query = new WP_Query([
            'post_type' => 'lww_bundle',
            'posts_per_page' => 20,
            'paged' => $this->get_pagenum(),
        ]);
        $this->items = $query->posts;
        $this->set_pagination_args([
            'total_items' => $query->found_posts,
            'per_page'    => 20,
        ]);
    }

    function column_cb($item) {
        return sprintf('<input type="checkbox" name="bundle[]" value="%s" />', $item->ID);
    }

    function column_title($item) {
        return sprintf('<strong>%s</strong>', esc_html($item->post_title));
    }

    function column_base_item($item) {
        $item_id = get_post_meta($item->ID, '_lww_inventory_item_id', true);
        if ($item_id) {
            return sprintf('<a href="%s">%s</a>', get_edit_post_link($item_id), get_the_title($item_id));
        }
        return '---';
    }

    function column_bundle_quantity($item) {
        return (int) get_post_meta($item->ID, '_lww_bundle_quantity', true);
    }

    function column_calculated_stock($item) {
        $item_id = get_post_meta($item->ID, '_lww_inventory_item_id', true);
        $bundle_qty = (int) get_post_meta($item->ID, '_lww_bundle_quantity', true);
        if ($item_id && $bundle_qty > 0) {
            $base_stock = (int) get_post_meta($item_id, '_quantity', true);
            return floor($base_stock / $bundle_qty);
        }
        return 0;
    }

    function column_prices($item) {
        $price_wc = (float) get_post_meta($item->ID, '_lww_price_wc', true);
        $price_ebay = (float) get_post_meta($item->ID, '_lww_price_ebay', true);
        return sprintf('WC: %s €<br>eBay: %s €', number_format_i18n($price_wc, 2), number_format_i18n($price_ebay, 2));
    }
    
    function column_marketplaces($item) {
        $wc_prod_id = get_post_meta($item->ID, '_lww_wc_product_id', true);
        $badges = [];
        if ($wc_prod_id) {
            $wc_link = get_edit_post_link($wc_prod_id);
            $badges[] = sprintf(
                '<a href="%s" target="_blank" class="lww-marketplace-badge lww-marketplace-wc" title="%s">WC</a>',
                esc_url($wc_link),
                sprintf(__('Als WooCommerce-Produkt synchronisiert (ID: %d)', 'lego-wawi'), $wc_prod_id)
            );
        }
        return empty($badges) ? '---' : implode(' ', $badges);
    }

    function column_actions($item) {
        $buttons = [];
        $buttons[] = sprintf(
            '<button class="button button-secondary button-small lww-ajax-sync-wc-bundle lww-button-wc" data-bundle-id="%d">WC Sync</button>',
            $item->ID
        );
        return '<div style="display:flex; gap: 4px;">' . implode(' ', $buttons) . '</div>';
    }
}

/**
 * Rendert die Seite "Pakete & Bundles".
 */
function lww_render_bundles_ui_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Sie haben keine Berechtigung, auf diese Seite zuzugreifen.', 'lego-wawi'));
    }

    $bundles_table = new LWW_Bundles_List_Table();
    $bundles_table->prepare_items();
    ?>
    <div class="wrap lww-wrap">
        <h1><img src="<?php echo esc_url(LWW_PLUGIN_URL . 'assets/img/bricksberg-logo.png'); ?>" alt="Bricksberg Logo" class="lww-header-logo" /> <?php _e('Pakete & Bundles', 'lego-wawi'); ?></h1>
        <p><?php _e('Erstelle und verwalte hier verkaufbare Pakete, die aus einer bestimmten Menge eines einzelnen Inventarartikels bestehen.', 'lego-wawi'); ?></p>

        <?php settings_errors('lww_bundle_messages'); ?>

        <div id="col-container" class="wp-clearfix">
            <div id="col-left">
                <div class="col-wrap">
                    <div class="form-wrap">
                        <h2><?php _e('Neues Paket erstellen', 'lego-wawi'); ?></h2>
                        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                            <input type="hidden" name="action" value="lww_create_bundle">
                            <?php wp_nonce_field('lww_create_bundle_nonce'); ?>

                            <div class="form-field">
                                <label for="lww_inventory_item_search"><?php _e('Basis-Inventarartikel', 'lego-wawi'); ?></label>
                                <input type="text" id="lww_inventory_item_search" placeholder="<?php esc_attr_e('Artikel suchen (z.B. 3001 rot)...', 'lego-wawi'); ?>">
                                <input type="hidden" name="lww_inventory_item_id" id="lww_inventory_item_id" required>
                                <p><?php _e('Wähle den Inventarartikel aus, aus dem das Paket bestehen soll.', 'lego-wawi'); ?></p>
                            </div>

                            <div class="form-field">
                                <label for="lww_bundle_quantity"><?php _e('Stück pro Paket', 'lego-wawi'); ?></label>
                                <input type="number" name="lww_bundle_quantity" id="lww_bundle_quantity" value="10" min="1" required>
                                <p><?php _e('Wie viele einzelne Teile sind in einem Paket enthalten?', 'lego-wawi'); ?></p>
                            </div>

                            <div class="form-field">
                                <label for="lww_price_wc"><?php _e('Preis für WooCommerce', 'lego-wawi'); ?></label>
                                <input type="number" name="lww_price_wc" id="lww_price_wc" step="0.01" min="0">
                            </div>
                            
                            <div class="form-field">
                                <label for="lww_price_ebay"><?php _e('Preis für eBay', 'lego-wawi'); ?></label>
                                <input type="number" name="lww_price_ebay" id="lww_price_ebay" step="0.01" min="0">
                            </div>

                            <?php submit_button(__('Paket erstellen', 'lego-wawi')); ?>
                        </form>
                    </div>
                </div>
            </div>
            <div id="col-right">
                <div class="col-wrap">
                    <div id="lww-bundles-list-container">
                         <?php $bundles_table->display(); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Verarbeitet das Formular zum Erstellen eines neuen Pakets.
 */
function lww_handle_create_bundle() {
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'lww_create_bundle_nonce')) {
        wp_die(__('Sicherheitsüberprüfung fehlgeschlagen.', 'lego-wawi'));
    }
    if (!current_user_can('manage_options')) {
        wp_die(__('Keine Berechtigung.', 'lego-wawi'));
    }

    $item_id = isset($_POST['lww_inventory_item_id']) ? absint($_POST['lww_inventory_item_id']) : 0;
    $bundle_qty = isset($_POST['lww_bundle_quantity']) ? absint($_POST['lww_bundle_quantity']) : 0;

    if (!$item_id || $bundle_qty <= 0) {
        add_settings_error('lww_bundle_messages', 'invalid_data', __('Bitte wähle einen gültigen Basis-Artikel und eine Stückzahl größer als 0.', 'lego-wawi'), 'error');
    } else {
        $base_item_title = get_the_title($item_id);
        $post_title = sprintf('%d-er Paket von "%s"', $bundle_qty, $base_item_title);

        $post_id = wp_insert_post([
            'post_type' => 'lww_bundle',
            'post_title' => $post_title,
            'post_status' => 'publish',
        ]);

        if (!is_wp_error($post_id)) {
            update_post_meta($post_id, '_lww_inventory_item_id', $item_id);
            update_post_meta($post_id, '_lww_bundle_quantity', $bundle_qty);
            update_post_meta($post_id, '_lww_price_wc', isset($_POST['lww_price_wc']) ? floatval($_POST['lww_price_wc']) : 0.0);
            update_post_meta($post_id, '_lww_price_ebay', isset($_POST['lww_price_ebay']) ? floatval($_POST['lww_price_ebay']) : 0.0);
            add_settings_error('lww_bundle_messages', 'bundle_created', __('Neues Paket erfolgreich erstellt.', 'lego-wawi'), 'success');
        } else {
            add_settings_error('lww_bundle_messages', 'creation_failed', __('Fehler beim Erstellen des Pakets: ', 'lego-wawi') . $post_id->get_error_message(), 'error');
        }
    }

    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect(wp_get_referer());
    exit;
}
add_action('admin_post_lww_create_bundle', 'lww_handle_create_bundle');

?>