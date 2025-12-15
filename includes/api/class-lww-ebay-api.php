<?php
/**
 * Modul: eBay API-Kommunikation (v15.0)
 *
 * Stellt eine Klasse zur Verfügung, um mit der eBay Trading API zu interagieren.
 * Ersetzt die vorherige Simulation.
 */
if (!defined('ABSPATH')) exit;

class LWW_eBay_API {

    private $api_settings;
    private $api_endpoint_production = 'https://api.ebay.com/ws/api.dll';
    private $api_endpoint_sandbox = 'https://api.sandbox.ebay.com/ws/api.dll';
    private $site_id = 77; // Deutschland
    private $compat_level = 967;

    /**
     * Konstruktor.
     * @param array $api_settings Die API-Einstellungen aus den Optionen.
     */
    public function __construct($api_settings) {
        $this->api_settings = $api_settings;
    }

    /**
     * Ruft eine Liste der aktiven Angebots-IDs des Verkäufers ab.
     * @return array|WP_Error Ein Array von IDs oder ein Fehlerobjekt.
     */
    public function get_active_listings() {
        lww_log_system_event('INFO (eBay): Rufe aktive Angebote ab.');
        
        $xml_body = '<?xml version="1.0" encoding="utf-8"?>' .
                    '<GetMyeBaySellingRequest xmlns="urn:ebay:apis:eBLBaseComponents">' .
                    '<RequesterCredentials><eBayAuthToken>' . esc_html($this->api_settings['ebay_auth_token']) . '</eBayAuthToken></RequesterCredentials>' .
                    '<ActiveList><Sort>TimeLeft</Sort><Pagination><EntriesPerPage>200</EntriesPerPage><PageNumber>1</PageNumber></Pagination></ActiveList>' .
                    '</GetMyeBaySellingRequest>';

        $response = $this->make_request('GetMyeBaySelling', $xml_body);

        if (is_wp_error($response)) {
            lww_log_api_call('ebay', 'GetMyeBaySelling', false, 0, ['error' => $response->get_error_message()]);
            return $response;
        }

        $listing_ids = [];
        if (isset($response->ActiveList->ItemArray->Item)) {
            foreach ($response->ActiveList->ItemArray->Item as $item) {
                $listing_ids[] = (string) $item->ItemID;
            }
        }
        
        lww_log_api_call('ebay', 'GetMyeBaySelling', true, 0.001, ['count' => count($listing_ids)]);
        return $listing_ids;
    }

    /**
     * Ruft die Details für ein einzelnes Angebot ab, inklusive Varianten.
     * @param string $listing_id Die eBay Angebots-ID.
     * @return array|WP_Error Ein Array mit Angebotsdetails oder ein Fehlerobjekt.
     */
    public function get_item_details($listing_id) {
        lww_log_system_event('INFO (eBay): Rufe Details für Angebot ' . $listing_id . ' ab.');

        $xml_body = '<?xml version="1.0" encoding="utf-8"?>' .
                    '<GetItemRequest xmlns="urn:ebay:apis:eBLBaseComponents">' .
                    '<RequesterCredentials><eBayAuthToken>' . esc_html($this->api_settings['ebay_auth_token']) . '</eBayAuthToken></RequesterCredentials>' .
                    '<ItemID>' . esc_html($listing_id) . '</ItemID>' .
                    '<IncludeItemSpecifics>true</IncludeItemSpecifics>' .
                    '<DetailLevel>ReturnAll</DetailLevel>' .
                    '</GetItemRequest>';

        $response = $this->make_request('GetItem', $xml_body);

        if (is_wp_error($response)) {
            lww_log_api_call('ebay', 'GetItem', false, 0, ['listing_id' => $listing_id, 'error' => $response->get_error_message()]);
            return $response;
        }

        $item = $response->Item;
        $details = [
            'sku' => (string) $item->SKU,
            'title' => (string) $item->Title,
            'price' => (float) $item->StartPrice,
            'quantity' => (int) $item->Quantity - (int)($item->SellingStatus->QuantitySold ?? 0),
            'condition' => (string) $item->ConditionDisplayName,
            'variations' => [],
        ];

        if (isset($item->Variations->Variation)) {
            foreach ($item->Variations->Variation as $variation) {
                $details['variations'][] = [
                    'sku' => (string) $variation->SKU, // KORREKTUR: Variation SKU verwenden
                    'variation_name' => implode(', ', (array)($variation->VariationSpecifics->NameValueList->Value ?? [])),
                    'price' => (float) $variation->StartPrice,
                    'quantity' => (int) $variation->Quantity - (int)($variation->SellingStatus->QuantitySold ?? 0),
                    'condition' => (string) $item->ConditionDisplayName,
                ];
            }
        }

        lww_log_api_call('ebay', 'GetItem', true, 0.001, ['listing_id' => $listing_id]);
        return $details;
    }

    /**
     * Erstellt oder aktualisiert ein Angebot auf eBay.
     * @param array $data Die aufbereiteten Daten für das Angebot.
     * @param string|null $listing_id Die Angebots-ID, falls es ein Update ist.
     * @return string|WP_Error Die neue/aktualisierte Angebots-ID oder ein Fehlerobjekt.
     */
    public function create_or_update_listing($data, $listing_id = null) {
        $action = $listing_id ? 'ReviseFixedPriceItem' : 'AddFixedPriceItem';
        lww_log_system_event('INFO (eBay): Führe Aktion ' . $action . ' aus für SKU: ' . ($data['sku'] ?? 'N/A'));
        
        $xml_body = '<?xml version="1.0" encoding="utf-8"?>';
        $xml_body .= '<' . $action . 'Request xmlns="urn:ebay:apis:eBLBaseComponents">';
        $xml_body .= '<RequesterCredentials><eBayAuthToken>' . esc_html($this->api_settings['ebay_auth_token']) . '</eBayAuthToken></RequesterCredentials>';
        $xml_body .= '<Item>';
        if ($listing_id) {
            $xml_body .= '<ItemID>' . esc_html($listing_id) . '</ItemID>';
        }
        if (!$listing_id) { // Diese Felder sind beim Revise meist nicht änderbar
            $xml_body .= '<Country>DE</Country>';
            $xml_body .= '<Currency>EUR</Currency>';
            $xml_body .= '<PrimaryCategory><CategoryID>' . esc_html($data['category_id'] ?? '19006') . '</CategoryID></PrimaryCategory>'; 
            $xml_body .= '<ListingDuration>GTC</ListingDuration>';
            $xml_body .= '<ListingType>FixedPriceItem</ListingType>';
        }
        $xml_body .= '<Title>' . esc_html($data['title']) . '</Title>';
        $xml_body .= '<SKU>' . esc_html($data['sku']) . '</SKU>';
        $xml_body .= '<StartPrice>' . esc_html($data['price']) . '</StartPrice>';
        $xml_body .= '<Quantity>' . esc_html($data['quantity']) . '</Quantity>';
        $xml_body .= '<Description><![CDATA[' . $data['description'] . ']]></Description>';
        
        // NEU: ConditionID und ConditionDescription hinzufügen
        if (!empty($data['condition_id'])) {
            $xml_body .= '<ConditionID>' . esc_html($data['condition_id']) . '</ConditionID>';
        }
        if (!empty($data['condition_description'])) {
            $xml_body .= '<ConditionDescription><![CDATA[' . esc_html($data['condition_description']) . ']]></ConditionDescription>';
        }

        // NEU: Mehrere Bilder unterstützen
        if (!empty($data['image_urls']) && is_array($data['image_urls'])) {
            $xml_body .= '<PictureDetails>';
            foreach ($data['image_urls'] as $url) {
                $xml_body .= '<PictureURL>' . esc_url_raw($url) . '</PictureURL>';
            }
            $xml_body .= '</PictureDetails>';
        }
        
        $xml_body .= '<ReturnPolicy><ReturnsAcceptedOption>ReturnsAccepted</ReturnsAcceptedOption></ReturnPolicy>';
        $xml_body .= '</Item>';
        $xml_body .= '</' . $action . 'Request>';

        $response = $this->make_request($action, $xml_body);

        if (is_wp_error($response)) {
            lww_log_api_call('ebay', $action, false, 0.05, ['sku' => $data['sku'] ?? 'N/A', 'error' => $response->get_error_message()]);
            return $response;
        }

        $new_listing_id = (string) $response->ItemID;
        lww_log_api_call('ebay', $action, true, 0.05, ['sku' => $data['sku'] ?? 'N/A', 'listing_id' => $new_listing_id]);

        return $new_listing_id;
    }

    /**
     * Führt eine Anfrage an die eBay Trading API durch.
     *
     * @param string $callName Der Name der API-Aktion (z.B. 'GetItem').
     * @param string $xml_body Der vollständige XML-Body der Anfrage.
     * @return SimpleXMLElement|WP_Error Das XML-Antwortobjekt oder ein WP_Error.
     */
    private function make_request($callName, $xml_body) {
        $headers = [
            'X-EBAY-API-COMPATIBILITY-LEVEL' => $this->compat_level,
            'X-EBAY-API-DEV-NAME'          => $this->api_settings['ebay_dev_id'],
            'X-EBAY-API-APP-NAME'          => $this->api_settings['ebay_app_id'],
            'X-EBAY-API-CERT-NAME'         => $this->api_settings['ebay_cert_id'],
            'X-EBAY-API-CALL-NAME'         => $callName,
            'X-EBAY-API-SITEID'            => $this->site_id,
            'Content-Type'                 => 'text/xml; charset=utf-8',
        ];

        $response = wp_remote_post($this->api_endpoint_production, [
            'headers' => $headers,
            'body'    => $xml_body,
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $response_body = wp_remote_retrieve_body($response);
        $xml_response = simplexml_load_string($response_body);

        if ($xml_response === false) {
            return new WP_Error('ebay_xml_error', __('Ungültige XML-Antwort von der eBay API.', 'lego-wawi'));
        }

        if ((string) $xml_response->Ack !== 'Success' && (string) $xml_response->Ack !== 'Warning') {
            $error_message = (string) $xml_response->Errors->LongMessage ?? __('Unbekannter eBay API Fehler.', 'lego-wawi');
            return new WP_Error('ebay_api_error', $error_message);
        }

        return $xml_response;
    }
}
