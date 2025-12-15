<?php
/**
 * Modul: Nachfrageanalyse & KI (v14.0)
 *
 * Stellt Funktionen zur Analyse der Marktnachfrage für Inventarartikel bereit.
 * Implementiert die KI-Anbindung und den zugehörigen Hintergrund-Job.
 */
if (!defined('ABSPATH')) exit;

/**
 * Berechnet den Nachfrage-Score für einen einzelnen Inventarartikel via KI-API.
 *
 * @param int $item_id Die Post-ID des lww_inventory_item.
 * @return int|WP_Error Den berechneten Score (1-100) oder ein WP_Error Objekt.
 */
function lww_calculate_demand_score_for_item($item_id) {
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

    $result = lww_execute_ai_demand_analysis($item_id, 'demand_analysis_item', $prompt);

    if (!is_wp_error($result)) {
        update_post_meta($item_id, '_lww_demand_score', $result);
    }

    return $result;
}

/**
 * Berechnet den Nachfrage-Score für ein einzelnes Set via KI-API.
 *
 * @param int $set_id Die Post-ID des lww_set.
 * @return int|WP_Error Den berechneten Score (1-100) oder ein WP_Error Objekt.
 */
function lww_calculate_demand_score_for_set($set_id) {
    $set_name = get_the_title($set_id);
    $set_num = get_post_meta($set_id, '_lww_set_num', true);
    $theme_list = strip_tags(get_the_term_list($set_id, 'lww_theme', '', ', ', ''));
    $num_parts = get_post_meta($set_id, '_lww_num_parts', true);
    $year = get_post_meta($set_id, '_lww_year_released', true);

    $prompt = sprintf(
        'Bewerte die aktuelle Marktnachfrage für das folgende LEGO Set auf einer Skala von 1 (sehr niedrig) bis 100 (sehr hoch). Berücksichtige dabei Thema, Alter, Teileanzahl und ob es ein Sammlerstück ist. Antworte nur mit der Zahl.\nSet-Name: %s\nSet-Nummer: %s\nThema: %s\nTeileanzahl: %s\nErscheinungsjahr: %s',
        $set_name,
        $set_num,
        $theme_list,
        $num_parts,
        $year
    );

    $result = lww_execute_ai_demand_analysis($set_id, 'demand_analysis_set', $prompt);

    if (!is_wp_error($result)) {
        update_post_meta($set_id, '_lww_demand_score', $result);
    }

    return $result;
}

/**
 * Führt die eigentliche KI-Anfrage aus, loggt sie und gibt den Score zurück.
 * OPTIMIERT: Nutzt die Transients API, um wiederholte, teure API-Aufrufe zu cachen.
 */
function lww_execute_ai_demand_analysis($post_id, $endpoint_slug, $prompt) {
    // --- PERFORMANCE OPTIMIZATION: Caching mit Transients ---
    $transient_key = 'lww_ai_demand_' . md5($prompt);
    $cached_result = get_transient($transient_key);
    if (false !== $cached_result) {
        // Gib das gecachte Ergebnis direkt zurück
        lww_log_api_call(get_option('lww_ai_provider', 'openai'), $endpoint_slug, true, 0, ['post_id' => $post_id, 'score' => $cached_result, 'from_cache' => true]);
        return $cached_result;
    }

    $api_settings = get_option('lww_api_settings');
    $ai_provider = get_option('lww_ai_provider', 'openai');
    $api_key = '';
    $simulated_cost = 0.0;
    $api_url = '';
    $api_body = [];
    $headers = [];
    $response_path = [];

    if ($ai_provider === 'openai') {
        $api_key = $api_settings['openai_api_key'] ?? '';
        $simulated_cost = 0.0015;
        $api_url = 'https://api.openai.com/v1/chat/completions';
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ];
        $api_body = [
            'model' => 'gpt-3.5-turbo',
            'messages' => [
                ['role' => 'system', 'content' => 'Du bist ein Experte für den LEGO-Zweitmarkt.'],
                ['role' => 'user', 'content' => $prompt]
            ],
            'max_tokens' => 5,
            'temperature' => 0.2,
        ];
        $response_path = ['choices', 0, 'message', 'content'];

    } elseif ($ai_provider === 'gemini') {
        $api_key = $api_settings['gemini_api_key'] ?? '';
        $simulated_cost = 0.0010;
        $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.0-pro:generateContent?key=' . $api_key;
        $headers = ['Content-Type' => 'application/json'];
        $api_body = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => 'Du bist ein Experte für den LEGO-Zweitmarkt.'],
                        ['text' => $prompt]
                    ]
                ]
            ]
        ];
        $response_path = ['candidates', 0, 'content', 'parts', 0, 'text'];
    }

    if (empty($api_key)) {
        $error_msg = sprintf(__('Kein API-Schlüssel für den ausgewählten Anbieter (%s) konfiguriert.', 'lego-wawi'), strtoupper($ai_provider));
        lww_log_api_call($ai_provider, $endpoint_slug, false, 0, ['post_id' => $post_id, 'error' => 'No API Key']);
        return new WP_Error('no_api_key', $error_msg);
    }

    $response = wp_remote_post($api_url, [
        'method' => 'POST',
        'timeout' => 30,
        'headers' => $headers,
        'body' => json_encode($api_body),
    ]);

    if (is_wp_error($response)) {
        lww_log_api_call($ai_provider, $endpoint_slug, false, 0, ['post_id' => $post_id, 'error' => $response->get_error_message()]);
        return $response;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);
    $data = json_decode($response_body, true);

    if ($response_code !== 200) {
        $error_message = $data['error']['message'] ?? 'Unbekannter API-Fehler';
        lww_log_api_call($ai_provider, $endpoint_slug, false, 0, ['post_id' => $post_id, 'error' => $error_message, 'response_code' => $response_code]);
        return new WP_Error('api_error', $error_message);
    }

    // Navigiere durch den verschachtelten Antwortpfad
    $content = $data;
    foreach ($response_path as $key) {
        if (isset($content[$key])) {
            $content = $content[$key];
        } else {
            $content = '';
            break;
        }
    }
    
    preg_match('/\d+/', $content, $matches);
    $score = isset($matches[0]) ? (int)$matches[0] : 0;

    lww_log_api_call($ai_provider, $endpoint_slug, true, $simulated_cost, ['post_id' => $post_id, 'score' => $score]);

    if ($score >= 1 && $score <= 100) {
        // Speichere das gültige Ergebnis im Cache für 24 Stunden
        set_transient($transient_key, $score, DAY_IN_SECONDS);
        return $score;
    } else {
        return new WP_Error('invalid_api_response', sprintf(__('Ungültige oder keine numerische Antwort von der KI-API erhalten. Antwort war: "%s"', 'lego-wawi'), esc_html($content)));
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

    // Finde alle relevanten Inventar-Items UND Sets
    $query = new WP_Query([
        'post_type' => ['lww_inventory_item', 'lww_set'],
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'meta_query' => $meta_query
    ]);

    $item_ids_to_process = $query->posts;

    if (empty($item_ids_to_process)) {
        add_settings_error('lww_messages', 'no_items_for_analysis', __('Alle relevanten Inventarartikel und Sets haben bereits einen Nachfrage-Score. Es gibt nichts zu tun.', 'lego-wawi'), 'info');
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_safe_redirect(admin_url('admin.php?page=lww_analysis_ui'));
        exit;
    }

    $priority = (int) get_option('lww_job_priority_demand_analysis', 15);

    // Job erstellen
    $job_id = wp_insert_post([
        'post_title'   => sprintf(__('Nachfrageanalyse für %d Artikel/Sets (%s)', 'lego-wawi'), count($item_ids_to_process), $mode === 'all' ? 'alle' : 'fehlende'),
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
        lww_log_to_job($job_id, sprintf('Job erstellt. %d Artikel/Sets zur Analyse in der Warteschlange.', count($item_ids_to_process)));

        lww_start_cron_job();
        add_settings_error('lww_messages', 'job_created', __('Neuer Analyse-Job wurde erfolgreich erstellt und zur Warteschlange hinzugefügt.', 'lego-wawi'), 'success');
    }

    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect(admin_url('admin.php?page=lww_jobs_ui'));
    exit;
}
add_action('admin_post_lww_run_demand_analysis', 'lww_handle_run_demand_analysis_tool');

?>