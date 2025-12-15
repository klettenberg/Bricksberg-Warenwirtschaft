<?php
/**
 * Modul: Elementor Integration
 * Registriert benutzerdefinierte Widgets und Kategorien für Elementor.
 */
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

final class LWW_Elementor_Integration {

    private static $_instance = null;

    public static function instance() {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    private function __construct() {
        add_action('elementor/elements/categories_registered', [$this, 'register_widget_category']);
        add_action('elementor/widgets/widgets_registered', [$this, 'register_widgets']);

        // AJAX Handler für die Artikelsuche
        add_action('wp_ajax_lww_elementor_search_items', [$this, 'ajax_search_items']);
    }

    /**
     * Registriert eine benutzerdefinierte Widget-Kategorie.
     */
    public function register_widget_category($elements_manager) {
        $elements_manager->add_category(
            'lww-category',
            [
                'title' => __('Bricksberg WaWi', 'lego-wawi'),
                'icon' => 'fa fa-plug',
            ]
        );
    }

    /**
     * Lädt und registriert die benutzerdefinierten Widgets.
     */
    public function register_widgets($widgets_manager) {
        // Prüfen, ob die Widget-Basisklasse existiert
        if (!class_exists('\Elementor\Widget_Base')) {
            return;
        }

        // Widget-Dateien einbinden
        require_once LWW_PLUGIN_PATH . 'includes/elementor-widgets/class-lww-item-list-widget.php';
        require_once LWW_PLUGIN_PATH . 'includes/elementor-widgets/class-lww-product-showcase-widget.php'; // NEU

        // Widgets registrieren
        $widgets_manager->register(new LWW_Elementor_Item_List_Widget());
        $widgets_manager->register(new LWW_Product_Showcase_Widget()); // NEU
    }

    /**
     * AJAX-Handler für die Artikelsuche in Elementor-Widgets.
     */
    public function ajax_search_items() {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error([], 403);
        }

        $search_term = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';

        if (empty($search_term)) {
            wp_send_json_success(['results' => []]);
        }

        $query = new WP_Query([
            'post_type' => ['lww_part', 'lww_set', 'lww_minifig'],
            'post_status' => 'publish',
            'posts_per_page' => 20,
            's' => $search_term,
        ]);

        $results = [];
        if ($query->have_posts()) {
            foreach ($query->posts as $post) {
                $post_type_object = get_post_type_object($post->post_type);
                $type_label = $post_type_object ? $post_type_object->labels->singular_name : '';
                $item_number = get_post_meta($post->ID, '_lww_' . str_replace('lww_', '', $post->post_type) . '_num', true);

                $results[] = [
                    'id' => $post->ID,
                    'text' => sprintf('[%s] %s (%s)', $type_label, $post->post_title, $item_number),
                ];
            }
        }

        wp_send_json_success(['results' => $results]);
    }
}

// Elementor erst initialisieren, wenn es geladen ist.
add_action('elementor/init', function() {
    LWW_Elementor_Integration::instance();
});
