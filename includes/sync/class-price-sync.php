<?php
namespace WC_Hub_Dilicom\Sync;
use WC_Hub_Dilicom\Api\Hub_Api_Catalog;
use WC_Hub_Dilicom\Api\Hub_Api_Client;
use WC_Hub_Dilicom\Hub_Logger;
use WC_Hub_Dilicom\Onix\Onix_Parser;
if ( ! defined( 'ABSPATH' ) ) exit;

class Price_Sync {

    /**
     * Synchronise les prix et disponibilités de TOUS les produits HUB.
     * Peut être appelée manuellement ou par un cron.
     */
    public static function run(): void {
        global $wpdb;

        Hub_Logger::info( 'sync/price', 'Début synchronisation des prix.' );
        $start = microtime( true );

        // 1. Récupérer tous les produits HUB (ean13, gln_distributor, product_id)
        $products = $wpdb->get_results(
            "SELECT pm1.post_id AS product_id,
                    pm1.meta_value AS ean13,
                    pm2.meta_value AS gln
             FROM {$wpdb->postmeta} pm1
             INNER JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id
             WHERE pm1.meta_key = '_hub_ean13'
             AND pm2.meta_key = '_hub_gln_distributor'"
        );

        if ( empty( $products ) ) {
            Hub_Logger::info( 'sync/price', 'Aucun produit HUB trouvé.' );
            return;
        }

        Hub_Logger::info( 'sync/price', count( $products ) . ' produit(s) HUB à vérifier.' );

        // 2. Forcer le GLN Immatériel par défaut si vide
        $default_gln = '3012410001000';
        $products_by_gln = [];
        foreach ( $products as $p ) {
            $gln = $p->gln ?: $default_gln;
            $products_by_gln[ $gln ][] = [
                'ean13'      => $p->ean13,
                'product_id' => (int) $p->product_id,
            ];
        }

        $api     = new Hub_Api_Catalog();
        $parser  = new Onix_Parser();
        $updated = 0;
        $errors  = 0;
        $not_found = 0;

        // 3. Pour chaque distributeur, appeler getDetailNotices par lots de 12
        foreach ( $products_by_gln as $gln => $items ) {
            $chunks = array_chunk( $items, 12 );
            foreach ( $chunks as $chunk ) {
                $ean_list = [];
                $item_map = [];
                foreach ( $chunk as $item ) {
                    $ean_list[] = [ 'ean13' => $item['ean13'], 'glnDistributor' => $gln ];
                    $item_map[ $item['ean13'] ] = $item;
                }

                $response = $api->get_detail_notices( $ean_list );
                if ( ! Hub_Api_Client::is_success( $response ) ) {
                    Hub_Logger::warning( 'sync/price', "Échec getDetailNotices pour GLN {$gln} : " . ( $response['returnStatus'] ?? '?' ) );
                    $errors += count( $chunk );
                    continue;
                }

                // Récupérer les notices depuis detailNotices (pluriel) ou noticesList
                $notices = $response['detailNotices'] ?? $response['noticesList'] ?? [];
                if ( empty( $notices ) ) {
                    Hub_Logger::warning( 'sync/price', "Aucune notice dans la réponse pour GLN {$gln}." );
                    $not_found += count( $chunk );
                    continue;
                }

                foreach ( $notices as $notice ) {
                    $ean = $notice['ean13'] ?? '';
                    if ( ! isset( $item_map[ $ean ] ) ) continue;

                    $pid = $item_map[ $ean ]['product_id'];

                    // Extraire le prix et la disponibilité depuis la notice ONIX brute
                    $price = 0;
                    $avail = '';
                    if ( ! empty( $notice['onixProduct'] ) ) {
                        // Parser la notice ONIX encodée dans le champ onixProduct
                        $parsed = $parser->parse_string( $notice['onixProduct'] );
                        $onix_data = $parsed[ $ean ] ?? reset( $parsed );
                        if ( $onix_data ) {
                            $price = (int) ( $onix_data['unit_price'] ?? 0 );
                            $avail = $onix_data['product_availability'] ?? '';
                        }
                    }

                    // Fallback sur les champs directs de la réponse (si pas de XML)
                    if ( $price <= 0 ) {
                        $price = (int) ( $notice['unit_price'] ?? $notice['unitPrice'] ?? 0 );
                    }
                    if ( empty( $avail ) ) {
                        $avail = $notice['product_availability'] ?? $notice['availability'] ?? '';
                    }

                    if ( $price > 0 ) {
                        // Mise à jour du prix en centimes
                        update_post_meta( $pid, '_hub_unit_price', $price );
                        // Mise à jour du prix WooCommerce
                        $wc_price = $price / 100;
                        update_post_meta( $pid, '_regular_price', wc_format_decimal( $wc_price ) );
                        update_post_meta( $pid, '_price', wc_format_decimal( $wc_price ) );

                        Hub_Logger::info( 'sync/price', "EAN {$ean} : prix mis à jour → {$price} centimes (" . number_format( $wc_price, 2 ) . " €)" );
                        $updated++;
                    } else {
                        Hub_Logger::warning( 'sync/price', "EAN {$ean} : prix absent ou zéro dans la notice, ignoré." );
                        $not_found++;
                    }

                    if ( $avail ) {
                        update_post_meta( $pid, '_hub_product_availability', $avail );
                    }

                    update_post_meta( $pid, '_hub_last_sync', current_time( 'mysql', true ) );
                    unset( $item_map[ $ean ] );
                }

                // EAN non retournés
                foreach ( $item_map as $missing_ean => $missing_item ) {
                    Hub_Logger::warning( 'sync/price', "EAN {$missing_ean} introuvable dans la réponse getDetailNotices." );
                    $not_found++;
                }
            }
        }

        $duration = round( microtime( true ) - $start, 2 );
        Hub_Logger::info( 'sync/price', "Synchronisation terminée en {$duration}s : {$updated} prix mis à jour, {$not_found} introuvables, {$errors} erreurs." );
    }
}