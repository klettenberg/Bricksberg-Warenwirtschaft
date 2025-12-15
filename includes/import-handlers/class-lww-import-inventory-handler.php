<?php
/**
 * Import-Handler für BrickOwl/CSV Inventar (v27.2-LOT-ID)
 * 
 * UPDATE: Smart-Match via Titel-Parsing verbessert. ID-Erkennung robuster.
 * UPDATE: Mandanten-Support.
 * UPDATE: Verbessertes Logging für Fehleranalyse.
 * UPDATE: Speicherung von Lot IDs (wichtig für Sync).
 */
if (!defined('ABSPATH')) exit;

class LWW_Import_Inventory_Handler extends LWW_Import_Handler_Base {

    public function start_job($job_id) {
        self::$post_cache = [];
        // Health check
        $core_health = lww_check_core_data_health();
        if (!$core_health['colors_ok']) {
            lww_log_to_job($job_id, 'WARNUNG: Wenige Farben im System. Import könnte fehlerhaft sein.');
        }
        lww_log_to_job($job_id, 'Starte BrickOwl/CSV Inventar Import...');
    }

    public function process_row($job_id, $row_data_raw, $header_map) {
        $normalized_header_map = [];
        foreach ($header_map as $k => $v) {
            $normalized_header_map[strtolower(trim($k))] = $v;
        }

        $data = self::get_data_from_row($row_data_raw, $normalized_header_map);
        $line_number = self::get_current_line_number($job_id);

        // --- 1. ID-Erkennung (Smart Parse) ---
        $raw_boid = '';
        $possible_id_keys = ['boid', 'bo_id', 'item_id', 'item_no', 'no', 'bl_item_no', 'bricklink_id', 'inventory_id', 'part_number', 'item no'];
        foreach ($possible_id_keys as $key) {
            if (!empty($data[$key])) { $raw_boid = sanitize_text_field($data[$key]); break; }
        }

        $raw_name = sanitize_text_field($data['name'] ?? $data['item_name'] ?? $data['description'] ?? '');

        // Fallback: Smart Parse aus Titel
        if (empty($raw_boid) && !empty($raw_name)) {
            if (preg_match('/^([0-9]+[a-zA-Z]*|[a-zA-Z]+[0-9]+)(-\d+)?/', $raw_name, $m)) {
                $raw_boid = $m[0];
            } 
            elseif (preg_match('/LEGO\s+([0-9]+[a-zA-Z]*)/i', $raw_name, $m)) {
                $raw_boid = $m[1];
            }
        }

        if (empty($raw_boid)) {
            // Fallback auf Spaltenindex 0 wenn keine Header oder Header-Mapping fehlgeschlagen
            if (isset($row_data_raw[0]) && preg_match('/^[a-zA-Z0-9]+(-\d+)?$/', trim($row_data_raw[0]))) {
                $raw_boid = trim($row_data_raw[0]);
            }
        }

        if (empty($raw_boid)) {
            // Nur jeden 100. Fehler loggen um Spam zu vermeiden, oder wenn < 100 Zeilen
            if ($line_number < 100 || $line_number % 100 === 0) {
                lww_log_to_job($job_id, sprintf("SKIP Zeile %d: Keine gültige ID (BOID/ItemNo) gefunden. Rohdaten: %s", $line_number, json_encode(array_slice($row_data_raw, 0, 3))));
            }
            return; 
        }

        // --- 2. Katalog-Match ---
        $catalog_item = self::find_catalog_item_by_boid($raw_boid);
        
        // Wenn nicht gefunden, erstelle Katalogeintrag (Quelle: BrickOwl)
        if (empty($catalog_item)) {
            $catalog_item = lww_create_and_enrich_missing_catalog_item($raw_boid, 'brickowl', $job_id);
        }

        if (empty($catalog_item)) {
            lww_log_unresolved_reference($job_id, 'inventory.csv', 'BOID/Name', $raw_boid, $line_number);
            return;
        }

        $catalog_post_id = $catalog_item['id'];
        $catalog_post_type = $catalog_item['type'];

        // BrickOwl-ID auf Katalog-Eintrag hinterlegen, falls noch nicht vorhanden
        $existing_bo_id = get_post_meta($catalog_post_id, '_lww_brickowl_id', true);
        if (empty($existing_bo_id)) {
            update_post_meta($catalog_post_id, '_lww_brickowl_id', $raw_boid);
        }

        // --- 3. Farbe ---
        $color_name = sanitize_text_field($data['color_name'] ?? $data['color'] ?? '');
        $color_post_id = 0;
        
        if ($catalog_post_type === 'lww_part') {
            if (!empty($color_name)) {
                $color_post_id = self::find_color_by_name($color_name);
            }
            if (!$color_post_id && isset($data['color_id'])) {
                $color_post_id = self::find_color_by_brickowl_id(intval($data['color_id']));
            }
            if (!$color_post_id) $color_post_id = self::find_color_by_rebrickable_id(0);
        }
        
        if ($color_post_id) $color_name = get_the_title($color_post_id);

        // --- 4. Werte ---
        $condition = sanitize_text_field($data['condition'] ?? $data['new_or_used'] ?? 'new');
        $condition = (strtolower(substr($condition, 0, 1)) === 'u') ? 'used' : 'new';
        
        $quantity = intval($data['quantity'] ?? $data['qty'] ?? 0);
        $price = floatval(str_replace(',', '.', $data['price'] ?? $data['unit_price'] ?? '0.0'));

        $post_title = sprintf('%s - %s (%s)', get_the_title($catalog_post_id), $color_name, $condition);

        // --- 5. Speichern ---
        $uid = $raw_boid . '|' . $color_post_id . '|' . $condition;
        if (class_exists('LWW_Multitenancy')) {
            $tid = LWW_Multitenancy::get_current_tenant_id();
            if ($tid > 0) $uid .= '|' . $tid;
        }

        $post_id = self::find_post_by_meta('lww_inventory_item', '_lww_inventory_uid', $uid);

        $post_data = [
            'post_title' => $post_title,
            'post_status' => 'publish',
            'post_type' => 'lww_inventory_item'
        ];
        if ($post_id > 0) {
            $post_data['ID'] = $post_id;
            wp_update_post($post_data);
        } else {
            $post_id = wp_insert_post($post_data, true);
            if (is_wp_error($post_id)) {
                lww_log_to_job($job_id, "Fehler beim Speichern von $post_title: " . $post_id->get_error_message());
                return;
            }
        }

        // Meta Update
        update_post_meta($post_id, '_lww_inventory_uid', $uid);
        update_post_meta($post_id, '_lww_catalog_post_type', $catalog_post_type);
        
        if ($catalog_post_type === 'lww_part') {
            update_post_meta($post_id, '_lww_part_id', $catalog_post_id);
            update_post_meta($post_id, '_lww_color_id', $color_post_id);
        } elseif ($catalog_post_type === 'lww_set') {
            update_post_meta($post_id, '_lww_set_id', $catalog_post_id);
        } elseif ($catalog_post_type === 'lww_minifig') {
            update_post_meta($post_id, '_lww_minifig_id', $catalog_post_id);
        }

        update_post_meta($post_id, '_boid', $raw_boid);
        update_post_meta($post_id, '_color_name', $color_name);
        update_post_meta($post_id, '_condition', $condition);
        update_post_meta($post_id, '_quantity', $quantity);
        update_post_meta($post_id, '_price', $price);

        // Speichere Lot ID (wichtig für Sync zurück zu BO)
        $lot_id = sanitize_text_field($data['lot_id'] ?? $data['lotid'] ?? $data['inventory_id'] ?? '');
        if ($lot_id) update_post_meta($post_id, '_lww_lot_id', $lot_id);
        
        if (class_exists('LWW_Multitenancy')) {
            $tid = LWW_Multitenancy::get_current_tenant_id();
            if ($tid) update_post_meta($post_id, '_lww_tenant_id', $tid);
        }
    }
}
?>