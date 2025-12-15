<?php
/**
 * Modul: Duplikat-Erkennung & Bereinigung (v2.0)
 * 
 * Bietet eine UI zum Scannen nach Duplikaten (basierend auf normalisierten Titeln)
 * und zum Starten von Bereinigungs-Jobs.
 */
if (!defined('ABSPATH')) exit;

/**
 * Rendert die UI für Duplikate.
 */
function lww_render_duplicates_ui_page() {
    if (!current_user_can('manage_options')) wp_die(__('Keine Berechtigung.', 'lego-wawi'));

    // Prüfen, ob ein Scan-Ergebnis vorliegt (im letzten abgeschlossenen Scan-Job)
    $last_scan_job = get_posts([
        'post_type' => 'lww_job',
        'meta_key' => '_job_type',
        'meta_value' => 'duplicate_scan',
        'post_status' => 'lww_complete',
        'posts_per_page' => 1,
        'orderby' => 'ID',
        'order' => 'DESC'
    ]);

    $scan_results = [];
    $scan_date = '';
    if (!empty($last_scan_job)) {
        $scan_results = get_post_meta($last_scan_job[0]->ID, '_duplicate_results', true);
        $scan_date = get_the_date('d.m.Y H:i', $last_scan_job[0]->ID);
    }

    ?>
    <div class="wrap lww-wrap">
        <h1><img src="<?php echo esc_url(LWW_PLUGIN_URL . 'assets/img/bricksberg-logo.png'); ?>" alt="Bricksberg Logo" class="lww-header-logo" /> <?php _e('Duplikate finden & bereinigen', 'lego-wawi'); ?></h1>
        <p><?php _e('Der Smart Scan analysiert den Katalog (Teile, Sets, Minifiguren) auf identische oder sehr ähnliche Einträge (z.B. durch unterschiedliche Schreibweisen).', 'lego-wawi'); ?></p>
        
        <?php settings_errors('lww_messages'); ?>

        <div class="lww-card lww-mt-20">
            <form action="admin-post.php" method="post" style="display:flex; align-items:center; gap:20px;">
                <input type="hidden" name="action" value="lww_start_duplicate_scan">
                <?php wp_nonce_field('lww_start_duplicate_scan_nonce'); ?>
                
                <div>
                    <h3><?php _e('Neuen Scan starten', 'lego-wawi'); ?></h3>
                    <p class="description"><?php _e('Der Scan läuft im Hintergrund. Dies kann bei großem Katalog einige Minuten dauern.', 'lego-wawi'); ?></p>
                </div>
                <div>
                    <?php submit_button(__('Smart Scan starten', 'lego-wawi'), 'primary large', 'submit', false); ?>
                </div>
            </form>
        </div>

        <?php if (!empty($scan_results) && is_array($scan_results)): ?>
            <div class="lww-card lww-mt-20">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                    <h2 style="margin:0;"><?php printf(__('Gefundene Duplikate (Stand: %s)', 'lego-wawi'), $scan_date); ?></h2>
                    
                    <form action="admin-post.php" method="post" onsubmit="return confirm('<?php _e('Sind Sie sicher? Die ausgewählten Duplikate werden dauerhaft gelöscht und Inventar wird (wenn möglich) zusammengeführt.', 'lego-wawi'); ?>');">
                        <input type="hidden" name="action" value="lww_start_duplicate_cleanup">
                        <?php wp_nonce_field('lww_start_duplicate_cleanup_nonce'); ?>
                        <!-- IDs werden per JS gesammelt -->
                        <input type="hidden" name="ids_to_delete" id="lww_ids_to_delete">
                        <button type="submit" class="button button-link-delete button-large" id="lww-cleanup-btn" disabled><?php _e('Ausgewählte bereinigen', 'lego-wawi'); ?></button>
                    </form>
                </div>

                <table class="wp-list-table widefat striped lww-duplicates-table">
                    <thead>
                        <tr>
                            <th><?php _e('Gruppe / Normalisierter Titel', 'lego-wawi'); ?></th>
                            <th><?php _e('Einträge', 'lego-wawi'); ?></th>
                            <th style="width:150px;"><?php _e('Aktion', 'lego-wawi'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($scan_results as $norm_title => $ids): 
                            if (count($ids) < 2) continue;
                            // Hole Post Objekte für Details
                            $posts = get_posts(['post__in' => $ids, 'post_type' => 'any', 'posts_per_page' => -1]);
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($norm_title); ?></strong></td>
                            <td>
                                <ul class="lww-duplicate-list">
                                <?php 
                                $first = true;
                                foreach ($posts as $p): 
                                    $is_stub = get_post_meta($p->ID, '_lww_is_stub', true);
                                    $inventory_count = lww_count_inventory_for_catalog_item($p->ID);
                                    $css_class = $first ? 'lww-keep-candidate' : 'lww-delete-candidate';
                                    $badge = $is_stub ? '<span class="lww-badge-stub">STUB</span>' : '';
                                ?>
                                    <li class="<?php echo $css_class; ?>">
                                        <label>
                                            <input type="radio" name="keep_<?php echo md5($norm_title); ?>" value="<?php echo $p->ID; ?>" <?php checked($first); ?> class="lww-dup-selector">
                                            <span class="dashicons dashicons-saved lww-keep-icon"></span>
                                            <strong><?php echo esc_html($p->post_title); ?></strong> (ID: <?php echo $p->ID; ?>)
                                            <?php echo $badge; ?>
                                            <span class="lww-inv-count"><?php printf(__('%d Inventar-Posten', 'lego-wawi'), $inventory_count); ?></span>
                                        </label>
                                        <a href="<?php echo get_edit_post_link($p->ID); ?>" target="_blank" class="button button-small"><span class="dashicons dashicons-external"></span></a>
                                    </li>
                                <?php 
                                $first = false;
                                endforeach; ?>
                                </ul>
                            </td>
                            <td>
                                <span class="description"><?php _e('Wählen Sie den Eintrag, der BEHALTEN werden soll. Alle anderen in dieser Gruppe werden gelöscht.', 'lego-wawi'); ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <script>
            jQuery(document).ready(function($) {
                function updateDeleteList() {
                    var toDelete = [];
                    $('.lww-duplicate-list').each(function() {
                        var keepId = $(this).find('input:checked').val();
                        $(this).find('input').each(function() {
                            if($(this).val() != keepId) toDelete.push($(this).val());
                        });
                    });
                    $('#lww_ids_to_delete').val(toDelete.join(','));
                    $('#lww-cleanup-btn').prop('disabled', toDelete.length === 0).text('Ausgewählte bereinigen (' + toDelete.length + ')');
                }

                $('.lww-dup-selector').on('change', function() {
                    // Visuelles Feedback
                    $(this).closest('ul').find('li').removeClass('lww-keep-candidate').addClass('lww-delete-candidate');
                    $(this).closest('li').removeClass('lww-delete-candidate').addClass('lww-keep-candidate');
                    updateDeleteList();
                });
                
                // Init
                updateDeleteList();
            });
            </script>
        <?php elseif (isset($last_scan_job)): ?>
            <div class="notice notice-success inline lww-mt-20"><p><?php _e('Der letzte Scan hat keine Duplikate gefunden. Alles sauber!', 'lego-wawi'); ?></p></div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Hilfsfunktion: Zählt verknüpfte Inventar-Items.
 */
function lww_count_inventory_for_catalog_item($post_id) {
    global $wpdb;
    // Prüfe Part, Set, Minifig Verknüpfung
    $sql = "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN ('_lww_part_id', '_lww_set_id', '_lww_minifig_id') AND meta_value = %d";
    return (int) $wpdb->get_var($wpdb->prepare($sql, $post_id));
}

/**
 * Handler: Startet den Scan-Job.
 */
function lww_handle_start_duplicate_scan() {
    if (!check_admin_referer('lww_start_duplicate_scan_nonce')) wp_die('Security Check');
    
    $job_id = wp_insert_post([
        'post_title' => __('Duplikat-Scan (Smart Analysis)', 'lego-wawi') . ' - ' . date_i18n('d.m.Y H:i'),
        'post_type' => 'lww_job',
        'post_status' => 'lww_pending',
        'post_author' => get_current_user_id()
    ]);

    if (!is_wp_error($job_id)) {
        update_post_meta($job_id, '_job_type', 'duplicate_scan');
        update_post_meta($job_id, '_processed_items', 0);
        lww_log_to_job($job_id, 'Scan gestartet.');
        lww_start_cron_job();
        add_settings_error('lww_messages', 'scan_started', __('Scan-Job gestartet. Ergebnisse erscheinen hier nach Abschluss.', 'lego-wawi'), 'success');
    }
    
    wp_safe_redirect(admin_url('admin.php?page=lww_data_correction_ui')); // Oder eigene Seite
    exit;
}
add_action('admin_post_lww_start_duplicate_scan', 'lww_handle_start_duplicate_scan');

/**
 * Handler: Startet den Bereinigungs-Job.
 */
function lww_handle_start_duplicate_cleanup() {
    if (!check_admin_referer('lww_start_duplicate_cleanup_nonce')) wp_die('Security Check');

    $ids_string = $_POST['ids_to_delete'] ?? '';
    if (empty($ids_string)) {
        wp_safe_redirect(wp_get_referer()); exit;
    }

    $ids = array_map('absint', explode(',', $ids_string));

    $job_id = wp_insert_post([
        'post_title' => sprintf(__('Duplikat-Bereinigung (%d Elemente)', 'lego-wawi'), count($ids)),
        'post_type' => 'lww_job',
        'post_status' => 'lww_pending',
        'post_author' => get_current_user_id()
    ]);

    if (!is_wp_error($job_id)) {
        update_post_meta($job_id, '_job_type', 'duplicate_cleanup');
        update_post_meta($job_id, '_item_ids_to_process', $ids);
        update_post_meta($job_id, '_total_items', count($ids));
        update_post_meta($job_id, '_processed_items', 0);
        lww_start_cron_job();
        add_settings_error('lww_messages', 'cleanup_started', __('Bereinigung gestartet.', 'lego-wawi'), 'success');
    }

    wp_safe_redirect(admin_url('admin.php?page=lww_jobs_ui'));
    exit;
}
add_action('admin_post_lww_start_duplicate_cleanup', 'lww_handle_start_duplicate_cleanup');
