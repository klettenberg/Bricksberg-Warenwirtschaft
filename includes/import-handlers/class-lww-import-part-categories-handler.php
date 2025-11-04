<?php
/**
 * Import-Handler für Rebrickable 'part_categories.csv'
 *
 * * Optimierte Version:
 * 1. Nutzt den zentralen statischen Cache der Basisklasse für Term-Lookups.
 */
if (!defined('ABSPATH')) exit;

class LWW_Import_Part_Categories_Handler extends LWW_Import_Handler_Base {

    /**
     * Wird vom Importer aufgerufen, BEVOR die erste Zeile verarbeitet wird.
     * Leert die für diesen Handler relevanten Caches.
     */
    public function start_job($job_id) {
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
        $meta_key = '_lww_category_id_external';

        // Nutze die gecachte Finder-Methode aus der Basisklasse
        $term_wp_id = $this->find_term_by_meta($taxonomy, $meta_key, $category_id_external);

        $term_args = [
            'name' => $category_name,
            'slug' => sanitize_title($category_name . '-' . $category_id_external), // Robuster Slug
        ];

        if ($term_wp_id > 0) {
            // Nur aktualisieren, wenn sich der Name geändert hat
            $existing_term = get_term($term_wp_id, $taxonomy);
            if ($existing_term && $existing_term->name !== $category_name) {
                wp_update_term($term_wp_id, $taxonomy, $term_args);
            }
        } else {
            $result = wp_insert_term($category_name, $taxonomy, $term_args);
             if (!is_wp_error($result)) {
                $term_wp_id = $result['term_id'];
                // Logging entfernt
                // lww_log_to_job($job_id, sprintf('INFO (Teile-Kat.): "%s" (ID: %d) NEU erstellt.', $category_name, $term_wp_id));
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
}
