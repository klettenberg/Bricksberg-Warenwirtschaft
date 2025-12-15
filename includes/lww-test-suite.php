<?php
/**
 * Modul: Test Suite (v20.1)
 * 
 * Ermöglicht einen End-to-End Test des Import- und Anreicherungsprozesses
 * mit einer kleinen Datenmenge (10 Items) und erweiterten Details.
 */
if (!defined('ABSPATH')) exit;

function lww_run_brickowl_import_test() {
    $api_settings = get_option('lww_api_settings');
    if (empty($api_settings['brickowl_api_key'])) {
        return new WP_Error('no_key', 'BrickOwl API Key fehlt.');
    }

    $log = [];
    $log[] = '--- START TEST: BrickOwl Import (Limit 10) ---';

    // 1. API Call BrickOwl Inventory
    $api = new LWW_BrickOwl_API($api_settings['brickowl_api_key']);
    $inventory = $api->get_inventory_list();
    if (is_wp_error($inventory)) {
        return $inventory;
    }

    $items_to_test = array_slice($inventory, 0, 10);
    $log[] = sprintf('%d Items von BrickOwl empfangen. Starte Analyse...', count($items_to_test));

    foreach ($items_to_test as $raw_data) {
        $boid = $raw_data['boid'];
        $log[] = "\n[ITEM] Prüfe BOID: $boid";

        // 2. Katalog-Suche simulieren
        $catalog_item = LWW_Import_Handler_Base::find_catalog_item_by_boid($boid);
        
        if (empty($catalog_item)) {
            $log[] = " - Status: Nicht im Katalog gefunden (würde Stub erstellen)";
        } else {
            $post_id = $catalog_item['id'];
            $post_type = $catalog_item['type'];
            $post = get_post($post_id);
            
            $log[] = " - Status: Im Katalog gefunden (ID: $post_id)";
            $log[] = " - Titel: " . $post->post_title;
            $log[] = " - Typ: " . $post_type;

            // Metadaten abrufen
            $meta_prefix = '_lww_' . str_replace('lww_', '', $post_type) . '_';
            $lego_id = get_post_meta($post_id, $meta_prefix . 'num', true);
            $bl_id = get_post_meta($post_id, '_lww_bricklink_id', true);
            $bo_id = get_post_meta($post_id, '_lww_brickowl_id', true);
            $name_de = get_post_meta($post_id, $meta_prefix . 'name_de', true);

            $log[] = " - IDs: LEGO=$lego_id | BL=$bl_id | BO=$bo_id";
            $log[] = " - DE Name: " . ($name_de ?: 'Keine Übersetzung');

            // Bildstatus
            if (has_post_thumbnail($post_id)) {
                $log[] = " - Bild: Vorhanden (ID: " . get_post_thumbnail_id($post_id) . ")";
            } elseif (get_post_meta($post_id, '_lww_sideload_image_url', true)) {
                $log[] = " - Bild: URL vorgemerkt für Sideload";
            } else {
                $log[] = " - Bild: FEHLT (würde per API/KI gesucht)";
            }
        }
    }

    $log[] = "\n--- TEST ABGESCHLOSSEN ---";
    return implode("\n", $log);
}
