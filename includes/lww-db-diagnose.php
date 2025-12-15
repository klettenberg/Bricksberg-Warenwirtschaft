<?php
/**
 * Modul: Datenbank-Aufräum- und Diagnose-Tool (v1.0)
 *
 * Prüft und bereinigt typische WaWi-Altlasten. Optional: Statistiken.
 */
if (!defined('ABSPATH')) exit;

/**
 * Führt eine gründliche DB-Diagnose aus und gibt eine Zusammenfassung zurück.
 */
function lww_run_db_diagnose_and_cleanup($do_cleanup = true, $job_log = null) {
    global $wpdb;
    $results = [];
    
    // 1. Anzahl/Größe Katalogdaten
    $total_parts = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'lww_part'");
    $total_sets  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'lww_set'");
    $total_minis = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'lww_minifig'");
    $results[] = "Teile: $total_parts | Sets: $total_sets | Minifigs: $total_minis";

    // 2. Anzahl verwaiste Stubs
    $stub_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE (post_type IN ('lww_part','lww_set','lww_minifig')) AND post_status='publish' AND ID NOT IN (SELECT DISTINCT IFNULL(meta_value,0) FROM {$wpdb->postmeta} WHERE meta_key IN ('_lww_part_id','_lww_set_id','_lww_minifig_id')) AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id = {$wpdb->posts}.ID AND pm.meta_key = '_lww_is_stub' AND pm.meta_value = '1')");
    $results[] = "Verwaiste Stubs: $stub_count";
    
    // 3. Anzahl verwaiste Inventory-Items
    $orphans = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_lww_part_id' WHERE p.post_type = 'lww_inventory_item' AND p.post_status='publish' AND (pm.meta_value IS NULL OR pm.meta_value = '' OR pm.meta_value = '0')");
    $results[] = "Inventory-Items ohne Teilverbindung: $orphans";

    // 4. Dickste Metaschlüssel (Top 10 Key-Verteilung)
    $metas = $wpdb->get_results("SELECT meta_key, COUNT(*) as cnt FROM {$wpdb->postmeta} GROUP BY meta_key ORDER BY cnt DESC LIMIT 10");
    $meta_stat = array_map(function($m){return $m->meta_key.': '.$m->cnt;}, $metas);
    $results[] = 'Top 10 Postmeta-Keys: ' . implode(' | ', $meta_stat);

    // 5. Optionale Aufräumarbeiten
    $deleted_stubs = 0; $cleaned_meta = 0; $deleted_chunks = 0;
    if ($do_cleanup) {
        // a) Verwaiste Stubs löschen (nie im Inventar referenziert, ist_stub=1)
        $stub_ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE (post_type IN ('lww_part','lww_set','lww_minifig')) AND post_status='publish' AND ID NOT IN (SELECT DISTINCT IFNULL(meta_value,0) FROM {$wpdb->postmeta} WHERE meta_key IN ('_lww_part_id','_lww_set_id','_lww_minifig_id')) AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id = {$wpdb->posts}.ID AND pm.meta_key = '_lww_is_stub' AND pm.meta_value = '1')");
        foreach ($stub_ids as $id) {
            wp_delete_post($id, true); $deleted_stubs++;
        }
        
        // b) Aufräumen: _job_log, _current_processing_info, _last_heartbeat alter Jobs (älter als 7 Tage und status=lww_complete|failed)
        $old_job_ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type='lww_job' AND post_status IN ('lww_complete','lww_failed') AND post_modified < %s", date('Y-m-d H:i:s', strtotime('-7 days'))));
        if (!empty($old_job_ids)) {
            $in = implode(',', array_map('intval', $old_job_ids));
            $cleaned_meta += $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($in) AND meta_key IN ('_job_log','_current_processing_info','_last_heartbeat','_processed_items','_total_items','_csv_headers','_total_chunks','_current_chunk','_processed_rows')");
        }

        // c) Postmeta zu gelöschten Posts entsorgen
        $cleaned_meta += $wpdb->query("DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} wp ON wp.ID = pm.post_id WHERE wp.ID IS NULL");

        // d) Temporäre API-Import-Chunks entfernen
        $upload_dir = wp_upload_dir();
        $files = glob($upload_dir['basedir'] . '/lww_api_sync_*_chunk_*.json');
        foreach ($files as $file) {
            @unlink($file); $deleted_chunks++;
        }
    }

    $results[] = "Bereinigt: $deleted_stubs Stubs entfernt, $cleaned_meta Metas gelöscht, $deleted_chunks Chunks gelöscht.";
    if ($job_log) foreach($results as $line) lww_log_to_job($job_log, $line);
    return $results;
}

