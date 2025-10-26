<?php
/**
 * Modul: Inventar UI & Steuerung (NEU in v9.0)
 *
 * Rendert die Seite "BrickOwl Inventar verwalten" und wird später die
 * Logik enthalten, um WooCommerce Produkte selektiv zu erstellen/aktualisieren.
 */
if (!defined('ABSPATH')) exit;

// Diese Datei enthält im Moment nur die Render-Funktion für die Seite.
// Die WP_List_Table und die AJAX-Handler kommen in einem späteren Schritt hinzu.

/**
 * Rendert den Inhalt der Seite "BrickOwl Inventar verwalten".
 * (Wird über add_submenu_page in lww-admin-page.php aufgerufen)
 */
function lww_render_inventory_ui_page() {
    // Sicherstellen, dass der Nutzer die Berechtigung hat
    if (!current_user_can('manage_options')) {
        wp_die(__('Sie haben keine Berechtigung, auf diese Seite zuzugreifen.', 'lego-wawi'));
    }

    // Hier kommt später die WP_List_Table für lww_inventory_item Posts hin
    // $inventory_list_table = new LWW_Inventory_List_Table();
    // $inventory_list_table->prepare_items();

    ?>
    <div class="wrap lww-wrap">
        <h1><span class="dashicons dashicons-list-view lww-title-icon"></span> <?php _e('BrickOwl Inventar Verwalten', 'lego-wawi'); ?></h1>
        <p><?php _e('Hier sehen Sie Ihren importierten BrickOwl-Bestand. Wählen Sie Artikel aus, um WooCommerce-Produkte zu erstellen oder zu aktualisieren.', 'lego-wawi'); ?></p>

        <?php settings_errors('lww_inventory_messages'); // Für zukünftige Nachrichten ?>

        <div class="lww-card">

            <h2><?php _e('Inventarliste (Platzhalter)', 'lego-wawi'); ?></h2>
            <p><?php _e('In Zukunft wird hier eine durchsuchbare und filterbare Tabelle Ihres importierten BrickOwl-Inventars angezeigt.', 'lego-wawi'); ?></p>

            <form id="inventory-filter" method="post">
                <!-- Versteckte Felder für WP_List_Table -->
                <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page'] ?? ''); ?>" />
                <?php // $inventory_list_table->search_box(__('Inventar durchsuchen', 'lego-wawi'), 'lww-inventory-search'); ?>
                <?php // $inventory_list_table->display(); ?>

                <div class="lww-placeholder" style="margin-top: 20px;">
                    <?php _e('Tabelle wird hier angezeigt...', 'lego-wawi'); ?>
                    <br><br>
                     <strong><?php _e('Geplante Funktionen:', 'lego-wawi'); ?></strong>
                     <ul>
                         <li><span class="dashicons dashicons-info-outline"></span> <?php _e('Anzeige von BrickOwl ID, Name, Farbe, Zustand, Menge, Preis.', 'lego-wawi'); ?></li>
                         <li><span class="dashicons dashicons-admin-links"></span> <?php _e('Verknüpfung zum entsprechenden Katalog-Eintrag (lww_part).', 'lego-wawi'); ?></li>
                          <li><span class="dashicons dashicons-store"></span> <?php _e('Statusanzeige (Bereits in WooCommerce vorhanden?).', 'lego-wawi'); ?></li>
                         <li><span class="dashicons dashicons-edit"></span> <?php _e('Button pro Artikel/Gruppe, um WooCommerce-Produkt(e) zu erstellen/aktualisieren.', 'lego-wawi'); ?></li>
                         <li><span class="dashicons dashicons-controls-skipforward"></span> <?php _e('Bulk-Aktionen (z.B. alle neuen Teile einer Farbe erstellen).', 'lego-wawi'); ?></li>
                     </ul>
                </div>
            </form>

        </div>
    </div>
    <?php
}

// TODO:
// 1. WP_List_Table Klasse 'LWW_Inventory_List_Table' erstellen (ähnlich wie LWW_Jobs_List_Table).
//    - Spalten: Checkbox, BOID, Name (Link zu lww_part), Farbe (mit Vorschau), Zustand, Menge, Preis, WC-Status, Aktionen (Button).
//    - prepare_items() mit WP_Query für 'lww_inventory_item'.
//    - Sortierung, Filterung (nach Name, Farbe, Zustand?).
//    - Bulk Actions definieren.
// 2. AJAX Handler für den "Erstellen/Aktualisieren"-Button:
//    - Nimmt BOID/Inventory Item ID entgegen.
//    - Holt alle lww_inventory_item Posts für diese BOID (alle Farben/Zustände).
//    - Findet/Erstellt das variable WooCommerce Produkt (verknüpft mit lww_part).
//    - Findet/Erstellt die WooCommerce Variationen mit Menge und Preis aus den lww_inventory_item Posts.
//    - Setzt ggf. Variantenbilder.
//    - Gibt Erfolgs-/Fehlermeldung zurück.
// 3. Logik für Bulk Actions implementieren.

?>