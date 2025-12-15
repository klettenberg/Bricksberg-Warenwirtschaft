<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Try to include importer class if present (optional)
$bricklink_importer_path = LWW_PLUGIN_PATH . 'includes/importers/bricklink-importer.php';
if (file_exists($bricklink_importer_path)) {
    require_once $bricklink_importer_path;
}

// --- New: Load demand analyzer if present ---
$demand_analyzer_path = LWW_PLUGIN_PATH . 'includes/analysis/demand-analyzer.php';
if (file_exists($demand_analyzer_path)) {
    require_once $demand_analyzer_path;
}

/**
 * Enqueue Chart.js + dashboard script only on our plugin dashboard.
 */
function lww_enqueue_dashboard_assets($hook) {
    if (!isset($_GET['page']) || $_GET['page'] !== LWW_PLUGIN_SLUG) {
        return;
    }

    wp_enqueue_script('lww-chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', [], null, true);
    wp_register_script('lww-dashboard-js', LWW_PLUGIN_URL . 'assets/js/lww-dashboard.js', ['lww-chartjs', 'jquery'], null, true);
    wp_enqueue_script('lww-dashboard-js');

    // Localize for AJAX and nonce
    $nonce = wp_create_nonce('lww_dashboard');
    wp_localize_script('lww-dashboard-js', 'lwwDashboard', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => $nonce,
    ]);
}
add_action('admin_enqueue_scripts', 'lww_enqueue_dashboard_assets');

/**
 * Temporarily allow XML mime type for wp_handle_upload.
 */
function lww_allow_xml_mime($mimes) {
    $mimes['xml'] = 'application/xml';
    return $mimes;
}

/**
 * Render import form and chart canvas inside admin notices region (mapped to admin dashboard page)
 */
function lww_render_dashboard_ui() {
    if (!isset($_GET['page']) || $_GET['page'] !== LWW_PLUGIN_SLUG) {
        return;
    }
    ?>
    <div id="lww-dashboard-graphic-wrapper" style="margin: 20px 0;">
        <h3><?php echo esc_html__('Import Statistik', 'lego-wawi'); ?></h3>
        <form id="lww-import-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" style="margin-bottom: 1em;">
            <?php wp_nonce_field('lww_import_bricklink', 'lww_import_bricklink_nonce'); ?>
            <input type="hidden" name="action" value="lww_import_bricklink_inventory" />
            <label for="lww_bricklink_file" style="display:block; margin-bottom: .5em;"><?php echo esc_html__('Bricklink XML Datei auswählen', 'lego-wawi'); ?></label>
            <input type="file" name="lww_bricklink_file" id="lww_bricklink_file" accept=".xml" style="display:inline-block; margin-right: .5em;" required />
            <input type="submit" class="button button-primary" value="<?php echo esc_attr__('Import starten', 'lego-wawi'); ?>" />
        </form>

        <div style="width:100%; max-width:900px; height:320px; border:1px solid #eee; padding:10px;">
            <canvas id="lww-dashboard-canvas" width="800" height="300" style="width:100%; height:100%;"></canvas>
        </div>

        <!-- Demand analyzer summary -->
        <div id="lww-demand-analysis" style="margin-top:12px; font-size:0.95rem; color:#333;"></div>
    </div>
    <?php
}
add_action('admin_notices', 'lww_render_dashboard_ui');

/**
 * Display the import result set in transient by the import handler.
 */
function lww_display_import_result_admin_notice() {
    if (!is_admin()) {
        return;
    }
    $result = get_transient('lww_bricklink_import_result');
    if ($result === false) {
        return;
    }

    delete_transient('lww_bricklink_import_result');

    $class = (isset($result['success']) && $result['success']) ? 'notice notice-success' : 'notice notice-error';
    $message = isset($result['message']) ? $result['message'] : __('Unbekanntes Ergebnis.', 'lego-wawi');

    echo '<div class="' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
}
add_action('admin_notices', 'lww_display_import_result_admin_notice');

/**
 * AJAX: Provide chart data for the dashboard
 */
function lww_get_dashboard_data_ajax() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Keine Berechtigung', 'lego-wawi'), 403);
    }
    $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : '';
    if (!wp_verify_nonce($nonce, 'lww_dashboard')) {
        wp_send_json_error(__('Ungültiger Nonce', 'lego-wawi'), 403);
    }

    $history = get_option('lww_import_history', []);
    if (empty($history)) {
        $labels = [];
        $data = [];
    } else {
        $labels = array_map(function ($entry) {
            return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $entry['time']);
        }, $history);

        $data = array_map(function ($entry) {
            return (int)$entry['count'];
        }, $history);
    }

    wp_send_json_success(['labels' => $labels, 'data' => $data]);
}
add_action('wp_ajax_lww_get_dashboard_data', 'lww_get_dashboard_data_ajax');

/**
 * New AJAX: Provide demand analysis data for the dashboard
 */
function lww_get_demand_analysis_ajax() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Keine Berechtigung', 'lego-wawi'), 403);
    }
    $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : '';
    if (!wp_verify_nonce($nonce, 'lww_dashboard')) {
        wp_send_json_error(__('Ungültiger Nonce', 'lego-wawi'), 403);
    }

    $history = get_option('lww_import_history', []);
    if (!class_exists('LWW_Demand_Analyzer')) {
        // If the analyzer is not present, return a simple empty response
        wp_send_json_success(['analysis' => null]);
    }

    $analysis = LWW_Demand_Analyzer::analyze_from_history($history, 3);
    wp_send_json_success(['analysis' => $analysis, 'summary' => LWW_Demand_Analyzer::summary_text($analysis)]);
}
add_action('wp_ajax_lww_get_demand_analysis', 'lww_get_demand_analysis_ajax');

/**
 * Admin POST handler: Validate upload, parse Bricklink XML & update import history
 */
function lww_handle_bricklink_import() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Insufficient permissions to perform import.', 'lego-wawi'));
    }

    // Nonce check (CSRF protection)
    if (!isset($_POST['lww_import_bricklink_nonce']) || !check_admin_referer('lww_import_bricklink', 'lww_import_bricklink_nonce')) {
        $msg = __('Ungültiger oder fehlender Sicherheits-Nonce.', 'lego-wawi');
        set_transient('lww_bricklink_import_result', ['success' => false, 'message' => $msg], 30);
        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    if (empty($_FILES['lww_bricklink_file']) || $_FILES['lww_bricklink_file']['error'] !== UPLOAD_ERR_OK) {
        $msg = __('Keine Datei hochgeladen oder Upload-Fehler.', 'lego-wawi');
        set_transient('lww_bricklink_import_result', ['success' => false, 'message' => $msg], 30);
        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    $file = $_FILES['lww_bricklink_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'xml') {
        $msg = __('Ungültiger Dateityp. Bitte eine .xml Datei hochladen.', 'lego-wawi');
        set_transient('lww_bricklink_import_result', ['success' => false, 'message' => $msg], 30);
        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    if (!class_exists('Bricksberg_Bricklink_Importer')) {
        // If importer missing, respond gracefully
        $msg = __('Importer-Klasse nicht verfügbar.', 'lego-wawi');
        set_transient('lww_bricklink_import_result', ['success' => false, 'message' => $msg], 30);
        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    // Temporarily allow XML upload MIME and use wp_handle_upload
    add_filter('upload_mimes', 'lww_allow_xml_mime');
    $movefile = wp_handle_upload($file, ['test_form' => false]);
    remove_filter('upload_mimes', 'lww_allow_xml_mime');

    if (isset($movefile['error'])) {
        $msg = __('Datei konnte nicht hochgeladen werden: ', 'lego-wawi') . $movefile['error'];
        set_transient('lww_bricklink_import_result', ['success' => false, 'message' => $msg], 30);
        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    $uploadPath = $movefile['file'] ?? $file['tmp_name'];

    $importer = new Bricksberg_Bricklink_Importer();
    $result = $importer->importFile($uploadPath);

    // Cleanup uploaded file if present
    if (isset($movefile['file']) && file_exists($movefile['file'])) {
        @unlink($movefile['file']);
    }

    if (is_wp_error($result)) {
        $message = $result->get_error_message();
        set_transient('lww_bricklink_import_result', ['success' => false, 'message' => $message], 30);
        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    // Count items and build message
    $count = isset($result['items']) ? count($result['items']) : 0;
    $errors = isset($result['errors']) ? $result['errors'] : [];

    $details = [
        'items' => $result['items'] ?? [],
        'errors' => $errors,
    ];

    $msg = sprintf(__('Import erfolgreich: %d Einträge eingelesen.', 'lego-wawi'), $count);
    if (!empty($errors)) {
        // Append a short summary of item errors
        $msg .= ' ' . sprintf(_n('%d Fehler erkannt.', '%d Fehler erkannt.', count($errors), 'lego-wawi'), count($errors));
    }

    set_transient('lww_bricklink_import_result', ['success' => true, 'message' => $msg, 'details' => $details], 30);

    wp_safe_redirect(wp_get_referer() ?: admin_url());
    exit;
}

// Only register admin_post export handler if in admin area
add_action('admin_post_lww_import_bricklink_inventory', 'lww_handle_bricklink_import');
