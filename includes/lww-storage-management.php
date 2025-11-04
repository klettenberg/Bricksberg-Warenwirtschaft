<?php
/**
 * Modul: Lagerverwaltung
 *
 * Rendert den Inhalt für den "Lagerverwaltung"-Tab, inklusive Statistiken
 * und der Logik für Lagerort-Vorschläge.
 */
if (!defined('ABSPATH')) exit;

/**
 * Rendert den Inhalt des "Lagerverwaltung"-Tabs.
 */
function lww_render_storage_ui_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Sie haben keine Berechtigung, auf diese Seite zuzugreifen.', 'lego-wawi'));
    }

    // --- 1. Daten für Statistiken sammeln ---
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

    ?>
    <div class="wrap lww-wrap">
        <h1><img src="<?php echo esc_url(LWW_PLUGIN_URL . 'assets/img/bricksberg-logo.png'); ?>" alt="Bricksberg Logo" class="lww-header-logo" /> <?php _e('Lagerverwaltung', 'lego-wawi'); ?></h1>
        <p><?php _e('Verwalte deine Lagerorte, weise Artikel zu und erhalte intelligente Vorschläge zur Organisation.', 'lego-wawi'); ?></p>

        <?php settings_errors('lww_messages'); ?>

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
                        <div class="stat-number"><?php echo esc_html(number_format_i18n(count($locations))); ?></div>
                    </div>
                </div>

                <?php if (!empty($locations) && is_array($locations)):
                    // Sortiere nach Anzahl der Artikel, absteigend
                    usort($locations, function($a, $b) { return $b->count - $a->count; });
                ?>
                    <table class="wp-list-table widefat striped lww-mt-20">
                        <thead>
                            <tr>
                                <th><?php _e('Lagerort', 'lego-wawi'); ?></th>
                                <th style="width: 150px;"><?php _e('Anzahl Artikel', 'lego-wawi'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($locations, 0, 15) as $location): // Nur Top 15 anzeigen ?>
                                <tr>
                                    <td><strong><a href="<?php echo esc_url(get_edit_term_link($location->term_id, 'lww_inventory_location')); ?>"><?php echo esc_html($location->name); ?></a></strong></td>
                                    <td><?php echo esc_html(number_format_i18n($location->count)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                     <?php if(count($locations) > 15): ?>
                        <p class="lww-mt-20"><em><?php printf(__('Es werden die 15 größten von %d Lagerorten angezeigt.', 'lego-wawi'), count($locations)); ?></em></p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="lww-card">
                <h2><?php _e('Artikel ohne Lagerort', 'lego-wawi'); ?></h2>
                <p><?php _e('Hier sind Artikel aufgelistet, denen noch kein Lagerort zugewiesen wurde. Das System macht einen Vorschlag basierend auf ähnlichen, bereits sortierten Artikeln.', 'lego-wawi'); ?></p>

                <?php
                // --- 2. Liste der Artikel ohne Lagerort mit Vorschlägen ---
                $items_to_suggest_query = new WP_Query([
                    'post_type' => 'lww_inventory_item',
                    'post_status' => 'publish',
                    'posts_per_page' => 50, // Limit für die Anzeige, um Performance zu schonen
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
                            <th><?php _e('Begründung', 'lego-wawi'); ?></th>
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
                    <div class="notice notice-success inline lww-notice"><p><?php _e('Großartig! Alle Artikel haben einen zugewiesenen Lagerort.', 'lego-wawi'); ?></p></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Generiert einen intelligenten Vorschlag für einen Lagerort.
 *
 * @param int $item_id Die ID des lww_inventory_item Posts.
 * @return array Ein Array mit 'location' und 'reason'.
 */
function lww_suggest_location_for_item($item_id) {
    $part_id = get_post_meta($item_id, '_lww_part_id', true);
    if (!$part_id) {
        return ['location' => '', 'reason' => __('Kein Katalog-Teil verknüpft.', 'lego-wawi')];
    }
    
    // Strategie 1: Basierend auf dem gleichen Teil (andere Farben/Zustände)
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

    return ['location' => '', 'reason' => __('Keine ähnlichen Teile mit Lagerort gefunden.', 'lego-wawi')];
}

/**
 * Hilfsfunktion, um den häufigsten Lagerort für ähnliche Artikel zu finden.
 *
 * @param string $relation_type 'lww_part_category' oder 'lww_part'.
 * @param array $relation_ids Array von Term-IDs oder Post-IDs.
 * @param int $exclude_part_id Optional. Die ID des Teils, das von der Suche ausgeschlossen werden soll.
 * @return array|null Ein absteigend sortiertes Array von [location_name => count] oder null.
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
        wp_die(__('Ungültige Anfrage.', 'lego-wawi'));
    }

    $item_id = absint($_POST['item_id']);

    if (!wp_verify_nonce($_POST['lww_assign_location_nonce'], 'lww_assign_location_' . $item_id)) {
        wp_die(__('Sicherheitsüberprüfung fehlgeschlagen.', 'lego-wawi'));
    }

    if (!current_user_can('edit_post', $item_id)) {
        wp_die(__('Keine Berechtigung.', 'lego-wawi'));
    }

    $location_name = isset($_POST['location_name']) ? sanitize_text_field($_POST['location_name']) : '';

    if (function_exists('lww_update_locations_from_string')) {
        lww_update_locations_from_string($item_id, $location_name);
        add_settings_error('lww_messages', 'location_assigned', sprintf(__('Lagerort für Artikel %d erfolgreich zugewiesen.', 'lego-wawi'), $item_id), 'success');
    } else {
        add_settings_error('lww_messages', 'location_assign_error', __('Fehler: Die benötigte Funktion zum Speichern des Lagerorts wurde nicht gefunden.', 'lego-wawi'), 'error');
    }

    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect(wp_get_referer());
    exit;
}
add_action('admin_post_lww_assign_location', 'lww_handle_assign_location');

?>