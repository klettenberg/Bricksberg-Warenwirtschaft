<?php
/**
 * Modul: Preis-KI (v19.1)
 * 
 * Stellt Funktionen zur Ermittlung von Preisempfehlungen via KI bereit.
 * UPDATE: AJAX Handler für Preisübernahme nutzt nun zentrale Funktion mit Logging.
 */
if (!defined('ABSPATH')) exit;

add_action('wp_ajax_lww_get_ai_price_recommendation', 'lww_ajax_get_ai_price_recommendation_handler');
add_action('wp_ajax_lww_apply_ai_price', 'lww_ajax_apply_ai_price_handler');

function lww_ajax_get_ai_price_recommendation_handler() {
    check_ajax_referer('lww_inventory_ajax_nonce', '_ajax_nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Keine Berechtigung.'], 403);

    $item_id = isset($_POST['item_id']) ? absint($_POST['item_id']) : 0;
    if (!$item_id) wp_send_json_error(['message' => 'Ungültige Item-ID.'], 400);

    $result = lww_get_ai_price_recommendation($item_id);

    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()], 500);
    } else {
        wp_send_json_success($result);
    }
}

function lww_ajax_apply_ai_price_handler() {
    check_ajax_referer('lww_inventory_ajax_nonce', '_ajax_nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Keine Berechtigung.'], 403);

    $item_id = isset($_POST['item_id']) ? absint($_POST['item_id']) : 0;
    $new_price = isset($_POST['new_price']) ? floatval($_POST['new_price']) : 0.0;

    if (!$item_id || $new_price <= 0) wp_send_json_error(['message' => 'Ungültiger Preis.'], 400);

    if (function_exists('lww_update_item_price')) {
        // Nutze zentrale Funktion für Logging
        $updated = lww_update_item_price($item_id, $new_price, 'ai_suggestion');
        if ($updated) {
            wp_send_json_success(['message' => 'Preis übernommen.', 'new_price_html' => number_format($new_price, 3, ',', '.') . ' €']);
        } else {
            wp_send_json_success(['message' => 'Preis unverändert.', 'new_price_html' => number_format($new_price, 3, ',', '.') . ' €']);
        }
    } else {
        // Fallback falls Funktion fehlt (sollte nicht passieren)
        update_post_meta($item_id, '_price', $new_price);
        wp_send_json_success(['message' => 'Preis übernommen (kein Log).', 'new_price_html' => number_format($new_price, 3, ',', '.') . ' €']);
    }
}

function lww_get_ai_price_recommendation($item_id) {
    $part_id = get_post_meta($item_id, '_lww_part_id', true);
    $set_id = get_post_meta($item_id, '_lww_set_id', true);
    $minifig_id = get_post_meta($item_id, '_lww_minifig_id', true);
    $catalog_id = $part_id ?: $set_id ?: $minifig_id;

    if (!$catalog_id) return new WP_Error('missing_data', 'Kein Katalogeintrag gefunden.');

    $market_prices = get_post_meta($item_id, '_lww_market_prices', true);
    
    // Prüfen ob KI-Keys da sind
    $api_settings = get_option('lww_api_settings');
    $ai_provider = get_option('lww_ai_provider', 'openai');
    $has_key = ($ai_provider === 'openai' && !empty($api_settings['openai_api_key'])) || ($ai_provider === 'gemini' && !empty($api_settings['gemini_api_key']));

    // FALLBACK: Wenn keine KI konfiguriert ist, nutze einfache Logik
    if (!$has_key) {
        $prices = [];
        if (isset($market_prices['bricklink']['price'])) $prices[] = $market_prices['bricklink']['price'];
        if (isset($market_prices['brickowl']['price'])) $prices[] = $market_prices['brickowl']['price'];
        
        if (empty($prices)) {
            return new WP_Error('no_data', 'Keine KI konfiguriert und keine Marktdaten verfügbar.');
        }
        
        $avg_price = array_sum($prices) / count($prices);
        return [
            'price' => round($avg_price, 2),
            'justification' => 'Automatisch berechnet: Durchschnitt der verfügbaren Marktpreise (BrickLink/BrickOwl), da keine KI-Anbindung konfiguriert ist.'
        ];
    }

    // KI-Prompt bauen
    $post_type = get_post_type($catalog_id);
    $data = [
        'type' => str_replace('lww_', '', $post_type),
        'name' => get_the_title($catalog_id),
        'condition' => get_post_meta($item_id, '_condition', true),
        'market_prices' => $market_prices,
    ];

    $prompt = "Analysiere den Markt für den LEGO-Artikel '{$data['name']}' ({$data['type']}). Zustand: {$data['condition']}. ";
    if (!empty($data['market_prices'])) {
        $prompt .= "Bekannte Preise: " . json_encode($data['market_prices']) . ". ";
    }
    $prompt .= "Gib ein JSON mit 'price' (float) und 'justification' (string) zurück. Begründe kurz, warum dieser Preis kompetitiv ist.";

    $result = lww_execute_ai_price_analysis($item_id, 'price_recommendation', $prompt);

    if(is_wp_error($result)) return $result;

    // Parse JSON from AI result
    $json_start = strpos($result, '{');
    $json_end = strrpos($result, '}');
    if ($json_start !== false && $json_end !== false) {
        $json_str = substr($result, $json_start, $json_end - $json_start + 1);
        $parsed = json_decode($json_str, true);
        if ($parsed && isset($parsed['price'])) {
            return $parsed;
        }
    }

    return new WP_Error('ai_parse_error', 'Konnte KI-Antwort nicht verarbeiten.');
}

/**
 * Führt die KI-Anfrage für Preise aus.
 */
function lww_execute_ai_price_analysis($post_id, $endpoint, $prompt) {
    // Nutze die Logik aus lww-demand-analyzer.php, da sie bereits robust ist.
    if (!function_exists('lww_execute_ai_demand_analysis')) {
        return new WP_Error('missing_core', 'KI-Kernfunktion nicht gefunden.');
    }
    
    $api_settings = get_option('lww_api_settings');
    $ai_provider = get_option('lww_ai_provider', 'openai');
    $api_key = ($ai_provider === 'openai') ? ($api_settings['openai_api_key'] ?? '') : ($api_settings['gemini_api_key'] ?? '');
    
    if (empty($api_key)) return new WP_Error('no_api_key', 'API Key fehlt');

    $api_url = ($ai_provider === 'openai') 
        ? 'https://api.openai.com/v1/chat/completions' 
        : 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.0-pro:generateContent?key=' . $api_key;

    $headers = ['Content-Type' => 'application/json'];
    if ($ai_provider === 'openai') $headers['Authorization'] = 'Bearer ' . $api_key;

    $body = [];
    if ($ai_provider === 'openai') {
        $body = [
            'model' => 'gpt-3.5-turbo',
            'messages' => [
                ['role' => 'system', 'content' => 'Du bist ein Pricing-Experte für LEGO.'],
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.3
        ];
    } else {
        $body = ['contents' => [['parts' => [['text' => $prompt]]]]];
    }

    $response = wp_remote_post($api_url, [
        'method' => 'POST',
        'timeout' => 30,
        'headers' => $headers,
        'body' => json_encode($body),
    ]);

    if (is_wp_error($response)) return $response;
    
    $data = json_decode(wp_remote_retrieve_body($response), true);
    
    $content = '';
    if ($ai_provider === 'openai') {
        $content = $data['choices'][0]['message']['content'] ?? '';
    } else {
        $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    return $content;
}
