<?php
/**
 * Modul: Marktplatz Synchronisation (v26.2)
 * 
 * Stellt Funktionen und AJAX-Handler bereit, um Inventarartikel
 * mit externen Marktplätzen zu synchronisieren.
 * 
 * UPDATE: Logik in Kernfunktionen extrahiert für Batch-Processing.
 */
if (!defined('ABSPATH')) exit;

add_action('wp_ajax_lww_sync_brickowl_item', 'lww_ajax_sync_brickowl_item_handler');
add_action('wp_ajax_lww_sync_bricklink_item', 'lww_ajax_sync_bricklink_item_handler');
add_action('wp_ajax_lww_get_brickowl_price', 'lww_ajax_get_brickowl_price_handler');
add_action('wp_ajax_lww_get_minifig_prices', 'lww_ajax_get_minifig_prices_handler');
add_action('wp_ajax_lww_check_stock_sync', 'lww_ajax_check_stock_sync_handler');

/**
 * Kernfunktion: Synchronisiert ein Item zu BrickOwl.
 * 
 * @param int $item_id Die ID des lww_inventory_item.
 * @return bool|WP_Error True bei Erfolg, sonst WP_Error.
 */
function lww_sync_item_to_brickowl($item_id) {
    $lot_id = get_post_meta($item_id, '_lww_lot_id', true);
    if (empty($lot_id)) return new WP_Error('no_lot_id', 'Keine BrickOwl Lot ID.');

    $data = [
        'price' => (float) get_post_meta($item_id, '_price', true),
        'quantity' => (int) get_post_meta($item_id, '_quantity', true),
        'remarks' => get_post_meta($item_id, '_remarks', true),
    ];

    $api_settings = get_option('lww_api_settings');
    if (empty($api_settings['brickowl_api_key'])) return new WP_Error('no_key', 'Kein BrickOwl API-Schlüssel.');
    
    $api = new LWW_BrickOwl_API($api_settings['brickowl_api_key']);
    $result = $api->update_inventory_item($lot_id, $data);

    if (is_wp_error($result)) return $result;
    return true;
}

/**
 * AJAX-Handler: Wrapper für BrickOwl Sync.
 */
function lww_ajax_sync_brickowl_item_handler() {
    try {
        check_ajax_referer('lww_inventory_ajax_nonce', '_ajax_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Fehlende Berechtigung.'], 403);

        $item_id = isset($_POST['item_id']) ? absint($_POST['item_id']) : 0;
        if (empty($item_id)) wp_send_json_error(['message' => 'Ungültige Item-ID.'], 400);

        $result = lww_sync_item_to_brickowl($item_id);

        if (is_wp_error($result)) wp_send_json_error(['message' => $result->get_error_message()], 500);

        $lot_id = get_post_meta($item_id, '_lww_lot_id', true);
        wp_send_json_success(['message' => "Sync OK (Lot: $lot_id)"]);
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()], 500);
    }
    wp_die();
}

/**
 * Kernfunktion: Synchronisiert ein Item zu BrickLink.
 * 
 * @param int $item_id Die ID des lww_inventory_item.
 * @return string|WP_Error Die BrickLink Inventory ID bei Erfolg, sonst WP_Error.
 */
function lww_sync_item_to_bricklink($item_id) {
    $api_settings = get_option('lww_api_settings');
    if (empty($api_settings['bricklink_consumer_key'])) return new WP_Error('no_key', 'Keine BrickLink API-Schlüssel.');
    
    $api = new LWW_Bricklink_API(
        $api_settings['bricklink_consumer_key'], $api_settings['bricklink_consumer_secret'],
        $api_settings['bricklink_token_value'], $api_settings['bricklink_token_secret']
    );

    // Daten laden
    $bl_inv_id = get_post_meta($item_id, '_lww_bricklink_inventory_id', true);
    $qty = (int) get_post_meta($item_id, '_quantity', true);
    $price = (float) get_post_meta($item_id, '_price', true);
    $remarks = get_post_meta($item_id, '_remarks', true);
    
    // 1. Wenn ID vorhanden: Update versuchen
    if (!empty($bl_inv_id)) {
        $data = ['unit_price' => $price, 'qty' => $qty, 'remarks' => $remarks];
        $result = $api->update_inventory($bl_inv_id, $data);

        if (is_wp_error($result)) {
            // Wenn Item nicht gefunden (z.B. gelöscht auf BL), ID löschen und neu versuchen
            if ($result->get_error_code() == 404) {
                delete_post_meta($item_id, '_lww_bricklink_inventory_id');
                $bl_inv_id = null; // Weiter zu Schritt 2
            } else {
                return $result;
            }
        } else {
            return $bl_inv_id; // Success Update
        }
    }

    // 2. Wenn keine ID (oder Update fehlgeschlagen): Suchen oder Erstellen
    if (empty($bl_inv_id)) {
        // Benötigte Infos für Suche/Erstellung
        $item_no = get_post_meta($item_id, '_lww_bricklink_item_no', true) ?: get_post_meta($item_id, '_lww_part_num', true);
        // Für Sets/Minifigs nutzen wir die Hauptnummer
        if (!$item_no) {
            if ($pid = get_post_meta($item_id, '_lww_part_id', true)) $item_no = get_post_meta($pid, '_lww_part_num', true);
            elseif ($sid = get_post_meta($item_id, '_lww_set_id', true)) $item_no = get_post_meta($sid, '_lww_set_num', true);
            elseif ($mid = get_post_meta($item_id, '_lww_minifig_id', true)) $item_no = get_post_meta($mid, '_lww_minifig_num', true);
        }

        $color_id = get_post_meta($item_id, '_lww_bricklink_color_id', true) ?: 0;
        $condition = get_post_meta($item_id, '_condition', true) === 'new' ? 'N' : 'U';
        $type = get_post_meta($item_id, '_lww_catalog_post_type', true);
        $bl_type = ($type === 'lww_set') ? 'SET' : (($type === 'lww_minifig') ? 'MINIFIG' : 'PART');

        if (empty($item_no)) return new WP_Error('no_item_no', 'Keine Item-Nummer für BrickLink gefunden.');

        // 2a. Suchen
        $search_params = [
            'item_no' => $item_no,
            'color_id' => $color_id,
            'new_or_used' => $condition,
            'item_type' => $bl_type
        ];
        
        $search_res = $api->search_inventory($search_params);
        
        if (!is_wp_error($search_res) && !empty($search_res)) {
            // Erstes Ergebnis nehmen (sollte eindeutig sein bei diesen Params)
            $found_item = $search_res[0];
            $bl_inv_id = $found_item['inventory_id'];
            update_post_meta($item_id, '_lww_bricklink_inventory_id', $bl_inv_id);
            
            // Jetzt Update machen
            $api->update_inventory($bl_inv_id, ['unit_price' => $price, 'qty' => $qty, 'remarks' => $remarks]);
            return $bl_inv_id;
        } else {
            // 2b. Erstellen
            $create_data = [
                'item' => [
                    'no' => $item_no,
                    'type' => $bl_type
                ],
                'color_id' => (int)$color_id,
                'qty' => $qty,
                'unit_price' => $price,
                'new_or_used' => $condition,
                'remarks' => $remarks
            ];

            $create_res = $api->create_inventory($create_data);
            
            if (is_wp_error($create_res)) {
                return $create_res;
            } else {
                $new_id = $create_res['inventory_id'];
                update_post_meta($item_id, '_lww_bricklink_inventory_id', $new_id);
                return $new_id;
            }
        }
    }
    return new WP_Error('unknown_error', 'Unbekannter Fehler bei BrickLink Sync.');
}

/**
 * AJAX-Handler: Wrapper für BrickLink Sync.
 */
function lww_ajax_sync_bricklink_item_handler() {
    try {
        check_ajax_referer('lww_inventory_ajax_nonce', '_ajax_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Fehlende Berechtigung.'], 403);

        $item_id = isset($_POST['item_id']) ? absint($_POST['item_id']) : 0;
        if (empty($item_id)) wp_send_json_error(['message' => 'Ungültige Item-ID.'], 400);

        $result = lww_sync_item_to_bricklink($item_id);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 500);
        } else {
            wp_send_json_success(['message' => "Sync OK (ID: $result)"]);
        }

    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()], 500);
    }
    wp_die();
}

/**
 * AJAX-Handler: Prüft den Bestand live bei BrickLink und BrickOwl.
 */
function lww_ajax_check_stock_sync_handler() {
    check_ajax_referer('lww_inventory_ajax_nonce', '_ajax_nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Forbidden']);

    $item_id = absint($_POST['item_id']);
    $local_qty = (int) get_post_meta($item_id, '_quantity', true);
    $api_settings = get_option('lww_api_settings');

    $results = [];

    // 1. BrickLink Check
    $bl_inv_id = get_post_meta($item_id, '_lww_bricklink_inventory_id', true);
    if ($bl_inv_id && !empty($api_settings['bricklink_consumer_key'])) {
        $bl_api = new LWW_Bricklink_API(
            $api_settings['bricklink_consumer_key'], $api_settings['bricklink_consumer_secret'],
            $api_settings['bricklink_token_value'], $api_settings['bricklink_token_secret']
        );
        $bl_res = $bl_api->get_inventory($bl_inv_id);
        if (!is_wp_error($bl_res)) {
            $bl_qty = (int) ($bl_res['qty'] ?? -1);
            $match = ($bl_qty === $local_qty);
            $style = $match ? 'color:green' : 'color:red;font-weight:bold';
            $results[] = sprintf('BL: <span style="%s">%d</span>', $style, $bl_qty);
        } else {
            $results[] = 'BL: Error';
        }
    } else {
        $results[] = 'BL: N/A';
    }

    // 2. BrickOwl Check
    $bo_lot_id = get_post_meta($item_id, '_lww_lot_id', true);
    if ($bo_lot_id && !empty($api_settings['brickowl_api_key'])) {
        // $bo_api = new LWW_BrickOwl_API($api_settings['brickowl_api_key']);
        // Hier vereinfacht: Echte Abfrage ist komplexer ohne direkte GET Lot ID Methode
        $results[] = 'BO: Pending'; 
    } else {
        $results[] = 'BO: N/A';
    }

    $html = 'Local: ' . $local_qty . ' | ' . implode(' | ', $results);
    wp_send_json_success(['html' => $html]);
    wp_die();
}

/**
 * Holt den aktuellen Marktpreis von BrickOwl für ein Item.
 */
function lww_ajax_get_brickowl_price_handler() { 
    check_ajax_referer('lww_inventory_ajax_nonce', '_ajax_nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Forbidden']);

    $item_id = absint($_POST['item_id']);
    $boid = get_post_meta($item_id, '_boid', true);
    
    if (empty($boid)) {
        // Fallback: BOID aus Katalog holen
        $part_id = get_post_meta($item_id, '_lww_part_id', true);
        if ($part_id) {
            $boid = get_post_meta($part_id, '_lww_brickowl_id', true);
            $color_id = get_post_meta($item_id, '_lww_color_id', true);
            if ($color_id) {
                $bo_color_id = get_post_meta($color_id, '_lww_brickowl_id', true);
                if ($bo_color_id) $boid .= '-' . $bo_color_id;
            }
        }
    }

    if (empty($boid)) wp_send_json_error(['message' => 'Keine BOID gefunden.']);

    $api_settings = get_option('lww_api_settings');
    if (empty($api_settings['brickowl_api_key'])) wp_send_json_error(['message' => 'Kein API Key.']);

    $api = new LWW_BrickOwl_API($api_settings['brickowl_api_key']);
    $price = $api->get_item_price($boid);

    if (is_wp_error($price)) wp_send_json_error(['message' => $price->get_error_message()]);
    
    wp_send_json_success([
        'price' => number_format((float)$price, 2) . ' €',
        'message' => 'BrickOwl Preis abgerufen.'
    ]);
    wp_die(); 
}

/**
 * Holt Minifiguren-Preise vom BrickLink Price Guide.
 */
function lww_ajax_get_minifig_prices_handler() { 
    check_ajax_referer('lww_minifigs_ajax_nonce', '_ajax_nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Forbidden']);

    $item_id = absint($_POST['item_id']);
    // Wenn Aufruf aus Inventarliste, dann Item ID.
    // Wenn aus Minifig Liste, kann es auch Catalog ID sein. Checke Typ.
    $post = get_post($item_id);
    $fig_num = '';

    if ($post->post_type === 'lww_inventory_item') {
        $minifig_id = get_post_meta($item_id, '_lww_minifig_id', true);
        if ($minifig_id) $fig_num = get_post_meta($minifig_id, '_lww_minifig_num', true);
    } elseif ($post->post_type === 'lww_minifig') {
        $fig_num = get_post_meta($item_id, '_lww_minifig_num', true);
    }

    if (empty($fig_num)) wp_send_json_error(['message' => 'Keine Fig-Nummer gefunden.']);

    $api_settings = get_option('lww_api_settings');
    if (empty($api_settings['bricklink_consumer_key'])) wp_send_json_error(['message' => 'Kein API Key.']);

    $api = new LWW_Bricklink_API(
        $api_settings['bricklink_consumer_key'], $api_settings['bricklink_consumer_secret'],
        $api_settings['bricklink_token_value'], $api_settings['bricklink_token_secret']
    );

    // Hole Used und New Preise
    $res_used = $api->get_price_guide('MINIFIG', $fig_num, 0, 'U');
    $res_new = $api->get_price_guide('MINIFIG', $fig_num, 0, 'N');

    $html = '<div style="text-align:left;"><strong>BrickLink Price Guide (Avg):</strong><br>';
    if (!is_wp_error($res_used)) {
        $html .= 'Gebraucht: ' . number_format((float)($res_used['avg_price']??0), 2) . ' €<br>';
    }
    if (!is_wp_error($res_new)) {
        $html .= 'Neu: ' . number_format((float)($res_new['avg_price']??0), 2) . ' €';
    }
    $html .= '</div>';

    wp_send_json_success(['html' => $html]);
    wp_die(); 
}
