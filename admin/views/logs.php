<?php
namespace WC_Hub_Dilicom;
if ( ! defined( 'ABSPATH' ) ) exit;
use WC_Hub_Dilicom\Hub_Logger;

$paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$per_page = 50;
$offset   = ( $paged - 1 ) * $per_page;

$filters = [
    'level'     => sanitize_text_field( $_GET['level']   ?? '' ),
    'endpoint'  => sanitize_text_field( $_GET['endpoint'] ?? '' ),
    'search'    => sanitize_text_field( $_GET['search']  ?? '' ),
    'date_from' => sanitize_text_field( $_GET['date_from'] ?? '' ),
    'date_to'   => sanitize_text_field( $_GET['date_to']   ?? '' ),
];

$result = Hub_Logger::get_logs( array_filter( $filters ), $per_page, $offset );
$logs   = $result['logs'];
$total  = $result['total'];
$pages  = (int) ceil( $total / $per_page );

$level_colors = [ 'info' => 'whd-badge--info', 'warning' => 'whd-badge--warning', 'error' => 'whd-badge--error' ];
?>
<div class="wrap whd-wrap">
    <h1 class="whd-page-title">
        <span class="dashicons dashicons-list-view"></span>
        <?php esc_html_e( 'Journal des logs — HUB Dilicom', 'wc-hub-dilicom' ); ?>
        <button type="button" id="whd-purge-logs" class="button button-secondary page-title-action">
            <span class="dashicons dashicons-trash"></span>
            <?php esc_html_e( 'Vider les logs', 'wc-hub-dilicom' ); ?>
        </button>
    </h1>

    <form method="get" class="whd-log-filters" id="whd-log-filter-form">
        <input type="hidden" name="page" value="wc-hub-dilicom-logs" />
        <div class="whd-filters-row">
            <select name="level">
                <option value=""><?php esc_html_e( 'Tous niveaux', 'wc-hub-dilicom' ); ?></option>
                <option value="info"    <?php selected( $filters['level'], 'info' ); ?>>INFO</option>
                <option value="warning" <?php selected( $filters['level'], 'warning' ); ?>>WARNING</option>
                <option value="error"   <?php selected( $filters['level'], 'error' ); ?>>ERROR</option>
            </select>
            <input type="text" name="endpoint" value="<?php echo esc_attr( $filters['endpoint'] ); ?>" placeholder="<?php esc_attr_e( 'Endpoint…', 'wc-hub-dilicom' ); ?>" />
            <input type="text" name="search" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Recherche message…', 'wc-hub-dilicom' ); ?>" />
            <input type="date" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>" />
            <span><?php esc_html_e( 'au', 'wc-hub-dilicom' ); ?></span>
            <input type="date" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>" />
            <button type="submit" class="button"><?php esc_html_e( 'Filtrer', 'wc-hub-dilicom' ); ?></button>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-hub-dilicom-logs' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Réinitialiser', 'wc-hub-dilicom' ); ?></a>
        </div>
    </form>

    <p class="whd-result-count">
        <?php printf( esc_html__( '%1$d entrée(s) — Page %2$d / %3$d', 'wc-hub-dilicom' ), $total, $paged, max(1,$pages) ); ?>
    </p>

    <?php if ( empty( $logs ) ) : ?>
    <div class="whd-empty-state">
        <span class="dashicons dashicons-list-view" style="font-size:48px;color:#ccc;"></span>
        <h3><?php esc_html_e( 'Aucun log trouvé.', 'wc-hub-dilicom' ); ?></h3>
    </div>
    <?php else : ?>
    <table class="wp-list-table widefat fixed striped whd-logs-table">
        <thead>
            <tr>
                <th style="width:40px;">ID</th>
                <th style="width:70px;"><?php esc_html_e( 'Niveau', 'wc-hub-dilicom' ); ?></th>
                <th style="width:140px;"><?php esc_html_e( 'Endpoint', 'wc-hub-dilicom' ); ?></th>
                <th><?php esc_html_e( 'Message', 'wc-hub-dilicom' ); ?></th>
                <th style="width:120px;"><?php esc_html_e( 'Code retour', 'wc-hub-dilicom' ); ?></th>
                <th style="width:130px;"><?php esc_html_e( 'Date (UTC)', 'wc-hub-dilicom' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $logs as $log ) :
            $ctx = ! empty( $log['context'] ) ? json_decode( $log['context'], true ) : null;
        ?>
            <tr class="whd-log-row whd-log-row--<?php echo esc_attr( $log['level'] ); ?>">
                <td><?php echo (int) $log['id']; ?></td>
                <td>
                    <span class="whd-badge <?php echo esc_attr( $level_colors[ $log['level'] ] ?? '' ); ?>">
                        <?php echo esc_html( strtoupper( $log['level'] ) ); ?>
                    </span>
                </td>
                <td><code><?php echo esc_html( $log['endpoint'] ); ?></code></td>
                <td>
                    <?php echo esc_html( $log['message'] ); ?>
                    <?php if ( $ctx ) : ?>
                    <details class="whd-log-context">
                        <summary><?php esc_html_e( 'Contexte', 'wc-hub-dilicom' ); ?></summary>
                        <pre><?php echo esc_html( wp_json_encode( $ctx, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></pre>
                    </details>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ( ! empty( $log['return_code'] ) ) : ?>
                    <code class="whd-return-code"><?php echo esc_html( $log['return_code'] ); ?></code>
                    <?php endif; ?>
                </td>
                <td><?php echo esc_html( $log['created_at'] ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ( $pages > 1 ) :
        echo paginate_links( [ 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $pages ] );
    endif; ?>
    <?php endif; ?>
</div>
