<?php
/**
 * Modul: eBay Import UI
 *
 * Rendert den "eBay-Import"-Tab und startet den Synchronisations-Job.
 */
if (!defined('ABSPATH')) exit;

/**
 * Rendert den Inhalt des "eBay-Import"-Bereichs auf der Import-Seite.
 */
function lww_render_ebay_import_section() {
    $api_settings = get_option('lww_api_settings', []);
    $can_import = !empty($api_settings['ebay_app_id']) && !empty($api_settings['ebay_auth_token']);
    ?>
    <div class="lww-admin-form lww-card lww-mt-20">
        <h2><?php _e('eBay-Inventar synchronisieren', 'lego-wawi'); ?></h2>

        <?php if (!$can_import): ?>
            <div class="notice notice-warning inline lww-notice">
                <p>
                    <span class="dashicons dashicons-warning"></span>
                    <strong><?php _e('Konfiguration erforderlich!', 'lego-wawi'); ?></strong>
                    <?php printf(
                        __('Bitte hinterlege zuerst deine eBay API-Schlüssel in den <a href="%s">Einstellungen</a>, um diese Funktion nutzen zu können.', 'lego-wawi'),
                        esc_url(admin_url('admin.php?page=lww_settings_ui'))
                    );
                    ?>
                </p>
            </div>
        <?php endif; ?>

        <p><?php _e('Dieses Werkzeug liest alle deine aktiven eBay-Angebote ein und erstellt bzw. aktualisiert die entsprechenden Einträge in deinem lokalen Inventar. Der Prozess läuft im Hintergrund.', 'lego-wawi'); ?></p>
        <p><strong><?php _e('Wichtig:', 'lego-wawi'); ?></strong> <?php _e('Damit ein eBay-Artikel korrekt zugeordnet werden kann, muss dessen Artikelnummer (SKU) mit der Set- oder Minifiguren-Nummer aus dem Katalog übereinstimmen.', 'lego-wawi'); ?></p>

        <form action="admin-post.php" method="post">
            <input type="hidden" name="action" value="lww_start_ebay_sync">
            <?php wp_nonce_field('lww_start_ebay_sync_nonce'); ?>
            
            <?php 
            submit_button(
                __('Synchronisation aller aktiven eBay-Angebote starten', 'lego-wawi'),
                'primary large',
                'submit',
                true,
                !$can_import ? ['disabled' => 'disabled'] : null
            );
            ?>
             <?php if (!$can_import): ?>
                <p class="description"><?php _e('Der Button ist deaktiviert, da die eBay API-Schlüssel fehlen.', 'lego-wawi'); ?></p>
             <?php endif; ?>
        </form>
    </div>
    <?php
}

/**
 * Handler für den admin-post Request zum Starten der eBay-Synchronisation.
 * Erstellt einen neuen Job vom Typ 'ebay_sync'.
 */
function lww_handle_start_ebay_sync() {
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'lww_start_ebay_sync_nonce')) {
        wp_die(__('Sicherheitsüberprüfung fehlgeschlagen.', 'lego-wawi'));
    }
    if (!current_user_can('manage_options')) {
        wp_die(__('Keine Berechtigung.', 'lego-wawi'));
    }

    $api_settings = get_option('lww_api_settings', []);
    if (empty($api_settings['ebay_app_id']) || empty($api_settings['ebay_auth_token'])) {
        add_settings_error('lww_messages', 'ebay_api_keys_missing', __('eBay API-Schlüssel fehlen in den Einstellungen.', 'lego-wawi'), 'error');
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_safe_redirect(wp_get_referer());
        exit;
    }

    $ebay_api = new LWW_eBay_API($api_settings);
    $listing_ids = $ebay_api->get_active_listings();

    if (is_wp_error($listing_ids)) {
        add_settings_error('lww_messages', 'ebay_api_error', __('Fehler beim Abrufen der eBay-Angebote: ', 'lego-wawi') . $listing_ids->get_error_message(), 'error');
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_safe_redirect(wp_get_referer());
        exit;
    }

    if (empty($listing_ids)) {
        add_settings_error('lww_messages', 'no_ebay_listings', __('Keine aktiven eBay-Angebote zur Synchronisation gefunden.', 'lego-wawi'), 'info');
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_safe_redirect(wp_get_referer());
        exit;
    }
    
    $priority = (int) get_option('lww_job_priority_ebay_sync', 15);

    // Job erstellen
    $job_id = wp_insert_post([
        'post_title'   => sprintf(__('eBay Inventar-Synchronisation für %d Angebote', 'lego-wawi'), count($listing_ids)) . ' - ' . date_i18n('d.m.Y H:i'),
        'post_type'    => 'lww_job',
        'post_status'  => 'lww_pending',
        'post_author'  => get_current_user_id(),
        'menu_order'   => $priority,
    ], true);

    if (is_wp_error($job_id)) {
        add_settings_error('lww_messages', 'job_creation_failed', __('Fehler beim Erstellen des eBay-Sync-Jobs: ', 'lego-wawi') . $job_id->get_error_message(), 'error');
    } else {
        update_post_meta($job_id, '_job_type', 'ebay_sync');
        update_post_meta($job_id, '_item_ids_to_process', $listing_ids);
        update_post_meta($job_id, '_total_items', count($listing_ids));
        update_post_meta($job_id, '_processed_items', 0);
        lww_log_to_job($job_id, sprintf('Job erstellt. %d eBay-Angebote zur Synchronisation in der Warteschlange.', count($listing_ids)));

        lww_start_cron_job();
        add_settings_error('lww_messages', 'job_created', __('Neuer eBay-Synchronisations-Job wurde erfolgreich erstellt.', 'lego-wawi'), 'success');
    }

    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect(admin_url('admin.php?page=lww_jobs_ui'));
    exit;
}
add_action('admin_post_lww_start_ebay_sync', 'lww_handle_start_ebay_sync');

?>