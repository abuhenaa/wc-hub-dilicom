<?php
namespace WC_Hub_Dilicom\WooCommerce;
use WC_Hub_Dilicom\Api\Hub_Api_Orders;
use WC_Hub_Dilicom\Api\Hub_Api_Client;
use WC_Hub_Dilicom\Hub_Logger;
if ( ! defined( 'ABSPATH' ) ) exit;

class WC_Order_Handler {
    private Hub_Api_Orders $orders_api;
    public function __construct( ?Hub_Api_Orders $a=null ) { $this->orders_api = $a ?? new Hub_Api_Orders(); }

    public function init_hooks(): void {
        add_action('woocommerce_payment_complete',     [$this, 'on_payment_complete']);
        add_action('woocommerce_order_status_cancelled',  [$this, 'on_order_cancelled']);
    }

    public function on_payment_complete( int $order_id ): void {
        $this->process_order( $order_id );
    }

    private function process_order( int $id ): void {
        // Vérification immédiate de la présence d'un ID HUB (même sans _hub_order_sent)
        if ( get_post_meta( $id, '_hub_order_id', true ) ) {
            Hub_Logger::info( 'sendOrder', sprintf( 'Commande WC #%d déjà envoyée (ID HUB présent).', $id ) );
            return;
        }

        $o = wc_get_order( $id );
        if ( ! $o ) return;
        if ( ! $this->order_has_hub_items( $o ) ) return;

        try {
            $res    = $this->orders_api->send_order( $o );
            $hub_id = $this->orders_api->build_hub_order_id( $id );

            // Sauvegarde immédiate de l'ID HUB (avant tout traitement)
            update_post_meta( $id, '_hub_order_id', sanitize_text_field( $hub_id ) );

            if ( Hub_Api_Client::is_success( $res ) ) {
                $this->save_order_lines_from_response( $id, $res['orderLines'] ?? [] );
                update_post_meta( $id, '_hub_order_sent', '1' );
                $o->add_order_note( sprintf( __( 'Commande transmise au HUB Dilicom (ID: %s).', 'wc-hub-dilicom' ), $hub_id ) );
                $o->save();
                do_action( 'whd_order_sent_to_hub', $id, $res );
                Hub_Logger::info( 'sendOrder', sprintf( 'Commande WC #%d transmise (Hub: %s).', $id, $hub_id ) );
            } else {
                $msg = $res['returnMessage'][0] ?? 'Erreur inconnue';
                $o->add_order_note( sprintf( __( 'Échec HUB Dilicom : %s', 'wc-hub-dilicom' ), $msg ) );
                Hub_Logger::error( 'sendOrder', sprintf( 'Commande WC #%d échec : %s', $id, $msg ), $res['returnStatus'] ?? 'ERROR' );
                $o->save();
            }
        } catch ( \Throwable $e ) {
            Hub_Logger::error( 'sendOrder', sprintf( 'Exception pour commande #%d : %s', $id, $e->getMessage() ) );
        }
    }

    public function on_order_cancelled( int $id ): void {
        $o = wc_get_order( $id );
        if ( ! $o ) return;
        $hub_id = $o->get_meta( '_hub_order_id' );
        if ( empty( $hub_id ) ) return;
        $line_ids = $this->get_hub_order_line_ids( $id );
        $res = $this->orders_api->cancel_order( $hub_id, $line_ids );
        $ok  = 'OK' === ( $res['returnStatus'] ?? '' );
        $o->add_order_note( $ok ? __( 'Annulée auprès du HUB.', 'wc-hub-dilicom' ) : sprintf( __( 'Annulation HUB échouée : %s', 'wc-hub-dilicom' ), $res['returnMessage'][0] ?? '' ) );
        $o->save();
        $ok ? Hub_Logger::info( 'cancelOrder', 'Commande annulée HUB ' . $hub_id ) : Hub_Logger::error( 'cancelOrder', 'Annulation échouée ' . $hub_id, $res['returnStatus'] ?? '' );
    }

    private function save_order_lines_from_response( int $oid, array $lines ): void {
        global $wpdb;
        if ( empty( $lines ) ) return;
        $hub_id = get_post_meta( $oid, '_hub_order_id', true );
        $t      = $wpdb->prefix . 'hub_order_lines';
        foreach ( $lines as $l ) {
            $wpdb->insert( $t, [
                'wc_order_id'     => $oid,
                'hub_order_id'    => $hub_id,
                'ean13'           => $l['ean13'] ?? '',
                'gln_distributor' => $l['glnDistributor'] ?? '',
                'order_line_id'   => $l['orderLineId'] ?? '',
                'links_json'      => ! empty( $l['links'] ) ? wp_json_encode( $l['links'] ) : null,
            ], [ '%d', '%s', '%s', '%s', '%s', '%s' ] );
        }
    }

    private function order_has_hub_items( \WC_Order $o ): bool {
        foreach ( $o->get_items() as $item ) {
            if ( WC_Product_Book::is_hub_book( (int) $item->get_product_id() ) ) {
                return true;
            }
        }
        return false;
    }

    private function get_hub_order_line_ids( int $oid ): array {
        global $wpdb;
        return $wpdb->get_col( $wpdb->prepare(
            "SELECT order_line_id FROM {$wpdb->prefix}hub_order_lines WHERE wc_order_id = %d",
            $oid
        ) );
    }
}