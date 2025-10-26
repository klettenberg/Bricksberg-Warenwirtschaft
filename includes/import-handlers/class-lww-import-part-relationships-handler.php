<?php
/**
 * Import-Handler für Rebrickable 'part_relationships.csv'
 * Speichert Beziehungen zwischen Teilen (Alternate, Print, Mold, etc.).
 */
if (!defined('ABSPATH')) exit;

class LWW_Import_Part_Relationships_Handler extends LWW_Import_Handler_Base {

    public function process_row($job_id, $row_data_raw, $header_map) {
        $data = $this->get_data_from_row($row_data_raw, $header_map);

        $rel_type = sanitize_text_field($data['rel_type'] ?? ''); // 'A'=Alternate, 'P'=Print, 'M'=Mold, etc.
        $child_part_num = sanitize_text_field($data['child_part_num'] ?? '');
        $parent_part_num = sanitize_text_field($data['parent_part_num'] ?? '');

        if (empty($rel_type) || empty($child_part_num) || empty($parent_part_num)) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Part Rel): Zeile übersprungen. Typ, Child oder Parent PartNum fehlt. Data: %s', implode(', ', $data)));
            return;
        }

        // --- 1. Finde die WordPress Post IDs ---
        $child_part_id = $this->find_part_by_boid($child_part_num);
        if (empty($child_part_id)) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Part Rel): Child PartNum "%s" nicht im Katalog gefunden.', $child_part_num));
            return;
        }

        $parent_part_id = $this->find_part_by_boid($parent_part_num);
        if (empty($parent_part_id)) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Part Rel): Parent PartNum "%s" nicht im Katalog gefunden.', $parent_part_num));
            return;
        }

        // --- 2. Beziehung als Meta-Feld speichern ---
        // Wir speichern die Beziehung am "Child"-Part, da es oft mehrere Alternativen/Prints gibt.
        // Format: Speichere ein Array, gruppiert nach Beziehungstyp.

        $meta_key = '_lww_part_relationships';
        $current_rels = get_post_meta($child_part_id, $meta_key, true);
        if (!is_array($current_rels)) {
            $current_rels = [];
        }

        // Initialisiere den Array-Schlüssel für den Beziehungstyp, falls nicht vorhanden
        if (!isset($current_rels[$rel_type])) {
            $current_rels[$rel_type] = [];
        }

        // Füge die Parent Part ID hinzu, wenn sie noch nicht existiert (verhindert Duplikate)
        if (!in_array($parent_part_id, $current_rels[$rel_type])) {
            $current_rels[$rel_type][] = $parent_part_id;
            // Speichere das aktualisierte Array
            update_post_meta($child_part_id, $meta_key, $current_rels);
             lww_log_to_job($job_id, sprintf('INFO (Part Rel): Beziehung Typ "%s" von Child %d (%s) zu Parent %d (%s) hinzugefügt.', $rel_type, $child_part_id, $child_part_num, $parent_part_id, $parent_part_num));
        }
    }
}
