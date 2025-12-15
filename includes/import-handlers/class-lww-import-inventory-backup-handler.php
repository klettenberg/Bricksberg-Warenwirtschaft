<?php
/**
 * Import-Handler für BrickOwl 'inventory_backup.csv' (v16.0)
 * Diese Klasse ist funktional identisch mit dem normalen Inventar-Handler,
 * da eine Backup-Datei eine Obermenge der normalen Inventardatei ist.
 * * REFACTORING: Nutzt die zentrale Methode zur Ermittlung der Zeilennummer.
 */
if (!defined('ABSPATH')) exit;

class LWW_Import_Inventory_Backup_Handler extends LWW_Import_Handler_Base {

    public function start_job($job_id) {
        self::$post_cache = [];
    }

    public function process_row($job_id, $row_data_raw, $header_map) {
        $data = self::get_data_from_row($row_data_raw, $header_map);
        
        // KORREKTUR: Robuste Zeilennummern-Ermittlung über zentrale Methode
        $line_number = self::get_current_line_number($job_id);

        // --- Core data ---
        $raw_boid = sanitize_text_field($data['boid'] ?? '');
        $color_name = sanitize_text_field($data['color_name'] ?? '');
        
        if (empty($raw_boid)) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Inventar): Zeile %d übersprungen. BOID fehlt.', $line_number));
            return;
        }

        // NEU: BOID parsen, um Teil- und Farb-ID zu trennen (z.B. "3001-5")
        $boid_parts = explode('-', $raw_boid);
        $boid = $boid_parts[0]; // Die eigentliche Teil-/Set-/Minifig-Nummer
        $brickowl_color_id = isset($boid_parts[1]) && is_numeric($boid_parts[1]) ? intval($boid_parts[1]) : null;

        // --- Find related catalog items first ---
        $catalog_item = self::find_catalog_item_by_boid($boid);
        if (empty($catalog_item)) {
             if (function_exists('lww_create_and_enrich_missing_catalog_item')) {
                $catalog_item = lww_create_and_enrich_missing_catalog_item($boid, 'brickowl', $job_id);
            }

            if (empty($catalog_item)) {
                lww_log_unresolved_reference($job_id, 'inventory.csv', 'BOID/Nummer', $boid, $line_number);
                return; 
            }
        }

        $catalog_post_id = $catalog_item['id'];
        $catalog_post_type = $catalog_item['type'];

        // BrickOwl-ID auf Katalog-Eintrag hinterlegen, falls noch nicht vorhanden
        $existing_bo_id = get_post_meta($catalog_post_id, '_lww_brickowl_id', true);
        if (empty($existing_bo_id)) {
            update_post_meta($catalog_post_id, '_lww_brickowl_id', $boid);
        }
        $color_post_id = 0;

        // --- Farbe validieren (nur für Teile relevant) ---
        if ($catalog_post_type === 'lww_part') {
            // Priorität 1: Suche nach BrickOwl Color ID
            if ($brickowl_color_id !== null) {
                $color_post_id = self::find_color_by_brickowl_id($brickowl_color_id);
                if (!empty($color_post_id)) {
                    // Wenn die ID gefunden wird, aktualisiere den Farbnamen für Konsistenz
                    $color_name = get_the_title($color_post_id);
                } else {
                    lww_log_unresolved_reference($job_id, 'inventory.csv', 'BrickOwl Color ID', (string)$brickowl_color_id, $line_number);
                    return;
                }
            } 
            // Priorität 2: Fallback auf Farbnamen, wenn keine ID in der BOID war
            else {
                if (empty($color_name)) {
                    lww_log_unresolved_reference($job_id, 'inventory.csv', 'Fehlende Farbe für Teil', $boid, $line_number);
                    return;
                }
                $color_post_id = self::find_color_by_name($color_name);
                if (empty($color_post_id)) {
                    lww_log_unresolved_reference($job_id, 'inventory.csv', 'Color Name', $color_name, $line_number);
                    return;
                }
            }
        }

        // --- Sanitize all fields ---
        $condition = sanitize_text_field($data['condition'] ?? 'new');
        $condition = strtolower($condition);
        if ($condition !== 'new' && $condition !== 'used') {
            $condition = 'new'; // Default to 'new' if invalid
        }
        
        $quantity = intval($data['quantity'] ?? 0);
        // KORREKTUR: Robustes Parsen von deutschen Zahlenformaten
        $price = floatval(str_replace(',', '.', $data['price'] ?? '0.0'));
        $remarks = sanitize_textarea_field($data['remarks'] ?? ''); // Personal note
        $public_notes = sanitize_textarea_field($data['public_notes'] ?? '');
        $external_id = sanitize_text_field($data['external_id'] ?? '');

        // --- New fields ---
        $lot_id = sanitize_text_field($data['lot_id'] ?? '');
        $bo_item_id = sanitize_text_field($data['item_id'] ?? '');
        $my_cost = floatval(str_replace(',', '.', $data['my_cost'] ?? '0.0'));
        $my_weight = floatval(str_replace(',', '.', $data['my_weight'] ?? '0.0'));
        $weight = floatval(str_replace(',', '.', $data['weight'] ?? '0.0'));
        $bulk_qty = intval($data['bulk'] ?? 0);
        $sale_percent = floatval(str_replace(',', '.', $data['sale_price'] ?? '0.0'));
        $for_sale = strtolower($data['for_sale'] ?? '') === 'true';
        $force_quote = strtolower($data['force_quote'] ?? '') === 'true';
        $reserve_uid = sanitize_text_field($data['reserve_uid'] ?? '');

        $tier_prices = [];
        for ($i = 1; $i <= 3; $i++) {
            $qty_key = 'tier_qty_' . $i;
            $price_key = 'tier_price_' . $i;
            if (isset($data[$qty_key]) && $data[$qty_key] !== '' && isset($data[$price_key]) && $data[$price_key] !== '') {
                $tier_prices[] = [
                    'quantity' => intval($data[$qty_key]),
                    'price' => floatval(str_replace(',', '.', $data[$price_key])),
                ];
            }
        }

        // --- Find or create inventory item post ---
        $post_title = sprintf('%s - %s (%s)', get_the_title($catalog_post_id), $color_name, $condition);
        $unique_meta_key = '_lww_inventory_uid';
        // Die UID muss für Sets/Minifigs anders sein, da sie keine Farbe haben.
        $uid_color_part = ($catalog_post_type === 'lww_part') ? $color_post_id : '0';
        $unique_meta_value = $boid . '|' . $uid_color_part . '|' . $condition;

        $post_id = self::find_post_by_meta('lww_inventory_item', $unique_meta_key, $unique_meta_value);
        
        $post_data = [
            'post_title'   => $post_title,
            'post_status'  => 'publish',
            'post_type'    => 'lww_inventory_item',
        ];
        
        if ($post_id > 0) {
            $post_data['ID'] = $post_id;
            wp_update_post($post_data);
        } else {
            $post_id = wp_insert_post($post_data, true);
            if (is_wp_error($post_id)) {
                lww_log_to_job($job_id, sprintf('FEHLER (Inventar-Insert): Konnte \"%s\" nicht erstellen: %s', $post_title, $post_id->get_error_message()));
                return;
            }
            // NEU: Logging für neue Einträge
            lww_log_to_job($job_id, sprintf('INFO (Inventar): \"%s\" (ID: %d) NEU erstellt.', $post_title, $post_id));
        }

        // --- Save all meta data ---
        if ($post_id > 0) {
            update_post_meta($post_id, $unique_meta_key, $unique_meta_value);
            
            // Relational meta
            update_post_meta($post_id, '_lww_catalog_post_type', $catalog_post_type);
            if ($catalog_post_type === 'lww_part') {
                update_post_meta($post_id, '_lww_part_id', $catalog_post_id);
                update_post_meta($post_id, '_lww_color_id', $color_post_id);
                delete_post_meta($post_id, '_lww_set_id');
                delete_post_meta($post_id, '_lww_minifig_id');
            } elseif ($catalog_post_type === 'lww_set') {
                update_post_meta($post_id, '_lww_set_id', $catalog_post_id);
                delete_post_meta($post_id, '_lww_part_id');
                delete_post_meta($post_id, '_lww_color_id');
                delete_post_meta($post_id, '_lww_minifig_id');
            } elseif ($catalog_post_type === 'lww_minifig') {
                update_post_meta($post_id, '_lww_minifig_id', $catalog_post_id);
                delete_post_meta($post_id, '_lww_part_id');
                delete_post_meta($post_id, '_lww_color_id');
                delete_post_meta($post_id, '_lww_set_id');
            }

            // Standard data fields
            update_post_meta($post_id, '_boid', $raw_boid); // Speichere die ursprüngliche BOID mit Farb-ID
            update_post_meta($post_id, '_color_name', $color_name);
            update_post_meta($post_id, '_condition', $condition);
            update_post_meta($post_id, '_quantity', $quantity);
            update_post_meta($post_id, '_price', $price);
            update_post_meta($post_id, '_remarks', $remarks);
            update_post_meta($post_id, '_public_notes', $public_notes);
            update_post_meta($post_id, '_external_id', $external_id);

            // New data fields
            update_post_meta($post_id, '_lww_lot_id', $lot_id);
            update_post_meta($post_id, '_lww_bo_item_id', $bo_item_id);
            update_post_meta($post_id, '_lww_my_cost', $my_cost);
            update_post_meta($post_id, '_lww_my_weight', $my_weight);
            update_post_meta($post_id, '_lww_catalog_weight', $weight);
            update_post_meta($post_id, '_lww_bulk_qty', $bulk_qty);
            update_post_meta($post_id, '_lww_sale_percent', $sale_percent);
            update_post_meta($post_id, '_lww_for_sale', $for_sale);
            update_post_meta($post_id, '_lww_force_quote', $force_quote);
            update_post_meta($post_id, '_lww_reserve_uid', $reserve_uid);

            if (!empty($tier_prices)) {
                update_post_meta($post_id, '_lww_tier_prices', $tier_prices);
            } else {
                delete_post_meta($post_id, '_lww_tier_prices');
            }

            // Parse and assign locations from 'remarks'
            if (function_exists('lww_update_locations_from_string')) {
                lww_update_locations_from_string($post_id, $remarks);
            }
        }
    }
}
