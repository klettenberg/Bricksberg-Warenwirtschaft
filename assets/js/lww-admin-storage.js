/**
 * LEGO WaWi Admin - Lagerverwaltung UI (v1.0)
 *
 * Fügt Autocomplete-Funktionalität zu den Lagerort-Eingabefeldern hinzu.
 */
jQuery(document).ready(function($) {

    // Prüfen, ob wir auf der richtigen Seite sind und die Daten vorhanden sind.
    if (typeof lww_storage_data === 'undefined' || typeof lww_storage_data.locations === 'undefined') {
        return;
    }

    var availableLocations = lww_storage_data.locations;
    var container = $('#wpbody-content'); // Stabiler Eltern-Container für die Event-Delegation

    // Nutze Event-Delegation, um das Autocomplete-Widget an das Eingabefeld zu binden,
    // sobald es den Fokus erhält. Dies ist robust für dynamisch geladene Inhalte.
    container.on('focus', '.lww-assign-location-form input[name="location_name"]', function() {
        var $input = $(this);

        // Prüfen, ob das Widget bereits initialisiert wurde, um doppelte Bindungen zu vermeiden.
        if ($input.data('ui-autocomplete')) {
            return;
        }

        $input.autocomplete({
            source: availableLocations,
            minLength: 0, // Vorschläge bereits bei Fokus anzeigen, ohne Eingabe
            classes: {
                "ui-autocomplete": "lww-autocomplete" // Eigene Klasse für Styling
            }
        }).focus(function() {
            // Öffnet die Vorschlagsliste, sobald das Feld fokussiert wird.
            $(this).autocomplete("search", "");
        });
    });

});
