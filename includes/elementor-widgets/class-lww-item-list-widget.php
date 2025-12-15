<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class LWW_Elementor_Item_List_Widget extends \Elementor\Widget_Base {

    public function get_name() {
        return 'lww-item-list';
    }

    public function get_title() {
        return __('LEGO Artikel-Liste', 'lego-wawi');
    }

    public function get_icon() {
        return 'eicon-bullet-list';
    }

    public function get_categories() {
        return ['lww-category'];
    }

    protected function _register_controls() {
        $this->start_controls_section(
            'content_section',
            [
                'label' => __('Inhalt', 'lego-wawi'),
                'tab' => \Elementor\Controls_Manager::TAB_CONTENT,
            ]
        );

        $repeater = new \Elementor\Repeater();

        $repeater->add_control(
            'item_id',
            [
                'label' => __('Artikel auswählen', 'lego-wawi'),
                'type' => \Elementor\Controls_Manager::SELECT2,
                'label_block' => true,
                'options' => [], // Wird per AJAX gefüllt
                'select2options' => [
                    'ajax' => [
                        'url' => admin_url('admin-ajax.php'),
                        'dataType' => 'json',
                        'data' => 'js:function(params) { return { q: params.term, action: "lww_elementor_search_items" }; }',
                        'processResults' => 'js:function(data) { return data.data; }',
                    ],
                    'minimumInputLength' => 2,
                ],
            ]
        );

        $repeater->add_control(
            'override_price',
            [
                'label' => __('Preis überschreiben (optional)', 'lego-wawi'),
                'type' => \Elementor\Controls_Manager::NUMBER,
                'step' => 0.01,
                'placeholder' => __('z.B. 1.23', 'lego-wawi'),
            ]
        );

        $this->add_control(
            'item_list',
            [
                'label' => __('Artikel', 'lego-wawi'),
                'type' => \Elementor\Controls_Manager::REPEATER,
                'fields' => $repeater->get_controls(),
                'default' => [],
                'title_field' => '{{{ item_id }}}',
            ]
        );

        $this->end_controls_section();

        $this->start_controls_section(
            'layout_section',
            [
                'label' => __('Layout', 'lego-wawi'),
                'tab' => \Elementor\Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_control(
            'layout',
            [
                'label' => __('Darstellung', 'lego-wawi'),
                'type' => \Elementor\Controls_Manager::SELECT,
                'default' => 'list',
                'options' => [
                    'list' => __('Einfache Liste', 'lego-wawi'),
                    'grid' => __('Zweispaltiges Gitter', 'lego-wawi'),
                ],
            ]
        );

        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();

        if (empty($settings['item_list'])) {
            return;
        }

        // Lade die Frontend-Assets, wenn das Widget gerendert wird
        if (class_exists('LWW_Shortcodes')) {
            LWW_Shortcodes::set_assets_flag();
        }

        $layout_class = 'lww-elementor-item-list--' . esc_attr($settings['layout']);
        $tag = ($settings['layout'] === 'list') ? 'ul' : 'div';

        echo '<' . $tag . ' class="lww-elementor-item-list ' . $layout_class . '">';

        foreach ($settings['item_list'] as $item) {
            $post_id = (int)$item['item_id'];
            if (!$post_id) {
                continue;
            }

            $post = get_post($post_id);
            if (!$post) {
                continue;
            }

            $price = null;
            if (isset($item['override_price']) && is_numeric($item['override_price']) && $item['override_price'] !== '') {
                $price = (float)$item['override_price'];
            } else {
                // Nutze die neue zentrale Funktion zur Preisfindung.
                if (function_exists('lww_get_price_for_catalog_item')) {
                    $price = lww_get_price_for_catalog_item($post_id);
                }
            }
            
            $post_type = get_post_type($post_id);
            $german_name_key = '_lww_' . str_replace('lww_', '', $post_type) . '_name_de';
            $german_name = get_post_meta($post_id, $german_name_key, true);
            $display_title = !empty($german_name) ? $german_name : $post->post_title;

            $item_tag = ($settings['layout'] === 'list') ? 'li' : 'div';

            // NEU: Hole die korrekte URL (Produktseite oder Detailseite)
            $url = function_exists('lww_get_wc_product_url_for_catalog_item') ? lww_get_wc_product_url_for_catalog_item($post_id) : get_permalink($post_id);

            echo '<' . $item_tag . ' class="lww-elementor-item">';
            echo '<a href="' . esc_url($url) . '" style="display: contents;">'; // Link um den Inhalt legen
            echo '<span class="item-name">' . esc_html($display_title) . '</span>';
            if ($price !== null) {
                echo '<span class="item-price">' . esc_html(number_format_i18n($price, 2)) . ' €</span>';
            }
            echo '</a>';
            echo '</' . $item_tag . '>';
        }

        echo '</' . $tag . '>';
    }
}
