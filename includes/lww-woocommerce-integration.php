<?php
/**
 * Modul: WooCommerce Integration (v10.0)
 *
 * Stellt AJAX-Handler und Helferfunktionen bereit, um
 * lww_inventory_item Posts in WooCommerce-Produkte (Variationen)
 * umzuwandeln.
 */
if (!defined('ABSPATH')) exit;

/**
 * Registriert den AJAX-Handler für die Erstellung von WC-Produkten.
 */
add_action('wp_ajax_lww_create_wc_product', 'lww_ajax_create_wc_product_handler');

/**
 * Der AJAX-Handler.
 * Nimmt eine lww_inventory_item ID entgegen, findet das lww_part
 * und erstellt/aktualisiert ein variables WC-Produkt mit der
 * entsprechenden Variation.
 */
function lww_ajax_create_wc_product_handler() {
    try {
        // 1. Sicherheit prüfen
        check_ajax_referer('lww_inventory_ajax_nonce', '_ajax_nonce');
        if (!current_user_can('manage_options') || !class_exists('WooCommerce')) {
            wp_send_json_error(['message' => __('Fehlende Berechtigung oder WooCommerce nicht aktiv.', 'lego-wawi')], 403);
        }

        // 2. Daten holen
        $item_id = isset($_POST['item_id']) ? absint($_POST['item_id']) : 0;
        $part_id = isset($_POST['part_id']) ? absint($_POST['part_id']) : 0;
        
        if (empty($item_id) || empty($part_id)) {
            wp_send_json_error(['message' => __('Ungültige Item- oder Part-ID.', 'lego-wawi')], 400);
        }
        
        $item_post = get_post($item_id);
        $part_post = get_post($part_id);

        if (!$item_post || $item_post->post_type !== 'lww_inventory_item' || !$part_post || $part_post->post_type !== 'lww_part') {
             wp_send_json_error(['message' => __('Inventar- oder Katalog-Post nicht gefunden.', 'lego-wawi')], 404);
        }

        // 3. Globale Attribute "Farbe" und "Zustand" sicherstellen
        $attr_color_id = lww_get_or_create_wc_attribute('Farbe');
        $attr_condition_id = lww_get_or_create_wc_attribute('Zustand');
        
        $attributes_for_product = [];

        // 4. Attribut "Farbe" (pa_farbe) vorbereiten
        $attr_color = new WC_Product_Attribute();
        $attr_color->set_id($attr_color_id); // Globale ID
        $attr_color->set_name('pa_farbe'); // Slug
        $attr_color->set_visible(true);
        $attr_color->set_variation(true); // Wichtig: Ist eine Variation
        $attributes_for_product[] = $attr_color;
        
        // 4. Attribut "Zustand" (pa_zustand) vorbereiten
        $attr_condition = new WC_Product_Attribute();
        $attr_condition->set_id($attr_condition_id);
        $attr_condition->set_name('pa_zustand');
        $attr_condition->set_visible(true);
        $attr_condition->set_variation(true);
        $attributes_for_product[] = $attr_condition;

        // 5. Variables WooCommerce-Produkt finden oder erstellen
        $product_id = lww_find_wc_product_by_part_id($part_id);
        $product = null;

        if ($product_id) {
            $product = wc_get_product($product_id);
            if (!$product || !$product->is_type('variable')) {
                 // Sollte nicht passieren, aber sicher ist sicher
                 wp_send_json_error(['message' => sprintf('Produkt (ID %d) existiert, ist aber kein variables Produkt.', $product_id)], 400);
            }
             // Stelle sicher, dass die Attribute gesetzt sind
            $product->set_attributes($attributes_for_product);
            $product->save();
        } else {
            // Produkt neu erstellen
            $product = new WC_Product_Variable();
            $product->set_name(get_the_title($part_id));
            $product->set_sku(get_post_meta($part_id, 'lww_part_num', true));
            $product->set_status('publish'); // Als Entwurf: 'draft'
            $product->set_catalog_visibility('visible');
            $product->set_attributes($attributes_for_product);
            
            // Thumbnail vom lww_part setzen
            $thumbnail_id = get_post_thumbnail_id($part_id);
            if ($thumbnail_id) {
                $product->set_image_id($thumbnail_id);
            }

            $product_id = $product->save();
            update_post_meta($product_id, '_lww_part_id', $part_id); // Verknüpfung
        }

        // 6. Term (Attribut-Wert) finden oder erstellen
        $color_name = get_post_meta($item_id, '_color_name', true);
        $condition_raw = get_post_meta($item_id, '_condition', true);
        $condition_label = ($condition_raw === 'new') ? 'Neu' : 'Gebraucht';

        $term_color = lww_get_or_create_wc_attribute_term($color_name, 'pa_farbe');
        $term_condition = lww_get_or_create_wc_attribute_term($condition_label, 'pa_zustand');
        
        if (is_wp_error($term_color) || is_wp_error($term_condition)) {
             wp_send_json_error(['message' => 'Fehler beim Erstellen der Attribut-Begriffe (Terms).'], 500);
        }

        // 7. Begriffe dem Hauptprodukt zuweisen (WICHTIG!)
        // 'true' = anhängen
        wp_set_object_terms($product_id, $term_color->slug, 'pa_farbe', true);
        wp_set_object_terms($product_id, $term_condition->slug, 'pa_zustand', true);

        // 8. Variation finden oder erstellen
        $variation_id = lww_find_wc_variation($product_id, [
            'attribute_pa_farbe' => $term_color->slug,
            'attribute_pa_zustand' => $term_condition->slug
        ]);

        $variation = null;
        if ($variation_id) {
            $variation = new WC_Product_Variation($variation_id);
        } else {
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($product_id);
            $variation->set_attributes([
                'pa_farbe'   => $term_color->slug,
                'pa_zustand' => $term_condition->slug,
            ]);
        }

        // 9. Variation aktualisieren (Preis, Menge)
        $quantity = (int) get_post_meta($item_id, '_quantity', true);
        $price = (float) get_post_meta($item_id, '_price', true);

        $variation->set_manage_stock(true);
        $variation->set_stock_quantity($quantity);
        $variation->set_stock_status($quantity > 0 ? 'instock' : 'outofstock');
        $variation->set_regular_price($price);
        $variation->set_status('publish'); // Wichtig, damit sie sichtbar ist

        $new_variation_id = $variation->save();
        
        // 10. Verknüpfung zurück zum lww_inventory_item speichern
        update_post_meta($item_id, '_lww_wc_variation_id', $new_variation_id);
        update_post_meta($item_id, '_lww_wc_product_id', $product_id);

        // 11. Erfolgsantwort mit neuem Status-HTML senden
        $wc_link = get_edit_post_link($new_variation_id);
        $status_html = sprintf(
            '<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span> <a href="%s" target="_blank">%s (%d)</a>',
            esc_url($wc_link),
            __('Synchronisiert', 'lego-wawi'),
            $new_variation_id
        );

        wp_send_json_success([
            'message'     => sprintf('Variation erstellt/aktualisiert (ID: %d)', $new_variation_id),
            'status_html' => $status_html
        ]);

    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()], 500);
    }
    wp_die(); // sollte nie erreicht werden
}


/**
 * Helfer: Findet oder erstellt ein globales WC-Attribut.
 * @param string $name Name des Attributs (z.B. "Farbe")
 * @return int ID des Attributs
 */
function lww_get_or_create_wc_attribute($name) {
    $slug = sanitize_title($name);
    $taxonomy_name = 'pa_' . $slug;

    // 1. Versuche, anhand des Slugs (pa_farbe) zu finden
    $attr_id = wc_attribute_taxonomy_id_by_name($taxonomy_name);
    if ($attr_id) {
        return $attr_id;
    }

    // 2. Nicht gefunden, neu erstellen
    $attribute_data = [
        'name'         => $name,
        'slug'         => $slug,
        'type'         => 'select', // 'select' ist Standard für Variationen
        'order_by'     => 'menu_order',
        'has_archives' => false,
    ];

    $new_attr_id = wc_create_attribute($attribute_data);

    if (is_wp_error($new_attr_id)) {
        throw new Exception('Fehler beim Erstellen des Attributs "' . $name . '": ' . $new_attr_id->get_error_message());
    }
    
    // 3. Taxonomie registrieren (wichtig!)
    register_taxonomy(
        'pa_' . $slug,
        apply_filters('woocommerce_taxonomy_objects_pa_' . $slug, ['product']),
        apply_filters('woocommerce_taxonomy_args_pa_' . $slug, [
            'labels'       => ['name' => $name],
            'hierarchical' => false,
            'show_ui'      => false,
            'query_var'    => true,
            'rewrite'      => false,
            'public'       => false,
        ])
    );
    
    // Globale Attribute im Cache löschen, damit WC sie neu lädt
    delete_transient('wc_attribute_taxonomies');

    return $new_attr_id;
}

/**
 * Helfer: Findet oder erstellt einen Attribut-Term (z.B. "Rot").
 * @param string $term_name Name (z.B. "Rot")
 * @param string $taxonomy_slug Slug (z.B. "pa_farbe")
 * @return WP_Term|WP_Error
 */
function lww_get_or_create_wc_attribute_term($term_name, $taxonomy_slug) {
    $term = get_term_by('name', $term_name, $taxonomy_slug);
    
    if ($term) {
        return $term;
    }
    
    // Nicht gefunden, neu erstellen
    $term_result = wp_insert_term($term_name, $taxonomy_slug);
    
    if (is_wp_error($term_result)) {
        // Möglicherweise existiert er unter einem anderen Slug?
        $term = get_term_by('slug', sanitize_title($term_name), $taxonomy_slug);
        if ($term) return $term;
        
        // Immer noch nicht? Dann ist es ein echter Fehler.
        throw new Exception('Fehler beim Erstellen des Terms "' . $term_name . '": ' . $term_result->get_error_message());
    }
    
    return get_term($term_result['term_id'], $taxonomy_slug);
}

/**
 * Helfer: Findet ein WC-Produkt anhand der _lww_part_id Meta.
 * @param int $part_id
 * @return int Produkt-ID oder 0
 */
function lww_find_wc_product_by_part_id($part_id) {
    $query = new WP_Query([
        'post_type'      => 'product',
        'post_status'    => 'any',
        'meta_key'       => '_lww_part_id',
        'meta_value'     => $part_id,
        'posts_per_page' => 1,
        'fields'         => 'ids',
    ]);
    
    return $query->have_posts() ? $query->posts[0] : 0;
}

/**
 * Helfer: Findet eine existierende Variation anhand der Attribute.
 * @param int $product_id
 * @param array $attributes ['attribute_pa_farbe' => 'rot', 'attribute_pa_zustand' => 'neu']
 * @return int Variation-ID oder 0
 */
function lww_find_wc_variation($product_id, $attributes = []) {
    global $wpdb;
    
    $query_parts = [];
    foreach ($attributes as $key => $value) {
        $query_parts[] = $wpdb->prepare(
            "( meta.meta_key = %s AND meta.meta_value = %s )",
            $key, $value
        );
    }

    $query = "
        SELECT p.ID FROM {$wpdb->posts} as p
        INNER JOIN {$wpdb->postmeta} as pm ON p.ID = pm.post_id
        WHERE p.post_type = 'product_variation'
        AND p.post_status = 'publish'
        AND p.post_parent = %d
        AND pm.meta_key = '_stock_status' AND pm.meta_value != ''
        GROUP BY p.ID
        HAVING SUM(
    ";
    
    $query .= implode(" OR ", $query_parts);
    $query .= " ) = " . count($attributes); // Muss alle Attribute matchen

    $variation_id = $wpdb->get_var($wpdb->prepare($query, $product_id));

    return $variation_id ? absint($variation_id) : 0;
}
