jQuery(document).ready(function($) {
    
    // Prüfen, ob wir auf der Job-Liste sind (sicherstellen, dass die Container da sind)
    var $jobListContainer = $('#lww-job-list-container');
    var $toggleCheckbox = $('#lww-job-refresh-toggle');
    
    if ($jobListContainer.length === 0) {
        // Wir sind nicht auf der richtigen Seite, nichts tun
        return; 
    }

    var lww_job_fetch_interval; // Variable für unseren Timer
    var refresh_rate_ms = 7000; // 7 Sekunden

    /**
     * Holt die aktualisierte Job-Tabelle vom Server.
     */
    function fetchJobList() {
        
        // Hole alle aktuellen GET-Parameter (für Sortierung, Paginierung, Filter)
        var currentURLParams = new URLSearchParams(window.location.search);
        
        // Bereite die AJAX-Daten vor
        var requestData = {
            action: 'lww_get_job_list_table', // Unser WP-AJAX-Action-Hook
            _ajax_nonce: lww_jobs_data.nonce, // Sicherheits-Nonce
            
            // Reiche die URL-Parameter (Sortierung, Filter, Seite) weiter,
            // damit die WP_List_Table korrekt gerendert wird.
            paged: currentURLParams.get('paged') || '1',
            orderby: currentURLParams.get('orderby') || '',
            order: currentURLParams.get('order') || '',
            post_status: currentURLParams.get('post_status') || ''
        };

        $.post(lww_jobs_data.ajax_url, requestData)
            .done(function(response) {
                if (response.success && response.data) {
                    // Ersetze den Inhalt des Containers durch das neue HTML
                    $jobListContainer.html(response.data);
                }
            })
            .fail(function() {
                // Bei einem Fehler (z.B. Server 500) stoppen wir das Intervall,
                // um den Server nicht weiter zu belasten.
                console.error('LWW Job Refresh: AJAX-Fehler.');
                stopInterval();
            });
    }

    /**
     * Startet den Timer.
     */
    function startInterval() {
        // Verhindere doppelte Timer
        if (lww_job_fetch_interval) {
            clearInterval(lww_job_fetch_interval);
        }
        // Starte den Timer, der alle X Sekunden fetchJobList aufruft
        lww_job_fetch_interval = setInterval(fetchJobList, refresh_rate_ms);
        $toggleCheckbox.prop('checked', true); // Checkbox auf "an" setzen
    }

    /**
     * Stoppt den Timer.
     */
    function stopInterval() {
        clearInterval(lww_job_fetch_interval);
        lww_job_fetch_interval = null;
        $toggleCheckbox.prop('checked', false); // Checkbox auf "aus" setzen
    }

    // --- Event Handler ---
    
    // Reagiere auf Klicks auf die Checkbox
    $toggleCheckbox.on('change', function() {
        if ($(this).is(':checked')) {
            startInterval();
            fetchJobList(); // Einmal sofort ausführen
        } else {
            stopInterval();
        }
    });

    // --- Init ---
    // Starte den Prozess, wenn die Seite lädt und die Checkbox (standardmäßig) angehakt ist
    if ($toggleCheckbox.is(':checked')) {
        startInterval();
    }
});
