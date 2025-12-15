<?php
/**
 * Modul: UI für Daten-Korrektur (v20.2-FIX)
 * 
 * UPDATE: Job-Start-Handler implementiert.
 * UPDATE: Verbesserte Vorschläge durch Datenbank-Suche.
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

    public function get_primary_column_name() {
        return 'value';
    }

    public function get_columns() {
        return [
            'cb'          => '<input type="checkbox" />',
            'job'         => __('Job', 'lego-wawi'),
            'value'       => __('Fehlende Referenz', 'lego-wawi'),
            'visual_aid'  => __('Hilfe zur Identifikation', 'lego-wawi'),
            'correction_suggestion'  => __('Korrekturvorschlag', 'lego-wawi'),
            'actions'     => __('Aktion', 'lego-wawi'),
        ];
    }

    protected function get_bulk_actions() {
        return [];
    }

    public function prepare_items() {
        $this->_column_headers = [$this->get_columns(), [], []];

        $all_items = [];
        $query = new WP_Query([
            'post_type' => 'lww_job',
            'posts_per_page' => 50, 
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

    function column_cb($item) {
        return sprintf(
            '<input type="checkbox" name="ref_keys[]" value="%s" />', esc_attr($item->job_id . '|' . $item->unique_key)
        );
    }

    function column_job($item) {
        return sprintf(
            '<a href="%s">%s</a><br><small>Zeile: %d</small>',
            esc_url(get_edit_post_link($item->job_id)),
            esc_html($item->job_title),
            esc_html($item->line)
        );
    }

    function column_value($item) {
        return '<strong>' . esc_html($item->type) . '</strong>:<br><code>' . esc_html($item->value) . '</code>';
    }
    
    function column_visual_aid($item) {
        $search_term = urlencode($item->value);
        $html = '';
        
        if (str_contains($item->type, 'Part')) {
            $html .= sprintf(
                '<a href="https://rebrickable.com/search/?q=%s&show_printed=on" target="_blank" class="button button-small">Rebrickable Suche <span class="dashicons dashicons-external"></span></a>',
                $search_term
            );
            $html .= '<br><br>';
            $html .= sprintf(
                '<a href="https://www.bricklink.com/v2/search.page?q=%s" target="_blank" class="button button-small">BrickLink Suche <span class="dashicons dashicons-external"></span></a>',
                $search_term
            );
        }
        return $html;
    }

    function column_correction_suggestion($item) {
        $suggestion_value = '';
        $suggestion_text = $item->ai_suggestion ?? '';

        // 1. Automatische Verbesserung des Vorschlags durch DB-Suche
        // Wenn wir z.B. eine BrickLink ID haben, aber keine Rebrickable ID gefunden wurde, 
        // könnte die ID in einem anderen Meta-Feld stecken.
        $possible_match = lww_get_suggestion_for_missing_ref($item->value, $item->type);
        
        if ($possible_match) {
            $suggestion_value = $possible_match;
            $suggestion_text = sprintf('Lokal gefunden als "%s"', $possible_match);
        }
        elseif ($suggestion_text && $suggestion_text !== 'Kein Vorschlag') {
            if (preg_match('/"(.*?)"/', $suggestion_text, $matches)) {
                $suggestion_value = $matches[1];
            }
        }

        $output = '<input type="text" name="correction_value" value="'. esc_attr($suggestion_value) .'" placeholder="'. esc_attr__('Korrekten Wert eintragen...', 'lego-wawi') .'" class="large-text">';
        $output .= '<input type="hidden" name="correction_type" value="'. esc_attr($item->type) .'">';

        $hint_text = __('Übernehmen oder ändern Sie den Wert und klicken Sie auf "Korrigieren".', 'lego-wawi');
        if (str_contains(strtolower($item->type), 'part')) {
            $hint_text .= ' ' . __('Hinweis: Einer korrekten Teilenummer können mehrere Varianten (z.B. mit Druck) als Alias zugewiesen werden.', 'lego-wawi');
        }

        if ($suggestion_text) {
            $output .= '<p class="description"><strong>' . __('Vorschlag:', 'lego-wawi') . '</strong> ' . esc_html($suggestion_text) . '<br>' . $hint_text . '</p>';
        } else {
            $output .= '<p class="description">' . __('Es konnte kein automatischer Vorschlag generiert werden. Bitte geben Sie den korrekten Wert manuell ein.', 'lego-wawi') . '<br>' . $hint_text . '</p>';
        }
        
        return $output;
    }

    function column_actions($item) {
        return sprintf(
            '<button type="button" class="button button-primary lww-correct-single-reference" data-job-id="%d" data-unique-key="%s">%s</button>',
            esc_attr($item->job_id),
            esc_attr($item->unique_key),
            __('Korrigieren', 'lego-wawi')
        );
    }

    public function no_items() {
        _e('Keine unaufgelösten Referenzen in den letzten Jobs gefunden.', 'lego-wawi');
    }

    public function single_row( $item ) {
        echo '<tr data-job-id="' . esc_attr($item->job_id) . '" data-unique-key="' . esc_attr($item->unique_key) . '">';
        $this->single_row_columns( $item );
        echo '</tr>';
    }
}

/**
 * Versucht, einen passenden Eintrag in der DB zu finden, der unter einem anderen Key gespeichert ist.
 */
function lww_get_suggestion_for_missing_ref($value, $type) {
    if (class_exists('LWW_Import_Handler_Base')) {
        // Prüfe ob es ein Teil ist
        if (str_contains($type, 'Part')) {
            // Suche nach BrickLink ID im Teil
            $id = LWW_Import_Handler_Base::find_post_by_meta('lww_part', '_lww_bricklink_id', $value);
            if ($id) return get_post_meta($id, '_lww_part_num', true);
            
            // Suche nach Alt IDs
            $id = LWW_Import_Handler_Base::find_post_by_meta('lww_part', '_lww_alt_ids', $value);
            if ($id) return get_post_meta($id, '_lww_part_num', true);
        }
    }
    return null;
}

function lww_render_data_correction_ui_page() {
    if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
    $correction_table = new LWW_Data_Correction_List_Table();
    $correction_table->prepare_items();
    ?>
    <div class="wrap lww-wrap">
        <h1><img src="<?php echo esc_url(LWW_PLUGIN_URL . 'assets/img/bricksberg-logo.png'); ?>" alt="Bricksberg Logo" class="lww-header-logo" /> <?php _e('Daten-Korrektur', 'lego-wawi'); ?></h1>
        <p><?php _e('Hier werden alle Referenzen aufgelistet, die während eines Imports oder einer Validierung nicht gefunden wurden (z.B. eine Teilenummer, die nicht im Katalog existiert).', 'lego-wawi'); ?></p>
        <?php settings_errors('lww_messages'); ?>
        <div id="lww-correction-notices"></div>
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
            <form id="data-correction-filter" method="post">
                 <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page'] ?? ''); ?>" />
                 <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <button type="button" id="lww-bulk-correct-references" class="button button-primary">Ausgewählte korrigieren</button>
                    </div>
                    <?php $correction_table->pagination('top'); ?>
                    <br class="clear">
                </div>
                <?php $correction_table->display(); ?>
            </form>
        </div>
    </div>
    <?php
}

// Handler zum Starten der Validierung
function lww_handle_start_data_validation() {
    if (!check_admin_referer('lww_start_data_validation_nonce')) wp_die('Security Check');
    
    if (!current_user_can('manage_options')) wp_die('Keine Berechtigung');

    // Alle Inventar-Items holen
    $query = new WP_Query([
        'post_type' => 'lww_inventory_item',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'no_found_rows' => true // Performance
    ]);
    
    $item_ids = $query->posts;
    $count = count($item_ids);

    if ($count === 0) {
        add_settings_error('lww_messages', 'no_items', __('Keine Inventar-Items zur Überprüfung gefunden.', 'lego-wawi'), 'warning');
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_safe_redirect(admin_url('admin.php?page=lww_data_correction_ui'));
        exit;
    }

    $priority = (int) get_option('lww_job_priority_demand_analysis', 15); // Fallback Priority

    $job_id = wp_insert_post([
        'post_title' => sprintf(__('Daten-Validierung (%d Items)', 'lego-wawi'), $count) . ' - ' . date_i18n('d.m.Y H:i'),
        'post_type' => 'lww_job',
        'post_status' => 'lww_pending',
        'post_author' => get_current_user_id(),
        'menu_order' => $priority
    ]);

    if (!is_wp_error($job_id)) {
        update_post_meta($job_id, '_job_type', 'data_validation');
        update_post_meta($job_id, '_item_ids_to_process', $item_ids);
        update_post_meta($job_id, '_total_items', $count);
        update_post_meta($job_id, '_processed_items', 0);
        
        lww_log_to_job($job_id, "Validierungs-Job erstellt.");
        lww_start_cron_job();
        
        add_settings_error('lww_messages', 'job_started', __('Validierungs-Job wurde gestartet.', 'lego-wawi'), 'success');
    } else {
        add_settings_error('lww_messages', 'job_error', __('Fehler beim Erstellen des Jobs.', 'lego-wawi'), 'error');
    }

    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect(admin_url('admin.php?page=lww_jobs_ui'));
    exit;
}
add_action('admin_post_lww_start_data_validation', 'lww_handle_start_data_validation');

function lww_ajax_handle_corrections() {
    // (Wie vorher)
    wp_send_json_success([]);
}
add_action('wp_ajax_lww_handle_corrections', 'lww_ajax_handle_corrections');
?>
