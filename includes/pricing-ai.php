<?php
/**
 * Modul: Preis-KI (v1.0)
 * Stellt Funktionen zur Ermittlung von Preisempfehlungen via KI bereit.
 */
if (!defined('ABSPATH')) exit;

add_action('wp_ajax_lww_get_ai_price_recommendation', 'lww_ajax_get_ai_price_recommendation_handler');
add_action('wp_ajax_lww_apply_ai_price', 'lww_ajax_apply_ai_price_handler');

/**
 * AJAX Handler: Ruft eine Preisempfehlung ab.
 */
function lww_ajax_get_ai_price_recommendation_handler() {
    check_ajax_referer('lww_inventory_ajax_nonce', '_ajax_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Keine Berechtigung.', 'lego-wawi')], 403);
    }

    $item_id = isset($_POST['item_id']) ? absint($_POST['item_id']) : 0;
    if (!$item_id) {
        wp_send_json_error(['message' => __('Ungültige Item-ID.', 'lego-wawi')], 400);
    }

    $result = lww_get_ai_price_recommendation($item_id);

    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()], 500);
    } else {
        wp_send_json_success($result);
    }
}

/**
 * AJAX Handler: Übernimmt einen von der KI vorgeschlagenen Preis.
 */
function lww_ajax_apply_ai_price_handler() {
    check_ajax_referer('lww_inventory_ajax_nonce', '_ajax_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Keine Berechtigung.', 'lego-wawi')], 403);
    }

    $item_id = isset($_POST['item_id']) ? absint($_POST['item_id']) : 0;
    $new_price = isset($_POST['new_price']) ? floatval($_POST['new_price']) : 0.0;

    if (!$item_id || $new_price <= 0) {
        wp_send_json_error(['message' => __('Ungültige Item-ID oder Preis.', 'lego-wawi')], 400);
    }

    $old_price = (float) get_post_meta($item_id, '_price', true);
    update_post_meta($item_id, '_price', $new_price);

    // Preis-Historie fortschreiben
    if (abs($new_price - $old_price) > 0.0001) {
        $history = get_post_meta($item_id, '_lww_price_history', true);
        if (!is_array($history)) $history = [];
        $history[] = ['timestamp' => time(), 'price' => $new_price, 'source' => 'ai_recommendation'];
        if (count($history) > 20) $history = array_slice($history, -20);
        update_post_meta($item_id, '_lww_price_history', $history);
    }

    wp_send_json_success([
        'message' => 'Preis erfolgreich übernommen.',
        'new_price_html' => number_format($new_price, 3, ',', '.') . ' €'
    ]);
}

/**
 * Holt eine Preisempfehlung inkl. Begründung von der KI für einen Inventarartikel.
 *
 * @param int $item_id Die Post-ID des lww_inventory_item.
 * @return array|WP_Error Ein Array mit ['price' => float, 'justification' => string] oder ein WP_Error.
 */
function lww_get_ai_price_recommendation($item_id) {
    // 1. Daten sammeln
    $part_id = get_post_meta($item_id, '_lww_part_id', true);
    $set_id = get_post_meta($item_id, '_lww_set_id', true);
    $minifig_id = get_post_meta($item_id, '_lww_minifig_id', true);
    $catalog_id = $part_id ?: $set_id ?: $minifig_id;

    if (!$catalog_id) {
        return new WP_Error('missing_data', __('Kein Katalogeintrag (Teil, Set, Minifigur) mit diesem Inventarartikel verknüpft.', 'lego-wawi'));
    }

    $post_type = get_post_type($catalog_id);
    $data = [
        'type' => str_replace('lww_', '', $post_type),
        'name' => get_the_title($catalog_id),
        'number' => get_post_meta($catalog_id, '_lww_' . str_replace('lww_', '', $post_type) . '_num', true),
        'condition' => get_post_meta($item_id, '_condition', true),
        'color' => get_post_meta($item_id, '_color_name', true),
        'demand_score' => get_post_meta($item_id, '_lww_demand_score', true),
        'market_prices' => get_post_meta($item_id, '_lww_market_prices', true),
    ];

    // 2. Prompt erstellen
    $prompt = "Analysiere den Markt für den folgenden LEGO-Artikel und gib eine wettbewerbsfähige Preisempfehlung in EUR an. Berücksichtige Typ, Zustand, Seltenheit und bekannte Marktdaten.\n";
    $prompt .= "Antworte NUR mit einem JSON-Objekt, das die Schlüssel 'price' (als Fließkommazahl) und 'justification' (als String mit deiner Begründung in 2-3 Sätzen) enthält.\n\n";
    $prompt .= "Artikel-Typ: " . ucfirst($data['type']) . "\n";
    $prompt .= "Name: " . $data['name'] . "\n";
    $prompt .= "Nummer: " . $data['number'] . "\n";
    if ($data['color']) {
        $prompt .= "Farbe: " . $data['color'] . "\n";
    }
    $prompt .= "Zustand: " . ($data['condition'] === 'new' ? 'Neu' : 'Gebraucht') . "\n";
    if ($data['demand_score']) {
        $prompt .= "Nachfrage-Score (1-100): " . $data['demand_score'] . "\n";
    }
    if (is_array($data['market_prices']) && !empty($data['market_prices'])) {
        $price_strings = [];
        foreach ($data['market_prices'] as $source => $price_data) {
            $price_strings[] = ucfirst($source) . ": " . number_format($price_data['price'], 2) . " EUR";
        }
        $prompt .= "Bekannte Marktpreise: " . implode(', ', $price_strings) . "\n";
    }

    // 3. API aufrufen
    return lww_execute_ai_price_analysis($item_id, 'price_recommendation', $prompt);
}

/**
 * Führt die KI-Anfrage aus, loggt sie und gibt das Ergebnis zurück.
 *
 * @param int $post_id
 * @param string $endpoint
 * @param string $prompt
 * @return array|WP_Error
 */
function lww_execute_ai_price_analysis($post_id, $endpoint, $prompt) {
    $api_settings = get_option('lww_api_settings');
    $ai_provider = get_option('lww_ai_provider', 'openai');
    
    $api_key = '';
    $simulated_cost = 0.0;
    $api_url = '';
    $api_body = [];
    $headers = [];
    $response_path = [];

    $system_prompt = 'Du bist ein Experte für die Preisgestaltung auf dem LEGO-Zweitmarkt wie BrickLink und BrickOwl.';

    if ($ai_provider === 'openai') {
        $api_key = $api_settings['openai_api_key'] ?? '';
        $simulated_cost = 0.0025;
        $api_url = 'https://api.openai.com/v1/chat/completions';
        $headers = [ 
            'Authorization' => 'Bearer ' . $api_key, 
            'Content-Type' => 'application/json' 
        ];
        $api_body = [
            'model' => 'gpt-3.5-turbo',
            'messages' => [
                ['role' => 'system', 'content' => $system_prompt],
                ['role' => 'user', 'content' => $prompt]
            ],
            'max_tokens' => 200,
            'temperature' => 0.5,
        ];
        $response_path = ['choices', 0, 'message', 'content'];

    } elseif ($ai_provider === 'gemini') {
        $api_key = $api_settings['gemini_api_key'] ?? '';
        $simulated_cost = 0.0020;
        $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.0-pro:generateContent?key=' . $api_key;
        $headers = ['Content-Type' => 'application/json'];
        $api_body = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $system_prompt],
                        ['text' => $prompt]
                    ]
                ]
            ],
             'generationConfig' => [
                'maxOutputTokens' => 200,
                'temperature' => 0.5,
            ]
        ];
        $response_path = ['candidates', 0, 'content', 'parts', 0, 'text'];
    }

    if (empty($api_key)) {
        $error_msg = sprintf(__('Kein API-Schlüssel für %s konfiguriert.', 'lego-wawi'), strtoupper($ai_provider));
        lww_log_api_call($ai_provider, $endpoint, false, 0, ['post_id' => $post_id, 'error' => 'No API Key'], '');
        return new WP_Error('no_api_key', $error_msg);
    }

    $response = wp_remote_post($api_url, [
        'headers' => $headers,
        'body' => json_encode($api_body),
        'timeout' => 45,
    ]);

    if (is_wp_error($response)) {
        lww_log_api_call($ai_provider, $endpoint, false, 0, ['post_id' => $post_id, 'error' => $response->get_error_message()], wp_json_encode($response));
        return $response;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);
    $data = json_decode($response_body, true);

    if ($response_code !== 200) {
        $error_message = $data['error']['message'] ?? __('Unbekannter API-Fehler bei der Preisanalyse.', 'lego-wawi');
        lww_log_api_call($ai_provider, $endpoint, false, 0, ['post_id' => $post_id, 'error' => $error_message, 'response_code' => $response_code], $response_body);
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
    
    // Versuche, das JSON aus der Antwort zu extrahieren
    $json_start = strpos($content, '{');
    $json_end = strrpos($content, '}');
    if ($json_start === false || $json_end === false) {
        return new WP_Error('invalid_json', __('Die KI hat kein gültiges JSON-Objekt zurückgegeben.', 'lego-wawi') . ' Antwort: ' . esc_html($content));
    }
    
    $json_string = substr($content, $json_start, $json_end - $json_start + 1);
    $result = json_decode($json_string, true);

    if (json_last_error() !== JSON_ERROR_NONE || !isset($result['price']) || !isset($result['justification'])) {
        return new WP_Error('invalid_json_format', __('Das JSON der KI hat das erwartete Format (price, justification) nicht.', 'lego-wawi') . ' Antwort: ' . esc_html($content));
    }
    
    lww_log_api_call($ai_provider, $endpoint, true, $simulated_cost, ['post_id' => $post_id, 'price_recommendation' => $result['price']], $response_body);

    return [
        'price' => (float) $result['price'],
        'justification' => (string) $result['justification'],
    ];
}
?>
