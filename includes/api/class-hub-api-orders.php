<?php
namespace WC_Hub_Dilicom\Api;
use WC_Hub_Dilicom\Hub_Logger;
if ( ! defined( 'ABSPATH' ) ) exit;

class Hub_Api_Orders {
    private Hub_Api_Client $client;

    // Distributeurs qui exigent l'envoi du prix HT
    private const GLN_REQUIRES_HT = [ '3012410002007', '3019000000306' ]; // EDEN, De Marque

    public function __construct( ?Hub_Api_Client $c = null ) {
        $this->client = $c ?? new Hub_Api_Client();
    }

    /**
     * Envoi intelligent : n'effectue un fallback que si nécessaire et si la commande n'a pas déjà été créée.
     */
    public function send_order( \WC_Order $o ): array {
        // Première tentative
        $response = $this->do_send_order( $o );

        // Si la commande a déjà été créée (ordre accepté), on ne retente rien
        if ( ! empty( $response['orderId'] ) ) {
            return $response;
        }

        // Fallback uniquement pour les distributeurs qui exigent le HT, et sans prix HT cette fois
        if ( $this->is_price_error( $response ) && $this->order_contains_gln_requiring_ht( $o ) ) {
            Hub_Logger::warning( 'sendOrder', 'Fallback sans unitPriceExcludingTax.' );
            $response = $this->do_send_order( $o, false );
        }

        return $response;
    }

    /**
     * Vérifie si la commande contient au moins un GLN nécessitant le prix HT.
     */
    private function order_contains_gln_requiring_ht( \WC_Order $o ): bool {
        foreach ( $o->get_items() as $item ) {
            $gln = get_post_meta( $item->get_product_id(), '_hub_gln_distributor', true );
            if ( in_array( $gln, self::GLN_REQUIRES_HT, true ) ) {
                return true;
            }
        }
        return false;
    }

    private function do_send_order( \WC_Order $o, bool $include_ht = true ): array {
        $params = array_merge(
            $this->build_order_header( $o ),
            $this->build_final_book_owner( $o ),
            $this->build_order_lines( $o, $include_ht )
        );
        $response = $this->client->request(
            $this->client->get_json_url( 'sendOrder' ),
            $params
        );
        Hub_Logger::info( 'sendOrder', 'Réponse sendOrder : ' . json_encode( $response ) );
        return $response;
    }

    private function is_price_error( array $response ): bool {
        foreach ( $response['orderLines'] ?? [] as $line ) {
            $msg = $line['returnMessage'][0] ?? '';
            if ( str_contains( $msg, 'SellInvalidPrice' ) ) {
                return true;
            }
        }
        return false;
    }

    public function cancel_order( string $hub_order_id, array $line_ids = [] ): array {
        $params = [ 'orderId' => $hub_order_id ];
        foreach ( array_values( $line_ids ) as $i => $lid ) {
            $params[ "cancelOrderLines[{$i}].orderLineId" ] = (string) $lid;
        }
        return $this->client->request(
            $this->client->get_json_url( 'cancelOrder' ),
            $params
        );
    }

    public function get_order_details( string $hub_order_id ): array {
        return $this->client->request_get(
            $this->client->get_json_url( 'getOrderDetails' ),
            [ 'orderId' => $hub_order_id ]
        );
    }

    private function build_order_header( \WC_Order $o ): array {
        $customer_id = $o->get_customer_id() ?: 'g' . $o->get_id();
        $customer_id = preg_replace('/[^a-zA-Z0-9]/', '', $customer_id);
        return [
            'orderId'    => $this->build_hub_order_id( $o->get_id() ),
            'customerId' => $customer_id,
        ];
    }

    public function build_final_book_owner( \WC_Order $o ): array {
        $civility = $this->map_civility( (string) $o->get_meta( '_billing_title' ) );
        $identifier = $o->get_customer_id() ?: 'g' . $o->get_id();
        $identifier = preg_replace('/[^a-zA-Z0-9]/', '', 'wc' . $identifier);
        return [
            'finalBookOwner.identifier'  => $identifier,
            'finalBookOwner.civility'    => $civility,
            'finalBookOwner.firstName'   => (string) $o->get_billing_first_name(),
            'finalBookOwner.lastName'    => (string) $o->get_billing_last_name(),
            'finalBookOwner.email'       => (string) $o->get_billing_email(),
            'finalBookOwner.country'     => (string) ( $o->get_billing_country() ?: get_option( 'whd_country', 'FR' ) ),
            'finalBookOwner.postalCode'  => (string) $o->get_billing_postcode(),
            'finalBookOwner.city'        => (string) $o->get_billing_city(),
        ];
    }

    public function build_order_lines( \WC_Order $o, bool $include_ht = true ): array {
        $params   = [];
        $currency = (string) get_option( 'whd_currency', 'EUR' );
        $i        = 0;

        foreach ( $o->get_items() as $item ) {
            $pid = (int) $item->get_product_id();
            $ean = (string) get_post_meta( $pid, '_hub_ean13', true );
            $gln = (string) get_post_meta( $pid, '_hub_gln_distributor', true );
            if ( empty( $ean ) || empty( $gln ) ) continue;

            // Récupérer le prix catalogue (celui validé par checkAvailability)
            $unit_price = (int) get_post_meta( $pid, '_hub_unit_price', true );
            if ( $unit_price <= 0 ) {
                $unit_price = $this->format_price_cents( (float) $item->get_total() / max( 1, $item->get_quantity() ) );
            }
            if ( $unit_price <= 0 ) {
                Hub_Logger::warning( 'sendOrder', "Ligne EAN {$ean} prix zéro, ignorée." );
                continue;
            }

            $k = "orderRequestLines[{$i}]";
            $params[ "{$k}.ean13" ]          = $ean;
            $params[ "{$k}.glnDistributor" ] = $gln;
            $params[ "{$k}.quantity" ]        = 1;
            $params[ "{$k}.unitPrice" ]       = $unit_price;

            // Ajouter le prix HT uniquement pour les distributeurs qui l'exigent (et si le paramètre est actif)
            if ( $include_ht && in_array( $gln, self::GLN_REQUIRES_HT ) ) {
                $params[ "{$k}.unitPriceExcludingTax" ] = $unit_price;
            }

            $params[ "{$k}.currency" ]      = $currency;
            $params[ "{$k}.lineReference" ] = 'L' . $o->get_id() . 'I' . $i;
            $i++;
        }
        return $params;
    }

    public function build_hub_order_id( int $wc_order_id ): string {
        return 'WC' . str_pad( (string) $wc_order_id, 14, '0', STR_PAD_LEFT );
    }

    public function format_price_cents( float $price ): int {
        return (int) round( $price * 100 );
    }

    private function map_civility( string $raw ): string {
        $raw = strtolower( trim( $raw ) );
        if ( in_array( $raw, [ 'mme', 'madame', 'ms', 'mrs', 'mrs.', 'ms.' ], true ) ) return 'MME';
        if ( in_array( $raw, [ 'mle', 'mademoiselle', 'miss' ], true ) )               return 'MLE';
        return 'M';
    }
}