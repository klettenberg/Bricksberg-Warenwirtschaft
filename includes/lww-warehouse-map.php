<?php
/**
 * Modul: Lagerkarte (Warehouse Map) (v3.1-FIXED)
 * Visualisierung und Verwaltung von Lagerorten in Regalen mit Grid-Editor und Gesamtkarte.
 * 
 * BEHOBEN: Syntaxfehler durch unvollständigen Code.
 * UPDATE: Implementierung der fehlenden Grid- und Auto-Map-Funktionen.
 */
if (!defined('ABSPATH')) exit;

// Handler für die Grid-Interaktion
add_action('wp_ajax_lww_update_shelf_cell', 'lww_ajax_update_shelf_cell');
// Handler für Auto-Mapping
add_action('admin_post_lww_automap_locations', 'lww_handle_automap_locations');

function lww_render_warehouse_map_ui_page() {
    if (!current_user_can('edit_posts')) wp_die('Keine Berechtigung.');
    
    // Tab-Steuerung
    $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'list';
    
    // Speichern neuer Regale
    if (isset($_POST['lww_create_shelf']) && check_admin_referer('lww_create_shelf_nonce')) {
        $name = sanitize_text_field($_POST['shelf_name']);
        $rows = absint($_POST['shelf_rows']);
        $cols = absint($_POST['shelf_cols']);
        $pos_x = absint($_POST['pos_x']);
        $pos_y = absint($_POST['pos_y']);
        $width = absint($_POST['width']) ?: 100;
        $height = absint($_POST['height']) ?: 200;
        
        if ($name && $rows > 0 && $cols > 0) {
            wp_insert_post([
                'post_type' => 'lww_shelf',
                'post_title' => $name,
                'post_status' => 'publish',
                'meta_input' => [
                    '_lww_shelf_rows' => $rows,
                    '_lww_shelf_cols' => $cols,
                    '_lww_pos_x' => $pos_x,
                    '_lww_pos_y' => $pos_y,
                    '_lww_width' => $width,
                    '_lww_height' => $height,
                ]
            ]);
            echo '<div class="notice notice-success is-dismissible"><p>' . __('Schrank/Regal erfolgreich erstellt.', 'lego-wawi') . '</p></div>';
        }
    }

    $shelves = get_posts(['post_type' => 'lww_shelf', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC']);
    ?>
    <div class="wrap lww-wrap">
        <h1><img src="<?php echo esc_url(LWW_PLUGIN_URL . 'assets/img/bricksberg-logo.png'); ?>" class="lww-header-logo" /> <?php _e('Lager-Visualisierung', 'lego-wawi'); ?></h1>
        
        <h2 class="nav-tab-wrapper">
            <a href="?page=lww_warehouse_map_ui&tab=list" class="nav-tab <?php echo $active_tab === 'list' ? 'nav-tab-active' : ''; ?>"><?php _e('Schrank-Verwaltung', 'lego-wawi'); ?></a>
            <a href="?page=lww_warehouse_map_ui&tab=map" class="nav-tab <?php echo $active_tab === 'map' ? 'nav-tab-active' : ''; ?>"><?php _e('Gesamtkarte (Raum)', 'lego-wawi'); ?></a>
            <a href="?page=lww_warehouse_map_ui&tab=automap" class="nav-tab <?php echo $active_tab === 'automap' ? 'nav-tab-active' : ''; ?>"><?php _e('Auto-Zuweisung', 'lego-wawi'); ?></a>
        </h2>

        <?php settings_errors('lww_messages'); ?>

        <?php if ($active_tab === 'list'): ?>
            <!-- TAB: LISTE & ERSTELLEN -->
            <div class="lww-dashboard-grid lww-mt-20" style="grid-template-columns: 1fr 2fr;">
                <!-- Linke Spalte: Erstellen -->
                <div class="lww-card">
                    <h2><span class="dashicons dashicons-plus"></span> <?php _e('Neuen Schrank definieren', 'lego-wawi'); ?></h2>
                    <form method="post">
                        <?php wp_nonce_field('lww_create_shelf_nonce'); ?>
                        <p>
                            <label><?php _e('Bezeichnung:', 'lego-wawi'); ?></label>
                            <input type="text" name="shelf_name" placeholder="z.B. A" class="widefat" required>
                            <span class="description">Kurz halten (z.B. A, B, R1).</span>
                        </p>
                        <div style="display:flex; gap:10px;">
                            <div>
                                <label><?php _e('Reihen (Y):', 'lego-wawi'); ?></label>
                                <input type="number" name="shelf_rows" value="10" min="1" class="small-text" required>
                            </div>
                            <div>
                                <label><?php _e('Spalten (X):', 'lego-wawi'); ?></label>
                                <input type="number" name="shelf_cols" value="5" min="1" class="small-text" required>
                            </div>
                        </div>
                        
                        <h4 style="margin-bottom:5px; margin-top:15px;">Position im Raum (px)</h4>
                        <div style="display:flex; gap:10px;">
                            <div>
                                <label>X:</label>
                                <input type="number" name="pos_x" value="0" class="small-text">
                            </div>
                            <div>
                                <label>Y:</label>
                                <input type="number" name="pos_y" value="0" class="small-text">
                            </div>
                        </div>
                        <div style="display:flex; gap:10px; margin-top:5px;">
                            <div>
                                <label>Breite:</label>
                                <input type="number" name="width" value="100" class="small-text">
                            </div>
                            <div>
                                <label>Höhe/Tiefe:</label>
                                <input type="number" name="height" value="200" class="small-text">
                            </div>
                        </div>

                        <p class="submit">
                            <button type="submit" name="lww_create_shelf" class="button button-primary"><?php _e('Schrank anlegen', 'lego-wawi'); ?></button>
                        </p>
                    </form>
                </div>

                <!-- Rechte Spalte: Übersicht -->
                <div class="lww-card">
                    <h2><span class="dashicons dashicons-grid-view"></span> <?php _e('Schrank-Übersicht', 'lego-wawi'); ?></h2>
                    <?php if (empty($shelves)): ?>
                        <p><?php _e('Noch keine Schränke definiert.', 'lego-wawi'); ?></p>
                    <?php else: ?>
                        <div class="lww-shelf-list">
                            <?php foreach($shelves as $shelf): 
                                $rows = (int)get_post_meta($shelf->ID, '_lww_shelf_rows', true);
                                $cols = (int)get_post_meta($shelf->ID, '_lww_shelf_cols', true);
                                $pos_x = get_post_meta($shelf->ID, '_lww_pos_x', true);
                                $pos_y = get_post_meta($shelf->ID, '_lww_pos_y', true);
                            ?>
                            <div class="lww-shelf-item">
                                <div>
                                    <strong><?php echo esc_html($shelf->post_title); ?></strong>
                                    <span class="lww-status-badge"><?php echo "{$rows}x{$cols}"; ?></span>
                                    <span class="description" style="font-size:0.8em; margin-left:10px;">Pos: <?php echo "$pos_x / $pos_y"; ?></span>
                                </div>
                                <div>
                                    <a href="<?php echo get_edit_post_link($shelf->ID); ?>" class="button button-small">Edit</a>
                                    <button type="button" class="button button-small lww-toggle-shelf-view" onclick="jQuery('#lww-shelf-grid-<?php echo $shelf->ID; ?>').toggle();"><?php _e('Raster öffnen', 'lego-wawi'); ?></button>
                                </div>
                            </div>
                            
                            <!-- Versteckter Grid Editor -->
                            <div id="lww-shelf-grid-<?php echo $shelf->ID; ?>" class="lww-shelf-grid-container" style="display:none; margin-top:10px; border:1px solid #ddd; padding:10px;">
                                <?php lww_render_shelf_grid_editor($shelf->ID, $rows, $cols); ?>
                            </div>

                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($active_tab === 'map'): ?>
            <!-- TAB: GESAMTKARTE -->
            <div class="lww-card lww-mt-20">
                <h2><?php _e('Lagerplan (Gesamtansicht)', 'lego-wawi'); ?></h2>
                <p><?php _e('Hier sehen Sie die Positionierung aller Regale im Raum. Klicken Sie auf ein Regal, um dessen Inhalt zu sehen.', 'lego-wawi'); ?></p>
                
                <div id="lww-warehouse-floor-plan" style="position:relative; width:100%; height:600px; background:#f0f0f0; border:1px solid #ccc; overflow:auto;">
                    <?php foreach($shelves as $shelf): 
                        $pos_x = (int)get_post_meta($shelf->ID, '_lww_pos_x', true);
                        $pos_y = (int)get_post_meta($shelf->ID, '_lww_pos_y', true);
                        $w = (int)get_post_meta($shelf->ID, '_lww_width', true) ?: 100;
                        $h = (int)get_post_meta($shelf->ID, '_lww_height', true) ?: 200;
                        
                        // Belegung berechnen
                        $locations = lww_get_locations_in_shelf($shelf->ID);
                        $fill_percent = 0;
                        $capacity = ((int)get_post_meta($shelf->ID, '_lww_shelf_rows', true) * (int)get_post_meta($shelf->ID, '_lww_shelf_cols', true));
                        if ($capacity > 0) {
                            $fill_percent = min(100, round((count($locations) / $capacity) * 100));
                        }
                        $color = $fill_percent > 80 ? '#ef4444' : ($fill_percent > 40 ? '#f59e0b' : '#3b82f6');
                    ?>
                        <div class="lww-map-shelf" 
                             style="position:absolute; left:<?php echo $pos_x; ?>px; top:<?php echo $pos_y; ?>px; width:<?php echo $w; ?>px; height:<?php echo $h; ?>px; background:#fff; border:1px solid #999; border-top: 4px solid <?php echo $color; ?>; padding:5px; cursor:pointer; box-sizing:border-box;"
                             onclick="window.location.href='?page=lww_warehouse_map_ui&tab=list&open=<?php echo $shelf->ID; ?>';">
                            <strong class="shelf-title" style="display:block; font-size:12px; text-align:center;"><?php echo esc_html($shelf->post_title); ?></strong>
                            <div class="shelf-info" style="font-size:10px; text-align:center; margin-top:5px; color:#666;">
                                <?php echo $fill_percent; ?>% Belegt
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        <?php elseif ($active_tab === 'automap'): ?>
            <!-- TAB: AUTOMATISIERUNG -->
            <div class="lww-card lww-mt-20">
                <h2><?php _e('Lagerorte aus Import-Notizen zuweisen', 'lego-wawi'); ?></h2>
                <div class="notice notice-info inline">
                    <p><?php _e('Sie haben Lagerorte (z.B. aus BrickOwl "Private Note") importiert? Nutzen Sie diese Funktion, um diese Orte automatisch im visuellen Raster zu platzieren.', 'lego-wawi'); ?></p>
                </div>
                
                <p><?php _e('Das System sucht nach Lagerorten, die einem Muster wie <code>Regalname-Reihe-Spalte</code> entsprechen (z.B. "A-01-05" für Regal A, Reihe 1, Spalte 5).', 'lego-wawi'); ?></p>
                
                <form action="admin-post.php" method="post">
                    <input type="hidden" name="action" value="lww_automap_locations">
                    <?php wp_nonce_field('lww_automap_locations_nonce'); ?>
                    <p><?php submit_button(__('Auto-Mapping starten', 'lego-wawi'), 'primary', 'submit', false); ?></p>
                </form>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Rendert das Raster für ein Regal.
 */
function lww_render_shelf_grid_editor($shelf_id, $rows, $cols) {
    $locations = lww_get_locations_in_shelf($shelf_id); // Format: ['1-1' => term_object]
    
    echo '<div class="lww-shelf-grid" style="display:grid; grid-template-columns: repeat(' . $cols . ', 1fr); gap:5px;">';
    
    for ($r = 1; $r <= $rows; $r++) {
        for ($c = 1; $c <= $cols; $c++) {
            $key = $r . '-' . $c;
            $has_item = isset($locations[$key]);
            $class = $has_item ? 'occupied' : 'empty';
            $bg = $has_item ? '#d1fae5' : '#f3f4f6';
            $border = $has_item ? '#10b981' : '#d1d5db';
            
            $term_name = $has_item ? $locations[$key]->name : '';
            $count = $has_item ? $locations[$key]->count : 0;
            
            echo '<div class="lww-grid-cell ' . $class . '" data-row="'.$r.'" data-col="'.$c.'" style="background:'.$bg.'; border:1px solid '.$border.'; padding:10px; text-align:center; border-radius:4px; min-height:40px;">';
            echo '<strong>' . sprintf('%02d-%02d', $r, $c) . '</strong>';
            if ($has_item) {
                echo '<br><small>' . $count . ' Items</small>';
            }
            echo '</div>';
        }
    }
    
    echo '</div>';
}

/**
 * Hilfsfunktion: Holt alle belegten Plätze in einem Regal.
 * Gibt ein Array zurück mit Key "Reihe-Spalte".
 */
function lww_get_locations_in_shelf($shelf_id) {
    $shelf = get_post($shelf_id);
    if (!$shelf) return [];
    
    $prefix = $shelf->post_title;
    // Wir suchen nach Terms, die mit "RegalName-" beginnen.
    // Da dies eine einfache Suche ist, iterieren wir über alle Terms und filtern.
    // Bei vielen Terms könnte das langsam sein -> Caching empfohlen.
    
    $terms = get_terms(['taxonomy' => 'lww_inventory_location', 'hide_empty' => true]);
    $grid_data = [];
    
    foreach ($terms as $term) {
        // Erwartetes Format: "A-01-05" (Regal-Reihe-Spalte)
        if (preg_match('/^' . preg_quote($prefix, '/') . '-(\d+)-(\d+)$/i', $term->name, $matches)) {
            $row = (int)$matches[1];
            $col = (int)$matches[2];
            $grid_data[$row . '-' . $col] = $term;
        }
    }
    
    return $grid_data;
}

/**
 * AJAX Handler: Zelle aktualisieren (Dummy Implementierung für UI Feedback)
 */
function lww_ajax_update_shelf_cell() {
    // Hier würde die Logik stehen, um einen Term zu erstellen oder zu löschen
    // Für v3.0 ist das Grid erst mal Read-Only bzw. Visualisierung.
    wp_send_json_success('Not implemented yet');
}

/**
 * Handler für Auto-Mapping.
 */
function lww_handle_automap_locations() {
    check_admin_referer('lww_automap_locations_nonce');
    if (!current_user_can('edit_posts')) wp_die('Keine Berechtigung.');
    
    // 1. Hole alle Items und versuche, Standortinfos aus privaten Notizen zu extrahieren
    $items = get_posts([
        'post_type' => 'lww_inventory_item',
        'posts_per_page' => -1,
        'post_status' => 'publish',
    ]);
    $count = 0;
    
    foreach ($items as $item) {
        // Private Notizen = _remarks; zusätzlich (falls vorhanden) _public_notes anhängen
        $private_notes = (string) get_post_meta($item->ID, '_remarks', true);
        $public_notes  = (string) get_post_meta($item->ID, '_public_notes', true);
        $notes_combined = trim($private_notes . ' ' . $public_notes);

        if ($notes_combined === '') {
            continue;
        }

        // Vorherige Locations merken, um echte Neu-Zuweisungen zählen zu können
        $before = wp_get_post_terms($item->ID, 'lww_inventory_location', ['fields' => 'ids']);

        if (function_exists('lww_update_locations_from_string')) {
            lww_update_locations_from_string($item->ID, $notes_combined);
        }

        $after = wp_get_post_terms($item->ID, 'lww_inventory_location', ['fields' => 'ids']);

        // Zähle nur neu hinzugekommene Locations
        if (!is_wp_error($after)) {
            $new_ids = array_diff((array)$after, (array)$before);
            $count += count($new_ids);
        }
    }
    
    add_settings_error('lww_messages', 'automap_success', sprintf(__('%d Artikel wurden basierend auf Notizen automatisch zugewiesen.', 'lego-wawi'), $count), 'success');
    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect(wp_get_referer());
    exit;
}
?>
