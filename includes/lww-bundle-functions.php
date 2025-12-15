<?php
/**
 * Modul: Paket & Bundle Funktionen (v1.1)
 *
 * Enthält die gesamte Backend-Logik für die Erstellung, Synchronisation
 * und Lagerbestandsverwaltung von Paketen (Bundles).
 * 
 * UPDATE: AJAX-Funktionen für Schnell-Erstellung aus der Liste hinzugefügt.
 */
if (!defined('ABSPATH')) exit;

add_action('init', 'lww_register_bundle_ajax_handlers_and_hooks');

function lww_register_bundle_ajax_handlers_and_hooks() {
    // AJAX-Handler
    add_action('wp_ajax_lww_search_inventory_items', 'lww_ajax_search_inventory_items');
    add_action('wp_ajax_lww_sync_wc_bundle', 'lww_ajax_sync_wc_bundle');
    
    // NEU: Quick Bundle Creation Handlers
    add_action('wp_ajax_lww_get_bundle_creation_form', 'lww_ajax_get_bundle_creation_form');
    add_action('wp_ajax_lww_process_bundle_creation_from_inventory', 'lww_ajax_process_bundle_creation_from_inventory');

    // Hooks für die Lagerbestands-Synchronisation
    add_action('woocommerce_reduce_stock_levels', 'lww_reduce_base_stock_for_bundle_sale');
    add_action('updated_post_meta', 'lww_update_bundle_product_stock_hook', 10, 4);
}

/**
 * AJAX-Handler zur Suche nach lww_inventory_item Posts für Autocomplete.
 */
function lww_ajax_search_inventory_items() {
    check_ajax_referer('lww_bundles_ajax_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error([], 403);
    }

    $search_term = isset($_GET['term']) ? sanitize_text_field($_GET['term']) : '';
    if (empty($search_term)) {
        wp_send_json_error([], 400);
    }

    $query = new WP_Query([
        'post_type' => 'lww_inventory_item',
        'posts_per_page' => 15,
        's' => $search_term,
    ]);

    $results = [];
    if ($query->have_posts()) {
        foreach ($query->posts as $post) {
            $results[] = [
                'id' => $post->ID,
                'label' => $post->post_title,
                'value' => $post->post_title,
            ];
        }
    }

    wp_send_json($results);
}

/**
 * AJAX-Handler zur Synchronisation eines Pakets mit WooCommerce.
 */
function lww_ajax_sync_wc_bundle() {
    check_ajax_referer('lww_bundles_ajax_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Fehlende Berechtigung.', 'lego-wawi')], 403);
    }

    $bundle_id = isset($_POST['bundle_id']) ? absint($_POST['bundle_id']) : 0;
    if (!$bundle_id || get_post_type($bundle_id) !== 'lww_bundle') {
        wp_send_json_error(['message' => __('Ungültige Paket-ID.', 'lego-wawi')], 400);
    }

    $base_item_id = get_post_meta($bundle_id, '_lww_inventory_item_id', true);
    $bundle_qty = (int) get_post_meta($bundle_id, '_lww_bundle_quantity', true);
    $price = (float) get_post_meta($bundle_id, '_lww_price_wc', true);
    $wc_product_id = get_post_meta($bundle_id, '_lww_wc_product_id', true);

    if (!$base_item_id || $bundle_qty <= 0) {
        wp_send_json_error(['message' => __('Paket ist nicht korrekt konfiguriert (Basis-Artikel oder Stückzahl fehlt).', 'lego-wawi')], 400);
    }

    $base_stock = (int) get_post_meta($base_item_id, '_quantity', true);
    $calculated_stock = floor($base_stock / $bundle_qty);

    $product = $wc_product_id ? wc_get_product($wc_product_id) : null;
    if (!$product) {
        $product = new WC_Product_Simple();
    }

    $product->set_name(get_the_title($bundle_id));
    $product->set_status('publish');
    $product->set_regular_price($price);
    $product->set_manage_stock(true);
    $product->set_stock_quantity($calculated_stock);
    $product->update_meta_data('_lww_bundle_id', $bundle_id);

    $new_product_id = $product->save();

    if ($new_product_id) {
        update_post_meta($bundle_id, '_lww_wc_product_id', $new_product_id);
        wp_send_json_success(['message' => 'WooCommerce-Produkt erfolgreich synchronisiert.']);
    }
    wp_send_json_error(['message' => 'Fehler beim Speichern des WooCommerce-Produkts.']);
}

/**
 * Reduziert den Lagerbestand des Basis-Artikels, wenn ein Paket verkauft wird.
 */
function lww_reduce_base_stock_for_bundle_sale($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;

    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        $bundle_id = $product->get_meta('_lww_bundle_id', true);

        if ($bundle_id) {
            $base_item_id = get_post_meta($bundle_id, '_lww_inventory_item_id', true);
            $bundle_qty = (int) get_post_meta($bundle_id, '_lww_bundle_quantity', true);
            $ordered_qty = $item->get_quantity();

            if ($base_item_id && $bundle_qty > 0) {
                $total_to_reduce = $ordered_qty * $bundle_qty;
                $current_base_stock = (int) get_post_meta($base_item_id, '_quantity', true);
                $new_stock = $current_base_stock - $total_to_reduce;
                update_post_meta($base_item_id, '_quantity', $new_stock);
                $order->add_order_note(sprintf(__('Lagerbestand für Basis-Artikel #%d (aus Paket #%d) um %d Stück reduziert.', 'lego-wawi'), $base_item_id, $bundle_id, $total_to_reduce));
            }
        }
    }
}

/**
 * Hook-Wrapper, der prüft, ob die Meta-Aktualisierung relevant ist.
 */
function lww_update_bundle_product_stock_hook($meta_id, $object_id, $meta_key, $_meta_value) {
    if ($meta_key === '_quantity' && get_post_type($object_id) === 'lww_inventory_item') {
        lww_update_bundle_product_stock($object_id, $_meta_value);
    }
}

/**
 * Aktualisiert den Lagerbestand aller zugehörigen Paket-Produkte in WooCommerce,
 * wenn sich der Bestand des Basis-Artikels ändert.
 */
function lww_update_bundle_product_stock($base_item_id, $new_base_stock) {
    $bundles_query = new WP_Query([
        'post_type' => 'lww_bundle',
        'posts_per_page' => -1,
        'meta_query' => [
            [
                'key' => '_lww_inventory_item_id',
                'value' => $base_item_id,
            ],
        ],
    ]);

    if ($bundles_query->have_posts()) {
        foreach ($bundles_query->posts as $bundle_post) {
            $wc_product_id = get_post_meta($bundle_post->ID, '_lww_wc_product_id', true);
            $bundle_qty = (int) get_post_meta($bundle_post->ID, '_lww_bundle_quantity', true);

            if ($wc_product_id && $bundle_qty > 0) {
                $new_bundle_stock = floor((int)$new_base_stock / $bundle_qty);
                wc_update_product_stock($wc_product_id, $new_bundle_stock);
            }
        }
    }
}

/**
 * AJAX: Rendert das Formular für die schnelle Bundle-Erstellung.
 */
function lww_ajax_get_bundle_creation_form() {
    // Verwende den Nonce aus dem Inventar-Kontext, da der Aufruf von dort kommt
    check_ajax_referer('lww_inventory_ajax_nonce');
    
    $item_id = absint($_GET['item_id']);
    $item = get_post($item_id);
    if (!$item) wp_die('Artikel nicht gefunden.');

    $current_qty = (int)get_post_meta($item_id, '_quantity', true);
    $unit_price = (float)get_post_meta($item_id, '_price', true);
    $default_bundle_qty = 10; 
    $suggested_price = $unit_price * $default_bundle_qty;

    echo '<form id="lww-quick-bundle-form" style="padding:20px;">';
    echo '<input type="hidden" name="action" value="lww_process_bundle_creation_from_inventory">';
    echo '<input type="hidden" name="item_id" value="' . esc_attr($item_id) . '">';
    wp_nonce_field('lww_inventory_ajax_nonce', '_nonce');

    echo '<h3>Bundle erstellen für: ' . esc_html($item->post_title) . '</h3>';
    echo '<p><em>Verfügbarer Bestand: ' . $current_qty . ' Stück</em></p>';

    echo '<p><label><strong>Stück pro Paket:</strong></label><br>';
    echo '<input type="number" name="bundle_qty" value="' . $default_bundle_qty . '" min="1" max="' . $current_qty . '" class="widefat" required></p>';

    echo '<p><label><strong>Verkaufspreis (Gesamt):</strong></label><br>';
    echo '<input type="number" name="bundle_price" value="' . number_format($suggested_price, 2, '.', '') . '" step="0.01" min="0" class="widefat" required>';
    echo '<span class="description">Standard: Einzelpreis x Menge</span></p>';

    echo '<p><button type="submit" class="button button-primary button-large" style="width:100%">Bundle anlegen</button></p>';
    echo '</form>';
    wp_die();
}

/**
 * AJAX: Verarbeitet die Erstellung.
 */
function lww_ajax_process_bundle_creation_from_inventory() {
    check_ajax_referer('lww_inventory_ajax_nonce', '_nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Keine Rechte.']);

    $item_id = absint($_POST['item_id']);
    $bundle_qty = absint($_POST['bundle_qty']);
    $price = floatval($_POST['bundle_price']);

    if (!$item_id || $bundle_qty <= 0) wp_send_json_error(['message' => 'Ungültige Eingaben.']);

    $item_title = get_the_title($item_id);
    $bundle_title = sprintf('%d-er Paket: %s', $bundle_qty, $item_title);

    $post_id = wp_insert_post([
        'post_type' => 'lww_bundle',
        'post_title' => $bundle_title,
        'post_status' => 'publish',
    ]);

    if (is_wp_error($post_id)) wp_send_json_error(['message' => $post_id->get_error_message()]);

    update_post_meta($post_id, '_lww_inventory_item_id', $item_id);
    update_post_meta($post_id, '_lww_bundle_quantity', $bundle_qty);
    update_post_meta($post_id, '_lww_price_wc', $price);
    update_post_meta($post_id, '_lww_price_ebay', $price);

    wp_send_json_success(['message' => 'Bundle erfolgreich erstellt.']);
}
