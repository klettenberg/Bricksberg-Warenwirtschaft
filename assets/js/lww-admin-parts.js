/**
 * LEGO WaWi Admin - Teile-Varianten UI (v1.0)
 *
 * Behandelt die Interaktivität auf der "Teile-Varianten"-Seite,
 * insbesondere das Ein- und Ausklappen der detaillierten Variantenliste.
 */
jQuery(document).ready(function($) {

    var $container = $('#lww-parts-filter'); // Das Formular, das die Tabelle umschließt
    if ($container.length === 0) {
        return; // Wir sind nicht auf der richtigen Seite
    }

    // Nutze Event Delegation für den Klick-Handler, damit er auch nach dem Sortieren/Filtern funktioniert
    $container.on('click', '.lww-variants-toggle', function() {
        // Finde den übergeordneten Container und schalte die 'is-expanded' Klasse um
        // Das CSS kümmert sich um die eigentliche Animation.
        $(this).closest('.lww-variants-container').toggleClass('is-expanded');
    });

});
