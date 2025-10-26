<?php
/**
 * Import-Handler für Rebrickable 'inventories.csv' (Deckblatt)
 */
if (!defined('ABSPATH')) exit;

class LWW_Import_Inventories_Handler extends LWW_Import_Handler_Base {

    public function process_row($job_id, $row_data_raw, $header_map) {
        $data = $this->get_data_from_row($row_data_raw, $header_map);

        $inventory_id = intval($data['id'] ?? 0);
        $item_num = sanitize_text_field($data['set_num'] ?? ''); // Kann Set- oder Fig-Nummer sein
        $version = intval($data['version'] ?? 1);

        if (empty($inventory_id) || empty($item_num)) {
             lww_log_to_job($job_id, sprintf('WARNUNG (Inventories): Zeile übersprungen. Inv-ID ("%s") oder Item-Num ("%s") fehlt.', $inventory_id, $item_num));
             return;
        }

        $post_id = 0;
        
        // 1. Ist es ein Set?
        $post_id = $this->find_set_by_num($item_num);
        
        // 2. Wenn nicht, ist es eine Minifigur?
        if (empty($post_id)) {
            $post_id = $this->find_minifig_by_num($item_num);
        }
        
        if (empty($post_id)) {
             lww_log_to_job($job_id, sprintf('WARNUNG (Inventories): Item-Num "%s" (für Inv-ID %d) wurde weder als Set noch als Minifig gefunden.', $item_num, $inventory_id));
             return;
        }

        $existing_inv_id = get_post_meta($post_id, '_lww_inventory_id', true);
        if (empty($existing_inv_id) || $version >= get_post_meta($post_id, '_lww_inventory_version', true)) {
             update_post_meta($post_id, '_lww_inventory_id', $inventory_id);
             update_post_meta($post_id, '_lww_inventory_version', $version);
             // lww_log_to_job($job_id, sprintf('INFO (Inventories): Inventar-ID %d wurde mit Post %d (%s) verknüpft.', $inventory_id, $post_id, $item_num));
        }
    }
}
