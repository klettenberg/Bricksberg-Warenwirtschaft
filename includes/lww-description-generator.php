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
        $prompt_data['year'],
        $prompt_data['num_parts']
    );

    $simulated_response = sprintf(
        '<p>Erlebe Bauspaß pur mit dem LEGO® Set <strong>%s (%s)</strong> aus der beliebten <strong>%s</strong>-Themenwelt! Dieses fantastische Set aus dem Jahr %s wird dich begeistern und ist eine tolle Ergänzung für jede Sammlung.</p>\n<p>Mit insgesamt %s Teilen bietet dieses Modell stundenlange Unterhaltung und ein detailreiches Bauerlebnis. Ob zum Spielen oder als Ausstellungsstück, das Set %s ist ein echter Hingucker.</p>\n<ul>\n<li><strong>Set-Name:</strong> LEGO %s</li>\n<li><strong>Set-Nummer:</strong> %s</li>\n<li><strong>Thema:</strong> %s</li>\n<li><strong>Erscheinungsjahr:</strong> %s</li>\n<li><strong>Teileanzahl:</strong> %s</li>\n</ul>',
        $prompt_data['name'], $prompt_data['number'], $prompt_data['theme'], $prompt_data['year'], $prompt_data['num_parts'], $prompt_data['number'], $prompt_data['name'], $prompt_data['number'], $prompt_data['theme'], $prompt_data['year'], $prompt_data['num_parts']
    );

    $result = lww_execute_ai_generation($post_id, 'set_description', $prompt, $simulated_response);

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
        'Erstelle eine kurze, ansprechende und SEO-optimierte Produktbeschreibung für die folgende LEGO Minifigur. Die Beschreibung soll für WooCommerce und eBay geeignet sein. Sprich den Sammler direkt an. Baue Keywords wie "LEGO Minifigur", "%s" und "%s" natürlich ein.\n\nName: %s\nFigur-Nummer: %s',
        $prompt_data['name'], $prompt_data['number'], $prompt_data['name'], $prompt_data['number']
    );

    $simulated_response = sprintf(
        '<p>Vervollständige deine Sammlung mit der originalen LEGO® Minifigur <strong>%s (%s)</strong>! Diese detailreiche Figur ist ein Muss für jeden Fan und Sammler.</p>\n<ul>\n<li><strong>Figur-Name:</strong> LEGO %s</li>\n<li><strong>Figur-Nummer:</strong> %s</li>\n<li><strong>Bestandteile:</strong> %s Teile</li>\n</ul>',
        $prompt_data['name'], $prompt_data['number'], $prompt_data['name'], $prompt_data['number'], $prompt_data['num_parts']
    );

    $result = lww_execute_ai_generation($post_id, 'minifig_description', $prompt, $simulated_response);

    if (!is_wp_error($result)) {
        update_post_meta($post_id, '_lww_seo_description_wc', $result);
        return true;
    }
    return $result;
}

/**
 * Generiert eine aussagekräftige Kurzbeschreibung für ein Teil.
 *
 * @param int $post_id Die Post-ID des lww_part.
 * @return bool|WP_Error True bei Erfolg, ansonsten WP_Error.
 */
function lww_generate_part_short_description($post_id) {
    $prompt_data = [
        'post_type' => 'Teil',
        'name' => get_the_title($post_id),
        'number' => get_post_meta($post_id, '_lww_part_num', true),
        'category' => strip_tags(get_the_term_list($post_id, 'lww_part_category', '', ', ', '')),
    ];

    $prompt = sprintf(
        'Erstelle eine sehr kurze, prägnante und informative WooCommerce-Kurzbeschreibung (max. 1-2 Sätze) für das folgende LEGO Teil. Nenne den Namen, die Kategorie und die Teilenummer.\n\nName: %s\nTeilenummer: %s\nKategorie: %s',
        $prompt_data['name'], $prompt_data['number'], $prompt_data['category']
    );

    $simulated_response = sprintf(
        'Original LEGO® Ersatzteil: %s in der Kategorie %s. Offizielle Teilenummer: %s. Perfekt zum Erweitern deiner Sammlung oder für eigene Kreationen (MOCs).',
        $prompt_data['name'], $prompt_data['category'], $prompt_data['number']
    );
    
    $long_simulated_response = '<p>' . $simulated_response . '</p><p>' . __('Dieses Einzelteil ist ideal, um verlorene Steine zu ersetzen oder eigene Bauprojekte (MOCs - My Own Creations) zu realisieren. Als offizielles LEGO®-Produkt garantiert es perfekte Passform und die gewohnt hohe Qualität.', 'lego-wawi') . '</p>';

    $result = lww_execute_ai_generation($post_id, 'part_short_description', $prompt, $simulated_response);

    if (!is_wp_error($result)) {
        update_post_meta($post_id, '_lww_short_description', $result);
        // Optional auch eine längere Beschreibung für Teile generieren
        update_post_meta($post_id, '_lww_seo_description_wc', $long_simulated_response);
        return true;
    }
    return $result;
}

/**
 * Führt die (simulierte) KI-Anfrage aus, loggt sie und gibt das Ergebnis zurück.
 */
function lww_execute_ai_generation($post_id, $endpoint, $prompt, $simulated_response) {
    $api_settings = get_option('lww_api_settings');
    $ai_provider = get_option('lww_ai_provider', 'openai');
    $api_key = ($ai_provider === 'openai') ? ($api_settings['openai_api_key'] ?? '') : ($api_settings['gemini_api_key'] ?? '');
    $simulated_cost = 0.0020; // Annahme für Texterstellung

    if (empty($api_key)) {
        $error_msg = sprintf(__('Kein API-Schlüssel für %s konfiguriert.', 'lego-wawi'), strtoupper($ai_provider));
        lww_log_api_call($ai_provider, $endpoint, false, 0, ['post_id' => $post_id, 'error' => 'No API Key']);
        return new WP_Error('no_api_key', $error_msg);
    }

    lww_log_system_event(sprintf('SIMULATION (%s): Sende Prompt für Post %d:\n%s', strtoupper($ai_provider), $post_id, $prompt));
    
    // SIMULATION
    $success = true;
    $response = $simulated_response;

    lww_log_api_call($ai_provider, $endpoint, $success, $simulated_cost, ['post_id' => $post_id]);

    if ($success) {
        return $response;
    } else {
        return new WP_Error('api_error', __('Fehler bei der KI-API-Anfrage.', 'lego-wawi'));
    }
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