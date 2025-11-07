<?php
/**
 * Import-Handler für Rebrickable 'parts.csv'
 *
 * * Optimierte Version:
 * 1. Nutzt zentrale statische Caches für Term- (Kategorie) und Post- (Teil) Lookups.
 * 2. Lädt Bilder NICHT direkt herunter, sondern speichert nur die URL
 * für einen späteren Hintergrund-Sideloading-Prozess.
 */
if (!defined('ABSPATH')) exit;

class LWW_Import_Parts_Handler extends LWW_Import_Handler_Base {

    /**
     * Wird vom Importer aufgerufen, BEVOR die erste Zeile verarbeitet wird.
     */
    public function start_job($job_id) {
        // Caches für diesen Job-Lauf zurücksetzen
        self::$term_cache = [];
        self::$post_cache = [];
    }

    /**
     * Verarbeitet eine einzelne Zeile aus der 'parts.csv'.
     */
    public function process_row($job_id, $row_data_raw, $header_map) {
        $data = $this->get_data_from_row($row_data_raw, $header_map);
        $line_number = ($job_queue = get_post_meta($job_id, '_job_queue', true)) ? ($job_queue[get_post_meta($job_id, '_current_task_index', true)]['rows_processed'] ?? 0) + 1 : 0;

        $part_num = sanitize_text_field($data['part_num'] ?? '');
        $part_name = sanitize_text_field($data['name'] ?? '');
        $category_id_external = intval($data['part_cat_id'] ?? 0);
        $image_url = esc_url_raw($data['part_img_url'] ?? '');
        $part_material = sanitize_text_field($data['part_material'] ?? ''); // NEU

        if (empty($part_num) || empty($part_name)) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Part): Zeile %d übersprungen. PartNum ("%s") oder Name ("%s") fehlt.', $line_number, $part_num, $part_name));
            return;
        }

        // --- 1. Post-ID holen (mit Caching) ---
        $meta_key = '_lww_part_num'; 
        $post_id = $this->find_post_by_meta('lww_part', $meta_key, $part_num);

        // Wenn Post existiert, überspringen wir das Update, um Performance zu sparen.
        // Ein Update-Lauf sollte über ein separates Werkzeug gesteuert werden.
        if ($post_id > 0) {
            // Optional: Hier könnte man prüfen, ob sich der Name geändert hat und nur dann updaten.
            // Für den reinen Neu-Import ist das Überspringen am schnellsten.
            return;
        }
        
        // --- 2. Kategorie-ID holen (mit Caching) --- 
        $part_category_wp_id = 0;
        if ($category_id_external > 0) {
            $part_category_wp_id = $this->find_term_by_meta('lww_part_category', '_lww_category_id_external', $category_id_external);
            
            if(empty($part_category_wp_id)) {
                lww_log_unresolved_reference($job_id, 'parts.csv', 'Part Category ID', (string)$category_id_external, $line_number);
            }
        }

        // --- 3. Post erstellen --- 
        $post_data = [
            'post_title'   => $part_name,
            'post_status'  => 'publish',
            'post_type'    => 'lww_part',
        ];
        
        $post_id = wp_insert_post($post_data, true);
        if (is_wp_error($post_id)) {
            lww_log_to_job($job_id, sprintf('FEHLER (Part): Konnte "%s" nicht erstellen: %s', $part_name, $post_id->get_error_message()));
            return;
        }
        // Das Logging bei der Erstellung wurde entfernt, um die Log-Dateien bei großen Importen schlank zu halten.
        // lww_log_to_job($job_id, sprintf('INFO (Part): "%s" (ID: %d) NEU erstellt.', $part_name, $post_id));

        // --- 4. Meta-Daten und Taxonomien speichern --- 
        update_post_meta($post_id, $meta_key, $part_num);
        update_post_meta($post_id, '_lww_part_name', $part_name);
        update_post_meta($post_id, '_lww_rebrickable_id', $part_num);
        update_post_meta($post_id, '_lww_material', $part_material); // NEU
        
        // Initialisiere leere Meta-Felder
        update_post_meta($post_id, '_lww_short_description', '');
        update_post_meta($post_id, '_lww_seo_description_wc', '');

        // Kategorie zuweisen
        if ($part_category_wp_id > 0) {
            wp_set_object_terms($post_id, (int)$part_category_wp_id, 'lww_part_category', false);
        }
        
        // --- Optimierte Bild-Handhabung ---
        if (!empty($image_url)) {
            update_post_meta($post_id, '_lww_sideload_image_url', $image_url);
        }
    }
}
