<?php
/**
 * Import-Handler für Rebrickable 'minifigs.csv' (v16.0)
 *
 * * Optimierte Version:
 * 1. Nutzt einen zentralen statischen Cache für Post-Lookups via fig_num.
 * 2. Lädt Bilder NICHT direkt herunter, sondern speichert nur die URL
 * für einen späteren Hintergrund-Sideloading-Prozess.
 * * UPDATE (v16.0): Aktualisiert nun auch bestehende Minifiguren, anstatt sie zu überspringen.
 */
if (!defined('ABSPATH')) exit;

class LWW_Import_Minifigs_Handler extends LWW_Import_Handler_Base {

    /**
     * Wird vom Importer aufgerufen, BEVOR die erste Zeile verarbeitet wird.
     */
    public function start_job($job_id) {
        // Caches für diesen Job-Lauf zurücksetzen
        self::$post_cache = [];
    }

    /**
     * Verarbeitet eine einzelne Zeile aus der 'minifigs.csv'.
     */
    public function process_row($job_id, $row_data_raw, $header_map) {
        $data = self::get_data_from_row($row_data_raw, $header_map);

        $fig_num = sanitize_text_field($data['fig_num'] ?? '');
        $fig_name = sanitize_text_field($data['name'] ?? '');
        $image_url = esc_url_raw($data['img_url'] ?? '');
        
        if (empty($fig_num) || empty($fig_name)) {
            $line_number = self::get_current_line_number($job_id);
            lww_log_to_job($job_id, sprintf('WARNUNG (Minifig): Zeile %d übersprungen. FigNum ("%s") oder Name ("%s") fehlt.', $line_number, $fig_num, $fig_name));
            return;
        }
        
        // Nutze die gecachte Finder-Methode
        $post_id = self::find_minifig_by_num($fig_num);
        $is_new = ($post_id === 0);
        
        $post_data = [
            'post_title'   => $fig_name,
            'post_status'  => 'publish',
            'post_type'    => 'lww_minifig',
        ];
        
        if ($is_new) {
            $post_id = wp_insert_post($post_data, true);
            if (is_wp_error($post_id)) {
                lww_log_to_job($job_id, sprintf('FEHLER (Minifig): Konnte "%s" nicht erstellen: %s', $fig_name, $post_id->get_error_message()));
                return;
            }
        } else {
            $post_data['ID'] = $post_id;
            wp_update_post($post_data);
        }

        // Speichere die Metadaten
        $meta_key = '_lww_minifig_num'; 
        update_post_meta($post_id, $meta_key, $fig_num);
        update_post_meta($post_id, '_lww_minifig_name', $fig_name);
        update_post_meta($post_id, '_lww_rebrickable_id', $fig_num);
        update_post_meta($post_id, '_lww_num_parts', intval($data['num_parts'] ?? 0));

        $year = intval($data['year'] ?? 0);
        $release_date = ($year > 1900) ? sprintf('%d-01-01', $year) : '';
        update_post_meta($post_id, '_lww_year_released', $release_date);

        // Initialisiere leere Meta-Felder nur für neue Minifigs
        if ($is_new) {
            update_post_meta($post_id, '_lww_minifig_name_de', ''); // NEU
            update_post_meta($post_id, '_lww_sales_price', '');
            update_post_meta($post_id, '_lww_seo_description_wc', '');
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
