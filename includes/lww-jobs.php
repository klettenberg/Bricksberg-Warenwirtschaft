<?php
/**
 * Modul: Job-Warteschlange (v9.5 - Mit AJAX Refresh & Progress)
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
            'job_progress' => __('Fortschritt', 'lego-wawi'), // Angepasste Anzeige
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
            // Sortierung nach Metafeld _job_type muss via pre_get_posts gehandhabt werden (siehe unten)
            'job_type'   => ['job_type', false],
            'job_status' => ['post_status', false],
            'date'       => ['date', true] // Standard Sortierung
        ];
    }

    /**
     * Bereitet die Daten für die Anzeige vor (Abrufen der Jobs, Paginierung, Sortierung).
     * Wird sowohl für den initialen Ladevorgang als auch für AJAX-Anfragen verwendet.
     */
    public function prepare_items() {
        // Spalten-Header definieren
        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];

        // Bulk Actions verarbeiten (nur bei POST-Requests, nicht bei AJAX)
        // Die Nonce-Prüfung erfolgt in process_bulk_action selbst
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
             $this->process_bulk_action();
        }


        // Paginierungsparameter (aus $_REQUEST holen, da es auch per AJAX kommen kann)
        $per_page = 20;
        $current_page = $this->get_pagenum(); // Diese Funktion nutzt $_REQUEST['paged']
        $offset = ($current_page - 1) * $per_page;

        // Sortierungsparameter (aus $_REQUEST holen)
        $orderby = isset($_REQUEST['orderby']) ? sanitize_key($_REQUEST['orderby']) : 'date';
        $order = isset($_REQUEST['order']) && in_array(strtoupper($_REQUEST['order']), ['ASC', 'DESC']) ? strtoupper($_REQUEST['order']) : 'DESC';

        // Filterparameter (aus $_REQUEST holen)
        $post_status_filter = isset($_REQUEST['post_status']) ? sanitize_key($_REQUEST['post_status']) : '';

        // Argumente für die WP_Query
        $args = [
            'post_type'      => 'lww_job',
            'posts_per_page' => $per_page,
            'offset'         => $offset,
            'orderby'        => $orderby,
            'order'          => $order,
            // Standardmäßig alle relevanten Status anzeigen, außer wenn gefiltert
            'post_status'    => ($post_status_filter && $post_status_filter !== 'all')
                                ? $post_status_filter
                                : ['lww_pending', 'lww_running', 'lww_complete', 'lww_failed', 'trash']
        ];

        // Spezielle Sortierung nach Metafeld '_job_type'
        // Muss hier angepasst werden, da pre_get_posts bei AJAX nicht zuverlässig greift
        if ($orderby === 'job_type') {
             $args['meta_key'] = '_job_type';
             $args['orderby'] = 'meta_value';
        }


        // Die Abfrage ausführen
        $query = new WP_Query($args);
        $this->items = $query->posts;

        // Paginierungs-Argumente setzen
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
                // Zeige das Erstellungsdatum an
                 return mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item->post_date );
             default:
                // Normalerweise sollte hier nichts ausgegeben werden
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
        $page_slug = LWW_PLUGIN_SLUG; // Slug der Hauptseite
        $actions = [];

        // Basis-URL für Aktionen
        $base_action_url = admin_url('admin-post.php');

        // URL zum Job-Log (Detailansicht) - Optional, falls man eine Detailseite baut
        // $view_log_url = admin_url('post.php?post=' . $item->ID . '&action=edit');
        // $actions['view_log'] = sprintf('<a href="%s">%s</a>', esc_url($view_log_url), __('Details/Log', 'lego-wawi'));

        // "Abbrechen"-Aktion für laufende oder wartende Jobs
        if (in_array($item->post_status, ['lww_pending', 'lww_running'])) {
            $cancel_nonce_action = 'lww_cancel_job_' . $item->ID;
            $cancel_url = wp_nonce_url(add_query_arg(['action' => 'lww_cancel_job', 'job_id' => $item->ID], $base_action_url), $cancel_nonce_action);
            $actions['cancel'] = sprintf('<a href="%s" class="lww-action-cancel" onclick="return confirm(\'%s\');">%s</a>',
                esc_url($cancel_url),
                esc_js(__('Möchtest du diesen Job wirklich abbrechen? Er wird als fehlgeschlagen markiert.', 'lego-wawi')),
                __('Abbrechen', 'lego-wawi')
            );
        }

        // Aktionen basierend auf dem Papierkorb-Status
        if ($item->post_status === 'trash') {
             // Im Papierkorb: Wiederherstellen & Endgültig löschen
             $untrash_nonce_action = 'lww_untrash_job_' . $item->ID;
             $untrash_url = wp_nonce_url(add_query_arg(['action' => 'lww_untrash_job', 'job_id' => $item->ID], $base_action_url), $untrash_nonce_action);
             $actions['untrash'] = sprintf('<a href="%s" class="lww-action-untrash">%s</a>', esc_url($untrash_url), __('Wiederherstellen', 'lego-wawi'));

             $delete_perm_nonce_action = 'lww_delete_permanently_job_' . $item->ID;
             $delete_permanently_url = wp_nonce_url(add_query_arg(['action' => 'lww_delete_permanently_job', 'job_id' => $item->ID], $base_action_url), $delete_perm_nonce_action);
             $actions['delete_permanently'] = sprintf('<a href="%s" class="lww-action-delete" onclick="return confirm(\'%s\');">%s</a>',
                esc_url($delete_permanently_url),
                esc_js(__('Möchtest du diesen Job wirklich endgültig löschen? Diese Aktion kann nicht rückgängig gemacht werden.', 'lego-wawi')),
                __('Endgültig löschen', 'lego-wawi')
             );
        } else {
             // Nicht im Papierkorb: In Papierkorb verschieben
             $trash_nonce_action = 'lww_delete_job_' . $item->ID;
             $trash_url = wp_nonce_url(add_query_arg(['action' => 'lww_delete_job', 'job_id' => $item->ID], $base_action_url), $trash_nonce_action);
             $actions['trash'] = sprintf('<a href="%s" class="lww-action-delete">%s</a>', esc_url($trash_url), __('Papierkorb', 'lego-wawi'));
        }


        // Gibt den Titel und die Aktions-Links zurück
        return '<strong>' . esc_html($title) . '</strong>' . $this->row_actions($actions);
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
             default: return esc_html($type ?: __('Unbekannt', 'lego-wawi'));
         }
     }

     /**
      * Rendert die "Fortschritt"-Spalte mit Zeilenzahlen und letzter Log-Nachricht. (NEU)
      */
     public function column_job_progress($item) {
         $log = get_post_meta($item->ID, '_job_log', true);
         $job_queue = get_post_meta($item->ID, '_job_queue', true);
         // Stellt sicher, dass task_index ein gültiger Integer ist, Default 0
         $task_index = max(0, (int)get_post_meta($item->ID, '_current_task_index', true));
         $status = $item->post_status;

         $output = '';
         $last_message = (is_array($log) && !empty($log)) ? end($log) : '';
         // Zeitstempel für die Anzeige entfernen
         $message_without_timestamp = preg_replace('/^\[.*?\]\s*/', '', $last_message);

         // Statusabhängige Anzeige
         switch ($status) {
             case 'lww_pending':
                 $output = '<em>' . __('Wartet auf Start...', 'lego-wawi') . '</em>';
                 break;

             case 'lww_running':
                 if (is_array($job_queue) && isset($job_queue[$task_index])) {
                     $current_task = $job_queue[$task_index];
                     $file_key = $current_task['key'] ?? __('Unbekannt', 'lego-wawi');
                     $rows_processed = isset($current_task['rows_processed']) ? (int)$current_task['rows_processed'] : 0;
                     // total_rows wird erst am Ende der Task gesetzt
                     $total_rows = isset($current_task['total_rows']) ? (int)$current_task['total_rows'] : 0;
                     $total_tasks = count($job_queue);

                     $output = sprintf(
                         '<strong>%s %d/%d: %s</strong><br>',
                         __('Aufgabe', 'lego-wawi'),
                         $task_index + 1, // Index ist 0-basiert
                         $total_tasks,
                         esc_html(ucwords(str_replace('_', ' ', $file_key))) // Macht z.B. aus 'inventory_parts' -> 'Inventory Parts'
                     );

                     // Verarbeitete Zeilen (Datenzeilen, ohne Header)
                     $processed_data_rows = max(0, $rows_processed - 1);

                     $output .= sprintf(
                         '<span class="row-count">%d %s</span>',
                         $processed_data_rows,
                         __('Zeilen verarbeitet', 'lego-wawi')
                     );

                     // Gesamtzahl nur anzeigen, wenn die Aufgabe abgeschlossen ist (und total_rows gesetzt wurde)
                     if (($current_task['status'] ?? '') === 'complete' && $total_rows > 0) {
                          $output .= sprintf(' / %d Gesamt', $total_rows);
                     } elseif ($total_rows > 0) {
                         // Optional: Fortschrittsbalken oder %-Anzeige, wenn total_rows geschätzt werden könnte
                         // $percentage = round(($processed_data_rows / $total_rows) * 100);
                         // $output .= sprintf(' (%d%%)', $percentage);
                     }

                     // Füge die letzte Log-Nachricht hinzu (gekürzt)
                      if ($message_without_timestamp) {
                          $short_message = mb_strimwidth($message_without_timestamp, 0, 70, '...');
                          $output .= '<br><small class="lww-last-log" title="' . esc_attr($message_without_timestamp) . '">' . esc_html($short_message) . '</small>';
                      }

                 } else {
                     // Fallback, wenn Queue-Daten fehlen
                     $output = '<em>' . __('Verarbeite...', 'lego-wawi') . '</em>';
                     if ($message_without_timestamp) {
                           $short_message = mb_strimwidth($message_without_timestamp, 0, 70, '...');
                           $output .= '<br><small class="lww-last-log" title="' . esc_attr($message_without_timestamp) . '">' . esc_html($short_message) . '</small>';
                     }
                 }
                 break;

             case 'lww_complete':
                  $output = '<span style="color: green;">' . __('Abgeschlossen', 'lego-wawi') . '</span>';
                  // Zeige die allerletzte Log-Nachricht (oft "Job abgeschlossen")
                  if ($message_without_timestamp) {
                      $output .= '<br><small class="lww-last-log">' . esc_html($message_without_timestamp) . '</small>';
                  }
                 break;

             case 'lww_failed':
                  $output = '<span style="color: red;">' . __('Fehlgeschlagen', 'lego-wawi') . '</span>';
                   // Zeige die letzte Log-Nachricht (oft die Fehlermeldung)
                  if ($message_without_timestamp) {
                       $short_message = mb_strimwidth($message_without_timestamp, 0, 100, '...'); // Etwas länger bei Fehlern
                       $output .= '<br><small class="lww-last-log" title="' . esc_attr($message_without_timestamp) . '">' . esc_html($short_message) . '</small>';
                  }
                 break;

             case 'trash':
                 $output = '<em>' . __('Papierkorb', 'lego-wawi') . '</em>';
                 break;

             default:
                 $output = '--- (' . esc_html($status) . ')'; // Unbekannten Status anzeigen
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
        // Andere Aktionen im Papierkorb-View
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
        // Stelle sicher, dass IDs als Array übergeben werden
        $job_ids = isset($_REQUEST['job']) ? (array) $_REQUEST['job'] : [];
        $job_ids = array_map('absint', $job_ids); // Nur positive IDs zulassen
        $job_ids = array_filter($job_ids); // Leere Einträge entfernen

        if (empty($job_ids) || !$action || strpos($action, 'bulk-') !== 0) {
            return;
        }

        // Korrekte Nonce-Aktion für Bulk Actions ist 'bulk-{$plural}'
        $nonce_action = 'bulk-' . $this->_args['plural'];
        if (!check_admin_referer($nonce_action)) {
             wp_die(__('Sicherheitsüberprüfung fehlgeschlagen. Bitte versuche es erneut.', 'lego-wawi'));
        }

        $processed_count = 0;
        $redirect_needed = false; // Flag, um Redirect nur bei Erfolg auszulösen

        foreach ($job_ids as $job_id) {
            // Berechtigungsprüfung für jeden einzelnen Post
            switch ($action) {
                case 'bulk-trash':
                    if (current_user_can('delete_post', $job_id) && wp_trash_post($job_id)) {
                        $processed_count++;
                    }
                    break;
                case 'bulk-untrash':
                     // Man braucht 'delete_post' Rechte, um aus dem Papierkorb wiederherzustellen
                     if (current_user_can('delete_post', $job_id) && wp_untrash_post($job_id)) {
                         $processed_count++;
                     }
                     break;
                 case 'bulk-delete-permanently':
                      // Striktere Prüfung: 'delete_post' UND Post muss im Papierkorb sein
                     if (current_user_can('delete_post', $job_id) && get_post_status($job_id) === 'trash' && wp_delete_post($job_id, true)) {
                         $processed_count++;
                     }
                     break;
            }
        }

        // Nachricht anzeigen und Weiterleitung
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
                 $redirect_needed = true; // Nur weiterleiten, wenn etwas passiert ist
             }
        } else {
             // Optional: Fehlermeldung, wenn nichts verarbeitet wurde
             add_settings_error('lww_messages', 'bulk_action_failed', __('Keine Jobs für die ausgewählte Aktion verarbeitet (evtl. fehlende Berechtigungen?).', 'lego-wawi'), 'error');
             set_transient('settings_errors', get_settings_errors(), 30);
             $redirect_needed = true; // Auch hier weiterleiten, um die Meldung anzuzeigen
        }

        // Redirect nach der Aktion, um Resubmit zu verhindern
        if ($redirect_needed) {
            // Baue die URL zusammen, um Filter etc. beizubehalten
            // wp_get_referer() ist nicht immer zuverlässig, besser die aktuelle URL nehmen
            $current_url = add_query_arg();
            // Entferne Aktionsparameter
            $redirect_url = remove_query_arg(['action', 'action2', 'job', '_wpnonce', '_wp_http_referer'], $current_url);
            wp_safe_redirect($redirect_url);
            exit;
        }
    }


    /**
     * Fügt Filter-Links über der Tabelle hinzu (Alle, Wartend, Laufend, ...).
     */
    protected function get_views() {
        $status_links = [];
        // Zählt alle Jobs, unabhängig vom Status, lesbar für den aktuellen Benutzer
        $num_posts = wp_count_posts('lww_job', 'readable');
        $total_items = 0; // Gesamt (ohne Papierkorb)

        // Basis-URL für die Filter-Links
        $base_url = admin_url('admin.php?page=' . LWW_PLUGIN_SLUG . '&tab=tab_jobs');

        // Alle registrierten LWW-Status durchgehen
        $lww_statuses = ['lww_pending', 'lww_running', 'lww_complete', 'lww_failed'];
        foreach ($lww_statuses as $status_name) {
            $status_object = get_post_status_object($status_name);
            if (!$status_object) continue;

            $count = $num_posts->$status_name ?? 0;
            $total_items += $count; // Zur Gesamtsumme (ohne Papierkorb) addieren

            $status_url = add_query_arg('post_status', $status_name, $base_url);
            // Prüft den aktuellen Filter im $_REQUEST
            $current_class = (isset($_REQUEST['post_status']) && $_REQUEST['post_status'] === $status_name) ? 'current' : '';

            $status_links[$status_name] = sprintf(
                '<a href="%s" %s>%s <span class="count">(%d)</span></a>',
                esc_url($status_url),
                ($current_class ? 'class="current"' : ''), // Füge Klasse nur hinzu, wenn 'current'
                esc_html($status_object->label),
                $count
            );
        }

        // Link "Alle" (zeigt alle außer Papierkorb)
        $all_url = remove_query_arg('post_status', $base_url);
        // "Alle" ist aktiv, wenn kein post_status gesetzt ist ODER er 'all' ist
        $current_all_class = (!isset($_REQUEST['post_status']) || $_REQUEST['post_status'] === '' || $_REQUEST['post_status'] === 'all') ? 'current' : '';
        $status_links = array_merge(['all' => sprintf( // Füge "Alle" am Anfang ein
            '<a href="%s" %s>%s <span class="count">(%d)</span></a>',
            esc_url($all_url),
            ($current_all_class ? 'class="current"' : ''),
            __('Alle', 'lego-wawi'),
            $total_items
        )], $status_links);


        // Link "Papierkorb" (falls Jobs im Papierkorb sind)
        $trash_count = $num_posts->trash ?? 0;
        if ($trash_count > 0) {
             $trash_url = add_query_arg('post_status', 'trash', $base_url);
             $current_trash_class = (isset($_REQUEST['post_status']) && $_REQUEST['post_status'] === 'trash') ? 'current' : '';
             $status_links['trash'] = sprintf(
                '<a href="%s" %s>%s <span class="count">(%d)</span></a>',
                esc_url($trash_url),
                ($current_trash_class ? 'class="current"' : ''),
                __('Papierkorb', 'lego-wawi'),
                $trash_count
             );
        }

        return $status_links;
    }

} // Ende LWW_Jobs_List_Table


/**
 * Rendert den Inhalt des "Job-Warteschlange"-Tabs. (NEU mit AJAX-Wrapper & Toggle)
 */
function lww_render_tab_jobs() {
    // Erstellt eine Instanz unserer Job-Tabelle
    $job_list_table = new LWW_Jobs_List_Table();
    // Bereitet die Items (Jobs) für die Anzeige vor (DB-Abfrage etc.)
    // Wichtig: prepare_items() muss hier aufgerufen werden für den initialen Ladevorgang
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

        <?php $job_list_table->views(); // Zeigt die Filter-Links (Alle | Wartend | Laufend | ...) ?>

        <form id="jobs-filter" method="post">
            <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page'] ?? LWW_PLUGIN_SLUG); ?>" />
            <input type="hidden" name="tab" value="tab_jobs" />
            
            <div id="lww-job-list-container">
                <?php
                // Hier wird die Tabelle initial ausgegeben.
                // AJAX ersetzt später den Inhalt dieses DIVs.
                $job_list_table->display();
                ?>
            </div>
            
        </form>
    </div>
    <?php
}


// --- Handler für Einzel-Job-Aktionen (Abbrechen, Löschen, Wiederherstellen) ---

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
    if (!current_user_can('edit_post', $job_id)) { // edit_post reicht, um Status zu ändern
         wp_die('Keine Berechtigung, diesen Job zu bearbeiten.');
    }

    // Job-Status auf 'failed' setzen
    wp_update_post([
        'ID' => $job_id,
        'post_status' => 'lww_failed'
    ]);
    lww_log_to_job($job_id, __('Job manuell vom Benutzer abgebrochen.', 'lego-wawi'));

    // Globale Sperre aufheben, WENN dieser Job sie hatte
    if(get_option('lww_current_running_job_id') == $job_id) {
        delete_option('lww_current_running_job_id');
        lww_log_system_event('Globale Sperre für Job ' . $job_id . ' nach manuellem Abbruch aufgehoben.');
    }

    // Erfolgsmeldung und Redirect zur Job-Liste
    add_settings_error('lww_messages', 'job_cancelled', __('Job erfolgreich abgebrochen.', 'lego-wawi'), 'updated');
    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect(admin_url('admin.php?page=' . LWW_PLUGIN_SLUG . '&tab=tab_jobs'));
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

    // Sicher in den Papierkorb verschieben
    if (wp_trash_post($job_id)) {
        add_settings_error('lww_messages', 'job_trashed', __('Job in den Papierkorb verschoben.', 'lego-wawi'), 'updated');
    } else {
         add_settings_error('lww_messages', 'job_trash_failed', __('Fehler beim Verschieben des Jobs in den Papierkorb.', 'lego-wawi'), 'error');
    }

    set_transient('settings_errors', get_settings_errors(), 30);
    // Redirect zur Referrer-URL oder zur Job-Liste
    $redirect_url = wp_get_referer() ?: admin_url('admin.php?page=' . LWW_PLUGIN_SLUG . '&tab=tab_jobs');
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
    // Man braucht 'delete_post' Rechte, um wiederherzustellen
    if (!current_user_can('delete_post', $job_id)) wp_die('No permission.');

    if (wp_untrash_post($job_id)) {
         add_settings_error('lww_messages', 'job_untrashed', __('Job wiederhergestellt.', 'lego-wawi'), 'updated');
         // Optional: Status explizit auf 'pending' setzen? Hängt davon ab, was wp_untrash_post macht.
         // wp_update_post(['ID' => $job_id, 'post_status' => 'lww_pending']);
    } else {
          add_settings_error('lww_messages', 'job_untrash_failed', __('Fehler beim Wiederherstellen des Jobs.', 'lego-wawi'), 'error');
    }

    set_transient('settings_errors', get_settings_errors(), 30);
    // Redirect zur Referrer-URL (vermutlich der Papierkorb-Ansicht) oder zur Job-Liste
    $redirect_url = wp_get_referer() ?: admin_url('admin.php?page=' . LWW_PLUGIN_SLUG . '&tab=tab_jobs');
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

    // Nur aus dem Papierkorb endgültig löschen
    if (get_post_status($job_id) !== 'trash') {
        wp_die('Job befindet sich nicht im Papierkorb.');
    }

    if (wp_delete_post($job_id, true)) { // true = Force delete
        add_settings_error('lww_messages', 'job_deleted', __('Job endgültig gelöscht.', 'lego-wawi'), 'updated');
    } else {
         add_settings_error('lww_messages', 'job_delete_failed', __('Fehler beim endgültigen Löschen des Jobs.', 'lego-wawi'), 'error');
    }

    set_transient('settings_errors', get_settings_errors(), 30);
    // Zurück zum Papierkorb-View oder zur Job-Liste
    $redirect_url = wp_get_referer() ?: admin_url('admin.php?page=' . LWW_PLUGIN_SLUG . '&tab=tab_jobs&post_status=trash');
    wp_safe_redirect($redirect_url);
    exit;
}
add_action('admin_post_lww_delete_permanently_job', 'lww_delete_permanently_job_handler');


/**
 * =========================================================================
 * AJAX HANDLER FÜR JOB-LISTE (NEU)
 * =========================================================================
 */
 
/**
 * Antwortet auf die AJAX-Anfrage von lww-admin-jobs.js.
 * Erstellt die WP_List_Table und sendet nur das HTML der Tabelle zurück.
 */
function lww_ajax_get_job_list_table() {
    // 1. Sicherheit prüfen: Nonce verifizieren
    // Der Nonce-Name muss mit dem im wp_localize_script übereinstimmen
    if (!check_ajax_referer('lww_job_list_nonce', false, false)) { // false = keine Ausgabe bei Fehler
        wp_send_json_error(['message' => __('Sicherheitsüberprüfung fehlgeschlagen (Nonce ungültig).', 'lego-wawi')], 403); // 403 Forbidden
        wp_die(); // Wichtig: Beendet die Ausführung
    }
    
    // 2. Berechtigungen prüfen
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Keine Berechtigung.', 'lego-wawi')], 403);
        wp_die();
    }
    
    // 3. WP_List_Table neu erstellen
    // WICHTIG: Die List Table liest die $_REQUEST-Parameter (Sortierung, Filter, Seite),
    // die wir im JS mitgeschickt haben.
    $job_list_table = new LWW_Jobs_List_Table();
    // Bereite die Items vor (holt Daten basierend auf $_REQUEST)
    $job_list_table->prepare_items(); 
    
    // 4. HTML der Tabelle per Output Buffering abfangen
    ob_start();
    // Nur die Tabelle selbst ausgeben, ohne Filter etc.
    $job_list_table->display(); 
    $table_html = ob_get_clean();
    
    // 5. HTML als Erfolgs-Antwort im 'data'-Feld zurücksenden
    wp_send_json_success($table_html);
    wp_die(); // Wichtig: AJAX-Handler immer mit wp_die() beenden
}
// Hook für den AJAX-Aufruf (wp_ajax_{action})
add_action('wp_ajax_lww_get_job_list_table', 'lww_ajax_get_job_list_table');

?>
