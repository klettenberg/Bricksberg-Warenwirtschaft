<?php
/**
 * Import-Handler für Rebrickable 'themes.csv'
 *
 * * Optimierte Version:
 * 1. Nutzt den zentralen statischen Cache der Basisklasse für Term-Lookups.
 */
if (!defined('ABSPATH')) exit;

class LWW_Import_Themes_Handler extends LWW_Import_Handler_Base {

    /**
     * Wird vom Importer aufgerufen, BEVOR die erste Zeile verarbeitet wird.
     */
    public function start_job($job_id) {
        // Caches für diesen Job-Lauf zurücksetzen
        self::$term_cache = [];
    }

    /**
     * Verarbeitet eine einzelne Zeile aus der 'themes.csv'.
     */
    public function process_row($job_id, $row_data_raw, $header_map) {
        $data = self::get_data_from_row($row_data_raw, $header_map);

        $theme_id_external = intval($data['id'] ?? 0);
        $theme_name = sanitize_text_field($data['name'] ?? '');
        $parent_id_external = isset($data['parent_id']) && $data['parent_id'] !== '' ? intval($data['parent_id']) : null;
        
        if (empty($theme_id_external) || empty($theme_name)) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Theme): Zeile übersprungen. ID oder Name fehlt.'));
            return;
        }

        $taxonomy = 'lww_theme';
        $meta_key = '_lww_theme_id_external'; // KORREKTUR: Spezifischerer Meta-Schlüssel

        // --- 1. IDs mit Caching holen ---

        // Finde den Term selbst (aus dem Cache oder DB)
        $term_wp_id = self::find_term_by_meta($taxonomy, $meta_key, $theme_id_external);
        
        // Finde den Parent-Term (aus dem Cache oder DB)
        $parent_term_wp_id = 0;
        if ($parent_id_external !== null) {
            $parent_term_wp_id = self::find_term_by_meta($taxonomy, $meta_key, $parent_id_external);
            
            // Logik-Check: Ein Theme kann nicht sein eigener Parent sein
            if ($parent_term_wp_id === $term_wp_id && $term_wp_id > 0) {
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
                // Logging entfernt
                // lww_log_to_job($job_id, sprintf('INFO (Theme): "%s" (ID: %d) NEU erstellt.', $theme_name, $term_wp_id));
            } else {
                 lww_log_to_job($job_id, sprintf('FEHLER (Theme): Konnte "%s" nicht erstellen: %s', $theme_name, $result->get_error_message()));
                 return;
            }
        }
        
        // --- 3. Metadaten speichern --- 
        if ($term_wp_id > 0 && !is_wp_error($result)) {
            update_term_meta($term_wp_id, $meta_key, $theme_id_external);
        }
    }
}
