/**
 * LEGO WaWi Admin - Pick-Listen UI (v1.0)
 *
 * Behandelt UI-Interaktionen auf der Pick-Listen-Seite, wie z.B. den Druck-Button.
 */
jQuery(document).ready(function($) {

    var $container = $('#lww-pick-list-page');
    if ($container.length === 0) {
        return; // Wir sind nicht auf der richtigen Seite
    }

    // Handler für den Druck-Button
    $container.on('click', '#lww-print-pick-list-button', function(e) {
        e.preventDefault();
        window.print();
    });

});
