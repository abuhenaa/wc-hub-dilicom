<?php
namespace WC_Hub_Dilicom\Admin;

use WC_Hub_Dilicom\Api\Hub_Api_Client;
use WC_Hub_Dilicom\Hub_Logger;
use WC_Hub_Dilicom\Import\Cover_Image_Service;

defined( 'ABSPATH' ) || exit;

class Admin_Settings {

    const OPTION_GROUP         = 'whd_settings_group';
    const OPT_GLN_RESELLER     = 'whd_gln_reseller';
    const OPT_API_LOGIN        = 'whd_api_login';
    const OPT_PASSWORD         = 'whd_password';
    const OPT_GLN_CONTRACTOR   = 'whd_gln_contractor';
    const OPT_ENVIRONMENT      = 'whd_environment';
    const OPT_CURRENCY         = 'whd_currency';
    const OPT_COUNTRY          = 'whd_country';
    const OPT_IMPORT_TAX_STATUS = 'whd_import_tax_status';
    const OPT_COVER_ENABLED    = 'whd_cover_optimize_enabled';
    const OPT_COVER_MAX_H      = 'whd_cover_max_height';
    const OPT_COVER_QUALITY    = 'whd_cover_webp_quality';
    const OPT_COVER_BATCH      = 'whd_cover_queue_batch';
    const OPT_FTP_HOST         = 'whd_ftp_host';
    const OPT_FTP_USER         = 'whd_ftp_user';
    const OPT_FTP_PASS         = 'whd_ftp_pass';
    const OPT_FTP_PORT         = 'whd_ftp_port';

    public function __construct() {
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'wp_ajax_whd_test_connection', [ $this, 'ajax_test_connection' ] );
        add_action( 'wp_ajax_whd_backfill_covers', [ $this, 'ajax_backfill_covers' ] );
        add_action( 'wp_ajax_whd_cover_queue_status', [ $this, 'ajax_cover_queue_status' ] );
    }

    public function register_settings(): void {
        $opts = [
            self::OPT_GLN_RESELLER      => 'sanitize_text_field',
            self::OPT_API_LOGIN         => 'sanitize_text_field',
            self::OPT_PASSWORD          => [ $this, 'sanitize_password' ],
            self::OPT_GLN_CONTRACTOR    => 'sanitize_text_field',
            self::OPT_ENVIRONMENT       => [ $this, 'sanitize_environment' ],
            self::OPT_CURRENCY          => 'sanitize_text_field',
            self::OPT_COUNTRY           => 'sanitize_text_field',
            self::OPT_IMPORT_TAX_STATUS => [ $this, 'sanitize_import_tax_status' ],
            self::OPT_COVER_ENABLED     => [ $this, 'sanitize_yes_no' ],
            self::OPT_COVER_MAX_H       => [ $this, 'sanitize_cover_max_height' ],
            self::OPT_COVER_QUALITY     => [ $this, 'sanitize_cover_quality' ],
            self::OPT_COVER_BATCH       => [ $this, 'sanitize_cover_batch' ],
            self::OPT_FTP_HOST          => 'sanitize_text_field',
            self::OPT_FTP_USER          => 'sanitize_text_field',
            self::OPT_FTP_PASS          => [ $this, 'sanitize_ftp_password' ],
            self::OPT_FTP_PORT          => [ $this, 'sanitize_ftp_port' ],
        ];
        foreach ( $opts as $name => $cb ) {
            register_setting( self::OPTION_GROUP, $name, [ 'sanitize_callback' => $cb ] );
        }
    }

    public function ajax_test_connection(): void {
        check_ajax_referer( 'whd_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( [ 'message' => 'Non autorisé.' ] );
        $client = new Hub_Api_Client();
        $res    = $client->test_connection();
        $ok     = Hub_Api_Client::is_success( $res );
        $login  = $client->get_api_login();
        if ( $ok ) {
            Hub_Logger::info( 'settings/test', "Connexion OK — Login : {$login}" );
            wp_send_json_success( [ 'message' => sprintf( __( 'Connexion réussie ! (Login : %s)', 'wc-hub-dilicom' ), esc_html( $login ) ), 'status' => $res['returnStatus'] ?? 'OK' ] );
        } else {
            $msg = Hub_Api_Client::get_error_message( $res );
            Hub_Logger::info( 'settings/test', "Test ÉCHEC — Login : {$login} — {$msg}" );
            wp_send_json_error( [ 'message' => $msg, 'status' => $res['returnStatus'] ?? 'ERROR', 'login' => $login ] );
        }
    }

    public function ajax_backfill_covers(): void {
        check_ajax_referer( 'whd_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Non autorisé.', 'wc-hub-dilicom' ) ] );
        }
        $queue = new \WC_Hub_Dilicom\Import\Cover_Queue();
        $count = $queue->backfill_all();
        wp_send_json_success( [
            'message' => sprintf(
                __( '%d couverture(s) ajoutée(s) à la file de traitement.', 'wc-hub-dilicom' ),
                $count
            ),
            'queued'  => $count,
            'status'  => $queue->get_status(),
        ] );
    }

    public function ajax_cover_queue_status(): void {
        check_ajax_referer( 'whd_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Non autorisé.', 'wc-hub-dilicom' ) ] );
        }
        $queue = new \WC_Hub_Dilicom\Import\Cover_Queue();
        wp_send_json_success( $queue->get_status() );
    }

    public function sanitize_password( string $v ): string {
        return empty( trim( $v ) ) ? (string) get_option( self::OPT_PASSWORD, '' ) : sanitize_text_field( $v );
    }

    public function sanitize_environment( string $v ): string {
        return in_array( $v, [ 'production', 'test' ], true ) ? $v : 'production';
    }

    public function sanitize_import_tax_status( string $v ): string {
        return in_array( $v, [ 'taxable', 'none' ], true ) ? $v : 'taxable';
    }

    public function sanitize_yes_no( string $v ): string {
        return 'yes' === $v ? 'yes' : 'no';
    }

    public function sanitize_cover_max_height( $v ): int {
        return max( 100, min( 2000, (int) $v ) );
    }

    public function sanitize_cover_quality( $v ): int {
        return max( 1, min( 100, (int) $v ) );
    }

    public function sanitize_cover_batch( $v ): int {
        return max( 5, min( 100, (int) $v ) );
    }

    public function sanitize_ftp_password( string $v ): string {
        return empty( trim( $v ) ) ? (string) get_option( self::OPT_FTP_PASS, '' ) : sanitize_text_field( $v );
    }

    public function sanitize_ftp_port( $v ): int {
        return max( 1, min( 65535, (int) $v ) );
    }

    public static function get_all(): array {
        $cover = Cover_Image_Service::get_settings();
        return [
            'gln_reseller'      => (string) get_option( self::OPT_GLN_RESELLER, '' ),
            'api_login'         => (string) get_option( self::OPT_API_LOGIN, '' ),
            'password'          => (string) get_option( self::OPT_PASSWORD, '' ),
            'gln_contractor'    => (string) get_option( self::OPT_GLN_CONTRACTOR, '' ),
            'environment'       => (string) get_option( self::OPT_ENVIRONMENT, 'production' ),
            'currency'          => (string) get_option( self::OPT_CURRENCY, 'EUR' ),
            'country'           => (string) get_option( self::OPT_COUNTRY, 'FR' ),
            'import_tax_status' => (string) get_option( self::OPT_IMPORT_TAX_STATUS, 'taxable' ),
            'cover_enabled'     => $cover['enabled'] ? 'yes' : 'no',
            'cover_max_height'  => $cover['max_height'],
            'cover_quality'     => $cover['quality'],
            'cover_batch'       => (int) get_option( self::OPT_COVER_BATCH, 25 ),
            'cover_webp'        => $cover['webp'],
            'ftp_host'          => (string) get_option( self::OPT_FTP_HOST, 'pftp.centprod.com' ),
            'ftp_user'          => (string) get_option( self::OPT_FTP_USER, '' ),
            'ftp_pass'          => (string) get_option( self::OPT_FTP_PASS, '' ),
            'ftp_port'          => (int) get_option( self::OPT_FTP_PORT, 21 ),
        ];
    }

    public static function are_credentials_set(): bool {
        return ! empty( get_option( self::OPT_GLN_RESELLER ) ) && ! empty( get_option( self::OPT_PASSWORD ) );
    }
}
