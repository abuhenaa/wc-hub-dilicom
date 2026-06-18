<?php
namespace WC_Hub_Dilicom;
if ( ! defined( 'ABSPATH' ) ) exit;
use WC_Hub_Dilicom\Admin\Admin_Orders;

$admin_orders = new Admin_Orders();
$paged        = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$result       = $admin_orders->get_hub_orders( 20, $paged );
$orders       = $result['orders'];
$total        = $result['total'];
$total_pages  = (int) ceil( $total / 20 );

// --- DIAGNOSTIC (à supprimer après vérification) ---
echo '<div style="background:#fef9e7;border:1px solid #f0d060;padding:10px;margin-bottom:20px;">';
echo '<h3>Diagnostic Commandes HUB</h3>';
echo '<p><strong>Total commandes HUB trouvées :</strong> ' . $total . '</p>';

// Vérifions la commande 325
$test_order = wc_get_order( 325 );
if ( $test_order ) {
    $hub_id = $test_order->get_meta('_hub_order_id');
    $hub_sent = $test_order->get_meta('_hub_order_sent');
    $hub_links = $test_order->get_meta('_hub_download_links');
    echo '<p><strong>Commande #325 :</strong> ';
    echo 'Statut : ' . $test_order->get_status() . ', ';
    echo 'Hub Order ID : ' . ($hub_id ?: 'AUCUN') . ', ';
    echo 'Envoyée HUB : ' . ($hub_sent ?: 'NON') . ', ';
    echo 'Liens DL : ' . ($hub_links ? (count(json_decode($hub_links, true) ?? []) . ' lien(s)') : 'AUCUN');
    echo '</p>';
} else {
    echo '<p>Commande #325 introuvable.</p>';
}

// Vérifions la requête directe en base
global $wpdb;
$direct_ids = $wpdb->get_col("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_hub_order_id' LIMIT 10");
echo '<p><strong>IDs des commandes avec _hub_order_id (max 10) :</strong> ' . implode(', ', $direct_ids) . '</p>';

$count_all = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_hub_order_id'");
echo '<p><strong>Nombre total de metas _hub_order_id :</strong> ' . $count_all . '</p>';

echo '</div>';
// --- FIN DIAGNOSTIC ---
?>
<div class="wrap whd-wrap">
    <h1 class="whd-page-title">
        <span class="dashicons dashicons-cart"></span>
        <?php esc_html_e( 'Commandes HUB Dilicom', 'wc-hub-dilicom' ); ?>
    </h1>

    <?php if ( empty( $orders ) ) : ?>
    <div class="whd-empty-state">
        <span class="dashicons dashicons-cart" style="font-size:48px;color:#ccc;"></span>
        <h3><?php esc_html_e( 'Aucune commande transmise au HUB.', 'wc-hub-dilicom' ); ?></h3>
        <p><?php esc_html_e( 'Les commandes validées contenant des livres HUB apparaîtront ici.', 'wc-hub-dilicom' ); ?></p>
    </div>
    <?php else : ?>

    <p class="whd-result-count"><?php printf( esc_html__( '%d commande(s) trouvée(s).', 'wc-hub-dilicom' ), $total ); ?></p>

    <table class="wp-list-table widefat fixed striped whd-orders-table">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Commande WC', 'wc-hub-dilicom' ); ?></th>
                <th><?php esc_html_e( 'ID HUB', 'wc-hub-dilicom' ); ?></th>
                <th><?php esc_html_e( 'Client', 'wc-hub-dilicom' ); ?></th>
                <th><?php esc_html_e( 'Montant', 'wc-hub-dilicom' ); ?></th>
                <th><?php esc_html_e( 'Statut WC', 'wc-hub-dilicom' ); ?></th>
                <th><?php esc_html_e( 'Date', 'wc-hub-dilicom' ); ?></th>
                <th><?php esc_html_e( 'Liens DL', 'wc-hub-dilicom' ); ?></th>
                <th><?php esc_html_e( 'Actions', 'wc-hub-dilicom' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $orders as $order ) :
            $hub_id = $order->get_meta( '_hub_order_id' );
            $sent   = $order->get_meta( '_hub_order_sent' );
            $links  = $order->get_meta( '_hub_download_links' );
            $n_links= $links ? count( json_decode( $links, true ) ?? [] ) : 0;
            $date   = $order->get_date_created()?->date_i18n( 'd/m/Y H:i' ) ?? '—';
        ?>
            <tr>
                <td>
                    <a href="<?php echo esc_url( get_edit_post_link( $order->get_id() ) ); ?>">
                        #<?php echo esc_html( $order->get_id() ); ?>
                    </a>
                </td>
                <td>
                    <?php if ( $hub_id ) : ?>
                        <code><?php echo esc_html( $hub_id ); ?></code>
                    <?php else : ?><em>—</em><?php endif; ?>
                </td>
                <td><?php echo esc_html( $order->get_formatted_billing_full_name() ); ?></td>
                <td><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></td>
                <td>
                    <span class="whd-status-badge whd-status-<?php echo esc_attr( $order->get_status() ); ?>">
                        <?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?>
                    </span>
                </td>
                <td><?php echo esc_html( $date ); ?></td>
                <td>
                    <?php if ( $n_links > 0 ) : ?>
                        <span class="whd-badge whd-badge--success"><?php printf( esc_html__( '%d lien(s)', 'wc-hub-dilicom' ), $n_links ); ?></span>
                    <?php else : ?><em>—</em><?php endif; ?>
                </td>
                <td>
                    <a href="<?php echo esc_url( get_edit_post_link( $order->get_id() ) ); ?>" class="button button-small"><?php esc_html_e( 'Voir', 'wc-hub-dilicom' ); ?></a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ( $total_pages > 1 ) :
        echo paginate_links( [ 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $total_pages ] );
    endif; ?>
    <?php endif; ?>
</div>