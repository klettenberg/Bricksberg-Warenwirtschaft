<?php
/**
 * Modul: Admin Spalten Anpassungen (NEU in v8.4, Stand v9.0)
 *
 * Fügt benutzerdefinierte Spalten zu den Admin-Listenansichten hinzu
 * (z.B. Farbvorschau für lww_color).
 */
if (!defined('ABSPATH')) exit;

/**
 * Fügt neue Spalten zur lww_color Liste hinzu.
 * Hook: manage_{post_type}_posts_columns
 */
function lww_add_color_columns($columns) {
    // Füge die 'color_preview'-Spalte nach der 'title'-Spalte ein
    $new_columns = [];
    foreach ($columns as $key => $title) {
        $new_columns[$key] = $title;
        if ($key === 'title') {
            $new_columns['color_preview'] = __('Vorschau', 'lego-wawi');
            $new_columns['color_rgb'] = __('RGB', 'lego-wawi');
            $new_columns['rebrickable_id'] = __('Rebrickable ID', 'lego-wawi');
        }
    }
    // Entferne die Standard-Datumsspalte, falls gewünscht
    unset($new_columns['date']);
    return $new_columns;
}
add_filter('manage_lww_color_posts_columns', 'lww_add_color_columns');

/**
 * Füllt die benutzerdefinierten Spalten in der lww_color Liste mit Inhalt.
 * Hook: manage_{post_type}_posts_custom_column
 */
function lww_render_color_columns($column_name, $post_id) {
    switch ($column_name) {
        case 'color_preview':
            $rgb = get_post_meta($post_id, '_rgb', true);
            if ($rgb) {
                // Erstellt ein kleines Farbfeld
                printf(
                    '<div style="width: 40px; height: 20px; background-color: %s; border: 1px solid #ccc;" title="%s"></div>',
                    esc_attr($rgb),
                    esc_attr($rgb) // Zeigt den RGB-Wert als Tooltip
                );
            } else {
                echo '---';
            }
            break;

        case 'color_rgb':
            $rgb = get_post_meta($post_id, '_rgb', true);
            echo $rgb ? esc_html($rgb) : '---';
            break;

        case 'rebrickable_id':
            $rb_id = get_post_meta($post_id, '_rebrickable_id', true);
             if ($rb_id) {
                 // Link zur Rebrickable-Farbseite
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
 * Hook: manage_edit-{post_type}_sortable_columns
 */
function lww_make_color_columns_sortable($columns) {
    $columns['rebrickable_id'] = '_rebrickable_id'; // Sortiere nach dem Meta-Key
    return $columns;
}
add_filter('manage_edit-lww_color_sortable_columns', 'lww_make_color_columns_sortable');

/**
 * Passt die WP_Query an, wenn nach der Rebrickable ID sortiert wird.
 * Hook: pre_get_posts
 */
function lww_sort_color_by_rebrickable_id($query) {
    // Nur im Admin-Bereich, nur für die Haupt-Query und nur für lww_color Posts
    if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'lww_color') {
        return;
    }

    $orderby = $query->get('orderby');

    if ('_rebrickable_id' === $orderby) {
        $query->set('meta_key', '_rebrickable_id');
        // Wichtig: Meta-Werte als Zahlen sortieren
        $query->set('orderby', 'meta_value_num');
    }
}
add_action('pre_get_posts', 'lww_sort_color_by_rebrickable_id');


// TODO: Ähnliche Funktionen können für andere CPTs (lww_part, lww_inventory_item etc.) hinzugefügt werden,
// um z.B. PartNum, BOID, Menge, Preis etc. als Spalten anzuzeigen.

?>