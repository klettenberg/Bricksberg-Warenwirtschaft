<?php
/**
 * Import-Handler für BrickLink 'inventory.xml' (welches CSV-Inhalt hat)
 */
if (!defined('ABSPATH')) exit;

class LWW_Import_Bricklink_Inventory_Handler extends LWW_Import_Handler_Base {

    public function start_job($job_id) {
        self::$post_cache = [];
        self::$term_cache = [];
    }

    public function process_row($job_id, $row_data_raw, $header_map) {
        $data = $this->get_data_from_row($row_data_raw, $header_map);
        $line_number = ($job_queue = get_post_meta($job_id, '_job_queue', true)) ? ($job_queue[0]['rows_processed'] ?? 0) + 1 : 0;

        // --- Kerndaten --- 
        $item_no = sanitize_text_field($data['item_no'] ?? '');
        $color_name = sanitize_text_field($data['color'] ?? '');
        $lot_id = sanitize_text_field($data['lot_id'] ?? '');

        if (empty($item_no) || empty($color_name) || empty($lot_id)) {
            lww_log_to_job($job_id, sprintf('WARNUNG (BrickLink-Inventar): Zeile %d übersprungen. Item No, Farbe oder Lot ID fehlt.', $line_number));
            return;
        }

        // --- Daten sanitisieren und aufbereiten ---
        $condition_raw = strtoupper($data['condition'] ?? 'N');
        $condition = ($condition_raw === 'N') ? 'new' : 'used';

        $quantity = intval($data['quantity'] ?? 0);
        $price = floatval($data['price'] ?? 0.0);
        $remarks = sanitize_textarea_field($data['remarks'] ?? '');
        $description = sanitize_textarea_field($data['description'] ?? '');
        $stockroom = sanitize_text_field($data['stockroom'] ?? '');

        // --- Verknüpfte Katalogeinträge finden ---
        $catalog_item = $this->find_catalog_item_by_boid($item_no);
        $color_post_id = $this->find_color_by_name($color_name);

        if (empty($catalog_item)) {
            lww_log_unresolved_reference($job_id, 'bricklink_inventory.xml', 'Item No', $item_no, $line_number);
            return;
        }
        if (empty($color_post_id) && $catalog_item['type'] === 'lww_part') {
            lww_log_unresolved_reference($job_id, 'bricklink_inventory.xml', 'Color Name', $color_name, $line_number);
            return;
        }

        $catalog_post_id = $catalog_item['id'];
        $catalog_post_type = $catalog_item['type'];

        // --- Inventar-Post finden oder erstellen ---
        $post_title = sprintf('%s - %s (%s)', get_the_title($catalog_post_id), $color_name, $condition);
        $unique_meta_key = '_lww_inventory_uid';
        $unique_meta_value = 'bricklink|' . $lot_id;

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
                lww_log_to_job($job_id, sprintf('FEHLER (BL-Inventar-Insert): Konnte \"%s\" nicht erstellen: %s', $post_title, $post_id->get_error_message()));
                return;
            }
        }

        // --- Alle Meta-Daten speichern ---
        if ($post_id > 0) {
            update_post_meta($post_id, $unique_meta_key, $unique_meta_value);
            
            // Relationale Meta-Daten
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

            // Standard-Datenfelder
            update_post_meta($post_id, '_lww_bricklink_item_no', $item_no);
            update_post_meta($post_id, '_color_name', $color_name);
            update_post_meta($post_id, '_condition', $condition);
            update_post_meta($post_id, '_quantity', $quantity);
            update_post_meta($post_id, '_price', $price);
            update_post_meta($post_id, '_remarks', $remarks);
            update_post_meta($post_id, '_public_notes', $description);

            // BrickLink-spezifische Felder
            update_post_meta($post_id, '_lww_bricklink_lot_id', $lot_id);
            update_post_meta($post_id, '_lww_bricklink_sub_condition', sanitize_text_field($data['sub_condition'] ?? ''));
            update_post_meta($post_id, '_lww_bricklink_category_id', sanitize_text_field($data['category'] ?? ''));
            update_post_meta($post_id, '_lww_bricklink_bulk_qty', intval($data['bulk'] ?? 0));
            update_post_meta($post_id, '_lww_bricklink_sale_percent', floatval($data['sale'] ?? 0.0));
            update_post_meta($post_id, '_lww_bricklink_url', esc_url_raw($data['url'] ?? ''));
            update_post_meta($post_id, '_lww_bricklink_reserved_for', sanitize_text_field($data['reserved_for'] ?? ''));
            update_post_meta($post_id, '_lww_bricklink_retain', sanitize_text_field($data['retain'] ?? ''));
            update_post_meta($post_id, '_lww_bricklink_super_lot_id', sanitize_text_field($data['super_lot_id'] ?? ''));
            update_post_meta($post_id, '_lww_bricklink_super_lot_qty', intval($data['super_lot_qty'] ?? 0));
            update_post_meta($post_id, '_lww_my_weight', floatval($data['weight'] ?? 0.0));
            update_post_meta($post_id, '_lww_bricklink_extended_desc', sanitize_textarea_field($data['extended_description'] ?? ''));
            update_post_meta($post_id, '_lww_bricklink_date_added', sanitize_text_field($data['date_added'] ?? ''));
            update_post_meta($post_id, '_lww_bricklink_date_last_sold', sanitize_text_field($data['date_last_sold'] ?? ''));
            update_post_meta($post_id, '_lww_bricklink_currency', sanitize_text_field($data['currency'] ?? ''));

            // Staffelpreise
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
            if (!empty($tier_prices)) {
                update_post_meta($post_id, '_lww_tier_prices', $tier_prices);
            } else {
                delete_post_meta($post_id, '_lww_tier_prices');
            }

            // Lagerort aus 'Stockroom' parsen und zuweisen
            if (function_exists('lww_update_locations_from_string') && !empty($stockroom)) {
                lww_update_locations_from_string($post_id, $stockroom);
            }
        }
    }
}
