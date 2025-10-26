<?php
/**
 * Import-Handler für Rebrickable 'inventory_sets.csv'
 * Speichert Set-in-Set Beziehungen (z.B. Polybags in einem größeren Set).
 */
if (!defined('ABSPATH')) exit;

class LWW_Import_Inventory_Sets_Handler extends LWW_Import_Handler_Base {

    public function process_row($job_id, $row_data_raw, $header_map) {
        $data = $this->get_data_from_row($row_data_raw, $header_map);

        // Annahme Header: inventory_id, set_num, quantity
        $inventory_id = intval($data['inventory_id'] ?? 0);
        $child_set_num = sanitize_text_field($data['set_num'] ?? '');
        $quantity = intval($data['quantity'] ?? 0);

        if (empty($inventory_id) || empty($child_set_num) || $quantity <= 0) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Inv-Sets): Zeile übersprungen. Inv-ID, SetNum oder Menge fehlt/ungültig. Data: %s', implode(', ', $data)));
            return;
        }

        // --- 1. Finde die WordPress Post IDs ---

        // Finde den Parent Post (Set oder Minifig), zu dem das Inventar gehört
        $parent_post_id = $this->find_post_by_inventory_id($inventory_id);
        if (empty($parent_post_id)) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Inv-Sets): Parent-Post für Inventar-ID "%d" nicht gefunden.', $inventory_id));
            return;
        }

        // Finde den Child Set Post
        $child_set_id = $this->find_set_by_num($child_set_num);
        if (empty($child_set_id)) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Inv-Sets): Child SetNum "%s" (für Inv-ID %d) nicht im Katalog gefunden.', $child_set_num, $inventory_id));
            return;
        }

        // --- 2. Daten speichern ---

        // Stelle sicher, dass alte Set-Beziehungen für diesen Parent nur einmal pro Job gelöscht werden
        static $inventories_cleared = [];
        $meta_key = '_lww_inventory_set_line'; // Eigener Meta-Key für Set-Beziehungen
        $job_cache_key = $job_id . '_sets_' . $parent_post_id;

        if (!isset($inventories_cleared[$job_cache_key])) {
            delete_post_meta($parent_post_id, $meta_key);
            $inventories_cleared[$job_cache_key] = true;
            lww_log_to_job($job_id, sprintf('INFO (Inv-Sets): Alte Set-Stückliste für Post ID %d (Inv-ID %d) wird gelöscht...', $parent_post_id, $inventory_id));
        }

        // Format: "Child_Set_Post_ID|Menge"
        $line_data = implode('|', [
            $child_set_id,
            $quantity,
        ]);

        // Füge die Zeile als neues, separates Meta-Feld hinzu
        add_post_meta($parent_post_id, $meta_key, $line_data, false);
        // Optional: Detaillierteres Logging
        // lww_log_to_job($job_id, sprintf('INFO (Inv-Sets): %dx Set %d zu Parent %d hinzugefügt.', $quantity, $child_set_id, $parent_post_id));
    }
}
