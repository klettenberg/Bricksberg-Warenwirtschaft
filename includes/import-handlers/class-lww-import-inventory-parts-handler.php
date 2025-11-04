<?php
/**
 * Import-Handler für Rebrickable 'inventory_parts.csv'
 * Speichert die Stückliste (Teile in Sets/Minifigs).
 */
if (!defined('ABSPATH')) exit;

class LWW_Import_Inventory_Parts_Handler extends LWW_Import_Handler_Base {

    // Tracks which parent inventories have been cleared in this job run
    private static $inventories_cleared = [];

    public function start_job($job_id) {
        self::$post_cache = [];
        self::$inventories_cleared = [];
    }

    public function process_row($job_id, $row_data_raw, $header_map) {
        $data = $this->get_data_from_row($row_data_raw, $header_map);

        $inventory_id = intval($data['inventory_id'] ?? 0);
        $part_num = sanitize_text_field($data['part_num'] ?? '');
        $color_id_external = intval($data['color_id'] ?? -1);
        $quantity = intval($data['quantity'] ?? 0);
        $is_spare = (strtolower($data['is_spare'] ?? 'f') === 't');
        $line_number = ($job_queue = get_post_meta($job_id, '_job_queue', true)) ? ($job_queue[get_post_meta($job_id, '_current_task_index', true)]['rows_processed'] ?? 0) + 1 : 0;

        if (empty($inventory_id) || empty($part_num) || $color_id_external < 0 || $quantity <= 0) {
            return; // Zeilen ohne relevante Daten überspringen
        }

        // --- 1. Finde WordPress Post IDs mit gecachten Methoden ---

        // Finde Parent Post (Set oder Minifig)
        $parent_post_id = $this->find_post_by_inventory_id($inventory_id);
        if (empty($parent_post_id)) {
            // Dieses Inventar gehört zu keinem Set/Minifig in unserem Katalog. Normal, daher kein Log.
            return;
        }

        // Finde Child-Part Post
        $part_post_id = $this->find_post_by_meta('lww_part', '_lww_part_num', $part_num);
        if (empty($part_post_id)) {
            lww_log_unresolved_reference($job_id, 'inventory_parts.csv', 'Part Number', $part_num, $line_number);
            return;
        }

        // Finde Color Post
        $color_post_id = $this->find_color_by_rebrickable_id($color_id_external);
        if (empty($color_post_id)) {
            lww_log_unresolved_reference($job_id, 'inventory_parts.csv', 'Color ID (Rebrickable)', (string)$color_id_external, $line_number);
            return;
        }

        // --- 2. Daten effizient speichern ---

        $meta_key = '_lww_inventory_part_line';
        $job_cache_key = $job_id . '_' . $parent_post_id;

        // Alte Stückliste für diesen Parent-Post EINMAL pro Job-Lauf löschen
        if (!isset(self::$inventories_cleared[$job_cache_key])) {
            delete_post_meta($parent_post_id, $meta_key);
            self::$inventories_cleared[$job_cache_key] = true;
            lww_log_to_job($job_id, sprintf('INFO (Inv-Parts): Alte Teile-Stückliste für Post ID %d (Inv-ID %d) wird gelöscht...', $parent_post_id, $inventory_id));
        }

        // Speichere Zeilendaten in einem kompakten, Pipe-getrennten Format
        $line_data = implode('|', [
            $part_post_id,
            $color_post_id,
            $quantity,
            $is_spare ? '1' : '0',
        ]);

        // Nutze add_post_meta, um mehrere Zeilen hinzuzufügen, ohne zu überschreiben
        add_post_meta($parent_post_id, $meta_key, $line_data, false);
    }
}
