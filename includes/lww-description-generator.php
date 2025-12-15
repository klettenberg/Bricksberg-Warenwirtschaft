<?php
/**
 * Modul: Beschreibungs-Generator (KI)
 *
 * Stellt Funktionen zur Generierung von SEO-optimierten Beschreibungen
 * für Katalogeinträge (Sets, Minifigs, Teile) bereit.
 */
if (!defined('ABSPATH')) exit;

/**
 * Generiert eine SEO-optimierte Beschreibung für ein Set.
 *
 * @param int $post_id Die Post-ID des lww_set.
 * @return bool|WP_Error True bei Erfolg, ansonsten WP_Error.
 */
function lww_generate_set_description($post_id) {
    $prompt_data = [
        'post_type' => 'Set',
        'name' => get_the_title($post_id),
        'number' => get_post_meta($post_id, '_lww_set_num', true),
        'year' => get_post_meta($post_id, '_lww_year_released', true),
        'num_parts' => get_post_meta($post_id, '_lww_num_parts', true),
        'theme' => strip_tags(get_the_term_list($post_id, 'lww_theme', '', ', ', '')),
    ];

    $prompt = sprintf(
        'Erstelle eine informative und SEO-optimierte Produktbeschreibung für das folgende LEGO Set. Die Beschreibung soll für WooCommerce und eBay geeignet sein. Strukturiere sie mit HTML-Absätzen (<p>) und einer Liste (<ul><li>) für die wichtigsten Merkmale. Sprich den Kunden direkt an. Erwähne das Thema, die Anzahl der Teile und das Erscheinungsjahr. Baue Keywords wie "LEGO", "%s", "%s" und "%s" natürlich ein.\n\nName: %s\nThema: %s\nSet-Nummer: %s\nErscheinungsjahr: %s\nAnzahl Teile: %s',
        $prompt_data['name'],
        $prompt_data['number'],
        $prompt_data['theme'],
        $prompt_data['name'],
        $prompt_data['theme'],
        $prompt_data['number'],
        date('Y', strtotime($prompt_data['year'])),
        $prompt_data['num_parts']
    );

    $result = lww_execute_ai_generation($post_id, 'set_description', $prompt, 512);

    if (!is_wp_error($result)) {
        update_post_meta($post_id, '_lww_seo_description_wc', $result);
        return true;
    }
    return $result;
}

/**
 * Generiert eine SEO-optimierte Beschreibung für eine Minifigur.
 *
 * @param int $post_id Die Post-ID der lww_minifig.
 * @return bool|WP_Error True bei Erfolg, ansonsten WP_Error.
 */
function lww_generate_minifig_description($post_id) {
    $prompt_data = [
        'post_type' => 'Minifigur',
        'name' => get_the_title($post_id),
        'number' => get_post_meta($post_id, '_lww_minifig_num', true),
        'num_parts' => get_post_meta($post_id, '_lww_num_parts', true),
    ];

    $prompt = sprintf(
        'Erstelle eine kurze, ansprechende und SEO-optimierte Produktbeschreibung (ca. 2-3 Absätze) für die folgende LEGO Minifigur. Die Beschreibung soll für WooCommerce und eBay geeignet sein. Sprich den Sammler direkt an. Baue Keywords wie "LEGO Minifigur", "%s" und "%s" natürlich ein.\n\nName: %s\nFigur-Nummer: %s',
        $prompt_data['name'], $prompt_data['number'], $prompt_data['name'], $prompt_data['number']
    );

    $result = lww_execute_ai_generation($post_id, 'minifig_description', $prompt, 300);

    if (!is_wp_error($result)) {
        update_post_meta($post_id, '_lww_seo_description_wc', $result);
        return true;
    }
    return $result;
}

/**
 * Generiert eine aussagekräftige Kurzbeschreibung und eine lange Beschreibung für ein Teil.
 *
 * @param int $post_id Die Post-ID des lww_part.
 * @return bool|WP_Error True bei Erfolg, ansonsten WP_Error.
 */
function lww_generate_part_descriptions($post_id) {
    $prompt_data = [
        'post_type' => 'Teil',
        'name' => get_the_title($post_id),
        'number' => get_post_meta($post_id, '_lww_part_num', true),
        'category' => strip_tags(get_the_term_list($post_id, 'lww_part_category', '', ', ', '')),
    ];

    $short_prompt = sprintf(
        'Erstelle eine sehr kurze, prägnante und informative WooCommerce-Kurzbeschreibung (max. 1-2 Sätze) für das folgende LEGO Teil. Nenne den Namen, die Kategorie und die Teilenummer.\n\nName: %s\nTeilenummer: %s\nKategorie: %s',
        $prompt_data['name'], $prompt_data['number'], $prompt_data['category']
    );
    
    $long_prompt = sprintf(
        'Erstelle eine informative und SEO-optimierte lange Produktbeschreibung (ca. 2 Absätze) für das folgende LEGO-Teil. Erwähne, dass es sich um ein originales Ersatzteil handelt, ideal zum Ersetzen verlorener Steine oder für eigene Bauprojekte (MOCs). Baue Keywords wie "LEGO", "Ersatzteil", "%s" und "%s" ein.\n\nName: %s\nTeilenummer: %s\nKategorie: %s',
        $prompt_data['name'], $prompt_data['number'], $prompt_data['name'], $prompt_data['number'], $prompt_data['category']
    );

    $short_result = lww_execute_ai_generation($post_id, 'part_short_description', $short_prompt, 80);
    if (is_wp_error($short_result)) return $short_result;

    $long_result = lww_execute_ai_generation($post_id, 'part_long_description', $long_prompt, 250);
    if (is_wp_error($long_result)) return $long_result;

    update_post_meta($post_id, '_lww_short_description', $short_result);
    update_post_meta($post_id, '_lww_seo_description_wc', $long_result);
    return true;
}

/**
 * Führt die KI-Anfrage aus, loggt sie und gibt das Ergebnis zurück.
 * OPTIMIERT: Nutzt die Transients API, um wiederholte, teure API-Aufrufe zu cachen.
 */
function lww_execute_ai_generation($post_id, $endpoint, $prompt, $max_tokens = 256) {
    // --- PERFORMANCE OPTIMIZATION: Caching mit Transients ---
    $transient_key = 'lww_ai_gen_' . md5($prompt);
    $cached_result = get_transient($transient_key);
    if (false !== $cached_result) {
        lww_log_api_call(get_option('lww_ai_provider', 'openai'), $endpoint, true, 0, ['post_id' => $post_id, 'from_cache' => true]);
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
        $simulated_cost = 0.0020;
        $api_url = 'https://api.openai.com/v1/chat/completions';
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ];
        $api_body = [
            'model' => 'gpt-3.5-turbo',
            'messages' => [
                ['role' => 'system', 'content' => 'Du bist ein SEO-Experte für LEGO-Produkte.'],
                ['role' => 'user', 'content' => $prompt]
            ],
            'max_tokens' => $max_tokens,
            'temperature' => 0.7,
        ];
        $response_path = ['choices', 0, 'message', 'content'];

    } elseif ($ai_provider === 'gemini') {
        $api_key = $api_settings['gemini_api_key'] ?? '';
        $simulated_cost = 0.0015;
        $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.0-pro:generateContent?key=' . $api_key;
        $headers = ['Content-Type' => 'application/json'];
        $api_body = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => 'Du bist ein SEO-Experte für LEGO-Produkte.'],
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'maxOutputTokens' => $max_tokens,
                'temperature' => 0.7,
            ]
        ];
        $response_path = ['candidates', 0, 'content', 'parts', 0, 'text'];
    }

    if (empty($api_key)) {
        $error_msg = sprintf(__('Kein API-Schlüssel für %s konfiguriert.', 'lego-wawi'), strtoupper($ai_provider));
        lww_log_api_call($ai_provider, $endpoint, false, 0, ['post_id' => $post_id, 'error' => 'No API Key']);
        return new WP_Error('no_api_key', $error_msg);
    }

    $response = wp_remote_post($api_url, [
        'method' => 'POST',
        'headers' => $headers,
        'body' => json_encode($api_body),
        'timeout' => 60,
    ]);

    if (is_wp_error($response)) {
        lww_log_api_call($ai_provider, $endpoint, false, 0, ['post_id' => $post_id, 'error' => $response->get_error_message()]);
        return $response;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);
    $data = json_decode($response_body, true);

    if ($response_code !== 200) {
        $error_message = $data['error']['message'] ?? __('Unbekannter API-Fehler bei der Texterstellung.', 'lego-wawi');
        lww_log_api_call($ai_provider, $endpoint, false, 0, ['post_id' => $post_id, 'error' => $error_message, 'response_code' => $response_code]);
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

    lww_log_api_call($ai_provider, $endpoint, true, $simulated_cost, ['post_id' => $post_id]);

    $trimmed_content = trim($content);

    // Speichere das Ergebnis im Cache für 24 Stunden
    set_transient($transient_key, $trimmed_content, DAY_IN_SECONDS);

    return $trimmed_content;
}

/**
 * Handler für den admin-post-Request zum Starten der Beschreibungs-Generierung.
 */
function lww_handle_run_description_generation() {
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'lww_description_generation_nonce')) {
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

    $mode = isset($_POST['generation_mode']) ? sanitize_key($_POST['generation_mode']) : 'missing';
    $post_types_to_check = ['lww_set', 'lww_minifig', 'lww_part'];
    $ids_to_process = [];

    foreach ($post_types_to_check as $post_type) {
        $meta_key_to_check = ($post_type === 'lww_part') ? '_lww_short_description' : '_lww_seo_description_wc';
        
        $query_args = [
            'post_type' => $post_type,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ];

        if ($mode === 'missing') {
            $query_args['meta_query'] = [
                'relation' => 'OR',
                ['key' => $meta_key_to_check, 'compare' => 'NOT EXISTS'],
                ['key' => $meta_key_to_check, 'value' => '', 'compare' => '=']
            ];
        }

        $query = new WP_Query($query_args);
        if ($query->have_posts()) {
            $ids_to_process = array_merge($ids_to_process, $query->posts);
        }
    }

    if (empty($ids_to_process)) {
        add_settings_error('lww_messages', 'no_items_for_description', __('Alle relevanten Katalogeinträge haben bereits eine Beschreibung. Es gibt nichts zu tun.', 'lego-wawi'), 'info');
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_safe_redirect(admin_url('admin.php?page=lww_analysis_ui'));
        exit;
    }

    $priority = (int) get_option('lww_job_priority_description_generation', 20);

    // Job erstellen
    $job_id = wp_insert_post([
        'post_title'   => sprintf(__('KI-Beschreibungserstellung für %d Einträge (%s)', 'lego-wawi'), count($ids_to_process), $mode === 'all' ? 'alle' : 'fehlende'),
        'post_type'    => 'lww_job',
        'post_status'  => 'lww_pending',
        'post_author'  => get_current_user_id(),
        'menu_order'   => $priority, // Konfigurierbare Priorität
    ], true);

    if (is_wp_error($job_id)) {
        add_settings_error('lww_messages', 'job_creation_failed', __('Fehler beim Erstellen des Beschreibungs-Jobs: ', 'lego-wawi') . $job_id->get_error_message(), 'error');
    } else {
        update_post_meta($job_id, '_job_type', 'description_generation');
        update_post_meta($job_id, '_item_ids_to_process', $ids_to_process);
        update_post_meta($job_id, '_total_items', count($ids_to_process));
        update_post_meta($job_id, '_processed_items', 0);
        lww_log_to_job($job_id, sprintf('Job erstellt. %d Einträge zur Analyse in der Warteschlange.', count($ids_to_process)));

        lww_start_cron_job();
        add_settings_error('lww_messages', 'job_created', __('Neuer Job zur Beschreibungserstellung wurde erfolgreich erstellt.', 'lego-wawi'), 'success');
    }

    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect(admin_url('admin.php?page=lww_jobs_ui'));
    exit;
}
add_action('admin_post_lww_run_description_generation', 'lww_handle_run_description_generation');

?>