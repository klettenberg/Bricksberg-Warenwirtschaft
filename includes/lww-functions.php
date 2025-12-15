<?php
/**
 * Modul: Globale Hilfsfunktionen (v27.2-PERFORMANCE)
 * Enthält Logging, Statistiken, Cron-Management, Import-Hilfen und Bild-Handling.
 * 
 * UPDATE: Erhöhtes Caching für teure Statistik-Abfragen.
 */
if (!defined('ABSPATH')) exit;

function lww_write_debug_log($message, $type = 'INFO') {
    $upload_dir = wp_upload_dir();
    $log_dir = $upload_dir['basedir'] . '/lww-logs';
    if (!file_exists($log_dir)) {
        wp_mkdir_p($log_dir);
        file_put_contents($log_dir . '/.htaccess', 'Deny from all');
        file_put_contents($log_dir . '/index.php', '<?php // Silence is golden');
    }

    $log_file = $log_dir . '/debug.log';
    $timestamp = wp_date('Y-m-d H:i:s');
    $formatted_message = sprintf("[%s] [%s] %s\n", $timestamp, strtoupper($type), $message);

    @file_put_contents($log_file, $formatted_message, FILE_APPEND);

    if (file_exists($log_file) && filesize($log_file) > 5 * 1024 * 1024) {
        @rename($log_file, $log_dir . '/debug-' . wp_date('Y-m-d-H-i') . '.log');
    }
}

function lww_read_debug_log($lines = 100) {
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/lww-logs/debug.log';
    if (!file_exists($log_file)) return __('Kein Log-File vorhanden.', 'lego-wawi');
    $file = file($log_file);
    if (!$file) return __('Log-File leer oder nicht lesbar.', 'lego-wawi');
    $output = array_slice($file, -$lines);
    return implode("", $output);
}

function lww_clear_debug_log() {
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/lww-logs/debug.log';
    if (file_exists($log_file)) {
        @unlink($log_file);
    }
}

function lww_record_system_log($action, $service, $details = []) {
    $msg = "$action ($service) - " . json_encode($details);
    if (isset($details['error'])) {
        lww_write_debug_log($msg, 'ERROR');
    } else {
        lww_write_debug_log($msg, 'INFO');
    }
}

function lww_log_api_call($service, $action, $success, $cost = 0.0, $details = []) {
    $status = $success ? 'SUCCESS' : 'ERROR';
    $msg = sprintf("API Call [%s]: %s - Status: %s - Cost: %s", $service, $action, $status, $cost);
    if (!empty($details)) {
        $msg .= " - Details: " . json_encode($details);
    }
    lww_write_debug_log($msg, $success ? 'API' : 'API_ERR');
}

function lww_log_to_job($job_id, $message) {
    if (empty($job_id)) return;
    lww_write_debug_log("Job #$job_id: $message", 'JOB');
    update_post_meta($job_id, '_last_run_timestamp', time());
    update_post_meta($job_id, '_last_log_message', $message);
    
    $log = get_post_meta($job_id, '_job_log', true);
    if (!is_array($log)) $log = [];
    $entry = sprintf('[%s] %s', wp_date('H:i:s'), $message);
    $log[] = $entry;
    if (count($log) > 100) $log = array_slice($log, -100);
    update_post_meta($job_id, '_job_log', $log);
}

function lww_log_unresolved_reference($job_id, $context, $type, $value, $line_number) {
    if (empty($job_id)) {
        lww_write_debug_log(sprintf("Unresolved Reference in %s (Line %d): %s = %s", $context, $line_number, $type, $value), 'WARN');
        return;
    }
    $unresolved = get_post_meta($job_id, '_unresolved_references', true);
    if (!is_array($unresolved)) $unresolved = [];
    if (count($unresolved) > 500) return;

    $key = md5($context . $type . $value);
    if (!isset($unresolved[$key])) {
        $unresolved[$key] = ['context' => $context, 'type' => $type, 'value' => $value, 'line' => $line_number, 'count' => 1];
    } else { $unresolved[$key]['count']++; }
    update_post_meta($job_id, '_unresolved_references', $unresolved);
}

function lww_scrape_lego_com($set_num) {
    $url = 'https://www.lego.com/de-de/product/' . $set_num;
    $response = wp_remote_get($url, ['user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36']);
    if (is_wp_error($response)) return false;
    $html = wp_remote_retrieve_body($response);
    $data = [];
    if (preg_match('/<h1[^>]*>(.*?)<\/h1>/s', $html, $matches)) {
        $data['title'] = trim(strip_tags($matches[1]));
    }
    if (preg_match('/<meta name="description" content="(.*?)"/', $html, $matches)) {
        $data['description'] = $matches[1];
    }
    return $data;
}

function lww_sideload_image_to_media_library($url, $desc, $post_id = 0) {
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');

    if (empty($url)) return new WP_Error('empty_url', 'URL ist leer.');
    if (filter_var($url, FILTER_VALIDATE_URL) === false) return new WP_Error('invalid_url', 'Ungültige URL.');

    $tmp = download_url($url);
    if (is_wp_error($tmp)) return $tmp;

    $file_array = ['name' => basename(parse_url($url, PHP_URL_PATH)), 'tmp_name' => $tmp];
    if (pathinfo($file_array['name'], PATHINFO_EXTENSION) === '') $file_array['name'] .= '.jpg';

    $id = media_handle_sideload($file_array, $post_id, $desc);
    if (is_wp_error($id)) {
        @unlink($file_array['tmp_name']);
        return $id;
    }
    update_post_meta($id, '_wp_attachment_image_alt', $desc);
    return $id;
}

/**
 * Erstellt einen Katalogeintrag mit intelligenter Enrichment-Strategie.
 *
 * @param string $item_no Die Item-Nummer (z.B. "3001", "SW001-1")
 * @param string $source Quelle der Daten ('rebrickable', 'bricklink', 'brickowl', 'csv_import')
 * @param int $job_id Optional: Job-ID für Logging
 * @param bool $force_immediate_resolution Soll sofort alles aufgelöst werden (kostet API-Calls)?
 * @return array|false Array mit ['id' => Post-ID, 'type' => Post-Type] oder false bei Fehler
 */
function lww_create_and_enrich_missing_catalog_item($item_no, $source = 'rebrickable', $job_id = 0, $force_immediate_resolution = false) {
    $item_no = trim($item_no);
    if (empty($item_no)) return false;

    $type = 'part';
    if (strpos($item_no, '-') !== false && preg_match('/^\d+-\d+$/', $item_no)) $type = 'set';
    elseif (preg_match('/^fig-/i', $item_no)) $type = 'minifig';

    $post_type = "lww_$type";
    $meta_key_num = "_lww_{$type}_num";

    // Prüfe, ob bereits existiert
    if (class_exists('LWW_Import_Handler_Base')) {
        $exists = LWW_Import_Handler_Base::find_post_by_meta($post_type, $meta_key_num, $item_no);
        if (!$exists && $source === 'bricklink') $exists = LWW_Import_Handler_Base::find_post_by_meta($post_type, '_lww_bricklink_id', $item_no);
        if (!$exists && $source === 'brickowl') $exists = LWW_Import_Handler_Base::find_post_by_meta($post_type, '_lww_brickowl_id', $item_no);
    } else { $exists = 0; }

    if ($exists) return ['id' => $exists, 'type' => $post_type];

    // Entscheide, ob sofort alles aufgelöst werden soll
    $should_resolve_now = $force_immediate_resolution || lww_should_resolve_immediately($item_no, $source);

    if ($should_resolve_now) {
        // Vollständige Auflösung mit APIs
        return lww_create_enriched_catalog_item($item_no, $source, $job_id);
    } else {
        // Klassischer Stub (aber mit besserem Namen)
        return lww_create_smart_stub($item_no, $source, $job_id);
    }
}

/**
 * Erstellt einen vollständig aufgelösten Katalogeintrag mit API-Daten.
 */
function lww_create_enriched_catalog_item($item_no, $source = 'rebrickable', $job_id = 0) {
    $item_no = trim($item_no);
    if (empty($item_no)) return false;

    $type = 'part';
    if (strpos($item_no, '-') !== false && preg_match('/^\d+-\d+$/', $item_no)) $type = 'set';
    elseif (preg_match('/^fig-/i', $item_no)) $type = 'minifig';

    $post_type = "lww_$type";
    $meta_key_num = "_lww_{$type}_num";

    // Prüfe, ob bereits existiert
    if (class_exists('LWW_Import_Handler_Base')) {
        $exists = LWW_Import_Handler_Base::find_post_by_meta($post_type, $meta_key_num, $item_no);
        if (!$exists && $source === 'bricklink') $exists = LWW_Import_Handler_Base::find_post_by_meta($post_type, '_lww_bricklink_id', $item_no);
        if (!$exists && $source === 'brickowl') $exists = LWW_Import_Handler_Base::find_post_by_meta($post_type, '_lww_brickowl_id', $item_no);
    } else { $exists = 0; }

    if ($exists) return ['id' => $exists, 'type' => $post_type];

    // Hole Daten aus APIs
    $enriched_data = lww_enrich_item_data($item_no, $source);

    // Bestimme Titel
    $title = $enriched_data['name'] ?? "$item_no (unbekannt)";

    $post_id = wp_insert_post([
        'post_title' => $title,
        'post_type' => $post_type,
        'post_status' => 'publish'
    ]);

    if (is_wp_error($post_id)) {
        if ($job_id) lww_log_to_job($job_id, "Fehler beim Erstellen von $item_no: " . $post_id->get_error_message());
        return false;
    }

    // Multitenancy
    if (class_exists('LWW_Multitenancy')) {
        $current_tenant = LWW_Multitenancy::get_current_tenant_id();
        if ($current_tenant) update_post_meta($post_id, '_lww_tenant_id', $current_tenant);
    }

    // Basis-Metas
    update_post_meta($post_id, $meta_key_num, $item_no);
    update_post_meta($post_id, '_lww_is_stub', 0); // Nicht mehr als Stub markieren

    // Plattform-IDs setzen
    if (!empty($enriched_data['rebrickable_id'])) {
        update_post_meta($post_id, '_lww_rebrickable_id', $enriched_data['rebrickable_id']);
    }
    if (!empty($enriched_data['bricklink_id'])) {
        update_post_meta($post_id, '_lww_bricklink_id', $enriched_data['bricklink_id']);
    }
    if (!empty($enriched_data['brickowl_id'])) {
        update_post_meta($post_id, '_lww_brickowl_id', $enriched_data['brickowl_id']);
    }

    // Zusätzliche Daten
    if (!empty($enriched_data['year'])) {
        update_post_meta($post_id, '_lww_year_released', $enriched_data['year']);
    }
    if (!empty($enriched_data['num_parts'])) {
        update_post_meta($post_id, '_lww_num_parts', $enriched_data['num_parts']);
    }
    if (!empty($enriched_data['image_url'])) {
        update_post_meta($post_id, '_lww_sideload_image_url', $enriched_data['image_url']);
    }

    // Spezifische Namen-Metas
    switch ($type) {
        case 'part':
            if (!empty($enriched_data['name'])) {
                update_post_meta($post_id, '_lww_part_name', $enriched_data['name']);
            }
            break;
        case 'set':
            if (!empty($enriched_data['name'])) {
                update_post_meta($post_id, '_lww_set_name', $enriched_data['name']);
            }
            break;
        case 'minifig':
            if (!empty($enriched_data['name'])) {
                update_post_meta($post_id, '_lww_minifig_name', $enriched_data['name']);
            }
            break;
    }

    // Caching
    if (class_exists('LWW_Import_Handler_Base')) {
        LWW_Import_Handler_Base::cache_post_lookup($post_type, $meta_key_num, $item_no, $post_id);
        if (!empty($enriched_data['rebrickable_id'])) {
            LWW_Import_Handler_Base::cache_post_lookup($post_type, '_lww_rebrickable_id', $enriched_data['rebrickable_id'], $post_id);
        }
        if (!empty($enriched_data['bricklink_id'])) {
            LWW_Import_Handler_Base::cache_post_lookup($post_type, '_lww_bricklink_id', $enriched_data['bricklink_id'], $post_id);
        }
        if (!empty($enriched_data['brickowl_id'])) {
            LWW_Import_Handler_Base::cache_post_lookup($post_type, '_lww_brickowl_id', $enriched_data['brickowl_id'], $post_id);
        }
    }

    if ($job_id) {
        lww_log_to_job($job_id, "Vollständiger Katalogeintrag erstellt: $title (ID: $post_id, Typ: $post_type)");
    }

    return ['id' => $post_id, 'type' => $post_type];
}

/**
 * Erstellt einen intelligenten Stub (ohne "Stub..." im Namen).
 */
function lww_create_smart_stub($item_no, $source = 'rebrickable', $job_id = 0) {
    $item_no = trim($item_no);
    if (empty($item_no)) return false;

    $type = 'part';
    if (strpos($item_no, '-') !== false && preg_match('/^\d+-\d+$/', $item_no)) $type = 'set';
    elseif (preg_match('/^fig-/i', $item_no)) $type = 'minifig';

    $post_type = "lww_$type";
    $meta_key_num = "_lww_{$type}_num";

    // Prüfe, ob bereits existiert
    if (class_exists('LWW_Import_Handler_Base')) {
        $exists = LWW_Import_Handler_Base::find_post_by_meta($post_type, $meta_key_num, $item_no);
        if (!$exists && $source === 'bricklink') $exists = LWW_Import_Handler_Base::find_post_by_meta($post_type, '_lww_bricklink_id', $item_no);
        if (!$exists && $source === 'brickowl') $exists = LWW_Import_Handler_Base::find_post_by_meta($post_type, '_lww_brickowl_id', $item_no);
    } else { $exists = 0; }

    if ($exists) return ['id' => $exists, 'type' => $post_type];

    // Besserer Titel ohne "Stub"
    $smart_title = lww_generate_smart_title($item_no, $type, $source);

    $post_id = wp_insert_post([
        'post_title' => $smart_title,
        'post_type' => $post_type,
        'post_status' => 'publish'
    ]);

    if (is_wp_error($post_id)) {
        if ($job_id) lww_log_to_job($job_id, "Fehler beim Erstellen von $item_no: " . $post_id->get_error_message());
        return false;
    }

    // Multitenancy
    if (class_exists('LWW_Multitenancy')) {
        $current_tenant = LWW_Multitenancy::get_current_tenant_id();
        if ($current_tenant) update_post_meta($post_id, '_lww_tenant_id', $current_tenant);
    }

    // Basis-Metas
    update_post_meta($post_id, '_lww_is_stub', 1);
    update_post_meta($post_id, $meta_key_num, $item_no);

    // Plattform-ID setzen
    if ($source === 'bricklink') update_post_meta($post_id, '_lww_bricklink_id', $item_no);
    elseif ($source === 'brickowl') update_post_meta($post_id, '_lww_brickowl_id', $item_no);

    // Caching
    if (class_exists('LWW_Import_Handler_Base')) {
        LWW_Import_Handler_Base::cache_post_lookup($post_type, $meta_key_num, $item_no, $post_id);
        if ($source === 'bricklink') LWW_Import_Handler_Base::cache_post_lookup($post_type, '_lww_bricklink_id', $item_no, $post_id);
        if ($source === 'brickowl') LWW_Import_Handler_Base::cache_post_lookup($post_type, '_lww_brickowl_id', $item_no, $post_id);
    }

    if ($job_id) {
        lww_log_to_job($job_id, "Intelligenter Stub erstellt: $smart_title (ID: $post_id, Typ: $post_type)");
    }

    return ['id' => $post_id, 'type' => $post_type];
}

/**
 * Generiert einen intelligenten Titel für Stubs (ohne "Stub" im Namen).
 */
function lww_generate_smart_title($item_no, $type, $source) {
    $base = $item_no;

    switch ($type) {
        case 'part':
            $title = "Teil $item_no";
            break;
        case 'set':
            $title = "Set $item_no";
            break;
        case 'minifig':
            $title = "Minifigur $item_no";
            break;
        default:
            $title = $item_no;
    }

    // Quelle hinzufügen für bessere Erkennbarkeit
    $source_label = '';
    switch ($source) {
        case 'bricklink':
            $source_label = ' (BrickLink)';
            break;
        case 'brickowl':
            $source_label = ' (BrickOwl)';
            break;
        case 'csv_import':
            $source_label = ' (Import)';
            break;
    }

    return $title . $source_label;
}

/**
 * Entscheidet, ob sofort alles aufgelöst werden soll (API-Calls machen).
 */
function lww_should_resolve_immediately($item_no, $source) {
    // Bei kleinen Imports immer alles auflösen
    static $import_size = null;
    if ($import_size === null) {
        $import_size = get_option('lww_current_import_size', 100); // Default 100
    }

    // Wenn kleiner Import oder explizit gewünscht
    $force_resolve = get_option('lww_force_immediate_resolution', false);
    if ($force_resolve || $import_size < 50) {
        return true;
    }

    // Bei bekannten/niedrigen Nummern aus BrickLink/BrickOwl öfter auflösen
    if ($source === 'bricklink' || $source === 'brickowl') {
        // Einfache Heuristik: Wenn Nummer kurz, wahrscheinlich bekannt
        return strlen($item_no) < 10;
    }

    return false;
}

/**
 * Holt angereicherte Daten für ein Item aus verschiedenen APIs.
 */
function lww_enrich_item_data($item_no, $source = 'rebrickable') {
    $data = [
        'name' => null,
        'rebrickable_id' => null,
        'bricklink_id' => null,
        'brickowl_id' => null,
        'year' => null,
        'num_parts' => null,
        'image_url' => null,
    ];

    // Rebrickable als Master-Quelle
    if (class_exists('LWW_Rebrickable_API')) {
        $api_settings = get_option('lww_api_settings');
        $rb_key = $api_settings['rebrickable_api_key'] ?? '';
        if (!empty($rb_key)) {
            $rb_api = new LWW_Rebrickable_API($rb_key);

            // Typ bestimmen
            $type = 'part';
            if (strpos($item_no, '-') !== false && preg_match('/^\d+-\d+$/', $item_no)) $type = 'set';
            elseif (preg_match('/^fig-/i', $item_no)) $type = 'minifig';

            $rb_data = null;
            if ($type === 'part') {
                $rb_data = $rb_api->get_part($item_no);
            } elseif ($type === 'set') {
                $rb_data = $rb_api->get_set($item_no);
            } elseif ($type === 'minifig') {
                $rb_data = $rb_api->get_minifig($item_no);
            }

            if (!is_wp_error($rb_data) && is_array($rb_data)) {
                $data['name'] = $rb_data['name'] ?? null;
                $data['rebrickable_id'] = $rb_data['part_num'] ?? $rb_data['set_num'] ?? $rb_data['set_num'] ?? $item_no;
                $data['year'] = $rb_data['year'] ?? null;
                $data['num_parts'] = $rb_data['num_parts'] ?? null;
                $data['image_url'] = $rb_data['img_url'] ?? null;
            }
        }
    }

    // BrickLink-ID heuristisch setzen
    if ($source === 'bricklink') {
        $data['bricklink_id'] = $item_no;
    }

    // BrickOwl-ID heuristisch setzen
    if ($source === 'brickowl') {
        $data['brickowl_id'] = $item_no;
    }

    return $data;
}

function lww_hex_to_hsl($hex) {
    $hex = str_replace('#', '', $hex);
    if (strlen($hex) == 3) {
        $r = hexdec(substr($hex, 0, 1) . substr($hex, 0, 1));
        $g = hexdec(substr($hex, 1, 1) . substr($hex, 1, 1));
        $b = hexdec(substr($hex, 2, 1) . substr($hex, 2, 1));
    } else {
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
    }
    $r /= 255; $g /= 255; $b /= 255;
    $max = max($r, $g, $b); $min = min($r, $g, $b);
    $h = $s = $l = ($max + $min) / 2;
    if ($max == $min) { $h = $s = 0; } else {
        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        switch ($max) {
            case $r: $h = ($g - $b) / $d + ($g < $b ? 6 : 0); break;
            case $g: $h = ($b - $r) / $d + 2; break;
            case $b: $h = ($r - $g) / $d + 4; break;
        }
        $h /= 6;
    }
    return ['h' => $h * 360, 's' => $s * 100, 'l' => $l * 100];
}

function lww_start_cron_job() {
    if (!wp_next_scheduled('lww_main_batch_hook')) {
        $interval = get_option('lww_cron_interval', 'lww_every_minute');
        wp_schedule_event(time(), $interval, 'lww_main_batch_hook');
        lww_write_debug_log('Cronjob lww_main_batch_hook geplant mit Intervall: ' . $interval);
    }
}

/**
 * Berechnet Inventory-Statistiken mit Cache (für Dashboard/UI).
 */
function lww_get_inventory_stats() {
    // PERFORMANCE: Cache auf 1 Stunde erhöht (3600s)
    $stats = get_transient('lww_inventory_stats_cache_v2');
    if (false !== $stats) return $stats;

    $stats = lww_calculate_inventory_stats();
    set_transient('lww_inventory_stats_cache_v2', $stats, HOUR_IN_SECONDS);
    return $stats;
}

/**
 * Berechnet Inventory-Statistiken ohne Cache (für Diagnose).
 */
function lww_calculate_inventory_stats($force_refresh = false) {
    global $wpdb;

    $tenant_sql = "";
    if (class_exists('LWW_Multitenancy')) {
        $tid = LWW_Multitenancy::get_current_tenant_id();
        if ($tid > 0) {
            $tenant_sql = $wpdb->prepare(" AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} tm WHERE tm.post_id = p.ID AND tm.meta_key = '_lww_tenant_id' AND tm.meta_value = %d) ", $tid);
        }
    }

    // Gesamtzahl Teile - nur von existierenden Posts mit gültigen Werten
    $total_quantity = (int) $wpdb->get_var("
        SELECT SUM(CAST(pm.meta_value AS UNSIGNED))
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
        WHERE p.post_type = 'lww_inventory_item'
        AND p.post_status = 'publish'
        AND pm.meta_key = '_quantity'
        AND pm.meta_value > 0
        AND pm.meta_value REGEXP '^[0-9]+$'
        {$tenant_sql}
    ");

    // Anzahl Lots (Unique Posts) - nur mit gültiger Quantity
    $total_lots = (int) $wpdb->get_var("
        SELECT COUNT(DISTINCT p.ID)
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
        WHERE p.post_type = 'lww_inventory_item'
        AND p.post_status = 'publish'
        AND pm.meta_key = '_quantity'
        AND pm.meta_value > 0
        {$tenant_sql}
    ");

    // Lagerwert (Filtern auf realistische Preise < 1000) - robuster
    $total_value = (float) $wpdb->get_var("
        SELECT SUM(CAST(pm_qty.meta_value AS UNSIGNED) * CAST(pm_price.meta_value AS DECIMAL(10,3)))
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} pm_qty ON p.ID = pm_qty.post_id AND pm_qty.meta_key = '_quantity'
        INNER JOIN {$wpdb->postmeta} pm_price ON p.ID = pm_price.post_id AND pm_price.meta_key = '_price'
        WHERE p.post_type = 'lww_inventory_item'
        AND p.post_status = 'publish'
        AND pm_qty.meta_value > 0
        AND pm_price.meta_value > 0
        AND pm_price.meta_value < 1000
        AND pm_price.meta_value REGEXP '^[0-9]+(\.[0-9]{1,3})?$'
        {$tenant_sql}
    ");

    // Umsatz letzte 30 Tage
    $thirty_days_ago = date('Y-m-d H:i:s', strtotime('-30 days'));
    $sales_30 = (float) $wpdb->get_var($wpdb->prepare(
        "SELECT SUM(CAST(pm.meta_value AS DECIMAL(10,2)))
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
         WHERE p.post_type = 'lww_order'
         AND p.post_status IN ('lww_completed', 'lww_shipped')
         AND p.post_date >= %s
         AND pm.meta_key = '_lww_order_total'
         AND pm.meta_value > 0",
        $thirty_days_ago
    ));

    return [
        'total_quantity' => $total_quantity ?: 0,
        'total_lots' => $total_lots ?: 0,
        'total_value' => $total_value ?: 0.0,
        'sales_30_days' => $sales_30 ?: 0.0
    ];
}

/**
 * Invalidiert den Inventory-Stats-Cache (für manuelle Aktualisierung).
 */
function lww_invalidate_inventory_stats_cache() {
    delete_transient('lww_inventory_stats_cache_v2');
}

/**
 * Diagnostiziert Inventory-Datenbank auf Inkonsistenzen.
 */
function lww_diagnose_inventory_data() {
    global $wpdb;

    $issues = [];

    // 1. Prüfe auf verwaiste Metadaten (Posts gelöscht, Meta blieb)
    $orphaned_meta = (int) $wpdb->get_var("
        SELECT COUNT(*) FROM {$wpdb->postmeta} pm
        LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
        WHERE p.ID IS NULL
        AND pm.meta_key IN ('_quantity', '_price', '_lww_inventory_uid')
    ");
    if ($orphaned_meta > 0) {
        $issues[] = "Verwaiste Metadaten: {$orphaned_meta} Einträge";
    }

    // 2. Prüfe auf Inventory-Items ohne Quantity
    $no_quantity = (int) $wpdb->get_var("
        SELECT COUNT(*) FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_quantity'
        WHERE p.post_type = 'lww_inventory_item'
        AND p.post_status = 'publish'
        AND (pm.meta_value IS NULL OR pm.meta_value = '' OR pm.meta_value = '0')
    ");
    if ($no_quantity > 0) {
        $issues[] = "Inventory-Items ohne Quantity: {$no_quantity}";
    }

    // 3. Prüfe auf ungültige Quantity-Werte
    $invalid_quantity = (int) $wpdb->get_var("
        SELECT COUNT(*) FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
        WHERE p.post_type = 'lww_inventory_item'
        AND p.post_status = 'publish'
        AND pm.meta_key = '_quantity'
        AND (pm.meta_value NOT REGEXP '^[0-9]+$' OR CAST(pm.meta_value AS SIGNED) < 0)
    ");
    if ($invalid_quantity > 0) {
        $issues[] = "Ungültige Quantity-Werte: {$invalid_quantity}";
    }

    // 4. Prüfe auf ungültige Price-Werte
    $invalid_price = (int) $wpdb->get_var("
        SELECT COUNT(*) FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
        WHERE p.post_type = 'lww_inventory_item'
        AND p.post_status = 'publish'
        AND pm.meta_key = '_price'
        AND (pm.meta_value NOT REGEXP '^[0-9]+(\.[0-9]{1,3})?$' OR CAST(pm.meta_value AS DECIMAL(10,3)) < 0)
    ");
    if ($invalid_price > 0) {
        $issues[] = "Ungültige Price-Werte: {$invalid_price}";
    }

    // 5. Prüfe auf Duplikate (gleiche UID)
    $duplicate_uids = $wpdb->get_results("
        SELECT meta_value, COUNT(*) as count
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
        WHERE p.post_type = 'lww_inventory_item'
        AND p.post_status = 'publish'
        AND pm.meta_key = '_lww_inventory_uid'
        GROUP BY meta_value
        HAVING count > 1
        LIMIT 10
    ");
    if (!empty($duplicate_uids)) {
        $issues[] = "Duplikate UIDs gefunden: " . count($duplicate_uids) . " verschiedene";
    }

    // 6. Berechne korrigierte Statistiken
    $corrected_stats = lww_calculate_inventory_stats(true);

    return [
        'issues' => $issues,
        'corrected_stats' => $corrected_stats,
        'cached_stats' => lww_get_inventory_stats()
    ];
}

/**
 * Bereinigt Inventory-Datenbank von Inkonsistenzen.
 */
function lww_cleanup_inventory_data() {
    global $wpdb;

    $cleaned = [];

    // 1. Lösche verwaiste Metadaten
    $orphaned_count = $wpdb->query("
        DELETE pm FROM {$wpdb->postmeta} pm
        LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
        WHERE p.ID IS NULL
        AND pm.meta_key IN ('_quantity', '_price', '_lww_inventory_uid', '_boid', '_condition')
    ");
    $cleaned[] = "Verwaiste Metadaten gelöscht: {$orphaned_count}";

    // 2. Lösche Inventory-Items ohne Quantity
    $no_quantity_posts = $wpdb->get_col("
        SELECT p.ID FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_quantity'
        WHERE p.post_type = 'lww_inventory_item'
        AND p.post_status = 'publish'
        AND (pm.meta_value IS NULL OR pm.meta_value = '' OR pm.meta_value = '0')
    ");
    if (!empty($no_quantity_posts)) {
        foreach ($no_quantity_posts as $post_id) {
            wp_delete_post($post_id, true);
        }
        $cleaned[] = "Inventory-Items ohne Quantity gelöscht: " . count($no_quantity_posts);
    }

    // 3. Invalidiere Cache
    lww_invalidate_inventory_stats_cache();

    return $cleaned;
}

/**
 * Liefert globale LEGO-Katalogzahlen (Teile/Sets/Minifigs).
 * Primäre Quelle ist die Rebrickable-API (über den Sync-Job), mit Fallback auf lokale Zählung.
 */
function lww_get_global_lego_stats() {
    $stats = get_option('lww_rebrickable_global_stats');

    if (!is_array($stats)) {
        // Fallback: Zähle lokale Katalog-Posts als Näherung
        $parts_count = wp_count_posts('lww_part');
        $sets_count = wp_count_posts('lww_set');
        $minifig_count = wp_count_posts('lww_minifig');

        $stats = [
            'parts' => (int) ($parts_count->publish ?? 0),
            'sets' => (int) ($sets_count->publish ?? 0),
            'minifigs' => (int) ($minifig_count->publish ?? 0),
            'synced_at' => null,
        ];
    }

    return $stats;
}

function lww_detect_inventory_anomalies() {
    global $wpdb;
    
    $high_value_sql = "
        SELECT p.ID, p.post_title, CAST(pm_qty.meta_value AS UNSIGNED) as qty, CAST(pm_price.meta_value AS DECIMAL(10,3)) as price, (CAST(pm_qty.meta_value AS UNSIGNED) * CAST(pm_price.meta_value AS DECIMAL(10,3))) as total
        FROM {$wpdb->posts} p 
        JOIN {$wpdb->postmeta} pm_qty ON p.ID = pm_qty.post_id AND pm_qty.meta_key = '_quantity'
        JOIN {$wpdb->postmeta} pm_price ON p.ID = pm_price.post_id AND pm_price.meta_key = '_price'
        WHERE p.post_type = 'lww_inventory_item' AND p.post_status = 'publish'
        HAVING total > 500
        ORDER BY total DESC
        LIMIT 5
    ";
    $high_value_items = $wpdb->get_results($high_value_sql);

    $duplicates_sql = "
        SELECT meta_value, COUNT(post_id) as count 
        FROM {$wpdb->postmeta} 
        WHERE meta_key IN ('_boid', '_lww_bricklink_item_no') AND meta_value != ''
        GROUP BY meta_value 
        HAVING count > 1 
        ORDER BY count DESC 
        LIMIT 5
    ";
    $duplicates = $wpdb->get_results($duplicates_sql);

    return [
        'high_value' => $high_value_items,
        'duplicates' => $duplicates
    ];
}

function lww_get_sales_by_platform_data() {
    global $wpdb;
    $sql = "
        SELECT pm.meta_value as platform, COUNT(p.ID) as count, SUM(pm_total.meta_value) as value
        FROM {$wpdb->posts} p
        JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_lww_order_source'
        JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id AND pm_total.meta_key = '_lww_order_total'
        WHERE p.post_type = 'lww_order' AND p.post_status IN ('lww_completed', 'lww_shipped')
        GROUP BY pm.meta_value
    ";
    $results = $wpdb->get_results($sql);
    $labels = [];
    $values = [];
    foreach($results as $row) {
        $labels[] = ucfirst($row->platform);
        $values[] = (float)$row->value;
    }
    if(empty($labels)) { $labels = ['Keine Daten']; $values = [0]; }
    return ['labels' => $labels, 'values' => $values];
}

function lww_get_memory_limit_bytes() {
    $memory_limit = ini_get('memory_limit');
    if (preg_match('/^(\d+)(.)$/', $memory_limit, $matches)) {
        if ($matches[2] == 'M') return $matches[1] * 1024 * 1024;
        if ($matches[2] == 'K') return $matches[1] * 1024;
        if ($matches[2] == 'G') return $matches[1] * 1024 * 1024 * 1024;
    }
    return $memory_limit;
}

function lww_get_catalog_count($post_type) {
    $count = wp_count_posts($post_type);
    return (int) ($count->publish ?? 0);
}

function lww_add_cron_intervals($schedules) {
    $schedules['lww_every_30_seconds'] = ['interval' => 30, 'display' => __('Alle 30 Sekunden (LWW)', 'lego-wawi')];
    $schedules['lww_every_minute'] = ['interval' => 60, 'display' => __('Jede Minute (LWW)', 'lego-wawi')];
    $schedules['lww_every_5_minutes'] = ['interval' => 300, 'display' => __('Alle 5 Minuten (LWW)', 'lego-wawi')];
    $schedules['lww_every_15_minutes'] = ['interval' => 900, 'display' => __('Alle 15 Minuten (LWW)', 'lego-wawi')];
    return $schedules;
}
add_filter('cron_schedules', 'lww_add_cron_intervals');

function lww_check_core_data_health() {
    $colors = wp_count_posts('lww_color');
    $color_count = (int)($colors->publish ?? 0);
    $cats = wp_count_terms(['taxonomy' => 'lww_part_category', 'hide_empty' => false]);
    $cat_count = is_wp_error($cats) ? 0 : (int)$cats;
    return ['colors_ok' => $color_count > 50, 'categories_ok' => $cat_count > 10, 'color_count' => $color_count, 'cat_count' => $cat_count];
}

function lww_update_locations_from_string($post_id, $text) {
    if (empty($text)) return;
    $taxonomy = 'lww_inventory_location';
    $locations_to_assign = [];
    $clean_text = str_replace(['[', ']'], ' ', $text);
    $parts = preg_split('/[\/,;]/', $clean_text);
    foreach ($parts as $part) {
        $part = trim($part);
        if (empty($part)) continue;
        if (strlen($part) < 15 && preg_match('/[A-Za-z]/', $part) && preg_match('/[0-9]/', $part)) {
             $locations_to_assign[] = strtoupper($part);
        } 
        elseif (preg_match('/(Lager|Loc|Pos|Box):?\s*([A-Za-z0-9\-]+)/i', $part, $m)) {
             $locations_to_assign[] = strtoupper(trim($m[2]));
        }
    }
    if (empty($locations_to_assign) && strlen($text) < 10 && !ctype_digit($text)) {
        $locations_to_assign[] = strtoupper(trim($text));
    }
    if (!empty($locations_to_assign)) {
        $term_ids = [];
        foreach ($locations_to_assign as $loc_name) {
            $term = get_term_by('name', $loc_name, $taxonomy);
            if (!$term) {
                $new_term = wp_insert_term($loc_name, $taxonomy);
                if (!is_wp_error($new_term)) $term_ids[] = $new_term['term_id'];
            } else {
                $term_ids[] = $term->term_id;
            }
        }
        if (!empty($term_ids)) wp_set_object_terms($post_id, array_map('intval', $term_ids), $taxonomy, false);
    }
}
?>