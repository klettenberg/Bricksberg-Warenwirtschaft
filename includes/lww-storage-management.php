<?php
/**
 * Modul: Lagerverwaltung (v20.3)
 * 
 * UPDATE: Zugriffsberechtigungen auf 'edit_posts' angepasst.
 */
if (!defined('ABSPATH')) exit;

/**
 * Rendert den Inhalt des "Lagerverwaltung"-Tabs.
 */
function lww_render_storage_ui_page() {
    // KORREKTUR: Berechtigung auf edit_posts gesenkt (f r Shop Manager)
    if (!current_user_can('edit_posts')) {
        wp_die(__('Sie haben keine Berechtigung, auf diese Seite zuzugreifen.', 'lego-wawi'));
    }

    // --- 1. Daten f r Statistiken sammeln ---
    $locations = get_terms([
        'taxonomy' => 'lww_inventory_location',
        'hide_empty' => false,
    ]);

    $total_items_query = new WP_Query([
        'post_type' => 'lww_inventory_item',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
    ]);
    $total_items_count = $total_items_query->post_count;

    $items_without_location_query = new WP_Query([
        'post_type' => 'lww_inventory_item',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'tax_query' => [
            [
                'taxonomy' => 'lww_inventory_location',
                'operator' => 'NOT EXISTS',
            ],
        ],
    ]);
    $items_without_location_count = $items_without_location_query->post_count;
    $items_with_location_count = $total_items_count - $items_without_location_count;
    
    // Daten f r die Optimierungs-Vorschl ge holen
    $merge_suggestions = lww_get_location_merge_suggestions();

    ?>
    <div class="wrap lww-wrap">
        <h1><img src="<?php echo esc_url(LWW_PLUGIN_URL . 'assets/img/bricksberg-logo.png'); ?>" alt="Bricksberg Logo" class="lww-header-logo" /> <?php _e('Lagerverwaltung', 'lego-wawi'); ?></h1>
        <p><?php _e('Verwalte deine Lagerorte, weise Artikel zu und erhalte intelligente Vorschl ge zur Organisation.', 'lego-wawi'); ?></p>

        <?php settings_errors('lww_messages'); ?>
        
        <!-- Abschnitt f r Lagerort-Optimierung -->
        <?php if (!empty($merge_suggestions)): ?>
        <div class="lww-card lww-mt-20">
            <h2><?php _e('Lagerorte optimieren', 'lego-wawi'); ?></h2>
            <p><?php _e('Das System hat Lagerorte mit hnlichen Namen gefunden. F hre sie zusammen, um deine Daten zu vereinheitlichen.', 'lego-wawi'); ?></p>
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th><?php _e('Zusammenzuf hrende Lagerorte (Anzahl Artikel)', 'lego-wawi'); ?></th>
                        <th style="width: 400px;"><?php _e('Neuer, vereinheitlichter Name', 'lego-wawi'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($merge_suggestions as $suggestion): 
                        $term_ids_to_merge = wp_list_pluck($suggestion['terms_to_merge'], 'term_id');
                    ?>
                        <tr>
                            <td>
                                <?php 
                                $term_labels = [];
                                foreach ($suggestion['terms_to_merge'] as $term) {
                                    $term_labels[] = sprintf('%s (%d)', esc_html($term->name), $term->count);
                                }
                                echo implode(', ', $term_labels);
                                ?>
                                <br><small><?php printf(__('Insgesamt %d Artikel betroffen.', 'lego-wawi'), $suggestion['total_items']); ?></small>
                            </td>
                            <td>
                                <form action="admin-post.php" method="post">
                                    <input type="hidden" name="action" value="lww_merge_locations">
                                    <input type="hidden" name="old_term_ids" value="<?php echo esc_attr(implode(',', $term_ids_to_merge)); ?>">
                                    <?php wp_nonce_field('lww_merge_locations_nonce'); ?>
                                    <div class="lww-assign-location-form">
                                        <input type="text" name="new_name" value="<?php echo esc_attr($suggestion['suggested_name']); ?>" required>
                                        <button type="submit" class="button button-primary"><?php _e('Zusammenf hren', 'lego-wawi'); ?></button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <div class="lww-storage-grid lww-mt-20">
            <div class="lww-card">
                <h2><?php _e('Lagerort-Statistiken', 'lego-wawi'); ?></h2>
                <div class="lww-storage-stats-grid">
                    <div class="lww-stat-card">
                        <h3><span class="dashicons dashicons-archive"></span><?php _e('Artikel mit Lagerort', 'lego-wawi'); ?></h3>
                        <div class="stat-number"><?php echo esc_html(number_format_i18n($items_with_location_count)); ?></div>
                    </div>
                    <div class="lww-stat-card core-data-missing">
                        <h3><span class="dashicons dashicons-warning"></span><?php _e('Artikel ohne Lagerort', 'lego-wawi'); ?></h3>
                        <div class="stat-number"><?php echo esc_html(number_format_i18n($items_without_location_count)); ?></div>
                    </div>
                    <div class="lww-stat-card">
                        <h3><span class="dashicons dashicons-tag"></span><?php _e('Definierte Lagerorte', 'lego-wawi'); ?></h3>
                        <div class="stat-number"><?php echo esc_html(number_format_i18n(is_array($locations) ? count($locations) : 0)); ?></div>
                    </div>
                </div>

                <!-- Detaillierte Liste der Lagerorte -->
                <h3 class="lww-mt-20"><?php _e('Detail-Liste aller Lagerorte', 'lego-wawi'); ?></h3>
                <div style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd;">
                    <table class="wp-list-table widefat striped fixed">
                        <thead>
                            <tr>
                                <th><?php _e('Name', 'lego-wawi'); ?></th>
                                <th><?php _e('Slug', 'lego-wawi'); ?></th>
                                <th><?php _e('Lots (Artikel-Typen)', 'lego-wawi'); ?></th>
                                <th><?php _e('Teile (Gesamtmenge)', 'lego-wawi'); ?></th>
                                <th><?php _e('Aktion', 'lego-wawi'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            if (!empty($locations)) {
                                foreach ($locations as $loc) {
                                    global $wpdb;
                                    $qty_sum = $wpdb->get_var($wpdb->prepare(
                                        "SELECT SUM(CAST(pm.meta_value AS UNSIGNED)) 
                                         FROM {$wpdb->postmeta} pm 
                                         JOIN {$wpdb->term_relationships} tr ON pm.post_id = tr.object_id 
                                         JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id 
                                         WHERE tt.term_id = %d AND pm.meta_key = '_quantity'",
                                        $loc->term_id
                                    ));
                                    
                                    $filter_url = admin_url('admin.php?page=lww_inventory_ui&lww_inventory_location=' . $loc->slug);
                                    
                                    echo '<tr>';
                                    echo '<td><strong><a href="'.esc_url($filter_url).'">' . esc_html($loc->name) . '</a></strong></td>';
                                    echo '<td>' . esc_html($loc->slug) . '</td>';
                                    echo '<td>' . esc_html($loc->count) . '</td>';
                                    echo '<td>' . number_format_i18n((int)$qty_sum) . '</td>';
                                    echo '<td><a href="'.esc_url($filter_url).'" class="button button-small">' . __('Inhalt anzeigen', 'lego-wawi') . '</a></td>';
                                    echo '</tr>';
                                }
                            } else {
                                echo '<tr><td colspan="5">' . __('Keine Lagerorte gefunden.', 'lego-wawi') . '</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>

                <?php if (!empty($locations) && is_array($locations)):
                    usort($locations, function($a, $b) { return $b->count - $a->count; });
                ?>
                    <h3 class="lww-mt-20"><?php _e('Grafische bersicht (Karten)', 'lego-wawi'); ?></h3>
                    <div class="lww-location-grid lww-mt-20">
                         <?php foreach ($locations as $location): 
                            $location_view_url = admin_url('admin.php?page=lww_inventory_ui&lww_inventory_location=' . $location->slug);
                        ?>
                            <a href="<?php echo esc_url($location_view_url); ?>" class="lww-location-card" title="<?php echo esc_attr(sprintf(__('Alle Artikel im Lagerort %s anzeigen', 'lego-wawi'), $location->name)); ?>">
                                <span class="dashicons dashicons-inbox"></span>
                                <h3><?php echo esc_html($location->name); ?></h3>
                                <p class="item-count">Item-Types: <?php echo esc_html($location->count); ?></p>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="lww-card">
                <h2><?php _e('Artikel ohne Lagerort', 'lego-wawi'); ?></h2>
                <p><?php _e('Hier sind Artikel aufgelistet, denen noch kein Lagerort zugewiesen wurde. Das System macht einen Vorschlag basierend auf hnlichen, bereits sortierten Artikeln.', 'lego-wawi'); ?></p>

                <?php
                // --- 2. Liste der Artikel ohne Lagerort mit Vorschl gen ---
                $items_to_suggest_query = new WP_Query([
                    'post_type' => 'lww_inventory_item',
                    'post_status' => 'publish',
                    'posts_per_page' => 50, // Limit f r die Anzeige
                    'tax_query' => [
                        [
                            'taxonomy' => 'lww_inventory_location',
                            'operator' => 'NOT EXISTS',
                        ],
                    ],
                ]);

                if ($items_to_suggest_query->have_posts()):
                ?>
                <table class="wp-list-table widefat striped lww-storage-suggestions-table">
                    <thead>
                        <tr>
                            <th><?php _e('Artikel', 'lego-wawi'); ?></th>
                            <th><?php _e('Vorgeschlagener Lagerort', 'lego-wawi'); ?></th>
                            <th><?php _e('Begr ndung', 'lego-wawi'); ?></th>
                            <th style="width: 250px;"><?php _e('Aktion', 'lego-wawi'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($items_to_suggest_query->have_posts()): $items_to_suggest_query->the_post(); 
                            $item_id = get_the_ID();
                            $suggestion = lww_suggest_location_for_item($item_id);
                        ?>
                            <tr>
                                <td><strong><a href="<?php echo get_edit_post_link($item_id); ?>" target="_blank"><?php the_title(); ?></a></strong></td>
                                <td><span class="suggestion-text"><?php echo esc_html($suggestion['location']); ?></span></td>
                                <td><em class="suggestion-reason"><?php echo esc_html($suggestion['reason']); ?></em></td>
                                <td>
                                    <form action="admin-post.php" method="post" class="lww-assign-location-form">
                                        <input type="hidden" name="action" value="lww_assign_location">
                                        <input type="hidden" name="item_id" value="<?php echo esc_attr($item_id); ?>">
                                        <?php wp_nonce_field('lww_assign_location_' . $item_id, 'lww_assign_location_nonce'); ?>
                                        <input type="text" name="location_name" value="<?php echo esc_attr($suggestion['location']); ?>" placeholder="<?php esc_attr_e('Lagerort eingeben', 'lego-wawi'); ?>">
                                        <button type="submit" class="button button-primary button-small"><?php _e('Speichern', 'lego-wawi'); ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; wp_reset_postdata(); ?>
                    </tbody>
                </table>
                <?php if ($items_without_location_count > 50): ?>
                    <p class="lww-mt-20"><em><?php printf(__('Es werden die ersten 50 von %d Artikeln ohne Lagerort angezeigt.', 'lego-wawi'), $items_without_location_count); ?></em></p>
                <?php endif; ?>
                <?php else: ?>
                    <div class="notice notice-success inline lww-notice"><p><?php _e('Gro artig! Alle Artikel haben einen zugewiesenen Lagerort.', 'lego-wawi'); ?></p></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Generiert einen intelligenten Vorschlag f r einen Lagerort.
 */
function lww_suggest_location_for_item($item_id) {
    $part_id = get_post_meta($item_id, '_lww_part_id', true);
    if (!$part_id) {
        return ['location' => '', 'reason' => __('Kein Katalog-Teil verkn pft.', 'lego-wawi')];
    }
    
    // Strategie 1: Basierend auf dem gleichen Teil (andere Farben/Zust nde)
    $locations = lww_find_common_location_by_meta_relation('lww_part', [$part_id]);
    if ($locations) {
        return [
            'location' => key($locations),
            'reason' => __('Andere Varianten dieses Teils sind dort gelagert.', 'lego-wawi')
        ];
    }

    // Strategie 2: Basierend auf der Teile-Kategorie
    $part_categories = wp_get_post_terms($part_id, 'lww_part_category');
    if (!empty($part_categories) && !is_wp_error($part_categories)) {
        $category_ids = wp_list_pluck($part_categories, 'term_id');
        $locations = lww_find_common_location_by_meta_relation('lww_part_category', $category_ids, $part_id);
        if ($locations) {
            return [
                'location' => key($locations), 
                'reason' => sprintf(__('Andere Teile der Kategorie "%s" sind dort gelagert.', 'lego-wawi'), esc_html($part_categories[0]->name))
            ];
        }
    }

    return ['location' => '', 'reason' => __('Keine hnlichen Teile mit Lagerort gefunden.', 'lego-wawi')];
}

/**
 * Hilfsfunktion, um den h ufigsten Lagerort f r hnliche Artikel zu finden.
 */
function lww_find_common_location_by_meta_relation($relation_type, $relation_ids, $exclude_part_id = 0) {
    $args = [
        'post_type' => 'lww_inventory_item',
        'post_status' => 'publish',
        'posts_per_page' => 100, // Limit zur Performance-Steigerung
        'fields' => 'ids',
        'tax_query' => [
            ['taxonomy' => 'lww_inventory_location', 'operator' => 'EXISTS']
        ]
    ];

    if ($relation_type === 'lww_part_category') {
        $args['meta_query'] = [
            [
                'key' => '_lww_part_id',
                'value' => '0',
                'compare' => '>', 
            ]
        ];
        $parts_in_cat_query = new WP_Query([
            'post_type' => 'lww_part',
            'posts_per_page' => 100, // Limit
            'fields' => 'ids',
            'post__not_in' => $exclude_part_id ? [$exclude_part_id] : [],
            'tax_query' => [
                ['taxonomy' => 'lww_part_category', 'field' => 'term_id', 'terms' => $relation_ids]
            ]
        ]);
        if (!$parts_in_cat_query->have_posts()) return null;
        $args['meta_query'][0]['value'] = $parts_in_cat_query->posts;
        $args['meta_query'][0]['compare'] = 'IN';
    } else { // 'lww_part'
        $args['meta_query'] = [
            ['key' => '_lww_part_id', 'value' => $relation_ids, 'compare' => 'IN']
        ];
    }

    $items_query = new WP_Query($args);
    if (!$items_query->have_posts()) return null;

    $location_counts = [];
    foreach ($items_query->posts as $item_id) {
        $locations = wp_get_post_terms($item_id, 'lww_inventory_location');
        if (!is_wp_error($locations)) {
            foreach ($locations as $location) {
                if (!isset($location_counts[$location->name])) {
                    $location_counts[$location->name] = 0;
                }
                $location_counts[$location->name]++;
            }
        }
    }

    arsort($location_counts);
    return !empty($location_counts) ? $location_counts : null;
}


/**
 * Verarbeitet die Zuweisung eines Lagerorts aus dem Formular.
 */
function lww_handle_assign_location() {
    if (!isset($_POST['item_id']) || !isset($_POST['lww_assign_location_nonce'])) {
        wp_die(__('Ung ltige Anfrage.', 'lego-wawi'));
    }

    $item_id = absint($_POST['item_id']);

    if (!wp_verify_nonce($_POST['lww_assign_location_nonce'], 'lww_assign_location_' . $item_id)) {
        wp_die(__('Sicherheits berpr fung fehlgeschlagen.', 'lego-wawi'));
    }

    // Auch hier: edit_posts reicht f r Shop Manager
    if (!current_user_can('edit_post', $item_id)) {
        wp_die(__('Keine Berechtigung.', 'lego-wawi'));
    }

    $location_name = isset($_POST['location_name']) ? sanitize_text_field($_POST['location_name']) : '';

    if (function_exists('lww_update_locations_from_string')) {
        lww_update_locations_from_string($item_id, $location_name);
        add_settings_error('lww_messages', 'location_assigned', sprintf(__('Lagerort f r Artikel %d erfolgreich zugewiesen.', 'lego-wawi'), $item_id), 'success');
    } else {
        add_settings_error('lww_messages', 'location_assign_error', __('Fehler: Die ben tigte Funktion zum Speichern des Lagerorts wurde nicht gefunden.', 'lego-wawi'), 'error');
    }

    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect(wp_get_referer());
    exit;
}
add_action('admin_post_lww_assign_location', 'lww_handle_assign_location');

/**
 * Normalisiert einen Lagerort-Namen f r den Vergleich.
 */
function lww_normalize_location_name($name) {
    $name = strtolower($name);
    $name = str_replace(['-', '_', '.'], ' ', $name);
    $name = preg_replace('/\s+/', ' ', $name); // Mehrfache Leerzeichen durch eins ersetzen
    return trim($name);
}

/**
 * Findet und gruppiert hnliche Lagerort-Begriffe.
 */
function lww_get_location_merge_suggestions() {
    $terms = get_terms([
        'taxonomy' => 'lww_inventory_location',
        'hide_empty' => false,
    ]);

    if (empty($terms) || is_wp_error($terms)) {
        return [];
    }

    $groups = [];
    foreach ($terms as $term) {
        $normalized = lww_normalize_location_name($term->name);
        if (empty($normalized)) continue;
        $groups[$normalized][] = $term;
    }

    $suggestions = [];
    foreach ($groups as $group) {
        if (count($group) < 2) continue;

        usort($group, function($a, $b) {
            return $b->count <=> $a->count;
        });

        $suggestions[] = [
            'suggested_name' => $group[0]->name,
            'terms_to_merge' => $group,
            'total_items' => array_sum(wp_list_pluck($group, 'count')),
        ];
    }

    return $suggestions;
}

/**
 * Handler f r das Zusammenf hren von Lagerorten.
 */
function lww_handle_merge_locations() {
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'lww_merge_locations_nonce')) {
        wp_die(__('Sicherheits berpr fung fehlgeschlagen.', 'lego-wawi'));
    }
    if (!current_user_can('edit_posts')) {
        wp_die(__('Keine Berechtigung.', 'lego-wawi'));
    }

    $old_term_ids_str = sanitize_text_field($_POST['old_term_ids'] ?? '');
    $new_name = sanitize_text_field($_POST['new_name'] ?? '');

    if (empty($old_term_ids_str) || empty($new_name)) {
        add_settings_error('lww_messages', 'merge_error', __('Fehler: Nicht alle Daten zum Zusammenf hren wurden bermittelt.', 'lego-wawi'), 'error');
        wp_safe_redirect(wp_get_referer());
        exit;
    }

    $old_term_ids = array_map('absint', explode(',', $old_term_ids_str));
    $taxonomy = 'lww_inventory_location';

    // Finde oder erstelle den Ziel-Begriff
    $target_term_info = term_exists($new_name, $taxonomy);
    if (!$target_term_info) {
        $target_term_info = wp_insert_term($new_name, $taxonomy);
    }
    
    if (is_wp_error($target_term_info)) {
        add_settings_error('lww_messages', 'merge_error', __('Fehler beim Erstellen des neuen Lagerorts: ', 'lego-wawi') . $target_term_info->get_error_message(), 'error');
        wp_safe_redirect(wp_get_referer());
        exit;
    }
    $target_term_id = (int)$target_term_info['term_id'];

    // Finde alle Posts, die zu den alten Begriffen geh ren
    $posts_to_update = get_posts([
        'post_type' => 'lww_inventory_item',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'tax_query' => [
            [
                'taxonomy' => $taxonomy,
                'field' => 'term_id',
                'terms' => $old_term_ids,
            ],
        ],
    ]);

    // Weise den neuen Begriff allen Posts zu
    foreach ($posts_to_update as $post_id) {
        wp_add_object_terms($post_id, $target_term_id, $taxonomy);
    }
    
    $deleted_terms_count = 0;
    // L sche die alten Begriffe
    foreach ($old_term_ids as $old_term_id) {
        if ($old_term_id !== $target_term_id) {
            wp_delete_term($old_term_id, $taxonomy);
            $deleted_terms_count++;
        }
    }

    add_settings_error('lww_messages', 'merge_success', sprintf(__('%d Lagerorte wurden erfolgreich zu "%s" zusammengef hrt. %d Artikel wurden aktualisiert.', 'lego-wawi'), $deleted_terms_count, esc_html($new_name), count($posts_to_update)), 'success');
    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect(wp_get_referer());
    exit;
}
add_action('admin_post_lww_merge_locations', 'lww_handle_merge_locations');
?>