<?php
/**
 * Modul: Globale Hilfsfunktionen (v13.0)
 *
 * Stellt zentralisierte, wiederverwendbare Funktionen für das gesamte Plugin bereit,
 * z.B. für Logging, Zählungen und Fehlerbehandlung.
 */
if (!defined('ABSPATH')) exit;

/**
 * =========================================================================
 * LOGGING FUNKTIONEN
 * =========================================================================
 */

/**
 * Fügt eine Nachricht zum Log eines spezifischen Jobs hinzu.
 *
 * @param int $job_id Die ID des Job-Posts.
 * @param string $message Die zu loggende Nachricht.
 */
function lww_log_to_job($job_id, $message) {
    if (empty($job_id)) return;
    $log = get_post_meta($job_id, '_job_log', true);
    if (!is_array($log)) $log = [];
    $log_entry = sprintf('[%s] %s', wp_date('H:i:s'), $message);
    $log[] = $log_entry;
    $max_log_entries = apply_filters('lww_max_job_log_entries', 200);
    if (count($log) > $max_log_entries) {
        $log = array_slice($log, -$max_log_entries);
    }
    update_post_meta($job_id, '_job_log', $log);
    // Speichere die letzte Nachricht auch separat für schnellen Zugriff in der UI
    update_post_meta($job_id, '_last_log_message', $log_entry);
}

/**
 * Schreibt eine System-Nachricht in das PHP Error Log, wenn WP_DEBUG_LOG aktiv ist.
 *
 * @param string $message Die System-Nachricht.
 */
function lww_log_system_event($message) {
    if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG === true) {
        error_log('[LWW System] ' . $message);
    }
}

/**
 * Erstellt einen neuen Log-Eintrag für einen API-Aufruf.
 *
 * @param string $service Der Name des Dienstes (z.B. 'openai', 'brickowl').
 * @param string $endpoint Der aufgerufene Endpunkt oder die Aktion.
 * @param bool $success War der Aufruf erfolgreich?
 * @param float $cost Die (simulierten) Kosten des Aufrufs.
 * @param array $details Zusätzliche Details, die als Post-Meta gespeichert werden.
 */
function lww_log_api_call($service, $endpoint, $success, $cost, $details = []) {
    $post_id = wp_insert_post([
        'post_type'    => 'lww_api_log',
        'post_title'   => sprintf('%s: %s', strtoupper($service), $endpoint),
        'post_status'  => 'publish',
        'post_content' => wp_json_encode($details, JSON_PRETTY_PRINT),
    ]);

    if ($post_id && !is_wp_error($post_id)) {
        update_post_meta($post_id, '_lww_service', $service);
        update_post_meta($post_id, '_lww_endpoint', $endpoint);
        update_post_meta($post_id, '_lww_status', $success ? 'Success' : 'Failure');
        update_post_meta($post_id, '_lww_cost', (float) $cost);
    }
}

/**
 * Protokolliert eine fehlende Referenz während eines Imports zur späteren Überprüfung.
 *
 * @param int    $job_id Die ID des Import-Jobs.
 * @param string $context Ein Kontext, z.B. der Dateiname oder der Import-Schritt.
 * @param string $reference_type Der Typ der fehlenden Referenz (z.B. 'Part Number', 'Theme ID').
 * @param string $reference_value Der Wert, der nicht gefunden wurde.
 * @param int    $line_number Die Zeilennummer in der CSV-Datei.
 */
function lww_log_unresolved_reference($job_id, $context, $reference_type, $reference_value, $line_number) {
    $log_message = sprintf(
        'WARNUNG in %s (Zeile %d): Referenz vom Typ "%s" mit Wert "%s" konnte nicht gefunden werden.',
        $context,
        $line_number,
        $reference_type,
        $reference_value
    );
    
    // Simulierter KI-Vorschlag
    $ai_suggestion = 'Kein Vorschlag';
    if (str_contains(strtolower($reference_type), 'part') || str_contains(strtolower($reference_type), 'boid')) {
        if (preg_match('/(\d+)/', $reference_value, $matches)) {
            $numeric_part = $matches[1];
            if ($numeric_part !== $reference_value) {
                 $ai_suggestion = sprintf('Möglicher Treffer für Basisteil: "%s"', $numeric_part);
            }
        }
    }

    lww_log_to_job($job_id, $log_message . ' (' . $ai_suggestion . ')');

    // Speichere die Information strukturiert im Job für eine spätere Auswertung/UI
    $unresolved = get_post_meta($job_id, '_unresolved_references', true);
    if (!is_array($unresolved)) {
        $unresolved = [];
    }

    // Eindeutigen Key generieren, um Duplikate zu vermeiden
    $unique_key = md5($context . $line_number . $reference_type . $reference_value);

    $unresolved[$unique_key] = [
        'timestamp' => time(),
        'context' => $context,
        'line' => $line_number,
        'type' => $reference_type,
        'value' => $reference_value,
        'ai_suggestion' => $ai_suggestion
    ];

    // Nur die letzten X Referenzen speichern, um die DB nicht aufzublähen
    if (count($unresolved) > 500) {
        $unresolved = array_slice($unresolved, -500, null, true);
    }

    update_post_meta($job_id, '_unresolved_references', $unresolved);
}

/**
 * =========================================================================
 * ZÄHL- & DATENFUNKTIONEN
 * =========================================================================
 */

/**
 * Zählt Einträge eines CPTs oder einer Taxonomie.
 *
 * @param string $type Post-Type-Slug oder Taxonomie-Slug.
 * @return int Anzahl der Einträge.
 */
function lww_get_catalog_count($type) {
    $count = 0;
    // Prüfen, ob es ein CPT oder eine Taxonomie ist
    if (post_type_exists($type)) {
        $data = wp_count_posts($type);
        $count = $data->publish ?? 0; // Zähle nur veröffentlichte Posts
    } elseif (taxonomy_exists($type)) {
        $count = wp_count_terms(['taxonomy' => $type, 'hide_empty' => false]);
    }
    // Gib immer eine Zahl zurück, im Fehlerfall 0
    return is_wp_error($count) ? 0 : intval($count);
}

/**
 * Holt aggregierte Statistiken über das Inventar.
 *
 * Nutzt einen Transient-Cache, um die Datenbank-Last zu reduzieren.
 * @return array Ein Array mit 'total_quantity' und 'total_value'.
 */
function lww_get_inventory_stats() {
    global $wpdb;

    // Versuche, die zwischengespeicherten Daten zu laden
    $stats = get_transient('lww_inventory_stats');

    if (false === $stats) {
        // SQL-Abfrage, um die Gesamtzahl und den Gesamtwert zu berechnen
        $query = "
            SELECT
                SUM(CAST(qty_meta.meta_value AS UNSIGNED)) as total_quantity,
                SUM(CAST(qty_meta.meta_value AS UNSIGNED) * CAST(price_meta.meta_value AS DECIMAL(10,4))) as total_value
            FROM
                {$wpdb->posts} p
            INNER JOIN
                {$wpdb->postmeta} qty_meta ON p.ID = qty_meta.post_id AND qty_meta.meta_key = '_quantity'
            INNER JOIN
                {$wpdb->postmeta} price_meta ON p.ID = price_meta.post_id AND price_meta.meta_key = '_price'
            WHERE
                p.post_type = 'lww_inventory_item'
                AND p.post_status = 'publish'
        ";

        $result = $wpdb->get_row($query, ARRAY_A);

        $stats = [
            'total_quantity' => $result['total_quantity'] ? (int) $result['total_quantity'] : 0,
            'total_value'    => $result['total_value'] ? (float) $result['total_value'] : 0.0,
        ];
        
        // Ergebnis für 1 Stunde zwischenspeichern
        set_transient('lww_inventory_stats', $stats, HOUR_IN_SECONDS);
    }
    
    return $stats;
}

/**
 * =========================================================================
 * CRON JOB & HELPER FUNKTIONEN
 * =========================================================================
 */

/**
 * Formatiert eine Dauer in Sekunden in ein lesbares Format (z.B. "1m 25s").
 *
 * @param int $seconds Dauer in Sekunden.
 * @return string Formatierte Zeichenkette.
 */
function lww_format_duration($seconds) {
    if ($seconds < 1) {
        return '0s';
    }
    $minutes = floor($seconds / 60);
    $remaining_seconds = $seconds % 60;
    $output = '';
    if ($minutes > 0) {
        $output .= $minutes . 'm ';
    }
    $output .= $remaining_seconds . 's';
    return $output;
}

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

/**
 * Plant den Cron Job.
 */
function lww_start_cron_job() {
    $hook = 'lww_main_batch_hook';
    $interval = get_option('lww_cron_interval', 'lww_every_minute');
    $schedules = wp_get_schedules();
    if (!isset($schedules[$interval])) { 
        lww_log_system_event('FEHLER: Ungültiges Cron-Intervall "' . $interval . '". Nutze "lww_every_minute".'); 
        $interval = 'lww_every_minute'; 
    }
    if (!wp_next_scheduled($hook)) {
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
    wp_clear_scheduled_hook($hook);
}

/**
 * Markiert einen Job als fehlgeschlagen.
 */
function lww_fail_job($job_id, $message) {
    if (get_post_status($job_id) !== 'lww_failed') {
        wp_update_post(['ID' => $job_id, 'post_status' => 'lww_failed']);
        lww_log_to_job($job_id, 'FEHLER: ' . $message);
        if (get_option('lww_current_running_job_id') == $job_id) { 
            delete_option('lww_current_running_job_id'); 
        }
        lww_log_system_event('Job ' . $job_id . ' fehlgeschlagen.');
    }
}

/**
 * Gibt eine lesbare Fehlermeldung für PHP Upload-Fehlercodes zurück.
 *
 * @param int $error_code Der PHP UPLOAD_ERR_* Code.
 * @return string Die übersetzte Fehlermeldung.
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