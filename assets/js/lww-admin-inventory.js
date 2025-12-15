jQuery(document).ready(function($) {
    var $container = $('#inventory-filter'); 
    if ($container.length === 0 && $('.wp-list-table').length > 0) {
        $container = $('body'); // Fallback scope if container ID missing
    }

    function handleAjaxButtonClick(e, action, getRequestData, onSuccess) {
        e.preventDefault();
        var $button = $(e.currentTarget);
        var originalButtonHTML = $button.html();

        if ($button.is('.loading') || $button.is('.success')) return;

        var requestData;
        try {
            requestData = getRequestData($button);
            if (!requestData) return;
        } catch (error) {
            alert(error.message);
            return;
        }

        requestData.action = action;
        requestData._ajax_nonce = lww_inventory_data.nonce;

        $button.prop('disabled', true).addClass('loading').html('<span class="dashicons dashicons-update spin"></span>');

        $.post(lww_inventory_data.ajax_url, requestData)
            .done(function(response) {
                if (response.success) {
                    $button.removeClass('loading').addClass('success').html('<span class="dashicons dashicons-yes"></span>');
                    if (onSuccess) onSuccess($button, response.data);
                    setTimeout(function() { 
                        $button.prop('disabled', false).removeClass('success').html(originalButtonHTML); 
                    }, 3000);
                } else {
                    alert('Fehler: ' + (response.data.message || 'Unknown'));
                    $button.prop('disabled', false).removeClass('loading').html(originalButtonHTML);
                }
            })
            .fail(function() {
                alert('Server-Fehler.');
                $button.prop('disabled', false).removeClass('loading').html(originalButtonHTML);
            });
    }

    // Generic WC Sync Handler supporting Parts, Sets, Minifigs
    $container.on('click', '.lww-ajax-create-wc-product', function(e) {
        handleAjaxButtonClick(e, 'lww_create_wc_product', function($button) {
            var itemID = $button.data('item-id');
            var catalogID = $button.data('catalog-id'); // Use generic catalog ID
            
            // Fallback for legacy buttons
            if(!catalogID) catalogID = $button.data('part-id');

            if (!itemID) throw new Error('Data Item ID missing');
            return { item_id: itemID, catalog_id: catalogID };
        });
    });

    // BrickLink Sync Handler
    $container.on('click', '.lww-ajax-sync-bricklink', function(e) {
        handleAjaxButtonClick(e, 'lww_sync_bricklink_item', function($button) {
            var itemID = $button.data('item-id');
            if (!itemID) throw new Error('Item ID missing');
            return { item_id: itemID };
        });
    });

    // BrickOwl Sync Handler
    $container.on('click', '.lww-ajax-sync-brickowl', function(e) {
        handleAjaxButtonClick(e, 'lww_sync_brickowl_item', function($button) {
            var itemID = $button.data('item-id');
            if (!itemID) throw new Error('Item ID missing');
            return { item_id: itemID };
        });
    });

    // NEU: Handler für Bundle-Erstellung via Modal
    $container.on('click', '.lww-create-bundle-from-list', function(e) {
        e.preventDefault();
        var $button = $(this);
        var itemID = $button.data('item-id');
        
        if (!itemID) return;

        // URL für ThickBox Content (AJAX)
        var modalUrl = lww_inventory_data.ajax_url + '?action=lww_get_bundle_creation_form&item_id=' + itemID + '&_wpnonce=' + lww_inventory_data.nonce + '&TB_iframe=true&width=500&height=400';
        
        tb_show('Schnelles Bundle erstellen', modalUrl);
    });

    // Event Listener für Formular im ThickBox iFrame (muss an body delegiert werden)
    $('body').on('submit', '#lww-quick-bundle-form', function(e) {
        e.preventDefault();
        var $form = $(this);
        var $submitBtn = $form.find('button[type="submit"]');
        var originalText = $submitBtn.text();

        $submitBtn.prop('disabled', true).text('Erstelle...');

        $.post(lww_inventory_data.ajax_url, $form.serialize())
            .done(function(response) {
                if(response.success) {
                    alert('Bundle erfolgreich erstellt!');
                    parent.tb_remove();
                    // Optional: Seite neu laden oder Button Status ändern
                } else {
                    alert('Fehler: ' + response.data.message);
                    $submitBtn.prop('disabled', false).text(originalText);
                }
            })
            .fail(function() {
                alert('Server Fehler');
                $submitBtn.prop('disabled', false).text(originalText);
            });
    });
});