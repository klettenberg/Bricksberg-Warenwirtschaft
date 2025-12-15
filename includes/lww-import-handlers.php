<?php
/**
 * Modul: Upload & Job Erstellung (v22.0-FIXED)
 * 
 * Enthält Handler für Datei-Uploads und API-Job-Starts.
 * BEHEBT: Fehlende Funktionen und stellt BrickLink Inventory API Sync bereit.
 */
if (!defined('ABSPATH')) exit;

// --- KERN-HELPER FÜR DATEIEN ---

function lww_unzip_file($zip_path, $target, $orig) {
    if (!class_exists('ZipArchive')) return false;
    $zip = new ZipArchive; 
    if ($zip->open($zip_path) !== TRUE) return false;
    $csv = '';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if (strpos(strtolower($stat['name']), '.csv') !== false) { $csv = $stat['name']; break; }
    }
    $res = false;
    if ($csv && $zip->extractTo(dirname($target), $csv)) {
         $extracted = dirname($target) . '/' . $csv;
         if (rename($extracted, $target)) $res = true;
    }
    $zip->close(); 
    return $res;
}

function lww_un_gz_file($gz, $target) {
    $buf = 4096; 
    $zh = @gzopen($gz, 'rb'); 
    if (!$zh) return false;
    $th = @fopen($target, 'wb'); 
    if (!$th) { gzclose($zh); return false; } 
    while (!gzeof($zh)) fwrite($th, gzread($zh, $buf));
    gzclose($zh); 
    fclose($th); 
    return true;
}

// --- 1. KATALOG IMPORT (CSV) ---

function lww_catalog_import_handler() {
    $redirect_url = admin_url('admin.php?page=lww_import_ui');
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'lww_catalog_import_nonce')) {
        add_settings_error('lww_messages', 'security_fail', __('Sicherheitsprüfung fehlgeschlagen.', 'lego-wawi'), 'error');
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_safe_redirect($redirect_url); exit;
    }
    if (empty($_FILES['lww_csv_files']['name'][0])) {
        add_settings_error('lww_messages', 'no_file', __('Keine Datei.', 'lego-wawi'), 'error');
        wp_safe_redirect($redirect_url); exit;
    }

    $upload_dir = wp_upload_dir(); 
    $import_files = [];
    $files_data = $_FILES['lww_csv_files']; 
    
    // Mapping: Dateiname => Handler-Key
    $file_mapping = [
        'colors' => 'colors', 'themes' => 'themes', 'part_categories' => 'part_categories', 
        'part_relationships' => 'part_relationships', 'inventory_parts' => 'inventory_parts',
        'inventory_sets' => 'inventory_sets', 'inventory_minifigs' => 'inventory_minifigs',
        'inventories' => 'inventories', 'elements' => 'elements', 'minifigs' => 'minifigs',
        'sets' => 'sets', 'parts' => 'parts',
    ];

    foreach ($files_data['name'] as $key => $name) {
        if ($files_data['error'][$key] === UPLOAD_ERR_OK) {
            $tmp = $files_data['tmp_name'][$key];
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $target = $upload_dir['basedir'] . '/lww_imp_' . sanitize_file_name($name) . '_' . time() . '.csv';
            $ok = false;

            if ($ext === 'zip') $ok = lww_unzip_file($tmp, $target, $name);
            elseif ($ext === 'gz') $ok = lww_un_gz_file($tmp, $target);
            elseif ($ext === 'csv') $ok = move_uploaded_file($tmp, $target);

            if ($ok) {
                $fname_low = strtolower($name);
                foreach ($file_mapping as $search => $map_key) {
                    if (strpos($fname_low, $search) !== false) { $import_files[$map_key] = $target; break; }
                }
            }
        }
    }

    if (empty($import_files)) {
        add_settings_error('lww_messages', 'fail', 'Keine gültigen Katalog-Dateien erkannt.', 'error');
        wp_safe_redirect($redirect_url); exit;
    }

    $order = ['colors', 'themes', 'part_categories', 'parts', 'sets', 'minifigs', 'part_relationships', 'elements', 'inventories', 'inventory_parts', 'inventory_sets', 'inventory_minifigs'];
    $queue = [];
    foreach($order as $k) if(isset($import_files[$k])) $queue[] = ['key' => $k, 'path' => $import_files[$k], 'rows_processed' => 0];

    $job_id = wp_insert_post(['post_title' => 'Katalog-Import (CSV) - ' . date('H:i'), 'post_type' => 'lww_job', 'post_status' => 'lww_pending']);
    update_post_meta($job_id, '_job_type', 'catalog_import');
    update_post_meta($job_id, '_job_queue', $queue);
    update_post_meta($job_id, '_current_task_index', 0);
    
    lww_start_cron_job();
    add_settings_error('lww_messages', 'ok', sprintf('Job erstellt (%d Dateien).', count($queue)), 'success');
    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect(admin_url('admin.php?page=lww_jobs_ui')); exit;
}
add_action('admin_post_lww_upload_catalog_csv', 'lww_catalog_import_handler');

// --- 2. INVENTAR IMPORT (CSV - BrickOwl Style) ---

function lww_inventory_import_handler() {
    if (!check_admin_referer('lww_inventory_import_nonce')) wp_die('Security Check');

    if (empty($_FILES['inventory_csv_file']['name'])) wp_die('Keine Datei.');

    $upload_dir = wp_upload_dir();
    $target_file = $upload_dir['basedir'] . '/lww_inventory_' . time() . '.csv';
    
    if (move_uploaded_file($_FILES['inventory_csv_file']['tmp_name'], $target_file)) {
        $job_id = wp_insert_post([
            'post_title' => 'Inventar-Import (CSV) - ' . date('d.m.Y H:i'),
            'post_type' => 'lww_job',
            'post_status' => 'lww_pending'
        ]);
        update_post_meta($job_id, '_job_type', 'inventory_import');
        update_post_meta($job_id, '_file_path', $target_file);
        update_post_meta($job_id, '_processed_rows', 0);
        
        lww_start_cron_job();
        wp_safe_redirect(admin_url('admin.php?page=lww_jobs_ui'));
        exit;
    } else {
        wp_die('Upload Fehler.');
    }
}
add_action('admin_post_lww_upload_inventory_csv', 'lww_inventory_import_handler');

// --- 3. BRICKLINK INVENTAR XML IMPORT ---

function lww_bricklink_inventory_import_handler() {
    if (!check_admin_referer('lww_bricklink_inventory_import_nonce')) wp_die('Security Check');
    
    if (empty($_FILES['bricklink_inventory_xml_file']['name'])) wp_die('Keine Datei.');

    // XML direkt parsen und in eine flache CSV-Struktur oder JSON für den Batch-Prozessor umwandeln
    // Da unser Batch-Prozessor CSVs bevorzugt, konvertieren wir hier on-the-fly oder speichern XML und nutzen speziellen Handler.
    // Wir speichern die XML und lassen den Job diese verarbeiten.
    
    $upload_dir = wp_upload_dir();
    $target_file = $upload_dir['basedir'] . '/lww_bl_inventory_' . time() . '.xml';
    
    if (move_uploaded_file($_FILES['bricklink_inventory_xml_file']['tmp_name'], $target_file)) {
        // Konvertiere XML zu CSV für den Batch Prozessor (einfacher als neuer XML-Handler)
        // Oder: Wir nutzen den existierenden XML-Handler (LWW_Import_Bricklink_Inventory_Handler) 
        // Der erwartet aber ein Array von Rows. 
        // Wir parsen hier das XML und speichern es als JSON-Datei für den Batch-Prozessor.
        
        $xml = simplexml_load_file($target_file);
        if ($xml) {
            $items = [];
            foreach ($xml->ITEM as $item) {
                $items[] = (array)$item;
            }
            $json_file = str_replace('.xml', '.json', $target_file);
            file_put_contents($json_file, json_encode($items));
            @unlink($target_file); // XML löschen

            $job_id = wp_insert_post([
                'post_title' => 'BrickLink XML Import - ' . date('d.m.Y H:i'),
                'post_type' => 'lww_job',
                'post_status' => 'lww_pending'
            ]);
            update_post_meta($job_id, '_job_type', 'inventory_import'); // Nutzt generischen File Loop
            update_post_meta($job_id, '_file_path', $json_file); // JSON Datei statt CSV
            update_post_meta($job_id, '_file_format', 'json_bricklink'); // Marker für den Handler
            update_post_meta($job_id, '_processed_rows', 0);

            lww_start_cron_job();
            wp_safe_redirect(admin_url('admin.php?page=lww_jobs_ui'));
            exit;
        }
    }
    wp_die('Fehler beim Verarbeiten der XML Datei.');
}
add_action('admin_post_lww_upload_bricklink_inventory_xml', 'lww_bricklink_inventory_import_handler');

// --- 4. API SYNCS (JOBS STARTEN) ---

function lww_start_bricklink_catalog_sync_handler() {
    if (!check_admin_referer('lww_bricklink_catalog_sync_nonce')) wp_die('Security Check');
    $job_id = wp_insert_post(['post_title' => 'BrickLink Katalog Sync (Farben)', 'post_type' => 'lww_job', 'post_status' => 'lww_pending']);
    update_post_meta($job_id, '_job_type', 'bricklink_catalog_sync');
    update_post_meta($job_id, '_processed_items', 0);
    lww_start_cron_job();
    wp_safe_redirect(admin_url('admin.php?page=lww_jobs_ui')); exit;
}
add_action('admin_post_lww_start_bricklink_catalog_sync', 'lww_start_bricklink_catalog_sync_handler');

function lww_start_brickowl_catalog_sync_handler() {
    if (!check_admin_referer('lww_brickowl_catalog_sync_nonce')) wp_die('Security Check');
    $job_id = wp_insert_post(['post_title' => 'BrickOwl Katalog Sync (Farben)', 'post_type' => 'lww_job', 'post_status' => 'lww_pending']);
    update_post_meta($job_id, '_job_type', 'brickowl_catalog_sync');
    update_post_meta($job_id, '_processed_items', 0);
    lww_start_cron_job();
    wp_safe_redirect(admin_url('admin.php?page=lww_jobs_ui')); exit;
}
add_action('admin_post_lww_start_brickowl_catalog_sync', 'lww_start_brickowl_catalog_sync_handler');

function lww_start_brickowl_inventory_sync_handler() {
    if (!check_admin_referer('lww_brickowl_inventory_sync_nonce')) wp_die('Security Check');
    $job_id = wp_insert_post(['post_title' => 'BrickOwl Inventar Sync - ' . date('H:i'), 'post_type' => 'lww_job', 'post_status' => 'lww_pending']);
    update_post_meta($job_id, '_job_type', 'brickowl_inventory_sync');
    update_post_meta($job_id, '_processed_items', 0);
    lww_start_cron_job();
    wp_safe_redirect(admin_url('admin.php?page=lww_jobs_ui')); exit;
}
add_action('admin_post_lww_start_brickowl_inventory_sync', 'lww_start_brickowl_inventory_sync_handler');

// NEU: BrickLink Inventory API Sync
function lww_start_bricklink_inventory_sync_handler() {
    if (!check_admin_referer('lww_bricklink_inventory_sync_nonce')) wp_die('Security Check');
    
    $job_id = wp_insert_post([
        'post_title' => 'BrickLink Inventar Sync (API) - ' . date('H:i'), 
        'post_type' => 'lww_job', 
        'post_status' => 'lww_pending'
    ]);
    update_post_meta($job_id, '_job_type', 'bricklink_inventory_sync');
    update_post_meta($job_id, '_processed_items', 0);
    
    lww_start_cron_job();
    
    add_settings_error('lww_messages', 'job_started', 'BrickLink API Sync Job gestartet.', 'success');
    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect(admin_url('admin.php?page=lww_jobs_ui')); 
    exit;
}
add_action('admin_post_lww_start_bricklink_inventory_sync', 'lww_start_bricklink_inventory_sync_handler');
?>