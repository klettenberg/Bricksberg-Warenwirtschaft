<?php
/**
 * Modul: Inventar UI & Steuerung (v10.0)
 *
 * Rendert die Seite "BrickOwl Inventar verwalten" und zeigt eine
 * WP_List_Table des 'lww_inventory_item' CPTs.
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

    /**
     * Konstruktor. Setzt die Bezeichnungen.
     */
    public function __construct() {
        parent::__construct([
            'singular' => __('Inventar Item', 'lego-wawi'), // singular name of the listed records
            'plural'   => __('Inventar Items', 'lego-wawi'), // plural name of the listed records
            'ajax'     => false // AJAX wird (noch) nicht für die Paginierung verwendet
        ]);
    }

    /**
     * Definiert die Spalten der Tabelle.
     * @return array Assoziatives Array der Spalten-Slugs zu den Titeln.
     */
    public function get_columns() {
        $columns = [
            'cb'         => '<input type="checkbox" />',
            'thumbnail'  => __('Bild', 'lego-wawi'),
            'name'       => __('Name / Teil', 'lego-wawi'),
            'color'      => __('Farbe', 'lego-wawi'),
            'condition'  => __('Zustand', 'lego-wawi'),
            'quantity'   => __('Menge', 'lego-wawi'),
            'price'      => __('Preis', 'lego-wawi'),
            'wc_status'  => __('WooCommerce Status', 'lego-wawi'),
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
     * Verlinkt zum 'lww_part' Post, falls verknüpft.
     */
    function column_name($item) {
        $title = $item->post_title;
        $part_id = get_post_meta($item->ID, '_lww_part_id', true);
        $boid = get_post_meta($item->ID, '_boid', true);
        $actions = [];

        // Link zum Bearbeiten des Inventar-Items selbst
        $actions['edit'] = sprintf(
            '<a href="%s" aria-label="%s">%s</a>',
            get_edit_post_link($item->ID),
            esc_attr(sprintf(__('Bearbeite "%s"', 'lego-wawi'), $title)),
            __('Bearbeiten', 'lego-wawi')
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

        return sprintf('<strong>%s</strong><br><small>(BOID: %s)</small>%s',
            esc_html($title),
            esc_html($boid),
            $this->row_actions($actions)
        );
    }

    /**
     * Rendert die Spalte 'color' (Farbe) mit Vorschau.
     */
    function column_color($item) {
        $color_id = get_post_meta($item->ID, '_lww_color_id', true);
        $color_name = get_post_meta($item->ID, '_color_name', true);

        if (!$color_id) {
            return esc_html($color_name) ?: '---';
        }

        $rgb = get_post_meta($color_id, 'lww_rgb_hex', true);
        $is_trans = get_post_meta($color_id, 'lww_is_transparent', true);
        
        $preview_html = '';
        if ($rgb) {
            $style = ''; $class = 'lww-color-preview'; $inner_style = '';
            if ($is_trans) {
                $class .= ' transparent';
                $inner_style = 'style="background-color: #' . esc_attr($rgb) . '; opacity: 0.7;"';
            } else {
                $style = 'style="background-color: #' . esc_attr($rgb) . ';"';
            }
            $preview_html = sprintf(
                '<div class="%s" %s title="#%s"><div class="lww-color-preview-inner" %s></div></div>',
                esc_attr($class), $style, esc_attr($rgb), $inner_style
            );
        }
        
        return $preview_html . ' ' . esc_html($color_name);
    }
    
    /**
     * Rendert die Spalte 'thumbnail' (Bild).
     * Holt das Bild vom verknüpften 'lww_part'.
     */
    function column_thumbnail($item) {
        $part_id = get_post_meta($item->ID, '_lww_part_id', true);
        $thumb_size = [40, 40];
        $placeholder_style = 'style="width:' . $thumb_size[0] . 'px; height:' . $thumb_size[1] . 'px; background:#f0f0f1; border:1px solid #ddd; text-align:center; display:inline-block; line-height:' . $thumb_size[1] . 'px;"';

        if ($part_id && has_post_thumbnail($part_id)) {
            return get_the_post_thumbnail($part_id, $thumb_size);
        }
        
        return '<span class="dashicons dashicons-format-image" ' . $placeholder_style . ' title="' . __('Kein Bild im Katalog', 'lego-wawi') . '"></span>';
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
        return number_format($price, 2, ',', '.') . ' €';
    }

    /**
     * Rendert die Spalte 'wc_status'.
     * Platzhalter-Logik: Prüft, ob ein Meta-Feld '_lww_wc_variation_id' existiert.
     */
    function column_wc_status($item) {
        $wc_var_id = get_post_meta($item->ID, '_lww_wc_variation_id', true);
        
        if ($wc_var_id) {
            $wc_link = get_edit_post_link($wc_var_id);
            return sprintf(
                '<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span> <a href="%s" target="_blank">%s (%d)</a>',
                esc_url($wc_link),
                __('Synchronisiert', 'lego-wawi'),
                $wc_var_id
            );
        }
        
        return '<span class="dashicons dashicons-minus" style="color: #a0a5aa;"></span> ' . __('Nicht in WooCommerce', 'lego-wawi');
    }

    /**
     * Rendert die Spalte 'actions' (Aktionen).
     * Bereitet die Buttons für die AJAX-Handler vor.
     */
    function column_actions($item) {
        $wc_var_id = get_post_meta($item->ID, '_lww_wc_variation_id', true);
        $part_id = get_post_meta($item->ID, '_lww_part_id', true);
        
        // Wenn kein Katalog-Teil verknüpft ist, kann kein WC-Produkt erstellt werden
        if (!$part_id) {
            return '<button class="button button-small" disabled>' . __('Katalog-Teil fehlt', 'lego-wawi') . '</button>';
        }

        $button_text = $wc_var_id ? __('Aktualisieren', 'lego-wawi') : __('Erstellen', 'lego-wawi');
        
        return sprintf(
            '<button class="button button-primary button-small lww-ajax-create-wc-product" data-item-id="%d" data-part-id="%d">%s</button>',
            $item->ID,
            $part_id,
            $button_text
        );
    }

    /**
     * Standard-Spalten-Renderer (Fallback).
     */
    function column_default($item, $column_name) {
        // Zeigt Roh-Meta-Daten für nicht definierte Spalten
        return get_post_meta($item->ID, '_' . $column_name, true);
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
        $total_items  = self::get_item_count(); // Statische Hilfsfunktion

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page
        ]);

        // WP_Query Argumente
        $args = [
            'post_type'      => 'lww_inventory_item',
            'posts_per_page' => $per_page,
            'paged'          => $current_page,
            'post_status'    => ['publish', 'draft'], // Zeige 'new' (publish) und 'used' (draft)
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
        }

        // Suche
        if (!empty($_REQUEST['s'])) {
            $search_term = sanitize_text_field($_REQUEST['s']);
            $args['s'] = $search_term;
            // TODO: Suche auch in Meta-Feldern (z.B. _boid) erweitern
        }

        $query = new WP_Query($args);
        $this->items = $query->posts;
    }
    
    /**
     * Hilfsfunktion: Zählt alle Items für die Paginierung.
     */
    public static function get_item_count() {
        $count_data = wp_count_posts('lww_inventory_item');
        return $count_data->publish + $count_data->draft;
    }
    
    /**
     * Zeigt eine Nachricht an, wenn keine Items gefunden wurden.
     */
    public function no_items() {
        _e('Keine Inventar-Items gefunden. Bitte führe zuerst einen Inventar-Import durch.', 'lego-wawi');
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
        <h1><span class="dashicons dashicons-list-view lww-title-icon"></span> <?php _e('BrickOwl Inventar Verwalten', 'lego-wawi'); ?></h1>
        <p><?php _e('Hier siehst du deinen importierten BrickOwl-Bestand. Wähle Artikel aus, um WooCommerce-Produkte zu erstellen oder zu aktualisieren.', 'lego-wawi'); ?></p>

        <?php settings_errors('lww_inventory_messages'); // Für zukünftige Nachrichten ?>

        <div class="lww-card">
            <form id="inventory-filter" method="post">
                <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page'] ?? ''); ?>" />
                
                <?php $inventory_list_table->search_box(__('Inventar durchsuchen', 'lego-wawi'), 'lww-inventory-search'); ?>
                
                <?php $inventory_list_table->display(); // Zeigt die Tabelle an ?>
            </form>
        </div>
    </div>
    
    <?php
    // TODO: JavaScript für die AJAX-Handler hier einbinden (enqueue)
    // Dieses Skript würde auf Klicks auf '.lww-ajax-create-wc-product' lauschen
    // und einen wp_ajax_... Call mit der 'data-item-id' machen.
}

?>
