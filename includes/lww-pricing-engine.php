<?php
/**
 * Modul: Pricing Engine (v1.2-DECIMAL)
 * 
 * Zentrale Logik zur Berechnung von Zielpreisen.
 * UPDATE: Unterstützt 3 Dezimalstellen (z.B. für Teile mit 0.005 €).
 */
if (!defined('ABSPATH')) exit;

/**
 * Berechnet den empfohlenen Preis für ein Inventar-Item.
 * 
 * @param int $item_id Die ID des lww_inventory_item Posts.
 * @return array ['price' => float, 'source' => string, 'details' => string] oder WP_Error.
 */
function lww_calculate_target_price($item_id) {
    $strategy_source = get_option('lww_price_strategy_source', 'bricklink_avg');
    $strategy_modifier = (float) get_option('lww_price_strategy_modifier', 0); // Prozent
    $rounding_rule = get_option('lww_price_strategy_rounding', 'none'); // none, 0.05, 0.09, 0.99

    $market_prices = get_post_meta($item_id, '_lww_market_prices', true);
    if (empty($market_prices) || !is_array($market_prices)) {
        return new WP_Error('no_data', __('Keine Marktdaten für diesen Artikel verfügbar.', 'lego-wawi'));
    }

    $base_price = 0.0;
    $source_label = '';

    // 1. Basispreis ermitteln
    switch ($strategy_source) {
        case 'bricklink_min':
            $base_price = (float) ($market_prices['bricklink']['min_price'] ?? $market_prices['bricklink']['price'] ?? 0);
            $source_label = 'BrickLink Minimum';
            break;
        case 'bricklink_avg':
            $base_price = (float) ($market_prices['bricklink']['price'] ?? 0);
            $source_label = 'BrickLink Durchschnitt';
            break;
        case 'brickowl_min':
            $base_price = (float) ($market_prices['brickowl']['min_price'] ?? $market_prices['brickowl']['price'] ?? 0);
            $source_label = 'BrickOwl Minimum';
            break;
        case 'brickowl_avg':
            $base_price = (float) ($market_prices['brickowl']['price'] ?? 0);
            $source_label = 'BrickOwl Durchschnitt';
            break;
        default:
            // Fallback auf BL Avg
            $base_price = (float) ($market_prices['bricklink']['price'] ?? 0);
            $source_label = 'BrickLink Durchschnitt (Fallback)';
    }

    if ($base_price <= 0) {
        return new WP_Error('invalid_price', sprintf(__('Basispreis konnte mit Strategie "%s" nicht ermittelt werden (0.00 €).', 'lego-wawi'), $source_label));
    }

    // 2. Modifikator anwenden
    if ($strategy_modifier != 0) {
        $base_price = $base_price * (1 + ($strategy_modifier / 100));
    }

    // 3. Runden
    $final_price = $base_price;
    switch ($rounding_rule) {
        case '0.05':
            $final_price = round($base_price * 20) / 20;
            break;
        case '0.09':
            $final_price = floor($base_price * 10) / 10 + 0.09;
            break;
        case '0.99':
            $final_price = floor($base_price) + 0.99;
            break;
        case 'none':
        default:
            // UPDATE: Standard 3 Dezimalstellen für Teile, aber keine erzwungene Rundung
            $final_price = round($base_price, 3); 
            break;
    }

    return [
        'price' => $final_price,
        'source' => $source_label,
        'details' => sprintf(__('%s %s%% (%s)', 'lego-wawi'), $source_label, ($strategy_modifier > 0 ? '+' : '') . $strategy_modifier, $rounding_rule)
    ];
}

/**
 * Aktualisiert den Preis eines Artikels sicher und protokolliert die Änderung.
 * 
 * @param int $item_id Die ID des Inventar-Items.
 * @param float $new_price Der neue Preis.
 * @param string $context Der Kontext der Änderung (z.B. 'bulk_update', 'ai_suggestion', 'manual').
 * @return bool True, wenn der Preis geändert wurde, sonst False.
 */
function lww_update_item_price($item_id, $new_price, $context = 'manual') {
    $item_id = absint($item_id);
    $new_price = floatval($new_price);
    
    if (!$item_id || $new_price < 0) return false;
    
    $old_price = (float) get_post_meta($item_id, '_price', true);
    
    // UPDATE: Vergleich auf 0.0001 Genauigkeit für 3 Dezimalstellen
    if (abs($old_price - $new_price) < 0.0001) return false;
    
    update_post_meta($item_id, '_price', $new_price);
    
    // Log-Eintrag erstellen
    $item_title = get_the_title($item_id);
    $log_msg = sprintf('Preisänderung für "%s" (ID: %d): %.3f € -> %.3f €', $item_title, $item_id, $old_price, $new_price);
    
    if (function_exists('lww_record_system_log')) {
        lww_record_system_log('Price Update', $context, [
            'item_id' => $item_id,
            'old_price' => $old_price,
            'new_price' => $new_price,
            'msg' => $log_msg
        ]);
    }
    
    return true;
}
?>
