<?php
/**
 * Modul: Pick-Listen UI (v18.2-FIX)
 * Ermöglicht das Erstellen und Drucken von optimierten Packlisten für offene Bestellungen.
 * 
 * UPDATE: Nonce-Handling korrigiert, um "Link abgelaufen" Fehler zu beheben.
 */
if (!defined('ABSPATH')) exit;

// Stellt sicher, dass die WP_List_Table Klasse geladen ist
if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Rendert den Inhalt der Seite "Pick-Listen".
 */
function lww_render_pick_list_ui_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Sie haben keine Berechtigung, auf diese Seite zuzugreifen.', 'lego-wawi'));
    }

    // Prüfen, ob wir im "Druck-Modus" sind (nach Formular-Absenden)
    // FIX: Expliziter Nonce-Check mit eigenem Feldnamen, um Konflikte mit WP_List_Table zu vermeiden
    if (isset($_POST['lww_action']) && $_POST['lww_action'] === 'generate_pick_list') {
        if (isset($_POST['lww_picklist_nonce']) && wp_verify_nonce($_POST['lww_picklist_nonce'], 'lww_generate_pick_list_action')) {
            lww_render_pick_list_print_view();
            return;
        } else {
            echo '<div class="error"><p>' . __('Sicherheitsüberprüfung fehlgeschlagen. Bitte versuchen Sie es erneut.', 'lego-wawi') . '</p></div>';
        }
    }

    $pick_list_table = new LWW_Pick_List_Table();
    $pick_list_table->prepare_items();
    ?>
    <div class="wrap lww-wrap" id="lww-pick-list-page">
        <h1><img src="<?php echo esc_url(LWW_PLUGIN_URL . 'assets/img/bricksberg-logo.png'); ?>" alt="Bricksberg Logo" class="lww-header-logo" /> <?php _e('Pick-Listen Generator', 'lego-wawi'); ?></h1>
        <p><?php _e('Wählen Sie offene Bestellungen aus, um eine laufwegoptimierte Packliste zu generieren.', 'lego-wawi'); ?></p>

        <div class="lww-card lww-mt-20">
            <form method="post">
                <input type="hidden" name="lww_action" value="generate_pick_list">
                <!-- FIX: Eigener Name für das Nonce-Feld -->
                <?php wp_nonce_field('lww_generate_pick_list_action', 'lww_picklist_nonce'); ?>
                
                <?php $pick_list_table->display(); ?>
                
                <p class="submit">
                    <?php submit_button(__('Pick-Liste für Auswahl generieren', 'lego-wawi'), 'primary large', 'submit', false); ?>
                </p>
            </form>
        </div>
    </div>
    <?php
}

/**
 * Rendert die eigentliche Pick-Liste zum Drucken.
 */
function lww_render_pick_list_print_view() {
    $order_ids = isset($_POST['order']) ? array_map('absint', $_POST['order']) : [];
    
    if (empty($order_ids)) {
        echo '<div class="error"><p>' . __('Bitte wählen Sie mindestens eine Bestellung aus.', 'lego-wawi') . '</p></div>';
        lww_render_pick_list_ui_page(); // Fallback zur Auswahl
        return;
    }

    $aggregated_items = lww_generate_aggregated_pick_list_data($order_ids);
    ?>
    <div class="wrap lww-wrap">
        <div class="no-print">
            <h1><?php _e('Packliste Vorschau', 'lego-wawi'); ?></h1>
            <p>
                <button type="button" class="button button-primary button-hero" id="lww-print-pick-list-button" onclick="window.print();"><span class="dashicons dashicons-printer"></span> <?php _e('Jetzt Drucken', 'lego-wawi'); ?></button>
                <a href="<?php echo admin_url('admin.php?page=lww_pick_list_ui'); ?>" class="button button-secondary button-hero"><?php _e('Zurück zur Übersicht', 'lego-wawi'); ?></a>
            </p>
        </div>

        <div class="lww-pick-list-print-area lww-card">
            <div class="print-header">
                <h2><?php printf(__('Packliste (%s)', 'lego-wawi'), date('d.m.Y H:i')); ?></h2>
                <p><strong><?php echo count($order_ids); ?> <?php _e('Bestellungen:', 'lego-wawi'); ?></strong> <?php echo implode(', ', array_map(function($id) { return '#' . (get_post_meta($id, '_lww_external_order_id', true) ?: $id); }, $order_ids)); ?></p>
            </div>
            
            <table class="wp-list-table widefat striped print-table">
                <thead>
                    <tr>
                        <th style="width:30px;">[ ]</th>
                        <th style="width:60px;"><?php _e('Bild', 'lego-wawi'); ?></th>
                        <th style="width:120px;"><?php _e('Lagerort', 'lego-wawi'); ?></th>
                        <th style="width:60px;"><?php _e('Menge', 'lego-wawi'); ?></th>
                        <th><?php _e('Artikel', 'lego-wawi'); ?></th>
                        <th><?php _e('Farbe / Zustand', 'lego-wawi'); ?></th>
                        <th><?php _e('Verteilung (Order: Menge)', 'lego-wawi'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($aggregated_items as $item): ?>
                        <tr>
                            <td><div class="check-box"></div></td>
                            <td><?php echo $item['image']; ?></td>
                            <td><strong style="font-size:1.4em;"><?php echo esc_html(implode(', ', $item['locations'])); ?></strong></td>
                            <td><strong style="font-size:1.6em;"><?php echo $item['quantity']; ?>x</strong></td>
                            <td><strong><?php echo esc_html($item['title']); ?></strong><br><small><?php echo esc_html($item['part_num']); ?></small></td>
                            <td><?php echo esc_html($item['color']); ?><br><small><?php echo esc_html($item['condition']); ?></small></td>
                            <td>
                                <?php foreach ($item['allocations'] as $order_ref => $qty): ?>
                                    <span class="allocation-tag">#<?php echo esc_html($order_ref); ?>: <strong><?php echo $qty; ?></strong></span>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <style>
        .check-box { width: 20px; height: 20px; border: 2px solid #333; }
        .allocation-tag { display: inline-block; background: #eee; padding: 2px 6px; border-radius: 4px; margin: 2px; font-size: 0.9em; border: 1px solid #ddd; }
        @media print {
            .no-print, #adminmenumain, #wpadminbar, .update-nag, .notice, #wpfooter, .lww-header-logo, .button { display: none !important; }
            #wpcontent { margin-left: 0 !important; padding: 0 !important; }
            .lww-wrap { margin: 0 !important; width: 100% !important; max-width: none !important; }
            .lww-pick-list-print-area { border: none !important; box-shadow: none !important; padding: 0 !important; margin: 0 !important; }
            .print-table { border: 1px solid #ccc; font-size: 12px; }
            .print-table th { background-color: #f0f0f0 !important; -webkit-print-color-adjust: exact; }
            .print-table td { padding: 8px 5px !important; border-bottom: 1px solid #ddd; }
            tr { page-break-inside: avoid; }
        }
    </style>
    <?php
}

/**
 * Generiert die aggregierten und sortierten Daten für die Pick-Liste.
 */
function lww_generate_aggregated_pick_list_data(array $order_ids) {
    $aggregated_items = [];

    foreach ($order_ids as $order_id) {
        $order_post = get_post($order_id);
        $external_order_id = get_post_meta($order_id, '_lww_external_order_id', true) ?: $order_id;
        
        $order_items = get_post_meta($order_id, '_lww_order_items', true);
        if (!is_array($order_items)) continue;

        foreach ($order_items as $order_item) {
            $boid = $order_item['boid'] ?? '';
            $item_no = $order_item['item_no'] ?? '';
            $inv_item_id = 0;

            $meta_query = ['relation' => 'OR'];
            if ($boid) $meta_query[] = ['key' => '_boid', 'value' => $boid];
            if ($item_no) $meta_query[] = ['key' => '_lww_bricklink_item_no', 'value' => $item_no];
            
            if (count($meta_query) > 1) {
                $inv_query = new WP_Query([
                    'post_type' => 'lww_inventory_item',
                    'posts_per_page' => 1,
                    'meta_query' => $meta_query,
                    'fields' => 'ids'
                ]);
                if ($inv_query->have_posts()) {
                    $inv_item_id = $inv_query->posts[0];
                }
            }

            $key = $inv_item_id ? 'inv_' . $inv_item_id : 'raw_' . ($boid ?: $item_no) . '_' . ($order_item['color_id'] ?? '0');

            if (!isset($aggregated_items[$key])) {
                $title = __('Unbekannter Artikel', 'lego-wawi');
                $part_num = $boid ?: $item_no;
                $image = '';
                $color_name = __('Unbekannt', 'lego-wawi');
                $condition = '';
                $locations = [];

                if ($inv_item_id) {
                    $title = get_the_title($inv_item_id);
                    $part_id = get_post_meta($inv_item_id, '_lww_part_id', true);
                    if ($part_id) {
                        $part_num = get_post_meta($part_id, '_lww_part_num', true);
                        $image = get_the_post_thumbnail($part_id, [50, 50]);
                        $german_name = get_post_meta($part_id, '_lww_part_name_de', true);
                        if($german_name) $title = $german_name;
                    }
                    $color_name = get_post_meta($inv_item_id, '_color_name', true);
                    $condition_raw = get_post_meta($inv_item_id, '_condition', true);
                    $condition = ($condition_raw === 'new') ? __('Neu', 'lego-wawi') : __('Gebraucht', 'lego-wawi');
                    
                    $loc_terms = wp_get_post_terms($inv_item_id, 'lww_inventory_location', ['fields' => 'names']);
                    if (!is_wp_error($loc_terms) && !empty($loc_terms)) {
                        $locations = $loc_terms;
                    } else {
                        $locations = [__('ZZ_Unsortiert', 'lego-wawi')];
                    }
                } else {
                    if (isset($order_item['color_name'])) $color_name = $order_item['color_name'];
                    $locations = [__('ZZ_Nicht im Inventar', 'lego-wawi')];
                    // Versuche Namen aus Order Item zu nehmen
                    if (!empty($order_item['item_name'])) $title = $order_item['item_name'];
                }

                $aggregated_items[$key] = [
                    'title' => $title,
                    'part_num' => $part_num,
                    'image' => $image,
                    'color' => $color_name,
                    'condition' => $condition,
                    'locations' => $locations,
                    'quantity' => 0,
                    'allocations' => [],
                ];
            }

            $qty = (int) $order_item['quantity'];
            $aggregated_items[$key]['quantity'] += $qty;
            
            if (!isset($aggregated_items[$key]['allocations'][$external_order_id])) {
                $aggregated_items[$key]['allocations'][$external_order_id] = 0;
            }
            $aggregated_items[$key]['allocations'][$external_order_id] += $qty;
        }
    }

    uasort($aggregated_items, function ($a, $b) {
        $loc_a = reset($a['locations']);
        $loc_b = reset($b['locations']);
        return strnatcasecmp($loc_a, $loc_b);
    });

    return $aggregated_items;
}

/**
 * Tabelle zur Auswahl der Bestellungen.
 */
class LWW_Pick_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct([
            'singular' => __('Bestellung', 'lego-wawi'),
            'plural'   => __('Bestellungen', 'lego-wawi'),
            'ajax'     => false
        ]);
    }

    public function get_columns() {
        return [
            'cb'      => '<input type="checkbox" />',
            'order'   => __('Bestellung', 'lego-wawi'),
            'status'  => __('Status', 'lego-wawi'),
            'items'   => __('Positionen', 'lego-wawi'),
            'date'    => __('Datum', 'lego-wawi'),
        ];
    }

    public function prepare_items() {
        $this->_column_headers = [$this->get_columns(), [], []];
        
        $query = new WP_Query([
            'post_type' => 'lww_order',
            'post_status' => ['lww_processing', 'lww_received'],
            'posts_per_page' => 50,
            'orderby' => 'date',
            'order' => 'ASC',
        ]);
        
        $this->items = $query->posts;
    }

    function column_cb($item) {
        return sprintf('<input type="checkbox" name="order[]" value="%s" />', $item->ID);
    }

    function column_order($item) {
        $ext_id = get_post_meta($item->ID, '_lww_external_order_id', true);
        return sprintf('<strong><a href="%s">%s</a></strong><br><small>Extern: #%s</small>', get_edit_post_link($item->ID), esc_html($item->post_title), esc_html($ext_id));
    }

    function column_status($item) {
        $status_obj = get_post_status_object($item->post_status);
        return $status_obj ? $status_obj->label : $item->post_status;
    }

    function column_items($item) {
        $items = get_post_meta($item->ID, '_lww_order_items', true);
        return is_array($items) ? count($items) : 0;
    }

    function column_date($item) {
        return get_the_date('d.m.Y H:i', $item->ID);
    }
    
    public function no_items() {
        _e('Keine offenen Bestellungen gefunden.', 'lego-wawi');
    }
}
