<?php
/**
 * Modul: UI für Marketing & Vertrieb (v1.0)
 *
 * Rendert den Inhalt für den "Marketing & Vertrieb"-Tab.
 */
if (!defined('ABSPATH')) exit;

/**
 * Rendert den Inhalt des "Marketing & Vertrieb"-Tabs.
 */
function lww_render_marketing_ui_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Sie haben keine Berechtigung, auf diese Seite zuzugreifen.', 'lego-wawi'));
    }
    ?>
    <div class="wrap lww-wrap">
        <h1><img src="<?php echo esc_url(LWW_PLUGIN_URL . 'assets/img/bricksberg-logo.png'); ?>" alt="Bricksberg Logo" class="lww-header-logo" /> <?php _e('Marketing & Vertrieb', 'lego-wawi'); ?></h1>
        <p><?php _e('Nutzen Sie diese Werkzeuge, um die Vorteile Ihrer Bricksberg WaWi-Installation zu präsentieren und neue Kunden zu gewinnen.', 'lego-wawi'); ?></p>
        
        <div class="lww-card lww-mt-20">
            <h2><?php _e('Feature- & Preisseite (Landingpage)', 'lego-wawi'); ?></h2>
            <p><?php _e('Um eine professionelle Landingpage zu erstellen, die die Funktionen und potenziellen Preispläne Ihrer Software anzeigt, verwenden Sie den folgenden Shortcode auf einer beliebigen Seite oder in einem Beitrag:', 'lego-wawi'); ?></p>
            
            <p><input type="text" value="[lww_features_page]" class="large-text" readonly onfocus="this.select();"></p>
            
            <p><?php _e('Dieser Shortcode generiert eine vollständige, modern gestaltete Seite mit:', 'lego-wawi'); ?></p>
            <ul>
                <li><?php _e('Einer Übersicht der Kernfunktionen mit Icons.', 'lego-wawi'); ?></li>
                <li><?php _e('Einer anpassbaren Preisvergleichstabelle.', 'lego-wawi'); ?></li>
                <li><?php _e('Call-to-Action-Buttons.', 'lego-wawi'); ?></li>
            </ul>
            <p><?php _e('Das Design kann über die Datei <code>assets/css/lww-frontend-styles.css</code> in Ihrem Plugin-Ordner weiter angepasst werden.', 'lego-wawi'); ?></p>
        </div>
    </div>
    <?php
}
