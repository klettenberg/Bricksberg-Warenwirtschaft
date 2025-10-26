<?php
/**
 * Import-Handler für Rebrickable 'elements.csv'
 * Verknüpft Part + Color mit einer eindeutigen Element ID.
 */
if (!defined('ABSPATH')) exit;

class LWW_Import_Elements_Handler extends LWW_Import_Handler_Base {

    public function process_row($job_id, $row_data_raw, $header_map) {
        $data = $this->get_data_from_row($row_data_raw, $header_map);

        $element_id = sanitize_text_field($data['element_id'] ?? '');
        $part_num = sanitize_text_field($data['part_num'] ?? '');
        $color_id_external = intval($data['color_id'] ?? -1); // Rebrickable Color ID

        if (empty($element_id) || empty($part_num) || $color_id_external == -1) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Elements): Zeile übersprungen. ElementID, PartNum oder ColorID fehlt/ungültig. Data: %s', implode(', ', $data)));
            return;
        }

        // --- 1. Finde die WordPress Post IDs ---
        $part_post_id = $this->find_part_by_boid($part_num); // Nutzt die flexible Suche
        if (empty($part_post_id)) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Elements): PartNum "%s" (für Element %s) nicht im Katalog gefunden.', $part_num, $element_id));
            return;
        }

        $color_post_id = $this->find_color_by_rebrickable_id($color_id_external);
        if (empty($color_post_id)) {
             lww_log_to_job($job_id, sprintf('WARNUNG (Elements): Color-ID "%d" (für Element %s, Part %s) nicht im Katalog gefunden.', $color_id_external, $element_id, $part_num));
             return;
        }

        // --- 2. Element ID als Meta-Feld speichern ---
        // Wir speichern die Element ID am Part Post, zusammen mit der Color ID,
        // da ein Part mehrere Element IDs haben kann (eine pro Farbe).
        // Format: Speichere ein Array von "ColorID|ElementID" Strings.

        $meta_key = '_lww_element_ids';
        $current_elements = get_post_meta($part_post_id, $meta_key, true);
        if (!is_array($current_elements)) {
            $current_elements = [];
        }

        // Erstelle einen eindeutigen Schlüssel für dieses Element im Array
        $element_key = $color_post_id; // Die Color Post ID ist der Schlüssel
        $element_value = $element_id;

        // Update oder füge das Element hinzu
        $current_elements[$element_key] = $element_value;

        // Speichere das aktualisierte Array
        update_post_meta($part_post_id, $meta_key, $current_elements);

        // Optional: Log nur bei Änderungen oder neuen Einträgen, um Log-Spam zu vermeiden.
        // lww_log_to_job($job_id, sprintf('INFO (Elements): Element %s für Part %s / Color %d hinzugefügt/aktualisiert.', $element_id, $part_num, $color_id_external));
    }
}
