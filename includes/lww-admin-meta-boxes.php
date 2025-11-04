<?php
/**
 * Modul: Admin Meta-Boxen (v13.0)
 *
 * Registriert und rendert benutzerdefinierte Meta-Boxen für die
 * Bearbeitungsseiten der CPTs.
 */
if (!defined('ABSPATH')) exit;

/**
 * Registriert alle Meta-Boxen für das Plugin.
 */
function lww_register_meta_boxes() {
    // Meta-Box für Stücklisten auf Set- und Minifig-Seiten
    add_meta_box(
        'lww_inventory_list_meta_box',
        __('Stückliste / Inventar', 'lego-wawi'),
        'lww_render_inventory_meta_box',
        ['lww_set', 'lww_minifig'],
        'normal',
        'high'
    );

    // Meta-Box für Preis-Historie auf Inventar-Item-Seiten
    add_meta_box(
        'lww_price_history_meta_box',
        __('Preis-Historie', 'lego-wawi'),
        'lww_render_price_history_meta_box',
        ['lww_inventory_item'],
        'side',
        'default'
    );

    // Meta-Box für externe Links auf Katalogseiten
    add_meta_box(
        'lww_external_links_meta_box',
        __('Externe Links', 'lego-wawi'),
        'lww_render_external_links_meta_box',
        ['lww_part', 'lww_set', 'lww_minifig'],
        'side',
        'default'
    );

    // NEUE Meta-Box für generierte Inhalte und UVP
    add_meta_box(
        'lww_generated_content_meta_box',
        __('Generierte Inhalte & Metadaten', 'lego-wawi'),
        'lww_render_generated_content_meta_box',
        ['lww_part', 'lww_set', 'lww_minifig'],
        'normal',
        'default'
    );
}
add_action('add_meta_boxes', 'lww_register_meta_boxes');

/**
 * Speichert die Daten aus der neuen Meta-Box.
 */
function lww_save_generated_content_meta_box_data($post_id) {
    if (!isset($_POST['lww_generated_content_nonce']) || !wp_verify_nonce($_POST['lww_generated_content_nonce'], 'lww_save_generated_content')) {
        return;
    }
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Felder für Sets
    if (isset($_POST['_lww_lego_rrp'])) {
        update_post_meta($post_id, '_lww_lego_rrp', sanitize_text_field($_POST['_lww_lego_rrp']));
    }
    
    // Gemeinsames Feld für Beschreibung (WC)
    if (isset($_POST['_lww_seo_description_wc'])) {
        update_post_meta($post_id, '_lww_seo_description_wc', wp_kses_post($_POST['_lww_seo_description_wc']));
    }

    // Felder für Teile
    if (isset($_POST['_lww_short_description'])) {
        update_post_meta($post_id, '_lww_short_description', sanitize_textarea_field($_POST['_lww_short_description']));
    }
}
add_action('save_post', 'lww_save_generated_content_meta_box_data');


/**
 * Rendert die Meta-Box für generierte Inhalte (Beschreibungen, UVP).
 */
function lww_render_generated_content_meta_box($post) {
    wp_nonce_field('lww_save_generated_content', 'lww_generated_content_nonce');
    $post_type = get_post_type($post->ID);

    echo '<p>' . __('Diese Inhalte wurden ggf. durch die KI-Funktionen generiert und können hier manuell angepasst werden.', 'lego-wawi') . '</p>';

    switch ($post_type) {
        case 'lww_set':
            $rrp = get_post_meta($post->ID, '_lww_lego_rrp', true);
            $desc_wc = get_post_meta($post->ID, '_lww_seo_description_wc', true);
            ?>
            <table class="form-table">
                <tr>
                    <th><label for="lww_lego_rrp"><?php _e('LEGO Listenpreis (UVP)', 'lego-wawi'); ?></label></th>
                    <td><input type="number" id="lww_lego_rrp" name="_lww_lego_rrp" value="<?php echo esc_attr($rrp); ?>" class="small-text" step="0.01" /> €</td>
                </tr>
                <tr>
                    <th><label for="lww_seo_description_wc"><?php _e('WooCommerce Beschreibung', 'lego-wawi'); ?></label></th>
                    <td><textarea id="lww_seo_description_wc" name="_lww_seo_description_wc" rows="8" class="large-text"><?php echo esc_textarea($desc_wc); ?></textarea></td>
                </tr>
            </table>
            <?php
            break;

        case 'lww_minifig':
            $desc_wc = get_post_meta($post->ID, '_lww_seo_description_wc', true);
            ?>
            <table class="form-table">
                 <tr>
                    <th><label for="lww_seo_description_wc"><?php _e('WooCommerce Beschreibung', 'lego-wawi'); ?></label></th>
                    <td><textarea id="lww_seo_description_wc" name="_lww_seo_description_wc" rows="8" class="large-text"><?php echo esc_textarea($desc_wc); ?></textarea></td>
                </tr>
            </table>
            <?php
            break;

        case 'lww_part':
            $short_desc = get_post_meta($post->ID, '_lww_short_description', true);
            $desc_wc = get_post_meta($post->ID, '_lww_seo_description_wc', true);
            ?>
            <table class="form-table">
                <tr>
                    <th><label for="lww_short_description"><?php _e('Kurzbeschreibung (WooCommerce)', 'lego-wawi'); ?></label></th>
                    <td><textarea id="lww_short_description" name="_lww_short_description" rows="3" class="large-text"><?php echo esc_textarea($short_desc); ?></textarea></td>
                </tr>
                 <tr>
                    <th><label for="lww_seo_description_wc"><?php _e('Lange Beschreibung (WooCommerce)', 'lego-wawi'); ?></label></th>
                    <td><textarea id="lww_seo_description_wc" name="_lww_seo_description_wc" rows="8" class="large-text"><?php echo esc_textarea($desc_wc); ?></textarea></td>
                </tr>
            </table>
            <?php
            break;
    }
}

/**
 * Rendert die Meta-Box für externe Links zu Marktplätzen.
 *
 * @param WP_Post $post Das aktuelle Post-Objekt.
 */
function lww_render_external_links_meta_box($post) {
    $links = [];
    $post_type = get_post_type($post->ID);

    switch ($post_type) {
        case 'lww_part':
            $part_num = get_post_meta($post->ID, '_lww_part_num', true);
            $bl_id = get_post_meta($post->ID, '_lww_bricklink_id', true);
            $bo_id = get_post_meta($post->ID, '_lww_brickowl_id', true);
            if ($part_num) {
                $links['rebrickable'] = 'https://rebrickable.com/parts/' . urlencode($part_num) . '/';
            }
            if ($bl_id) {
                $links['bricklink'] = 'https://www.bricklink.com/v2/catalog/catalogitem.page?P=' . urlencode($bl_id);
            }
            if ($bo_id) {
                $links['brickowl'] = 'https://www.brickowl.com/catalog/lego-' . urlencode($bo_id);
            }
            break;
        
        case 'lww_set':
            $set_num = get_post_meta($post->ID, '_lww_set_num', true);
            if ($set_num) {
                $links['rebrickable'] = 'https://rebrickable.com/sets/' . urlencode($set_num) . '/';
                $links['bricklink'] = 'https://www.bricklink.com/v2/catalog/catalogitem.page?S=' . urlencode($set_num);
                $links['brickowl'] = 'https://www.brickowl.com/catalog/lego-' . urlencode(rtrim($set_num, '-1')) . '-set'; // rtrim to handle variants like 75192-1
            }
            break;

        case 'lww_minifig':
            $minifig_num = get_post_meta($post->ID, '_lww_minifig_num', true);
            if ($minifig_num) {
                $links['rebrickable'] = 'https://rebrickable.com/minifigs/' . urlencode($minifig_num) . '/';
                $links['bricklink'] = 'https://www.bricklink.com/v2/catalog/catalogitem.page?M=' . urlencode($minifig_num);
                $links['brickowl'] = 'https://www.brickowl.com/catalog/lego-' . urlencode($minifig_num) . '-minifigure';
            }
            break;
    }

    if (empty($links)) {
        echo '<p>' . __('Für dieses Item konnten keine externen Links generiert werden.', 'lego-wawi') . '</p>';
        return;
    }

    echo '<ul>';
    foreach ($links as $site => $url) {
        printf(
            '<li><a href="%s" target="_blank">%s</a></li>',
            esc_url($url),
            sprintf(__('Bei %s ansehen', 'lego-wawi'), esc_html(ucfirst($site)))
        );
    }
    echo '</ul>';
}


/**
 * Rendert die Meta-Box für die Preis-Historie.
 *
 * @param WP_Post $post Das aktuelle Post-Objekt.
 */
function lww_render_price_history_meta_box($post) {
    $history = get_post_meta($post->ID, '_lww_price_history', true);

    if (empty($history) || !is_array($history)) {
        echo '<p>' . __('Noch keine Preisänderungen aufgezeichnet.', 'lego-wawi') . '</p>';
        return;
    }

    // Historie umkehren, um die neuesten Einträge zuerst anzuzeigen
    $history = array_reverse($history);

    echo '<table class="widefat">';
    echo '<thead><tr><th>' . __('Datum', 'lego-wawi') . '</th><th>' . __('Preis', 'lego-wawi') . '</th><th>' . __('Quelle', 'lego-wawi') . '</th></tr></thead>';
    echo '<tbody>';

    foreach ($history as $entry) {
        $timestamp = $entry['timestamp'] ?? 0;
        $price = $entry['price'] ?? 0.0;
        $source = $entry['source'] ?? 'unbekannt';

        echo '<tr>';
        echo '<td>' . ($timestamp ? esc_html(wp_date(get_option('date_format') . ' H:i', $timestamp)) : '---') . '</td>';
        echo '<td>' . esc_html(number_format((float)$price, 3, ',', '.')) . ' €</td>';
        echo '<td>' . esc_html(ucwords(str_replace('_', ' ', $source))) . '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';
}

/**
 * Rendert die Meta-Box für die Stückliste (Inventar) von Sets und Minifigs.
 *
 * @param WP_Post $post Das aktuelle Post-Objekt (ein Set oder eine Minifigur).
 */
function lww_render_inventory_meta_box($post) {
    $part_lines = get_post_meta($post->ID, '_lww_inventory_part_line');
    $set_lines = get_post_meta($post->ID, '_lww_inventory_set_line');
    $minifig_lines = get_post_meta($post->ID, '_lww_inventory_minifig_line');

    if (empty($part_lines) && empty($set_lines) && empty($minifig_lines)) {
        echo '<p>' . __('Für dieses Item wurde noch keine Stückliste importiert.', 'lego-wawi') . '</p>';
        return;
    }

    // Teile-Tabelle
    if (!empty($part_lines)) {
        echo '<h3>' . __('Enthaltene Teile', 'lego-wawi') . '</h3>';
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>' . __('Bild', 'lego-wawi') . '</th><th>' . __('Teil', 'lego-wawi') . '</th><th>' . __('Farbe', 'lego-wawi') . '</th><th>' . __('Menge', 'lego-wawi') . '</th><th>' . __('Ersatzteil', 'lego-wawi') . '</th></tr></thead>';
        echo '<tbody>';
        foreach ($part_lines as $line) {
            list($part_id, $color_id, $quantity, $is_spare) = explode('|', $line . '|||'); // Fallback für ältere Einträge
            $part_post = get_post((int)$part_id);
            $color_post = get_post((int)$color_id);
            if (!$part_post || !$color_post) continue;

            echo '<tr>';
            echo '<td style="width: 60px;">' . get_the_post_thumbnail($part_post->ID, [50, 50]) . '</td>';
            echo '<td><strong><a href="' . get_edit_post_link($part_post->ID) . '">' . esc_html($part_post->post_title) . '</a></strong><br><small>' . esc_html(get_post_meta($part_post->ID, '_lww_part_num', true)) . '</small></td>';
            echo '<td><a href="' . get_edit_post_link($color_post->ID) . '">' . esc_html($color_post->post_title) . '</a></td>';
            echo '<td>' . (int)$quantity . '</td>';
            echo '<td>' . ($is_spare == '1' ? __('Ja', 'lego-wawi') : __('Nein', 'lego-wawi')) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    // Set-Tabelle
    if (!empty($set_lines)) {
        echo '<h3 style="margin-top: 20px;">' . __('Enthaltene Sets', 'lego-wawi') . '</h3>';
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>' . __('Bild', 'lego-wawi') . '</th><th>' . __('Set', 'lego-wawi') . '</th><th>' . __('Menge', 'lego-wawi') . '</th></tr></thead>';
        echo '<tbody>';
        foreach ($set_lines as $line) {
            list($set_id, $quantity) = explode('|', $line . '|');
            $set_post = get_post((int)$set_id);
            if (!$set_post) continue;

            echo '<tr>';
            echo '<td style="width: 60px;">' . get_the_post_thumbnail($set_post->ID, [50, 50]) . '</td>';
            echo '<td><strong><a href="' . get_edit_post_link($set_post->ID) . '">' . esc_html($set_post->post_title) . '</a></strong><br><small>' . esc_html(get_post_meta($set_post->ID, '_lww_set_num', true)) . '</small></td>';
            echo '<td>' . (int)$quantity . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    // Minifiguren-Tabelle
    if (!empty($minifig_lines)) {
        echo '<h3 style="margin-top: 20px;">' . __('Enthaltene Minifiguren', 'lego-wawi') . '</h3>';
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>' . __('Bild', 'lego-wawi') . '</th><th>' . __('Minifigur', 'lego-wawi') . '</th><th>' . __('Menge', 'lego-wawi') . '</th></tr></thead>';
        echo '<tbody>';
        foreach ($minifig_lines as $line) {
            list($minifig_id, $quantity) = explode('|', $line . '|');
            $minifig_post = get_post((int)$minifig_id);
            if (!$minifig_post) continue;

            echo '<tr>';
            echo '<td style="width: 60px;">' . get_the_post_thumbnail($minifig_post->ID, [50, 50]) . '</td>';
            echo '<td><strong><a href="' . get_edit_post_link($minifig_post->ID) . '">' . esc_html($minifig_post->post_title) . '</a></strong><br><small>' . esc_html(get_post_meta($minifig_post->ID, '_lww_minifig_num', true)) . '</small></td>';
            echo '<td>' . (int)$quantity . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
}
