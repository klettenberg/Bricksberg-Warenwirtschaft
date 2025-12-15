jQuery(document).ready(function($) {

    var $container = $('.lww-settings-page-form');
    // Sicherheitscheck: Existiert das Formular?
    if ($container.length === 0) return;

    var $tabs = $('.nav-tab-wrapper .nav-tab');
    var $contents = $('.lww-settings-tab-content');
    var $hiddenInput = $('#lww_active_tab_input');
    var storageKey = 'lww_active_settings_tab';

    function activateTab(tabId) {
        if (!tabId || tabId.indexOf('#') !== 0) return;
        
        // UI Update
        $tabs.removeClass('nav-tab-active');
        $('.nav-tab-wrapper .nav-tab[href="' + tabId + '"]').addClass('nav-tab-active');
        
        // Content Update
        $contents.hide();
        $(tabId).show();
        
        // State Persistence
        localStorage.setItem(storageKey, tabId);
        if($hiddenInput.length) $hiddenInput.val(tabId.replace('#', ''));
    }

    $tabs.on('click', function(e) {
        e.preventDefault();
        activateTab($(this).attr('href'));
    });

    // Initialisierung beim Laden
    var savedTab = localStorage.getItem(storageKey);
    // Wenn URL Hash vorhanden, hat dieser Vorrang (optional)
    if (window.location.hash && $(window.location.hash).length > 0) {
        savedTab = window.location.hash;
    }
    
    // Validierung: Existiert der Tab überhaupt? Wenn nicht, Default.
    if (!savedTab || $(savedTab).length === 0) {
        savedTab = '#lww_settings_api';
    }
    
    activateTab(savedTab);

    // --- External Store Logic ---
    // (Hier keine Änderungen, Logik bleibt gleich, wird nur erneut eingebunden)
    
    $('#lww-add-store-btn').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var name = $('#new_store_name').val();
        var url = $('#new_store_url').val();
        var key = $('#new_store_key').val();
        var secret = $('#new_store_secret').val();

        if(!name || !url || !key || !secret) {
            alert('Bitte alle Felder ausfüllen.');
            return;
        }

        $btn.prop('disabled', true);
        $('#lww-store-spinner').addClass('is-active');

        $.post(lww_settings_data.ajax_url, {
            action: 'lww_add_external_store',
            _ajax_nonce: lww_settings_data.nonce,
            name: name, url: url, key: key, secret: secret
        }).done(function(res) {
            if(res.success) {
                $('#lww-external-stores-list').html(res.data.html);
                $('#new_store_name, #new_store_url, #new_store_key, #new_store_secret').val('');
            } else {
                alert('Fehler: ' + res.data);
            }
        }).always(function(){
            $btn.prop('disabled', false);
            $('#lww-store-spinner').removeClass('is-active');
        });
    });

    $(document).on('click', '.lww-delete-store', function(e) {
        if(!confirm('Diesen Shop entfernen?')) return;
        var id = $(this).data('id');
        $.post(lww_settings_data.ajax_url, {
            action: 'lww_delete_external_store',
            _ajax_nonce: lww_settings_data.nonce,
            id: id
        }).done(function(res) {
            if(res.success) $('#lww-external-stores-list').html(res.data.html);
        });
    });

    $(document).on('click', '.lww-test-store', function(e) {
        var $btn = $(this);
        var id = $btn.data('id');
        var $status = $btn.closest('tr').find('.lww-store-status');
        
        $btn.prop('disabled', true).text('Teste...');
        
        $.post(lww_settings_data.ajax_url, {
            action: 'lww_test_external_store',
            _ajax_nonce: lww_settings_data.nonce,
            id: id
        }).done(function(res) {
            if(res.success) {
                $status.html('<span style="color:green" class="dashicons dashicons-yes"></span> OK');
            } else {
                $status.html('<span style="color:red" class="dashicons dashicons-no"></span> ' + res.data);
            }
        }).always(function() { $btn.prop('disabled', false).text('Verbindung testen'); });
    });
});