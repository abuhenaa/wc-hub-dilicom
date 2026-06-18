/* global whdImport, jQuery */
(function ($) {
    'use strict';

    var currentPage = 1;
    var totalPages  = 1;
    var currentItems = [];
    var currentGln = '';

    function ajax(action, data, onSuccess, onError) {
        data.action = action;
        data.nonce = whdImport.nonce;
        $.post(whdImport.ajaxurl, data)
            .done(function(r) { r.success ? onSuccess(r.data) : onError && onError(r.data || { message: 'Erreur inconnue.' }); })
            .fail(function(xhr) { onError && onError({ message: 'Erreur réseau HTTP ' + xhr.status }); });
    }

    function showLoading() { $('#whd-import-loading').show(); }
    function hideLoading() { $('#whd-import-loading').hide(); }
    function showError(msg) { alert(msg); }

    function getFilters() {
        return {
            gln:        $('#whd-import-gln').val().trim(),
            type:       $('#whd-import-type').val(),
            price_min:  $('#whd-import-price-min').val(),
            price_max:  $('#whd-import-price-max').val(),
            category:   $('#whd-import-category').val().trim(),
            author:     $('#whd-import-author').val().trim(),
            ean13:      $('#whd-import-ean').val().trim()
        };
    }

    function loadResults(page) {
        page = page || currentPage;
        showLoading();
        $('#whd-import-results, #whd-import-actions-bar').hide();
        var filters = getFilters();
        filters.mode = 'sinceDate';
        var d = new Date(Date.now() - 7 * 24 * 60 * 60 * 1000);
        filters.sinceDate = d.toISOString().split('T')[0];
        filters.paged = page;
        ajax('whd_browse_catalog', filters, function(data) {
            hideLoading();
            currentItems = data.items || [];
            totalPages = data.pages || 1;
            currentPage = data.current_page || 1;
            renderTable(currentItems);
            renderPagination();
            $('#whd-import-count').text(data.total + ' livre(s)');
            $('#whd-import-results, #whd-import-actions-bar').show();
            updateSelectionBar();
        }, function(err) {
            hideLoading();
            showError(err.message);
        });
    }

    function renderTable(items) {
        var $tbody = $('#whd-import-tbody').empty();
        // 10 colonnes
        if (!items.length) {
            $tbody.append('<tr><td colspan="10" style="text-align:center;padding:20px;">Aucun résultat.</td></tr>');
            return;
        }
        $.each(items, function(i, book) {
            var cover = book.cover_url
                ? '<img src="' + esc(book.cover_url) + '" width="40" height="56" alt="">'
                : '<span class="dashicons dashicons-book-alt"></span>';
            var type = book.book_type === 'digital' ? 'Num.' : (book.book_type === 'physical' ? 'Phys.' : '—');
            var price = book.unit_price ? (book.unit_price / 100).toFixed(2).replace('.', ',') + ' €' : '—';
            var authors = '';
            if (Array.isArray(book.contributors)) {
                authors = book.contributors.map(function(c) { return c.name; }).join(', ');
            } else if (typeof book.contributors === 'string') {
                authors = book.contributors;
            }
            var publisher = book.publisher || '—';
            var pubDate = (book.publication_date || '').toString();
            if (pubDate.length === 8) pubDate = pubDate.replace(/^(\d{4})(\d{2})(\d{2})$/, '$3/$2/$1');
            var status = book.imported
                ? '<span class="whd-badge whd-badge--success">✓ Importé</span>'
                : '<span class="whd-badge whd-badge--neutral">—</span>';
            var checkbox = book.imported
                ? ''
                : '<input type="checkbox" class="whd-import-checkbox" value="' + esc(book.ean13) + '" />';

            // Ordre : checkbox, couv, titre, auteur(s), ean13, prix, type, éditeur, publié, statut
            $tbody.append(
                '<tr data-ean="' + esc(book.ean13) + '">' +
                '<td class="check-column">' + checkbox + '</td>' +
                '<td>' + cover + '</td>' +
                '<td><strong>' + esc(book.title||'—') + '</strong></td>' +
                '<td>' + esc(authors || '—') + '</td>' +
                '<td><code>' + esc(book.ean13) + '</code></td>' +
                '<td>' + price + '</td>' +
                '<td>' + type + '</td>' +
                '<td>' + esc(publisher) + '</td>' +
                '<td>' + esc(pubDate || '—') + '</td>' +
                '<td>' + status + '</td>' +
                '</tr>'
            );
        });
        $('#whd-import-check-page').prop('checked', false);
    }

    // ── Pagination, cases à cocher, import ── (inchangé)
    function renderPagination() {
        var $top = $('#whd-import-pagination-top'),
            $bottom = $('#whd-import-pagination-bottom');
        $top.empty(); $bottom.empty();
        if (totalPages <= 1) return;
        var html = '';
        if (currentPage > 1) html += '<button class="button whd-page-btn" data-page="' + (currentPage - 1) + '">« Préc.</button> ';
        html += '<span>Page ' + currentPage + ' / ' + totalPages + '</span>';
        if (currentPage < totalPages) html += ' <button class="button whd-page-btn" data-page="' + (currentPage + 1) + '">Suiv. »</button>';
        $top.html(html); $bottom.html(html);
    }

    $(document).on('click', '.whd-page-btn', function() {
        loadResults(parseInt($(this).data('page')));
        $('html,body').animate({scrollTop: $('#whd-import-results').offset().top - 50}, 200);
    });

    $('#whd-import-check-page').on('change', function() {
        var checked = $(this).is(':checked');
        $('.whd-import-checkbox').each(function() { if (!$(this).prop('disabled')) $(this).prop('checked', checked); });
        updateSelectionBar();
    });
    $(document).on('change', '.whd-import-checkbox', updateSelectionBar);
    $('#whd-import-select-all').on('change', function() {
        var checked = $(this).is(':checked');
        $('.whd-import-checkbox').each(function() { if (!$(this).prop('disabled')) $(this).prop('checked', checked); });
        updateSelectionBar();
    });

    function updateSelectionBar() {
        var count = $('.whd-import-checkbox:checked').length;
        $('#whd-import-selected-count').text(count + ' sélectionné(s)');
        $('#whd-import-start').prop('disabled', count === 0);
    }

    $('#whd-import-start').on('click', function() {
        var eans = $('.whd-import-checkbox:checked').map(function() { return $(this).val(); }).get();
        if (!eans.length) return;
        if (!confirm('Importer ' + eans.length + ' livre(s) ?')) return;
        startImport(eans);
    });

    function startImport(eans) {
        var $progress = $('#whd-import-progress-wrap');
        $progress.show();
        $('#whd-import-progress-total').text(eans.length);
        $('#whd-import-progress-done').text(0);
        $('#whd-import-progress-bar').css('width', '0%');
        $('#whd-import-progress-pct').text('0%');
        $('#whd-import-start').prop('disabled', true);

        var done = 0, errors = 0;

        function processNext() {
            if (done + errors >= eans.length) {
                $('#whd-import-result').text('Import terminé : ' + done + ' succès, ' + errors + ' erreur(s).').addClass('success');
                $('#whd-import-start').prop('disabled', false);
                setTimeout(function() { loadResults(currentPage); }, 2000);
                return;
            }
            var ean = eans[done + errors];
            ajax('whd_import_single', {
                ean13: ean,
                gln_distributor: getFilters().gln || '3012410001000'
            }, function(data) {
                done++;
                updateProgress(done + errors, eans.length);
                processNext();
            }, function(err) {
                errors++;
                updateProgress(done + errors, eans.length);
                processNext();
            });
        }

        function updateProgress(current, total) {
            var pct = Math.round((current / total) * 100);
            $('#whd-import-progress-done').text(current);
            $('#whd-import-progress-bar').css('width', pct + '%').attr('aria-valuenow', pct);
            $('#whd-import-progress-pct').text(pct + '%');
        }

        processNext();
    }

    $('#whd-import-search').on('click', function() { loadResults(1); });

    function esc(s) { return $('<div>').text(s).html(); }

})(jQuery);