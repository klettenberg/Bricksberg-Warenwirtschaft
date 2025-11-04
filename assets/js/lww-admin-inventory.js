/**
 * LEGO WaWi Admin - Inventar UI (v13.0)
 *
 * Behandelt AJAX-Aktionen auf der "Inventar Verwalten"-Seite,
 * primär die Erstellung/Aktualisierung von WooCommerce-Produkten und Preisabrufe.
 */
jQuery(document).ready(function($) {

    var $container = $('#inventory-filter'); // Das Formular, das die Tabelle umschließt
    if ($container.length === 0) {
        return; // Wir sind nicht auf der richtigen Seite
    }

    // --- Handler für WooCommerce Produkt Erstellung/Update ---
    $container.on('click', '.lww-ajax-create-wc-product', function(e) {
        e.preventDefault();

        var $button = $(this);
        var $statusColumn = $button.closest('tr').find('.column-wc_status');
        var originalButtonHTML = $button.html();

        if ($button.is('.loading') || $button.is('.success')) {
            return;
        }

        var itemID = $button.data('item-id');
        var partID = $button.data('part-id');
        
        if (!itemID || !partID) {
            alert('Fehler: data-item-id oder data-part-id fehlt am Button.');
            return;
        }
        
        $button.prop('disabled', true).addClass('loading');
        $button.html('<span class="spinner is-active" style="float:left; margin: 0 5px 0 0;"></span>' + 'Verarbeite...');

        var requestData = {
            action: 'lww_create_wc_product',
            _ajax_nonce: lww_inventory_data.nonce,
            item_id: itemID,
            part_id: partID
        };

        $.post(lww_inventory_data.ajax_url, requestData)
            .done(function(response) {
                if (response.success) {
                    $button.removeClass('loading').addClass('success').html('<span class="dashicons dashicons-yes-alt"></span> ' + 'Aktualisiert');
                    $statusColumn.html(response.data.status_html);
                    setTimeout(function() {
                        $button.prop('disabled', false).removeClass('success').html(originalButtonHTML.replace('Erstellen', 'Aktualisieren'));
                    }, 3000);
                } else {
                    handleAjaxError($button, originalButtonHTML, response.data.message || 'Unbekannter Server-Fehler.');
                }
            })
            .fail(function(jqXHR) {
                var errorMsg = (jqXHR.responseJSON && jqXHR.responseJSON.data) ? jqXHR.responseJSON.data.message : 'AJAX-Anfrage fehlgeschlagen.';
                handleAjaxError($button, originalButtonHTML, errorMsg);
            });
    });

    // --- Handler für BrickOwl Preis-Abruf ---
    $container.on('click', '.lww-ajax-get-brickowl-price', function(e) {
        e.preventDefault();

        var $button = $(this);
        var $priceColumn = $button.closest('tr').find('.column-price .lww-item-price-display');
        var $originalButtonIcon = $button.html(); // Originales Icon speichern

        if ($button.is('.loading') || $button.is('.success')) {
            return;
        }

        var itemID = $button.data('item-id');
        var boid = $button.data('boid');

        if (!itemID || !boid) {
            alert('Fehler: data-item-id oder data-boid fehlt am Button.');
            return;
        }

        $button.prop('disabled', true).addClass('loading');
        $button.html('<span class="spinner is-active"></span>');

        var requestData = {
            action: 'lww_get_brickowl_price',
            _ajax_nonce: lww_inventory_data.nonce,
            item_id: itemID,
            boid: boid
        };

        $.post(lww_inventory_data.ajax_url, requestData)
            .done(function(response) {
                if (response.success) {
                    $button.removeClass('loading').addClass('success').html('<span class="dashicons dashicons-yes-alt"></span>');
                    $priceColumn.html(response.data.new_price_html); // Preis-Anzeige aktualisieren
                    
                    // Button zurücksetzen
                    setTimeout(function() {
                         $button.prop('disabled', false).removeClass('success').html($originalButtonIcon);
                    }, 2000);

                } else {
                    handleAjaxError($button, $originalButtonIcon, response.data.message || 'Unbekannter Server-Fehler.');
                }
            })
            .fail(function(jqXHR) {
                var errorMsg = (jqXHR.responseJSON && jqXHR.responseJSON.data) ? jqXHR.responseJSON.data.message : 'AJAX-Anfrage fehlgeschlagen.';
                handleAjaxError($button, $originalButtonIcon, errorMsg);
            });
    });

    /**
     * Zentrale Fehlerbehandlungsfunktion für AJAX-Buttons.
     * @param {jQuery} $button Das Button-Objekt.
     * @param {string} originalContent Der ursprüngliche HTML-Inhalt des Buttons.
     * @param {string} message Die Fehlermeldung.
     */
    function handleAjaxError($button, originalContent, message) {
        var errorIcon = '<span class="dashicons dashicons-warning"></span>';
        if ($button.hasClass('lww-ajax-create-wc-product')) {
            errorIcon += ' Fehler'; // Füge Text nur für den größeren Button hinzu
        }

        $button.removeClass('loading').addClass('error').html(errorIcon);
        console.error('LWW Admin Error:', message);
        // alert('Fehler: ' + message); // Optional: Alert anzeigen

        setTimeout(function() {
            $button.prop('disabled', false).removeClass('error').html(originalContent);
        }, 5000);
    }
});
