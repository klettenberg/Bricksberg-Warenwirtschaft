jQuery(document).ready(function($) {
    var $container = $('#lww-minifigs-filter');
    if ($container.length === 0) {
        return;
    }

    var mediaUploader;

    // 1. Bearbeiten-Button-Klick-Handler
    $container.on('click', '.lww-edit-ebay-meta', function(e) {
        e.preventDefault();

        var $button = $(this);
        var itemID = $button.data('item-id');
        
        var url = lww_minifigs_data.ajax_url + '?action=lww_get_ebay_meta_form&item_id=' + itemID + '&_wpnonce=' + lww_minifigs_data.nonce + '&TB_iframe=true&width=750&height=550';
        tb_show('eBay-Daten für Minifigur bearbeiten', url);
    });
    
    // Event-Delegation für AJAX-Buttons in der Hauptliste
    $container.on('click', '.lww-ajax-create-ebay-listing', function(e) {
        e.preventDefault();

        var $button = $(this);
        var originalButtonHTML = $button.html();
        var itemID = $button.data('item-id');

        if ($button.is('.loading') || $button.is('.success')) {
            return;
        }

        $button.prop('disabled', true).addClass('loading').html('<span class="spinner is-active" style="float:left; margin: 0 5px 0 0;"></span>Sync...');

        $.post(ajaxurl, { // ajaxurl ist global in WP Admin verfügbar
            action: 'lww_create_ebay_listing',
            _ajax_nonce: lww_minifigs_data.nonce, // Verwenden wir den gleichen Nonce zur Einfachheit
            item_id: itemID
        })
        .done(function(response) {
            if (response.success) {
                $button.removeClass('loading').addClass('success').html('<span class="dashicons dashicons-yes-alt"></span> Synced');
                setTimeout(function() {
                    location.reload();
                }, 1500);
            } else {
                alert('Fehler: ' + response.data.message);
                $button.prop('disabled', false).removeClass('loading').html(originalButtonHTML);
            }
        })
        .fail(function() {
            alert('Server-Fehler.');
            $button.prop('disabled', false).removeClass('loading').html(originalButtonHTML);
        });
    });

    // Die folgenden Handler werden an 'body' gebunden, da sie im ThickBox-iFrame ausgeführt werden.

    // 2. Handler für den Media Uploader
    $('body').on('click', '#lww-upload-gallery-button', function(e) {
        e.preventDefault();

        if (mediaUploader) {
            mediaUploader.open();
            return;
        }

        mediaUploader = wp.media.frames.file_frame = wp.media({
            title: 'Bilder für eBay-Galerie auswählen',
            button: { text: 'Bilder verwenden' },
            multiple: true
        });

        mediaUploader.on('select', function() {
            var attachments = mediaUploader.state().get('selection').toJSON();
            var $galleryContainer = $('#lww-image-gallery-container');
            var $hiddenInput = $('#lww_ebay_gallery_images');
            var currentIDs = $hiddenInput.val() ? $hiddenInput.val().split(',') : [];

            attachments.forEach(function(attachment) {
                if (currentIDs.indexOf(attachment.id.toString()) === -1) {
                    $galleryContainer.append(
                        '<div class="lww-gallery-image" data-id="' + attachment.id + '">' +
                        '<img src="' + attachment.sizes.thumbnail.url + '" />' +
                        '<a href="#" class="remove-image">&times;</a>' +
                        '</div>'
                    );
                    currentIDs.push(attachment.id);
                }
            });
            $hiddenInput.val(currentIDs.join(','));
        });
        mediaUploader.open();
    });

    // 3. Handler zum Entfernen von Bildern
    $('body').on('click', '.lww-gallery-image .remove-image', function(e) {
        e.preventDefault();
        var $imageDiv = $(this).closest('.lww-gallery-image');
        var imageID = $imageDiv.data('id').toString();
        var $hiddenInput = $('#lww_ebay_gallery_images');
        var currentIDs = $hiddenInput.val().split(',');
        var newIDs = currentIDs.filter(function(id) { return id !== imageID; });
        $hiddenInput.val(newIDs.join(','));
        $imageDiv.remove();
    });

    // 4. Handler für das Speichern des Formulars
    $('body').on('submit', '#lww-ebay-meta-form', function(e) {
        e.preventDefault();
        var $form = $(this);
        var $submitButton = $form.find('button[type="submit"]');
        var originalButtonText = $submitButton.text();
        $submitButton.prop('disabled', true).text('Speichern...');

        $.post(ajaxurl, $form.serialize())
            .done(function(response) {
                if (response.success) {
                    parent.tb_remove();
                    parent.location.reload();
                } else {
                    alert('Fehler: ' + response.data.message);
                    $submitButton.prop('disabled', false).text(originalButtonText);
                }
            })
            .fail(function() {
                alert('Ein Serverfehler ist aufgetreten.');
                $submitButton.prop('disabled', false).text(originalButtonText);
            });
    });
});
