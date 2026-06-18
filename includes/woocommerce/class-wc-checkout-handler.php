<?php
namespace WC_Hub_Dilicom\WooCommerce;
use WC_Hub_Dilicom\Api\Hub_Api_Availability;
use WC_Hub_Dilicom\Api\Hub_Api_Client;
use WC_Hub_Dilicom\Hub_Logger;
if ( ! defined( 'ABSPATH' ) ) exit;

class WC_Checkout_Handler {
    const PRICE_TOLERANCE_CENTS = 1; // tolérance d'1 centime
    private Hub_Api_Availability $availability;

    public function __construct( ?Hub_Api_Availability $a = null ) {
        $this->availability = $a ?? new Hub_Api_Availability();
    }

    public function init_hooks(): void {
        add_action( 'woocommerce_check_cart_items', [ $this, 'verify_before_payment' ] );
    }

    /**
     * Vérifie disponibilité et prix avant le passage en caisse.
     */
    public function verify_before_payment(): void {
        $cart = WC()->cart;
        if ( ! $cart || $cart->is_empty() ) {
            return;
        }
        $items = $cart->get_cart();
        $lines = $this->availability->build_availability_lines( $items );
        if ( empty( $lines ) ) {
            return;
        }

        // 1. Vérification de disponibilité via l'API
        $res = $this->availability->check( $lines );
        if ( ! Hub_Api_Client::is_success( $res ) ) {
            wc_add_notice(
                __( 'Impossible de vérifier la disponibilité des livres. Veuillez réessayer.', 'wc-hub-dilicom' ),
                'error'
            );
            return;
        }

        // Articles indisponibles
        $unavailable = $this->availability->get_unavailable_items( $res, $items );
        foreach ( $unavailable as $item ) {
            wc_add_notice(
                sprintf(
                    __( '"%s" n\'est plus disponible. Veuillez le retirer de votre panier.', 'wc-hub-dilicom' ),
                    esc_html( $item['product_name'] )
                ),
                'error'
            );
            Hub_Logger::warning( 'checkout/verify', 'EAN13 ' . $item['ean13'] . ' indisponible.' );
        }

        // 2. Vérification des prix (comparaison base HUB vs. prix affiché)
        $this->verify_prices( $items );
    }

    /**
     * Bloque si le prix affiché en boutique diffère du prix HUB de référence.
     */
    private function verify_prices( array $cart_items ): void {
        foreach ( $cart_items as $item ) {
            $pid = (int) ( $item['product_id'] ?? 0 );
            if ( ! $pid || ! \WC_Hub_Dilicom\WooCommerce\WC_Product_Book::is_hub_book( $pid ) ) {
                continue;
            }

            $hub_price_cents = (int) get_post_meta( $pid, '_hub_unit_price', true );
            if ( $hub_price_cents <= 0 ) {
                continue; // pas de prix HUB de référence
            }

            $product = wc_get_product( $pid );
            if ( ! $product ) {
                continue;
            }

            $current_price_cents = (int) round( (float) $product->get_price() * 100 );

            if ( abs( $current_price_cents - $hub_price_cents ) > self::PRICE_TOLERANCE_CENTS ) {
                wc_add_notice(
                    sprintf(
                        __( 'Le prix de "%1$s" a été mis à jour. Il est maintenant de %2$s. Veuillez réessayer votre commande.', 'wc-hub-dilicom' ),
                        esc_html( $product->get_name() ),
                        number_format( $hub_price_cents / 100, 2, ',', ' ' ) . ' €'
                    ),
                    'error'
                );
                Hub_Logger::warning(
                    'checkout/verify',
                    sprintf(
                        'EAN %s : prix site = %d cts, prix HUB = %d cts.',
                        get_post_meta( $pid, '_hub_ean13', true ),
                        $current_price_cents,
                        $hub_price_cents
                    )
                );
            }
        }
    }

    /**
     * Compare un prix WC (float) avec un prix HUB en centimes.
     */
    public function compare_prices( float $wc_price, int $hub_cents ): bool {
        return abs( (int) round( $wc_price * 100 ) - $hub_cents ) <= self::PRICE_TOLERANCE_CENTS;
    }
}