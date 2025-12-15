<?php
/**
 * Modul: UI für Marktlage & Preisoptimierung (v21.4)
 * 
 * HINZUGEFÜGT: Spotlight-Feature zur Visualisierung von Marktpreisen pro Farbe für Top-Artikel.
 * UPDATE: Button 'Marktdaten abrufen' aktiviert, sobald mind. EINE API konfiguriert ist.
 */
if (!defined('ABSPATH')) exit;

function lww_render_market_analysis_ui_page() {
    if (!current_user_can('manage_options')) wp_die('Access denied');

    $api_settings = get_option('lww_api_settings');
    // KORREKTUR: ODER statt UND. Es reicht, wenn eine der beiden APIs da ist.
    $can_analyze = !empty($api_settings['brickowl_api_key']) || !empty($api_settings['bricklink_consumer_key']);
    $stats = lww_get_market_analysis_stats();
    $global_source = get_option('lww_price_strategy_source', 'bricklink_avg');
    ?>
    <div class="wrap lww-wrap">
        <h1><img src="<?php echo esc_url(LWW_PLUGIN_URL . 'assets/img/bricksberg-logo.png'); ?>" alt="Bricksberg Logo" class="lww-header-logo" /> <?php _e('Marktlage & Preisoptimierung', 'lego-wawi'); ?></h1>
        <p><?php _e('Analysiere deine Preisstrategie im Vergleich zu den aktuellen Marktdaten von BrickOwl und BrickLink.', 'lego-wawi'); ?></p>

        <?php settings_errors('lww_messages'); ?>

        <div class="lww-admin-form lww-card lww-mt-20">
            <h2><?php _e('Marktdaten aktualisieren', 'lego-wawi'); ?></h2>
            <p><?php _e('Hintergrund-Job starten, um aktuelle Preise (Avg, Min, Qty) von BrickLink und/oder BrickOwl zu laden.', 'lego-wawi'); ?></p>
             <?php if (!$can_analyze): ?>
                <div class="notice notice-warning inline lww-notice"><p><?php _e('Keine API-Schlüssel gefunden (Einstellungen). Bitte konfigurieren Sie mindestens BrickLink oder BrickOwl.', 'lego-wawi'); ?></p></div>
            <?php endif; ?>
            <form action="admin-post.php" method="post">
                <input type="hidden" name="action" value="lww_start_market_price_sync">
                <?php wp_nonce_field('lww_start_market_price_sync_nonce'); ?>
                <?php submit_button(__('Marktdaten jetzt abrufen', 'lego-wawi'), 'primary', 'submit', true, !$can_analyze ? ['disabled' => 'disabled'] : null); ?>
            </form>
        </div>
        
        <!-- NEU: Spotlight Widget -->
        <?php lww_render_price_spotlight(); ?>

        <h2 class="lww-mt-20"><?php _e('Preis-Analyse Übersicht', 'lego-wawi'); ?></h2>
        <div class="lww-dashboard-grid">
            <div class="lww-stat-card">
                <h3><span class="dashicons dashicons-chart-pie"></span><?php _e('Analysierte Artikel', 'lego-wawi'); ?></h3>
                <div class="stat-number"><?php echo esc_html(number_format_i18n($stats['total_analyzed'])); ?></div>
                 <p class="description"><?php printf(__('%s%% deines Inventars.', 'lego-wawi'), $stats['percentage_analyzed']); ?></p>
            </div>
            <div class="lww-stat-card core-data-ok">
                <h3><span class="dashicons dashicons-arrow-down-alt"></span><?php _e('Günstiger als Markt', 'lego-wawi'); ?></h3>
                <div class="stat-number"><?php echo esc_html(number_format_i18n($stats['below_market'])); ?></div>
            </div>
            <div class="lww-stat-card core-data-missing">
                <h3><span class="dashicons dashicons-arrow-up-alt"></span><?php _e('Teurer als Markt', 'lego-wawi'); ?></h3>
                <div class="stat-number"><?php echo esc_html(number_format_i18n($stats['above_market'])); ?></div>
            </div>
        </div>

        <div class="lww-card lww-mt-20">
            <h2><?php _e('Massen-Preisoptimierung', 'lego-wawi'); ?></h2>
            <p><?php _e('Identifiziere Artikel mit starken Preisabweichungen und passe sie an.', 'lego-wawi'); ?></p>
            
            <form method="get" class="lww-price-analysis-filter">
                <input type="hidden" name="page" value="lww_market_analysis_ui">
                <div class="tablenav top">
                    <div class="alignleft actions">
                        <select name="compare_source">
                            <option value="bricklink_avg" <?php selected($_GET['compare_source'] ?? $global_source, 'bricklink_avg'); ?>>BrickLink Durchschnitt (Avg)</option>
                            <option value="bricklink_min" <?php selected($_GET['compare_source'] ?? $global_source, 'bricklink_min'); ?>>BrickLink Minimum (Min)</option>
                            <option value="brickowl_avg" <?php selected($_GET['compare_source'] ?? $global_source, 'brickowl_avg'); ?>>BrickOwl Durchschnitt (Avg)</option>
                            <option value="brickowl_min" <?php selected($_GET['compare_source'] ?? $global_source, 'brickowl_min'); ?>>BrickOwl Minimum (Min)</option>
                        </select>
                        <select name="deviation_type">
                            <option value="expensive" <?php selected($_GET['deviation_type'] ?? '', 'expensive'); ?>>Teurer als Markt (> X%)</option>
                            <option value="cheap" <?php selected($_GET['deviation_type'] ?? '', 'cheap'); ?>>Billiger als Markt (< X%)</option>
                        </select>
                        <input type="number" name="deviation_percent" value="<?php echo esc_attr($_GET['deviation_percent'] ?? '20'); ?>" style="width: 60px;" min="1"> %
                        <input type="submit" class="button" value="Analysieren">
                    </div>
                </div>
            </form>

            <?php lww_render_price_optimization_table(); ?>
        </div>
    </div>
    <?php
}

/**
 * Rendert ein Info-Element, das für ein populäres Teil die Preise pro Farbe anzeigt.
 */
function lww_render_price_spotlight() {
    global $wpdb;

    // 1. Finde das am häufigsten vorkommende Teil im Inventar (nach Anzahl der Farbvarianten)
    $top_part_id = $wpdb->get_var("
        SELECT pm.meta_value 
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
        WHERE pm.meta_key = '_lww_part_id' 
        AND p.post_type = 'lww_inventory_item' 
        AND p.post_status = 'publish'
        GROUP BY pm.meta_value 
        ORDER BY COUNT(pm.post_id) DESC 
        LIMIT 1
    ");

    if (!$top_part_id) return;

    // 2. Lade alle Inventar-Einträge für dieses Teil
    $variants = get_posts([
        'post_type' => 'lww_inventory_item',
        'posts_per_page' => -1,
        'meta_key' => '_lww_part_id',
        'meta_value' => $top_part_id,
        'post_status' => 'publish'
    ]);

    if (empty($variants)) return;

    $part_title = get_the_title($top_part_id);
    $part_img = get_the_post_thumbnail($top_part_id, [100, 100], ['style' => 'border-radius:4px; border:1px solid #ccc;']);

    echo '<div class="lww-card lww-mt-20" style="background:#fafafa;">';
    echo '<div style="display:flex; align-items:center; gap:20px; margin-bottom:15px;">';
    echo '<div>' . $part_img . '</div>';
    echo '<div><h3 style="margin:0;">' . __('Markt-Spotlight:', 'lego-wawi') . ' ' . esc_html($part_title) . '</h3>';
    echo '<p>' . __('Marktpreis-Vergleich pro Farbe (BrickLink Avg) für diesen Bestseller.', 'lego-wawi') . '</p></div>';
    echo '</div>';

    echo '<div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap:10px;">';

    foreach ($variants as $variant) {
        $color_name = get_post_meta($variant->ID, '_color_name', true);
        $color_id = get_post_meta($variant->ID, '_lww_color_id', true);
        $rgb = $color_id ? get_post_meta($color_id, '_lww_rgb_hex', true) : 'cccccc';
        
        // Marktpreise holen
        $market_data = get_post_meta($variant->ID, '_lww_market_prices', true);
        $bl_price = (float)($market_data['bricklink']['price'] ?? 0);
        $bo_price = (float)($market_data['brickowl']['price'] ?? 0);
        
        // Nur anzeigen, wenn Marktpreise da sind
        if ($bl_price <= 0 && $bo_price <= 0) continue;

        $ref_price = $bl_price > 0 ? $bl_price : $bo_price;
        $source = $bl_price > 0 ? 'BL Avg' : 'BO Avg';

        echo '<div style="background:#fff; border:1px solid #e5e5e5; padding:10px; border-radius:4px; text-align:center;">';
        echo '<div style="width:100%; height:8px; background:#' . esc_attr($rgb) . '; margin-bottom:8px; border-radius:2px;"></div>';
        echo '<strong style="display:block; font-size:0.9em; margin-bottom:5px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="'.esc_attr($color_name).'">' . esc_html($color_name) . '</strong>';
        echo '<div style="font-size:1.4em; font-weight:bold; color:#0055bf;">' . number_format($ref_price, 3) . ' €</div>';
        echo '<small style="color:#888;">' . esc_html($source) . '</small>';
        echo '</div>';
    }

    echo '</div>'; // End Grid
    echo '</div>'; // End Card
}

function lww_render_price_optimization_table() {
    if (!isset($_GET['compare_source'])) return;

    $source = sanitize_key($_GET['compare_source']);
    $type = sanitize_key($_GET['deviation_type']);
    $percent = absint($_GET['deviation_percent']);
    $items = lww_get_items_with_price_deviation($source, $type, $percent);
    
    if (empty($items)) {
        echo '<p><em>' . __('Keine Artikel gefunden.', 'lego-wawi') . '</em></p>';
        return;
    }

    echo '<form action="admin-post.php" method="post">';
    echo '<input type="hidden" name="action" value="lww_bulk_update_prices">';
    wp_nonce_field('lww_bulk_update_prices_nonce');
    
    echo '<table class="wp-list-table widefat striped">';
    echo '<thead><tr>';
    echo '<th><input type="checkbox" id="cb-select-all-1"></th>';
    echo '<th>' . __('Artikel', 'lego-wawi') . '</th>';
    echo '<th>' . __('Aktueller Preis', 'lego-wawi') . '</th>';
    echo '<th>' . __('Marktpreis', 'lego-wawi') . '</th>';
    echo '<th>' . __('Abweichung', 'lego-wawi') . '</th>';
    echo '<th>' . __('Neuer Vorschlag', 'lego-wawi') . '</th>';
    echo '</tr></thead><tbody>';

    foreach ($items as $item) {
        $suggested = lww_calculate_target_price($item['id']);
        $suggestion_price = is_wp_error($suggested) ? $item['market_price'] : $suggested['price'];

        echo '<tr>';
        echo '<td><input type="checkbox" name="item_ids[]" value="' . esc_attr($item['id'] . '|' . $suggestion_price) . '"></td>';
        echo '<td><strong><a href="' . get_edit_post_link($item['id']) . '" target="_blank">' . esc_html(get_the_title($item['id'])) . '</a></strong></td>';
        echo '<td>' . number_format($item['price'], 2) . ' €</td>';
        echo '<td>' . number_format($item['market_price'], 2) . ' €</td>';
        echo '<td style="color: ' . ($type == 'expensive' ? 'red' : 'green') . ';">' . number_format($item['deviation'], 1) . '%</td>';
        echo '<td>' . number_format($suggestion_price, 2) . ' €</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    
    echo '<div class="tablenav bottom"><div class="alignleft actions">';
    submit_button(__('Ausgewählte Preise an Marktpreis anpassen', 'lego-wawi'), 'primary', 'submit', false);
    echo '</div></div>';
    echo '</form>';
    
    echo '<script>jQuery("#cb-select-all-1").click(function(){ jQuery("input[name=\'item_ids[]\']").prop("checked", this.checked); });</script>';
}

function lww_get_items_with_price_deviation($source_key, $type, $percent) {
    global $wpdb;
    $items = [];
    list($platform, $metric) = explode('_', $source_key);

    // Hole Items die einen Preis haben und Marktdaten besitzen
    $query = $wpdb->prepare("
        SELECT p.ID, p.post_title, pm_p.meta_value as price, pm_m.meta_value as market_data
        FROM {$wpdb->posts} p
        JOIN {$wpdb->postmeta} pm_p ON p.ID = pm_p.post_id AND pm_p.meta_key = '_price'
        JOIN {$wpdb->postmeta} pm_m ON p.ID = pm_m.post_id AND pm_m.meta_key = '_lww_market_prices'
        WHERE p.post_type = 'lww_inventory_item' AND p.post_status = 'publish'
        LIMIT 2500
    ");
    
    $results = $wpdb->get_results($query);

    foreach ($results as $row) {
        $price = (float)$row->price;
        if ($price <= 0) continue;

        $market_data = maybe_unserialize($row->market_data);
        if (!isset($market_data[$platform])) continue;
        
        $market_price = 0.0;
        if ($metric === 'avg') {
            $market_price = (float)($market_data[$platform]['price'] ?? 0);
        } elseif ($metric === 'min') {
            $market_price = (float)($market_data[$platform]['min_price'] ?? $market_data[$platform]['price'] ?? 0);
        }

        if ($market_price <= 0) continue;

        $diff_percent = (($price - $market_price) / $market_price) * 100;

        if ($type === 'expensive' && $diff_percent > $percent) {
            $items[] = ['id' => $row->ID, 'price' => $price, 'market_price' => $market_price, 'deviation' => $diff_percent];
        } elseif ($type === 'cheap' && $diff_percent < -$percent) {
             $items[] = ['id' => $row->ID, 'price' => $price, 'market_price' => $market_price, 'deviation' => $diff_percent];
        }
    }

    return array_slice($items, 0, 100);
}

function lww_handle_bulk_update_prices() {
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'lww_bulk_update_prices_nonce')) wp_die('Security Check');
    if (!current_user_can('manage_options')) wp_die('Permission Denied');
    
    $ids = $_POST['item_ids'] ?? [];
    $count = 0;
    
    if (!function_exists('lww_update_item_price')) {
        // Fallback falls Funktion nicht geladen (sollte nicht passieren)
        wp_die('Fehler: Pricing-Modul nicht vollständig geladen.');
    }
    
    foreach ($ids as $val) {
        list($item_id, $new_price) = explode('|', $val);
        $item_id = absint($item_id);
        $new_price = floatval($new_price);
        
        if ($item_id && $new_price > 0) {
            if (lww_update_item_price($item_id, $new_price, 'bulk_optimization')) {
                $count++;
            }
        }
    }
    
    add_settings_error('lww_messages', 'prices_updated', sprintf(__('%d Preise wurden aktualisiert.', 'lego-wawi'), $count), 'success');
    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect(wp_get_referer());
    exit;
}
add_action('admin_post_lww_bulk_update_prices', 'lww_handle_bulk_update_prices');

function lww_handle_start_market_price_sync() {
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'lww_start_market_price_sync_nonce')) {
        wp_die(__('Sicherheitsüberprüfung fehlgeschlagen.', 'lego-wawi'));
    }
    if (!current_user_can('manage_options')) {
        wp_die(__('Keine Berechtigung.', 'lego-wawi'));
    }

    $query = new WP_Query([
        'post_type' => 'lww_inventory_item',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'no_found_rows' => true
    ]);
    
    $item_ids = $query->posts;

    if (empty($item_ids)) {
        add_settings_error('lww_messages', 'no_items', __('Keine Inventar-Items gefunden.', 'lego-wawi'), 'warning');
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_safe_redirect(wp_get_referer());
        exit;
    }

    $priority = (int) get_option('lww_job_priority_demand_analysis', 15);

    $job_id = wp_insert_post([
        'post_title'   => sprintf(__('Marktpreis-Abgleich für %d Artikel', 'lego-wawi'), count($item_ids)) . ' - ' . date_i18n('d.m.Y H:i'),
        'post_type'    => 'lww_job',
        'post_status'  => 'lww_pending',
        'post_author'  => get_current_user_id(),
        'menu_order'   => $priority,
    ], true);

    if (is_wp_error($job_id)) {
        add_settings_error('lww_messages', 'job_creation_failed', __('Fehler beim Erstellen des Jobs: ', 'lego-wawi') . $job_id->get_error_message(), 'error');
    } else {
        update_post_meta($job_id, '_job_type', 'market_price_sync');
        update_post_meta($job_id, '_item_ids_to_process', $item_ids);
        update_post_meta($job_id, '_total_items', count($item_ids));
        update_post_meta($job_id, '_processed_items', 0);
        
        lww_log_to_job($job_id, sprintf('Job erstellt. %d Artikel zur Preisprüfung.', count($item_ids)));
        
        lww_start_cron_job();
        add_settings_error('lww_messages', 'job_created', __('Job für Marktpreis-Abgleich wurde gestartet.', 'lego-wawi'), 'success');
    }

    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect(admin_url('admin.php?page=lww_jobs_ui'));
    exit;
}
add_action('admin_post_lww_start_market_price_sync', 'lww_handle_start_market_price_sync');

function lww_get_market_analysis_stats() {
    $stats = get_transient('lww_market_analysis_stats_v2');
    if (false === $stats) {
        global $wpdb;
        $query = "SELECT pm_p.meta_value as own_price, pm_m.meta_value as market_prices FROM {$wpdb->postmeta} pm_m JOIN {$wpdb->postmeta} pm_p ON pm_m.post_id = pm_p.post_id AND pm_p.meta_key = '_price' WHERE pm_m.meta_key = '_lww_market_prices'";
        $results = $wpdb->get_results($query);
        $stats = ['total_analyzed' => 0, 'below_market' => 0, 'above_market' => 0, 'at_market' => 0, 'percentage_analyzed' => 0];
        $total_items = (int) wp_count_posts('lww_inventory_item')->publish;
        foreach ($results as $row) {
            $market_data = maybe_unserialize($row->market_prices);
            $own = (float)$row->own_price;
            $ref_price = (float)($market_data['bricklink']['price'] ?? $market_data['brickowl']['price'] ?? 0);
            if ($ref_price > 0 && $own > 0) {
                $stats['total_analyzed']++;
                if ($own < $ref_price) $stats['below_market']++;
                elseif ($own > $ref_price) $stats['above_market']++;
                else $stats['at_market']++;
            }
        }
        if ($total_items > 0) $stats['percentage_analyzed'] = round(($stats['total_analyzed'] / $total_items) * 100);
        set_transient('lww_market_analysis_stats_v2', $stats, HOUR_IN_SECONDS);
    }
    return $stats;
}
?>