<?php
/**
 * Modul: Rebrickable API-Kommunikation (v3.0)
 *
 * Stellt eine Klasse zur Verfügung, um mit der Rebrickable API v3 zu interagieren.
 * Dokumentation: https://rebrickable.com/api/v3/docs/
 */
if (!defined('ABSPATH')) exit;

class LWW_Rebrickable_API {

    private $api_key;
    private $api_endpoint = 'https://rebrickable.com/api/v3/';

    public function __construct($api_key) {
        $this->api_key = $api_key;
    }

    /**
     * Testet die Verbindung zur Rebrickable API durch Abrufen der User-Token-Details.
     *
     * @return bool|WP_Error True bei Erfolg, ansonsten WP_Error.
     */
    public function test_connection() {
        $endpoint = 'users/_token/';
        $response = $this->make_request($endpoint);

        if (is_wp_error($response)) {
            return $response;
        }

        // Rebrickable gibt bei Erfolg Benutzerdetails zurück. 
        return true;
    }

    /**
     * Ruft eine Liste von Farben ab.
     */
    public function get_colors($page = 1, $page_size = 1000) {
        return $this->make_request('lego/colors/', ['page' => $page, 'page_size' => $page_size]);
    }

    /**
     * Ruft Teile-Kategorien ab.
     */
    public function get_part_categories($page = 1, $page_size = 1000) {
        return $this->make_request('lego/part_categories/', ['page' => $page, 'page_size' => $page_size]);
    }

    /**
     * Ruft Themen ab.
     */
    public function get_themes($page = 1, $page_size = 1000) {
        return $this->make_request('lego/themes/', ['page' => $page, 'page_size' => $page_size]);
    }

    /**
     * Ruft Teile ab (Katalog).
     */
    public function get_parts($page = 1, $page_size = 1000, $params = []) {
        $args = array_merge(['page' => $page, 'page_size' => $page_size], $params);
        return $this->make_request('lego/parts/', $args);
    }

    /**
     * Ruft ein einzelnes Teil anhand der Nummer ab.
     */
    public function get_part($part_num) {
        return $this->make_request("lego/parts/{$part_num}/");
    }

    /**
     * Ruft Sets ab.
     * Unterstützt Filter wie theme_id, min_year, max_year.
     */
    public function get_sets($page = 1, $page_size = 1000, $params = []) {
        $args = array_merge(['page' => $page, 'page_size' => $page_size], $params);
        return $this->make_request('lego/sets/', $args);
    }

    /**
     * Ruft ein einzelnes Set anhand der Nummer ab.
     */
    public function get_set($set_num) {
        return $this->make_request("lego/sets/{$set_num}/");
    }

    /**
     * Ruft Minifiguren ab.
     */
    public function get_minifigs($page = 1, $page_size = 1000, $params = []) {
        $args = array_merge(['page' => $page, 'page_size' => $page_size], $params);
        return $this->make_request('lego/minifigs/', $args);
    }

    /**
     * Ruft eine einzelne Minifigur ab.
     */
    public function get_minifig($fig_num) {
        return $this->make_request("lego/minifigs/{$fig_num}/");
    }

    /**
     * Ruft Elemente (Part+Color Kombinationen) ab.
     */
    public function get_elements($page = 1, $page_size = 1000) {
        return $this->make_request('lego/elements/', ['page' => $page, 'page_size' => $page_size]);
    }

    /**
     * Macht eine Anfrage an die Rebrickable API.
     *
     * @param string $endpoint Der API-Endpunkt (z.B. 'parts/3001/').
     * @param array  $params   GET-Parameter.
     * @return array|WP_Error Die decodierte JSON-Antwort oder ein WP_Error.
     */
    private function make_request($endpoint, $params = []) {
        if (empty($this->api_key)) {
            return new WP_Error('no_api_key', __('Kein Rebrickable API-Schlüssel konfiguriert.', 'lego-wawi'));
        }

        $url = $this->api_endpoint . $endpoint;
        if (!empty($params)) {
            $url = add_query_arg($params, $url);
        }

        $args = [
            'headers' => [
                'Authorization' => 'key ' . $this->api_key,
                'Accept' => 'application/json',
            ],
            'timeout' => 30,
        ];

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            lww_log_api_call('rebrickable', $endpoint, false, 0, ['error' => $response->get_error_message()]);
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($response_code !== 200) {
            // Spezielle Behandlung für Throttling (429)
            if ($response_code === 429) {
                $wait = wp_remote_retrieve_header($response, 'retry-after') ?: 10;
                lww_log_api_call('rebrickable', $endpoint, false, 0, ['error' => 'Throttled', 'retry_after' => $wait]);
                return new WP_Error('api_throttled', sprintf(__('API Limit erreicht. Bitte %d Sekunden warten.', 'lego-wawi'), $wait));
            }

            $error_message = isset($data['detail']) ? $data['detail'] : __('Unbekannter Rebrickable API Fehler.', 'lego-wawi');
            lww_log_api_call('rebrickable', $endpoint, false, 0, ['error' => $error_message, 'response_code' => $response_code]);
            return new WP_Error('rebrickable_api_error', $error_message);
        }

        lww_log_api_call('rebrickable', $endpoint, true, 0); 
        return $data;
    }
}
