<?php
/**
 * Modul: Bilder-Fetcher (v20.3-SIDELOAD)
 * 
 * Erlaubt das sofortige Nachladen von Bildern für einzelne Artikel per AJAX.
 * UPDATE: Nutzt jetzt lww_sideload_image_to_media_library für physischen Import.
 */
if (!defined('ABSPATH')) exit;

add_action('wp_ajax_lww_fetch_item_image', 'lww_ajax_fetch_item_image_handler');

function lww_ajax_fetch_item_image_handler() {
    check_ajax_referer('lww_inventory_ajax_nonce', '_ajax_nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Keine Berechtigung.']);

    $item_id = isset($_POST['item_id']) ? absint($_POST['item_id']) : 0;
    if (!$item_id) wp_send_json_error(['message' => 'Ungültige ID.']);

    // Katalog-Item finden
    $catalog_id = get_post_meta($item_id, '_lww_part_id', true) 
               ?: get_post_meta($item_id, '_lww_minifig_id', true) 
               ?: get_post_meta($item_id, '_lww_set_id', true);
    
    if (!$catalog_id) {
        $pt = get_post_type($item_id);
        if (in_array($pt, ['lww_part', 'lww_set', 'lww_minifig'])) {
            $catalog_id = $item_id;
        } else {
            wp_send_json_error(['message' => 'Kein Katalog-Eintrag verknüpft.']);
        }
    }

    $post_type = get_post_type($catalog_id);
    $type_slug = str_replace('lww_', '', $post_type);
    $title = get_the_title($catalog_id);

    // 1. Primäre Nummer suchen
    $item_num = get_post_meta($catalog_id, "_lww_{$type_slug}_num", true);

    // 2. Fallback IDs
    if (empty($item_num)) $item_num = get_post_meta($catalog_id, '_lww_bricklink_id', true);
    if (empty($item_num)) $item_num = get_post_meta($catalog_id, '_lww_brickowl_id', true);
    if (empty($item_num)) {
        if (preg_match('/^(\d+)/', $title, $m)) $item_num = $m[1];
    }

    $has_identifier = !empty($item_num);

    // --- Versuch 1: Rebrickable API ---
    $api_settings = get_option('lww_api_settings');
    $rb_api_key = $api_settings['rebrickable_api_key'] ?? '';
    $img_url = '';

    if ($has_identifier && !empty($rb_api_key)) {
        $rb_type_slug = ($type_slug === 'minifig') ? 'minifigs' : $type_slug . 's';
        $url = "https://rebrickable.com/api/v3/lego/{$rb_type_slug}/{$item_num}/";
        $res = wp_remote_get($url, ['headers' => ['Authorization' => 'key ' . $rb_api_key], 'timeout' => 10]);
        
        if (!is_wp_error($res)) {
            $code = wp_remote_retrieve_response_code($res);
            if ($code === 200) {
                $data = json_decode(wp_remote_retrieve_body($res), true);
                $img_url = $data['part_img_url'] ?? $data['set_img_url'] ?? $data['img_url'] ?? '';
            }
        }
    }

    // --- Versuch 2: KI Generierung (Fallback) ---
    if (empty($img_url)) {
        $openai_key = $api_settings['openai_api_key'] ?? '';
        if (!empty($openai_key) && function_exists('lww_generate_image_with_dalle')) {
            $prompt_desc = $title;
            if ($has_identifier) $prompt_desc .= " (LEGO ID: $item_num)";
            $prompt = "A high-quality, white background product photo of a LEGO {$type_slug}: '{$prompt_desc}'. Photorealistic, isolated on white, studio lighting.";
            
            $dalle_url = lww_generate_image_with_dalle($prompt, $openai_key);
            if (!is_wp_error($dalle_url)) {
                $img_url = $dalle_url;
            }
        }
    }

    if ($img_url) {
        // BEST PRACTICE: Sideload Image sofort
        if (function_exists('lww_sideload_image_to_media_library')) {
            $attach_id = lww_sideload_image_to_media_library($img_url, $title, $catalog_id);
            if (!is_wp_error($attach_id)) {
                set_post_thumbnail($catalog_id, $attach_id);
                wp_send_json_success(['message' => 'Bild importiert & zugewiesen!', 'img_url' => wp_get_attachment_thumb_url($attach_id)]);
            } else {
                wp_send_json_error(['message' => 'Bild gefunden, aber Import fehlgeschlagen: ' . $attach_id->get_error_message()]);
            }
        } else {
            // Fallback (sollte nicht passieren)
            update_post_meta($catalog_id, '_lww_sideload_image_url', esc_url_raw($img_url));
            wp_send_json_success(['message' => 'Bild-URL gespeichert (Hintergrund-Download).', 'img_url' => $img_url]);
        }
    } else {
        $msg = 'Kein Bild gefunden.';
        if (!$has_identifier) $msg .= ' Auch keine gültige Teilenummer gefunden.';
        wp_send_json_error(['message' => $msg]);
    }
}
?>