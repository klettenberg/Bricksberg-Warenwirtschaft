<?php
/**
 * Modul: Admin-Seite (Schaltzentrale) (v9.0)
 * Baut die Admin-Seite mit der neuen Tab-Struktur auf,
 * zeigt Stammdaten-Warnung und nutzt CSS Klassen.
 */

if (!defined('ABSPATH')) exit;

/**
 * Erstellt den Haupt-Menüpunkt und das Dashboard-Untermenü.
 */
function lww_add_admin_menu() {
    // Hauptmenüpunkt, der standardmäßig zum Dashboard führt
    add_menu_page(
        __('LEGO WaWi Dashboard', 'lego-wawi'), // Seitentitel
        __('LEGO WaWi', 'lego-wawi'),           // Menütitel
        'manage_options',                       // Benötigte Berechtigung
        LWW_PLUGIN_SLUG,                        // Slug (Identifikator) der Seite
        'lww_admin_page_html',                  // Funktion, die den Seiteninhalt rendert
        'dashicons-layout',                     // Icon
        26                                      // Position im Menü
    );

    // Explizites Dashboard-Untermenü (zeigt auf die gleiche Seite)
    add_submenu_page(
        LWW_PLUGIN_SLUG,                        // Slug der Eltern-Seite
        __('Dashboard', 'lego-wawi'),           // Seitentitel
        __('Dashboard', 'lego-wawi'),           // Menütitel
        'manage_options',                       // Berechtigung
        LWW_PLUGIN_SLUG,                        // Slug (identisch zur Hauptseite)
        'lww_admin_page_html'                   // Gleiche Render-Funktion
    );

     // Explizites Untermenü für das neue BrickOwl Inventar UI
     add_submenu_page(
        LWW_PLUGIN_SLUG,
        __('BrickOwl Inventar', 'lego-wawi'),
        __('BrickOwl Inventar', 'lego-wawi'),
        'manage_options',
        'lww_inventory_ui',                     // Eigener Slug für diese Seite
        'lww_render_inventory_ui_page'          // Funktion aus lww-inventory-ui.php
    );

    // Die CPTs und Taxonomien werden über 'show_in_menu' => LWW_PLUGIN_SLUG
    // in lww-cpts.php und lww-taxonomies.php automatisch hier eingehängt.
}
add_action('admin_menu', 'lww_add_admin_menu');


/**
 * Rendert die HTML-Struktur der Admin-Seite (Wrapper und Tabs).
 */
function lww_admin_page_html() {
    // Sicherstellen, dass der Nutzer die Berechtigung hat
    if (!current_user_can('manage_options')) {
        wp_die(__('Sie haben keine Berechtigung, auf diese Seite zuzugreifen.', 'lego-wawi'));
    }

    // Aktiven Tab bestimmen (Standard ist das Dashboard)
    $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'tab_dashboard';
    ?>
    <div class="wrap lww-wrap">
        <h1><span class="dashicons dashicons-layout lww-title-icon"></span> <?php echo esc_html(get_admin_page_title()); ?></h1>
        <p><?php _e('Zentrale Steuerung für deine LEGO Warenwirtschaft.', 'lego-wawi'); ?></p>

        <!-- Tab-Navigation -->
        <nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Plugin Navigation', 'lego-wawi' ); ?>">
             <a href="?page=<?php echo LWW_PLUGIN_SLUG; ?>&tab=tab_dashboard" class="nav-tab <?php echo $active_tab == 'tab_dashboard' ? 'nav-tab-active' : ''; ?>">
                 <span class="dashicons dashicons-dashboard"></span> <?php _e('Dashboard', 'lego-wawi'); ?>
             </a>
             <a href="?page=<?php echo LWW_PLUGIN_SLUG; ?>&tab=tab_jobs" class="nav-tab <?php echo $active_tab == 'tab_jobs' ? 'nav-tab-active' : ''; ?>">
                 <span class="dashicons dashicons-admin-settings"></span> <?php _e('Job-Warteschlange', 'lego-wawi'); ?>
             </a>
             <a href="?page=<?php echo LWW_PLUGIN_SLUG; ?>&tab=tab_catalog_import" class="nav-tab <?php echo $active_tab == 'tab_catalog_import' ? 'nav-tab-active' : ''; ?>">
                 <span class="dashicons dashicons-database-import"></span> <?php _e('Katalog-Import', 'lego-wawi'); ?>
             </a>
             <a href="?page=<?php echo LWW_PLUGIN_SLUG; ?>&tab=tab_inventory_import" class="nav-tab <?php echo $active_tab == 'tab_inventory_import' ? 'nav-tab-active' : ''; ?>">
                 <span class="dashicons dashicons-archive"></span> <?php _e('Inventar-Import', 'lego-wawi'); ?>
             </a>
             <a href="admin.php?page=lww_inventory_ui" class="nav-tab <?php echo (isset($_GET['page']) && $_GET['page'] == 'lww_inventory_ui') ? 'nav-tab-active' : ''; ?>">
                 <span class="dashicons dashicons-list-view"></span> <?php _e('Inventar Verwalten', 'lego-wawi'); ?>
             </a>
             <a href="?page=<?php echo LWW_PLUGIN_SLUG; ?>&tab=tab_analysis" class="nav-tab <?php echo $active_tab == 'tab_analysis' ? 'nav-tab-active' : ''; ?>">
                 <span class="dashicons dashicons-chart-bar"></span> <?php _e('Analyse & KI', 'lego-wawi'); ?>
             </a>
             <a href="?page=<?php echo LWW_PLUGIN_SLUG; ?>&tab=tab_settings" class="nav-tab <?php echo $active_tab == 'tab_settings' ? 'nav-tab-active' : ''; ?>">
                 <span class="dashicons dashicons-admin-network"></span> <?php _e('Einstellungen', 'lego-wawi'); ?>
             </a>
        </nav>

        <?php
        // Zeigt Erfolgs-/Fehlermeldungen an (z.B. nach Job-Erstellung)
        settings_errors('lww_messages');
        ?>

        <!-- Container für den Inhalt des aktiven Tabs -->
        <div class="lww-tab-content">
            <?php
            // Ruft die passende Render-Funktion für den aktiven Tab auf
            switch ($active_tab) {
                case 'tab_dashboard':
                    if (function_exists('lww_render_tab_dashboard')) {
                        lww_render_tab_dashboard(); // Funktion aus lww-dashboard.php
                    }
                    break;
                case 'tab_jobs':
                     if (function_exists('lww_render_tab_jobs')) {
                        lww_render_tab_jobs(); // Funktion aus lww-jobs.php
                     }
                    break;
                case 'tab_catalog_import':
                    lww_render_tab_catalog_import(); // Funktion weiter unten in dieser Datei
                    break;
                case 'tab_inventory_import':
                    lww_render_tab_inventory_import(); // Funktion weiter unten in dieser Datei
                    break;
                 // case 'tab_inventory_manage': // Wird über eigene Seite lww_inventory_ui gerendert
                 //    if (function_exists('lww_render_inventory_ui_page')) {
                 //        lww_render_inventory_ui_page(); // Funktion aus lww-inventory-ui.php
                 //    }
                 //    break;
                case 'tab_analysis':
                    lww_render_tab_analysis(); // Funktion weiter unten in dieser Datei
                    break;
                case 'tab_settings':
                default:
                    lww_render_tab_settings(); // Funktion weiter unten in dieser Datei
                    break;
            }
            ?>
        </div>
    </div>
    <?php
}

// --- Render-Funktionen für die Inhalte der Tabs (bleiben in dieser Datei) ---

/**
 * Rendert den Inhalt des "Einstellungen"-Tabs.
 */
function lww_render_tab_settings() {
    ?>
    <div class="lww-admin-form lww-card">
        <h2><?php _e('API-Schlüssel Konfiguration', 'lego-wawi'); ?></h2>
        <form action="options.php" method="post">
            <?php
            settings_fields('lww_settings_group'); // WordPress-Funktion zum Anzeigen der registrierten Feldergruppe
            do_settings_sections(LWW_PLUGIN_SLUG); // WordPress-Funktion zum Anzeigen der Sections und Felder
            submit_button(__('Einstellungen speichern', 'lego-wawi')); // Standard-Speichern-Button
            ?>
        </form>
    </div>
    <?php
}

/**
 * Rendert den Inhalt des "Katalog-Import"-Tabs.
 */
function lww_render_tab_catalog_import() {
    ?>
    <div class="lww-admin-form lww-card">
        <h2><?php _e('Katalog-Import starten (Rebrickable CSV)', 'lego-wawi'); ?></h2>
        <p><?php _e('Lade hier CSV-, ZIP- oder GZ-Dateien von Rebrickable hoch. Ein neuer Job wird erstellt und in die Warteschlange eingereiht.', 'lego-wawi'); ?></p>
        <p><strong><?php _e('Wichtig:', 'lego-wawi'); ?></strong> <?php _e('Lade alle Dateien hoch, die du importieren möchtest, und klicke DANN auf "Import STARTEN". Das System verarbeitet sie in der korrekten Reihenfolge.', 'lego-wawi'); ?></p>

        <form action="admin-post.php" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="lww_upload_catalog_csv"> <!-- Wichtig für admin-post.php -->
            <?php wp_nonce_field('lww_catalog_import_nonce'); // Sicherheitsfeld ?>

            <table class="form-table lww-import-table">
                <tbody>
                    <?php
                    // Struktur der Rebrickable-Dateien für das Formular
                    $files_to_upload = [
                        'Basis-Daten' => ['colors' => 'colors.csv (Farben)', 'themes' => 'themes.csv (Themen)', 'part_categories' => 'part_categories.csv (Teile-Kategorien)'],
                        'Haupt-Katalog' => ['parts' => 'parts.csv (Teile/Formen)', 'sets' => 'sets.csv (Sets)', 'minifigs' => 'minifigs.csv (Minifiguren)'],
                        'Relationen & Details' => ['part_relationships' => 'part_relationships.csv (Teile-Relationen)', 'elements' => 'elements.csv (Element IDs = Teil + Farbe)'],
                        'Inventar-Stücklisten' => ['inventories' => 'inventories.csv (Inventar-Listen)', 'inventory_parts' => 'inventory_parts.csv (Teile in Inventaren)', 'inventory_sets' => 'inventory_sets.csv (Sets in Inventaren)', 'inventory_minifigs' => 'inventory_minifigs.csv (Minifigs in Inventaren)']
                    ];
                    $accept_files = '.csv,.zip,.gz,application/zip,application/x-gzip,text/csv'; // Erlaubte Dateitypen

                    // Schleife durch die Gruppen und Dateien, um die Upload-Felder zu erstellen
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

            <?php submit_button(__('Neuen Katalog-Import-Job erstellen', 'lego-wawi'), 'primary large lww-submit-button'); // Button mit CSS-Klassen ?>
        </form>
    </div>
    <?php
}

/**
 * Rendert den Inhalt des "Inventar-Import"-Tabs.
 * Zeigt jetzt eine Warnung, wenn Stammdaten fehlen.
 */
function lww_render_tab_inventory_import() {
    // Prüfen, ob die notwendigen Stammdaten importiert wurden
    $required_data = ['colors', 'parts']; // Mindestens Farben und Teile müssen da sein
    $missing_data = [];
    foreach($required_data as $type) {
        $count_func = 'lww_get_catalog_count'; // Helper function from dashboard
         if (function_exists($count_func)) {
            if (call_user_func($count_func, $type) === 0) {
                 $missing_data[] = ucfirst($type); // Add the type name if count is 0
            }
        }
    }
    $can_import = empty($missing_data); // Import erlauben, wenn keine Daten fehlen

    ?>
    <div class="lww-admin-form lww-card">
        <h2><?php _e('Inventar-Import starten (BrickOwl CSV)', 'lego-wawi'); ?></h2>

        <?php if (!$can_import): ?>
            <div class="notice notice-error inline lww-notice">
                <p>
                    <span class="dashicons dashicons-warning"></span>
                    <strong><?php _e('Fehlende Stammdaten!', 'lego-wawi'); ?></strong>
                    <?php printf(
                        __('Der Inventar-Import kann erst gestartet werden, wenn die folgenden Katalogdaten importiert wurden: %s. Bitte führe zuerst den Katalog-Import durch.', 'lego-wawi'),
                        '<strong>' . implode(', ', $missing_data) . '</strong>'
                    ); ?>
                </p>
            </div>
        <?php else: ?>
             <div class="notice notice-success inline lww-notice">
                 <p><span class="dashicons dashicons-yes-alt"></span> <?php _e('Alle notwendigen Katalogdaten sind vorhanden.', 'lego-wawi'); ?></p>
             </div>
        <?php endif; ?>

        <p><?php _e('Lade hier deinen persönlichen BrickOwl-Inventar-Export als CSV-Datei hoch. Die Daten werden in eine interne Liste importiert.', 'lego-wawi'); ?></p>

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
                'primary large lww-submit-button', // CSS Klassen
                'submit', // name Attribut
                true, // wrap in <p>
                !$can_import ? ['disabled' => 'disabled'] : null // Button deaktivieren, wenn Daten fehlen
             ); ?>
             <?php if (!$can_import): ?>
                <p class="description"><?php _e('Der Button ist deaktiviert, da wichtige Katalogdaten fehlen (siehe Meldung oben).', 'lego-wawi'); ?></p>
             <?php endif; ?>
        </form>
    </div>
    <?php
}

/**
 * Rendert den Inhalt des "Analyse & KI"-Tabs.
 */
function lww_render_tab_analysis() {
    ?>
    <div class="lww-admin-form lww-card">
        <h2><?php _e('Analyse & KI (Zukunft)', 'lego-wawi'); ?></h2>
        <p><?php _e('Dieser Bereich ist für zukünftige Erweiterungen vorgesehen.', 'lego-wawi'); ?></p>
        <div class="lww-placeholder">
            <h3><?php _e('Geplante Funktionen:', 'lego-wawi'); ?></h3>
            <ul>
                <li><span class="dashicons dashicons-edit"></span> <?php _e('KI-gestützte Produktbeschreibungen (z.B. mit OpenAI).', 'lego-wawi'); ?></li>
                <li><span class="dashicons dashicons-chart-line"></span> <?php _e('Analyse von Verkaufsdaten (Trends, gefragte Teile).', 'lego-wawi'); ?></li>
                <li><span class="dashicons dashicons-cart"></span> <?php _e('Vorschläge für Einkaufslisten basierend auf Marktdaten.', 'lego-wawi'); ?></li>
            </ul>
        </div>
    </div>
    <?php
}
?>