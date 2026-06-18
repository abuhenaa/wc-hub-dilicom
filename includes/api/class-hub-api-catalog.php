<?php
namespace WC_Hub_Dilicom\Api;

use WC_Hub_Dilicom\Hub_Logger;

if ( ! defined( 'ABSPATH' ) ) exit;

class Hub_Api_Catalog {
    private Hub_Api_Client $client;

    public function __construct( ?Hub_Api_Client $c = null ) {
        $this->client = $c ?? new Hub_Api_Client();
    }

    public function get_notices( string $mode = 'sinceDate', string $since = '' ): array {
        $client = new Hub_Api_Client();
        $url    = $client->get_json_url( 'getNotices' );

        if ( $mode === 'lastConnection' ) {
            $params = [ 'lastConnection' => 'true' ];
        } elseif ( $mode === 'initialization' ) {
            $params = [ 'initialization' => 'true' ];
        } else {
            if ( empty( $since ) ) {
                $since = date( 'Y-m-d', strtotime( '-3 days' ) ) . 'T00:00:00';
            } elseif ( strlen( $since ) === 10 ) {
                $since = $since . 'T00:00:00';
            }
            $params = [ 'sinceDate' => $since ];
        }

        Hub_Logger::info( 'api/getNotices', 'Mode: ' . $mode . ' | Paramètre envoyé: ' . json_encode( $params ) );

        return $client->request_get( $url, $params, Hub_Api_Client::TIMEOUT_NOTICES );
    }

    public function get_detail_notices( array $items ): array {
    $params = [];
    foreach ( array_values( $items ) as $i => $item ) {
        $params[ "notices[{$i}].ean13" ]          = $item['ean13'];
        $params[ "notices[{$i}].glnDistributor" ] = $item['glnDistributor'];
    }
    return $this->client->request(
        $this->client->get_json_url( 'getDetailNotices' ),
        $params
    );
}

    public function get_detail_notices_bulk( array $ean13s, string $gln_distributor ): array {
        $all    = [];
        $chunks = array_chunk( $ean13s, 12 );
        foreach ( $chunks as $chunk ) {
            $items = array_map( static fn( $e ) => [ 'ean13' => $e, 'glnDistributor' => $gln_distributor ], $chunk );
            $res   = $this->get_detail_notices( $items );
            if ( Hub_Api_Client::is_success( $res ) ) {
                $all = array_merge( $all, $res['detailNotice'] ?? [] );
            }
        }
        return $all;
    }

    public function get_notice_xml( string $ean13, string $gln_distributor ): array {
        $xml = $this->client->request_xml(
            $this->client->get_onix_url( 'getNotice' ),
            [ 'ean13' => $ean13, 'glnDistributor' => $gln_distributor ]
        );
        if ( is_wp_error( $xml ) ) {
            return [ 'returnStatus' => 'HTTP_ERROR', 'returnMessage' => [ $xml->get_error_message() ] ];
        }
        if ( empty( $xml ) ) {
            return [ 'returnStatus' => 'ERROR', 'returnMessage' => [ 'Réponse ONIX vide.' ] ];
        }
        return [ 'returnStatus' => 'OK', 'onix' => $xml, 'ean13' => $ean13, 'gln_distributor' => $gln_distributor ];
    }

    public function get_digital_versions( array $physical_eans ): array {
        $params = [];
        foreach ( array_values( $physical_eans ) as $i => $ean ) {
            $params[ "physicalEans{$i}" ] = $ean;
        }
        return $this->client->request(
            $this->client->get_v1_url( 'getDigitalVersions' ),
            $params
        );
    }
}