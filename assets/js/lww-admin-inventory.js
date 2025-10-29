/**
 * LEGO WaWi Admin - Inventar UI (v10.0)
 *
 * Behandelt AJAX-Aktionen auf der "Inventar Verwalten"-Seite,
 * primär die Erstellung/Aktualisierung von WooCommerce-Produkten.
 */
jQuery(document).ready(function($) {

    var $container = $('#inventory-filter'); // Das Formular, das die Tabelle umschließt
    if ($container.length === 0) {
        return; // Wir sind nicht auf der richtigen Seite
    }

    // Wir verwenden Event-Delegation, da die Tabelle
    // (theoretisch) per AJAX neu geladen werden könnte.
    $container.on('click', '.lww-ajax-create-wc-product', function(e) {
        e.preventDefault();

        var $button = $(this);
        var $statusColumn = $button.closest('tr').find('.column-wc_status');
        var $originalButtonText = $button.html(); // Text speichern (z.B. "Erstellen")

        // Verhindere Doppelklicks
        if ($button.is('.loading') || $button.is('.success')) {
            return;
        }

        // Daten aus dem Button auslesen
        var itemID = $button.data('item-id');
        var partID = $button.data('part-id');
        
        if (!itemID || !partID) {
            alert('Fehler: data-item-id oder data-part-id fehlt am Button.');
            return;
        }
        
        // 1. Lade-Status anzeigen
        $button.prop('disabled', true).addClass('loading');
        $button.html('<span class="spinner is-active" style="float:left; margin: 0 5px 0 0;"></span>' + 'Verarbeite...');

        // 2. AJAX-Daten vorbereiten
        var requestData = {
            action: 'lww_create_wc_product',
            _ajax_nonce: lww_inventory_data.nonce, // Kommt von wp_localize_script
            item_id: itemID,
            part_id: partID
        };

        // 3. AJAX-Call senden
        $.post(lww_inventory_data.ajax_url, requestData)
            .done(function(response) {
                if (response.success) {
                    // 4a. Erfolg
                    $button.removeClass('loading').addClass('success').html('<span class="dashicons dashicons-yes-alt"></span> ' + 'Aktualisiert');
                    // Status-Spalte mit dem HTML vom Server aktualisieren
                    $statusColumn.html(response.data.status_html);
                    
                    // Button nach 3 Sek. zurücksetzen (optional, aber hilfreich)
                    setTimeout(function() {
                        $button.prop('disabled', false).removeClass('success').text('Aktualisieren');
                    }, 3000);
                    
                } else {
                    // 4b. Server-Fehler (z.B. 404, 500)
                    handleAjaxError(response.data.message || 'Unbekannter Server-Fehler.');
                }
            })
            .fail(function(jqXHR) {
                // 4c. AJAX-Fehler (z.B. Verbindung getrennt)
                var errorMsg = (jqXHR.responseJSON && jqXHR.responseJSON.data) ? jqXHR.responseJSON.data.message : 'AJAX-Anfrage fehlgeschlagen.';
                handleAjaxError(errorMsg);
            });
        
        function handleAjaxError(message) {
            $button.removeClass('loading').addClass('error').html('<span class="dashicons dashicons-warning"></span> ' + 'Fehler');
            // Logge den Fehler und setze den Button zurück
            console.error('LWW WooCommerce Sync Error:', message);
            alert('Fehler bei der Synchronisierung: ' + message);
            
            setTimeout(function() {
                $button.prop('disabled', false).removeClass('error').html($originalButtonText);
            }, 3000);
        }
    });
});
