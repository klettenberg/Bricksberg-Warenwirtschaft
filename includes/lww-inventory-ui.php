<?php
/**
 * Modul: Inventar UI & Steuerung (v14.0)
 *
 * Rendert die Seite "BrickOwl Inventar verwalten" und zeigt eine
 * WP_List_Table des 'lww_inventory_item' CPTs mit erweiterten, persistenten Filtern.
 */
if (!defined('ABSPATH')) exit;

// Stellt sicher, dass die WP_List_Table Klasse geladen ist
if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * =========================================================================
 * LWW_Inventory_List_Table Klasse
 * =========================================================================
 * Erstellt die Haupttabelle für die Inventar-Übersicht.
 */
class LWW_Inventory_List_Table extends WP_List_Table {

    private $user_filters = [];

    /**
     * Konstruktor. Setzt die Bezeichnungen und lädt persistente Filter.
     */
    public function __construct() {
        parent::__construct([
            'singular' => __('Inventar Item', 'lego-wawi'), // singular name of the listed records
            'plural'   => __('Inventar Items', 'lego-wawi'), // plural name of the listed records
            'ajax'     => false // AJAX wird (noch) nicht für die Paginierung verwendet
        ]);

        $this->load_and_set_filters();
    }

    /**
     * Lädt gespeicherte Filter oder speichert neue aus der URL.
     */
    private function load_and_set_filters() {
        $user_id = get_current_user_id();
        $meta_key = 'lww_inventory_filters';

        // Filter zurücksetzen, wenn angefordert
        if (isset($_REQUEST['lww_reset_filters'])) {
            delete_user_meta($user_id, $meta_key);
            wp_safe_redirect(remove_query_arg(['lww_reset_filters', '_wpnonce']));
            exit;
        }

        $this->user_filters = get_user_meta($user_id, $meta_key, true);
        if (!is_array($this->user_filters)) {
            $this->user_filters = [];
        }

        $possible_filters = ['lww_inventory_location', 'condition_filter', 'wc_status_filter', 'image_status_filter', 'demand_filter'];
        $filters_changed = false;

        foreach ($possible_filters as $filter_key) {
            if (isset($_REQUEST[$filter_key])) {
                $this->user_filters[$filter_key] = sanitize_text_field($_REQUEST[$filter_key]);
                $filters_changed = true;
            } elseif (!isset($_REQUEST[$filter_key])) {
                // Wenn kein Filter in der URL ist, den gespeicherten anwenden
                if (!empty($this->user_filters[$filter_key])) {
                    $_REQUEST[$filter_key] = $this->user_filters[$filter_key];
                }
            }
        }

        if ($filters_changed) {
            update_user_meta($user_id, $meta_key, $this->user_filters);
        }
    }

    /**
     * Definiert die Spalten der Tabelle.
     * @return array Assoziatives Array der Spalten-Slugs zu den Titeln.
     */
    public function get_columns() {
        $columns = [
            'cb'         => '<input type="checkbox" />',
            'thumbnail'  => __('Bild', 'lego-wawi'),
            'name'       => __('Inventar-Posten / Teil', 'lego-wawi'), // Angepasster Titel
            'color'      => __('Farbe', 'lego-wawi'),
            'location'   => __('Lagerort', 'lego-wawi'),
            'condition'  => __('Zustand', 'lego-wawi'),
            'quantity'   => __('Menge', 'lego-wawi'),
            'price'      => __('Preis', 'lego-wawi'),
            'demand'     => __('Nachfrage (KI)', 'lego-wawi'),
            'marketplaces' => __('Marktplätze', 'lego-wawi'),
            'actions'    => __('Aktionen', 'lego-wawi'),
        ];
        return $columns;
    }

    /**
     * Definiert, welche Spalten sortierbar sind.
     * @return array
     */
    public function get_sortable_columns() {
        $sortable_columns = [
            'name'      => ['name', false], // Sortiert nach Titel
            'condition' => ['condition', false],
            'quantity'  => ['quantity', false],
            'price'     => ['price', false],
            'demand'    => ['demand', false],
        ];
        return $sortable_columns;
    }

    /**
     * Definiert die Bulk-Aktionen.
     * @return array
     */
    public function get_bulk_actions() {
        $actions = [
            'lww_bulk_create_wc' => __('WooCommerce Produkte erstellen/aktualisieren', 'lego-wawi'),
            'lww_bulk_delete'    => __('Löschen (Nur Inventar-Eintrag)', 'lego-wawi')
        ];
        return $actions;
    }

    /**
     * Rendert die Checkbox-Spalte.
     */
    function column_cb($item) {
        return sprintf(
            '<input type="checkbox" name="inventory_item[]" value="%s" />', $item->ID
        );
    }

    /**
     * Rendert die Spalte 'name'.
     * Verlinkt zum 'lww_inventory_item' und zum 'lww_part' Post.
     */
    function column_name($item) {
        $title = $item->post_title;
        $part_id = get_post_meta($item->ID, '_lww_part_id', true);
        $boid = get_post_meta($item->ID, '_boid', true);
        $actions = [];

        // Title with link to edit the inventory item
        $edit_link = get_edit_post_link($item->ID);
        $linked_title = sprintf(
            '<a class="row-title" href="%s" aria-label="%s"><strong>%s</strong></a>',
            esc_url($edit_link),
            esc_attr(sprintf(__('Bearbeite "%s"', 'lego-wawi'), $title)),
            esc_html($title)
        );

        // Link zum verknüpften 'lww_part'
        if ($part_id) {
            $part_title = get_the_title($part_id);
            $actions['view_part'] = sprintf(
                '<a href="%s" aria-label="%s" style="color: #2271b1;">%s</a>',
                get_edit_post_link($part_id),
                esc_attr(sprintf(__('Bearbeite Teil "%s"', 'lego-wawi'), $part_title)),
                __('Katalog-Teil anzeigen', 'lego-wawi')
            );
        } else {
             $actions['view_part'] = sprintf('<span style="color: #d63638;">%s</span>', __('Kein Katalog-Teil verknüpft', 'lego-wawi'));
        }

        return sprintf('%s<br><small>(BOID: %s)</small>%s',
            $linked_title,
            esc_html($boid),
            $this->row_actions($actions)
        );
    }

    /**
     * Rendert die Spalte 'color' (Farbe) mit Vorschau und Link.
     */
    function column_color($item) {
        $color_id = get_post_meta($item->ID, '_lww_color_id', true);
        $color_name = get_post_meta($item->ID, '_color_name', true);

        if (!$color_id && !$color_name) {
            return '---';
        }

        $rgb = $color_id ? get_post_meta($color_id, '_lww_rgb_hex', true) : null;
        $is_trans = $color_id ? get_post_meta($color_id, '_lww_is_transparent', true) : false;
        
        $preview_html = '';
        if ($rgb) {
            $style = ''; $class = 'lww-color-preview'; $inner_style = '';
            if ($is_trans) {
                $class .= ' transparent';
                $inner_style = 'style="background-color: #' . esc_attr($rgb) . '; opacity: 0.7;"';
            } else {
                $style = 'style="background-color: #' . esc_attr($rgb) . '"';
            }
            $preview_html = sprintf(
                '<div class="%s" %s title="#%s"><div class="lww-color-preview-inner" %s></div></div>',
                esc_attr($class), $style, esc_attr($rgb), $inner_style
            );
        }
        
        $color_title_html = esc_html($color_name);
        if ($color_id) {
            $color_title_html = sprintf(
                '<a href="%s">%s</a>',
                esc_url(get_edit_post_link($color_id)),
                $color_title_html
            );
        }

        return $preview_html . ' ' . $color_title_html;
    }
    
    /**
     * Rendert die Spalte 'thumbnail' (Bild).
     * Holt das Bild vom verknüpften 'lww_part'.
     */
    function column_thumbnail($item) {
        $part_id = get_post_meta($item->ID, '_lww_part_id', true);
        $thumb_size = [80, 80];
        $placeholder_style = 'style="width:' . $thumb_size[0] . 'px; height:' . $thumb_size[1] . 'px; background:#f0f0f1; border:1px solid #ddd; text-align:center; display:inline-block; line-height:' . $thumb_size[1] . 'px;"';

        if ($part_id && has_post_thumbnail($part_id)) {
            return get_the_post_thumbnail($part_id, $thumb_size);
        }
        
        return '<span class="dashicons dashicons-format-image" ' . $placeholder_style . ' title="' . __('Kein Bild im Katalog', 'lego-wawi') . '"></span>';
    }

    /**
     * Rendert die Spalte 'location' (Lagerort).
     */
    function column_location($item) {
        return get_the_term_list($item->ID, 'lww_inventory_location', '', ', ', '') ?: '---';
    }

    /**
     * Rendert die Spalte 'condition' (Zustand).
     */
    function column_condition($item) {
        $condition = get_post_meta($item->ID, '_condition', true);
        return ($condition === 'new') ? __('Neu', 'lego-wawi') : __('Gebraucht', 'lego-wawi');
    }

    /**
     * Rendert die Spalte 'quantity' (Menge).
     */
    function column_quantity($item) {
        return (int) get_post_meta($item->ID, '_quantity', true);
    }

    /**
     * Rendert die Spalte 'price' (Preis).
     */
    function column_price($item) {
        $price = (float) get_post_meta($item->ID, '_price', true);
        return '<span class="lww-item-price-display">' . number_format($price, 3, ',', '.') . ' €</span>';
    }

    /**
     * Rendert die Spalte 'demand' (Nachfrage).
     */
    function column_demand($item) {
        $score = get_post_meta($item->ID, '_lww_demand_score', true);
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
    }

    /**
     * Rendert die Spalte 'marketplaces'.
     * Prüft, ob der Artikel auf verschiedenen Marktplätzen gelistet ist.
     */
    function column_marketplaces($item) {
        $boid = get_post_meta($item->ID, '_boid', true);
        $wc_var_id = get_post_meta($item->ID, '_lww_wc_variation_id', true);
        $wc_prod_id = get_post_meta($item->ID, '_lww_wc_product_id', true);
        $ebay_id = get_post_meta($item->ID, '_lww_ebay_listing_id', true);
        
        $badges = [];

        if ($boid) {
            $badges[] = '<span class="lww-marketplace-badge lww-marketplace-bo" title="' . esc_attr__('Auf BrickOwl gelistet', 'lego-wawi') . '">BO</span>';
        }

        if ($wc_var_id && $wc_prod_id) {
            $wc_link = get_edit_post_link($wc_prod_id);
            $badges[] = sprintf(
                '<a href="%s" target="_blank" class="lww-marketplace-badge lww-marketplace-wc" title="' . esc_attr__('Als WooCommerce-Produkt synchronisiert (Variation ID: %d)', 'lego-wawi') . '">WC</a>',
                esc_url($wc_link),
                $wc_var_id
            );
        }

        if ($ebay_id) {
             $ebay_link = 'https://www.ebay.de/itm/' . $ebay_id;
             $badges[] = sprintf(
                '<a href="%s" target="_blank" class="lww-marketplace-badge lww-marketplace-ebay" title="' . esc_attr__('Auf eBay gelistet (Listing ID: %s)', 'lego-wawi') . '">eBay</a>',
                esc_url($ebay_link),
                $ebay_id
            );
        }

        if (empty($badges)) {
            return '<span class="dashicons dashicons-minus" style="color: #a0a5aa;"></span> ' . __('Nirgends gelistet', 'lego-wawi');
        }

        return '<div class="lww-marketplace-badges">' . implode(' ', $badges) . '</div>';
    }

    /**
     * Rendert die Spalte 'actions' (Aktionen).
     * Bereitet die Buttons für die AJAX-Handler vor.
     */
    function column_actions($item) {
        $wc_var_id = get_post_meta($item->ID, '_lww_wc_variation_id', true);
        $part_id = get_post_meta($item->ID, '_lww_part_id', true);
        $boid = get_post_meta($item->ID, '_boid', true);
        
        $buttons = [];

        // Button für BrickOwl-Preisabruf
        if ($boid) {
            $buttons[] = sprintf(
                '<button class="button button-secondary button-small lww-ajax-get-brickowl-price" data-item-id="%d" data-boid="%s" title="%s"><span class="dashicons dashicons-download"></span></button>',
                $item->ID,
                esc_attr($boid),
                __('Aktuellen Preis von BrickOwl abrufen', 'lego-wawi')
            );
        }

        // Button für WooCommerce-Erstellung/-Aktualisierung
        if ($part_id) {
            $wc_button_text = $wc_var_id ? __('Aktualisieren', 'lego-wawi') : __('Erstellen', 'lego-wawi');
            $buttons[] = sprintf(
                '<button class="button button-primary button-small lww-ajax-create-wc-product" data-item-id="%d" data-part-id="%d">%s</button>',
                $item->ID,
                $part_id,
                $wc_button_text
            );
        } else {
            $buttons[] = '<button class="button button-small" disabled>' . __('Katalog-Teil fehlt', 'lego-wawi') . '</button>';
        }

        return '<div style="display:flex; gap: 4px;">' . implode(' ', $buttons) . '</div>';
    }

    /**
     * Standard-Spalten-Renderer (Fallback).
     */
    function column_default($item, $column_name) {
        // Zeigt Roh-Meta-Daten für nicht definierte Spalten
        return get_post_meta($item->ID, '_' . $column_name, true);
    }

    /**
     * Fügt Filter-Dropdowns über der Tabelle hinzu.
     */
    public function extra_tablenav($which) {
        if ($which == "top") {
            echo '<div class="alignleft actions">';

            // --- Filter für Lagerort ---
            $taxonomy = 'lww_inventory_location';
            $selected_location = !empty($_REQUEST[$taxonomy]) ? sanitize_text_field($_REQUEST[$taxonomy]) : '';
            $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);

            if (!empty($terms) && !is_wp_error($terms)) {
                echo '<select name="' . esc_attr($taxonomy) . '" id="filter-by-' . esc_attr($taxonomy) . '">';
                echo '<option value="">' . __('Alle Lagerorte', 'lego-wawi') . '</option>';
                foreach ($terms as $term) {
                    printf(
                        '<option value="%s"%s>%s (%d)</option>',
                        esc_attr($term->slug),
                        selected($selected_location, $term->slug, false),
                        esc_html($term->name),
                        esc_html($term->count)
                    );
                }
                echo '</select>';
            }

            // --- Filter für Zustand ---
            $selected_condition = !empty($_REQUEST['condition_filter']) ? sanitize_key($_REQUEST['condition_filter']) : '';
            echo '<select name="condition_filter">';
            echo '<option value="">' . __('Alle Zustände', 'lego-wawi') . '</option>';
            echo '<option value="new"' . selected($selected_condition, 'new', false) . '>' . __('Neu', 'lego-wawi') . '</option>';
            echo '<option value="used"' . selected($selected_condition, 'used', false) . '>' . __('Gebraucht', 'lego-wawi') . '</option>';
            echo '</select>';

            // --- Filter für WooCommerce-Status ---
            $selected_wc_status = !empty($_REQUEST['wc_status_filter']) ? sanitize_key($_REQUEST['wc_status_filter']) : '';
            echo '<select name="wc_status_filter">';
            echo '<option value="">' . __('Alle WC-Status', 'lego-wawi') . '</option>';
            echo '<option value="synced"' . selected($selected_wc_status, 'synced', false) . '>' . __('Synchronisiert', 'lego-wawi') . '</option>';
            echo '<option value="not_synced"' . selected($selected_wc_status, 'not_synced', false) . '>' . __('Nicht synchronisiert', 'lego-wawi') . '</option>';
            echo '</select>';

            // --- Filter für Bild-Status ---
            $selected_image_status = !empty($_REQUEST['image_status_filter']) ? sanitize_key($_REQUEST['image_status_filter']) : '';
            echo '<select name="image_status_filter">';
            echo '<option value="">' . __('Alle Bild-Status', 'lego-wawi') . '</option>';
            echo '<option value="has_image"' . selected($selected_image_status, 'has_image', false) . '>' . __('Mit Bild', 'lego-wawi') . '</option>';
            echo '<option value="no_image"' . selected($selected_image_status, 'no_image', false) . '>' . __('Ohne Bild', 'lego-wawi') . '</option>';
            echo '</select>';

            // --- Filter für Nachfrage-Score ---
            $demand_filter_options = [
                '' => __('Alle Nachfrage-Scores', 'lego-wawi'),
                'high' => __('Hoch (75+)', 'lego-wawi'),
                'medium' => __('Mittel (40-74)', 'lego-wawi'),
                'low' => __('Niedrig (< 40)', 'lego-wawi'),
                'none' => __('Ohne Score', 'lego-wawi'),
            ];
            $current_demand_filter = !empty($_REQUEST['demand_filter']) ? sanitize_key($_REQUEST['demand_filter']) : '';
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

            submit_button(__('Filtern', 'lego-wawi'), 'button', 'filter_action', false, ['id' => 'post-query-submit']);

            // --- Filter zurücksetzen Button ---
            if (!empty($this->user_filters)) {
                $reset_url = add_query_arg('lww_reset_filters', 'true');
                $reset_url = remove_query_arg(['paged', 's'], $reset_url);
                echo ' <a href="' . esc_url($reset_url) . '" class="button">' . __('Filter zurücksetzen', 'lego-wawi') . '</a>';
            }

            echo '</div>';
        }
    }

    /**
     * Holt die Daten und bereitet sie für die Anzeige vor (WP_Query).
     */
    public function prepare_items() {
        $columns  = $this->get_columns();
        $hidden   = []; // Versteckte Spalten
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];

        // Bulk Actions
        $this->process_bulk_action();

        // Paginierung
        $per_page     = $this->get_items_per_page('inventory_items_per_page', 20);
        $current_page = $this->get_pagenum();

        // WP_Query Argumente
        $args = [
            'post_type'      => 'lww_inventory_item',
            'posts_per_page' => $per_page,
            'paged'          => $current_page,
            'post_status'    => ['publish'],
        ];

        // Sortierung
        $orderby = $_REQUEST['orderby'] ?? 'name';
        $order   = $_REQUEST['order'] ?? 'asc';

        switch ($orderby) {
            case 'name':
                $args['orderby'] = 'title';
                $args['order'] = $order;
                break;
            case 'condition':
                $args['meta_key'] = '_condition';
                $args['orderby'] = 'meta_value';
                $args['order'] = $order;
                break;
            case 'quantity':
                $args['meta_key'] = '_quantity';
                $args['orderby'] = 'meta_value_num';
                $args['order'] = $order;
                break;
            case 'price':
                $args['meta_key'] = '_price';
                $args['orderby'] = 'meta_value_num';
                $args['order'] = $order;
                break;
            case 'demand': // NEU
                $args['meta_key'] = '_lww_demand_score';
                $args['orderby'] = 'meta_value_num';
                $args['order'] = $order;
                break;
        }

        // Suche wird über pre_get_posts gehandhabt
        if (!empty($_REQUEST['s'])) {
            $args['s'] = sanitize_text_field($_REQUEST['s']);
        }

        // Meta Query für Filter
        $args['meta_query'] = ['relation' => 'AND'];
        if (!empty($_REQUEST['condition_filter'])) {
            $args['meta_query'][] = [
                'key' => '_condition',
                'value' => sanitize_key($_REQUEST['condition_filter']),
            ];
        }
        if (!empty($_REQUEST['wc_status_filter'])) {
            if ($_REQUEST['wc_status_filter'] === 'synced') {
                 $args['meta_query'][] = ['key' => '_lww_wc_variation_id', 'compare' => 'EXISTS'];
            } elseif ($_REQUEST['wc_status_filter'] === 'not_synced') {
                 $args['meta_query'][] = ['key' => '_lww_wc_variation_id', 'compare' => 'NOT EXISTS'];
            }
        }
        if (!empty($_REQUEST['demand_filter'])) {
            $demand_filter = sanitize_key($_REQUEST['demand_filter']);
            switch ($demand_filter) {
                case 'high':
                    $args['meta_query'][] = ['key' => '_lww_demand_score', 'value' => 75, 'compare' => '>=', 'type' => 'NUMERIC'];
                    break;
                case 'medium':
                    $args['meta_query'][] = ['key' => '_lww_demand_score', 'value' => [40, 74], 'compare' => 'BETWEEN', 'type' => 'NUMERIC'];
                    break;
                case 'low':
                    $args['meta_query'][] = ['key' => '_lww_demand_score', 'value' => 40, 'compare' => '<', 'type' => 'NUMERIC'];
                    break;
                case 'none':
                    $args['meta_query'][] = ['key' => '_lww_demand_score', 'compare' => 'NOT EXISTS'];
                    break;
            }
        }

        // Bild-Status Filter
        if (!empty($_REQUEST['image_status_filter'])) {
            $image_status_filter = sanitize_key($_REQUEST['image_status_filter']);
            $part_query_args = [
                'post_type' => 'lww_part',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'meta_query' => [
                    [
                        'key' => '_thumbnail_id',
                        'compare' => ($image_status_filter === 'has_image') ? 'EXISTS' : 'NOT EXISTS',
                    ]
                ]
            ];
            $part_ids_with_status = get_posts($part_query_args);

            if (empty($part_ids_with_status)) {
                $part_ids_with_status = [0]; // Sicherstellen, dass die 'IN' Query nicht leer ist
            }

            $args['meta_query'][] = [
                'key' => '_lww_part_id',
                'value' => $part_ids_with_status,
                'compare' => 'IN'
            ];
        }

        // Taxonomie-Query für Filter
        $args['tax_query'] = ['relation' => 'AND'];
        if (!empty($_REQUEST['lww_inventory_location'])) {
            $args['tax_query'][] = [
                'taxonomy' => 'lww_inventory_location',
                'field'    => 'slug',
                'terms'    => sanitize_text_field($_REQUEST['lww_inventory_location']),
            ];
        }

        $query = new WP_Query($args);
        $this->items = $query->posts;

        $this->set_pagination_args([
            'total_items' => $query->found_posts,
            'per_page'    => $per_page
        ]);
    }
    
    /**
     * Zeigt eine Nachricht an, wenn keine Items gefunden wurden.
     */
    public function no_items() {
        _e('Keine Inventar-Items gefunden. Bitte führe zuerst einen Inventar-Import durch oder passe deine Filter an.', 'lego-wawi');
    }

} // Ende LWW_Inventory_List_Table


/**
 * =========================================================================
 * Haupt-Render-Funktion
 * =========================================================================
 * Rendert den Inhalt der Seite "BrickOwl Inventar verwalten".
 */
function lww_render_inventory_ui_page() {
    // Sicherstellen, dass der Nutzer die Berechtigung hat
    if (!current_user_can('manage_options')) {
        wp_die(__('Sie haben keine Berechtigung, auf diese Seite zuzugreifen.', 'lego-wawi'));
    }

    // Instanziieren und Vorbereiten der Tabelle
    $inventory_list_table = new LWW_Inventory_List_Table();
    $inventory_list_table->prepare_items();

    ?>
    <div class="wrap lww-wrap">
        <h1><img src="<?php echo esc_url(LWW_PLUGIN_URL . 'assets/img/bricksberg-logo.png'); ?>" alt="Bricksberg Logo" class="lww-header-logo" /> <?php _e('Inventar Verwalten', 'lego-wawi'); ?></h1>
        <p><?php _e('Hier siehst du deinen importierten Bestand. Wähle Artikel aus, um WooCommerce-Produkte zu erstellen oder zu aktualisieren.', 'lego-wawi'); ?></p>

        <?php settings_errors('lww_inventory_messages'); // Für zukünftige Nachrichten ?>

        <div class="lww-card">
            <form id="inventory-filter" method="get">
                <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page'] ?? ''); ?>" />
                
                <?php 
                $inventory_list_table->search_box(__('Inventar durchsuchen', 'lego-wawi'), 'lww-inventory-search');
                $inventory_list_table->display(); // Zeigt die Tabelle an 
                ?>
            </form>
        </div>
    </div>
    
    <?php
}

?>