<?php
/**
 * Modul: Admin Spalten Anpassungen (v14.0)
 *
 * Fügt benutzerdefinierte, sortierbare Spalten zu den Admin-listenansichten hinzu
 * und macht relevante Daten (Teile, Farben) klickbar.
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
            $rgb = get_post_meta($post_id, '_lww_rgb_hex', true);
            $is_trans = get_post_meta($post_id, '_lww_is_transparent', true);
            $style = '';
            $class = 'lww-color-preview';
            $inner_style = '';

            if ($rgb) {
                if ($is_trans) {
                    $class .= ' transparent';
                    $inner_style = 'style="background-color: #' . esc_attr($rgb) . '; opacity: 0.7;"';
                } else {
                    $style = 'style="background-color: #' . esc_attr($rgb) . '"';
                }
                printf(
                    '<span class="%s" %s title="#%s"><span class="lww-color-preview-inner" %s></span></span>',
                    esc_attr($class),
                    $style,
                    esc_attr($rgb),
                    $inner_style
                );
            } else {
                echo '---';
            }
            break;

        case 'color_rgb':
            $rgb = get_post_meta($post_id, '_lww_rgb_hex', true);
            echo $rgb ? '#' . esc_html($rgb) : '---';
            break;

        case 'rebrickable_id':
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

    if ('_lww_rebrickable_id' === $orderby) {
        $query->set('meta_key', '_lww_rebrickable_id');
        $query->set('orderby', 'meta_value_num'); // Als Zahl sortieren
    }
}
add_action('pre_get_posts', 'lww_sort_color_by_rebrickable_id');


/**
 * =========================================================================
 * SPALTEN FÜR KATALOG-CPTs (PARTS, SETS, MINIFIGS)
 * =========================================================================
 */

/**
 * Fügt benutzerdefinierte Spalten für Katalog-CPTs hinzu.
 * Diese Funktion wird für Parts, Sets und Minifigs wiederverwendet.
 */
function lww_add_catalog_columns($columns) {
    // Thumbnail nach der Checkbox einfügen
    $new_columns = [];
    if (isset($columns['cb'])) {
        $new_columns['cb'] = $columns['cb'];
        unset($columns['cb']);
    }
    $new_columns['thumbnail'] = __('Bild', 'lego-wawi');

    // Originalspalten mergen
    $columns = array_merge($new_columns, $columns);
    
    // Spalten nach dem Titel einfügen
    $final_columns = [];
    $post_type = get_current_screen()->post_type;

    foreach ($columns as $key => $title) {
        $final_columns[$key] = $title;
        if ($key === 'title') {
            switch ($post_type) {
                case 'lww_part':
                    $final_columns['part_num'] = __('Part-Nummer', 'lego-wawi');
                    $final_columns['part_category'] = __('Kategorie', 'lego-wawi');
                    break;
                case 'lww_set':
                    $final_columns['set_num'] = __('Set-Nummer', 'lego-wawi');
                    $final_columns['num_parts'] = __('Teile', 'lego-wawi');
                    $final_columns['year_released'] = __('Jahr', 'lego-wawi');
                    $final_columns['theme'] = __('Thema', 'lego-wawi');
                    break;
                case 'lww_minifig':
                    $final_columns['minifig_num'] = __('Figur-Nummer', 'lego-wawi');
                    $final_columns['num_parts'] = __('Teile', 'lego-wawi');
                    break;
            }
        }
    }
    
    if(isset($final_columns['date'])) {
        $date_col = $final_columns['date'];
        unset($final_columns['date']); // Datum am Ende entfernen
        $final_columns['date'] = $date_col; // Und wieder ans Ende setzen
    }
    
    return $final_columns;
}

/**
 * Rendert den Inhalt für die benutzerdefinierten Katalog-Spalten.
 */
function lww_render_catalog_columns($column_name, $post_id) {
    // Thumbnail-Rendering (wird von allen genutzt)
    if ($column_name === 'thumbnail') {
        $thumb_size = [80, 80]; // ** GRÖSSE ERHÖHT **
        $edit_link = get_edit_post_link($post_id);

        echo '<a href="' . esc_url($edit_link) . '" style="display:inline-block; width:' . $thumb_size[0] . 'px; height:' . $thumb_size[1] . 'px; text-align:center; background:#f0f0f1; border:1px solid #ddd; vertical-align:middle;">';
        
        if (has_post_thumbnail($post_id)) {
            the_post_thumbnail($thumb_size, ['style' => 'max-width:100%; height:auto; display:block;']); 
        } else {
            $item_num = '';
            $img_src = '';
            $post_type = get_post_type($post_id);

            // Versuche, ein Fallback-Bild von BrickLink zu laden
            if ($post_type === 'lww_part') {
                $item_num = get_post_meta($post_id, '_lww_part_num', true);
                if ($item_num) $img_src = 'https://img.bricklink.com/ItemImage/PN/0/' . urlencode($item_num) . '.png';
            } elseif ($post_type === 'lww_set') {
                $item_num = get_post_meta($post_id, '_lww_set_num', true);
                if ($item_num) $img_src = 'https://img.bricklink.com/ItemImage/SN/0/' . urlencode($item_num) . '.png';
            } elseif ($post_type === 'lww_minifig') {
                $item_num = get_post_meta($post_id, '_lww_minifig_num', true);
                if ($item_num) $img_src = 'https://img.bricklink.com/ItemImage/MN/0/' . urlencode($item_num) . '.png';
            }

            // Zeige das Fallback-Bild oder einen Platzhalter
            if ($img_src) {
                 echo '<img src="' . esc_url($img_src) . '" alt="' . __('Fallback-Bild', 'lego-wawi') . '" width="' . $thumb_size[0] . '" height="' . $thumb_size[1] . '" style="object-fit:contain;" onerror="this.style.display=\'none\'; this.nextSibling.style.display=\'block\'" />';
                 echo '<span class="dashicons dashicons-format-image" style="display:none; font-size: ' . ($thumb_size[0] * 0.8) . 'px; line-height: ' . $thumb_size[1] . 'px; width: 100%; height: 100%; color: #ccc;"></span>';
            } else {
                 echo '<span class="dashicons dashicons-format-image" style="font-size: ' . ($thumb_size[0] * 0.8) . 'px; line-height: ' . $thumb_size[1] . 'px; width: 100%; height: 100%; color: #ccc;"></span>';
            }
        }
        echo '</a>';
        return;
    }

    // Spezifische Spalten je nach Post-Typ
    switch (get_post_type($post_id)) {
        case 'lww_part':
            switch ($column_name) {
                case 'part_num':
                    echo esc_html(get_post_meta($post_id, '_lww_part_num', true)) ?: '---';
                    break;
                case 'part_category':
                    echo get_the_term_list($post_id, 'lww_part_category', '', ', ', '') ?: '---';
                    break;
            }
            break;
        case 'lww_set':
            switch ($column_name) {
                case 'set_num':
                    echo esc_html(get_post_meta($post_id, '_lww_set_num', true)) ?: '---';
                    break;
                case 'num_parts':
                    echo (int) get_post_meta($post_id, '_lww_num_parts', true);
                    break;
                case 'year_released':
                    echo (int) get_post_meta($post_id, '_lww_year_released', true) ?: '---';
                    break;
                case 'theme':
                    echo get_the_term_list($post_id, 'lww_theme', '', ', ', '') ?: '---';
                    break;
            }
            break;
        case 'lww_minifig':
            switch ($column_name) {
                case 'minifig_num':
                    echo esc_html(get_post_meta($post_id, '_lww_minifig_num', true)) ?: '---';
                    break;
                case 'num_parts':
                    echo (int) get_post_meta($post_id, '_lww_num_parts', true);
                    break;
            }
            break;
    }
}

/**
 * Macht die neuen Katalog-Spalten sortierbar.
 */
function lww_make_catalog_columns_sortable($columns) {
    $post_type = get_current_screen()->post_type;
    switch ($post_type) {
        case 'lww_part':
            $columns['part_num'] = '_lww_part_num';
            $columns['part_category'] = 'taxonomy-lww_part_category';
            break;
        case 'lww_set':
            $columns['set_num'] = '_lww_set_num';
            $columns['num_parts'] = '_lww_num_parts';
            $columns['year_released'] = '_lww_year_released';
            $columns['theme'] = 'taxonomy-lww_theme';
            break;
        case 'lww_minifig':
            $columns['minifig_num'] = '_lww_minifig_num';
            $columns['num_parts'] = '_lww_num_parts';
            break;
    }
    return $columns;
}

/**
 * Passt die WP_Query für die Sortierung der Katalog-Spalten an.
 */
function lww_sort_catalog_columns_query($query) {
    if (!is_admin() || !$query->is_main_query()) {
        return;
    }

    $orderby = $query->get('orderby');
    $post_type = $query->get('post_type');

    $meta_sort_keys = [
        'lww_part' => ['_lww_part_num'],
        'lww_set' => ['_lww_set_num', '_lww_num_parts', '_lww_year_released'],
        'lww_minifig' => ['_lww_minifig_num', '_lww_num_parts'],
    ];

    if (isset($meta_sort_keys[$post_type]) && in_array($orderby, $meta_sort_keys[$post_type])) {
        $query->set('meta_key', $orderby);
        if (in_array($orderby, ['_lww_num_parts', '_lww_year_released'])) {
            $query->set('orderby', 'meta_value_num');
        } else {
            $query->set('orderby', 'meta_value');
        }
    }
}


// --- Hooks für Katalog-CPTs ---
add_filter('manage_lww_part_posts_columns', 'lww_add_catalog_columns');
add_action('manage_lww_part_posts_custom_column', 'lww_render_catalog_columns', 10, 2);
add_filter('manage_edit-lww_part_sortable_columns', 'lww_make_catalog_columns_sortable');

add_filter('manage_lww_set_posts_columns', 'lww_add_catalog_columns');
add_action('manage_lww_set_posts_custom_column', 'lww_render_catalog_columns', 10, 2);
add_filter('manage_edit-lww_set_sortable_columns', 'lww_make_catalog_columns_sortable');

add_filter('manage_lww_minifig_posts_columns', 'lww_add_catalog_columns');
add_action('manage_lww_minifig_posts_custom_column', 'lww_render_catalog_columns', 10, 2);
add_filter('manage_edit-lww_minifig_sortable_columns', 'lww_make_catalog_columns_sortable');

add_action('pre_get_posts', 'lww_sort_catalog_columns_query');

/**
 * Fügt Taxonomie-Filter zu den Admin-Listenansichten hinzu.
 */
function lww_add_taxonomy_filters() {
    global $typenow;

    $taxonomies = [];
    if ($typenow === 'lww_part') {
        $taxonomies['lww_part_category'] = __('Nach Kategorie filtern', 'lego-wawi');
    } elseif ($typenow === 'lww_set') {
        $taxonomies['lww_theme'] = __('Nach Thema filtern', 'lego-wawi');
    }

    if (empty($taxonomies)) {
        return;
    }

    foreach ($taxonomies as $tax_slug => $label) {
        $tax_obj = get_taxonomy($tax_slug);
        wp_dropdown_categories([
            'show_option_all' => $tax_obj->labels->all_items,
            'taxonomy'        => $tax_slug,
            'name'            => $tax_slug,
            'orderby'         => 'name',
            'selected'        => $_GET[$tax_slug] ?? '',
            'hierarchical'    => true,
            'show_count'      => true,
            'hide_empty'      => true,
        ]);
    }
}
add_action('restrict_manage_posts', 'lww_add_taxonomy_filters');

/**
 * Fügt Meta-basierte Filter für die lww_inventory_item Liste hinzu.
 */
function lww_add_inventory_meta_filters() {
    global $typenow;
    if ($typenow !== 'lww_inventory_item') {
        return;
    }

    // Nachfrage-Score Filter
    $demand_filter_options = [
        '' => __('Alle Nachfrage-Scores', 'lego-wawi'),
        'high' => __('Hoch (75+)', 'lego-wawi'),
        'medium' => __('Mittel (40-74)', 'lego-wawi'),
        'low' => __('Niedrig (< 40)', 'lego-wawi'),
        'none' => __('Ohne Score', 'lego-wawi'),
    ];
    $current_demand_filter = $_GET['demand_filter'] ?? '';

    echo '<select name="demand_filter">';
    foreach ($demand_filter_options as $value => $label) {
        printf(
            '<option value="%s" %s>%s</option>',
            esc_attr($value),
            selected($current_demand_filter, $value, false),
            esc_html($label)
        );
    }
    echo '</select>';
}
add_action('restrict_manage_posts', 'lww_add_inventory_meta_filters');

/**
 * =========================================================================
 * SPALTEN FÜR 'lww_inventory_item' (Inventar)
 * =========================================================================
 */

function lww_add_inventory_item_columns($columns) {
    unset($columns['title'], $columns['date']);

    return [
        'cb'          => '<input type="checkbox" />',
        'part_image'  => __('Bild', 'lego-wawi'),
        'part_info'   => __('Artikel / ID', 'lego-wawi'),
        'color_info'  => __('Farbe', 'lego-wawi'),
        'quantity'    => __('Menge', 'lego-wawi'),
        'price'       => __('Preis', 'lego-wawi'),
        'price_history' => __('Preis-Info', 'lego-wawi'),
        'demand'      => __('Nachfrage (KI)', 'lego-wawi'),
        'lww_inventory_location' => __('Lagerort', 'lego-wawi'),
        'condition'   => __('Zustand', 'lego-wawi'),
        'date'        => __('Datum', 'lego-wawi'),
    ];
}
add_filter('manage_lww_inventory_item_posts_columns', 'lww_add_inventory_item_columns');

function lww_render_inventory_item_columns($column_name, $post_id) {
    switch ($column_name) {
        case 'part_image':
            $catalog_id = get_post_meta($post_id, '_lww_part_id', true)
                          ?: get_post_meta($post_id, '_lww_set_id', true)
                          ?: get_post_meta($post_id, '_lww_minifig_id', true);
            if ($catalog_id) {
                lww_render_catalog_columns('thumbnail', $catalog_id);
            } else {
                lww_render_catalog_columns('thumbnail', 0);
            }
            break;

        case 'part_info':
            $part_id = get_post_meta($post_id, '_lww_part_id', true);
            $set_id = get_post_meta($post_id, '_lww_set_id', true);
            $minifig_id = get_post_meta($post_id, '_lww_minifig_id', true);
            $boid = get_post_meta($post_id, '_boid', true);
            $ebay_id = get_post_meta($post_id, '_lww_ebay_listing_id', true);
            
            $catalog_id = $part_id ?: $set_id ?: $minifig_id;
            $id_label = $boid ? 'BOID' : ($ebay_id ? 'eBay ID' : 'ID');
            $id_value = $boid ?: $ebay_id;

            if ($catalog_id) {
                printf(
                    '<strong><a href="%s">%s</a></strong><br><small>%s: %s</small>',
                    esc_url(get_edit_post_link($catalog_id)),
                    esc_html(get_the_title($catalog_id)),
                    esc_html($id_label),
                    esc_html($id_value)
                );
            } else {
                 echo '<strong>' . get_the_title($post_id) . '</strong><br>';
                 if($id_value) {
                    echo '<small>' . esc_html($id_label) . ': ' . esc_html($id_value) . '</small>';
                 }
            }
            break;

        case 'color_info':
            $color_id = get_post_meta($post_id, '_lww_color_id', true);
            if ($color_id) {
                lww_render_color_columns('color_preview', $color_id);
                printf(
                    '<a href="%s">%s</a>',
                    esc_url(get_edit_post_link($color_id)),
                    esc_html(get_the_title($color_id))
                );
            } else {
                echo esc_html(get_post_meta($post_id, '_color_name', true)) ?: '---';
            }
            break;

        case 'quantity':
            echo (int) get_post_meta($post_id, '_quantity', true);
            break;

        case 'price':
            $price = (float) get_post_meta($post_id, '_price', true);
            echo number_format($price, 3, ',', '.') . ' €';
            break;

        case 'price_history':
            $history = get_post_meta($post_id, '_lww_price_history', true);
            if (is_array($history) && !empty($history)) {
                $last_change = end($history);
                $timestamp = $last_change['timestamp'] ?? 0;
                $source = $last_change['source'] ?? 'unbekannt';

                if ($timestamp) {
                    printf(
                        '<span title="%s">%s</span><br><small>via %s</small>',
                        esc_attr__('Letzte Aktualisierung', 'lego-wawi'),
                        wp_date(get_option('date_format'), $timestamp),
                        esc_html(str_replace('_', ' ', ucwords($source, '_')))
                    );
                } else {
                    echo '---';
                }
            } else {
                echo '---';
            }
            break;

        case 'demand':
            $score = get_post_meta($post_id, '_lww_demand_score', true);
            if (is_numeric($score)) {
                $score = (int) $score;
                $color = '#777'; // Grau (Standard)
                if ($score >= 75) {
                    $color = '#2a9d8f'; // Grün
                } elseif ($score >= 40) {
                    $color = '#e9c46a'; // Gelb
                } else {
                    $color = '#e76f51'; // Rot
                }
                 printf(
                    '<strong style="color: %s; font-size: 1.1em;" title="%s">%d / 100</strong>',
                    esc_attr($color),
                    esc_attr__('KI-basierter Nachfrage-Score (1-100)', 'lego-wawi'),
                    $score
                );
            } else {
                printf(
                    '<span class="lww-demand-score-placeholder" title="%s">%s</span>',
                    esc_attr__('Noch kein Score berechnet. Nutzen Sie das Analyse-Werkzeug.', 'lego-wawi'),
                    '---'
                );
            }
            break;

        case 'condition':
            $condition = get_post_meta($post_id, '_condition', true);
            echo ($condition === 'new') ? __('Neu', 'lego-wawi') : __('Gebraucht', 'lego-wawi');
            break;
    }
}
add_action('manage_lww_inventory_item_posts_custom_column', 'lww_render_inventory_item_columns', 10, 2);

function lww_make_inventory_item_columns_sortable($columns) {
    $columns['part_info'] = 'title'; // Sortiert nach Post Title
    $columns['quantity'] = '_quantity';
    $columns['price'] = '_price';
    $columns['demand'] = '_lww_demand_score';
    $columns['condition'] = '_condition';
    $columns['lww_inventory_location'] = 'taxonomy-lww_inventory_location';
    return $columns;
}
add_filter('manage_edit-lww_inventory_item_sortable_columns', 'lww_make_inventory_item_columns_sortable');

function lww_sort_inventory_items_query($query) {
    if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'lww_inventory_item') {
        return;
    }

    // Handle sorting
    $orderby = $query->get('orderby');

    if ($orderby === '_quantity' || $orderby === '_price' || $orderby === '_lww_demand_score') {
        $query->set('meta_key', $orderby);
        $query->set('orderby', 'meta_value_num');
    } elseif ($orderby === '_condition') {
        $query->set('meta_key', $orderby);
        $query->set('orderby', 'meta_value');
    }

    $meta_query = $query->get('meta_query') ?: [];
    if (!is_array($meta_query)) {
        $meta_query = [];
    }

    // Handle demand score filter
    if (!empty($_GET['demand_filter'])) {
        $demand_filter = sanitize_key($_GET['demand_filter']);
        switch ($demand_filter) {
            case 'high':
                $meta_query[] = ['key' => '_lww_demand_score', 'value' => 75, 'compare' => '>=', 'type' => 'NUMERIC'];
                break;
            case 'medium':
                $meta_query[] = ['key' => '_lww_demand_score', 'value' => [40, 74], 'compare' => 'BETWEEN', 'type' => 'NUMERIC'];
                break;
            case 'low':
                $meta_query[] = ['key' => '_lww_demand_score', 'value' => 40, 'compare' => '<', 'type' => 'NUMERIC'];
                break;
            case 'none':
                $meta_query[] = ['key' => '_lww_demand_score', 'compare' => 'NOT EXISTS'];
                break;
        }
    }

    // Handle smart search
    $search_term = $query->get('s');
    if ($search_term) {
        // Prevent default search from running
        $query->set('s', '');

        $search_meta_query = ['relation' => 'OR'];
        $search_meta_query[] = ['key' => '_boid', 'value' => $search_term, 'compare' => 'LIKE'];
        $search_meta_query[] = ['key' => '_remarks', 'value' => $search_term, 'compare' => 'LIKE'];
        
        // Add search conditions to the main meta query
        $meta_query[] = $search_meta_query;

        // Add filter to modify WHERE clause for post_title
        add_filter('posts_where', function($where) use ($search_term) {
            global $wpdb;
            // Add OR condition for post_title
            $where .= $wpdb->prepare(" OR {$wpdb->posts}.post_title LIKE %s", '%' . $wpdb->esc_like($search_term) . '%');
            // Remove this filter immediately after use to avoid affecting other queries
            remove_filter('posts_where', __FUNCTION__);
            return $where;
        });
    }

    if (count($meta_query) > 1) {
        if (!isset($meta_query['relation'])) {
            $meta_query['relation'] = 'AND';
        }
        $query->set('meta_query', $meta_query);
    } elseif (count($meta_query) === 1) {
        $query->set('meta_query', $meta_query);
    }
}
add_action('pre_get_posts', 'lww_sort_inventory_items_query');


/**
 * =========================================================================
 * SPALTEN FÜR 'lww_api_log' (API-Log) - NEU
 * =========================================================================
 */

function lww_add_api_log_columns($columns) {
    unset($columns['title']);
    return [
        'cb' => $columns['cb'],
        'title' => __('Aktion / Endpunkt', 'lego-wawi'),
        'service' => __('Dienst', 'lego-wawi'),
        'status' => __('Status', 'lego-wawi'),
        'cost' => __('Kosten (Simuliert)', 'lego-wawi'),
        'date' => __('Datum', 'lego-wawi'),
    ];
}
add_filter('manage_lww_api_log_posts_columns', 'lww_add_api_log_columns');

function lww_render_api_log_columns($column_name, $post_id) {
    switch ($column_name) {
        case 'service':
            $service = get_post_meta($post_id, '_lww_service', true);
            echo '<strong>' . esc_html(strtoupper($service)) . '</strong>';
            break;
        case 'status':
            $status = get_post_meta($post_id, '_lww_status', true);
            if ($status === 'Success') {
                echo '<span style="color: #2a9d8f;">' . __('Erfolgreich', 'lego-wawi') . '</span>';
            } else {
                echo '<span style="color: #e76f51;">' . __('Fehlgeschlagen', 'lego-wawi') . '</span>';
            }
            break;
        case 'cost':
            $cost = (float) get_post_meta($post_id, '_lww_cost', true);
            echo '$' . number_format_i18n($cost, 5);
            break;
    }
}
add_action('manage_lww_api_log_posts_custom_column', 'lww_render_api_log_columns', 10, 2);

function lww_make_api_log_columns_sortable($columns) {
    $columns['service'] = '_lww_service';
    $columns['status'] = '_lww_status';
    $columns['cost'] = '_lww_cost';
    return $columns;
}
add_filter('manage_edit-lww_api_log_sortable_columns', 'lww_make_api_log_columns_sortable');

function lww_sort_api_log_query($query) {
    if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'lww_api_log') {
        return;
    }

    $orderby = $query->get('orderby');

    if (in_array($orderby, ['_lww_service', '_lww_status', '_lww_cost'])) {
        $query->set('meta_key', $orderby);
        $query->set('orderby', $orderby === '_lww_cost' ? 'meta_value_num' : 'meta_value');
    }
}
add_action('pre_get_posts', 'lww_sort_api_log_query');

?>