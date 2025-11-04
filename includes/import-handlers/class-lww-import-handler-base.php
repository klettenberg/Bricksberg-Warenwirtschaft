<?php
/**
 * Abstrakte Klasse LWW_Import_Handler_Base
 * Stellt wiederverwendbare Hilfsfunktionen für alle Import-Handler bereit.
 *
 * * Optimierte Version:
 * - Caching ist hier zentralisiert.
 * - Caches sind 'static properties' der Klasse, damit sie über
 * alle Funktionsaufrufe innerhalb eines Requests (Batches) bestehen bleiben.
 * - Redundante Finder-Funktionen wurden entfernt und in die
 * Standard-Finder integriert.
 */
if (!defined('ABSPATH')) exit;

abstract class LWW_Import_Handler_Base implements LWW_Import_Handler_Interface {

    // --- Zentrale Caches ---
    // Diese Caches leben für die Dauer eines HTTP-Requests (eines Batches).
    
    /** @var array Cache für Post-Lookups. [cache_key => post_id] */
    protected static $post_cache = [];
    
    /** @var array Cache für Term-Lookups. [cache_key => term_id] */
    protected static $term_cache = [];


    /**
     * Wird vom Importer aufgerufen, BEVOR die erste Zeile eines Batches verarbeitet wird.
     * Hier können Caches für einen neuen Job-Lauf zurückgesetzt werden.
     */
    public function start_job($job_id) {
        // Standardimplementierung tut nichts, kann von Kindklassen überschrieben werden.
    }

    /**
     * Wird vom Importer aufgerufen, NACHDEM die letzte Zeile einer Datei verarbeitet wurde.
     * Nützlich, um gesammelte Daten zu speichern (siehe Part Relationships).
     */
    public function finish_job($job_id) {
        // Standardimplementierung tut nichts, kann von Kindklassen überschrieben werden.
    }

    // Implementiere die vom Interface geforderte Methode als abstrakt
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
     * Nutzt den statischen Klassen-Cache.
     */
    protected function find_term_by_meta($taxonomy, $meta_key, $meta_value) {
        if (empty($meta_value) && $meta_value !== '0') return 0;
        
        $cache_key = $taxonomy . '_' . $meta_key . '_' . $meta_value;
        
        if (isset(self::$term_cache[$cache_key])) {
            return self::$term_cache[$cache_key];
        }

        $terms = get_terms([
            'taxonomy'   => $taxonomy, 'hide_empty' => false,
            'meta_query' => [[ 'key' => $meta_key, 'value' => $meta_value, 'compare' => '=', ]],
            'fields'     => 'ids', 'number'     => 1,
        ]);

        $term_id = 0;
        if (!empty($terms) && !is_wp_error($terms)) {
            $term_id = (int)$terms[0];
        }
        
        self::$term_cache[$cache_key] = $term_id;
        return $term_id;
    }

    /**
     * Findet einen Post (Part, Set, etc.) anhand eines Meta-Feldes.
     * Nutzt den statischen Klassen-Cache.
     *
     * @param string|array $post_type Einer oder mehrere Post-Types
     * @param string $meta_key
     * @param string $meta_value
     * @return int Post-ID oder 0
     */
    protected function find_post_by_meta($post_type, $meta_key, $meta_value) {
        if (empty($meta_value) && $meta_value !== '0') return 0;

        $cache_key = (is_array($post_type) ? implode('_', $post_type) : $post_type) . '_' . $meta_key . '_' . $meta_value;
        if (isset(self::$post_cache[$cache_key])) {
            return self::$post_cache[$cache_key];
        }
        
        $args = [
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'meta_key'       => $meta_key,
            'meta_value'     => $meta_value,
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true, // Performance
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ];
        $query = new WP_Query($args);
        $post_id = $query->have_posts() ? (int)$query->posts[0] : 0;
        
        self::$post_cache[$cache_key] = $post_id;
        return $post_id;
    }
    
    /**
     * Findet einen Post (z.B. Part) anhand MEHRERER möglicher Meta-Felder (OR-Suche).
     * Nutzt primär den Cache und fällt auf eine teurere DB-Query zurück, wenn nötig.
     */
    protected function find_post_by_any_meta($post_type, $meta_fields, $value) {
        if (empty($value) && $value !== '0') return 0;

        // 1. Versuche, den Post mit jedem Feld einzeln zu finden (um den Cache zu nutzen)
        foreach ($meta_fields as $meta_key) {
            $post_id = $this->find_post_by_meta($post_type, $meta_key, $value);
            if ($post_id > 0) {
                return $post_id;
            }
        }
        
        // 2. Wenn nichts im Cache war, mache eine OR-Query
        $cache_key = (is_array($post_type) ? implode('_', $post_type) : $post_type) . '_any_' . $value;
        if (isset(self::$post_cache[$cache_key])) {
            return self::$post_cache[$cache_key];
        }

        $meta_query = ['relation' => 'OR'];
        foreach ($meta_fields as $meta_key) {
            $meta_query[] = ['key' => $meta_key, 'value' => $value];
        }

        $args = [
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'meta_query'     => $meta_query,
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ];
        $query = new WP_Query($args);
        $post_id = $query->have_posts() ? (int)$query->posts[0] : 0;
        
        self::$post_cache[$cache_key] = $post_id;
        return $post_id;
    }
    
    // --- Spezifische Finder-Funktionen ---
    // Diese rufen jetzt alle die zentralen, gecachten Finder auf.
    
    protected function find_set_by_num($set_num) {
        return $this->find_post_by_meta('lww_set', '_lww_set_num', $set_num);
    }
    
    protected function find_minifig_by_num($fig_num) {
         return $this->find_post_by_meta('lww_minifig', '_lww_minifig_num', $fig_num);
    }

    protected function find_color_by_rebrickable_id($color_id) {
         return $this->find_post_by_meta('lww_color', '_lww_rebrickable_id', $color_id);
    }
    
    protected function find_post_by_inventory_id($inventory_id) {
        // Sucht in Sets ODER Minifigs nach der Inventar-ID
        return $this->find_post_by_meta(['lww_set', 'lww_minifig'], '_lww_inventory_id', $inventory_id);
    }
    
    protected function find_part_by_boid($boid) {
        $meta_keys = ['_lww_part_num', '_lww_rebrickable_id', '_lww_brickowl_id', '_lww_bricklink_id'];
        return $this->find_post_by_any_meta('lww_part', $meta_keys, $boid);
    }

    /**
     * Findet ein Teil speziell anhand seiner Rebrickable-Teilenummer.
     * Dies ist präziser als find_part_by_boid für Rebrickable-spezifische Importe.
     */
    protected function find_part_by_rebrickable_num($part_num) {
        // Rebrickable-Teilenummern werden in _lww_part_num und _lww_rebrickable_id gespeichert.
        $meta_keys = ['_lww_part_num', '_lww_rebrickable_id'];
        return $this->find_post_by_any_meta('lww_part', $meta_keys, $part_num);
    }
    
    protected function find_color_by_name($color_name) {
        if (empty($color_name)) return 0;

        $cache_key = 'lww_color_name_' . $color_name;
        if (isset(self::$post_cache[$cache_key])) {
            return self::$post_cache[$cache_key];
        }

        // WordPress ist bei 'post_title' (Name) sehr schnell.
        $post = get_page_by_title($color_name, OBJECT, 'lww_color');
        if ($post) {
             self::$post_cache[$cache_key] = $post->ID;
             return $post->ID;
        }
        // Fallback auf das Meta-Feld (das gecacht wird)
        $post_id = $this->find_post_by_meta('lww_color', '_lww_color_name', $color_name);
        self::$post_cache[$cache_key] = $post_id;
        return $post_id;
    }
}
