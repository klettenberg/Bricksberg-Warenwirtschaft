<?php
if (!defined('ABSPATH')) exit;

/**
 * Elementor Widget: Product Showcase (v0.9.0)
 * Eine professionelle Präsentation für die Bricksberg WaWi Software.
 */
class LWW_Product_Showcase_Widget extends \Elementor\Widget_Base {

    public function get_name() {
        return 'lww-product-showcase';
    }

    public function get_title() {
        return __('Bricksberg Product Showcase', 'lego-wawi');
    }

    public function get_icon() {
        return 'eicon-slideshow';
    }

    public function get_categories() {
        return ['lww-category'];
    }

    protected function _register_controls() {
        // --- Content Section ---
        $this->start_controls_section(
            'content_section',
            [
                'label' => __('Inhalt', 'lego-wawi'),
                'tab' => \Elementor\Controls_Manager::TAB_CONTENT,
            ]
        );

        $this->add_control(
            'title',
            [
                'label' => __('Titel', 'lego-wawi'),
                'type' => \Elementor\Controls_Manager::TEXT,
                'default' => __('Bricksberg WaWi', 'lego-wawi'),
            ]
        );

        $this->add_control(
            'subtitle',
            [
                'label' => __('Untertitel', 'lego-wawi'),
                'type' => \Elementor\Controls_Manager::TEXTAREA,
                'default' => __('Die ultimative Lösung für Ihren LEGO®-Handel.', 'lego-wawi'),
            ]
        );

        $this->add_control(
            'features',
            [
                'label' => __('Features (Liste)', 'lego-wawi'),
                'type' => \Elementor\Controls_Manager::WYSIWYG,
                'default' => '<ul><li>KI-Pricing</li><li>Multi-Channel Sync</li><li>Lagerverwaltung</li></ul>',
            ]
        );

        $this->end_controls_section();
        
        // --- Style Section ---
        $this->start_controls_section(
            'style_section',
            [
                'label' => __('Stil', 'lego-wawi'),
                'tab' => \Elementor\Controls_Manager::TAB_STYLE,
            ]
        );
        
        $this->add_control(
            'dark_mode',
            [
                'label' => __('Dark Mode', 'lego-wawi'),
                'type' => \Elementor\Controls_Manager::SWITCHER,
                'default' => 'yes',
            ]
        );

        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        $mode_class = ($settings['dark_mode'] === 'yes') ? 'lww-showcase-dark' : 'lww-showcase-light';
        
        // SVG Graphic (Abstract Tech Dashboard)
        $svg_graphic = '<svg viewBox="0 0 500 300" xmlns="http://www.w3.org/2000/svg" class="lww-hero-svg">
            <defs>
                <linearGradient id="grad1" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" style="stop-color:#3498db;stop-opacity:1" />
                    <stop offset="100%" style="stop-color:#8e44ad;stop-opacity:1" />
                </linearGradient>
            </defs>
            <rect x="50" y="50" width="400" height="200" rx="10" fill="#2c3e50" opacity="0.8" />
            <circle cx="80" cy="80" r="10" fill="#e74c3c" />
            <circle cx="110" cy="80" r="10" fill="#f1c40f" />
            <circle cx="140" cy="80" r="10" fill="#27ae60" />
            <rect x="80" y="110" width="100" height="100" rx="5" fill="url(#grad1)" />
            <rect x="200" y="110" width="220" height="20" rx="5" fill="#ecf0f1" opacity="0.5" />
            <rect x="200" y="140" width="180" height="20" rx="5" fill="#ecf0f1" opacity="0.3" />
            <rect x="200" y="170" width="200" height="20" rx="5" fill="#ecf0f1" opacity="0.4" />
        </svg>';

        ?>
        <div class="lww-product-showcase-wrapper <?php echo esc_attr($mode_class); ?>">
            <div class="lww-showcase-content">
                <h2 class="lww-showcase-title"><?php echo esc_html($settings['title']); ?></h2>
                <div class="lww-showcase-subtitle"><?php echo wp_kses_post($settings['subtitle']); ?></div>
                <div class="lww-showcase-features">
                    <?php echo $settings['features']; ?>
                </div>
                <button class="lww-showcase-cta">Demo Anfordern</button>
            </div>
            <div class="lww-showcase-visual">
                <?php echo $svg_graphic; ?>
            </div>
        </div>
        <?php
    }
}
