<?php
/**
 * Modul: API-Einstellungen & Performance (v13.0)
 * Registriert die Einstellungsfelder für API-Schlüssel und Import-Performance.
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Registriert alle Einstellungsfelder.
 */
function lww_register_settings() {

    $settings_page_slug = 'lww_settings_ui';

    // === 1. API-Schlüssel Sektion ===
    
    register_setting(
        'lww_settings_group', // Options-Gruppe
        'lww_api_settings'    // Name der Option in wp_options (Array für alle API-Keys)
    );

    add_settings_section(
        'lww_api_section', // ID
        __('API-Schlüssel Konfiguration', 'lego-wawi'), // Titel
        function () {
            echo '<p>' . __('Trage hier die API-Schlüssel für die Marktplätze und KI-Dienste ein. Detaillierte Anleitungen findest du unter jedem Feld.', 'lego-wawi') . '</p>';
        },
        $settings_page_slug // Slug der Seite
    );

    // API-Felder
    $api_keys = [
        'brickowl_api_key' => __('BrickOwl API Key', 'lego-wawi'),
        'bricklink_consumer_key' => __('BrickLink Consumer Key', 'lego-wawi'),
        'bricklink_consumer_secret' => __('BrickLink Consumer Secret', 'lego-wawi'),
        'bricklink_token_value' => __('BrickLink Token Value', 'lego-wawi'),
        'bricklink_token_secret' => __('BrickLink Token Secret', 'lego-wawi'),
        'rebrickable_api_key' => __('Rebrickable API Key', 'lego-wawi'),
        'openai_api_key' => __('OpenAI API Key', 'lego-wawi'),
        'gemini_api_key' => __('Google Gemini API Key', 'lego-wawi'),
        'ebay_app_id' => __('eBay App ID', 'lego-wawi'), // Neu
        'ebay_dev_id' => __('eBay Dev ID', 'lego-wawi'), // Neu
        'ebay_cert_id' => __('eBay Cert ID', 'lego-wawi'), // Neu
        'ebay_auth_token' => __('eBay Auth Token', 'lego-wawi'), // Neu
    ];

    foreach ($api_keys as $key => $label) {
        add_settings_field(
            $key, // ID des Feldes (z.B. 'brickowl_api_key')
            $label, // Angezeigter Label
            'lww_settings_field_password_callback', // Callback-Funktion zum Rendern
            $settings_page_slug, // Slug der Seite
            'lww_api_section', // ID der Section
            ['key' => $key] // Argumente für den Callback (der Key im Options-Array)
        );
    }

    // === 2. KI-Anbieter Sektion ===
    register_setting('lww_settings_group', 'lww_ai_provider', ['type' => 'string', 'sanitize_callback' => 'sanitize_key', 'default' => 'openai']);

    add_settings_section(
        'lww_ai_provider_section',
        __('KI-Konfiguration', 'lego-wawi'),
        function () {
            echo '<p>' . __('Wähle den KI-Anbieter, der für die Datenanreicherung (z.B. Nachfrageanalyse) verwendet werden soll.', 'lego-wawi') . '</p>';
        },
        $settings_page_slug
    );

    add_settings_field(
        'lww_ai_provider',
        __('Bevorzugter KI-Anbieter', 'lego-wawi'),
        'lww_settings_field_ai_provider_select_callback',
        $settings_page_slug,
        'lww_ai_provider_section'
    );
    
    // === 3. Performance Sektion ===
    
    // Registriere jede Performance-Option einzeln (einfacher zu verwalten)
    register_setting('lww_settings_group', 'lww_cron_interval', ['type' => 'string', 'sanitize_callback' => 'sanitize_key', 'default' => 'lww_every_minute']);
    register_setting('lww_settings_group', 'lww_catalog_batch_size', ['type' => 'number', 'sanitize_callback' => 'absint', 'default' => 200]);
    register_setting('lww_settings_group', 'lww_inventory_batch_size', ['type' => 'number', 'sanitize_callback' => 'absint', 'default' => 300]);

    add_settings_section(
        'lww_performance_section', // ID
        __('Import & Performance', 'lego-wawi'), // Titel
        function () {
            echo '<p>' . __('Steuere hier die Server-Auslastung durch die Import-Prozesse.', 'lego-wawi') . '</p>';
        },
        $settings_page_slug // Slug der Seite
    );
    
    // Feld für Cron-Intervall
    add_settings_field(
        'lww_cron_interval',
        __('Cron-Job Intervall', 'lego-wawi'),
        'lww_settings_field_cron_select_callback', // Neue Callback-Funktion
        $settings_page_slug,
        'lww_performance_section'
    );
    
    // Feld für Katalog Batch-Größe
    add_settings_field(
        'lww_catalog_batch_size',
        __('Katalog Batch-Größe', 'lego-wawi'),
        'lww_settings_field_number_callback', // Neue Callback-Funktion
        $settings_page_slug,
        'lww_performance_section',
        [
            'key' => 'lww_catalog_batch_size', // Name der Option
            'default' => 200,
            'desc' => __('Zeilen, die pro Durchlauf (Katalog) verarbeitet werden. Geringere Zahl = Geringere Serverlast, längere Importzeit.', 'lego-wawi')
        ]
    );
    
    // Feld für Inventar Batch-Größe
    add_settings_field(
        'lww_inventory_batch_size',
        __('Inventar Batch-Größe', 'lego-wawi'),
        'lww_settings_field_number_callback', // Wiederverwendete Callback-Funktion
        $settings_page_slug,
        'lww_performance_section',
        [
            'key' => 'lww_inventory_batch_size', // Name der Option
            'default' => 300,
            'desc' => __('Zeilen, die pro Durchlauf (Inventar) verarbeitet werden.', 'lego-wawi')
        ]
    );

    // === 4. Job-Prioritäten Sektion ===
    add_settings_section(
        'lww_job_priorities_section',
        __('Job-Prioritäten', 'lego-wawi'),
        function () {
            echo '<p>' . __('Lege die Priorität für verschiedene Hintergrund-Jobs fest. Eine niedrigere Zahl bedeutet eine höhere Priorität (z.B. 0 = höchste, 20 = niedriger).', 'lego-wawi') . '</p>';
        },
        $settings_page_slug
    );

    $job_priorities = [
        'data_purge' => ['label' => __('Datenbereinigung', 'lego-wawi'), 'default' => 0],
        'inventory_backup_import' => ['label' => __('Inventar-Backup Import', 'lego-wawi'), 'default' => 5],
        'bricklink_inventory_import' => ['label' => __('BrickLink Inventar-Import', 'lego-wawi'), 'default' => 5],
        'catalog_import' => ['label' => __('Katalog-Import', 'lego-wawi'), 'default' => 10],
        'inventory_import' => ['label' => __('Inventar-Import', 'lego-wawi'), 'default' => 10],
        'location_sync' => ['label' => __('Lagerort-Synchronisation', 'lego-wawi'), 'default' => 10],
        'demand_analysis' => ['label' => __('Nachfrageanalyse (KI)', 'lego-wawi'), 'default' => 15],
        'ebay_sync' => ['label' => __('eBay Inventar Sync', 'lego-wawi'), 'default' => 15],
        'brickowl_inventory_sync' => ['label' => __('BrickOwl Bestandsabgleich', 'lego-wawi'), 'default' => 15],
        'description_generation' => ['label' => __('Beschreibungserstellung (KI)', 'lego-wawi'), 'default' => 20],
        'brickowl_price_sync' => ['label' => __('BrickOwl Preis-Sync', 'lego-wawi'), 'default' => 20],
        'data_validation' => ['label' => __('Daten-Validierung', 'lego-wawi'), 'default' => 25],
        'brickowl_catalog_enrichment' => ['label' => __('BrickOwl Katalog-Anreicherung', 'lego-wawi'), 'default' => 25],
    ];

    foreach ($job_priorities as $key => $details) {
        $option_name = 'lww_job_priority_' . $key;
        register_setting('lww_settings_group', $option_name, ['type' => 'number', 'sanitize_callback' => 'absint', 'default' => $details['default']]);
        add_settings_field(
            $option_name,
            $details['label'],
            'lww_settings_field_priority_number_callback',
            $settings_page_slug,
            'lww_job_priorities_section',
            ['key' => $option_name, 'default' => $details['default']]
        );
    }
}
add_action('admin_init', 'lww_register_settings');


/**
 * Callback für API-Schlüssel (Passwort-Felder).
 */
function lww_settings_field_password_callback($args) {
    $options = get_option('lww_api_settings');
    $key = $args['key'];
    $value = isset($options[$key]) ? esc_attr($options[$key]) : '';

    // Service-Namen für den Test-Button extrahieren (z.B. 'openai_api_key' -> 'openai')
    $service = str_replace(['_api_key', '_consumer_key', '_app_id'], '', $key);
    
    // Zeige das Input-Feld an
    printf(
        '<input type="password" id="%1$s" name="lww_api_settings[%1$s]" value="%2$s" class="regular-text" placeholder="%3$s" />',
        esc_attr($key),
        $value,
        __('API-Schlüssel hier einfügen', 'lego-wawi')
    );

    // Zeige den Test-Button für relevante Dienste an
    $testable_services = ['brickowl', 'rebrickable', 'openai', 'gemini', 'ebay'];
    if (in_array($service, $testable_services)) {
        printf(
            ' <button type="button" class="button button-secondary lww-test-api-connection" data-service="%1$s">%2$s</button>',
            esc_attr($service),
            __('Verbindung testen', 'lego-wawi')
        );
        printf('<span class="lww-api-test-result"></span>');
    }

    // Füge Hilfetexte hinzu
    $help_text = '';
    switch ($key) {
        case 'brickowl_api_key':
            $help_text = sprintf(
                __('Diesen Schlüssel findest du in deinem BrickOwl-Account unter %s.', 'lego-wawi'),
                '<a href="https://www.brickowl.com/user/api" target="_blank">Settings &rarr; API</a>'
            );
            break;
        case 'bricklink_consumer_key':
        case 'bricklink_consumer_secret':
        case 'bricklink_token_value':
        case 'bricklink_token_secret':
            $help_text = sprintf(
                __('Diese vier Werte erhältst du auf BrickLink unter %s. Du musst eine neue Applikation registrieren, um Consumer Key/Secret zu erhalten, und diese dann authorisieren, um Token Value/Secret zu generieren.', 'lego-wawi'),
                '<a href="https://www.bricklink.com/v2/api/register_consumer.page" target="_blank">API Access</a>'
            );
            break;
        case 'rebrickable_api_key':
            $help_text = sprintf(
                __('Deinen API-Key findest du in deinem Rebrickable-Profil unter %s.', 'lego-wawi'),
                '<a href="https://rebrickable.com/account/api/" target="_blank">Account &rarr; API</a>'
            );
            break;
        case 'openai_api_key':
            $help_text = sprintf(
                __('Melde dich bei %s an, navigiere zu %s und erstelle einen neuen "Secret Key".', 'lego-wawi'),
                '<a href="https://platform.openai.com/" target="_blank">platform.openai.com</a>',
                '<a href="https://platform.openai.com/api-keys" target="_blank">API Keys</a>'
            );
            break;
        case 'gemini_api_key':
            $help_text = sprintf(
                __('Diesen Schlüssel erhältst du über das %s. Klicke dort auf "Get API key".', 'lego-wawi'),
                '<a href="https://aistudio.google.com/app/apikey" target="_blank">Google AI Studio</a>'
            );
            break;
        case 'ebay_app_id':
        case 'ebay_dev_id':
        case 'ebay_cert_id':
        case 'ebay_auth_token':
            $help_text = sprintf(
                __('Diese Werte erhältst du im %s. Erstelle eine neue Applikation, um deine App/Dev/Cert IDs zu erhalten. Der %s muss anschließend über einen User-Consent-Flow generiert werden.', 'lego-wawi'),
                '<a href="https://developer.ebay.com/" target="_blank">eBay Developers Program</a>',
                '<strong>' . __('eBay Auth Token', 'lego-wawi') . '</strong>'
            );
            break;
    }

    if ($help_text) {
        printf('<p class="description">%s</p>', $help_text);
    }
}

/**
 * Callback für Nummern-Felder (Batch-Größe).
 */
function lww_settings_field_number_callback($args) {
    $key = $args['key'];
    $default = $args['default'] ?? 100;
    $desc = $args['desc'] ?? '';
    
    $value = get_option($key, $default);
    
    printf(
        '<input type="number" id="%1$s" name="%1$s" value="%2$d" class="small-text" min="50" step="50" />',
        esc_attr($key),
        absint($value)
    );
    if ($desc) {
        printf('<p class="description">%s</p>', esc_html($desc));
    }
}

/**
 * Callback für Nummern-Felder (Prioritäten).
 */
function lww_settings_field_priority_number_callback($args) {
    $key = $args['key'];
    $default = $args['default'] ?? 10;
    $value = get_option($key, $default);
    
    printf(
        '<input type="number" id="%1$s" name="%1$s" value="%2$d" class="small-text" min="0" step="1" />',
        esc_attr($key),
        absint($value)
    );
}


/**
 * Callback für KI-Anbieter (Dropdown).
 */
function lww_settings_field_ai_provider_select_callback() {
    $current_value = get_option('lww_ai_provider', 'openai');
    $providers = [
        'openai' => 'OpenAI',
        'gemini' => 'Google Gemini',
    ];

    echo '<select id="lww_ai_provider" name="lww_ai_provider">';
    foreach ($providers as $key => $label) {
        printf(
            '<option value="%s" %s>%s</option>',
            esc_attr($key),
            selected($current_value, $key, false),
            esc_html($label)
        );
    }
    echo '</select>';
    echo '<p class="description">' . __('Der hier ausgewählte Dienst benötigt einen gültigen API-Schlüssel oben.', 'lego-wawi') . '</p>';
}

/**
 * Callback für Cron-Intervall (Dropdown).
 */
function lww_settings_field_cron_select_callback() {
    $current_value = get_option('lww_cron_interval', 'lww_every_minute');
    $schedules = wp_get_schedules();
    
    echo '<select id="lww_cron_interval" name="lww_cron_interval">';
    
    $allowed_schedules = ['lww_every_minute', 'lww_every_5_minutes', 'lww_every_15_minutes'];
    
    foreach ($schedules as $key => $details) {
        if (in_array($key, $allowed_schedules)) {
            printf(
                '<option value="%s" %s>%s (%s)</option>',
                esc_attr($key),
                selected($current_value, $key, false),
                esc_html($details['display']),
                sprintf(esc_html__('%d Sek.', 'lego-wawi'), $details['interval'])
            );
        }
    }
    echo '</select>';
    echo '<p class="description">' . __('Wie oft der Server nach neuen Jobs suchen soll. "Jede Minute" wird empfohlen, außer bei sehr schwachen Servern.', 'lego-wawi') . '</p>';
}

/**
 * AJAX Handler für API-Verbindungstests.
 */
function lww_ajax_test_api_connection_handler() {
    check_ajax_referer('lww_settings_ajax_nonce', '_ajax_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Fehlende Berechtigung.', 'lego-wawi')], 403);
    }

    $service = isset($_POST['service']) ? sanitize_key($_POST['service']) : '';
    $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';

    if (empty($service) || empty($api_key)) {
        wp_send_json_error(['message' => __('Dienst oder API-Schlüssel fehlt.', 'lego-wawi')], 400);
    }

    // SIMULATION: Hier würden die echten API-Calls stattfinden.
    // Wir simulieren eine Erfolgs- oder Fehlermeldung.
    $is_success = (strlen($api_key) > 10 && strpos($api_key, 'test_fail') === false); 

    // Logge den Versuch (ohne den Schlüssel)
    lww_log_api_call($service, 'connection_test', $is_success, 0.00, ['status' => $is_success ? 'Success' : 'Failure']);

    if ($is_success) {
        wp_send_json_success(['message' => __('Verbindung erfolgreich!', 'lego-wawi')]);
    } else {
        wp_send_json_error(['message' => __('Verbindung fehlgeschlagen. Bitte prüfe den Schlüssel.', 'lego-wawi')], 401);
    }
}
add_action('wp_ajax_lww_test_api_connection', 'lww_ajax_test_api_connection_handler');
