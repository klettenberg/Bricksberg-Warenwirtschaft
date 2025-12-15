<?php
/**
 * Modul: Taxonomien (v21.4)
 * Registriert Taxonomien und fügt Bild-Felder hinzu.
 * 
 * UPDATE: Labels für Jahreszahlen auf 'Erscheinungsjahr' präzisiert.
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Registriert alle Taxonomien.
 */
function lww_register_taxonomies() {

    // --- TAXONOMIEN (Kategorien) ---

    $public_tax_args = [
        'hierarchical'      => true,
        'public'            => true, // Macht die Taxonomie öffentlich
        'show_ui'           => true,
        'show_admin_column' => true,
        'show_in_nav_menus' => true, // Erlaubt die Aufnahme in Navigationsmenüs
        'show_tagcloud'     => false,
        'query_var'         => true, // Erlaubt Abfragen wie ?theme=star-wars
        'show_in_menu'      => false, // Wird manuell in lww-admin-page.php hinzugefügt
    ];

    // TAX: lww_theme (Thema, z.B. Star Wars)
    $theme_labels = lww_get_tax_labels('Thema', 'Themen');
    $theme_args = $public_tax_args;
    $theme_args['labels'] = $theme_labels;
    $theme_args['rewrite'] = ['slug' => 'theme', 'with_front' => false, 'hierarchical' => true];
    register_taxonomy('lww_theme', ['lww_set'], $theme_args);

    // TAX: lww_part_category (Teile-Kategorie, z.B. Brick)
    $cat_labels = lww_get_tax_labels('Teile-Kategorie', 'Teile-Kategorien');
    $cat_args = $public_tax_args;
    $cat_args['labels'] = $cat_labels;
    $cat_args['hierarchical'] = true;
    $cat_args['rewrite'] = ['slug' => 'part-category', 'with_front' => false, 'hierarchical' => true];
    register_taxonomy('lww_part_category', ['lww_part'], $cat_args);

    // TAX: lww_year (Erscheinungsjahr)
    // UPDATE: Eindeutigere Benennung
    $year_labels = lww_get_tax_labels('Erscheinungsjahr', 'Erscheinungsjahre');
    $year_args = $public_tax_args;
    $year_args['labels'] = $year_labels;
    $year_args['hierarchical'] = false;
    $year_args['sort'] = true;
    $year_args['show_in_menu'] = false; 
    $year_args['rewrite'] = ['slug' => 'year', 'with_front' => false];
    register_taxonomy('lww_year', ['lww_set', 'lww_minifig'], $year_args);

    // Private Taxonomien (Lagerort)
    $private_tax_args = [
        'hierarchical'      => false,
        'public'            => false,
        'show_ui'           => true,
        'show_admin_column' => true,
        'rewrite'           => false,
        'show_in_menu'      => false,
        'query_var'         => true,
        'show_in_nav_menus' => false,
        'show_tagcloud'     => false,
    ];

    $loc_labels = lww_get_tax_labels('Lagerort', 'Lagerorte');
    $loc_args = $private_tax_args;
    $loc_args['labels'] = $loc_labels;
    register_taxonomy('lww_inventory_location', ['lww_inventory_item'], $loc_args);
}
add_action('init', 'lww_register_taxonomies', 1);

// --- Bild-Felder für Taxonomien ---

// 1. Felder anzeigen beim Erstellen
function lww_taxonomy_add_image_field() {
    ?>
    <div class="form-field term-image-wrap">
        <label for="lww_term_image"><?php _e('Bild', 'lego-wawi'); ?></label>
        <input type="hidden" id="lww_term_image_id" name="lww_term_image_id" value="">
        <div id="lww_term_image_preview" style="margin-bottom:10px;"></div>
        <button type="button" class="button lww-upload-term-image"><?php _e('Bild hochladen', 'lego-wawi'); ?></button>
        <button type="button" class="button lww-remove-term-image" style="display:none;"><?php _e('Entfernen', 'lego-wawi'); ?></button>
    </div>
    <?php
}
add_action('lww_theme_add_form_fields', 'lww_taxonomy_add_image_field');
add_action('lww_part_category_add_form_fields', 'lww_taxonomy_add_image_field');

// 2. Felder anzeigen beim Bearbeiten
function lww_taxonomy_edit_image_field($term) {
    $image_id = get_term_meta($term->term_id, '_lww_term_image_id', true);
    $image_url = $image_id ? wp_get_attachment_thumb_url($image_id) : '';
    ?>
    <tr class="form-field term-image-wrap">
        <th scope="row"><label for="lww_term_image"><?php _e('Bild', 'lego-wawi'); ?></label></th>
        <td>
            <input type="hidden" id="lww_term_image_id" name="lww_term_image_id" value="<?php echo esc_attr($image_id); ?>">
            <div id="lww_term_image_preview" style="margin-bottom:10px;">
                <?php if ($image_url): ?>
                    <img src="<?php echo esc_url($image_url); ?>" style="max-width:150px;height:auto;border:1px solid #ccc;padding:5px;">
                <?php endif; ?>
            </div>
            <button type="button" class="button lww-upload-term-image"><?php _e('Bild hochladen', 'lego-wawi'); ?></button>
            <button type="button" class="button lww-remove-term-image" <?php echo empty($image_id) ? 'style="display:none;"' : ''; ?>><?php _e('Entfernen', 'lego-wawi'); ?></button>
            <p class="description">
                <?php _e('Weise diesem Begriff ein Bild zu. ', 'lego-wawi'); ?>
                <button type="button" class="button button-secondary button-small lww-generate-term-image-ai" data-term-id="<?php echo $term->term_id; ?>" data-taxonomy="<?php echo $term->taxonomy; ?>"><?php _e('Per KI / API generieren', 'lego-wawi'); ?></button>
            </p>
        </td>
    </tr>
    <?php
}
add_action('lww_theme_edit_form_fields', 'lww_taxonomy_edit_image_field');
add_action('lww_part_category_edit_form_fields', 'lww_taxonomy_edit_image_field');

// 3. Speichern der Felder
function lww_save_taxonomy_image($term_id) {
    if (isset($_POST['lww_term_image_id'])) {
        update_term_meta($term_id, '_lww_term_image_id', absint($_POST['lww_term_image_id']));
    }
}
add_action('created_lww_theme', 'lww_save_taxonomy_image');
add_action('edited_lww_theme', 'lww_save_taxonomy_image');
add_action('created_lww_part_category', 'lww_save_taxonomy_image');
add_action('edited_lww_part_category', 'lww_save_taxonomy_image');


function lww_add_categories_to_attachments() {
    register_taxonomy_for_object_type('category', 'attachment');
}
add_action('init', 'lww_add_categories_to_attachments');

// Helper für Labels
if (!function_exists('lww_get_tax_labels')) {
    function lww_get_tax_labels($singular, $plural) {
        return [
            'name'                       => _x($plural, 'Taxonomy General Name', 'lego-wawi'),
            'singular_name'              => _x($singular, 'Taxonomy Singular Name', 'lego-wawi'),
            'menu_name'                  => __($plural, 'lego-wawi'),
            'all_items'                  => __('Alle ' . $plural, 'lego-wawi'),
            'edit_item'                  => __($singular . ' bearbeiten', 'lego-wawi'),
            'update_item'                => __($singular . ' aktualisieren', 'lego-wawi'),
            'add_new_item'               => __('Neue(s) ' . $singular . ' hinzufügen', 'lego-wawi'),
            'new_item_name'              => __('Neue(r) ' . $singular . '-Name', 'lego-wawi'),
            'search_items'               => __($plural . ' suchen', 'lego-wawi'),
        ];
    }
}
?>