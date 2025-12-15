<?php
/**
 * Plugin Name:       Bricksberg Warenwirtschaft (WaWi)
 * Plugin URI:        https://bricksberg.eu/
 * Description:       Professionelles Warenwirtschaftssystem für LEGO® Händler. Dient als zentrale Verwaltungsplattform, um Katalogdaten zu organisieren und den Lagerbestand über mehrere Marktplätze wie WooCommerce, BrickOwl und eBay präzise zu synchronisieren. <a href="admin.php?page=bricksberg_wawi_dashboard">Zum Dashboard</a>
 * Version:           6.50.0
 * Author:            Bricksberg
 * Author URI:        https://bricksberg.eu/
 * License:           GPL v2 or later
 * Requires at least: 6.2
 * Tested up to:      6.7
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * Text Domain:       bricksberg-wawi
 * Domain Path:       /languages
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// --- Core Plugin Constants ---
define('LWW_PLUGIN_FILE', __FILE__);
define('LWW_PLUGIN_PATH', plugin_dir_path(LWW_PLUGIN_FILE));
define('LWW_PLUGIN_URL', plugin_dir_url(LWW_PLUGIN_FILE));
define('LWW_PLUGIN_SLUG', 'bricksberg_wawi_dashboard');
define('LWW_PLUGIN_VERSION', '6.50.0');

/**
 * Lädt die Textdomain des Plugins für Übersetzungen.
 * KORREKTUR: Verschiebung auf 'init', um WP 6.7+ Warnungen zu vermeiden.
 */
function lww_load_textdomain() {
    load_plugin_textdomain('bricksberg-wawi', false, dirname(plugin_basename(LWW_PLUGIN_FILE)) . '/languages/');
}
add_action('init', 'lww_load_textdomain');

/**
 * Guard against redeclaration.
 * If the main plugin class exists, it means the plugin has already been loaded,
 * so we can exit early to prevent a fatal error.
 */
if (class_exists('LWW_Core')) {
    return;
}

// Load the main plugin class. Using require_once is a secondary guard.
require_once LWW_PLUGIN_PATH . 'includes/lww-core.php';

// Register activation/deactivation hooks to be handled by the main class's static methods.
register_activation_hook(__FILE__, ['LWW_Core', 'activate']);
register_deactivation_hook(__FILE__, ['LWW_Core', 'deactivate']);

// Initialize the plugin by getting the singleton instance of the main class.
function lww_core() {
    return LWW_Core::instance();
}
lww_core();
