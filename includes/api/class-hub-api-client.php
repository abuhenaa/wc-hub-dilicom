<?php
namespace WC_Hub_Dilicom\Api;

use WC_Hub_Dilicom\Hub_Logger;

if ( ! defined( 'ABSPATH' ) ) exit;

class Hub_Api_Client {

    const BASE_JSON_PROD = 'https://hub-dilicom.centprod.com/v3/hub-numerique-api/json/';
    const BASE_ONIX_PROD = 'https://hub-dilicom.centprod.com/v3/hub-numerique-api/onix/';
    const BASE_V1_PROD   = 'https://hub-dilicom.centprod.com/v1/hub-numerique-api/json/';
    const BASE_JSON_TEST = 'https://hub-test.centprod.com/v3/hub-numerique-api/json/';
    const BASE_ONIX_TEST = 'https://hub-test.centprod.com/v3/hub-numerique-api/onix/';
    const BASE_V1_TEST   = 'https://hub-test.centprod.com/v1/hub-numerique-api/json/';

    const TIMEOUT_DEFAULT = 60;
    const TIMEOUT_NOTICES = 600;

    private string $gln_reseller;
    private string $api_login;
    private string $password;
    private string $gln_contractor;
    private string $environment;

    public function __construct() {
        $this->gln_reseller   = (string) get_option( 'whd_gln_reseller',   '' );
        $this->api_login      = (string) get_option( 'whd_api_login',      '' );
        $this->password       = (string) get_option( 'whd_password',       '' );
        $this->gln_contractor = (string) get_option( 'whd_gln_contractor', '' );
        $this->environment    = (string) get_option( 'whd_environment',    'production' );
    }

    public function get_json_url( string $service ): string {
        return ( 'production' === $this->environment ? self::BASE_JSON_PROD : self::BASE_JSON_TEST ) . ltrim( $service, '/' );
    }
    public function get_onix_url( string $service ): string {
        return ( 'production' === $this->environment ? self::BASE_ONIX_PROD : self::BASE_ONIX_TEST ) . ltrim( $service, '/' );
    }
    public function get_v1_url( string $service ): string {
        return ( 'production' === $this->environment ? self::BASE_V1_PROD : self::BASE_V1_TEST ) . ltrim( $service, '/' );
    }

    public function get_api_login(): string {
        return ! empty( $this->api_login ) ? $this->api_login : $this->gln_reseller;
    }

    private function build_qs( array $params ): string {
    $parts = [];
    foreach ( $params as $k => $v ) {
        $parts[] = rawurlencode( (string) $k ) . '=' . str_replace( '%3A', ':', rawurlencode( (string) $v ) );
    }
    return implode( '&', $parts );
}

    private function get_common_params(): array {
        $p = [];
        if ( ! empty( $this->gln_contractor ) ) {
            $p['glnContractor'] = $this->gln_contractor;
        }
        return $p;
    }

    private function validate_credentials(): ?array {
        if ( empty( $this->gln_reseller ) ) {
            Hub_Logger::error( 'credentials', 'GLN Revendeur vide.', 'MISSING_CREDENTIALS' );
            return [ 'returnStatus' => 'MISSING_CREDENTIALS', 'returnMessage' => [ 'GLN Revendeur manquant dans les Réglages.' ] ];
        }
        if ( empty( $this->password ) ) {
            Hub_Logger::error( 'credentials', 'Mot de passe vide.', 'MISSING_CREDENTIALS' );
            return [ 'returnStatus' => 'MISSING_CREDENTIALS', 'returnMessage' => [ 'Mot de passe manquant dans les Réglages.' ] ];
        }
        return null;
    }

    // POST cURL — comme dilicomPost() dans le fichier test validé
    public function request( string $url, array $params = [], int $timeout = self::TIMEOUT_DEFAULT ): array {
        $err = $this->validate_credentials();
        if ( $err ) return $err;

        $all_params = array_merge( $this->get_common_params(), $params );
        $all_params = array_filter( $all_params, static fn( $v ) => $v !== '' && $v !== null );
        $qs         = $this->build_qs( $all_params );
        $login      = $this->get_api_login();

        Hub_Logger::info( $url,
            "POST → {$url} | Login: {$login} | Contractor: " . ( $this->gln_contractor ?: 'vide' )
            . " | Env: {$this->environment} | Params: {$qs}"
        );

        $ch = curl_init();
        curl_setopt_array( $ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $qs,
            CURLOPT_USERPWD        => $login . ':' . $this->password,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ] );

        $raw  = curl_exec( $ch );
        $code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $cerr = curl_error( $ch );
        curl_close( $ch );

        return $this->parse_curl_response( $raw, $code, $cerr, $url );
    }

    // GET cURL pour les endpoints qui le nécessitent
    public function request_get( string $url, array $params = [], int $timeout = self::TIMEOUT_DEFAULT ): array {
        $err = $this->validate_credentials();
        if ( $err ) return $err;

        $all_params = array_merge( $this->get_common_params(), $params );
        $all_params = array_filter( $all_params, static fn( $v ) => $v !== '' && $v !== null );
        $full_url   = $url . '?' . $this->build_qs( $all_params );
        $login      = $this->get_api_login();

        $ch = curl_init();
        curl_setopt_array( $ch, [
            CURLOPT_URL            => $full_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $login . ':' . $this->password,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [ 'Accept: application/json' ],
        ] );

        $raw  = curl_exec( $ch );
        $code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $cerr = curl_error( $ch );
        curl_close( $ch );

        return $this->parse_curl_response( $raw, $code, $cerr, $url );
    }

    // XML cURL — pour getNotice ONIX
    public function request_xml( string $url, array $params = [] ): string|\WP_Error {
        $err = $this->validate_credentials();
        if ( $err ) return new \WP_Error( 'missing_credentials', implode( ' ', $err['returnMessage'] ) );

        $all_params = array_merge( $this->get_common_params(), $params );
        $all_params = array_filter( $all_params, static fn( $v ) => $v !== '' && $v !== null );
        $full_url   = $url . '?' . $this->build_qs( $all_params );
        $login      = $this->get_api_login();

        $ch = curl_init();
        curl_setopt_array( $ch, [
            CURLOPT_URL            => $full_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $login . ':' . $this->password,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_TIMEOUT        => self::TIMEOUT_DEFAULT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => [ 'Accept: application/xml' ],
        ] );

        $raw  = curl_exec( $ch );
        $code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $cerr = curl_error( $ch );
        curl_close( $ch );

        if ( $cerr ) return new \WP_Error( 'curl_error', $cerr );
        if ( 401 === $code || 403 === $code ) return new \WP_Error( 'auth_error', "Authentification échouée (HTTP {$code})." );
        if ( 200 !== $code ) return new \WP_Error( 'http_error', "HTTP {$code}" );

        if ( substr( $raw, 0, 2 ) === "\x1f\x8b" ) {
            $raw = gzdecode( $raw );
        }

        return $raw ?: new \WP_Error( 'empty_response', 'Réponse vide.' );
    }

    private function parse_curl_response( $raw, int $code, string $cerr, string $endpoint ): array {
        if ( $cerr ) {
            Hub_Logger::error( $endpoint, "cURL error: {$cerr}", 'HTTP_ERROR' );
            return [ 'returnStatus' => 'HTTP_ERROR', 'returnMessage' => [ "Erreur cURL : {$cerr}" ] ];
        }
        if ( 401 === $code ) {
            Hub_Logger::error( $endpoint, "HTTP 401 — credentials refusés.", 'AUTHENTICATION_ERROR' );
            return [ 'returnStatus' => 'AUTHENTICATION_ERROR', 'returnMessage' => [ 'HTTP 401 — Identifiants incorrects.' ] ];
        }
        if ( 403 === $code ) {
            $login = $this->get_api_login();
            Hub_Logger::error( $endpoint, "HTTP 403 — Login: {$login} | GLN: {$this->gln_reseller} | Env: {$this->environment}", 'AUTHENTICATION_ERROR' );
            return [ 'returnStatus' => 'AUTHENTICATION_ERROR', 'returnMessage' => [
                "HTTP 403 — Login: {$login} | GLN: {$this->gln_reseller} | Env: {$this->environment}"
            ] ];
        }
        if ( 400 === $code ) {
            Hub_Logger::error( $endpoint, "HTTP 400 — " . substr( $raw, 0, 200 ), 'INVALID_ARGUMENTS' );
            return [ 'returnStatus' => 'INVALID_ARGUMENTS', 'returnMessage' => [ "HTTP 400 : " . substr( $raw, 0, 200 ) ] ];
        }
        if ( 500 === $code ) {
            Hub_Logger::error( $endpoint, "HTTP 500.", 'SERVER_ERROR' );
            return [ 'returnStatus' => 'SERVER_ERROR', 'returnMessage' => [ 'Erreur serveur Dilicom (HTTP 500).' ] ];
        }
        if ( $code < 200 || $code >= 300 ) {
            Hub_Logger::error( $endpoint, "HTTP inattendu: {$code}", 'HTTP_ERROR' );
            return [ 'returnStatus' => 'HTTP_ERROR', 'returnMessage' => [ "HTTP {$code}" ] ];
        }

        $json = json_decode( $raw, true );
        if ( ! is_array( $json ) ) {
            Hub_Logger::error( $endpoint, "JSON invalide. Body: " . substr( $raw, 0, 300 ), 'JSON_ERROR' );
            return [ 'returnStatus' => 'JSON_ERROR', 'returnMessage' => [ 'Réponse non JSON.' ] ];
        }

        $status = $json['returnStatus'] ?? 'OK';
        Hub_Logger::info( $endpoint, "HTTP {$code} — returnStatus: {$status}" );
        return $json;
    }

    public function test_connection(): array {
    $since = gmdate( 'Y-m-d\TH:i:s', strtotime( '-7 days' ) );
    $url   = $this->get_json_url( 'getNotices' );
    Hub_Logger::info( 'settings/test',
        "TEST CONNEXION — URL: {$url} | Login: " . $this->get_api_login()
        . " | GLN: {$this->gln_reseller} | Contractor: " . ( $this->gln_contractor ?: 'vide' )
        . " | Env: {$this->environment} | sinceDate: {$since}"
    );
    return $this->request_get( $url, [ 'sinceDate' => $since ], self::TIMEOUT_DEFAULT );
}

    public static function is_success( array $r ): bool {
        return in_array( $r['returnStatus'] ?? '', [ 'OK', 'WARNING' ], true );
    }
    public static function get_error_message( array $r ): string {
        $msgs = (array) ( $r['returnMessage'] ?? [] );
        return ! empty( $msgs ) ? implode( ' ', $msgs ) : ( $r['returnStatus'] ?? 'Erreur inconnue' );
    }
    public function get_gln_reseller(): string   { return $this->gln_reseller; }
    public function get_gln_contractor(): string { return $this->gln_contractor; }
}