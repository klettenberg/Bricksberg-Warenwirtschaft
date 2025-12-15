<?php
/**
 * Tools & Wartung (v27.0)
 * UPDATE: Aggressive Bereinigungs-Tools (Reset) und DB-Log Cleanup hinzugefügt.
 */
if (!defined('ABSPATH')) exit;

add_action('admin_post_lww_wipe_data', 'lww_handle_wipe_data');
add_action('admin_post_lww_truncate_api_logs', 'lww_handle_truncate_api_logs');

function lww_render_tools_ui_page() {
    if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
    ?>
    <div class="wrap lww-wrap">
        <h1>Werkzeuge & Wartung</h1>
        
        <?php settings_errors('lww_messages'); ?>

        <!-- Log Management -->
        <div class="lww-card">
            <h2>Debug-Log & Datenbank-Bereinigung</h2>
            <p><?php _e('Verwalten Sie hier die Systemprotokolle und bereinigen Sie die Datenbank von alten Einträgen.', 'lego-wawi'); ?></p>
            
            <div style="background: #23282d; color: #72aee6; padding: 15px; border-radius: 4px; font-family: monospace; height: 300px; overflow-y: scroll; white-space: pre-wrap; margin-bottom: 15px;">
                <?php 
                if (function_exists('lww_read_debug_log')) {
                    echo esc_html(lww_read_debug_log(200)); 
                } else {
                    echo 'Log-Reader Funktion nicht verfügbar.';
                }
                ?>
            </div>

            <div style="display:flex; gap:10px;">
                <form action="admin-post.php" method="post">
                    <input type="hidden" name="action" value="lww_clear_debug_log">
                    <?php wp_nonce_field('lww_clear_log_nonce'); ?>
                    <?php submit_button(__('Debug-File leeren', 'lego-wawi'), 'secondary', 'submit', false); ?>
                </form>
                
                <form action="admin-post.php" method="post" onsubmit="return confirm('Warnung: Dies löscht ALLE Einträge aus der Tabelle wp_postmeta/posts für lww_api_log. Das reduziert die DB-Größe massiv.');">
                    <input type="hidden" name="action" value="lww_truncate_api_logs">
                    <?php wp_nonce_field('lww_truncate_logs_nonce'); ?>
                    <?php submit_button(__('DB: Alte API-Logs löschen (TRUNCATE)', 'lego-wawi'), 'delete', 'submit', false); ?>
                </form>
            </div>
        </div>

        <!-- Data Reset Zone -->
        <div class="lww-card lww-mt-20" style="border-left: 5px solid red;">
            <h2><span class="dashicons dashicons-warning" style="color:red"></span> Gefahrenzone: Daten zurücksetzen</h2>
            <p><?php _e('Achtung: Diese Aktionen sind unwiderruflich! Bitte erstellen Sie vorher ein Backup.', 'lego-wawi'); ?></p>
            
            <table class="form-table">
                <tr>
                    <th>Shop-Bestand löschen</th>
                    <td>
                        <form action="admin-post.php" method="post" onsubmit="return confirm('Löscht alle WooCommerce Produkte, die von der WaWi erstellt wurden. Sicher?');">
                            <input type="hidden" name="action" value="lww_wipe_data">
                            <input type="hidden" name="wipe_target" value="shop_products">
                            <?php wp_nonce_field('lww_wipe_data_nonce'); ?>
                            <button class="button button-secondary">Alle WaWi-Produkte im Shop löschen</button>
                        </form>
                        <p class="description">Löscht Produkte, die mit <code>_lww_catalog_id</code> verknüpft sind.</p>
                    </td>
                </tr>
                <tr>
                    <th>Katalog löschen</th>
                    <td>
                        <form action="admin-post.php" method="post" onsubmit="return confirm('Löscht den gesamten Katalog (Teile, Sets, Minifigs, Farben). Inventar bleibt bestehen (wird aber entknüpft). Sicher?');">
                            <input type="hidden" name="action" value="lww_wipe_data">
                            <input type="hidden" name="wipe_target" value="catalog">
                            <?php wp_nonce_field('lww_wipe_data_nonce'); ?>
                            <button class="button button-secondary">Katalogdaten löschen</button>
                        </form>
                    </td>
                </tr>
                <tr>
                    <th>Vollständiger Reset</th>
                    <td>
                        <form action="admin-post.php" method="post" onsubmit="return confirm('TOTALER DATENVERLUST! Löscht ALLES (Inventar, Katalog, Jobs, Logs, Einstellungen). Sind Sie absolut sicher?');">
                            <input type="hidden" name="action" value="lww_wipe_data">
                            <input type="hidden" name="wipe_target" value="full_reset">
                            <?php wp_nonce_field('lww_wipe_data_nonce'); ?>
                            <button class="button button-link-delete button-large">System auf Werkseinstellungen zurücksetzen</button>
                        </form>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Cron & Tests -->
        <div class="lww-card lww-mt-20">
            <h2>System Status & Tests</h2>
            <div style="display: flex; gap: 10px;">
                <form action="admin-post.php" method="post">
                    <input type="hidden" name="action" value="lww_repair_cron_schedule">
                    <?php wp_nonce_field('lww_tools_cron_nonce'); ?>
                    <?php submit_button(__('Cron-Zeitplan reparieren', 'lego-wawi'), 'secondary', 'submit', false); ?>
                </form>
                
                <form action="admin-post.php" method="post">
                    <input type="hidden" name="action" value="lww_delete_stubs">
                    <?php wp_nonce_field('lww_delete_stubs_nonce'); ?>
                    <?php submit_button(__('Stubs (Platzhalter) bereinigen', 'lego-wawi'), 'secondary', 'submit', false); ?>
                </form>
            </div>
        </div>
    </div>
    <?php
}

// --- Handler Functions ---

function lww_handle_truncate_api_logs() {
    if (!check_admin_referer('lww_truncate_logs_nonce')) wp_die('Security Check');
    if (!current_user_can('manage_options')) wp_die('Permission Denied');

    global $wpdb;
    // Lösche alle Posts vom Typ lww_api_log. Das ist schneller als wp_delete_post in Loop.
    // Wir müssen auch postmeta und tax relations bereinigen.
    
    $post_type = 'lww_api_log';
    
    // 1. Hole IDs (für Meta Löschung wichtig)
    // Bei sehr vielen Einträgen (1M+) kann das Timeouten. Besser: Direct SQL Delete.
    
    $query_posts = $wpdb->prepare("DELETE FROM {$wpdb->posts} WHERE post_type = %s", $post_type);
    $deleted_posts = $wpdb->query($query_posts);
    
    // Orphaned Postmeta bereinigen (generisch)
    $query_meta = "DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} wp ON wp.ID = pm.post_id WHERE wp.ID IS NULL";
    $deleted_meta = $wpdb->query($query_meta);

    add_settings_error('lww_messages', 'logs_deleted', sprintf(__('Datenbank bereinigt: %d Log-Einträge gelöscht. %d verwaiste Meta-Einträge entfernt.', 'lego-wawi'), $deleted_posts, $deleted_meta), 'success');
    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect(wp_get_referer()); exit;
}

function lww_handle_wipe_data() {
    if (!check_admin_referer('lww_wipe_data_nonce')) wp_die('Security Check');
    if (!current_user_can('manage_options')) wp_die('Permission Denied');
    
    global $wpdb;
    $target = $_POST['wipe_target'];
    $msg = '';

    if ($target === 'shop_products') {
        // Lösche alle Woo Produkte mit _lww_catalog_id meta
        // Direct SQL für Performance
        $sql = "DELETE p FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id WHERE p.post_type IN ('product', 'product_variation') AND pm.meta_key = '_lww_catalog_id'";
        $deleted = $wpdb->query($sql);
        // Bereinigung orphans
        $wpdb->query("DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} wp ON wp.ID = pm.post_id WHERE wp.ID IS NULL");
        $msg = "$deleted Shop-Produkte gelöscht.";
    }
    elseif ($target === 'catalog') {
        $types = "'lww_part', 'lww_set', 'lww_minifig', 'lww_color'";
        $deleted = $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_type IN ($types)");
        $wpdb->query("DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} wp ON wp.ID = pm.post_id WHERE wp.ID IS NULL");
        // Taxonomien leeren? Optional. Hier lassen wir sie, um Re-Import zu beschleunigen.
        $msg = "$deleted Katalog-Einträge gelöscht.";
    }
    elseif ($target === 'full_reset') {
        $types = "'lww_part', 'lww_set', 'lww_minifig', 'lww_color', 'lww_inventory_item', 'lww_job', 'lww_order', 'lww_api_log', 'lww_shelf', 'lww_bundle', 'lww_tenant'";
        $deleted = $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_type IN ($types)");
        $wpdb->query("DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} wp ON wp.ID = pm.post_id WHERE wp.ID IS NULL");
        
        // Optionen löschen
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'lww_%'");
        
        $msg = "System vollständig zurückgesetzt. $deleted Einträge gelöscht. Alle Einstellungen entfernt.";
    }

    add_settings_error('lww_messages', 'wipe_success', $msg, 'success');
    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect(wp_get_referer()); exit;
}

// Helper Stubs
function lww_handle_clear_debug_log() {
    check_admin_referer('lww_clear_log_nonce');
    lww_clear_debug_log();
    wp_safe_redirect(wp_get_referer()); exit;
}
add_action('admin_post_lww_clear_debug_log', 'lww_handle_clear_debug_log');

function lww_handle_repair_cron_schedule() {
    check_admin_referer('lww_tools_cron_nonce');
    wp_clear_scheduled_hook('lww_main_batch_hook');
    lww_start_cron_job();
    wp_safe_redirect(wp_get_referer()); exit;
}
add_action('admin_post_lww_repair_cron_schedule', 'lww_handle_repair_cron_schedule');

function lww_handle_delete_stubs() {
    check_admin_referer('lww_delete_stubs_nonce');
    // Job starten Logic (wie zuvor)
    $job_id = wp_insert_post(['post_title' => 'Stub Cleanup', 'post_type' => 'lww_job', 'post_status' => 'lww_pending']);
    update_post_meta($job_id, '_job_type', 'delete_stubs');
    lww_log_to_job($job_id, 'Stub Bereinigung gestartet');
    lww_start_cron_job();
    add_settings_error('lww_messages', 'stubs', 'Bereinigungs-Job gestartet.', 'success');
    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect(admin_url('admin.php?page=lww_jobs_ui')); exit;
}
add_action('admin_post_lww_delete_stubs', 'lww_handle_delete_stubs');

function lww_handle_run_import_test() {
    check_admin_referer('lww_run_test_nonce');
    // Test Logic placeholder
    wp_safe_redirect(wp_get_referer()); exit;
}
add_action('admin_post_lww_run_import_test', 'lww_handle_run_import_test');
?>