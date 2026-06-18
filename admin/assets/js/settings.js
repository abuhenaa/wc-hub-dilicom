/* global whdSettings, jQuery */
(function ($) {
    'use strict';

    var $btn    = $('#whd-test-connection');
    var $result = $('#whd-test-result');

    $btn.on('click', function () {
        $btn.prop('disabled', true).addClass('updating-message');
        $result.text('Test en cours…').removeClass('success error');

        $.ajax({
            url:    whdSettings.ajaxurl,
            method: 'POST',
            data: {
                action: 'whd_test_connection',
                nonce:  whdSettings.nonce
            },
            success: function (res) {
                $btn.prop('disabled', false).removeClass('updating-message');
                if (res.success) {
                    $result.text(res.data.message || 'Connexion réussie !').addClass('success').removeClass('error');
                } else {
                    $result.text((res.data && res.data.message) || 'Échec de connexion.').addClass('error').removeClass('success');
                }
            },
            error: function (xhr) {
                $btn.prop('disabled', false).removeClass('updating-message');
                $result.text('Erreur réseau (HTTP ' + xhr.status + ').').addClass('error').removeClass('success');
            }
        });
    });

}(jQuery));