<?php
/**
 * Import-Handler für Rebrickable 'minifigs.csv'
 *
 * * Optimierte Version:
 * 1. Nutzt einen zentralen statischen Cache für Post-Lookups via fig_num.
 * 2. Lädt Bilder NICHT direkt herunter, sondern speichert nur die URL
 * für einen späteren Hintergrund-Sideloading-Prozess.
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
        $data = $this->get_data_from_row($row_data_raw, $header_map);

        $fig_num = sanitize_text_field($data['fig_num'] ?? '');
        $fig_name = sanitize_text_field($data['name'] ?? '');
        $image_url = esc_url_raw($data['img_url'] ?? '');

        if (empty($fig_num) || empty($fig_name)) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Minifig): Zeile übersprungen. FigNum ("%s") oder Name ("%s") fehlt.', $fig_num, $fig_name));
            return;
        }
        
        $meta_key = '_lww_minifig_num'; 
        
        // Nutze die gecachte Finder-Methode
        $post_id = $this->find_post_by_meta('lww_minifig', $meta_key, $fig_num);

        // Wenn Post existiert, überspringen
        if ($post_id > 0) {
            return;
        }
        
        $post_data = [
            'post_title'   => $fig_name,
            'post_status'  => 'publish',
            'post_type'    => 'lww_minifig',
        ];
        
        $post_id = wp_insert_post($post_data, true);
        if (is_wp_error($post_id)) {
            lww_log_to_job($job_id, sprintf('FEHLER (Minifig): Konnte "%s" nicht erstellen: %s', $fig_name, $post_id->get_error_message()));
            return;
        }
        // Logging entfernt
        // lww_log_to_job($job_id, sprintf('INFO (Minifig): "%s" (ID: %d) NEU erstellt.', $fig_name, $post_id));

        // Speichere die Metadaten
        update_post_meta($post_id, $meta_key, $fig_num);
        update_post_meta($post_id, '_lww_minifig_name', $fig_name);
        update_post_meta($post_id, '_lww_rebrickable_id', $fig_num);
        update_post_meta($post_id, '_lww_num_parts', intval($data['num_parts'] ?? 0));

        // Initialisiere leere Meta-Felder für neue Minifigs
        update_post_meta($post_id, '_lww_seo_description_wc', '');
        
        // --- Optimierte Bild-Handhabung ---
        if (!empty($image_url)) {
            update_post_meta($post_id, '_lww_sideload_image_url', $image_url);
        }
    }
}
