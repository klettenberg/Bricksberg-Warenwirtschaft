<?php
/**
 * Modul: Werkzeuge & Hilfsprogramme (v13.0)
 *
 * Stellt einmalige Admin-Werkzeuge zur Verfügung, z.B. zur
 * Datenbereinigung oder für manuelle Aktionen.
 */
if (!defined('ABSPATH')) exit;

/**
 * Rendert den Inhalt des "Werkzeuge"-Tabs.
 */
function lww_render_tools_ui_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Sie haben keine Berechtigung, auf diese Seite zuzugreifen.', 'lego-wawi'));
    }

    $cleanup_file_count = lww_get_cleanup_file_count();
    ?>
    <div class="wrap lww-wrap">
        <h1><img src="<?php echo esc_url(LWW_PLUGIN_URL . 'assets/img/bricksberg-logo.png'); ?>" alt="Bricksberg Logo" class="lww-header-logo" /> <?php _e('Werkzeuge & Wartung', 'lego-wawi'); ?></h1>
        <p><?php _e('Diese Werkzeuge führen einmalige Aktionen für deinen gesamten Datenbestand aus oder helfen bei der Problembehebung.', 'lego-wawi'); ?></p>

        <?php settings_errors('lww_messages'); ?>

        <div class="lww-admin-form lww-card lww-mt-20">
            <h2><?php _e('Daten-Werkzeuge', 'lego-wawi'); ?></h2>
            <p><?php _e('Die Aktionen laufen im Hintergrund und können je nach Datenmenge einige Zeit in Anspruch nehmen.', 'lego-wawi'); ?></p>
            
            <hr>

            <h3><?php _e('Lagerorte synchronisieren', 'lego-wawi'); ?></h3>
            <p><?php _e('Dieses Werkzeug liest das Feld "private Notizen" (`remarks`) aller importierten Inventarartikel aus und weist die gefundenen Lagerorte (durch Komma oder / getrennt) der Lagerort-Taxonomie zu. Bestehende Lagerorte für einen Artikel werden dabei überschrieben.', 'lego-wawi'); ?></p>
            
            <form action="admin-post.php" method="post">
                <input type="hidden" name="action" value="lww_sync_locations_from_notes">
                <?php wp_nonce_field('lww_sync_locations_from_notes_nonce'); ?>
                <?php submit_button(__('Lagerorte aus Notizen für alle Artikel synchronisieren', 'lego-wawi'), 'primary', 'submit', true); ?>
            </form>
        </div>

        <div class="lww-admin-form lww-card lww-mt-20">
            <h2><?php _e('System-Wartung', 'lego-wawi'); ?></h2>
            <hr>
            <h3><?php _e('Job-System bereinigen', 'lego-wawi'); ?></h3>
            <p><?php _e('Dieses Werkzeug setzt alle Jobs, die im Status "Laufend" feststecken, auf "Fehlgeschlagen" zurück. Dies kann nützlich sein, wenn ein Cron-Prozess unerwartet abgebrochen ist und die globale Job-Sperre blockiert.', 'lego-wawi'); ?></p>
            
            <form action="admin-post.php" method="post">
                <input type="hidden" name="action" value="lww_reset_stuck_jobs">
                <?php wp_nonce_field('lww_reset_stuck_jobs_nonce'); ?>
                <?php submit_button(__('Alle als "Laufend" markierten Jobs zurücksetzen', 'lego-wawi'), 'secondary', 'submit', true); ?>
            </form>

            <hr>

            <h3><?php _e('Temporäre Import-Dateien löschen', 'lego-wawi'); ?></h3>
            <p><?php printf(
                _n(
                    'Es wurde %d temporäre Import-Datei im Uploads-Verzeichnis gefunden. Diese Dateien bleiben nach fehlgeschlagenen oder sehr alten Jobs manchmal übrig und können gelöscht werden.',
                    'Es wurden %d temporäre Import-Dateien im Uploads-Verzeichnis gefunden. Diese Dateien bleiben nach fehlgeschlagenen oder sehr alten Jobs manchmal übrig und können gelöscht werden.',
                    $cleanup_file_count,
                    'lego-wawi'
                ),
                $cleanup_file_count
            ); ?></p>
            
            <form action="admin-post.php" method="post">
                <input type="hidden" name="action" value="lww_cleanup_import_files">
                <?php wp_nonce_field('lww_cleanup_import_files_nonce'); ?>
                <?php submit_button(__('Alle temporären Import-Dateien löschen', 'lego-wawi'), 'secondary', 'submit', true, ($cleanup_file_count === 0) ? ['disabled' => 'disabled'] : []); ?>
            </form>
        </div>

        <div class="lww-card lww-danger-zone lww-mt-20">
            <h2><?php _e('Gefahrenzone', 'lego-wawi'); ?></h2>

            <h3><?php _e('Gesamten Datenbestand zurücksetzen', 'lego-wawi'); ?></h3>
            <p><strong><?php _e('WARNUNG:', 'lego-wawi'); ?></strong> <?php _e('Diese Aktion ist nicht umkehrbar! Sie löscht ALLE vom Bricksberg WaWi Plugin erstellten Daten, einschließlich:', 'lego-wawi'); ?></p>
            <ul>
                <li><?php _e('Alle Katalog-Daten (Teile, Sets, Minifiguren, Farben, etc.)', 'lego-wawi'); ?></li>
                <li><?php _e('Alle importierten Inventar-Daten', 'lego-wawi'); ?></li>
                <li><?php _e('Alle Job-Einträge und API-Logs', 'lego-wawi'); ?></li>
                <li><?php _e('Alle Lagerorte, Themen und Teile-Kategorien', 'lego-wawi'); ?></li>
            </ul>
            <p><?php _e('WooCommerce-Produkte, die über das Plugin erstellt wurden, bleiben bestehen, verlieren aber ihre Verknüpfung. Diese Aktion sollte nur ausgeführt werden, wenn du komplett neu anfangen möchtest.', 'lego-wawi'); ?></p>

            <form action="admin-post.php" method="post" onsubmit="return confirm('<?php echo esc_js(__('Bist du absolut sicher, dass du alle Katalog- und Inventardaten unwiderruflich löschen möchtest? Diese Aktion kann nicht rückgängig gemacht werden.', 'lego-wawi')); ?>');">
                <input type="hidden" name="action" value="lww_purge_all_data">
                <?php wp_nonce_field('lww_purge_all_data_nonce'); ?>
                <?php submit_button(__('Alle Katalog- und Inventardaten unwiderruflich löschen', 'lego-wawi'), 'delete', 'submit', true); ?>
            </form>
        </div>
    </div>
    <?php
}

/**
 * Handler für den admin-post Request zum Starten der Lagerort-Synchronisation.
 * Erstellt einen neuen Job vom Typ 'location_sync'.
 */
function lww_handle_sync_locations_from_notes() {
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'lww_sync_locations_from_notes_nonce')) {
        wp_die(__('Sicherheitsüberprüfung fehlgeschlagen.', 'lego-wawi'));
    }
    if (!current_user_can('manage_options')) {
        wp_die(__('Keine Berechtigung.', 'lego-wawi'));
    }

    // Finde alle Inventar-Items, die ein 'remarks'-Feld haben.
    $query = new WP_Query([
        'post_type' => 'lww_inventory_item',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'meta_query' => [
            [
                'key' => '_remarks',
                'compare' => 'EXISTS'
            ],
             [
                'key' => '_remarks',
                'value' => '',
                'compare' => '!='
            ]
        ]
    ]);

    $item_ids_to_process = $query->posts;

    if (empty($item_ids_to_process)) {
        add_settings_error('lww_messages', 'no_items_for_location_sync', __('Keine Inventarartikel mit Notizen zur Synchronisation gefunden.', 'lego-wawi'), 'info');
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_safe_redirect(admin_url('admin.php?page=lww_tools_ui'));
        exit;
    }

    $priority = (int) get_option('lww_job_priority_location_sync', 10);

    // Job erstellen
    $job_id = wp_insert_post([
        'post_title'   => sprintf(__('Lagerort-Synchronisation für %d Artikel', 'lego-wawi'), count($item_ids_to_process)) . ' - ' . date_i18n('d.m.Y H:i'),
        'post_type'    => 'lww_job',
        'post_status'  => 'lww_pending',
        'post_author'  => get_current_user_id(),
        'menu_order'   => $priority,
    ], true);

    if (is_wp_error($job_id)) {
        add_settings_error('lww_messages', 'job_creation_failed', __('Fehler beim Erstellen des Synchronisations-Jobs: ', 'lego-wawi') . $job_id->get_error_message(), 'error');
    } else {
        update_post_meta($job_id, '_job_type', 'location_sync');
        update_post_meta($job_id, '_item_ids_to_process', $item_ids_to_process);
        update_post_meta($job_id, '_total_items', count($item_ids_to_process));
        update_post_meta($job_id, '_processed_items', 0);
        lww_log_to_job($job_id, sprintf('Job erstellt. %d Artikel zur Synchronisation in der Warteschlange.', count($item_ids_to_process)));

        lww_start_cron_job();
        add_settings_error('lww_messages', 'job_created', __('Neuer Synchronisations-Job wurde erfolgreich erstellt und zur Warteschlange hinzugefügt.', 'lego-wawi'), 'success');
    }

    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect(admin_url('admin.php?page=lww_jobs_ui'));
    exit;
}
add_action('admin_post_lww_sync_locations_from_notes', 'lww_handle_sync_locations_from_notes');


/**
 * Handler, um feststeckende Jobs zurückzusetzen.
 */
function lww_handle_reset_stuck_jobs() {
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'lww_reset_stuck_jobs_nonce')) {
        wp_die(__('Sicherheitsüberprüfung fehlgeschlagen.', 'lego-wawi'));
    }
    if (!current_user_can('manage_options')) {
        wp_die(__('Keine Berechtigung.', 'lego-wawi'));
    }

    $stuck_jobs_query = new WP_Query([
        'post_type' => 'lww_job',
        'post_status' => 'lww_running',
        'posts_per_page' => -1,
        'fields' => 'ids',
    ]);

    $reset_count = 0;
    if ($stuck_jobs_query->have_posts()) {
        foreach ($stuck_jobs_query->posts as $job_id) {
            wp_update_post(['ID' => $job_id, 'post_status' => 'lww_failed']);
            lww_log_to_job($job_id, __('Job manuell durch Admin-Werkzeug als fehlgeschlagen markiert.', 'lego-wawi'));
            $reset_count++;
        }
    }

    // Globale Job-Sperre aufheben
    delete_option('lww_current_running_job_id');
    lww_log_system_event('Globale Job-Sperre durch Admin-Werkzeug aufgehoben.');

    if ($reset_count > 0) {
        add_settings_error('lww_messages', 'jobs_reset', sprintf(_n('%d Job wurde zurückgesetzt.', '%d Jobs wurden zurückgesetzt.', $reset_count, 'lego-wawi'), $reset_count), 'success');
    } else {
        add_settings_error('lww_messages', 'no_jobs_to_reset', __('Keine feststeckenden Jobs gefunden.', 'lego-wawi'), 'info');
    }

    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect(admin_url('admin.php?page=lww_tools_ui'));
    exit;
}
add_action('admin_post_lww_reset_stuck_jobs', 'lww_handle_reset_stuck_jobs');

/**
 * Handler, um temporäre Import-Dateien zu löschen.
 */
function lww_handle_cleanup_import_files() {
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'lww_cleanup_import_files_nonce')) {
        wp_die(__('Sicherheitsüberprüfung fehlgeschlagen.', 'lego-wawi'));
    }
    if (!current_user_can('manage_options')) {
        wp_die(__('Keine Berechtigung.', 'lego-wawi'));
    }

    $upload_dir = wp_upload_dir();
    $files = glob($upload_dir['basedir'] . '/lww_import_*.csv');
    $deleted_count = 0;

    if ($files) {
        foreach ($files as $file) {
            if (unlink($file)) {
                $deleted_count++;
            }
        }
    }

    if ($deleted_count > 0) {
        add_settings_error('lww_messages', 'files_cleaned', sprintf(_n('%d temporäre Datei wurde gelöscht.', '%d temporäre Dateien wurden gelöscht.', $deleted_count, 'lego-wawi'), $deleted_count), 'success');
    } else {
        add_settings_error('lww_messages', 'no_files_to_clean', __('Keine temporären Import-Dateien zum Löschen gefunden.', 'lego-wawi'), 'info');
    }

    // Lösche den Cache, damit die Zählung aktualisiert wird.
    delete_transient('lww_cleanup_file_count');

    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect(admin_url('admin.php?page=lww_tools_ui'));
    exit;
}
add_action('admin_post_lww_cleanup_import_files', 'lww_handle_cleanup_import_files');

/**
 * Handler, um den Job zur kompletten Datenbereinigung zu starten.
 */
function lww_handle_purge_all_data() {
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'lww_purge_all_data_nonce')) {
        wp_die(__('Sicherheitsüberprüfung fehlgeschlagen.', 'lego-wawi'));
    }
    if (!current_user_can('manage_options')) {
        wp_die(__('Keine Berechtigung.', 'lego-wawi'));
    }

    $priority = (int) get_option('lww_job_priority_data_purge', 0);

    $job_id = wp_insert_post([
        'post_title'   => __('Vollständige Datenbereinigung', 'lego-wawi') . ' - ' . date_i18n('d.m.Y H:i'),
        'post_type'    => 'lww_job',
        'post_status'  => 'lww_pending',
        'post_author'  => get_current_user_id(),
        'menu_order'   => $priority, // Höchste Priorität
    ], true);

    if (is_wp_error($job_id)) {
        add_settings_error('lww_messages', 'purge_job_failed', __('Fehler beim Erstellen des Bereinigungs-Jobs: ', 'lego-wawi') . $job_id->get_error_message(), 'error');
    } else {
        update_post_meta($job_id, '_job_type', 'data_purge');
        update_post_meta($job_id, '_purge_step', 0);
        lww_log_to_job($job_id, 'Job zur vollständigen Datenbereinigung erstellt und priorisiert.');
        lww_start_cron_job();
        add_settings_error('lww_messages', 'purge_job_created', __('Der Job zur vollständigen Datenbereinigung wurde gestartet. Der Fortschritt kann in der Job-Warteschlange verfolgt werden.', 'lego-wawi'), 'success');
    }

    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect(admin_url('admin.php?page=lww_jobs_ui'));
    exit;
}
add_action('admin_post_lww_purge_all_data', 'lww_handle_purge_all_data');

/**
 * Zählt die Anzahl der temporären Import-Dateien im Upload-Verzeichnis.
 * Nutzt einen Cache, um wiederholte Dateisystem-Zugriffe zu vermeiden.
 *
 * @return int Anzahl der gefundenen Dateien.
 */
function lww_get_cleanup_file_count() {
    $count = get_transient('lww_cleanup_file_count');

    if (false === $count) {
        $upload_dir = wp_upload_dir();
        $files = glob($upload_dir['basedir'] . '/lww_import_*.csv');
        $count = $files ? count($files) : 0;
        // Ergebnis für 5 Minuten zwischenspeichern
        set_transient('lww_cleanup_file_count', $count, 5 * MINUTE_IN_SECONDS);
    }

    return (int) $count;
}

/**
 * Parst einen String mit Lagerorten und weist sie einem Post zu.
 *
 * @param int $post_id Die ID des lww_inventory_item Posts.
 * @param string $notes Der String aus dem 'remarks'-Feld, der die Lagerorte enthält.
 */
function lww_update_locations_from_string($post_id, $notes) {
    if (empty($notes) || !is_string($notes)) {
        // Wenn keine Notizen vorhanden sind, alle bestehenden Lagerorte entfernen
        wp_set_object_terms($post_id, [], 'lww_inventory_location', false);
        return;
    }

    // Ersetze verschiedene Trennzeichen (Schrägstrich, Semikolon) durch Kommas
    $notes_normalized = str_replace(['/', ';'], ',', $notes);

    // Zerlege den String anhand von Kommas
    $locations = explode(',', $notes_normalized);

    // Bereinige jeden Lagerortnamen (Leerzeichen entfernen)
    $locations = array_map('trim', $locations);

    // Entferne leere Einträge, die durch doppelte Kommas oder Trennzeichen am Ende entstehen könnten
    $locations = array_filter($locations, 'strlen');

    if (empty($locations)) {
        wp_set_object_terms($post_id, [], 'lww_inventory_location', false);
        return;
    }

    $term_ids = [];
    foreach ($locations as $location_name) {
        // Term suchen oder erstellen
        $term = get_term_by('name', $location_name, 'lww_inventory_location');
        if (!$term) {
            $term_result = wp_insert_term($location_name, 'lww_inventory_location');
            if (!is_wp_error($term_result)) {
                $term_ids[] = (int)$term_result['term_id'];
            }
        } else {
            $term_ids[] = (int)$term->term_id;
        }
    }

    // Weise alle gefundenen/erstellten Term-IDs dem Post zu.
    // Das 'false' am Ende sorgt dafür, dass alle alten Begriffe ersetzt werden.
    wp_set_object_terms($post_id, $term_ids, 'lww_inventory_location', false);
}
?>