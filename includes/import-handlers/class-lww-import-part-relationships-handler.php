<?php
/**
 * Import-Handler für Rebrickable 'part_relationships.csv' (v16.0)
 * Speichert Beziehungen zwischen Teilen (Alternate, Print, Mold, etc.).
 * * Härtung: Fügt robuste Überprüfungen für fehlende Referenzen hinzu.
 * * REFACTORING: Nutzt die zentrale Methode zur Ermittlung der Zeilennummer.
 * * UPDATE: Implementiert eine Fallback-Logik, um Druckvarianten ihrem Basis-Teil zuzuordnen.
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
        $data = self::get_data_from_row($row_data_raw, $header_map);
        
        $line_number = self::get_current_line_number($job_id);

        $rel_type = sanitize_text_field($data['rel_type'] ?? ''); // 'A', 'P', 'M', etc.
        $child_part_num = sanitize_text_field($data['child_part_num'] ?? '');
        $parent_part_num = sanitize_text_field($data['parent_part_num'] ?? '');

        if (empty($rel_type) || empty($child_part_num) || empty($parent_part_num)) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Part Rel): Zeile %d übersprungen. Typ, Child oder Parent PartNum fehlt.', $line_number));
            return;
        }

        // --- 1. Finde die WordPress Post IDs (mit robuster, gecachter Methode und Fallback-Logik) ---
        $child_part_id = $this->find_part_with_fallback($child_part_num, $job_id, $line_number, 'Child Part');
        if (empty($child_part_id)) {
            return; // Logging wird in der Hilfsfunktion erledigt
        }

        $parent_part_id = $this->find_part_with_fallback($parent_part_num, $job_id, $line_number, 'Parent Part');
        if (empty($parent_part_id)) {
            return; // Logging wird in der Hilfsfunktion erledigt
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
     * Sucht ein Teil und nutzt eine Fallback-Logik für Druckvarianten.
     *
     * @param string $part_num Die zu suchende Teilenummer.
     * @param int $job_id Die ID des aktuellen Jobs für das Logging.
     * @param int $line_number Die aktuelle Zeilennummer.
     * @param string $part_role Die Rolle des Teils ('Child Part' oder 'Parent Part') für klarere Log-Nachrichten.
     * @return int Die Post-ID des Teils oder 0, wenn nicht gefunden.
     */
    private function find_part_with_fallback($part_num, $job_id, $line_number, $part_role = 'Part') {
        // 1. Versuche die exakte Übereinstimmung (schnellster Weg)
        $post_id = self::find_part_by_rebrickable_num($part_num);
        if ($post_id > 0) {
            return $post_id;
        }

        // 2. Wenn nicht gefunden, versuche Fallback für Druckvarianten (z.B. mit 'pr' oder 'p' Suffix)
        if (preg_match('/^([0-9a-zA-Z]+?)(p[a-zA-Z0-9]+)$/i', $part_num, $matches)) {
            $base_part_num = $matches[1];
            $post_id = self::find_part_by_rebrickable_num($base_part_num);

            if ($post_id > 0) {
                // Logge den erfolgreichen Fallback zur Transparenz
                lww_log_to_job($job_id, sprintf('INFO (Part Rel): Zeile %d: Konnte "%s" nicht finden, aber Basis-Teil "%s" (ID: %d) wurde erfolgreich zugeordnet.', $line_number, $part_num, $base_part_num, $post_id));
                return $post_id;
            }
        }

        // 3. Wenn immer noch nicht gefunden, protokolliere den Fehler und gib 0 zurück
        lww_log_unresolved_reference($job_id, 'part_relationships.csv', $part_role . ' Number', $part_num, $line_number);
        return 0;
    }

    /**
     * Wird vom Importer aufgerufen, NACHDEM alle Zeilen verarbeitet wurden.
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
