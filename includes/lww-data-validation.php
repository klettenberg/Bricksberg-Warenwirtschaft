<?php
/**
 * Modul: Datenvalidierung & Enrichment (v1.0)
 *
 * Prt Katalogeintrge (lww_part, lww_set, lww_minifig) und ergnz fehlende
 * Namen und Plattform-IDs (Rebrickable, BrickLink, BrickOwl), soweit mglich.
 */
if (!defined('ABSPATH')) exit;

/**
 * Validiert und reichert einen einzelnen Katalogeintrag an.
 *
 * @param int $catalog_id Post-ID eines lww_part / lww_set / lww_minifig.
 * @param int $job_id     Optional: Job-ID fr Logging.
 */
function lww_validate_and_enrich_catalog_item($catalog_id, $job_id = 0) {
    $post = get_post($catalog_id);
    if (!$post) return;

    $type = get_post_type($catalog_id);
    if (!in_array($type, ['lww_part', 'lww_set', 'lww_minifig'], true)) return;

    $post_title = get_the_title($catalog_id);

    // Primfre Nummer & vorhandene IDs lesen
    switch ($type) {
        case 'lww_part':
            $num_meta = '_lww_part_num';
            break;
        case 'lww_set':
            $num_meta = '_lww_set_num';
            break;
        case 'lww_minifig':
            $num_meta = '_lww_minifig_num';
            break;
        default:
            return;
    }

    $main_num      = trim((string) get_post_meta($catalog_id, $num_meta, true));
    $rb_id         = trim((string) get_post_meta($catalog_id, '_lww_rebrickable_id', true));
    $bl_id         = trim((string) get_post_meta($catalog_id, '_lww_bricklink_id', true));
    $bo_id         = trim((string) get_post_meta($catalog_id, '_lww_brickowl_id', true));

    $needs_title   = ($post_title === '' || stripos($post_title, 'stub') !== false);
    $needs_rb_id   = ($rb_id === '');
    $needs_bl_id   = ($bl_id === '');
    $needs_bo_id   = ($bo_id === '');

    // Wenn alles okay ist, nichts tun
    if (!$needs_title && !$needs_rb_id && !$needs_bl_id && !$needs_bo_id) {
        return;
    }

    // Rebrickable API laden (Master fr Katalogdaten)
    if (!class_exists('LWW_Rebrickable_API')) {
        $api_file = LWW_PLUGIN_PATH . 'includes/api/class-lww-rebrickable-api.php';
        if (file_exists($api_file)) {
            require_once $api_file;
        }
    }

    $api_settings = get_option('lww_api_settings');
    $rb_key       = $api_settings['rebrickable_api_key'] ?? '';

    $rb_data = null;

    if (!empty($rb_key)) {
        $rb_api = new LWW_Rebrickable_API($rb_key);

        // Lookup nach Nummer, wenn noch kein Rebrickable-Eintrag vorhanden ist
        if ($needs_title || $needs_rb_id) {
            if ($type === 'lww_part') {
                $lookup_num = $main_num ?: $rb_id;
                if ($lookup_num) {
                    $rb_data = $rb_api->get_part($lookup_num);
                }
            } elseif ($type === 'lww_set') {
                $lookup_num = $main_num ?: $rb_id;
                if ($lookup_num) {
                    $rb_data = $rb_api->get_set($lookup_num);
                }
            } elseif ($type === 'lww_minifig') {
                $lookup_num = $main_num ?: $rb_id;
                if ($lookup_num) {
                    $rb_data = $rb_api->get_minifig($lookup_num);
                }
            }
        }
    }

    if (is_wp_error($rb_data)) {
        if ($job_id) {
            lww_log_to_job($job_id, sprintf('Rebrickable Fehler bei Katalog-ID %d: %s', $catalog_id, $rb_data->get_error_message()));
        }
        $rb_data = null;
    }

    // Titel/Namen aus Rebrickable cbernehmen
    if ($needs_title && is_array($rb_data) && !empty($rb_data['name'])) {
        wp_update_post([
            'ID'         => $catalog_id,
            'post_title' => sanitize_text_field($rb_data['name']),
        ]);
        if ($type === 'lww_part') {
            update_post_meta($catalog_id, '_lww_part_name', $rb_data['name']);
        } elseif ($type === 'lww_set') {
            update_post_meta($catalog_id, '_lww_set_name', $rb_data['name']);
        } elseif ($type === 'lww_minifig') {
            update_post_meta($catalog_id, '_lww_minifig_name', $rb_data['name']);
        }
    }

    // Rebrickable-ID setzen, falls mglich
    if ($needs_rb_id && is_array($rb_data)) {
        if (!empty($rb_data['part_num']) && $type === 'lww_part') {
            $rb_id = $rb_data['part_num'];
        } elseif (!empty($rb_data['set_num']) && $type === 'lww_set') {
            $rb_id = $rb_data['set_num'];
        } elseif (!empty($rb_data['set_num']) && $type === 'lww_minifig') {
            // Rebrickable nutzt fr Minifigs set_num-4hnliche Kennungen
            $rb_id = $rb_data['set_num'];
        }

        if (!empty($rb_id)) {
            update_post_meta($catalog_id, '_lww_rebrickable_id', $rb_id);
            if (!$main_num) {
                update_post_meta($catalog_id, $num_meta, $rb_id);
            }
        }
    }

    // BrickLink- und BrickOwl-IDs werden primfr cber Inventar- und CSV-Importe gesetzt.
    // Hier kfnnen wir nur einfache Konsistenz herstellen:
    if ($type === 'lww_part' && !$bl_id && $main_num && strpos($main_num, '-') === false) {
        // Heuristik: PartNum ohne Suffix als BrickLink-ID verwenden, wenn noch leer.
        update_post_meta($catalog_id, '_lww_bricklink_id', $main_num);
        $bl_id = $main_num;
    }

    if ($type === 'lww_part' && !$bo_id && $main_num) {
        // Heuristik: Wenn BOID bereits irgendwo als Inventar-BOID vorkommt, auflfsen.
        global $wpdb;
        $boid = $wpdb->get_var($wpdb->prepare(
            "SELECT pm.meta_value FROM {$wpdb->postmeta} pm \n             JOIN {$wpdb->posts} p ON p.ID = pm.post_id \n             WHERE p.post_type = 'lww_inventory_item' \n             AND pm.meta_key = '_boid' \n             AND pm.meta_value LIKE %s \n             LIMIT 1",
            $main_num . '%'
        ));
        if ($boid) {
            update_post_meta($catalog_id, '_lww_brickowl_id', $boid);
        }
    }

    if ($job_id) {
        lww_log_to_job($job_id, sprintf(
            'Validierung abgeschlossen fr Katalog-ID %d (%s). Titel: "%s", RB: "%s", BL: "%s", BO: "%s".',
            $catalog_id,
            $type,
            get_the_title($catalog_id),
            get_post_meta($catalog_id, '_lww_rebrickable_id', true),
            get_post_meta($catalog_id, '_lww_bricklink_id', true),
            get_post_meta($catalog_id, '_lww_brickowl_id', true)
        ));
    }
}
