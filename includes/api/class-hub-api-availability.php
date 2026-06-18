<?php
namespace WC_Hub_Dilicom\Api;
use WC_Hub_Dilicom\Hub_Logger;
if ( ! defined( 'ABSPATH' ) ) exit;

class Hub_Api_Availability {
    private Hub_Api_Client $client;

    public function __construct( ?Hub_Api_Client $c = null ) {
        $this->client = $c ?? new Hub_Api_Client();
    }

    /**
     * Vérifie la disponibilité d'une liste de livres.
     * Format correct : checkAvailabilityLines[0].ean13, etc.
     */
    public function check( array $lines ): array {
        $params   = [];
        $currency = (string) get_option( 'whd_currency', 'EUR' );
        $country  = (string) get_option( 'whd_country',  'FR' );

        if ( ! empty( $country ) ) {
            $params['country'] = $country;
        }

        Hub_Logger::info( 'availability/check', 'Début vérification pour ' . count( $lines ) . ' ligne(s).' );

        foreach ( array_values( $lines ) as $i => $line ) {
            // Correction : ajout des crochets pour respecter la norme Dilicom
            $k = "checkAvailabilityLines[{$i}]";

            $ean = (string) $line['ean13'];
            $gln = (string) $line['glnDistributor'];

            $params[ "{$k}.ean13" ]          = $ean;
            $params[ "{$k}.glnDistributor" ] = $gln;

            if ( isset( $line['unitPrice'] ) && $line['unitPrice'] > 0 ) {
                $params[ "{$k}.unitPrice" ] = (int) $line['unitPrice'];
                $params[ "{$k}.currency"  ] = $line['currency'] ?? $currency;
            }

            Hub_Logger::info( 'availability/check', "Ligne $i : EAN=$ean, GLN=$gln, prix=" . ( $line['unitPrice'] ?? 'non défini' ) );
        }

        Hub_Logger::info( 'availability/check', 'Envoi requête avec paramètres : ' . json_encode( $params ) );

        $response = $this->client->request(
            $this->client->get_json_url( 'checkAvailability' ),
            $params
        );

        // Log complet de la réponse
        $status = $response['returnStatus'] ?? 'INCONNU';
        Hub_Logger::info( 'availability/response', 'Statut HTTP : ' . ( $response['httpCode'] ?? '?' ) . ', returnStatus : ' . $status );
        Hub_Logger::info( 'availability/response', 'Réponse brute : ' . json_encode( $response ) );

        if ( isset( $response['checkAvailabilityResponseLines'] ) ) {
            foreach ( $response['checkAvailabilityResponseLines'] as $r ) {
                $ean = $r['ean13'] ?? '?';
                $available = $r['checkAvailabilityReturnValue'] ?? '?';
                Hub_Logger::info( 'availability/response', "EAN $ean => $available" );
            }
        } else {
            Hub_Logger::warning( 'availability/response', 'Aucune ligne dans la réponse checkAvailability' );
        }

        return $response;
    }

    /**
     * Vérifie la disponibilité d'un seul EAN.
     */
    public function is_available( string $ean13, string $gln, ?int $unit_price = null ): bool {
        Hub_Logger::info( 'availability/is_available', "Vérification de l'EAN $ean13 chez $gln..." );
        $res = $this->check( [ $this->build_line( $ean13, $gln, $unit_price ) ] );
        if ( ! Hub_Api_Client::is_success( $res ) ) {
            Hub_Logger::warning( 'availability/is_available', "Échec de l'appel API pour $ean13" );
            return false;
        }
        foreach ( $res['checkAvailabilityResponseLines'] ?? [] as $r ) {
            if ( ( $r['ean13'] ?? '' ) === $ean13 ) {
                $available = 'AVAILABLE' === ( $r['checkAvailabilityReturnValue'] ?? '' );
                Hub_Logger::info( 'availability/is_available', "EAN $ean13 -> " . ( $available ? 'Disponible' : 'Indisponible' ) );
                return $available;
            }
        }
        Hub_Logger::warning( 'availability/is_available', "EAN $ean13 introuvable dans la réponse" );
        return false; // par sécurité, si non trouvé, indisponible
    }

    /**
     * Construit les lignes depuis les items du panier WooCommerce.
     */
    public function build_availability_lines( array $cart_items ): array {
        $lines    = [];
        $currency = (string) get_option( 'whd_currency', 'EUR' );

        foreach ( $cart_items as $item ) {
            $pid = (int) ( $item['product_id'] ?? 0 );
            if ( ! $pid ) continue;
            $ean13 = (string) get_post_meta( $pid, '_hub_ean13', true );
            $gln   = (string) get_post_meta( $pid, '_hub_gln_distributor', true );
            if ( empty( $ean13 ) || empty( $gln ) ) continue;

            $price = (int) get_post_meta( $pid, '_hub_unit_price', true );
            $line  = [ 'ean13' => $ean13, 'glnDistributor' => $gln ];
            if ( $price > 0 ) { $line['unitPrice'] = $price; $line['currency'] = $currency; }
            $lines[] = $line;
        }
        return $lines;
    }

    /**
     * Retourne les items du panier qui sont indisponibles.
     */
    public function get_unavailable_items( array $response, array $cart_items ): array {
        $unavailable = [];
        $ean_map = [];
        foreach ( $cart_items as $item ) {
            $pid = (int) ( $item['product_id'] ?? 0 );
            if ( $pid ) {
                $ean = get_post_meta( $pid, '_hub_ean13', true );
                if ( $ean ) {
                    $ean_map[ $ean ] = $pid;
                }
            }
        }
        foreach ( $response['checkAvailabilityResponseLines'] ?? [] as $line ) {
            $ean = $line['ean13'] ?? '';
            $val = $line['checkAvailabilityReturnValue'] ?? '';
            if ( 'UNAVAILABLE' === $val && isset( $ean_map[ $ean ] ) ) {
                $unavailable[] = [
                    'ean13'        => $ean,
                    'product_id'   => $ean_map[ $ean ],
                    'product_name' => get_the_title( $ean_map[ $ean ] ),
                ];
            }
        }
        return $unavailable;
    }

    /**
     * Appelé par le hook woocommerce_check_cart_items (dans WC_Checkout_Handler).
     */
    public function block_unavailable_items( \WC_Cart $cart ): void {
        $items = $cart->get_cart();
        $lines = $this->build_availability_lines( $items );
        if ( empty( $lines ) ) return;

        $response = $this->check( $lines );
        if ( ! Hub_Api_Client::is_success( $response ) ) {
            wc_add_notice( __( 'Impossible de vérifier la disponibilité des livres. Veuillez réessayer.', 'wc-hub-dilicom' ), 'error' );
            return;
        }

        foreach ( $response['checkAvailabilityResponseLines'] ?? [] as $r ) {
            $ean   = $r['ean13'] ?? '';
            $val   = $r['checkAvailabilityReturnValue'] ?? '';
            if ( 'UNAVAILABLE' === $val ) {
                $product_name = $ean;
                foreach ( $items as $item ) {
                    $pid = $item['product_id'] ?? 0;
                    if ( $ean === get_post_meta( $pid, '_hub_ean13', true ) ) {
                        $product_name = get_the_title( $pid );
                        break;
                    }
                }
                wc_add_notice( sprintf( __( '"%s" n\'est pas disponible. Veuillez réessayer ultérieurement.', 'wc-hub-dilicom' ), esc_html( $product_name ) ), 'error' );
                Hub_Logger::warning( 'cart/validation', "EAN13 {$ean} UNAVAILABLE." );
            }
        }
    }

    private function build_line( string $ean13, string $gln, ?int $unit_price = null ): array {
        $line = [ 'ean13' => $ean13, 'glnDistributor' => $gln ];
        if ( null !== $unit_price ) $line['unitPrice'] = $unit_price;
        return $line;
    }
}