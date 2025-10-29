<?php
/**
 * Import-Handler für Rebrickable 'parts.csv'
 *
 * * Optimierte Version:
 * 1. Nutzt statische Caches für Term- (Kategorie) und Post- (Teil) Lookups.
 * 2. Lädt Bilder NICHT direkt herunter, sondern speichert nur die URL
 * für einen späteren Hintergrund-Sideloading-Prozess.
 */
if (!defined('ABSPATH')) exit;

class LWW_Import_Parts_Handler extends LWW_Import_Handler_Base {

    /**
     * Cache für externe Kategorie-ID -> WordPress Term-ID
     */
    private static $term_cache = [];

    /**
     * Cache für Part-Nummer (part_num) -> WordPress Post-ID
     */
    private static $part_num_cache = [];

    /**
     * Wird vom Importer aufgerufen, BEVOR die erste Zeile verarbeitet wird.
     * Stellt sicher, dass Caches von einem vorherigen Lauf geleert werden.
     *
     * @param int $job_id Die ID des aktuellen Import-Jobs.
     */
    public function start_job($job_id) {
        // Caches für diesen Job-Lauf zurücksetzen
        self::$term_cache = [];
        self::$part_num_cache = [];
    }

    /**
     * Verarbeitet eine einzelne Zeile aus der 'parts.csv'.
     */
    public function process_row($job_id, $row_data_raw, $header_map) {
        $data = $this->get_data_from_row($row_data_raw, $header_map);

        $part_num = sanitize_text_field($data['part_num'] ?? '');
        $part_name = sanitize_text_field($data['name'] ?? '');
        $category_id_external = intval($data['part_cat_id'] ?? 0);
        $image_url = esc_url_raw($data['part_img_url'] ?? ''); 

        if (empty($part_num) || empty($part_name)) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Part): Zeile übersprungen. PartNum ("%s") oder Name ("%s") fehlt.', $part_num, $part_name));
            return;
        }

        // --- 1. Kategorie-ID holen (mit Caching) ---
        $part_category_wp_id = 0;
        if ($category_id_external > 0) {
            $part_category_wp_id = $this->get_cached_term_id($category_id_external);
            
            if(empty($part_category_wp_id)) {
                lww_log_to_job($job_id, sprintf('WARNUNG (Part): Kategorie-ID "%d" für Part "%s" nicht gefunden.', $category_id_external, $part_num));
            }
        }
        
        // --- 2. Post-ID holen (mit Caching) ---
        $meta_key = 'lww_part_num'; 
        $post_id = $this->get_cached_part_post_id($meta_key, $part_num);
        
        // --- 3. Post erstellen oder aktualisieren ---
        $post_data = [
            'post_title'   => $part_name,
            'post_status'  => 'publish',
            'post_type'    => 'lww_part',
        ];
        
        if ($post_id > 0) {
            $post_data['ID'] = $post_id;
            wp_update_post($post_data);
        } else {
            $post_id = wp_insert_post($post_data, true);
             if (is_wp_error($post_id)) {
                lww_log_to_job($job_id, sprintf('FEHLER (Part): Konnte "%s" nicht erstellen: %s', $part_name, $post_id->get_error_message()));
                return;
            }
            lww_log_to_job($job_id, sprintf('INFO (Part): "%s" (ID: %d) NEU erstellt.', $part_name, $post_id));
        }
        
        // --- 4. Meta-Daten und Taxonomien speichern ---
        if ($post_id > 0) {
            update_post_meta($post_id, $meta_key, $part_num);
            update_post_meta($post_id, 'lww_part_name', $part_name);
            update_post_meta($post_id, 'lww_rebrickable_id', $part_num);
            update_post_meta($post_id, 'lww_brickowl_id', sanitize_text_field($data['brickowl_ids'] ?? ''));
            update_post_meta($post_id, 'lww_bricklink_id', sanitize_text_field($data['bricklink_ids'] ?? ''));
            
            // Kategorie zuweisen (wp_set_object_terms überschreibt alte)
            if ($part_category_wp_id > 0) {
                wp_set_object_terms($post_id, (int)$part_category_wp_id, 'lww_part_category', false); // false = überschreiben
            } else {
                 // Ggf. alte Kategorien entfernen, wenn jetzt keine mehr zugewiesen ist
                 wp_set_object_terms($post_id, [], 'lww_part_category', false);
            }
            
            // --- Optimierte Bild-Handhabung ---
            if (!empty($image_url)) {
                // Nur speichern, wenn der Post kein Beitragsbild hat
                if (!has_post_thumbnail($post_id)) {
                    update_post_meta($post_id, '_lww_sideload_image_url', $image_url);
                } else {
                    delete_post_meta($post_id, '_lww_sideload_image_url');
                }
            }
        }
    }

    /**
     * Cache-Wrapper für die Term-ID (Kategorie-Suche).
     */
    private function get_cached_term_id($external_id) {
        if (isset(self::$term_cache[$external_id])) {
            return self::$term_cache[$external_id];
        }

        $term_id = $this->find_term_by_meta('lww_part_category', 'lww_category_id_external', $external_id);
        self::$term_cache[$external_id] = $term_id;
        
        return $term_id;
    }

    /**
     * Cache-Wrapper für die Post-ID (Teile-Suche).
     */
    private function get_cached_part_post_id($meta_key, $part_num) {
        if (isset(self::$part_num_cache[$part_num])) {
            return self::$part_num_cache[$part_num];
        }

        $post_id = $this->find_post_by_meta('lww_part', $meta_key, $part_num);
        self::$part_num_cache[$part_num] = $post_id;
        
        return $post_id;
    }
}
