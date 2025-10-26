<?php
/**
 * Modul: Batch-Prozessor (v9.3)
 *
 * Verarbeitet die Job-Warteschlange (CPT 'lww_job') im Hintergrund.
 * KORREKTUR: Sucht jetzt korrekt nach laufenden Jobs zum Fortsetzen,
 * bevor nach neuen wartenden Jobs gesucht wird.
 */
if (!defined('ABSPATH')) exit;

// NEU:
function lww_add_cron_interval($schedules) {
    // Fügt benutzerdefinierte Intervalle hinzu, falls sie nicht existieren
    if (!isset($schedules['lww_every_minute'])) {
        $schedules['lww_every_minute'] = [
            'interval' => 60, // 60 Sekunden
            'display'  => esc_html__('Jede Minute (LWW Standard)', 'lego-wawi')
        ];
    }
    if (!isset($schedules['lww_every_5_minutes'])) {
        $schedules['lww_every_5_minutes'] = [
            'interval' => 300, // 5 * 60 Sekunden
            'display'  => esc_html__('Alle 5 Minuten (LWW)', 'lego-wawi')
        ];
    }
    if (!isset($schedules['lww_every_15_minutes'])) {
        $schedules['lww_every_15_minutes'] = [
            'interval' => 900, // 15 * 60 Sekunden
            'display'  => esc_html__('Alle 15 Minuten (LWW)', 'lego-wawi')
        ];
    }
    return $schedules;
}

add_filter('cron_schedules', 'lww_add_cron_interval');

// Hook unverändert...
add_action('lww_main_batch_hook', 'lww_run_job_processor');

/**
 * Haupt-Job-Verarbeitungsfunktion ("Job Manager").
 * Wird (potenziell) jede Minute vom Cron aufgerufen.
 */
function lww_run_job_processor() {
    lww_log_system_event('===== Cron Hook `lww_run_job_processor` START =====');

    // 1. Job-Sperre prüfen
    $current_job_id_option = get_option('lww_current_running_job_id');
    if ($current_job_id_option) {
        // Prüfen ob Sperre noch gültig ist
        $job_post = get_post($current_job_id_option);
        if (!$job_post || !in_array($job_post->post_status, ['lww_running'])) { // Nur 'running' prüfen
            delete_option('lww_current_running_job_id');
            lww_log_system_event('Alte Job-Sperre aufgehoben für ungültigen/nicht laufenden Job ' . $current_job_id_option);
            $current_job_id_option = false; // Sperre ist weg
        } else {
            lww_log_system_event('Job ' . $current_job_id_option . ' läuft bereits (Sperre aktiv). Cron-Instanz beendet.');
            return; // Job läuft noch, diese Instanz beenden.
        }
    } else {
         lww_log_system_event('Keine aktive Job-Sperre gefunden.');
    }

    // --- NEUE LOGIK v9.3 START ---
    $job_id = 0;
    $job_to_process = null;

    // 2. ZUERST: Nach einem laufenden Job suchen (der vielleicht pausiert hat)
    lww_log_system_event('Suche nach laufendem Job (lww_running)...');
    $running_job_args = [
        'post_type'      => 'lww_job',
        'post_status'    => 'lww_running',
        'posts_per_page' => 1,
        'orderby'        => 'modified', // Den, der am längsten nicht bearbeitet wurde? Oder 'date' ASC?
        'order'          => 'ASC'
    ];
    $running_jobs_query = new WP_Query($running_job_args);

    if ($running_jobs_query->have_posts()) {
        $job_to_process = $running_jobs_query->posts[0];
        $job_id = $job_to_process->ID;
        lww_log_system_event('Laufenden Job gefunden: ID ' . $job_id . '. Setze Verarbeitung fort.');
    } else {
        lww_log_system_event('Kein laufender Job gefunden.');
        // 3. DANN: Nach dem ältesten wartenden Job suchen
        lww_log_system_event('Suche nach ältestem wartenden Job (lww_pending)...');
        $pending_job_args = [
            'post_type'      => 'lww_job',
            'post_status'    => 'lww_pending',
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'order'          => 'ASC'
        ];
        $pending_jobs_query = new WP_Query($pending_job_args);

        if ($pending_jobs_query->have_posts()) {
             $job_to_process = $pending_jobs_query->posts[0];
             $job_id = $job_to_process->ID;
             lww_log_system_event('Wartenden Job gefunden: ID ' . $job_id . '. Starte Verarbeitung.');
             // Status muss noch auf 'running' gesetzt werden
        }
    }
    // --- NEUE LOGIK v9.3 ENDE ---

    // Wenn weder ein laufender noch ein wartender Job gefunden wurde
    if (!$job_to_process) {
        lww_log_system_event('Keine laufenden oder wartenden Jobs gefunden. Cron-Instanz beendet.');
        // Cron NICHT stoppen
        return;
    }

    // 4. Job gefunden! Daten holen, Status ggf. auf 'running' setzen, Sperre setzen.
    $job_type = get_post_meta($job_id, '_job_type', true);

    // Sperre setzen
    update_option('lww_current_running_job_id', $job_id);
    lww_log_system_event('Globale Sperre für Job ' . $job_id . ' gesetzt.');

    // Status auf 'running' setzen, falls er 'pending' war
    if ($job_to_process->post_status === 'lww_pending') {
        $update_status = wp_update_post(['ID' => $job_id, 'post_status' => 'lww_running']);
         if ($update_status === 0 || is_wp_error($update_status)) {
             lww_log_system_event('FEHLER: Konnte Status für Job ' . $job_id . ' nicht auf "lww_running" setzen.');
             delete_option('lww_current_running_job_id'); // Sperre wieder lösen bei Fehler
             return;
         }
        lww_log_to_job($job_id, sprintf('Job %d gestartet (Typ: %s).', $job_id, esc_html($job_type)));
    } else {
        // Wenn der Job bereits 'running' war, nur eine Log-Nachricht hinzufügen
        lww_log_to_job($job_id, sprintf('Job %d fortgesetzt (Typ: %s).', $job_id, esc_html($job_type)));
    }

    // 5. Job-spezifische Verarbeitungsfunktion aufrufen
    $job_finished_or_failed = false;
    try {
        lww_log_system_event('Starte Verarbeitung für Job ' . $job_id . '...');
        if ($job_type === 'catalog_import') {
            lww_process_catalog_job_batch($job_id);
        } elseif ($job_type === 'inventory_import') {
            lww_process_inventory_job_batch($job_id);
        } else {
            throw new Exception(sprintf(__('Unbekannter Job-Typ: %s', 'lego-wawi'), esc_html($job_type)));
        }

        $current_status = get_post_status($job_id);
        if ($current_status === 'lww_complete' || $current_status === 'lww_failed') {
            $job_finished_or_failed = true;
            lww_log_system_event('Job ' . $job_id . ' wurde als "' . $current_status . '" markiert.');
        } else {
             lww_log_system_event('Batch für Job ' . $job_id . ' beendet, Job-Status weiterhin "' . $current_status . '".');
        }

    } catch (Exception $e) {
        lww_fail_job($job_id, 'KRITISCHER FEHLER im Job-Prozessor: ' . $e->getMessage());
        $job_finished_or_failed = true;
        lww_log_system_event('Job ' . $job_id . ' mit kritischem Fehler fehlgeschlagen: ' . $e->getMessage());
    } finally {
        // 6. Globale Sperre IMMER aufheben
        delete_option('lww_current_running_job_id');
        lww_log_system_event('Globale Job-Sperre für Job ' . $job_id . ' aufgehoben.');
    }

     lww_log_system_event('===== Cron Hook `lww_run_job_processor` ENDE =====');

} // Ende lww_run_job_processor


// -----------------------------------------------------------------------------
// Funktionen lww_process_catalog_job_batch & lww_process_inventory_job_batch
// sind UNVERÄNDERT von Version 9.2 (mit dem detaillierten Logging)
// -----------------------------------------------------------------------------
function lww_process_catalog_job_batch($job_id){ /* ... unverändert ... */
    lww_log_system_event('--- Start lww_process_catalog_job_batch (Job ' . $job_id . ') ---'); // NEU
    $job_queue = get_post_meta($job_id, '_job_queue', true); $task_index = get_post_meta($job_id, '_current_task_index', true);
    if (!is_array($job_queue) || !isset($job_queue[$task_index])) { throw new Exception('Katalog-Job-Warteschlange ungültig.'); }
    $current_task = $job_queue[$task_index]; $file_key = $current_task['key']; $file_path = $current_task['path']; $current_row = $current_task['rows_processed']; $batch_size = apply_filters('lww_catalog_batch_size', 200);
    lww_log_system_event('Verarbeite Aufgabe "' . $file_key . '" (Job ' . $job_id . '), starte bei Zeile ' . $current_row . ', Batch-Größe: ' . $batch_size); // NEU
    if (!file_exists($file_path)) { throw new Exception('Import-Datei nicht gefunden: ' . basename($file_path)); }
    lww_log_system_event('Öffne Datei: ' . basename($file_path) . ' (Job ' . $job_id . ')'); // NEU
    $handle = @fopen($file_path, 'r'); if (!$handle) { throw new Exception('Import-Datei konnte nicht geöffnet werden: ' . basename($file_path)); }
    if ($current_row > 0) { lww_log_system_event('Springe zu Zeile ' . $current_row . ' in ' . basename($file_path) . ' (Job ' . $job_id . ')'); @set_time_limit(300); for ($i = 0; $i < $current_row; $i++) { if (feof($handle)) { lww_log_system_event('WARNUNG: EOF beim Springen zu Zeile ' . $current_row . ' (Job ' . $job_id . ')'); break; } @fgets($handle); } lww_log_system_event('Sprung zu Zeile ' . $current_row . ' beendet (Job ' . $job_id . ')'); }
    $header_map_key = '_header_map_' . $file_key; $header_map = get_post_meta($job_id, $header_map_key, true);
    if ($current_row === 0 && !$header_map) { lww_log_system_event('Lese Header aus ' . basename($file_path) . ' (Job ' . $job_id . ')'); $header = @fgetcsv($handle); if ($header && is_array($header)) { $header_map = array_flip(array_map('trim', $header)); update_post_meta($job_id, $header_map_key, $header_map); $current_row++; $job_queue[$task_index]['rows_processed'] = $current_row; update_post_meta($job_id, '_job_queue', $job_queue); lww_log_system_event('Header gelesen und gespeichert (Job ' . $job_id . ')'); } else { @fclose($handle); throw new Exception(sprintf('Header nicht lesbar: %s', basename($file_path))); } }
    if (empty($header_map) || !is_array($header_map)) { @fclose($handle); throw new Exception(sprintf('Header-Map nicht gefunden: %s', basename($file_path))); }
    $processed_in_this_batch = 0; lww_log_system_event('Starte Batch-Verarbeitung (Zeile ' . $current_row . ' bis ca. ' . ($current_row + $batch_size) . ') für ' . basename($file_path) . ' (Job ' . $job_id . ')');
    while ($processed_in_this_batch < $batch_size && !feof($handle)) { $line_number_for_log = $current_row + 1; $data = @fgetcsv($handle); if ($data === FALSE || $data === null || (count($data) === 1 && ($data[0] === null || $data[0] === ''))) { if (feof($handle)) { lww_log_system_event('Dateiende erreicht in while-Schleife (Job ' . $job_id . ')'); break; } $current_row++; continue; } if (count($data) !== count($header_map)) { lww_log_to_job($job_id, sprintf('WARNUNG: Z %d Spaltenanzahl falsch (%d!=%d) in %s', $line_number_for_log, count($data), count($header_map), basename($file_path))); $current_row++; continue; }
        try { switch ($file_key) { /* ... case Anweisungen unverändert ... */ case 'colors': lww_import_colors_data($job_id, $data, $header_map); break; case 'themes': lww_import_themes_data($job_id, $data, $header_map); break; case 'part_categories': lww_import_part_categories_data($job_id, $data, $header_map); break; case 'parts': lww_import_parts_data($job_id, $data, $header_map); break; case 'sets': lww_import_sets_data($job_id, $data, $header_map); break; case 'minifigs': lww_import_minifigs_data($job_id, $data, $header_map); break; case 'elements': lww_import_elements_data($job_id, $data, $header_map); break; case 'part_relationships': lww_import_part_relationships_data($job_id, $data, $header_map); break; case 'inventories': lww_import_inventories_data($job_id, $data, $header_map); break; case 'inventory_parts': lww_import_inventory_parts_data($job_id, $data, $header_map); break; case 'inventory_sets': lww_import_inventory_sets_data($job_id, $data, $header_map); break; case 'inventory_minifigs': lww_import_inventory_minifigs_data($job_id, $data, $header_map); break; default: lww_log_to_job($job_id, sprintf('WARNUNG: Keine Import Fkt für Typ "%s" (Z %d).', $file_key, $line_number_for_log)); break; }
        } catch (Exception $e) { lww_log_to_job($job_id, sprintf('FEHLER Z %d in %s: %s', $line_number_for_log, basename($file_path), $e->getMessage())); }
        $current_row++; $processed_in_this_batch++;
    } lww_log_system_event('Batch-Verarbeitung beendet. ' . $processed_in_this_batch . ' Zeilen in diesem Lauf. Aktuelle Zeile: ' . $current_row . ' (Job ' . $job_id . ')');
    $job_queue[$task_index]['rows_processed'] = $current_row;
    if (feof($handle)) { lww_log_system_event('Dateiende für ' . basename($file_path) . ' erreicht (Job ' . $job_id . ')'); @fclose($handle); @unlink($file_path); $job_queue[$task_index]['status'] = 'complete'; $job_queue[$task_index]['total_rows'] = $current_row > 0 ? $current_row -1 : 0; lww_log_to_job($job_id, sprintf('Aufgabe "%s" abgeschlossen (%d Zeilen).', $file_key, $job_queue[$task_index]['total_rows'])); $task_index++; if (isset($job_queue[$task_index])) { update_post_meta($job_id, '_current_task_index', $task_index); update_post_meta($job_id, '_job_queue', $job_queue); lww_log_to_job($job_id, sprintf('Starte nächste Aufgabe "%s"...', $job_queue[$task_index]['key'])); lww_log_system_event('Nächste Aufgabe (' . $task_index . ') für Job ' . $job_id . ' vorbereitet: "' . $job_queue[$task_index]['key'] . '"'); } else { wp_update_post(['ID' => $job_id, 'post_status' => 'lww_complete']); lww_log_to_job($job_id, 'Katalog-Import-Job abgeschlossen.'); lww_log_system_event('Job ' . $job_id . ' abgeschlossen.'); /* Sperre wird in Hauptfunktion gelöscht */ }
    } else { @fclose($handle); update_post_meta($job_id, '_job_queue', $job_queue); lww_log_to_job($job_id, sprintf('Aufgabe "%s": Batch beendet, %d Zeilen verarbeitet.', $file_key, $current_row)); lww_log_system_event('Batch für Aufgabe "' . $file_key . '" (Job ' . $job_id . ') beendet, aber Datei noch nicht fertig.'); }
     lww_log_system_event('--- Ende lww_process_catalog_job_batch (Job ' . $job_id . ') ---');
}
function lww_process_inventory_job_batch($job_id){ /* ... unverändert von v9.0/9.2 ... */ lww_log_system_event('--- Start lww_process_inventory_job_batch (Job ' . $job_id . ') ---'); $job_queue = get_post_meta($job_id, '_job_queue', true); $task_index = 0; if (!is_array($job_queue) || !isset($job_queue[$task_index])) { throw new Exception('Inventar-Job-Warteschlange ungültig.'); } $current_task = $job_queue[$task_index]; $file_key = $current_task['key']; $file_path = $current_task['path']; $current_row = $current_task['rows_processed']; $batch_size = apply_filters('lww_inventory_batch_size', 300); lww_log_system_event('Verarbeite Aufgabe "' . $file_key . '" (Job ' . $job_id . '), starte bei Zeile ' . $current_row); if (!file_exists($file_path)) { throw new Exception('Inventar-Datei nicht gefunden: ' . basename($file_path)); } $handle = @fopen($file_path, 'r'); if (!$handle) { throw new Exception('Inventar-Datei nicht lesbar: ' . basename($file_path)); } if ($current_row > 0) { lww_log_system_event('Springe zu Zeile ' . $current_row . '...'); @set_time_limit(300); for ($i = 0; $i < $current_row; $i++) { if (feof($handle)) break; @fgets($handle); } lww_log_system_event('Sprung beendet.'); } $header_map_key = '_header_map_inventory'; $header_map = get_post_meta($job_id, $header_map_key, true); if ($current_row === 0 && !$header_map) { lww_log_system_event('Lese Inventar-Header...'); $header = @fgetcsv($handle); if ($header && is_array($header)) { $header_normalized = array_map('strtolower', array_map('trim', $header)); $header_map = [ /* ... Spaltenzuordnung unverändert ... */ 'boid'=>array_search('boid',$header_normalized),'name'=>array_search('name',$header_normalized)?:array_search('item_name',$header_normalized),'color_name'=>array_search('color',$header_normalized)?:array_search('color_name',$header_normalized),'condition'=>array_search('condition',$header_normalized),'quantity'=>array_search('qty',$header_normalized)?:array_search('quantity',$header_normalized),'price'=>array_search('price',$header_normalized)?:array_search('unit_price',$header_normalized),'bulk'=>array_search('bulk',$header_normalized),'sale_price'=>array_search('sale_price',$header_normalized),'remarks'=>array_search('remarks',$header_normalized),'external_id'=>array_search('external_id',$header_normalized)?:array_search('external_id_1',$header_normalized),'location'=>array_search('location',$header_normalized),'tier_qty_1'=>array_search('tier_qty_1',$header_normalized),'tier_price_1'=>array_search('tier_price_1',$header_normalized),'tier_qty_2'=>array_search('tier_qty_2',$header_normalized),'tier_price_2'=>array_search('tier_price_2',$header_normalized),'tier_qty_3'=>array_search('tier_qty_3',$header_normalized),'tier_price_3'=>array_search('tier_price_3',$header_normalized),]; $required_cols = ['boid','color_name','condition','quantity','price']; $missing_cols = []; foreach($required_cols as $req_col){if(!isset($header_map[$req_col])||$header_map[$req_col]===false){$missing_cols[]=$req_col;}} if(!empty($missing_cols)){ @fclose($handle); throw new Exception('Inventar-CSV Spalten fehlen: '.implode(', ',$missing_cols)); } update_post_meta($job_id, $header_map_key, $header_map); $current_row++; $job_queue[$task_index]['rows_processed'] = $current_row; update_post_meta($job_id, '_job_queue', $job_queue); lww_log_system_event('Inventar-Header gelesen.'); } else { @fclose($handle); throw new Exception('Inventar-Header nicht lesbar.'); } } if (empty($header_map) || !is_array($header_map)) { @fclose($handle); throw new Exception('Inventar-Header-Map nicht gefunden.'); }
    $processed_in_this_batch = 0; lww_log_system_event('Starte Inventar Batch (Z '.$current_row.' bis ca. '.($current_row+$batch_size).')'); while ($processed_in_this_batch < $batch_size && !feof($handle)) { $line_number_for_log = $current_row + 1; $data = @fgetcsv($handle); if ($data === FALSE || $data === null || (count($data) === 1 && ($data[0] === null || $data[0] === ''))) { if (feof($handle)) { lww_log_system_event('Inventar: EOF in while'); break; } $current_row++; continue; } try { lww_import_inventory_data($job_id, $data, $header_map); } catch (Exception $e) { lww_log_to_job($job_id, sprintf('FEHLER Inventar Z %d: %s', $line_number_for_log, $e->getMessage())); } $current_row++; $processed_in_this_batch++; } lww_log_system_event('Inventar Batch beendet. '.$processed_in_this_batch.' Zeilen verarbeitet. Aktuelle Zeile: '.$current_row);
    $job_queue[$task_index]['rows_processed'] = $current_row; if (feof($handle)) { lww_log_system_event('Inventar: EOF erreicht.'); @fclose($handle); @unlink($file_path); $job_queue[$task_index]['status'] = 'complete'; $job_queue[$task_index]['total_rows'] = $current_row > 0 ? $current_row -1 : 0; update_post_meta($job_id, '_job_queue', $job_queue); wp_update_post(['ID' => $job_id, 'post_status' => 'lww_complete']); lww_log_to_job($job_id, sprintf('Inventar-Import-Job abgeschlossen (%d Zeilen).', $job_queue[$task_index]['total_rows'])); /* Sperre wird in Hauptfunktion gelöscht */ } else { @fclose($handle); update_post_meta($job_id, '_job_queue', $job_queue); lww_log_to_job($job_id, sprintf('Inventar-Import: Batch beendet, %d Zeilen verarbeitet.', $current_row)); lww_log_system_event('Inventar Batch beendet, Datei noch nicht fertig.'); } lww_log_system_event('--- Ende lww_process_inventory_job_batch (Job ' . $job_id . ') ---');
}

// --- HILFSFUNKTIONEN FÜR JOBS & CRON ---
// (lww_start_cron_job, lww_stop_cron_job, lww_fail_job, lww_log_to_job, lww_log_system_event unverändert)
function lww_start_cron_job(){ $hook='lww_main_batch_hook'; $interval='lww_every_minute'; if(!wp_next_scheduled($hook)){ $scheduled=wp_schedule_event(time(),$interval,$hook); if($scheduled===false){lww_log_system_event('FEHLER: Konnte Cron "'.$hook.'" nicht planen!');} else {lww_log_system_event('Cron "'.$hook.'" geplant.');}} else { /* Weniger verbose */ }}
function lww_stop_cron_job(){ $hook='lww_main_batch_hook'; $timestamp=wp_next_scheduled($hook); if($timestamp){ $unscheduled=wp_unschedule_event($timestamp,$hook); if($unscheduled===false){lww_log_system_event('FEHLER: Konnte Cron "'.$hook.'" nicht stoppen!');} else {lww_log_system_event('Cron "'.$hook.'" gestoppt.');}} wp_clear_scheduled_hook($hook);}
function lww_fail_job($job_id, $message){ wp_update_post(['ID'=>$job_id,'post_status'=>'lww_failed']); lww_log_to_job($job_id,'FEHLER: '.$message); delete_option('lww_current_running_job_id'); lww_log_system_event('Job '.$job_id.' fehlgeschlagen. Sperre aufgehoben.');}
function lww_log_to_job($job_id, $message){ $log=get_post_meta($job_id,'_job_log',true);if(!is_array($log))$log=[]; $log_entry=sprintf('[%s] %s',wp_date('H:i:s'),$message);$log[]=$log_entry; $max_log_entries=apply_filters('lww_max_job_log_entries',50);if(count($log)>$max_log_entries){$log=array_slice($log,-$max_log_entries);} update_post_meta($job_id,'_job_log',$log);}
function lww_log_system_event($message){ if(defined('WP_DEBUG_LOG')&&WP_DEBUG_LOG===true){error_log('[LWW System] '.$message);}}

// --- IMPORT-FUNKTIONEN (Eine pro CSV-Typ) ---


?>