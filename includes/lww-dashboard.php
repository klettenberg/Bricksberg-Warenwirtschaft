<?php
/**
 * Modul: Dashboard & Statistik (v13.0)
 * Rendert den "Dashboard"-Tab mit Katalog-Metriken und Stammdaten-Status.
 */
if (!defined('ABSPATH')) exit;

/**
 * Holt die Daten für das Dashboard und nutzt Caching zur Performance-Optimierung.
 *
 * @return array Aufbereitete Daten für das Dashboard.
 */
function lww_get_dashboard_data() {
    // Versuche, die Daten aus dem Cache zu holen
    $dashboard_data = get_transient('lww_dashboard_data');

    if (false === $dashboard_data) {
        global $wpdb;
        $dashboard_data = [];

        // 1. Katalog-Einträge zählen
        $count_types = [
            'colors' => 'lww_color',
            'parts' => 'lww_part',
            'sets' => 'lww_set',
            'minifigs' => 'lww_minifig',
            'themes' => 'lww_theme',
            'part_categories' => 'lww_part_category',
            'inventory_locations' => 'lww_inventory_location',
            'inventory_items' => 'lww_inventory_item',
        ];
        foreach ($count_types as $key => $type) {
            $dashboard_data['catalog_counts'][$key] = lww_get_catalog_count($type);
        }

        // 2. Teile mit Beziehungen zählen (KORRIGIERT: Zählt jetzt die Gesamtzahl der Beziehungen)
        $relationship_meta_values = $wpdb->get_col("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_lww_part_relationships'");
        $total_relationships = 0;
        foreach ($relationship_meta_values as $serialized_meta) {
            $relationships = maybe_unserialize($serialized_meta);
            if (is_array($relationships)) {
                foreach ($relationships as $type => $ids) {
                    if (is_array($ids)) {
                        $total_relationships += count($ids);
                    }
                }
            }
        }
        $dashboard_data['catalog_counts']['part_relationships'] = $total_relationships;

        // 3. Inventar-Statistiken holen (diese Funktion hat bereits ihr eigenes Caching)
        $dashboard_data['inventory_stats'] = function_exists('lww_get_inventory_stats') ? lww_get_inventory_stats() : ['total_quantity' => 0, 'total_value' => 0.0];

        // 4. Jobs nach Status zählen (Optimiert: Eine einzige DB-Abfrage)
        $job_counts_result = $wpdb->get_results("\n            SELECT post_status, COUNT( * ) AS num_posts\n            FROM {$wpdb->posts}\n            WHERE post_type = 'lww_job'\n            GROUP BY post_status\n        ", ARRAY_A);

        $job_counts = ['running' => 0, 'pending' => 0, 'failed' => 0, 'complete' => 0];
        foreach($job_counts_result as $row) {
            $status_key = str_replace('lww_', '', $row['post_status']);
            if (array_key_exists($status_key, $job_counts)) {
                $job_counts[$status_key] = (int) $row['num_posts'];
            }
        }
        $dashboard_data['job_counts'] = $job_counts;

        // 5. API-Nutzungsstatistik (Optimiert: 2 einzelne DB-Abfragen statt WP_Query über alle Posts)
        $twenty_four_hours_ago = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $dashboard_data['api_call_count'] = (int) $wpdb->get_var($wpdb->prepare("\n            SELECT COUNT(ID) FROM {$wpdb->posts}\n            WHERE post_type = 'lww_api_log' AND post_date > %s\n        ", $twenty_four_hours_ago));

        $dashboard_data['api_total_cost'] = (float) $wpdb->get_var($wpdb->prepare("\n            SELECT SUM(CAST(pm.meta_value AS DECIMAL(10,5)))\n            FROM {$wpdb->postmeta} pm\n            JOIN {$wpdb->posts} p ON p.ID = pm.post_id\n            WHERE p.post_type = 'lww_api_log'\n            AND p.post_date > %s\n            AND pm.meta_key = '_lww_cost'\n        ", $twenty_four_hours_ago));
        
        // 6. Datenqualität & Plattform-Statistiken (Hier sind die Zählungen schnell genug)
        $dashboard_data['items_without_location_count'] = (new WP_Query(['post_type' => 'lww_inventory_item', 'fields' => 'ids', 'posts_per_page' => -1, 'tax_query' => [['taxonomy' => 'lww_inventory_location', 'operator' => 'NOT EXISTS']]]))->post_count;
        $dashboard_data['items_without_score_count'] = (new WP_Query(['post_type' => 'lww_inventory_item', 'fields' => 'ids', 'posts_per_page' => -1, 'meta_query' => [['key' => '_lww_demand_score', 'compare' => 'NOT EXISTS']]]))->post_count;
        $dashboard_data['wc_product_count'] = (new WP_Query(['post_type' => 'product', 'fields' => 'ids', 'posts_per_page' => -1, 'meta_query' => [['key' => '_lww_part_id', 'compare' => 'EXISTS']]]))->post_count;
        $dashboard_data['ebay_item_count'] = (new WP_Query(['post_type' => 'lww_inventory_item', 'fields' => 'ids', 'posts_per_page' => -1, 'meta_query' => [['key' => '_lww_ebay_listing_id', 'compare' => 'EXISTS']]]))->post_count;

        // Daten für 15 Minuten im Cache speichern
        set_transient('lww_dashboard_data', $dashboard_data, 15 * MINUTE_IN_SECONDS);
    }

    return $dashboard_data;
}


/**
 * Rendert den Inhalt des Dashboard-Tabs.
 */
function lww_render_tab_dashboard() {

    // Hole alle Dashboard-Daten aus der gecachten Funktion
    $data = lww_get_dashboard_data();

    $job_counts = $data['job_counts'];
    $catalog_counts = $data['catalog_counts'];
    $inventory_stats = $data['inventory_stats'];
    $api_call_count = $data['api_call_count'];
    $api_total_cost = $data['api_total_cost'];
    $items_without_location_count = $data['items_without_location_count'];
    $items_without_score_count = $data['items_without_score_count'];
    $wc_product_count = $data['wc_product_count'];
    $ebay_item_count = $data['ebay_item_count'];
    
    ?>
    <div class="wrap lww-wrap">
        <h1><img src="<?php echo esc_url(LWW_PLUGIN_URL . 'assets/img/bricksberg-logo.png'); ?>" alt="Bricksberg Logo" class="lww-header-logo" /> <?php echo esc_html(get_admin_page_title()); ?></h1>
        <p><?php _e('Zentrale Steuerung für deine LEGO Warenwirtschaft.', 'lego-wawi'); ?></p>
        
        <h2 class="lww-mt-20"><?php _e('Job-Status', 'lego-wawi'); ?></h2>
        <div class="lww-dashboard-grid">
            <div class="lww-stat-card jobs-running">
                <h3><span class="dashicons dashicons-update-alt"></span><?php _e('Laufende Jobs', 'lego-wawi'); ?></h3>
                <div class="stat-number"><?php echo esc_html($job_counts['running']); ?></div>
            </div>
            <div class="lww-stat-card jobs-pending">
                <h3><span class="dashicons dashicons-hourglass"></span><?php _e('Wartende Jobs', 'lego-wawi'); ?></h3>
                <div class="stat-number"><?php echo esc_html($job_counts['pending']); ?></div>
            </div>
            <div class="lww-stat-card jobs-complete">
                <h3><span class="dashicons dashicons-yes-alt"></span><?php _e('Abgeschlossene Jobs', 'lego-wawi'); ?></h3>
                <div class="stat-number"><?php echo esc_html($job_counts['complete']); ?></div>
            </div>
            <div class="lww-stat-card jobs-failed">
                <h3><span class="dashicons dashicons-warning"></span><?php _e('Fehlgeschlagene Jobs', 'lego-wawi'); ?></h3>
                <div class="stat-number"><?php echo esc_html($job_counts['failed']); ?></div>
            </div>
        </div>

        <h2 style="margin-top: 40px;"><?php _e('Datenqualität & Aktionen', 'lego-wawi'); ?></h2>
        <div class="lww-dashboard-grid">
            <div class="lww-stat-card <?php echo ($items_without_location_count > 0) ? 'core-data-missing' : 'core-data-ok'; ?>">
                <h3><span class="dashicons dashicons-inbox"></span><?php _e('Artikel ohne Lagerort', 'lego-wawi'); ?></h3>
                <div class="stat-number"><?php echo esc_html(number_format_i18n($items_without_location_count)); ?></div>
                <?php if ($items_without_location_count > 0): ?>
                    <a href="?page=lww_storage_ui" class="button button-secondary lww-mt-20"><?php _e('Jetzt zuweisen', 'lego-wawi'); ?></a>
                <?php else: ?>
                    <p class="description"><?php _e('Alle Artikel sind sortiert.', 'lego-wawi'); ?></p>
                <?php endif; ?>
            </div>
            <div class="lww-stat-card <?php echo ($items_without_score_count > 0) ? 'core-data-missing' : 'core-data-ok'; ?>">
                <h3><span class="dashicons dashicons-chart-bar"></span><?php _e('Artikel ohne Nachfrage-Score', 'lego-wawi'); ?></h3>
                <div class="stat-number"><?php echo esc_html(number_format_i18n($items_without_score_count)); ?></div>
                <?php if ($items_without_score_count > 0): ?>
                    <a href="?page=lww_analysis_ui" class="button button-secondary lww-mt-20"><?php _e('Analyse starten', 'lego-wawi'); ?></a>
                <?php else: ?>
                    <p class="description"><?php _e('Alle Artikel wurden analysiert.', 'lego-wawi'); ?></p>
                <?php endif; ?>
            </div>
            <div class="lww-stat-card">
                <h3><span class="dashicons dashicons-admin-tools"></span><?php _e('Lagerort-Synchronisation', 'lego-wawi'); ?></h3>
                <p class="description"><?php _e('Liest Lagerorte aus den Notizen aller Artikel neu ein.', 'lego-wawi'); ?></p>
                <a href="?page=lww_tools_ui" class="button button-secondary lww-mt-20"><?php _e('Zu den Werkzeugen', 'lego-wawi'); ?></a>
            </div>
        </div>

        <h2 style="margin-top: 40px;"><?php _e('Inventar & Marktplätze', 'lego-wawi'); ?></h2>
        <div class="lww-dashboard-grid">
            <div class="lww-stat-card">
                <h3><span class="dashicons dashicons-archive"></span><?php _e('Inventar-Positionen', 'lego-wawi'); ?></h3>
                <div class="stat-number"><?php echo esc_html(number_format_i18n($catalog_counts['inventory_items'])); ?></div>
                <p class="description"><?php _e('Alle importierten Bestandspositionen.', 'lego-wawi'); ?></p>
            </div>
            <div class="lww-stat-card">
                <h3><span class="dashicons dashicons-forms"></span><?php _e('Gesamtstückzahl Teile', 'lego-wawi'); ?></h3>
                <div class="stat-number"><?php echo esc_html(number_format_i18n($inventory_stats['total_quantity'])); ?></div>
                <p class="description"><?php _e('Summe aller Teile im Inventar.', 'lego-wawi'); ?></p>
            </div>
            <div class="lww-stat-card">
                <h3><span class="dashicons dashicons-money-alt"></span><?php _e('Gesamtwert Inventar', 'lego-wawi'); ?></h3>
                <div class="stat-number"><?php echo esc_html(number_format_i18n($inventory_stats['total_value'], 2)); ?> €</div>
                <p class="description"><?php _e('Berechneter Wert des Lagerbestands.', 'lego-wawi'); ?></p>
            </div>
            <div class="lww-stat-card">
                <h3><span class="dashicons dashicons-wordpress"></span><?php _e('WooCommerce Produkte', 'lego-wawi'); ?></h3>
                <div class="stat-number"><?php echo esc_html(number_format_i18n($wc_product_count)); ?></div>
                <p class="description"><?php _e('Synchronisierte variable Produkte.', 'lego-wawi'); ?></p>
            </div>
            <div class="lww-stat-card">
                <h3><span class="dashicons dashicons-tagcloud"></span><?php _e('eBay Artikel', 'lego-wawi'); ?></h3>
                <div class="stat-number"><?php echo esc_html(number_format_i18n($ebay_item_count)); ?></div>
                <p class="description"><?php _e('Importierte eBay-Bestandspositionen.', 'lego-wawi'); ?></p>
            </div>
            <div class="lww-stat-card">
                <h3><span class="dashicons dashicons-cloud-upload"></span><?php _e('API-Aufrufe (24h)', 'lego-wawi'); ?></h3>
                <div class="stat-number"><?php echo esc_html(number_format_i18n($api_call_count)); ?></div>
            </div>
            <div class="lww-stat-card">
                <h3><span class="dashicons dashicons-money-alt"></span><?php _e('API-Kosten (24h)', 'lego-wawi'); ?></h3>
                <div class="stat-number">$<?php echo esc_html(number_format_i18n($api_total_cost, 5)); ?></div>
            </div>
            <div class="lww-stat-card">
                <h3><span class="dashicons dashicons-info-outline"></span><?php _e('Plugin Version', 'lego-wawi'); ?></h3>
                <div class="stat-number"><?php echo esc_html(LWW_PLUGIN_VERSION); ?></div>
            </div>
        </div>

        <h2 style="margin-top: 40px;"><?php _e('Katalog-Stammdaten', 'lego-wawi'); ?></h2>
        <div class="lww-dashboard-grid">
            <div class="lww-stat-card">
                <h3><span class="dashicons dashicons-admin-generic"></span><?php _e('Teile (Parts)', 'lego-wawi'); ?></h3>
                <div class="stat-number"><?php echo esc_html(number_format_i18n($catalog_counts['parts'])); ?></div>
            </div>
            <div class="lww-stat-card">
                <h3><span class="dashicons dashicons-admin-appearance"></span><?php _e('Farben', 'lego-wawi'); ?></h3>
                <div class="stat-number"><?php echo esc_html(number_format_i18n($catalog_counts['colors'])); ?></div>
            </div>
            <div class="lww-stat-card">
                <h3><span class="dashicons dashicons-store"></span><?php _e('Sets', 'lego-wawi'); ?></h3>
                <div class="stat-number"><?php echo esc_html(number_format_i18n($catalog_counts['sets'])); ?></div>
            </div>
            <div class="lww-stat-card">
                <h3><span class="dashicons dashicons-admin-users"></span><?php _e('Minifiguren', 'lego-wawi'); ?></h3>
                <div class="stat-number"><?php echo esc_html(number_format_i18n($catalog_counts['minifigs'])); ?></div>
            </div>
            <div class="lww-stat-card">
                <h3><span class="dashicons dashicons-tag"></span><?php _e('Teile-Kategorien', 'lego-wawi'); ?></h3>
                <div class="stat-number"><?php echo esc_html(number_format_i18n($catalog_counts['part_categories'])); ?></div>
            </div>
            <div class="lww-stat-card">
                <h3><span class="dashicons dashicons-networking"></span><?php _e('Teile-Beziehungen', 'lego-wawi'); ?></h3>
                <div class="stat-number"><?php echo esc_html(number_format_i18n($catalog_counts['part_relationships'])); ?></div>
            </div>
            <div class="lww-stat-card">
                <h3><span class="dashicons dashicons-flag"></span><?php _e('Themen', 'lego-wawi'); ?></h3>
                <div class="stat-number"><?php echo esc_html(number_format_i18n($catalog_counts['themes'])); ?></div>
            </div>
            <div class="lww-stat-card">
                <h3><span class="dashicons dashicons-inbox"></span><?php _e('Lagerorte', 'lego-wawi'); ?></h3>
                <div class="stat-number"><?php echo esc_html(number_format_i18n($catalog_counts['inventory_locations'])); ?></div>
            </div>
        </div>
    </div>
    <?php
}
?>