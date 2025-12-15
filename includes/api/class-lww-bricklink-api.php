<?php
/**
 * Modul: BrickLink API (v26.6)
 * 
 * Implementiert OAuth 1.0a Signierung und Methoden für Inventar, Preis-Guide & Bestellungen.
 * Dokumentation: https://www.bricklink.com/v3/api.page
 */
if (!defined('ABSPATH')) exit;

class LWW_Bricklink_API {
    private $consumer_key;
    private $consumer_secret;
    private $token_value;
    private $token_secret;
    private $api_endpoint = 'https://api.bricklink.com/api/store/v1/';

    public function __construct($ck, $cs, $tv, $ts) {
        $this->consumer_key = $ck;
        $this->consumer_secret = $cs;
        $this->token_value = $tv;
        $this->token_secret = $ts;
    }

    /**
     * Ruft das Inventar ab.
     * GET /inventories
     *
     * Unterstützte Parameter:
     * - status: 'I' (in stock), 'S' (sold), etc.
     * - item_type: 'PART', 'SET', 'MINIFIG', etc.
     * - category_id: BrickLink Kategorie-ID
     * - color_id: BrickLink Farb-ID
     * - condition: 'N' (new), 'U' (used)
     */
    public function get_inventory_list($params = []) {
        // Standard-Parameter setzen falls nicht angegeben
        $defaults = [
            'status' => 'I', // Nur aktive Items standardmäßig
        ];
        $params = array_merge($defaults, $params);

        return $this->make_request('inventories', 'GET', $params);
    }

    /**
     * Ruft Details zu einem einzelnen Inventar-Eintrag ab.
     * GET /inventories/{inventory_id}
     */
    public function get_inventory($inventory_id) {
        if (empty($inventory_id)) return new WP_Error('invalid_id', 'Inventory ID fehlt.');
        return $this->make_request("inventories/{$inventory_id}", 'GET');
    }

    /**
     * Erstellt einen neuen Inventar-Eintrag.
     * POST /inventories
     */
    public function create_inventory($data) {
        return $this->make_request('inventories', 'POST', $data);
    }

    /**
     * Aktualisiert einen Inventar-Eintrag.
     * PUT /inventories/{inventory_id}
     */
    public function update_inventory($inventory_id, $data) {
        if (empty($inventory_id)) return new WP_Error('invalid_id', 'Inventory ID fehlt.');
        return $this->make_request("inventories/{$inventory_id}", 'PUT', $data);
    }

    /**
     * Sucht im Inventar (Wrapper um get_inventory_list mit Filtern).
     */
    public function search_inventory($params) {
        return $this->get_inventory_list($params);
    }

    /**
     * Ruft Bestellungen ab.
     * GET /orders
     * 
     * @param array $params Filter: direction (in/out), status, filed (true/false)
     */
    public function get_orders($params = []) {
        return $this->make_request('orders', 'GET', $params);
    }

    /**
     * Ruft Details einer Bestellung ab.
     * GET /orders/{order_id}
     */
    public function get_order($order_id) {
        if (empty($order_id)) return new WP_Error('invalid_id', 'Order ID fehlt.');
        return $this->make_request("orders/{$order_id}", 'GET');
    }

    /**
     * Ruft die Items einer Bestellung ab.
     * GET /orders/{order_id}/items
     */
    public function get_order_items($order_id) {
        if (empty($order_id)) return new WP_Error('invalid_id', 'Order ID fehlt.');
        return $this->make_request("orders/{$order_id}/items", 'GET');
    }

    /**
     * Ruft den Price Guide ab.
     * GET /items/{type}/{no}/price
     */
    public function get_price_guide($type, $no, $color_id = 0, $condition = 'U', $guide_type = 'sold') {
        $endpoint = sprintf("items/%s/%s/price", strtoupper($type), $no);
        $params = [
            'guide_type' => $guide_type,
            'new_or_used' => $condition,
            'vat' => 'N', // Netto-Preise für Vergleich oft besser, oder 'Y' je nach Strategie
        ];
        if ($color_id > 0) {
            $params['color_id'] = $color_id;
        }

        return $this->make_request($endpoint, 'GET', $params);
    }

    /**
     * Ruft Farben-Liste ab (Katalog).
     * GET /colors
     */
    public function get_colors() {
        return $this->make_request('colors', 'GET');
    }

    /**
     * Ruft Kategorien-Liste ab (Katalog).
     * GET /categories
     */
    public function get_categories() {
        return $this->make_request('categories', 'GET');
    }

    /**
     * Führt den Request mit OAuth 1.0a Signierung durch.
     */
    private function make_request($endpoint, $method = 'GET', $params = []) {
        if (empty($this->consumer_key)) return new WP_Error('no_key', 'Consumer Key fehlt');

        $url = $this->api_endpoint . $endpoint;
        
        $oauth = [
            'oauth_consumer_key' => $this->consumer_key,
            'oauth_nonce' => md5(microtime() . mt_rand()),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => time(),
            'oauth_token' => $this->token_value,
            'oauth_version' => '1.0',
        ];

        // Parameter für Signatur sammeln
        $signature_params = $oauth;
        if ($method === 'GET') {
            $signature_params = array_merge($signature_params, $params);
        }
        // WICHTIG: BrickLink erfordert bei JSON-Body keine Parameter in der Signatur, 
        // es sei denn, sie sind im Query String. Hier gehen wir davon aus, dass POST/PUT Body ist.

        ksort($signature_params);
        
        $base_string_parts = [];
        foreach ($signature_params as $key => $value) {
            $base_string_parts[] = rawurlencode($key) . '=' . rawurlencode($value);
        }
        $base_string = strtoupper($method) . '&' . rawurlencode($url) . '&' . rawurlencode(implode('&', $base_string_parts));

        $signing_key = rawurlencode($this->consumer_secret) . '&' . rawurlencode($this->token_secret);
        $oauth['oauth_signature'] = base64_encode(hash_hmac('sha1', $base_string, $signing_key, true));

        // Auth Header bauen
        ksort($oauth);
        $auth_header_parts = [];
        foreach ($oauth as $key => $value) {
            $auth_header_parts[] = $key . '="' . rawurlencode($value) . '"';
        }
        $auth_header = 'OAuth ' . implode(',', $auth_header_parts);

        $args = [
            'method' => $method,
            'timeout' => 30,
            'headers' => [
                'Authorization' => $auth_header,
                'Content-Type' => 'application/json'
            ]
        ];

        if ($method === 'GET' && !empty($params)) {
            $url .= '?' . http_build_query($params);
        } elseif ($method !== 'GET' && !empty($params)) {
            $args['body'] = json_encode($params);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) return $response;

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        // BrickLink Error Handling
        // Meta code 200 oder 201 ist OK.
        if (isset($data['meta']['code']) && !in_array($data['meta']['code'], [200, 201])) {
            return new WP_Error('api_error', $data['meta']['message'] ?? 'Unknown BrickLink API Error', ['code' => $data['meta']['code']]);
        }

        return $data['data'] ?? [];
    }
}
