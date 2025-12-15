<?php
/**
 * Modul: BrickOwl API-Kommunikation (v20.0)
 * 
 * Gehärtete Version für robustere Abfragen.
 * UPDATE: Besseres Logging bei JSON-Fehlern.
 */
if (!defined('ABSPATH')) exit;

class LWW_BrickOwl_API {

    private $api_key;
    private $api_endpoint = 'https://api.brickowl.com/v1/';

    public function __construct($api_key) {
        $this->api_key = $api_key;
    }

    public function get_item_price($boid) {
        $market_data = $this->get_item_market_data($boid);
        if (is_wp_error($market_data)) return $market_data;
        return (float) ($market_data['price'] ?? 0.0);
    }

    public function get_item_market_data($lookup_value) {
        if (empty($lookup_value) && $lookup_value !== '0') return new WP_Error('invalid', 'Empty Lookup');
        $param = is_numeric($lookup_value) ? 'id' : 'boid';
        $response = $this->make_request('catalog/id_lookup', 'GET', [], [$param => $lookup_value]);
        if (is_wp_error($response)) return $response;
        if (empty($response) || !is_array($response) || !isset($response[0])) {
             return new WP_Error('not_found', 'Kein Artikel gefunden für ' . $lookup_value);
        }
        return $response[0];
    }

    /**
     * Ruft die komplette Inventar-Liste ab.
     * GET /inventory/list
     *
     * Gibt alle Lots im BrickOwl-Inventar zurück.
     * Jeder Lot enthält: boid, qty, price, condition, etc.
     */
    public function get_inventory_list() {
        return $this->make_request('inventory/list', 'GET');
    }

    public function update_inventory_item($lot_id, $data) {
        if (empty($lot_id)) return new WP_Error('invalid_lot', 'Lot ID fehlt');
        return $this->make_request('inventory/update', 'POST', array_merge($data, ['lot_id' => $lot_id]));
    }

    public function get_orders_list($min_time = null) {
        $params = [];
        if ($min_time) $params['min_time'] = $min_time;
        return $this->make_request('order/list', 'GET', [], $params);
    }

    public function get_order_details($order_id) {
        if (empty($order_id)) return new WP_Error('invalid_id', 'Order ID fehlt');
        return $this->make_request('order/view', 'GET', [], ['order_id' => $order_id]);
    }

    public function get_color_list() {
        return $this->make_request('catalog/color_list', 'GET');
    }

    public function get_category_list() {
        return $this->make_request('catalog/category_list', 'GET');
    }
    
    private function make_request($endpoint, $method = 'GET', $body = [], $params = []) {
        if (empty($this->api_key)) return new WP_Error('no_key', 'API Key fehlt');
        
        $url = $this->api_endpoint . $endpoint;
        $params['key'] = $this->api_key;
        
        $args = [
            'timeout' => 60,
            'method' => $method,
            'user-agent' => 'BricksbergWaWi/6.0 (WordPress Plugin)', 
        ]; 

        if ($method === 'GET') {
            $url .= '?' . http_build_query($params);
        } else {
            $args['body'] = array_merge($params, $body);
        }

        $res = wp_remote_request($url, $args);
        if (is_wp_error($res)) return $res;
        
        $code = wp_remote_retrieve_response_code($res);
        $raw_body = wp_remote_retrieve_body($res);
        $data = json_decode($raw_body, true);
        
        if ($code >= 400) {
            $error_msg = $data['message'] ?? 'Unbekannter API Fehler';
            if ($error_msg === 'Unbekannter API Fehler' && !empty($raw_body)) {
                $error_msg .= ' (Raw: ' . mb_strimwidth(strip_tags($raw_body), 0, 100, '...') . ')';
            }
            return new WP_Error('api_error', sprintf('%s (HTTP %d)', $error_msg, $code), ['code' => $code, 'body' => $raw_body]);
        }
        
        if (json_last_error() !== JSON_ERROR_NONE) {
             // Raw Body logging for debug
             lww_write_debug_log("BrickOwl JSON Error: " . substr($raw_body, 0, 500), 'ERROR');
             return new WP_Error('json_error', 'Ungültige JSON Antwort von BrickOwl: ' . mb_strimwidth($raw_body, 0, 100));
        }
        
        return $data;
    }
}
