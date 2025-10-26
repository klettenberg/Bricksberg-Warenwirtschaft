<?php
/**
 * Modul: Job-Warteschlange (v9.0)
 * Rendert den "Job-Warteschlange"-Tab und verwaltet die Job-Aktionen.
 * Zeigt jetzt auch den Job-Typ 'inventory_import'.
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
            'singular' => __('Job', 'lego-wawi'),    // Singular Bezeichnung des Items
            'plural'   => __('Jobs', 'lego-wawi'),   // Plural Bezeichnung der Items
            'ajax'     => false                     // Keine AJAX-Funktionalität für diese Tabelle
        ]);
    }

    /**
     * Definiert die Spalten der Tabelle.
     * @return array Assoziatives Array [Spalten-Slug => Spalten-Titel]
     */
    public function get_columns() {
        return [
            'cb'           => '<input type="checkbox" />', // Checkbox für Bulk-Aktionen
            'title'        => __('Job', 'lego-wawi'),       // Job-Titel (z.B. "Katalog-Import vom ...")
            'job_type'     => __('Typ', 'lego-wawi'),       // Art des Jobs (Katalog oder Inventar)
            'job_status'   => __('Status', 'lego-wawi'),    // Aktueller Status (Wartend, Laufend, etc.)
            'job_progress' => __('Fortschritt', 'lego-wawi'),// Letzte Log-Nachricht als Fortschrittsanzeige
            'date'         => __('Erstellt', 'lego-wawi')   // Erstellungsdatum des Jobs
        ];
    }

    /**
     * Definiert, welche Spalten sortierbar sind.
     * @return array Array [Spalten-Slug => [orderby-Parameter, initial-sort-order]]
     */
    public function get_sortable_columns() {
        return [
            'title'      => ['title', false],          // Sortieren nach Titel
            'job_type'   => ['job_type', false],       // Sortieren nach Job-Typ (über Metafeld)
            'job_status' => ['post_status', false],   // Sortieren nach Post-Status
            'date'       => ['date', true]            // Sortieren nach Datum (Standard, absteigend)
        ];
    }

    /**
     * Bereitet die Daten für die Anzeige vor (Abrufen der Jobs, Paginierung, Sortierung).
     */
    public function prepare_items() {
        // Spalten-Header definieren
        $columns = $this->get_columns();
        $hidden = []; // Keine versteckten Spalten
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];

        // Paginierungsparameter
        $per_page = 20; // Anzahl der Jobs pro Seite
        $current_page = $this->get_pagenum(); // Aktuelle Seitennummer holen
        $offset = ($current_page - 1) * $per_page; // Berechnen des Offsets für die DB-Abfrage

        // Sortierungsparameter holen (oder Standardwerte verwenden)
        $orderby = isset($_GET['orderby']) ? sanitize_key($_GET['orderby']) : 'date';
        $order = isset($_GET['order']) ? sanitize_key($_GET['order']) : 'DESC';

        // Meta-Key für Sortierung nach Job-Typ
        $meta_key_order = ($orderby === 'job_type') ? '_job_type' : '';

        // Argumente für die WP_Query zum Abrufen der Job-Posts
        $args = [
            'post_type'      => 'lww_job',                     // Nur Posts vom Typ 'lww_job'
            'posts_per_page' => $per_page,                     // Anzahl pro Seite
            'offset'         => $offset,                       // Offset für Paginierung
            'orderby'        => ($orderby === 'job_type') ? 'meta_value' : $orderby, // Sortierung nach Titel, Datum, Status oder Meta-Wert
            'order'          => $order,                        // Sortierrichtung (ASC oder DESC)
            'meta_key'       => $meta_key_order,               // Meta-Key nur setzen, wenn nach Typ sortiert wird
            'post_status'    => ['lww_pending', 'lww_running', 'lww_complete', 'lww_failed', 'trash'] // Alle relevanten Status anzeigen
        ];
        $query = new WP_Query($args);

        // Die gefundenen Posts als Items für die Tabelle setzen
        $this->items = $query->posts;

        // Paginierungs-Argumente für die WordPress-Paginierungs-Links setzen
        $total_items = $query->found_posts; // Gesamtzahl der gefundenen Jobs
        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page) // Gesamtanzahl der Seiten berechnen
        ]);
    }

    /**
     * Standard-Renderer für Spalten, falls keine spezifische Methode existiert.
     * Gibt zur Sicherheit den Inhalt des Items aus (sollte nicht vorkommen).
     */
    public function column_default($item, $column_name) {
        // Normalerweise sollte für jede Spalte eine column_{Spaltenname}-Methode existieren.
        // Diese Funktion ist ein Fallback.
        switch($column_name) {
             case 'date':
                return get_the_date('', $item); // Formatiertes Datum
             default:
                // Wenn Daten unerwartet sind, zur Sicherheit ausgeben
                // return print_r($item, true); 
                return '---'; // Oder einfach nichts
        }
    }

    /**
     * Rendert die Checkbox-Spalte für Bulk-Aktionen.
     */
    public function column_cb($item) {
        return sprintf(
            '<input type="checkbox" name="job[]" value="%s" />', $item->ID // Checkbox mit der Job-ID als Wert
        );
    }

    /**
     * Rendert die "Job"-Titel-Spalte, inklusive der Aktionen (Abbrechen, Löschen).
     */
    public function column_title($item) {
        $title = $item->post_title ? $item->post_title : __('(kein Titel)', 'lego-wawi'); // Holt den Post-Titel, Fallback
        $actions = []; // Array für die Aktions-Links

        // "Abbrechen"-Aktion nur für laufende oder wartende Jobs anzeigen
        if (in_array($item->post_status, ['lww_pending', 'lww_running'])) {
            // URL für die Abbruch-Aktion erstellen (nutzt admin-post.php für sichere Verarbeitung)
            $cancel_url = wp_nonce_url( // Fügt einen Sicherheits-Nonce hinzu
                admin_url('admin-post.php?action=lww_cancel_job&job_id=' . $item->ID), // Ziel-URL mit Job-ID
                'lww_cancel_job_' . $item->ID // Eindeutige Nonce-Aktion
            );
            $actions['cancel'] = sprintf('<a href="%s" class="lww-action-cancel">%s</a>', esc_url($cancel_url), __('Abbrechen', 'lego-wawi'));
        }

        // "Löschen"-Aktion (verschiebt in den Papierkorb) immer anzeigen
        // URL für die Lösch-Aktion erstellen
        $delete_url = wp_nonce_url(
             admin_url('admin-post.php?action=lww_delete_job&job_id=' . $item->ID),
             'lww_delete_job_' . $item->ID
        );
        // Unterschiedliche Bezeichnung je nach Status (Papierkorb vs. Endgültig löschen)
        if ($item->post_status === 'trash') {
             // Aktionen für Items im Papierkorb
             $untrash_url = wp_nonce_url(admin_url('admin-post.php?action=lww_untrash_job&job_id=' . $item->ID), 'lww_untrash_job_' . $item->ID);
             $delete_permanently_url = wp_nonce_url(admin_url('admin-post.php?action=lww_delete_permanently_job&job_id=' . $item->ID), 'lww_delete_permanently_job_' . $item->ID);
             $actions['untrash'] = sprintf('<a href="%s" class="lww-action-untrash">%s</a>', esc_url($untrash_url), __('Wiederherstellen', 'lego-wawi'));
             $actions['delete_permanently'] = sprintf('<a href="%s" class="lww-action-delete">%s</a>', esc_url($delete_permanently_url), __('Endgültig löschen', 'lego-wawi'));
        } else {
             // Aktion für Items, die nicht im Papierkorb sind
             $actions['trash'] = sprintf('<a href="%s" class="lww-action-delete">%s</a>', esc_url($delete_url), __('Papierkorb', 'lego-wawi'));
        }


        // Gibt den Titel und die darunter schwebenden Aktions-Links zurück
        return '<strong>' . esc_html($title) . '</strong>' . $this->row_actions($actions);
    }

    /**
     * Rendert die "Status"-Spalte mit farbigen Badges.
     */
    public function column_job_status($item) {
        $status = $item->post_status; // Holt den Post-Status (z.B. 'lww_running')
        $status_object = get_post_status_object($status); // Holt das Objekt mit Label etc.
        $status_label = $status_object ? $status_object->label : ucfirst(str_replace('lww_', '', $status)); // Holt das lesbare Label

        // Wählt eine CSS-Klasse basierend auf dem Status für die Farbgebung
        $status_class = 'lww-status-' . str_replace('lww_', '', $status); // z.B. 'lww-status-running'

        // Gibt das formatierte Status-Badge zurück
        return sprintf('<span class="lww-status-badge %s">%s</span>', esc_attr($status_class), esc_html($status_label));
    }

    /**
     * Rendert die "Typ"-Spalte basierend auf dem Metafeld '_job_type'.
     */
     public function column_job_type($item) {
         $type = get_post_meta($item->ID, '_job_type', true); // Holt den Job-Typ aus den Metadaten
         // Gibt eine lesbare Bezeichnung zurück
         switch($type) {
             case 'catalog_import': return __('Katalog-Import', 'lego-wawi');
             case 'inventory_import': return __('Inventar-Import', 'lego-wawi');
             default: return __('Unbekannt', 'lego-wawi');
         }
     }

     /**
     * Rendert die "Fortschritt"-Spalte, indem die letzte Log-Nachricht angezeigt wird.
     */
     public function column_job_progress($item) {
         $log = get_post_meta($item->ID, '_job_log', true); // Holt das Log-Array
         if (is_array($log) && !empty($log)) {
             $last_message = end($log); // Nimmt die letzte Nachricht aus dem Array
             // Entfernt den Zeitstempel für eine kürzere Anzeige
             $message_without_timestamp = preg_replace('/^\[.*?\]\s*/', '', $last_message);
             // Zeige nur einen Teil langer Nachrichten
             $short_message = mb_strimwidth($message_without_timestamp, 0, 70, '...');
             return '<span class="lww-progress-log" title="' . esc_attr($message_without_timestamp) . '">' . esc_html($short_message) . '</span>';
         }
         return '---'; // Wenn kein Log vorhanden ist
     }

    /**
     * Definiert Bulk-Aktionen (z.B. "Löschen").
     */
    public function get_bulk_actions() {
        $actions = [
            'bulk-trash' => __('In den Papierkorb', 'lego-wawi')
        ];
        // Wenn wir uns im Papierkorb-Filter befinden, andere Aktionen anbieten
        if (isset($_GET['post_status']) && $_GET['post_status'] === 'trash') {
             $actions = [
                'bulk-untrash' => __('Wiederherstellen', 'lego-wawi'),
                'bulk-delete-permanently' => __('Endgültig löschen', 'lego-wawi')
             ];
        }
        return $actions;
    }

    /**
     * Verarbeitet Bulk-Aktionen.
     * Wird automatisch von WP_List_Table aufgerufen.
     */
    public function process_bulk_action() {
        // Holt die aktuelle Aktion (z.B. 'bulk-trash')
        $action = $this->current_action();
        // Holt die IDs der ausgewählten Jobs
        $job_ids = isset($_REQUEST['job']) ? wp_parse_id_list($_REQUEST['job']) : [];

        // Wenn keine IDs oder keine Aktion, abbrechen
        if (empty($job_ids) || !$action) return;

        // Sicherheitsprüfung (Nonce)
        // Die Nonce wird normalerweise im Formular von WP_List_Table hinzugefügt
        // Wir sollten sie hier prüfen, um CSRF-Angriffe zu verhindern.
        $nonce_action = 'bulk-' . $this->_args['plural'];
        if (!check_admin_referer($nonce_action)) { // Prüft die Nonce
             wp_die(__('Sicherheitsüberprüfung fehlgeschlagen. Bitte versuche es erneut.', 'lego-wawi'));
        }

        $processed_count = 0;
        // Schleife durch die ausgewählten Job-IDs
        foreach ($job_ids as $job_id) {
            // Sicherstellen, dass es eine gültige ID ist
            if ($job_id <= 0) continue;

            // Prüfen, ob der Benutzer die Berechtigung hat, diesen Post zu bearbeiten/löschen
            if (!current_user_can('delete_post', $job_id)) continue;

            // Führe die entsprechende Aktion aus
            switch ($action) {
                case 'bulk-trash':
                    if (wp_trash_post($job_id)) { // Verschiebt den Post in den Papierkorb
                        $processed_count++;
                    }
                    break;
                case 'bulk-untrash':
                     if (wp_untrash_post($job_id)) { // Stellt den Post aus dem Papierkorb wieder her
                         // Optional: Status nach Wiederherstellung explizit setzen?
                         // wp_update_post(['ID' => $job_id, 'post_status' => 'lww_pending']);
                         $processed_count++;
                     }
                     break;
                 case 'bulk-delete-permanently':
                     if (wp_delete_post($job_id, true)) { // Löscht den Post endgültig
                         $processed_count++;
                     }
                     break;
            }
        }

        // Erfolgs-/Fehlermeldung anzeigen (optional)
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
             }
        }

        // Redirect nach Aktion, um erneutes Senden beim Neuladen zu verhindern
        // Baue die URL zusammen, um Filter etc. beizubehalten
        $redirect_url = remove_query_arg(['action', 'action2', 'job', '_wpnonce', '_wp_http_referer'], wp_get_referer());
        wp_safe_redirect($redirect_url);
        exit;
    }


    /**
     * Fügt Filter-Links über der Tabelle hinzu (z.B. "Alle", "Wartend", "Papierkorb").
     */
    protected function get_views() {
        $status_links = [];
        $num_posts = wp_count_posts('lww_job', 'readable'); // Zählt Posts nach Status für den aktuellen Benutzer
        $total_items = 0; // Gesamtanzahl (ohne Papierkorb) initialisieren

        // Basis-URL für die Filter-Links
        $base_url = admin_url('admin.php?page=' . LWW_PLUGIN_SLUG . '&tab=tab_jobs');

        // Alle relevanten Status durchgehen, die wir anzeigen wollen
        $relevant_statuses = ['lww_pending', 'lww_running', 'lww_complete', 'lww_failed'];
        foreach ($relevant_statuses as $status_name) {
            $status_object = get_post_status_object($status_name);
            if (!$status_object) continue; // Überspringen, falls Status nicht registriert ist

            $count = $num_posts->$status_name ?? 0;
            $total_items += $count; // Zur Gesamtsumme addieren

            $status_url = add_query_arg('post_status', $status_name, $base_url);
            $current_class = (isset($_GET['post_status']) && $_GET['post_status'] === $status_name) ? 'current' : '';

            $status_links[$status_name] = sprintf(
                '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
                esc_url($status_url),
                $current_class,
                esc_html($status_object->label), // Lesbares Label verwenden
                $count
            );
        }

        // Link "Alle" (zeigt alle außer Papierkorb)
        $all_url = remove_query_arg('post_status', $base_url);
        $current_all_class = (!isset($_GET['post_status']) || $_GET['post_status'] === '') ? 'current' : '';
         // Link "Alle" an den Anfang des Arrays stellen
        $status_links = array_merge(['all' => sprintf(
            '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
            esc_url($all_url),
            $current_all_class,
            __('Alle', 'lego-wawi'),
            $total_items
        )], $status_links);


        // Link "Papierkorb" (falls vorhanden)
        $trash_count = $num_posts->trash ?? 0;
        if ($trash_count > 0) {
             $trash_url = add_query_arg('post_status', 'trash', $base_url);
             $current_trash_class = (isset($_GET['post_status']) && $_GET['post_status'] === 'trash') ? 'current' : '';
             $status_links['trash'] = sprintf(
                '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
                esc_url($trash_url),
                $current_trash_class,
                __('Papierkorb', 'lego-wawi'),
                $trash_count
             );
        }

        return $status_links; // Gibt das Array der Filter-Links zurück
    }

} // Ende LWW_Jobs_List_Table


/**
 * Rendert den Inhalt des "Job-Warteschlange"-Tabs mithilfe der WP_List_Table.
 */
function lww_render_tab_jobs() {
    // Erstellt eine Instanz unserer Job-Tabelle
    $job_list_table = new LWW_Jobs_List_Table();
    // Bereitet die Items (Jobs) für die Anzeige vor (DB-Abfrage etc.)
    $job_list_table->prepare_items();

    ?>
    <div class="lww-admin-form lww-card">
        <h2><?php _e('Job-Warteschlange & Verlauf', 'lego-wawi'); ?></h2>
        <p><?php _e('Hier siehst du alle laufenden, wartenden und abgeschlossenen Import-Jobs.', 'lego-wawi'); ?></p>

        <?php $job_list_table->views(); // Zeigt die Filter-Links (Alle | Wartend | Laufend | ...) ?>

        <form id="jobs-filter" method="post">
            <!-- Wichtig: method="post" für Bulk Actions -->
            <!-- Versteckte Felder für die Tabelle ( Seitennummer, Tab ) -->
            <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']); ?>" />
             <input type="hidden" name="tab" value="tab_jobs" />
            <?php // Nonce wird von WP_List_Table::display() hinzugefügt, wenn Bulk Actions vorhanden sind ?>
            <?php $job_list_table->display(); // Zeigt die eigentliche Tabelle an ?>
        </form>
    </div>
    <?php
}


// --- Handler für Job-Aktionen (Abbrechen, Löschen, Wiederherstellen) ---

/**
 * Verarbeitet die 'admin_post_lww_cancel_job'-Aktion.
 * Setzt den Job-Status auf 'failed' und stoppt ggf. den Cron.
 */
function lww_cancel_job_handler() {
    // Prüft Nonce und Berechtigungen
    if (!isset($_GET['job_id']) || !isset($_GET['_wpnonce'])) return;
    $job_id = absint($_GET['job_id']);
    if (!wp_verify_nonce($_GET['_wpnonce'], 'lww_cancel_job_' . $job_id)) {
        wp_die('Sicherheitsüberprüfung fehlgeschlagen.');
    }
    if (!current_user_can('edit_post', $job_id)) { // Besser: edit_post Capability prüfen
         wp_die('Keine Berechtigung.');
    }

    // Job-Status aktualisieren
    wp_update_post([
        'ID' => $job_id,
        'post_status' => 'lww_failed' // Setzt Status auf Fehlgeschlagen
    ]);
    lww_log_to_job($job_id, __('Job manuell vom Benutzer abgebrochen.', 'lego-wawi'));

    // Cron-Job stoppen, WENN es der aktuell laufende Job ist
    $current_job_id_option = get_option('lww_current_running_job_id');
    if($current_job_id_option == $job_id) {
        // lww_stop_cron_job(); // Nicht den Cron stoppen, nur die Sperre lösen!
        delete_option('lww_current_running_job_id'); // Entfernt die Job-Sperre
    }

    // Erfolgsmeldung hinzufügen und zurück zur Job-Liste leiten
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
    // Prüft Nonce und Berechtigungen
    if (!isset($_GET['job_id']) || !isset($_GET['_wpnonce'])) return;
    $job_id = absint($_GET['job_id']);
    if (!wp_verify_nonce($_GET['_wpnonce'], 'lww_delete_job_' . $job_id)) {
        wp_die('Sicherheitsüberprüfung fehlgeschlagen.');
    }
     if (!current_user_can('delete_post', $job_id)) { // delete_post Capability
         wp_die('Keine Berechtigung.');
    }

    // Job sicher in den Papierkorb verschieben
    wp_trash_post($job_id);

    // Erfolgsmeldung und Redirect
    add_settings_error('lww_messages', 'job_trashed', __('Job in den Papierkorb verschoben.', 'lego-wawi'), 'updated');
    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect(admin_url('admin.php?page=' . LWW_PLUGIN_SLUG . '&tab=tab_jobs'));
    exit;
}
add_action('admin_post_lww_delete_job', 'lww_delete_job_handler');


/**
 * Handler für 'admin_post_lww_untrash_job' (Wiederherstellen aus Papierkorb).
 */
function lww_untrash_job_handler() {
    if (!isset($_GET['job_id']) || !isset($_GET['_wpnonce'])) return;
    $job_id = absint($_GET['job_id']);
    if (!wp_verify_nonce($_GET['_wpnonce'], 'lww_untrash_job_' . $job_id)) wp_die('Security check failed.');
    if (!current_user_can('delete_post', $job_id)) wp_die('No permission.'); // Braucht delete, um wiederherzustellen

    wp_untrash_post($job_id); // Stellt den Post wieder her (Status wird normalerweise auf vorherigen gesetzt)
    // Optional: Explizit auf 'pending' setzen, falls gewünscht
    // wp_update_post(['ID' => $job_id, 'post_status' => 'lww_pending']);

    add_settings_error('lww_messages', 'job_untrashed', __('Job wiederhergestellt.', 'lego-wawi'), 'updated');
    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect(admin_url('admin.php?page=' . LWW_PLUGIN_SLUG . '&tab=tab_jobs'));
    exit;
}
add_action('admin_post_lww_untrash_job', 'lww_untrash_job_handler');


/**
 * Handler für 'admin_post_lww_delete_permanently_job' (Endgültig löschen).
 */
function lww_delete_permanently_job_handler() {
    if (!isset($_GET['job_id']) || !isset($_GET['_wpnonce'])) return;
    $job_id = absint($_GET['job_id']);
    if (!wp_verify_nonce($_GET['_wpnonce'], 'lww_delete_permanently_job_' . $job_id)) wp_die('Security check failed.');
    if (!current_user_can('delete_post', $job_id)) wp_die('No permission.');

    wp_delete_post($job_id, true); // Endgültig aus der DB löschen

    add_settings_error('lww_messages', 'job_deleted', __('Job endgültig gelöscht.', 'lego-wawi'), 'updated');
    set_transient('settings_errors', get_settings_errors(), 30);
    // Zurück zum Papierkorb-View, da der Job jetzt weg ist
    wp_safe_redirect(admin_url('admin.php?page=' . LWW_PLUGIN_SLUG . '&tab=tab_jobs&post_status=trash'));
    exit;
}
add_action('admin_post_lww_delete_permanently_job', 'lww_delete_permanently_job_handler');
?>