<?php
/**
 * Modul: eBay Integration (v15.0)
 *
 * Stellt AJAX-Handler bereit, um lww_inventory_item Posts
 * mit eBay-Angeboten zu synchronisieren.
 */
if (!defined('ABSPATH')) exit;

add_action('wp_ajax_lww_create_ebay_listing', 'lww_ajax_create_ebay_listing_handler');

/**
 * AJAX-Handler: Erstellt/aktualisiert ein eBay-Angebot.
 */
function lww_ajax_create_ebay_listing_handler() {
    try {
        // 1. Sicherheit prüfen
        // KORREKTUR: Nonce aus einer konsistenten Quelle verwenden
        $nonce = $_POST['_ajax_nonce'] ?? $_POST['_wpnonce'] ?? '';
        if (!wp_verify_nonce($nonce, 'lww_inventory_ajax_nonce') && !wp_verify_nonce($nonce, 'lww_minifigs_ajax_nonce')) {
            wp_send_json_error(['message' => __('Sicherheitsüberprüfung fehlgeschlagen.', 'lego-wawi')], 403);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Fehlende Berechtigung.', 'lego-wawi')], 403);
        }

        // 2. Daten aus dem Request holen
        $item_id = isset($_POST['item_id']) ? absint($_POST['item_id']) : 0;
        if (empty($item_id)) {
            wp_send_json_error(['message' => __('Ungültige Item-ID.', 'lego-wawi')], 400);
        }

        // 3. Notwendige Daten für die eBay-Anzeige sammeln
        $item_post = get_post($item_id);
        if (!$item_post || $item_post->post_type !== 'lww_inventory_item') {
            wp_send_json_error(['message' => __('Inventar-Eintrag nicht gefunden.', 'lego-wawi')], 404);
        }

        $minifig_id = get_post_meta($item_id, '_lww_minifig_id', true);
        $set_id = get_post_meta($item_id, '_lww_set_id', true);
        $catalog_post = null;
        $catalog_type = '';

        if ($minifig_id) {
            $catalog_post = get_post($minifig_id);
            $catalog_type = 'minifig';
        } elseif ($set_id) {
            $catalog_post = get_post($set_id);
            $catalog_type = 'set';
        }

        if (!$catalog_post) {
            wp_send_json_error(['message' => __('Dieser Inventar-Eintrag ist weder mit einer Minifigur noch mit einem Set verknüpft.', 'lego-wawi')], 400);
        }

        // --- NEU: Lese benutzerdefinierte eBay-Metadaten ---
        $ebay_title = get_post_meta($item_id, '_lww_ebay_title', true);
        $ebay_condition_id = get_post_meta($item_id, '_lww_ebay_condition_id', true);
        $ebay_condition_desc = get_post_meta($item_id, '_lww_ebay_condition_description', true);
        $ebay_category_id = get_post_meta($item_id, '_lww_ebay_category_id', true);
        $ebay_gallery_ids = get_post_meta($item_id, '_lww_ebay_gallery_images', true);

        // Daten aufbereiten (Fallback auf Standardwerte, falls keine benutzerdefinierten Daten vorhanden sind)
        $condition = get_post_meta($item_id, '_condition', true);
        $condition_label = ($condition === 'new') ? 'Neu' : 'Gebraucht';
        $data_for_api = [];

        if ($catalog_type === 'minifig') {
            $data_for_api = [
                'title' => !empty($ebay_title) ? $ebay_title : sprintf('LEGO® Minifigur %s (%s) - %s', get_the_title($catalog_post->ID), get_post_meta($catalog_post->ID, '_lww_minifig_num', true), $condition_label),
                'sku' => get_post_meta($catalog_post->ID, '_lww_minifig_num', true),
                'description' => get_post_meta($catalog_post->ID, '_lww_seo_description_wc', true),
                'category_id' => !empty($ebay_category_id) ? $ebay_category_id : '19006', // Fallback auf LEGO Baukästen
                'item_specifics' => [
                    ['name' => 'Marke', 'value' => 'LEGO'],
                    ['name' => 'Produktart', 'value' => 'Minifigur'],
                    ['name' => 'LEGO Charakter', 'value' => get_the_title($catalog_post->ID)],
                ],
            ];
        } elseif ($catalog_type === 'set') {
            $data_for_api = [
                'title' => !empty($ebay_title) ? $ebay_title : sprintf('LEGO® Set %s (%s) - %s', get_the_title($catalog_post->ID), get_post_meta($catalog_post->ID, '_lww_set_num', true), $condition_label),
                'sku' => get_post_meta($catalog_post->ID, '_lww_set_num', true),
                'description' => get_post_meta($catalog_post->ID, '_lww_seo_description_wc', true),
                'category_id' => !empty($ebay_category_id) ? $ebay_category_id : '19006',
                'item_specifics' => [
                    ['name' => 'Marke', 'value' => 'LEGO'],
                    ['name' => 'Produktart', 'value' => 'Bausatz/Set'],
                    ['name' => 'LEGO Setnummer', 'value' => get_post_meta($catalog_post->ID, '_lww_set_num', true)],
                    ['name' => 'LEGO Setname', 'value' => get_the_title($catalog_post->ID)],
                ],
            ];
        }

        $image_urls = [];
        if (!empty($ebay_gallery_ids) && is_array($ebay_gallery_ids)) {
            foreach ($ebay_gallery_ids as $attachment_id) {
                $url = wp_get_attachment_url($attachment_id);
                if ($url) $image_urls[] = $url;
            }
        }
        if (empty($image_urls)) {
            $catalog_image_url = get_the_post_thumbnail_url($catalog_post->ID, 'full');
            if ($catalog_image_url) $image_urls[] = $catalog_image_url;
        }
        
        $data_for_api = array_merge($data_for_api, [
            'description' => !empty($data_for_api['description']) ? $data_for_api['description'] : $data_for_api['title'], // Fallback
            'image_urls' => $image_urls,
            'price' => (float) get_post_meta($item_id, '_price', true),
            'quantity' => (int) get_post_meta($item_id, '_quantity', true),
            'condition_id' => !empty($ebay_condition_id) ? $ebay_condition_id : (($condition === 'new') ? 1000 : 3000), // eBay API condition IDs
            'condition_description' => $ebay_condition_desc ?? '',
        ]);
        
        // 4. eBay API-Klasse instanziieren und Angebot erstellen/aktualisieren
        $api_settings = get_option('lww_api_settings');
        if (empty($api_settings['ebay_auth_token'])) {
            wp_send_json_error(['message' => __('Kein eBay Authentifizierungs-Token in den Einstellungen konfiguriert.', 'lego-wawi')], 401);
        }
        $ebay_api = new LWW_eBay_API($api_settings);

        $existing_listing_id = get_post_meta($item_id, '_lww_ebay_listing_id', true);

        $new_listing_id = $ebay_api->create_or_update_listing($data_for_api, $existing_listing_id);

        if (is_wp_error($new_listing_id)) {
            wp_send_json_error(['message' => $new_listing_id->get_error_message()], 500);
        }

        // 5. Verknüpfung im Inventar-Eintrag speichern
        update_post_meta($item_id, '_lww_ebay_listing_id', $new_listing_id);

        // 6. Erfolgsantwort mit neuem Status-HTML senden
        $ebay_link = 'https://www.ebay.de/itm/' . $new_listing_id;
        $status_html = sprintf(
            '<a href="%s" target="_blank" class="lww-marketplace-badge lww-marketplace-ebay" title="%s">eBay</a>',
            esc_url($ebay_link),
            sprintf(__('Auf eBay gelistet (Listing ID: %s)', 'lego-wawi'), esc_attr($new_listing_id))
        );

        wp_send_json_success([
            'message'     => sprintf('eBay-Angebot erstellt/aktualisiert (ID: %s)', $new_listing_id),
            'status_html' => $status_html,
        ]);

    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()], 500);
    }
    wp_die();
}

?>
