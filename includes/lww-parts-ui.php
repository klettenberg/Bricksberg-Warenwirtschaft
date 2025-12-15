<?php
/**
 * Modul: Teile-Varianten UI (v15.3)
 *
 * Rendert die Seite "Teile-Varianten" und zeigt eine WP_List_Table
 * des 'lww_part' CPTs mit einer verschachtelten Ansicht aller zugehörigen
 * Inventar-Varianten.
 */
if (!defined('ABSPATH')) exit;

// Stellt sicher, dass die WP_List_Table Klasse geladen ist
if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Rendert den Inhalt der Seite "Teile-Varianten".
 */
function lww_render_parts_ui_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Sie haben keine Berechtigung, auf diese Seite zuzugreifen.', 'lego-wawi'));
    }

    $parts_table = new LWW_Parts_List_Table();
    $parts_table->prepare_items();
    ?>
    <div class="wrap lww-wrap">
        <h1><img src="<?php echo esc_url(LWW_PLUGIN_URL . 'assets/img/bricksberg-logo.png'); ?>" alt="Bricksberg Logo" class="lww-header-logo" /> <?php _e('Teile-Varianten', 'lego-wawi'); ?></h1>
        <p><?php _e('Diese Ansicht bündelt alle im Inventar vorhandenen Farb- und Zustandsvarianten pro LEGO-Teil.', 'lego-wawi'); ?></p>

        <div class="lww-card lww-mt-20">
            <form id="lww-parts-filter" method="get">
                <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page'] ?? ''); ?>" />
                <?php 
                $parts_table->search_box(__('Teile suchen', 'lego-wawi'), 'lww-parts-search');
                $parts_table->display(); 
                ?>
            </form>
        </div>
    </div>
    <?php
}

/**
 * Tabellen-Klasse für Teile-Varianten.
 */
class LWW_Parts_List_Table extends WP_List_Table {

    /** @var array Cache für die Inventar-Varianten der aktuellen Seite. */
    private $variants_by_part_id = [];

    public function __construct() {
        parent::__construct([
            'singular' => __('Teil', 'lego-wawi'),
            'plural'   => __('Teile', 'lego-wawi'),
            'ajax'     => false
        ]);
    }

    public function get_primary_column_name() {
        return 'name';
    }

    public function get_columns() {
        return [
            'cb'        => '<input type="checkbox" />',
            'thumbnail' => __('Bild', 'lego-wawi'),
            'name'      => __('Teil', 'lego-wawi'),
            'lww_part_category' => __('Kategorie', 'lego-wawi'),
            'variants'  => __('Farbvarianten im Inventar', 'lego-wawi'),
        ];
    }

    public function get_sortable_columns() {
        return [
            'name' => ['title', false],
            'lww_part_category' => ['taxonomy', false],
        ];
    }

    public function prepare_items() {
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];

        // NEU: Nur Teile-IDs abrufen, die tatsächlich Varianten im Inventar haben.
        global $wpdb;
        $parts_with_variants_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm " .
                "INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID " .
                "WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status = 'publish'",
                '_lww_part_id',
                'lww_inventory_item'
            )
        );

        // Wenn keine Teile Varianten haben, beende die Abfrage, indem wir nach einer unmöglichen ID suchen.
        if (empty($parts_with_variants_ids)) {
            $parts_with_variants_ids = [0]; // Verhindert, dass alle Teile angezeigt werden
        }

        $per_page = 10;
        $current_page = $this->get_pagenum();

        $args = [
            'post_type' => 'lww_part',
            'posts_per_page' => $per_page,
            'paged' => $current_page,
            'orderby' => $_REQUEST['orderby'] ?? 'title',
            'order' => $_REQUEST['order'] ?? 'asc',
            's' => $_REQUEST['s'] ?? '',
            'post__in' => $parts_with_variants_ids, // WICHTIG: Nur relevante Teile abfragen
        ];
        
        if (!empty($_REQUEST['lww_part_category'])) {
            $args['tax_query'] = [
                [
                    'taxonomy' => 'lww_part_category',
                    'field'    => 'slug',
                    'terms'    => sanitize_text_field($_REQUEST['lww_part_category']),
                ]
            ];
        }

        $query = new WP_Query($args);
        $this->items = $query->posts;

        $this->set_pagination_args([
            'total_items' => $query->found_posts,
            'per_page'    => $per_page
        ]);

        // Lade alle Varianten für die angezeigten Teile in einer einzigen Abfrage
        $part_ids = wp_list_pluck($this->items, 'ID');
        if (!empty($part_ids)) {
            $variants_query = new WP_Query([
                'post_type' => 'lww_inventory_item',
                'posts_per_page' => -1,
                'meta_query' => [
                    [
                        'key' => '_lww_part_id',
                        'value' => $part_ids,
                        'compare' => 'IN'
                    ]
                ]
            ]);

            foreach ($variants_query->posts as $variant_post) {
                $part_id = get_post_meta($variant_post->ID, '_lww_part_id', true);
                if (!isset($this->variants_by_part_id[$part_id])) {
                    $this->variants_by_part_id[$part_id] = [];
                }
                $this->variants_by_part_id[$part_id][] = $variant_post;
            }
        }
    }

    public function column_cb($item) {
        return sprintf('<input type="checkbox" name="part[]" value="%s" />', $item->ID);
    }

    public function column_thumbnail($item) {
        echo get_the_post_thumbnail($item->ID, [60, 60]);
    }
    
    public function column_name($item) {
        $part_num = get_post_meta($item->ID, '_lww_part_num', true);
        $actions = [
            'edit' => sprintf('<a href="%s">%s</a>', get_edit_post_link($item->ID), __('Bearbeiten', 'lego-wawi')),
        ];

        $title_html = '<strong>' . esc_html($item->post_title) . '</strong>';
        $german_name = get_post_meta($item->ID, '_lww_part_name_de', true);
        if (!empty($german_name)) {
            $title_html = '<strong>' . esc_html($german_name) . '</strong><br><small style="color: #666;">' . esc_html($item->post_title) . '</small>';
        }

        return sprintf('%s<br><small>%s</small>%s',
            $title_html,
            esc_html($part_num),
            $this->row_actions($actions)
        );
    }
    
    public function column_lww_part_category($item) {
        return get_the_term_list($item->ID, 'lww_part_category', '', ', ', '');
    }

    public function column_variants($item) {
        $variants = $this->variants_by_part_id[$item->ID] ?? [];
        if (empty($variants)) {
            echo '<em>' . __('Keine Varianten im Inventar gefunden.', 'lego-wawi') . '</em>';
            return;
        }

        // NEU: Gesamtstückzahl berechnen
        $total_quantity = 0;
        foreach ($variants as $variant_post) {
            $total_quantity += (int) get_post_meta($variant_post->ID, '_quantity', true);
        }

        // Sortieren, z.B. nach Farbe und dann Zustand
        usort($variants, function($a, $b) {
            $color_a = get_post_meta($a->ID, '_color_name', true);
            $color_b = get_post_meta($b->ID, '_color_name', true);
            $condition_a = get_post_meta($a->ID, '_condition', true);
            $condition_b = get_post_meta($b->ID, '_condition', true);
            if ($color_a === $color_b) {
                return strcmp($condition_a, $condition_b);
            }
            return strcmp($color_a, $color_b);
        });

        echo '<div class="lww-variants-container">';

        // Zusammengeklappte Ansicht mit Zähler und Farbfeldern
        echo '<div class="lww-variants-toggle">';
        
        // MODIFIZIERT: Zusammenfassungs-Text mit Gesamtstückzahl
        $summary_text = sprintf(
            _n('%d Variante', '%d Varianten', count($variants), 'lego-wawi'),
            count($variants)
        );
        $summary_text .= ' | ' . sprintf(
            __('Gesamt: %s Stück', 'lego-wawi'),
            number_format_i18n($total_quantity)
        );
        echo '<span class="lww-variants-summary">' . $summary_text . '</span>';

        echo '<div class="lww-variants-swatches">';
        foreach (array_slice($variants, 0, 10) as $variant_post) { // Zeige max. 10 Swatches
            $color_id = get_post_meta($variant_post->ID, '_lww_color_id', true);
            $color_name = get_post_meta($variant_post->ID, '_color_name', true);
            $style = '';
            if ($color_id) {
                $rgb = get_post_meta($color_id, '_lww_rgb_hex', true);
                if ($rgb) {
                     $style = 'style="background-color:#' . esc_attr($rgb) . ';"';
                }
            }
            echo '<span class="lww-variant-swatch" ' . $style . ' title="' . esc_attr($color_name) . '"></span>';
        }
        echo '</div>';
        echo '<span class="lww-toggle-indicator dashicons dashicons-arrow-down-alt2" title="' . esc_attr__('Details ein-/ausblenden', 'lego-wawi') . '"></span>';
        echo '</div>';

        // Ausgeklappte Ansicht mit detaillierter Tabelle
        echo '<div class="lww-variants-expanded">';
        echo '<table class="wp-list-table widefat striped lww-variant-details-table">';
        echo '<thead><tr>';
        echo '<th>' . __('Farbe', 'lego-wawi') . '</th>';
        echo '<th>' . __('Zustand', 'lego-wawi') . '</th>';
        echo '<th>' . __('Menge', 'lego-wawi') . '</th>';
        echo '<th>' . __('Preis', 'lego-wawi') . '</th>';
        echo '<th>' . __('Aktion', 'lego-wawi') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        foreach ($variants as $variant_post) {
            $color_id = get_post_meta($variant_post->ID, '_lww_color_id', true);
            $color_name = get_post_meta($variant_post->ID, '_color_name', true);
            $condition = get_post_meta($variant_post->ID, '_condition', true);
            $quantity = (int) get_post_meta($variant_post->ID, '_quantity', true);
            $price = (float) get_post_meta($variant_post->ID, '_price', true);

            echo '<tr>';
            // Farbe
            echo '<td>';
            if ($color_id) {
                $rgb = get_post_meta($color_id, '_lww_rgb_hex', true);
                if ($rgb) {
                     printf('<span class="lww-color-preview" style="background-color:#%s;"></span>', esc_attr($rgb));
                }
            }
            echo '<a href="' . esc_url(get_edit_post_link($variant_post->ID)) . '">' . esc_html($color_name) . '</a>';
            echo '</td>';

            // Zustand
            echo '<td>';
            if ($condition === 'new') {
                echo '<span class="lww-condition lww-condition-new"><span class="dashicons dashicons-plus-alt2"></span>' . __('Neu', 'lego-wawi') . '</span>';
            } else {
                echo '<span class="lww-condition lww-condition-used"><span class="dashicons dashicons-backup"></span>' . __('Gebraucht', 'lego-wawi') . '</span>';
            }
            echo '</td>';

            // Menge
            echo '<td>' . number_format_i18n($quantity) . '</td>';
            // Preis
            echo '<td>' . number_format_i18n($price, 3) . ' €</td>';
            // Aktion
            echo '<td><a href="' . esc_url(get_edit_post_link($variant_post->ID)) . '" class="button button-secondary button-small">' . __('Bearbeiten', 'lego-wawi') . '</a></td>';
            echo '</tr>';
        }
        echo '</tbody>';
        echo '</table>';
        echo '</div>';

        echo '</div>';
    }

    public function extra_tablenav($which) {
        if ($which == "top") {
            echo '<div class="alignleft actions">';
            wp_dropdown_categories([
                'show_option_all' => __('Alle Teile-Kategorien', 'lego-wawi'),
                'taxonomy'        => 'lww_part_category',
                'name'            => 'lww_part_category',
                'orderby'         => 'name',
                'selected'        => $_REQUEST['lww_part_category'] ?? '',
                'hierarchical'    => true,
                'show_count'      => true,
                'hide_empty'      => true,
            ]);
            submit_button(__('Filtern', 'lego-wawi'), 'button', 'filter_action', false, ['id' => 'post-query-submit']);
            echo '</div>';
        }
    }

    public function no_items() {
        _e('Keine Teile mit Inventar-Varianten gefunden, die den Filtern entsprechen.', 'lego-wawi');
    }
}
?>