<?php
if ( ! defined( 'ABSPATH' ) ) exit;
use WC_Hub_Dilicom\Admin\Admin_Settings;
$creds_set = Admin_Settings::are_credentials_set();
?>
<div class="wrap whd-wrap">
    <h1 class="whd-page-title">
        <span class="dashicons dashicons-download"></span>
        <?php esc_html_e( 'Import groupé — HUB Dilicom', 'wc-hub-dilicom' ); ?>
    </h1>

    <?php if ( ! $creds_set ) : ?>
    <div class="notice notice-warning"><p>
        <?php esc_html_e( 'Configurez vos identifiants Dilicom dans Réglages avant d\'utiliser l\'import.', 'wc-hub-dilicom' ); ?>
    </p></div>
    <?php endif; ?>

    <!-- Filtres avancés -->
    <div class="whd-card">
        <h2><?php esc_html_e( 'Filtres de recherche', 'wc-hub-dilicom' ); ?></h2>
        <div class="whd-filters-row">
            <div class="whd-filter-group">
                <label for="whd-import-gln"><?php esc_html_e( 'GLN Distributeur', 'wc-hub-dilicom' ); ?></label>
                <input type="text" id="whd-import-gln" class="regular-text" placeholder="<?php esc_attr_e( 'Tous', 'wc-hub-dilicom' ); ?>" maxlength="13" />
            </div>
            <div class="whd-filter-group">
                <label for="whd-import-type"><?php esc_html_e( 'Type de livre', 'wc-hub-dilicom' ); ?></label>
                <select id="whd-import-type">
                    <option value=""><?php esc_html_e( 'Tous', 'wc-hub-dilicom' ); ?></option>
                    <option value="digital"><?php esc_html_e( 'Numérique', 'wc-hub-dilicom' ); ?></option>
                    <option value="physical"><?php esc_html_e( 'Physique', 'wc-hub-dilicom' ); ?></option>
                </select>
            </div>
            <div class="whd-filter-group whd-filter-group--price">
                <label><?php esc_html_e( 'Prix (€)', 'wc-hub-dilicom' ); ?></label>
                <input type="number" id="whd-import-price-min" class="small-text" placeholder="0" min="0" step="0.01" />
                <span>&nbsp;—&nbsp;</span>
                <input type="number" id="whd-import-price-max" class="small-text" placeholder="999" min="0" step="0.01" />
            </div>
            <div class="whd-filter-group">
                <label for="whd-import-category"><?php esc_html_e( 'Catégorie (mot-clé)', 'wc-hub-dilicom' ); ?></label>
                <input type="text" id="whd-import-category" class="regular-text" placeholder="<?php esc_attr_e( 'Ex: Astronomie', 'wc-hub-dilicom' ); ?>" />
            </div>
            <div class="whd-filter-group">
                <label for="whd-import-author"><?php esc_html_e( 'Auteur', 'wc-hub-dilicom' ); ?></label>
                <input type="text" id="whd-import-author" class="regular-text" placeholder="<?php esc_attr_e( 'Nom de l\'auteur', 'wc-hub-dilicom' ); ?>" />
            </div>
            <div class="whd-filter-group">
                <label for="whd-import-ean"><?php esc_html_e( 'EAN13', 'wc-hub-dilicom' ); ?></label>
                <input type="text" id="whd-import-ean" class="regular-text" placeholder="<?php esc_attr_e( 'EAN13 exact', 'wc-hub-dilicom' ); ?>" maxlength="13" />
            </div>
            <div class="whd-filter-group whd-filter-group--btn">
                <button type="button" id="whd-import-search" class="button button-primary">
                    <span class="dashicons dashicons-search"></span>
                    <?php esc_html_e( 'Appliquer les filtres', 'wc-hub-dilicom' ); ?>
                </button>
            </div>
        </div>
    </div>

    <!-- Barre d'actions -->
    <div id="whd-import-actions-bar" class="whd-actions-bar" style="display:none;">
        <label>
            <input type="checkbox" id="whd-import-select-all" />
            <?php esc_html_e( 'Tout sélectionner', 'wc-hub-dilicom' ); ?>
        </label>
        <span id="whd-import-selected-count" class="whd-selected-count">0 <?php esc_html_e( 'sélectionné(s)', 'wc-hub-dilicom' ); ?></span>
        <button type="button" id="whd-import-start" class="button button-primary">
            <span class="dashicons dashicons-download"></span>
            <?php esc_html_e( 'Importer la sélection', 'wc-hub-dilicom' ); ?>
        </button>
        <span id="whd-import-result" class="whd-inline-result" aria-live="polite"></span>
    </div>

    <!-- Chargement -->
    <div id="whd-import-loading" class="whd-loading" style="display:none;">
        <span class="spinner is-active"></span>
        <?php esc_html_e( 'Chargement des livres…', 'wc-hub-dilicom' ); ?>
    </div>

    <!-- Tableau des résultats -->
    <div id="whd-import-results" style="display:none;">
        <div class="whd-card">
            <div class="whd-list-header">
                <h2>
                    <?php esc_html_e( 'Résultats', 'wc-hub-dilicom' ); ?>
                    <span id="whd-import-count" class="whd-badge"></span>
                </h2>
                <div class="whd-pagination" id="whd-import-pagination-top"></div>
            </div>
            <table class="wp-list-table widefat fixed striped" id="whd-import-table">
                <thead>
                    <tr>
                        <th class="check-column" style="width:36px;"><input type="checkbox" id="whd-import-check-page" title="<?php esc_attr_e( 'Sélectionner cette page', 'wc-hub-dilicom' ); ?>" /></th>
                        <th style="width:60px;"><?php esc_html_e( 'Couv.', 'wc-hub-dilicom' ); ?></th>
                        <th><?php esc_html_e( 'Titre', 'wc-hub-dilicom' ); ?></th>
                        <th><?php esc_html_e( 'Auteur(s)', 'wc-hub-dilicom' ); ?></th>
                        <th style="width:120px;"><?php esc_html_e( 'EAN13', 'wc-hub-dilicom' ); ?></th>
                        <th style="width:80px;"><?php esc_html_e( 'Prix', 'wc-hub-dilicom' ); ?></th>
                        <th style="width:90px;"><?php esc_html_e( 'Type', 'wc-hub-dilicom' ); ?></th>
                        <th><?php esc_html_e( 'Éditeur', 'wc-hub-dilicom' ); ?></th>
                        <th><?php esc_html_e( 'Publié', 'wc-hub-dilicom' ); ?></th>
                        <th style="width:100px;"><?php esc_html_e( 'Statut', 'wc-hub-dilicom' ); ?></th>
                    </tr>
                </thead>
                <tbody id="whd-import-tbody"></tbody>
            </table>
            <div class="whd-pagination" id="whd-import-pagination-bottom" style="margin-top:12px;"></div>
        </div>
    </div>

    <!-- Barre de progression -->
    <div id="whd-import-progress-wrap" class="whd-card" style="display:none;">
        <h2><?php esc_html_e( 'Import en cours', 'wc-hub-dilicom' ); ?></h2>
        <div class="whd-progress-info">
            <span id="whd-import-progress-done">0</span> / <span id="whd-import-progress-total">0</span>
            <?php esc_html_e( 'livres importés', 'wc-hub-dilicom' ); ?>
        </div>
        <div class="whd-progress-bar-wrap">
            <div id="whd-import-progress-bar" class="whd-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                <span id="whd-import-progress-pct">0%</span>
            </div>
        </div>
        <p id="whd-import-status-msg" class="whd-status-msg" aria-live="polite"></p>
    </div>
    <script type="text/javascript">
jQuery(document).ready(function($) {
    var currentPage = 1;
    var totalPages  = 1;

    // Fonction de chargement des résultats
    function loadCatalog(page) {
        page = page || currentPage;
        $('#whd-import-loading').show();
        $('#whd-import-results, #whd-import-actions-bar').hide();

        var filters = {
            gln:        $('#whd-import-gln').val().trim(),
            type:       $('#whd-import-type').val(),
            price_min:  $('#whd-import-price-min').val(),
            price_max:  $('#whd-import-price-max').val(),
            category:   $('#whd-import-category').val().trim(),
            author:     $('#whd-import-author').val().trim(),
            ean13:      $('#whd-import-ean').val().trim(),
            mode:       'sinceDate',
            sinceDate:  new Date(Date.now() - 7 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
            paged:      page
        };

        $.post(whdImport.ajaxurl, {
            action: 'whd_browse_catalog',
            nonce:  whdImport.nonce,
            ...filters
        }, function(response) {
            $('#whd-import-loading').hide();
            if (response.success) {
                var items = response.data.items || [];
                totalPages  = response.data.pages || 1;
                currentPage = response.data.current_page || 1;
                renderTable(items);
                renderPagination();
                $('#whd-import-count').text(response.data.total + ' livre(s)');
                $('#whd-import-results, #whd-import-actions-bar').show();
                updateSelectionBar();
            } else {
                alert(response.data.message || 'Erreur lors du chargement.');
            }
        }).fail(function() {
            $('#whd-import-loading').hide();
            alert('Erreur réseau');
        });
    }

    // Rendu du tableau
    function renderTable(items) {
        var $tbody = $('#whd-import-tbody').empty();
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
                authors = book.contributors.map(function(c){ return c.name; }).join(', ');
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

    // Pagination
    function renderPagination() {
        var $top = $('#whd-import-pagination-top'), $bottom = $('#whd-import-pagination-bottom');
        $top.empty(); $bottom.empty();
        if (totalPages <= 1) return;
        var html = '';
        if (currentPage > 1) html += '<button class="button whd-page-btn" data-page="' + (currentPage - 1) + '">« Préc.</button> ';
        html += '<span>Page ' + currentPage + ' / ' + totalPages + '</span>';
        if (currentPage < totalPages) html += ' <button class="button whd-page-btn" data-page="' + (currentPage + 1) + '">Suiv. »</button>';
        $top.html(html); $bottom.html(html);
    }

    $(document).on('click', '.whd-page-btn', function() {
        loadCatalog(parseInt($(this).data('page')));
        $('html,body').animate({scrollTop: $('#whd-import-results').offset().top - 50}, 200);
    });

    // Cases à cocher
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

    // Import
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
                setTimeout(function() { loadCatalog(currentPage); }, 2000);
                return;
            }
            var ean = eans[done + errors];
            var gln = $('#whd-import-gln').val().trim() || '3012410001000';
            $.post(whdImport.ajaxurl, {
                action: 'whd_import_single',
                nonce:  whdImport.nonce,
                ean13:  ean,
                gln_distributor: gln
            }, function() {
                done++;
                updateProgress(done + errors, eans.length);
                processNext();
            }).fail(function() {
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

    // Bouton rechercher
    $('#whd-import-search').on('click', function() { loadCatalog(1); });

    function esc(s) { return $('<div>').text(s).html(); }
});
</script>
</div>