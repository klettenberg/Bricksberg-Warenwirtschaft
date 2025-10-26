<?php
/**
 * Modul: Dashboard & Statistik (v9.0)
 * Rendert den "Dashboard"-Tab mit Katalog-Metriken und Stammdaten-Status.
 */
if (!defined('ABSPATH')) exit;

/**
 * Rendert den Inhalt des Dashboard-Tabs.
 */
function lww_render_tab_dashboard() {

    // Zähle die Einträge für jeden relevanten CPT und jede Taxonomie
    // Wir speichern diese Zählungen auch in einer Option, damit andere Teile des Plugins
    // (wie der Stammdaten-Check im Inventar-Import-Tab) darauf zugreifen können.
    $catalog_counts = [];
    $count_types = [
        'colors' => 'lww_color',
        'parts' => 'lww_part',
        'sets' => 'lww_set',
        'minifigs' => 'lww_minifig',
        'themes' => 'lww_theme',
        'part_categories' => 'lww_part_category',
        'inventory_items' => 'lww_inventory_item', // Zählt das interne Inventar
    ];

    foreach ($count_types as $key => $type) {
        $catalog_counts[$key] = lww_get_catalog_count($type);
    }
    update_option('lww_catalog_counts', $catalog_counts); // Zählungen für andere Module speichern

    // Zähle die Jobs nach Status
    $job_counts = [
        'running'   => lww_get_job_counts(['post_status' => 'lww_running']),
        'pending'   => lww_get_job_counts(['post_status' => 'lww_pending']),
        'failed'    => lww_get_job_counts(['post_status' => 'lww_failed']),
        'complete'  => lww_get_job_counts(['post_status' => 'lww_complete']), // Abgeschlossene Jobs zählen
    ];

    // Prüfen, ob die notwendigen Stammdaten für den Inventar-Import vorhanden sind
    $required_data_keys = ['colors', 'parts'];
    $core_data_imported = true;
    foreach($required_data_keys as $key) {
        if ($catalog_counts[$key] === 0) {
            $core_data_imported = false;
            break;
        }
    }

    ?>
    <div class="lww-dashboard-grid">

        <!-- Job Status Kacheln -->
        <div class="lww-stat-card jobs-running">
            <h3><span class="dashicons dashicons-update-alt"></span><?php _e('Laufende Jobs', 'lego-wawi'); ?></h3>
            <div class="stat-number"><?php echo esc_html($job_counts['running']); ?></div>
            <p class="description"><?php _e('Aktive Hintergrund-Prozesse.', 'lego-wawi'); ?></p>
        </div>
        <div class="lww-stat-card jobs-pending">
            <h3><span class="dashicons dashicons-hourglass"></span><?php _e('Wartende Jobs', 'lego-wawi'); ?></h3>
            <div class="stat-number"><?php echo esc_html($job_counts['pending']); ?></div>
             <p class="description"><?php _e('Jobs, die auf Bearbeitung warten.', 'lego-wawi'); ?></p>
        </div>
         <div class="lww-stat-card jobs-complete">
            <h3><span class="dashicons dashicons-yes-alt"></span><?php _e('Abgeschlossene Jobs', 'lego-wawi'); ?></h3>
            <div class="stat-number"><?php echo esc_html($job_counts['complete']); ?></div>
             <p class="description"><?php _e('Erfolgreich beendete Importe.', 'lego-wawi'); ?></p>
        </div>
        <div class="lww-stat-card jobs-failed">
            <h3><span class="dashicons dashicons-warning"></span><?php _e('Fehlgeschlagene Jobs', 'lego-wawi'); ?></h3>
            <div class="stat-number"><?php echo esc_html($job_counts['failed']); ?></div>
             <p class="description"><?php _e('Importe mit Fehlern (Details in Job-Liste).', 'lego-wawi'); ?></p>
        </div>

        <!-- Stammdaten Status Kachel -->
         <div class="lww-stat-card <?php echo $core_data_imported ? 'core-data-ok' : 'core-data-missing'; ?>">
            <h3>
                <span class="dashicons <?php echo $core_data_imported ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
                <?php _e('Stammdaten Status', 'lego-wawi'); ?>
            </h3>
            <?php if ($core_data_imported): ?>
                <p><?php _e('Notwendige Katalogdaten (Farben, Teile) sind importiert.', 'lego-wawi'); ?></p>
                 <a href="?page=<?php echo LWW_PLUGIN_SLUG; ?>&tab=tab_inventory_import" class="button button-secondary">
                     <?php _e('Zum Inventar-Import', 'lego-wawi'); ?>
                 </a>
            <?php else: ?>
                <p><?php _e('Wichtige Katalogdaten fehlen! Bitte importiere zuerst Farben und Teile.', 'lego-wawi'); ?></p>
                <a href="?page=<?php echo LWW_PLUGIN_SLUG; ?>&tab=tab_catalog_import" class="button button-primary">
                     <?php _e('Zum Katalog-Import', 'lego-wawi'); ?>
                 </a>
            <?php endif; ?>
        </div>

        <!-- Katalog Kacheln -->
        <div class="lww-stat-card">
            <h3><span class="dashicons dashicons-admin-appearance"></span><?php _e('Farben', 'lego-wawi'); ?></h3>
            <div class="stat-number"><?php echo esc_html($catalog_counts['colors']); ?></div>
             <p class="description"><?php _e('Importierte Farbdefinitionen.', 'lego-wawi'); ?></p>
        </div>
        <div class="lww-stat-card">
            <h3><span class="dashicons dashicons-admin-generic"></span><?php _e('Teile (Parts)', 'lego-wawi'); ?></h3>
            <div class="stat-number"><?php echo esc_html($catalog_counts['parts']); ?></div>
             <p class="description"><?php _e('Importierte Teile/Formen.', 'lego-wawi'); ?></p>
        </div>
         <div class="lww-stat-card">
            <h3><span class="dashicons dashicons-archive"></span><?php _e('Inventar-Positionen', 'lego-wawi'); ?></h3>
            <div class="stat-number"><?php echo esc_html($catalog_counts['inventory_items']); ?></div>
             <p class="description"><?php _e('Importierte BrickOwl-Bestandspositionen.', 'lego-wawi'); ?></p>
        </div>
        <div class="lww-stat-card">
            <h3><span class="dashicons dashicons-store"></span><?php _e('Sets', 'lego-wawi'); ?></h3>
            <div class="stat-number"><?php echo esc_html($catalog_counts['sets']); ?></div>
             <p class="description"><?php _e('Importierte Set-Definitionen.', 'lego-wawi'); ?></p>
        </div>
        <div class="lww-stat-card">
            <h3><span class="dashicons dashicons-admin-users"></span><?php _e('Minifiguren', 'lego-wawi'); ?></h3>
            <div class="stat-number"><?php echo esc_html($catalog_counts['minifigs']); ?></div>
            <p class="description"><?php _e('Importierte Minifiguren-Definitionen.', 'lego-wawi'); ?></p>
        </div>
        <div class="lww-stat-card">
            <h3><span class="dashicons dashicons-tag"></span><?php _e('Teile-Kategorien', 'lego-wawi'); ?></h3>
            <div class="stat-number"><?php echo esc_html($catalog_counts['part_categories']); ?></div>
            <p class="description"><?php _e('Importierte Kategorien.', 'lego-wawi'); ?></p>
        </div>
        <div class="lww-stat-card">
            <h3><span class="dashicons dashicons-flag"></span><?php _e('Themen', 'lego-wawi'); ?></h3>
            <div class="stat-number"><?php echo esc_html($catalog_counts['themes']); ?></div>
             <p class="description"><?php _e('Importierte Themenwelten.', 'lego-wawi'); ?></p>
        </div>

    </div>
    <?php
}

/**
 * Hilfsfunktion zum Zählen von Katalogeinträgen oder Jobs.
 * Wird auch von lww-admin-page.php (Inventar-Tab-Warnung) genutzt.
 */
if (!function_exists('lww_get_catalog_count')) {
    function lww_get_catalog_count($type) {
        $count = 0;
        // Prüfen, ob es ein CPT oder eine Taxonomie ist
        if (post_type_exists($type)) {
            $data = wp_count_posts($type);
            $count = $data->publish ?? 0; // Zähle nur veröffentlichte Posts
        } elseif (taxonomy_exists($type)) {
            $count = wp_count_terms(['taxonomy' => $type, 'hide_empty' => false]);
        }
        // Gib immer eine Zahl zurück, im Fehlerfall 0
        return is_wp_error($count) ? 0 : intval($count);
    }
}

/**
 * Hilfsfunktion zum Zählen von Jobs nach Status.
 */
if (!function_exists('lww_get_job_counts')) {
    function lww_get_job_counts($args) {
        // Standard-Argumente für die Job-Abfrage
        $defaults = [
            'post_type' => 'lww_job',
            'posts_per_page' => -1, // Alle zählen
            'fields' => 'ids', // Performance: Nur IDs abrufen
        ];
        // Kombiniere Standard-Argumente mit den übergebenen Argumenten (z.B. post_status)
        $query_args = wp_parse_args($args, $defaults);
        $query = new WP_Query($query_args);
        // Gib die Anzahl der gefundenen Posts zurück
        return $query->post_count;
    }
}
?>