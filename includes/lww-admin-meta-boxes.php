<?php
/**
 * Modul: Admin Meta-Boxen (v21.1-DECIMAL)
 * 
 * UPDATE: Preis-Eingabefeld unterstützt nun 3 Dezimalstellen (step="0.001").
 */
if (!defined('ABSPATH')) exit;

function lww_register_meta_boxes() {
    // Boxen für Inventar-Item
    add_meta_box('lww_inventory_data', __('Inventar-Daten (Warenwirtschaft)', 'lego-wawi'), 'lww_render_inventory_data_meta_box', 'lww_inventory_item', 'normal', 'high');
    
    // Box für Farben
    add_meta_box('lww_color_details', __('Farb-Details & Übersetzung', 'lego-wawi'), 'lww_render_color_meta_box', 'lww_color', 'normal', 'high');
}
add_action('add_meta_boxes', 'lww_register_meta_boxes');

/**
 * Rendert die Meta-Box für Inventar-Items.
 * Erlaubt das Bearbeiten von Preis, Menge, Zustand und Lagerort.
 */
function lww_render_inventory_data_meta_box($post) {
    wp_nonce_field('lww_save_inventory_meta', 'lww_inventory_meta_nonce');

    $price = get_post_meta($post->ID, '_price', true);
    $quantity = get_post_meta($post->ID, '_quantity', true);
    $condition = get_post_meta($post->ID, '_condition', true);
    $remarks = get_post_meta($post->ID, '_remarks', true);
    $boid = get_post_meta($post->ID, '_boid', true);
    
    // Lagerorte abrufen (Taxonomie)
    $current_locations = wp_get_post_terms($post->ID, 'lww_inventory_location', ['fields' => 'names']);
    $location_val = !empty($current_locations) ? implode(', ', $current_locations) : '';

    ?>
    <div class="lww-meta-box-grid" style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
        <div>
            <p>
                <label for="lww_price"><strong><?php _e('Verkaufspreis (€):', 'lego-wawi'); ?></strong></label><br>
                <!-- 3 Dezimalstellen Support -->
                <input type="number" name="lww_price" id="lww_price" value="<?php echo esc_attr(number_format((float)$price, 3, '.', '')); ?>" step="0.001" min="0" class="widefat" style="font-size:1.2em;">
            </p>
            <p>
                <label for="lww_quantity"><strong><?php _e('Menge / Bestand:', 'lego-wawi'); ?></strong></label><br>
                <input type="number" name="lww_quantity" id="lww_quantity" value="<?php echo esc_attr($quantity); ?>" step="1" min="0" class="widefat" style="font-size:1.2em;">
            </p>
            <p>
                <label for="lww_condition"><strong><?php _e('Zustand:', 'lego-wawi'); ?></strong></label><br>
                <select name="lww_condition" id="lww_condition" class="widefat">
                    <option value="new" <?php selected($condition, 'new'); ?>><?php _e('Neu (New)', 'lego-wawi'); ?></option>
                    <option value="used" <?php selected($condition, 'used'); ?>><?php _e('Gebraucht (Used)', 'lego-wawi'); ?></option>
                </select>
            </p>
        </div>
        <div>
            <p>
                <label for="lww_location"><strong><?php _e('Lagerort:', 'lego-wawi'); ?></strong></label><br>
                <input type="text" name="lww_location" id="lww_location" value="<?php echo esc_attr($location_val); ?>" class="widefat" placeholder="z.B. A-01-05">
                <span class="description"><?php _e('Mehrere Orte durch Komma trennen. Wird automatisch als Taxonomie gespeichert.', 'lego-wawi'); ?></span>
            </p>
            <p>
                <label for="lww_boid"><strong><?php _e('BrickOwl ID / Externe ID:', 'lego-wawi'); ?></strong></label><br>
                <input type="text" name="lww_boid" id="lww_boid" value="<?php echo esc_attr($boid); ?>" class="widefat">
            </p>
            <p>
                <label for="lww_remarks"><strong><?php _e('Bemerkungen (Intern):', 'lego-wawi'); ?></strong></label><br>
                <textarea name="lww_remarks" id="lww_remarks" class="widefat" rows="3"><?php echo esc_textarea($remarks); ?></textarea>
            </p>
        </div>
    </div>
    <?php
}

/**
 * Speichert die Meta-Daten für Inventar-Items.
 */
function lww_save_inventory_meta($post_id) {
    if (!isset($_POST['lww_inventory_meta_nonce']) || !wp_verify_nonce($_POST['lww_inventory_meta_nonce'], 'lww_save_inventory_meta')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    // Preis
    if (isset($_POST['lww_price'])) {
        // Speichere volle Präzision
        update_post_meta($post_id, '_price', floatval($_POST['lww_price']));
    }
    // Menge
    if (isset($_POST['lww_quantity'])) {
        update_post_meta($post_id, '_quantity', intval($_POST['lww_quantity']));
    }
    // Zustand
    if (isset($_POST['lww_condition'])) {
        update_post_meta($post_id, '_condition', sanitize_text_field($_POST['lww_condition']));
    }
    // BOID
    if (isset($_POST['lww_boid'])) {
        update_post_meta($post_id, '_boid', sanitize_text_field($_POST['lww_boid']));
    }
    // Remarks
    if (isset($_POST['lww_remarks'])) {
        update_post_meta($post_id, '_remarks', sanitize_textarea_field($_POST['lww_remarks']));
    }
    // Lagerort (Taxonomie Update)
    if (isset($_POST['lww_location'])) {
        $loc_string = sanitize_text_field($_POST['lww_location']);
        if (!empty($loc_string)) {
            $locs = array_map('trim', explode(',', $loc_string));
            wp_set_object_terms($post_id, $locs, 'lww_inventory_location');
        } else {
            wp_set_object_terms($post_id, [], 'lww_inventory_location');
        }
    }
}
add_action('save_post_lww_inventory_item', 'lww_save_inventory_meta');


// --- Bestehende Funktionen für Farben ---

function lww_render_color_meta_box($post) {
    wp_nonce_field('lww_save_color_meta', 'lww_color_meta_nonce');
    $name_de = get_post_meta($post->ID, '_lww_color_name_de', true);
    $rgb = get_post_meta($post->ID, '_lww_rgb_hex', true);
    ?>
    <p>
        <label for="lww_color_name_de"><strong><?php _e('Deutscher Name:', 'lego-wawi'); ?></strong></label><br>
        <input type="text" name="lww_color_name_de" id="lww_color_name_de" value="<?php echo esc_attr($name_de); ?>" class="widefat">
        <span class="description"><?php _e('Wird in der Liste und im Frontend angezeigt.', 'lego-wawi'); ?></span>
    </p>
    <p>
        <label for="lww_rgb_hex"><strong>RGB Hex:</strong></label><br>
        <input type="text" name="lww_rgb_hex" value="<?php echo esc_attr($rgb); ?>" class="small-text">
        <span style="display:inline-block; width:20px; height:20px; background:#<?php echo esc_attr($rgb); ?>; vertical-align:middle; border:1px solid #ccc;"></span>
    </p>
    <?php
}

function lww_save_color_meta($post_id) {
    if (!isset($_POST['lww_color_meta_nonce']) || !wp_verify_nonce($_POST['lww_color_meta_nonce'], 'lww_save_color_meta')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    
    if (isset($_POST['lww_color_name_de'])) update_post_meta($post_id, '_lww_color_name_de', sanitize_text_field($_POST['lww_color_name_de']));
    if (isset($_POST['lww_rgb_hex'])) update_post_meta($post_id, '_lww_rgb_hex', sanitize_hex_color_no_hash($_POST['lww_rgb_hex']));
}
add_action('save_post_lww_color', 'lww_save_color_meta');
?>
