<?php
/**
 * Modul: UI für Analyse & KI Tab (v14.0)
 *
 * Rendert den Inhalt für den "Analyse & KI"-Tab, inklusive der Steuerelemente
 * zum Starten von Analyse-Jobs.
 */
if (!defined('ABSPATH')) exit;

/**
 * Rendert den Inhalt des "Analyse & KI"-Tabs.
 */
function lww_render_analysis_ui_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Sie haben keine Berechtigung, auf diese Seite zuzugreifen.', 'lego-wawi'));
    }

    $api_settings = get_option('lww_api_settings');
    $ai_provider = get_option('lww_ai_provider', 'openai');
    
    $api_key = '';
    if ($ai_provider === 'openai') {
        $api_key = $api_settings['openai_api_key'] ?? '';
    } elseif ($ai_provider === 'gemini') {
        $api_key = $api_settings['gemini_api_key'] ?? '';
    }
    
    $can_analyze = !empty($api_key);
    ?>
    <div class="wrap lww-wrap">
        <h1><img src="<?php echo esc_url(LWW_PLUGIN_URL . 'assets/img/bricksberg-logo.png'); ?>" alt="Bricksberg Logo" class="lww-header-logo" /> <?php _e('Analyse & KI', 'lego-wawi'); ?></h1>
        <p><?php _e('Nutze künstliche Intelligenz, um deine Daten anzureichern und die Marktnachfrage zu analysieren.', 'lego-wawi'); ?></p>
        
        <?php settings_errors('lww_messages'); ?>

        <?php if (!$can_analyze): ?>
            <div class="notice notice-warning inline lww-notice">
                <p>
                    <span class="dashicons dashicons-warning"></span>
                    <strong><?php _e('Konfiguration erforderlich!', 'lego-wawi'); ?></strong>
                    <?php printf(
                        __('Der ausgewählte KI-Anbieter ist %1$s. Bitte hinterlege zuerst den passenden API-Schlüssel in den <a href="%2$s">Einstellungen</a>, um diese Funktion nutzen zu können.', 'lego-wawi'),
                        '<strong>' . esc_html(strtoupper($ai_provider)) . '</strong>',
                        esc_url(admin_url('admin.php?page=lww_settings_ui'))
                    );
                    ?>
                </p>
            </div>
        <?php else: ?>
             <div class="notice notice-info inline lww-notice">
                 <p><span class="dashicons dashicons-info-outline"></span> <?php printf(__('Der aktive KI-Anbieter ist %s.', 'lego-wawi'), '<strong>' . esc_html(strtoupper($ai_provider)) . '</strong>'); ?></p>
             </div>
        <?php endif; ?>

        <div class="lww-admin-form lww-card lww-mt-20">
            <h2><?php _e('Nachfrageanalyse (KI-gestützt)', 'lego-wawi'); ?></h2>
            <p><?php _e('Dieses Werkzeug bewertet die Marktnachfrage für jeden Inventarartikel. Der Prozess läuft im Hintergrund und kann je nach Bestandsgröße einige Zeit dauern.', 'lego-wawi'); ?></p>
            
            <div style="display: flex; gap: 10px; align-items: flex-start;">
                <form action="admin-post.php" method="post">
                    <input type="hidden" name="action" value="lww_run_demand_analysis">
                    <input type="hidden" name="analysis_mode" value="missing">
                    <?php wp_nonce_field('lww_demand_analysis_nonce'); ?>
                    <?php submit_button(
                        __('Analyse für Artikel ohne Score starten', 'lego-wawi'),
                        'primary',
                        'submit',
                        true,
                        $can_analyze ? null : ['disabled' => 'disabled']
                    ); ?>
                </form>
                
                <form action="admin-post.php" method="post" onsubmit="return confirm('<?php echo esc_js(__('Möchtest du wirklich alle Scores neu berechnen? Dies kann je nach Inventargröße sehr lange dauern und API-Kosten verursachen.', 'lego-wawi')); ?>');">
                    <input type="hidden" name="action" value="lww_run_demand_analysis">
                    <input type="hidden" name="analysis_mode" value="all">
                    <?php wp_nonce_field('lww_demand_analysis_nonce'); ?>
                     <?php submit_button(
                        __('Analyse für ALLE Artikel neu starten', 'lego-wawi'),
                        'secondary',
                        'submit',
                        true,
                        $can_analyze ? null : ['disabled' => 'disabled']
                    ); ?>
                </form>
            </div>

            <?php if (!$can_analyze): ?>
                <p class="description"><?php _e('Die Buttons sind deaktiviert, da der API-Schlüssel für den ausgewählten Anbieter fehlt.', 'lego-wawi'); ?></p>
            <?php endif; ?>
        </div>

        <div class="lww-admin-form lww-card lww-mt-20">
            <h2><?php _e('SEO-Beschreibungen (KI-gestützt)', 'lego-wawi'); ?></h2>
            <p><?php _e('Dieses Werkzeug generiert SEO-optimierte Beschreibungen für deine Katalogeinträge (Sets, Minifiguren) und Kurzbeschreibungen für Teile. Der Prozess läuft im Hintergrund.', 'lego-wawi'); ?></p>

            <div style="display: flex; gap: 10px; align-items: flex-start;">
                <form action="admin-post.php" method="post">
                    <input type="hidden" name="action" value="lww_run_description_generation">
                    <input type="hidden" name="generation_mode" value="missing">
                    <?php wp_nonce_field('lww_description_generation_nonce'); ?>
                    <?php submit_button(
                        __('Beschreibungen für Einträge ohne Text generieren', 'lego-wawi'),
                        'primary',
                        'submit',
                        true,
                        $can_analyze ? null : ['disabled' => 'disabled']
                    ); ?>
                </form>
                
                <form action="admin-post.php" method="post" onsubmit="return confirm('<?php echo esc_js(__('Möchtest du wirklich alle Beschreibungen neu generieren? Dies überschreibt auch manuell geänderte Texte.', 'lego-wawi')); ?>');">
                    <input type="hidden" name="action" value="lww_run_description_generation">
                    <input type="hidden" name="generation_mode" value="all">
                    <?php wp_nonce_field('lww_description_generation_nonce'); ?>
                    <?php submit_button(
                        __('Beschreibungen für ALLE Einträge neu generieren', 'lego-wawi'),
                        'secondary',
                        'submit',
                        true,
                        $can_analyze ? null : ['disabled' => 'disabled']
                    ); ?>
                </form>
            </div>

            <?php if (!$can_analyze): ?>
                <p class="description"><?php _e('Die Buttons sind deaktiviert, da der API-Schlüssel für den ausgewählten Anbieter fehlt.', 'lego-wawi'); ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

?>
