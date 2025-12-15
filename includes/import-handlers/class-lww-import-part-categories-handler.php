<?php
/**
 * Import-Handler für Rebrickable 'part_categories.csv'
 * 
 * * Release-Status: Gehärtet gegen Duplikate (Term-Existenzprüfung).
 */
if (!defined('ABSPATH')) exit;

class LWW_Import_Part_Categories_Handler extends LWW_Import_Handler_Base {

    public function start_job($job_id) {
        self::$term_cache = [];
    }

    public function process_row($job_id, $row_data_raw, $header_map) {
        $data = self::get_data_from_row($row_data_raw, $header_map);
        
        $category_id_external = intval($data['id'] ?? 0);
        $category_name = sanitize_text_field($data['name'] ?? '');

        if (empty($category_id_external) || empty($category_name)) {
            return;
        }
        
        $taxonomy = 'lww_part_category';
        $meta_key = '_lww_category_id_external';

        // 1. Suche nach Meta-ID
        $term_wp_id = self::find_term_by_meta($taxonomy, $meta_key, $category_id_external);

        // 2. Suche nach Name (falls ID nicht gefunden), um Duplikate zu verhindern
        if (!$term_wp_id) {
            $existing_term = term_exists($category_name, $taxonomy);
            if ($existing_term) {
                $term_wp_id = is_array($existing_term) ? $existing_term['term_id'] : $existing_term;
            }
        }

        $term_args = [
            'name' => $category_name,
            'slug' => sanitize_title($category_name . '-' . $category_id_external), // Slug stabil halten
        ];

        if ($term_wp_id > 0) {
            // Update existierenden Term
            $current_term = get_term($term_wp_id, $taxonomy);
            if ($current_term && !is_wp_error($current_term) && $current_term->name !== $category_name) {
                wp_update_term($term_wp_id, $taxonomy, $term_args);
            }
        } else {
            // Neu erstellen
            $result = wp_insert_term($category_name, $taxonomy, $term_args);
             if (!is_wp_error($result)) {
                $term_wp_id = $result['term_id'];
            } else {
                 lww_log_to_job($job_id, sprintf('FEHLER (Teile-Kat.): Konnte "%s" nicht erstellen: %s', $category_name, $result->get_error_message()));
                 return;
            }
        }
        
        // Speichere die externe ID als Meta-Feld für zukünftige Lookups
        if ($term_wp_id > 0) {
            update_term_meta($term_wp_id, $meta_key, $category_id_external);
        }
    }
}
