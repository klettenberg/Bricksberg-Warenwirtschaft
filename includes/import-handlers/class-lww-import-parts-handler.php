<?php
/**
 * Import-Handler für Rebrickable 'parts.csv' (v16.2)
 * 
 * UPDATE: Kategorie-Zuordnung abgesichert.
 */
if (!defined('ABSPATH')) exit;

class LWW_Import_Parts_Handler extends LWW_Import_Handler_Base {

    public function start_job($job_id) {
        self::$term_cache = [];
        self::$post_cache = [];
        $core_health = lww_check_core_data_health();
        if (!$core_health['categories_ok']) {
            // Statt Abbruch nur Warnung, falls User partiell importiert
            lww_log_to_job($job_id, sprintf('WARNUNG: Wenige Kategorien gefunden (%d).', $core_health['cat_count']));
        }
    }

    public function process_row($job_id, $row_data_raw, $header_map) {
        $data = self::get_data_from_row($row_data_raw, $header_map);
        $line_number = self::get_current_line_number($job_id);

        $part_num = sanitize_text_field($data['part_num'] ?? '');
        $part_name = sanitize_text_field($data['name'] ?? '');
        $category_id_external = intval($data['part_cat_id'] ?? 0);
        $image_url = esc_url_raw($data['part_img_url'] ?? '');
        $part_material = sanitize_text_field($data['part_material'] ?? '');

        if (empty($part_num) || empty($part_name)) return;

        $post_id = self::find_part_by_rebrickable_num($part_num);
        $is_new = ($post_id === 0);
        
        $part_category_wp_id = 0;
        if ($category_id_external > 0) {
            $part_category_wp_id = self::find_part_category_by_rebrickable_id($category_id_external);
        }

        $post_data = [
            'post_title'   => $part_name,
            'post_status'  => 'publish',
            'post_type'    => 'lww_part',
        ];
        
        if ($is_new) {
            $post_id = wp_insert_post($post_data, true);
            if (is_wp_error($post_id)) return;
        } else {
            $post_data['ID'] = $post_id;
            wp_update_post($post_data); 
        }

        update_post_meta($post_id, '_lww_part_num', $part_num);
        update_post_meta($post_id, '_lww_part_name', $part_name);
        update_post_meta($post_id, '_lww_rebrickable_id', $part_num);
        update_post_meta($post_id, '_lww_material', $part_material);
        
        if ($is_new) {
            update_post_meta($post_id, '_lww_part_name_de', '');
            update_post_meta($post_id, '_lww_bricklink_id', '');
            update_post_meta($post_id, '_lww_brickowl_id', '');
        }

        // Kategorie zuweisen
        if ($part_category_wp_id > 0) {
            wp_set_object_terms($post_id, (int)$part_category_wp_id, 'lww_part_category', false);
        }
        
        if (!empty($image_url)) {
            update_post_meta($post_id, '_lww_sideload_image_url', $image_url);
        }
    }
}
