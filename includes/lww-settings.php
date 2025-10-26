<?php
/**
 * Modul: API-Einstellungen (v9.0 - Unverändert von v8.0)
 * Registriert die Einstellungsfelder für die API-Schlüssel.
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Registriert die Einstellungsfelder.
 */
 // NEU:
/**
 * Registriert alle Einstellungsfelder.
 */
function lww_register_settings() {

    // === 1. API-Schlüssel Sektion ===

    register_setting(
        'lww_settings_group', // Options-Gruppe
        'lww_api_settings'    // Name der Option in wp_options
    );

    add_settings_section(
        'lww_api_section', // ID
        __('API-Schlüssel Konfiguration', 'lego-wawi'), // Titel
        function () {
            echo '<p>' . __('Trage hier die API-Schlüssel für die Marktplätze ein.', 'lego-wawi') . '</p>';
        },
        LWW_PLUGIN_SLUG // Slug der Seite
    );

    // API-Felder (wie bisher)
    $api_keys = [
        'brickowl_api_key' => __('BrickOwl API Key', 'lego-wawi'),
        'bricklink_consumer_key' => __('BrickLink Consumer Key', 'lego-wawi'),
        // ... (du kannst hier alle deine API-Keys wie vorher auflisten) ...
        'rebrickable_api_key' => __('Rebrickable API Key', 'lego-wawi'),
    ];

    foreach ($api_keys as $key => $label) {
        add_settings_field(
            $key, $label,
            'lww_settings_field_password_callback', // Wichtig: Umbenannt von 'lww_settings_field_callback'
            LWW_PLUGIN_SLUG, 'lww_api_section',
            ['key' => $key]
        );
    }

    // === 2. Performance Sektion ===

    // Registriere jede Option einzeln (einfacher zu verwalten)
    register_setting('lww_settings_group', 'lww_cron_interval', ['type' => 'string', 'default' => 'lww_every_minute']);
    register_setting('lww_settings_group', 'lww_catalog_batch_size', ['type' => 'number', 'default' => 200]);
    register_setting('lww_settings_group', 'lww_inventory_batch_size', ['type' => 'number', 'default' => 300]);

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
            'desc' => __('Zeilen, die pro Durchlauf (Katalog) verarbeitet werden. Geringere Zahl = Geringere Serverlast.', 'lego-wawi')
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

add_action('admin_init', 'lww_register_settings');

/**
 * Callback-Funktion zum Rendern eines Einstellungsfeldes.
 * Wird für jedes Feld aufgerufen, das mit add_settings_field registriert wurde.
 */
function lww_settings_field_callback($args) {
    // Hole die gespeicherten Optionen aus der Datenbank
    $options = get_option('lww_api_settings');
    $key = $args['key'];
    // Hole den Wert für dieses spezifische Feld oder setze einen leeren String
    $value = isset($options[$key]) ? esc_attr($options[$key]) : '';
    // Gib das HTML für das Input-Feld aus
    printf(
        '<input type="password" id="%1$s" name="lww_api_settings[%1$s]" value="%2$s" class="regular-text" placeholder="%3$s" />',
        $key, // id und name Attribut
        $value, // value Attribut
        __('API-Schlüssel hier einfügen', 'lego-wawi') // placeholder Text
    );
    // Optional: Beschreibung hinzufügen
    // echo '<p class="description">' . __('Beschreibung für dieses Feld.', 'lego-wawi') . '</p>';
}
/**
 * ALT: Callback für API-Schlüssel (jetzt umbenannt)
 */
function lww_settings_field_password_callback($args) {
    $options = get_option('lww_api_settings');
    $key = $args['key'];
    $value = isset($options[$key]) ? esc_attr($options[$key]) : '';
    printf(
        '<input type="password" id="%1$s" name="lww_api_settings[%1$s]" value="%2$s" class="regular-text" placeholder="%3$s" />',
        $key,
        $value,
        __('API-Schlüssel hier einfügen', 'lego-wawi')
    );
}

/**
 * NEU: Callback für Nummern-Felder (Batch-Größe)
 */
function lww_settings_field_number_callback($args) {
    $key = $args['key'];
    $default = $args['default'] ?? 100;
    $desc = $args['desc'] ?? '';

    $value = get_option($key, $default);

    printf(
        '<input type="number" id="%1$s" name="%1$s" value="%2$s" class="small-text" min="50" step="50" />',
        esc_attr($key),
        esc_attr($value)
    );
    if ($desc) {
        printf('<p class="description">%s</p>', esc_html($desc));
    }
}

/**
 * NEU: Callback für Cron-Intervall (Dropdown)
 */
function lww_settings_field_cron_select_callback() {
    $current_value = get_option('lww_cron_interval', 'lww_every_minute');
    // Hole alle registrierten WP-Cron-Intervalle
    $schedules = wp_get_schedules();

    echo '<select id="lww_cron_interval" name="lww_cron_interval">';

    // Filtere nur die, die wir anzeigen wollen (unsere LWW-Intervalle)
    $allowed_schedules = ['lww_every_minute', 'lww_every_5_minutes', 'lww_every_15_minutes'];

    foreach ($schedules as $key => $details) {
        if (in_array($key, $allowed_schedules)) {
            printf(
                '<option value="%s" %s>%s (%s)</option>',
                esc_attr($key),
                selected($current_value, $key, false), // Markiert die gespeicherte Option
                esc_html($details['display']),
                sprintf(esc_html__('%d Sek.', 'lego-wawi'), $details['interval'])
            );
        }
    }
    echo '</select>';
     echo '<p class="description">' . __('Wie oft der Server nach neuen Jobs suchen soll. "Jede Minute" wird empfohlen.', 'lego-wawi') . '</p>';
}
