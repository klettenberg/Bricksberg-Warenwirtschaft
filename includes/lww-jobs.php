<?php
/**
 * Modul: Job-Warteschlange UI (v30.2-STATS)
 * 
 * Bietet eine detaillierte, responsive und stilvolle Ansicht der Hintergrundjobs.
 * UPDATE: Verbesserte Stats-Anzeige (Prozent/Zeit) auch für wartende Jobs.
 */
if (!defined('ABSPATH')) exit;

if (!class_exists('WP_List_Table')) require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');

// Hooks
add_action('admin_post_lww_job_control', 'lww_handle_job_control_action');
add_action('wp_ajax_lww_get_job_list_table', 'lww_ajax_get_job_list_table_handler');
add_action('wp_ajax_lww_get_job_log_content', 'lww_ajax_get_job_log_content_handler');

class LWW_Jobs_List_Table extends WP_List_Table {
    private $status_filter = [];

    public function __construct($status_filter = []) {
        parent::__construct([
            'singular' => __('Job', 'lego-wawi'),
            'plural' => __('Jobs', 'lego-wawi'),
            'ajax' => false
        ]);
        $this->status_filter = $status_filter;
    }

    public function get_columns() {
        return [
            'job_details' => __('Job-Details', 'lego-wawi'), 
            'stats' => __('Statistik & Laufzeit', 'lego-wawi'),
            'status_visual' => __('Status', 'lego-wawi'),
            'actions' => __('Steuerung', 'lego-wawi'),
        ];
    }

    public function prepare_items() {
        $this->_column_headers = [$this->get_columns(), [], []];
        $per_page = 15;
        $current_page = $this->get_pagenum();

        $args = [
            'post_type' => 'lww_job',
            'posts_per_page' => $per_page,
            'paged' => $current_page,
            'orderby' => 'date',
            'order' => 'DESC',
            'post_status' => $this->status_filter
        ];

        $query = new WP_Query($args);
        $this->items = $query->posts;
        $this->set_pagination_args(['total_items' => $query->found_posts, 'per_page' => $per_page]);
    }

    function column_job_details($item) {
        $job_type = get_post_meta($item->ID, '_job_type', true);
        $info = get_post_meta($item->ID, '_current_processing_info', true);
        $log_url = add_query_arg(['action' => 'lww_get_job_log_content', 'job_id' => $item->ID, 'TB_iframe' => 'true', 'width' => 800, 'height' => 600], admin_url('admin-ajax.php'));
        
        $type_badge_color = '#64748b';
        if(strpos($job_type, 'import') !== false) $type_badge_color = '#00a32a'; // Green
        elseif(strpos($job_type, 'sync') !== false) $type_badge_color = '#2271b1'; // Blue
        elseif(strpos($job_type, 'scan') !== false) $type_badge_color = '#d63638'; // Red

        $out = '<div class="lww-job-card-main">';
        $out .= sprintf('<span class="lww-type-badge" style="background-color:%s">%s</span>', $type_badge_color, esc_html(strtoupper(str_replace('_', ' ', $job_type))));
        $out .= '<strong>' . esc_html($item->post_title) . '</strong>';
        if ($info) {
            $out .= '<div class="lww-job-info-line"><span class="dashicons dashicons-marker"></span> ' . esc_html($info) . '</div>';
        }
        $out .= sprintf('<div class="lww-job-meta"><a href="%s" class="thickbox">Protokoll ansehen</a></div>', esc_url($log_url));
        $out .= '</div>';
        
        return $out;
    }

    function column_stats($item) {
        $processed = (int) get_post_meta($item->ID, '_processed_items', true);
        $total = (int) get_post_meta($item->ID, '_total_items', true);
        $start = (int) get_post_meta($item->ID, '_job_start_ts', true);
        $end = (int) get_post_meta($item->ID, '_job_end_ts', true);
        
        $duration = '-';
        if ($start) {
            $end_time = $end ? $end : time();
            $diff = $end_time - $start;
            $duration = gmdate("H:i:s", $diff);
        }

        $percent = ($total > 0) ? min(100, round(($processed / $total) * 100)) : 0;
        if ($item->post_status === 'lww_complete') $percent = 100;

        $out = '<div class="lww-job-stats-col">';
        
        // Anzeige auch wenn 0%, solange der Job läuft oder wartet
        $out .= sprintf('<div class="lww-progress-container"><div class="lww-progress-bar" style="width:%d%%"></div></div>', $percent);
        $out .= sprintf('<span class="lww-progress-text">%d / %d (%d%%)</span>', $processed, $total, $percent);
        
        $out .= sprintf('<div class="lww-runtime"><span class="dashicons dashicons-clock"></span> %s</div>', $duration);
        $out .= '</div>';
        return $out;
    }

    function column_status_visual($item) {
        $status = $item->post_status;
        $map = [
            'lww_pending' => ['label' => 'WARTEND', 'cls' => 'st-pending'],
            'lww_running' => ['label' => 'AKTIV', 'cls' => 'st-running'],
            'lww_paused'  => ['label' => 'PAUSE', 'cls' => 'st-paused'],
            'lww_complete'=> ['label' => 'FERTIG', 'cls' => 'st-complete'],
            'lww_failed'  => ['label' => 'FEHLER', 'cls' => 'st-failed'],
        ];
        $s = $map[$status] ?? ['label' => $status, 'cls' => ''];
        
        $dot = ($status === 'lww_running') ? '<span class="lww-pulse-dot"></span>' : '';
        
        return sprintf('<span class="lww-status-pill %s">%s %s</span>', $s['cls'], $dot, $s['label']);
    }

    function column_actions($item) {
        $nonce = wp_create_nonce('lww_job_control_' . $item->ID);
        $base = admin_url('admin-post.php?action=lww_job_control&job_id=' . $item->ID . '&_wpnonce=' . $nonce);
        $status = $item->post_status;

        $actions = '';
        if (in_array($status, ['lww_pending', 'lww_paused'])) {
            $actions .= sprintf('<a href="%s&cmd=run" class="button button-primary lww-icon-btn" title="Start"><span class="dashicons dashicons-controls-play"></span></a> ', $base);
        }
        if ($status === 'lww_running') {
            $actions .= sprintf('<a href="%s&cmd=pause" class="button button-secondary lww-icon-btn" title="Pause"><span class="dashicons dashicons-controls-pause"></span></a> ', $base);
        }
        
        if ($status !== 'lww_running') {
             $actions .= sprintf('<a href="%s&cmd=restart" class="button button-secondary lww-icon-btn" title="Neustart"><span class="dashicons dashicons-update"></span></a> ', $base);
             $actions .= sprintf('<a href="%s&cmd=delete" class="button button-link-delete lww-icon-btn" title="Löschen" onclick="return confirm(\'Löschen?\');"><span class="dashicons dashicons-trash"></span></a> ', $base);
        } else {
             $actions .= sprintf('<a href="%s&cmd=cancel" class="button button-secondary lww-icon-btn" title="Abbruch"><span class="dashicons dashicons-no"></span></a> ', $base);
        }

        return $actions;
    }
}

function lww_render_tab_jobs() {
    if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
    add_thickbox(); 
    
    echo '<div class="wrap lww-wrap">';
    echo '<h1>Job-Warteschlange & Prozess-Monitor</h1>';
    
    echo '<div class="lww-job-toolbar">';
    echo '<span id="lww-job-loading-indicator" class="dashicons dashicons-update" style="display:none; margin-right: 10px; color: var(--lww-accent);"></span>';
    echo '<label class="lww-switch"><input type="checkbox" id="lww-job-refresh-toggle" checked><span class="slider round"></span></label> <span class="lww-switch-label">Live-Update</span>';
    echo '<select id="lww-job-refresh-rate" style="margin-left:10px;">';
    echo '<option value="3000">3 Sek.</option>';
    echo '<option value="5000" selected>5 Sek.</option>';
    echo '<option value="10000">10 Sek.</option>';
    echo '</select>';
    echo '</div>';
    
    echo '<div id="lww-job-list-container">';
    lww_render_job_tables_html();
    echo '</div></div>';
}

function lww_render_job_tables_html() {
    $active = new LWW_Jobs_List_Table(['lww_pending', 'lww_running', 'lww_paused']);
    $active->prepare_items();
    
    $history = new LWW_Jobs_List_Table(['lww_complete', 'lww_failed']);
    $history->prepare_items();

    if (count($active->items) > 0) {
        echo '<div class="lww-card lww-jobs-card active-jobs">';
        echo '<h2><span class="dashicons dashicons-admin-settings spin"></span> Aktive Prozesse</h2>';
        $active->display();
        echo '</div>';
    }

    echo '<div class="lww-card lww-jobs-card history-jobs">';
    echo '<h2><span class="dashicons dashicons-calendar-alt"></span> Verlauf</h2>';
    $history->display();
    echo '</div>';
}

function lww_ajax_get_job_list_table_handler() {
    check_ajax_referer('lww_job_list_nonce');
    ob_start(); lww_render_job_tables_html(); $html = ob_get_clean();
    wp_send_json_success($html);
}

function lww_handle_job_control_action() {
    $job_id = isset($_GET['job_id']) ? absint($_GET['job_id']) : 0;
    if (!$job_id || !check_admin_referer('lww_job_control_' . $job_id)) wp_die('Security');
    $cmd = sanitize_key($_GET['cmd']);
    $new = '';
    if($cmd == 'run') { 
        wp_update_post(['ID'=>$job_id, 'post_status'=>'lww_running']); 
        wp_remote_post(admin_url('admin-ajax.php'), ['blocking'=>false, 'body'=>['action'=>'lww_trigger_batch_process'], 'cookies'=>$_COOKIE]);
    }
    elseif($cmd == 'pause') $new = 'lww_paused';
    elseif($cmd == 'cancel') $new = 'lww_failed';
    elseif($cmd == 'restart') { 
        $new = 'lww_pending'; 
        update_post_meta($job_id, '_processed_items', 0);
        update_post_meta($job_id, '_processed_rows', 0);
    }
    elseif($cmd == 'delete') { wp_delete_post($job_id, true); wp_safe_redirect(admin_url('admin.php?page=lww_jobs_ui')); exit; }

    if($new) wp_update_post(['ID'=>$job_id, 'post_status'=>$new]);
    wp_safe_redirect(admin_url('admin.php?page=lww_jobs_ui')); exit;
}

function lww_ajax_get_job_log_content_handler() {
    $job_id = absint($_GET['job_id']);
    $job = get_post($job_id);
    $logs = get_post_meta($job_id, '_job_log', true);
    echo '<div style="padding:20px; font-family:monospace;"><h3>Protokoll: '.esc_html($job->post_title).'</h3>';
    echo '<div style="background:#23282d; color:#eee; padding:15px; height:400px; overflow-y:auto;">';
    if(is_array($logs)) foreach(array_reverse($logs) as $l) echo '<div>'.esc_html($l).'</div>';
    echo '</div></div>';
    wp_die();
}
?>