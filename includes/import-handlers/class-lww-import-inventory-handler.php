<?php
/**
 * Import-Handler für BrickOwl 'inventory.csv'
 */
if (!defined('ABSPATH')) exit;

class LWW_Import_Inventory_Handler extends LWW_Import_Handler_Base {

    public function process_row($job_id, $row_data_raw, $header_map) {
        $data = $this->get_data_from_row($row_data_raw, $header_map);

        $boid = sanitize_text_field($data['boid'] ?? '');
        $color_name = sanitize_text_field($data['color_name'] ?? '');
        $condition = sanitize_text_field($data['condition'] ?? 'new');
        $quantity = intval($data['quantity'] ?? 0);
        $price = floatval($data['price'] ?? 0.0);

        if (empty($boid) || empty($color_name)) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Inventar): Zeile übersprungen. BOID ("%s") oder Farbe ("%s") fehlt.', $boid, $color_name));
            return;
        }
        
        $condition = strtolower($condition);
        if ($condition !== 'new' && $condition !== 'used') {
            $condition = 'new';
        }

        $part_post_id = $this->find_part_by_boid($boid);
        $color_post_id = $this->find_color_by_name($color_name);

        if (empty($part_post_id)) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Inventar): Item "%s" (%s) übersprungen. Katalog-Teil (Part) mit BOID "%s" nicht gefunden.', $data['name'] ?? 'Unbekannt', $boid, $boid));
            return; 
        }
        if (empty($color_post_id)) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Inventar): Item "%s" (%s) übersprungen. Katalog-Farbe (Color) mit Namen "%s" nicht gefunden.', $data['name'] ?? 'Unbekannt', $boid, $color_name));
            return;
        }

        $post_title = sprintf('%s - %s (%s)', $boid, $color_name, $condition);
        $unique_meta_key = '_lww_inventory_uid';
        $unique_meta_value = $boid . '|' . $color_post_id . '|' . $condition;

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
                lww_log_to_job($job_id, sprintf('FEHLER (Inventar-Insert): Konnte "%s" nicht erstellen: %s', $post_title, $post_id->get_error_message()));
                return;
            }
            lww_log_to_job($job_id, sprintf('INFO (Inventar-Insert): "%s" (ID: %d) NEU erstellt.', $post_title, $post_id));
        }

        if ($post_id > 0) {
            update_post_meta($post_id, $unique_meta_key, $unique_meta_value);
            update_post_meta($post_id, '_lww_part_id', $part_post_id);
            update_post_meta($post_id, '_lww_color_id', $color_post_id);
            update_post_meta($post_id, '_lww_boid', $boid);
            update_post_meta($post_id, '_lww_color_name', $color_name);
            update_post_meta($post_id, '_lww_condition', $condition);
            update_post_meta($post_id, '_lww_quantity', $quantity);
            update_post_meta($post_id, '_lww_price', $price);
            update_post_meta($post_id, '_lww_bulk', intval($data['bulk'] ?? 1));
            update_post_meta($post_id, '_lww_remarks', sanitize_textarea_field($data['remarks'] ?? ''));
            update_post_meta($post_id, '_lww_location', sanitize_text_field($data['location'] ?? ''));
            update_post_meta($post_id, '_lww_external_id', sanitize_text_field($data['external_id'] ?? ''));
        }
    }
}
