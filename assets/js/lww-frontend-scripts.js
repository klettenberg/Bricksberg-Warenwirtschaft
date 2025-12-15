/**
 * LEGO Warenwirtschaft (WaWi) Frontend Scripts (v1.1)
 * 
 * Enthält Lightbox-Funktionalität für Galerien.
 */
jQuery(document).ready(function($) {
    
    // Einfache Lightbox für Galerie-Bilder
    if ($('.lww-gallery-item-image img').length > 0) {
        
        // Overlay erstellen
        var $overlay = $('<div id="lww-lightbox-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.8); z-index:99999; justify-content:center; align-items:center; cursor:pointer;"><img src="" style="max-width:90%; max-height:90%; border-radius:4px; box-shadow:0 0 20px rgba(0,0,0,0.5);"></div>');
        $('body').append($overlay);

        // Click Event auf Bilder (via Delegation falls dynamisch geladen)
        $(document).on('click', '.lww-gallery-item-image img', function(e) {
            e.preventDefault();
            var src = $(this).attr('src');
            // Versuch, eine größere Version zu finden (WordPress Logik: -150x150 entfernen)
            // Einfacher Hack: Wir nehmen das Original, falls im src 'medium' oder ähnliches steht, könnte man replacen.
            // Hier nehmen wir einfach das aktuelle Bild, da im Frontend oft schon 'large' geladen wird.
            
            $overlay.find('img').attr('src', src);
            $overlay.css('display', 'flex').fadeIn(200);
        });

        // Schließen beim Klick auf Overlay
        $overlay.on('click', function() {
            $(this).fadeOut(200);
        });

        // Schließen mit ESC Taste
        $(document).on('keyup', function(e) {
            if (e.key === "Escape") $overlay.fadeOut(200);
        });
    }
});
