<?php
/**
 * Modul: Handbuch & Hilfe (v1.1)
 * 
 * Update: Verbesserter Markdown-Parser mit ID-Generierung für Sprungmarken.
 */
if (!defined('ABSPATH')) exit;

function lww_render_manual_ui_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Sie haben keine Berechtigung, auf diese Seite zuzugreifen.', 'lego-wawi'));
    }

    $manual_path = LWW_PLUGIN_PATH . 'HANDBUCH.md';
    ?>
    <div class="wrap lww-wrap">
        <h1><img src="<?php echo esc_url(LWW_PLUGIN_URL . 'assets/img/bricksberg-logo.png'); ?>" alt="Bricksberg Logo" class="lww-header-logo" /> <?php _e('Handbuch & Hilfe', 'lego-wawi'); ?></h1>
        <p><?php _e('Hier finden Sie eine umfassende Anleitung zur Installation, Konfiguration und Nutzung des Bricksberg WaWi Plugins.', 'lego-wawi'); ?></p>
        
        <div class="lww-manual-content lww-card lww-mt-20">
            <style>
                .lww-manual-content { padding: 30px; line-height: 1.6; max-width: 900px; margin: 0 auto; background: #fff; }
                .lww-manual-content h1 { border-bottom: 2px solid #eee; padding-bottom: 10px; margin-top: 0; }
                .lww-manual-content h2 { margin-top: 40px; color: #0055bf; border-bottom: 1px solid #eee; padding-bottom: 5px; }
                .lww-manual-content h3 { margin-top: 25px; color: #237841; }
                .lww-manual-content ul { list-style-type: disc; margin-left: 20px; }
                .lww-manual-content code { background: #f0f0f1; padding: 2px 5px; border-radius: 3px; font-family: monospace; }
                .lww-manual-content a { text-decoration: none; color: #0073aa; }
                .lww-manual-content a:hover { text-decoration: underline; }
            </style>
            <?php
            if (file_exists($manual_path)) {
                $markdown_content = file_get_contents($manual_path);
                echo lww_parse_markdown_to_html($markdown_content);
            } else {
                echo '<div class="notice notice-error"><p>' . __('Fehler: Die Handbuch-Datei (HANDBUCH.md) konnte nicht gefunden werden.', 'lego-wawi') . '</p></div>';
            }
            ?>
        </div>
    </div>
    <?php
}

/**
 * Konvertiert Markdown zu HTML und fügt IDs für Sprungmarken hinzu.
 */
function lww_parse_markdown_to_html($text) {
    $text = str_replace(array("\r\n", "\r"), "\n", $text);
    $lines = explode("\n", $text);
    $html = '';
    $in_list = false;

    foreach ($lines as $line) {
        $trimmed = trim($line);
        
        // Headers mit ID-Generierung für TOC
        if (preg_match('/^(#{1,3})\s+(.*)$/', $line, $matches)) {
            if ($in_list) { $html .= "</ul>\n"; $in_list = false; }
            
            $level = strlen($matches[1]); // Anzahl der #
            $title = trim($matches[2]);
            $slug = sanitize_title($title);
            
            $html .= sprintf('<h%d id="%s">%s</h%d>\n', $level, $slug, $title, $level);
            continue;
        }

        // Trennlinie
        if (preg_match('/^---/', $line)) {
            if ($in_list) { $html .= "</ul>\n"; $in_list = false; }
            $html .= '<hr>' . "\n";
            continue;
        }

        // Listenpunkte
        if (preg_match('/^[\[\*\-]\s+(.*)/', $line, $matches)) {
            if (!$in_list) { $html .= "<ul>\n"; $in_list = true; }
            $content = lww_parse_inline_markdown(trim($matches[1]));
            $html .= '<li>' . $content . '</li>' . "\n";
            continue;
        }
        
        // Nummerierte Listen
        if (preg_match('/^\d+\.\s+(.*)/', $line, $matches)) {
             // Vereinfachung: Behandle nummerierte Listen hier als <ul> oder füge <ol> Logik hinzu
             // Für dieses Handbuch reicht einfache Konvertierung, um Struktur zu wahren
             if (!$in_list) { $html .= "<ul>\n"; $in_list = true; }
             $content = lww_parse_inline_markdown(trim($matches[1]));
             $html .= '<li>' . $content . '</li>' . "\n";
             continue;
        }

        // Listen-Ende Erkennung
        if ($in_list && empty($trimmed)) {
            $html .= "</ul>\n";
            $in_list = false;
        }

        // Normale Absätze
        if (!empty($trimmed)) {
            if ($in_list) { $html .= "</ul>\n"; $in_list = false; }
            $html .= '<p>' . lww_parse_inline_markdown($trimmed) . '</p>' . "\n";
        }
    }
    
    if ($in_list) { $html .= "</ul>\n"; }
    
    return $html;
}

function lww_parse_inline_markdown($text) {
    // Fett
    $text = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $text);
    // Code
    $text = preg_replace('/`(.*?)`/', '<code>$1</code>', $text);
    // Links [Text](URL)
    $text = preg_replace('/\[(.*?)\]\((.*?)\)/', '<a href="$2">$1</a>', $text);
    // Pfeile
    $text = str_replace('&rarr;', '→', $text);
    return $text;
}
?>
