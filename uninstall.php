<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

global $wpdb;

wp_clear_scheduled_hook( 'whd_process_cover_queue' );

// Supprime les tables custom
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}hub_logs" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}hub_import_queue" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}hub_order_lines" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}hub_cover_queue" );

// Supprime toutes les options du plugin
$options = [
    'whd_gln_reseller',
    'whd_password',
    'whd_gln_contractor',
    'whd_environment',
    'whd_currency',
    'whd_country',
    'whd_last_connection',
    'whd_cover_optimize_enabled',
    'whd_cover_max_height',
    'whd_cover_webp_quality',
    'whd_cover_queue_batch',
];
foreach ( $options as $opt ) delete_option( $opt );

// Supprime les metas produits HUB
$meta_keys = [
    '_hub_ean13',
    '_hub_gln_distributor',
    '_hub_book_type',
    '_hub_cover_url',
    '_hub_cover_attachment_id',
    '_hub_placeholder_attachment_id',
    '_hub_onix_form',
    '_hub_unit_price',
    '_hub_currency',
    '_hub_product_availability',
    '_hub_last_sync',
    '_hub_isbn13',
    '_hub_publisher',
    '_hub_publication_date',
    '_hub_language',
    '_hub_page_count',
    '_hub_product_form_detail',
];
foreach ( $meta_keys as $key ) {
    $wpdb->delete( $wpdb->postmeta, [ 'meta_key' => $key ] );
}
