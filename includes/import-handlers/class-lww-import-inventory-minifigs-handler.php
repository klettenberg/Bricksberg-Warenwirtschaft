<?php
/**
 * Import-Handler für Rebrickable 'inventory_minifigs.csv'
 * Speichert Minifiguren-in-Set Beziehungen.
 *
 * * Optimierte Version:
 * 1. Nutzt Caching für Parent-Post-ID (via Inventory-ID) Lookups.
 * 2. Nutzt Caching für Child-Minifig-ID (via Fig-Num) Lookups.
 */
if (!defined('ABSPATH')) exit;

class LWW_Import_Inventory_Minifigs_Handler extends LWW_Import_Handler_Base {

    /**
     * Cache für Inventory-ID -> Post-ID Lookups.
     */
    private static $inventory_id_cache = [];

    /**
     * Cache für Fig-Nummer -> Post-ID Lookups.
     */
    private static $fig_num_cache = [];

    /**
     * Wird vom Importer aufgerufen, BEVOR die erste Zeile verarbeitet wird.
     * Stellt sicher, dass Caches von einem vorherigen Lauf geleert werden.
     *
     * @param int $job_id Die ID des aktuellen Import-Jobs.
     */
    public function start_job($job_id) {
        // Caches für diesen Job-Lauf zurücksetzen
        self::$inventory_id_cache = [];
        self::$fig_num_cache = [];
    }

    /**
     * Verarbeitet eine einzelne Zeile aus der 'inventory_minifigs.csv'.
     */
    public function process_row($job_id, $row_data_raw, $header_map) {
        $data = $this->get_data_from_row($row_data_raw, $header_map);

        // Annahme Header: inventory_id, fig_num, quantity
        $inventory_id = intval($data['inventory_id'] ?? 0);
        $child_fig_num = sanitize_text_field($data['fig_num'] ?? '');
        $quantity = intval($data['quantity'] ?? 0);

        if (empty($inventory_id) || empty($child_fig_num) || $quantity <= 0) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Inv-Minifigs): Zeile übersprungen. Inv-ID, FigNum oder Menge fehlt/ungültig. Data: %s', implode(', ', $data)));
            return;
        }

        // --- 1. Finde die WordPress Post IDs (mit Caching) ---

        // Finde den Parent Post (normalerweise ein Set)
        $parent_post_id = $this->get_cached_parent_post_id($inventory_id);
        if (empty($parent_post_id)) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Inv-Minifigs): Parent-Post für Inventar-ID "%d" nicht gefunden.', $inventory_id));
            return;
        }

        // Finde den Child Minifig Post
        $child_minifig_id = $this->get_cached_minifig_post_id($child_fig_num);
        if (empty($child_minifig_id)) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Inv-Minifigs): Child FigNum "%s" (für Inv-ID %d) nicht im Katalog gefunden.', $child_fig_num, $inventory_id));
            return;
        }

        // --- 2. Daten speichern ---

        // Stelle sicher, dass alte Minifig-Beziehungen nur einmal gelöscht werden
        static $inventories_cleared = [];
        $meta_key = '_lww_inventory_minifig_line'; // Eigener Meta-Key für Minifig-Beziehungen
        $job_cache_key = $job_id . '_minifigs_' . $parent_post_id;

        if (!isset($inventories_cleared[$job_cache_key])) {
            delete_post_meta($parent_post_id, $meta_key);
            $inventories_cleared[$job_cache_key] = true;
            lww_log_to_job($job_id, sprintf('INFO (Inv-Minifigs): Alte Minifig-Stückliste für Post ID %d (Inv-ID %d) wird gelöscht...', $parent_post_id, $inventory_id));
        }

        // Format: "Child_Minifig_Post_ID|Menge"
        $line_data = implode('|', [
            $child_minifig_id,
            $quantity,
        ]);

        // Füge die Zeile als neues, separates Meta-Feld hinzu
        add_post_meta($parent_post_id, $meta_key, $line_data, false);
    }

    /**
     * Cache-Wrapper für die Parent-Post-ID (via Inventory-ID).
     */
    private function get_cached_parent_post_id($inventory_id) {
        if (isset(self::$inventory_id_cache[$inventory_id])) {
            return self::$inventory_id_cache[$inventory_id];
        }

        $post_id = $this->find_post_by_inventory_id($inventory_id);
        self::$inventory_id_cache[$inventory_id] = $post_id;
        
        return $post_id;
    }

    /**
     * Cache-Wrapper für die Child-Minifig-Post-ID (via Fig-Num).
     */
    private function get_cached_minifig_post_id($fig_num) {
        if (isset(self::$fig_num_cache[$fig_num])) {
            return self::$fig_num_cache[$fig_num];
        }
        
        // (Diese Funktion kommt aus deiner LWW_Import_Handler_Base Klasse)
        $post_id = $this->find_minifig_by_num($fig_num);
        self::$fig_num_cache[$fig_num] = $post_id;
        
        return $post_id;
    }
}
