<?php
namespace WC_Hub_Dilicom;
if ( ! defined( 'ABSPATH' ) ) exit;

class Hub_Logger {
    const LEVEL_INFO    = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR   = 'error';

    // Codes retour HUB (doc §2)
    const CODE_OK                   = 'OK';
    const CODE_WARNING              = 'WARNING';
    const CODE_ERROR                = 'ERROR';
    const CODE_AUTHENTICATION_ERROR = 'AUTHENTICATION_ERROR';
    const CODE_INVALID_ARGUMENTS    = 'INVALID_ARGUMENTS';
    const CODE_UNKNOWN_EAN          = 'UNKNOWN_EAN';
    const CODE_UNKNOWN_ORDER        = 'UNKNOWN_ORDER';
    const CODE_ORDER_ALREADY_EXISTS = 'ORDER_ALREADY_EXISTS';
    const CODE_ALREADY_DOWNLOADED   = 'ALREADY_DOWNLOADED';
    const CODE_CANCEL_ERROR         = 'CANCEL_ERROR';
    const CODE_UNAVAILABLE          = 'UNAVAILABLE';

    public static function info( string $endpoint, string $message, string $code = '' ): void {
        self::log( self::LEVEL_INFO, $endpoint, $message, $code );
    }

    public static function warning( string $endpoint, string $message, string $code = '' ): void {
        self::log( self::LEVEL_WARNING, $endpoint, $message, $code );
    }

    public static function error( string $endpoint, string $message, string $code = '' ): void {
        self::log( self::LEVEL_ERROR, $endpoint, $message, $code );
    }

    public static function log( string $level, string $endpoint, string $message, string $return_code = '', array $context = [] ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'hub_logs',
            [
                'level'       => sanitize_text_field( $level ),
                'endpoint'    => sanitize_text_field( $endpoint ),
                'message'     => sanitize_textarea_field( $message ),
                'return_code' => sanitize_text_field( $return_code ),
                'context'     => ! empty( $context ) ? wp_json_encode( $context ) : null,
                'created_at'  => current_time( 'mysql', true ),
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    public static function get_logs( array $filters = [], int $limit = 50, int $offset = 0 ): array {
        global $wpdb;
        $table  = $wpdb->prefix . 'hub_logs';
        $where  = [ '1=1' ];
        $params = [];

        if ( ! empty( $filters['level'] ) ) {
            $where[]  = 'level = %s';
            $params[] = $filters['level'];
        }
        if ( ! empty( $filters['endpoint'] ) ) {
            $where[]  = 'endpoint LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $filters['endpoint'] ) . '%';
        }
        if ( ! empty( $filters['search'] ) ) {
            $where[]  = 'message LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
        }
        if ( ! empty( $filters['date_from'] ) ) {
            $where[]  = 'created_at >= %s';
            $params[] = $filters['date_from'];
        }
        if ( ! empty( $filters['date_to'] ) ) {
            $where[]  = 'created_at <= %s';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        $where_sql = implode( ' AND ', $where );
        $params[]  = $limit;
        $params[]  = $offset;

        $sql  = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
        $rows = empty( array_filter( $params, 'is_string' ) )
            ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A )
            : $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}" );

        return [ 'logs' => $rows ?: [], 'total' => $total ];
    }

    public static function purge( int $days = 30 ): void {
        global $wpdb;
        if ( 0 === $days ) {
            $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}hub_logs" );
        } else {
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}hub_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            ) );
        }
    }
}
