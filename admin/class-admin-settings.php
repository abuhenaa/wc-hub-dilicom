<?php
namespace WC_Hub_Dilicom\Admin;

use WC_Hub_Dilicom\Api\Hub_Api_Client;
use WC_Hub_Dilicom\Hub_Logger;

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
    const OPT_IMPORT_TAX_STATUS = 'whd_import_tax_status';   // ← nouvelle constante

    public function __construct() {
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'wp_ajax_whd_test_connection', [ $this, 'ajax_test_connection' ] );
    }

    public function register_settings(): void {
        $opts = [
            self::OPT_GLN_RESELLER     => 'sanitize_text_field',
            self::OPT_API_LOGIN        => 'sanitize_text_field',
            self::OPT_PASSWORD         => [ $this, 'sanitize_password' ],
            self::OPT_GLN_CONTRACTOR   => 'sanitize_text_field',
            self::OPT_ENVIRONMENT      => [ $this, 'sanitize_environment' ],
            self::OPT_CURRENCY         => 'sanitize_text_field',
            self::OPT_COUNTRY          => 'sanitize_text_field',
            self::OPT_IMPORT_TAX_STATUS => [ $this, 'sanitize_import_tax_status' ],  // ← enregistrement
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

    public function sanitize_password( string $v ): string {
        return empty( trim( $v ) ) ? (string) get_option( self::OPT_PASSWORD, '' ) : sanitize_text_field( $v );
    }

    public function sanitize_environment( string $v ): string {
        return in_array( $v, [ 'production', 'test' ], true ) ? $v : 'production';
    }

    public function sanitize_import_tax_status( string $v ): string {
        return in_array( $v, [ 'taxable', 'none' ], true ) ? $v : 'taxable';
    }

    public static function get_all(): array {
        return [
            'gln_reseller'     => (string) get_option( self::OPT_GLN_RESELLER, '' ),
            'api_login'        => (string) get_option( self::OPT_API_LOGIN, '' ),
            'password'         => (string) get_option( self::OPT_PASSWORD, '' ),
            'gln_contractor'   => (string) get_option( self::OPT_GLN_CONTRACTOR, '' ),
            'environment'      => (string) get_option( self::OPT_ENVIRONMENT, 'production' ),
            'currency'         => (string) get_option( self::OPT_CURRENCY, 'EUR' ),
            'country'          => (string) get_option( self::OPT_COUNTRY, 'FR' ),
            'import_tax_status'=> (string) get_option( self::OPT_IMPORT_TAX_STATUS, 'taxable' ), // ← ajouté
        ];
    }

    public static function are_credentials_set(): bool {
        return ! empty( get_option( self::OPT_GLN_RESELLER ) ) && ! empty( get_option( self::OPT_PASSWORD ) );
    }
}