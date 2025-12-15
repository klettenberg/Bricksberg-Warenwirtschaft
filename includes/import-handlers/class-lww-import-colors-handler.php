<?php
/**
 * Import-Handler für Rebrickable 'colors.csv'
 * 
 * * Release-Status: Gehärtet gegen Duplikate (ID + Namensabgleich).
 */
if (!defined('ABSPATH')) exit;

class LWW_Import_Colors_Handler extends LWW_Import_Handler_Base {

    public function start_job($job_id) {
        self::$post_cache = [];
    }

    public function process_row($job_id, $row_data_raw, $header_map) {
        $data = self::get_data_from_row($row_data_raw, $header_map);
    
        // Rohdaten holen und prüfen
        $raw_id = $data['id'] ?? null;
        $color_name = sanitize_text_field($data['name'] ?? '');

        // '0' ist eine gültige ID bei Rebrickable (oft Schwarz oder Unbekannt), daher genaue Prüfung
        if ($raw_id === null || $raw_id === '' || empty($color_name)) {
            // Logging reduziert, um Spam zu vermeiden
            return;
        }
        
        $rebrickable_id = intval($raw_id);
        $meta_key = '_lww_rebrickable_id';
        
        // 1. Primär-Check: Suche nach ID
        $post_id = self::find_post_by_meta('lww_color', $meta_key, $rebrickable_id);

        // 2. Sekundär-Check: Suche nach Namen (Vermeidung von Duplikaten bei fehlender ID)
        if (!$post_id) {
            $existing_colors = new WP_Query([
                'post_type' => 'lww_color',
                'title' => $color_name,
                'post_status' => 'publish',
                'posts_per_page' => 1,
                'fields' => 'ids',
                'no_found_rows' => true
            ]);
            if ($existing_colors->have_posts()) {
                $post_id = $existing_colors->posts[0];
            }
        }

        $post_data = [
            'post_title'   => $color_name,
            'post_status'  => 'publish',
            'post_type'    => 'lww_color',
        ];
        
        $is_new = false;
        if ($post_id > 0) {
            $post_data['ID'] = $post_id;
            // Nur updaten, wenn der Name sich geändert hat (Performance)
            $current_post = get_post($post_id);
            if ($current_post && $current_post->post_title !== $color_name) {
                wp_update_post($post_data);
            }
        } else {
            $post_id = wp_insert_post($post_data, true);
            if (is_wp_error($post_id)) {
                lww_log_to_job($job_id, sprintf('FEHLER (Farbe): Konnte "%s" nicht erstellen: %s', $color_name, $post_id->get_error_message()));
                return;
            }
            $is_new = true;
        }

        if ($post_id > 0) {
            $rgb_hex = sanitize_hex_color_no_hash($data['rgb'] ?? '');

            // Meta-Daten aktualisieren
            update_post_meta($post_id, $meta_key, $rebrickable_id);
            update_post_meta($post_id, '_lww_rgb_hex', $rgb_hex);
            update_post_meta($post_id, '_lww_is_transparent', ($data['is_trans'] ?? 'f') === 't');

            // HSL-Werte für Sortierung berechnen
            if (!empty($rgb_hex) && function_exists('lww_hex_to_hsl')) {
                $hsl_values = lww_hex_to_hsl($rgb_hex);
                if ($hsl_values) {
                    update_post_meta($post_id, '_lww_hsl_hue', $hsl_values['h']);
                    update_post_meta($post_id, '_lww_hsl_lightness', $hsl_values['l']);
                }
            }

            // BrickLink / BrickOwl Mappings speichern (für spätere Cross-Reference)
            // Wir überschreiben diese nur, wenn sie leer sind, um manuelle Mappings nicht zu zerstören
            $bricklink_ext_ids_raw = sanitize_text_field($data['bricklink_ext_ids'] ?? '');
            if ($bricklink_ext_ids_raw && !get_post_meta($post_id, '_lww_bricklink_id', true)) {
                $bricklink_ext_ids = array_filter(array_map('trim', explode(',', $bricklink_ext_ids_raw)));
                if (!empty($bricklink_ext_ids[0])) {
                    update_post_meta($post_id, '_lww_bricklink_id', $bricklink_ext_ids[0]);
                }
            }

            $brickowl_ext_ids_raw = sanitize_text_field($data['brickowl_ext_ids'] ?? '');
            if ($brickowl_ext_ids_raw && !get_post_meta($post_id, '_lww_brickowl_id', true)) {
                $brickowl_ext_ids = array_filter(array_map('trim', explode(',', $brickowl_ext_ids_raw)));
                if (!empty($brickowl_ext_ids[0])) {
                    update_post_meta($post_id, '_lww_brickowl_id', $brickowl_ext_ids[0]);
                }
            }

            // Externe IDs als Array für die Meta-Box (nur bei Erstellung oder erzwungenem Update)
            if ($is_new) {
                 $external_ids_raw = [
                    'bricklink' => $data['bricklink_ext_ids'] ?? '',
                    'brickowl'  => $data['brickowl_ext_ids'] ?? '',
                    'lego'      => $data['lego_ids'] ?? '',
                    'ldraw'     => $data['ldraw_ext_ids'] ?? '',
                ];
                $external_ids = [];
                foreach ($external_ids_raw as $platform => $id_string) {
                    $ids = array_filter(array_map('trim', explode(',', sanitize_text_field($id_string))));
                    if (!empty($ids)) {
                        $external_ids[$platform] = ['ids' => $ids];
                    }
                }
                if (!empty($external_ids)) {
                    update_post_meta($post_id, '_lww_external_ids', $external_ids);
                }
            }
        }
    }
}
