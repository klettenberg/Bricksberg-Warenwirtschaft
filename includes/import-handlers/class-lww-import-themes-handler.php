<?php
/**
 * Import-Handler für Rebrickable 'themes.csv'
 *
 * * Optimierte Version:
 * 1. Nutzt einen statischen Cache für Term-Lookups (sowohl für den Term
 * als auch für dessen Parent-Term).
 */
if (!defined('ABSPATH')) exit;

class LWW_Import_Themes_Handler extends LWW_Import_Handler_Base {

    /**
     * Cache für externe Theme-ID -> WordPress Term-ID Lookups.
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
     * Verarbeitet eine einzelne Zeile aus der 'themes.csv'.
     */
    public function process_row($job_id, $row_data_raw, $header_map) {
        $data = $this->get_data_from_row($row_data_raw, $header_map);

        $theme_id_external = intval($data['id'] ?? 0);
        $theme_name = sanitize_text_field($data['name'] ?? '');
        $parent_id_external = intval($data['parent_id'] ?? 0);
        
        if (empty($theme_id_external) || empty($theme_name)) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Theme): Zeile übersprungen. ID oder Name fehlt.'));
            return;
        }

        $taxonomy = 'lww_theme';
        $meta_key = 'lww_theme_id_external';

        // --- 1. IDs mit Caching holen ---

        // Finde den Term selbst (aus dem Cache oder DB)
        $term_wp_id = $this->get_cached_term_id($taxonomy, $meta_key, $theme_id_external);
        
        // Finde den Parent-Term (aus dem Cache oder DB)
        $parent_term_wp_id = 0;
        if ($parent_id_external > 0) {
            $parent_term_wp_id = $this->get_cached_term_id($taxonomy, $meta_key, $parent_id_external);
            
            // Logik-Check: Ein Theme kann nicht sein eigener Parent sein
            if ($parent_term_wp_id == $term_wp_id) {
                $parent_term_wp_id = 0;
            }
        }

        // --- 2. Term erstellen oder aktualisieren ---
        $term_args = [
            'name' => $theme_name,
            'slug' => sanitize_title($theme_name . '-' . $theme_id_external),
            'parent' => $parent_term_wp_id,
        ];

        if ($term_wp_id > 0) {
            $result = wp_update_term($term_wp_id, $taxonomy, $term_args);
        } else {
            $result = wp_insert_term($theme_name, $taxonomy, $term_args);
            if (!is_wp_error($result)) {
                $term_wp_id = $result['term_id'];
                lww_log_to_job($job_id, sprintf('INFO (Theme): "%s" (ID: %d) NEU erstellt.', $theme_name, $term_wp_id));
            } else {
                 lww_log_to_job($job_id, sprintf('FEHLER (Theme): Konnte "%s" nicht erstellen: %s', $theme_name, $result->get_error_message()));
                 return;
            }
        }
        
        // --- 3. Metadaten speichern ---
        if ($term_wp_id > 0) {
            // Speichere die externe ID, falls sie noch nicht gesetzt war (wichtig für den Cache)
            update_term_meta($term_wp_id, $meta_key, $theme_id_external);
            
            // Stelle sicher, dass der gerade erstellte Term auch im Cache ist
            if (!isset(self::$term_cache[$theme_id_external])) {
                 self::$term_cache[$theme_id_external] = $term_wp_id;
            }
        }
    }

    /**
     * Wrapper-Funktion, die die Term-ID aus einem Cache holt
     * oder per DB-Abfrage sucht und dann cacht.
     *
     * @param string $taxonomy Die Taxonomie
     * @param string $meta_key Der Meta-Schlüssel
     * @param int    $external_id Der Meta-Wert (die externe ID)
     * @return int|null Term-ID oder null, wenn nicht gefunden.
     */
    private function get_cached_term_id($taxonomy, $meta_key, $external_id) {
        // Prüfe, ob der Wert bereits im Cache ist
        if (isset(self::$term_cache[$external_id])) {
            return self::$term_cache[$external_id];
        }

        // Nicht im Cache: Führe die eigentliche (teure) Suche aus
        $term_id = $this->find_term_by_meta($taxonomy, $meta_key, $external_id);

        // Speichere das Ergebnis im Cache
        self::$term_cache[$external_id] = $term_id;

        return $term_id;
    }
}
