<?php
/**
 * Import-Handler für Rebrickable 'minifigs.csv'
 *
 * * Optimierte Version:
 * 1. Nutzt einen statischen Cache für Post-Lookups via fig_num.
 * 2. Lädt Bilder NICHT direkt herunter, sondern speichert nur die URL
 * für einen späteren Hintergrund-Sideloading-Prozess.
 */
if (!defined('ABSPATH')) exit;

class LWW_Import_Minifigs_Handler extends LWW_Import_Handler_Base {

    /**
     * Cache für Fig-Nummer -> Post-ID Lookups.
     * Bleibt über alle Zeilen eines Jobs bestehen.
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
        self::$fig_num_cache = [];
    }

    /**
     * Verarbeitet eine einzelne Zeile aus der 'minifigs.csv'.
     */
    public function process_row($job_id, $row_data_raw, $header_map) {
        $data = $this->get_data_from_row($row_data_raw, $header_map);

        $fig_num = sanitize_text_field($data['fig_num'] ?? '');
        $fig_name = sanitize_text_field($data['name'] ?? '');
        $image_url = esc_url_raw($data['img_url'] ?? '');

        if (empty($fig_num) || empty($fig_name)) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Minifig): Zeile übersprungen. FigNum ("%s") oder Name ("%s") fehlt.', $fig_num, $fig_name));
            return;
        }
        
        $meta_key = 'lww_minifig_num'; 
        
        // Nutze die Caching-Funktion statt der direkten Abfrage
        $post_id = $this->get_cached_minifig_post_id($meta_key, $fig_num);
        
        $post_data = [
            'post_title'   => $fig_name,
            'post_status'  => 'publish',
            'post_type'    => 'lww_minifig',
        ];
        
        if ($post_id > 0) {
            $post_data['ID'] = $post_id;
            wp_update_post($post_data);
        } else {
            $post_id = wp_insert_post($post_data, true);
             if (is_wp_error($post_id)) {
                lww_log_to_job($job_id, sprintf('FEHLER (Minifig): Konnte "%s" nicht erstellen: %s', $fig_name, $post_id->get_error_message()));
                return;
            }
            lww_log_to_job($job_id, sprintf('INFO (Minifig): "%s" (ID: %d) NEU erstellt.', $fig_name, $post_id));
        }
        
        // Speichere die Metadaten
        if ($post_id > 0) {
            update_post_meta($post_id, $meta_key, $fig_num);
            update_post_meta($post_id, 'lww_minifig_name', $fig_name);
            update_post_meta($post_id, 'lww_rebrickable_id', $fig_num);
            update_post_meta($post_id, 'lww_num_parts', intval($data['num_parts'] ?? 0));
            
            // --- Optimierte Bild-Handhabung ---
            if (!empty($image_url)) {
                // Prüfe, ob der Post SCHON ein Beitragsbild hat.
                if (!has_post_thumbnail($post_id)) {
                    // Speichere nur die URL für einen späteren Cron-Job.
                    // Ein bestehender Wert wird überschrieben, falls sich die URL geändert hat.
                    update_post_meta($post_id, '_lww_sideload_image_url', $image_url);
                } else {
                    // Optional: Lösche eine alte URL, falls das Bild inzwischen manuell gesetzt wurde.
                    delete_post_meta($post_id, '_lww_sideload_image_url');
                }
            }
            // --- Ende Bild-Handhabung ---
        }
    }

    /**
     * Wrapper-Funktion, die die Minifig-Post-ID aus einem Cache holt
     * oder per DB-Abfrage sucht und dann cacht.
     *
     * @param string $meta_key Der Meta-Schlüssel (z.B. 'lww_minifig_num')
     * @param string $fig_num  Der Meta-Wert (die Fig-Nummer)
     * @return int|null Post-ID oder null, wenn nicht gefunden.
     */
    private function get_cached_minifig_post_id($meta_key, $fig_num) {
        // Prüfe, ob der Wert bereits im Cache ist
        if (isset(self::$fig_num_cache[$fig_num])) {
            return self::$fig_num_cache[$fig_num];
        }

        // Nicht im Cache: Führe die eigentliche (teure) Suche aus
        // (Diese Funktion kommt aus deiner LWW_Import_Handler_Base Klasse)
        $post_id = $this->find_post_by_meta('lww_minifig', $meta_key, $fig_num);

        // Speichere das Ergebnis im Cache (auch wenn es 'null' oder '0' ist),
        // damit wir nicht erneut suchen.
        self::$fig_num_cache[$fig_num] = $post_id;

        return $post_id;
    }
}
