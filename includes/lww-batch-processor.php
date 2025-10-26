<?php
/**
 * Modul: Batch-Prozessor (v9.5 - Modularisiert & Einstellbar)
 *
 * Verarbeitet die Job-Warteschlange (CPT 'lww_job') im Hintergrund.
 * Ruft dynamisch die korrekte Handler-Klasse für die Zeilenverarbeitung auf.
 * Liest Cron-Intervall und Batch-Größen aus den WordPress-Optionen.
 */
if (!defined('ABSPATH')) exit;

/**
 * Registriert benutzerdefinierte Cron-Intervalle (5, 15 Min.)
 */
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

// Hook für den Haupt-Cron-Job
add_action('lww_main_batch_hook', 'lww_run_job_processor');

/**
 * Haupt-Job-Verarbeitungsfunktion ("Job Manager").
 * Wird vom WP-Cron im eingestellten Intervall aufgerufen.
 */
function lww_run_job_processor() {
    lww_log_system_event('===== Cron Hook `lww_run_job_processor` START =====');

    // 1. Job-Sperre prüfen (verhindert parallele Ausführung)
    $current_job_id_option = get_option('lww_current_running_job_id');
    if ($current_job_id_option) {
        $job_post = get_post($current_job_id_option);
        // Prüfen, ob der gesperrte Job noch existiert und wirklich läuft
        if (!$job_post || $job_post->post_status !== 'lww_running') {
            delete_option('lww_current_running_job_id');
            lww_log_system_event('Alte Job-Sperre aufgehoben für ungültigen/nicht laufenden Job ' . $current_job_id_option);
            $current_job_id_option = false; // Sperre ist weg
        } else {
            // Prüfen, wie lange der Job schon läuft (Timeout)
            $last_modified_time = strtotime($job_post->post_modified_gmt);
            // Timeout-Wert (in Sekunden), Standard 5 Minuten, kann gefiltert werden
            $timeout_seconds = apply_filters('lww_job_timeout', 300); 
            if (time() > ($last_modified_time + $timeout_seconds)) {
                lww_log_system_event('Job ' . $current_job_id_option . ' hat Timeout (' . $timeout_seconds . 's) überschritten. Sperre wird aufgehoben und Job fehlgeschlagen.');
                // Markiere Job als fehlgeschlagen und lösche die Sperre
                lww_fail_job($current_job_id_option, __('Job wegen Timeout abgebrochen.', 'lego-wawi')); 
                $current_job_id_option = false; 
            } else {
                lww_log_system_event('Job ' . $current_job_id_option . ' läuft bereits (Sperre aktiv). Cron-Instanz beendet.');
                return; // Job läuft noch, diese Instanz beenden.
            }
        }
    } else {
         lww_log_system_event('Keine aktive Job-Sperre gefunden.');
    }

    // 2. Job zum Verarbeiten finden (zuerst 'running' zum Fortsetzen, dann den ältesten 'pending')
    $job_id = 0;
    $job_to_process = null;

    // Suche nach einem laufenden Job (z.B. nach Timeout oder Server-Neustart)
    $running_jobs_query = new WP_Query([
        'post_type' => 'lww_job', 'post_status' => 'lww_running',
        'posts_per_page' => 1, 'orderby' => 'modified', 'order' => 'ASC' // Der am längsten nicht aktualisierte
    ]);

    if ($running_jobs_query->have_posts()) {
        $job_to_process = $running_jobs_query->posts[0];
        $job_id = $job_to_process->ID;
        lww_log_system_event('Laufenden Job gefunden: ID ' . $job_id . '. Setze Verarbeitung fort.');
    } else {
        // Suche nach dem ältesten wartenden Job
        $pending_jobs_query = new WP_Query([
            'post_type' => 'lww_job', 'post_status' => 'lww_pending',
            'posts_per_page' => 1, 'orderby' => 'date', 'order' => 'ASC'
        ]);

        if ($pending_jobs_query->have_posts()) {
             $job_to_process = $pending_jobs_query->posts[0];
             $job_id = $job_to_process->ID;
             lww_log_system_event('Wartenden Job gefunden: ID ' . $job_id . '. Starte Verarbeitung.');
        }
    }

    // Wenn kein Job zu tun ist, beenden
    if (!$job_to_process) {
        lww_log_system_event('Keine laufenden oder wartenden Jobs gefunden. Cron-Instanz beendet.');
        return;
    }

    // 3. Job sperren und Status auf 'running' setzen
    $job_type = get_post_meta($job_id, '_job_type', true);
    
    // Globale Sperre setzen
    update_option('lww_current_running_job_id', $job_id);
    lww_log_system_event('Globale Sperre für Job ' . $job_id . ' gesetzt.');

    // Status auf 'running' setzen, falls er 'pending' war
    if ($job_to_process->post_status === 'lww_pending') {
        // Wichtig: 'post_modified' auch hier aktualisieren, um Timeout-Problem beim Start zu vermeiden
        $update_status = wp_update_post([
            'ID' => $job_id, 
            'post_status' => 'lww_running',
            'post_modified' => current_time('mysql'), 
            'post_modified_gmt' => current_time('mysql', 1)
        ]);
         if ($update_status === 0 || is_wp_error($update_status)) {
             lww_log_system_event('FEHLER: Konnte Status für Job ' . $job_id . ' nicht auf "lww_running" setzen.');
             delete_option('lww_current_running_job_id'); // Sperre wieder lösen bei Fehler
             return;
         }
        lww_log_to_job($job_id, sprintf('Job %d gestartet (Typ: %s).', $job_id, esc_html($job_type)));
    } else {
        // Wenn der Job bereits 'running' war (Fortsetzung), nur loggen und modified aktualisieren
         wp_update_post([
            'ID' => $job_id, 
            'post_modified' => current_time('mysql'), 
            'post_modified_gmt' => current_time('mysql', 1)
        ]);
        lww_log_to_job($job_id, sprintf('Job %d fortgesetzt (Typ: %s).', $job_id, esc_html($job_type)));
    }

    // 4. Den passenden Batch-Prozessor aufrufen
    try {
        lww_log_system_event('Starte Verarbeitung für Job ' . $job_id . '...');
        if ($job_type === 'catalog_import') {
            lww_process_catalog_job_batch($job_id);
        } elseif ($job_type === 'inventory_import') {
            lww_process_inventory_job_batch($job_id);
        } else {
            throw new Exception(sprintf(__('Unbekannter Job-Typ: %s', 'lego-wawi'), esc_html($job_type)));
        }

        // Status nach der Verarbeitung prüfen
        $current_status = get_post_status($job_id); // Status erneut holen, könnte sich geändert haben
        if ($current_status === 'lww_complete' || $current_status === 'lww_failed') {
            lww_log_system_event('Job ' . $job_id . ' wurde als "' . $current_status . '" markiert.');
            // Hier könnten Aufräumaktionen stattfinden, z.B. temporäre Dateien löschen
            // lww_cleanup_job_files($job_id);
        } else if ($current_status === 'lww_running') {
             // Wichtig: 'post_modified' aktualisieren, damit Timeout korrekt funktioniert
             wp_update_post(['ID' => $job_id, 'post_modified' => current_time('mysql'), 'post_modified_gmt' => current_time('mysql', 1)]);
             lww_log_system_event('Batch für Job ' . $job_id . ' beendet, Job-Status weiterhin "' . $current_status . '". Nächster Durchlauf geplant.');
        } else {
            // Unerwarteter Status
            lww_log_system_event('WARNUNG: Unerwarteter Job-Status "' . $current_status . '" nach Batch-Verarbeitung für Job ' . $job_id);
        }

    } catch (Exception $e) {
        // Kritischer Fehler im Batch-Prozessor selbst
        lww_fail_job($job_id, 'KRITISCHER FEHLER im Job-Prozessor: ' . $e->getMessage());
        lww_log_system_event('Job ' . $job_id . ' mit kritischem Fehler fehlgeschlagen: ' . $e->getMessage());
    } finally {
        // 5. Globale Sperre IMMER aufheben, damit der nächste Cron-Lauf starten kann
        // Nur aufheben, wenn DIESER Job die Sperre hatte (relevant bei Timeouts)
        if (get_option('lww_current_running_job_id') == $job_id) {
            delete_option('lww_current_running_job_id');
            lww_log_system_event('Globale Job-Sperre für Job ' . $job_id . ' aufgehoben.');
        } else {
             lww_log_system_event('Globale Job-Sperre war bereits für Job ' . $job_id . ' aufgehoben (vermutlich durch Timeout).');
        }
    }

     lww_log_system_event('===== Cron Hook `lww_run_job_processor` ENDE =====');
}

/**
 * Verarbeitet einen Batch eines KATALOG-Jobs
 */
function lww_process_catalog_job_batch($job_id) {
    lww_log_system_event('--- Start lww_process_catalog_job_batch (Job ' . $job_id . ') ---');
    $job_queue = get_post_meta($job_id, '_job_queue', true); 
    $task_index = get_post_meta($job_id, '_current_task_index', true); // Index der aktuellen Aufgabe
    
    if (!is_array($job_queue) || !isset($job_queue[$task_index])) { 
        throw new Exception(sprintf('Katalog-Job-Warteschlange ungültig (Job %d, Index %s).', $job_id, $task_index)); 
    }
    
    $current_task = $job_queue[$task_index]; 
    $file_key = $current_task['key'] ?? 'unknown'; // z.B. 'parts', 'colors'
    $file_path = $current_task['path'] ?? ''; 
    $current_row = isset($current_task['rows_processed']) ? (int)$current_task['rows_processed'] : 0; 
    // Batch-Größe aus Optionen lesen, Mindestwert 50 sicherstellen
    $batch_size = max(50, (int)get_option('lww_catalog_batch_size', 200)); 

    lww_log_system_event(sprintf('Verarbeite Aufgabe "%s" (Index %d), starte bei Zeile %d, Batch: %d', $file_key, $task_index, $current_row, $batch_size));
    
    if (empty($file_path)) {
        throw new Exception(sprintf('Kein Dateipfad für Aufgabe "%s" (Index %d) gefunden.', $file_key, $task_index));
    }
    if (!file_exists($file_path)) { 
        // Datei existiert nicht mehr - Aufgabe überspringen oder Job fehlschlagen?
        lww_log_to_job($job_id, sprintf('FEHLER: Import-Datei für Aufgabe "%s" nicht gefunden: %s. Diese Aufgabe wird übersprungen.', $file_key, basename($file_path)));
        // Gehe zur nächsten Aufgabe oder beende den Job
        return lww_skip_or_complete_job($job_id, $job_queue, $task_index, 'file_not_found');
    }
    
    $handle = @fopen($file_path, 'r'); 
    if (!$handle) { 
        throw new Exception(sprintf('Import-Datei konnte nicht geöffnet werden: %s', basename($file_path))); 
    }

    // --- Springe zur richtigen Zeile ---
    if ($current_row > 0) { 
        lww_log_system_event('Springe zu Zeile ' . $current_row . '...'); 
        @set_time_limit(300); // Mehr Zeit für das Überspringen
        for ($i = 0; $i < $current_row; $i++) { 
            if (feof($handle)) {
                 lww_log_system_event('WARNUNG: EOF beim Springen zu Zeile ' . $current_row); 
                 @fclose($handle);
                 // Wahrscheinlich war die Zeilenzahl falsch gespeichert, Datei trotzdem als fertig markieren?
                 // Oder Job fehlschlagen lassen? Sicherer ist erstmal, als fertig zu markieren.
                 return lww_skip_or_complete_job($job_id, $job_queue, $task_index, 'eof_during_skip');
            } 
            // Fehlerbehandlung für fgets
            if (@fgets($handle) === false && !feof($handle)) {
                @fclose($handle);
                throw new Exception('Fehler beim Lesen der Datei während des Springens.');
            }
        }
        lww_log_system_event('Sprung zu Zeile ' . $current_row . ' beendet.');
    }
    
    // --- Header einlesen (nur beim ersten Mal für diese Aufgabe) ---
    $header_map_key = '_header_map_' . $file_key; 
    $header_map = get_post_meta($job_id, $header_map_key, true);
    
    // Wenn current_row 0 ist (oder Header Map fehlt), Header lesen
    if ($current_row === 0 || empty($header_map)) { 
        lww_log_system_event('Lese Header aus ' . basename($file_path)); 
        $header = @fgetcsv($handle); 
        if ($header && is_array($header) && count($header) > 0) { 
            // Header normalisieren (klein, trimmen) und als [Spaltenname => Index] speichern
            $header_map = array_flip(array_map('trim', array_map('strtolower', $header))); 
            update_post_meta($job_id, $header_map_key, $header_map); 
            $current_row++; // Header-Zeile wurde gelesen
            $job_queue[$task_index]['rows_processed'] = $current_row;
            // Fortschritt speichern, bevor der Batch startet
            update_post_meta($job_id, '_job_queue', $job_queue); 
             lww_log_system_event('Header gelesen und gespeichert.');
        } else { 
            // Header konnte nicht gelesen werden oder Datei ist leer
            @fclose($handle); 
            // Aufgabe überspringen oder Job fehlschlagen?
            lww_log_to_job($job_id, sprintf('FEHLER: Header nicht lesbar oder Datei "%s" ist leer. Diese Aufgabe wird übersprungen.', basename($file_path)));
            return lww_skip_or_complete_job($job_id, $job_queue, $task_index, 'header_read_error');
        } 
    }
    
    // Sicherheitscheck: Ist die Header Map jetzt gültig?
    if (empty($header_map) || !is_array($header_map)) { 
        @fclose($handle); 
        throw new Exception(sprintf('Header-Map für "%s" nicht gefunden oder ungültig.', $file_key)); 
    }
    
    // --- Handler-Klasse finden (Factory-Pattern) ---
    static $handler_cache = []; // Cache für Handler-Instanzen
    $handler = null;
    // Baut den Klassennamen dynamisch auf (z.B. LWW_Import_Parts_Handler)
    $class_name = 'LWW_Import_' . str_replace('_', ' ', $file_key);
    $class_name = str_replace(' ', '_', ucwords($class_name));
    $class_name .= '_Handler';

    if (!isset($handler_cache[$class_name])) {
         if (class_exists($class_name)) {
            // Erstelle eine Instanz der Klasse und speichere sie im Cache
            $handler_cache[$class_name] = new $class_name();
         } else {
             $handler_cache[$class_name] = false; // Klasse nicht gefunden
             lww_log_to_job($job_id, sprintf('WARNUNG: Import-Handler-Klasse "%s" für Dateityp "%s" nicht gefunden.', $class_name, $file_key));
         }
    }
    $handler = $handler_cache[$class_name];
    
    // Wenn kein Handler gefunden wurde, überspringen wir diese Datei
    if (!$handler || !($handler instanceof LWW_Import_Handler_Interface)) {
        static $handler_warning_logged = [];
        $warn_key = $job_id . '_' . $file_key;
        if (!isset($handler_warning_logged[$warn_key])) {
             lww_log_to_job($job_id, sprintf('FEHLER: Kein gültiger Import-Handler für "%s". Diese Datei wird übersprungen.', $file_key));
             $handler_warning_logged[$warn_key] = true;
        }
         @fclose($handle);
         // Gehe zur nächsten Aufgabe oder beende den Job
         return lww_skip_or_complete_job($job_id, $job_queue, $task_index, 'handler_not_found');
    }

    // --- Batch verarbeiten ---
    $processed_in_this_batch = 0; 
    lww_log_system_event('Starte Batch-Verarbeitung mit Handler ' . $class_name . ' (Zeile ' . $current_row . ' bis ca. ' . ($current_row + $batch_size) . ')');
    
    while ($processed_in_this_batch < $batch_size && !feof($handle)) { 
        @set_time_limit(60); // Timeout für jede Zeile neu setzen
        $line_number_for_log = $current_row + 1; // Für Log-Nachrichten (1-basiert)
        $data = @fgetcsv($handle); 
        
        // Leere Zeilen oder Lesefehler am Ende der Datei überspringen
        if ($data === FALSE || $data === null || (count($data) === 1 && ($data[0] === null || trim($data[0]) === ''))) { 
            if (feof($handle)) { 
                lww_log_system_event('EOF in while-Schleife erreicht.');
                break; // Dateiende erreicht
            } 
            // Leere Zeile überspringen
            $current_row++; 
            continue; 
        } 
        
        // Grundlegende Validierung (z.B. Mindestanzahl Spalten?) - Optional
        
        try {
            // Handler für diese Zeile aufrufen
            $handler->process_row($job_id, $data, $header_map);
            
        } catch (Exception $e) { 
            // Fehler bei der Verarbeitung DIESER Zeile loggen, aber weiter machen
            lww_log_to_job($job_id, sprintf('FEHLER Z %d in %s: %s', $line_number_for_log, basename($file_path), $e->getMessage())); 
        }
        
        $current_row++; // Nächste Zeilennummer
        $processed_in_this_batch++; // Zähler für diesen Batch erhöhen
    } 
    
    // --- Status nach dem Batch aktualisieren ---
    lww_log_system_event('Batch beendet. ' . $processed_in_this_batch . ' Zeilen verarbeitet. Aktuelle Zeile für nächsten Lauf: ' . $current_row);
    // Aktuelle Zeilennummer im Job-Queue speichern
    $job_queue[$task_index]['rows_processed'] = $current_row;
    
    if (feof($handle)) { 
        // Dateiende wurde erreicht
        lww_log_system_event('Dateiende für ' . basename($file_path) . ' erreicht.'); 
        @fclose($handle); 
        // Temporäre CSV-Datei löschen
        @unlink($file_path); 
        // Status der Aufgabe auf 'complete' setzen
        $job_queue[$task_index]['status'] = 'complete'; 
        // Gesamtzahl der verarbeiteten Datenzeilen speichern (-1 wegen Header)
        $job_queue[$task_index]['total_rows'] = max(0, $current_row - 1); 
        lww_log_to_job($job_id, sprintf('Aufgabe "%s" abgeschlossen (%d Zeilen).', $file_key, $job_queue[$task_index]['total_rows'])); 
        
        // Gehe zur nächsten Aufgabe oder beende den Job
        lww_skip_or_complete_job($job_id, $job_queue, $task_index, 'task_complete');

    } else { 
        // Datei noch nicht fertig, Batch beendet
        @fclose($handle); 
        update_post_meta($job_id, '_job_queue', $job_queue); // Wichtig: Fortschritt speichern
        lww_log_to_job($job_id, sprintf('Aufgabe "%s": Batch beendet, %d Zeilen bisher verarbeitet.', $file_key, max(0, $current_row - 1))); 
    }
     lww_log_system_event('--- Ende lww_process_catalog_job_batch ---');
}

/**
 * Verarbeitet einen Batch eines INVENTAR-Jobs
 */
function lww_process_inventory_job_batch($job_id) {
    lww_log_system_event('--- Start lww_process_inventory_job_batch (Job ' . $job_id . ') ---');
    $job_queue = get_post_meta($job_id, '_job_queue', true); 
    $task_index = 0; // Inventar hat nur eine Aufgabe im Queue
    
    if (!is_array($job_queue) || !isset($job_queue[$task_index])) { 
        throw new Exception('Inventar-Job-Warteschlange ungültig.'); 
    } 
    
    $current_task = $job_queue[$task_index]; 
    $file_key = $current_task['key'] ?? 'inventory'; // Sollte immer 'inventory' sein
    $file_path = $current_task['path'] ?? ''; 
    $current_row = isset($current_task['rows_processed']) ? (int)$current_task['rows_processed'] : 0; 
    // Batch-Größe aus Optionen lesen, Mindestwert 50
    $batch_size = max(50, (int)get_option('lww_inventory_batch_size', 300)); 

    lww_log_system_event(sprintf('Verarbeite Aufgabe "%s", starte bei Zeile %d, Batch: %d', $file_key, $current_row, $batch_size));
    
    if (empty($file_path)) {
         throw new Exception('Kein Dateipfad für Inventar-Aufgabe gefunden.');
    }
    if (!file_exists($file_path)) { 
        lww_log_to_job($job_id, sprintf('FEHLER: Inventar-Datei nicht gefunden: %s. Job wird abgebrochen.', basename($file_path)));
        // Bei Inventar ist das fatal, Job fehlschlagen lassen
        wp_update_post(['ID' => $job_id, 'post_status' => 'lww_failed']);
        return; 
    } 
    
    $handle = @fopen($file_path, 'r'); 
    if (!$handle) { 
        throw new Exception(sprintf('Inventar-Datei nicht lesbar: %s', basename($file_path))); 
    } 
    
    // --- Springe zur richtigen Zeile ---
    if ($current_row > 0) { 
        lww_log_system_event('Springe zu Zeile ' . $current_row . '...'); 
        @set_time_limit(300); 
        for ($i = 0; $i < $current_row; $i++) { 
            if (feof($handle)) {
                 lww_log_system_event('WARNUNG: EOF beim Springen zu Zeile ' . $current_row); 
                 @fclose($handle);
                 // Datei als fertig markieren
                 return lww_skip_or_complete_job($job_id, $job_queue, $task_index, 'eof_during_skip');
            }
             if (@fgets($handle) === false && !feof($handle)) {
                @fclose($handle);
                throw new Exception('Fehler beim Lesen der Datei während des Springens.');
            }
        }
         lww_log_system_event('Sprung zu Zeile ' . $current_row . ' beendet.');
    } 
    
    // --- Header einlesen (nur beim ersten Mal) ---
    $header_map_key = '_header_map_inventory'; 
    $header_map = get_post_meta($job_id, $header_map_key, true); 
    
    if ($current_row === 0 || empty($header_map)) { 
        lww_log_system_event('Lese Inventar-Header...'); 
        $header = @fgetcsv($handle); 
        if ($header && is_array($header) && count($header) > 0) { 
            $header_normalized = array_map('strtolower', array_map('trim', $header));
            // Standard-BrickOwl-Header-Mapping (ggf. anpassen oder flexibler gestalten)
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
                // Füge hier bei Bedarf Tier Prices hinzu
                'tier_qty_1' => array_search('tier_qty_1', $header_normalized),
                'tier_price_1' => array_search('tier_price_1', $header_normalized),
                'tier_qty_2' => array_search('tier_qty_2', $header_normalized),
                'tier_price_2' => array_search('tier_price_2', $header_normalized),
                'tier_qty_3' => array_search('tier_qty_3', $header_normalized),
                'tier_price_3' => array_search('tier_price_3', $header_normalized),
            ];
            
            // Prüfe auf erforderliche Spalten
            $required_cols = ['boid','color_name','condition','quantity','price']; 
            $missing_cols = []; 
            foreach($required_cols as $req_col) {
                // Prüfen, ob der Key existiert UND der Index gefunden wurde (nicht false)
                if(!isset($header_map[$req_col]) || $header_map[$req_col] === false) {
                    $missing_cols[] = $req_col;
                }
            } 
            if(!empty($missing_cols)) { 
                @fclose($handle); 
                throw new Exception('Inventar-CSV Spalten fehlen: '.implode(', ',$missing_cols)); 
            }
            
            update_post_meta($job_id, $header_map_key, $header_map); 
            $current_row++; 
            $job_queue[$task_index]['rows_processed'] = $current_row; 
            update_post_meta($job_id, '_job_queue', $job_queue); // Fortschritt speichern
            lww_log_system_event('Inventar-Header gelesen und gespeichert.');
        } else { 
            @fclose($handle); 
            throw new Exception('Inventar-Header nicht lesbar oder Datei leer.'); 
        } 
    } 
    
    // Sicherheitscheck Header Map
    if (empty($header_map) || !is_array($header_map)) { 
        @fclose($handle); 
        throw new Exception('Inventar-Header-Map nicht gefunden oder ungültig.'); 
    }
    
    // --- Handler-Klasse finden ---
    static $handler_cache = [];
    $class_name = 'LWW_Import_Inventory_Handler'; // Fester Name für diesen Job-Typ
    
    if (!isset($handler_cache[$class_name])) {
         if (class_exists($class_name)) {
            $handler_cache[$class_name] = new $class_name();
         } else {
             $handler_cache[$class_name] = false; 
         }
    }
    $handler = $handler_cache[$class_name];
    
    if (!$handler || !($handler instanceof LWW_Import_Handler_Interface)) {
         throw new Exception(sprintf('KRITISCHER FEHLER: Inventar-Handler (%s) nicht gefunden oder implementiert Interface nicht.', $class_name));
    }

    // --- Batch verarbeiten ---
    $processed_in_this_batch = 0; 
    lww_log_system_event('Starte Inventar Batch mit Handler ' . $class_name . ' (Z '.$current_row.' bis ca. '.($current_row+$batch_size).')'); 
    
    while ($processed_in_this_batch < $batch_size && !feof($handle)) { 
        @set_time_limit(60);
        $line_number_for_log = $current_row + 1; 
        $data = @fgetcsv($handle); 
        
        if ($data === FALSE || $data === null || (count($data) === 1 && ($data[0] === null || trim($data[0]) === ''))) { 
            if (feof($handle)) {
                 lww_log_system_event('Inventar: EOF in while erreicht.');
                 break; 
            } 
            $current_row++; 
            continue; 
        } 
        
        try { 
            // Handler aufrufen
            $handler->process_row($job_id, $data, $header_map);
            
        } catch (Exception $e) { 
            lww_log_to_job($job_id, sprintf('FEHLER Inventar Z %d: %s', $line_number_for_log, $e->getMessage())); 
        } 
        $current_row++; 
        $processed_in_this_batch++;
    } 
    
    // --- Status nach dem Batch aktualisieren ---
    lww_log_system_event('Inventar Batch beendet. '.$processed_in_this_batch.' Zeilen verarbeitet. Aktuelle Zeile für nächsten Lauf: '.$current_row);
    
    $job_queue[$task_index]['rows_processed'] = $current_row; 
    
    if (feof($handle)) { 
        // Datei komplett verarbeitet
        lww_log_system_event('Inventar: EOF erreicht.'); 
        @fclose($handle); 
        @unlink($file_path); // Temporäre Datei löschen
        $job_queue[$task_index]['status'] = 'complete'; 
        $job_queue[$task_index]['total_rows'] = max(0, $current_row - 1); // -1 wegen Header
        wp_update_post(['ID' => $job_id, 'post_status' => 'lww_complete']); 
        lww_log_to_job($job_id, sprintf('Inventar-Import-Job abgeschlossen (%d Zeilen).', $job_queue[$task_index]['total_rows'])); 
    } else { 
        // Datei noch nicht fertig, Batch beendet
        @fclose($handle); 
        lww_log_to_job($job_id, sprintf('Inventar-Import: Batch beendet, %d Zeilen bisher verarbeitet.', max(0, $current_row - 1))); 
    } 
    
    // Fortschritt IMMER speichern
    update_post_meta($job_id, '_job_queue', $job_queue); 
    lww_log_system_event('--- Ende lww_process_inventory_job_batch ---');
}


// --- HILFSFUNKTIONEN FÜR JOBS & CRON ---

/**
 * Plant den Cron Job, falls er noch nicht geplant ist.
 * Verwendet das in den Optionen eingestellte Intervall.
 */
function lww_start_cron_job() {
    $hook = 'lww_main_batch_hook';
    // Lese Intervall aus Optionen, Standard 'jede Minute'
    $interval = get_option('lww_cron_interval', 'lww_every_minute'); 
    
    // Prüfe, ob das Intervall gültig ist (registriert in lww_add_cron_interval)
    $schedules = wp_get_schedules();
    if (!isset($schedules[$interval])) {
        lww_log_system_event('FEHLER: Ungültiges Cron-Intervall "' . $interval . '" in den Optionen. Verwende Standard "lww_every_minute".');
        $interval = 'lww_every_minute';
    }
    
    if (!wp_next_scheduled($hook)) {
        // Plane den Job mit einer leichten Verzögerung, um nicht sofort nach Aktivierung zu laufen
        $scheduled = wp_schedule_event(time() + 10, $interval, $hook); 
        if ($scheduled === false) {
            lww_log_system_event('FEHLER: Konnte Cron "' . $hook . '" nicht planen!');
        } else {
            lww_log_system_event('Cron "' . $hook . '" geplant (Intervall: ' . $interval . ').');
        }
    } else {
        lww_log_system_event('Cron "' . $hook . '" ist bereits geplant.');
    }
}

/**
 * Entfernt den Cron Job.
 */
function lww_stop_cron_job() {
    $hook = 'lww_main_batch_hook';
    $timestamp = wp_next_scheduled($hook);
    if ($timestamp) {
        $unscheduled = wp_unschedule_event($timestamp, $hook);
        if ($unscheduled === false) {
            lww_log_system_event('FEHLER: Konnte Cron "' . $hook . '" nicht stoppen!');
        } else {
            lww_log_system_event('Cron "' . $hook . '" gestoppt.');
        }
    }
    // Sicherstellen, dass alle Hooks entfernt werden
    wp_clear_scheduled_hook($hook); 
}

/**
 * Markiert einen Job als fehlgeschlagen und loggt die Nachricht.
 */
function lww_fail_job($job_id, $message) {
    // Nur aktualisieren, wenn der Job nicht schon fehlgeschlagen ist
    if (get_post_status($job_id) !== 'lww_failed') {
        wp_update_post(['ID' => $job_id, 'post_status' => 'lww_failed']);
        lww_log_to_job($job_id, 'FEHLER: ' . $message);
        // Lösche die Sperre nur, wenn DIESER Job die Sperre hatte
        if (get_option('lww_current_running_job_id') == $job_id) {
            delete_option('lww_current_running_job_id');
        }
        lww_log_system_event('Job ' . $job_id . ' fehlgeschlagen.');
    }
}

/**
 * Fügt eine Nachricht zum Job-Log hinzu (Meta-Feld).
 */
function lww_log_to_job($job_id, $message) {
    if (empty($job_id)) return;
    $log = get_post_meta($job_id, '_job_log', true);
    if (!is_array($log)) $log = [];
    
    // Zeitstempel und Nachricht formatieren
    $log_entry = sprintf('[%s] %s', wp_date('H:i:s'), $message);
    $log[] = $log_entry;
    
    // Log auf eine maximale Anzahl von Einträgen begrenzen
    $max_log_entries = apply_filters('lww_max_job_log_entries', 200); // Erhöht auf 200
    if (count($log) > $max_log_entries) {
        $log = array_slice($log, -$max_log_entries);
    }
    update_post_meta($job_id, '_job_log', $log);
}

/**
 * Schreibt eine Nachricht in das PHP Error Log, wenn WP_DEBUG_LOG aktiv ist.
 */
function lww_log_system_event($message) {
    if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG === true) {
        error_log('[LWW System] ' . $message);
    }
}

/**
 * Hilfsfunktion, um zur nächsten Aufgabe zu springen oder den Job abzuschließen.
 * Wird verwendet, wenn eine Datei/Aufgabe fertig ist oder übersprungen wird.
 */
function lww_skip_or_complete_job($job_id, $job_queue, $current_task_index, $reason = 'unknown') {
    
    // Markiere die aktuelle Aufgabe basierend auf dem Grund
    switch ($reason) {
        case 'task_complete':
            $job_queue[$current_task_index]['status'] = 'complete';
             // total_rows wurde bereits gesetzt
            break;
        case 'file_not_found':
        case 'header_read_error':
        case 'handler_not_found':
        case 'eof_during_skip':
            $job_queue[$current_task_index]['status'] = 'skipped';
             $job_queue[$current_task_index]['total_rows'] = $job_queue[$current_task_index]['rows_processed']; // Bisher verarbeitete Zeilen als Total
             lww_log_to_job($job_id, sprintf('Aufgabe "%s" übersprungen (Grund: %s).', $job_queue[$current_task_index]['key'], $reason));
            break;
        default:
             $job_queue[$current_task_index]['status'] = 'unknown_error';
             lww_log_to_job($job_id, sprintf('Aufgabe "%s" mit unbekanntem Fehler beendet.', $job_queue[$current_task_index]['key']));
            break;
    }

    $next_task_index = $current_task_index + 1;

    if (isset($job_queue[$next_task_index])) {
        // Es gibt noch Aufgaben -> gehe zur nächsten
        update_post_meta($job_id, '_current_task_index', $next_task_index);
        update_post_meta($job_id, '_job_queue', $job_queue); // Aktualisierten Queue speichern
        lww_log_to_job($job_id, sprintf('Starte nächste Aufgabe "%s"...', $job_queue[$next_task_index]['key']));
        lww_log_system_event(sprintf('Nächste Aufgabe (Index %d) für Job %d vorbereitet: "%s"', $next_task_index, $job_id, $job_queue[$next_task_index]['key']));
    } else {
        // Letzte Aufgabe war dran -> Job abschließen
        update_post_meta($job_id, '_job_queue', $job_queue); // Finalen Queue-Status speichern
        wp_update_post(['ID' => $job_id, 'post_status' => 'lww_complete']);
        lww_log_to_job($job_id, 'Katalog-Import-Job abgeschlossen.');
        lww_log_system_event('Job ' . $job_id . ' abgeschlossen.');
        // Hier könnten alle temporären Dateien des Jobs gelöscht werden
        // lww_cleanup_job_files($job_id); 
    }
}

?>
