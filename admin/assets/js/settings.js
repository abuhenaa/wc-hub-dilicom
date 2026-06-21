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

    function renderQueueStatus(data) {
        if (!data) {
            return '';
        }
        return 'File : ' + (data.pending || 0) + ' en attente, ' +
            (data.failed || 0) + ' échouée(s), ' +
            (data.total || 0) + ' au total.';
    }

    function refreshQueueStatus() {
        var $status = $('#whd-cover-queue-status');
        if (!$status.length) {
            return;
        }
        $.post(whdSettings.ajaxurl, {
            action: 'whd_cover_queue_status',
            nonce:  whdSettings.nonce
        }).done(function (res) {
            if (res.success) {
                $status.text(renderQueueStatus(res.data));
            }
        });
    }

    $('#whd-backfill-covers').on('click', function () {
        var $backfillBtn = $(this);
        var $backfillResult = $('#whd-cover-backfill-result');

        $backfillBtn.prop('disabled', true).addClass('updating-message');
        $backfillResult.text('Mise en file…').removeClass('success error');

        $.ajax({
            url:    whdSettings.ajaxurl,
            method: 'POST',
            data: {
                action: 'whd_backfill_covers',
                nonce:  whdSettings.nonce
            },
            success: function (res) {
                $backfillBtn.prop('disabled', false).removeClass('updating-message');
                if (res.success) {
                    $backfillResult.text(res.data.message || 'Couvertures mises en file.').addClass('success').removeClass('error');
                    $('#whd-cover-queue-status').text(renderQueueStatus(res.data.status));
                } else {
                    $backfillResult.text((res.data && res.data.message) || 'Échec.').addClass('error').removeClass('success');
                }
            },
            error: function (xhr) {
                $backfillBtn.prop('disabled', false).removeClass('updating-message');
                $backfillResult.text('Erreur réseau (HTTP ' + xhr.status + ').').addClass('error').removeClass('success');
            }
        });
    });

    refreshQueueStatus();

}(jQuery));
