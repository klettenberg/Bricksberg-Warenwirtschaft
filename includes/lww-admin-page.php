<?php
/**
 * Modul: Admin-Seite (Schaltzentrale) (v14.0)
 * Baut die Admin-Seite mit der neuen hierarchischen Menü-Struktur auf.
 */

if (!defined('ABSPATH')) exit;

/**
 * Erstellt den Haupt-Menüpunkt und die Untermenüs.
 */
function lww_add_admin_menu() {
    // Hauptmenüpunkt -> Dashboard
    add_menu_page(
        __('Bricksberg WaWi Dashboard', 'lego-wawi'),
        __('Bricksberg WaWi', 'lego-wawi'),
        'manage_options',
        LWW_PLUGIN_SLUG, // bricksberg_wawi_dashboard
        'lww_render_dashboard_ui_page',
        'dashicons-layout',
        26
    );

    // Untermenü: Dashboard (verweist auf die Hauptseite)
    add_submenu_page(
        LWW_PLUGIN_SLUG,
        __('Dashboard', 'lego-wawi'),
        __('Dashboard', 'lego-wawi'),
        'manage_options',
        LWW_PLUGIN_SLUG, // Gleicher Slug wie Hauptseite
        'lww_render_dashboard_ui_page'
    );

    // --- GRUPPE: DATENVERWALTUNG ---
    add_submenu_page(LWW_PLUGIN_SLUG, '', '<span style="display:block; margin:1px 0 1px -5px; padding:0; height:1px; line-height:1px; background:#4f5d6b;"></span>', 'manage_options', '#');

    // Untermenü: Inventar Verwalten
     add_submenu_page(
        LWW_PLUGIN_SLUG,
        __('Inventar Verwalten', 'lego-wawi'),
        __('Inventar Verwalten', 'lego-wawi'),
        'manage_options',
        'lww_inventory_ui',
        'lww_render_inventory_ui_page'
    );

    // Untermenü: Lagerverwaltung
    add_submenu_page(
        LWW_PLUGIN_SLUG,
        __('Lagerverwaltung', 'lego-wawi'),
        __('Lagerverwaltung', 'lego-wawi'),
        'manage_options',
        'lww_storage_ui',
        'lww_render_storage_ui_page'
    );

    // --- GRUPPE: KATALOG-STAMMDATEN (wird automatisch durch CPTs gefüllt) ---
    add_submenu_page(LWW_PLUGIN_SLUG, '', '<span style="display:block; margin:1px 0 1px -5px; padding:0; height:1px; line-height:1px; background:#4f5d6b;"></span>', 'manage_options', '#');

    // --- GRUPPE: JOBS & PROZESSE ---
    add_submenu_page(LWW_PLUGIN_SLUG, '', '<span style="display:block; margin:1px 0 1px -5px; padding:0; height:1px; line-height:1px; background:#4f5d6b;"></span>', 'manage_options', '#');

    // Untermenü: Import
    add_submenu_page(
        LWW_PLUGIN_SLUG,
        __('Import', 'lego-wawi'),
        __('Import', 'lego-wawi'),
        'manage_options',
        'lww_import_ui',
        'lww_render_import_ui_page'
    );
    
    // Untermenü: API Synchronisation
    add_submenu_page(
        LWW_PLUGIN_SLUG,
        __('API Synchronisation', 'lego-wawi'),
        __('API Synchronisation', 'lego-wawi'),
        'manage_options',
        'lww_sync_ui',
        'lww_render_sync_ui_page'
    );

    // Untermenü: Job-Warteschlange
    add_submenu_page(
        LWW_PLUGIN_SLUG,
        __('Job-Warteschlange', 'lego-wawi'),
        __('Job-Warteschlange', 'lego-wawi'),
        'manage_options',
        'lww_jobs_ui',
        'lww_render_jobs_ui_page'
    );

    // --- GRUPPE: ANALYSE & WERKZEUGE ---
    add_submenu_page(LWW_PLUGIN_SLUG, '', '<span style="display:block; margin:1px 0 1px -5px; padding:0; height:1px; line-height:1px; background:#4f5d6b;"></span>', 'manage_options', '#');

     // Untermenü: Analyse & KI
     add_submenu_page(
        LWW_PLUGIN_SLUG,
        __('Analyse & KI', 'lego-wawi'),
        __('Analyse & KI', 'lego-wawi'),
        'manage_options',
        'lww_analysis_ui',
        'lww_render_analysis_ui_page'
    );

    // Untermenü: Werkzeuge & Wartung
    add_submenu_page(
        LWW_PLUGIN_SLUG,
        __('Werkzeuge & Wartung', 'lego-wawi'),
        __('Werkzeuge & Wartung', 'lego-wawi'),
        'manage_options',
        'lww_tools_ui',
        'lww_render_tools_ui_page'
    );

    // Untermenü: Daten-Korrektur
    add_submenu_page(
        LWW_PLUGIN_SLUG,
        __('Daten-Korrektur', 'lego-wawi'),
        __('Daten-Korrektur', 'lego-wawi'),
        'manage_options',
        'lww_data_correction_ui',
        'lww_render_data_correction_ui_page'
    );

    // --- GRUPPE: SYSTEM ---
    add_submenu_page(LWW_PLUGIN_SLUG, '', '<span style="display:block; margin:1px 0 1px -5px; padding:0; height:1px; line-height:1px; background:#4f5d6b;"></span>', 'manage_options', '#');

    // API-Log UI
     add_submenu_page(
        LWW_PLUGIN_SLUG,
        __('API-Log', 'lego-wawi'),
        __('API-Log', 'lego-wawi'),
        'manage_options',
        'lww_api_log_ui',
        'lww_render_api_log_ui_page'
    );

    // Untermenü: Einstellungen
    add_submenu_page(
        LWW_PLUGIN_SLUG,
        __('Einstellungen', 'lego-wawi'),
        __('Einstellungen', 'lego-wawi'),
        'manage_options',
        'lww_settings_ui',
        'lww_render_settings_ui_page'
    );
}
add_action('admin_menu', 'lww_add_admin_menu');


/**
 * =========================================================================
 * Callback-Funktionen für die neuen Untermenü-Seiten
 * =========================================================================
 */

/**
 * Rendert die Dashboard-Seite.
 */
function lww_render_dashboard_ui_page() {
    lww_render_tab_dashboard();
}

/**
 * Rendert die Job-Übersichtsseite.
 */
function lww_render_jobs_ui_page() {
    lww_render_tab_jobs();
}

/**
 * Rendert die Einstellungsseite.
 */
function lww_render_settings_ui_page() {
    ?>
    <div class="wrap lww-wrap">
        <h1><img src="<?php echo esc_url(LWW_PLUGIN_URL . 'assets/img/bricksberg-logo.png'); ?>" alt="Bricksberg Logo" class="lww-header-logo" /> <?php _e('Einstellungen', 'lego-wawi'); ?></h1>
        <p><?php _e('Konfiguriere hier API-Schlüssel, Performance-Optionen und Job-Prioritäten.', 'lego-wawi'); ?></p>
        <?php settings_errors('lww_messages'); ?>
        <div class="lww-admin-form lww-card lww-mt-20">
            <form action="options.php" method="post" class="lww-settings-page-form">
                <?php
                settings_fields('lww_settings_group');
                do_settings_sections('lww_settings_ui'); // KORRIGIERT: Der Slug muss mit dem bei der Registrierung übereinstimmen
                submit_button(__('Einstellungen speichern', 'lego-wawi'));
                ?>
            </form>
        </div>
    </div>
    <?php
}

/**
 * Rendert die kombinierte Import-Seite.
 */
function lww_render_import_ui_page() {
    ?>
    <div class="wrap lww-wrap">
        <h1><img src="<?php echo esc_url(LWW_PLUGIN_URL . 'assets/img/bricksberg-logo.png'); ?>" alt="Bricksberg Logo" class="lww-header-logo" /> <?php _e('Daten Import', 'lego-wawi'); ?></h1>
        <p><?php _e('Starte hier manuelle Import-Jobs aus CSV-Dateien von Rebrickable oder deinem BrickOwl Shop.', 'lego-wawi'); ?></p>
        <?php settings_errors('lww_messages'); ?>
        
        <?php 
        lww_render_catalog_import_section();
        lww_render_inventory_import_section();
        lww_render_ebay_import_section();
        ?>
    </div>
    <?php
}


/**
 * Rendert den Abschnitt für den Katalog-Import.
 */
function lww_render_catalog_import_section() {
    ?>
    <div class="lww-admin-form lww-card lww-mt-20">
        <h2><?php _e('Katalog-Import starten (Rebrickable CSV)', 'lego-wawi'); ?></h2>
        <p><?php _e('Lade hier CSV-, ZIP- oder GZ-Dateien von Rebrickable hoch. Ein neuer Job wird erstellt und in die Warteschlange eingereiht.', 'lego-wawi'); ?></p>
        <p><strong><?php _e('Wichtig:', 'lego-wawi'); ?></strong> <?php _e('Lade alle Dateien hoch, die du importieren möchtest, und klicke DANN auf "Import STARTEN". Das System verarbeitet sie in der korrekten Reihenfolge.', 'lego-wawi'); ?></p>

        <form action="admin-post.php" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="lww_upload_catalog_csv">
            <?php wp_nonce_field('lww_catalog_import_nonce'); ?>

            <table class="form-table lww-import-table">
                <tbody>
                    <?php
                    $files_to_upload = [
                        'Basis-Daten' => ['colors' => 'colors.csv (Farben)', 'themes' => 'themes.csv (Themen)', 'part_categories' => 'part_categories.csv (Teile-Kategorien)'],
                        'Haupt-Katalog' => ['parts' => 'parts.csv (Teile/Formen)', 'sets' => 'sets.csv (Sets)', 'minifigs' => 'minifigs.csv (Minifiguren)'],
                        'Relationen & Details' => ['part_relationships' => 'part_relationships.csv (Teile-Relationen)', 'elements' => 'elements.csv (Element IDs = Teil + Farbe)'],
                        'Inventar-Stücklisten' => ['inventories' => 'inventories.csv (Inventar-Listen)', 'inventory_parts' => 'inventory_parts.csv (Teile in Inventaren)', 'inventory_sets' => 'inventory_sets.csv (Sets in Inventaren)', 'inventory_minifigs' => 'inventory_minifigs.csv (Minifigs in Inventaren)']
                    ];
                    $accept_files = '.csv,.zip,.gz,application/zip,application/x-gzip,text/csv';

                    foreach ($files_to_upload as $group_label => $files) :
                    ?>
                    <tr class="lww-form-group-header">
                        <td colspan="2">
                            <h3><?php echo esc_html($group_label); ?></h3>
                        </td>
                    </tr>
                    <?php
                        foreach ($files as $key => $label) :
                    ?>
                    <tr>
                        <th scope="row">
                            <label for="lww_file_<?php echo $key; ?>"><?php echo esc_html($label); ?></label>
                        </th>
                        <td>
                            <input type="file" id="lww_file_<?php echo $key; ?>" name="lww_csv_files[<?php echo $key; ?>]" accept="<?php echo $accept_files; ?>" class="lww-file-input">
                        </td>
                    </tr>
                    <?php
                        endforeach;
                    endforeach;
                    ?>
                </tbody>
            </table>

            <?php submit_button(__('Neuen Katalog-Import-Job erstellen', 'lego-wawi'), 'primary large lww-submit-button'); ?>
        </form>
    </div>
    <?php
}

/**
 * Rendert den Abschnitt für den Inventar-Import.
 */
function lww_render_inventory_import_section() {
    $required_data = ['lww_color', 'lww_part']; // CPT slugs
    $missing_data = [];
    $is_ready = true;
    foreach($required_data as $type) {
        if (function_exists('lww_get_catalog_count')) {
            if (lww_get_catalog_count($type) === 0) {
                 $missing_data[] = get_post_type_object($type)->labels->name;
                 $is_ready = false;
            }
        }
    }
    ?>
    <div class="lww-admin-form lww-card lww-mt-20">
        <h2><?php _e('Inventar-Import starten', 'lego-wawi'); ?></h2>

        <?php if (!$is_ready): ?>
            <div class="notice notice-error inline lww-notice">
                <p>
                    <span class="dashicons dashicons-warning"></span>
                    <strong><?php _e('Fehlende Stammdaten!', 'lego-wawi'); ?></strong>
                    <?php printf(
                        __('Der Inventar-Import ist erst möglich, wenn die folgenden Katalogdaten importiert wurden: %s. Bitte führe zuerst den Katalog-Import durch.', 'lego-wawi'),
                        '<strong>' . implode(', ', $missing_data) . '</strong>'
                    );
                    ?>
                </p>
            </div>
        <?php else: ?>
             <div class="notice notice-success inline lww-notice">
                 <p><span class="dashicons dashicons-yes-alt"></span> <?php _e('Alle für den Import notwendigen Katalogdaten (Teile, Farben) sind vorhanden.', 'lego-wawi'); ?></p>
             </div>
        <?php endif; ?>

        <hr style="margin-top: 20px;">

        <h3><?php _e('BrickLink Inventar-Import', 'lego-wawi'); ?></h3>
        <p><?php _e('Lade hier deinen BrickLink Inventar-Export als .xml-Datei hoch.', 'lego-wawi'); ?></p>
        <form action="admin-post.php" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="lww_upload_bricklink_inventory_xml">
            <?php wp_nonce_field('lww_bricklink_inventory_import_nonce'); ?>
            <table class="form-table">
                <tbody>
                    <tr>
                        <th scope="row"><label for="bricklink_inventory_xml_file"><?php _e('BrickLink Inventar XML-Datei', 'lego-wawi'); ?></label></th>
                        <td><input type="file" id="bricklink_inventory_xml_file" name="bricklink_inventory_xml_file" accept=".xml,text/xml" required></td>
                    </tr>
                </tbody>
            </table>
            <?php submit_button(
                __('Neuen BrickLink Import-Job erstellen', 'lego-wawi'),
                'primary',
                'submit',
                true,
                !$is_ready ? ['disabled' => 'disabled'] : null
             ); ?>
        </form>

        <hr style="margin: 20px 0;">

        <h3><?php _e('BrickOwl Inventar-Import', 'lego-wawi'); ?></h3>
        <p><?php _e('Lade hier deinen persönlichen BrickOwl-Inventar-Export oder ein komplettes Backup als CSV-Datei hoch.', 'lego-wawi'); ?></p>

        <form action="admin-post.php" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="lww_upload_inventory_csv">
            <?php wp_nonce_field('lww_inventory_import_nonce'); ?>
            <table class="form-table">
                <tbody>
                    <tr>
                        <th scope="row"><label for="inventory_csv_file"><?php _e('BrickOwl Inventar CSV-Datei', 'lego-wawi'); ?></label></th>
                        <td><input type="file" id="inventory_csv_file" name="inventory_csv_file" accept=".csv, text/csv" required></td>
                    </tr>
                </tbody>
            </table>
            <?php submit_button(
                __('Neuen Inventar-Import-Job erstellen', 'lego-wawi'),
                'primary',
                'submit',
                true,
                !$is_ready ? ['disabled' => 'disabled'] : null
             ); ?>
        </form>
        
        <hr style="margin: 20px 0;">

        <form action="admin-post.php" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="lww_upload_inventory_backup_csv">
            <?php wp_nonce_field('lww_inventory_backup_import_nonce'); ?>
            <table class="form-table">
                <tbody>
                    <tr>
                        <th scope="row"><label for="inventory_backup_csv_file"><?php _e('BrickOwl Backup CSV-Datei', 'lego-wawi'); ?></label></th>
                        <td><input type="file" id="inventory_backup_csv_file" name="inventory_backup_csv_file" accept=".csv, text/csv" required></td>
                    </tr>
                </tbody>
            </table>
            <?php submit_button(
                __('Neuen Backup-Import-Job erstellen', 'lego-wawi'),
                'secondary',
                'submit',
                true,
                !$is_ready ? ['disabled' => 'disabled'] : null
             ); ?>
        </form>

    </div>
    <?php
}

?>