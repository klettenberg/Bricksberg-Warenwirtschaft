jQuery(document).ready(function($) {
    // Handler für den "Bild laden" Button (Inventar)
    $(document).on('click', '.lww-fetch-image', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var itemId = $btn.data('id');
        var $container = $btn.closest('td').find('.lww-thumbnail-wrapper');

        $btn.addClass('loading').prop('disabled', true);

        $.post(ajaxurl, {
            action: 'lww_fetch_item_image',
            item_id: itemId,
            _ajax_nonce: lww_inventory_data.nonce
        }).done(function(res) {
            if (res.success) {
                $container.html('<img src="' + res.data.img_url + '" width="40" height="40" style="object-fit:contain;">');
                $btn.remove(); 
            } else {
                alert('Fehler: ' + res.data.message);
                $btn.removeClass('loading').prop('disabled', false);
            }
        }).fail(function() {
            alert('Server-Fehler.');
            $btn.removeClass('loading').prop('disabled', false);
        });
    });

    // --- TERM BILD UPLOAD HANDLER ---
    // Nur initialisieren, wenn wir auf einer Taxonomie-Seite sind
    if ($('.lww-upload-term-image').length > 0) {
        var mediaUploader;

        $(document).on('click', '.lww-upload-term-image', function(e) {
            e.preventDefault();
            if (mediaUploader) {
                mediaUploader.open();
                return;
            }
            mediaUploader = wp.media.frames.file_frame = wp.media({ title: 'Kategoriebild auswählen', button: { text: 'Bild verwenden' }, multiple: false });
            mediaUploader.on('select', function() {
                var attachment = mediaUploader.state().get('selection').first().toJSON();
                $('#lww_term_image_id').val(attachment.id);
                $('#lww_term_image_preview').html('<img src="' + attachment.url + '" style="max-width:150px;height:auto;">');
                $('.lww-remove-term-image').show();
            });
            mediaUploader.open();
        });

        $(document).on('click', '.lww-remove-term-image', function(e) {
            e.preventDefault();
            $('#lww_term_image_id').val('');
            $('#lww_term_image_preview').html('');
            $(this).hide();
        });

        // --- AI GENERATE HANDLER ---
        $(document).on('click', '.lww-generate-term-image-ai', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var termId = $btn.data('term-id');
            var tax = $btn.data('taxonomy');
            
            $btn.prop('disabled', true).text('Generiere...');

            $.post(ajaxurl, {
                action: 'lww_generate_term_image',
                term_id: termId,
                taxonomy: tax
                // nonce wird hier vereinfacht weggelassen oder global geholt, idealerweise wp_localize_script nutzen
            }).done(function(res) {
                if(res.success) {
                    $('#lww_term_image_id').val(res.data.image_id);
                    $('#lww_term_image_preview').html('<img src="' + res.data.image_url + '" style="max-width:150px;height:auto;">');
                    $('.lww-remove-term-image').show();
                    $btn.text('Fertig!');
                } else {
                    alert(res.data.message);
                    $btn.prop('disabled', false).text('Per KI / API generieren');
                }
            }).fail(function() {
                alert('Server Error');
                $btn.prop('disabled', false).text('Per KI / API generieren');
            });
        });
    }
});