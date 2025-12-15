<?php
/**
 * Modul: Minifiguren-Verwaltung UI (v1.2)
 * UPDATE: Bulk-Action Verarbeitung auf admin_init umgestellt für bessere Stabilität.
 */
if (!defined('ABSPATH')) exit;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

// Handler
add_action('wp_ajax_lww_get_ebay_meta_form', 'lww_ajax_get_ebay_meta_form_handler');
add_action('wp_ajax_lww_save_ebay_meta', 'lww_ajax_save_ebay_meta_handler');
// KORREKTUR: Handler auf admin_init verschoben, um WP_List_Table Konflikte zu lösen
add_action('admin_init', 'lww_process_minifig_bulk_actions');

function lww_render_minifigs_ui_page() {
    if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
    add_thickbox();

    $minifigs_table = new LWW_Minifigs_List_Table();
    $minifigs_table->prepare_items();
    ?>
    <div class="wrap lww-wrap">
        <h1><img src="<?php echo esc_url(LWW_PLUGIN_URL . 'assets/img/bricksberg-logo.png'); ?>" alt="Logo" class="lww-header-logo" /> <?php _e('Minifiguren-Verwaltung', 'lego-wawi'); ?></h1>
        <p><?php _e('Verwalten Sie Ihre Minifiguren für eBay und WooCommerce.', 'lego-wawi'); ?></p>
        
        <?php settings_errors('lww_messages'); ?>

        <div class="lww-card lww-mt-20">
            <!-- Formularziel ist die aktuelle Seite -->
            <form id="lww-minifigs-filter" method="post">
                <?php 
                $minifigs_table->search_box(__('Minifiguren suchen', 'lego-wawi'), 'lww-minifigs-search');
                $minifigs_table->display(); 
                ?>
            </form>
        </div>
    </div>
    <?php
}

class LWW_Minifigs_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct([
            'singular' => __('Minifigur-Inventar', 'lego-wawi'),
            'plural'   => __('Minifiguren-Inventar', 'lego-wawi'),
            'ajax'     => false
        ]);
    }

    public function get_columns() {
        return [
            'cb'            => '<input type="checkbox" />',
            'thumbnail'     => __('Bild', 'lego-wawi'),
            'name'          => __('Minifigur', 'lego-wawi'),
            'inventory'     => __('Inventar-Details', 'lego-wawi'),
            'ebay_status'   => __('eBay-Status', 'lego-wawi'),
            'actions'       => __('Aktionen', 'lego-wawi'),
        ];
    }

    protected function get_bulk_actions() {
        return [
            'wc_sync_batch' => __('Zu WooCommerce übertragen (Batch)', 'lego-wawi')
        ];
    }

    public function prepare_items() {
        $this->_column_headers = [$this->get_columns(), [], []];
        $query = new WP_Query([
            'post_type' => 'lww_inventory_item',
            'posts_per_page' => 20,
            'paged' => $this->get_pagenum(),
            'meta_key' => '_lww_minifig_id',
            'meta_compare' => 'EXISTS',
            's' => $_REQUEST['s'] ?? '',
        ]);
        $this->items = $query->posts;
        $this->set_pagination_args(['total_items' => $query->found_posts, 'per_page' => 20]);
    }

    function column_cb($item) {
        return sprintf('<input type="checkbox" name="item_ids[]" value="%s" />', $item->ID);
    }

    function column_thumbnail($item) {
        $minifig_id = get_post_meta($item->ID, '_lww_minifig_id', true);
        if ($minifig_id) lww_render_catalog_columns('thumbnail', $minifig_id);
    }

    function column_name($item) {
        $minifig_id = get_post_meta($item->ID, '_lww_minifig_id', true);
        if ($minifig_id) {
            $minifig_num = get_post_meta($minifig_id, '_lww_minifig_num', true);
            return sprintf('<strong><a href="%s">%s</a></strong><br><small>%s</small>', get_edit_post_link($minifig_id), get_the_title($minifig_id), esc_html($minifig_num));
        }
        return get_the_title($item->ID);
    }

    function column_inventory($item) {
        $quantity = (int) get_post_meta($item->ID, '_quantity', true);
        $price = (float) get_post_meta($item->ID, '_price', true);
        $condition = get_post_meta($item->ID, '_condition', true);
        return sprintf('<strong>Menge:</strong> %d<br><strong>Preis:</strong> %s €<br><strong>Zustand:</strong> %s', $quantity, number_format_i18n($price, 2), esc_html(ucfirst($condition)));
    }

    function column_ebay_status($item) {
        $listing_id = get_post_meta($item->ID, '_lww_ebay_listing_id', true);
        if ($listing_id) {
            return sprintf('<a href="https://www.ebay.de/itm/%s" target="_blank" class="lww-marketplace-badge lww-marketplace-ebay">Auf eBay</a>', esc_attr($listing_id));
        }
        return '<span style="color: #787c82;">' . __('Nicht gelistet', 'lego-wawi') . '</span>';
    }

    function column_actions($item) {
        return sprintf('<button class="button button-secondary button-small lww-edit-ebay-meta" data-item-id="%d">eBay-Daten</button> <button class="button button-primary button-small lww-ajax-create-ebay-listing" data-item-id="%d">eBay Sync</button>', $item->ID, $item->ID);
    }
}

/**
 * Verarbeitet die Bulk-Actions für Minifiguren direkt im Admin-Init.
 */
function lww_process_minifig_bulk_actions() {
    // Prüfen, ob wir auf der richtigen Seite sind
    if (!isset($_GET['page']) || $_GET['page'] !== 'lww_minifigs_ui') return;
    
    // Prüfen ob POST Request
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;

    // Action ermitteln (kann in 'action' oder 'action2' stehen)
    $action = $_POST['action'] ?? '';
    if ($action === '-1') {
        $action = $_POST['action2'] ?? '';
    }

    if ($action === 'wc_sync_batch') {
        // Sicherheit: Nonce prüfen
        // WP_List_Table generiert Nonce basierend auf dem Plural-Namen
        check_admin_referer('bulk-minifiguren-inventar');

        if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
        
        $item_ids = isset($_POST['item_ids']) ? array_map('absint', $_POST['item_ids']) : [];
        
        if (!empty($item_ids)) {
            // Job erstellen
            $job_id = wp_insert_post([
                'post_title' => sprintf(__('WooCommerce Batch-Sync für %d Minifiguren', 'lego-wawi'), count($item_ids)) . ' - ' . date_i18n('d.m.Y H:i'),
                'post_type' => 'lww_job',
                'post_status' => 'lww_pending',
                'post_author' => get_current_user_id()
            ]);

            if (!is_wp_error($job_id)) {
                update_post_meta($job_id, '_job_type', 'wc_sync_batch');
                update_post_meta($job_id, '_item_ids_to_process', $item_ids);
                update_post_meta($job_id, '_total_items', count($item_ids));
                update_post_meta($job_id, '_processed_items', 0);
                
                lww_log_to_job($job_id, sprintf('Batch-Job erstellt für %d Items.', count($item_ids)));
                
                // Trigger Cron sofort (Best Effort)
                lww_start_cron_job();
                
                add_settings_error('lww_messages', 'batch_started', sprintf(__('Batch-Job (ID: %d) gestartet. Sie können den Fortschritt im Tab "Jobs" verfolgen.', 'lego-wawi'), $job_id), 'success');
            } else {
                add_settings_error('lww_messages', 'job_error', __('Fehler beim Erstellen des Jobs.', 'lego-wawi'), 'error');
            }
        } else {
            add_settings_error('lww_messages', 'no_selection', __('Keine Minifiguren ausgewählt.', 'lego-wawi'), 'warning');
        }
    }
}

// --- AJAX Handlers für Modals ---
function lww_ajax_get_ebay_meta_form_handler() {
    check_ajax_referer('lww_minifigs_ajax_nonce');
    $item_id = absint($_GET['item_id']);
    $post = get_post($item_id);
    if(!$post) wp_die('Item not found');
    
    $ebay_title = get_post_meta($item_id, '_lww_ebay_title', true);
    $ebay_desc = get_post_meta($item_id, '_lww_ebay_condition_description', true);
    $gallery_ids = get_post_meta($item_id, '_lww_ebay_gallery_images', true);
    
    echo '<form id="lww-ebay-meta-form">';
    echo '<input type="hidden" name="action" value="lww_save_ebay_meta">';
    echo '<input type="hidden" name="item_id" value="'.esc_attr($item_id).'">';
    wp_nonce_field('lww_minifigs_ajax_nonce');
    
    echo '<p><label><strong>Titel für eBay</strong></label><br><input type="text" name="ebay_title" class="widefat" value="'.esc_attr($ebay_title).'"></p>';
    echo '<p><label><strong>Zustandsbeschreibung</strong></label><br><textarea name="ebay_description" class="widefat" rows="5">'.esc_textarea($ebay_desc).'</textarea></p>';
    
    echo '<p><label><strong>Galerie-Bilder</strong></label><br>';
    echo '<button id="lww-upload-gallery-button" class="button">Bilder auswählen</button>';
    echo '<input type="hidden" name="ebay_gallery_images" id="lww_ebay_gallery_images" value="'.esc_attr($gallery_ids).'">';
    echo '<div id="lww-image-gallery-container" style="display:flex; gap:10px; flex-wrap:wrap; margin-top:10px;">';
    
    if($gallery_ids) {
        $ids = explode(',', $gallery_ids);
        foreach($ids as $img_id) {
            echo '<div class="lww-gallery-image" data-id="'.esc_attr($img_id).'"><img src="'.wp_get_attachment_thumb_url($img_id).'" style="width:60px;height:60px;object-fit:cover;" /><a href="#" class="remove-image" style="color:red;display:block;text-align:center;">&times;</a></div>';
        }
    }
    echo '</div></p>';
    
    echo '<p><button type="submit" class="button button-primary">Speichern</button></p>';
    echo '</form>';
    wp_die();
}

function lww_ajax_save_ebay_meta_handler() {
    check_ajax_referer('lww_minifigs_ajax_nonce');
    $item_id = absint($_POST['item_id']);
    if(!current_user_can('edit_post', $item_id)) wp_send_json_error();
    
    update_post_meta($item_id, '_lww_ebay_title', sanitize_text_field($_POST['ebay_title']));
    update_post_meta($item_id, '_lww_ebay_condition_description', sanitize_textarea_field($_POST['ebay_description']));
    update_post_meta($item_id, '_lww_ebay_gallery_images', sanitize_text_field($_POST['ebay_gallery_images']));
    
    wp_send_json_success(['message' => 'Daten gespeichert.']);
}
