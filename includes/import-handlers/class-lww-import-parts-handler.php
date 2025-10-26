<?php
/**
 * Import-Handler für Rebrickable 'parts.csv'
 */
if (!defined('ABSPATH')) exit;

class LWW_Import_Parts_Handler extends LWW_Import_Handler_Base {

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

        $part_category_wp_id = 0;
        if ($category_id_external > 0) {
            $part_category_wp_id = $this->find_term_by_meta('lww_part_category', 'lww_category_id_external', $category_id_external);
            if(empty($part_category_wp_id)) {
                lww_log_to_job($job_id, sprintf('WARNUNG (Part): Kategorie-ID "%d" für Part "%s" nicht gefunden.', $category_id_external, $part_num));
            }
        }
        
        $meta_key = 'lww_part_num'; 
        $post_id = $this->find_post_by_meta('lww_part', $meta_key, $part_num);
        
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
        
        if ($post_id > 0) {
            update_post_meta($post_id, $meta_key, $part_num);
            update_post_meta($post_id, 'lww_part_name', $part_name);
            update_post_meta($post_id, 'lww_rebrickable_id', $part_num);
            update_post_meta($post_id, 'lww_brickowl_id', sanitize_text_field($data['brickowl_ids'] ?? ''));
            update_post_meta($post_id, 'lww_bricklink_id', sanitize_text_field($data['bricklink_ids'] ?? ''));
            
            if ($part_category_wp_id > 0) {
                wp_set_object_terms($post_id, (int)$part_category_wp_id, 'lww_part_category');
            }
            
            if (!empty($image_url)) {
                $img_result = $this->sideload_image_to_post($image_url, $post_id, $part_name);
                if (is_wp_error($img_result)) {
                     lww_log_to_job($job_id, sprintf('WARNUNG (Part): Bild für "%s" fehlgeschlagen: %s', $part_name, $img_result->get_error_message()));
                }
            }
        }
    }
}
