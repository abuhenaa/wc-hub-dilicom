/* global whdAdmin, jQuery */
(function ($) {
    'use strict';

    function whdAjax(action, data, onSuccess, onError) {
        return $.ajax({
            url: whdAdmin.ajaxurl,
            method: 'POST',
            data: Object.assign({ action: action, nonce: whdAdmin.nonce }, data),
            success: function (res) {
                if (res.success) {
                    onSuccess && onSuccess(res.data);
                } else {
                    onError && onError(res.data || { message: 'Erreur.' });
                }
            },
            error: function (xhr) {
                onError && onError({ message: 'Erreur HTTP ' + xhr.status });
            }
        });
    }

    $(document).on('click', '.whd-toggle-password', function () {
        var $btn   = $(this);
        var $input = $('#' + $btn.data('target'));
        if ($input.attr('type') === 'password') {
            $input.attr('type', 'text');
            $btn.text('Masquer');
        } else {
            $input.attr('type', 'password');
            $btn.text('Afficher');
        }
    });

    window.whdAjax = whdAjax;

}(jQuery));