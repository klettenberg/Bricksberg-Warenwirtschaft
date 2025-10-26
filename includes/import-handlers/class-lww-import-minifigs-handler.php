<?php
/**
 * Import-Handler für Rebrickable 'minifigs.csv'
 */
if (!defined('ABSPATH')) exit;

class LWW_Import_Minifigs_Handler extends LWW_Import_Handler_Base {

    public function process_row($job_id, $row_data_raw, $header_map) {
        $data = $this->get_data_from_row($row_data_raw, $header_map);

        $fig_num = sanitize_text_field($data['fig_num'] ?? '');
        $fig_name = sanitize_text_field($data['name'] ?? '');
        $image_url = esc_url_raw($data['img_url'] ?? '');

        if (empty($fig_num) || empty($fig_name)) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Minifig): Zeile übersprungen. FigNum ("%s") oder Name ("%s") fehlt.', $fig_num, $fig_name));
            return;
        }
        
        $meta_key = 'lww_minifig_num'; 
        $post_id = $this->find_post_by_meta('lww_minifig', $meta_key, $fig_num);
        
        $post_data = [
            'post_title'   => $fig_name,
            'post_status'  => 'publish',
            'post_type'    => 'lww_minifig',
        ];
        
        if ($post_id > 0) {
            $post_data['ID'] = $post_id;
            wp_update_post($post_data);
        } else {
            $post_id = wp_insert_post($post_data, true);
             if (is_wp_error($post_id)) {
                lww_log_to_job($job_id, sprintf('FEHLER (Minifig): Konnte "%s" nicht erstellen: %s', $fig_name, $post_id->get_error_message()));
                return;
            }
            lww_log_to_job($job_id, sprintf('INFO (Minifig): "%s" (ID: %d) NEU erstellt.', $fig_name, $post_id));
        }
        
        if ($post_id > 0) {
            update_post_meta($post_id, $meta_key, $fig_num);
            update_post_meta($post_id, 'lww_minifig_name', $fig_name);
            update_post_meta($post_id, 'lww_rebrickable_id', $fig_num);
            update_post_meta($post_id, 'lww_num_parts', intval($data['num_parts'] ?? 0));
            
            if (!empty($image_url)) {
                $img_result = $this->sideload_image_to_post($image_url, $post_id, $fig_name);
                if (is_wp_error($img_result)) {
                     lww_log_to_job($job_id, sprintf('WARNUNG (Minifig): Bild für "%s" fehlgeschlagen: %s', $fig_name, $img_result->get_error_message()));
                }
            }
        }
    }
}
