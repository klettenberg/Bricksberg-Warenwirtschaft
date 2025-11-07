<?php
/**
 * Modul: API Synchronisation UI (v14.0)
 *
 * Stellt die Benutzeroberfläche zur Steuerung der API-Synchronisations-Jobs bereit.
 */
if (!defined('ABSPATH')) exit;

/**
 * Rendert die UI für die Synchronisations-Seite.
 */
function lww_render_sync_ui_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Sie haben keine Berechtigung, auf diese Seite zuzugreifen.', 'lego-wawi'));
    }

    $api_settings = get_option('lww_api_settings');
    $can_sync_bo = !empty($api_settings['brickowl_api_key']);

    ?>
    <div class="wrap lww-wrap">
        <h1><img src="<?php echo esc_url(LWW_PLUGIN_URL . 'assets/img/bricksberg-logo.png'); ?>" alt="Bricksberg Logo" class="lww-header-logo" /> <?php _e('API Synchronisation', 'lego-wawi'); ?></h1>
        <p><?php _e('Steuere hier die regelmäßigen Synchronisationsprozesse mit externen Marktplätzen und APIs.', 'lego-wawi'); ?></p>

        <?php settings_errors('lww_messages'); ?>

        <div class="lww-admin-form lww-card lww-mt-20">
            <h2><?php _e('BrickOwl Synchronisation', 'lego-wawi'); ?></h2>
            
            <?php if (!$can_sync_bo): ?>
                <div class="notice notice-warning inline lww-notice">
                    <p>
                        <span class="dashicons dashicons-warning"></span>
                        <strong><?php _e('Konfiguration erforderlich!', 'lego-wawi'); ?></strong>
                        <?php printf(
                            __('Bitte hinterlege zuerst deinen BrickOwl API-Schlüssel in den <a href="%s">Einstellungen</a>, um diese Funktionen nutzen zu können.', 'lego-wawi'),
                            esc_url(admin_url('admin.php?page=lww_settings_ui'))
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <hr>
            <h3><?php _e('Bestandsabgleich (Pull von BrickOwl)', 'lego-wawi'); ?></h3>
            <p><?php _e('Dieser Job ruft für alle deine lokalen Inventar-Einträge die aktuellen Daten (Preis, Menge, Notizen) von BrickOwl ab und aktualisiert deinen lokalen Bestand. Dies ist nützlich, um sicherzustellen, dass dein WaWi auf dem gleichen Stand wie dein Shop ist.', 'lego-wawi'); ?></p>
            <form action="admin-post.php" method="post">
                <input type="hidden" name="action" value="lww_start_brickowl_inventory_sync">
                <?php wp_nonce_field('lww_start_brickowl_inventory_sync_nonce'); ?>
                <?php 
                submit_button(
                    __('Vollständigen Bestandsabgleich mit BrickOwl starten', 'lego-wawi'),
                    'primary',
                    'submit',
                    true,
                    !$can_sync_bo ? ['disabled' => 'disabled'] : null
                );
                ?>
            </form>

            <hr>
            <h3><?php _e('Nur Preis-Synchronisation', 'lego-wawi'); ?></h3>
            <p><?php _e('Dieser Job synchronisiert nur die Preise deines lokalen Inventars mit den aktuellen Daten von BrickOwl. Der Prozess läuft im Hintergrund und respektiert API-Limits.', 'lego-wawi'); ?></p>
            <p><?php _e('Artikel mit einem hohen Nachfrage-Score werden bevorzugt behandelt.', 'lego-wawi'); ?></p>
            
            <form action="admin-post.php" method="post">
                <input type="hidden" name="action" value="lww_start_brickowl_price_sync">
                <?php wp_nonce_field('lww_start_brickowl_price_sync_nonce'); ?>
                <?php 
                submit_button(
                    __('BrickOwl Preis-Synchronisation starten', 'lego-wawi'),
                    'secondary',
                    'submit',
                    true,
                    !$can_sync_bo ? ['disabled' => 'disabled'] : null
                );
                ?>
            </form>

            <hr>
            <h3><?php _e('Katalog-Anreicherung', 'lego-wawi'); ?></h3>
            <p><?php _e('Dieser Job geht deine Katalog-Stammdaten (Teile, Sets, Minifigs) durch und versucht, fehlende Informationen wie z.B. Gewicht oder Abmessungen über die BrickOwl API zu ergänzen.', 'lego-wawi'); ?></p>
             <form action="admin-post.php" method="post">
                <input type="hidden" name="action" value="lww_start_brickowl_catalog_enrichment">
                <?php wp_nonce_field('lww_start_brickowl_catalog_enrichment_nonce'); ?>
                <?php 
                submit_button(
                    __('Katalog-Anreicherung via BrickOwl starten', 'lego-wawi'),
                    'secondary',
                    'submit',
                    true,
                    !$can_sync_bo ? ['disabled' => 'disabled'] : null
                );
                ?>
            </form>
        </div>

    </div>
    <?php
}

/**
 * Handler zum Starten des BrickOwl Preis Sync Jobs.
 */
function lww_handle_start_brickowl_price_sync() {
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'lww_start_brickowl_price_sync_nonce')) {
        wp_die(__('Sicherheitsüberprüfung fehlgeschlagen.', 'lego-wawi'));
    }
    if (!current_user_can('manage_options')) {
        wp_die(__('Keine Berechtigung.', 'lego-wawi'));
    }

    $api_settings = get_option('lww_api_settings');
    if (empty($api_settings['brickowl_api_key'])) {
        add_settings_error('lww_messages', 'api_key_missing_bo', __('Der Job konnte nicht gestartet werden, da der BrickOwl API-Schlüssel fehlt.', 'lego-wawi'), 'error');
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_safe_redirect(admin_url('admin.php?page=lww_sync_ui'));
        exit;
    }

    // Finde alle Inventar-Items mit einer BOID
    $query = new WP_Query([
        'post_type' => 'lww_inventory_item',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'meta_query' => [
            [
                'key' => '_boid',
                'compare' => 'EXISTS'
            ],
            [
                'key' => '_boid',
                'value' => '',
                'compare' => '!='
            ]
        ]
    ]);

    $item_ids_to_process = $query->posts;

    if (empty($item_ids_to_process)) {
        add_settings_error('lww_messages', 'no_items_for_sync', __('Kein Inventar mit BrickOwl IDs zur Synchronisation gefunden.', 'lego-wawi'), 'info');
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_safe_redirect(admin_url('admin.php?page=lww_sync_ui'));
        exit;
    }

    $priority = (int) get_option('lww_job_priority_brickowl_price_sync', 20);

    // Job erstellen
    $job_id = wp_insert_post([
        'post_title'   => sprintf(__('BrickOwl Preis-Sync für %d Artikel', 'lego-wawi'), count($item_ids_to_process)) . ' - ' . date_i18n('d.m.Y H:i'),
        'post_type'    => 'lww_job',
        'post_status'  => 'lww_pending',
        'post_author'  => get_current_user_id(),
        'menu_order'   => $priority, 
    ], true);

    if (is_wp_error($job_id)) {
        add_settings_error('lww_messages', 'job_creation_failed', __('Fehler beim Erstellen des Sync-Jobs: ', 'lego-wawi') . $job_id->get_error_message(), 'error');
    } else {
        update_post_meta($job_id, '_job_type', 'brickowl_price_sync');
        update_post_meta($job_id, '_item_ids_to_process', $item_ids_to_process);
        update_post_meta($job_id, '_total_items', count($item_ids_to_process));
        update_post_meta($job_id, '_processed_items', 0);
        lww_log_to_job($job_id, sprintf('Job erstellt. %d Artikel zur Preissynchronisation in der Warteschlange.', count($item_ids_to_process)));

        lww_start_cron_job();
        add_settings_error('lww_messages', 'job_created', __('Neuer BrickOwl Preis-Sync-Job wurde erfolgreich erstellt.', 'lego-wawi'), 'success');
    }

    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect(admin_url('admin.php?page=lww_jobs_ui'));
    exit;
}
add_action('admin_post_lww_start_brickowl_price_sync', 'lww_handle_start_brickowl_price_sync');

/**
 * Handler zum Starten des BrickOwl Inventory Sync Jobs.
 */
function lww_handle_start_brickowl_inventory_sync() {
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'lww_start_brickowl_inventory_sync_nonce')) {
        wp_die(__('Sicherheitsüberprüfung fehlgeschlagen.', 'lego-wawi'));
    }
    if (!current_user_can('manage_options')) {
        wp_die(__('Keine Berechtigung.', 'lego-wawi'));
    }

    $api_settings = get_option('lww_api_settings');
    if (empty($api_settings['brickowl_api_key'])) {
        add_settings_error('lww_messages', 'api_key_missing_bo', __('Der Job konnte nicht gestartet werden, da der BrickOwl API-Schlüssel fehlt.', 'lego-wawi'), 'error');
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_safe_redirect(admin_url('admin.php?page=lww_sync_ui'));
        exit;
    }

    $query = new WP_Query([
        'post_type' => 'lww_inventory_item',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'meta_query' => [['key' => '_boid', 'compare' => 'EXISTS'], ['key' => '_boid', 'value' => '', 'compare' => '!=']]
    ]);
    $item_ids_to_process = $query->posts;

    if (empty($item_ids_to_process)) {
        add_settings_error('lww_messages', 'no_items_for_sync', __('Kein Inventar mit BrickOwl IDs für den Bestandsabgleich gefunden.', 'lego-wawi'), 'info');
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_safe_redirect(admin_url('admin.php?page=lww_sync_ui'));
        exit;
    }

    $priority = (int) get_option('lww_job_priority_brickowl_inventory_sync', 15);

    $job_id = wp_insert_post([
        'post_title'   => sprintf(__('BrickOwl Bestandsabgleich für %d Artikel', 'lego-wawi'), count($item_ids_to_process)) . ' - ' . date_i18n('d.m.Y H:i'),
        'post_type'    => 'lww_job',
        'post_status'  => 'lww_pending',
        'post_author'  => get_current_user_id(),
        'menu_order'   => $priority,
    ], true);

    if (is_wp_error($job_id)) {
        add_settings_error('lww_messages', 'job_creation_failed', __('Fehler beim Erstellen des Sync-Jobs: ', 'lego-wawi') . $job_id->get_error_message(), 'error');
    } else {
        update_post_meta($job_id, '_job_type', 'brickowl_inventory_sync');
        update_post_meta($job_id, '_item_ids_to_process', $item_ids_to_process);
        update_post_meta($job_id, '_total_items', count($item_ids_to_process));
        update_post_meta($job_id, '_processed_items', 0);
        lww_log_to_job($job_id, sprintf('Job erstellt. %d Artikel zum Bestandsabgleich in der Warteschlange.', count($item_ids_to_process)));
        lww_start_cron_job();
        add_settings_error('lww_messages', 'job_created', __('Neuer BrickOwl Bestandsabgleich-Job wurde erfolgreich erstellt.', 'lego-wawi'), 'success');
    }

    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect(admin_url('admin.php?page=lww_jobs_ui'));
    exit;
}
add_action('admin_post_lww_start_brickowl_inventory_sync', 'lww_handle_start_brickowl_inventory_sync');

/**
 * Handler zum Starten des BrickOwl Catalog Enrichment Jobs.
 */
function lww_handle_start_brickowl_catalog_enrichment() {
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'lww_start_brickowl_catalog_enrichment_nonce')) {
        wp_die(__('Sicherheitsüberprüfung fehlgeschlagen.', 'lego-wawi'));
    }
    if (!current_user_can('manage_options')) {
        wp_die(__('Keine Berechtigung.', 'lego-wawi'));
    }

    $api_settings = get_option('lww_api_settings');
    if (empty($api_settings['brickowl_api_key'])) {
        add_settings_error('lww_messages', 'api_key_missing_bo', __('Der Job konnte nicht gestartet werden, da der BrickOwl API-Schlüssel fehlt.', 'lego-wawi'), 'error');
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_safe_redirect(admin_url('admin.php?page=lww_sync_ui'));
        exit;
    }

    $post_types = ['lww_part', 'lww_set', 'lww_minifig'];
    $query = new WP_Query([
        'post_type' => $post_types,
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
    ]);
    $item_ids_to_process = $query->posts;

    if (empty($item_ids_to_process)) {
        add_settings_error('lww_messages', 'no_items_for_enrichment', __('Keine Katalogeinträge zur Anreicherung gefunden.', 'lego-wawi'), 'info');
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_safe_redirect(admin_url('admin.php?page=lww_sync_ui'));
        exit;
    }

    $priority = (int) get_option('lww_job_priority_brickowl_catalog_enrichment', 25);

    $job_id = wp_insert_post([
        'post_title'   => sprintf(__('BrickOwl Katalog-Anreicherung für %d Einträge', 'lego-wawi'), count($item_ids_to_process)) . ' - ' . date_i18n('d.m.Y H:i'),
        'post_type'    => 'lww_job',
        'post_status'  => 'lww_pending',
        'post_author'  => get_current_user_id(),
        'menu_order'   => $priority,
    ], true);

    if (is_wp_error($job_id)) {
        add_settings_error('lww_messages', 'job_creation_failed', __('Fehler beim Erstellen des Anreicherungs-Jobs: ', 'lego-wawi') . $job_id->get_error_message(), 'error');
    } else {
        update_post_meta($job_id, '_job_type', 'brickowl_catalog_enrichment');
        update_post_meta($job_id, '_item_ids_to_process', $item_ids_to_process);
        update_post_meta($job_id, '_total_items', count($item_ids_to_process));
        update_post_meta($job_id, '_processed_items', 0);
        lww_log_to_job($job_id, sprintf('Job erstellt. %d Katalogeinträge zur Anreicherung in der Warteschlange.', count($item_ids_to_process)));
        lww_start_cron_job();
        add_settings_error('lww_messages', 'job_created', __('Neuer Job zur Katalog-Anreicherung wurde erfolgreich erstellt.', 'lego-wawi'), 'success');
    }

    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect(admin_url('admin.php?page=lww_jobs_ui'));
    exit;
}
add_action('admin_post_lww_start_brickowl_catalog_enrichment', 'lww_handle_start_brickowl_catalog_enrichment');
