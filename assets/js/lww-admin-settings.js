/**
 * LEGO WaWi Admin - Einstellungen (v13.0)
 *
 * Behandelt AJAX-Aktionen auf der Einstellungsseite, primär die API-Verbindungstests.
 */
jQuery(document).ready(function($) {

    var $container = $('.lww-settings-page-form'); // Das Formular, das die Tabelle umschließt
    if ($container.length === 0) {
        return; // Wir sind nicht auf der richtigen Seite
    }

    $container.on('click', '.lww-test-api-connection', function(e) {
        e.preventDefault();

        var $button = $(this);
        var $resultContainer = $button.next('.lww-api-test-result');
        var service = $button.data('service');

        if ($button.is('.loading')) {
            return; // Verhindere Doppelklicks
        }

        // API-Schlüssel-Wert aus dem zugehörigen Input-Feld holen
        var apiKey = $button.closest('td').find('input[type="password"]').val();

        // 1. Lade-Status anzeigen
        $button.prop('disabled', true).addClass('loading');
        $resultContainer.removeClass('success error').empty().html('<span class="spinner is-active"></span>');

        // 2. AJAX-Daten vorbereiten
        var requestData = {
            action: 'lww_test_api_connection',
            _ajax_nonce: lww_settings_data.nonce,
            service: service,
            api_key: apiKey // Sende den Schlüssel mit (serverseitig wird er nicht geloggt)
        };

        // 3. AJAX-Call senden
        $.post(lww_settings_data.ajax_url, requestData)
            .done(function(response) {
                if (response.success) {
                    // 4a. Erfolg
                    $resultContainer.addClass('success').html('<span class="dashicons dashicons-yes-alt"></span> ' + response.data.message);
                } else {
                    // 4b. Server-Fehler (z.B. API-Key ungültig)
                    $resultContainer.addClass('error').html('<span class="dashicons dashicons-warning"></span> ' + response.data.message);
                }
            })
            .fail(function(jqXHR) {
                // 4c. AJAX-Fehler (z.B. Verbindung getrennt, Server 500)
                var errorMsg = (jqXHR.responseJSON && jqXHR.responseJSON.data) ? jqXHR.responseJSON.data.message : 'AJAX-Anfrage fehlgeschlagen.';
                 $resultContainer.addClass('error').html('<span class="dashicons dashicons-warning"></span> ' + errorMsg);
            })
            .always(function() {
                // 5. Button-Status zurücksetzen
                $button.prop('disabled', false).removeClass('loading');
            });
    });
});
