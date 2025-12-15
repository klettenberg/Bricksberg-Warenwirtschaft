jQuery(document).ready(function($) {

    var $container = $('#data-correction-filter');
    if ($container.length === 0) {
        return;
    }

    var $noticesContainer = $('#lww-correction-notices');

    /**
     * Handler für einzelne Korrektur-Buttons.
     */
    $container.on('click', '.lww-correct-single-reference', function(e) {
        e.preventDefault();
        var $button = $(this);
        var $row = $button.closest('tr');
        
        var correctionData = [{
            job_id: $row.data('job-id'),
            unique_key: $row.data('unique-key'),
            correction_value: $row.find('input[name="correction_value"]').val(),
            correction_type: $row.find('input[name="correction_type"]').val()
        }];

        sendCorrectionRequest(correctionData, $button);
    });

    /**
     * Handler für den Bulk-Korrektur-Button.
     */
    $('#lww-bulk-correct-references').on('click', function(e) {
        e.preventDefault();
        var $button = $(this);
        var corrections = [];

        $container.find('input[name="ref_keys[]"]:checked').each(function() {
            var $row = $(this).closest('tr');
            corrections.push({
                job_id: $row.data('job-id'),
                unique_key: $row.data('unique-key'),
                correction_value: $row.find('input[name="correction_value"]').val(),
                correction_type: $row.find('input[name="correction_type"]').val()
            });
        });

        if (corrections.length === 0) {
            alert('Bitte wählen Sie zuerst die zu korrigierenden Einträge aus.');
            return;
        }

        sendCorrectionRequest(corrections, $button);
    });

    /**
     * Sendet die AJAX-Anfrage zur Korrektur.
     * @param {Array} corrections Array von Korrektur-Objekten.
     * @param {jQuery} $button Der geklickte Button für das UI-Feedback.
     */
    function sendCorrectionRequest(corrections, $button) {
        var originalButtonText = $button.html();

        $button.prop('disabled', true).html('<span class="spinner is-active"></span> Verarbeite...');

        $.post(lww_correction_data.ajax_url, {
            action: 'lww_handle_corrections',
            nonce: lww_correction_data.nonce,
            corrections: corrections
        })
        .done(function(response) {
            if (response.success) {
                // UI aktualisieren
                if (response.data.corrected_keys && response.data.corrected_keys.length > 0) {
                    response.data.corrected_keys.forEach(function(item) {
                        $container.find('tr[data-job-id="' + item.job_id + '"][data-unique-key="' + item.unique_key + '"]').fadeOut(400, function() {
                            $(this).remove();
                        });
                    });
                }

                // Erfolgsnachricht anzeigen
                var successMessage = response.data.success_count + ' Referenz(en) erfolgreich korrigiert.';
                if (response.data.fail_count > 0) {
                    successMessage += ' ' + response.data.fail_count + ' Fehler.';
                }
                showNotice(successMessage, 'success');

            } else {
                showNotice(response.data.message || 'Ein unbekannter Fehler ist aufgetreten.', 'error');
            }
        })
        .fail(function() {
            showNotice('Die AJAX-Anfrage ist fehlgeschlagen. Bitte überprüfen Sie die Server-Logs.', 'error');
        })
        .always(function() {
            $button.prop('disabled', false).html(originalButtonText);
        });
    }

    /**
     * Zeigt eine Admin-Notiz an.
     * @param {string} message Die anzuzeigende Nachricht.
     * @param {string} type 'success' oder 'error'.
     */
    function showNotice(message, type) {
        var noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
        var $notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + message + '</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Diese Meldung ausblenden.</span></button></div>');
        
        $noticesContainer.html($notice);

        $notice.find('.notice-dismiss').on('click', function() {
            $notice.fadeOut(300, function() { $(this).remove(); });
        });

        // Nach 10 Sekunden automatisch ausblenden
        setTimeout(function() {
            $notice.fadeOut(500, function() { $(this).remove(); });
        }, 10000);
    }
});
