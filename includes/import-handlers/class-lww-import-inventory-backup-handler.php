<?php
/**
 * Import-Handler für BrickOwl 'inventory-backup.csv'
 * Diese Klasse ist funktional identisch zum normalen Inventar-Handler,
 * existiert aber als eigener Typ für Klarheit und zukünftige Anpassungen.
 */
if (!defined('ABSPATH')) exit;

class LWW_Import_Inventory_Backup_Handler extends LWW_Import_Handler_Base {

    public function start_job($job_id) {
        self::$post_cache = [];
    }

    public function process_row($job_id, $row_data_raw, $header_map) {
        $data = $this->get_data_from_row($row_data_raw, $header_map);
        $line_number = ($job_queue = get_post_meta($job_id, '_job_queue', true)) ? ($job_queue[0]['rows_processed'] ?? 0) + 1 : 0;

        // --- Core data ---
        $boid = sanitize_text_field($data['boid'] ?? '');
        $color_name = sanitize_text_field($data['color_name'] ?? '');
        
        if (empty($boid) || empty($color_name)) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Inventar-Backup): Zeile %d übersprungen. BOID ("%s") oder Farbe ("%s") fehlt.', $line_number, $boid, $color_name));
            return;
        }

        // --- Sanitize all fields ---
        $condition = sanitize_text_field($data['condition'] ?? 'new');
        $condition = strtolower($condition);
        if ($condition !== 'new' && $condition !== 'used') {
            $condition = 'new'; // Default to 'new' if invalid
        }
        
        $quantity = intval($data['quantity'] ?? 0);
        $price = floatval($data['price'] ?? 0.0);
        $remarks = sanitize_textarea_field($data['remarks'] ?? ''); // Personal note
        $public_notes = sanitize_textarea_field($data['public_notes'] ?? '');
        $external_id = sanitize_text_field($data['external_id'] ?? '');

        // --- New fields ---
        $lot_id = sanitize_text_field($data['lot_id'] ?? '');
        $bo_item_id = sanitize_text_field($data['item_id'] ?? '');
        $my_cost = floatval($data['my_cost'] ?? 0.0);
        $my_weight = floatval($data['my_weight'] ?? 0.0);
        $weight = floatval($data['weight'] ?? 0.0);
        $bulk_qty = intval($data['bulk'] ?? 0);
        $sale_percent = floatval($data['sale_price'] ?? 0.0);
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
                    'price' => floatval($data[$price_key]),
                ];
            }
        }
        
        // --- Find related catalog items ---
        $catalog_item = $this->find_catalog_item_by_boid($boid);
        $color_post_id = $this->find_color_by_name($color_name);

        if (empty($catalog_item)) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Inventar-Backup): Item "%s" (%s) übersprungen. Katalog-Item (Teil/Set/Minifig) mit BOID/Nummer "%s" nicht gefunden.', $data['name'] ?? 'Unbekannt', $boid, $boid));
            return; 
        }
        if (empty($color_post_id) && $catalog_item['type'] === 'lww_part') {
            lww_log_to_job($job_id, sprintf('WARNUNG (Inventar-Backup): Item "%s" (%s) übersprungen. Katalog-Farbe (Color) mit Namen "%s" nicht gefunden.', $data['name'] ?? 'Unbekannt', $boid, $color_name));
            return;
        }
        
        $catalog_post_id = $catalog_item['id'];
        $catalog_post_type = $catalog_item['type'];

        // --- Find or create inventory item post ---
        $post_title = sprintf('%s - %s (%s)', get_the_title($catalog_post_id), $color_name, $condition);
        $unique_meta_key = '_lww_inventory_uid';
        $uid_color_part = ($catalog_post_type === 'lww_part') ? $color_post_id : '0';
        $unique_meta_value = $boid . '|' . $uid_color_part . '|' . $condition;

        $post_id = $this->find_post_by_meta('lww_inventory_item', $unique_meta_key, $unique_meta_value);
        
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
                lww_log_to_job($job_id, sprintf('FEHLER (Inventar-Backup-Insert): Konnte "%s" nicht erstellen: %s', $post_title, $post_id->get_error_message()));
                return;
            }
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
            update_post_meta($post_id, '_boid', $boid);
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
