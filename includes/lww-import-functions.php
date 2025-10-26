<?php
/**
 * Modul: Import-Funktionen (v9.0)
 *
 * Beinhaltet die Logik zum Parsen von CSV-Dateien und zum Einfügen/Aktualisieren
 * von Daten in WordPress (CPTs, Taxonomien, Metadaten).
 */
if (!defined('ABSPATH')) exit;

// Diese Funktion ist aus dem vorherigen Entwurf und wird hier benötigt.
// Stell sicher, dass sie oder eine ähnliche Funktion für dein Logging existiert.
if (!function_exists('lww_log_job_message')) {
    function lww_log_job_message($job_id, $message, $type = 'info') {
        $current_log = get_post_meta($job_id, '_job_log', true);
        if (!is_array($current_log)) {
            $current_log = [];
        }
        $current_log[] = sprintf('[%s] [%s] %s', date('H:i:s'), strtoupper($type), $message);
        update_post_meta($job_id, '_job_log', $current_log);
    }
}

/**
 * Importiert Farb-Daten aus einer CSV-Datei.
 * Erstellt oder aktualisiert 'lww_color' CPTs.
 * Diese Funktion wird vor 'lww_import_parts_csv' aufgerufen.
 *
 * @param string $file_path Der absolute Pfad zur CSV-Datei.
 * @param int    $job_id    Der ID des aktuellen Jobs (für Logging/Status-Updates).
 * @param int    $task_index Index der aktuellen Aufgabe im Job.
 * @return array Ein assoziatives Array mit 'processed_rows' und 'failed_rows'.
 */
function lww_import_colors_csv($file_path, $job_id, $task_index) {
    if (!file_exists($file_path)) {
        lww_log_job_message($job_id, sprintf('FEHLER: Color CSV-Datei nicht gefunden: %s', basename($file_path)), 'error');
        return ['processed_rows' => 0, 'failed_rows' => 0];
    }

    $processed_rows = 0;
    $failed_rows = 0;
    $file_handle = fopen($file_path, 'r');

    if (!$file_handle) {
        lww_log_job_message($job_id, sprintf('FEHLER: Konnte Color CSV-Datei nicht öffnen: %s', basename($file_path)), 'error');
        return ['processed_rows' => 0, 'failed_rows' => 0];
    }

    $header = fgetcsv($file_handle);
    if (empty($header)) {
        lww_log_job_message($job_id, 'FEHLER: Color CSV-Datei ist leer oder hat keine Kopfzeile.', 'error');
        fclose($file_handle);
        return ['processed_rows' => 0, 'failed_rows' => 0];
    }

    $header = array_map('trim', $header);
    $header = array_map('mb_strtolower', $header);

    // Erforderliche Spalten für Farben
    // Annahme: Es gibt einen 'code' (z.B. BrickLink Color ID) und einen 'name' (z.B. "Bright Red")
    $required_columns = ['colorcode', 'colorname'];
    foreach ($required_columns as $col) {
        if (!in_array($col, $header)) {
            lww_log_job_message($job_id, sprintf('FEHLER: Erforderliche Spalte "%s" fehlt in der Color CSV-Datei.', $col), 'error');
            fclose($file_handle);
            return ['processed_rows' => 0, 'failed_rows' => 0];
        }
    }

    lww_log_job_message($job_id, sprintf('Starte Import von Farben aus %s.', basename($file_path)));

    while (($row = fgetcsv($file_handle)) !== FALSE) {
        if (count($header) !== count($row)) {
            lww_log_job_message($job_id, sprintf('WARNUNG: Zeile übersprungen in Color CSV aufgrund abweichender Spaltenanzahl. Zeile: %s', implode(', ', $row)), 'warning');
            $failed_rows++;
            continue;
        }

        $data = array_combine($header, array_map('trim', $row));

        $color_code = sanitize_text_field($data['colorcode'] ?? '');
        $color_name = sanitize_text_field($data['colorname'] ?? '');

        if (empty($color_code) || empty($color_name)) {
            lww_log_job_message($job_id, sprintf('WARNUNG: Color übersprungen - Code oder Name fehlt. Daten: %s', implode(', ', $data)), 'warning');
            $failed_rows++;
            continue;
        }

        // ---------- Ermittlung und Verknüpfung für Farben (CPT lww_color) ----------

        // Farben werden über einen eindeutigen Code (z.B. BrickLink ID) identifiziert.
        // Wir suchen nach einem bestehenden lww_color CPT mit diesem Code als Metadatum.
        $existing_color_posts = get_posts([
            'post_type'  => 'lww_color',
            'meta_key'   => 'lww_color_code', // Annahme: Wir speichern den Code als Meta-Feld
            'meta_value' => $color_code,
            'posts_per_page' => 1,
            'fields'     => 'ids',
            'post_status' => 'publish', // Oder 'any'
        ]);

        $color_post_id = 0;
        if (!empty($existing_color_posts)) {
            $color_post_id = $existing_color_posts[0];
            // Post aktualisieren
            $update_result = wp_update_post([
                'ID'         => $color_post_id,
                'post_title' => $color_name,
                // 'post_content' => $color_description, // Falls vorhanden
            ], true);

            if (is_wp_error($update_result)) {
                lww_log_job_message($job_id, sprintf('FEHLER: Farbe "%s" (ID: %d) konnte nicht aktualisiert werden: %s', $color_name, $color_post_id, $update_result->get_error_message()), 'error');
                $failed_rows++;
                continue;
            } else {
                lww_log_job_message($job_id, sprintf('INFO: Farbe "%s" (ID: %d) erfolgreich aktualisiert.', $color_name, $color_post_id));
            }
        } else {
            // Post neu erstellen
            $insert_result = wp_insert_post([
                'post_title'  => $color_name,
                'post_type'   => 'lww_color',
                'post_status' => 'publish',
            ], true);

            if (is_wp_error($insert_result)) {
                lww_log_job_message($job_id, sprintf('FEHLER: Farbe "%s" konnte nicht erstellt werden: %s', $color_name, $insert_result->get_error_message()), 'error');
                $failed_rows++;
                continue;
            } else {
                $color_post_id = $insert_result;
                lww_log_job_message($job_id, sprintf('INFO: Farbe "%s" (ID: %d) erfolgreich erstellt.', $color_name, $color_post_id));
            }
        }

        // Zusätzliche Metadaten speichern (z.B. den Color Code, RGB/Hex, externe IDs)
        if ($color_post_id) {
            update_post_meta($color_post_id, 'lww_color_code', $color_code); // Eindeutiger Code
            update_post_meta($color_post_id, 'lww_color_name', $color_name); // Für Schnellsuche
            update_post_meta($color_post_id, 'lww_bricklink_id', sanitize_text_field($data['bricklinkid'] ?? ''));
            update_post_meta($color_post_id, 'lww_brickowl_id', sanitize_text_field($data['brickowlid'] ?? ''));
            update_post_meta($color_post_id, 'lww_rebrickable_id', sanitize_text_field($data['rebrickableid'] ?? ''));
            update_post_meta($color_post_id, 'lww_rgb_hex', sanitize_text_field($data['rgbhex'] ?? '')); // Z.B. "FFFFFF"
        }

        $processed_rows++;
    }

    fclose($file_handle);
    lww_log_job_message($job_id, sprintf('Color-Import abgeschlossen. %d Zeilen verarbeitet, %d fehlgeschlagen.', $processed_rows, $failed_rows));

    return ['processed_rows' => $processed_rows, 'failed_rows' => $failed_rows];
}


/**
 * Importiert Teile-Kategorie-Daten aus einer CSV-Datei.
 * Erstellt oder aktualisiert 'lww_part_category' Taxonomie-Terms.
 * Diese Funktion wird vor 'lww_import_parts_csv' aufgerufen.
 *
 * @param string $file_path Der absolute Pfad zur CSV-Datei.
 * @param int    $job_id    Der ID des aktuellen Jobs (für Logging/Status-Updates).
 * @param int    $task_index Index der aktuellen Aufgabe im Job.
 * @return array Ein assoziatives Array mit 'processed_rows' und 'failed_rows'.
 */
function lww_import_part_categories_csv($file_path, $job_id, $task_index) {
    if (!file_exists($file_path)) {
        lww_log_job_message($job_id, sprintf('FEHLER: Part Category CSV-Datei nicht gefunden: %s', basename($file_path)), 'error');
        return ['processed_rows' => 0, 'failed_rows' => 0];
    }

    $processed_rows = 0;
    $failed_rows = 0;
    $file_handle = fopen($file_path, 'r');

    if (!$file_handle) {
        lww_log_job_message($job_id, sprintf('FEHLER: Konnte Part Category CSV-Datei nicht öffnen: %s', basename($file_path)), 'error');
        return ['processed_rows' => 0, 'failed_rows' => 0];
    }

    $header = fgetcsv($file_handle);
    if (empty($header)) {
        lww_log_job_message($job_id, 'FEHLER: Part Category CSV-Datei ist leer oder hat keine Kopfzeile.', 'error');
        fclose($file_handle);
        return ['processed_rows' => 0, 'failed_rows' => 0];
    }

    $header = array_map('trim', $header);
    $header = array_map('mb_strtolower', $header);

    // Erforderliche Spalten für Teile-Kategorien
    $required_columns = ['categoryid', 'categoryname']; // Annahme: categoryid für Eindeutigkeit
    foreach ($required_columns as $col) {
        if (!in_array($col, $header)) {
            lww_log_job_message($job_id, sprintf('FEHLER: Erforderliche Spalte "%s" fehlt in der Part Category CSV-Datei.', $col), 'error');
            fclose($file_handle);
            return ['processed_rows' => 0, 'failed_rows' => 0];
        }
    }

    lww_log_job_message($job_id, sprintf('Starte Import von Teile-Kategorien aus %s.', basename($file_path)));

    while (($row = fgetcsv($file_handle)) !== FALSE) {
        if (count($header) !== count($row)) {
            lww_log_job_message($job_id, sprintf('WARNUNG: Zeile übersprungen in Part Category CSV aufgrund abweichender Spaltenanzahl. Zeile: %s', implode(', ', $row)), 'warning');
            $failed_rows++;
            continue;
        }

        $data = array_combine($header, array_map('trim', $row));

        $category_id = sanitize_text_field($data['categoryid'] ?? ''); // Z.B. die BrickLink Category ID
        $category_name = sanitize_text_field($data['categoryname'] ?? '');
        $category_description = wp_kses_post($data['categorydescription'] ?? '');

        if (empty($category_id) || empty($category_name)) {
            lww_log_job_message($job_id, sprintf('WARNUNG: Part Category übersprungen - ID oder Name fehlt. Daten: %s', implode(', ', $data)), 'warning');
            $failed_rows++;
            continue;
        }

        // ---------- Ermittlung und Verknüpfung für Teile-Kategorien (Taxonomie lww_part_category) ----------

        // Prüfen, ob der Term bereits existiert (entweder über Slug, der vom category_id abgeleitet wird, oder Name)
        $term_slug = sanitize_title($category_id); // Nutzen der ID für den Slug
        $existing_term = term_exists($term_slug, 'lww_part_category');
        if (!$existing_term) {
            $existing_term = term_exists($category_name, 'lww_part_category');
        }

        $term_wp_id = 0;
        $term_args = [
            'slug'        => $term_slug,
            'description' => $category_description,
        ];

        if ($existing_term && is_array($existing_term)) {
            $term_wp_id = (int) $existing_term['term_id'];
            $update_result = wp_update_term($term_wp_id, 'lww_part_category', array_merge($term_args, ['name' => $category_name]));
            if (is_wp_error($update_result)) {
                lww_log_job_message($job_id, sprintf('FEHLER: Part Category "%s" (ID: %d) konnte nicht aktualisiert werden: %s', $category_name, $term_wp_id, $update_result->get_error_message()), 'error');
                $failed_rows++;
                continue;
            } else {
                lww_log_job_message($job_id, sprintf('INFO: Part Category "%s" (ID: %d) erfolgreich aktualisiert.', $category_name, $term_wp_id));
            }
        } else {
            $insert_result = wp_insert_term($category_name, 'lww_part_category', $term_args);
            if (is_wp_error($insert_result)) {
                lww_log_job_message($job_id, sprintf('FEHLER: Part Category "%s" konnte nicht erstellt werden: %s', $category_name, $insert_result->get_error_message()), 'error');
                $failed_rows++;
                continue;
            } else {
                $term_wp_id = (int) $insert_result['term_id'];
                lww_log_job_message($job_id, sprintf('INFO: Part Category "%s" (ID: %d) erfolgreich erstellt.', $category_name, $term_wp_id));
            }
        }

        // Zusätzliche Metadaten speichern (z.B. externe ID)
        if ($term_wp_id) {
            update_term_meta($term_wp_id, 'lww_category_id_external', $category_id); // Speichern der originalen ID
        }

        $processed_rows++;
    }

    fclose($file_handle);
    lww_log_job_message($job_id, sprintf('Part Category-Import abgeschlossen. %d Zeilen verarbeitet, %d fehlgeschlagen.', $processed_rows, $failed_rows));

    return ['processed_rows' => $processed_rows, 'failed_rows' => $failed_rows];
}


/**
 * Importiert Part-Daten aus einer CSV-Datei.
 * Erstellt oder aktualisiert 'lww_part' CPTs.
 * Hier finden die Haupt-Verknüpfungen statt.
 *
 * @param string $file_path Der absolute Pfad zur CSV-Datei.
 * @param int    $job_id    Der ID des aktuellen Jobs (für Logging/Status-Updates).
 * @param int    $task_index Index der aktuellen Aufgabe im Job.
 * @return array Ein assoziatives Array mit 'processed_rows' und 'failed_rows'.
 */
function lww_import_parts_csv($file_path, $job_id, $task_index) {
    if (!file_exists($file_path)) {
        lww_log_job_message($job_id, sprintf('FEHLER: Parts CSV-Datei nicht gefunden: %s', basename($file_path)), 'error');
        return ['processed_rows' => 0, 'failed_rows' => 0];
    }

    $processed_rows = 0;
    $failed_rows = 0;
    $file_handle = fopen($file_path, 'r');

    if (!$file_handle) {
        lww_log_job_message($job_id, sprintf('FEHLER: Konnte Parts CSV-Datei nicht öffnen: %s', basename($file_path)), 'error');
        return ['processed_rows' => 0, 'failed_rows' => 0];
    }

    $header = fgetcsv($file_handle);
    if (empty($header)) {
        lww_log_job_message($job_id, 'FEHLER: Parts CSV-Datei ist leer oder hat keine Kopfzeile.', 'error');
        fclose($file_handle);
        return ['processed_rows' => 0, 'failed_rows' => 0];
    }

    $header = array_map('trim', $header);
    $header = array_map('mb_strtolower', $header);

    // Erforderliche Spalten für Parts
    // Annahme: Es gibt eine eindeutige 'partnum' (Teilenummer) und einen 'partname'.
    // Auch 'categoryid' und 'colorcode' sind entscheidend für die Verknüpfungen.
    $required_columns = ['partnum', 'partname', 'categoryid']; // 'colorcode' wird im Inventory Item relevant
    foreach ($required_columns as $col) {
        if (!in_array($col, $header)) {
            lww_log_job_message($job_id, sprintf('FEHLER: Erforderliche Spalte "%s" fehlt in der Parts CSV-Datei.', $col), 'error');
            fclose($file_handle);
            return ['processed_rows' => 0, 'failed_rows' => 0];
        }
    }

    lww_log_job_message($job_id, sprintf('Starte Import von Parts aus %s.', basename($file_path)));

    // Hier könnten wir vorbereitende Abfragen machen, um Performance zu verbessern
    // Z.B. alle lww_part_category Terme und lww_color CPTs im Voraus laden,
    // wenn die Anzahl nicht extrem groß ist.

    // Wenn Du WooCommerce-Integration hast, hier relevant:
    // require_once ABSPATH . 'wp-admin/includes/image.php'; // Für media_sideload_image

    while (($row = fgetcsv($file_handle)) !== FALSE) {
        if (count($header) !== count($row)) {
            lww_log_job_message($job_id, sprintf('WARNUNG: Zeile übersprungen in Parts CSV aufgrund abweichender Spaltenanzahl. Zeile: %s', implode(', ', $row)), 'warning');
            $failed_rows++;
            continue;
        }

        $data = array_combine($header, array_map('trim', $row));

        $part_num = sanitize_text_field($data['partnum'] ?? ''); // z.B. BrickLink Part Number
        $part_name = sanitize_text_field($data['partname'] ?? '');
        $part_description = wp_kses_post($data['partdescription'] ?? '');
        $category_id_external = sanitize_text_field($data['categoryid'] ?? ''); // Die externe ID der Kategorie
        $image_url = esc_url_raw($data['imageurl'] ?? ''); // URL zum Hauptbild des Teils

        if (empty($part_num) || empty($part_name)) {
            lww_log_job_message($job_id, sprintf('WARNUNG: Part übersprungen - Nummer oder Name fehlt. Daten: %s', implode(', ', $data)), 'warning');
            $failed_rows++;
            continue;
        }

        // ---------- Ermittlung und Verknüpfung für Parts (CPT lww_part) ----------

        // 1. Part Category zuweisen
        $part_category_wp_id = 0;
        if (!empty($category_id_external)) {
            // Finde den Term basierend auf der externen ID, die wir zuvor als Meta-Feld gespeichert haben.
            $terms = get_terms([
                'taxonomy'   => 'lww_part_category',
                'hide_empty' => false,
                'meta_query' => [
                    [
                        'key'     => 'lww_category_id_external',
                        'value'   => $category_id_external,
                        'compare' => '=',
                    ],
                ],
                'fields'     => 'ids',
                'number'     => 1,
            ]);

            if (!empty($terms)) {
                $part_category_wp_id = $terms[0];
            } else {
                lww_log_job_message($job_id, sprintf('WARNUNG: Part "%s" - Zugehörige Kategorie mit externer ID "%s" nicht gefunden. Part wird ohne Kategorie importiert.', $part_name, $category_id_external), 'warning');
            }
        }

        // 2. Part (lww_part CPT) erstellen oder aktualisieren
        // Wir identifizieren Parts über ihre eindeutige Teilenummer ('partnum') als Metadatum.
        $existing_part_posts = get_posts([
            'post_type'  => 'lww_part',
            'meta_key'   => 'lww_part_num', // Annahme: Wir speichern die Part Number als Meta-Feld
            'meta_value' => $part_num,
            'posts_per_page' => 1,
            'fields'     => 'ids',
            'post_status' => 'publish', // Oder 'any'
        ]);

        $part_post_id = 0;
        $post_data = [
            'post_title'   => $part_name,
            'post_content' => $part_description,
            'post_status'  => 'publish',
            'post_type'    => 'lww_part',
        ];

        if (!empty($existing_part_posts)) {
            $part_post_id = $existing_part_posts[0];
            $post_data['ID'] = $part_post_id;
            $update_result = wp_update_post($post_data, true);

            if (is_wp_error($update_result)) {
                lww_log_job_message($job_id, sprintf('FEHLER: Part "%s" (ID: %d) konnte nicht aktualisiert werden: %s', $part_name, $part_post_id, $update_result->get_error_message()), 'error');
                $failed_rows++;
                continue;
            } else {
                lww_log_job_message($job_id, sprintf('INFO: Part "%s" (ID: %d) erfolgreich aktualisiert.', $part_name, $part_post_id));
            }
        } else {
            $insert_result = wp_insert_post($post_data, true);
            if (is_wp_error($insert_result)) {
                lww_log_job_message($job_id, sprintf('FEHLER: Part "%s" konnte nicht erstellt werden: %s', $part_name, $insert_result->get_error_message()), 'error');
                $failed_rows++;
                continue;
            } else {
                $part_post_id = $insert_result;
                lww_log_job_message($job_id, sprintf('INFO: Part "%s" (ID: %d) erfolgreich erstellt.', $part_name, $part_post_id));
            }
        }

        // 3. Taxonomie zuweisen (Part Category)
        if ($part_post_id && $part_category_wp_id) {
            wp_set_object_terms($part_post_id, (int)$part_category_wp_id, 'lww_part_category');
        }

        // 4. Metadaten speichern
        if ($part_post_id) {
            update_post_meta($part_post_id, 'lww_part_num', $part_num); // Die eindeutige Part Number
            update_post_meta($part_post_id, 'lww_part_name', $part_name); // Für Schnellsuche
            update_post_meta($part_post_id, 'lww_bricklink_id', sanitize_text_field($data['bricklinkid'] ?? ''));
            update_post_meta($part_post_id, 'lww_brickowl_id', sanitize_text_field($data['brickowlid'] ?? ''));
            update_post_meta($part_post_id, 'lww_rebrickable_id', sanitize_text_field($data['rebrickableid'] ?? ''));
            update_post_meta($part_post_id, 'lww_weight', floatval($data['weight'] ?? 0)); // Annahme: Gewicht als Float
            update_post_meta($part_post_id, 'lww_dim_x', floatval($data['dim_x'] ?? 0)); // Annahme: Dimensionen
            update_post_meta($part_post_id, 'lww_dim_y', floatval($data['dim_y'] ?? 0));
            update_post_meta($part_post_id, 'lww_dim_z', floatval($data['dim_z'] ?? 0));
            // HIER KÖNNTEN WEITERE META-DATEN GESPEICHERT WERDEN
            // z.B. `is_moulded` (Boolean), `stud_count`, `year_released` etc.
        }

        // 5. Beitragsbild (Thumbnail) von URL importieren und setzen
        if ($part_post_id && !empty($image_url)) {
            // Stelle sicher, dass diese Dateien nur einmal required werden oder global verfügbar sind
            if ( ! function_exists( 'media_handle_upload' ) ) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
            }

            // Das Bild von der URL herunterladen und als Attachment speichern
            // 'id' als letztes Argument gibt die ID des Attachments zurück
            $attachment_id = media_sideload_image($image_url, $part_post_id, $part_name, 'id');

            if (!is_wp_error($attachment_id)) {
                set_post_thumbnail($part_post_id, $attachment_id);
                lww_log_job_message($job_id, sprintf('INFO: Part "%s" - Bild von URL importiert und als Thumbnail gesetzt. ID: %d', $part_name, $attachment_id));
            } else {
                lww_log_job_message($job_id, sprintf('WARNUNG: Part "%s" - Fehler beim Import des Bildes von URL "%s": %s', $part_name, $image_url, $attachment_id->get_error_message()), 'warning');
            }
        }

        $processed_rows++;
    }

    fclose($file_handle);
    lww_log_job_message($job_id, sprintf('Part-Import abgeschlossen. %d Zeilen verarbeitet, %d fehlgeschlagen.', $processed_rows, $failed_rows));

    return ['processed_rows' => $processed_rows, 'failed_rows' => $failed_rows];
}

// Weitere Import-Funktionen (z.B. lww_import_sets_csv, lww_import_minifigs_csv) würden hier folgen...
// Die Themen-Importfunktion, die wir zuerst besprochen hatten, könnte hier auch eingefügt werden.
// function lww_import_themes_csv(...) { ... }
// ... (bestehender Code von lww-import-functions.php, einschließlich lww_log_job_message, lww_import_colors_csv, lww_import_part_categories_csv, lww_import_parts_csv) ...

/**
 * Importiert Set-Daten aus einer CSV-Datei.
 * Erstellt oder aktualisiert 'lww_set' CPTs.
 *
 * @param string $file_path Der absolute Pfad zur CSV-Datei.
 * @param int    $job_id    Der ID des aktuellen Jobs (für Logging/Status-Updates).
 * @param int    $task_index Index der aktuellen Aufgabe im Job.
 * @return array Ein assoziatives Array mit 'processed_rows' und 'failed_rows'.
 */
function lww_import_sets_csv($file_path, $job_id, $task_index) {
    if (!file_exists($file_path)) {
        lww_log_job_message($job_id, sprintf('FEHLER: Sets CSV-Datei nicht gefunden: %s', basename($file_path)), 'error');
        return ['processed_rows' => 0, 'failed_rows' => 0];
    }

    $processed_rows = 0;
    $failed_rows = 0;
    $file_handle = fopen($file_path, 'r');

    if (!$file_handle) {
        lww_log_job_message($job_id, sprintf('FEHLER: Konnte Sets CSV-Datei nicht öffnen: %s', basename($file_path)), 'error');
        return ['processed_rows' => 0, 'failed_rows' => 0];
    }

    $header = fgetcsv($file_handle);
    if (empty($header)) {
        lww_log_job_message($job_id, 'FEHLER: Sets CSV-Datei ist leer oder hat keine Kopfzeile.', 'error');
        fclose($file_handle);
        return ['processed_rows' => 0, 'failed_rows' => 0];
    }

    $header = array_map('trim', $header);
    $header = array_map('mb_strtolower', $header);

    // Erforderliche Spalten für Sets
    $required_columns = ['setnum', 'setname', 'themeid']; // themeid als externe ID für lww_theme
    foreach ($required_columns as $col) {
        if (!in_array($col, $header)) {
            lww_log_job_message($job_id, sprintf('FEHLER: Erforderliche Spalte "%s" fehlt in der Sets CSV-Datei.', $col), 'error');
            fclose($file_handle);
            return ['processed_rows' => 0, 'failed_rows' => 0];
        }
    }

    lww_log_job_message($job_id, sprintf('Starte Import von Sets aus %s.', basename($file_path)));

    while (($row = fgetcsv($file_handle)) !== FALSE) {
        if (count($header) !== count($row)) {
            lww_log_job_message($job_id, sprintf('WARNUNG: Zeile übersprungen in Sets CSV aufgrund abweichender Spaltenanzahl. Zeile: %s', implode(', ', $row)), 'warning');
            $failed_rows++;
            continue;
        }

        $data = array_combine($header, array_map('trim', $row));

        $set_num = sanitize_text_field($data['setnum'] ?? ''); // z.B. BrickLink Set Number
        $set_name = sanitize_text_field($data['setname'] ?? '');
        $set_description = wp_kses_post($data['setdescription'] ?? '');
        $theme_id_external = sanitize_text_field($data['themeid'] ?? ''); // Die externe ID des Themes
        $image_url = esc_url_raw($data['imageurl'] ?? ''); // URL zum Hauptbild des Sets

        if (empty($set_num) || empty($set_name)) {
            lww_log_job_message($job_id, sprintf('WARNUNG: Set übersprungen - Nummer oder Name fehlt. Daten: %s', implode(', ', $data)), 'warning');
            $failed_rows++;
            continue;
        }

        // ---------- Ermittlung und Verknüpfung für Sets (CPT lww_set) ----------

        // 1. Theme zuweisen
        $theme_wp_id = 0;
        if (!empty($theme_id_external)) {
            // Finde den Term basierend auf der externen ID, die wir als Meta-Feld gespeichert haben.
            $terms = get_terms([
                'taxonomy'   => 'lww_theme',
                'hide_empty' => false,
                'meta_query' => [
                    [
                        'key'     => 'lww_theme_id_external', // Annahme: Wir speichern externe IDs als Term-Meta
                        'value'   => $theme_id_external,
                        'compare' => '=',
                    ],
                ],
                'fields'     => 'ids',
                'number'     => 1,
            ]);

            if (!empty($terms)) {
                $theme_wp_id = $terms[0];
            } else {
                lww_log_job_message($job_id, sprintf('WARNUNG: Set "%s" - Zugehöriges Theme mit externer ID "%s" nicht gefunden. Set wird ohne Theme importiert.', $set_name, $theme_id_external), 'warning');
            }
        }

        // 2. Set (lww_set CPT) erstellen oder aktualisieren
        // Wir identifizieren Sets über ihre eindeutige Setnummer ('setnum') als Metadatum.
        $existing_set_posts = get_posts([
            'post_type'  => 'lww_set',
            'meta_key'   => 'lww_set_num', // Annahme: Wir speichern die Set Number als Meta-Feld
            'meta_value' => $set_num,
            'posts_per_page' => 1,
            'fields'     => 'ids',
            'post_status' => 'publish', // Oder 'any'
        ]);

        $set_post_id = 0;
        $post_data = [
            'post_title'   => $set_name,
            'post_content' => $set_description,
            'post_status'  => 'publish',
            'post_type'    => 'lww_set',
        ];

        if (!empty($existing_set_posts)) {
            $set_post_id = $existing_set_posts[0];
            $post_data['ID'] = $set_post_id;
            $update_result = wp_update_post($post_data, true);

            if (is_wp_error($update_result)) {
                lww_log_job_message($job_id, sprintf('FEHLER: Set "%s" (ID: %d) konnte nicht aktualisiert werden: %s', $set_name, $set_post_id, $update_result->get_error_message()), 'error');
                $failed_rows++;
                continue;
            } else {
                lww_log_job_message($job_id, sprintf('INFO: Set "%s" (ID: %d) erfolgreich aktualisiert.', $set_name, $set_post_id));
            }
        } else {
            $insert_result = wp_insert_post($post_data, true);
            if (is_wp_error($insert_result)) {
                lww_log_job_message($job_id, sprintf('FEHLER: Set "%s" konnte nicht erstellt werden: %s', $set_name, $insert_result->get_error_message()), 'error');
                $failed_rows++;
                continue;
            } else {
                $set_post_id = $insert_result;
                lww_log_job_message($job_id, sprintf('INFO: Set "%s" (ID: %d) erfolgreich erstellt.', $set_name, $set_post_id));
            }
        }

        // 3. Taxonomie zuweisen (Theme)
        if ($set_post_id && $theme_wp_id) {
            wp_set_object_terms($set_post_id, (int)$theme_wp_id, 'lww_theme');
        }

        // 4. Metadaten speichern
        if ($set_post_id) {
            update_post_meta($set_post_id, 'lww_set_num', $set_num); // Die eindeutige Set Number
            update_post_meta($set_post_id, 'lww_set_name', $set_name); // Für Schnellsuche
            update_post_meta($set_post_id, 'lww_bricklink_id', sanitize_text_field($data['bricklinkid'] ?? ''));
            update_post_meta($set_post_id, 'lww_brickowl_id', sanitize_text_field($data['brickowlid'] ?? ''));
            update_post_meta($set_post_id, 'lww_rebrickable_id', sanitize_text_field($data['rebrickableid'] ?? ''));
            update_post_meta($set_post_id, 'lww_num_parts', intval($data['num_parts'] ?? 0)); // Anzahl der Teile im Set
            update_post_meta($set_post_id, 'lww_year_released', intval($data['year_released'] ?? 0)); // Erscheinungsjahr
            update_post_meta($set_post_id, 'lww_retail_price', floatval($data['retail_price'] ?? 0)); // UVP
            // HIER KÖNNTEN WEITERE META-DATEN GESPEICHERT WERDEN
        }

        // 5. Beitragsbild (Thumbnail) von URL importieren und setzen
        if ($set_post_id && !empty($image_url)) {
            // Sicherstellen, dass die Media-Funktionen geladen sind
            if ( ! function_exists( 'media_handle_upload' ) ) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
            }
            $attachment_id = media_sideload_image($image_url, $set_post_id, $set_name, 'id');
            if (!is_wp_error($attachment_id)) {
                set_post_thumbnail($set_post_id, $attachment_id);
                lww_log_job_message($job_id, sprintf('INFO: Set "%s" - Bild von URL importiert und als Thumbnail gesetzt. ID: %d', $set_name, $attachment_id));
            } else {
                lww_log_job_message($job_id, sprintf('WARNUNG: Set "%s" - Fehler beim Import des Bildes von URL "%s": %s', $set_name, $image_url, $attachment_id->get_error_message()), 'warning');
            }
        }

        $processed_rows++;
    }

    fclose($file_handle);
    lww_log_job_message($job_id, sprintf('Set-Import abgeschlossen. %d Zeilen verarbeitet, %d fehlgeschlagen.', $processed_rows, $failed_rows));

    return ['processed_rows' => $processed_rows, 'failed_rows' => $failed_rows];
}


/**
 * Importiert Minifiguren-Daten aus einer CSV-Datei.
 * Erstellt oder aktualisiert 'lww_minifig' CPTs.
 *
 * @param string $file_path Der absolute Pfad zur CSV-Datei.
 * @param int    $job_id    Der ID des aktuellen Jobs (für Logging/Status-Updates).
 * @param int    $task_index Index der aktuellen Aufgabe im Job.
 * @return array Ein assoziatives Array mit 'processed_rows' und 'failed_rows'.
 */
function lww_import_minifigs_csv($file_path, $job_id, $task_index) {
    if (!file_exists($file_path)) {
        lww_log_job_message($job_id, sprintf('FEHLER: Minifigs CSV-Datei nicht gefunden: %s', basename($file_path)), 'error');
        return ['processed_rows' => 0, 'failed_rows' => 0];
    }

    $processed_rows = 0;
    $failed_rows = 0;
    $file_handle = fopen($file_path, 'r');

    if (!$file_handle) {
        lww_log_job_message($job_id, sprintf('FEHLER: Konnte Minifigs CSV-Datei nicht öffnen: %s', basename($file_path)), 'error');
        return ['processed_rows' => 0, 'failed_rows' => 0];
    }

    $header = fgetcsv($file_handle);
    if (empty($header)) {
        lww_log_job_message($job_id, 'FEHLER: Minifigs CSV-Datei ist leer oder hat keine Kopfzeile.', 'error');
        fclose($file_handle);
        return ['processed_rows' => 0, 'failed_rows' => 0];
    }

    $header = array_map('trim', $header);
    $header = array_map('mb_strtolower', $header);

    // Erforderliche Spalten für Minifiguren
    $required_columns = ['fignum', 'figname']; // fignum als eindeutiger Identifikator
    foreach ($required_columns as $col) {
        if (!in_array($col, $header)) {
            lww_log_job_message($job_id, sprintf('FEHLER: Erforderliche Spalte "%s" fehlt in der Minifigs CSV-Datei.', $col), 'error');
            fclose($file_handle);
            return ['processed_rows' => 0, 'failed_rows' => 0];
        }
    }

    lww_log_job_message($job_id, sprintf('Starte Import von Minifiguren aus %s.', basename($file_path)));

    while (($row = fgetcsv($file_handle)) !== FALSE) {
        if (count($header) !== count($row)) {
            lww_log_job_message($job_id, sprintf('WARNUNG: Zeile übersprungen in Minifigs CSV aufgrund abweichender Spaltenanzahl. Zeile: %s', implode(', ', $row)), 'warning');
            $failed_rows++;
            continue;
        }

        $data = array_combine($header, array_map('trim', $row));

        $fig_num = sanitize_text_field($data['fignum'] ?? ''); // z.B. BrickLink Minifig Number
        $fig_name = sanitize_text_field($data['figname'] ?? '');
        $fig_description = wp_kses_post($data['figdescription'] ?? '');
        $image_url = esc_url_raw($data['imageurl'] ?? ''); // URL zum Hauptbild der Minifigur

        if (empty($fig_num) || empty($fig_name)) {
            lww_log_job_message($job_id, sprintf('WARNUNG: Minifigur übersprungen - Nummer oder Name fehlt. Daten: %s', implode(', ', $data)), 'warning');
            $failed_rows++;
            continue;
        }

        // ---------- Ermittlung und Verknüpfung für Minifiguren (CPT lww_minifig) ----------

        // 1. Minifigur (lww_minifig CPT) erstellen oder aktualisieren
        // Wir identifizieren Minifiguren über ihre eindeutige Nummer ('fignum') als Metadatum.
        $existing_minifig_posts = get_posts([
            'post_type'  => 'lww_minifig',
            'meta_key'   => 'lww_minifig_num', // Annahme: Wir speichern die Minifig Number als Meta-Feld
            'meta_value' => $fig_num,
            'posts_per_page' => 1,
            'fields'     => 'ids',
            'post_status' => 'publish', // Oder 'any'
        ]);

        $minifig_post_id = 0;
        $post_data = [
            'post_title'   => $fig_name,
            'post_content' => $fig_description,
            'post_status'  => 'publish',
            'post_type'    => 'lww_minifig',
        ];

        if (!empty($existing_minifig_posts)) {
            $minifig_post_id = $existing_minifig_posts[0];
            $post_data['ID'] = $minifig_post_id;
            $update_result = wp_update_post($post_data, true);

            if (is_wp_error($update_result)) {
                lww_log_job_message($job_id, sprintf('FEHLER: Minifigur "%s" (ID: %d) konnte nicht aktualisiert werden: %s', $fig_name, $minifig_post_id, $update_result->get_error_message()), 'error');
                $failed_rows++;
                continue;
            } else {
                lww_log_job_message($job_id, sprintf('INFO: Minifigur "%s" (ID: %d) erfolgreich aktualisiert.', $fig_name, $minifig_post_id));
            }
        } else {
            $insert_result = wp_insert_post($post_data, true);
            if (is_wp_error($insert_result)) {
                lww_log_job_message($job_id, sprintf('FEHLER: Minifigur "%s" konnte nicht erstellt werden: %s', $fig_name, $insert_result->get_error_message()), 'error');
                $failed_rows++;
                continue;
            } else {
                $minifig_post_id = $insert_result;
                lww_log_job_message($job_id, sprintf('INFO: Minifigur "%s" (ID: %d) erfolgreich erstellt.', $fig_name, $minifig_post_id));
            }
        }

        // 2. Metadaten speichern
        if ($minifig_post_id) {
            update_post_meta($minifig_post_id, 'lww_minifig_num', $fig_num); // Die eindeutige Minifig Number
            update_post_meta($minifig_post_id, 'lww_minifig_name', $fig_name); // Für Schnellsuche
            update_post_meta($minifig_post_id, 'lww_bricklink_id', sanitize_text_field($data['bricklinkid'] ?? ''));
            update_post_meta($minifig_post_id, 'lww_brickowl_id', sanitize_text_field($data['brickowlid'] ?? ''));
            update_post_meta($minifig_post_id, 'lww_rebrickable_id', sanitize_text_field($data['rebrickableid'] ?? ''));
            update_post_meta($minifig_post_id, 'lww_year_released', intval($data['year_released'] ?? 0)); // Erscheinungsjahr
            // HIER KÖNNTEN WEITERE META-DATEN GESPEICHERT WERDEN
            // Z.B. `set_num_origin` wenn die Minifig aus einem bestimmten Set stammt
        }

        // 3. Beitragsbild (Thumbnail) von URL importieren und setzen
        if ($minifig_post_id && !empty($image_url)) {
            // Sicherstellen, dass die Media-Funktionen geladen sind
            if ( ! function_exists( 'media_handle_upload' ) ) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
            }
            $attachment_id = media_sideload_image($image_url, $minifig_post_id, $fig_name, 'id');
            if (!is_wp_error($attachment_id)) {
                set_post_thumbnail($minifig_post_id, $attachment_id);
                lww_log_job_message($job_id, sprintf('INFO: Minifigur "%s" - Bild von URL importiert und als Thumbnail gesetzt. ID: %d', $fig_name, $attachment_id));
            } else {
                lww_log_job_message($job_id, sprintf('WARNUNG: Minifigur "%s" - Fehler beim Import des Bildes von URL "%s": %s', $fig_name, $image_url, $attachment_id->get_error_message()), 'warning');
            }
        }

        $processed_rows++;
    }

    fclose($file_handle);
    lww_log_job_message($job_id, sprintf('Minifiguren-Import abgeschlossen. %d Zeilen verarbeitet, %d fehlgeschlagen.', $processed_rows, $failed_rows));

    return ['processed_rows' => $processed_rows, 'failed_rows' => $failed_rows];
}
?>