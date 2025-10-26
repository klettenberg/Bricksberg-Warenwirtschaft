<?php
/**
 * Modul: Import-Handler (v9.0)
 *
 * Behandelt Datei-Uploads und erstellt Jobs in der Warteschlange
 * für Katalog- und Inventar-Importe.
 */
if (!defined('ABSPATH')) exit;

/**
 * Handler für Katalog-CSV-Upload (Phase 2a).
 * Erstellt einen neuen Job vom Typ 'catalog_import'.
 */
function lww_catalog_import_handler() {
    // 1. Sicherheit prüfen (Nonce und Berechtigung)
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'lww_catalog_import_nonce')) {
        wp_die(__('Sicherheitsüberprüfung fehlgeschlagen (Nonce ungültig).', 'lego-wawi'));
    }
    if (!current_user_can('manage_options')) {
        wp_die(__('Sie haben keine Berechtigung, diesen Vorgang auszuführen.', 'lego-wawi'));
    }
    if (!isset($_FILES['lww_csv_files']) || empty($_FILES['lww_csv_files']['name'])) {
         add_settings_error('lww_messages', 'no_file_array', __('Keine Dateien im Upload-Array gefunden.', 'lego-wawi'), 'error');
         wp_redirect(admin_url('admin.php?page=' . LWW_PLUGIN_SLUG . '&tab=tab_catalog_import'));
         exit;
    }

    $upload_dir = wp_upload_dir(); // Holt das WordPress Upload-Verzeichnis
    $import_files = []; // Array zum Speichern der erfolgreich verarbeiteten Dateipfade
    $files_data = $_FILES['lww_csv_files']; // Das $_FILES Array für unsere Uploads
    $at_least_one_file_uploaded = false;
    $errors_found = false;

    // 2. Schleife durch alle potenziell hochgeladenen Dateien
    foreach ($files_data['name'] as $key => $name) {
        // Nur verarbeiten, wenn kein Upload-Fehler aufgetreten ist UND ein Dateiname vorhanden ist
        if (isset($files_data['error'][$key]) && $files_data['error'][$key] === UPLOAD_ERR_OK && !empty($name)) {
            $tmp_name = $files_data['tmp_name'][$key]; // Temporärer Pfad der hochgeladenen Datei
            $file_ext = strtolower(pathinfo($name, PATHINFO_EXTENSION)); // Dateiendung (csv, zip, gz)

            // Zieldatei im Upload-Ordner ist IMMER .csv (nach Entpackung)
            // Erzeugt einen eindeutigeren Namen, um Konflikte bei wiederholten Uploads zu minimieren
            $timestamp = time();
            $target_csv_path = $upload_dir['basedir'] . '/lww_import_' . sanitize_file_name($key) . '_' . $timestamp . '.csv';

            $result = false; // Flag für erfolgreiche Verarbeitung

            // Fall 1: ZIP-Datei entpacken
            if ($file_ext === 'zip') {
                $result = lww_unzip_file($tmp_name, $target_csv_path, $key);
                if (!$result) {
                     add_settings_error('lww_messages', 'unzip_error_' . $key, sprintf(__('ZIP-Datei %s konnte nicht entpackt werden (enthält sie %s.csv?).', 'lego-wawi'), esc_html($name), esc_html($key)), 'error');
                     $errors_found = true;
                }
            }
            // Fall 2: GZ-Datei entpacken
            elseif ($file_ext === 'gz') {
                $result = lww_un_gz_file($tmp_name, $target_csv_path);
                 if (!$result) {
                     add_settings_error('lww_messages', 'ungz_error_' . $key, sprintf(__('GZ-Datei %s konnte nicht entpackt werden.', 'lego-wawi'), esc_html($name)), 'error');
                     $errors_found = true;
                }
            }
            // Fall 3: Reine CSV-Datei verschieben
            elseif ($file_ext === 'csv') {
                if (is_uploaded_file($tmp_name)) { // Zusätzliche Sicherheitsprüfung
                    $result = move_uploaded_file($tmp_name, $target_csv_path);
                    if (!$result) {
                        add_settings_error('lww_messages', 'move_error_' . $key, sprintf(__('CSV-Datei %s konnte nicht verschoben werden.', 'lego-wawi'), esc_html($name)), 'error');
                        $errors_found = true;
                    }
                } else {
                     add_settings_error('lww_messages', 'upload_invalid_' . $key, sprintf(__('Ungültiger Upload für Datei %s.', 'lego-wawi'), esc_html($name)), 'error');
                     $errors_found = true;
                }
            }
            // Fall 4: Unbekannter/unerlaubter Dateityp
            else {
                 add_settings_error('lww_messages', 'type_error_' . $key, sprintf(__('Dateityp von %s nicht unterstützt (nur CSV, ZIP, GZ).', 'lego-wawi'), esc_html($name)), 'warning');
                 // Kein $errors_found = true;, da es nur eine Warnung ist
            }

            // Wenn die Verarbeitung erfolgreich war, Pfad speichern
            if ($result) {
                $import_files[$key] = $target_csv_path;
                $at_least_one_file_uploaded = true;
            }
        }
        // Fehler beim Upload selbst behandeln (z.B. Datei zu groß)
        elseif (isset($files_data['error'][$key]) && $files_data['error'][$key] !== UPLOAD_ERR_NO_FILE) {
             add_settings_error('lww_messages', 'upload_error_' . $key, sprintf(__('Fehler beim Upload von %s: %s', 'lego-wawi'), esc_html($name), lww_get_upload_error_message($files_data['error'][$key])), 'error');
             $errors_found = true;
        }
    } // Ende foreach

    // Wenn Fehler aufgetreten sind ODER keine gültige Datei hochgeladen wurde
    if ($errors_found || !$at_least_one_file_uploaded) {
        add_settings_error('lww_messages', 'upload_failed', __('Einige Dateien konnten nicht verarbeitet werden oder es wurde keine gültige Datei hochgeladen. Der Job wurde nicht erstellt.', 'lego-wawi'), $errors_found ? 'error' : 'warning');
        // Temporäre Dateien löschen, falls welche erstellt wurden
        foreach ($import_files as $path) { @unlink($path); }
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_safe_redirect(admin_url('admin.php?page=' . LWW_PLUGIN_SLUG . '&tab=tab_catalog_import'));
        exit;
    }

    // 4. Import-Reihenfolge der Dateien definieren
    $processing_order = [
        'colors', 'themes', 'part_categories', 'parts', 'sets', 'minifigs',
        'part_relationships', 'elements', 'inventories',
        'inventory_parts', 'inventory_sets', 'inventory_minifigs'
    ];

    // Erstellt die "Aufgabenliste" (job_queue) für diesen spezifischen Job,
    // basierend auf den erfolgreich hochgeladenen Dateien und der korrekten Reihenfolge.
    $job_queue = [];
    foreach($processing_order as $key) {
        if(isset($import_files[$key])) {
            $job_queue[] = [
                'key'            => $key,                 // Eindeutiger Schlüssel der Datei (z.B. 'colors')
                'path'           => $import_files[$key],  // Absoluter Pfad zur entpackten CSV-Datei
                'status'         => 'pending',           // Status dieser Aufgabe ('pending', 'running', 'complete', 'failed')
                'rows_processed' => 0,                   // Zähler für verarbeitete Zeilen
                'total_rows'     => 0,                   // Gesamtzahl der Zeilen (wird später geschätzt/ermittelt)
            ];
        }
    }

    // Wenn nach Filterung keine relevanten Dateien übrig bleiben (unwahrscheinlich nach obiger Prüfung, aber sicher ist sicher)
    if (empty($job_queue)) {
         add_settings_error('lww_messages', 'no_valid_files_queued', __('Keine der hochgeladenen Dateien war für den Katalog-Import relevant.', 'lego-wawi'), 'warning');
         // Temporäre Dateien löschen
         foreach ($import_files as $path) { @unlink($path); }
         set_transient('settings_errors', get_settings_errors(), 30);
         wp_safe_redirect(admin_url('admin.php?page=' . LWW_PLUGIN_SLUG . '&tab=tab_catalog_import'));
         exit;
    }

    // 5. Neuen Job-Post (CPT 'lww_job') in der Datenbank erstellen
    $job_id = wp_insert_post([
        'post_title'   => sprintf(__('Katalog-Import (%d Dateien)', 'lego-wawi'), count($job_queue)) . ' - ' . date_i18n('d.m.Y H:i'), // Aussagekräftiger Titel
        'post_type'    => 'lww_job',        // Post-Typ ist 'lww_job'
        'post_status'  => 'lww_pending',    // Startet im Status "Wartend"
        'post_author'  => get_current_user_id(), // Ordnet den Job dem aktuellen Benutzer zu
    ], true); // true gibt WP_Error bei Fehler zurück

    if (is_wp_error($job_id)) {
        // Fehler beim Erstellen des Job-Posts
         add_settings_error('lww_messages', 'job_creation_failed', __('Fehler beim Erstellen des Import-Jobs: ', 'lego-wawi') . $job_id->get_error_message(), 'error');
         // Temporäre Dateien löschen
         foreach ($import_files as $path) { @unlink($path); }
         set_transient('settings_errors', get_settings_errors(), 30);
         wp_safe_redirect(admin_url('admin.php?page=' . LWW_PLUGIN_SLUG . '&tab=tab_catalog_import'));
         exit;
    }

    // 6. Job-Details als Metadaten speichern
    update_post_meta($job_id, '_job_type', 'catalog_import');       // Typ des Jobs
    update_post_meta($job_id, '_job_queue', $job_queue);             // Die Liste der zu verarbeitenden Dateien (Aufgaben)
    update_post_meta($job_id, '_current_task_index', 0);           // Index der aktuellen Aufgabe (beginnt bei 0)
    update_post_meta($job_id, '_job_log', [sprintf('[%s] Job erstellt. %d Datei(en) in der Warteschlange.', date('H:i:s'), count($job_queue))]); // Erste Log-Nachricht

    // 7. Cron-Job starten/aufwecken, falls er nicht schon läuft
    lww_start_cron_job();

    // 8. Erfolgsmeldung speichern und zur Job-Liste weiterleiten
    add_settings_error('lww_messages', 'job_created', __('Neuer Katalog-Import-Job wurde erfolgreich erstellt und zur Warteschlange hinzugefügt.', 'lego-wawi'), 'success');
    set_transient('settings_errors', get_settings_errors(), 30); // Speichert die Nachricht für die nächste Seite

    wp_safe_redirect(admin_url('admin.php?page=' . LWW_PLUGIN_SLUG . '&tab=tab_jobs')); // Leitet zur Job-Liste weiter
    exit;
}
add_action('admin_post_lww_upload_catalog_csv', 'lww_catalog_import_handler'); // Hook für das Katalog-Formular


/**
 * Handler für Inventar-CSV-Upload (Phase 2b).
 * Erstellt einen neuen Job vom Typ 'inventory_import'.
 */
function lww_inventory_import_handler() {
    // 1. Sicherheit prüfen
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'lww_inventory_import_nonce')) {
        wp_die(__('Sicherheitsüberprüfung fehlgeschlagen.', 'lego-wawi'));
    }
     if (!current_user_can('manage_options')) {
         wp_die(__('Du hast keine Berechtigung.', 'lego-wawi'));
    }
     if (!isset($_FILES['inventory_csv_file']) || $_FILES['inventory_csv_file']['error'] !== UPLOAD_ERR_OK) {
        add_settings_error('lww_messages', 'inv_upload_error', __('Fehler beim Upload der Inventar-Datei: ', 'lego-wawi') . lww_get_upload_error_message($_FILES['inventory_csv_file']['error'] ?? UPLOAD_ERR_NO_FILE), 'error');
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_safe_redirect(admin_url('admin.php?page=' . LWW_PLUGIN_SLUG . '&tab=tab_inventory_import'));
        exit;
    }

     // Prüfen, ob Stammdaten vorhanden sind (erneut, zur Sicherheit)
    $catalog_counts = get_option('lww_catalog_counts', []);
    $required_data_keys = ['colors', 'parts'];
    $core_data_imported = true;
    foreach($required_data_keys as $key) {
        if (empty($catalog_counts[$key]) || $catalog_counts[$key] === 0) {
            $core_data_imported = false;
            break;
        }
    }
    if (!$core_data_imported) {
         add_settings_error('lww_messages', 'inv_missing_core_data', __('Inventar-Import nicht möglich, da wichtige Katalogdaten (Farben, Teile) fehlen. Bitte führe zuerst den Katalog-Import durch.', 'lego-wawi'), 'error');
         set_transient('settings_errors', get_settings_errors(), 30);
         wp_safe_redirect(admin_url('admin.php?page=' . LWW_PLUGIN_SLUG . '&tab=tab_inventory_import'));
         exit;
    }

    $upload_dir = wp_upload_dir();
    $tmp_name = $_FILES['inventory_csv_file']['tmp_name'];
    $name = $_FILES['inventory_csv_file']['name'];
    $file_ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    if ($file_ext !== 'csv') {
        add_settings_error('lww_messages', 'inv_wrong_type', __('Falscher Dateityp. Bitte lade eine .csv-Datei hoch.', 'lego-wawi'), 'error');
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_safe_redirect(admin_url('admin.php?page=' . LWW_PLUGIN_SLUG . '&tab=tab_inventory_import'));
        exit;
    }

    // Zieldatei im Upload-Ordner
    $timestamp = time();
    $target_csv_path = $upload_dir['basedir'] . '/lww_import_inventory_' . $timestamp . '.csv';

    if (is_uploaded_file($tmp_name) && move_uploaded_file($tmp_name, $target_csv_path)) {
        // Datei erfolgreich verschoben

        // Aufgabenliste für diesen Job (nur eine Aufgabe)
         $job_queue = [[
            'key'            => 'inventory',
            'path'           => $target_csv_path,
            'status'         => 'pending',
            'rows_processed' => 0,
            'total_rows'     => 0,
        ]];

        // Neuen Job-Post erstellen
        $job_id = wp_insert_post([
            'post_title'   => __('Inventar-Import', 'lego-wawi') . ' - ' . date_i18n('d.m.Y H:i'),
            'post_type'    => 'lww_job',
            'post_status'  => 'lww_pending',
            'post_author'  => get_current_user_id(),
        ], true);

        if (is_wp_error($job_id)) {
             add_settings_error('lww_messages', 'inv_job_creation_failed', __('Fehler beim Erstellen des Inventar-Import-Jobs: ', 'lego-wawi') . $job_id->get_error_message(), 'error');
             @unlink($target_csv_path); // Temporäre Datei löschen
        } else {
            // Job-Details speichern
            update_post_meta($job_id, '_job_type', 'inventory_import');
            update_post_meta($job_id, '_job_queue', $job_queue);
            update_post_meta($job_id, '_current_task_index', 0);
            update_post_meta($job_id, '_job_log', [sprintf('[%s] Inventar-Import-Job erstellt.', date('H:i:s'))]);

            // Cron starten/aufwecken
            lww_start_cron_job();

            add_settings_error('lww_messages', 'inv_job_created', __('Neuer Inventar-Import-Job wurde erfolgreich erstellt.', 'lego-wawi'), 'success');
        }

    } else {
        // Fehler beim Verschieben der Datei
         add_settings_error('lww_messages', 'inv_move_error', __('Die hochgeladene Inventar-Datei konnte nicht verarbeitet werden.', 'lego-wawi'), 'error');
    }

    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect(admin_url('admin.php?page=' . LWW_PLUGIN_SLUG . '&tab=tab_jobs')); // Zur Job-Liste leiten
    exit;
}
add_action('admin_post_lww_upload_inventory_csv', 'lww_inventory_import_handler'); // Hook für das Inventar-Formular


// --- HILFSFUNKTIONEN FÜR DATEI-ENTPACKUNG UND FEHLER ---

/**
 * Entpackt eine .zip-Datei und extrahiert die relevante CSV.
 * Sucht nach einer Datei namens "{key}.csv" im Archiv.
 * @param string $zip_path Pfad zur hochgeladenen ZIP-Datei.
 * @param string $target_csv_path Zielpfad für die extrahierte CSV.
 * @param string $file_key Der erwartete Name der CSV ohne Endung (z.B. 'parts').
 * @return bool True bei Erfolg, False bei Fehler.
 */
function lww_unzip_file($zip_path, $target_csv_path, $file_key) {
    if (!class_exists('ZipArchive')) return false; // Prüfen, ob Zip-Erweiterung vorhanden ist

    $zip = new ZipArchive;
    if ($zip->open($zip_path) === TRUE) {
        // Finde die relevante CSV-Datei im ZIP (Groß-/Kleinschreibung ignorieren?)
        $csv_filename_in_zip = $file_key . '.csv';
        $file_index = $zip->locateName($csv_filename_in_zip, ZipArchive::FL_NOCASE); // FL_NOCASE für mehr Robustheit

        if ($file_index !== false) {
            // Extrahiere nur diese eine Datei direkt in den Zielpfad
            if ($zip->extractTo(dirname($target_csv_path), $zip->getNameIndex($file_index))) {
                 // Umbenennen, falls der extrahierte Name Großbuchstaben enthält etc.
                 $extracted_path = dirname($target_csv_path) . '/' . $zip->getNameIndex($file_index);
                 // Nur umbenennen, wenn Pfad unterschiedlich ist (verhindert Fehler bei identischen Namen)
                 if ($extracted_path !== $target_csv_path) {
                    if (!rename($extracted_path, $target_csv_path)) {
                        $zip->close();
                        @unlink($zip_path);
                        @unlink($extracted_path); // Aufräumen
                        return false; // Fehler beim Umbenennen
                    }
                 }
                 $zip->close();
                 @unlink($zip_path); // Temporäres ZIP löschen
                 return true;
            }
        }
        $zip->close(); // Wichtig: Immer schließen
    }
    @unlink($zip_path); // Aufräumen bei Fehler
    return false; // Fehler beim Öffnen oder Datei nicht gefunden
}

/**
 * Entpackt eine .gz-Datei nach $target_csv_path.
 * @param string $gz_path Pfad zur hochgeladenen GZ-Datei.
 * @param string $target_csv_path Zielpfad für die entpackte CSV.
 * @return bool True bei Erfolg, False bei Fehler.
 */
function lww_un_gz_file($gz_path, $target_csv_path) {
    // Puffergröße für das Lesen/Schreiben
    $buffer_size = 4096; // 4KB

    // Quelldatei (gz) öffnen
    $gz_handle = @gzopen($gz_path, 'rb');
    if (!$gz_handle) return false;

    // Zieldatei (csv) öffnen
    $csv_handle = @fopen($target_csv_path, 'wb');
    if (!$csv_handle) {
        gzclose($gz_handle);
        return false;
    }

    // Inhalt Stück für Stück übertragen
    while (!gzeof($gz_handle)) {
        $buffer = gzread($gz_handle, $buffer_size);
        if ($buffer === false) { // Fehler beim Lesen
            fclose($csv_handle);
            gzclose($gz_handle);
            @unlink($target_csv_path); // Unvollständige Zieldatei löschen
            @unlink($gz_path);
             return false;
        }
        if (fwrite($csv_handle, $buffer) === false) { // Fehler beim Schreiben
             fclose($csv_handle);
             gzclose($gz_handle);
             @unlink($target_csv_path);
             @unlink($gz_path);
             return false;
        }
    }

    // Dateien schließen
    gzclose($gz_handle);
    fclose($csv_handle);

    // Temporäre GZ-Datei löschen
    @unlink($gz_path);
    return true; // Erfolg
}

/**
 * Gibt eine lesbare Fehlermeldung für PHP Upload-Fehlercodes zurück.
 * @param int $error_code Der PHP UPLOAD_ERR_* Code.
 * @return string Die Fehlermeldung.
 */
function lww_get_upload_error_message($error_code) {
    switch ($error_code) {
        case UPLOAD_ERR_INI_SIZE:
            return __('Die Datei überschreitet die `upload_max_filesize`-Direktive in php.ini.', 'lego-wawi');
        case UPLOAD_ERR_FORM_SIZE:
            return __('Die Datei überschreitet die MAX_FILE_SIZE-Direktive im HTML-Formular.', 'lego-wawi');
        case UPLOAD_ERR_PARTIAL:
            return __('Die Datei wurde nur teilweise hochgeladen.', 'lego-wawi');
        case UPLOAD_ERR_NO_FILE:
            return __('Es wurde keine Datei hochgeladen.', 'lego-wawi');
        case UPLOAD_ERR_NO_TMP_DIR:
            return __('Es fehlt ein temporäres Verzeichnis auf dem Server.', 'lego-wawi');
        case UPLOAD_ERR_CANT_WRITE:
            return __('Datei konnte nicht auf die Festplatte geschrieben werden.', 'lego-wawi');
        case UPLOAD_ERR_EXTENSION:
            return __('Eine PHP-Erweiterung hat den Datei-Upload gestoppt.', 'lego-wawi');
        default:
            return __('Unbekannter Upload-Fehler.', 'lego-wawi');
    }
}
?>