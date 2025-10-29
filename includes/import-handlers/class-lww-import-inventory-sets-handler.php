<?php
/**
 * Import-Handler für Rebrickable 'inventory_sets.csv'
 * Speichert Set-in-Set Beziehungen (z.B. Polybags in einem größeren Set).
 * * Diese Version enthält Caching für Post-ID-Lookups, um die Datenbank-
 * abfragen bei großen Dateien drastisch zu reduzieren.
 */
if (!defined('ABSPATH')) exit;

class LWW_Import_Inventory_Sets_Handler extends LWW_Import_Handler_Base {

    /**
     * Cache für Inventory-ID -> Post-ID Lookups.
     * Bleibt über alle Zeilen eines Jobs bestehen.
     */
    private static $inventory_id_cache = [];

    /**
     * Cache für Set-Nummer -> Post-ID Lookups.
     * Bleibt über alle Zeilen eines Jobs bestehen.
     */
    private static $set_num_cache = [];

    /**
     * Wird vom Importer aufgerufen, BEVOR die erste Zeile verarbeitet wird.
     * Stellt sicher, dass Caches von einem vorherigen Lauf geleert werden.
     *
     * @param int $job_id Die ID des aktuellen Import-Jobs.
     */
    public function start_job($job_id) {
        // Caches für diesen Job-Lauf zurücksetzen
        self::$inventory_id_cache = [];
        self::$set_num_cache = [];
        
        // Hinweis: Der $inventories_cleared-Cache in process_row() 
        // ist "static" und wird innerhalb von process_row() pro Job-ID verwaltet,
        // er muss hier nicht geleert werden.
    }

    /**
     * Verarbeitet eine einzelne Zeile aus der 'inventory_sets.csv'.
     */
    public function process_row($job_id, $row_data_raw, $header_map) {
        $data = $this->get_data_from_row($row_data_raw, $header_map);

        // Annahme Header: inventory_id, set_num, quantity
        $inventory_id = intval($data['inventory_id'] ?? 0);
        $child_set_num = sanitize_text_field($data['set_num'] ?? '');
        $quantity = intval($data['quantity'] ?? 0);

        if (empty($inventory_id) || empty($child_set_num) || $quantity <= 0) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Inv-Sets): Zeile übersprungen. Inv-ID, SetNum oder Menge fehlt/ungültig. Data: %s', implode(', ', $data)));
            return;
        }

        // --- 1. Finde die WordPress Post IDs (jetzt mit Caching) ---

        // Finde den Parent Post (Set oder Minifig), zu dem das Inventar gehört
        $parent_post_id = $this->get_cached_parent_post_id($inventory_id);
        if (empty($parent_post_id)) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Inv-Sets): Parent-Post für Inventar-ID "%d" nicht gefunden.', $inventory_id));
            return;
        }

        // Finde den Child Set Post
        $child_set_id = $this->get_cached_child_set_id($child_set_num);
        if (empty($child_set_id)) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Inv-Sets): Child SetNum "%s" (für Inv-ID %d) nicht im Katalog gefunden.', $child_set_num, $inventory_id));
            return;
        }

        // --- 2. Daten speichern ---

        // Stelle sicher, dass alte Set-Beziehungen für diesen Parent nur einmal pro Job gelöscht werden
        static $inventories_cleared = [];
        $meta_key = '_lww_inventory_set_line'; // Eigener Meta-Key für Set-Beziehungen
        $job_cache_key = $job_id . '_sets_' . $parent_post_id;

        if (!isset($inventories_cleared[$job_cache_key])) {
            delete_post_meta($parent_post_id, $meta_key);
            $inventories_cleared[$job_cache_key] = true;
            lww_log_to_job($job_id, sprintf('INFO (Inv-Sets): Alte Set-Stückliste für Post ID %d (Inv-ID %d) wird gelöscht...', $parent_post_id, $inventory_id));
        }

        // Format: "Child_Set_Post_ID|Menge"
        $line_data = implode('|', [
            $child_set_id,
            $quantity,
        ]);

        // Füge die Zeile als neues, separates Meta-Feld hinzu
        add_post_meta($parent_post_id, $meta_key, $line_data, false);
    }

    /**
     * Wrapper-Funktion, die die Parent-Post-ID aus einem Cache holt 
     * oder per DB-Abfrage sucht und dann cacht.
     *
     * @param int $inventory_id
     * @return int|null Post-ID oder null, wenn nicht gefunden.
     */
    private function get_cached_parent_post_id($inventory_id) {
        // Prüfe, ob der Wert bereits im Cache ist
        if (isset(self::$inventory_id_cache[$inventory_id])) {
            return self::$inventory_id_cache[$inventory_id];
        }

        // Nicht im Cache: Führe die eigentliche (teure) Suche aus
        // (Diese Funktion kommt aus deiner LWW_Import_Handler_Base Klasse)
        $post_id = $this->find_post_by_inventory_id($inventory_id);

        // Speichere das Ergebnis im Cache (auch wenn es 'null' oder '0' ist),
        // damit wir nicht erneut suchen.
        self::$inventory_id_cache[$inventory_id] = $post_id;

        return $post_id;
    }

    /**
     * Wrapper-Funktion, die die Child-Set-ID aus einem Cache holt
     * oder per DB-Abfrage sucht und dann cacht.
     *
     * @param string $set_num
     * @return int|null Post-ID oder null, wenn nicht gefunden.
     */
    private function get_cached_child_set_id($set_num) {
        // Prüfe, ob der Wert bereits im Cache ist
        if (isset(self::$set_num_cache[$set_num])) {
            return self::$set_num_cache[$set_num];
        }

        // Nicht im Cache: Führe die eigentliche (teure) Suche aus
        // (Diese Funktion kommt aus deiner LWW_Import_Handler_Base Klasse)
        $post_id = $this->find_set_by_num($set_num);

        // Speichere das Ergebnis im Cache
        self::$set_num_cache[$set_num] = $post_id;

        return $post_id;
    }
}
