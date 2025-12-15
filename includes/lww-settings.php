<?php
/**
 * Modul: Einstellungen (v22.3-UX)
 * 
 * Hinzugefügt: Bessere Platzhalter für externe Store Keys.
 */
if (!defined('ABSPATH')) exit;

add_action('wp_ajax_lww_add_external_store', 'lww_ajax_add_external_store');
add_action('wp_ajax_lww_delete_external_store', 'lww_ajax_delete_external_store');
add_action('wp_ajax_lww_test_external_store', 'lww_ajax_test_external_store');

function lww_register_settings() {
    // Haupt-Option für API-Settings (Array)
    register_setting('lww_settings_group', 'lww_api_settings', ['sanitize_callback' => 'lww_sanitize_api_settings']);

    // Einzelne Optionen
    register_setting('lww_settings_group', 'lww_price_strategy_source');
    register_setting('lww_settings_group', 'lww_price_strategy_modifier');
    register_setting('lww_settings_group', 'lww_price_strategy_rounding');
    
    register_setting('lww_settings_group', 'lww_ai_provider');
    register_setting('lww_settings_group', 'lww_translation_provider');
    
    register_setting('lww_settings_group', 'lww_cron_interval');
    register_setting('lww_settings_group', 'lww_adaptive_delay');
    
    // Job Prioritäten
    $priorities = [
        'lww_job_priority_catalog_import', 
        'lww_job_priority_inventory_import', 
        'lww_job_priority_bricklink_order_sync',
        'lww_job_priority_brickowl_inventory_sync',
        'lww_job_priority_description_generation',
        'lww_job_priority_demand_analysis'
    ];
    foreach($priorities as $p) register_setting('lww_settings_group', $p);

    // --- SEKTION 1: API KEYS ---
    add_settings_section('lww_api_main', __('Marktplatz & Katalog APIs', 'lego-wawi'), null, 'lww_settings_api');

    $api_fields = [
        'bricklink_consumer_key' => ['label' => 'BrickLink Consumer Key', 'help' => 'Registrieren Sie eine Anwendung unter <a href="https://www.bricklink.com/v2/api/register_consumer.page" target="_blank">BrickLink API</a>.'],
        'bricklink_consumer_secret' => ['label' => 'BrickLink Consumer Secret', 'help' => ''],
        'bricklink_token_value' => ['label' => 'BrickLink Token Value', 'help' => ''],
        'bricklink_token_secret' => ['label' => 'BrickLink Token Secret', 'help' => ''],
        'brickowl_api_key' => ['label' => 'BrickOwl API Key', 'help' => 'Zu finden in Ihrem Profil unter <a href="https://www.brickowl.com/user" target="_blank">BrickOwl User Settings</a>.'],
        'rebrickable_api_key' => ['label' => 'Rebrickable API Key', 'help' => 'Erstellen Sie einen API Key unter <a href="https://rebrickable.com/api/" target="_blank">Rebrickable API</a> (v3).'],
        'brickset_api_key' => ['label' => 'Brickset API Key', 'help' => 'Beantragen Sie einen Key unter <a href="https://brickset.com/tools/webservices/requestkey" target="_blank">Brickset Web Services</a>.'],
        'ebay_app_id' => ['label' => 'eBay App ID (Client ID)', 'help' => 'Aus dem <a href="https://developer.ebay.com/" target="_blank">eBay Developer Program</a>.'],
        'ebay_cert_id' => ['label' => 'eBay Cert ID (Client Secret)', 'help' => ''],
        'ebay_dev_id' => ['label' => 'eBay Dev ID', 'help' => ''],
        'ebay_auth_token' => ['label' => 'eBay Auth Token (User Token)', 'help' => 'Generieren Sie einen User Token über das eBay Developer "User Access Tokens" Tool.'],
    ];

    foreach ($api_fields as $key => $data) {
        add_settings_field(
            'lww_api_' . $key, 
            $data['label'], 
            'lww_render_api_field', 
            'lww_settings_api', 
            'lww_api_main', 
            ['key' => $key, 'description' => $data['help']]
        );
    }

    add_settings_section('lww_api_services', __('KI & Dienste', 'lego-wawi'), null, 'lww_settings_api');
    add_settings_field('lww_openai_key', 'OpenAI API Key', 'lww_render_api_field', 'lww_settings_api', 'lww_api_services', ['key' => 'openai_api_key', 'description' => 'Erstellen Sie einen Key unter <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI Platform</a>.']);
    add_settings_field('lww_gemini_key', 'Google Gemini API Key', 'lww_render_api_field', 'lww_settings_api', 'lww_api_services', ['key' => 'gemini_api_key', 'description' => 'Erhalten Sie einen Key im <a href="https://aistudio.google.com/app/apikey" target="_blank">Google AI Studio</a>.']);
    add_settings_field('lww_deepl_key', 'DeepL API Key', 'lww_render_api_field', 'lww_settings_api', 'lww_api_services', ['key' => 'deepl_api_key', 'description' => 'Abonnieren Sie die API (Free oder Pro) unter <a href="https://www.deepl.com/pro-api" target="_blank">DeepL API</a>.']);

    // --- SEKTION 2: PREISPOLITIK ---
    add_settings_section('lww_pricing_main', __('Preisstrategie', 'lego-wawi'), null, 'lww_settings_pricing');
    add_settings_field('lww_price_strategy_source', 'Basis-Preisquelle', 'lww_render_select_field', 'lww_settings_pricing', 'lww_pricing_main', [
        'option_name' => 'lww_price_strategy_source',
        'options' => [
            'bricklink_avg' => 'BrickLink Durchschnitt (Avg)',
            'bricklink_min' => 'BrickLink Minimum (Current)',
            'brickowl_avg' => 'BrickOwl Durchschnitt',
            'brickowl_min' => 'BrickOwl Minimum'
        ]
    ]);
    add_settings_field('lww_price_strategy_modifier', 'Preisaufschlag (%)', 'lww_render_number_field', 'lww_settings_pricing', 'lww_pricing_main', [
        'option_name' => 'lww_price_strategy_modifier',
        'description' => 'Prozentualer Aufschlag auf den Basispreis (z.B. 10 für +10%).'
    ]);
    add_settings_field('lww_price_strategy_rounding', 'Rundungsregel', 'lww_render_select_field', 'lww_settings_pricing', 'lww_pricing_main', [
        'option_name' => 'lww_price_strategy_rounding',
        'options' => [
            'none' => 'Keine Rundung (3 Dezimalstellen)',
            '0.05' => 'Auf 0.05 runden',
            '0.09' => 'Auf .9 Endung (Psychologisch)',
            '0.99' => 'Auf .99 Endung'
        ]
    ]);

    // --- SEKTION 3: KI ---
    add_settings_section('lww_ai_main', __('Künstliche Intelligenz', 'lego-wawi'), null, 'lww_settings_ai');
    add_settings_field('lww_ai_provider', 'Bevorzugter KI-Anbieter', 'lww_render_select_field', 'lww_settings_ai', 'lww_ai_main', [
        'option_name' => 'lww_ai_provider',
        'options' => ['openai' => 'OpenAI (GPT-3.5/4)', 'gemini' => 'Google Gemini']
    ]);
    add_settings_field('lww_translation_provider', 'Übersetzungs-Dienst', 'lww_render_select_field', 'lww_settings_ai', 'lww_ai_main', [
        'option_name' => 'lww_translation_provider',
        'options' => ['deepl' => 'DeepL (Empfohlen)', 'ai' => 'Generative KI (OpenAI/Gemini)']
    ]);

    // --- SEKTION 4: PERFORMANCE ---
    add_settings_section('lww_perf_main', __('Systemleistung', 'lego-wawi'), null, 'lww_settings_performance');
    add_settings_field('lww_cron_interval', 'Hintergrund-Intervall', 'lww_render_select_field', 'lww_settings_performance', 'lww_perf_main', [
        'option_name' => 'lww_cron_interval',
        'options' => [
            'lww_every_minute' => 'Jede Minute (Standard)',
            'lww_every_5_minutes' => 'Alle 5 Minuten (Ressourcensparend)',
            'lww_every_30_seconds' => 'Alle 30 Sekunden (High Performance)'
        ]
    ]);
    add_settings_field('lww_adaptive_delay', 'Adaptive Verzögerung (ms)', 'lww_render_number_field', 'lww_settings_performance', 'lww_perf_main', [
        'option_name' => 'lww_adaptive_delay',
        'description' => 'Zusätzliche Pause zwischen API-Calls in Millisekunden.'
    ]);

    // --- SEKTION 5: JOB PRIORITÄTEN ---
    add_settings_section('lww_prio_main', __('Job Prioritäten (Niedriger = Wichtiger)', 'lego-wawi'), null, 'lww_settings_priorities');
    foreach($priorities as $p_opt) {
        add_settings_field($p_opt, str_replace(['lww_job_priority_', '_'], ['', ' '], $p_opt), 'lww_render_number_field', 'lww_settings_priorities', 'lww_prio_main', ['option_name' => $p_opt]);
    }
}
add_action('admin_init', 'lww_register_settings');

function lww_settings_page_html() { 
    if (!current_user_can('manage_options')) return;
    ?>
    <div class="wrap lww-wrap">
        <h1>Einstellungen</h1>
        <?php settings_errors(); ?>
        
        <h2 class="nav-tab-wrapper">
            <a href="#lww_settings_api" class="nav-tab nav-tab-active">APIs & Keys</a>
            <a href="#lww_settings_stores" class="nav-tab">Externe Shops (Multi-Woo)</a>
            <a href="#lww_settings_pricing" class="nav-tab">Pricing</a>
            <a href="#lww_settings_ai" class="nav-tab">KI & Analyse</a>
            <a href="#lww_settings_performance" class="nav-tab">Performance</a>
            <a href="#lww_settings_priorities" class="nav-tab">Jobs</a>
        </h2>

        <form action="options.php" method="post" class="lww-settings-page-form">
            <?php 
            settings_fields('lww_settings_group'); 
            ?>
            <input type="hidden" id="lww_active_tab_input" name="lww_active_tab" value="">

            <!-- Container für Tabs -->
            
            <div id="lww_settings_api" class="lww-settings-tab-content">
                <?php do_settings_sections('lww_settings_api'); ?>
            </div>

            <div id="lww_settings_stores" class="lww-settings-tab-content" style="display:none;">
                <h3>Verbundene WooCommerce Shops</h3>
                <p class="description">Verwalten Sie hier Verbindungen zu weiteren WooCommerce-Instanzen für den Bestandsabgleich.</p>
                <div id="lww-external-stores-list">
                    <?php lww_render_external_stores_table(); ?>
                </div>
                
                <hr>
                <h3>Neuen Shop hinzufügen</h3>
                <table class="form-table">
                    <tr>
                        <th><label>Name (Intern)</label></th>
                        <td><input type="text" id="new_store_name" class="regular-text" placeholder="z.B. Shop B"></td>
                    </tr>
                    <tr>
                        <th><label>Shop URL</label></th>
                        <td><input type="url" id="new_store_url" class="regular-text" placeholder="https://mein-shop.de"></td>
                    </tr>
                    <tr>
                        <th><label>Consumer Key (CK)</label></th>
                        <td><input type="text" id="new_store_key" class="regular-text" placeholder="ck_xxxxxxxxxxxxxxxxxxxxxxxx"></td>
                    </tr>
                    <tr>
                        <th><label>Consumer Secret (CS)</label></th>
                        <td><input type="password" id="new_store_secret" class="regular-text" placeholder="cs_xxxxxxxxxxxxxxxxxxxxxxxx"></td>
                    </tr>
                </table>
                <p><button type="button" class="button button-primary" id="lww-add-store-btn">Shop hinzufügen</button> <span class="spinner" id="lww-store-spinner"></span></p>
            </div>

            <div id="lww_settings_pricing" class="lww-settings-tab-content" style="display:none;">
                <?php do_settings_sections('lww_settings_pricing'); ?>
            </div>

            <div id="lww_settings_ai" class="lww-settings-tab-content" style="display:none;">
                <?php do_settings_sections('lww_settings_ai'); ?>
            </div>

            <div id="lww_settings_performance" class="lww-settings-tab-content" style="display:none;">
                <?php do_settings_sections('lww_settings_performance'); ?>
            </div>
            
            <div id="lww_settings_priorities" class="lww-settings-tab-content" style="display:none;">
                <?php do_settings_sections('lww_settings_priorities'); ?>
            </div>

            <p class="submit">
                <?php submit_button('Änderungen speichern', 'primary', 'submit', false); ?>
            </p>
        </form>
    </div>
    <?php
}

// --- MULTI-STORE HELPER & AJAX ---

function lww_render_external_stores_table() {
    $stores = get_option('lww_external_stores', []);
    if (empty($stores)) {
        echo '<p><em>Noch keine externen Shops verbunden.</em></p>';
        return;
    }
    ?>
    <table class="wp-list-table widefat striped">
        <thead><tr><th>Name</th><th>URL</th><th>Status</th><th>Aktionen</th></tr></thead>
        <tbody>
            <?php foreach ($stores as $id => $store): ?>
                <tr>
                    <td><strong><?php echo esc_html($store['name']); ?></strong></td>
                    <td><?php echo esc_url($store['url']); ?></td>
                    <td><span class="lww-store-status" data-id="<?php echo esc_attr($id); ?>">Unknown</span></td>
                    <td>
                        <button type="button" class="button button-small lww-test-store" data-id="<?php echo esc_attr($id); ?>">Verbindung testen</button>
                        <button type="button" class="button button-small button-link-delete lww-delete-store" data-id="<?php echo esc_attr($id); ?>">Entfernen</button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

function lww_ajax_add_external_store() {
    check_ajax_referer('lww_settings_ajax_nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Forbidden');

    $name = sanitize_text_field($_POST['name']);
    $url = esc_url_raw($_POST['url']);
    $key = sanitize_text_field($_POST['key']);
    $secret = sanitize_text_field($_POST['secret']);

    if (!$name || !$url || !$key || !$secret) wp_send_json_error('Bitte alle Felder ausfüllen.');

    $stores = get_option('lww_external_stores', []);
    $id = uniqid('store_');
    
    $stores[$id] = [
        'name' => $name, 
        'url' => $url, 
        'key' => $key, 
        'secret' => $secret
    ];

    update_option('lww_external_stores', $stores);
    
    ob_start();
    lww_render_external_stores_table();
    wp_send_json_success(['html' => ob_get_clean()]);
}

function lww_ajax_delete_external_store() {
    check_ajax_referer('lww_settings_ajax_nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Forbidden');
    $id = sanitize_text_field($_POST['id']);
    $stores = get_option('lww_external_stores', []);
    if (isset($stores[$id])) {
        unset($stores[$id]);
        update_option('lww_external_stores', $stores);
    }
    ob_start();
    lww_render_external_stores_table();
    wp_send_json_success(['html' => ob_get_clean()]);
}

function lww_ajax_test_external_store() {
    check_ajax_referer('lww_settings_ajax_nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Forbidden');
    $id = sanitize_text_field($_POST['id']);
    $stores = get_option('lww_external_stores', []);
    
    if (!isset($stores[$id])) wp_send_json_error('Store nicht gefunden.');
    $store = $stores[$id];

    // Connection Test using WooCommerce API
    $api_url = trailingslashit($store['url']) . 'wp-json/wc/v3/system_status';
    $auth = base64_encode($store['key'] . ':' . $store['secret']);
    
    $response = wp_remote_get($api_url, [
        'headers' => ['Authorization' => 'Basic ' . $auth],
        'timeout' => 10
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error('Verbindungsfehler: ' . $response->get_error_message());
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code === 200) {
        wp_send_json_success('Verbindung erfolgreich!');
    } else {
        wp_send_json_error("Fehler: HTTP $code. Prüfen Sie URL/Keys.");
    }
}

// --- CALLBACKS ---
function lww_sanitize_api_settings($input) {
    $new_input = [];
    if(is_array($input)) {
        foreach($input as $key => $val) $new_input[$key] = sanitize_text_field($val);
    }
    return $new_input;
}

function lww_render_api_field($args) {
    $options = get_option('lww_api_settings');
    $key = $args['key'];
    $value = isset($options[$key]) ? $options[$key] : '';
    $type = (strpos($key, 'secret') !== false || strpos($key, 'token') !== false || strpos($key, 'key') !== false) ? 'password' : 'text';
    echo '<input type="'.esc_attr($type).'" name="lww_api_settings['.esc_attr($key).']" value="'.esc_attr($value).'" class="regular-text">';
    if (!empty($args['description'])) {
        echo '<p class="description">' . $args['description'] . '</p>';
    }
}
function lww_render_text_field($args) {
    $val = get_option($args['option_name']);
    echo '<input type="text" name="'.esc_attr($args['option_name']).'" value="'.esc_attr($val).'" class="regular-text">';
}
function lww_render_number_field($args) {
    $val = get_option($args['option_name']);
    echo '<input type="number" name="'.esc_attr($args['option_name']).'" value="'.esc_attr($val).'" class="small-text">';
    if (isset($args['description'])) echo '<p class="description">'.esc_html($args['description']).'</p>';
}
function lww_render_select_field($args) {
    $val = get_option($args['option_name']);
    echo '<select name="'.esc_attr($args['option_name']).'">';
    foreach($args['options'] as $k => $v) {
        printf('<option value="%s" %s>%s</option>', $k, selected($val, $k, false), $v);
    }
    echo '</select>';
}
?>