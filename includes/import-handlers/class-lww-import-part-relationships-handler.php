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
     * Cache für Part Boid (Part-Nummer) -> Post-ID Lookups.
     */
    private static $part_boid_cache = [];

    /**
     * NEU: Sammlung aller Beziehungen, die gespeichert werden sollen.
     * Format: [ child_part_id => [ rel_type => [parent_id1, parent_id2] ] ]
     */
    private static $relations_to_save = [];

    /**
     * Wird vom Importer aufgerufen, BEVOR die erste Zeile verarbeitet wird.
     */
    public function start_job($job_id) {
        // Caches für diesen Job-Lauf zurücksetzen
        self::$part_boid_cache = [];
        self::$relations_to_save = [];
    }

    /**
     * Verarbeitet eine einzelne Zeile und SAMMELT nur die Daten.
     */
    public function process_row($job_id, $row_data_raw, $header_map) {
        $data = $this->get_data_from_row($row_data_raw, $header_map);

        $rel_type = sanitize_text_field($data['rel_type'] ?? ''); // 'A', 'P', 'M', etc.
        $child_part_num = sanitize_text_field($data['child_part_num'] ?? '');
        $parent_part_num = sanitize_text_field($data['parent_part_num'] ?? '');

        if (empty($rel_type) || empty($child_part_num) || empty($parent_part_num)) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Part Rel): Zeile übersprungen. Typ, Child oder Parent PartNum fehlt. Data: %s', implode(', ', $data)));
            return;
        }

        // --- 1. Finde die WordPress Post IDs (mit Caching) ---
        $child_part_id = $this->get_cached_part_id_by_boid($child_part_num);
        if (empty($child_part_id)) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Part Rel): Child PartNum "%s" nicht im Katalog gefunden.', $child_part_num));
            return;
        }

        $parent_part_id = $this->get_cached_part_id_by_boid($parent_part_num);
        if (empty($parent_part_id)) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Part Rel): Parent PartNum "%s" nicht im Katalog gefunden.', $parent_part_num));
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
        lww_log_to_job($job_id, sprintf('INFO (Part Rel): Speichere gesammelte Beziehungen für %d Teile...', count(self::$relations_to_save)));

        $meta_key = '_lww_part_relationships';

        foreach (self::$relations_to_save as $child_id => $relationships) {
            
            // Bereinige Duplikate, die ggf. in der CSV waren
            foreach ($relationships as $type => $parent_ids) {
                $relationships[$type] = array_unique($parent_ids);
            }

            // Speichere das finale, saubere Array für dieses Teil.
            // Dies überschreibt alle alten Daten (logisch korrekt!)
            // und ist nur EINE Datenbank-Abfrage pro Child-Part.
            update_post_meta($child_id, $meta_key, $relationships);
        }

        lww_log_to_job($job_id, 'INFO (Part Rel): Speichern der Beziehungen abgeschlossen.');

        // Speicher freigeben
        self::$relations_to_save = [];
        self::$part_boid_cache = [];
    }


    /**
     * Wrapper-Funktion, die die Part-Post-ID aus einem Cache holt
     * oder per DB-Abfrage sucht und dann cacht.
     *
     * @param string $part_boid
     * @return int|null Post-ID oder null, wenn nicht gefunden.
     */
    private function get_cached_part_id_by_boid($part_boid) {
        // Prüfe, ob der Wert bereits im Cache ist
        if (isset(self::$part_boid_cache[$part_boid])) {
            return self::$part_boid_cache[$part_boid];
        }

        // Nicht im Cache: Führe die eigentliche (teure) Suche aus
        // (Diese Funktion kommt aus deiner LWW_Import_Handler_Base Klasse)
        $post_id = $this->find_part_by_boid($part_boid);

        // Speichere das Ergebnis im Cache
        self::$part_boid_cache[$part_boid] = $post_id;

        return $post_id;
    }
}
