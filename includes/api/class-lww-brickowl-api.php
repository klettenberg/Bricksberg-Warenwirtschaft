<?php
/**
 * Modul: BrickOwl API-Kommunikation (v13.0)
 *
 * Stellt eine Klasse zur Verfügung, um mit der BrickOwl API zu interagieren.
 * HINWEIS: Aktuell ist die Funktionalität SIMULIERT.
 */
if (!defined('ABSPATH')) exit;

class LWW_BrickOwl_API {

    /**
     * Der API-Schlüssel für BrickOwl.
     * @var string
     */
    private $api_key;

    /**
     * Der Endpunkt der BrickOwl API.
     * @var string
     */
    private $api_endpoint = 'https://api.brickowl.com/v1';

    /**
     * Konstruktor.
     *
     * @param string $api_key Der zu verwendende API-Schlüssel.
     */
    public function __construct($api_key) {
        $this->api_key = $api_key;
    }

    /**
     * Ruft den Preis für einen bestimmten Artikel (BOID) ab.
     *
     * HINWEIS: SIMULATION - gibt einen zufälligen Preis zurück.
     * In einer echten Implementierung würde hier ein wp_remote_get() Aufruf stehen.
     *
     * @param string $boid Die BrickOwl ID des Artikels.
     * @return float|WP_Error Der Preis als Fließkommazahl oder ein Fehlerobjekt.
     */
    public function get_item_price($boid) {
        if (empty($boid)) {
            return new WP_Error('invalid_boid', 'Die angegebene BOID ist ungültig.');
        }

        $endpoint = sprintf('/catalog/id_lookup?boid=%s', urlencode($boid));
        $request_url = $this->api_endpoint . $endpoint . '&key=' . $this->api_key;

        // --- ECHTER API CALL WÜRDE HIER ERFOLGEN ---
        // $response = wp_remote_get($request_url, ['timeout' => 15]);
        // if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) { ... }
        // $body = wp_remote_retrieve_body($response);
        // $data = json_decode($body, true);
        // $price = (float) $data[0]['price']; // Annahme der Datenstruktur

        // --- SIMULATION --- 
        lww_log_system_event('SIMULATION (BrickOwl): Rufe Preis für BOID ' . $boid . ' ab. URL: ' . $request_url);
        
        // Simuliere einen Erfolg oder Fehlschlag
        $is_success = (rand(1, 10) > 1); // 90% Erfolgschance
        $simulated_cost = 0.0001; // Simulierte Kosten für einen einfachen API-Call

        if (!$is_success) {
            lww_log_api_call('brickowl', 'catalog/id_lookup', false, 0, ['boid' => $boid, 'error' => 'Simulated API failure']);
            return new WP_Error('api_error', 'Simulierter Fehler bei der BrickOwl API-Anfrage.');
        }

        // Generiere einen zufälligen Preis zwischen 0.010 und 5.000
        $simulated_price = round(rand(10, 5000) / 1000, 3);

        lww_log_api_call('brickowl', 'catalog/id_lookup', true, $simulated_cost, ['boid' => $boid, 'price' => $simulated_price]);

        return $simulated_price;
        // --- ENDE SIMULATION ---
    }
}
