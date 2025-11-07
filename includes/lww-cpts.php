<?php
/**
 * Modul: Custom Post Types (v14.0)
 * Registriert CPTs: Teil, Set, Minifig, Farbe, Job, Inventar-Item.
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Registriert alle CPTs.
 */
function lww_register_cpts() {

    // --- CPTs (Custom Post Types) ---

    $base_args = [
        'supports' => ['title', 'editor', 'thumbnail', 'custom-fields'],
        'hierarchical' => false,
        'public' => false, // Nicht öffentlich sichtbar
        'show_ui' => true,
        'show_in_menu' => LWW_PLUGIN_SLUG, // Unter dem Hauptmenü anzeigen
        'menu_position' => 5,
        'show_in_admin_bar' => false,
        'show_in_nav_menus' => false,
        'can_export' => true,
        'has_archive' => false,
        'exclude_from_search' => true,
        'publicly_queryable' => false,
        'capability_type' => 'post',
        'rewrite' => false,
    ];

    // CPT: lww_part (LEGO Teil)
    $part_args = $base_args;
    $part_args['label'] = __('LEGO Teil', 'lego-wawi');
    $part_args['description'] = __('Katalog für LEGO-Teile (Formen/Molds)', 'lego-wawi');
    $part_args['labels'] = lww_get_cpt_labels('Teil', 'Teile');
    $part_args['menu_icon'] = 'dashicons-block-default';
    register_post_type('lww_part', $part_args);

    // CPT: lww_set (LEGO Set)
    $set_args = $base_args;
    $set_args['label'] = __('LEGO Set', 'lego-wawi');
    $set_args['description'] = __('Katalog für LEGO-Sets', 'lego-wawi');
    $set_args['labels'] = lww_get_cpt_labels('Set', 'Sets');
    $set_args['menu_icon'] = 'dashicons-store';
    register_post_type('lww_set', $set_args);

    // CPT: lww_minifig (LEGO Minifigur)
    $minifig_args = $base_args;
    $minifig_args['label'] = __('LEGO Minifigur', 'lego-wawi');
    $minifig_args['description'] = __('Katalog für LEGO-Minifiguren', 'lego-wawi');
    $minifig_args['labels'] = lww_get_cpt_labels('Minifigur', 'Minifiguren');
    $minifig_args['menu_icon'] = 'dashicons-admin-users';
    register_post_type('lww_minifig', $minifig_args);

    // CPT: lww_color (LEGO Farbe)
    $color_args = $base_args;
    $color_args['label'] = __('LEGO Farbe', 'lego-wawi');
    $color_args['description'] = __('Katalog für LEGO-Farben', 'lego-wawi');
    $color_args['labels'] = lww_get_cpt_labels('Farbe', 'Farben');
    $color_args['menu_icon'] = 'dashicons-admin-appearance';
    $color_args['supports'] = ['title', 'custom-fields']; // Keine Editor/Thumbnail
    register_post_type('lww_color', $color_args);

    // CPT: lww_job (Hintergrund-Job)
    $job_args = $base_args;
    $job_args['label'] = __('Job', 'lego-wawi');
    $job_args['description'] = __('Hintergrund-Jobs für Im- und Exporte', 'lego-wawi');
    $job_args['labels'] = lww_get_cpt_labels('Job', 'Jobs');
    $job_args['menu_icon'] = 'dashicons-admin-settings';
    $job_args['supports'] = ['title', 'custom-fields'];
    $job_args['show_in_menu'] = false; // Wird im UI-Tab angezeigt
    register_post_type('lww_job', $job_args);

    // CPT: lww_inventory_item (BrickOwl Inventar-Item)
    $inventory_args = $base_args;
    $inventory_args['label'] = __('Inventar-Eintrag', 'lego-wawi');
    $inventory_args['description'] = __('Ein einzelner Posten aus dem importierten Inventar', 'lego-wawi');
    $inventory_args['labels'] = lww_get_cpt_labels('Inventar-Eintrag', 'Inventar-Einträge');
    $inventory_args['menu_icon'] = 'dashicons-archive';
    $inventory_args['supports'] = ['title', 'custom-fields']; // Titel wird z.B. "3001 Red Used"
    $inventory_args['show_in_menu'] = LWW_PLUGIN_SLUG; // Als Untermenüpunkt
    register_post_type('lww_inventory_item', $inventory_args);

    // CPT: lww_api_log (API-Nutzungs-Log)
    $api_log_args = $base_args;
    $api_log_args['label'] = __('API-Log', 'lego-wawi');
    $api_log_args['description'] = __('Protokoll der ausgehenden API-Aufrufe.', 'lego-wawi');
    $api_log_args['labels'] = lww_get_cpt_labels('API-Log-Eintrag', 'API-Log-Einträge');
    $api_log_args['menu_icon'] = 'dashicons-cloud-upload';
    $api_log_args['supports'] = ['title', 'custom-fields', 'editor']; // Editor für Details
    $api_log_args['capabilities'] = ['create_posts' => 'do_not_allow']; // Verhindert manuelle Erstellung
    $api_log_args['map_meta_cap'] = true;
    $api_log_args['show_in_menu'] = false; // Wird über eigene UI-Seite angezeigt
    register_post_type('lww_api_log', $api_log_args);
}
add_action('init', 'lww_register_cpts', 0);


/**
 * Hilfsfunktion: Labels für CPTs.
 */
function lww_get_cpt_labels($singular, $plural) {
    // Generiert die Standard-Labels für CPTs
    return [
        'name' => _x($plural, 'Post Type General Name', 'lego-wawi'),
        'singular_name' => _x($singular, 'Post Type Singular Name', 'lego-wawi'),
        'menu_name' => __($plural, 'lego-wawi'),
        'name_admin_bar' => __($singular, 'lego-wawi'),
        'archives' => __($singular . '-Archive', 'lego-wawi'),
        'attributes' => __($singular . '-Attribute', 'lego-wawi'),
        'parent_item_colon' => __('Übergeordnet:', 'lego-wawi'),
        'all_items' => __('Alle ' . $plural, 'lego-wawi'),
        'add_new_item' => __('Neue(n) ' . $singular . ' hinzufügen', 'lego-wawi'),
        'add_new' => __('Neu hinzufügen', 'lego-wawi'),
        'new_item' => __('Neue(r) ' . $singular, 'lego-wawi'),
        'edit_item' => __($singular . ' bearbeiten', 'lego-wawi'),
        'update_item' => __($singular . ' aktualisieren', 'lego-wawi'),
        'view_item' => __($singular . ' ansehen', 'lego-wawi'),
        'view_items' => __($plural . ' ansehen', 'lego-wawi'),
        'search_items' => __($plural . ' suchen', 'lego-wawi'),
        'not_found' => __('Nicht gefunden', 'lego-wawi'),
        'not_found_in_trash' => __('Nicht im Papierkorb gefunden', 'lego-wawi'),
    ];
}
