<?php
/**
 * Modul: Übersetzungs-Manager (v2.0)
 * 
 * Unterstützt nun DeepL als primären Übersetzer für kostengünstige und präzise Ergebnisse.
 * Hybride Strategie: Regelbasiert -> DeepL -> Generative KI.
 */
if (!defined('ABSPATH')) exit;

/**
 * Startet einen Hintergrund-Job zur Übersetzung fehlender deutscher Titel.
 */
function lww_start_translation_job($post_types = ['lww_part', 'lww_set', 'lww_minifig']) {
    $items_to_translate = [];

    foreach ($post_types as $type) {
        $meta_key = '_lww_' . str_replace('lww_', '', $type) . '_name_de';
        
        // Suche Items ohne deutschen Namen
        $query = new WP_Query([
            'post_type' => $type,
            'post_status' => 'publish',
            'posts_per_page' => 500, // Limit pro Batch-Start
            'meta_query' => [
                'relation' => 'OR',
                ['key' => $meta_key, 'compare' => 'NOT EXISTS'],
                ['key' => $meta_key, 'value' => '', 'compare' => '=']
            ],
            'fields' => 'ids'
        ]);

        if ($query->have_posts()) {
            $items_to_translate = array_merge($items_to_translate, $query->posts);
        }
    }

    if (empty($items_to_translate)) {
        return new WP_Error('no_items', __('Keine Artikel gefunden, die eine Übersetzung benötigen.', 'lego-wawi'));
    }

    $job_id = wp_insert_post([
        'post_title' => sprintf(__('Titel-Übersetzung für %d Artikel', 'lego-wawi'), count($items_to_translate)),
        'post_type' => 'lww_job',
        'post_status' => 'lww_pending',
        'post_author' => get_current_user_id()
    ]);

    if (!is_wp_error($job_id)) {
        update_post_meta($job_id, '_job_type', 'translate_titles');
        update_post_meta($job_id, '_item_ids_to_process', $items_to_translate);
        update_post_meta($job_id, '_total_items', count($items_to_translate));
        update_post_meta($job_id, '_processed_items', 0);
        lww_start_cron_job();
        return $job_id;
    }

    return $job_id;
}

/**
 * Übersetzt einen einzelnen Titel.
 * 
 * @param int $post_id Post ID
 * @param bool $use_ai Ob KI als Fallback genutzt werden soll.
 * @return string Übersetzter Titel
 */
function lww_translate_item_title($post_id, $use_ai = false) {
    $post = get_post($post_id);
    if (!$post) return '';

    $original_title = $post->post_title;
    $post_type = $post->post_type;
    $translated_title = $original_title;

    // 1. Regelbasierte Übersetzung (für Teile sehr effektiv)
    if ($post_type === 'lww_part') {
        $translated_title = lww_translate_part_name_rules($original_title);
    }

    // Prüfen, ob eine externe Übersetzung notwendig ist (wenn Titel noch sehr englisch)
    $needs_translation = ($translated_title === $original_title || str_word_count($translated_title) > 3);

    if ($needs_translation) {
        $provider = get_option('lww_translation_provider', 'deepl');

        // 2. DeepL (Kostengünstig & Schnell)
        if ($provider === 'deepl') {
            $deepl_result = lww_translate_with_deepl($original_title);
            if (!is_wp_error($deepl_result) && !empty($deepl_result)) {
                $translated_title = $deepl_result;
            }
        } 
        
        // 3. Generative KI (Fallback oder wenn als Provider gewählt)
        if ($provider === 'ai' || ($provider === 'deepl' && isset($deepl_result) && is_wp_error($deepl_result) && $use_ai)) {
            if (function_exists('lww_execute_ai_generation')) {
                $prompt = sprintf(
                    'Übersetze den folgenden LEGO-Artikelnamen präzise ins Deutsche. Behalte Fachbegriffe bei, wenn sie üblich sind (z.B. "Stud", "Slope" oft auch eingedeutscht, aber "Brick" ist "Stein"). Antworte NUR mit dem deutschen Titel.\nOriginal: "%s"',
                    $original_title
                );
                $ai_result = lww_execute_ai_generation($post_id, 'translate_title', $prompt, 60);
                if (!is_wp_error($ai_result) && !empty($ai_result)) {
                    $translated_title = trim($ai_result, ' "');
                }
            }
        }
    }

    // Speichern
    $meta_key = '_lww_' . str_replace('lww_', '', $post_type) . '_name_de';
    update_post_meta($post_id, $meta_key, $translated_title);

    return $translated_title;
}

/**
 * Führt eine Übersetzung mit der DeepL API durch.
 */
function lww_translate_with_deepl($text) {
    $api_settings = get_option('lww_api_settings');
    $auth_key = $api_settings['deepl_api_key'] ?? '';

    if (empty($auth_key)) return new WP_Error('no_key', 'DeepL API Key fehlt');

    // Cache prüfen (Transients), um Kosten zu sparen
    $cache_key = 'lww_trans_' . md5($text . 'DE');
    $cached = get_transient($cache_key);
    if ($cached) return $cached;

    // API URL bestimmen (Free vs Pro)
    $api_url = (strpos($auth_key, ':fx') !== false) ? 'https://api-free.deepl.com/v2/translate' : 'https://api.deepl.com/v2/translate';

    $body = [
        'text' => [$text],
        'target_lang' => 'DE'
    ];

    $response = wp_remote_post($api_url, [
        'headers' => [
            'Authorization' => 'DeepL-Auth-Key ' . $auth_key,
            'Content-Type'  => 'application/json',
        ],
        'body' => json_encode($body),
        'timeout' => 15
    ]);

    if (is_wp_error($response)) return $response;

    $data = json_decode(wp_remote_retrieve_body($response), true);

    if (isset($data['translations'][0]['text'])) {
        $result = $data['translations'][0]['text'];
        set_transient($cache_key, $result, MONTH_IN_SECONDS); // Lange cachen
        return $result;
    }

    return new WP_Error('api_error', 'DeepL Antwort fehlerhaft.');
}

/**
 * Regelbasierte Übersetzung für LEGO Teile.
 */
function lww_translate_part_name_rules($name) {
    $dictionary = [
        'Brick' => 'Stein',
        'Plate' => 'Platte',
        'Tile' => 'Fliese',
        'Slope' => 'Dachstein',
        'Curved' => 'gebogen',
        'Round' => 'rund',
        'Corner' => 'Ecke',
        'Inverted' => 'invertiert',
        'Modified' => 'modifiziert',
        'with' => 'mit',
        'Clip' => 'Clip',
        'Stud' => 'Noppe',
        'Studs' => 'Noppen',
        'Technic' => 'Technic',
        'Axle' => 'Achse',
        'Pin' => 'Pin',
        'Beam' => 'Liftarm',
        'Gear' => 'Zahnrad',
        'Wheel' => 'Rad',
        'Tire' => 'Reifen',
        'Windscreen' => 'Windschutzscheibe',
        'Glass' => 'Glas',
        'Door' => 'Tür',
        'Frame' => 'Rahmen',
        'Window' => 'Fenster',
        'Hinge' => 'Scharnier',
        'Baseplate' => 'Bauplatte',
        'Antenna' => 'Antenne',
        'Bar' => 'Stange',
        'Cone' => 'Kegel',
        'Cylinder' => 'Zylinder',
        'Dish' => 'Sat-Schüssel',
        'Fence' => 'Zaun',
        'Flag' => 'Fahne',
        'Hose' => 'Schlauch',
        'Ladder' => 'Leiter',
        'Lever' => 'Hebel',
        'Magnet' => 'Magnet',
        'Minifig' => 'Minifigur',
        'Panel' => 'Paneel',
        'Propeller' => 'Propeller',
        'Rock' => 'Fels',
        'Stairs' => 'Treppe',
        'Support' => 'Stütze',
        'Tail' => 'Heck',
        'Tap' => 'Zapfhahn',
        'Vehicle' => 'Fahrzeug',
        'Wedge' => 'Keilstein',
        'Wing' => 'Flügel',
        'Plant' => 'Pflanze',
        'Flower' => 'Blume',
        'Leaves' => 'Blätter',
        'Tree' => 'Baum',
    ];

    // Einfache Wort-Ersetzung
    foreach ($dictionary as $en => $de) {
        // Wortgrenzen beachten, um Teilwörter nicht falsch zu ersetzen
        $name = preg_replace('/\b' . preg_quote($en, '/') . '\b/i', $de, $name);
    }

    // Grammatik-Korrekturen (einfach)
    $name = str_replace('Stein rund', 'Rundstein', $name);
    $name = str_replace('Platte rund', 'Rundplatte', $name);
    $name = str_replace('Fliese rund', 'Rundfliese', $name);

    return $name;
}
