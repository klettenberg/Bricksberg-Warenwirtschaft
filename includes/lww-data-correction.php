<?php
/**
 * Modul: UI für Daten-Korrektur
 *
 * Rendert den Inhalt für den "Daten-Korrektur"-Tab und listet alle
 * nicht aufgelösten Referenzen aus den Import-Jobs auf.
 */
if (!defined('ABSPATH')) exit;

// Stellt sicher, dass die WP_List_Table Klasse geladen ist
if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * LWW_Data_Correction_List_Table Klasse
 */
class LWW_Data_Correction_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct([
            'singular' => __('Fehlerhafte Referenz', 'lego-wawi'),
            'plural'   => __('Fehlerhafte Referenzen', 'lego-wawi'),
            'ajax'     => false
        ]);
    }

    public function get_columns() {
        return [
            'cb'          => '<input type="checkbox" />',
            'job'         => __('Job', 'lego-wawi'),
            'context'     => __('Datei / Kontext', 'lego-wawi'),
            'line'        => __('Zeile', 'lego-wawi'),
            'type'        => __('Referenz-Typ', 'lego-wawi'),
            'value'       => __('Fehlerhafter Wert', 'lego-wawi'),
            'suggestion'  => __('Vorschlag', 'lego-wawi'),
            'actions'     => __('Aktionen', 'lego-wawi'),
        ];
    }

    public function prepare_items() {
        $this->_column_headers = [$this->get_columns(), [], []];

        $all_items = [];
        $query = new WP_Query([
            'post_type' => 'lww_job',
            'posts_per_page' => 50, // Nur die letzten 50 Jobs prüfen
            'post_status' => ['lww_complete', 'lww_failed'],
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => [
                [
                    'key' => '_unresolved_references',
                    'compare' => 'EXISTS'
                ]
            ]
        ]);

        if ($query->have_posts()) {
            foreach ($query->posts as $job_post) {
                $unresolved_refs = get_post_meta($job_post->ID, '_unresolved_references', true);
                if (is_array($unresolved_refs)) {
                    foreach ($unresolved_refs as $key => $ref) {
                        $ref['job_id'] = $job_post->ID;
                        $ref['job_title'] = $job_post->post_title;
                        $ref['unique_key'] = $key;
                        $all_items[] = (object)$ref;
                    }
                }
            }
        }

        $per_page = 25;
        $current_page = $this->get_pagenum();
        $total_items = count($all_items);

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page
        ]);

        $this->items = array_slice($all_items, (($current_page - 1) * $per_page), $per_page);
    }

    function column_default($item, $column_name) {
        return isset($item->$column_name) ? esc_html($item->$column_name) : '---';
    }
    
    function column_cb($item) {
        return sprintf(
            '<input type="checkbox" name="ref_keys[]" value="%s" />', esc_attr($item->job_id . '|' . $item->unique_key)
        );
    }

    function column_job($item) {
        return sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=lww_jobs_ui&post_status=all')),
            esc_html($item->job_title)
        );
    }

    function column_value($item) {
        return '<code>' . esc_html($item->value) . '</code>';
    }

    function column_suggestion($item) {
        if ($item->ai_suggestion && $item->ai_suggestion !== 'Kein Vorschlag') {
            return '<strong class="lww-suggestion-text">' . esc_html($item->ai_suggestion) . '</strong>';
        }
        return '<em>' . esc_html($item->ai_suggestion) . '</em>';
    }

    function column_actions($item) {
        // Platzhalter für zukünftige interaktive Aktionen
        return '<button class="button button-small" disabled>' . __('Korrigieren', 'lego-wawi') . '</button>';
    }

    public function no_items() {
        _e('Keine unaufgelösten Referenzen in den letzten Jobs gefunden.', 'lego-wawi');
    }
}

/**
 * Rendert den Inhalt des "Daten-Korrektur"-Tabs.
 */
function lww_render_data_correction_ui_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Sie haben keine Berechtigung, auf diese Seite zuzugreifen.', 'lego-wawi'));
    }

    $correction_table = new LWW_Data_Correction_List_Table();
    $correction_table->prepare_items();
    ?>
    <div class="wrap lww-wrap">
        <h1><img src="<?php echo esc_url(LWW_PLUGIN_URL . 'assets/img/bricksberg-logo.png'); ?>" alt="Bricksberg Logo" class="lww-header-logo" /> <?php _e('Daten-Korrektur', 'lego-wawi'); ?></h1>
        <p><?php _e('Hier werden alle Referenzen aufgelistet, die während eines Imports oder einer Validierung nicht gefunden wurden (z.B. eine Teilenummer, die nicht im Katalog existiert).', 'lego-wawi'); ?></p>

        <?php settings_errors('lww_messages'); ?>

        <div class="lww-card lww-mt-20">
            <form action="admin-post.php" method="post">
                <input type="hidden" name="action" value="lww_start_data_validation">
                <?php wp_nonce_field('lww_start_data_validation_nonce'); ?>
                <?php submit_button(__('Vollständige Daten-Validierung starten', 'lego-wawi'), 'primary'); ?>
                <p class="description"><?php _e('Startet einen Hintergrundjob, der dein gesamtes Inventar auf fehlende Verknüpfungen zum Katalog prüft.', 'lego-wawi'); ?></p>
            </form>
        </div>

        <div class="lww-card lww-mt-20">
            <h2><?php _e('Gefundene Probleme', 'lego-wawi'); ?></h2>
            <form id="data-correction-filter" method="get">
                <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page'] ?? ''); ?>" />
                <?php $correction_table->display(); ?>
            </form>
        </div>
    </div>
    <?php
}

/**
 * Handler zum Starten des Daten-Validierungs-Jobs.
 */
function lww_handle_start_data_validation() {
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'lww_start_data_validation_nonce')) {
        wp_die(__('Sicherheitsüberprüfung fehlgeschlagen.', 'lego-wawi'));
    }
    if (!current_user_can('manage_options')) {
        wp_die(__('Keine Berechtigung.', 'lego-wawi'));
    }

    // Zähle die Gesamtzahl der zu prüfenden Artikel für die Fortschrittsanzeige
    $total_items_query = new WP_Query([
        'post_type' => 'lww_inventory_item',
        'post_status' => 'publish',
        'fields' => 'ids',
        'posts_per_page' => -1,
    ]);
    $total_items = $total_items_query->post_count;

    $priority = (int) get_option('lww_job_priority_data_validation', 25);

    $job_id = wp_insert_post([
        'post_title'   => __('Vollständige Daten-Validierung', 'lego-wawi') . ' - ' . date_i18n('d.m.Y H:i'),
        'post_type'    => 'lww_job',
        'post_status'  => 'lww_pending',
        'post_author'  => get_current_user_id(),
        'menu_order'   => $priority, 
    ], true);

    if (is_wp_error($job_id)) {
        add_settings_error('lww_messages', 'job_creation_failed', __('Fehler beim Erstellen des Validierungs-Jobs: ', 'lego-wawi') . $job_id->get_error_message(), 'error');
    } else {
        update_post_meta($job_id, '_job_type', 'data_validation');
        update_post_meta($job_id, '_processed_items', 0);
        update_post_meta($job_id, '_processed_page', 0); // Zähler für die Paginierung initialisieren
        update_post_meta($job_id, '_total_items', $total_items); // Gesamtzahl für Fortschrittsanzeige speichern
        lww_log_to_job($job_id, sprintf('Job zur Daten-Validierung für %d Artikel erstellt.', $total_items));
        lww_start_cron_job();
        add_settings_error('lww_messages', 'job_created', __('Neuer Job zur Daten-Validierung wurde erfolgreich erstellt.', 'lego-wawi'), 'success');
    }

    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect(admin_url('admin.php?page=lww_jobs_ui'));
    exit;
}
add_action('admin_post_lww_start_data_validation', 'lww_handle_start_data_validation');

?>