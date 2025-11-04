<?php
/**
 * Import-Handler für Rebrickable 'elements.csv'
 * Verknüpft Part + Color mit einer eindeutigen Element ID.
 */
if (!defined('ABSPATH')) exit;

class LWW_Import_Elements_Handler extends LWW_Import_Handler_Base {

    public function start_job($job_id) {
        self::$post_cache = [];
    }

    public function process_row($job_id, $row_data_raw, $header_map) {
        $data = $this->get_data_from_row($row_data_raw, $header_map);
        $line_number = ($job_queue = get_post_meta($job_id, '_job_queue', true)) ? ($job_queue[get_post_meta($job_id, '_current_task_index', true)]['rows_processed'] ?? 0) + 1 : 0;

        $element_id = sanitize_text_field($data['element_id'] ?? '');
        $part_num = sanitize_text_field($data['part_num'] ?? ''); // Rebrickable Part Num
        $color_id_external = intval($data['color_id'] ?? -1); // Rebrickable Color ID

        if (empty($element_id) || empty($part_num) || $color_id_external < 0) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Elements): Zeile %d übersprungen. ElementID, PartNum oder ColorID fehlt/ungültig.', $line_number));
            return;
        }

        // --- 1. Finde die WordPress Post IDs --- 
        $part_post_id = $this->find_post_by_meta('lww_part', '_lww_part_num', $part_num);

        if (empty($part_post_id)) {
            lww_log_unresolved_reference($job_id, 'elements.csv', 'Part Number', $part_num, $line_number);
            return;
        }

        $color_post_id = $this->find_color_by_rebrickable_id($color_id_external);
        if (empty($color_post_id)) {
             lww_log_unresolved_reference($job_id, 'elements.csv', 'Color ID (Rebrickable)', (string)$color_id_external, $line_number);
             return;
        }

        // --- 2. Element ID als Meta-Feld speichern --- 
        // Wir speichern die Element ID am Part Post, zusammen mit der Color ID,
        // da ein Part mehrere Element IDs haben kann (eine pro Farbe).
        // Format: Speichere ein Array von [Color_Post_ID] => ElementID

        $meta_key = '_lww_element_ids';
        $current_elements = get_post_meta($part_post_id, $meta_key, true);
        if (!is_array($current_elements)) {
            $current_elements = [];
        }

        // Nur aktualisieren, wenn sich der Wert geändert hat
        if (!isset($current_elements[$color_post_id]) || $current_elements[$color_post_id] !== $element_id) {
            $current_elements[$color_post_id] = $element_id;
            update_post_meta($part_post_id, $meta_key, $current_elements);
        }
    }
}
