<?php
/**
 * Modul: Nachfrageanalyse & KI (v13.0)
 *
 * Stellt Funktionen zur Analyse der Marktnachfrage für Inventarartikel bereit.
 * Implementiert eine (simulierte) KI-Anbindung und den zugehörigen Hintergrund-Job.
 */
if (!defined('ABSPATH')) exit;

/**
 * Berechnet den Nachfrage-Score für einen einzelnen Inventarartikel.
 *
 * HINWEIS: Dies ist eine SIMULATION. Anstatt einen echten, teuren API-Call
 * zu machen, wird der Prompt geloggt und ein zufälliger Score zurückgegeben.
 * In einer Produktivumgebung würde hier der echte API-Request stattfinden.
 *
 * @param int $item_id Die Post-ID des lww_inventory_item.
 * @return int|WP_Error Den berechneten Score (1-100) oder ein WP_Error Objekt.
 */
function lww_calculate_demand_score_for_item($item_id) {
    $api_settings = get_option('lww_api_settings');
    $ai_provider = get_option('lww_ai_provider', 'openai');
    $api_key = '';
    $simulated_cost = 0.0015; // Standardkosten für eine Anfrage

    if ($ai_provider === 'openai') {
        $api_key = $api_settings['openai_api_key'] ?? '';
    } elseif ($ai_provider === 'gemini') {
        $api_key = $api_settings['gemini_api_key'] ?? '';
        $simulated_cost = 0.0010; // Gemini ist vielleicht günstiger
    }

    if (empty($api_key)) {
        $error_msg = sprintf(__('Kein API-Schlüssel für den ausgewählten Anbieter (%s) konfiguriert.', 'lego-wawi'), strtoupper($ai_provider));
        lww_log_api_call($ai_provider, 'demand_analysis', false, 0, ['item_id' => $item_id, 'error' => 'No API Key']);
        return new WP_Error('no_api_key', $error_msg);
    }

    // Daten für den Prompt sammeln
    $part_id = get_post_meta($item_id, '_lww_part_id', true);
    $color_id = get_post_meta($item_id, '_lww_color_id', true);

    if (!$part_id || !$color_id) {
        return new WP_Error('missing_data', sprintf(__('Verknüpftes Teil oder Farbe für Item %d nicht gefunden.', 'lego-wawi'), $item_id));
    }

    $part_name = get_the_title($part_id);
    $part_num = get_post_meta($part_id, '_lww_part_num', true);
    $color_name = get_the_title($color_id);

    // KI-Prompt erstellen
    $prompt = sprintf(
        'Bewerte die aktuelle Marktnachfrage für das folgende LEGO-Teil auf einer Skala von 1 (sehr niedrig) bis 100 (sehr hoch). Antworte nur mit der Zahl.\nTeilename: %s\nTeilenummer: %s\nFarbe: %s',
        $part_name,
        $part_num,
        $color_name
    );

    // --- ECHTER API CALL WÜRDE HIER ERFOLGEN ---
    // $response = wp_remote_post('https://api.openai.com/...', [...]);

    // --- SIMULATION ---
    lww_log_system_event(sprintf('SIMULATION (%s): Sende folgenden Prompt für Item %d:\n%s', strtoupper($ai_provider), $item_id, $prompt));
    $simulated_score = rand(1, 100);
    $success = true;
    // --- ENDE SIMULATION ---

    // Logge den API-Aufruf
    lww_log_api_call($ai_provider, 'demand_analysis', $success, $simulated_cost, ['item_id' => $item_id, 'score' => $simulated_score]);

    if ($success && is_numeric($simulated_score) && $simulated_score >= 1 && $simulated_score <= 100) {
        update_post_meta($item_id, '_lww_demand_score', $simulated_score);
        return (int) $simulated_score;
    } else {
        return new WP_Error('invalid_api_response', __('Ungültige Antwort von der KI-API erhalten.', 'lego-wawi'));
    }
}

/**
 * Handler für den admin-post-Request zum Starten der Analyse.
 * Erstellt einen neuen Job vom Typ 'demand_analysis'.
 */
function lww_handle_run_demand_analysis_tool() {
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'lww_demand_analysis_nonce')) {
        wp_die(__('Sicherheitsüberprüfung fehlgeschlagen.', 'lego-wawi'));
    }
    if (!current_user_can('manage_options')) {
        wp_die(__('Keine Berechtigung.', 'lego-wawi'));
    }

    // API-Schlüssel vorab prüfen
    $api_settings = get_option('lww_api_settings');
    $ai_provider = get_option('lww_ai_provider', 'openai');
    $api_key = ($ai_provider === 'openai') ? ($api_settings['openai_api_key'] ?? '') : ($api_settings['gemini_api_key'] ?? '');

    if (empty($api_key)) {
        add_settings_error('lww_messages', 'api_key_missing', __('Der Job konnte nicht gestartet werden, da der API-Schlüssel für den ausgewählten KI-Anbieter fehlt.', 'lego-wawi'), 'error');
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_safe_redirect(admin_url('admin.php?page=lww_analysis_ui'));
        exit;
    }

    $mode = isset($_POST['analysis_mode']) ? sanitize_key($_POST['analysis_mode']) : 'missing';

    $meta_query = [
        [
            'key' => '_lww_demand_score',
            'compare' => 'NOT EXISTS'
        ]
    ];

    if ($mode === 'all') {
        $meta_query = []; // Leeres Meta-Query, um alle zu erfassen
    }

    // Finde alle relevanten Inventar-Items
    $query = new WP_Query([
        'post_type' => 'lww_inventory_item',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'meta_query' => $meta_query
    ]);

    $item_ids_to_process = $query->posts;

    if (empty($item_ids_to_process)) {
        add_settings_error('lww_messages', 'no_items_for_analysis', __('Alle relevanten Inventarartikel haben bereits einen Nachfrage-Score. Es gibt nichts zu tun.', 'lego-wawi'), 'info');
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_safe_redirect(admin_url('admin.php?page=lww_analysis_ui'));
        exit;
    }

    $priority = (int) get_option('lww_job_priority_demand_analysis', 15);

    // Job erstellen
    $job_id = wp_insert_post([
        'post_title'   => sprintf(__('Nachfrageanalyse für %d Artikel (%s)', 'lego-wawi'), count($item_ids_to_process), $mode === 'all' ? 'alle' : 'fehlende'),
        'post_type'    => 'lww_job',
        'post_status'  => 'lww_pending',
        'post_author'  => get_current_user_id(),
        'menu_order'   => $priority, 
    ], true);

    if (is_wp_error($job_id)) {
        add_settings_error('lww_messages', 'job_creation_failed', __('Fehler beim Erstellen des Analyse-Jobs: ', 'lego-wawi') . $job_id->get_error_message(), 'error');
    } else {
        update_post_meta($job_id, '_job_type', 'demand_analysis');
        update_post_meta($job_id, '_item_ids_to_process', $item_ids_to_process);
        update_post_meta($job_id, '_total_items', count($item_ids_to_process));
        update_post_meta($job_id, '_processed_items', 0);
        lww_log_to_job($job_id, sprintf('Job erstellt. %d Artikel zur Analyse in der Warteschlange.', count($item_ids_to_process)));

        lww_start_cron_job();
        add_settings_error('lww_messages', 'job_created', __('Neuer Analyse-Job wurde erfolgreich erstellt und zur Warteschlange hinzugefügt.', 'lego-wawi'), 'success');
    }

    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect(admin_url('admin.php?page=lww_jobs_ui'));
    exit;
}
add_action('admin_post_lww_run_demand_analysis', 'lww_handle_run_demand_analysis_tool');

?>