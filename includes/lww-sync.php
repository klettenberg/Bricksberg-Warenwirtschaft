<?php
/**
 * Sync UI (v19.0)
 * Mit erweiterten Filteroptionen für Teilmengen (Status, Datum).
 */
if (!defined('ABSPATH')) exit;

function lww_render_sync_ui_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Sie haben keine Berechtigung, auf diese Seite zuzugreifen.', 'lego-wawi'));
    }
    ?>
    <div class="wrap lww-wrap">
        <h1><?php _e('Synchronisation', 'lego-wawi'); ?></h1>
        
        <div class="lww-card lww-mt-20">
            <h2><?php _e('Bestellungen (BrickLink)', 'lego-wawi'); ?></h2>
            <p><?php _e('Importieren Sie Bestellungen von BrickLink.', 'lego-wawi'); ?></p>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                <input type="hidden" name="action" value="lww_start_bricklink_order_sync">
                <?php wp_nonce_field('lww_sync_orders_nonce'); ?>
                
                <div style="margin-bottom: 15px;">
                    <label><strong><?php _e('Status-Filter (Teilmenge):', 'lego-wawi'); ?></strong></label><br>
                    <label><input type="checkbox" name="status[]" value="PENDING" checked> Pending</label>
                    <label><input type="checkbox" name="status[]" value="UPDATED" checked> Updated</label>
                    <label><input type="checkbox" name="status[]" value="PAID"> Paid</label>
                    <label><input type="checkbox" name="status[]" value="PACKED"> Packed</label>
                    <label><input type="checkbox" name="status[]" value="SHIPPED"> Shipped</label>
                    <label><input type="checkbox" name="status[]" value="COMPLETED"> Completed</label>
                    <label><input type="checkbox" name="status[]" value="CANCELLED"> Cancelled</label>
                </div>
                
                <?php submit_button(__('BrickLink Bestellungen abrufen', 'lego-wawi'), 'primary'); ?>
            </form>
        </div>

        <!-- BrickOwl Order Sync -->
        <div class="lww-card lww-mt-20">
            <h2><?php _e('Bestellungen (BrickOwl)', 'lego-wawi'); ?></h2>
            <p><?php _e('Importieren Sie Bestellungen von BrickOwl.', 'lego-wawi'); ?></p>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                <input type="hidden" name="action" value="lww_start_brickowl_order_sync">
                <?php wp_nonce_field('lww_brickowl_orders_nonce'); ?>

                <div style="margin-bottom: 15px;">
                    <label for="bo_days"><strong><?php _e('Zeitraum:', 'lego-wawi'); ?></strong></label>
                    <input type="number" name="days_back" id="bo_days" value="7" min="1" max="365" class="small-text"> <?php _e('Tage rückwirkend prüfen.', 'lego-wawi'); ?>
                </div>

                <div style="margin-bottom: 15px;">
                    <label><strong><?php _e('Status-Filter (Lokal):', 'lego-wawi'); ?></strong></label><br>
                    <label><input type="checkbox" name="status[]" value="Pending" checked> Pending</label>
                    <label><input type="checkbox" name="status[]" value="Payment Received" checked> Payment Received</label>
                    <label><input type="checkbox" name="status[]" value="Processing"> Processing</label>
                    <label><input type="checkbox" name="status[]" value="Shipped"> Shipped</label>
                    <label><input type="checkbox" name="status[]" value="Completed"> Completed</label>
                </div>

                <?php submit_button(__('BrickOwl Bestellungen abrufen', 'lego-wawi'), 'primary'); ?>
            </form>
        </div>
    </div>
    <?php
}

/**
 * Handler für den Start des BrickLink Bestell-Syncs.
 */
function lww_handle_start_bricklink_order_sync() {
    if (!check_admin_referer('lww_sync_orders_nonce')) wp_die('Security Check');

    $priority = (int) get_option('lww_job_priority_bricklink_order_sync', 15);
    $status_array = isset($_POST['status']) ? array_map('sanitize_text_field', $_POST['status']) : ['PENDING', 'UPDATED'];
    $status_label = implode(', ', $status_array);

    $job_id = wp_insert_post([
        'post_title'   => sprintf(__('BrickLink Bestell-Sync [%s]', 'lego-wawi'), $status_label) . ' - ' . date_i18n('d.m.Y H:i'),
        'post_type'    => 'lww_job',
        'post_status'  => 'lww_pending',
        'post_author'  => get_current_user_id(),
        'menu_order'   => $priority,
    ], true);

    if (is_wp_error($job_id)) {
        add_settings_error('lww_messages', 'job_creation_failed', 'Fehler: ' . $job_id->get_error_message(), 'error');
    } else {
        update_post_meta($job_id, '_job_type', 'bricklink_order_sync');
        update_post_meta($job_id, '_sync_filters', ['status' => $status_array]);
        update_post_meta($job_id, '_processed_items', 0);
        lww_log_to_job($job_id, 'Job erstellt mit Filter: ' . $status_label);
        lww_start_cron_job();
        add_settings_error('lww_messages', 'job_created', 'BrickLink Sync gestartet.', 'success');
    }

    wp_safe_redirect(admin_url('admin.php?page=lww_jobs_ui'));
    exit;
}
add_action('admin_post_lww_start_bricklink_order_sync', 'lww_handle_start_bricklink_order_sync');

/**
 * Handler für den Start des BrickOwl Bestell-Syncs.
 */
function lww_handle_start_brickowl_order_sync() {
    if (!check_admin_referer('lww_brickowl_orders_nonce')) wp_die('Security Check');

    $days_back = isset($_POST['days_back']) ? absint($_POST['days_back']) : 7;
    $status_array = isset($_POST['status']) ? array_map('sanitize_text_field', $_POST['status']) : ['Pending'];
    
    $job_id = wp_insert_post([
        'post_title'   => sprintf(__('BrickOwl Bestell-Sync (%d Tage)', 'lego-wawi'), $days_back) . ' - ' . date_i18n('d.m.Y H:i'),
        'post_type'    => 'lww_job',
        'post_status'  => 'lww_pending',
        'post_author'  => get_current_user_id(),
        'menu_order'   => 15,
    ], true);

    if (is_wp_error($job_id)) {
        add_settings_error('lww_messages', 'job_creation_failed', 'Fehler: ' . $job_id->get_error_message(), 'error');
    } else {
        update_post_meta($job_id, '_job_type', 'brickowl_order_sync');
        update_post_meta($job_id, '_sync_filters', ['days_back' => $days_back, 'status' => $status_array]);
        update_post_meta($job_id, '_processed_items', 0);
        lww_log_to_job($job_id, 'Job erstellt.');
        lww_start_cron_job();
        add_settings_error('lww_messages', 'job_created', 'BrickOwl Sync gestartet.', 'success');
    }

    wp_safe_redirect(admin_url('admin.php?page=lww_jobs_ui'));
    exit;
}
add_action('admin_post_lww_start_brickowl_order_sync', 'lww_handle_start_brickowl_order_sync');
?>
