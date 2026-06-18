<?php
if ( ! defined( 'ABSPATH' ) ) exit;
use WC_Hub_Dilicom\Admin\Admin_Settings;
$creds_set = Admin_Settings::are_credentials_set();
?>
<div class="wrap whd-wrap">
    <h1 class="whd-page-title">
        <span class="dashicons dashicons-book-alt"></span>
        <?php esc_html_e( 'Catalogue HUB Dilicom', 'wc-hub-dilicom' ); ?>
    </h1>

    <?php if ( ! $creds_set ) : ?>
    <div class="notice notice-warning"><p>
        <?php esc_html_e( 'Configurez vos identifiants Dilicom dans Réglages avant d\'utiliser le catalogue.', 'wc-hub-dilicom' ); ?>
    </p></div>
    <?php endif; ?>

    <div class="whd-card">
        <div class="whd-catalog-toolbar">
            <div class="whd-toolbar-group">
                <label for="whd-notices-mode"><?php esc_html_e( 'Mode', 'wc-hub-dilicom' ); ?></label>
                <select id="whd-notices-mode">
                    <option value="lastConnection"><?php esc_html_e( 'Depuis dernière connexion', 'wc-hub-dilicom' ); ?></option>
                    <option value="sinceDate"><?php esc_html_e( 'Depuis une date', 'wc-hub-dilicom' ); ?></option>
                </select>
                <input type="date" id="whd-notices-since" style="display:none;" />
                <input type="text" id="whd-notices-gln" class="regular-text" placeholder="<?php esc_attr_e( 'GLN Distributeur (optionnel)', 'wc-hub-dilicom' ); ?>" maxlength="13" />
                <button type="button" id="whd-load-catalog" class="button button-primary">
                    <span class="dashicons dashicons-update"></span>
                    <?php esc_html_e( 'Charger la liste', 'wc-hub-dilicom' ); ?>
                </button>
            </div>
            <div class="whd-toolbar-group">
                <input type="text" id="whd-catalog-ean13" class="regular-text" placeholder="<?php esc_attr_e( 'EAN13 (recherche directe)', 'wc-hub-dilicom' ); ?>" maxlength="13" />
                <button type="button" id="whd-catalog-search" class="button">
                    <span class="dashicons dashicons-search"></span>
                    <?php esc_html_e( 'Prévisualiser', 'wc-hub-dilicom' ); ?>
                </button>
            </div>
        </div>
    </div>

    <div id="whd-catalog-loading" class="whd-loading" style="display:none;">
        <span class="spinner is-active"></span>
        <?php esc_html_e( 'Chargement en cours…', 'wc-hub-dilicom' ); ?>
    </div>
    <div id="whd-catalog-error" class="notice notice-error" style="display:none;"><p></p></div>

    <div id="whd-catalog-list-wrap" style="display:none;">
        <div class="whd-card">
            <div class="whd-list-header">
                <h2>
                    <?php esc_html_e( 'Livres disponibles', 'wc-hub-dilicom' ); ?>
                    <span id="whd-catalog-count" class="whd-badge"></span>
                </h2>
                <div class="whd-pagination" id="whd-pagination-top"></div>
            </div>
            <table class="wp-list-table widefat fixed striped" id="whd-catalog-table">
                <thead>
                    <tr>
                        <th style="width:80px;"><?php esc_html_e( 'Couverture', 'wc-hub-dilicom' ); ?></th>
                        <th><?php esc_html_e( 'Titre', 'wc-hub-dilicom' ); ?></th>
                        <th style="width:140px;"><?php esc_html_e( 'EAN13', 'wc-hub-dilicom' ); ?></th>
                        <th style="width:100px;"><?php esc_html_e( 'Type', 'wc-hub-dilicom' ); ?></th>
                        <th style="width:80px;"><?php esc_html_e( 'Prix', 'wc-hub-dilicom' ); ?></th>
                        <th style="width:120px;"><?php esc_html_e( 'Statut', 'wc-hub-dilicom' ); ?></th>
                        <th style="width:140px;"><?php esc_html_e( 'Actions', 'wc-hub-dilicom' ); ?></th>
                    </tr>
                </thead>
                <tbody id="whd-catalog-tbody">
                    <tr><td colspan="7" style="text-align:center;padding:20px;">
                        <?php esc_html_e( 'Cliquez sur "Charger la liste" pour afficher les livres disponibles.', 'wc-hub-dilicom' ); ?>
                    </td></tr>
                </tbody>
            </table>
            <div class="whd-pagination" id="whd-pagination-bottom" style="margin-top:12px;"></div>
        </div>
    </div>

    <div id="whd-catalog-result" class="whd-catalog-result" style="display:none;">
        <div class="whd-card whd-book-preview">
            <button type="button" id="whd-close-preview" class="button" style="float:right;">✕ <?php esc_html_e( 'Fermer', 'wc-hub-dilicom' ); ?></button>
            <div class="whd-book-cover-wrap">
                <img id="whd-book-cover" src="" alt="" style="display:none;" loading="lazy" />
                <div id="whd-book-no-cover" class="whd-no-cover"><span class="dashicons dashicons-book-alt"></span></div>
            </div>
            <div class="whd-book-info">
                <h2 id="whd-book-title" class="whd-book-title">—</h2>
                <p id="whd-book-subtitle" class="whd-book-subtitle"></p>
                <table class="whd-info-table">
                    <tr><th><?php esc_html_e( 'EAN13', 'wc-hub-dilicom' ); ?></th><td id="whd-book-ean13">—</td></tr>
                    <tr><th><?php esc_html_e( 'Type', 'wc-hub-dilicom' ); ?></th><td id="whd-book-type">—</td></tr>
                    <tr><th><?php esc_html_e( 'Prix', 'wc-hub-dilicom' ); ?></th><td id="whd-book-price">—</td></tr>
                    <tr><th><?php esc_html_e( 'Éditeur', 'wc-hub-dilicom' ); ?></th><td id="whd-book-publisher">—</td></tr>
                    <tr><th><?php esc_html_e( 'Disponibilité', 'wc-hub-dilicom' ); ?></th><td id="whd-book-availability">—</td></tr>
                    <tr><th><?php esc_html_e( 'Format ONIX', 'wc-hub-dilicom' ); ?></th><td id="whd-book-form">—</td></tr>
                    <tr><th><?php esc_html_e( 'GLN Distributeur', 'wc-hub-dilicom' ); ?></th><td id="whd-book-gln">—</td></tr>
                </table>
                <div id="whd-book-description" class="whd-book-description"></div>
                <div class="whd-import-actions">
                    <button type="button" id="whd-import-single" class="button button-primary" style="display:none;">
                        <span class="dashicons dashicons-download"></span>
                        <?php esc_html_e( 'Importer ce livre', 'wc-hub-dilicom' ); ?>
                    </button>
                    <a id="whd-edit-product" href="#" target="_blank" class="button" style="display:none;">
                        <span class="dashicons dashicons-edit"></span>
                        <?php esc_html_e( 'Voir le produit WC', 'wc-hub-dilicom' ); ?>
                    </a>
                    <span id="whd-import-single-result" class="whd-inline-result" aria-live="polite"></span>
                </div>
            </div>
        </div>
    </div>
</div>