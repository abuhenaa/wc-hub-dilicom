/* global whdCatalog, jQuery */
(function ($) {
    'use strict';

    var currentPage  = 1;
    var totalPages   = 1;
    var currentItems = [];
    var currentGln   = '';

    // ── Helpers Ajax ────────────────────────────────────────────────────────
    function ajax(action, data, onSuccess, onError) {
    data.action = action;
    data.nonce  = whdCatalog.nonce;
    $.post(whdCatalog.ajaxurl, data)
        .done(function(r, status, xhr) {
            if (r && r.success) {
                onSuccess(r.data);
            } else if (r && r.data) {
                onError && onError(r.data);
            } else {
                onError && onError({ message: 'Réponse inattendue du serveur (action: ' + action + ').' });
            }
        })
        .fail(function(xhr, status, err) {
            var msg = 'Erreur réseau [' + action + '] HTTP ' + xhr.status + ' — ' + (xhr.responseText ? xhr.responseText.substring(0, 300) : err);
            console.error('[WHD]', msg, xhr.responseText);
            onError && onError({ message: msg });
        });
}

    function showError(msg) {
        $('#whd-catalog-error p').text(msg || 'Erreur inconnue.');
        $('#whd-catalog-error').show();
        $('#whd-catalog-loading').hide();
    }

    // ── Affichage/masquage date selon mode ───────────────────────────────────
    $('#whd-notices-mode').on('change', function(){
        $('#whd-notices-since').toggle($(this).val() === 'sinceDate');
    });

    // ── Charger la liste des livres disponibles ──────────────────────────────
    $('#whd-load-catalog').on('click', function(){
        currentPage = 1;
        loadCatalog();
    });

    function loadCatalog(page) {
        page = page || currentPage;
        currentGln = $.trim($('#whd-notices-gln').val());
        var mode  = $('#whd-notices-mode').val();
        var since = $('#whd-notices-since').val();

        $('#whd-catalog-error').hide();
        $('#whd-catalog-loading').show();
        $('#whd-catalog-list-wrap').hide();
        $('#whd-catalog-result').hide();

        ajax('whd_browse_catalog', {
            gln: currentGln, mode: mode, sinceDate: since, paged: page
        }, function(data) {
            $('#whd-catalog-loading').hide();
            currentItems = data.items || [];
            totalPages   = data.pages || 1;
            currentPage  = data.current_page || 1;

            renderTable(currentItems);
            renderPagination();

            $('#whd-catalog-count').text(data.total + ' livre(s)');
            $('#whd-catalog-list-wrap').show();
        }, function(data) {
            showError(data.message);
        });
    }

    // ── Rendu du tableau ─────────────────────────────────────────────────────
    function renderTable(items) {
        var $tbody = $('#whd-catalog-tbody').empty();
        if (!items.length) {
            $tbody.append('<tr><td colspan="7" style="text-align:center;padding:20px;">Aucun livre trouvé.</td></tr>');
            return;
        }
        $.each(items, function(i, book) {
            var cover = book.cover_url
                ? '<img src="' + esc(book.cover_url) + '" alt="" style="width:50px;height:auto;" loading="lazy">'
                : '<span class="dashicons dashicons-book-alt" style="font-size:32px;color:#ccc;"></span>';

            var type  = book.book_type === 'digital' ? '📱 Num.' : (book.book_type === 'physical' ? '📖 Phys.' : '—');
            var price = book.unit_price ? (book.unit_price / 100).toFixed(2).replace('.', ',') + ' €' : '—';

            var statusBadge = book.imported
                ? '<span class="whd-badge whd-badge--success">✓ Importé</span>'
                : '<span class="whd-badge whd-badge--neutral">Non importé</span>';

            var actions = '<button class="button button-small whd-preview-btn" data-ean="' + esc(book.ean13) + '" data-gln="' + esc(book.gln_distributor||currentGln) + '">🔍 Voir</button> ';
            if (book.imported && book.product_id) {
                actions += '<a href="' + esc(whdCatalog.adminUrl) + '?action=edit&post=' + book.product_id + '" target="_blank" class="button button-small">✏️</a> ';
            } else {
                actions += '<button class="button button-small button-primary whd-import-row-btn" data-ean="' + esc(book.ean13) + '" data-gln="' + esc(book.gln_distributor||currentGln) + '">⬇ Importer</button>';
            }

            $tbody.append(
                '<tr id="row-' + esc(book.ean13) + '">' +
                '<td style="text-align:center;">' + cover + '</td>' +
                '<td><strong>' + esc(book.title||'—') + '</strong>' + (book.subtitle ? '<br><small>'+esc(book.subtitle)+'</small>' : '') + '</td>' +
                '<td><code>' + esc(book.ean13) + '</code></td>' +
                '<td>' + type + '</td>' +
                '<td>' + price + '</td>' +
                '<td>' + statusBadge + '</td>' +
                '<td>' + actions + '</td>' +
                '</tr>'
            );
        });
    }

    // ── Pagination ───────────────────────────────────────────────────────────
    function renderPagination() {
        var html = '';
        if (totalPages <= 1) { $('#whd-pagination-top,#whd-pagination-bottom').html(''); return; }
        if (currentPage > 1) html += '<button class="button whd-page-btn" data-page="'+(currentPage-1)+'">« Préc.</button> ';
        html += '<span style="padding:0 8px;">Page ' + currentPage + ' / ' + totalPages + '</span>';
        if (currentPage < totalPages) html += ' <button class="button whd-page-btn" data-page="'+(currentPage+1)+'">Suiv. »</button>';
        $('#whd-pagination-top,#whd-pagination-bottom').html(html);
    }

    $(document).on('click', '.whd-page-btn', function(){
        currentPage = parseInt($(this).data('page'), 10);
        loadCatalog(currentPage);
        $('html,body').animate({scrollTop: $('#whd-catalog-list-wrap').offset().top - 50}, 200);
    });

    // ── Prévisualisation depuis la liste ─────────────────────────────────────
    $(document).on('click', '.whd-preview-btn', function(){
        var ean = $(this).data('ean');
        var gln = $(this).data('gln') || currentGln;
        loadPreview(ean, gln);
    });

    // ── Recherche directe EAN ────────────────────────────────────────────────
    $('#whd-catalog-search').on('click', function(){
        var ean = $.trim($('#whd-catalog-ean13').val());
        if (!ean) { alert('Veuillez saisir un EAN13.'); return; }
        var gln = currentGln || $.trim($('#whd-notices-gln').val());
        loadPreview(ean, gln);
    });

    $('#whd-catalog-ean13').on('keydown', function(e){ if (e.key==='Enter') $('#whd-catalog-search').trigger('click'); });

    function loadPreview(ean, gln) {
        $('#whd-catalog-error').hide();
        $('#whd-catalog-loading').show();
        $('#whd-catalog-result').hide();

        ajax('whd_search_catalog', { ean13: ean, gln_distributor: gln }, function(data){
            $('#whd-catalog-loading').hide();
            renderPreview(data);
            $('html,body').animate({scrollTop: $('#whd-catalog-result').offset().top - 50}, 200);
        }, function(data){
            showError(data.message);
        });
    }

    // ── Rendu de la prévisualisation ─────────────────────────────────────────
    function renderPreview(book) {
        setText('#whd-book-title',        book.title                 || '—');
        setText('#whd-book-subtitle',     book.subtitle              || '');
        setText('#whd-book-ean13',        book.ean13                 || '—');
        setText('#whd-book-publisher',    book.publisher             || '—');
        setText('#whd-book-form',         book.product_form          || '—');
        setText('#whd-book-availability', book.product_availability  || '—');
        setText('#whd-book-gln',          book.gln_distributor || currentGln || '—');

        var type  = book.book_type === 'digital' ? '📱 Numérique' : (book.book_type === 'physical' ? '📖 Physique' : '—');
        var price = book.unit_price ? (book.unit_price / 100).toFixed(2).replace('.', ',') + ' €' : '—';
        setText('#whd-book-type',  type);
        setText('#whd-book-price', price);

        if (book.cover_url) {
            $('#whd-book-cover').attr('src', book.cover_url).attr('alt', book.title||'').show();
            $('#whd-book-no-cover').hide();
        } else {
            $('#whd-book-cover').hide();
            $('#whd-book-no-cover').show();
        }

        if (book.description) $('#whd-book-description').html(book.description);

        var gln = book.gln_distributor || currentGln;
        $('#whd-import-single').data('ean13', book.ean13).data('gln', gln);
        $('#whd-import-single-result').text('').removeClass('success error');

        if (book.imported && book.product_id) {
            $('#whd-import-single').hide();
            $('#whd-edit-product').attr('href', whdCatalog.adminUrl + '?action=edit&post=' + book.product_id).show();
        } else {
            $('#whd-import-single').show();
            $('#whd-edit-product').hide();
        }

        $('#whd-catalog-result').show();
    }

    // ── Import depuis prévisualisation ───────────────────────────────────────
    $(document).on('click', '#whd-import-single', function(){
        var ean = $(this).data('ean13');
        var gln = $(this).data('gln') || currentGln;
        var $r  = $('#whd-import-single-result');
        if (!ean || !gln) { $r.text('EAN13 et GLN requis.').addClass('error'); return; }
        $(this).prop('disabled', true).addClass('updating-message');
        $r.text('Import…').removeClass('success error');
        ajax('whd_import_single', { ean13: ean, gln_distributor: gln }, function(data){
            $r.text(data.message || 'Importé !').addClass('success');
            $('#whd-import-single').prop('disabled', false).removeClass('updating-message').hide();
            if (data.product_id) {
                $('#whd-edit-product').attr('href', whdCatalog.adminUrl + '?action=edit&post=' + data.product_id).show();
            }
            // Rafraîchir la ligne dans le tableau
            var $row = $('#row-' + ean);
            if ($row.length) {
                $row.find('.whd-badge').replaceWith('<span class="whd-badge whd-badge--success">✓ Importé</span>');
                $row.find('.whd-import-row-btn').replaceWith('<a href="' + whdCatalog.adminUrl + '?action=edit&post=' + (data.product_id||0) + '" target="_blank" class="button button-small">✏️</a>');
            }
        }, function(data){
            $r.text(data.message || 'Erreur.').addClass('error');
            $('#whd-import-single').prop('disabled', false).removeClass('updating-message');
        });
    });

    // ── Import depuis la liste ────────────────────────────────────────────────
    $(document).on('click', '.whd-import-row-btn', function(){
        var $btn = $(this);
        var ean  = $btn.data('ean');
        var gln  = $btn.data('gln') || currentGln;
        $btn.prop('disabled', true).text('…');
        ajax('whd_import_single', { ean13: ean, gln_distributor: gln }, function(data){
            $btn.closest('tr').find('.whd-badge').replaceWith('<span class="whd-badge whd-badge--success">✓ Importé</span>');
            $btn.replaceWith('<a href="' + whdCatalog.adminUrl + '?action=edit&post=' + (data.product_id||0) + '" target="_blank" class="button button-small">✏️</a>');
        }, function(data){
            alert(data.message || 'Erreur d\'import.');
            $btn.prop('disabled', false).text('⬇ Importer');
        });
    });

    // ── Fermer prévisualisation ───────────────────────────────────────────────
    $(document).on('click', '#whd-close-preview', function(){
        $('#whd-catalog-result').hide();
    });

    // ── Helpers ──────────────────────────────────────────────────────────────
    function setText(sel, val) { $(sel).text(val); }
    function esc(s) { return $('<div>').text(s).html(); }

})(jQuery);