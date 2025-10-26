<?php
/**
 * Modul: Admin Spalten Anpassungen (v9.5)
 *
 * Fügt benutzerdefinierte Spalten zu den Admin-Listenansichten hinzu
 * (z.B. Farbvorschau, Thumbnails).
 */
if (!defined('ABSPATH')) exit;

/**
 * =========================================================================
 * SPALTEN FÜR 'lww_color' (Farben)
 * =========================================================================
 */

/**
 * Fügt neue Spalten zur lww_color Liste hinzu.
 */
function lww_add_color_columns($columns) {
    // Füge die Spalten nach der 'title'-Spalte ein
    $new_columns = [];
    foreach ($columns as $key => $title) {
        $new_columns[$key] = $title;
        if ($key === 'title') {
            $new_columns['color_preview'] = __('Vorschau', 'lego-wawi');
            $new_columns['color_rgb'] = __('RGB', 'lego-wawi');
            $new_columns['rebrickable_id'] = __('Rebrickable ID', 'lego-wawi');
        }
    }
    unset($new_columns['date']); // Datum entfernen
    return $new_columns;
}
add_filter('manage_lww_color_posts_columns', 'lww_add_color_columns');

/**
 * Füllt die benutzerdefinierten Spalten in der lww_color Liste mit Inhalt.
 */
function lww_render_color_columns($column_name, $post_id) {
    switch ($column_name) {
        case 'color_preview':
            $rgb = get_post_meta($post_id, 'lww_rgb_hex', true); // Meta-Key korrigiert
            $is_trans = get_post_meta($post_id, 'lww_is_transparent', true);
            $style = '';
            $class = 'lww-color-preview'; // CSS-Klasse aus lww-admin-styles.css
            $inner_style = '';

            if ($rgb) {
                if ($is_trans) {
                    $class .= ' transparent'; // Klasse für transparenten Hintergrund
                     // Setze die tatsächliche Farbe auf ein inneres Element
                    $inner_style = 'style="background-color: #' . esc_attr($rgb) . '; opacity: 0.7;"';
                } else {
                    $style = 'style="background-color: #' . esc_attr($rgb) . ';"';
                }
                 printf(
                    '<div class="%s" %s title="#%s"><div class="lww-color-preview-inner" %s></div></div>',
                    esc_attr($class),
                    $style, // Style für das äußere Div (nur bei nicht-transparent)
                    esc_attr($rgb),
                    $inner_style // Style für das innere Div (nur bei transparent)
                 );

            } else {
                echo '---';
            }
            break;

        case 'color_rgb':
            $rgb = get_post_meta($post_id, 'lww_rgb_hex', true); // Meta-Key korrigiert
            echo $rgb ? '#' . esc_html($rgb) : '---';
            break;

        case 'rebrickable_id':
             // Meta-Key korrigiert von _rebrickable_id zu _lww_rebrickable_id
            $rb_id = get_post_meta($post_id, '_lww_rebrickable_id', true); 
             if ($rb_id || $rb_id === 0 || $rb_id === '0') { // Rebrickable ID 0 ist gültig (Unknown)
                 printf(
                    '<a href="https://rebrickable.com/colors/%d/" target="_blank">%d</a>',
                    absint($rb_id),
                    absint($rb_id)
                 );
             } else {
                echo '---';
             }
            break;
    }
}
add_action('manage_lww_color_posts_custom_column', 'lww_render_color_columns', 10, 2);

/**
 * Macht die Rebrickable ID Spalte sortierbar.
 */
function lww_make_color_columns_sortable($columns) {
     // Meta-Key korrigiert
    $columns['rebrickable_id'] = '_lww_rebrickable_id';
    return $columns;
}
add_filter('manage_edit-lww_color_sortable_columns', 'lww_make_color_columns_sortable');

/**
 * Passt die WP_Query an, wenn nach der Rebrickable ID sortiert wird.
 */
function lww_sort_color_by_rebrickable_id($query) {
    if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'lww_color') {
        return;
    }

    $orderby = $query->get('orderby');

     // Meta-Key korrigiert
    if ('_lww_rebrickable_id' === $orderby) {
        $query->set('meta_key', '_lww_rebrickable_id');
        $query->set('orderby', 'meta_value_num'); // Als Zahl sortieren
    }
}
add_action('pre_get_posts', 'lww_sort_color_by_rebrickable_id');


/**
 * =========================================================================
 * THUMBNAIL-SPALTEN FÜR PARTS, SETS & MINIFIGS (NEU)
 * =========================================================================
 */

/**
 * Fügt die Spalte "Bild" zu den CPT-Listen hinzu.
 * Wird für Parts, Sets und Minifigs wiederverwendet.
 */
function lww_add_common_thumbnail_column($columns) {
    $cb = $columns['cb'] ?? ''; // Checkbox sichern
    if ($cb) {
        unset($columns['cb']); // Temporär entfernen
    }
    
    // Neue Spaltenstruktur: Checkbox, Bild, Rest
    $new_columns = [
        'cb' => $cb, // Checkbox wieder an den Anfang
        'thumbnail' => __('Bild', 'lego-wawi'),
    ];
    
    return array_merge($new_columns, $columns); // Fügt Bild nach Checkbox ein
}

/**
 * Rendert das Beitragsbild (Thumbnail) in der "Bild"-Spalte.
 * Wird für Parts, Sets und Minifigs wiederverwendet.
 */
function lww_render_common_thumbnail_column($column_name, $post_id) {
    if ($column_name === 'thumbnail') {
        $thumb_size = [60, 60]; // Breite, Höhe in Pixeln
        $edit_link = get_edit_post_link($post_id);

        echo '<a href="' . esc_url($edit_link) . '" style="display:inline-block; width:' . $thumb_size[0] . 'px; height:' . $thumb_size[1] . 'px; text-align:center; background:#f0f0f1; border:1px solid #ddd; vertical-align:middle;">';
        
        if (has_post_thumbnail($post_id)) {
            // Zeige das Beitragsbild mit der definierten Größe
            the_post_thumbnail($thumb_size, ['style' => 'max-width:100%; height:auto; display:block;']); 
        } else {
            // Zeige einen Platzhalter (z.B. Dashicon)
             // Prüfe, ob das Plugin-URL definiert ist, bevor es verwendet wird
             if (defined('LWW_PLUGIN_URL')) {
                 $placeholder_url = LWW_PLUGIN_URL . 'assets/images/placeholder.png'; // Beispielpfad
                 // Prüfe, ob die Platzhalterdatei existiert
                 if (file_exists(LWW_PLUGIN_PATH . 'assets/images/placeholder.png')) {
                      echo '<img src="' . esc_url($placeholder_url) . '" alt="' . __('Kein Bild', 'lego-wawi') . '" width="' . $thumb_size[0] . '" height="' . $thumb_size[1] . '" style="object-fit:contain;">';
                 } else {
                      // Fallback, wenn Bilddatei nicht existiert
                      echo '<span class="dashicons dashicons-format-image" style="font-size: ' . ($thumb_size[0] * 0.8) . 'px; line-height: ' . $thumb_size[1] . 'px; width: 100%; height: 100%; color: #ccc;"></span>';
                 }
             } else {
                 // Fallback, wenn Plugin-URL nicht verfügbar ist
                 echo '<span class="dashicons dashicons-format-image" style="font-size: ' . ($thumb_size[0] * 0.8) . 'px; line-height: ' . $thumb_size[1] . 'px; width: 100%; height: 100%; color: #ccc;"></span>';
             }
        }
        echo '</a>';
    }
}

// --- Hooks für 'lww_part' (Teile) ---
add_filter('manage_lww_part_posts_columns', 'lww_add_common_thumbnail_column');
add_action('manage_lww_part_posts_custom_column', 'lww_render_common_thumbnail_column', 10, 2);

// --- Hooks für 'lww_set' (Sets) ---
add_filter('manage_lww_set_posts_columns', 'lww_add_common_thumbnail_column');
add_action('manage_lww_set_posts_custom_column', 'lww_render_common_thumbnail_column', 10, 2);

// --- Hooks für 'lww_minifig' (Minifiguren) ---
add_filter('manage_lww_minifig_posts_columns', 'lww_add_common_thumbnail_column');
add_action('manage_lww_minifig_posts_custom_column', 'lww_render_common_thumbnail_column', 10, 2);

// TODO: Spalten für 'lww_inventory_item' hinzufügen (z.B. Menge, Preis, Zustand, verknüpftes Teil/Farbe)

?>
