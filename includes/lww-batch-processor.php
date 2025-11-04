<?php
/**
 * Modul: Batch-Prozessor (v13.0)
 * Verarbeitet die Job-Warteschlange (CPT 'lww_job') im Hintergrund.
 * Ruft dynamisch die korrekte Handler-Klasse für die Zeilenverarbeitung auf.
 * Liest Cron-Intervall und Batch-Größen aus den WordPress-Optionen.
 */
if (!defined('ABSPATH')) exit;

// Hook für den Haupt-Cron-Job
add_action('lww_main_batch_hook', 'lww_run_job_processor');

/**
 * Haupt-Job-Verarbeitungsfunktion ("Job Manager").
 */
function lww_run_job_processor() {
    lww_log_system_event('===== Cron Hook `lww_run_job_processor` START =====');

    // 1. Job-Sperre prüfen
    $current_job_id_option = get_option('lww_current_running_job_id');
    if ($current_job_id_option) {
        $job_post = get_post($current_job_id_option);
        // Prüfen, ob der gesperrte Job noch existiert und wirklich läuft
        if (!$job_post || $job_post->post_status !== 'lww_running') {
            delete_option('lww_current_running_job_id');
            lww_log_system_event('Alte Job-Sperre aufgehoben für ungültigen Job ' . $current_job_id_option);
            $current_job_id_option = false;
        } else {
            // Prüfen, wie lange der Job schon läuft (Timeout)
            $last_modified_time = strtotime($job_post->post_modified_gmt);
            $timeout_seconds = apply_filters('lww_job_timeout', 300); // 5 Minuten Timeout
            if (time() > ($last_modified_time + $timeout_seconds)) {
                lww_log_system_event('Job ' . $current_job_id_option . ' hat Timeout (' . $timeout_seconds . 's) überschritten. Sperre wird aufgehoben.');
                lww_fail_job($current_job_id_option, __('Job wegen Timeout abgebrochen.', 'lego-wawi'));
                $current_job_id_option = false;
            } else {
                lww_log_system_event('Job ' . $current_job_id_option . ' läuft bereits (Sperre aktiv). Cron beendet.');
                return;
            }
        }
    } else {
         lww_log_system_event('Keine aktive Job-Sperre gefunden.');
    }

    // 2. Job zum Verarbeiten finden
    $job_id = 0;
    $job_to_process = null;

    // ZUERST: Nach einem laufenden Job suchen
    $running_jobs_query = new WP_Query(['post_type' => 'lww_job', 'post_status' => 'lww_running', 'posts_per_page' => 1, 'orderby' => 'modified', 'order' => 'ASC']);
    if ($running_jobs_query->have_posts()) {
        $job_to_process = $running_jobs_query->posts[0];
        $job_id = $job_to_process->ID;
        lww_log_system_event('Laufenden Job gefunden: ID ' . $job_id . '. Setze fort.');
    } else {
        // DANN: Nach dem ältesten wartenden Job mit der höchsten Priorität suchen
        $pending_jobs_query = new WP_Query([
            'post_type' => 'lww_job',
            'post_status' => 'lww_pending',
            'posts_per_page' => 1,
            'orderby' => ['menu_order' => 'ASC', 'date' => 'ASC'] // Erst nach Priorität, dann nach Datum
        ]);
        if ($pending_jobs_query->have_posts()) {
             $job_to_process = $pending_jobs_query->posts[0];
             $job_id = $job_to_process->ID;
             lww_log_system_event('Wartenden Job gefunden: ID ' . $job_id . '. Starte.');
        }
    }

    if (!$job_to_process) {
        lww_log_system_event('Keine Jobs zu verarbeiten. Cron beendet.');
        return;
    }

    // 3. Job sperren und Status auf 'running' setzen
    $job_type = get_post_meta($job_id, '_job_type', true);
    update_option('lww_current_running_job_id', $job_id, 'no'); // 'no' for autoload
    lww_log_system_event('Globale Sperre für Job ' . $job_id . ' gesetzt.');

    if ($job_to_process->post_status === 'lww_pending') {
        $update_status = wp_update_post(['ID' => $job_id, 'post_status' => 'lww_running', 'post_modified' => current_time('mysql'), 'post_modified_gmt' => current_time('mysql', 1)]);
         if ($update_status === 0 || is_wp_error($update_status)) {
             lww_log_system_event('FEHLER: Status-Update Job ' . $job_id . ' fehlgeschlagen.');
             delete_option('lww_current_running_job_id');
             return;
         }
        lww_log_to_job($job_id, sprintf('Job %d gestartet (Typ: %s).', $job_id, esc_html($job_type)));
    } else {
         wp_update_post(['ID' => $job_id, 'post_modified' => current_time('mysql'), 'post_modified_gmt' => current_time('mysql', 1)]);
        lww_log_to_job($job_id, sprintf('Job %d fortgesetzt (Typ: %s).', $job_id, esc_html($job_type)));
    }

    // 4. Den passenden Batch-Prozessor aufrufen
    try {
        lww_log_system_event('Starte Verarbeitung Job ' . $job_id . '...');
        if ($job_type === 'catalog_import') {
            lww_process_catalog_job_batch($job_id);
        } elseif ($job_type === 'inventory_import' || $job_type === 'inventory_backup_import') {
            lww_process_inventory_job_batch($job_id, $job_type);
        } elseif ($job_type === 'demand_analysis') {
            lww_process_demand_analysis_batch($job_id);
        } elseif ($job_type === 'description_generation') {
            lww_process_description_generation_batch($job_id);
        } elseif ($job_type === 'location_sync') {
            lww_process_location_sync_batch($job_id);
        } elseif ($job_type === 'ebay_sync') {
            lww_process_ebay_sync_batch($job_id);
        } elseif ($job_type === 'brickowl_sync') {
            lww_process_brickowl_sync_batch($job_id);
        } elseif ($job_type === 'data_purge') {
            lww_process_data_purge_batch($job_id);
        } elseif ($job_type === 'data_validation') {
            lww_process_data_validation_batch($job_id);
        } else {
            throw new Exception(sprintf(__('Unbekannter Job-Typ: %s', 'lego-wawi'), esc_html($job_type)));
        }

        $current_status = get_post_status($job_id);
        if ($current_status === 'lww_complete' || $current_status === 'lww_failed') {
            lww_log_system_event('Job ' . $job_id . ' markiert als "' . $current_status . '". Sperre sollte bereits aufgehoben sein.');
        } else if ($current_status === 'lww_running') {
             wp_update_post(['ID' => $job_id, 'post_modified' => current_time('mysql'), 'post_modified_gmt' => current_time('mysql', 1)]);
             lww_log_system_event('Batch Job ' . $job_id . ' beendet, Status "' . $current_status . '". Sperre bleibt aktiv.');
        } else {
            lww_log_system_event('WARNUNG: Unerwarteter Status "' . $current_status . '" nach Batch für Job ' . $job_id);
        }

    } catch (Exception $e) {
        lww_fail_job($job_id, 'KRITISCHER FEHLER: ' . $e->getMessage());
        lww_log_system_event('Job ' . $job_id . ' fehlgeschlagen: ' . $e->getMessage());
    }

     lww_log_system_event('===== Cron Hook `lww_run_job_processor` ENDE =====');
}

/**
 * Verarbeitet einen Batch eines KATALOG-Jobs (v13.0 - mit Caching und Optimierungen)
 */
function lww_process_catalog_job_batch($job_id) {
    lww_log_system_event('--- Start lww_process_catalog_job_batch (Job ' . $job_id . ') ---');
    $job_queue = get_post_meta($job_id, '_job_queue', true);
    $task_index = (int) get_post_meta($job_id, '_current_task_index', true);

    if (!is_array($job_queue) || !isset($job_queue[$task_index])) {
        throw new Exception(sprintf('Katalog-Queue ungültig (Job %d, Index %s).', $job_id, $task_index));
    }

    $current_task = $job_queue[$task_index];
    $file_key = $current_task['key'] ?? 'unknown';
    $file_path = $current_task['path'] ?? '';
    $current_row = isset($current_task['rows_processed']) ? (int)$current_task['rows_processed'] : 0;
    $batch_size = max(50, (int)get_option('lww_catalog_batch_size', 200));

    lww_log_system_event(sprintf('Verarbeite "%s" (Index %d), Z %d, Batch: %d', $file_key, $task_index, $current_row, $batch_size));

    if (empty($file_path)) { throw new Exception(sprintf('Kein Pfad für "%s" (Index %d).', $file_key, $task_index)); }
    if (!file_exists($file_path)) {
        lww_log_to_job($job_id, sprintf('FEHLER: Datei für "%s" nicht gefunden: %s. Überspringe.', $file_key, basename($file_path)));
        return lww_skip_or_complete_job($job_id, $job_queue, $task_index, 'file_not_found');
    }

    $handle = @fopen($file_path, 'r');
    if (!$handle) { throw new Exception(sprintf('Datei öffnen fehlgeschlagen: %s', basename($file_path))); }

    // --- Springe zur richtigen Zeile ---
    if ($current_row > 0) {
        lww_log_system_event('Springe Z ' . $current_row . '...'); @set_time_limit(300);
        for ($i = 0; $i < $current_row; $i++) {
            if (feof($handle)) { lww_log_system_event('WARN: EOF beim Springen Z ' . $current_row); @fclose($handle); return lww_skip_or_complete_job($job_id, $job_queue, $task_index, 'eof_during_skip'); }
            if (@fgets($handle) === false && !feof($handle)) { @fclose($handle); throw new Exception('Fehler beim Springen.'); }
        }
        lww_log_system_event('Sprung Z ' . $current_row . ' beendet.');
    }

    // --- Header einlesen und Zeilen zählen (nur beim ersten Mal) ---
    $header_map_key = '_header_map_' . $file_key;
    $header_map = get_post_meta($job_id, $header_map_key, true);
    if ($current_row === 0 || empty($header_map) || empty($job_queue[$task_index]['total_rows'])) {
        lww_log_system_event('Lese Header und zähle Zeilen...'); 
        $header = @fgetcsv($handle);
        if ($header && is_array($header) && count($header) > 0) {
            $header_map = array_flip(array_map('trim', array_map('strtolower', $header)));
            update_post_meta($job_id, $header_map_key, $header_map);
            $current_row++; 
            $job_queue[$task_index]['rows_processed'] = $current_row;
            
            // Total rows zählen für Fortschrittsanzeige
            $total_rows = 1; // Header-Zeile
            while(fgets($handle) !== false) { $total_rows++; }
            $job_queue[$task_index]['total_rows'] = $total_rows;
            rewind($handle); // Zurück zum Anfang der Datei
            fgets($handle); // Header wieder überspringen

            update_post_meta($job_id, '_job_queue', $job_queue);
            lww_log_system_event('Header gelesen, ' . $total_rows . ' Zeilen gezählt.');
        } else { @fclose($handle); lww_log_to_job($job_id, sprintf('FEHLER: Header nicht lesbar oder Datei "%s" ist leer. Überspringe.', basename($file_path))); return lww_skip_or_complete_job($job_id, $job_queue, $task_index, 'header_read_error'); }
    }
    if (empty($header_map) || !is_array($header_map)) { @fclose($handle); throw new Exception(sprintf('Header-Map fehlt: %s', $file_key)); }

    // --- Handler-Klasse finden (Factory) ---
    static $handler_cache = [];
    $class_name = 'LWW_Import_' . str_replace(' ', '_', ucwords(str_replace('_', ' ', $file_key))) . '_Handler';

    if (!isset($handler_cache[$class_name])) {
         if (class_exists($class_name)) {
            $handler_cache[$class_name] = new $class_name();
         } else {
             $handler_cache[$class_name] = false;
             lww_log_to_job($job_id, sprintf('WARNUNG: Import-Handler-Klasse "%s" für "%s" nicht gefunden.', $class_name, $file_key));
         }
    }
    $handler = $handler_cache[$class_name];

    if (!$handler || !($handler instanceof LWW_Import_Handler_Interface)) {
        static $handler_warning_logged = []; $warn_key = $job_id . '_' . $file_key;
        if (!isset($handler_warning_logged[$warn_key])) { lww_log_to_job($job_id, sprintf('FEHLER: Kein gültiger Handler für "%s". Überspringe Datei.', $file_key)); $handler_warning_logged[$warn_key] = true; }
        @fclose($handle); return lww_skip_or_complete_job($job_id, $job_queue, $task_index, 'handler_not_found');
    }

    // Rufe start_job() einmal pro Datei/Job-Kombination auf.
    static $start_job_called = [];
    $start_job_key = $job_id . '_' . $file_key;
    if (!isset($start_job_called[$start_job_key]) && method_exists($handler, 'start_job')) {
        lww_log_system_event('Rufe start_job() für ' . $class_name . ' auf...');
        $handler->start_job($job_id);
        $start_job_called[$start_job_key] = true;
    }

    // --- Batch verarbeiten ---
    $processed_in_this_batch = 0;
    $total_rows_in_task = $job_queue[$task_index]['total_rows'] ?? 0;
    lww_log_system_event('Starte Batch mit ' . $class_name . ' (Z ' . $current_row . ' bis ca. ' . ($current_row + $batch_size) . ')');
    while ($processed_in_this_batch < $batch_size && !feof($handle)) {
        @set_time_limit(60);
        $line_number_for_log = $current_row + 1;
        $data = @fgetcsv($handle);
        if ($data === FALSE || $data === null || (count($data) === 1 && ($data[0] === null || trim($data[0]) === ''))) { if (feof($handle)) { lww_log_system_event('EOF in while'); break; } $current_row++; continue; }
        
        // Heartbeat, um Timeouts bei langen Batches zu verhindern
        if (($processed_in_this_batch > 0) && ($processed_in_this_batch % 100) === 0) {
            wp_update_post(['ID' => $job_id, 'post_modified' => current_time('mysql'), 'post_modified_gmt' => current_time('mysql', 1)]);
        }

        try {
            $handler->process_row($job_id, $data, $header_map);
        } catch (Exception $e) {
            lww_log_to_job($job_id, sprintf('FEHLER Z %d in %s: %s', $line_number_for_log, basename($file_path), $e->getMessage()));
        }
        $current_row++; $processed_in_this_batch++;
    }
    lww_log_system_event('Batch beendet. ' . $processed_in_this_batch . ' Zeilen. Aktuelle Z: ' . $current_row);

    // --- Status nach dem Batch aktualisieren ---
    $job_queue[$task_index]['rows_processed'] = $current_row;
    if (feof($handle)) {
        lww_log_system_event('EOF für ' . basename($file_path) . ' erreicht.'); @fclose($handle); @unlink($file_path);
        
        // Rufe finish_job() auf, wenn die Methode im Handler existiert
        if (method_exists($handler, 'finish_job')) {
            try {
                lww_log_system_event('Rufe finish_job() für ' . $class_name . ' auf...');
                $handler->finish_job($job_id);
            } catch (Exception $e) {
                lww_log_to_job($job_id, sprintf('FEHLER in finish_job() für %s: %s', $class_name, $e->getMessage()));
            }
        }

        $job_queue[$task_index]['status'] = 'complete'; 
        $final_processed_rows = max(0, $current_row - 1);
        $job_queue[$task_index]['total_rows'] = $final_processed_rows;
        lww_log_to_job($job_id, sprintf('Aufgabe "%s" abgeschlossen (%d Zeilen).', $file_key, $final_processed_rows));
        lww_skip_or_complete_job($job_id, $job_queue, $task_index, 'task_complete');
    } else {
        @fclose($handle);
        update_post_meta($job_id, '_job_queue', $job_queue);
        $log_message = sprintf('Aufgabe "%s": Batch beendet, %s / %s Zeilen verarbeitet.', $file_key, number_format_i18n(max(0, $current_row-1)), number_format_i18n($total_rows_in_task - 1));
        lww_log_to_job($job_id, $log_message);
    }
     lww_log_system_event('--- Ende lww_process_catalog_job_batch ---');
}

/**
 * Verarbeitet einen Batch eines INVENTAR-Jobs (v12.0 - nutzt Handler)
 */
function lww_process_inventory_job_batch($job_id, $job_type = 'inventory_import') {
    lww_log_system_event('--- Start lww_process_inventory_job_batch (Job ' . $job_id . ', Typ: ' . $job_type . ') ---');
    $job_queue = get_post_meta($job_id, '_job_queue', true); $task_index = 0;
    if (!is_array($job_queue) || !isset($job_queue[$task_index])) { throw new Exception('Inventar-Queue ungültig.'); }
    $current_task = $job_queue[$task_index]; $file_key = $current_task['key'] ?? 'inventory'; $file_path = $current_task['path'] ?? ''; $current_row = isset($current_task['rows_processed']) ? (int)$current_task['rows_processed'] : 0;
    $batch_size = max(50, (int)get_option('lww_inventory_batch_size', 300));
    lww_log_system_event(sprintf('Verarbeite "%s", Z %d, Batch: %d', $file_key, $current_row, $batch_size));
    if (empty($file_path)) { throw new Exception('Kein Pfad für Inventar.'); }
    if (!file_exists($file_path)) { lww_log_to_job($job_id, sprintf('FEHLER: Inventar-Datei fehlt: %s.', basename($file_path))); wp_update_post(['ID' => $job_id, 'post_status' => 'lww_failed']); delete_option('lww_current_running_job_id'); return; }
    $handle = @fopen($file_path, 'r'); if (!$handle) { throw new Exception(sprintf('Inventar-Datei nicht lesbar: %s', basename($file_path))); }
    if ($current_row > 0) { lww_log_system_event('Springe Z ' . $current_row . '...'); @set_time_limit(300); for ($i = 0; $i < $current_row; $i++) { if (feof($handle)) { lww_log_system_event('WARN: EOF beim Springen Z ' . $current_row); @fclose($handle); return lww_skip_or_complete_job($job_id, $job_queue, $task_index, 'eof_during_skip'); } if (@fgets($handle) === false && !feof($handle)) { @fclose($handle); throw new Exception('Fehler beim Springen.'); } } lww_log_system_event('Sprung Z ' . $current_row . ' beendet.'); }
    
    $header_map_key = '_header_map_inventory'; $header_map = get_post_meta($job_id, $header_map_key, true);
    if ($current_row === 0 || empty($header_map) || empty($job_queue[$task_index]['total_rows'])) {
        lww_log_system_event('Lese Inventar-Header und zähle Zeilen...'); 
        $header = @fgetcsv($handle);
        if ($header && is_array($header) && count($header) > 0) {
            $header_normalized = array_map('strtolower', array_map('trim', $header));
            
            // --- START DER FEHLERBEHEBUNG ---
            // Robuste Spaltenzuordnung, die den `array_search` `0`-Bug behebt.
            $possible_maps = [
                'boid' => ['boid'],
                'name' => ['name', 'item_name'],
                'color_name' => ['color_name', 'color'],
                'condition' => ['condition'],
                'quantity' => ['quantity', 'qty'],
                'price' => ['unit_price', 'price'],
                'bulk' => ['bulk'],
                'sale_price' => ['sale_price'],
                'remarks' => ['remarks'],
                'external_id' => ['external_id', 'external_id_1'],
                'location' => ['location'],
                'tier_qty_1' => ['tier_qty_1'], 'tier_price_1' => ['tier_price_1'],
                'tier_qty_2' => ['tier_qty_2'], 'tier_price_2' => ['tier_price_2'],
                'tier_qty_3' => ['tier_qty_3'], 'tier_price_3' => ['tier_price_3'],
            ];

            $header_map = [];
            foreach ($possible_maps as $target_key => $source_keys) {
                $header_map[$target_key] = false; // Standardwert
                foreach ($source_keys as $source_key) {
                    $found_index = array_search($source_key, $header_normalized);
                    if ($found_index !== false) {
                        $header_map[$target_key] = $found_index;
                        break; // Nimm den ersten Treffer
                    }
                }
            }
            // --- ENDE DER FEHLERBEHEBUNG ---

            $required_cols = ['boid','color_name','condition','quantity','price']; $missing_cols = [];
            foreach($required_cols as $req_col){ if(!isset($header_map[$req_col]) || $header_map[$req_col] === false) { $missing_cols[] = $req_col; } }
            if(!empty($missing_cols)){ @fclose($handle); throw new Exception('Inventar-CSV Spalten fehlen: '.implode(', ',$missing_cols)); }
            update_post_meta($job_id, $header_map_key, $header_map); $current_row++; 

            // Total rows zählen für Fortschrittsanzeige
            $total_rows = 1; // Header-Zeile
            while(fgets($handle) !== false) { $total_rows++; }
            $job_queue[$task_index]['total_rows'] = $total_rows;
            update_post_meta($job_id, '_total_items', $total_rows - 1); // Total items for progress bar
            rewind($handle); // Zurück zum Anfang der Datei
            fgets($handle); // Header wieder überspringen

            $job_queue[$task_index]['rows_processed'] = $current_row; 
            update_post_meta($job_id, '_processed_items', 0);
            update_post_meta($job_id, '_job_queue', $job_queue); 
            lww_log_system_event('Inventar-Header gelesen, ' . $total_rows . ' Zeilen gezählt.');
        } else { @fclose($handle); throw new Exception('Inventar-Header nicht lesbar.'); }
    }
    if (empty($header_map) || !is_array($header_map)) { @fclose($handle); throw new Exception('Inventar-Header-Map fehlt.'); }

    // --- Handler-Klasse finden (v12.0) ---
    static $handler_cache_inv = [];
    $class_name = ($job_type === 'inventory_backup_import') ? 'LWW_Import_Inventory_Backup_Handler' : 'LWW_Import_Inventory_Handler';

    if (!isset($handler_cache_inv[$class_name])) {
         if (class_exists($class_name)) { $handler_cache_inv[$class_name] = new $class_name(); } 
         else { $handler_cache_inv[$class_name] = false; }
    }
    $handler = $handler_cache_inv[$class_name];
    if (!$handler || !($handler instanceof LWW_Import_Handler_Interface)) { throw new Exception(sprintf('KRITISCH: Inventar-Handler (%s) fehlt.', $class_name)); }

    // Rufe start_job() einmalig auf
    static $inv_start_job_called = [];
    if (!isset($inv_start_job_called[$job_id])) {
        $handler->start_job($job_id);
        $inv_start_job_called[$job_id] = true;
    }

    // --- Batch verarbeiten ---
    $processed_in_this_batch = 0;
    $total_rows_in_task = $job_queue[$task_index]['total_rows'] ?? 0;
    lww_log_system_event('Starte Inventar Batch mit ' . $class_name . ' (Z '.$current_row.' bis ca. '.($current_row+$batch_size).')');
    while ($processed_in_this_batch < $batch_size && !feof($handle)) {
        @set_time_limit(60);
        $line_number_for_log = $current_row + 1; $data = @fgetcsv($handle);
        if ($data === FALSE || $data === null || (count($data) === 1 && ($data[0] === null || trim($data[0]) === ''))) { if (feof($handle)) { lww_log_system_event('Inventar: EOF in while'); break; } $current_row++; continue; }
        
        // Heartbeat
        if (($processed_in_this_batch > 0) && ($processed_in_this_batch % 100) === 0) {
            wp_update_post(['ID' => $job_id, 'post_modified' => current_time('mysql'), 'post_modified_gmt' => current_time('mysql', 1)]);
        }

        try {
            $handler->process_row($job_id, $data, $header_map);
        } catch (Exception $e) { lww_log_to_job($job_id, sprintf('FEHLER Inventar Z %d: %s', $line_number_for_log, $e->getMessage())); }
        $current_row++; $processed_in_this_batch++;
    }
    lww_log_system_event('Inventar Batch beendet. '.$processed_in_this_batch.' Zeilen. Aktuelle Z: '.$current_row);

    // --- Status nach dem Batch aktualisieren ---
    $job_queue[$task_index]['rows_processed'] = $current_row;
    $processed_items = (int) get_post_meta($job_id, '_processed_items', true) + $processed_in_this_batch;
    update_post_meta($job_id, '_processed_items', $processed_items);

    if (feof($handle)) {
        lww_log_system_event('Inventar: EOF erreicht.'); @fclose($handle); @unlink($file_path);
        $job_queue[$task_index]['status'] = 'complete'; 
        $final_processed_rows = max(0, $current_row - 1);
        $job_queue[$task_index]['total_rows'] = $final_processed_rows;
        wp_update_post(['ID' => $job_id, 'post_status' => 'lww_complete']);
        lww_log_to_job($job_id, sprintf('Inventar-Import-Job abgeschlossen (%d Zeilen).', $final_processed_rows));
        // Cache für Inventar-Statistiken löschen
        delete_transient('lww_inventory_stats');
        if (get_option('lww_current_running_job_id') == $job_id) delete_option('lww_current_running_job_id');
    } else {
        @fclose($handle); 
        $log_message = sprintf('Inventar-Import: Batch beendet, %s / %s Zeilen verarbeitet.', number_format_i18n(max(0, $current_row - 1)), number_format_i18n($total_rows_in_task - 1));
        lww_log_to_job($job_id, $log_message);
    }
    update_post_meta($job_id, '_job_queue', $job_queue);
    lww_log_system_event('--- Ende lww_process_inventory_job_batch ---');
}

/**
 * Verarbeitet einen Batch eines Nachfrageanalyse-Jobs.
 */
function lww_process_demand_analysis_batch($job_id) {
    lww_log_system_event('--- Start lww_process_demand_analysis_batch (Job ' . $job_id . ') ---');
    
    $item_ids = get_post_meta($job_id, '_item_ids_to_process', true);
    $total_items = (int) get_post_meta($job_id, '_total_items', true);
    $processed_count = (int) get_post_meta($job_id, '_processed_items', true);

    if (!is_array($item_ids) || empty($item_ids)) {
        lww_log_to_job($job_id, 'Keine Artikel-IDs für die Analyse gefunden. Job wird abgeschlossen.');
        wp_update_post(['ID' => $job_id, 'post_status' => 'lww_complete']);
        if (get_option('lww_current_running_job_id') == $job_id) delete_option('lww_current_running_job_id');
        return;
    }

    // Batch-Größe für KI ist klein, da Anfragen langsam sein können
    $batch_size = apply_filters('lww_demand_analysis_batch_size', 10);
    $items_in_this_batch = array_slice($item_ids, $processed_count, $batch_size);

    if (empty($items_in_this_batch)) {
        // Alle Artikel wurden verarbeitet
        lww_log_to_job($job_id, sprintf('Analyse für alle %d Artikel abgeschlossen.', $total_items));
        wp_update_post(['ID' => $job_id, 'post_status' => 'lww_complete']);
        if (get_option('lww_current_running_job_id') == $job_id) delete_option('lww_current_running_job_id');
        return;
    }

    $processed_in_this_batch = 0;
    foreach ($items_in_this_batch as $item_id) {
        @set_time_limit(60);
        
        // Heartbeat für KI-Jobs (häufiger)
        if (($processed_in_this_batch > 0) && ($processed_in_this_batch % 5) === 0) {
            wp_update_post(['ID' => $job_id, 'post_modified' => current_time('mysql'), 'post_modified_gmt' => current_time('mysql', 1)]);
        }

        $result = lww_calculate_demand_score_for_item($item_id);
        if (is_wp_error($result)) {
            lww_log_to_job($job_id, sprintf('FEHLER bei Artikel %d: %s', $item_id, $result->get_error_message()));
        }
        $processed_count++;
        $processed_in_this_batch++;
    }

    // Fortschritt speichern
    update_post_meta($job_id, '_processed_items', $processed_count);
    lww_log_to_job($job_id, sprintf('Batch beendet. %d von %d Artikeln analysiert.', $processed_count, $total_items));
    
    lww_log_system_event(sprintf('Analyse-Batch beendet. %d/%d verarbeitet.', $processed_count, $total_items));
}

/**
 * Verarbeitet einen Batch eines Beschreibungs-Generierungs-Jobs.
 */
function lww_process_description_generation_batch($job_id) {
    lww_log_system_event('--- Start lww_process_description_generation_batch (Job ' . $job_id . ') ---');

    $item_ids = get_post_meta($job_id, '_item_ids_to_process', true);
    $total_items = (int) get_post_meta($job_id, '_total_items', true);
    $processed_count = (int) get_post_meta($job_id, '_processed_items', true);

    if (!is_array($item_ids) || empty($item_ids)) {
        lww_log_to_job($job_id, 'Keine Eintrags-IDs für die Beschreibungserstellung gefunden. Job wird abgeschlossen.');
        wp_update_post(['ID' => $job_id, 'post_status' => 'lww_complete']);
        if (get_option('lww_current_running_job_id') == $job_id) delete_option('lww_current_running_job_id');
        return;
    }

    $batch_size = apply_filters('lww_description_generation_batch_size', 5); // Kleinere Batch-Größe für Texterstellung
    $items_in_this_batch = array_slice($item_ids, $processed_count, $batch_size);

    if (empty($items_in_this_batch)) {
        lww_log_to_job($job_id, sprintf('Beschreibungserstellung für alle %d Einträge abgeschlossen.', $total_items));
        wp_update_post(['ID' => $job_id, 'post_status' => 'lww_complete']);
        if (get_option('lww_current_running_job_id') == $job_id) delete_option('lww_current_running_job_id');
        return;
    }

    $processed_in_this_batch = 0;
    foreach ($items_in_this_batch as $post_id) {
        @set_time_limit(120);

        // Heartbeat
        if (($processed_in_this_batch > 0) && ($processed_in_this_batch % 2) === 0) {
            wp_update_post(['ID' => $job_id, 'post_modified' => current_time('mysql'), 'post_modified_gmt' => current_time('mysql', 1)]);
        }

        $post_type = get_post_type($post_id);
        $result = null;

        switch ($post_type) {
            case 'lww_set':
                $result = function_exists('lww_generate_set_description') ? lww_generate_set_description($post_id) : new WP_Error('function_missing', 'lww_generate_set_description nicht gefunden.');
                break;
            case 'lww_minifig':
                $result = function_exists('lww_generate_minifig_description') ? lww_generate_minifig_description($post_id) : new WP_Error('function_missing', 'lww_generate_minifig_description nicht gefunden.');
                break;
            case 'lww_part':
                $result = function_exists('lww_generate_part_short_description') ? lww_generate_part_short_description($post_id) : new WP_Error('function_missing', 'lww_generate_part_short_description nicht gefunden.');
                break;
        }

        if (is_wp_error($result)) {
            lww_log_to_job($job_id, sprintf('FEHLER bei Eintrag %d (%s): %s', $post_id, $post_type, $result->get_error_message()));
        }
        $processed_count++;
        $processed_in_this_batch++;
    }

    update_post_meta($job_id, '_processed_items', $processed_count);
    lww_log_to_job($job_id, sprintf('Batch beendet. %d von %d Beschreibungen erstellt.', $processed_count, $total_items));
    lww_log_system_event(sprintf('Beschreibungs-Batch beendet. %d/%d verarbeitet.', $processed_count, $total_items));
}

/**
 * Verarbeitet einen Batch eines Lagerort-Synchronisations-Jobs.
 */
function lww_process_location_sync_batch($job_id) {
    lww_log_system_event('--- Start lww_process_location_sync_batch (Job ' . $job_id . ') ---');

    $item_ids = get_post_meta($job_id, '_item_ids_to_process', true);
    $total_items = (int) get_post_meta($job_id, '_total_items', true);
    $processed_count = (int) get_post_meta($job_id, '_processed_items', true);

    if (!is_array($item_ids) || empty($item_ids)) {
        lww_log_to_job($job_id, 'Keine Artikel-IDs für die Synchronisation gefunden. Job wird abgeschlossen.');
        wp_update_post(['ID' => $job_id, 'post_status' => 'lww_complete']);
        if (get_option('lww_current_running_job_id') == $job_id) delete_option('lww_current_running_job_id');
        return;
    }

    $batch_size = apply_filters('lww_location_sync_batch_size', 200);
    $items_in_this_batch = array_slice($item_ids, $processed_count, $batch_size);

    if (empty($items_in_this_batch)) {
        lww_log_to_job($job_id, sprintf('Lagerort-Synchronisation für alle %d Artikel abgeschlossen.', $total_items));
        wp_update_post(['ID' => $job_id, 'post_status' => 'lww_complete']);
        if (get_option('lww_current_running_job_id') == $job_id) delete_option('lww_current_running_job_id');
        return;
    }

    $processed_in_this_batch = 0;
    foreach ($items_in_this_batch as $item_id) {
        @set_time_limit(60);

        if (($processed_in_this_batch > 0) && ($processed_in_this_batch % 100) === 0) {
            wp_update_post(['ID' => $job_id, 'post_modified' => current_time('mysql'), 'post_modified_gmt' => current_time('mysql', 1)]);
        }

        $remarks = get_post_meta($item_id, '_remarks', true);
        if (function_exists('lww_update_locations_from_string')) {
            lww_update_locations_from_string($item_id, $remarks);
        } else {
            lww_log_to_job($job_id, sprintf('FEHLER bei Artikel %d: Hilfsfunktion lww_update_locations_from_string() nicht gefunden.', $item_id));
        }
        $processed_count++;
        $processed_in_this_batch++;
    }

    update_post_meta($job_id, '_processed_items', $processed_count);
    lww_log_to_job($job_id, sprintf('Batch beendet. %d von %d Artikeln synchronisiert.', $processed_count, $total_items));
    lww_log_system_event(sprintf('Lagerort-Sync-Batch beendet. %d/%d verarbeitet.', $processed_count, $total_items));
}

/**
 * Verarbeitet einen Batch eines eBay Synchronisations-Jobs. (NEU & KORRIGIERT)
 */
function lww_process_ebay_sync_batch($job_id) {
    lww_log_system_event('--- Start lww_process_ebay_sync_batch (Job ' . $job_id . ') ---');

    $listing_ids = get_post_meta($job_id, '_item_ids_to_process', true);
    $total_items = (int) get_post_meta($job_id, '_total_items', true);
    $processed_count = (int) get_post_meta($job_id, '_processed_items', true);

    if (!is_array($listing_ids) || empty($listing_ids)) {
        lww_log_to_job($job_id, 'Keine eBay Angebots-IDs für die Synchronisation gefunden. Job wird abgeschlossen.');
        wp_update_post(['ID' => $job_id, 'post_status' => 'lww_complete']);
        if (get_option('lww_current_running_job_id') == $job_id) delete_option('lww_current_running_job_id');
        return;
    }

    $batch_size = apply_filters('lww_ebay_sync_batch_size', 10); // API-Calls sind langsam
    $items_in_this_batch = array_slice($listing_ids, $processed_count, $batch_size);

    if (empty($items_in_this_batch)) {
        lww_log_to_job($job_id, sprintf('eBay-Synchronisation für alle %d Angebote abgeschlossen.', $total_items));
        wp_update_post(['ID' => $job_id, 'post_status' => 'lww_complete']);
        if (get_option('lww_current_running_job_id') == $job_id) delete_option('lww_current_running_job_id');
        return;
    }

    $api_settings = get_option('lww_api_settings');
    $ebay_api = new LWW_eBay_API($api_settings);
    $handler = new LWW_Import_Handler_Base(); // Um Hilfsfunktionen zu nutzen

    $processed_in_this_batch = 0;
    foreach ($items_in_this_batch as $listing_id) {
        @set_time_limit(120);

        if (($processed_in_this_batch > 0) && ($processed_in_this_batch % 5) === 0) {
            wp_update_post(['ID' => $job_id, 'post_modified' => current_time('mysql'), 'post_modified_gmt' => current_time('mysql', 1)]);
        }

        $details = $ebay_api->get_item_details($listing_id);

        if (is_wp_error($details)) {
            lww_log_to_job($job_id, sprintf('FEHLER bei Abruf von eBay Angebot %s: %s', $listing_id, $details->get_error_message()));
            $processed_count++;
            $processed_in_this_batch++;
            continue;
        }
        
        $catalog_sku = $details['sku'] ?? null;
        if (empty($catalog_sku)) {
            lww_log_to_job($job_id, sprintf('WARNUNG: eBay Angebot %s übersprungen, da Haupt-SKU fehlt.', $listing_id));
            $processed_count++;
            $processed_in_this_batch++;
            continue;
        }

        // Finde das zugehörige Katalog-Item (Set oder Minifig) anhand der Haupt-SKU
        $catalog_post_id = $handler->find_set_by_num($catalog_sku);
        $catalog_post_type = 'lww_set';
        if (empty($catalog_post_id)) {
            $catalog_post_id = $handler->find_minifig_by_num($catalog_sku);
            $catalog_post_type = 'lww_minifig';
        }

        if (empty($catalog_post_id)) {
            lww_log_unresolved_reference($job_id, 'ebay_sync', 'Catalog SKU', $catalog_sku, 0);
            $processed_count++;
            $processed_in_this_batch++;
            continue;
        }

        $items_to_process = [];
        if (!empty($details['variations'])) {
            $items_to_process = $details['variations'];
        } else {
            $items_to_process[] = $details;
        }

        foreach ($items_to_process as $item_data) {
            $variation_sku = $item_data['sku'] ?? $catalog_sku;
            $unique_meta_key = '_lww_inventory_uid';
            $unique_meta_value = 'ebay|' . $listing_id . '|' . $variation_sku;

            $post_id = $handler->find_post_by_meta('lww_inventory_item', $unique_meta_key, $unique_meta_value);

            $post_title = get_the_title($catalog_post_id);
            if (!empty($item_data['variation_name'])) {
                $post_title .= ' - ' . $item_data['variation_name'];
            }

            $post_data = [
                'post_title'   => $post_title,
                'post_status'  => 'publish',
                'post_type'    => 'lww_inventory_item',
            ];

            if ($post_id > 0) {
                $post_data['ID'] = $post_id;
                wp_update_post($post_data);
            } else {
                $post_id = wp_insert_post($post_data, true);
                if (is_wp_error($post_id)) {
                    lww_log_to_job($job_id, sprintf('FEHLER (eBay-Import): Konnte "%s" nicht erstellen: %s', $post_title, $post_id->get_error_message()));
                    continue;
                }
                lww_log_to_job($job_id, sprintf('INFO (eBay-Import): "%s" (ID: %d) NEU erstellt.', $post_title, $post_id));
            }

            // Meta-Daten speichern
            update_post_meta($post_id, $unique_meta_key, $unique_meta_value);
            if ($catalog_post_type === 'lww_set') {
                update_post_meta($post_id, '_lww_set_id', $catalog_post_id);
                delete_post_meta($post_id, '_lww_minifig_id');
                delete_post_meta($post_id, '_lww_part_id');
            } else {
                update_post_meta($post_id, '_lww_minifig_id', $catalog_post_id);
                delete_post_meta($post_id, '_lww_set_id');
                delete_post_meta($post_id, '_lww_part_id');
            }
            update_post_meta($post_id, '_quantity', intval($item_data['quantity']));
            update_post_meta($post_id, '_price', floatval($item_data['price']));
            update_post_meta($post_id, '_condition', strtolower($item_data['condition']) === 'new' ? 'new' : 'used');
            update_post_meta($post_id, '_lww_ebay_listing_id', $listing_id);
            update_post_meta($post_id, '_lww_ebay_variation_sku', $variation_sku);
        }

        $processed_count++;
        $processed_in_this_batch++;
    }

    update_post_meta($job_id, '_processed_items', $processed_count);
    lww_log_to_job($job_id, sprintf('Batch beendet. %d von %d eBay-Angeboten verarbeitet.', $processed_count, $total_items));
    lww_log_system_event(sprintf('eBay-Sync-Batch beendet. %d/%d verarbeitet.', $processed_count, $total_items));
}

/**
 * Verarbeitet einen Batch eines BrickOwl Preis-Synchronisations-Jobs.
 */
function lww_process_brickowl_sync_batch($job_id) {
    lww_log_system_event('--- Start lww_process_brickowl_sync_batch (Job ' . $job_id . ') ---');

    $item_ids = get_post_meta($job_id, '_item_ids_to_process', true);
    $total_items = (int) get_post_meta($job_id, '_total_items', true);
    $processed_count = (int) get_post_meta($job_id, '_processed_items', true);

    if (!is_array($item_ids) || empty($item_ids)) {
        lww_log_to_job($job_id, 'Keine Artikel-IDs für die Synchronisation gefunden. Job wird abgeschlossen.');
        wp_update_post(['ID' => $job_id, 'post_status' => 'lww_complete']);
        if (get_option('lww_current_running_job_id') == $job_id) delete_option('lww_current_running_job_id');
        return;
    }

    $batch_size = apply_filters('lww_brickowl_sync_batch_size', 15);
    $items_in_this_batch = array_slice($item_ids, $processed_count, $batch_size);

    if (empty($items_in_this_batch)) {
        lww_log_to_job($job_id, sprintf('BrickOwl Preis-Synchronisation für alle %d Artikel abgeschlossen.', $total_items));
        wp_update_post(['ID' => $job_id, 'post_status' => 'lww_complete']);
        if (get_option('lww_current_running_job_id') == $job_id) delete_option('lww_current_running_job_id');
        return;
    }

    $api_settings = get_option('lww_api_settings');
    $api_key = $api_settings['brickowl_api_key'] ?? '';
    if (empty($api_key)) {
        lww_fail_job($job_id, __('BrickOwl API-Schlüssel nicht konfiguriert.', 'lego-wawi'));
        return;
    }
    $brickowl_api = new LWW_BrickOwl_API($api_key);

    $processed_in_this_batch = 0;
    foreach ($items_in_this_batch as $item_id) {
        @set_time_limit(60);

        if (($processed_in_this_batch > 0) && ($processed_in_this_batch % 5) === 0) {
            wp_update_post(['ID' => $job_id, 'post_modified' => current_time('mysql'), 'post_modified_gmt' => current_time('mysql', 1)]);
        }

        $boid = get_post_meta($item_id, '_boid', true);

        if (empty($boid)) {
            lww_log_to_job($job_id, sprintf('WARNUNG bei Artikel %d: Keine BOID gefunden, wird übersprungen.', $item_id));
            $processed_count++;
            $processed_in_this_batch++;
            continue;
        }

        $new_price = $brickowl_api->get_item_price($boid);

        if (is_wp_error($new_price)) {
            lww_log_to_job($job_id, sprintf('FEHLER bei Artikel %d (BOID: %s): %s', $item_id, $boid, $new_price->get_error_message()));
        } else {
            $old_price = (float) get_post_meta($item_id, '_price', true);
            if (abs($new_price - $old_price) > 0.0001) { 
                update_post_meta($item_id, '_price', $new_price);
                $history = get_post_meta($item_id, '_lww_price_history', true);
                if (!is_array($history)) $history = [];
                $history[] = ['timestamp' => time(), 'price' => $new_price, 'source' => 'brickowl_sync_job'];
                if (count($history) > 20) $history = array_slice($history, -20);
                update_post_meta($item_id, '_lww_price_history', $history);
            }
        }
        $processed_count++;
        $processed_in_this_batch++;
    }

    update_post_meta($job_id, '_processed_items', $processed_count);
    lww_log_to_job($job_id, sprintf('Batch beendet. %d von %d Artikeln synchronisiert.', $processed_count, $total_items));
    lww_log_system_event(sprintf('BrickOwl-Sync-Batch beendet. %d/%d verarbeitet.', $processed_count, $total_items));
}

/**
 * Verarbeitet einen Batch eines Datenbereinigungs-Jobs.
 */
function lww_process_data_purge_batch($job_id) {
    $step = (int) get_post_meta($job_id, '_purge_step', true);
    $batch_size = 500;

    $purge_steps = [
        ['type' => 'cpt', 'name' => 'lww_inventory_item', 'label' => 'Inventar-Einträge'],
        ['type' => 'cpt', 'name' => 'lww_part', 'label' => 'Teile'],
        ['type' => 'cpt', 'name' => 'lww_set', 'label' => 'Sets'],
        ['type' => 'cpt', 'name' => 'lww_minifig', 'label' => 'Minifiguren'],
        ['type' => 'cpt', 'name' => 'lww_color', 'label' => 'Farben'],
        ['type' => 'cpt', 'name' => 'lww_api_log', 'label' => 'API-Logs'],
        ['type' => 'cpt', 'name' => 'lww_job', 'label' => 'Jobs'],
        ['type' => 'tax', 'name' => 'lww_inventory_location', 'label' => 'Lagerorte'],
        ['type' => 'tax', 'name' => 'lww_part_category', 'label' => 'Teile-Kategorien'],
        ['type' => 'tax', 'name' => 'lww_theme', 'label' => 'Themen'],
        ['type' => 'options', 'label' => 'Plugin-Optionen'],
    ];

    if ($step >= count($purge_steps)) {
        lww_log_to_job($job_id, 'Datenbereinigung erfolgreich abgeschlossen.');
        wp_update_post(['ID' => $job_id, 'post_status' => 'lww_complete']);
        lww_log_system_event('Job ' . $job_id . ' (data_purge) abgeschlossen.');
        if (get_option('lww_current_running_job_id') == $job_id) delete_option('lww_current_running_job_id');
        return;
    }

    $current_step = $purge_steps[$step];
    $deleted_count = 0;
    lww_log_to_job($job_id, sprintf('Starte Schritt %d: Lösche %s...', $step + 1, $current_step['label']));

    if ($current_step['type'] === 'cpt') {
        $query_args = [
            'post_type' => $current_step['name'],
            'posts_per_page' => $batch_size,
            'post_status' => 'any',
            'fields' => 'ids',
        ];
        // Den Bereinigungs-Job selbst nicht löschen
        if ($current_step['name'] === 'lww_job') {
            $query_args['post__not_in'] = [$job_id];
        }
        $items_to_delete = get_posts($query_args);
        if (!empty($items_to_delete)) {
            foreach ($items_to_delete as $post_id_to_delete) {
                wp_delete_post($post_id_to_delete, true);
                $deleted_count++;
            }
            lww_log_to_job($job_id, sprintf('%d %s gelöscht. Suche nach weiteren...', $deleted_count, $current_step['label']));
            // Im selben Schritt bleiben, um den nächsten Batch zu löschen
            return;
        }
    } elseif ($current_step['type'] === 'tax') {
        $terms_to_delete = get_terms(['taxonomy' => $current_step['name'], 'number' => $batch_size, 'hide_empty' => false, 'fields' => 'ids']);
        if (!empty($terms_to_delete) && !is_wp_error($terms_to_delete)) {
            foreach ($terms_to_delete as $term_id_to_delete) {
                wp_delete_term($term_id_to_delete, $current_step['name']);
                $deleted_count++;
            }
            lww_log_to_job($job_id, sprintf('%d %s gelöscht. Suche nach weiteren...', $deleted_count, $current_step['label']));
            // Im selben Schritt bleiben
            return;
        }
    } elseif ($current_step['type'] === 'options') {
        delete_option('lww_catalog_counts');
        lww_log_to_job($job_id, 'Plugin-Optionen zurückgesetzt.');
    }

    // Wenn hier angekommen, ist der Schritt abgeschlossen -> zum nächsten Schritt
    lww_log_to_job($job_id, sprintf('Schritt "%s" abgeschlossen.', $current_step['label']));
    update_post_meta($job_id, '_purge_step', $step + 1);
}

/**
 * Verarbeitet einen Batch eines Daten-Validierungs-Jobs.
 */
function lww_process_data_validation_batch($job_id) {
    lww_log_system_event('--- Start lww_process_data_validation_batch (Job ' . $job_id . ') ---');
    $processed_page = (int) get_post_meta($job_id, '_processed_page', true);
    $current_page = $processed_page + 1;
    $batch_size = apply_filters('lww_data_validation_batch_size', 200);

    $query = new WP_Query([
        'post_type'      => 'lww_inventory_item',
        'post_status'    => 'publish',
        'posts_per_page' => $batch_size,
        'paged'          => $current_page,
        'fields'         => 'ids',
        'orderby'        => 'ID', // Konsistente Reihenfolge
        'order'          => 'ASC',
    ]);

    if (!$query->have_posts()) {
        lww_log_to_job($job_id, 'Daten-Validierung für das gesamte Inventar abgeschlossen.');
        wp_update_post(['ID' => $job_id, 'post_status' => 'lww_complete']);
        delete_post_meta($job_id, '_processed_page');
        if (get_option('lww_current_running_job_id') == $job_id) delete_option('lww_current_running_job_id');
        return;
    }

    $found_issues = 0;
    foreach ($query->posts as $item_id) {
        $part_id = get_post_meta($item_id, '_lww_part_id', true);
        $set_id = get_post_meta($item_id, '_lww_set_id', true);
        $minifig_id = get_post_meta($item_id, '_lww_minifig_id', true);

        if (empty($part_id) && empty($set_id) && empty($minifig_id)) {
            lww_log_unresolved_reference(
                $job_id,
                'Daten-Validierung',
                'Fehlende Katalog-Verknüpfung',
                'Inventar-Item ID: ' . $item_id,
                0 // Keine Zeilennummer für diese Art von Prüfung
            );
            $found_issues++;
        }
    }
    
    $total_processed = (($current_page - 1) * $batch_size) + $query->post_count;
    update_post_meta($job_id, '_processed_page', $current_page);
    update_post_meta($job_id, '_processed_items', $total_processed); // Für die Fortschrittsanzeige
    
    $log_message = sprintf('Batch %d abgeschlossen. Bisher %d Artikel geprüft. %d neue Probleme gefunden.', $current_page, $total_processed, $found_issues);
    lww_log_to_job($job_id, $log_message);
    lww_log_system_event('Daten-Validierungs-Batch beendet. ' . $log_message);
}

/**
 * Hilfsfunktion zum Abschließen/Überspringen einer Aufgabe.
 */
function lww_skip_or_complete_job($job_id, $job_queue, $current_task_index, $reason = 'unknown') {
    switch ($reason) {
        case 'task_complete': $job_queue[$current_task_index]['status'] = 'complete'; break;
        case 'file_not_found':
        case 'header_read_error':
        case 'handler_not_found':
        case 'eof_during_skip':
            $job_queue[$current_task_index]['status'] = 'skipped';
            $job_queue[$current_task_index]['total_rows'] = $job_queue[$current_task_index]['rows_processed'];
            lww_log_to_job($job_id, sprintf('Aufgabe "%s" übersprungen (%s).', $job_queue[$current_task_index]['key'], $reason));
            break;
        default:
             $job_queue[$current_task_index]['status'] = 'unknown_error';
             lww_log_to_job($job_id, sprintf('Aufgabe "%s" mit unbek. Fehler.', $job_queue[$current_task_index]['key']));
            break;
    }
    $next_task_index = $current_task_index + 1;
    if (isset($job_queue[$next_task_index])) {
        update_post_meta($job_id, '_current_task_index', $next_task_index);
        update_post_meta($job_id, '_job_queue', $job_queue);
        lww_log_to_job($job_id, sprintf('Starte nächste Aufgabe "%s"...', $job_queue[$next_task_index]['key']));
        lww_log_system_event(sprintf('Nächste Aufgabe (Index %d): "%s"', $next_task_index, $job_queue[$next_task_index]['key']));
    } else {
        update_post_meta($job_id, '_job_queue', $job_queue);
        wp_update_post(['ID' => $job_id, 'post_status' => 'lww_complete']);
        lww_log_to_job($job_id, 'Katalog-Import-Job abgeschlossen.');
        lww_log_system_event('Job ' . $job_id . ' abgeschlossen.');
        if (get_option('lww_current_running_job_id') == $job_id) {
            delete_option('lww_current_running_job_id');
        }
    }
}
?>
