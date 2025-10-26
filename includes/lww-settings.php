<?php
/**
 * Modul: API-Einstellungen & Performance (v9.5)
 * Registriert die Einstellungsfelder für API-Schlüssel und Import-Performance.
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Registriert alle Einstellungsfelder.
 */
function lww_register_settings() {

    // === 1. API-Schlüssel Sektion ===
    
    register_setting(
        'lww_settings_group', // Options-Gruppe
        'lww_api_settings'    // Name der Option in wp_options (Array für alle API-Keys)
    );

    add_settings_section(
        'lww_api_section', // ID
        __('API-Schlüssel Konfiguration', 'lego-wawi'), // Titel
        function () {
            echo '<p>' . __('Trage hier die API-Schlüssel für die Marktplätze ein.', 'lego-wawi') . '</p>';
        },
        LWW_PLUGIN_SLUG // Slug der Seite
    );

    // API-Felder
    $api_keys = [
        'brickowl_api_key' => __('BrickOwl API Key', 'lego-wawi'),
        'bricklink_consumer_key' => __('BrickLink Consumer Key', 'lego-wawi'),
        'bricklink_consumer_secret' => __('BrickLink Consumer Secret', 'lego-wawi'),
        'bricklink_token_value' => __('BrickLink Token Value', 'lego-wawi'),
        'bricklink_token_secret' => __('BrickLink Token Secret', 'lego-wawi'),
        'rebrickable_api_key' => __('Rebrickable API Key', 'lego-wawi'),
        // Füge hier bei Bedarf weitere API-Keys hinzu
        // 'ebay_api_key' => __('eBay API Key (Zukunft)', 'lego-wawi'),
        // 'openai_api_key' => __('OpenAI API Key (für KI-Anreicherung)', 'lego-wawi'),
    ];

    foreach ($api_keys as $key => $label) {
        add_settings_field(
            $key, // ID des Feldes (z.B. 'brickowl_api_key')
            $label, // Angezeigter Label
            'lww_settings_field_password_callback', // Callback-Funktion zum Rendern
            LWW_PLUGIN_SLUG, // Slug der Seite
            'lww_api_section', // ID der Section
            ['key' => $key] // Argumente für den Callback (der Key im Options-Array)
        );
    }
    
    // === 2. Performance Sektion ===
    
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
        LWW_PLUGIN_SLUG // Slug der Seite
    );
    
    // Feld für Cron-Intervall
    add_settings_field(
        'lww_cron_interval',
        __('Cron-Job Intervall', 'lego-wawi'),
        'lww_settings_field_cron_select_callback', // Neue Callback-Funktion
        LWW_PLUGIN_SLUG,
        'lww_performance_section'
    );
    
    // Feld für Katalog Batch-Größe
    add_settings_field(
        'lww_catalog_batch_size',
        __('Katalog Batch-Größe', 'lego-wawi'),
        'lww_settings_field_number_callback', // Neue Callback-Funktion
        LWW_PLUGIN_SLUG,
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
        LWW_PLUGIN_SLUG,
        'lww_performance_section',
        [
            'key' => 'lww_inventory_batch_size', // Name der Option
            'default' => 300,
            'desc' => __('Zeilen, die pro Durchlauf (Inventar) verarbeitet werden.', 'lego-wawi')
        ]
    );
}
add_action('admin_init', 'lww_register_settings');


/**
 * Callback für API-Schlüssel (Passwort-Felder).
 */
function lww_settings_field_password_callback($args) {
    // Hole das Array mit allen API-Schlüsseln
    $options = get_option('lww_api_settings');
    $key = $args['key'];
    // Hole den Wert für diesen spezifischen Schlüssel oder setze einen leeren String
    $value = isset($options[$key]) ? esc_attr($options[$key]) : '';
    // Gib das HTML für das Input-Feld aus
    printf(
        // Wichtig: Der Name muss 'lww_api_settings[KEY]' sein, damit WP es als Array speichert
        '<input type="password" id="%1$s" name="lww_api_settings[%1$s]" value="%2$s" class="regular-text" placeholder="%3$s" />',
        esc_attr($key), // id und der Key im Array-Namen
        $value, // value Attribut
        __('API-Schlüssel hier einfügen', 'lego-wawi') // placeholder Text
    );
}

/**
 * Callback für Nummern-Felder (Batch-Größe).
 */
function lww_settings_field_number_callback($args) {
    $key = $args['key'];
    $default = $args['default'] ?? 100;
    $desc = $args['desc'] ?? '';
    
    // Hole den Wert der einzelnen Option
    $value = get_option($key, $default);
    
    printf(
        // Name ist hier direkt der Options-Name
        '<input type="number" id="%1$s" name="%1$s" value="%2$d" class="small-text" min="50" step="50" />',
        esc_attr($key), // id und name Attribut
        absint($value)  // Stellt sicher, dass es eine positive Ganzzahl ist
    );
    if ($desc) {
        printf('<p class="description">%s</p>', esc_html($desc));
    }
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

?>
