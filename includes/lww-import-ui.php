<?php
/**
 * Modul: Import UI (v21.1-BL-API)
 * 
 * Implementiert einen geführten Import-Prozess für Stammdaten -> Katalog -> Inventar.
 * UPDATE: BrickLink API Inventar Import hinzugefügt.
 */
if (!defined('ABSPATH')) exit;

function lww_render_import_ui_page() {
    if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
    ?>
    <div class="wrap lww-wrap">
        <h1><img src="<?php echo esc_url(LWW_PLUGIN_URL . 'assets/img/bricksberg-logo.png'); ?>" alt="Logo" class="lww-header-logo" /> <?php _e('Import & Synchronisation', 'lego-wawi'); ?></h1>
        <p><?php _e('Befolgen Sie diese Schritte, um Ihr System sauber einzurichten. Bitte die Reihenfolge einhalten.', 'lego-wawi'); ?></p>
        
        <?php settings_errors('lww_messages'); ?>

        <!-- STEP 1: Stammdaten -->
        <div class="lww-card lww-mt-20">
            <h2><?php _e('Schritt 1: Stammdaten (Farben & Kategorien)', 'lego-wawi'); ?></h2>
            <p><?php _e('Diese Daten sind die Grundlage für alle Teile und Sets. Importieren Sie diese zuerst.', 'lego-wawi'); ?></p>
            <div style="display:flex; gap:10px;">
                <!-- Rebrickable CSV -->
                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" enctype="multipart/form-data" style="border:1px solid #ddd; padding:15px; border-radius:4px; background:#f9f9f9;">
                    <strong style="display:block; margin-bottom:10px;">Option A: Rebrickable CSVs</strong>
                    <input type="hidden" name="action" value="lww_upload_catalog_csv">
                    <?php wp_nonce_field('lww_catalog_import_nonce'); ?>
                    <input type="file" name="lww_csv_files[]" multiple accept=".csv,.gz,.zip" required />
                    <p class="description">Benötigt: <code>colors.csv</code>, <code>themes.csv</code>, <code>part_categories.csv</code></p>
                    <?php submit_button(__('CSV Import starten', 'lego-wawi'), 'secondary', 'submit', false); ?>
                </form>
                
                <!-- API Sync -->
                <div style="border:1px solid #ddd; padding:15px; border-radius:4px; background:#f9f9f9;">
                    <strong style="display:block; margin-bottom:10px;">Option B: API Direkt-Sync</strong>
                    <form action="admin-post.php" method="post" style="margin-bottom:5px;">
                        <input type="hidden" name="action" value="lww_start_bricklink_catalog_sync">
                        <?php wp_nonce_field('lww_bricklink_catalog_sync_nonce'); ?>
                        <button type="submit" class="button">BrickLink (Farben)</button>
                    </form>
                    <form action="admin-post.php" method="post">
                        <input type="hidden" name="action" value="lww_start_brickowl_catalog_sync">
                        <?php wp_nonce_field('lww_brickowl_catalog_sync_nonce'); ?>
                        <button type="submit" class="button">BrickOwl (Farben)</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- STEP 2: Katalog -->
        <div class="lww-card lww-mt-20">
            <h2><?php _e('Schritt 2: Katalog (Teile, Sets, Minifigs)', 'lego-wawi'); ?></h2>
            <p><?php _e('Nach den Stammdaten können die eigentlichen Artikel importiert werden. Dies kann lange dauern.', 'lego-wawi'); ?></p>
            
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" enctype="multipart/form-data" style="background:#fff; padding:15px; border:1px solid #ccc;">
                <input type="hidden" name="action" value="lww_upload_catalog_csv">
                <?php wp_nonce_field('lww_catalog_import_nonce'); ?>
                <label><strong>CSV Upload (Rebrickable):</strong></label><br>
                <input type="file" name="lww_csv_files[]" multiple accept=".csv,.gz,.zip" required />
                <p class="description">Benötigt: <code>parts.csv</code>, <code>sets.csv</code>, <code>minifigs.csv</code>, <code>elements.csv</code>, <code>part_relationships.csv</code></p>
                <?php submit_button(__('Katalog-Import starten', 'lego-wawi'), 'primary', 'submit', false); ?>
            </form>
        </div>

        <!-- STEP 3: Inventar -->
        <div class="lww-card lww-mt-20">
            <h2><?php _e('Schritt 3: Dein Inventar', 'lego-wawi'); ?></h2>
            <p><?php _e('Zuletzt wird dein persönlicher Bestand eingelesen und mit dem Katalog verknüpft.', 'lego-wawi'); ?></p>
            
            <div style="display:flex; gap:20px;">
                <div style="flex:1; padding:15px; background:#fff; border:1px solid #e5e5e5;">
                    <h3>BrickLink XML (Datei)</h3>
                    <form action="admin-post.php" method="post" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="lww_upload_bricklink_inventory_xml">
                        <?php wp_nonce_field('lww_bricklink_inventory_import_nonce'); ?>
                        <input type="file" name="bricklink_inventory_xml_file" accept=".xml" required>
                        <p class="description">Exportiere dein Inventar auf BrickLink als XML und lade es hier hoch.</p>
                        <?php submit_button(__('Import BrickLink XML', 'lego-wawi'), 'secondary', 'submit', false); ?>
                    </form>
                </div>

                <div style="flex:1; padding:15px; background:#fff; border:1px solid #e5e5e5;">
                    <h3>BrickLink API (Direkt)</h3>
                    <form action="admin-post.php" method="post">
                        <input type="hidden" name="action" value="lww_start_bricklink_inventory_sync">
                        <?php wp_nonce_field('lww_bricklink_inventory_sync_nonce'); ?>
                        <p>Lädt das gesamte Inventar direkt über die BrickLink API. Kann bei großen Shops einige Minuten dauern.</p>
                        <?php submit_button(__('Start API Sync (BL)', 'lego-wawi'), 'primary', 'submit', false); ?>
                    </form>
                </div>
                
                <div style="flex:1; padding:15px; background:#fff; border:1px solid #e5e5e5;">
                    <h3>BrickOwl API (Direkt)</h3>
                    <form action="admin-post.php" method="post">
                        <input type="hidden" name="action" value="lww_start_brickowl_inventory_sync">
                        <?php wp_nonce_field('lww_brickowl_inventory_sync_nonce'); ?>
                        <p>Lädt das gesamte Inventar direkt über die BrickOwl API.</p>
                        <?php submit_button(__('Start API Sync (BO)', 'lego-wawi'), 'primary', 'submit', false); ?>
                    </form>
                </div>
            </div>
        </div>

        <!-- Weitere Tools -->
        <div class="lww-card lww-mt-20">
            <h3><?php _e('Spezial-Funktionen', 'lego-wawi'); ?></h3>
            <?php if (function_exists('lww_render_ebay_import_section')) lww_render_ebay_import_section(); ?>
        </div>
    </div>
    <?php
}
?>