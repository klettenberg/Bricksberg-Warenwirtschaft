<?php
/**
 * Import-Handler für Rebrickable 'inventory_sets.csv' (v16.0)
 * Speichert Set-in-Set Beziehungen (z.B. Polybags in einem größeren Set).
 * * Härtung: Fügt robuste Überprüfungen für fehlende Referenzen hinzu.
 * * REFACTORING: Nutzt die zentrale Methode zur Ermittlung der Zeilennummer.
 */
if (!defined('ABSPATH')) exit;

class LWW_Import_Inventory_Sets_Handler extends LWW_Import_Handler_Base {

    /**
     * Tracks which parent inventories have been cleared in this job run
     */
    private static $inventories_cleared = [];

    /**
     * Wird vom Importer aufgerufen, BEVOR die erste Zeile verarbeitet wird.
     */
    public function start_job($job_id) {
        // Caches für diesen Job-Lauf zurücksetzen
        self::$post_cache = [];
        self::$inventories_cleared = [];
    }

    /**
     * Verarbeitet eine einzelne Zeile aus der 'inventory_sets.csv'.
     */
    public function process_row($job_id, $row_data_raw, $header_map) {
        $data = self::get_data_from_row($row_data_raw, $header_map);
        
        // KORREKTUR: Robuste Zeilennummern-Ermittlung über zentrale Methode
        $line_number = self::get_current_line_number($job_id);

        // Annahme Header: inventory_id, set_num, quantity
        $inventory_id = intval($data['inventory_id'] ?? 0);
        $child_set_num = sanitize_text_field($data['set_num'] ?? '');
        $quantity = intval($data['quantity'] ?? 0);

        if (empty($inventory_id) || empty($child_set_num) || $quantity <= 0) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Inv-Sets): Zeile %d übersprungen. Inv-ID, SetNum oder Menge fehlt/ungültig.', $line_number));
            return;
        }

        // --- 1. Finde die WordPress Post IDs (mit Caching) ---

        // Finde den Parent Post (Set oder Minifig), zu dem das Inventar gehört
        $parent_post_id = self::find_post_by_inventory_id($inventory_id);
        if (empty($parent_post_id)) {
            // Normal, da wir nicht alle Sets/Minifigs importieren
            return;
        }

        // Finde den Child Set Post
        $child_set_id = self::find_set_by_num($child_set_num);
        if (empty($child_set_id)) {
            lww_log_unresolved_reference($job_id, 'inventory_sets.csv', 'Child Set Number', $child_set_num, $line_number);
            return;
        }

        // --- 2. Daten speichern ---

        // Stelle sicher, dass alte Set-Beziehungen für diesen Parent nur einmal pro Job gelöscht werden
        $meta_key = '_lww_inventory_set_line'; // Eigener Meta-Key für Set-Beziehungen
        $job_cache_key = $job_id . '_sets_' . $parent_post_id;

        if (!isset(self::$inventories_cleared[$job_cache_key])) {
            delete_post_meta($parent_post_id, $meta_key);
            self::$inventories_cleared[$job_cache_key] = true;
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
}
