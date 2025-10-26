<?php
/**
 * Modul: Job Status Registrierung (v9.0 - Unverändert von v8.3)
 * Registriert die Custom Post Status für 'lww_job'.
 */
if (!defined('ABSPATH')) exit;

/**
 * Registriert die benutzerdefinierten Post-Status für Jobs.
 */
function lww_register_job_post_statuses() {
    register_post_status('lww_pending', array(
        'label'                     => _x('Wartend', 'post status', 'lego-wawi'),
        'public'                    => false, // Nicht im Frontend sichtbar
        'exclude_from_search'       => true,
        'show_in_admin_all_list'    => true, // In der "Alle Jobs"-Liste anzeigen
        'show_in_admin_status_list' => true, // Als Filter-Option anzeigen
        'label_count'               => _n_noop('Wartend <span class="count">(%s)</span>', 'Wartend <span class="count">(%s)</span>', 'lego-wawi'),
    ));
    register_post_status('lww_running', array(
        'label'                     => _x('Laufend', 'post status', 'lego-wawi'),
        'public'                    => false,
        'exclude_from_search'       => true,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop('Laufend <span class="count">(%s)</span>', 'Laufend <span class="count">(%s)</span>', 'lego-wawi'),
    ));
     register_post_status('lww_complete', array(
        'label'                     => _x('Abgeschlossen', 'post status', 'lego-wawi'),
        'public'                    => false,
        'exclude_from_search'       => true,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop('Abgeschlossen <span class="count">(%s)</span>', 'Abgeschlossen <span class="count">(%s)</span>', 'lego-wawi'),
    ));
     register_post_status('lww_failed', array(
        'label'                     => _x('Fehlgeschlagen', 'post status', 'lego-wawi'),
        'public'                    => false,
        'exclude_from_search'       => true,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop('Fehlgeschlagen <span class="count">(%s)</span>', 'Fehlgeschlagen <span class="count">(%s)</span>', 'lego-wawi'),
    ));
}
// Wichtig: Muss früh in 'init' laufen, bevor 'register_post_type' ausgeführt wird
add_action('init', 'lww_register_job_post_statuses', 1); // Priorität 1