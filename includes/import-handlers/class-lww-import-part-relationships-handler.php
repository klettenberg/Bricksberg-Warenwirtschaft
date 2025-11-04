<?php
/**
 * Import-Handler für Rebrickable 'part_relationships.csv'
 * Speichert Beziehungen zwischen Teilen (Alternate, Print, Mold, etc.).
 *
 * * Optimierte Version:
 * 1. Caching für Part-ID-Lookups.
 * 2. Sammelt alle Beziehungen im Speicher und speichert sie
 * erst am ENDE des Jobs (in finish_job), um Tausende
 * einzelne DB-Writes zu vermeiden und alte Daten zu überschreiben.
 */
if (!defined('ABSPATH')) exit;

class LWW_Import_Part_Relationships_Handler extends LWW_Import_Handler_Base {

    /**
     * Sammlung aller Beziehungen, die gespeichert werden sollen.
     * Format: [ child_part_id => [ rel_type => [parent_id1, parent_id2] ] ]
     */
    private static $relations_to_save = [];

    /**
     * Wird vom Importer aufgerufen, BEVOR die erste Zeile verarbeitet wird.
     */
    public function start_job($job_id) {
        // Caches für diesen Job-Lauf zurücksetzen
        self::$post_cache = [];
        self::$relations_to_save = [];
    }

    /**
     * Verarbeitet eine einzelne Zeile und SAMMELT nur die Daten.
     */
    public function process_row($job_id, $row_data_raw, $header_map) {
        $data = $this->get_data_from_row($row_data_raw, $header_map);
        $line_number = ($job_queue = get_post_meta($job_id, '_job_queue', true)) ? ($job_queue[get_post_meta($job_id, '_current_task_index', true)]['rows_processed'] ?? 0) + 1 : 0;

        $rel_type = sanitize_text_field($data['rel_type'] ?? ''); // 'A', 'P', 'M', etc.
        $child_part_num = sanitize_text_field($data['child_part_num'] ?? '');
        $parent_part_num = sanitize_text_field($data['parent_part_num'] ?? '');

        if (empty($rel_type) || empty($child_part_num) || empty($parent_part_num)) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Part Rel): Zeile %d übersprungen. Typ, Child oder Parent PartNum fehlt.', $line_number));
            return;
        }

        // --- 1. Finde die WordPress Post IDs (mit Caching) --- KORRIGIERT: Spezifische Funktion verwenden
        $child_part_id = $this->find_part_by_rebrickable_num($child_part_num);
        if (empty($child_part_id)) {
            lww_log_unresolved_reference($job_id, 'part_relationships.csv', 'Child Part Number', $child_part_num, $line_number);
            return;
        }

        $parent_part_id = $this->find_part_by_rebrickable_num($parent_part_num);
        if (empty($parent_part_id)) {
            lww_log_unresolved_reference($job_id, 'part_relationships.csv', 'Parent Part Number', $parent_part_num, $line_number);
            return;
        }

        // --- 2. Beziehung im PHP-Speicher SAMMELN (nicht speichern!) ---
        
        // Initialisiere die Arrays, falls sie noch nicht existieren
        if (!isset(self::$relations_to_save[$child_part_id])) {
            self::$relations_to_save[$child_part_id] = [];
        }
        if (!isset(self::$relations_to_save[$child_part_id][$rel_type])) {
            self::$relations_to_save[$child_part_id][$rel_type] = [];
        }

        // Füge die Parent Part ID hinzu (Duplikate werden am Ende in finish_job entfernt)
        self::$relations_to_save[$child_part_id][$rel_type][] = $parent_part_id;
    }

    /**
     * NEU: Wird vom Importer aufgerufen, NACHDEM alle Zeilen verarbeitet wurden.
     * Speichert die gesammelten Daten gebündelt in der Datenbank.
     *
     * @param int $job_id Die ID des aktuellen Import-Jobs.
     */
    public function finish_job($job_id) {
        if (empty(self::$relations_to_save)) {
            lww_log_to_job($job_id, 'INFO (Part Rel): Keine neuen Beziehungen zum Speichern gefunden.');
            return;
        }

        lww_log_to_job($job_id, sprintf('INFO (Part Rel): Speichere gesammelte Beziehungen für %d Teile...', count(self::$relations_to_save)));

        $meta_key = '_lww_part_relationships';

        foreach (self::$relations_to_save as $child_id => $relationships) {
            
            // Bereinige Duplikate, die ggf. in der CSV waren
            foreach ($relationships as $type => $parent_ids) {
                $relationships[$type] = array_values(array_unique($parent_ids));
            }

            // Speichere das finale, saubere Array für dieses Teil.
            // Dies überschreibt alle alten Daten (logisch korrekt!)
            // und ist nur EINE Datenbank-Abfrage pro Child-Part.
            update_post_meta($child_id, $meta_key, $relationships);
        }

        lww_log_to_job($job_id, 'INFO (Part Rel): Speichern der Beziehungen abgeschlossen.');

        // Speicher freigeben
        self::$relations_to_save = [];
        self::$post_cache = [];
    }
}
