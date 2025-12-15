<?php
/**
 * Basis-Import-Handler (v17.0)
 * Enthält Stubbing-Logik.
 */
if (!defined('ABSPATH')) exit;

abstract class LWW_Import_Handler_Base implements LWW_Import_Handler_Interface {
    // ... (Caches etc. wie gehabt)
    protected static $post_cache = [];

    /**
     * Erstellt einen Stub (Platzhalter) wenn ein Teil nicht existiert.
     */
    protected function create_stub($item_no, $type) {
        // Prüfen ob schon da
        $exists = self::find_post_by_meta($type, "_lww_{$type}_num", $item_no);
        if ($exists) return $exists;

        $post_id = wp_insert_post([
            'post_title' => "STUB: $item_no",
            'post_type' => "lww_$type",
            'post_status' => 'publish'
        ]);
        
        update_post_meta($post_id, "_lww_{$type}_num", $item_no);
        update_post_meta($post_id, '_lww_is_stub', 1);
        
        return $post_id;
    }
    
    // ... (Restliche Methoden wie find_post_by_meta bleiben erhalten)
    public static function find_post_by_meta($post_type, $meta_key, $meta_value) {
        // ...
        $args = [
            'post_type' => $post_type,
            'meta_key' => $meta_key,
            'meta_value' => $meta_value,
            'posts_per_page' => 1,
            'fields' => 'ids'
        ];
        $q = new WP_Query($args);
        return $q->have_posts() ? $q->posts[0] : 0;
    }
    
    // Abstrakt
    abstract public function process_row($job_id, $row_data, $header_map);
    public function start_job($job_id) {}
    public function finish_job($job_id) {}
}
?>