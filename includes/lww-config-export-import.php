<?php
/**
 * Modul: Konfiguration Export/Import
 * Stellt Funktionen zum Sichern und Wiederherstellen der Plugin-Einstellungen bereit.
 */
if (!defined('ABSPATH')) exit;

add_action('admin_post_lww_config_export', 'lww_handle_config_export');
add_action('admin_post_lww_config_import', 'lww_handle_config_import');

/**
 * Sammelt alle Plugin-Einstellungen, verschlüsselt sie und bietet sie als Download an.
 */
function lww_handle_config_export() {
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'lww_config_export_nonce')) {
        wp_die(__('Sicherheitsüberprüfung fehlgeschlagen.', 'lego-wawi'));
    }
    if (!current_user_can('manage_options')) {
        wp_die(__('Keine Berechtigung.', 'lego-wawi'));
    }

    // 1. Alle relevanten Optionen sammeln
    $options_to_export = [
        'lww_api_settings',
        'lww_ai_provider',
        'lww_cron_interval',
        'lww_catalog_batch_size',
        'lww_inventory_batch_size',
        'lww_api_batch_size_free',
        'lww_api_batch_size_paid',
    ];

    // Alle Job-Prioritäten dynamisch sammeln
    global $wpdb;
    $priority_options = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'lww_job_priority_%'");
    $options_to_export = array_merge($options_to_export, $priority_options);

    $settings_data = [];
    foreach ($options_to_export as $option_name) {
        $settings_data[$option_name] = get_option($option_name);
    }

    // 2. Daten verschlüsseln
    $data_json = wp_json_encode($settings_data);
    $encryption_key = wp_generate_password(32, true, true);
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $encrypted_data = openssl_encrypt($data_json, 'aes-256-cbc', $encryption_key, 0, $iv);

    // 3. Export-Struktur vorbereiten
    $export_data = [
        'version' => LWW_PLUGIN_VERSION,
        'encrypted_data' => base64_encode($encrypted_data),
        'iv' => base64_encode($iv),
    ];

    // 4. Download erzwingen
    $filename = 'bricksberg-wawi-config-' . date('Y-m-d') . '.json';
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    // Speichere den Schlüssel in einem Transient, um ihn nach dem Redirect anzuzeigen
    set_transient('lww_config_export_key', $encryption_key, MINUTE_IN_SECONDS * 5);

    echo wp_json_encode($export_data, JSON_PRETTY_PRINT);
    exit;
}

/**
 * Verarbeitet den Upload einer Konfigurationsdatei, entschlüsselt sie und stellt die Einstellungen wieder her.
 */
function lww_handle_config_import() {
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'lww_config_import_nonce')) {
        wp_die(__('Sicherheitsüberprüfung fehlgeschlagen.', 'lego-wawi'));
    }
    if (!current_user_can('manage_options')) {
        wp_die(__('Keine Berechtigung.', 'lego-wawi'));
    }

    $redirect_url = wp_get_referer() ?: admin_url('admin.php?page=lww_tools_ui');

    if (!isset($_FILES['lww_config_file']) || $_FILES['lww_config_file']['error'] !== UPLOAD_ERR_OK) {
        add_settings_error('lww_messages', 'import_file_error', __('Fehler beim Upload der Konfigurationsdatei.', 'lego-wawi'), 'error');
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_safe_redirect($redirect_url);
        exit;
    }

    $encryption_key = sanitize_text_field($_POST['lww_encryption_key'] ?? '');
    if (empty($encryption_key)) {
        add_settings_error('lww_messages', 'import_key_missing', __('Der Verschlüsselungsschlüssel wurde nicht angegeben.', 'lego-wawi'), 'error');
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_safe_redirect($redirect_url);
        exit;
    }

    $file_content = file_get_contents($_FILES['lww_config_file']['tmp_name']);
    $import_data = json_decode($file_content, true);

    if (json_last_error() !== JSON_ERROR_NONE || !isset($import_data['encrypted_data']) || !isset($import_data['iv'])) {
        add_settings_error('lww_messages', 'import_file_invalid', __('Die Konfigurationsdatei hat ein ungültiges Format.', 'lego-wawi'), 'error');
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_safe_redirect($redirect_url);
        exit;
    }

    $encrypted_data = base64_decode($import_data['encrypted_data']);
    $iv = base64_decode($import_data['iv']);

    $decrypted_json = openssl_decrypt($encrypted_data, 'aes-256-cbc', $encryption_key, 0, $iv);

    if ($decrypted_json === false) {
        add_settings_error('lww_messages', 'import_decryption_failed', __('Entschlüsselung fehlgeschlagen. Der Schlüssel ist falsch oder die Datei ist beschädigt.', 'lego-wawi'), 'error');
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_safe_redirect($redirect_url);
        exit;
    }

    $settings_data = json_decode($decrypted_json, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($settings_data)) {
        add_settings_error('lww_messages', 'import_json_invalid', __('Die entschlüsselten Daten sind kein gültiges JSON.', 'lego-wawi'), 'error');
        set_transient('settings_errors', get_settings_errors(), 30);
        wp_safe_redirect($redirect_url);
        exit;
    }

    // Alle Einstellungen aktualisieren
    foreach ($settings_data as $option_name => $option_value) {
        update_option($option_name, $option_value);
    }

    // Nach dem Import den Cron-Job neu starten, um das Intervall zu übernehmen
    if (function_exists('lww_start_cron_job')) {
        lww_start_cron_job();
    }

    add_settings_error('lww_messages', 'import_success', __('Alle Einstellungen wurden erfolgreich importiert.', 'lego-wawi'), 'success');
    set_transient('settings_errors', get_settings_errors(), 30);
    wp_safe_redirect($redirect_url);
    exit;
}
?>