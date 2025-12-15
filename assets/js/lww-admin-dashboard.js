jQuery(document).ready(function($) {
    // Live Ticker Logik
    var $tickerContent = $('#lww-live-ticker-content');
    var $tickerIcon = $('#lww-live-ticker-icon');
    
    if ($tickerContent.length) {
        // Sofortigen Aufruf starten, damit der Benutzer nicht 5s warten muss
        fetchTickerUpdate();

        // Intervall für Updates setzen
        setInterval(fetchTickerUpdate, 5000);
    }

    function fetchTickerUpdate() {
        // Nutze lww_dashboard_data.ajax_url statt globaler ajaxurl für bessere Kompatibilität
        var ajaxUrl = (typeof lww_dashboard_data !== 'undefined' && lww_dashboard_data.ajax_url) 
                      ? lww_dashboard_data.ajax_url 
                      : ajaxurl;

        // Nonce aus lokalisierten Daten
        var nonce = (typeof lww_dashboard_data !== 'undefined' && lww_dashboard_data.nonce) 
                    ? lww_dashboard_data.nonce 
                    : '';

        if (!nonce) return; // Abbruch wenn Daten fehlen

        $.post(ajaxUrl, { 
            action: 'lww_get_live_ticker_update', 
            _ajax_nonce: nonce 
        }, function(res) {
            if(res.success) {
                var data = res.data;
                var html = '';
                
                if (data.status === 'running') {
                    html = '<strong style="color:#fff;"><span style="margin-right:5px;">▶</span> ' + data.job_title + '</strong>: <span style="color:#ffd700;">' + data.message + '</span>';
                    $tickerIcon.addClass('spin').css('color', '#ffd700');
                } else {
                    html = '<em style="color:#ccc;">' + data.message + '</em>';
                    $tickerIcon.removeClass('spin').css('color', '');
                }
                
                // Nur updaten, wenn sich der Text geändert hat, um Flackern zu vermeiden
                if ($tickerContent.html() !== html) {
                    $tickerContent.fadeOut(200, function() {
                        $(this).html(html).fadeIn(200);
                    });
                }
            }
        });
    }
});