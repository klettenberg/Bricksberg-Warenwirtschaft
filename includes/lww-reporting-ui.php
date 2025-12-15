<?php
/**
 * Modul: Erweitertes Reporting (v2.0)
 * Visualisiert Geschäftsdaten mit HTML5 Canvas Charts und bietet Tiefenanalyse.
 * 
 * UPDATE: Erweiterung zum zentralen Statistik-Hub mit Datenqualitäts-Prüfung.
 */
if (!defined('ABSPATH')) exit;

function lww_render_reporting_ui_page() {
    if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
    
    // Daten laden
    $sales_data = lww_get_sales_chart_data();
    $cat_data = lww_get_top_categories_data();
    $platform_data = lww_get_sales_by_platform_data();
    $inventory_stats = lww_get_inventory_stats();
    $anomalies = lww_detect_inventory_anomalies();

    // Prüfe auf Inkonsistenzen zwischen Cache und Live-Daten
    $live_stats = lww_calculate_inventory_stats(true);
    $cache_issues = [];
    if (abs($live_stats['total_quantity'] - $inventory_stats['total_quantity']) > 100) {
        $cache_issues[] = 'Teile-Anzahl weicht stark ab';
    }
    if (abs($live_stats['total_value'] - $inventory_stats['total_value']) > 100) {
        $cache_issues[] = 'Lagerwert weicht stark ab';
    }
    ?>
    <div class="wrap lww-wrap">
        <h1><img src="<?php echo esc_url(LWW_PLUGIN_URL . 'assets/img/bricksberg-logo.png'); ?>" class="lww-header-logo" /> <?php _e('Geschäftsberichte & Statistik', 'lego-wawi'); ?></h1>
        <p><?php _e('Zentrale Auswertung Ihrer Verkäufe, Lagerbestände und Datenqualität.', 'lego-wawi'); ?></p>

        <?php if (!empty($cache_issues)): ?>
        <div class="notice notice-warning is-dismissible">
            <p><strong>Cache-Inkonsistenzen erkannt:</strong> <?php echo implode(', ', $cache_issues); ?>.
            <a href="#lww-cache-section" class="button button-small">Cache leeren</a></p>
        </div>
        <?php endif; ?>

        <!-- KPI Header -->
        <div class="lww-dashboard-grid" style="margin-bottom: 30px;">
            <div class="lww-stat-card brick-blue">
                <h3><span class="dashicons dashicons-money-alt"></span> <?php _e('Lagerwert (Gesamt)', 'lego-wawi'); ?></h3>
                <div class="stat-number"><?php echo number_format_i18n($inventory_stats['total_value'], 2); ?> €</div>
                <small><?php printf(__('%s Einzelteile in %s Lots', 'lego-wawi'), number_format_i18n($inventory_stats['total_quantity']), number_format_i18n($inventory_stats['total_lots'])); ?></small>
            </div>
            <div class="lww-stat-card brick-green">
                <h3><span class="dashicons dashicons-chart-area"></span> <?php _e('Umsatz (Ltz. 30 Tage)', 'lego-wawi'); ?></h3>
                <div class="stat-number"><?php echo number_format_i18n($inventory_stats['sales_30_days'], 2); ?> €</div>
                <small><?php _e('Über alle Kanäle', 'lego-wawi'); ?></small>
            </div>
            <div class="lww-stat-card brick-yellow">
                <h3><span class="dashicons dashicons-tag"></span> <?php _e('Ø Teilepreis', 'lego-wawi'); ?></h3>
                <div class="stat-number">
                    <?php 
                    $avg = $inventory_stats['total_quantity'] > 0 ? $inventory_stats['total_value'] / $inventory_stats['total_quantity'] : 0;
                    echo number_format_i18n($avg, 3);
                    ?> €
                </div>
                <small><?php _e('Durchschnittswert pro Stein', 'lego-wawi'); ?></small>
            </div>
        </div>

        <div class="lww-dashboard-grid">
            <!-- Umsatz Chart -->
            <div class="lww-card" style="grid-column: span 2;">
                <h2><?php _e('Umsatzentwicklung (Letzte 6 Monate)', 'lego-wawi'); ?></h2>
                <div class="lww-chart-container" style="position: relative; height: 300px; width: 100%;">
                    <canvas id="lwwSalesChart"></canvas>
                </div>
            </div>

            <!-- Platform Chart (NEU) -->
            <div class="lww-card">
                <h2><?php _e('Umsatz nach Kanal', 'lego-wawi'); ?></h2>
                <div class="lww-chart-container" style="position: relative; height: 300px; width: 100%;">
                    <canvas id="lwwPlatformChart"></canvas>
                </div>
            </div>
        </div>

        <div class="lww-dashboard-grid">
            <!-- Kategorien Chart -->
            <div class="lww-card">
                <h2><?php _e('Top Kategorien (Menge)', 'lego-wawi'); ?></h2>
                <div class="lww-chart-container" style="position: relative; height: 300px; width: 100%;">
                    <canvas id="lwwCatChart"></canvas>
                </div>
            </div>

            <!-- Datenqualität / Anomalien (NEU) -->
            <div class="lww-card" style="grid-column: span 2; border-left: 4px solid #f59e0b;">
                <h2><span class="dashicons dashicons-warning"></span> <?php _e('Daten-Integrität & Ausreißer', 'lego-wawi'); ?></h2>
                <p><?php _e('Hier finden Sie Artikel, die den Lagerwert verfälschen könnten (z.B. extrem hohe Preise) oder potenzielle Dubletten.', 'lego-wawi'); ?></p>
                
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <!-- High Value Check -->
                    <div>
                        <h3><?php _e('Top 5 Teuerste Lots (Gesamtwert)', 'lego-wawi'); ?></h3>
                        <table class="wp-list-table widefat striped">
                            <thead><tr><th>Artikel</th><th>Menge</th><th>Einzelpreis</th><th>Gesamt</th><th></th></tr></thead>
                            <tbody>
                                <?php foreach($anomalies['high_value'] as $item): ?>
                                <tr>
                                    <td><strong><a href="<?php echo get_edit_post_link($item->ID); ?>" target="_blank"><?php echo esc_html($item->post_title); ?></a></strong></td>
                                    <td><?php echo $item->qty; ?></td>
                                    <td><?php echo number_format_i18n($item->price, 2); ?> €</td>
                                    <td><strong><?php echo number_format_i18n($item->total, 2); ?> €</strong></td>
                                    <td><a href="<?php echo get_edit_post_link($item->ID); ?>" class="button button-small"><span class="dashicons dashicons-edit"></span></a></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($anomalies['high_value'])) echo '<tr><td colspan="5">Keine Auffälligkeiten.</td></tr>'; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Potential Duplicates Check -->
                    <div>
                        <h3><?php _e('Potenzielle Inventar-Dubletten', 'lego-wawi'); ?></h3>
                        <p class="description"><?php _e('Gleiche BOID/Nummer mehrfach im Inventar gefunden.', 'lego-wawi'); ?></p>
                        <table class="wp-list-table widefat striped">
                            <thead><tr><th>Nummer (BOID/Part)</th><th>Anzahl Einträge</th><th>Aktion</th></tr></thead>
                            <tbody>
                                <?php foreach($anomalies['duplicates'] as $dup): ?>
                                <tr>
                                    <td><code><?php echo esc_html($dup->meta_value); ?></code></td>
                                    <td><span class="lww-status-badge" style="background:red;"><?php echo $dup->count; ?>x</span></td>
                                    <td>
                                        <a href="<?php echo admin_url('admin.php?page=lww_inventory_ui&s='.urlencode($dup->meta_value)); ?>" class="button button-small" target="_blank">Prüfen</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($anomalies['duplicates'])) echo '<tr><td colspan="3">Keine offensichtlichen Dubletten.</td></tr>'; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Datenbank-Diagnose Sektion -->
        <div id="lww-cache-section" class="lww-card" style="border-left: 4px solid #f59e0b; margin-top: 30px;">
            <h2><span class="dashicons dashicons-database"></span> <?php _e('Datenbank-Diagnose', 'lego-wawi'); ?></h2>
            <p><?php _e('Überprüfen Sie die Konsistenz Ihrer Inventory-Daten und bereinigen Sie ggf. Inkonsistenzen.', 'lego-wawi'); ?></p>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
                <div>
                    <h3><?php _e('Cache-Status', 'lego-wawi'); ?></h3>
                    <p><?php _e('Die Inventory-Statistiken werden für 1 Stunde gecached. Bei ungewöhnlichen Zahlen kann ein Cache-Reset helfen.', 'lego-wawi'); ?></p>
                    <button type="button" class="button button-primary" id="lww-clear-cache-btn">
                        <span class="dashicons dashicons-update"></span> Cache leeren & neu berechnen
                    </button>
                    <div id="cache-result" style="margin-top: 10px;"></div>
                </div>

                <div>
                    <h3><?php _e('Datenbank-Diagnose', 'lego-wawi'); ?></h3>
                    <p><?php _e('Vollständige Analyse der Datenbank auf Inkonsistenzen.', 'lego-wawi'); ?></p>
                    <button type="button" class="button button-secondary" id="lww-run-diagnosis-btn">
                        <span class="dashicons dashicons-search"></span> Diagnose starten
                    </button>
                    <div id="diagnosis-result" style="margin-top: 10px;"></div>
                </div>
            </div>

            <div id="diagnosis-details" style="margin-top: 20px; display: none;">
                <h3><?php _e('Diagnose-Ergebnisse', 'lego-wawi'); ?></h3>
                <div id="diagnosis-content"></div>
                <div id="cleanup-section" style="margin-top: 15px; display: none;">
                    <button type="button" class="button button-primary" id="lww-run-cleanup-btn">
                        <span class="dashicons dashicons-trash"></span> Bereinigung durchführen
                    </button>
                    <div id="cleanup-result" style="margin-top: 10px;"></div>
                </div>
            </div>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const salesData = <?php echo json_encode($sales_data); ?>;
        const catData = <?php echo json_encode($cat_data); ?>;
        const platformData = <?php echo json_encode($platform_data); ?>;

        if(typeof lwwInitCharts === 'function') {
            lwwInitCharts(salesData, catData, platformData);
        }

        // Cache-Reset Funktion
        document.getElementById('lww-clear-cache-btn').addEventListener('click', function() {
            const btn = this;
            const result = document.getElementById('cache-result');
            btn.disabled = true;
            btn.innerHTML = '<span class="dashicons dashicons-update spin"></span> Cache wird geleert...';

            fetch(ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=lww_clear_inventory_cache&_wpnonce=' + '<?php echo wp_create_nonce("lww_reporting_nonce"); ?>'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    result.innerHTML = '<div class="notice notice-success inline"><p>' +
                        'Cache geleert. Neue Werte: ' +
                        data.data.total_quantity + ' Teile, ' +
                        data.data.total_lots + ' Lots, ' +
                        data.data.total_value.toFixed(2) + ' € Wert' +
                        '</p></div>';
                    // Seite neu laden nach 2 Sekunden
                    setTimeout(() => location.reload(), 2000);
                } else {
                    result.innerHTML = '<div class="notice notice-error inline"><p>Fehler: ' + data.data + '</p></div>';
                }
            })
            .catch(error => {
                result.innerHTML = '<div class="notice notice-error inline"><p>Netzwerkfehler: ' + error.message + '</p></div>';
            })
            .finally(() => {
                btn.disabled = false;
                btn.innerHTML = '<span class="dashicons dashicons-update"></span> Cache leeren & neu berechnen';
            });
        });

        // Diagnose-Funktion
        document.getElementById('lww-run-diagnosis-btn').addEventListener('click', function() {
            const btn = this;
            const result = document.getElementById('diagnosis-result');
            const details = document.getElementById('diagnosis-details');
            const content = document.getElementById('diagnosis-content');
            const cleanup = document.getElementById('cleanup-section');

            btn.disabled = true;
            btn.innerHTML = '<span class="dashicons dashicons-search spin"></span> Diagnose läuft...';

            fetch(ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=lww_run_inventory_diagnosis&_wpnonce=' + '<?php echo wp_create_nonce("lww_reporting_nonce"); ?>'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    result.innerHTML = '<div class="notice notice-info inline"><p>Diagnose abgeschlossen.</p></div>';

                    let html = '<table class="wp-list-table widefat striped"><thead><tr>';
                    html += '<th>Problem</th><th>Details</th></tr></thead><tbody>';

                    if (data.data.issues.length === 0) {
                        html += '<tr><td colspan="2"><div class="notice notice-success inline"><p>Keine Probleme gefunden! Ihre Datenbank ist konsistent.</p></div></td></tr>';
                    } else {
                        data.data.issues.forEach(issue => {
                            html += '<tr><td><span class="dashicons dashicons-warning" style="color: #f59e0b;"></span></td><td>' + issue + '</td></tr>';
                        });
                        html += '<tr><td colspan="2"><strong>Korrigierte Statistiken:</strong><br>';
                        html += 'Teile: ' + data.data.corrected_stats.total_quantity + ', ';
                        html += 'Lots: ' + data.data.corrected_stats.total_lots + ', ';
                        html += 'Wert: ' + data.data.corrected_stats.total_value.toFixed(2) + ' €</td></tr>';
                        cleanup.style.display = 'block';
                    }

                    html += '</tbody></table>';
                    content.innerHTML = html;
                    details.style.display = 'block';
                } else {
                    result.innerHTML = '<div class="notice notice-error inline"><p>Diagnose-Fehler: ' + data.data + '</p></div>';
                }
            })
            .catch(error => {
                result.innerHTML = '<div class="notice notice-error inline"><p>Netzwerkfehler: ' + error.message + '</p></div>';
            })
            .finally(() => {
                btn.disabled = false;
                btn.innerHTML = '<span class="dashicons dashicons-search"></span> Diagnose starten';
            });
        });

        // Cleanup-Funktion
        document.getElementById('lww-run-cleanup-btn').addEventListener('click', function() {
            if (!confirm('Sind Sie sicher? Diese Aktion kann nicht rückgängig gemacht werden.')) return;

            const btn = this;
            const result = document.getElementById('cleanup-result');

            btn.disabled = true;
            btn.innerHTML = '<span class="dashicons dashicons-trash spin"></span> Bereinigung läuft...';

            fetch(ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=lww_run_inventory_cleanup&_wpnonce=' + '<?php echo wp_create_nonce("lww_reporting_nonce"); ?>'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    result.innerHTML = '<div class="notice notice-success inline"><p>' +
                        'Bereinigung erfolgreich:<br>' + data.data.join('<br>') +
                        '<br><strong>Seite wird neu geladen...</strong></p></div>';
                    setTimeout(() => location.reload(), 3000);
                } else {
                    result.innerHTML = '<div class="notice notice-error inline"><p>Bereinigungs-Fehler: ' + data.data + '</p></div>';
                }
            })
            .catch(error => {
                result.innerHTML = '<div class="notice notice-error inline"><p>Netzwerkfehler: ' + error.message + '</p></div>';
            })
            .finally(() => {
                btn.disabled = false;
                btn.innerHTML = '<span class="dashicons dashicons-trash"></span> Bereinigung durchführen';
            });
        });
    });
    </script>
    <?php
}

/**
 * Holt aggregierte Verkaufsdaten der letzten 6 Monate.
 */
function lww_get_sales_chart_data() {
    global $wpdb;
    $data = ['labels' => [], 'values' => []];
    
    for ($i = 5; $i >= 0; $i--) {
        $date = new DateTime("first day of -$i month");
        $month_start = $date->format('Y-m-01 00:00:00');
        $month_end = $date->format('Y-m-t 23:59:59');
        $label = $date->format('M Y');
        
        $sql = $wpdb->prepare(
            "SELECT SUM(pm.meta_value) FROM {$wpdb->posts} p 
             JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
             WHERE p.post_type = 'lww_order' 
             AND p.post_status IN ('lww_completed', 'lww_shipped')
             AND p.post_date >= %s AND p.post_date <= %s
             AND pm.meta_key = '_lww_order_total'",
            $month_start, $month_end
        );
        
        $total = (float) $wpdb->get_var($sql);
        $data['labels'][] = $label;
        $data['values'][] = $total;
    }
    return $data;
}

/**
 * Holt Top 5 Kategorien nach Inventar-Menge.
 */
function lww_get_top_categories_data() {
    $cache_key = 'lww_chart_top_cats_qty';
    $cached = get_transient($cache_key);
    if (false !== $cached) {
        return $cached;
    }

    // Wir nutzen hier Term Counts als Näherungswert für Performance
    $terms = get_terms(['taxonomy' => 'lww_part_category', 'number' => 10, 'orderby' => 'count', 'order' => 'DESC']);
    
    $labels = [];
    $values = [];
    
    if(!is_wp_error($terms)) {
        foreach($terms as $term) {
            $labels[] = $term->name;
            $values[] = $term->count;
        }
    }
    
    $data = ['labels' => $labels, 'values' => $values];
    set_transient($cache_key, $data, HOUR_IN_SECONDS * 12);
    return $data;
}

/**
 * AJAX Handler für Cache-Reset.
 */
function lww_ajax_clear_inventory_cache() {
    check_ajax_referer('lww_reporting_nonce', '_wpnonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Keine Berechtigung');
        return;
    }

    lww_invalidate_inventory_stats_cache();
    $fresh_stats = lww_calculate_inventory_stats(true);

    wp_send_json_success($fresh_stats);
}
add_action('wp_ajax_lww_clear_inventory_cache', 'lww_ajax_clear_inventory_cache');

/**
 * AJAX Handler für Inventory-Diagnose.
 */
function lww_ajax_run_inventory_diagnosis() {
    check_ajax_referer('lww_reporting_nonce', '_wpnonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Keine Berechtigung');
        return;
    }

    $diagnosis = lww_diagnose_inventory_data();
    wp_send_json_success($diagnosis);
}
add_action('wp_ajax_lww_run_inventory_diagnosis', 'lww_ajax_run_inventory_diagnosis');

/**
 * AJAX Handler für Inventory-Bereinigung.
 */
function lww_ajax_run_inventory_cleanup() {
    check_ajax_referer('lww_reporting_nonce', '_wpnonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Keine Berechtigung');
        return;
    }

    $cleanup_results = lww_cleanup_inventory_data();
    wp_send_json_success($cleanup_results);
}
add_action('wp_ajax_lww_run_inventory_cleanup', 'lww_ajax_run_inventory_cleanup');
?>
