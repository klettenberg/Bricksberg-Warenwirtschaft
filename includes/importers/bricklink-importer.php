<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bricksberg_Bricklink_Importer
 *
 * Parses Bricklink inventory XML and returns a normalized inventory array.
 */
class Bricksberg_Bricklink_Importer {

    /**
     * Detects whether the given XML string looks like a Bricklink inventory export.
     * Checks for root tags and required child elements.
     *
     * @param string $xmlString
     * @return bool
     */
    public static function detectFormat($xmlString) {
        if (empty(trim($xmlString))) {
            return false;
        }

        // Use regex to detect root element names more robustly (case-insensitive)
        if (preg_match('/<\s*inventory\b/i', $xmlString) || preg_match('/<\s*item\b/i', $xmlString)) {
            return true;
        }

        // Check for ITEMID plus QTY tokens to be more forgiving
        if (preg_match('/<\s*itemid\b/i', $xmlString) && preg_match('/<\s*qty\b/i', $xmlString)) {
            return true;
        }

        return false;
    }

    /**
     * Parse Bricklink XML string and convert to normalized inventory array.
     *
     * Return format:
     * [
     *   'items' => [
     *      ['item_id' => '3001', 'color_id' => '1', 'sku' => '3001-1', 'name' => 'Brick 2x4', 'type' => 'P', 'qty' => 4, 'unit_price' => 0.05],
     *      ...
     *   ],
     *   'errors' => ['error message strings']
     * ]
     *
     * @param string $xmlString
     * @return array|WP_Error
     */
    public function parseFromString($xmlString) {
        if (!self::detectFormat($xmlString)) {
            return new WP_Error('invalid_format', __('Die Datei scheint kein Bricklink-Inventar-XML zu sein.', 'lego-wawi'));
        }

        libxml_use_internal_errors(true);
        // For security: avoid remote entity loading
        $xml = simplexml_load_string($xmlString, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        if ($xml === false) {
            $errs = libxml_get_errors();
            $errorList = [];
            foreach ($errs as $err) {
                $errorList[] = trim($err->message);
            }
            libxml_clear_errors();
            return new WP_Error('xml_parse_error', __('Fehler beim Parsen des XML.', 'lego-wawi') . ' ' . implode('; ', $errorList));
        }

        // Generic xpath to locate all ITEM nodes regardless of namespaces/structure
        $itemsNodes = $xml->xpath('//*[local-name()="ITEM"]');
        if (empty($itemsNodes)) {
            return new WP_Error('no_items', __('Keine Items im Inventar gefunden.', 'lego-wawi'));
        }

        $resultItems = [];
        $errors = [];

        $index = 0;
        foreach ($itemsNodes as $itemNode) {
            $index++;
            if (!($itemNode instanceof SimpleXMLElement)) {
                $errors[] = sprintf(__('Item #%d ist kein gültiger XML-Knoten.', 'lego-wawi'), $index);
                continue;
            }

            $mappedOrError = $this->mapItem($itemNode);
            if (is_wp_error($mappedOrError)) {
                $errors[] = sprintf(__('Item #%d Fehler: %s', 'lego-wawi'), $index, $mappedOrError->get_error_message());
                continue;
            } elseif ($mappedOrError === null) {
                $errors[] = sprintf(__('Item #%d wurde übersprungen (fehlende Pflichtfelder).', 'lego-wawi'), $index);
                continue;
            }

            $mapped = $mappedOrError;

            $key = $mapped['sku'];
            if (isset($resultItems[$key])) {
                $resultItems[$key]['qty'] += $mapped['qty'];
            } else {
                $resultItems[$key] = $mapped;
            }
        }

        $itemsList = array_values($resultItems);

        return [
            'items' => $itemsList,
            'errors' => $errors,
        ];
    }

    /**
     * Import by file path helper (uses parseFromString).
     *
     * @param string $filePath
     * @return array|WP_Error
     */
    public function importFile($filePath) {
        if (!file_exists($filePath)) {
            return new WP_Error('file_not_found', __('Datei nicht gefunden.', 'lego-wawi'));
        }

        $contents = file_get_contents($filePath);
        if ($contents === false) {
            return new WP_Error('file_read_error', __('Fehler beim Lesen der Datei.', 'lego-wawi'));
        }

        return $this->parseFromString($contents);
    }

    /**
     * Map a single SimpleXMLElement ITEM node into internal structure.
     *
     * @param SimpleXMLElement $node
     * @return array|null
     */
    private function mapItem(SimpleXMLElement $node) {
        // Get itemid (check multiple tag names)
        $itemID = $this->nodeValue($node, ['ITEMID', 'ItemID', 'ITEM_NUMBER', 'ITEMNUMBER', 'ITEM_ID']);
        $qty = $this->nodeValue($node, ['QTY', 'QTYINSTOCK', 'QTYINVENTORY', 'QUANTITY', 'QUANTITYONHAND']);
        $colorID = $this->nodeValue($node, ['COLORID', 'COLOR', 'COLOURID']);

        if ($itemID === '' || $qty === '') {
            return null;
        }

        // cast and normalize qty (int)
        $qtyVal = (int) round(floatval(str_replace(',', '.', $qty)));
        if ($qtyVal <= 0) {
            // It's valid but quantity zero => skip
            $qtyVal = 0;
        }

        $unitPrice = $this->nodeValue($node, ['UNITPRICE', 'PRICE', 'COST']);
        $unitPriceVal = $unitPrice !== '' ? floatval(str_replace(',', '.', $unitPrice)) : 0.0;

        $name = $this->nodeValue($node, ['ITEMNAME', 'NAME', 'DESCRIPTION']);
        $type = $this->nodeValue($node, ['ITEMTYPE', 'TYPE']);

        // SKU fallback: if provided as field use it, else itemid + color
        $skuField = $this->nodeValue($node, ['SKU', 'STORAGE_SKU', 'CUSTOM_SKU']);
        $sku = $skuField !== '' ? $skuField : $itemID . ($colorID !== '' ? '-' . $colorID : '');

        return [
            'item_id' => $itemID,
            'color_id' => $colorID,
            'sku' => $sku,
            'name' => $name ?: null,
            'type' => $type ?: null,
            'qty' => $qtyVal,
            'unit_price' => $unitPriceVal,
        ];
    }

    /**
     * Helper to get node value with possible fallback alternatives.
     *
     * @param SimpleXMLElement $node
     * @param string|array $names
     * @return string
     */
    private function nodeValue(SimpleXMLElement $node, $names) {
        if (!is_array($names)) {
            $names = [$names];
        }
        foreach ($names as $name) {
            // use relative descendent search to find nodes anywhere under ITEM
            $xpath = $node->xpath('.//*[local-name()="' . $name . '"]');
            if (!empty($xpath) && isset($xpath[0]) && (string)$xpath[0] !== '') {
                return trim((string)$xpath[0]);
            }
        }

        return '';
    }

    /**
     * Optional helper that returns items ready to be persisted.
     * This is intentionally light — integrate with LWW_Core or storage in your workflow.
     *
     * @param array $items list of mapped items
     * @return array stats result ['count' => X, 'skus' => Y]
     */
    public function importToInventory(array $items) {
        // ...existing code...
        $skus = array_column($items, 'sku');
        return [
            'count' => count($items),
            'skus' => array_values(array_unique($skus)),
        ];
    }
}
