<?php
/**
 * Modul: Order Status Registrierung
 * Registriert die Custom Post Status für 'lww_order'.
 */
if (!defined('ABSPATH')) exit;

/**
 * Registriert die benutzerdefinierten Post-Status für Bestellungen.
 */
function lww_register_order_post_statuses() {
    // --- PERFORMANCE OPTIMIZATION ---
    // Status nur im Admin-Bereich registrieren, da der CPT 'lww_order' privat ist.
    if (!is_admin()) {
        return;
    }

    register_post_status('lww_received', array(
        'label'                     => _x('Erhalten', 'post status', 'lego-wawi'),
        'public'                    => false,
        'exclude_from_search'       => true,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop('Erhalten <span class="count">(%s)</span>', 'Erhalten <span class="count">(%s)</span>', 'lego-wawi'),
    ));
    register_post_status('lww_processing', array(
        'label'                     => _x('In Bearbeitung', 'post status', 'lego-wawi'),
        'public'                    => false,
        'exclude_from_search'       => true,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop('In Bearbeitung <span class="count">(%s)</span>', 'In Bearbeitung <span class="count">(%s)</span>', 'lego-wawi'),
    ));
    register_post_status('lww_shipped', array(
        'label'                     => _x('Versendet', 'post status', 'lego-wawi'),
        'public'                    => false,
        'exclude_from_search'       => true,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop('Versendet <span class="count">(%s)</span>', 'Versendet <span class="count">(%s)</span>', 'lego-wawi'),
    ));
     register_post_status('lww_completed', array(
        'label'                     => _x('Abgeschlossen', 'post status', 'lego-wawi'),
        'public'                    => false,
        'exclude_from_search'       => true,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop('Abgeschlossen <span class="count">(%s)</span>', 'Abgeschlossen <span class="count">(%s)</span>', 'lego-wawi'),
    ));
     register_post_status('lww_cancelled', array(
        'label'                     => _x('Storniert', 'post status', 'lego-wawi'),
        'public'                    => false,
        'exclude_from_search'       => true,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop('Storniert <span class="count">(%s)</span>', 'Storniert <span class="count">(%s)</span>', 'lego-wawi'),
    ));
}
// Muss früh in 'init' laufen, bevor 'register_post_type' ausgeführt wird
add_action('init', 'lww_register_order_post_statuses', 1);
