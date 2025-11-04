<?php
/**
 * Modul: Job-Warteschlange (v13.0)
 * Rendert den "Job-Warteschlange"-Tab und verwaltet die Job-Aktionen.
 * Enthält AJAX-Handler für Live-Updates.
 */
if (!defined('ABSPATH')) exit;

// Stellt sicher, dass die WP_List_Table-Klasse verfügbar ist
if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

/**
 * Erstellt die anpassbare Tabelle für die Job-Liste.
 * Erbt von der WordPress-Standardklasse WP_List_Table.
 */
class LWW_Jobs_List_Table extends WP_List_Table {

    /**
     * Konstruktor: Legt Singular- und Pluralnamen fest.
     */
    public function __construct() {
        parent::__construct([
            'singular' => __('Job', 'lego-wawi'),
            'plural'   => __('Jobs', 'lego-wawi'),
            'ajax'     => false // AJAX wird manuell über separates JS gehandhabt
        ]);
    }

    /**
     * Definiert die Spalten der Tabelle.
     * @return array Assoziatives Array [Spalten-Slug => Spalten-Titel]
     */
    public function get_columns() {
        return [
            'cb'           => '<input type="checkbox" />',
            'title'        => __('Job', 'lego-wawi'),
            'job_type'     => __('Typ', 'lego-wawi'),
            'job_status'   => __('Status', 'lego-wawi'),
            'job_progress' => __('Fortschritt', 'lego-wawi'),
            'date'         => __('Erstellt', 'lego-wawi')
        ];
    }

    /**
     * Definiert, welche Spalten sortierbar sind.
     * @return array Array [Spalten-Slug => [orderby-Parameter, initial-sort-order]]
     */
    public function get_sortable_columns() {
        return [
            'title'      => ['title', false],
            'job_type'   => ['job_type', false],
            'job_status' => ['post_status', false],
            'date'       => ['date', true] // Standard Sortierung
        ];
    }

    /**
     * Bereitet die Daten für die Anzeige vor (Abrufen der Jobs, Paginierung, Sortierung).
     */
    public function prepare_items() {
        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];

        // KORREKTUR: Bulk-Action-Verarbeitung wird nun in der Render-Funktion aufgerufen, nicht hier.

        $per_page = 20;
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;

        $orderby = isset($_REQUEST['orderby']) ? sanitize_key($_REQUEST['orderby']) : 'date';
        $order = isset($_REQUEST['order']) && in_array(strtoupper($_REQUEST['order']), ['ASC', 'DESC']) ? strtoupper($_REQUEST['order']) : 'DESC';
        
        // Standardmäßig nur aktive Jobs anzeigen, es sei denn ein Filter ist gesetzt
        $post_status_filter = !empty($_REQUEST['post_status']) ? sanitize_key($_REQUEST['post_status']) : 'active';

        $args = [
            'post_type'      => 'lww_job',
            'posts_per_page' => $per_page,
            'offset'         => $offset,
            'orderby'        => ['menu_order' => 'ASC', $orderby => $order], // Priorität zuerst
            'order'          => $order,
        ];

        if ($post_status_filter === 'all') {
            $args['post_status'] = ['lww_pending', 'lww_running', 'lww_paused', 'lww_complete', 'lww_failed'];
        } elseif ($post_status_filter === 'active') {
            $args['post_status'] = ['lww_pending', 'lww_running', 'lww_paused'];
        } else {
            $args['post_status'] = $post_status_filter;
        }

        if ($orderby === 'job_type') {
             $args['meta_key'] = '_job_type';
             $args['orderby'] = 'meta_value';
        }

        $query = new WP_Query($args);
        $this->items = $query->posts;

        $total_items = $query->found_posts;
        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ]);
    }

    /**
     * Standard-Renderer für Spalten.
     */
    public function column_default($item, $column_name) {
        switch($column_name) {
             case 'date':
                 return mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item->post_date );
             default:
                return '---';
        }
    }

    /**
     * Rendert die Checkbox-Spalte.
     */
    public function column_cb($item) {
        return sprintf(
            '<input type="checkbox" name="job[]" value="%s" />', $item->ID
        );
    }

    /**
     * Rendert die "Job"-Titel-Spalte mit Aktionen.
     */
    public function column_title($item) {
        $title = $item->post_title ? $item->post_title : __('(kein Titel)', 'lego-wawi');
        $actions = [];
        $base_action_url = admin_url('admin-post.php');
        $is_prioritized = $item->menu_order < 10;

        // Status-Indikatoren (Priorität, Pausiert)
        $indicators = '';
        if ($is_prioritized) {
            $indicators .= '<span class="dashicons dashicons-star-filled" style="color: #ffb900;" title="' . esc_attr__('Priorisiert', 'lego-wawi') . '"></span> ';
        }
        if ($item->post_status === 'lww_paused') {
            $indicators .= '<span class="dashicons dashicons-controls-pause" style="color: #787c82;" title="' . esc_attr__('Pausiert', 'lego-wawi') . '"></span> ';
        }

        // Aktionen basierend auf Status
        if ($item->post_status === 'lww_pending') {
            if (!$is_prioritized) {
                $prio_nonce = 'lww_prioritize_job_' . $item->ID;
                $prio_url = wp_nonce_url(add_query_arg(['action' => 'lww_prioritize_job', 'job_id' => $item->ID], $base_action_url), $prio_nonce);
                $actions['prioritize'] = sprintf('<a href="%s">%s</a>', esc_url($prio_url), __('Priorisieren', 'lego-wawi'));
            }
            $pause_nonce = 'lww_pause_job_' . $item->ID;
            $pause_url = wp_nonce_url(add_query_arg(['action' => 'lww_pause_job', 'job_id' => $item->ID], $base_action_url), $pause_nonce);
            $actions['pause'] = sprintf('<a href="%s">%s</a>', esc_url($pause_url), __('Pausieren', 'lego-wawi'));
        } elseif ($item->post_status === 'lww_running') {
            $pause_nonce = 'lww_pause_job_' . $item->ID;
            $pause_url = wp_nonce_url(add_query_arg(['action' => 'lww_pause_job', 'job_id' => $item->ID], $base_action_url), $pause_nonce);
            $actions['pause'] = sprintf('<a href="%s">%s</a>', esc_url($pause_url), __('Pausieren', 'lego-wawi'));
        } elseif ($item->post_status === 'lww_paused') {
            $resume_nonce = 'lww_resume_job_' . $item->ID;
            $resume_url = wp_nonce_url(add_query_arg(['action' => 'lww_resume_job', 'job_id' => $item->ID], $base_action_url), $resume_nonce);
            $actions['resume'] = sprintf('<a href="%s">%s</a>', esc_url($resume_url), __('Fortsetzen', 'lego-wawi'));
        }

        if (in_array($item->post_status, ['lww_pending', 'lww_running', 'lww_paused'])) {
            $cancel_nonce_action = 'lww_cancel_job_' . $item->ID;
            $cancel_url = wp_nonce_url(add_query_arg(['action' => 'lww_cancel_job', 'job_id' => $item->ID], $base_action_url), $cancel_nonce_action);
            $actions['cancel'] = sprintf('<a href="%s" class="lww-action-cancel" onclick="return confirm(\'%s\');">%s</a>',
                esc_url($cancel_url),
                esc_js(__('Möchtest du diesen Job wirklich abbrechen? Er wird als fehlgeschlagen markiert.', 'lego-wawi')),
                __('Abbrechen', 'lego-wawi')
            );
        }

        if ($item->post_status === 'trash') {
             $untrash_nonce_action = 'lww_untrash_job_' . $item->ID;
             $untrash_url = wp_nonce_url(add_query_arg(['action' => 'lww_untrash_job', 'job_id' => $item->ID], $base_action_url), $untrash_nonce_action);
             $actions['untrash'] = sprintf('<a href="%s" class="lww-action-untrash">%s</a>', esc_url($untrash_url), __('Wiederherstellen', 'lego-wawi'));

             $delete_perm_nonce_action = 'lww_delete_permanently_job_' . $item->ID;
             $delete_permanently_url = wp_nonce_url(add_query_arg(['action' => 'lww_delete_permanently_job', 'job_id' => $item->ID], $base_action_url), $delete_perm_nonce_action);
             $actions['delete_permanently'] = sprintf('<a href="%s" class="lww-action-delete" onclick="return confirm(\'%s\');">%s</a>',
                esc_url($delete_permanently_url),
                esc_js(__('WARNUNG: Bist du absolut sicher? Diese Aktion kann nicht rückgängig gemacht werden und löscht den Job endgültig.', 'lego-wawi')),
                __('Endgültig löschen', 'lego-wawi')
             );
        } else {
             $trash_nonce_action = 'lww_delete_job_' . $item->ID;
             $trash_url = wp_nonce_url(add_query_arg(['action' => 'lww_delete_job', 'job_id' => $item->ID], $base_action_url), $trash_nonce_action);
             $actions['trash'] = sprintf('<a href="%s" class="lww-action-delete">%s</a>', esc_url($trash_url), __('Papierkorb', 'lego-wawi'));
        }

        return $indicators . '<strong>' . esc_html($title) . '</strong>' . $this->row_actions($actions);
    }

    /**
     * Rendert die "Status"-Spalte mit farbigen Badges.
     */
    public function column_job_status($item) {
        $status = $item->post_status;
        $status_object = get_post_status_object($status);
        $status_label = $status_object ? $status_object->label : ucfirst(str_replace('lww_', '', $status));
        $status_class = 'lww-status-' . str_replace('lww_', '', $status);

        return sprintf('<span class="lww-status-badge %s">%s</span>', esc_attr($status_class), esc_html($status_label));
    }

    /**
     * Rendert die "Typ"-Spalte.
     */
     public function column_job_type($item) {
         $type = get_post_meta($item->ID, '_job_type', true);
         switch($type) {
             case 'catalog_import': return __('Katalog-Import', 'lego-wawi');
             case 'inventory_import': return __('Inventar-Import', 'lego-wawi');
             case 'inventory_backup_import': return __('Inventar-Backup Import', 'lego-wawi');
             case 'demand_analysis': return __('Nachfrageanalyse (KI)', 'lego-wawi');
             case 'description_generation': return __('Beschreibung (KI)', 'lego-wawi');
             case 'location_sync': return __('Lagerort-Sync', 'lego-wawi');
             case 'ebay_sync': return __('eBay Inventar Sync', 'lego-wawi');
             case 'brickowl_sync': return __('BrickOwl Preis-Sync', 'lego-wawi');
             case 'data_validation': return __('Daten-Validierung', 'lego-wawi');
             case 'data_purge': return __('Datenbereinigung', 'lego-wawi');
             default: return esc_html($type ?: __('Unbekannt', 'lego-wawi'));
         }
     }

     /**
      * Rendert die "Fortschritt"-Spalte mit Zeilenzahlen und letzter Log-Nachricht.
      */
     public function column_job_progress($item) {
        $log = get_post_meta($item->ID, '_job_log', true);
        $job_type = get_post_meta($item->ID, '_job_type', true);
        $status = $item->post_status;
        $output = '';

        $last_message = (is_array($log) && !empty($log)) ? end($log) : '';
        $message_without_timestamp = preg_replace('/^\\[.*]ls*]\\]\\s*/', '', $last_message);
        $short_message = mb_strimwidth($message_without_timestamp, 0, 70, '...');

        // --- Fortschrittsbalken-Logik ---
        $progress_percent = 0;
        $progress_text = '';

        if (in_array($job_type, ['inventory_import', 'inventory_backup_import', 'demand_analysis', 'description_generation', 'location_sync', 'ebay_sync', 'brickowl_sync'])) {
            $processed = (int) get_post_meta($item->ID, '_processed_items', true);
            $total = (int) get_post_meta($item->ID, '_total_items', true);
            if ($total > 0) {
                $progress_percent = round(($processed / $total) * 100);
                $progress_text = sprintf('%s / %s', number_format_i18n($processed), number_format_i18n($total));
            }
        } elseif ($job_type === 'catalog_import') {
            $job_queue = get_post_meta($item->ID, '_job_queue', true);
            $task_index = (int) get_post_meta($item->ID, '_current_task_index', true);
            if (is_array($job_queue) && !empty($job_queue)) {
                $total_tasks = count($job_queue);
                $progress_percent = round((($task_index) / $total_tasks) * 100);
                $progress_text = sprintf('%s %d / %d', __('Aufgabe', 'lego-wawi'), $task_index + 1, $total_tasks);

                if (isset($job_queue[$task_index])) {
                    $current_task = $job_queue[$task_index];
                    $rows_processed = $current_task['rows_processed'] ?? 0;
                    $total_rows = $current_task['total_rows'] ?? 0;
                    if ($total_rows > 1) {
                        $task_percent = round((($rows_processed - 1) / ($total_rows - 1)) * 100);
                        $progress_percent = round((($task_index) / $total_tasks) * 100 + ($task_percent / $total_tasks));
                        $progress_text .= sprintf(': %s (%d%%)', esc_html($current_task['key']), $task_percent);
                    }
                }
            }
        } elseif ($job_type === 'data_purge') {
             $purge_step = (int) get_post_meta($job_id, '_purge_step', true);
             $total_steps = 11; // 7 CPTs + 3 Tax + 1 Option
             $progress_percent = round(($purge_step / $total_steps) * 100);
             $progress_text = sprintf('%s %d / %d', __('Schritt', 'lego-wawi'), $purge_step, $total_steps);
        }

        // --- Anzeige basierend auf Status ---
        switch ($status) {
            case 'lww_pending':
                $output = '<em>' . __('Wartet auf Start...', 'lego-wawi') . '</em>';
                break;
            case 'lww_paused':
                $output = '<em>' . __('Pausiert', 'lego-wawi') . '</em>';
                break;
            case 'lww_running':
                $output = sprintf('<div class="lww-job-progress-text">%s (%d%%)</div>', $progress_text, $progress_percent);
                $output .= '<div class="lww-job-progress-bar"><div class="lww-job-progress-bar-inner" style="width:' . $progress_percent . '%;"></div></div>';
                $output .= sprintf(
                    '<small class="lww-last-log" title="%s" onclick="prompt(\'%s\', \'%s\');">%s</small>',
                    esc_attr__('Klicken, um die vollständige Nachricht zu kopieren', 'lego-wawi'),
                    esc_js(__('Vollständige Nachricht (zum Kopieren):', 'lego-wawi')),
                    esc_js($message_without_timestamp),
                    esc_html($short_message)
                );
                break;
            case 'lww_complete':
                $output = '<span style="color: green;">' . __('Abgeschlossen', 'lego-wawi') . '</span>';
                $output .= sprintf(
                    '<br><small class="lww-last-log" title="%s" onclick="prompt(\'%s\', \'%s\');">%s</small>',
                    esc_attr__('Klicken, um die vollständige Nachricht zu kopieren', 'lego-wawi'),
                    esc_js(__('Vollständige Nachricht (zum Kopieren):', 'lego-wawi')),
                    esc_js($message_without_timestamp),
                    esc_html($short_message)
                );
                break;
            case 'lww_failed':
                $output = '<span style="color: red;">' . __('Fehlgeschlagen', 'lego-wawi') . '</span>';
                $output .= sprintf(
                    '<br><small class="lww-last-log" title="%s" onclick="prompt(\'%s\', \'%s\');">%s</small>',
                    esc_attr__('Klicken, um die vollständige Nachricht zu kopieren', 'lego-wawi'),
                    esc_js(__('Vollständige Nachricht (zum Kopieren):', 'lego-wawi')),
                    esc_js($message_without_timestamp),
                    esc_html($short_message)
                );
                break;
            case 'trash':
                $output = '<em>' . __('Papierkorb', 'lego-wawi') . '</em>';
                break;
            default:
                $output = '--- (' . esc_html($status) . ')';
                break;
        }
        return $output;
     }

    /**
     * Definiert Bulk-Aktionen.
     */
    public function get_bulk_actions() {
        $actions = [
            'bulk-trash' => __('In den Papierkorb', 'lego-wawi')
        ];
        if (isset($_REQUEST['post_status']) && $_REQUEST['post_status'] === 'trash') {
             $actions = [
                'bulk-untrash' => __('Wiederherstellen', 'lego-wawi'),
                'bulk-delete-permanently' => __('Endgültig löschen', 'lego-wawi')
             ];
        }
        return $actions;
    }

    /**
     * Verarbeitet Bulk-Aktionen.
     */
    public function process_bulk_action() {
        $action = $this->current_action();
        $job_ids = isset($_REQUEST['job']) ? (array) $_REQUEST['job'] : [];
        $job_ids = array_map('absint', $job_ids);
        $job_ids = array_filter($job_ids);

        if (empty($job_ids) || !$action || strpos($action, 'bulk-') !== 0) {
            return;
        }

        $nonce_action = 'bulk-' . $this->_args['plural'];
        if (!check_admin_referer($nonce_action)) {
             wp_die(__('Sicherheitsüberprüfung fehlgeschlagen. Bitte versuche es erneut.', 'lego-wawi'));
        }

        $processed_count = 0;
        $redirect_needed = false;

        foreach ($job_ids as $job_id) {
            switch ($action) {
                case 'bulk-trash':
                    if (current_user_can('delete_post', $job_id) && wp_trash_post($job_id)) {
                        $processed_count++;
                    }
                    break;
                case 'bulk-untrash':
                     if (current_user_can('delete_post', $job_id) && wp_untrash_post($job_id)) {
                         $processed_count++;
                     }
                     break;
                 case 'bulk-delete-permanently':
                     if (current_user_can('delete_post', $job_id) && get_post_status($job_id) === 'trash' && wp_delete_post($job_id, true)) {
                         $processed_count++;
                     }
                     break;
            }
        }

        if ($processed_count > 0) {
            $message = '';
             switch ($action) {
                case 'bulk-trash': $message = sprintf(_n('%d Job in den Papierkorb verschoben.', '%d Jobs in den Papierkorb verschoben.', $processed_count, 'lego-wawi'), $processed_count); break;
                case 'bulk-untrash': $message = sprintf(_n('%d Job wiederhergestellt.', '%d Jobs wiederhergestellt.', $processed_count, 'lego-wawi'), $processed_count); break;
                case 'bulk-delete-permanently': $message = sprintf(_n('%d Job endgültig gelöscht.', '%d Jobs endgültig gelöscht.', $processed_count, 'lego-wawi'), $processed_count); break;
             }
             if ($message) {
                 add_settings_error('lww_messages', 'bulk_action_success', $message, 'updated');
                 set_transient('settings_errors', get_settings_errors(), 30);
                 $redirect_needed = true;
             }
        } else {
             add_settings_error('lww_messages', 'bulk_action_failed', __('Keine Jobs für die ausgewählte Aktion verarbeitet (evtl. fehlende Berechtigungen?).', 'lego-wawi'), 'error');
             set_transient('settings_errors', get_settings_errors(), 30);
             $redirect_needed = true;
        }

        if ($redirect_needed) {
            $current_url = add_query_arg();
            $redirect_url = remove_query_arg(['action', 'action2', 'job', '_wpnonce', '_wp_http_referer'], $current_url);
            wp_safe_redirect($redirect_url);
            exit;
        }
    }

    /**
     * Fügt Filter-Links über der Tabelle hinzu.
     */
    protected function get_views() {
        $status_links = [];
        $num_posts = wp_count_posts('lww_job', 'readable');
        $base_url = admin_url('admin.php?page=lww_jobs_ui'); // KORRIGIERTE URL

        $all_statuses = ['lww_pending', 'lww_running', 'lww_paused', 'lww_complete', 'lww_failed'];
        $total_items = 0;
        foreach($all_statuses as $status) {
            $total_items += $num_posts->$status ?? 0;
        }
        
        $current_status = !empty($_REQUEST['post_status']) ? $_REQUEST['post_status'] : 'active';

        // 'Alle' Link
        $all_url = add_query_arg('post_status', 'all', $base_url);
        $status_links['all'] = sprintf(
            '<a href="%s" %s>%s <span class="count">(%d)</span></a>',
            esc_url($all_url),
            ($current_status === 'all' ? 'class="current"' : ''),
            __('Alle', 'lego-wawi'),
            $total_items
        );

        // 'Aktive' Link (Standardansicht)
        $active_count = ($num_posts->lww_pending ?? 0) + ($num_posts->lww_running ?? 0) + ($num_posts->lww_paused ?? 0);
        $active_url = remove_query_arg('post_status', $base_url);
        $status_links['active'] = sprintf(
            '<a href="%s" %s>%s <span class="count">(%d)</span></a>',
            esc_url($active_url),
            ($current_status === 'active' ? 'class="current"' : ''),
            __('Aktive Jobs', 'lego-wawi'),
            $active_count
        );

        // Links für jeden einzelnen Status
        foreach ($all_statuses as $status_name) {
            $status_object = get_post_status_object($status_name);
            if (!$status_object) continue;

            $count = $num_posts->$status_name ?? 0;
            if ($count === 0) continue;

            $status_url = add_query_arg('post_status', $status_name, $base_url);
            $status_links[$status_name] = sprintf(
                '<a href="%s" %s>%s <span class="count">(%d)</span></a>',
                esc_url($status_url),
                ($current_status === $status_name ? 'class="current"' : ''),
                esc_html($status_object->label),
                $count
            );
        }

        // Papierkorb Link
        $trash_count = $num_posts->trash ?? 0;
        if ($trash_count > 0) {
             $trash_url = add_query_arg('post_status', 'trash', $base_url);
             $status_links['trash'] = sprintf(
                '<a href="%s" %s>%s <span class="count">(%d)</span></a>',
                esc_url($trash_url),
                ($current_status === 'trash' ? 'class="current"' : ''),
                __('Papierkorb', 'lego-wawi'),
                $trash_count
             );
        }

        return $status_links;
    }

} // Ende LWW_Jobs_List_Table


/**
 * Rendert den Inhalt des "Job-Warteschlange"-Tabs.
 */
function lww_render_tab_jobs() {
    $job_list_table = new LWW_Jobs_List_Table();
    // KORREKTUR: Bulk-Action-Verarbeitung hier ausführen, bevor die Daten abgerufen werden.
    $job_list_table->process_bulk_action();
    $job_list_table->prepare_items();

    ?>
    <div class="lww-admin-form lww-card">
    
        <div style="float: right; margin-top: -10px; margin-bottom: 10px; padding: 5px 10px; background: #f9f9f9; border: 1px solid #eee; border-radius: 4px;">
            <label>
                <input type="checkbox" id="lww-job-refresh-toggle" checked>
                <span class="dashicons dashicons-update" style="vertical-align: middle;"></span>
                <?php _e('Automatisch aktualisieren', 'lego-wawi'); ?>
            </label>
        </div>
    
        <h2><?php _e('Job-Warteschlange & Verlauf', 'lego-wawi'); ?></h2>
        <p><?php _e('Hier siehst du alle laufenden, wartenden und abgeschlossenen Import-Jobs.', 'lego-wawi'); ?></p>

        <?php settings_errors('lww_messages'); ?>
        
        <form id="jobs-filter" method="post">
            <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page'] ?? 'lww_jobs_ui'); ?>" />
            
            <div id="lww-job-list-container">
                <?php
                $job_list_table->views();
                $job_list_table->display();
                ?>
            </div>
            
        </form>
    </div>
    <?php
}


// --- Handler für Einzel-Job-Aktionen (Pausieren, Priorisieren, etc.) ---

/**
 * Verarbeitet die 'admin_post_lww_prioritize_job'-Aktion.
 */
function lww_prioritize_job_handler() {
    if (!isset($_GET['job_id']) || !isset($_GET['_wpnonce'])) return;
    $job_id = absint($_GET['job_id']);
    if (!wp_verify_nonce($_GET['_wpnonce'], 'lww_prioritize_job_' . $job_id) || !current_user_can('edit_post', $job_id)) {
        wp_die('Sicherheitsüberprüfung fehlgeschlagen.');
    }

    wp_update_post(['ID' => $job_id, 'menu_order' => 0]);
    add_settings_error('lww_messages', 'job_prioritized', __('Job wurde priorisiert.', 'lego-wawi'), 'updated');
    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=lww_jobs_ui'));
    exit;
}
add_action('admin_post_lww_prioritize_job', 'lww_prioritize_job_handler');

/**
 * Verarbeitet die 'admin_post_lww_pause_job'-Aktion.
 */
function lww_pause_job_handler() {
    if (!isset($_GET['job_id']) || !isset($_GET['_wpnonce'])) return;
    $job_id = absint($_GET['job_id']);
    if (!wp_verify_nonce($_GET['_wpnonce'], 'lww_pause_job_' . $job_id) || !current_user_can('edit_post', $job_id)) {
        wp_die('Sicherheitsüberprüfung fehlgeschlagen.');
    }

    wp_update_post(['ID' => $job_id, 'post_status' => 'lww_paused']);
    add_settings_error('lww_messages', 'job_paused', __('Job wurde pausiert.', 'lego-wawi'), 'updated');
    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=lww_jobs_ui'));
    exit;
}
add_action('admin_post_lww_pause_job', 'lww_pause_job_handler');

/**
 * Verarbeitet die 'admin_post_lww_resume_job'-Aktion.
 */
function lww_resume_job_handler() {
    if (!isset($_GET['job_id']) || !isset($_GET['_wpnonce'])) return;
    $job_id = absint($_GET['job_id']);
    if (!wp_verify_nonce($_GET['_wpnonce'], 'lww_resume_job_' . $job_id) || !current_user_can('edit_post', $job_id)) {
        wp_die('Sicherheitsüberprüfung fehlgeschlagen.');
    }

    wp_update_post(['ID' => $job_id, 'post_status' => 'lww_pending']);
    add_settings_error('lww_messages', 'job_resumed', __('Job wurde fortgesetzt und in die Warteschlange eingereiht.', 'lego-wawi'), 'updated');
    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=lww_jobs_ui'));
    exit;
}
add_action('admin_post_lww_resume_job', 'lww_resume_job_handler');


/**
 * Verarbeitet die 'admin_post_lww_cancel_job'-Aktion.
 */
function lww_cancel_job_handler() {
    if (!isset($_GET['job_id']) || !isset($_GET['_wpnonce'])) return;
    $job_id = absint($_GET['job_id']);
    $nonce_action = 'lww_cancel_job_' . $job_id;

    if (!wp_verify_nonce($_GET['_wpnonce'], $nonce_action)) {
        wp_die('Sicherheitsüberprüfung fehlgeschlagen (Nonce ungültig).');
    }
    if (!current_user_can('edit_post', $job_id)) {
         wp_die('Keine Berechtigung, diesen Job zu bearbeiten.');
    }

    wp_update_post([
        'ID' => $job_id,
        'post_status' => 'lww_failed'
    ]);
    lww_log_to_job($job_id, __('Job manuell vom Benutzer abgebrochen.', 'lego-wawi'));

    if(get_option('lww_current_running_job_id') == $job_id) {
        delete_option('lww_current_running_job_id');
        lww_log_system_event('Globale Sperre für Job ' . $job_id . ' nach manuellem Abbruch aufgehoben.');
    }

    add_settings_error('lww_messages', 'job_cancelled', __('Job erfolgreich abgebrochen.', 'lego-wawi'), 'updated');
    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect(admin_url('admin.php?page=lww_jobs_ui'));
    exit;
}
add_action('admin_post_lww_cancel_job', 'lww_cancel_job_handler');


/**
 * Verarbeitet die 'admin_post_lww_delete_job'-Aktion (verschiebt in Papierkorb).
 */
function lww_delete_job_handler() {
    if (!isset($_GET['job_id']) || !isset($_GET['_wpnonce'])) return;
    $job_id = absint($_GET['job_id']);
    $nonce_action = 'lww_delete_job_' . $job_id;

    if (!wp_verify_nonce($_GET['_wpnonce'], $nonce_action)) {
        wp_die('Sicherheitsüberprüfung fehlgeschlagen.');
    }
     if (!current_user_can('delete_post', $job_id)) {
         wp_die('Keine Berechtigung, diesen Job zu löschen.');
    }

    if (wp_trash_post($job_id)) {
        add_settings_error('lww_messages', 'job_trashed', __('Job in den Papierkorb verschoben.', 'lego-wawi'), 'updated');
    } else {
         add_settings_error('lww_messages', 'job_trash_failed', __('Fehler beim Verschieben des Jobs in den Papierkorb.', 'lego-wawi'), 'error');
    }

    set_transient('settings_errors', get_settings_errors(), 30);
    $redirect_url = wp_get_referer() ?: admin_url('admin.php?page=lww_jobs_ui');
    wp_safe_redirect($redirect_url);
    exit;
}
add_action('admin_post_lww_delete_job', 'lww_delete_job_handler');


/**
 * Handler für 'admin_post_lww_untrash_job' (Wiederherstellen aus Papierkorb).
 */
function lww_untrash_job_handler() {
    if (!isset($_GET['job_id']) || !isset($_GET['_wpnonce'])) return;
    $job_id = absint($_GET['job_id']);
    $nonce_action = 'lww_untrash_job_' . $job_id;

    if (!wp_verify_nonce($_GET['_wpnonce'], $nonce_action)) wp_die('Security check failed.');
    if (!current_user_can('delete_post', $job_id)) wp_die('No permission.');

    if (wp_untrash_post($job_id)) {
         add_settings_error('lww_messages', 'job_untrashed', __('Job wiederhergestellt.', 'lego-wawi'), 'updated');
    } else {
          add_settings_error('lww_messages', 'job_untrash_failed', __('Fehler beim Wiederherstellen des Jobs.', 'lego-wawi'), 'error');
    }

    set_transient('settings_errors', get_settings_errors(), 30);
    $redirect_url = wp_get_referer() ?: admin_url('admin.php?page=lww_jobs_ui');
    wp_safe_redirect($redirect_url);
    exit;
}
add_action('admin_post_lww_untrash_job', 'lww_untrash_job_handler');


/**
 * Handler für 'admin_post_lww_delete_permanently_job' (Endgültig löschen).
 */
function lww_delete_permanently_job_handler() {
    if (!isset($_GET['job_id']) || !isset($_GET['_wpnonce'])) return;
    $job_id = absint($_GET['job_id']);
    $nonce_action = 'lww_delete_permanently_job_' . $job_id;

    if (!wp_verify_nonce($_GET['_wpnonce'], $nonce_action)) wp_die('Security check failed.');
    if (!current_user_can('delete_post', $job_id)) wp_die('No permission.');

    if (get_post_status($job_id) !== 'trash') {
        wp_die('Job befindet sich nicht im Papierkorb.');
    }

    if (wp_delete_post($job_id, true)) { // true = Force delete
        add_settings_error('lww_messages', 'job_deleted', __('Job endgültig gelöscht.', 'lego-wawi'), 'updated');
    } else {
         add_settings_error('lww_messages', 'job_delete_failed', __('Fehler beim endgültigen Löschen des Jobs.', 'lego-wawi'), 'error');
    }

    set_transient('settings_errors', get_settings_errors(), 30);
    $redirect_url = wp_get_referer() ?: admin_url('admin.php?page=lww_jobs_ui&post_status=trash');
    wp_safe_redirect($redirect_url);
    exit;
}
add_action('admin_post_lww_delete_permanently_job', 'lww_delete_permanently_job_handler');


/**
 * =========================================================================
 * AJAX HANDLER FÜR JOB-LISTE
 * =========================================================================
 */
 
/**
 * Antwortet auf die AJAX-Anfrage von lww-admin-jobs.js.
 * Erstellt die WP_List_Table und sendet nur das HTML der Tabelle zurück.
 */
function lww_ajax_get_job_list_table() {
    // Nonce explizit mit dem vom JS gesendeten Feldnamen '_ajax_nonce' prüfen.
    if (!check_ajax_referer('lww_job_list_nonce', '_ajax_nonce', false)) {
        wp_send_json_error(['message' => __('Sicherheitsüberprüfung fehlgeschlagen (Nonce ungültig).', 'lego-wawi')], 403);
        wp_die();
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Keine Berechtigung.', 'lego-wawi')], 403);
        wp_die();
    }
    
    $job_list_table = new LWW_Jobs_List_Table();
    $job_list_table->prepare_items(); 
    
    ob_start();
    // Rendere die Teile, die aktualisiert werden sollen
    $job_list_table->views();
    $job_list_table->display(); 
    $table_html = ob_get_clean();
    
    wp_send_json_success($table_html);
    wp_die();
}
add_action('wp_ajax_lww_get_job_list_table', 'lww_ajax_get_job_list_table');

?>