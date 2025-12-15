<?php
/**
 * Modul: Brickset API (v3.0)
 * 
 * Implementierung gemäß Dokumentation: https://brickset.com/article/52664/api-version-3-documentation
 * Die API nutzt JSON-formatierte Parameter im Query-String ('params').
 */
if (!defined('ABSPATH')) exit;

class LWW_Brickset_API {
    private $api_key;
    private $base_url = 'https://brickset.com/api/v3.asmx/';

    public function __construct($api_key) {
        $this->api_key = $api_key;
    }

    /**
     * Testet die Verbindung und Gültigkeit des API Keys.
     * Nutzt die 'checkKey' Methode der API.
     */
    public function test_connection() {
        $result = $this->request('checkKey', []);
        if (is_wp_error($result)) return $result;
        
        // checkKey gibt 'status' => 'success' zurück, wenn der Key gültig ist.
        return true;
    }

    /**
     * Ruft Sets ab.
     * @param array $params Suchparameter (z.B. ['setNumber' => '75192-1', 'year' => '2020'])
     */
    public function getSets($params) {
        return $this->request('getSets', $params);
    }
    
    /**
     * Ruft Zusätzliche Bilder für ein Set ab.
     * @param int $setID Die interne Brickset SetID
     */
    public function getAdditionalImages($setID) {
        return $this->request('getAdditionalImages', ['setID' => $setID]);
    }

    /**
     * Ruft Minifiguren aus dem Katalog ab.
     * ACHTUNG: 'getMinifigCollection' ist für User-Inventar. 'getMinifigures' ist für den Katalog.
     */
    public function getMinifigures($params) {
        return $this->request('getMinifigures', $params);
    }

    /**
     * Sendet den Request an die API.
     */
    private function request($method, $params) {
        if (empty($this->api_key)) return new WP_Error('no_key', 'API Key fehlt');

        // Brickset v3 erwartet Parameter als JSON-String im GET-Parameter 'params'
        $query_args = [
            'apiKey' => $this->api_key,
            'userHash' => '', // Leer für öffentliche Abfragen, kann bei Bedarf erweitert werden
            'params' => json_encode($params)
        ];

        $url = $this->base_url . $method . '?' . http_build_query($query_args);
        
        $response = wp_remote_get($url, ['timeout' => 20]);
        if (is_wp_error($response)) return $response;

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', 'Ungültige JSON-Antwort von Brickset.');
        }

        if (isset($data['status']) && $data['status'] !== 'success') {
            return new WP_Error('api_error', $data['message'] ?? 'Unbekannter Brickset API Fehler', ['code' => $data['status']]);
        }

        return $data;
    }
}
