<?php
/**
 * Import-Handler für Rebrickable 'sets.csv'
 */
if (!defined('ABSPATH')) exit;

class LWW_Import_Sets_Handler extends LWW_Import_Handler_Base {

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

        $theme_wp_id = 0;
        if ($theme_id_external > 0) {
            $theme_wp_id = $this->find_term_by_meta('lww_theme', 'lww_theme_id_external', $theme_id_external);
            if(empty($theme_wp_id)) {
                lww_log_to_job($job_id, sprintf('WARNUNG (Set): Theme-ID "%d" für Set "%s" nicht gefunden.', $theme_id_external, $set_num));
            }
        }
        
        $meta_key = 'lww_set_num'; 
        $post_id = $this->find_post_by_meta('lww_set', $meta_key, $set_num);
        
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
        
        if ($post_id > 0) {
            update_post_meta($post_id, $meta_key, $set_num);
            update_post_meta($post_id, 'lww_set_name', $set_name);
            update_post_meta($post_id, 'lww_rebrickable_id', $set_num);
            update_post_meta($post_id, 'lww_year_released', intval($data['year'] ?? 0));
            update_post_meta($post_id, 'lww_num_parts', intval($data['num_parts'] ?? 0));

            if ($theme_wp_id > 0) {
                wp_set_object_terms($post_id, (int)$theme_wp_id, 'lww_theme');
            }
            
            if (!empty($image_url)) {
                $img_result = $this->sideload_image_to_post($image_url, $post_id, $set_name);
                if (is_wp_error($img_result)) {
                     lww_log_to_job($job_id, sprintf('WARNUNG (Set): Bild für "%s" fehlgeschlagen: %s', $set_name, $img_result->get_error_message()));
                }
            }
        }
    }
}
