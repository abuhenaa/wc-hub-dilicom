<?php
namespace WC_Hub_Dilicom;
if ( ! defined( 'ABSPATH' ) ) exit;
use WC_Hub_Dilicom\Admin\Admin_Settings;

$creds = Admin_Settings::are_credentials_set();
$env   = get_option( 'whd_environment', 'production' );
$last  = get_option( 'whd_last_connection', '' );

global $wpdb;
$total_products = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_hub_ean13'" );
$total_digital  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_hub_book_type' AND meta_value='digital'" );
$total_physical = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_hub_book_type' AND meta_value='physical'" );
$total_errors   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}hub_logs WHERE level='error' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)" );
$pending_queue  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}hub_import_queue WHERE status='pending'" );
?>
<div class="wrap whd-wrap">
    <h1 class="whd-page-title">
        <span class="dashicons dashicons-book-alt"></span>
        <?php esc_html_e( 'HUB Dilicom — Tableau de bord', 'wc-hub-dilicom' ); ?>
    </h1>

    <?php if ( ! $creds ) : ?>
    <div class="notice notice-warning whd-notice">
        <p>
            <strong><?php esc_html_e( 'Configuration requise :', 'wc-hub-dilicom' ); ?></strong>
            <?php esc_html_e( 'Vos identifiants API ne sont pas configurés.', 'wc-hub-dilicom' ); ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-hub-dilicom-settings' ) ); ?>">
                <?php esc_html_e( 'Configurer maintenant →', 'wc-hub-dilicom' ); ?>
            </a>
        </p>
    </div>
    <?php endif; ?>

    <div class="whd-kpi-grid">
        <div class="whd-kpi-card">
            <div class="whd-kpi-icon dashicons dashicons-book-alt"></div>
            <div class="whd-kpi-value"><?php echo esc_html( number_format( $total_products ) ); ?></div>
            <div class="whd-kpi-label"><?php esc_html_e( 'Livres importés', 'wc-hub-dilicom' ); ?></div>
        </div>
        <div class="whd-kpi-card">
            <div class="whd-kpi-icon dashicons dashicons-tablet"></div>
            <div class="whd-kpi-value"><?php echo esc_html( number_format( $total_digital ) ); ?></div>
            <div class="whd-kpi-label"><?php esc_html_e( 'Livres numériques', 'wc-hub-dilicom' ); ?></div>
        </div>
        <div class="whd-kpi-card">
            <div class="whd-kpi-icon dashicons dashicons-archive"></div>
            <div class="whd-kpi-value"><?php echo esc_html( number_format( $total_physical ) ); ?></div>
            <div class="whd-kpi-label"><?php esc_html_e( 'Livres physiques', 'wc-hub-dilicom' ); ?></div>
        </div>
        <div class="whd-kpi-card <?php echo $total_errors > 0 ? 'whd-kpi-card--error' : ''; ?>">
            <div class="whd-kpi-icon dashicons dashicons-warning"></div>
            <div class="whd-kpi-value"><?php echo esc_html( $total_errors ); ?></div>
            <div class="whd-kpi-label"><?php esc_html_e( 'Erreurs (24h)', 'wc-hub-dilicom' ); ?></div>
        </div>
    </div>

    <div class="whd-dashboard-grid">
        <div class="whd-card">
            <h2><?php esc_html_e( 'Connexion API', 'wc-hub-dilicom' ); ?></h2>
            <table class="whd-info-table">
                <tr><th><?php esc_html_e( 'Environnement', 'wc-hub-dilicom' ); ?></th>
                    <td><span class="whd-badge whd-badge--<?php echo 'production'===$env?'success':'warning'; ?>"><?php echo esc_html( ucfirst( $env ) ); ?></span></td></tr>
                <tr><th><?php esc_html_e( 'Identifiants', 'wc-hub-dilicom' ); ?></th>
                    <td><span class="whd-badge whd-badge--<?php echo $creds?'success':'error'; ?>"><?php echo $creds ? esc_html__( 'Configurés', 'wc-hub-dilicom' ) : esc_html__( 'Manquants', 'wc-hub-dilicom' ); ?></span></td></tr>
                <tr><th><?php esc_html_e( 'Dernière synchro', 'wc-hub-dilicom' ); ?></th>
                    <td><?php echo $last ? esc_html( date_i18n( 'd/m/Y H:i', strtotime( $last ) ) ) : '<em>—</em>'; ?></td></tr>
            </table>
            <div class="whd-card-actions">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-hub-dilicom-settings' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Paramètres', 'wc-hub-dilicom' ); ?></a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-hub-dilicom-logs' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Voir les logs', 'wc-hub-dilicom' ); ?></a>
            </div>
        </div>

        <!-- Parsing complet (arrière‑plan) -->
        <div class="whd-card">
            <h2><?php esc_html_e( 'Parsing complet (arrière‑plan)', 'wc-hub-dilicom' ); ?></h2>
            <p><?php esc_html_e( 'Parse le fichier onix_full.xml par petits lots.', 'wc-hub-dilicom' ); ?></p>
            <button type="button" id="whd-start-background-parse" class="button button-primary">
                <span class="dashicons dashicons-clock"></span>
                <?php esc_html_e( 'Lancer le parsing en arrière‑plan', 'wc-hub-dilicom' ); ?>
            </button>
            <span id="whd-background-result" style="display:inline-block;margin-top:10px;font-weight:500;"></span>
        </div>

        <!-- Régénération du cache allégé PAR LOTS -->
        <div class="whd-card">
            <h2><?php esc_html_e( 'Régénérer le cache allégé (par lots)', 'wc-hub-dilicom' ); ?></h2>
            <p><?php esc_html_e( 'Convertit le cache complet en version légère, par petits lots, sans limite de temps.', 'wc-hub-dilicom' ); ?></p>
            <button type="button" id="whd-start-light-cache-bg" class="button button-primary">
                <span class="dashicons dashicons-update"></span>
                <?php esc_html_e( 'Lancer la régénération', 'wc-hub-dilicom' ); ?>
            </button>
            <span id="whd-light-cache-bg-result" style="display:inline-block;margin-top:10px;font-weight:500;"></span>
        </div>
    </div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    // Parsing arrière‑plan
    $('#whd-start-background-parse').on('click', function() {
        var $btn = $(this).prop('disabled', true).text('Préparation…');
        var $msg = $('#whd-background-result').text('');

        $.post(whdAdmin.ajaxurl, {
            action: 'whd_parse_full_catalog_background',
            nonce:  whdAdmin.nonce
        })
        .done(function(r) {
            if (r.success) {
                $msg.text(r.data.message).css('color', 'green');
                if (r.data.done) {
                    $btn.hide();
                } else {
                    // Relancer automatiquement dans 10 secondes
                    setTimeout(function() {
                        $('#whd-start-background-parse').trigger('click');
                    }, 10000);
                }
            } else {
                $msg.text(r.data.message || 'Erreur.').css('color', 'red');
                $btn.prop('disabled', false).text('Lancer le parsing en arrière‑plan');
            }
        })
        .fail(function() {
            $msg.text('Erreur réseau.').css('color', 'red');
            $btn.prop('disabled', false).text('Lancer le parsing en arrière‑plan');
        });
    });

    // Régénération du cache allégé par lots
    $('#whd-start-light-cache-bg').on('click', function() {
        var $btn = $(this).prop('disabled', true).text('Préparation…');
        var $msg = $('#whd-light-cache-bg-result').text('');

        $.post(whdAdmin.ajaxurl, {
            action: 'whd_regenerate_light_cache_bg',
            nonce:  whdAdmin.nonce
        })
        .done(function(r) {
            if (r.success) {
                $msg.text(r.data.message).css('color', 'green');
                if (r.data.done) {
                    $btn.hide(); // terminé
                } else {
                    // Relancer automatiquement dans 10 secondes
                    setTimeout(function() {
                        $('#whd-start-light-cache-bg').trigger('click');
                    }, 10000);
                }
            } else {
                $msg.text(r.data.message || 'Erreur.').css('color', 'red');
                $btn.prop('disabled', false).text('Lancer la régénération');
            }
        })
        .fail(function() {
            $msg.text('Erreur réseau.').css('color', 'red');
            $btn.prop('disabled', false).text('Lancer la régénération');
        });
    });
});
</script>