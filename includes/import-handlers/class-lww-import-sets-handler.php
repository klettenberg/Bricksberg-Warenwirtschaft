<?php
/**
 * Import-Handler für Rebrickable 'sets.csv' (v16.0)
 *
 * * Optimierte Version:
 * 1. Nutzt zentrale statische Caches für Term- (Theme) und Post- (Set) Lookups.
 * 2. Lädt Bilder NICHT direkt herunter, sondern speichert nur die URL
 * für einen späteren Hintergrund-Sideloading-Prozess.
 * * Härtung: Fügt robuste Überprüfungen für fehlende Referenzen hinzu.
 * * UPDATE (v16.0): Aktualisiert nun auch bestehende Sets, anstatt sie zu überspringen.
 */
if (!defined('ABSPATH')) exit;

class LWW_Import_Sets_Handler extends LWW_Import_Handler_Base {

    /**
     * Wird vom Importer aufgerufen, BEVOR die erste Zeile verarbeitet wird.
     */
    public function start_job($job_id) {
        // Caches für diesen Job-Lauf zurücksetzen
        self::$term_cache = [];
        self::$post_cache = [];
    }

    /**
     * Verarbeitet eine einzelne Zeile aus der 'sets.csv'.
     */
    public function process_row($job_id, $row_data_raw, $header_map) {
        $data = self::get_data_from_row($row_data_raw, $header_map);
        
        $line_number = self::get_current_line_number($job_id);

        $set_num = sanitize_text_field($data['set_num'] ?? '');
        $set_name = sanitize_text_field($data['name'] ?? '');
        $theme_id_external = intval($data['theme_id'] ?? 0);
        $image_url = esc_url_raw($data['img_url'] ?? '');

        if (empty($set_num) || empty($set_name)) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Set): Zeile %d übersprungen. SetNum ("%s") oder Name ("%s") fehlt.', $line_number, $set_num, $set_name));
            return;
        }
        
        // --- 1. Post-ID holen (mit Caching) ---
        $post_id = self::find_set_by_num($set_num);
        $is_new = ($post_id === 0);

        // --- 2. Theme-ID holen (mit Caching) ---
        $theme_wp_id = 0;
        if ($theme_id_external > 0) {
            $theme_wp_id = self::find_theme_by_rebrickable_id($theme_id_external);
            if(empty($theme_wp_id)) {
                $job_type = get_post_meta($job_id, '_job_type', true);
                $context = str_contains($job_type, '_api_sync') ? 'Rebrickable API: sets' : 'sets.csv';
                lww_log_unresolved_reference($job_id, $context, 'Theme ID', (string)$theme_id_external, $line_number);
            }
        }
        
        // --- 3. Post erstellen oder aktualisieren --- 
        $post_data = [
            'post_title'   => $set_name,
            'post_status'  => 'publish',
            'post_type'    => 'lww_set',
        ];
        
        if ($is_new) {
            $post_id = wp_insert_post($post_data, true);
            if (is_wp_error($post_id)) {
                lww_log_to_job($job_id, sprintf('FEHLER (Set): Konnte "%s" nicht erstellen: %s', $set_name, $post_id->get_error_message()));
                return;
            }
        } else {
            $post_data['ID'] = $post_id;
            wp_update_post($post_data);
        }

        // --- 4. Meta-Daten und Taxonomien speichern ---
        $meta_key = '_lww_set_num';
        update_post_meta($post_id, $meta_key, $set_num);
        update_post_meta($post_id, '_lww_set_name', $set_name);
        update_post_meta($post_id, '_lww_rebrickable_id', $set_num);
        
        $year = intval($data['year'] ?? 0);
        $release_date = ($year > 1900) ? sprintf('%d-01-01', $year) : '';
        update_post_meta($post_id, '_lww_year_released', $release_date);

        update_post_meta($post_id, '_lww_num_parts', intval($data['num_parts'] ?? 0));

        // Initialisiere leere Meta-Felder nur für neue Sets
        if ($is_new) {
            update_post_meta($post_id, '_lww_set_name_de', ''); // NEU
            update_post_meta($post_id, '_lww_lego_rrp', '');
            update_post_meta($post_id, '_lww_sales_price', '');
            update_post_meta($post_id, '_lww_seo_description_wc', '');
        }

        // Theme zuweisen
        if ($theme_wp_id > 0) {
            wp_set_object_terms($post_id, (int)$theme_wp_id, 'lww_theme', false);
        }

        // --- NEU: Jahreszahl-Taxonomie zuweisen ---
        if ($year > 1900) {
            $year_term_id = self::find_or_create_term_by_name('lww_year', (string)$year);
            if ($year_term_id > 0) {
                wp_set_object_terms($post_id, (int)$year_term_id, 'lww_year', false);
            }
        } else {
            // Wenn kein gültiges Jahr, alte Terme entfernen
            wp_set_object_terms($post_id, [], 'lww_year', false);
        }
        
        // --- Optimierte Bild-Handhabung ---
        if (!empty($image_url)) {
            update_post_meta($post_id, '_lww_sideload_image_url', $image_url);
        }
    }
}
