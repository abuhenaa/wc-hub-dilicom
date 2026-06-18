<?php
namespace WC_Hub_Dilicom\WooCommerce;
use WC_Hub_Dilicom\Api\Hub_Api_Availability;
use WC_Hub_Dilicom\Hub_Logger;
if ( ! defined( 'ABSPATH' ) ) exit;

class WC_Cart_Handler {
    private Hub_Api_Availability $availability;
    public function __construct( ?Hub_Api_Availability $a=null ) { $this->availability = $a ?? new Hub_Api_Availability(); }

    public function init_hooks(): void {
        add_filter('woocommerce_add_to_cart_validation', [$this,'block_unavailable_item'], 10, 2);
    }

    public function block_unavailable_item( bool $passed, int $pid ): bool {
    if ( ! $passed || ! WC_Product_Book::is_hub_book( $pid ) ) {
        return $passed;
    }

    $ean   = (string) get_post_meta( $pid, '_hub_ean13', true );
    $gln   = (string) get_post_meta( $pid, '_hub_gln_distributor', true );
    $hub_price_cents = (int) get_post_meta( $pid, '_hub_unit_price', true );

    if ( empty( $ean ) || empty( $gln ) ) {
        return true; // pas de données HUB, on laisse passer
    }

    // 1. Vérification disponibilité (appel API)
    if ( ! $this->availability->is_available( $ean, $gln, $hub_price_cents ?: null ) ) {
        Hub_Logger::warning( 'cart/validation', sprintf( 'EAN13 %s UNAVAILABLE.', $ean ) );
        wc_add_notice( sprintf( __( '"%s" n\'est pas disponible. Veuillez réessayer ultérieurement.', 'wc-hub-dilicom' ), esc_html( get_the_title( $pid ) ) ), 'error' );
        return false;
    }

    // 2. Vérification prix (le prix du site doit être égal au prix HUB)
    $product = wc_get_product( $pid );
    if ( $product && $hub_price_cents > 0 ) {
        $current_price_cents = (int) round( (float) $product->get_price() * 100 );
        if ( abs( $current_price_cents - $hub_price_cents ) > 1 ) { // tolérance 1 centime
            wc_add_notice(
                sprintf(
                    __( 'Le prix de "%1$s" a changé. Il est maintenant de %2$s. Veuillez réessayer.', 'wc-hub-dilicom' ),
                    esc_html( get_the_title( $pid ) ),
                    number_format( $hub_price_cents / 100, 2, ',', ' ' ) . ' €'
                ),
                'error'
            );
            Hub_Logger::warning( 'cart/validation', sprintf( 'EAN13 %s prix modifié : site=%d centimes, hub=%d centimes.', $ean, $current_price_cents, $hub_price_cents ) );
            return false;
        }
    }

    return true;
}
}
