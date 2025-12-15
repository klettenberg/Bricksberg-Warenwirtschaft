<?php
/**
 * Plugin Name:       Bricksberg Warenwirtschaft (WaWi)
 * Plugin URI:        https://bricksberg.eu/
 * Description:       Professionelles Warenwirtschaftssystem für LEGO® Händler. Dient als zentrale Verwaltungsplattform, um Katalogdaten zu organisieren und den Lagerbestand über mehrere Marktplätze wie WooCommerce, BrickOwl und eBay präzise zu synchronisieren. <a href="admin.php?page=bricksberg_wawi_dashboard">Zum Dashboard</a>
 * Version:           4.29.0
 * Author:            Bricksberg
 * Author URI:        https://bricksberg.eu/
 * License:           GPL v2 or later
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * Text Domain:       lego-wawi
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

/**
 * Lädt die Textdomain des Plugins für Übersetzungen.
 * Wird auf 'plugins_loaded' aufgerufen, um sicherzustellen, dass Übersetzungen vor allen anderen Hooks geladen werden.
 */
function lww_load_textdomain() {
    load_plugin_textdomain('lego-wawi', false, dirname(plugin_basename(LWW_PLUGIN_FILE)) . '/languages/');
}
add_action('plugins_loaded', 'lww_load_textdomain');

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

require_once __DIR__ . '/../includes/importers/bricklink-importer.php';

class BricklinkImporterTest extends TestCase {

    public function testDetectFormatValid() {
        $xml = '<?xml version="1.0"?><INVENTORY><ITEM><ITEMID>3001</ITEMID><QTY>1</QTY></ITEM></INVENTORY>';
        $this->assertTrue(Bricksberg_Bricklink_Importer::detectFormat($xml));
    }

    public function testParseSimpleItem() {
        $xml = '<?xml version="1.0"?>
            <INVENTORY>
                <ITEM>
                    <ITEMID>3001</ITEMID>
                    <ITEMNAME>Brick 2x4</ITEMNAME>
                    <COLORID>1</COLORID>
                    <QTY>4</QTY>
                    <UNITPRICE>0.05</UNITPRICE>
                </ITEM>
            </INVENTORY>';
        $importer = new Bricksberg_Bricklink_Importer();
        $result = $importer->parseFromString($xml);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('items', $result);
        $this->assertCount(1, $result['items']);

        $item = $result['items'][0];
        $this->assertEquals('3001', $item['item_id']);
        $this->assertEquals('1', $item['color_id']);
        $this->assertEquals('3001-1', $item['sku']);
        $this->assertEquals(4, $item['qty']);
        $this->assertEquals(0.05, $item['unit_price']);
    }

    public function testParseInvalidXml() {
        $xml = '<INVENTORY><ITEM><ITEMID>3001</ITEMID><QTY>5</Q>'; // malformed
        $importer = new Bricksberg_Bricklink_Importer();
        $result = $importer->parseFromString($xml);
        $this->assertInstanceOf(WP_Error::class, $result);
    }

    public function testAggregateSameSku() {
        $xml = '<?xml version="1.0"?>
            <INVENTORY>
                <ITEM>
                    <ITEMID>3001</ITEMID>
                    <COLORID>1</COLORID>
                    <QTY>2</QTY>
                </ITEM>
                <ITEM>
                    <ITEMID>3001</ITEMID>
                    <COLORID>1</COLORID>
                    <QTY>3</QTY>
                </ITEM>
            </INVENTORY>';
        $importer = new Bricksberg_Bricklink_Importer();
        $result = $importer->parseFromString($xml);

        $this->assertIsArray($result);
        $this->assertCount(1, $result['items']);
        $this->assertEquals(5, $result['items'][0]['qty']);
    }

    public function testParseLowercaseAndNamespacedItem() {
        $xml = '<?xml version="1.0"?>
            <inventory xmlns:bl="http://bricklink.com">
                <item>
                    <itemid>3001</itemid>
                    <colorid>1</colorid>
                    <qty>3</qty>
                </item>
            </inventory>';
        $importer = new Bricksberg_Bricklink_Importer();
        $result = $importer->parseFromString($xml);

        $this->assertIsArray($result);
        $this->assertCount(1, $result['items']);
        $this->assertEquals(3, $result['items'][0]['qty']);
    }

    public function testMissingRequiredFieldsGeneratesErrors() {
        $xml = '<?xml version="1.0"?>
            <INVENTORY>
                <ITEM>
                    <ITEMNAME>No ID</ITEMNAME>
                    <QTY></QTY>
                </ITEM>
            </INVENTORY>';
        $importer = new Bricksberg_Bricklink_Importer();
        $result = $importer->parseFromString($xml);

        // A missing ITEMID/QTY should either return errors in 'errors' array or cause skip
        $this->assertIsArray($result);
        $this->assertGreaterThanOrEqual(1, count($result['errors']));
    }

    public function testNamepacedNodesAndMixedCase() {
        $xml = '<?xml version="1.0"?>
            <inv xmlns:bk="https://example.org">
                <bk:ITEM>
                    <bk:ITEMID>9999</bk:ITEMID>
                    <bk:QTY>7</bk:QTY>
                </bk:ITEM>
            </inv>';
        $importer = new Bricksberg_Bricklink_Importer();
        $result = $importer->parseFromString($xml);

        $this->assertIsArray($result);
        $this->assertCount(1, $result['items']);
        $this->assertEquals('9999', $result['items'][0]['item_id']);
    }
}