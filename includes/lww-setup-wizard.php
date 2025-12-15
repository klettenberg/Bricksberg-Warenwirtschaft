<?php
/**
 * Modul: Einrichtungs-Assistent (Setup Wizard) (v17.0)
 * Angepasst an den "Inventar-First" Workflow.
 */
if (!defined('ABSPATH')) exit;

function lww_render_setup_wizard_ui_page() {
    if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
    $steps = lww_get_setup_wizard_steps();
    // ... (Rendering Logic bleibt ähnlich, aber Schritte ändern sich)
    ?>
    <div class="wrap lww-wrap lww-setup-wizard-wrap">
        <h1><img src="<?php echo esc_url(LWW_PLUGIN_URL . 'assets/img/bricksberg-logo.png'); ?>" class="lww-header-logo" /> <?php _e('Einrichtungs-Assistent', 'lego-wawi'); ?></h1>
        <!-- Progress Bar Logic here -->
        <div class="lww-setup-steps">
            <?php foreach ($steps as $step): ?>
                <div class="lww-setup-step <?php echo $step['status']; ?>">
                    <h3><?php echo esc_html($step['title']); ?></h3>
                    <p><?php echo esc_html($step['description']); ?></p>
                    <?php if ($step['status'] === 'active') lww_render_setup_step_content($step['key']); ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}

function lww_get_setup_wizard_steps() {
    return [
        [
            'key' => 'api_keys',
            'title' => 'Schritt 1: APIs verbinden',
            'description' => 'Verbinde BrickLink, BrickOwl und Brickset für den Datenabgleich.',
            'status' => (get_option('lww_api_settings')['bricklink_consumer_key'] ? 'completed' : 'active'),
            'check_function' => function() { return !empty(get_option('lww_api_settings')['bricklink_consumer_key']); }
        ],
        [
            'key' => 'inventory_import',
            'title' => 'Schritt 2: Inventar einlesen',
            'description' => 'Importiere deinen Bestand. Fehlende Katalogteile werden automatisch als Platzhalter angelegt.',
            'status' => (lww_get_catalog_count('lww_inventory_item') > 0 ? 'completed' : 'pending'),
            'check_function' => function() { return lww_get_catalog_count('lww_inventory_item') > 0; }
        ],
        [
            'key' => 'enrichment',
            'title' => 'Schritt 3: Daten anreichern',
            'description' => 'Lade Bilder und Details für die importierten Platzhalter nach.',
            'status' => 'pending',
            'check_function' => function() { return false; } // Manuell triggern
        ]
    ];
}

function lww_render_setup_step_content($key) {
    if ($key === 'api_keys') {
        echo '<a href="admin.php?page=lww_settings_ui" class="button button-primary">Zu den API-Einstellungen</a>';
    } elseif ($key === 'inventory_import') {
        echo '<p>Nutze die API-Synchronisation:</p>';
        echo '<form action="admin-post.php" method="post">';
        echo '<input type="hidden" name="action" value="lww_start_bricklink_inventory_sync">';
        wp_nonce_field('lww_start_bricklink_inventory_sync_nonce');
        submit_button('BrickLink Inventar importieren', 'primary');
        echo '</form>';
    } elseif ($key === 'enrichment') {
        echo '<p>Starte den Anreicherungs-Prozess:</p>';
        echo '<form action="admin-post.php" method="post">';
        echo '<input type="hidden" name="action" value="lww_start_catalog_enrichment">';
        wp_nonce_field('lww_start_catalog_enrichment_nonce');
        submit_button('Katalog anreichern', 'primary');
        echo '</form>';
    }
}
