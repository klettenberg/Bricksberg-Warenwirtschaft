<?php
/**
 * Modul: Inventur-Modus UI (v2.0-APP)
 * 
 * UPDATE: Unterstützt nun den 'App'-Modus (Fullscreen) für mobile Geräte.
 */
if (!defined('ABSPATH')) exit;

function lww_render_stock_take_ui_page() {
    if (!current_user_can('manage_options')) wp_die('Access denied');
    
    // NEU: Body Class Filter für App-Modus
    $is_app_mode = isset($_GET['mode']) && $_GET['mode'] === 'app';
    
    if ($is_app_mode) {
        // Dies fügt die Klasse hinzu, die in lww-mobile-styles.css Admin-Elemente ausblendet
        add_filter('admin_body_class', function($classes) {
            return $classes . ' lww-mobile-view ';
        });
    }
    ?>
    <div class="wrap lww-wrap lww-stock-take-wrap">
        <?php if ($is_app_mode): ?>
            <!-- Mobile Header Only -->
            <a href="<?php echo admin_url('admin.php?page=bricksberg_wawi_dashboard'); ?>" class="lww-mobile-exit">EXIT</a>
        <?php endif; ?>

        <h1><span class="dashicons dashicons-clipboard"></span> <?php _e('Inventur-Modus (Scanner)', 'lego-wawi'); ?></h1>
        <p><?php _e('Dieser Modus ist für Tablets und Barcode-Scanner optimiert. Scannen Sie einen Artikel (oder geben Sie die ID/Nummer ein), um den Bestand sofort zu korrigieren.', 'lego-wawi'); ?></p>

        <div class="lww-card lww-stock-take-card">
            <!-- Scanner Input -->
            <div class="lww-scanner-input-group">
                <label for="lww-stock-scanner"><?php _e('Scan / Eingabe:', 'lego-wawi'); ?></label>
                <input type="text" id="lww-stock-scanner" class="large-text" placeholder="Teilenummer, BOID, oder ID..." autofocus autocomplete="off">
                <span class="spinner" id="lww-stock-spinner"></span>
            </div>

            <!-- Result Area -->
            <div id="lww-stock-result" style="display:none;">
                <div class="lww-stock-item-preview">
                    <!-- Wird per JS gefüllt -->
                    <div class="item-image"></div>
                    <div class="item-details">
                        <h2 class="item-title"></h2>
                        <p class="item-meta"></p>
                    </div>
                </div>

                <div class="lww-stock-adjustment">
                    <div class="current-stock-display">
                        <?php _e('Aktuell:', 'lego-wawi'); ?> <strong id="lww-current-stock">0</strong>
                    </div>
                    <div class="new-stock-input">
                        <label><?php _e('Gezählte Menge (IST):', 'lego-wawi'); ?></label>
                        <input type="number" id="lww-new-quantity" class="large-text" min="0">
                    </div>
                    <button type="button" id="lww-save-stock" class="button button-primary button-hero"><?php _e('Bestand korrigieren', 'lego-wawi'); ?></button>
                </div>
            </div>

            <div id="lww-stock-message"></div>
        </div>
    </div>
    
    <style>
        .lww-stock-take-card { padding: 30px; text-align: center; max-width: 600px; margin: 0 auto; }
        .lww-scanner-input-group label { display: block; font-size: 1.2em; font-weight: bold; margin-bottom: 10px; }
        .lww-scanner-input-group input { font-size: 1.5em; text-align: center; width: 100%; }
        .lww-stock-item-preview { display: flex; align-items: center; text-align: left; margin: 20px 0; border: 1px solid #ddd; padding: 15px; background: #f9f9f9; border-radius: 5px; }
        .lww-stock-item-preview .item-image { margin-right: 20px; }
        .lww-stock-item-preview img { max-width: 80px; height: auto; }
        .lww-stock-adjustment { background: #e5f5fa; padding: 20px; border-radius: 5px; }
        .current-stock-display { font-size: 1.2em; margin-bottom: 15px; }
        .new-stock-input input { font-size: 2em; width: 150px; text-align: center; }
        .button-hero { margin-top: 15px; width: 100%; }
    </style>
    <?php
}

// AJAX Handler registrieren
add_action('wp_ajax_lww_stock_take_search', 'lww_ajax_stock_take_search');
add_action('wp_ajax_lww_stock_take_update', 'lww_ajax_stock_take_update');

function lww_ajax_stock_take_search() {
    check_ajax_referer('lww_stock_take_nonce', '_nonce');
    $q = sanitize_text_field($_POST['query']);
    
    // Suche nach ID, Title, oder Meta
    $args = [
        'post_type' => 'lww_inventory_item',
        'post_status' => 'publish',
        'posts_per_page' => 1,
        'meta_query' => [
            'relation' => 'OR',
            ['key' => '_lww_part_num', 'value' => $q, 'compare' => '='], // Indirekt via Part (komplexer)
            ['key' => '_boid', 'value' => $q, 'compare' => '='],
            ['key' => '_lww_bricklink_item_no', 'value' => $q, 'compare' => '=']
        ]
    ];
    
    // Einfache Suche nach Titel/ID
    if (is_numeric($q)) $args['post__in'] = [$q];
    else $args['s'] = $q;

    $query = new WP_Query($args);
    if (!$query->have_posts()) wp_send_json_error('Kein Artikel gefunden.');
    
    $post = $query->posts[0];
    $qty = (int)get_post_meta($post->ID, '_quantity', true);
    
    // Bild holen
    $img = '';
    $catalog_id = get_post_meta($post->ID, '_lww_part_id', true) 
                  ?: get_post_meta($post->ID, '_lww_minifig_id', true) 
                  ?: get_post_meta($post->ID, '_lww_set_id', true);
    if ($catalog_id && has_post_thumbnail($catalog_id)) {
        $img = get_the_post_thumbnail($catalog_id, [80, 80]);
    }
    
    wp_send_json_success([
        'id' => $post->ID,
        'title' => $post->post_title,
        'quantity' => $qty,
        'meta_html' => 'Lagerort: ' . get_the_term_list($post->ID, 'lww_inventory_location', '', ', '),
        'image_html' => $img
    ]);
}

function lww_ajax_stock_take_update() {
    check_ajax_referer('lww_stock_take_nonce', '_nonce');
    $id = absint($_POST['id']);
    $qty = absint($_POST['qty']);
    
    update_post_meta($id, '_quantity', $qty);
    wp_send_json_success('Bestand aktualisiert.');
}
?>
