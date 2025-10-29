<?php
/**
 * Import-Handler für Rebrickable 'inventories.csv' (Deckblatt)
 *
 * * Optimierte Version:
 * 1. Caching für Set-Num -> Post-ID Lookups.
 * 2. Caching für Fig-Num -> Post-ID Lookups.
 * 3. Caching für Post-ID -> Inventory-Version Lookups.
 */
if (!defined('ABSPATH')) exit;

class LWW_Import_Inventories_Handler extends LWW_Import_Handler_Base {

    /**
     * Cache für Set-Nummer (set_num) -> WordPress Post-ID
     */
    private static $set_num_cache = [];

    /**
     * Cache für Fig-Nummer (fig_num) -> WordPress Post-ID
     */
    private static $fig_num_cache = [];

    /**
     * Cache für Post-ID -> Inventar-Version
     * Speichert 'null', wenn keine Version gefunden wurde.
     */
    private static $post_version_cache = [];

    /**
     * Wird vom Importer aufgerufen, BEVOR die erste Zeile verarbeitet wird.
     * Stellt sicher, dass Caches von einem vorherigen Lauf geleert werden.
     *
     * @param int $job_id Die ID des aktuellen Import-Jobs.
     */
    public function start_job($job_id) {
        // Caches für diesen Job-Lauf zurücksetzen
        self::$set_num_cache = [];
        self::$fig_num_cache = [];
        self::$post_version_cache = [];
    }


    public function process_row($job_id, $row_data_raw, $header_map) {
        $data = $this->get_data_from_row($row_data_raw, $header_map);

        $inventory_id = intval($data['id'] ?? 0);
        $item_num = sanitize_text_field($data['set_num'] ?? ''); // Kann Set- oder Fig-Nummer sein
        $version = intval($data['version'] ?? 1);

        if (empty($inventory_id) || empty($item_num)) {
             lww_log_to_job($job_id, sprintf('WARNUNG (Inventories): Zeile übersprungen. Inv-ID ("%s") oder Item-Num ("%s") fehlt.', $inventory_id, $item_num));
             return;
        }

        $post_id = 0;
        
        // --- 1. Post-ID mit Caching finden ---
        
        // Ist es ein Set?
        $post_id = $this->get_cached_set_id($item_num);
        
        // Wenn nicht, ist es eine Minifigur?
        if (empty($post_id)) {
            $post_id = $this->get_cached_minifig_id($item_num);
        }
        
        if (empty($post_id)) {
             lww_log_to_job($job_id, sprintf('WARNUNG (Inventories): Item-Num "%s" (für Inv-ID %d) wurde weder als Set noch als Minifig gefunden.', $item_num, $inventory_id));
             return;
        }

        // --- 2. Version mit Caching prüfen und speichern ---

        // Hole die aktuell in der DB gespeicherte Version (oder aus unserem Cache)
        $current_version = $this->get_cached_inventory_version($post_id);

        // $current_version ist 'null', wenn noch nie eine ID/Version gespeichert wurde.
        // In diesem Fall, oder wenn die neue Version >= der alten ist, speichern.
        if ($current_version === null || $version >= $current_version) {
             update_post_meta($post_id, '_lww_inventory_id', $inventory_id);
             update_post_meta($post_id, '_lww_inventory_version', $version);
             
             // Aktualisiere unseren Job-Cache, damit wir nicht erneut
