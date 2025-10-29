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
    private static $post_cache = [];
    
    /** @var array Cache für Term-Lookups. [cache_key => term_id] */
    private static $term_cache = [];


    /**
     * Wird vom Importer aufgerufen, BEVOR die erste Zeile eines Batches verarbeitet wird.
     * WICHTIG: Wenn der Importer für JEDEN BATCH eine NEUE Instanz der Handler-Klasse
     * erstellt, müssen diese Caches 'static' sein, damit sie bestehen bleiben.
     * * Wenn der Importer aber pro Job (über alle Batches) dieselbe Instanz
     * wiederverwendet, müssen wir die Caches leeren.
     *
     * Annahme: Der Importer startet einen Batch, die Caches werden gefüllt.
     * Nächster Request (nächster Batch): PHP startet neu, Caches sind eh leer.
     * Wir brauchen die `start_job` Logik aus den Kind-Klassen hier (vorerst) nicht.
     */
    

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
        if (empty($meta_value)) return 0;
        
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
            $term_id = $terms[0];
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
        if (empty($meta_value)) return 0;

        $cache_key = (is_array($post_type) ? implode_and($post_type) : $post_type) . '_' . $meta_key . '_' . $meta_value;
        if (isset(self::$post_cache[$cache_key])) {
            return self::$post_cache[$cache_key];
        }
        
        $args = [
            'post_type'      => $post_type,
            'post_status'    => 'publish', // 'any' wäre ggf. sicherer?
            'meta_key'       => $meta_key,
            'meta_value'     => $meta_value,
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ];
        $query = new WP_Query($args);
        $post_id = $query->have_posts() ? $query->posts[0] : 0;
        
        self::$post_cache[$cache_key] = $post_id;
        return $post_id;
    }
    
    /**
     * Findet einen Post (z.B. Part) anhand MEHRERER möglicher Meta-Felder.
     * (Ersetzt das alte find_part_by_boid)
     */
    protected function find_post_by_any_meta($post_type, $meta_fields, $value) {
        if (empty($value)) return 0;

        // Versuche, den Post mit jedem Feld zu finden (und nutze den Cache)
        foreach ($meta_fields as $meta_key) {
            // Nutze die Standard-Cache-Funktion
            $post_id = $this->find_post_by_meta($post_type, $meta_key, $value);
            if ($post_id > 0) {
                // Gefunden! Wir können aufhören und müssen nicht die teure 'OR' Query bauen.
                return $post_id;
            }
        }
        
        // Wenn wir hier ankommen, war in *keinem* Feld ein Treffer (oder der Cache ist voll mit Nullen).
        return 0;
    }


    /**
     * Lädt ein Bild von einer URL herunter und weist es einem Post als Beitragsbild zu.
     * DIESE FUNKTION SOLLTE NICHT IM 'process_row' LOOP VERWENDET WERDEN.
     * Sie ist für einen separaten Hintergrundprozess (Cron) gedacht.
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
    
    // --- Spezifische Finder-Funktionen ---
    // Diese rufen jetzt alle die zentralen, gecachten Finder auf.
    
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
        // Sucht in Sets ODER Minifigs nach der Inventar-ID
        return $this->find_post_by_meta(['lww_set', 'lww_minifig'], '_lww_inventory_id', $inventory_id);
    }
    
    /**
     * Ersetzt die alte, inkonsistente find_part_by_boid
     */
    protected function find_part_by_boid($boid) {
        // Definiere alle Meta-Keys, die als "BOID" (BrickOwl ID?) gelten
        $meta_keys = [
            'lww_part_num',
            'lww_rebrickable_id',
            'lww_brickowl_id',
            'lww_bricklink_id'
        ];
        // Nutze die neue ODER-Suchfunktion
        return $this->find_post_by_any_meta('lww_part', $meta_keys, $boid);
    }
    
    /**
     * Ersetzt die alte, inkonsistente find_color_by_name
     */
    protected function find_color_by_name($color_name) {
        // WordPress ist bei 'post_title' (Name) sehr schnell.
        $post = get_page_by_title($color_name, OBJECT, 'lww_color');
        if ($post) {
             return $post->ID;
        }
        // Fallback auf das Meta-Feld (das gecacht wird)
        return $this->find_post_by_meta('lww_color', 'lww_color_name', $color_name);
    }
}
