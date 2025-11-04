<?php
/**
 * Modul: API Synchronisation UI
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

    ?>
    <div class="wrap lww-wrap">
        <h1><img src="<?php echo esc_url(LWW_PLUGIN_URL . 'assets/img/bricksberg-logo.png'); ?>" alt="Bricksberg Logo" class="lww-header-logo" /> <?php _e('API Synchronisation', 'lego-wawi'); ?></h1>
        <p><?php _e('Steuere hier die regelmäßigen Synchronisationsprozesse mit externen Marktplätzen und APIs.', 'lego-wawi'); ?></p>

        <?php settings_errors('lww_messages'); ?>

        <div class="lww-admin-form lww-card lww-mt-20">
            <h2><?php _e('BrickOwl Inventar Synchronisation', 'lego-wawi'); ?></h2>
            
            <?php 
            $api_settings = get_option('lww_api_settings');
            $can_sync = !empty($api_settings['brickowl_api_key']);
            if (!$can_sync): 
            ?>
                <div class="notice notice-warning inline lww-notice">
                    <p>
                        <span class="dashicons dashicons-warning"></span>
                        <strong><?php _e('Konfiguration erforderlich!', 'lego-wawi'); ?></strong>
                        <?php printf(
                            __('Bitte hinterlege zuerst deinen BrickOwl API-Schlüssel in den <a href="%s">Einstellungen</a>, um diese Funktion nutzen zu können.', 'lego-wawi'),
                            esc_url(admin_url('admin.php?page=lww_settings_ui'))
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <p><?php _e('Dieser Job synchronisiert die Preise deines lokalen Inventars mit den aktuellen Daten von BrickOwl. Der Prozess läuft im Hintergrund und respektiert API-Limits.', 'lego-wawi'); ?></p>
            <p><?php _e('Artikel mit einem hohen Nachfrage-Score werden bevorzugt behandelt. Artikel ohne Score werden nach dem Zufallsprinzip ausgewählt, um eine breite Abdeckung sicherzustellen.', 'lego-wawi'); ?></p>
            
            <form action="admin-post.php" method="post">
                <input type="hidden" name="action" value="lww_start_brickowl_sync">
                <?php wp_nonce_field('lww_start_brickowl_sync_nonce'); ?>
                <?php 
                submit_button(
                    __('BrickOwl Preis-Synchronisation starten', 'lego-wawi'),
                    'primary large',
                    'submit',
                    true,
                    !$can_sync ? ['disabled' => 'disabled'] : null
                );
                ?>
            </form>
        </div>

    </div>
    <?php
}

/**
 * Handler zum Starten des BrickOwl Sync Jobs.
 */
function lww_handle_start_brickowl_sync() {
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'lww_start_brickowl_sync_nonce')) {
        wp_die(__('Sicherheitsüberprüfung fehlgeschlagen.', 'lego-wawi'));
    }
    if (!current_user_can('manage_options')) {
        wp_die(__('Keine Berechtigung.', 'lego-wawi'));
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
        wp_safe_redirect(wp_get_referer());
        exit;
    }

    $priority = (int) get_option('lww_job_priority_brickowl_sync', 20);

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
        update_post_meta($job_id, '_job_type', 'brickowl_sync');
        update_post_meta($job_id, '_item_ids_to_process', $item_ids_to_process);
        update_post_meta($job_id, '_total_items', count($item_ids_to_process));
        update_post_meta($job_id, '_processed_items', 0);
        lww_log_to_job($job_id, sprintf('Job erstellt. %d Artikel zur Synchronisation in der Warteschlange.', count($item_ids_to_process)));

        lww_start_cron_job();
        add_settings_error('lww_messages', 'job_created', __('Neuer BrickOwl Sync-Job wurde erfolgreich erstellt.', 'lego-wawi'), 'success');
    }

    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect(admin_url('admin.php?page=lww_jobs_ui'));
    exit;
}
add_action('admin_post_lww_start_brickowl_sync', 'lww_handle_start_brickowl_sync');
