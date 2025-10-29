<?php
/**
 * Modul: Batch-Prozessor (v10.0 - Objektorientiert)
 * Verarbeitet die Job-Warteschlange (CPT 'lww_job') im Hintergrund.
 * Ruft dynamisch die korrekte Handler-Klasse für die Zeilenverarbeitung auf.
 * Liest Cron-Intervall und Batch-Größen aus den WordPress-Optionen.
 */
if (!defined('ABSPATH')) exit;

/**
 * Registriert benutzerdefinierte Cron-Intervalle (1, 5, 15 Min.)
 */
function lww_add_cron_interval($schedules) {
    if (!isset($schedules['lww_every_minute'])) {
        $schedules['lww_every_minute'] = [ 'interval' => 60, 'display' => esc_html__('Jede Minute (LWW Standard)', 'lego-wawi')];
    }
    if (!isset($schedules['lww_every_5_minutes'])) {
        $schedules['lww_every_5_minutes'] = [ 'interval' => 300, 'display' => esc_html__('Alle 5 Minuten (LWW)', 'lego-wawi')];
    }
    if (!isset($schedules['lww_every_15_minutes'])) {
        $schedules['lww_every_15_minutes'] = [ 'interval' => 900, 'display' => esc_html__('Alle 15 Minuten (LWW)', 'lego-wawi')];
    }
    return $schedules;
}
add_filter('cron_schedules', 'lww_add_cron_interval');

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
        // DANN: Nach dem ältesten wartenden Job suchen
        $pending_jobs_query = new WP_Query(['post_type' => 'lww_job', 'post_status' => 'lww_pending', 'posts_per_page' => 1, 'orderby' => 'date', 'order' => 'ASC']);
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
    update_option('lww_current_running_job_id', $job_id);
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
        } elseif ($job_type === 'inventory_import') {
            lww_process_inventory_job_batch($job_id);
        } else {
            throw new Exception(sprintf(__('Unbekannter Job-Typ: %s', 'lego-wawi'), esc_html($job_type)));
        }

        $current_status = get_post_status($job_id);
        if ($current_status === 'lww_complete' || $current_status === 'lww_failed') {
            lww_log_system_event('Job ' . $job_id . ' markiert als "' . $current_status . '".');
        } else if ($current_status === 'lww_running') {
             wp_update_post(['ID' => $job_id, 'post_modified' => current_time('mysql'), 'post_modified_gmt' => current_time('mysql', 1)]);
             lww_log_system_event('Batch Job ' . $job_id . ' beendet, Status "' . $current_status . '".');
        } else {
            lww_log_system_event('WARNUNG: Unerwarteter Status "' . $current_status . '" nach Batch für Job ' . $job_id);
        }

    } catch (Exception $e) {
        lww_fail_job($job_id, 'KRITISCHER FEHLER: ' . $e->getMessage());
        lww_log_system_event('Job ' . $job_id . ' fehlgeschlagen: ' . $e->getMessage());
    } finally {
        // 5. Globale Sperre IMMER aufheben
        if (get_option('lww_current_running_job_id') == $job_id) {
            delete_option('lww_current_running_job_id');
            lww_log_system_event('Globale Job-Sperre Job ' . $job_id . ' aufgehoben.');
        } else {
             lww_log_system_event('Job-Sperre war bereits aufgehoben (vermutlich Timeout).');
        }
    }

     lww_log_system_event('===== Cron Hook `lww_run_job_processor` ENDE =====');
}

/**
 * Verarbeitet einen Batch eines KATALOG-Jobs (v10.0 - nutzt Handler)
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

    // --- Header einlesen (nur beim ersten Mal) ---
    $header_map_key = '_header_map_' . $file_key;
    $header_map = get_post_meta($job_id, $header_map_key, true);
    if ($current_row === 0 || empty($header_map)) {
        lww_log_system_event('Lese Header...'); $header = @fgetcsv($handle);
        if ($header && is_array($header) && count($header) > 0) {
            $header_map = array_flip(array_map('trim', array_map('strtolower', $header)));
            update_post_meta($job_id, $header_map_key, $header_map);
            $current_row++; $job_queue[$task_index]['rows_processed'] = $current_row;
            update_post_meta($job_id, '_job_queue', $job_queue);
            lww_log_system_event('Header gelesen.');
        } else { @fclose($handle); lww_log_to_job($job_id, sprintf('FEHLER: Header nicht lesbar oder Datei "%s" ist leer. Überspringe.', basename($file_path))); return lww_skip_or_complete_job($job_id, $job_queue, $task_index, 'header_read_error'); }
    }
    if (empty($header_map) || !is_array($header_map)) { @fclose($handle); throw new Exception(sprintf('Header-Map fehlt: %s', $file_key)); }

    // --- Handler-Klasse finden (Factory) ---
    static $handler_cache = [];
    // Baut Klassennamen: 'colors' -> 'LWW_Import_Colors_Handler', 'part_categories' -> 'LWW_Import_Part_Categories_Handler'
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

    // --- Batch verarbeiten ---
    $processed_in_this_batch = 0;
    lww_log_system_event('Starte Batch mit ' . $class_name . ' (Z ' . $current_row . ' bis ca. ' . ($current_row + $batch_size) . ')');
    while ($processed_in_this_batch < $batch_size && !feof($handle)) {
        @set_time_limit(60);
        $line_number_for_log = $current_row + 1;
        $data = @fgetcsv($handle);
        if ($data === FALSE || $data === null || (count($data) === 1 && ($data[0] === null || trim($data[0]) === ''))) { if (feof($handle)) { lww_log_system_event('EOF in while'); break; } $current_row++; continue; }
        
        // Flexible Spaltenanzahl-Prüfung (manche CSVs haben variable Spalten)
        // if (count($data) !== count($header_map)) { /* ... fehlerbehandlung ... */ }

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
        $job_queue[$task_index]['status'] = 'complete'; $job_queue[$task_index]['total_rows'] = max(0, $current_row - 1);
        lww_log_to_job($job_id, sprintf('Aufgabe "%s" abgeschlossen (%d Zeilen).', $file_key, $job_queue[$task_index]['total_rows']));
        lww_skip_or_complete_job($job_id, $job_queue, $task_index, 'task_complete');
    } else {
        @fclose($handle); update_post_meta($job_id, '_job_queue', $job_queue);
        lww_log_to_job($job_id, sprintf('Aufgabe "%s": Batch beendet, %d Zeilen verarbeitet.', $file_key, max(0, $current_row-1)));
    }
     lww_log_system_event('--- Ende lww_process_catalog_job_batch ---');
}

/**
 * Verarbeitet einen Batch eines INVENTAR-Jobs (v10.0 - nutzt Handler)
 */
function lww_process_inventory_job_batch($job_id) {
    lww_log_system_event('--- Start lww_process_inventory_job_batch (Job ' . $job_id . ') ---');
    $job_queue = get_post_meta($job_id, '_job_queue', true); $task_index = 0;
    if (!is_array($job_queue) || !isset($job_queue[$task_index])) { throw new Exception('Inventar-Queue ungültig.'); }
    $current_task = $job_queue[$task_index]; $file_key = $current_task['key'] ?? 'inventory'; $file_path = $current_task['path'] ?? ''; $current_row = isset($current_task['rows_processed']) ? (int)$current_task['rows_processed'] : 0;
    $batch_size = max(50, (int)get_option('lww_inventory_batch_size', 300));
    lww_log_system_event(sprintf('Verarbeite "%s", Z %d, Batch: %d', $file_key, $current_row, $batch_size));
    if (empty($file_path)) { throw new Exception('Kein Pfad für Inventar.'); }
    if (!file_exists($file_path)) { lww_log_to_job($job_id, sprintf('FEHLER: Inventar-Datei fehlt: %s.', basename($file_path))); wp_update_post(['ID' => $job_id, 'post_status' => 'lww_failed']); return; }
    $handle = @fopen($file_path, 'r'); if (!$handle) { throw new Exception(sprintf('Inventar-Datei nicht lesbar: %s', basename($file_path))); }
    if ($current_row > 0) { lww_log_system_event('Springe Z ' . $current_row . '...'); @set_time_limit(300); for ($i = 0; $i < $current_row; $i++) { if (feof($handle)) { lww_log_system_event('WARN: EOF beim Springen Z ' . $current_row); @fclose($handle); return lww_skip_or_complete_job($job_id, $job_queue, $task_index, 'eof_during_skip'); } if (@fgets($handle) === false && !feof($handle)) { @fclose($handle); throw new Exception('Fehler beim Springen.'); } } lww_log_system_event('Sprung Z ' . $current_row . ' beendet.'); }
    $header_map_key = '_header_map_inventory'; $header_map = get_post_meta($job_id, $header_map_key, true);
    if ($current_row === 0 || empty($header_map)) {
        lww_log_system_event('Lese Inventar-Header...'); $header = @fgetcsv($handle);
        if ($header && is_array($header) && count($header) > 0) {
            $header_normalized = array_map('strtolower', array_map('trim', $header));
            // WICHTIG: Das Mapping MUSS zu deiner BrickOwl CSV passen!
            $header_map = [
                'boid' => array_search('boid', $header_normalized),
                'name' => array_search('name', $header_normalized) ?: array_search('item_name', $header_normalized),
                'color_name' => array_search('color', $header_normalized) ?: array_search('color_name', $header_normalized),
                'condition' => array_search('condition', $header_normalized),
                'quantity' => array_search('qty', $header_normalized) ?: array_search('quantity', $header_normalized),
                'price' => array_search('price', $header_normalized) ?: array_search('unit_price', $header_normalized),
                'bulk' => array_search('bulk', $header_normalized),
                'sale_price' => array_search('sale_price', $header_normalized),
                'remarks' => array_search('remarks', $header_normalized),
                'external_id' => array_search('external_id', $header_normalized) ?: array_search('external_id_1', $header_normalized),
                'location' => array_search('location', $header_normalized),
                'tier_qty_1' => array_search('tier_qty_1', $header_normalized), 'tier_price_1' => array_search('tier_price_1', $header_normalized),
                'tier_qty_2' => array_search('tier_qty_2', $header_normalized), 'tier_price_2' => array_search('tier_price_2', $header_normalized),
                'tier_qty_3' => array_search('tier_qty_3', $header_normalized), 'tier_price_3' => array_search('tier_price_3', $header_normalized),
            ];
            $required_cols = ['boid','color_name','condition','quantity','price']; $missing_cols = [];
            foreach($required_cols as $req_col){ if(!isset($header_map[$req_col]) || $header_map[$req_col] === false) { $missing_cols[] = $req_col; } }
            if(!empty($missing_cols)){ @fclose($handle); throw new Exception('Inventar-CSV Spalten fehlen: '.implode(', ',$missing_cols)); }
            update_post_meta($job_id, $header_map_key, $header_map); $current_row++; $job_queue[$task_index]['rows_processed'] = $current_row; update_post_meta($job_id, '_job_queue', $job_queue); lww_log_system_event('Inventar-Header gelesen.');
        } else { @fclose($handle); throw new Exception('Inventar-Header nicht lesbar.'); }
    }
    if (empty($header_map) || !is_array($header_map)) { @fclose($handle); throw new Exception('Inventar-Header-Map fehlt.'); }

    // --- Handler-Klasse finden (v10.0) ---
    static $handler_cache_inv = [];
    // WICHTIG: Nutzt den umbenannten Handler
    $class_name = 'LWW_Import_Inventory_Handler';
    if (!isset($handler_cache_inv[$class_name])) {
         if (class_exists($class_name)) { $handler_cache_inv[$class_name] = new $class_name(); }
         else { $handler_cache_inv[$class_name] = false; }
    }
    $handler = $handler_cache_inv[$class_name];
    if (!$handler || !($handler instanceof LWW_Import_Handler_Interface)) { throw new Exception(sprintf('KRITISCH: Inventar-Handler (%s) fehlt.', $class_name)); }

    // --- Batch verarbeiten ---
    $processed_in_this_batch = 0;
    lww_log_system_event('Starte Inventar Batch mit ' . $class_name . ' (Z '.$current_row.' bis ca. '.($current_row+$batch_size).')');
    while ($processed_in_this_batch < $batch_size && !feof($handle)) {
        @set_time_limit(60);
        $line_number_for_log = $current_row + 1; $data = @fgetcsv($handle);
        if ($data === FALSE || $data === null || (count($data) === 1 && ($data[0] === null || trim($data[0]) === ''))) { if (feof($handle)) { lww_log_system_event('Inventar: EOF in while'); break; } $current_row++; continue; }
        try {
            $handler->process_row($job_id, $data, $header_map);
        } catch (Exception $e) { lww_log_to_job($job_id, sprintf('FEHLER Inventar Z %d: %s', $line_number_for_log, $e->getMessage())); }
        $current_row++; $processed_in_this_batch++;
    }
    lww_log_system_event('Inventar Batch beendet. '.$processed_in_this_batch.' Zeilen. Aktuelle Z: '.$current_row);

    // --- Status nach dem Batch aktualisieren ---
    $job_queue[$task_index]['rows_processed'] = $current_row;
    if (feof($handle)) {
        lww_log_system_event('Inventar: EOF erreicht.'); @fclose($handle); @unlink($file_path);
        $job_queue[$task_index]['status'] = 'complete'; $job_queue[$task_index]['total_rows'] = max(0, $current_row - 1);
        wp_update_post(['ID' => $job_id, 'post_status' => 'lww_complete']);
        lww_log_to_job($job_id, sprintf('Inventar-Import-Job abgeschlossen (%d Zeilen).', $job_queue[$task_index]['total_rows']));
    } else {
        @fclose($handle); lww_log_to_job($job_id, sprintf('Inventar-Import: Batch beendet, %d Zeilen verarbeitet.', max(0, $current_row - 1)));
    }
    update_post_meta($job_id, '_job_queue', $job_queue);
    lww_log_system_event('--- Ende lww_process_inventory_job_batch ---');
}


// --- HILFSFUNKTIONEN ---

/**
 * Plant den Cron Job.
 */
function lww_start_cron_job() {
    $hook = 'lww_main_batch_hook';
    $interval = get_option('lww_cron_interval', 'lww_every_minute');
    $schedules = wp_get_schedules();
    if (!isset($schedules[$interval])) { lww_log_system_event('FEHLER: Ungültiges Cron-Intervall "' . $interval . '". Nutze "lww_every_minute".'); $interval = 'lww_every_minute'; }
    if (!wp_next_scheduled($hook)) {
        $scheduled = wp_schedule_event(time() + 10, $interval, $hook);
        if ($scheduled === false) { lww_log_system_event('FEHLER: Konnte Cron "' . $hook . '" nicht planen!'); }
        else { lww_log_system_event('Cron "' . $hook . '" geplant (Intervall: ' . $interval . ').'); }
    } else { lww_log_system_event('Cron "' . $hook . '" ist bereits geplant.'); }
}

/**
 * Entfernt den Cron Job.
 */
function lww_stop_cron_job() {
    $hook = 'lww_main_batch_hook';
    $timestamp = wp_next_scheduled($hook);
    if ($timestamp) {
        $unscheduled = wp_unschedule_event($timestamp, $hook);
        if ($unscheduled === false) { lww_log_system_event('FEHLER: Konnte Cron "' . $hook . '" nicht stoppen!'); }
        else { lww_log_system_event('Cron "' . $hook . '" gestoppt.'); }
    }
    wp_clear_scheduled_hook($hook);
}

/**
 * Markiert einen Job als fehlgeschlagen.
 */
function lww_fail_job($job_id, $message) {
    if (get_post_status($job_id) !== 'lww_failed') {
        wp_update_post(['ID' => $job_id, 'post_status' => 'lww_failed']);
        lww_log_to_job($job_id, 'FEHLER: ' . $message);
        if (get_option('lww_current_running_job_id') == $job_id) { delete_option('lww_current_running_job_id'); }
        lww_log_system_event('Job ' . $job_id . ' fehlgeschlagen.');
    }
}

/**
 * Fügt eine Nachricht zum Job-Log hinzu.
 */
function lww_log_to_job($job_id, $message) {
    if (empty($job_id)) return;
    $log = get_post_meta($job_id, '_job_log', true);
    if (!is_array($log)) $log = [];
    $log_entry = sprintf('[%s] %s', wp_date('H:i:s'), $message); $log[] = $log_entry;
    $max_log_entries = apply_filters('lww_max_job_log_entries', 200);
    if (count($log) > $max_log_entries) { $log = array_slice($log, -$max_log_entries); }
    update_post_meta($job_id, '_job_log', $log);
}

/**
 * Schreibt in das PHP Error Log.
 */
function lww_log_system_event($message) {
    if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG === true) {
        error_log('[LWW System] ' . $message);
    }
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
    }
}
?>