jQuery(document).ready(function($) {
    
    var $jobListContainer = $('#lww-job-list-container');
    var $toggleCheckbox = $('#lww-job-refresh-toggle');
    var $rateSelect = $('#lww-job-refresh-rate');
    var $loadingIndicator = $('#lww-job-loading-indicator'); // NEU: Indikator Referenz
    
    if ($jobListContainer.length === 0) return;

    var lww_job_fetch_interval;
    var lww_job_trigger_interval;
    
    // Standardwert aus Select oder Fallback auf 5s
    var refresh_rate_ms = parseInt($rateSelect.val()) || 5000; 
    var trigger_rate_ms = 10000; // Client-Side Heartbeat alle 10 Sek

    function fetchJobList() {
        // NEU: Visuelles Feedback beim Start des Fetch
        if ($toggleCheckbox.is(':checked')) {
             $loadingIndicator.show().addClass('spin');
             $jobListContainer.addClass('lww-is-updating');
        }

        var requestData = {
            action: 'lww_get_job_list_table',
            _ajax_nonce: lww_jobs_data.nonce
        };

        $.post(lww_jobs_data.ajax_url, requestData)
            .done(function(response) {
                if (response.success && response.data) {
                    $jobListContainer.html(response.data);
                    
                    // FIX: Wenn ein Job als 'running' markiert ist, feuern wir sofort den Prozessor an.
                    if ($jobListContainer.find('.status-running').length > 0) {
                        console.log('LWW: Running job detected, triggering processor...');
                        triggerBatchProcessor();
                    }
                }
            })
            .fail(function() {
                console.error('LWW Job Refresh: AJAX-Fehler. Stoppe Auto-Refresh.');
                stopIntervals();
            })
            .always(function() {
                // NEU: Feedback entfernen wenn fertig (egal ob Erfolg oder Fehler)
                $loadingIndicator.hide().removeClass('spin');
                $jobListContainer.removeClass('lww-is-updating');
            });
    }

    // Client-Side Cron Trigger / Heartbeat
    // Stößt den PHP Batch Prozessor aktiv an
    function triggerBatchProcessor() {
        $.post(lww_jobs_data.ajax_url, {
            action: 'lww_trigger_batch_process',
            _ajax_nonce: lww_jobs_data.nonce
        });
    }

    function startIntervals() {
        if (lww_job_fetch_interval) clearInterval(lww_job_fetch_interval);
        lww_job_fetch_interval = setInterval(fetchJobList, refresh_rate_ms);

        if (lww_job_trigger_interval) clearInterval(lww_job_trigger_interval);
        lww_job_trigger_interval = setInterval(triggerBatchProcessor, trigger_rate_ms);
        
        // Sofort einmal ausführen
        triggerBatchProcessor();
    }

    function stopIntervals() {
        clearInterval(lww_job_fetch_interval);
        lww_job_fetch_interval = null;
        clearInterval(lww_job_trigger_interval);
        lww_job_trigger_interval = null;
    }

    $toggleCheckbox.on('change', function() {
        if ($(this).is(':checked')) {
            startIntervals();
            fetchJobList();
        } else {
            stopIntervals();
        }
    });
    
    // Event Listener für die Änderung des Intervalls
    $rateSelect.on('change', function() {
        refresh_rate_ms = parseInt($(this).val());
        if ($toggleCheckbox.is(':checked')) {
            // Restart mit neuer Rate
            stopIntervals();
            startIntervals();
        }
    });

    // Delegierter ThickBox Handler (wichtig, da Inhalt neu geladen wird)
    $('body').on('click', '#lww-job-list-container a.thickbox', function(e) {
        e.preventDefault();
        tb_show($(this).attr('title') || 'Log', $(this).attr('href'));
        $(this).blur();
    });

    // Start bei Page Load, wenn Checkbox an ist
    if ($toggleCheckbox.is(':checked')) {
        startIntervals();
        // Initialer Fetch falls wir von einem Redirect (Start Button) kommen
        fetchJobList();
    }
});