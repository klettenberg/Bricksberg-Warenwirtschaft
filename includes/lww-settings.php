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
function lww_register_settings() {
    register_setting(
        'lww_settings_group', // Options-Gruppe
        'lww_api_settings' // Name der Option in wp_options
    );

    add_settings_section(
        'lww_api_section', // ID
        __('API-Schlüssel Konfiguration', 'lego-wawi'), // Titel
        function () {
            echo '<p>' . __('Trage hier die API-Schlüssel für die Marktplätze ein.', 'lego-wawi') . '</p>';
        },
        LWW_PLUGIN_SLUG // Slug der Seite, auf der die Section angezeigt wird
    );

    // Felder definieren
    $api_keys = [
        'brickowl_api_key' => __('BrickOwl API Key', 'lego-wawi'),
        'bricklink_consumer_key' => __('BrickLink Consumer Key', 'lego-wawi'),
        'bricklink_consumer_secret' => __('BrickLink Consumer Secret', 'lego-wawi'),
        'bricklink_token_value' => __('BrickLink Token Value', 'lego-wawi'),
        'bricklink_token_secret' => __('BrickLink Token Secret', 'lego-wawi'),
        'rebrickable_api_key' => __('Rebrickable API Key', 'lego-wawi'),
        'ebay_api_key' => __('eBay API Key (Zukunft)', 'lego-wawi'),
        'openai_api_key' => __('OpenAI API Key (für KI-Anreicherung)', 'lego-wawi'),
    ];

    // Felder registrieren
    foreach ($api_keys as $key => $label) {
        add_settings_field(
            $key, // ID des Feldes
            $label, // Angezeigter Label
            'lww_settings_field_callback', // Callback-Funktion zum Rendern
            LWW_PLUGIN_SLUG, // Slug der Seite
            'lww_api_section', // ID der Section
            ['key' => $key] // Argumente für den Callback
        );
    }
}
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