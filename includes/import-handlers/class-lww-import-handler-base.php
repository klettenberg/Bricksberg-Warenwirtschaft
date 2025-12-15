<?php
/**
 * Base Import Handler
 *
 * Provides common functionality for all import handlers in the Bricksberg Warenwirtschaft plugin.
 * Handles caching, metadata lookups, and catalog item creation.
 *
 * @since 6.50.0
 * @package BricksbergWarenwirtschaft\ImportHandlers
 */
if (!defined('ABSPATH')) exit;

abstract class LWW_Import_Handler_Base implements LWW_Import_Handler_Interface {

    protected static $post_cache = [];
    protected static $term_cache = [];

    /**
     * Extrahiert Daten aus einer Zeile, unterstützt CSV (numerisch) und API (assoziativ).
     */
    protected static function get_data_from_row($row_data, $header_map) {
        if (count(array_filter(array_keys($row_data), 'is_string')) > 0) {
            return $row_data;
        }
        $data = [];
        if (!empty($header_map)) {
            foreach ($header_map as $key => $index) {
                $data[$key] = isset($row_data[$index]) ? trim($row_data[$index]) : '';
            }
        } else {
            return $row_data;
        }
        return $data;
    }

    protected static function get_current_line_number($job_id) {
        if (!$job_id) return 0;
        $processed = get_post_meta($job_id, '_processed_items', true);
        return (int)$processed + 1;
    }

    /**
     * Generische Suche nach Posts per Meta-Key mit Caching.
     * PERFORMANCE: Nutzt wp_cache_get/set für persistenten Cache + direktes SQL.
     */
    public static function find_post_by_meta($post_type, $meta_key, $meta_value) {
        // 1. Lokaler Runtime-Cache (verhindert doppelte DB-Calls im selben Request)
        $local_key = $post_type . '_' . $meta_key . '_' . $meta_value;
        if (isset(self::$post_cache[$local_key])) {
            return self::$post_cache[$local_key];
        }

        // 2. Persistent Object Cache (Redis/Memcached)
        // Group 'lww_lookups' nutzen für einfache Invalidierung bei Bedarf
        $cache_key = md5($local_key);
        $cache_group = 'lww_lookups';
        $cached_id = wp_cache_get($cache_key, $cache_group);

        if (false !== $cached_id) {
            self::$post_cache[$local_key] = (int)$cached_id;
            return (int)$cached_id;
        }

        global $wpdb;

        // 3. Direkte SQL-Abfrage (wesentlich schneller als WP_Query)
        $sql = $wpdb->prepare(
            "SELECT p.ID 
             FROM {$wpdb->posts} p 
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
             WHERE p.post_type = %s 
             AND pm.meta_key = %s 
             AND pm.meta_value = %s 
             LIMIT 1",
            $post_type,
            $meta_key,
            $meta_value
        );

        $result = $wpdb->get_var($sql);
        $post_id = $result ? (int)$result : 0;
        
        // In Cache speichern (für 24 Stunden, da sich IDs selten ändern)
        wp_cache_set($cache_key, $post_id, $cache_group, DAY_IN_SECONDS);
        self::$post_cache[$local_key] = $post_id;
        
        return $post_id;
    }

    /**
     * Hinterlegt Lookup-Ergebnisse nach manuellen Inserts, damit im selben Request
     * keine Duplikate entstehen (z. B. mehrfacher Stub-Anlage).
     */
    public static function cache_post_lookup($post_type, $meta_key, $meta_value, $post_id) {
        $local_key = $post_type . '_' . $meta_key . '_' . $meta_value;
        self::$post_cache[$local_key] = (int)$post_id;

        $cache_key = md5($local_key);
        wp_cache_set($cache_key, (int)$post_id, 'lww_lookups', DAY_IN_SECONDS);
    }

    /**
     * Generische Suche nach Terms per Meta-Key mit Caching.
     */
    protected static function find_term_by_meta($taxonomy, $meta_key, $meta_value) {
        $local_key = $taxonomy . '_' . $meta_key . '_' . $meta_value;
        if (isset(self::$term_cache[$local_key])) {
            return self::$term_cache[$local_key];
        }

        $cache_key = md5($local_key);
        $cache_group = 'lww_lookups';
        $cached_id = wp_cache_get($cache_key, $cache_group);

        if (false !== $cached_id) {
            self::$term_cache[$local_key] = (int)$cached_id;
            return (int)$cached_id;
        }

        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'meta_key' => $meta_key,
            'meta_value' => $meta_value,
            'number' => 1,
            'fields' => 'ids',
            'update_term_meta_cache' => false 
        ]);

        $result = !empty($terms) && !is_wp_error($terms) ? $terms[0] : 0;
        
        wp_cache_set($cache_key, $result, $cache_group, DAY_IN_SECONDS);
        self::$term_cache[$local_key] = $result;
        
        return $result;
    }

    protected static function find_or_create_term_by_name($taxonomy, $name) {
        $term = get_term_by('name', $name, $taxonomy);
        if ($term) return $term->term_id;
        
        $new_term = wp_insert_term($name, $taxonomy);
        if (!is_wp_error($new_term)) return $new_term['term_id'];
        
        return 0;
    }

    // --- Spezifische Wrapper ---
    public static function find_part_by_rebrickable_num($part_num) {
        return self::find_post_by_meta('lww_part', '_lww_part_num', $part_num);
    }
    public static function find_set_by_num($set_num) {
        return self::find_post_by_meta('lww_set', '_lww_set_num', $set_num);
    }
    public static function find_minifig_by_num($fig_num) {
        return self::find_post_by_meta('lww_minifig', '_lww_minifig_num', $fig_num);
    }
    public static function find_color_by_rebrickable_id($rb_id) {
        return self::find_post_by_meta('lww_color', '_lww_rebrickable_id', $rb_id);
    }
    public static function find_color_by_brickowl_id($bo_id) {
        return self::find_post_by_meta('lww_color', '_lww_brickowl_id', $bo_id);
    }
    public static function find_color_by_name($name) {
        // Cache nutzen wenn möglich
        $cache_key = 'color_name_' . md5($name);
        $cached = wp_cache_get($cache_key, 'lww_lookups');
        if($cached !== false) return (int)$cached;

        $query = new WP_Query([
            'post_type' => 'lww_color',
            'title' => $name,
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false
        ]);
        $id = $query->have_posts() ? $query->posts[0] : 0;
        wp_cache_set($cache_key, $id, 'lww_lookups', DAY_IN_SECONDS);
        return $id;
    }
    public static function find_theme_by_rebrickable_id($theme_id) {
        return self::find_term_by_meta('lww_theme', '_lww_theme_id_external', $theme_id);
    }
    public static function find_part_category_by_rebrickable_id($cat_id) {
        return self::find_term_by_meta('lww_part_category', '_lww_category_id_external', $cat_id);
    }
    public static function find_post_by_inventory_id($inv_id) {
        $post_id = self::find_post_by_meta('lww_set', '_lww_inventory_id', $inv_id);
        if ($post_id) return $post_id;
        return self::find_post_by_meta('lww_minifig', '_lww_inventory_id', $inv_id);
    }

    public static function find_catalog_item_by_boid($boid) {
        $types = ['lww_part', 'lww_set', 'lww_minifig'];
        $keys = ['_lww_brickowl_id', '_lww_bricklink_id', '_lww_part_num', '_lww_set_num', '_lww_minifig_num'];
        
        // Versuch Optimierung: Prüfe zuerst Parts, da am häufigsten
        foreach(['_lww_brickowl_id', '_lww_bricklink_id', '_lww_part_num'] as $k) {
            $id = self::find_post_by_meta('lww_part', $k, $boid);
            if($id) return ['id' => $id, 'type' => 'lww_part'];
        }

        $id = self::find_post_by_meta('lww_set', '_lww_set_num', $boid);
        if($id) return ['id' => $id, 'type' => 'lww_set'];
        
        $id = self::find_post_by_meta('lww_minifig', '_lww_minifig_num', $boid);
        if($id) return ['id' => $id, 'type' => 'lww_minifig'];

        return null;
    }

    /**
     * Erstellt einen Katalogeintrag (veraltet, nutze lww_create_and_enrich_missing_catalog_item).
     * @deprecated Verwende stattdessen lww_create_and_enrich_missing_catalog_item()
     */
    protected function create_stub($item_no, $type) {
        $source = 'csv_import'; // Fallback
        $result = lww_create_and_enrich_missing_catalog_item($item_no, $source);
        return $result ? $result['id'] : 0;
    }
    
    abstract public function process_row($job_id, $row_data, $header_map);
    public function start_job($job_id) {}
    public function finish_job($job_id) {}
}
