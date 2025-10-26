<?php
/**
 * Abstrakte Klasse LWW_Import_Handler_Base
 * Stellt wiederverwendbare Hilfsfunktionen für alle Import-Handler bereit.
 */
if (!defined('ABSPATH')) exit;

abstract class LWW_Import_Handler_Base implements LWW_Import_Handler_Interface {

    // Implementiere die vom Interface geforderte Methode als abstrakt,
    // damit die Kind-Klassen gezwungen sind, sie zu definieren.
    abstract public function process_row($job_id, $row_data, $header_map);

    /**
     * Wandelt eine rohe CSV-Zeile (numerisches Array) in ein assoziatives Array um.
     */
    protected function get_data_from_row($row_data_raw, $header_map) {
        $data = [];
        foreach ($header_map as $key => $index) {
            if ($index !== false && isset($row_data_raw[$index])) {
                $data[$key] = trim($row_data_raw[$index]);
            } else {
                $data[$key] = ''; // Sicherstellen, dass der Key existiert
            }
        }
        return $data;
    }

    /**
     * Findet einen Term (Kategorie, Thema) anhand eines Meta-Feldes.
     */
    protected function find_term_by_meta($taxonomy, $meta_key, $meta_value) {
        if (empty($meta_value)) return 0;
        
        static $term_cache = []; // Statischer Cache LEBT nur für die Dauer des Requests (d.h. eines Batches)
        $cache_key = $taxonomy . '_' . $meta_key . '_' . $meta_value;
        
        if (isset($term_cache[$cache_key])) {
            return $term_cache[$cache_key];
        }

        $terms = get_terms([
            'taxonomy'   => $taxonomy, 'hide_empty' => false,
            'meta_query' => [[ 'key' => $meta_key, 'value' => $meta_value, 'compare' => '=', ]],
            'fields'     => 'ids', 'number'     => 1,
        ]);

        if (!empty($terms) && !is_wp_error($terms)) {
            $term_cache[$cache_key] = $terms[0];
            return $terms[0];
        }
        
        $term_cache[$cache_key] = 0;
        return 0;
    }

    /**
     * Findet einen Post (Part, Set, etc.) anhand eines Meta-Feldes.
     */
    protected function find_post_by_meta($post_type, $meta_key, $meta_value) {
        if (empty($meta_value)) return 0;

        static $post_cache = [];
        $cache_key = (is_array($post_type) ? implode_and($post_type) : $post_type) . '_' . $meta_key . '_' . $meta_value;
        if (isset($post_cache[$cache_key])) return $post_cache[$cache_key];
        
        $args = [
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'meta_key'       => $meta_key,
            'meta_value'     => $meta_value,
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ];
        $query = new WP_Query($args);
        $post_id = $query->have_posts() ? $query->posts[0] : 0;
        $post_cache[$cache_key] = $post_id;
        return $post_id;
    }

    /**
     * Lädt ein Bild von einer URL herunter und weist es einem Post als Beitragsbild zu.
     */
    protected function sideload_image_to_post($image_url, $post_id, $description) {
        if (empty($image_url) || empty($post_id) || has_post_thumbnail($post_id)) {
            return null; // Nichts tun, wenn kein Bild da ist oder schon eins existiert
        }

        if (!function_exists('media_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        $attachment_id = media_sideload_image($image_url, $post_id, $description, 'id');

        if (!is_wp_error($attachment_id)) {
            set_post_thumbnail($post_id, $attachment_id);
            return $attachment_id;
        }
        
        return $attachment_id; // Gibt das WP_Error-Objekt bei Fehler zurück
    }
    
    // Hier fügen wir die spezifischen Finder-Funktionen hinzu, die wir in Schritt 1 erstellt hatten
    
    protected function find_set_by_num($set_num) {
        return $this->find_post_by_meta('lww_set', 'lww_set_num', $set_num);
    }
    
    protected function find_minifig_by_num($fig_num) {
         return $this->find_post_by_meta('lww_minifig', 'lww_minifig_num', $fig_num);
    }

    protected function find_color_by_rebrickable_id($color_id) {
         return $this->find_post_by_meta('lww_color', '_lww_rebrickable_id', $color_id);
    }
    
    protected function find_post_by_inventory_id($inventory_id) {
        return $this->find_post_by_meta(['lww_set', 'lww_minifig'], '_lww_inventory_id', $inventory_id);
    }
    
    protected function find_part_by_boid($boid) {
        if (empty($boid)) return 0;
        static $part_cache = [];
        if (isset($part_cache[$boid])) return $part_cache[$boid];

        $args = [
            'post_type' => 'lww_part', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids',
            'meta_query' => [
                'relation' => 'OR',
                [ 'key' => 'lww_part_num', 'value' => $boid ],
                [ 'key' => 'lww_rebrickable_id', 'value' => $boid ],
                [ 'key' => 'lww_brickowl_id', 'value' => $boid ],
                [ 'key' => 'lww_bricklink_id', 'value' => $boid ],
            ]
        ];
        $query = new WP_Query($args);
        $post_id = $query->have_posts() ? $query->posts[0] : 0;
        $part_cache[$boid] = $post_id;
        return $post_id;
    }
    
    protected function find_color_by_name($color_name) {
        if (empty($color_name)) return 0;
        static $color_cache = [];
        $cache_key = sanitize_title($color_name);
        if (isset($color_cache[$cache_key])) return $color_cache[$cache_key];

        $post = get_page_by_title($color_name, OBJECT, 'lww_color');
        if ($post) {
             $color_cache[$cache_key] = $post->ID;
             return $post->ID;
        }
        
        $post_id = $this->find_post_by_meta('lww_color', 'lww_color_name', $color_name);
        $color_cache[$cache_key] = $post_id;
        return $post_id;
    }
}
