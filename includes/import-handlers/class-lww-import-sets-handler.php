<?php
/**
 * Import-Handler für Rebrickable 'sets.csv'
 *
 * * Optimierte Version:
 * 1. Nutzt statische Caches für Term- (Theme) und Post- (Set) Lookups.
 * 2. Lädt Bilder NICHT direkt herunter, sondern speichert nur die URL
 * für einen späteren Hintergrund-Sideloading-Prozess.
 */
if (!defined('ABSPATH')) exit;

class LWW_Import_Sets_Handler extends LWW_Import_Handler_Base {

    /**
     * Cache für externe Theme-ID -> WordPress Term-ID
     */
    private static $term_cache = [];

    /**
     * Cache für Set-Nummer (set_num) -> WordPress Post-ID
     */
    private static $set_num_cache = [];

    /**
     * Wird vom Importer aufgerufen, BEVOR die erste Zeile verarbeitet wird.
     * Stellt sicher, dass Caches von einem vorherigen Lauf geleert werden.
     *
     * @param int $job_id Die ID des aktuellen Import-Jobs.
     */
    public function start_job($job_id) {
        // Caches für diesen Job-Lauf zurücksetzen
        self::$term_cache = [];
        self::$set_num_cache = [];
    }

    /**
     * Verarbeitet eine einzelne Zeile aus der 'sets.csv'.
     */
    public function process_row($job_id, $row_data_raw, $header_map) {
        $data = $this->get_data_from_row($row_data_raw, $header_map);

        $set_num = sanitize_text_field($data['set_num'] ?? '');
        $set_name = sanitize_text_field($data['name'] ?? '');
        $theme_id_external = intval($data['theme_id'] ?? 0);
        $image_url = esc_url_raw($data['img_url'] ?? '');

        if (empty($set_num) || empty($set_name)) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Set): Zeile übersprungen. SetNum ("%s") oder Name ("%s") fehlt.', $set_num, $set_name));
            return;
        }

        // --- 1. Theme-ID holen (mit Caching) ---
        $theme_wp_id = 0;
        if ($theme_id_external > 0) {
            $theme_wp_id = $this->get_cached_term_id($theme_id_external);
            if(empty($theme_wp_id)) {
                lww_log_to_job($job_id, sprintf('WARNUNG (Set): Theme-ID "%d" für Set "%s" nicht gefunden.', $theme_id_external, $set_num));
            }
        }
        
        // --- 2. Post-ID holen (mit Caching) ---
        $meta_key = 'lww_set_num'; 
        $post_id = $this->get_cached_set_post_id($meta_key, $set_num);
        
        // --- 3. Post erstellen oder aktualisieren ---
        $post_data = [
            'post_title'   => $set_name,
            'post_status'  => 'publish',
            'post_type'    => 'lww_set',
        ];
        
        if ($post_id > 0) {
            $post_data['ID'] = $post_id;
            wp_update_post($post_data);
        } else {
            $post_id = wp_insert_post($post_data, true);
             if (is_wp_error($post_id)) {
                lww_log_to_job($job_id, sprintf('FEHLER (Set): Konnte "%s" nicht erstellen: %s', $set_name, $post_id->get_error_message()));
                return;
            }
            lww_log_to_job($job_id, sprintf('INFO (Set): "%s" (ID: %d) NEU erstellt.', $set_name, $post_id));
        }
        
        // --- 4. Meta-Daten und Taxonomien speichern ---
        if ($post_id > 0) {
            update_post_meta($post_id, $meta_key, $set_num);
            update_post_meta($post_id, 'lww_set_name', $set_name);
            update_post_meta($post_id, 'lww_rebrickable_id', $set_num);
            update_post_meta($post_id, 'lww_year_released', intval($data['year'] ?? 0));
            update_post_meta($post_id, 'lww_num_parts', intval($data['num_parts'] ?? 0));

            // Theme zuweisen (wp_set_object_terms überschreibt alte)
            if ($theme_wp_id > 0) {
                wp_set_object_terms($post_id, (int)$theme_wp_id, 'lww_theme', false); // false = überschreiben
            } else {
                // Ggf. alte Themes entfernen
                wp_set_object_terms($post_id, [], 'lww_theme', false);
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
     * Cache-Wrapper für die Term-ID (Theme-Suche).
     */
    private function get_cached_term_id($external_id) {
        if (isset(self::$term_cache[$external_id])) {
            return self::$term_cache[$external_id];
        }

        $term_id = $this->find_term_by_meta('lww_theme', 'lww_theme_id_external', $external_id);
        self::$term_cache[$external_id] = $term_id;
        
        return $term_id;
    }

    /**
     * Cache-Wrapper für die Post-ID (Set-Suche).
     */
    private function get_cached_set_post_id($meta_key, $set_num) {
        if (isset(self::$set_num_cache[$set_num])) {
            return self::$set_num_cache[$set_num];
        }

        $post_id = $this->find_post_by_meta('lww_set', $meta_key, $set_num);
        self::$set_num_cache[$set_num] = $post_id;
        
        return $post_id;
    }
}
