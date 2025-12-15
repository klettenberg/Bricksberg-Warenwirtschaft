<?php
/**
 * Import-Handler für Rebrickable 'inventory_minifigs.csv' (v16.0)
 * Speichert Minifiguren-in-Set Beziehungen.
 * * Härtung: Fügt robuste Überprüfungen für fehlende Referenzen hinzu.
 * * REFACTORING: Nutzt die zentrale Methode zur Ermittlung der Zeilennummer.
 */
if (!defined('ABSPATH')) exit;

class LWW_Import_Inventory_Minifigs_Handler extends LWW_Import_Handler_Base {

    private static $inventories_cleared = [];
    private static $minifigs_cleared = []; // NEU: Cache für die umgekehrte Beziehung

    /**
     * Wird vom Importer aufgerufen, BEVOR die erste Zeile verarbeitet wird.
     */
    public function start_job($job_id) {
        // Caches für diesen Job-Lauf zurücksetzen
        self::$post_cache = [];
        self::$inventories_cleared = [];
        self::$minifigs_cleared = []; // NEU
    }

    /**
     * Verarbeitet eine einzelne Zeile aus der 'inventory_minifigs.csv'.
     */
    public function process_row($job_id, $row_data_raw, $header_map) {
        $data = self::get_data_from_row($row_data_raw, $header_map);
        
        // KORREKTUR: Robuste Zeilennummern-Ermittlung über zentrale Methode
        $line_number = self::get_current_line_number($job_id);

        // Annahme Header: inventory_id, fig_num, quantity
        $inventory_id = intval($data['inventory_id'] ?? 0);
        $child_fig_num = sanitize_text_field($data['fig_num'] ?? '');
        $quantity = intval($data['quantity'] ?? 0);

        if (empty($inventory_id) || empty($child_fig_num) || $quantity <= 0) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Inv-Minifigs): Zeile %d übersprungen. Inv-ID, FigNum oder Menge fehlt/ungültig.', $line_number));
            return;
        }

        // --- 1. Finde die WordPress Post IDs (mit Caching) ---

        // Finde den Parent Post (normalerweise ein Set)
        $parent_post_id = self::find_post_by_inventory_id($inventory_id);
        if (empty($parent_post_id)) {
            // Normal, da wir nicht alle Sets importieren
            return;
        }

        // Finde den Child Minifig Post
        $child_minifig_id = self::find_minifig_by_num($child_fig_num);
        if (empty($child_minifig_id)) {
            lww_log_unresolved_reference($job_id, 'inventory_minifigs.csv', 'Child Fig Number', $child_fig_num, $line_number);
            return;
        }

        // --- 2. Daten speichern ---

        // --- Beziehung: Set -> Minifig (auf dem Set speichern) ---
        $meta_key_on_parent = '_lww_inventory_minifig_line'; // Eigener Meta-Key für Minifig-Beziehungen
        $job_cache_key_parent = $job_id . '_minifigs_' . $parent_post_id;

        if (!isset(self::$inventories_cleared[$job_cache_key_parent])) {
            delete_post_meta($parent_post_id, $meta_key_on_parent);
            self::$inventories_cleared[$job_cache_key_parent] = true;
            lww_log_to_job($job_id, sprintf('INFO (Inv-Minifigs): Alte Minifig-Stückliste für Post ID %d (Inv-ID %d) wird gelöscht...', $parent_post_id, $inventory_id));
        }

        // Format: "Child_Minifig_Post_ID|Menge"
        $line_data = implode('|', [
            $child_minifig_id,
            $quantity,
        ]);

        // Füge die Zeile als neues, separates Meta-Feld hinzu
        add_post_meta($parent_post_id, $meta_key_on_parent, $line_data, false);

        // --- NEU: Beziehung: Minifig -> Set (auf der Minifig speichern) ---
        $meta_key_on_child = '_lww_appears_in_set';
        $job_cache_key_child = $job_id . '_appears_in_' . $child_minifig_id;

        if (!isset(self::$minifigs_cleared[$job_cache_key_child])) {
            delete_post_meta($child_minifig_id, $meta_key_on_child);
            self::$minifigs_cleared[$job_cache_key_child] = true;
        }

        // Füge die Set-ID zum Meta-Feld der Minifigur hinzu.
        add_post_meta($child_minifig_id, $meta_key_on_child, $parent_post_id, false);
    }
}
