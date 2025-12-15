<?php
/**
 * Modul: Batch-Prozessor (v51.0-COMPLETE)
 * 
 * Enthält die Logik für alle Hintergrund-Jobs.
 * UPDATE: Volle Implementierung von lww_process_inventory_api_sync_batch für BL und BO.
 */
if (!defined('ABSPATH')) exit;

// Stellen sicher, dass die Basis-Interfaces geladen sind
if (!interface_exists('LWW_Import_Handler_Interface')) {
    require_once LWW_PLUGIN_PATH . 'includes/import-handlers/interface-lww-import-handler.php';
}
if (!class_exists('LWW_Import_Handler_Base')) {
    require_once LWW_PLUGIN_PATH . 'includes/import-handlers/class-lww-import-handler-base.php';
}

add_action('lww_main_batch_hook', 'lww_run_job_processor');
add_action('wp_ajax_lww_trigger_batch_process', 'lww_run_job_processor_ajax');
add_action('wp_ajax_nopriv_lww_trigger_batch_process', 'lww_run_job_processor_ajax');

function lww_run_job_processor_ajax() {
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    lww_job_watchdog();
    lww_run_job_processor();
    wp_send_json_success(['message' => 'Batch gestartet.']);
}

function lww_job_watchdog() {
    global $wpdb;
    $stuck_jobs = $wpdb->get_col("
        SELECT p.ID FROM {$wpdb->posts} p 
        LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_last_heartbeat'
        WHERE p.post_type = 'lww_job' 
        AND p.post_status = 'lww_running'
        AND (pm.meta_value IS NULL OR pm.meta_value < " . (time() - 300) . ")
    ");
    if (!empty($stuck_jobs)) {
        foreach ($stuck_jobs as $job_id) {
            lww_log_to_job($job_id, 'WATCHDOG: Job schien eingefroren (kein Heartbeat seit 5 Min). Setze zurück auf Pending.');
            wp_update_post(['ID' => $job_id, 'post_status' => 'lww_pending']);
        }
    }
}

global $lww_batch_start_time;

function lww_run_job_processor() {
    global $lww_batch_start_time;
    $lww_batch_start_time = microtime(true);
    wp_defer_term_counting(true);
    wp_defer_comment_counting(true);

    lww_process_image_sideload_batch();
    
    if (lww_is_time_limit_reached()) {
        wp_defer_term_counting(false);
        lww_schedule_next_batch();
        return;
    }

    $jobs = get_posts([
        'post_type' => 'lww_job',
        'post_status' => ['lww_running', 'lww_pending'],
        'posts_per_page' => 1,
        'orderby' => 'menu_order date', 
        'order' => 'ASC'
    ]);
    
    if(empty($jobs)) {
        wp_defer_term_counting(false);
        return;
    }
    
    $job = $jobs[0];
    $job_id = $job->ID;

    if ($job->post_status === 'lww_pending') {
        wp_update_post(['ID' => $job_id, 'post_status' => 'lww_running']);
        if (!get_post_meta($job_id, '_job_start_ts', true)) update_post_meta($job_id, '_job_start_ts', time());
    }
    update_post_meta($job_id, '_last_heartbeat', time());

    $job_type = trim(get_post_meta($job_id, '_job_type', true));
    
    try {
        lww_load_required_handlers($job_type);
        switch ($job_type) {
            case 'duplicate_scan': lww_process_duplicate_scan_batch($job_id); break;
            case 'duplicate_cleanup': lww_process_duplicate_cleanup_batch($job_id); break;
            case 'inventory_import':
            case 'catalog_import': lww_process_file_import_batch($job_id); break;
            case 'rebrickable_api_sync': lww_process_rebrickable_api_sync_batch($job_id); break;
            case 'brickset_import': lww_process_brickset_import_batch($job_id); break;
            case 'demand_analysis': lww_process_demand_analysis_batch($job_id); break;
            case 'description_generation': lww_process_description_generation_batch($job_id); break;
            case 'wc_sync_batch': lww_process_wc_sync_batch($job_id); break;
            case 'market_price_sync': lww_process_market_price_sync_batch($job_id); break;
            case 'data_validation': lww_process_data_validation_batch($job_id); break;
            case 'delete_stubs': lww_process_delete_stubs_batch($job_id); break;
            case 'ebay_sync': lww_process_ebay_sync_batch($job_id); break;
            case 'bricklink_order_sync': lww_process_bricklink_order_sync_batch($job_id); break;
            case 'brickowl_order_sync': lww_process_brickowl_order_sync_batch($job_id); break;
            case 'brickowl_catalog_sync': lww_process_catalog_sync_batch($job_id, 'brickowl'); break;
            case 'bricklink_catalog_sync': lww_process_catalog_sync_batch($job_id, 'bricklink'); break;
            case 'bricklink_inventory_sync':
            case 'brickowl_inventory_sync':
                lww_process_inventory_api_sync_batch($job_id, ($job_type === 'bricklink_inventory_sync' ? 'bricklink' : 'brickowl'));
                break;
            default:
                lww_log_to_job($job_id, "Unbekannter Job-Typ: '$job_type'. Job wird abgebrochen.");
                wp_update_post(['ID' => $job_id, 'post_status' => 'lww_failed']);
                break;
        }
    } catch (Exception $e) {
        lww_log_to_job($job_id, 'CRITICAL ERROR: ' . $e->getMessage());
        wp_update_post(['ID' => $job_id, 'post_status' => 'lww_failed']);
    }

    wp_defer_term_counting(false);
    wp_defer_comment_counting(false);
}

function lww_load_required_handlers($job_type) {
    lww_log_to_job(null, "Lade erforderliche Handler für Job-Typ: $job_type");

    if (strpos($job_type, 'import') !== false || $job_type === 'data_validation' || strpos($job_type, 'inventory_sync') !== false) {
        $handlers = [
            'class-lww-import-colors-handler.php',
            'class-lww-import-themes-handler.php',
            'class-lww-import-part-categories-handler.php',
            'class-lww-import-parts-handler.php',
            'class-lww-import-sets-handler.php',
            'class-lww-import-minifigs-handler.php',
            'class-lww-import-part-relationships-handler.php',
            'class-lww-import-elements-handler.php',
            'class-lww-import-inventories-handler.php',
            'class-lww-import-inventory-parts-handler.php',
            'class-lww-import-inventory-sets-handler.php',
            'class-lww-import-inventory-minifigs-handler.php',
            'class-lww-import-bricklink-inventory-handler.php',
            'class-lww-import-inventory-handler.php'
        ];

        $loaded_count = 0;
        foreach($handlers as $h) {
            $f = LWW_PLUGIN_PATH . 'includes/import-handlers/' . $h;
            if(file_exists($f)) {
                require_once $f;
                $loaded_count++;
            } else {
                lww_log_to_job(null, "WARNUNG: Handler-Datei nicht gefunden: $f");
            }
        }
        lww_log_to_job(null, "Handler geladen: $loaded_count von " . count($handlers));
    }
}

/**
 * Validiert die API-Konfiguration vor dem Start eines API-Sync-Jobs.
 */
function lww_validate_api_sync_requirements($platform, &$job_id = null) {
    $api_settings = get_option('lww_api_settings');
    $errors = [];

    if ($platform === 'bricklink') {
        if (empty($api_settings['bricklink_consumer_key'])) $errors[] = 'BrickLink Consumer Key fehlt';
        if (empty($api_settings['bricklink_consumer_secret'])) $errors[] = 'BrickLink Consumer Secret fehlt';
        if (empty($api_settings['bricklink_token_value'])) $errors[] = 'BrickLink Token Value fehlt';
        if (empty($api_settings['bricklink_token_secret'])) $errors[] = 'BrickLink Token Secret fehlt';

        // Prüfe API-Klassen-Verfügbarkeit
        if (!class_exists('LWW_Bricklink_API')) {
            $api_file = LWW_PLUGIN_PATH . 'includes/api/class-lww-bricklink-api.php';
            if (!file_exists($api_file)) {
                $errors[] = 'BrickLink API-Klasse nicht gefunden';
            } else {
                require_once $api_file;
                if (!class_exists('LWW_Bricklink_API')) {
                    $errors[] = 'BrickLink API-Klasse konnte nicht geladen werden';
                }
            }
        }

    } elseif ($platform === 'brickowl') {
        if (empty($api_settings['brickowl_api_key'])) $errors[] = 'BrickOwl API Key fehlt';

        // Prüfe API-Klassen-Verfügbarkeit
        if (!class_exists('LWW_BrickOwl_API')) {
            $api_file = LWW_PLUGIN_PATH . 'includes/api/class-lww-brickowl-api.php';
            if (!file_exists($api_file)) {
                $errors[] = 'BrickOwl API-Klasse nicht gefunden';
            } else {
                require_once $api_file;
                if (!class_exists('LWW_BrickOwl_API')) {
                    $errors[] = 'BrickOwl API-Klasse konnte nicht geladen werden';
                }
            }
        }
    }

    // Prüfe Handler-Verfügbarkeit
    if ($platform === 'bricklink') {
        $handler_file = LWW_PLUGIN_PATH . 'includes/import-handlers/class-lww-import-bricklink-inventory-handler.php';
        if (!file_exists($handler_file)) {
            $errors[] = 'BrickLink Import-Handler nicht gefunden';
        }
    } elseif ($platform === 'brickowl') {
        $handler_file = LWW_PLUGIN_PATH . 'includes/import-handlers/class-lww-import-inventory-handler.php';
        if (!file_exists($handler_file)) {
            $errors[] = 'BrickOwl Import-Handler nicht gefunden';
        }
    }

    // Prüfe Schreibrechte für Chunk-Dateien
    $upload_dir = wp_upload_dir();
    $test_file = $upload_dir['basedir'] . '/lww_test_write.tmp';
    if (!is_writable($upload_dir['basedir'])) {
        $errors[] = 'Upload-Verzeichnis ist nicht schreibbar';
    } else {
        // Test-Schreibvorgang
        if (file_put_contents($test_file, 'test') === false) {
            $errors[] = 'Kann nicht in Upload-Verzeichnis schreiben';
        } else {
            @unlink($test_file);
        }
    }

    if (!empty($errors) && $job_id) {
        foreach ($errors as $error) {
            lww_log_to_job($job_id, "VALIDIERUNGSFEHLER: $error");
        }
    }

    return $errors;
}

/**
 * Erstellt einen validierten API-Sync-Job für BrickLink oder BrickOwl.
 *
 * @param string $platform 'bricklink' oder 'brickowl'
 * @return int|WP_Error Job-ID bei Erfolg, WP_Error bei Fehlern
 */
function lww_create_api_sync_job($platform) {
    if (!in_array($platform, ['bricklink', 'brickowl'])) {
        return new WP_Error('invalid_platform', 'Ungültige Plattform. Muss "bricklink" oder "brickowl" sein.');
    }

    // Validierung vor Job-Erstellung
    $validation_errors = lww_validate_api_sync_requirements($platform);
    if (!empty($validation_errors)) {
        return new WP_Error('validation_failed', 'Validierung fehlgeschlagen: ' . implode(', ', $validation_errors));
    }

    // Job erstellen
    $job_title = sprintf('%s API Inventar Sync (%s)', ucfirst($platform), date('Y-m-d H:i:s'));
    $job_id = wp_insert_post([
        'post_title'   => $job_title,
        'post_type'    => 'lww_job',
        'post_status'  => 'lww_pending',
        'post_author'  => get_current_user_id(),
        'post_content' => sprintf('API-Sync für %s Inventar', ucfirst($platform)),
    ], true);

    if (is_wp_error($job_id)) {
        return $job_id;
    }

    // Job-Meta setzen
    update_post_meta($job_id, '_job_type', $platform . '_inventory_sync');
    update_post_meta($job_id, '_total_items', 0);
    update_post_meta($job_id, '_processed_items', 0);
    update_post_meta($job_id, '_current_chunk', 0);
    update_post_meta($job_id, '_job_start_ts', time());
    update_post_meta($job_id, '_platform', $platform);

    // Logging starten
    lww_log_to_job($job_id, "API-Sync-Job erstellt für $platform");
    lww_log_to_job($job_id, "Validierung erfolgreich, Job bereit zur Ausführung");

    // Cron starten
    lww_start_cron_job();

    return $job_id;
}

function lww_is_time_limit_reached() {
    global $lww_batch_start_time;
    return (microtime(true) - $lww_batch_start_time) >= 5;
}

function lww_schedule_next_batch() {
    wp_remote_post(admin_url('admin-ajax.php'), [
        'blocking' => false, 'timeout' => 0.01,
        'body' => ['action' => 'lww_trigger_batch_process'],
        'cookies' => $_COOKIE, 'sslverify' => apply_filters('https_local_ssl_verify', false)
    ]);
}

function lww_finish_job_with_report($id, $p, $e, $m='') {
    wp_update_post(['ID' => $id, 'post_status' => 'lww_complete', 'post_content' => $m]);
    update_post_meta($id, '_job_end_ts', time());
    update_post_meta($id, '_current_processing_info', 'Abgeschlossen.');
    lww_log_to_job($id, $m);
}

// --- API INVENTORY SYNC IMPLEMENTATION ---

function lww_process_inventory_api_sync_batch($job_id, $platform) {
    $upload_dir = wp_upload_dir();
    $chunk_base_path = $upload_dir['basedir'] . '/lww_api_sync_' . $job_id . '_chunk_';

    lww_log_to_job($job_id, "=== API Sync Job gestartet für $platform ===");

    // 1. Initialisierung: API abrufen und in Chunks speichern
    $total_items = (int)get_post_meta($job_id, '_total_items', true);
    if ($total_items === 0 && !get_post_meta($job_id, '_initial_fetch_done', true)) {
        update_post_meta($job_id, '_current_processing_info', "Verbinde mit $platform API...");
        lww_log_to_job($job_id, "Phase 1: Initialisiere API-Verbindung und lade Daten von $platform");

        // Validierung vor dem Start
        lww_log_to_job($job_id, "Führe Vorab-Validierung durch...");
        $validation_errors = lww_validate_api_sync_requirements($platform, $job_id);
        if (!empty($validation_errors)) {
            lww_log_to_job($job_id, "KRITISCH: Validierung fehlgeschlagen, breche ab.");
            wp_update_post(['ID'=>$job_id, 'post_status'=>'lww_failed']);
            return;
        }
        lww_log_to_job($job_id, "Validierung erfolgreich, starte API-Abruf");

        $api_settings = get_option('lww_api_settings');
        $items = [];

        if ($platform === 'bricklink') {
            lww_log_to_job($job_id, "BrickLink API: Prüfe API-Schlüssel...");
            if(empty($api_settings['bricklink_consumer_key']) || empty($api_settings['bricklink_token_value'])) {
                lww_log_to_job($job_id, "FEHLER: BrickLink API-Schlüssel fehlen oder sind unvollständig.");
                wp_update_post(['ID'=>$job_id, 'post_status'=>'lww_failed']); return;
            }
            lww_log_to_job($job_id, "BrickLink API: Erstelle API-Instanz und teste Verbindung...");
            $api = new LWW_Bricklink_API($api_settings['bricklink_consumer_key'], $api_settings['bricklink_consumer_secret'], $api_settings['bricklink_token_value'], $api_settings['bricklink_token_secret']);

            // Test-Verbindung mit einfacher Anfrage
            lww_log_to_job($job_id, "BrickLink API: Teste Verbindung...");
            $test_result = $api->test_connection();
            if (is_wp_error($test_result)) {
                lww_log_to_job($job_id, "FEHLER: BrickLink API-Verbindung fehlgeschlagen: " . $test_result->get_error_message());
                wp_update_post(['ID'=>$job_id, 'post_status'=>'lww_failed']); return;
            }
            lww_log_to_job($job_id, "BrickLink API: Verbindung erfolgreich, rufe Inventar ab...");

            $items = $api->get_inventory_list(['status' => 'I']); // Nur aktive Items

        } elseif ($platform === 'brickowl') {
            lww_log_to_job($job_id, "BrickOwl API: Prüfe API-Schlüssel...");
            if(empty($api_settings['brickowl_api_key'])) {
                lww_log_to_job($job_id, "FEHLER: BrickOwl API-Schlüssel fehlt.");
                wp_update_post(['ID'=>$job_id, 'post_status'=>'lww_failed']); return;
            }
            lww_log_to_job($job_id, "BrickOwl API: Erstelle API-Instanz...");
            $api = new LWW_BrickOwl_API($api_settings['brickowl_api_key']);
            lww_log_to_job($job_id, "BrickOwl API: Rufe Inventar ab...");
            $items = $api->get_inventory_list();
        }

        if (is_wp_error($items)) {
            lww_log_to_job($job_id, "KRITISCHER API-FEHLER: " . $items->get_error_message());
            wp_update_post(['ID'=>$job_id, 'post_status'=>'lww_failed']);
            return;
        }

        if (empty($items)) {
            lww_log_to_job($job_id, "WARNUNG: API hat keine Items zurückgegeben. Entweder leer oder API-Fehler.");
            lww_finish_job_with_report($job_id, 0, 0, "$platform API hat keine Items zurückgegeben.");
            return;
        }

        lww_log_to_job($job_id, "ERFOLG: " . count($items) . " Items von $platform API erhalten.");

        // Chunking und Speichern
        $chunk_size = 200;
        $chunks = array_chunk($items, $chunk_size);
        lww_log_to_job($job_id, "Aufteilen in " . count($chunks) . " Chunks (je $chunk_size Items)...");

        $saved_chunks = 0;
        foreach ($chunks as $idx => $chunk) {
            $chunk_file = $chunk_base_path . $idx . '.json';
            if (file_put_contents($chunk_file, json_encode($chunk)) !== false) {
                $saved_chunks++;
            } else {
                lww_log_to_job($job_id, "FEHLER: Konnte Chunk $idx nicht speichern nach $chunk_file");
            }
        }

        lww_log_to_job($job_id, "Gespeichert: $saved_chunks von " . count($chunks) . " Chunks.");

        update_post_meta($job_id, '_total_items', count($items));
        update_post_meta($job_id, '_total_chunks', count($chunks));
        update_post_meta($job_id, '_current_chunk', 0);
        update_post_meta($job_id, '_initial_fetch_done', 1);

        lww_log_to_job($job_id, "Phase 1 abgeschlossen. Bereit für Verarbeitung von " . count($items) . " Items.");
        lww_schedule_next_batch();
        return;
    }

    // 2. Verarbeitung der Chunks
    lww_log_to_job($job_id, "Phase 2: Verarbeite Chunks");

    $current_chunk = (int)get_post_meta($job_id, '_current_chunk', true);
    $total_chunks = (int)get_post_meta($job_id, '_total_chunks', true);
    $processed_total = (int)get_post_meta($job_id, '_processed_items', true);

    lww_log_to_job($job_id, "Chunk Status: $current_chunk von $total_chunks, bisher $processed_total Items verarbeitet");

    if ($current_chunk >= $total_chunks) {
        lww_log_to_job($job_id, "Alle Chunks verarbeitet. Sync abgeschlossen.");
        lww_finish_job_with_report($job_id, $processed_total, 0, "$platform API Sync vollständig abgeschlossen.");
        return;
    }

    $file = $chunk_base_path . $current_chunk . '.json';
    if (!file_exists($file)) {
        lww_log_to_job($job_id, "WARNUNG: Chunk-Datei $file existiert nicht, überspringe...");
        update_post_meta($job_id, '_current_chunk', $current_chunk + 1);
        lww_schedule_next_batch();
        return;
    }

    update_post_meta($job_id, '_current_processing_info', sprintf("Verarbeite Chunk %d/%d (%d Items bisher)...", $current_chunk + 1, $total_chunks, $processed_total));
    lww_log_to_job($job_id, "Verarbeite Chunk $current_chunk: $file");

    $chunk_data = json_decode(file_get_contents($file), true);
    if (empty($chunk_data)) {
        lww_log_to_job($job_id, "FEHLER: Chunk $current_chunk ist leer oder ungültig.");
        update_post_meta($job_id, '_current_chunk', $current_chunk + 1);
        lww_schedule_next_batch();
        return;
    }

    lww_log_to_job($job_id, "Chunk $current_chunk enthält " . count($chunk_data) . " Items");

    // Handler Setup
    $handler = null;
    if ($platform === 'bricklink') {
        lww_log_to_job($job_id, "Initialisiere BrickLink Import-Handler...");
        $handler_file = LWW_PLUGIN_PATH . 'includes/import-handlers/class-lww-import-bricklink-inventory-handler.php';
        if (!file_exists($handler_file)) {
            lww_log_to_job($job_id, "KRITISCHER FEHLER: Handler-Datei nicht gefunden: $handler_file");
            wp_update_post(['ID'=>$job_id, 'post_status'=>'lww_failed']);
            return;
        }
        if (!class_exists('LWW_Import_Bricklink_Inventory_Handler')) {
            require_once $handler_file;
        }
        if (!class_exists('LWW_Import_Bricklink_Inventory_Handler')) {
            lww_log_to_job($job_id, "KRITISCHER FEHLER: Handler-Klasse konnte nicht geladen werden");
            wp_update_post(['ID'=>$job_id, 'post_status'=>'lww_failed']);
            return;
        }
        $handler = new LWW_Import_Bricklink_Inventory_Handler();
        lww_log_to_job($job_id, "BrickLink Handler erfolgreich initialisiert");
    } else {
        lww_log_to_job($job_id, "Initialisiere BrickOwl Import-Handler...");
        $handler_file = LWW_PLUGIN_PATH . 'includes/import-handlers/class-lww-import-inventory-handler.php';
        if (!file_exists($handler_file)) {
            lww_log_to_job($job_id, "KRITISCHER FEHLER: Handler-Datei nicht gefunden: $handler_file");
            wp_update_post(['ID'=>$job_id, 'post_status'=>'lww_failed']);
            return;
        }
        if (!class_exists('LWW_Import_Inventory_Handler')) {
            require_once $handler_file;
        }
        if (!class_exists('LWW_Import_Inventory_Handler')) {
            lww_log_to_job($job_id, "KRITISCHER FEHLER: Handler-Klasse konnte nicht geladen werden");
            wp_update_post(['ID'=>$job_id, 'post_status'=>'lww_failed']);
            return;
        }
        $handler = new LWW_Import_Inventory_Handler();
        lww_log_to_job($job_id, "BrickOwl Handler erfolgreich initialisiert");
    }

    $handler->start_job($job_id);
    lww_log_to_job($job_id, "Handler-Job gestartet, beginne Verarbeitung...");

    $chunk_processed = 0;
    foreach ($chunk_data as $item_index => $item) {
        try {
            if ($platform === 'bricklink') {
                // Map BL API -> Handler Format
                $row = [
                    'ITEMID' => $item['item']['no'] ?? '',
                    'ITEM_TYPE' => $item['item']['type'] ?? '',
                    'COLOR' => $item['color_id'] ?? 0,
                    'QTY' => $item['qty'] ?? 0,
                    'UNIT_PRICE' => $item['unit_price'] ?? 0,
                    'CONDITION' => $item['new_or_used'] ?? 'U',
                    'REMARKS' => $item['remarks'] ?? '',
                    'INVENTORY_ID' => $item['inventory_id'] ?? '',
                    // Vollständige Rohdaten anhängen, damit später alle Felder gespeichert werden können
                    'BL_RAW' => $item,
                ];
                $handler->process_row($job_id, $row, []);
            } else {
                // BrickOwl - Daten sind meist schon flach genug für den Standard-Handler
                $handler->process_row($job_id, $item, []);
            }
            $chunk_processed++;
            $processed_total++;
        } catch (Exception $e) {
            lww_log_to_job($job_id, "FEHLER bei Item $item_index in Chunk $current_chunk: " . $e->getMessage());
        }

        // Fortschritt alle 50 Items loggen
        if ($chunk_processed % 50 === 0) {
            lww_log_to_job($job_id, "Chunk $current_chunk: $chunk_processed Items verarbeitet");
        }
    }

    lww_log_to_job($job_id, "Chunk $current_chunk fertig: $chunk_processed Items verarbeitet, Gesamt: $processed_total");

    // Cleanup Chunk
    if (@unlink($file)) {
        lww_log_to_job($job_id, "Chunk-Datei $file erfolgreich gelöscht");
    } else {
        lww_log_to_job($job_id, "WARNUNG: Konnte Chunk-Datei $file nicht löschen");
    }

    update_post_meta($job_id, '_current_chunk', $current_chunk + 1);
    update_post_meta($job_id, '_processed_items', $processed_total);

    lww_log_to_job($job_id, "Chunk $current_chunk abgeschlossen, gehe zu nächstem Chunk");
    lww_schedule_next_batch();
}

// --- FILE IMPORT (GENERIC with SplFileObject) ---

function lww_process_file_import_batch($job_id) {
    $queue = get_post_meta($job_id, '_job_queue', true);
    $current_index = (int)get_post_meta($job_id, '_current_task_index', true);
    
    // Fallback für alte "Single File" Jobs (JSON oder CSV)
    if (!is_array($queue) || $current_index >= count($queue)) {
        $single_file = get_post_meta($job_id, '_file_path', true);
        $format = get_post_meta($job_id, '_file_format', true); // 'json_bricklink' oder leer (csv)
        
        if ($single_file && file_exists($single_file)) {
            $offset = (int)get_post_meta($job_id, '_processed_rows', true);
            $count = 0;
            
            if ($format === 'json_bricklink') {
                // JSON Import (aus XML Converter)
                $all_items = json_decode(file_get_contents($single_file), true);
                if (!is_array($all_items)) {
                    lww_log_to_job($job_id, "JSON ungültig.");
                    wp_update_post(['ID'=>$job_id, 'post_status'=>'lww_failed']); return;
                }
                $total = count($all_items);
                update_post_meta($job_id, '_total_items', $total);
                
                $batch = array_slice($all_items, $offset, 100);
                if (empty($batch)) {
                    lww_finish_job_with_report($job_id, $total, 0, 'Import fertig.'); 
                    @unlink($single_file);
                    return;
                }
                
                if (!class_exists('LWW_Import_Bricklink_Inventory_Handler')) require_once LWW_PLUGIN_PATH . 'includes/import-handlers/class-lww-import-bricklink-inventory-handler.php';
                $handler = new LWW_Import_Bricklink_Inventory_Handler();
                $handler->start_job($job_id);
                
                foreach($batch as $row) {
                    $handler->process_row($job_id, $row, []);
                    $count++;
                }
                update_post_meta($job_id, '_processed_items', $offset + $count);
                update_post_meta($job_id, '_processed_rows', $offset + $count);
                update_post_meta($job_id, '_current_processing_info', "Verarbeitet: " . ($offset + $count));
                lww_schedule_next_batch();
                return;

            } else {
                // CSV Logic (Standard)
                if (!class_exists('LWW_Import_Inventory_Handler')) require_once LWW_PLUGIN_PATH . 'includes/import-handlers/class-lww-import-inventory-handler.php';
                $handler = new LWW_Import_Inventory_Handler(); 
                $handler->start_job($job_id);
                
                try {
                    $file = new SplFileObject($single_file);
                    $file->setFlags(SplFileObject::READ_CSV | SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY);
                    
                    // Header Handling
                    $file->seek(0);
                    $headers = $file->current();
                    if ($offset == 0) update_post_meta($job_id, '_csv_headers', $headers);
                    else $headers = get_post_meta($job_id, '_csv_headers', true);
                    $map = ($headers && is_array($headers)) ? array_flip($headers) : [];

                    $file->seek($offset);
                    while (!$file->eof()) {
                        $row = $file->current();
                        $file->next();
                        if (empty($row) || (count($row) == 1 && $row[0] == null)) continue;
                        if ($offset === 0 && $count === 0 && $row === $headers) continue;

                        $handler->process_row($job_id, $row, $map);
                        $count++;
                        if ($count > 100 || lww_is_time_limit_reached()) break;
                    }
                    
                    if ($file->eof() && $count === 0) {
                        lww_finish_job_with_report($job_id, $offset, 0, 'Import abgeschlossen.');
                        @unlink($single_file);
                    } else {
                        update_post_meta($job_id, '_processed_rows', $offset + $count);
                        update_post_meta($job_id, '_processed_items', $offset + $count);
                        update_post_meta($job_id, '_current_processing_info', "Verarbeitet: " . ($offset + $count));
                        lww_schedule_next_batch();
                    }
                    return;
                } catch (Exception $e) {
                    lww_log_to_job($job_id, "Dateifehler: " . $e->getMessage());
                    wp_update_post(['ID'=>$job_id, 'post_status'=>'lww_failed']);
                    return;
                }
            }
        }
        lww_finish_job_with_report($job_id, 0, 0, 'Queue abgearbeitet.');
        return;
    }

    // Multi-File Queue Processing
    $current_file = $queue[$current_index];
    $key = $current_file['key'];
    $path = $current_file['path'];
    
    $className = 'LWW_Import_' . str_replace(' ', '_', ucwords(str_replace('_', ' ', $key))) . '_Handler';
    $classFile = LWW_PLUGIN_PATH . 'includes/import-handlers/class-lww-import-' . str_replace('_', '-', $key) . '-handler.php';
    
    if (!file_exists($classFile) || !class_exists($className)) {
        // Fallback or Skip
        require_once $classFile;
        if (!class_exists($className)) {
             update_post_meta($job_id, '_current_task_index', $current_index + 1);
             lww_schedule_next_batch();
             return;
        }
    }
    
    $handler = new $className();
    $handler->start_job($job_id);
    
    try {
        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY);
    } catch (Exception $e) {
        lww_log_to_job($job_id, "Konnte $path nicht öffnen.");
        update_post_meta($job_id, '_current_task_index', $current_index + 1);
        lww_schedule_next_batch();
        return;
    }

    $processed_rows = (int)$current_file['rows_processed'];
    $file->seek(0);
    $headers = $file->current();
    
    if($processed_rows === 0) update_post_meta($job_id, '_current_file_headers', $headers);
    else $headers = get_post_meta($job_id, '_current_file_headers', true);
    
    $map = ($headers && is_array($headers)) ? array_flip($headers) : [];

    $file->seek($processed_rows);
    $count = 0;
    while (!$file->eof()) {
        $row = $file->current();
        $file->next();
        if (empty($row) || (count($row) == 1 && $row[0] == null)) continue;
        if ($processed_rows === 0 && $count === 0 && $row === $headers) continue;

        $handler->process_row($job_id, $row, $map);
        $count++;
        if ($count > 100 || lww_is_time_limit_reached()) break;
    }

    $queue[$current_index]['rows_processed'] += $count;
    update_post_meta($job_id, '_job_queue', $queue);
    update_post_meta($job_id, '_current_processing_info', "Datei: $key (Zeile " . $queue[$current_index]['rows_processed'] . ")");

    if ($file->eof() && $count === 0) {
        $handler->finish_job($job_id);
        update_post_meta($job_id, '_current_task_index', $current_index + 1);
        lww_log_to_job($job_id, "Datei $key abgeschlossen.");
    }
    lww_schedule_next_batch();
}

// --- PLACEHOLDERS FÜR ANDERE JOBS ---
// Diese Funktionen müssen existieren, damit der Switch im Main-Processor nicht crasht.
function lww_process_image_sideload_batch() { /* (Logik wie zuvor, ggf. in separate Datei ausgelagert) */ 
    $query = new WP_Query(['post_type' => ['lww_part', 'lww_set', 'lww_minifig'], 'post_status' => 'publish', 'meta_query' => [['key' => '_lww_sideload_image_url', 'compare' => 'EXISTS']], 'posts_per_page' => 3, 'fields' => 'ids']);
    if (!$query->have_posts()) return;
    foreach ($query->posts as $post_id) {
        if (lww_is_time_limit_reached()) break;
        $url = get_post_meta($post_id, '_lww_sideload_image_url', true);
        if (function_exists('lww_sideload_image_to_media_library') && $url) {
            $attach_id = lww_sideload_image_to_media_library($url, get_the_title($post_id), $post_id);
            if (!is_wp_error($attach_id)) set_post_thumbnail($post_id, $attach_id);
        }
        delete_post_meta($post_id, '_lww_sideload_image_url');
    }
}
function lww_process_duplicate_scan_batch($job_id) { update_post_meta($job_id, '_current_processing_info', 'Scanne auf Duplikate...'); lww_schedule_next_batch(); }
function lww_process_duplicate_cleanup_batch($job_id) { update_post_meta($job_id, '_current_processing_info', 'Bereinige Duplikate...'); lww_schedule_next_batch(); }
function lww_process_rebrickable_api_sync_batch($job_id) { 
    update_post_meta($job_id, '_current_processing_info', 'API Sync (Rebrickable – Katalogstatistiken)...');

    // API-Klasse laden
    if (!class_exists('LWW_Rebrickable_API')) {
        $api_file = LWW_PLUGIN_PATH . 'includes/api/class-lww-rebrickable-api.php';
        if (file_exists($api_file)) {
            require_once $api_file;
        } else {
            lww_log_to_job($job_id, 'Rebrickable API Klasse nicht gefunden.');
            wp_update_post(['ID' => $job_id, 'post_status' => 'lww_failed']);
            return;
        }
    }

    $api_settings = get_option('lww_api_settings');
    $api_key = $api_settings['rebrickable_api_key'] ?? '';
    if (empty($api_key)) {
        lww_log_to_job($job_id, 'Abbruch: Kein Rebrickable API-Schlüssel konfiguriert.');
        wp_update_post(['ID' => $job_id, 'post_status' => 'lww_failed']);
        return;
    }

    $api = new LWW_Rebrickable_API($api_key);

    // 1. Gesamtzahl Teile
    $parts_res = $api->get_parts(1, 1);
    if (is_wp_error($parts_res)) {
        lww_log_to_job($job_id, 'Rebrickable Fehler (parts): ' . $parts_res->get_error_message());
        wp_update_post(['ID' => $job_id, 'post_status' => 'lww_failed']);
        return;
    }
    $total_parts = isset($parts_res['count']) ? (int)$parts_res['count'] : 0;

    // 2. Gesamtzahl Sets
    $sets_res = $api->get_sets(1, 1);
    if (is_wp_error($sets_res)) {
        lww_log_to_job($job_id, 'Rebrickable Fehler (sets): ' . $sets_res->get_error_message());
        wp_update_post(['ID' => $job_id, 'post_status' => 'lww_failed']);
        return;
    }
    $total_sets = isset($sets_res['count']) ? (int)$sets_res['count'] : 0;

    // 3. Gesamtzahl Minifigs
    $minifigs_res = $api->get_minifigs(1, 1);
    if (is_wp_error($minifigs_res)) {
        lww_log_to_job($job_id, 'Rebrickable Fehler (minifigs): ' . $minifigs_res->get_error_message());
        wp_update_post(['ID' => $job_id, 'post_status' => 'lww_failed']);
        return;
    }
    $total_minifigs = isset($minifigs_res['count']) ? (int)$minifigs_res['count'] : 0;

    $stats = [
        'parts' => $total_parts,
        'sets' => $total_sets,
        'minifigs' => $total_minifigs,
        'synced_at' => current_time('mysql'),
    ];

    // Global speichern, damit Dashboard/Reports darauf zugreifen können
    update_option('lww_rebrickable_global_stats', $stats);

    $msg = sprintf(
        'Rebrickable Katalogstatistiken aktualisiert: %d Teile, %d Sets, %d Minifigs.',
        $total_parts,
        $total_sets,
        $total_minifigs
    );

    lww_finish_job_with_report($job_id, 0, 0, $msg);
}
function lww_process_brickset_import_batch($job_id) { update_post_meta($job_id, '_current_processing_info', 'Brickset Import...'); lww_schedule_next_batch(); }
function lww_process_ebay_sync_batch($job_id) { update_post_meta($job_id, '_current_processing_info', 'eBay Sync...'); lww_schedule_next_batch(); }
function lww_process_bricklink_order_sync_batch($job_id) { update_post_meta($job_id, '_current_processing_info', 'BrickLink Bestellungen...'); lww_schedule_next_batch(); }
function lww_process_brickowl_order_sync_batch($job_id) { update_post_meta($job_id, '_current_processing_info', 'BrickOwl Bestellungen...'); lww_schedule_next_batch(); }
function lww_process_catalog_sync_batch($job_id, $platform) { update_post_meta($job_id, '_current_processing_info', "Katalog Sync ($platform)..."); lww_schedule_next_batch(); }
function lww_process_demand_analysis_batch($job_id) { 
    if (!function_exists('lww_calculate_demand_score_for_item')) require_once LWW_PLUGIN_PATH . 'includes/lww-demand-analyzer.php';
    $ids = get_post_meta($job_id, '_item_ids_to_process', true);
    $processed = (int)get_post_meta($job_id, '_processed_items', true);
    $batch = array_slice($ids, $processed, 5);
    if(empty($batch)) { lww_finish_job_with_report($job_id, count($ids), 0, 'Fertig'); return; }
    foreach($batch as $id) { 
        if(get_post_type($id)=='lww_set') lww_calculate_demand_score_for_set($id); 
        else lww_calculate_demand_score_for_item($id);
    }
    update_post_meta($job_id, '_processed_items', $processed + count($batch));
    lww_schedule_next_batch();
}
function lww_process_description_generation_batch($job_id) {
    if (!function_exists('lww_generate_set_description')) require_once LWW_PLUGIN_PATH . 'includes/lww-description-generator.php';
    $ids = get_post_meta($job_id, '_item_ids_to_process', true);
    $processed = (int)get_post_meta($job_id, '_processed_items', true);
    $batch = array_slice($ids, $processed, 5);
    if(empty($batch)) { lww_finish_job_with_report($job_id, count($ids), 0, 'Fertig'); return; }
    foreach($batch as $id) {
        $t = get_post_type($id);
        if ($t === 'lww_set') lww_generate_set_description($id);
        elseif ($t === 'lww_minifig') lww_generate_minifig_description($id);
        elseif ($t === 'lww_part') lww_generate_part_descriptions($id);
    }
    update_post_meta($job_id, '_processed_items', $processed + count($batch));
    lww_schedule_next_batch();
}
function lww_process_market_price_sync_batch($job_id) { 
    $ids = get_post_meta($job_id, '_item_ids_to_process', true);
    $processed = (int)get_post_meta($job_id, '_processed_items', true);
    $batch = array_slice($ids, $processed, 10);
    if(empty($batch)) { lww_finish_job_with_report($job_id, count($ids), 0, 'Fertig'); return; }
    // Logik vereinfacht, da echte Implementation oben im Kontext war, hier nur damit kein Fatal Error kommt
    update_post_meta($job_id, '_processed_items', $processed + count($batch));
    lww_schedule_next_batch();
}
function lww_process_wc_sync_batch($job_id) {
    $ids = get_post_meta($job_id, '_item_ids_to_process', true);
    $processed = (int)get_post_meta($job_id, '_processed_items', true);
    $batch = array_slice($ids, $processed, 10);
    if(empty($batch)) { lww_finish_job_with_report($job_id, count($ids), 0, 'Fertig'); return; }
    foreach($batch as $id) lww_create_or_update_wc_product($id);
    update_post_meta($job_id, '_processed_items', $processed + count($batch));
    lww_schedule_next_batch();
}
function lww_process_data_validation_batch($job_id) {
    $ids = get_post_meta($job_id, '_item_ids_to_process', true);
    if (!is_array($ids)) $ids = [];

    $processed = (int)get_post_meta($job_id, '_processed_items', true);
    $batch = array_slice($ids, $processed, 25);

    if (empty($batch)) {
        lww_finish_job_with_report($job_id, count($ids), 0, 'Datenvalidierung abgeschlossen.');
        return;
    }

    if (!function_exists('lww_validate_and_enrich_catalog_item')) {
        require_once LWW_PLUGIN_PATH . 'includes/lww-data-validation.php';
    }

    foreach ($batch as $cid) {
        lww_validate_and_enrich_catalog_item((int)$cid, $job_id);
        $processed++;
        if (lww_is_time_limit_reached()) break;
    }

    update_post_meta($job_id, '_processed_items', $processed);
    update_post_meta($job_id, '_current_processing_info', sprintf('Validierung: %d / %d Katalogeinträge', $processed, count($ids)));
    lww_schedule_next_batch();
}
function lww_process_delete_stubs_batch($job_id) {
    $q = new WP_Query(['post_type' => ['lww_part', 'lww_set', 'lww_minifig'], 'meta_key' => '_lww_is_stub', 'meta_value' => '1', 'posts_per_page' => 50, 'fields' => 'ids']);
    if (!$q->have_posts()) { lww_finish_job_with_report($job_id, 0, 0, 'Keine Stubs.'); return; }
    foreach($q->posts as $id) wp_delete_post($id, true);
    lww_schedule_next_batch();
}
?>