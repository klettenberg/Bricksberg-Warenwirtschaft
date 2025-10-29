<?php
/**
 * Import-Handler für Rebrickable 'part_categories.csv'
 *
 * * Optimierte Version:
 * 1. Nutzt einen statischen Cache für Term-Lookups (Kategorie-Suche).
 */
if (!defined('ABSPATH')) exit;

class LWW_Import_Part_Categories_Handler extends LWW_Import_Handler_Base {

    /**
     * Cache für externe Kategorie-ID -> WordPress Term-ID Lookups.
     * Bleibt über alle Zeilen eines Jobs bestehen.
     */
    private static $term_cache = [];

    /**
     * Wird vom Importer aufgerufen, BEVOR die erste Zeile verarbeitet wird.
     * Stellt sicher, dass Caches von einem vorherigen Lauf geleert werden.
     *
     * @param int $job_id Die ID des aktuellen Import-Jobs.
     */
    public function start_job($job_id) {
        // Caches für diesen Job-Lauf zurücksetzen
        self::$term_cache = [];
    }

    /**
     * Verarbeitet eine einzelne Zeile aus der 'part_categories.csv'.
     */
    public function process_row($job_id, $row_data_raw, $header_map) {
        $data = $this->get_data_from_row($row_data_raw, $header_map);
        
        $category_id_external = intval($data['id'] ?? 0);
        $category_name = sanitize_text_field($data['name'] ?? '');

        if (empty($category_id_external) || empty($category_name)) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Teile-Kat.): Zeile übersprungen. ID oder Name fehlt.'));
            return;
        }
        
        $taxonomy = 'lww_part_category';
        $meta_key = 'lww_category_id_external';

        // Nutze die Caching-Funktion statt der direkten Abfrage
        $term_wp_id = $this->get_cached_term_id($taxonomy, $meta_key, $category_id_external);

        $term_args = [
            'name' => $category_name,
            'slug' => sanitize_title($category_name . '-' . $category_id_external), // Robuster Slug
        ];

        if ($term_wp_id > 0) {
            wp_update_term($term_wp_id, $taxonomy, $term_args);
        } else {
            $result = wp_insert_term($category_name, $taxonomy, $term_args);
             if (!is_wp_error($result)) {
                $term_wp_id = $result['term_id'];
                lww_log_to_job($job_id, sprintf('INFO (Teile-Kat.): "%s" (ID: %d) NEU erstellt.', $category_name, $term_wp_id));
            } else {
                 lww_log_to_job($job_id, sprintf('FEHLER (Teile-Kat.): Konnte "%s" nicht erstellen: %s', $category_name, $result->get_error_message()));
                 return;
            }
        }
        
        // Speichere die externe ID als Meta-Feld
        if ($term_wp_id > 0) {
            update_term_meta($term_wp_id, $meta_key, $category_id_external);
        }
    }

    /**
     * Wrapper-Funktion, die die Term-ID aus einem Cache holt
     * oder per DB-Abfrage sucht und dann cacht.
     *
     * @param string $taxonomy Die Taxonomie (z.B. 'lww_part_category')
     * @param string $meta_key Der Meta-Schlüssel (z.B. 'lww_category_id_external')
     * @param int    $external_id Der Meta-Wert (die externe ID)
     * @return int|null Term-ID oder null, wenn nicht gefunden.
     */
    private function get_cached_term_id($taxonomy, $meta_key, $external_id) {
        // Prüfe, ob der Wert bereits im Cache ist
        if (isset(self::$term_cache[$external_id])) {
            return self::$term_cache[$external_id];
        }

        // Nicht im Cache: Führe die eigentliche (teure) Suche aus
        // (Diese Funktion kommt aus deiner LWW_Import_Handler_Base Klasse)
        $term_id = $this->find_term_by_meta($taxonomy, $meta_key, $external_id);

        // Speichere das Ergebnis im Cache (auch wenn es 'null' oder '0' ist),
        // damit wir nicht erneut suchen.
        self::$term_cache[$external_id] = $term_id;

        return $term_id;
    }
}
