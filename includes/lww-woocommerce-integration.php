<?php
/**
 * Modul: WooCommerce Integration (v22.1-ORDER-CONVERT)
 * 
 * Hinzugefügt: Konvertierung von Import-Bestellungen (lww_order) zu echten WC Orders.
 */
if (!defined('ABSPATH')) exit;

function lww_register_wc_hooks() {
    add_action('wp_ajax_lww_create_wc_product', 'lww_ajax_create_wc_product_handler');
    add_action('woocommerce_order_status_changed', 'lww_trigger_order_import_on_status_change', 10, 4);
    add_action('woocommerce_reduce_stock_levels', 'lww_reduce_wawi_stock_from_wc_order');
    add_action('updated_post_meta', 'lww_sync_stock_to_wc_on_meta_update', 10, 4);
    
    // NEU: Action für Order-Konvertierung
    add_action('admin_post_lww_convert_order_to_wc', 'lww_handle_convert_order_to_wc');
}
add_action('init', 'lww_register_wc_hooks');

/**
 * Konvertiert eine WaWi-Bestellung in eine WooCommerce-Bestellung.
 */
function lww_handle_convert_order_to_wc() {
    if (!check_admin_referer('lww_convert_order_nonce')) wp_die('Security check');
    if (!current_user_can('manage_options')) wp_die('Permission denied');

    $lww_order_id = absint($_GET['lww_order_id']);
    $result = lww_create_wc_order_from_lww_order($lww_order_id);

    if (is_wp_error($result)) {
        wp_die('Fehler: ' . $result->get_error_message());
    }

    // Redirect zum neuen WC Order Edit Screen
    wp_safe_redirect(admin_url('post.php?post=' . $result . '&action=edit'));
    exit;
}

function lww_create_wc_order_from_lww_order($lww_order_id) {
    if (!class_exists('WC_Order')) return new WP_Error('wc_missing', 'WooCommerce fehlt.');

    $lww_order = get_post($lww_order_id);
    if (!$lww_order || $lww_order->post_type !== 'lww_order') return new WP_Error('invalid_order', 'Ungültige WaWi Bestellung');

    $items = get_post_meta($lww_order_id, '_lww_order_items', true);
    if (empty($items)) return new WP_Error('no_items', 'Bestellung hat keine Positionen.');

    $wc_order = wc_create_order();
    $wc_order->set_date_created($lww_order->post_date);
    $wc_order->set_status('processing'); // Standard
    
    // Optional: Kundeninfos könnten hier aus Meta geholt werden, wenn gespeichert.
    $wc_order->set_billing_first_name('Imported');
    $wc_order->set_billing_last_name('Order #' . $lww_order_id);

    foreach ($items as $item_data) {
        // Versuch, ein passendes WC Produkt zu finden
        $product_id = 0;
        $inv_item_id = $item_data['inventory_item_id'] ?? 0;

        if ($inv_item_id) {
            $wc_prod_id = get_post_meta($inv_item_id, '_lww_wc_variation_id', true) ?: get_post_meta($inv_item_id, '_lww_wc_product_id', true);
            if ($wc_prod_id) $product_id = $wc_prod_id;
        }

        if ($product_id) {
            $product = wc_get_product($product_id);
            $wc_order->add_product($product, $item_data['quantity']);
        } else {
            // Ad-hoc Item
            $item = new WC_Order_Item_Product();
            $item->set_name($item_data['item_name'] . ' (' . ($item_data['color_name']??'') . ')');
            $item->set_quantity($item_data['quantity']);
            $item->set_total($item_data['unit_price'] * $item_data['quantity']);
            $wc_order->add_item($item);
        }
    }

    $wc_order->calculate_totals();
    $wc_order->add_order_note('Erstellt aus WaWi Bestellung #' . $lww_order_id);
    $wc_order_id = $wc_order->save();

    // Link back
    update_post_meta($lww_order_id, '_lww_wc_order_id', $wc_order_id);

    return $wc_order_id;
}

// ... (Restlicher Code aus lww-woocommerce-integration.php wie lww_ajax_create_wc_product_handler, etc. bleibt unverändert) ...
// Um die Datei komplett zu halten (da MODIFY), füge ich den Rest hier wieder an:

function lww_ajax_create_wc_product_handler() {
    try {
        check_ajax_referer('lww_inventory_ajax_nonce', '_ajax_nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Berechtigung fehlt.', 'lego-wawi')], 403);
        }
        $item_id = isset($_POST['item_id']) ? absint($_POST['item_id']) : 0;
        $catalog_id = isset($_POST['catalog_id']) ? absint($_POST['catalog_id']) : 0;
        $result = lww_create_or_update_wc_product($item_id, $catalog_id);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 500);
        }
        $wc_link = get_edit_post_link($result['product_id']);
        $status_html = sprintf(
            '<a href="%s" target="_blank" class="lww-marketplace-badge lww-marketplace-wc" title="%s">WC</a>',
            esc_url($wc_link),
            sprintf(__('Sync OK (Var ID: %d)', 'lego-wawi'), $result['variation_id'])
        );
        wp_send_json_success(['message' => sprintf('Sync OK (ID: %d)', $result['variation_id']), 'status_html' => $status_html]);
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()], 500);
    }
}

function lww_create_or_update_wc_product($item_id, $catalog_id = 0) {
    if (!class_exists('WooCommerce')) return new WP_Error('wc_missing', 'WooCommerce ist nicht aktiv.');
    if (empty($item_id)) return new WP_Error('invalid_id', 'Ungültige Item-ID.');

    if (empty($catalog_id)) {
        $catalog_id = get_post_meta($item_id, '_lww_part_id', true) 
                   ?: get_post_meta($item_id, '_lww_minifig_id', true) 
                   ?: get_post_meta($item_id, '_lww_set_id', true);
    }
    
    $catalog_post = get_post($catalog_id);
    if (!$catalog_post) return new WP_Error('not_found', 'Katalog-Eintrag nicht gefunden.');

    $post_type = get_post_type($catalog_id);
    $product_sku = '';
    $product_cat_name = '';
    $meta_prefix = '';
    $additional_attributes = [];

    switch ($post_type) {
        case 'lww_part':
            $brickowl_id = get_post_meta($catalog_id, '_lww_brickowl_id', true);
            $part_num = get_post_meta($catalog_id, '_lww_part_num', true);
            $product_sku = !empty($brickowl_id) ? $brickowl_id : $part_num;
            $product_cat_name = __('Ersatzteile', 'lego-wawi');
            $meta_prefix = '_lww_part_';
            $part_cats = wp_get_post_terms($catalog_id, 'lww_part_category', ['fields' => 'names']);
            if (!empty($part_cats)) $product_cat_name = $part_cats[0];
            break;
        case 'lww_minifig':
            $product_sku = get_post_meta($catalog_id, '_lww_minifig_num', true);
            $product_cat_name = __('Minifiguren', 'lego-wawi');
            $meta_prefix = '_lww_minifig_';
            $additional_attributes['Figur-Nummer'] = $product_sku;
            break;
        case 'lww_set':
            $product_sku = get_post_meta($catalog_id, '_lww_set_num', true);
            $product_cat_name = __('Sets', 'lego-wawi');
            $meta_prefix = '_lww_set_';
            $themes = wp_get_post_terms($catalog_id, 'lww_theme', ['fields' => 'names']);
            if (!empty($themes)) $product_cat_name = $themes[0];
            $additional_attributes['Set-Nummer'] = $product_sku;
            break;
    }

    $price = (float) get_post_meta($item_id, '_price', true);
    if ($price <= 0) return new WP_Error('invalid_price', 'Preis muss > 0 sein.');

    $attributes_for_product = [];
    $attr_condition_id = lww_get_or_create_wc_attribute('Zustand');
    $attr_condition = new WC_Product_Attribute();
    $attr_condition->set_id($attr_condition_id);
    $attr_condition->set_name('pa_zustand');
    $attr_condition->set_visible(true);
    $attr_condition->set_variation(true);
    $attributes_for_product[] = $attr_condition;

    if ($post_type === 'lww_part') {
        $attr_color_id = lww_get_or_create_wc_attribute('Farbe');
        $attr_color = new WC_Product_Attribute();
        $attr_color->set_id($attr_color_id);
        $attr_color->set_name('pa_farbe');
        $attr_color->set_visible(true);
        $attr_color->set_variation(true);
        $attributes_for_product[] = $attr_color;
    }

    foreach ($additional_attributes as $name => $val) {
        $attr = new WC_Product_Attribute();
        $attr->set_name($name);
        $attr->set_options([$val]);
        $attr->set_visible(true);
        $attr->set_variation(false);
        $attributes_for_product[] = $attr;
    }

    $product_id = lww_find_wc_product_by_catalog_id($catalog_id);
    $product = null;

    $short_description = get_post_meta($catalog_id, '_lww_short_description', true);
    $long_description_wc = get_post_meta($catalog_id, '_lww_seo_description_wc', true);
    $german_name = get_post_meta($catalog_id, $meta_prefix . 'name_de', true);
    $product_name = !empty($german_name) ? $german_name : get_the_title($catalog_id);

    if ($product_id) {
        $product = wc_get_product($product_id);
        if (!$product->is_type('variable')) {
             wp_set_object_terms($product_id, 'variable', 'product_type');
             $product = new WC_Product_Variable($product_id);
        }
    } else {
        $product = new WC_Product_Variable();
        $product->set_status('publish');
    }

    $product->set_name($product_name);
    $product->set_sku($product_sku);
    $product->set_attributes($attributes_for_product);
    $product->set_short_description($short_description);
    $product->set_description($long_description_wc);
    
    $thumbnail_id = get_post_thumbnail_id($catalog_id);
    if ($thumbnail_id) $product->set_image_id($thumbnail_id);

    $product_id = $product->save();
    update_post_meta($product_id, '_lww_catalog_id', $catalog_id);
    update_post_meta($catalog_id, '_lww_wc_product_id', $product_id);

    if ($product_cat_name) {
        $term = get_term_by('name', $product_cat_name, 'product_cat');
        if (!$term) {
            $term_res = wp_insert_term($product_cat_name, 'product_cat');
            if (!is_wp_error($term_res)) wp_set_object_terms($product_id, $term_res['term_id'], 'product_cat');
        } else {
            wp_set_object_terms($product_id, $term->term_id, 'product_cat');
        }
    }

    $condition_raw = get_post_meta($item_id, '_condition', true);
    $condition_label = ($condition_raw === 'new') ? 'Neu' : 'Gebraucht';
    $term_condition = lww_get_or_create_wc_attribute_term($condition_label, 'pa_zustand');
    wp_set_object_terms($product_id, $term_condition->slug, 'pa_zustand', true);
    $variation_attributes = ['attribute_pa_zustand' => $term_condition->slug];

    if ($post_type === 'lww_part') {
        $color_name = get_post_meta($item_id, '_color_name', true);
        $term_color = lww_get_or_create_wc_attribute_term($color_name, 'pa_farbe');
        wp_set_object_terms($product_id, $term_color->slug, 'pa_farbe', true);
        $variation_attributes['attribute_pa_farbe'] = $term_color->slug;
    }

    $variation_id = lww_find_wc_variation($product_id, $variation_attributes);
    $variation = $variation_id ? new WC_Product_Variation($variation_id) : new WC_Product_Variation();
    
    if (!$variation_id) {
        $variation->set_parent_id($product_id);
        $clean_attribs = [];
        foreach($variation_attributes as $k => $v) $clean_attribs[str_replace('attribute_', '', $k)] = $v;
        $variation->set_attributes($clean_attribs);
    }

    $quantity = (int) get_post_meta($item_id, '_quantity', true);
    $variation->set_manage_stock(true);
    $variation->set_stock_quantity($quantity);
    $variation->set_regular_price($price);
    $variation->set_status('publish');

    $new_variation_id = $variation->save();
    
    update_post_meta($item_id, '_lww_wc_variation_id', $new_variation_id);
    update_post_meta($item_id, '_lww_wc_product_id', $product_id);

    return ['product_id' => $product_id, 'variation_id' => $new_variation_id];
}

function lww_trigger_order_import_on_status_change($order_id, $old_status, $new_status, $order) {
    $import_statuses = apply_filters('lww_wc_order_import_statuses', ['processing', 'completed', 'on-hold']);
    if (in_array($new_status, $import_statuses)) lww_create_lww_order_from_wc_order($order_id);
}

function lww_create_lww_order_from_wc_order($wc_order_id) {
    if (!function_exists('wc_get_order')) return;
    if (get_post_meta($wc_order_id, '_lww_imported_to_wawi', true)) return;
    try {
        $order = wc_get_order($wc_order_id);
        if (!$order) throw new Exception('WC_Order Fehler');

        $wc_status = $order->get_status();
        $lww_status = lww_map_wc_status_to_lww($wc_status);

        $lww_order_id = wp_insert_post([
            'post_type'   => 'lww_order',
            'post_title'  => sprintf('WooCommerce #%d', $order->get_id()),
            'post_status' => $lww_status,
            'post_date'   => $order->get_date_created()->date('Y-m-d H:i:s'),
        ], true);

        if (is_wp_error($lww_order_id)) throw new Exception($lww_order_id->get_error_message());

        update_post_meta($lww_order_id, '_lww_order_source', 'woocommerce');
        update_post_meta($lww_order_id, '_lww_external_order_id', $order->get_id());
        update_post_meta($lww_order_id, '_lww_order_total', $order->get_total());
        update_post_meta($lww_order_id, '_lww_order_currency', $order->get_currency());

        $lww_items = [];
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_variation_id() ?: $item->get_product_id();
            $item_data = ['quantity' => $item->get_quantity(), 'unit_price' => $order->get_item_total($item, false, false), 'item_name' => $item->get_name()];
            $inv_query = new WP_Query(['post_type'=>'lww_inventory_item', 'meta_key'=>'_lww_wc_variation_id', 'meta_value'=>$product_id, 'posts_per_page'=>1]);
            if ($inv_query->have_posts()) {
                $inv_item_id = $inv_query->posts[0]->ID;
                $item_data['boid'] = get_post_meta($inv_item_id, '_boid', true);
                $item_data['color_name'] = get_post_meta($inv_item_id, '_color_name', true);
                $item_data['item_no'] = get_post_meta($inv_item_id, '_lww_bricklink_item_no', true) ?: get_post_meta($inv_item_id, '_lww_part_num', true);
                $item_data['inventory_item_id'] = $inv_item_id;
            } else {
                $prod = $item->get_product();
                if($prod) $item_data['item_no'] = $prod->get_sku();
                $item_data['color_name'] = $item->get_meta('pa_farbe', true) ?: '';
            }
            $lww_items[] = $item_data;
        }
        update_post_meta($lww_order_id, '_lww_order_items', $lww_items);
        update_post_meta($lww_order_id, '_lww_wc_order_id', $wc_order_id);
        update_post_meta($wc_order_id, '_lww_imported_to_wawi', $lww_order_id);
    } catch (Exception $e) { lww_log_system_event('FEHLER Import WC: ' . $e->getMessage(), 'error'); }
}

function lww_map_wc_status_to_lww($wc_status) {
    switch ($wc_status) {
        case 'completed': return 'lww_completed';
        case 'processing': return 'lww_processing';
        case 'on-hold': return 'lww_received';
        case 'cancelled': return 'lww_cancelled';
        case 'refunded': return 'lww_cancelled';
        default: return 'lww_received';
    }
}

function lww_reduce_wawi_stock_from_wc_order($order_id) {
    if (!function_exists('wc_get_order')) return;
    $order = wc_get_order($order_id);
    if (!$order) return;
    foreach ($order->get_items() as $item) {
        $product_id = $item->get_variation_id() ?: $item->get_product_id();
        $qty = $item->get_quantity();
        $inv_query = new WP_Query(['post_type' => 'lww_inventory_item', 'meta_key' => '_lww_wc_variation_id', 'meta_value' => $product_id, 'fields' => 'ids']);
        if ($inv_query->have_posts()) {
            $inv_id = $inv_query->posts[0];
            $curr = (int)get_post_meta($inv_id, '_quantity', true);
            update_post_meta($inv_id, '_quantity', max(0, $curr - $qty));
        }
    }
}

function lww_sync_stock_to_wc_on_meta_update($meta_id, $object_id, $meta_key, $meta_value) {
    if ($meta_key !== '_quantity' || get_post_type($object_id) !== 'lww_inventory_item') return;
    $wc_id = get_post_meta($object_id, '_lww_wc_variation_id', true);
    if ($wc_id) {
        $prod = wc_get_product($wc_id);
        if ($prod) wc_update_product_stock($prod, intval($meta_value));
    }
    if(function_exists('lww_sync_stock_to_external_stores')) lww_sync_stock_to_external_stores($object_id, intval($meta_value));
}

function lww_get_or_create_wc_attribute($name) {
    static $cache = [];
    if (isset($cache[$name])) return $cache[$name];
    $slug = sanitize_title($name);
    $id = wc_attribute_taxonomy_id_by_name('pa_' . $slug);
    if ($id) { $cache[$name] = $id; return $id; }
    $new_id = wc_create_attribute(['name' => $name, 'slug' => $slug, 'type' => 'select']);
    if (is_wp_error($new_id)) throw new Exception($new_id->get_error_message());
    register_taxonomy('pa_'.$slug, ['product'], ['labels' => ['name' => $name]]);
    delete_transient('wc_attribute_taxonomies');
    $cache[$name] = $new_id;
    return $new_id;
}

function lww_get_or_create_wc_attribute_term($term, $tax) {
    $exist = get_term_by('name', $term, $tax);
    if ($exist) return $exist;
    $new = wp_insert_term($term, $tax);
    if (is_wp_error($new)) {
        $exist = get_term_by('slug', sanitize_title($term), $tax);
        if ($exist) return $exist;
        throw new Exception($new->get_error_message());
    }
    return get_term($new['term_id'], $tax);
}

function lww_find_wc_product_by_catalog_id($catalog_id) {
    $wc_id = get_post_meta($catalog_id, '_lww_wc_product_id', true);
    if ($wc_id && get_post_type($wc_id) === 'product') return (int)$wc_id;
    $q = new WP_Query(['post_type' => 'product', 'meta_key' => '_lww_catalog_id', 'meta_value' => $catalog_id, 'fields' => 'ids', 'posts_per_page' => 1]);
    return $q->have_posts() ? $q->posts[0] : 0;
}

function lww_find_wc_variation($pid, $attrs) {
    $ds = WC_Data_Store::load('product');
    return $ds->find_matching_product_variation(new WC_Product($pid), $attrs);
}
?>