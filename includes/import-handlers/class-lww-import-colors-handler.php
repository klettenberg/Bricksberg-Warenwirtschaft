<?php
/**
 * Import-Handler für Rebrickable 'colors.csv'
 */
if (!defined('ABSPATH')) exit;

class LWW_Import_Colors_Handler extends LWW_Import_Handler_Base {

    public function start_job($job_id) {
        self::$post_cache = [];
    }

    public function process_row($job_id, $row_data_raw, $header_map) {
        $data = $this->get_data_from_row($row_data_raw, $header_map);
    
        // Rohdaten holen und prüfen
        $raw_id = $data['id'] ?? null;
        $color_name = sanitize_text_field($data['name'] ?? '');

        // Prüfen, ob die ID überhaupt gesetzt (nicht null, nicht leer) ODER der Name leer ist.
        // '0' ist eine gültige ID (z.B. für "Black").
        if ($raw_id === null || $raw_id === '' || empty($color_name)) {
            lww_log_to_job($job_id, sprintf('WARNUNG (Farbe): Zeile übersprungen. ID ("%s") oder Name ("%s") fehlt.', $raw_id ?? 'NULL', $color_name));
            return;
        }
        
        $rebrickable_id = intval($raw_id);
        $meta_key = '_lww_rebrickable_id';
        $post_id = $this->find_post_by_meta('lww_color', $meta_key, $rebrickable_id);

        $post_data = [
            'post_title'   => $color_name,
            'post_status'  => 'publish',
            'post_type'    => 'lww_color',
        ];
        
        $is_new = false;
        if ($post_id > 0) {
            $post_data['ID'] = $post_id;
            // Nur updaten, wenn der Name sich geändert hat
            $current_post = get_post($post_id);
            if ($current_post->post_title !== $color_name) {
                wp_update_post($post_data);
            }
        } else {
            $post_id = wp_insert_post($post_data, true);
            if (is_wp_error($post_id)) {
                lww_log_to_job($job_id, sprintf('FEHLER (Farbe): Konnte \"%s\" nicht erstellen: %s', $color_name, $post_id->get_error_message()));
                return;
            }
            $is_new = true;
            // Logging entfernt, um Logs zu verkleinern
            // lww_log_to_job($job_id, sprintf('INFO (Farbe): \"%s\" (ID: %d) NEU erstellt.', $color_name, $post_id));
        }

        if ($post_id > 0) {
            // Update Meta-Daten nur, wenn der Post neu ist oder sie sich potenziell ändern
            // Dies ist eine weitere kleine Optimierung
            update_post_meta($post_id, $meta_key, $rebrickable_id);
            update_post_meta($post_id, '_lww_color_name', $color_name);
            update_post_meta($post_id, '_lww_rgb_hex', sanitize_hex_color_no_hash($data['rgb'] ?? ''));
            update_post_meta($post_id, '_lww_is_transparent', ($data['is_trans'] ?? 'f') === 't');

            // Externe IDs nur bei neuen Posts speichern, da sie sich selten ändern
            if ($is_new) {
                $external_ids = [
                    'bricklink' => ['ids' => sanitize_text_field($data['bricklink_ext_ids'] ?? '')],
                    'brickowl'  => ['ids' => sanitize_text_field($data['brickowl_ext_ids'] ?? '')],
                    'lego'      => ['ids' => sanitize_text_field($data['lego_ids'] ?? '')],
                    'ldraw'     => ['ids' => sanitize_text_field($data['ldraw_ext_ids'] ?? '')],
                ];
                update_post_meta($post_id, '_lww_external_ids', $external_ids);
            }
        }
    }
}
