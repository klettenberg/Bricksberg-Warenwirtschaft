<?php
/**
 * Modul: WooCommerce Integration (v13.0)
 *
 * Stellt AJAX-Handler und Helferfunktionen bereit, um
 * lww_inventory_item Posts in WooCommerce-Produkte (Variationen)
 * umzuwandeln und Preise abzurufen.
 */
if (!defined('ABSPATH')) exit;

/**
 * Registriert die AJAX-Handler.
 */
add_action('wp_ajax_lww_create_wc_product', 'lww_ajax_create_wc_product_handler');
add_action('wp_ajax_lww_get_brickowl_price', 'lww_ajax_get_brickowl_price_handler');

/**
 * AJAX-Handler: Erstellt/aktualisiert ein variables WC-Produkt.
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

        // Hole generierte Beschreibungen vom lww_part Post
        $short_description = get_post_meta($part_id, '_lww_short_description', true);
        $long_description_wc = get_post_meta($part_id, '_lww_seo_description_wc', true);

        if ($product_id) {
            $product = wc_get_product($product_id);
            if (!$product || !$product->is_type('variable')) {
                 // Sollte nicht passieren, aber sicher ist sicher
                 wp_send_json_error(['message' => sprintf('Produkt (ID %d) existiert, ist aber kein variables Produkt.', $product_id)], 400);
            }
             // Stelle sicher, dass die Attribute gesetzt sind
            $product->set_attributes($attributes_for_product);

            // Beschreibungen aktualisieren
            $product->set_short_description($short_description);
            $product->set_description($long_description_wc);

            $product->save();
        } else {
            // Produkt neu erstellen
            $product = new WC_Product_Variable();
            $product->set_name(get_the_title($part_id));
            $product->set_sku(get_post_meta($part_id, '_lww_part_num', true));
            $product->set_status('publish'); // Als Entwurf: 'draft'
            $product->set_catalog_visibility('visible');
            $product->set_attributes($attributes_for_product);

            // Beschreibungen setzen
            $product->set_short_description($short_description);
            $product->set_description($long_description_wc);
            
            // Thumbnail vom lww_part setzen
            $thumbnail_id = get_post_thumbnail_id($part_id);
            if ($thumbnail_id) {
                $product->set_image_id($thumbnail_id);
            }

            $product_id = $product->save();
            update_post_meta($product_id, '_lww_part_id', $part_id); // Verknüpfung

            // Kategorie "Ersatzteile" zuweisen
            $category_name = __('Ersatzteile', 'lego-wawi');
            $term = get_term_by('name', $category_name, 'product_cat');
            if (!$term) {
                $term_result = wp_insert_term($category_name, 'product_cat');
                if (!is_wp_error($term_result)) {
                    $term_id = $term_result['term_id'];
                    wp_set_object_terms($product_id, $term_id, 'product_cat');
                }
            } else {
                wp_set_object_terms($product_id, $term->term_id, 'product_cat');
            }
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
        $wc_link = get_edit_post_link($product_id); // Link zum Hauptprodukt
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
 * AJAX-Handler: Ruft den aktuellen Preis von BrickOwl ab.
 */
function lww_ajax_get_brickowl_price_handler() {
    try {
        check_ajax_referer('lww_inventory_ajax_nonce', '_ajax_nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Fehlende Berechtigung.', 'lego-wawi')], 403);
        }

        $item_id = isset($_POST['item_id']) ? absint($_POST['item_id']) : 0;
        $boid = isset($_POST['boid']) ? sanitize_text_field($_POST['boid']) : '';

        if (empty($item_id) || empty($boid)) {
            wp_send_json_error(['message' => __('Ungültige Item-ID oder BOID.', 'lego-wawi')], 400);
        }

        // API-Schlüssel holen
        $api_settings = get_option('lww_api_settings');
        $api_key = $api_settings['brickowl_api_key'] ?? '';

        if (empty($api_key)) {
            wp_send_json_error(['message' => __('Kein BrickOwl API-Schlüssel in den Einstellungen konfiguriert.', 'lego-wawi')], 401);
        }

        // API-Klasse instanziieren und Preis abrufen
        $brickowl_api = new LWW_BrickOwl_API($api_key);
        $new_price = $brickowl_api->get_item_price($boid);

        if (is_wp_error($new_price)) {
            wp_send_json_error(['message' => $new_price->get_error_message()], 500);
        }

        $old_price = (float) get_post_meta($item_id, '_price', true);

        // Preis und Historie nur bei Änderung aktualisieren
        if (abs($new_price - $old_price) > 0.0001) { // Vergleich mit Toleranz für Fließkommazahlen
            update_post_meta($item_id, '_price', $new_price);

            // Preis-Historie aktualisieren
            $history = get_post_meta($item_id, '_lww_price_history', true);
            if (!is_array($history)) {
                $history = [];
            }
            $history[] = [
                'timestamp' => time(),
                'price'     => $new_price,
                'source'    => 'brickowl_api'
            ];
            // Nur die letzten 20 Einträge behalten, um die DB nicht aufzublähen
            if (count($history) > 20) {
                $history = array_slice($history, -20);
            }
            update_post_meta($item_id, '_lww_price_history', $history);
        }

        wp_send_json_success([
            'message' => 'Preis erfolgreich aktualisiert.',
            'new_price_html' => number_format($new_price, 3, ',', '.') . ' €'
        ]);

    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()], 500);
    }
    wp_die();
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
    
    // Globale Attribute im Cache löschen, damit WC sie neu lädt. 
    // OPTIMIERUNG: Nur ausführen, wenn wirklich ein neues Attribut erstellt wurde.
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
    $data_store = WC_Data_Store::load('product');
    $variation_id = $data_store->find_matching_product_variation(
        new WC_Product($product_id),
        $attributes
    );
    return $variation_id;
}
