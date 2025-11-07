<?php
/**
 * Plugin Name:       Bricksberg Warenwirtschaft (WaWi)
 * Plugin URI:        https://bricksberg.eu/
 * Description:       Professionelles Warenwirtschaftssystem für LEGO® Händler. Importiert Rebrickable-Kataloge und synchronisiert Inventar mit BrickOwl und WooCommerce.
 * Version:           0.58.0
 * Author:            Bricksberg
 * Author URI:        https://bricksberg.eu/
 * License:           GPL v2 or later
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Text Domain:       lego-wawi
 * Domain Path:       /languages
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Guard against redeclaration.
 * If the main plugin class exists, it means the plugin has already been loaded,
 * so we can exit early to prevent a fatal error.
 */
if (class_exists('LWW_Core')) {
    return;
}

// Load the main plugin class. Using require_once is a secondary guard.
require_once plugin_dir_path(__FILE__) . 'includes/lww-core.php';

// Register activation/deactivation hooks to be handled by the main class's static methods.
register_activation_hook(__FILE__, ['LWW_Core', 'activate']);
register_deactivation_hook(__FILE__, ['LWW_Core', 'deactivate']);

// Initialize the plugin by getting the singleton instance of the main class.
function lww_core() {
    return LWW_Core::instance();
}
lww_core();
?>