<?php
/**
 * Import-Handler für BrickLink Inventar (v21.0-FIX)
 * 
 * UPDATE: Korrektur der Preis-Spalten-Erkennung (UNIT_PRICE).
 */
if (!defined('ABSPATH')) exit;

class LWW_Import_Bricklink_Inventory_Handler extends LWW_Import_Handler_Base {

    public function process_row($job_id, $row_data_raw, $header_map) {
        // Für BrickLink API wird row_data_raw als assoziatives Array übergeben
        // Keys sind bereits in der erwarteten Form (ITEMID, COLOR, QTY, UNIT_PRICE etc.)
        $data = [];
        foreach ($row_data_raw as $k => $v) $data[strtoupper($k)] = $v;

        $item_no = $data['ITEMID'] ?? $data['ITEM_NO'] ?? '';

        if (empty($item_no)) {
            lww_log_to_job($job_id, "WARNUNG: Zeile übersprungen - keine Item-Nummer gefunden");
            return;
        }

        lww_log_to_job($job_id, "Verarbeite BrickLink Item: $item_no");

        $catalog_item = self::find_catalog_item_by_boid($item_no);
        if (!$catalog_item) {
             $post_id = self::find_post_by_meta('lww_part', '_lww_part_num', $item_no);
             if ($post_id) $catalog_item = ['id' => $post_id, 'type' => 'lww_part'];
        }
        if (!$catalog_item) {
            lww_log_to_job($job_id, "Smart Match fehlgeschlagen für: $item_no. Erstelle Katalogeintrag.");
            $catalog_item = lww_create_and_enrich_missing_catalog_item($item_no, 'bricklink', $job_id);
        }

        if ($catalog_item) {
            // Stelle sicher, dass der Katalog-Eintrag eine BrickLink-ID hat
            $catalog_post_id = $catalog_item['id'];
            $existing_bl_id = get_post_meta($catalog_post_id, '_lww_bricklink_id', true);
            if (empty($existing_bl_id)) {
                update_post_meta($catalog_post_id, '_lww_bricklink_id', $item_no);
            }
            $condition_val = $data['CONDITION'] ?? $data['NEW_OR_USED'] ?? 'U';
            $condition_key = ($condition_val === 'N') ? 'new' : 'used';
            $color_id = intval($data['COLOR'] ?? $data['COLOR_ID'] ?? 0);
            
            $unique_meta_key = '_lww_inventory_uid';
            $unique_meta_value = $item_no . '|' . $color_id . '|' . $condition_key;

            $post_id = self::find_post_by_meta('lww_inventory_item', $unique_meta_key, $unique_meta_value);

            $post_data = [
                'post_title'   => sprintf('%s - Color %d (%s)', get_the_title($catalog_item['id']), $color_id, $condition_key),
                'post_status'  => 'publish',
                'post_type'    => 'lww_inventory_item',
            ];
            
            if ($post_id > 0) {
                $post_data['ID'] = $post_id;
                wp_update_post($post_data);
            } else {
                $post_id = wp_insert_post($post_data, true);
                if (is_wp_error($post_id)) return;
            }
            
            $remarks = sanitize_textarea_field($data['REMARKS'] ?? $data['COMMENTS'] ?? '');
            
            // PREIS FIX: BrickLink XML nutzt oft UNIT_PRICE
            $price_raw = $data['UNIT_PRICE'] ?? $data['PRICE'] ?? '0.0';
            $price = floatval(str_replace(',', '.', $price_raw));

            $qty = intval($data['QTY'] ?? $data['MINQTY'] ?? 0);

            update_post_meta($post_id, $unique_meta_key, $unique_meta_value);
            update_post_meta($post_id, '_lww_bricklink_item_no', $item_no);
            update_post_meta($post_id, '_quantity', $qty);
            update_post_meta($post_id, '_price', $price);
            update_post_meta($post_id, '_condition', $condition_key);
            update_post_meta($post_id, '_remarks', $remarks);
            update_post_meta($post_id, '_lww_lot_id', sanitize_text_field($data['LOTID'] ?? $data['INVENTORY_ID'] ?? ''));

            // Vollständige BrickLink-Rohdaten (aus API-Sync) speichern, falls vorhanden
            if (!empty($data['BL_RAW']) && is_array($data['BL_RAW'])) {
                update_post_meta($post_id, '_lww_bricklink_raw', $data['BL_RAW']);
            }
            
            $cat_type = $catalog_item['type'];
            update_post_meta($post_id, '_lww_catalog_post_type', $cat_type);
            
            delete_post_meta($post_id, '_lww_part_id');
            delete_post_meta($post_id, '_lww_set_id');
            delete_post_meta($post_id, '_lww_minifig_id');
            
            if ($cat_type === 'lww_part') {
                update_post_meta($post_id, '_lww_part_id', $catalog_item['id']);
                update_post_meta($post_id, '_lww_bricklink_color_id', $color_id);
            } elseif ($cat_type === 'lww_set') {
                update_post_meta($post_id, '_lww_set_id', $catalog_item['id']);
            } elseif ($cat_type === 'lww_minifig') {
                update_post_meta($post_id, '_lww_minifig_id', $catalog_item['id']);
            }

            if (isset($data['COST']) || isset($data['UNIT_COST'])) {
                $cost = floatval(str_replace(',', '.', $data['COST'] ?? $data['UNIT_COST']));
                update_post_meta($post_id, '_lww_my_cost', $cost);
            }

            if (function_exists('lww_update_locations_from_string') && !empty($remarks)) {
                lww_update_locations_from_string($post_id, $remarks);
            }
        } else {
            lww_log_to_job($job_id, "FEHLER: Item $item_no konnte nicht verarbeitet werden - Katalogeintrag fehlt");
        }

        lww_log_to_job($job_id, "BrickLink Item $item_no erfolgreich verarbeitet");
    }
}
