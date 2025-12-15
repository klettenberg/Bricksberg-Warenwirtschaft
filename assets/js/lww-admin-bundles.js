jQuery(document).ready(function($) {
    var $container = $('#col-right');

    // 1. Autocomplete für die Inventar-Artikelsuche
    var $searchInput = $('#lww_inventory_item_search');
    var $hiddenInput = $('#lww_inventory_item_id');

    if ($searchInput.length > 0) {
        $searchInput.autocomplete({
            source: function(request, response) {
                $.ajax({
                    url: lww_bundles_data.ajax_url,
                    dataType: "json",
                    data: {
                        action: 'lww_search_inventory_items',
                        nonce: lww_bundles_data.nonce,
                        term: request.term
                    },
                    success: function(data) {
                        response(data);
                    }
                });
            },
            minLength: 2,
            select: function(event, ui) {
                $hiddenInput.val(ui.item.id);
            },
            change: function(event, ui) {
                if (!ui.item) {
                    $hiddenInput.val('');
                }
            }
        });
    }

    // 2. AJAX für WooCommerce Sync Button
    $container.on('click', '.lww-ajax-sync-wc-bundle', function(e) {
        e.preventDefault();

        var $button = $(this);
        var originalButtonHTML = $button.html();

        if ($button.is('.loading') || $button.is('.success')) {
            return;
        }

        var bundleID = $button.data('bundle-id');

        $button.prop('disabled', true).addClass('loading');
        $button.html('<span class="spinner is-active" style="float:left; margin: 0 5px 0 0;"></span>' + 'Sync...');

        $.post(lww_bundles_data.ajax_url, {
            action: 'lww_sync_wc_bundle',
            nonce: lww_bundles_data.nonce,
            bundle_id: bundleID
        })
        .done(function(response) {
            if (response.success) {
                $button.removeClass('loading').addClass('success').html('<span class="dashicons dashicons-yes-alt"></span> ' + 'Synced');
                setTimeout(function() {
                    location.reload(); // Seite neu laden, um den Status zu aktualisieren
                }, 1500);
            } else {
                handleBundleAjaxError($button, originalButtonHTML, response.data.message || 'Unbekannter Fehler.');
            }
        })
        .fail(function(jqXHR) {
            var errorMsg = (jqXHR.responseJSON && jqXHR.responseJSON.data) ? jqXHR.responseJSON.data.message : 'AJAX-Anfrage fehlgeschlagen.';
            handleBundleAjaxError($button, originalButtonHTML, errorMsg);
        });
    });

    function handleBundleAjaxError($button, originalContent, message) {
        $button.removeClass('loading').addClass('error').html('<span class="dashicons dashicons-warning"></span> Fehler');
        console.error('LWW Bundles Error:', message);
        alert('Fehler: ' + message);

        setTimeout(function() {
            $button.prop('disabled', false).removeClass('error').html(originalContent);
        }, 5000);
    }
});
