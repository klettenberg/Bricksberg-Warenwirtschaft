<?php
/**
 * Modul: Upload & Job Erstellung (v12.0)
 *
 * Behandelt Datei-Uploads und erstellt Jobs in der Warteschlange
 * für Katalog- und Inventar-Importe.
 */
if (!defined('ABSPATH')) exit;

/**
 * Handler für Katalog-CSV-Upload.
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
         wp_redirect(admin_url('admin.php?page=lww_import_ui'));
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

    if ($errors_found || !$at_least_one_file_uploaded) {
        add_settings_error('lww_messages', 'upload_failed', __('Einige Dateien konnten nicht verarbeitet werden oder es wurde keine gültige Datei hochgeladen. Der Job wurde nicht erstellt.', 'lego-wawi'), $errors_found ? 'error' : 'warning');
        // Temporäre Dateien löschen, falls welche erstellt wurden
        foreach ($import_files as $path) { @unlink($path); }
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_safe_redirect(admin_url('admin.php?page=lww_import_ui'));
        exit;
    }

    // 4. Import-Reihenfolge der Dateien definieren
    $processing_order = [
        'colors', 'themes', 'part_categories', 'parts', 'sets', 'minifigs',
        'part_relationships', 'elements', 'inventories',
        'inventory_parts', 'inventory_sets', 'inventory_minifigs'
    ];

    $job_queue = [];
    $job_title_parts = [];
    foreach($processing_order as $key) {
        if(isset($import_files[$key])) {
            $job_queue[] = [
                'key'            => $key,
                'path'           => $import_files[$key],
                'status'         => 'pending',
                'rows_processed' => 0,
                'total_rows'     => 0, // Wird im Batch-Prozessor ermittelt
            ];
            $job_title_parts[] = $key;
        }
    }

    if (empty($job_queue)) {
         add_settings_error('lww_messages', 'no_valid_files_queued', __('Keine der hochgeladenen Dateien war für den Katalog-Import relevant.', 'lego-wawi'), 'warning');
         foreach ($import_files as $path) { @unlink($path); }
         set_transient('settings_errors', get_settings_errors(), 30);
         wp_safe_redirect(admin_url('admin.php?page=lww_import_ui'));
         exit;
    }

    $priority = (int) get_option('lww_job_priority_catalog_import', 10);

    // 5. Neuen Job-Post erstellen
    $job_title = sprintf(
        __('Katalog-Import: %s', 'lego-wawi'),
        implode(', ', $job_title_parts)
    );

    $job_id = wp_insert_post([
        'post_title'   => $job_title . ' - ' . date_i18n('d.m.Y H:i'),
        'post_type'    => 'lww_job',
        'post_status'  => 'lww_pending',
        'post_author'  => get_current_user_id(),
        'menu_order'   => $priority,
    ], true);

    if (is_wp_error($job_id)) {
         add_settings_error('lww_messages', 'job_creation_failed', __('Fehler beim Erstellen des Import-Jobs: ', 'lego-wawi') . $job_id->get_error_message(), 'error');
         foreach ($import_files as $path) { @unlink($path); }
         set_transient('settings_errors', get_settings_errors(), 30);
         wp_safe_redirect(admin_url('admin.php?page=lww_import_ui'));
         exit;
    }

    // 6. Job-Details als Metadaten speichern
    update_post_meta($job_id, '_job_type', 'catalog_import');
    update_post_meta($job_id, '_job_queue', $job_queue);
    update_post_meta($job_id, '_current_task_index', 0);
    lww_log_to_job($job_id, sprintf('Job erstellt. %d Datei(en) in der Warteschlange.', count($job_queue)));

    lww_start_cron_job();

    add_settings_error('lww_messages', 'job_created', __('Neuer Katalog-Import-Job wurde erfolgreich erstellt und zur Warteschlange hinzugefügt.', 'lego-wawi'), 'success');
    set_transient('settings_errors', get_settings_errors(), 30);

    wp_safe_redirect(admin_url('admin.php?page=lww_jobs_ui'));
    exit;
}
add_action('admin_post_lww_upload_catalog_csv', 'lww_catalog_import_handler');


/**
 * Handler für Inventar-CSV-Upload.
 * Erstellt einen neuen Job vom Typ 'inventory_import'.
 */
function lww_inventory_import_handler() {
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'lww_inventory_import_nonce')) {
        wp_die(__('Sicherheitsüberprüfung fehlgeschlagen.', 'lego-wawi'));
    }
     if (!current_user_can('manage_options')) {
         wp_die(__('Du hast keine Berechtigung.', 'lego-wawi'));
    }
     if (!isset($_FILES['inventory_csv_file']) || $_FILES['inventory_csv_file']['error'] !== UPLOAD_ERR_OK) {
        add_settings_error('lww_messages', 'inv_upload_error', __('Fehler beim Upload der Inventar-Datei: ', 'lego-wawi') . lww_get_upload_error_message($_FILES['inventory_csv_file']['error'] ?? UPLOAD_ERR_NO_FILE), 'error');
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_safe_redirect(admin_url('admin.php?page=lww_import_ui'));
        exit;
    }

    $upload_dir = wp_upload_dir();
    $tmp_name = $_FILES['inventory_csv_file']['tmp_name'];
    $name = $_FILES['inventory_csv_file']['name'];
    $file_ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    if ($file_ext !== 'csv') {
        add_settings_error('lww_messages', 'inv_wrong_type', __('Falscher Dateityp. Bitte lade eine .csv-Datei hoch.', 'lego-wawi'), 'error');
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_safe_redirect(admin_url('admin.php?page=lww_import_ui'));
        exit;
    }

    $timestamp = time();
    $target_csv_path = $upload_dir['basedir'] . '/lww_import_inventory_' . $timestamp . '.csv';
    $priority = (int) get_option('lww_job_priority_inventory_import', 10);

    if (is_uploaded_file($tmp_name) && move_uploaded_file($tmp_name, $target_csv_path)) {
         $job_queue = [[ 
            'key'            => 'inventory',
            'path'           => $target_csv_path,
            'status'         => 'pending',
            'rows_processed' => 0,
            'total_rows'     => 0,
        ]];

        $job_id = wp_insert_post([
            'post_title'   => sprintf(__('Inventar-Import (%s)', 'lego-wawi'), esc_html($name)) . ' - ' . date_i18n('d.m.Y H:i'),
            'post_type'    => 'lww_job',
            'post_status'  => 'lww_pending',
            'post_author'  => get_current_user_id(),
            'menu_order'   => $priority,
        ], true);

        if (is_wp_error($job_id)) {
             add_settings_error('lww_messages', 'inv_job_creation_failed', __('Fehler beim Erstellen des Inventar-Import-Jobs: ', 'lego-wawi') . $job_id->get_error_message(), 'error');
             @unlink($target_csv_path);
        } else {
            update_post_meta($job_id, '_job_type', 'inventory_import');
            update_post_meta($job_id, '_job_queue', $job_queue);
            update_post_meta($job_id, '_current_task_index', 0);
            lww_log_to_job($job_id, 'Inventar-Import-Job erstellt.');

            lww_start_cron_job();

            add_settings_error('lww_messages', 'inv_job_created', __('Neuer Inventar-Import-Job wurde erfolgreich erstellt.', 'lego-wawi'), 'success');
        }

    } else {
         add_settings_error('lww_messages', 'inv_move_error', __('Die hochgeladene Inventar-Datei konnte nicht verarbeitet werden.', 'lego-wawi'), 'error');
    }

    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect(admin_url('admin.php?page=lww_jobs_ui'));
    exit;
}
add_action('admin_post_lww_upload_inventory_csv', 'lww_inventory_import_handler');

/**
 * Handler für Inventar-Backup-CSV-Upload.
 * Erstellt einen neuen Job vom Typ 'inventory_backup_import'.
 */
function lww_inventory_backup_import_handler() {
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'lww_inventory_backup_import_nonce')) {
        wp_die(__('Sicherheitsüberprüfung fehlgeschlagen.', 'lego-wawi'));
    }
    if (!current_user_can('manage_options')) {
        wp_die(__('Du hast keine Berechtigung.', 'lego-wawi'));
    }
    if (!isset($_FILES['inventory_backup_csv_file']) || $_FILES['inventory_backup_csv_file']['error'] !== UPLOAD_ERR_OK) {
        add_settings_error('lww_messages', 'inv_backup_upload_error', __('Fehler beim Upload der Backup-Datei: ', 'lego-wawi') . lww_get_upload_error_message($_FILES['inventory_backup_csv_file']['error'] ?? UPLOAD_ERR_NO_FILE), 'error');
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_safe_redirect(admin_url('admin.php?page=lww_import_ui'));
        exit;
    }

    $upload_dir = wp_upload_dir();
    $tmp_name = $_FILES['inventory_backup_csv_file']['tmp_name'];
    $name = $_FILES['inventory_backup_csv_file']['name'];
    $file_ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    if ($file_ext !== 'csv') {
        add_settings_error('lww_messages', 'inv_backup_wrong_type', __('Falscher Dateityp. Bitte lade eine .csv-Datei hoch.', 'lego-wawi'), 'error');
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_safe_redirect(admin_url('admin.php?page=lww_import_ui'));
        exit;
    }

    $timestamp = time();
    $target_csv_path = $upload_dir['basedir'] . '/lww_import_inventory_backup_' . $timestamp . '.csv';
    $priority = (int) get_option('lww_job_priority_inventory_backup_import', 5);

    if (is_uploaded_file($tmp_name) && move_uploaded_file($tmp_name, $target_csv_path)) {
        $job_queue = [[ 
            'key'            => 'inventory_backup',
            'path'           => $target_csv_path,
            'status'         => 'pending',
            'rows_processed' => 0,
            'total_rows'     => 0,
        ]];

        $job_id = wp_insert_post([
            'post_title'   => sprintf(__('Inventar-Backup Import (%s)', 'lego-wawi'), esc_html($name)) . ' - ' . date_i18n('d.m.Y H:i'),
            'post_type'    => 'lww_job',
            'post_status'  => 'lww_pending',
            'post_author'  => get_current_user_id(),
            'menu_order'   => $priority,
        ], true);

        if (is_wp_error($job_id)) {
            add_settings_error('lww_messages', 'inv_backup_job_creation_failed', __('Fehler beim Erstellen des Backup-Import-Jobs: ', 'lego-wawi') . $job_id->get_error_message(), 'error');
            @unlink($target_csv_path);
        } else {
            update_post_meta($job_id, '_job_type', 'inventory_backup_import');
            update_post_meta($job_id, '_job_queue', $job_queue);
            update_post_meta($job_id, '_current_task_index', 0);
            lww_log_to_job($job_id, 'Inventar-Backup-Import-Job erstellt.');

            lww_start_cron_job();

            add_settings_error('lww_messages', 'inv_backup_job_created', __('Neuer Backup-Import-Job wurde erfolgreich erstellt.', 'lego-wawi'), 'success');
        }
    } else {
        add_settings_error('lww_messages', 'inv_backup_move_error', __('Die hochgeladene Backup-Datei konnte nicht verarbeitet werden.', 'lego-wawi'), 'error');
    }

    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect(admin_url('admin.php?page=lww_jobs_ui'));
    exit;
}
add_action('admin_post_lww_upload_inventory_backup_csv', 'lww_inventory_backup_import_handler');


// --- HILFSFUNKTIONEN FÜR DATEI-ENTPACKUNG ---

/**
 * Entpackt eine .zip-Datei und extrahiert die relevante CSV.
 */
function lww_unzip_file($zip_path, $target_csv_path, $file_key) {
    if (!class_exists('ZipArchive')) return false;

    $zip = new ZipArchive;
    if ($zip->open($zip_path) === TRUE) {
        $csv_filename_in_zip = $file_key . '.csv';
        $file_index = $zip->locateName($csv_filename_in_zip, ZipArchive::FL_NOCASE);

        if ($file_index !== false) {
            if ($zip->extractTo(dirname($target_csv_path), $zip->getNameIndex($file_index))) {
                 $extracted_path = dirname($target_csv_path) . '/' . $zip->getNameIndex($file_index);
                 if ($extracted_path !== $target_csv_path) {
                    if (!rename($extracted_path, $target_csv_path)) {
                        $zip->close();
                        @unlink($zip_path);
                        @unlink($extracted_path);
                        return false;
                    }
                 }
                 $zip->close();
                 @unlink($zip_path);
                 return true;
            }
        }
        $zip->close();
    }
    @unlink($zip_path);
    return false;
}

/**
 * Entpackt eine .gz-Datei.
 */
function lww_un_gz_file($gz_path, $target_csv_path) {
    $buffer_size = 4096;
    $gz_handle = @gzopen($gz_path, 'rb');
    if (!$gz_handle) return false;

    $csv_handle = @fopen($target_csv_path, 'wb');
    if (!$csv_handle) {
        gzclose($gz_handle);
        return false;
    }

    while (!gzeof($gz_handle)) {
        $buffer = gzread($gz_handle, $buffer_size);
        if ($buffer === false) {
            fclose($csv_handle);
            gzclose($gz_handle);
            @unlink($target_csv_path);
            @unlink($gz_path);
             return false;
        }
        if (fwrite($csv_handle, $buffer) === false) {
             fclose($csv_handle);
             gzclose($gz_handle);
             @unlink($target_csv_path);
             @unlink($gz_path);
             return false;
        }
    }

    gzclose($gz_handle);
    fclose($csv_handle);
    @unlink($gz_path);
    return true;
}
?>