<?php
/**
 * Modul: eBay API-Kommunikation
 *
 * Stellt eine Klasse zur Verfügung, um mit der eBay API zu interagieren.
 * HINWEIS: Die Funktionalität ist SIMULIERT.
 */
if (!defined('ABSPATH')) exit;

class LWW_eBay_API {

    private $api_settings;

    /**
     * Konstruktor.
     * @param array $api_settings Die API-Einstellungen aus den Optionen.
     */
    public function __construct($api_settings) {
        $this->api_settings = $api_settings;
    }

    /**
     * Ruft eine Liste der aktiven Angebots-IDs des Verkäufers ab.
     *
     * SIMULATION: Gibt eine feste Liste von Angebots-IDs zurück.
     * @return array|WP_Error Ein Array von IDs oder ein Fehlerobjekt.
     */
    public function get_active_listings() {
        lww_log_system_event('SIMULATION (eBay): Rufe aktive Angebote ab.');
        lww_log_api_call('ebay', 'getActiveListings', true, 0.001);
        
        // Simulierte Angebots-IDs
        return [
            '110101101011', // Simuliertes Set ohne Varianten
            '110101101012', // Simuliertes Set mit Varianten
            '110101101013', // Simulierte Minifigur ohne Varianten
            '110101101014', // Artikel mit ungültiger SKU
        ];
    }

    /**
     * Ruft die Details für ein einzelnes Angebot ab, inklusive Varianten.
     *
     * SIMULATION: Gibt simulierte Detaildaten basierend auf der ID zurück.
     * @param string $listing_id Die eBay Angebots-ID.
     * @return array|WP_Error Ein Array mit Angebotsdetails oder ein Fehlerobjekt.
     */
    public function get_item_details($listing_id) {
        lww_log_system_event('SIMULATION (eBay): Rufe Details für Angebot ' . $listing_id . ' ab.');
        lww_log_api_call('ebay', 'getItem', true, 0.001, ['listing_id' => $listing_id]);

        switch ($listing_id) {
            case '110101101011': // Set ohne Varianten
                return [
                    'sku' => '75192-1',
                    'title' => 'LEGO Star Wars Millennium Falcon',
                    'price' => 799.99,
                    'quantity' => 1,
                    'condition' => 'New',
                    'variations' => [],
                ];

            case '110101101012': // Set mit Varianten
                return [
                    'sku' => '10305-1', // Lion Knights' Castle
                    'title' => 'LEGO Icons Lion Knights\' Castle',
                    'condition' => 'New',
                    'variations' => [
                        [
                            'variation_sku' => '10305-1-neu-ovp',
                            'variation_name' => 'Zustand: Neu & OVP',
                            'price' => 399.99,
                            'quantity' => 2,
                            'condition' => 'new',
                        ],
                        [
                            'variation_sku' => '10305-1-gebraucht-komplett',
                            'variation_name' => 'Zustand: Gebraucht, komplett',
                            'price' => 320.00,
                            'quantity' => 1,
                            'condition' => 'used',
                        ],
                    ],
                ];

            case '110101101013': // Minifigur ohne Varianten
                return [
                    'sku' => 'sw0001a',
                    'title' => 'LEGO Star Wars Battle Droid',
                    'price' => 1.50,
                    'quantity' => 150,
                    'condition' => 'Used',
                    'variations' => [],
                ];

            case '110101101014': // Ungültige SKU
                 return [
                    'sku' => 'invalid-sku-999',
                    'title' => 'Artikel mit falscher SKU',
                    'price' => 10.00,
                    'quantity' => 1,
                    'condition' => 'Used',
                    'variations' => [],
                ];

            default:
                return new WP_Error('ebay_api_error', 'Simuliertes Angebot nicht gefunden.');
        }
    }
}