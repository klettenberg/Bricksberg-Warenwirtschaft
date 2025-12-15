<?php
/**
 * Modul: Admin Spalten Anpassungen (v21.5)
 * 
 * UPDATE: Farbpalette Sortierung nach Nuance (Hue) hinzugef gt.
 */
if (!defined('ABSPATH')) exit;

// --- DEUTSCHE NAMEN IM TITEL ANZEIGEN ---
function lww_append_german_name_to_title($title, $post_id) {
    if (!is_admin() || !function_exists('get_current_screen')) return $title;

    $screen = get_current_screen();
    $supported_screens = ['edit-lww_part', 'edit-lww_set', 'edit-lww_minifig'];

    if ($screen && in_array($screen->id, $supported_screens)) {
        $post_type = get_post_type($post_id);
        if (in_array($post_type, ['lww_part', 'lww_set', 'lww_minifig'])) {
            remove_filter('the_title', 'lww_append_german_name_to_title', 10, 2);
            $original_title = get_the_title($post_id);
            add_filter('the_title', 'lww_append_german_name_to_title', 10, 2);

            if($title === $original_title) {
                $meta_key = '_lww_' . str_replace('lww_', '', $post_type) . '_name_de';
                $german_name = get_post_meta($post_id, $meta_key, true);
                if (!empty($german_name)) {
                    $title = '<strong>' . esc_html($german_name) . '</strong><br><small style="color: #666;">' . esc_html($original_title) . '</small>';
                }
            }
        }
    }
    return $title;
}
add_filter('the_title', 'lww_append_german_name_to_title', 10, 2);

// --- FARBEN SPALTEN ---
function lww_add_color_columns($columns) {
    $new_columns = [];
    foreach ($columns as $key => $title) {
        $new_columns[$key] = $title;
        if ($key === 'title') {
            $new_columns['color_preview'] = __('Vorschau', 'lego-wawi');
            $new_columns['color_name_de'] = __('Name (DE)', 'lego-wawi');
            $new_columns['rebrickable_id'] = __('Rebrickable ID', 'lego-wawi');
            $new_columns['inventory_count'] = __('Bestand', 'lego-wawi');
        }
    }
    unset($new_columns['date']);
    return $new_columns;
}
add_filter('manage_lww_color_posts_columns', 'lww_add_color_columns');

function lww_render_color_columns($column_name, $post_id) {
    switch ($column_name) {
        case 'color_preview':
            $rgb = get_post_meta($post_id, '_lww_rgb_hex', true);
            $is_trans = get_post_meta($post_id, '_lww_is_transparent', true);
            if ($rgb) {
                $style = $is_trans ? 'opacity: 0.6;' : '';
                printf('<div style="width:30px; height:30px; background-color:#%s; border:1px solid #ccc; border-radius:3px; %s"></div>', esc_attr($rgb), $style);
            } else { echo '-'; }
            break;
        case 'color_name_de':
            $de = get_post_meta($post_id, '_lww_color_name_de', true);
            echo $de ? '<strong>'.esc_html($de).'</strong>' : '<span style="color:#ccc;">-</span>';
            break;
        case 'rebrickable_id':
            echo esc_html(get_post_meta($post_id, '_lww_rebrickable_id', true));
            break;
        case 'inventory_count':
            echo '-';
            break;
    }
}
add_action('manage_lww_color_posts_custom_column', 'lww_render_color_columns', 10, 2);

// --- FARB-PALETTE GRID (Textfrei) ---
function lww_add_color_view_mode($views) {
    $current_mode = isset($_GET['mode']) ? $_GET['mode'] : 'list';
    $base_url = admin_url('edit.php?post_type=lww_color');
    $grid_url = add_query_arg('mode', 'grid', $base_url);
    $list_url = remove_query_arg('mode', $base_url);

    $list_class = ($current_mode !== 'grid') ? 'current' : '';
    $grid_class = ($current_mode === 'grid') ? 'current' : '';

    $views['list'] = sprintf('<a href="%s" class="%s">%s</a>', esc_url($list_url), $list_class, __('Liste', 'lego-wawi'));
    $views['grid'] = sprintf('<a href="%s" class="%s">%s</a>', esc_url($grid_url), $grid_class, __('Farbtafel (Grid)', 'lego-wawi'));
    
    return $views;
}
add_filter('views_edit-lww_color', 'lww_add_color_view_mode');

function lww_hide_list_table_for_palette() {
    $screen = get_current_screen();
    if ($screen && $screen->id === 'edit-lww_color' && isset($_GET['mode']) && $_GET['mode'] === 'grid') {
        echo '<style>
            #posts-filter .wp-list-table, 
            #posts-filter .tablenav.top, 
            #posts-filter .tablenav.bottom,
            #posts-filter .search-box 
            { display: none !important; }
        </style>';
    }
}
add_action('admin_head-edit.php', 'lww_hide_list_table_for_palette');

function lww_render_color_palette_grid() {
    $screen = get_current_screen();
    if (!$screen || $screen->id !== 'edit-lww_color' || !isset($_GET['mode']) || $_GET['mode'] !== 'grid') return;

    $sort_by = isset($_GET['color_sort']) ? $_GET['color_sort'] : 'name';

    $colors = get_posts(['post_type' => 'lww_color', 'posts_per_page' => -1, 'post_status' => 'publish']);

    // Sortierung anwenden
    if ($sort_by === 'hue') {
        usort($colors, function($a, $b) {
            $h_a = (float) get_post_meta($a->ID, '_lww_hsl_hue', true);
            $h_b = (float) get_post_meta($b->ID, '_lww_hsl_hue', true);
            // Wenn H gleich, sortiere nach Lightness
            if ($h_a === $h_b) {
                $l_a = (float) get_post_meta($a->ID, '_lww_hsl_lightness', true);
                $l_b = (float) get_post_meta($b->ID, '_lww_hsl_lightness', true);
                return $l_a <=> $l_b;
            }
            return $h_a <=> $h_b;
        });
    } else {
        // Default: Name ASC
        usort($colors, function($a, $b) { return strcmp($a->post_title, $b->post_title); });
    }

    $base_url = add_query_arg(['mode' => 'grid'], admin_url('edit.php?post_type=lww_color'));
    $sort_url_name = add_query_arg('color_sort', 'name', $base_url);
    $sort_url_hue = add_query_arg('color_sort', 'hue', $base_url);

    ob_start();
    ?>
    <div id="lww-color-grid-container" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; margin-top: 10px;">
        <div style="margin-bottom: 15px;">
            <strong><?php _e('Sortierung:', 'lego-wawi'); ?></strong> 
            <a href="<?php echo esc_url($sort_url_name); ?>" style="<?php echo $sort_by==='name' ? 'font-weight:bold; color:#000;' : ''; ?>">Name</a> |
            <a href="<?php echo esc_url($sort_url_hue); ?>" style="<?php echo $sort_by==='hue' ? 'font-weight:bold; color:#000;' : ''; ?>">Nuance (Farbton)</a>
        </div>

        <?php if (empty($colors)): ?>
            <div class="notice notice-warning inline"><p><?php _e('Keine Farben gefunden. Bitte importieren Sie zuerst den Katalog.', 'lego-wawi'); ?></p></div>
        <?php else: ?>
            <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(60px, 1fr)); gap:10px;">
                <?php foreach ($colors as $color): 
                    $rgb = get_post_meta($color->ID, '_lww_rgb_hex', true);
                    $name_de = get_post_meta($color->ID, '_lww_color_name_de', true);
                    $display_name = $name_de ? $name_de : $color->post_title;
                    $rb_id = get_post_meta($color->ID, '_lww_rebrickable_id', true);
                    $is_trans = get_post_meta($color->ID, '_lww_is_transparent', true);

                    $bg = $rgb ? '#' . esc_attr($rgb) : '#eeeeee';
                    $opacity = $is_trans ? '0.7' : '1';
                    $edit_link = get_edit_post_link($color->ID);
                    $tooltip = sprintf("%s\nID: %s\n(Orig: %s)", $display_name, $rb_id, $color->post_title);
                ?>
                    <a href="<?php echo esc_url($edit_link); ?>" title="<?php echo esc_attr($tooltip); ?>" style="display:block; text-decoration:none;">
                        <div style="width:100%; aspect-ratio: 1/1; background-color:<?php echo $bg; ?>; opacity:<?php echo $opacity; ?>; border-radius:4px; box-shadow:0 1px 3px rgba(0,0,0,0.2); position:relative; transition: transform 0.1s;"></div>
                        <div style="font-size:10px; text-align:center; color:#555; overflow:hidden; white-space:nowrap; text-overflow:ellipsis; margin-top:3px;"><?php echo esc_html($display_name); ?></div>
                    </a>
                <?php endforeach; ?>
            </div>
            <style>#lww-color-grid-container a:hover > div:first-child { transform: scale(1.1); z-index:10; box-shadow:0 4px 8px rgba(0,0,0,0.3); }</style>
        <?php endif; ?>
    </div>
    <script>
    jQuery(document).ready(function($) {
        $('#posts-filter').append($('#lww-color-grid-container'));
    });
    </script>
    <?php
    echo ob_get_clean();
}
add_action('admin_footer-edit.php', 'lww_render_color_palette_grid');

// --- KATALOG SPALTEN ---
function lww_add_catalog_columns($columns) {
    $new_columns = [];
    if (isset($columns['cb'])) { $new_columns['cb'] = $columns['cb']; unset($columns['cb']); }
    $new_columns['thumbnail'] = __('Bild', 'lego-wawi');
    $columns = array_merge($new_columns, $columns);
    
    $post_type = get_current_screen()->post_type;
    if ($post_type === 'lww_part') {
        $columns['part_num'] = __('Teilenummer', 'lego-wawi');
        $columns['category'] = __('Kategorie', 'lego-wawi');
    } elseif ($post_type === 'lww_set') {
        $columns['set_num'] = __('Setnummer', 'lego-wawi');
        $columns['theme'] = __('Thema', 'lego-wawi');
        $columns['year'] = __('Jahr', 'lego-wawi');
    }
    return $columns;
}
add_filter('manage_lww_part_posts_columns', 'lww_add_catalog_columns');
add_filter('manage_lww_set_posts_columns', 'lww_add_catalog_columns');
add_filter('manage_lww_minifig_posts_columns', 'lww_add_catalog_columns');

function lww_render_catalog_custom_column($column, $post_id) {
    if ($column === 'thumbnail') {
        if (has_post_thumbnail($post_id)) echo get_the_post_thumbnail($post_id, [50, 50]);
        else {
            $img = get_post_meta($post_id, '_lww_sideload_image_url', true);
            echo $img ? '<img src="'.esc_url($img).'" style="width:50px;height:50px;object-fit:contain;">' : '<span class="dashicons dashicons-format-image" style="color:#ccc;"></span>';
        }
    }
    if ($column === 'part_num') echo get_post_meta($post_id, '_lww_part_num', true);
    if ($column === 'category') echo get_the_term_list($post_id, 'lww_part_category', '', ', ');
    if ($column === 'set_num') echo get_post_meta($post_id, '_lww_set_num', true);
    if ($column === 'theme') echo get_the_term_list($post_id, 'lww_theme', '', ', ');
    if ($column === 'year') echo get_post_meta($post_id, '_lww_year_released', true);
}
add_action('manage_lww_part_posts_custom_column', 'lww_render_catalog_custom_column', 10, 2);
add_action('manage_lww_set_posts_custom_column', 'lww_render_catalog_custom_column', 10, 2);
add_action('manage_lww_minifig_posts_custom_column', 'lww_render_catalog_custom_column', 10, 2);
?>