<?php
/**
 * Modul: Import-Logik (Zeilen-Verarbeitung)
 *
 * Enthält alle lww_import_*_data Funktionen, die vom Batch-Prozessor
 * für jede einzelne CSV-Zeile aufgerufen werden.
 */
if (!defined('ABSPATH')) exit;


// ########################################################################
// ##### HELPER-FUNKTIONEN (Allgemeine Werkzeuge)
// ########################################################################

/**
 * Wandelt eine rohe CSV-Zeile (numerisches Array) in ein assoziatives Array um.
 * @param array $row_data_raw Das numerische Array von fgetcsv().
 * @param array $header_map Das Assoziative Array [ 'spaltenname' => index ].
 * @return array Assoziatives Array [ 'spaltenname' => 'wert' ].
 */
function lww_get_data_from_row($row_data_raw, $header_map) {
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
 * Mit statischem Cache für bessere Performance innerhalb eines Batches.
 */
function lww_find_term_by_meta($taxonomy, $meta_key, $meta_value) {
    if (empty($meta_value)) return 0;
    
    static $term_cache = [];
    $cache_key = $taxonomy . '_' . $meta_key . '_' . $meta_value;
    
    if (isset($term_cache[$cache_key])) {
        return $term_cache[$cache_key];
    }

    $terms = get_terms([
        'taxonomy'   => $taxonomy,
        'hide_empty' => false,
        'meta_query' => [
            [
                'key'     => $meta_key,
                'value'   => $meta_value,
                'compare' => '=',
            ],
        ],
        'fields'     => 'ids',
        'number'     => 1,
    ]);

    if (!empty($terms) && !is_wp_error($terms)) {
        $term_cache[$cache_key] = $terms[0];
        return $terms[0];
    }
    
    $term_cache[$cache_key] = 0;
    return 0;
}

/**
 * Lädt ein Bild von einer URL herunter und weist es einem Post als Beitragsbild zu.
 * Verhindert doppelten Download, wenn das Bild bereits existiert.
 */
function lww_sideload_image_to_post($image_url, $post_id, $description) {
    if (empty($image_url) || empty($post_id)) {
        return null;
    }
    
    // Prüfen, ob dieser Post bereits ein Thumbnail hat
    if (has_post_thumbnail($post_id)) {
        // Optional: Prüfen, ob die URL sich geändert hat
        // $existing_thumb_id = get_post_thumbnail_id($post_id);
        // $existing_url = get_post_meta($existing_thumb_id, '_source_url', true);
        // if ($existing_url == $image_url) return $existing_thumb_id;
        return get_post_thumbnail_id($post_id);
    }

    // WordPress-Funktionen für media_sideload_image laden
    if (!function_exists('media_handle_upload')) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
    }

    // Bild herunterladen und Attachment-ID holen
    $attachment_id = media_sideload_image($image_url, $post_id, $description, 'id');

    if (!is_wp_error($attachment_id)) {
        set_post_thumbnail($post_id, $attachment_id);
        // Speichern der Quell-URL, um doppelten Download zu verhindern
        // update_post_meta($attachment_id, '_source_url', $image_url); 
        return $attachment_id;
    }
    
    return $attachment_id; // Gibt das WP_Error-Objekt bei Fehler zurück
}

/**
 * HELPER: Findet einen 'lww_set' Post anhand der Set-Nummer.
 */
function lww_find_set_by_num($set_num) {
    if (empty($set_num)) return 0;
    static $cache = [];
    if (isset($cache[$set_num])) return $cache[$set_num];

    $args = [
        'post_type'      => 'lww_set',
        'post_status'    => 'publish',
        'meta_key'       => 'lww_set_num',
        'meta_value'     => $set_num,
        'posts_per_page' => 1,
        'fields'         => 'ids',
    ];
    $query = new WP_Query($args);
    $post_id = $query->have_posts() ? $query->posts[0] : 0;
    $cache[$set_num] = $post_id;
    return $post_id;
}

/**
 * HELPER: Findet einen 'lww_minifig' Post anhand der Minifig-Nummer.
 */
function lww_find_minifig_by_num($fig_num) {
    if (empty($fig_num)) return 0;
    static $cache = [];
    if (isset($cache[$fig_num])) return $cache[$fig_num];

    $args = [
        'post_type'      => 'lww_minifig',
        'post_status'    => 'publish',
        'meta_key'       => 'lww_minifig_num',
        'meta_value'     => $fig_num,
        'posts_per_page' => 1,
        'fields'         => 'ids',
    ];
    $query = new WP_Query($args);
    $post_id = $query->have_posts() ? $query->posts[0] : 0;
    $cache[$fig_num] = $post_id;
    return $post_id;
}

/**
 * HELPER: Findet einen Set- oder Minifig-Post anhand der Inventory ID.
 */
function lww_find_post_by_inventory_id($inventory_id) {
    if (empty($inventory_id)) return 0;
    static $cache = [];
    if (isset($cache[$inventory_id])) return $cache[$inventory_id];
    
    $args = [
        'post_type'      => ['lww_set', 'lww_minifig'], // Durchsuche beide CPTs
        'post_status'    => 'publish',
        'meta_key'       => '_lww_inventory_id', // Das Meta-Feld, das wir in lww_import_inventories_data setzen
        'meta_value'     => $inventory_id,
        'posts_per_page' => 1,
        'fields'         => 'ids',
    ];
    $query = new WP_Query($args);
    $post_id = $query->have_posts() ? $query->posts[0] : 0;
    $cache[$inventory_id] = $post_id;
    return $post_id;
}

/**
 * HELPER: Findet eine 'lww_color' Post anhand der Rebrickable Color ID.
 */
function lww_find_color_by_rebrickable_id($color_id) {
    if (empty($color_id) && $color_id !== 0) return 0;
    static $cache = [];
    if (isset($cache[$color_id])) return $cache[$color_id];
    
    $args = [
        'post_type'      => 'lww_color',
        'post_status'    => 'publish',
        'meta_key'       => '_lww_rebrickable_id', // Das Meta-Feld aus dem Color-Import
        'meta_value'     => $color_id,
        'posts_per_page' => 1,
        'fields'         => 'ids',
    ];
    $query = new WP_Query($args);
    $post_id = $query->have_posts() ? $query->posts[0] : 0;
    $cache[$color_id] = $post_id;
    return $post_id;
}



// ########################################################################
// ##### KATALOG-IMPORT-FUNKTIONEN (Zeilen-Verarbeitung)
// ########################################################################

/**
 * KATALOG: Importiert EINE Zeile aus colors.csv
 * (Angepasst von lww_import_colors_csv)
 */
function lww_import_colors_data($job_id, $row_data_raw, $header_map) {
    // Annahme Header: id, name, rgb, is_trans
    $data = lww_get_data_from_row($row_data_raw, $header_map);
    
    $rebrickable_id = intval($data['id'] ?? 0);
    $color_name = sanitize_text_field($data['name'] ?? '');
    $rgb_hex = sanitize_hex_color_no_hash($data['rgb'] ?? '');
    $is_trans = ($data['is_trans'] ?? 'f') === 't';

    if (empty($rebrickable_id) || empty($color_name)) {
        lww_log_to_job($job_id, sprintf('WARNUNG (Farbe): Zeile übersprungen. ID ("%s") oder Name ("%s") fehlt.', $rebrickable_id, $color_name));
        return;
    }

    // Eindeutige ID ist die Rebrickable ID
    $meta_key = '_lww_rebrickable_id';
    
    $existing_posts = get_posts([
        'post_type'  => 'lww_color',
        'meta_key'   => $meta_key,
        'meta_value' => $rebrickable_id,
        'posts_per_page' => 1, 'fields' => 'ids', 'post_status' => 'publish',
    ]);

    $post_data = [
        'post_title'   => $color_name,
        'post_status'  => 'publish',
        'post_type'    => 'lww_color',
    ];
    
    $post_id = 0;
    if (!empty($existing_posts)) {
        $post_id = $existing_posts[0];
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
        update_post_meta($post_id, 'lww_color_name', $color_name); // Duplikat für leichtere Suche
        update_post_meta($post_id, 'lww_rgb_hex', $rgb_hex);
        update_post_meta($post_id, 'lww_is_transparent', $is_trans);
        // Annahme: BrickLink/BrickOwl IDs sind in anderen CSVs (elements.csv?)
    }
}

/**
 * KATALOG: Importiert EINE Zeile aus themes.csv
 */
function lww_import_themes_data($job_id, $row_data_raw, $header_map) {
    // Annahme Header: id, name, parent_id
    $data = lww_get_data_from_row($row_data_raw, $header_map);

    $theme_id_external = intval($data['id'] ?? 0);
    $theme_name = sanitize_text_field($data['name'] ?? '');
    $parent_id_external = intval($data['parent_id'] ?? 0);
    
    if (empty($theme_id_external) || empty($theme_name)) {
        lww_log_to_job($job_id, sprintf('WARNUNG (Theme): Zeile übersprungen. ID oder Name fehlt.'));
        return;
    }

    $taxonomy = 'lww_theme';
    $meta_key = 'lww_theme_id_external'; // Eindeutige externe ID

    // Finde den WordPress Term
    $term_wp_id = lww_find_term_by_meta($taxonomy, $meta_key, $theme_id_external);
    
    // Finde das Eltern-Theme (falls vorhanden)
    $parent_term_wp_id = 0;
    if ($parent_id_external > 0) {
        $parent_term_wp_id = lww_find_term_by_meta($taxonomy, $meta_key, $parent_id_external);
        // Hinweis: Das funktioniert nur, wenn die parent_id bereits importiert wurde.
        // Ggf. muss man Themes in zwei Durchgängen importieren.
    }

    $term_args = [
        'name' => $theme_name,
        'slug' => sanitize_title($theme_name . '-' . $theme_id_external), // Eindeutiger Slug
        'parent' => $parent_term_wp_id,
    ];

    if ($term_wp_id > 0) {
        // Update
        $result = wp_update_term($term_wp_id, $taxonomy, $term_args);
    } else {
        // Insert
        $result = wp_insert_term($theme_name, $taxonomy, $term_args);
        if (!is_wp_error($result)) {
            $term_wp_id = $result['term_id'];
            lww_log_to_job($job_id, sprintf('INFO (Theme): "%s" (ID: %d) NEU erstellt.', $theme_name, $term_wp_id));
        } else {
             lww_log_to_job($job_id, sprintf('FEHLER (Theme): Konnte "%s" nicht erstellen: %s', $theme_name, $result->get_error_message()));
             return;
        }
    }
    
    if ($term_wp_id > 0) {
        update_term_meta($term_wp_id, $meta_key, $theme_id_external);
    }
}

/**
 * KATALOG: Importiert EINE Zeile aus part_categories.csv
 * (Angepasst von lww_import_part_categories_csv)
 */
function lww_import_part_categories_data($job_id, $row_data_raw, $header_map) {
    // Annahme Header: id, name
    $data = lww_get_data_from_row($row_data_raw, $header_map);
    
    $category_id_external = intval($data['id'] ?? 0);
    $category_name = sanitize_text_field($data['name'] ?? '');

    if (empty($category_id_external) || empty($category_name)) {
        lww_log_to_job($job_id, sprintf('WARNUNG (Teile-Kat.): Zeile übersprungen. ID oder Name fehlt.'));
        return;
    }
    
    $taxonomy = 'lww_part_category';
    $meta_key = 'lww_category_id_external'; // Eindeutige externe ID

    $term_wp_id = lww_find_term_by_meta($taxonomy, $meta_key, $category_id_external);

    $term_args = [
        'name' => $category_name,
        'slug' => sanitize_title($category_name . '-' . $category_id_external), // Eindeutiger Slug
    ];

    if ($term_wp_id > 0) {
        // Update
        wp_update_term($term_wp_id, $taxonomy, $term_args);
    } else {
        // Insert
        $result = wp_insert_term($category_name, $taxonomy, $term_args);
         if (!is_wp_error($result)) {
            $term_wp_id = $result['term_id'];
            lww_log_to_job($job_id, sprintf('INFO (Teile-Kat.): "%s" (ID: %d) NEU erstellt.', $category_name, $term_wp_id));
        } else {
             lww_log_to_job($job_id, sprintf('FEHLER (Teile-Kat.): Konnte "%s" nicht erstellen: %s', $category_name, $result->get_error_message()));
             return;
        }
    }
    
    if ($term_wp_id > 0) {
        update_term_meta($term_wp_id, $meta_key, $category_id_external);
    }
}

/**
 * KATALOG: Importiert EINE Zeile aus parts.csv
 * (Angepasst von lww_import_parts_csv)
 */
function lww_import_parts_data($job_id, $row_data_raw, $header_map) {
    // Annahme Header: part_num, name, part_cat_id, part_material, [bricklink_id, brickowl_id]
    $data = lww_get_data_from_row($row_data_raw, $header_map);

    $part_num = sanitize_text_field($data['part_num'] ?? '');
    $part_name = sanitize_text_field($data['name'] ?? '');
    $category_id_external = intval($data['part_cat_id'] ?? 0);
    // Annahme: Es gibt eine Spalte für die Bild-URL
    $image_url = esc_url_raw($data['part_img_url'] ?? ''); 

    if (empty($part_num) || empty($part_name)) {
        lww_log_to_job($job_id, sprintf('WARNUNG (Part): Zeile übersprungen. PartNum ("%s") oder Name ("%s") fehlt.', $part_num, $part_name));
        return;
    }

    // 1. Finde die zugehörige Kategorie-ID
    $part_category_wp_id = 0;
    if ($category_id_external > 0) {
        $part_category_wp_id = lww_find_term_by_meta('lww_part_category', 'lww_category_id_external', $category_id_external);
        if(empty($part_category_wp_id)) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Part): Kategorie-ID "%d" für Part "%s" nicht gefunden.', $category_id_external, $part_num));
        }
    }
    
    // 2. Erstelle oder aktualisiere den lww_part CPT
    // Eindeutige ID ist die PartNum
    $meta_key = 'lww_part_num'; 
    
    $existing_posts = get_posts([
        'post_type'  => 'lww_part',
        'meta_key'   => $meta_key,
        'meta_value' => $part_num,
        'posts_per_page' => 1, 'fields' => 'ids', 'post_status' => 'publish',
    ]);
    
    $post_data = [
        'post_title'   => $part_name,
        'post_status'  => 'publish',
        'post_type'    => 'lww_part',
        // 'post_content' => $part_description, // Falls vorhanden
    ];
    
    $post_id = 0;
    if (!empty($existing_posts)) {
        $post_id = $existing_posts[0];
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
    
    // 3. Metadaten und Taxonomie zuweisen
    if ($post_id > 0) {
        update_post_meta($post_id, $meta_key, $part_num);
        update_post_meta($post_id, 'lww_part_name', $part_name);
        update_post_meta($post_id, 'lww_rebrickable_id', $part_num); // Bei Rebrickable ist part_num die ID
        update_post_meta($post_id, 'lww_brickowl_id', sanitize_text_field($data['brickowl_ids'] ?? '')); // Annahme Spaltenname
        update_post_meta($post_id, 'lww_bricklink_id', sanitize_text_field($data['bricklink_ids'] ?? '')); // Annahme Spaltenname
        
        // Kategorie zuweisen
        if ($part_category_wp_id > 0) {
            wp_set_object_terms($post_id, (int)$part_category_wp_id, 'lww_part_category');
        }
        
        // 4. Bild importieren
        if (!empty($image_url)) {
            $img_result = lww_sideload_image_to_post($image_url, $post_id, $part_name);
            if (is_wp_error($img_result)) {
                 lww_log_to_job($job_id, sprintf('WARNUNG (Part): Bild für "%s" fehlgeschlagen: %s', $part_name, $img_result->get_error_message()));
            }
        }
    }
}

/**
 * KATALOG: Importiert EINE Zeile aus sets.csv
 * (Angepasst von lww_import_sets_csv)
 */
function lww_import_sets_data($job_id, $row_data_raw, $header_map) {
    // Annahme Header: set_num, name, year, theme_id, num_parts, img_url
    $data = lww_get_data_from_row($row_data_raw, $header_map);

    $set_num = sanitize_text_field($data['set_num'] ?? '');
    $set_name = sanitize_text_field($data['name'] ?? '');
    $theme_id_external = intval($data['theme_id'] ?? 0);
    $image_url = esc_url_raw($data['img_url'] ?? '');

    if (empty($set_num) || empty($set_name)) {
        lww_log_to_job($job_id, sprintf('WARNUNG (Set): Zeile übersprungen. SetNum ("%s") oder Name ("%s") fehlt.', $set_num, $set_name));
        return;
    }

    // 1. Finde die zugehörige Theme-ID
    $theme_wp_id = 0;
    if ($theme_id_external > 0) {
        $theme_wp_id = lww_find_term_by_meta('lww_theme', 'lww_theme_id_external', $theme_id_external);
        if(empty($theme_wp_id)) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Set): Theme-ID "%d" für Set "%s" nicht gefunden.', $theme_id_external, $set_num));
        }
    }
    
    // 2. Erstelle oder aktualisiere den lww_set CPT
    $meta_key = 'lww_set_num'; 
    
    $existing_posts = get_posts([
        'post_type'  => 'lww_set',
        'meta_key'   => $meta_key,
        'meta_value' => $set_num,
        'posts_per_page' => 1, 'fields' => 'ids', 'post_status' => 'publish',
    ]);
    
    $post_data = [
        'post_title'   => $set_name,
        'post_status'  => 'publish',
        'post_type'    => 'lww_set',
    ];
    
    $post_id = 0;
    if (!empty($existing_posts)) {
        $post_id = $existing_posts[0];
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
    
    // 3. Metadaten und Taxonomie zuweisen
    if ($post_id > 0) {
        update_post_meta($post_id, $meta_key, $set_num);
        update_post_meta($post_id, 'lww_set_name', $set_name);
        update_post_meta($post_id, 'lww_rebrickable_id', $set_num);
        update_post_meta($post_id, 'lww_year_released', intval($data['year'] ?? 0));
        update_post_meta($post_id, 'lww_num_parts', intval($data['num_parts'] ?? 0));

        if ($theme_wp_id > 0) {
            wp_set_object_terms($post_id, (int)$theme_wp_id, 'lww_theme');
        }
        
        // 4. Bild importieren
        if (!empty($image_url)) {
            $img_result = lww_sideload_image_to_post($image_url, $post_id, $set_name);
            if (is_wp_error($img_result)) {
                 lww_log_to_job($job_id, sprintf('WARNUNG (Set): Bild für "%s" fehlgeschlagen: %s', $set_name, $img_result->get_error_message()));
            }
        }
    }
}

/**
 * KATALOG: Importiert EINE Zeile aus minifigs.csv
 * (Angepasst von lww_import_minifigs_csv)
 */
function lww_import_minifigs_data($job_id, $row_data_raw, $header_map) {
    // Annahme Header: fig_num, name, num_parts, img_url
    $data = lww_get_data_from_row($row_data_raw, $header_map);

    $fig_num = sanitize_text_field($data['fig_num'] ?? '');
    $fig_name = sanitize_text_field($data['name'] ?? '');
    $image_url = esc_url_raw($data['img_url'] ?? '');

    if (empty($fig_num) || empty($fig_name)) {
        lww_log_to_job($job_id, sprintf('WARNUNG (Minifig): Zeile übersprungen. FigNum ("%s") oder Name ("%s") fehlt.', $fig_num, $fig_name));
        return;
    }
    
    // 2. Erstelle oder aktualisiere den lww_minifig CPT
    $meta_key = 'lww_minifig_num'; 
    
    $existing_posts = get_posts([
        'post_type'  => 'lww_minifig',
        'meta_key'   => $meta_key,
        'meta_value' => $fig_num,
        'posts_per_page' => 1, 'fields' => 'ids', 'post_status' => 'publish',
    ]);
    
    $post_data = [
        'post_title'   => $fig_name,
        'post_status'  => 'publish',
        'post_type'    => 'lww_minifig',
    ];
    
    $post_id = 0;
    if (!empty($existing_posts)) {
        $post_id = $existing_posts[0];
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
    
    // 3. Metadaten
    if ($post_id > 0) {
        update_post_meta($post_id, $meta_key, $fig_num);
        update_post_meta($post_id, 'lww_minifig_name', $fig_name);
        update_post_meta($post_id, 'lww_rebrickable_id', $fig_num);
        update_post_meta($post_id, 'lww_num_parts', intval($data['num_parts'] ?? 0));
        
        // 4. Bild importieren
        if (!empty($image_url)) {
            $img_result = lww_sideload_image_to_post($image_url, $post_id, $fig_name);
            if (is_wp_error($img_result)) {
                 lww_log_to_job($job_id, sprintf('WARNUNG (Minifig): Bild für "%s" fehlgeschlagen: %s', $fig_name, $img_result->get_error_message()));
            }
        }
    }
}


// --- Platzhalter für zukünftige Implementierungen ---

function lww_import_elements_data($job_id, $data, $header_map) { 
    /* TODO: Logik für elements.csv (Part + Color -> Element ID) */ 
    // lww_log_to_job($job_id, 'HINWEIS: lww_import_elements_data ist noch nicht implementiert.');
}
function lww_import_part_relationships_data($job_id, $data, $header_map) { 
    /* TODO: Logik für part_relationships.csv (Alternate Parts, Molds) */ 
    // lww_log_to_job($job_id, 'HINWEIS: lww_import_part_relationships_data ist noch nicht implementiert.');
}


/**
 * KATALOG: Importiert EINE Zeile aus inventories.csv (Deckblatt)
 * Verknüpft eine Inventory ID mit einem Set oder einer Minifigur.
 */
function lww_import_inventories_data($job_id, $row_data_raw, $header_map) {
    // Annahme Header: id (inventory_id), version, set_num (kann auch fig_num sein)
    $data = lww_get_data_from_row($row_data_raw, $header_map);

    $inventory_id = intval($data['id'] ?? 0);
    $item_num = sanitize_text_field($data['set_num'] ?? ''); // Kann Set- oder Fig-Nummer sein
    $version = intval($data['version'] ?? 1);

    if (empty($inventory_id) || empty($item_num)) {
         lww_log_to_job($job_id, sprintf('WARNUNG (Inventories): Zeile übersprungen. Inv-ID ("%s") oder Item-Num ("%s") fehlt.', $inventory_id, $item_num));
         return;
    }

    // Finde den zugehörigen Post (Set oder Minifig)
    $post_id = 0;
    
    // 1. Ist es ein Set?
    $post_id = lww_find_set_by_num($item_num);
    
    // 2. Wenn nicht, ist es eine Minifigur?
    if (empty($post_id)) {
        $post_id = lww_find_minifig_by_num($item_num);
    }
    
    if (empty($post_id)) {
         lww_log_to_job($job_id, sprintf('WARNUNG (Inventories): Item-Num "%s" (für Inv-ID %d) wurde weder als Set noch als Minifig gefunden.', $item_num, $inventory_id));
         return;
    }

    // Speichere die Inventory ID als Meta-Feld am Set/Minifig-Post
    // Wir speichern nur die ID der "Version 1" (oder der höchsten Version, falls die CSV sortiert ist)
    $existing_inv_id = get_post_meta($post_id, '_lww_inventory_id', true);
    if (empty($existing_inv_id) || $version >= get_post_meta($post_id, '_lww_inventory_version', true)) {
         update_post_meta($post_id, '_lww_inventory_id', $inventory_id);
         update_post_meta($post_id, '_lww_inventory_version', $version);
         lww_log_to_job($job_id, sprintf('INFO (Inventories): Inventar-ID %d wurde mit Post %d (%s) verknüpft.', $inventory_id, $post_id, $item_num));
    }
}


/**
 * KATALOG: Importiert EINE Zeile aus inventory_parts.csv (Stückliste)
 * Speichert die Teile-Beziehung auf dem Set/Minifig-Post.
 */
function lww_import_inventory_parts_data($job_id, $row_data_raw, $header_map) {
    // Annahme Header: inventory_id, part_num, color_id, quantity, is_spare
    $data = lww_get_data_from_row($row_data_raw, $header_map);

    $inventory_id = intval($data['inventory_id'] ?? 0);
    $part_num = sanitize_text_field($data['part_num'] ?? '');
    $color_id_external = intval($data['color_id'] ?? 0);
    $quantity = intval($data['quantity'] ?? 0);
    $is_spare = ($data['is_spare'] ?? 'f') === 't';

    if (empty($inventory_id) || empty($part_num) || $quantity <= 0) {
        lww_log_to_job($job_id, sprintf('WARNUNG (Inv-Parts): Zeile übersprungen. Inv-ID, PartNum oder Menge fehlt.'));
        return;
    }

    // --- 1. Finde die WordPress Post IDs ---
    
    // Finde den Set/Minifig Post (z.B. Post ID 456)
    $parent_post_id = lww_find_post_by_inventory_id($inventory_id);
    if (empty($parent_post_id)) {
        lww_log_to_job($job_id, sprintf('WARNUNG (Inv-Parts): Set/Minifig für Inventar-ID "%d" nicht gefunden. (Wurde inventories.csv importiert?)', $inventory_id));
        return;
    }
    
    // Finde den Teil Post (z.B. Post ID 789)
    $part_post_id = lww_find_part_by_boid($part_num); // lww_find_part_by_boid ist flexibler als by_num
    if (empty($part_post_id)) {
        lww_log_to_job($job_id, sprintf('WARNUNG (Inv-Parts): PartNum "%s" (für Inv-ID %d) nicht im Katalog gefunden.', $part_num, $inventory_id));
        return;
    }
    
    // Finde den Farb Post (z.B. Post ID 901)
    $color_post_id = lww_find_color_by_rebrickable_id($color_id_external);
    if (empty($color_post_id)) {
         lww_log_to_job($job_id, sprintf('WARNUNG (Inv-Parts): Color-ID "%d" (für Inv-ID %d, Part %s) nicht im Katalog gefunden.', $color_id_external, $inventory_id, $part_num));
         return;
    }

    // --- 2. Daten speichern ---

    // Wir müssen die alten Daten löschen, BEVOR wir neue hinzufügen.
    // Wir verwenden einen statischen Cache, um sicherzustellen, dass dies nur
    // EINMAL pro Set/Minifig pro Job-Durchlauf passiert.
    static $inventories_cleared = [];
    $job_cache_key = $job_id . '_' . $parent_post_id;

    if (!isset($inventories_cleared[$job_cache_key])) {
        // Dies ist das erste Teil, das wir für dieses Set in DIESEM Job sehen.
        // Lösche die gesamte alte Stückliste, um sie neu aufzubauen.
        delete_post_meta($parent_post_id, '_lww_inventory_part_line');
        $inventories_cleared[$job_cache_key] = true;
        lww_log_to_job($job_id, sprintf('INFO (Inv-Parts): Alte Stückliste für Post ID %d (Inv-ID %d) wird gelöscht und neu aufgebaut...', $parent_post_id, $inventory_id));
    }

    // Erstelle die Zeile für die Stückliste
    // Format: "Part_Post_ID|Color_Post_ID|Menge|IsSpare(1 oder 0)"
    $line_data = implode('|', [
        $part_post_id,
        $color_post_id,
        $quantity,
        (int)$is_spare 
    ]);

    // Füge die Zeile als neues, separates Meta-Feld hinzu.
    // (add_post_meta erlaubt mehrere Einträge mit demselben Schlüssel)
    add_post_meta($parent_post_id, '_lww_inventory_part_line', $line_data, false);
}


function lww_import_inventory_sets_data($job_id, $data, $header_map) { 
    /* TODO: Logik für inventory_sets.csv (Stückliste: 1x Set X enthält 1x Set Y) */ 
    // lww_log_to_job($job_id, 'HINWEIS: lww_import_inventory_sets_data ist noch nicht implementiert.');
}
function lww_import_inventory_minifigs_data($job_id, $data, $header_map) { 
    /* TODO: Logik für inventory_minifigs.csv (Stückliste: 1x Set X enthält 1x Minifig Y) */ 
    // lww_log_to_job($job_id, 'HINWEIS: lww_import_inventory_minifigs_data ist noch nicht implementiert.');
}


// ########################################################################
// ##### INVENTAR-IMPORT-FUNKTIONEN (Zeilen-Verarbeitung)
// ########################################################################

/**
 * INVENTAR: Verarbeitet EINE Zeile der BrickOwl Inventar-CSV.
 * Erstellt oder aktualisiert einen 'lww_inventory_item' Post.
 */
function lww_import_inventory_data($job_id, $row_data_raw, $header_map) {
    
    // 1. Daten aus der Zeile extrahieren
    // $header_map ist [ 'boid' => 0, 'color_name' => 1, ... ]
    $data = [];
    foreach ($header_map as $key => $index) {
        if ($index !== false && isset($row_data_raw[$index])) {
            $data[$key] = trim($row_data_raw[$index]);
        }
    }

    // 2. Wichtige Daten validieren
    $boid = sanitize_text_field($data['boid'] ?? '');
    $color_name = sanitize_text_field($data['color_name'] ?? '');
    $condition = sanitize_text_field($data['condition'] ?? 'new');
    $quantity = intval($data['quantity'] ?? 0);
    $price = floatval($data['price'] ?? 0.0);

    if (empty($boid) || empty($color_name)) {
        lww_log_to_job($job_id, sprintf('WARNUNG (Inventar): Zeile übersprungen. BOID ("%s") oder Farbe ("%s") fehlt.', $boid, $color_name));
        return;
    }
    
    $condition = strtolower($condition);
    if ($condition !== 'new' && $condition !== 'used') {
        $condition = 'new'; // Standard-Fallback
    }

    // 3. Verknüpfte Katalog-Posts finden (Teil und Farbe)
    $part_post_id = lww_find_part_by_boid($boid);
    $color_post_id = lww_find_color_by_name($color_name);

    if (empty($part_post_id)) {
        lww_log_to_job($job_id, sprintf('WARNUNG (Inventar): Item "%s" (%s) übersprungen. Katalog-Teil (Part) mit BOID "%s" nicht gefunden.', $data['name'] ?? 'Unbekannt', $boid, $boid));
        return; 
    }
    if (empty($color_post_id)) {
        lww_log_to_job($job_id, sprintf('WARNUNG (Inventar): Item "%s" (%s) übersprungen. Katalog-Farbe (Color) mit Namen "%s" nicht gefunden.', $data['name'] ?? 'Unbekannt', $boid, $color_name));
        return;
    }

    // 4. Eindeutigen Titel und UID erstellen
    $post_title = sprintf('%s - %s (%s)', $boid, $color_name, $condition);
    $unique_meta_key = '_lww_inventory_uid';
    $unique_meta_value = $boid . '|' . $color_post_id . '|' . $condition;

    // 5. Prüfen, ob dieser Inventar-Posten BEREITS EXISTIERT
    $existing_posts = get_posts([
        'post_type'      => 'lww_inventory_item',
        'post_status'    => 'publish',
        'meta_key'       => $unique_meta_key,
        'meta_value'     => $unique_meta_value,
        'posts_per_page' => 1,
        'fields'         => 'ids',
    ]);

    $post_data = [
        'post_title'   => $post_title,
        'post_status'  => 'publish',
        'post_type'    => 'lww_inventory_item',
    ];
    
    $inventory_post_id = 0;

    // 6. Logik: UPDATE (falls gefunden) oder INSERT (falls neu)
    if (!empty($existing_posts)) {
        // --- DATENSATZ AKTUALISIEREN ---
        $inventory_post_id = $existing_posts[0];
        $post_data['ID'] = $inventory_post_id;
        wp_update_post($post_data);
    } else {
        // --- NEUEN DATENSATZ ERSTELLEN ---
        $inventory_post_id = wp_insert_post($post_data, true);
        if (is_wp_error($inventory_post_id)) {
            lww_log_to_job($job_id, sprintf('FEHLER (Inventar-Insert): Konnte "%s" nicht erstellen: %s', $post_title, $inventory_post_id->get_error_message()));
            return;
        }
        lww_log_to_job($job_id, sprintf('INFO (Inventar-Insert): "%s" (ID: %d) NEU erstellt.', $post_title, $inventory_post_id));
    }

    // 7. Metadaten für den Posten (egal ob neu oder Update) speichern
    if ($inventory_post_id > 0) {
        update_post_meta($inventory_post_id, $unique_meta_key, $unique_meta_value);
        update_post_meta($inventory_post_id, '_lww_part_id', $part_post_id);
        update_post_meta($inventory_post_id, '_lww_color_id', $color_post_id);
        update_post_meta($inventory_post_id, '_lww_boid', $boid);
        update_post_meta($inventory_post_id, '_lww_color_name', $color_name);
        update_post_meta($inventory_post_id, '_lww_condition', $condition);
        update_post_meta($inventory_post_id, '_lww_quantity', $quantity);
        update_post_meta($inventory_post_id, '_lww_price', $price);
        update_post_meta($inventory_post_id, '_lww_bulk', intval($data['bulk'] ?? 1));
        update_post_meta($inventory_post_id, '_lww_remarks', sanitize_textarea_field($data['remarks'] ?? ''));
        update_post_meta($inventory_post_id, '_lww_location', sanitize_text_field($data['location'] ?? ''));
        update_post_meta($inventory_post_id, '_lww_external_id', sanitize_text_field($data['external_id'] ?? ''));
    }
}


/**
 * INVENTAR-HELFER: Findet einen 'lww_part' Post anhand der BrickOwl ID (BOID).
 * Verwendet jetzt die Rebrickable-Daten, die wir als lww_brickowl_id speichern (hoffentlich)
 */
function lww_find_part_by_boid($boid) {
    if (empty($boid)) return 0;
    static $part_cache = [];
    if (isset($part_cache[$boid])) return $part_cache[$boid];

    // BrickOwl verwendet oft die Rebrickable PartNum als BOID,
    // ABER manchmal auch die BrickLink ID.
    // Wir suchen in allen relevanten Meta-Feldern.
    
    $args = [
        'post_type'      => 'lww_part',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => [
            'relation' => 'OR',
            [ 'key' => 'lww_part_num', 'value' => $boid ], // Rebrickable ID
            [ 'key' => 'lww_rebrickable_id', 'value' => $boid ], // Alias
            [ 'key' => 'lww_brickowl_id', 'value' => $boid ], // Explizite BO ID
            [ 'key' => 'lww_bricklink_id', 'value' => $boid ], // Manchmal ist es die BL ID
        ]
    ];
    
    $query = new WP_Query($args);
    if ($query->have_posts()) {
        $part_cache[$boid] = $query->posts[0];
        return $query->posts[0];
    }
    
    $part_cache[$boid] = 0;
    return 0;
}

/**
 * INVENTAR-HELFER: Findet einen 'lww_color' Post anhand des Namens.
 */
function lww_find_color_by_name($color_name) {
    if (empty($color_name)) return 0;
    
    static $color_cache = [];
    $cache_key = sanitize_title($color_name);
    if (isset($color_cache[$cache_key])) return $color_cache[$cache_key];

    // 1. Versuche exakten Titel
    $post = get_page_by_title($color_name, OBJECT, 'lww_color');
    if ($post) {
         $color_cache[$cache_key] = $post->ID;
         return $post->ID;
    }
    
    // 2. Versuche Meta-Feld
    $args = [
        'post_type'      => 'lww_color',
        'post_status'    => 'publish',
        'meta_key'       => 'lww_color_name', // Das Meta-Feld, das wir selbst setzen
        'meta_value'     => $color_name,
        'posts_per_page' => 1,
        'fields'         => 'ids',
    ];
    $query = new WP_Query($args);

    if ($query->have_posts()) {
        $color_cache[$cache_key] = $query->posts[0];
        return $query->posts[0];
    }

    $color_cache[$cache_key] = 0;
    return 0;
}

?>
