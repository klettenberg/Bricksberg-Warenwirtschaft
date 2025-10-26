<?php
/**
 * Modul: Taxonomien (v9.0)
 * Registriert Taxonomien: Themen, Teile-Kategorien.
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Registriert alle Taxonomien.
 */
function lww_register_taxonomies() {

    // --- TAXONOMIEN (Kategorien) ---

    // TAX: lww_theme (Thema, z.B. Star Wars)
    $theme_labels = lww_get_tax_labels('Thema', 'Themen');
    $theme_args = [
        'labels'            => $theme_labels,
        'hierarchical'      => true, // Themen haben Eltern
        'public'            => false, // Nicht öffentlich
        'show_ui'           => true,
        'show_admin_column' => true, // Spalte in der Set-Übersicht anzeigen
        'show_in_nav_menus' => false,
        'show_tagcloud'     => false,
        'rewrite'           => false, // Keine eigene URL
        'show_in_menu'      => LWW_PLUGIN_SLUG, // Als Untermenüpunkt unter "LEGO WaWi"
        'query_var'         => false, // Performance: Nicht für Frontend-Queries
    ];
    register_taxonomy('lww_theme', ['lww_set'], $theme_args); // Wird mit SETS verknüpft

    // TAX: lww_part_category (Teile-Kategorie, z.B. Brick)
    $cat_labels = lww_get_tax_labels('Teile-Kategorie', 'Teile-Kategorien');
    $cat_args = [
        'labels'            => $cat_labels,
        'hierarchical'      => false, // Teile-Kategorien sind flach
        'public'            => false,
        'show_ui'           => true,
        'show_admin_column' => true, // Spalte in der Teile-Übersicht anzeigen
        'rewrite'           => false,
        'show_in_menu'      => LWW_PLUGIN_SLUG, // Als Untermenüpunkt unter "LEGO WaWi"
        'query_var'         => false,
    ];
    register_taxonomy('lww_part_category', ['lww_part'], $cat_args); // Wird mit TEILEN verknüpft
}
// Läuft nach den CPTs (standardmäßig Prio 10), aber vor dem Rest
add_action('init', 'lww_register_taxonomies', 1); // Prio 1 sicherstellen


/**
 * Hilfsfunktion: Labels für Taxonomien.
 */
if (!function_exists('lww_get_tax_labels')) {
    function lww_get_tax_labels($singular, $plural) {
        // Generiert die Standard-Labels für Taxonomien
        return [
            'name'                       => _x($plural, 'Taxonomy General Name', 'lego-wawi'),
            'singular_name'              => _x($singular, 'Taxonomy Singular Name', 'lego-wawi'),
            'menu_name'                  => __($plural, 'lego-wawi'),
            'all_items'                  => __('Alle ' . $plural, 'lego-wawi'),
            'parent_item'                => __('Übergeordnete(s) ' . $singular, 'lego-wawi'),
            'parent_item_colon'          => __('Übergeordnete(s) ' . $singular . ':', 'lego-wawi'),
            'new_item_name'              => __('Neue(r) ' . $singular . '-Name', 'lego-wawi'),
            'add_new_item'               => __('Neue(s) ' . $singular . ' hinzufügen', 'lego-wawi'),
            'edit_item'                  => __($singular . ' bearbeiten', 'lego-wawi'),
            'update_item'                => __($singular . ' aktualisieren', 'lego-wawi'),
            'view_item'                  => __($singular . ' ansehen', 'lego-wawi'),
            'separate_items_with_commas' => __($plural . ' mit Kommas trennen', 'lego-wawi'),
            'add_or_remove_items'        => __($plural . ' hinzufügen oder entfernen', 'lego-wawi'),
            'choose_from_most_used'      => __('Aus den meistgenutzten auswählen', 'lego-wawi'),
            'popular_items'              => __('Beliebte ' . $plural, 'lego-wawi'),
            'search_items'               => __($plural . ' suchen', 'lego-wawi'),
            'not_found'                  => __('Nicht gefunden', 'lego-wawi'),
            'no_terms'                   => __('Keine ' . $plural, 'lego-wawi'),
            'items_list'                 => __($plural . '-Liste', 'lego-wawi'),
            'items_list_navigation'      => __($plural . '-Listen-Navigation', 'lego-wawi'),
        ];
    }
}
?>