<?php
namespace WC_Hub_Dilicom\WooCommerce;
use WC_Hub_Dilicom\Api\Hub_Api_Orders;
use WC_Hub_Dilicom\Hub_Logger;
if ( ! defined( 'ABSPATH' ) ) exit;

class WC_Download_Handler {
    private Hub_Api_Orders $orders_api;
    public function __construct( ?Hub_Api_Orders $a=null ) { $this->orders_api = $a ?? new Hub_Api_Orders(); }

    public function init_hooks(): void { add_action('whd_order_sent_to_hub', [$this,'process_download_links'], 10, 2); }

    public function process_download_links( int $oid, array $hub_res ): void {
        // 1. Extraire les liens directement depuis la réponse de sendOrder
        $links = $this->extract_links_from_send_order_response( $hub_res );
        if ( empty( $links ) ) {
            // 2. Fallback : appeler getOrderDetails (corrigé en GET)
            $hub_id = (string) get_post_meta( $oid, '_hub_order_id', true );
            if ( $hub_id ) {
                $details = $this->orders_api->get_order_details( $hub_id );
                $links   = $this->extract_links_from_response( $details );
            }
        }
        if ( empty( $links ) ) {
            Hub_Logger::warning( 'download/process', "Commande #{$oid} : aucun lien trouvé." );
            return;
        }
        $this->save_links_to_order( $oid, $links );
        $this->send_download_email( $oid );
    }

    /**
     * Extrait les liens depuis la réponse de sendOrder.
     * La structure est : { "orderLines": [ { "links": [...] } ] }
     */
    private function extract_links_from_send_order_response( array $res ): array {
        $out = [];
        foreach ( $res['orderLines'] ?? [] as $line ) {
            $ean    = $line['ean13'] ?? '';
            $llinks = $line['links'] ?? $line['link'] ?? [];
            if ( is_string( $llinks ) ) {
                $llinks = [ [ 'url' => $llinks ] ];
            }
            foreach ( (array) $llinks as $l ) {
                $url = $l['url'] ?? $l;
                if ( ! empty( $url ) ) {
                    $out[] = [
                        'ean13'      => sanitize_text_field( $ean ),
                        'type'       => sanitize_text_field( $l['type'] ?? 'download' ),
                        'url'        => esc_url_raw( (string) $url ),
                        'expires_at' => sanitize_text_field( $l['expirationDate'] ?? '' ),
                        'format'     => sanitize_text_field( $l['format'] ?? '' ),
                    ];
                }
            }
        }
        return $out;
    }

    /**
     * Récupère les liens de téléchargement via getOrderDetails (GET).
     */
    public function fetch_download_links( string $hub_id ): array {
        $res = $this->orders_api->get_order_details( $hub_id );
        return $this->extract_links_from_response( $res );
    }

    /**
     * Extrait les liens depuis une réponse getOrderDetails (format "orderLine").
     */
    private function extract_links_from_response( array $res ): array {
        $out = [];
        foreach ( $res['orderLine'] ?? [] as $line ) {
            $ean    = $line['ean13'] ?? '';
            $llinks = $line['links'] ?? $line['link'] ?? [];
            if ( is_string( $llinks ) ) {
                $llinks = [ [ 'url' => $llinks ] ];
            }
            foreach ( (array) $llinks as $l ) {
                $url = $l['url'] ?? $l;
                if ( ! empty( $url ) ) {
                    $out[] = [
                        'ean13'      => sanitize_text_field( $ean ),
                        'type'       => sanitize_text_field( $l['type'] ?? 'download' ),
                        'url'        => esc_url_raw( (string) $url ),
                        'expires_at' => sanitize_text_field( $l['expirationDate'] ?? '' ),
                        'format'     => sanitize_text_field( $l['format'] ?? '' ),
                    ];
                }
            }
        }
        return $out;
    }

    public function save_links_to_order( int $oid, array $links ): void {
        global $wpdb;
        update_post_meta( $oid, '_hub_download_links', wp_json_encode( $links ) );
        $t      = $wpdb->prefix . 'hub_order_lines';
        $by_ean = [];
        foreach ( $links as $l ) {
            $by_ean[ $l['ean13'] ][] = $l;
        }
        foreach ( $by_ean as $ean => $el ) {
            $wpdb->update( $t, [ 'links_json' => wp_json_encode( $el ) ], [ 'wc_order_id' => $oid, 'ean13' => $ean ], [ '%s' ], [ '%d', '%s' ] );
        }
        Hub_Logger::info( 'download/save', sprintf( 'Commande #%d : %d lien(s) sauvegardés.', $oid, count( $links ) ) );
    }

    public function get_links_for_order( int $oid ): array {
        $j = get_post_meta( $oid, '_hub_download_links', true );
        if ( empty( $j ) ) {
            return [];
        }
        $l = json_decode( $j, true );
        return is_array( $l ) ? $l : [];
    }

    public function send_download_email( int $oid ): void {
        $o = wc_get_order( $oid );
        if ( ! $o ) {
            return;
        }
        $links = $this->get_links_for_order( $oid );
        if ( empty( $links ) ) {
            return;
        }
        $to    = $o->get_billing_email();
        $sub   = sprintf( __( '[%s] Vos livres numériques sont prêts', 'wc-hub-dilicom' ), get_bloginfo( 'name' ) );
        $msg   = $this->render_email( $o, $links );
        $hdr   = [ 'Content-Type: text/html; charset=UTF-8', 'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>' ];
        $sent  = wp_mail( $to, $sub, $msg, $hdr );
        $o->add_order_note( $sent ? __( 'Email téléchargement envoyé.', 'wc-hub-dilicom' ) : __( 'Échec email téléchargement.', 'wc-hub-dilicom' ) );
        $sent ? Hub_Logger::info( 'download/email', 'Email envoyé à ' . $to ) : Hub_Logger::error( 'download/email', 'Échec envoi #' . $oid );
        $o->save();
    }

    private function render_email( \WC_Order $o, array $links ): string {
        $tpl = WHD_PATH . 'templates/email/digital-download.php';
        if ( file_exists( $tpl ) ) {
            ob_start();
            include $tpl;
            return ob_get_clean();
        }
        $fn   = esc_html( $o->get_billing_first_name() );
        $html = "<p>Bonjour {$fn},</p><p>Vos livres numériques sont disponibles :</p><ul>";
        foreach ( $links as $l ) {
            $html .= '<li><a href="' . esc_url( $l['url'] ) . '">Télécharger ' . strtoupper( $l['format'] ?: $l['type'] ) . ' — EAN13 : ' . esc_html( $l['ean13'] ) . '</a></li>';
        }
        return $html . '</ul>';
    }
}