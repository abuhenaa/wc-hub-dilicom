<?php
namespace WC_Hub_Dilicom\Admin;
use WC_Hub_Dilicom\Api\Hub_Api_Orders;
if ( ! defined( 'ABSPATH' ) ) exit;

class Admin_Orders {

    public function render_orders_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( __( 'Accès non autorisé.', 'wc-hub-dilicom' ) );
        }
        include WHD_PATH . 'admin/views/orders.php';
    }

    public function get_hub_orders( int $per_page = 20, int $paged = 1 ): array {
        global $wpdb;

        $offset = ( $paged - 1 ) * $per_page;

        $order_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_hub_order_id' ORDER BY post_id DESC LIMIT %d OFFSET %d",
            $per_page, $offset
        ) );

        $orders = [];
        foreach ( $order_ids as $id ) {
            $order = wc_get_order( $id );
            if ( $order && ! in_array( $order->get_status(), [ 'trash', 'auto-draft' ], true ) ) {
                $orders[] = $order;
            }
        }

        $total = (int) $wpdb->get_var( "SELECT COUNT(post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_hub_order_id'" );

        return [
            'orders'        => $orders,
            'total'         => $total,
            'max_num_pages' => (int) ceil( $total / $per_page ),
        ];
    }

    public function get_hub_order_details( string $hub_id ): array {
        return ( new Hub_Api_Orders() )->get_order_details( $hub_id );
    }
}