<?php
/**
 * Import-Handler für Rebrickable 'colors.csv'
 */
if (!defined('ABSPATH')) exit;

class LWW_Import_Colors_Handler extends LWW_Import_Handler_Base {

    public function process_row($job_id, $row_data_raw, $header_map) {
        $data = $this->get_data_from_row($row_data_raw, $header_map);
    
        $rebrickable_id = intval($data['id'] ?? 0);
        $color_name = sanitize_text_field($data['name'] ?? '');
        $rgb_hex = sanitize_hex_color_no_hash($data['rgb'] ?? '');
        $is_trans = ($data['is_trans'] ?? 'f') === 't';

        if (empty($rebrickable_id) && $rebrickable_id !== 0 || empty($color_name)) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Farbe): Zeile übersprungen. ID ("%s") oder Name ("%s") fehlt.', $rebrickable_id, $color_name));
            return;
        }

        $meta_key = '_lww_rebrickable_id';
        $post_id = $this->find_post_by_meta('lww_color', $meta_key, $rebrickable_id);

        $post_data = [
            'post_title'   => $color_name,
            'post_status'  => 'publish',
            'post_type'    => 'lww_color',
        ];
        
        if ($post_id > 0) {
            $post_data['ID'] = $post_id;
            wp_update_post($post_data);
        } else {
            $post_id = wp_insert_post($post_data, true);
            if (is_wp_error($post_id)) {
                lww_log_to_job($job_id, sprintf('FEHLER (Farbe): Konnte "%s" nicht erstellen: %s', $color_name, $post_id->get_error_message()));
                return;
            }
            lww_log_to_job($job_id, sprintf('INFO (Farbe): "%s" (ID: %d) NEU erstellt.', $color_name, $post_id));
        }

        if ($post_id > 0) {
            update_post_meta($post_id, $meta_key, $rebrickable_id);
            update_post_meta($post_id, 'lww_color_name', $color_name);
            update_post_meta($post_id, 'lww_rgb_hex', $rgb_hex);
            update_post_meta($post_id, 'lww_is_transparent', $is_trans);
        }
    }
}
