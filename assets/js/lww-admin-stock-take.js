jQuery(document).ready(function($) {
    // Prüfen, ob wir auf der richtigen Seite sind
    var $container = $('.lww-stock-take-wrap');
    if ($container.length === 0) {
        return;
    }

    var timer;
    var ajaxUrl = lww_stock_take_data.ajax_url;
    var nonce = lww_stock_take_data.nonce;

    $('#lww-stock-scanner').on('input', function() {
        clearTimeout(timer);
        var val = $(this).val().trim();
        if(val.length > 2) {
            $('#lww-stock-spinner').addClass('is-active');
            timer = setTimeout(function() { searchItem(val); }, 500);
        }
    });

    function searchItem(query) {
        $.post(ajaxUrl, {
            action: 'lww_stock_take_search',
            query: query,
            _nonce: nonce
        }, function(res) {
            $('#lww-stock-spinner').removeClass('is-active');
            if(res.success) {
                var item = res.data;
                $('#lww-stock-result').show();
                $('.item-title').text(item.title);
                $('.item-meta').html(item.meta_html);
                $('.item-image').html(item.image_html);
                $('#lww-current-stock').text(item.quantity);
                $('#lww-new-quantity').val(item.quantity).focus().select();
                $('#lww-save-stock').data('id', item.id);
                $('#lww-stock-message').html('');
            } else {
                $('#lww-stock-result').hide();
                $('#lww-stock-message').html('<div class="notice notice-error"><p>' + res.data + '</p></div>');
            }
        });
    }

    $('#lww-save-stock').on('click', function() {
        var id = $(this).data('id');
        var qty = $('#lww-new-quantity').val();
        $.post(ajaxUrl, {
            action: 'lww_stock_take_update',
            id: id,
            qty: qty,
            _nonce: nonce
        }, function(res) {
            if(res.success) {
                $('#lww-stock-message').html('<div class="notice notice-success"><p>'+res.data+'</p></div>');
                $('#lww-stock-result').hide();
                $('#lww-stock-scanner').val('').focus();
            } else {
                alert('Fehler: ' + res.data);
            }
        });
    });
    
    // Enter im Menge-Feld speichert
    $('#lww-new-quantity').on('keypress', function(e) {
        if(e.which == 13) $('#lww-save-stock').click();
    });
});