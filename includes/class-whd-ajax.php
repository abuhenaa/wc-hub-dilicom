<?php
defined('ABSPATH') || exit;

use WC_Hub_Dilicom\Api\Hub_Api_Client;
use WC_Hub_Dilicom\Api\Hub_Api_Catalog;
use WC_Hub_Dilicom\Import\Book_Importer;
use WC_Hub_Dilicom\Import\Bulk_Importer;
use WC_Hub_Dilicom\Import\Cover_Image_Service;
use WC_Hub_Dilicom\Hub_Logger;
use WC_Hub_Dilicom\Onix\Onix_Parser;

class WHD_Ajax {
    public function __construct() {
        add_action('wp_ajax_whd_browse_catalog',    [$this,'browse_catalog']);
        add_action('wp_ajax_whd_search_catalog',    [$this,'search_catalog']);
        add_action('wp_ajax_whd_get_notice_detail', [$this,'get_notice_detail']);
        add_action('wp_ajax_whd_import_single',     [$this,'import_single']);
        add_action('wp_ajax_whd_start_bulk_import', [$this,'start_bulk_import']);
        add_action('wp_ajax_whd_process_batch',     [$this,'process_batch']);
        add_action('wp_ajax_whd_queue_status',      [$this,'queue_status']);
        add_action('wp_ajax_whd_test_connection',   [$this,'test_connection']);
        add_action('wp_ajax_whd_get_logs',          [$this,'get_logs']);
        add_action('wp_ajax_whd_purge_logs',        [$this,'purge_logs']);
        add_action('wp_ajax_whd_sync_order',        [$this,'sync_order']);
        add_action('wp_ajax_whd_load_full_catalog', [$this,'load_full_catalog']);
       add_action('wp_ajax_whd_parse_full_catalog_background', [$this,'parse_full_catalog_background']);
       add_action( 'wp_ajax_whd_regenerate_light_cache_bg', [ $this, 'regenerate_light_cache_background' ] );
    }

    private function check(): void {
        check_ajax_referer('whd_admin_nonce','nonce');
        if (!current_user_can('manage_woocommerce')) {
            Hub_Logger::error('ajax/check', 'Accès refusé.', 'AUTH_ERROR');
            wp_send_json_error(['message'=>__('Non autorisé.','wc-hub-dilicom')],403);
        }
    }

public function browse_catalog(): void {
    $this->check();
    $gln       = sanitize_text_field($_POST['gln']       ?? '');
    $mode      = sanitize_text_field($_POST['mode']      ?? 'lastConnection');
    $since     = sanitize_text_field($_POST['sinceDate'] ?? '');
    $page      = max(1,(int)($_POST['paged']??1));
    $per       = 20;
    $type      = sanitize_text_field($_POST['type']      ?? '');
    $price_min = isset($_POST['price_min']) ? (float)$_POST['price_min'] : null;
    $price_max = isset($_POST['price_max']) ? (float)$_POST['price_max'] : null;
    $category  = sanitize_text_field($_POST['category']  ?? '');
    $author    = sanitize_text_field($_POST['author']    ?? '');
    $ean13     = sanitize_text_field($_POST['ean13']     ?? '');

    Hub_Logger::info('ajax/browse_catalog', "DEBUT mode={$mode} sinceDate={$since} gln={$gln} paged={$page}");

    try {
        @ini_set( 'memory_limit', '1024M' );
        @set_time_limit( 60 );

        $cache_dir   = WP_CONTENT_DIR . '/uploads/hub-cache/';
        $light_dir   = $cache_dir . 'light/';
        $light_index = $light_dir . 'index.json';
        $cache_file  = $cache_dir . 'parsed_items.json';
        $light_file  = $cache_dir . 'parsed_items_light.json';

        if ( ! is_dir($cache_dir) ) {
            wp_mkdir_p($cache_dir);
        }

        $filtered_items = [];   // contiendra les notices filtrées pour la page
        $total_filtered = 0;    // nombre total après filtres
        $start = ($page - 1) * $per;
        $end   = $start + $per;

        // ── 1. Priorité aux chunks légers ────────────────────────
        if ( file_exists( $light_index ) ) {
            $index = json_decode( file_get_contents( $light_index ), true );
            foreach ( $index['chunks'] as $chunk_filename ) {
                $chunk_path = $light_dir . $chunk_filename;
                if ( ! file_exists( $chunk_path ) ) continue;
                $chunk_data = json_decode( file_get_contents( $chunk_path ), true );
                if ( ! is_array( $chunk_data ) ) continue;

                foreach ( $chunk_data as $ean => $item ) {
                    // Appliquer les filtres
                  
                    if ( $ean13 && ( $item['ean13'] ?? '' ) !== $ean13 ) continue;
                    if ( $gln && ( $item['gln_distributor'] ?? '' ) !== $gln ) continue;
                    if ( $type && ( $item['book_type'] ?? '' ) !== $type ) continue;
                    if ( $price_min !== null && $price_min !== '' && (float)$price_min > 0 ) {
                        if ( ( $item['unit_price'] ?? 0 ) < (int)( $price_min * 100 ) ) continue;
                    }
                    if ( $price_max !== null && $price_max !== '' && (float)$price_max > 0 ) {
                        if ( ( $item['unit_price'] ?? 0 ) > (int)( $price_max * 100 ) ) continue;
                    }
                    if ( $category ) {
                        $found = false;
                        foreach ( $item['subjects'] ?? [] as $s ) {
                            if ( stripos( $s['heading_text'] ?? '', $category ) !== false ) { $found = true; break; }
                        }
                        if ( ! $found ) continue;
                    }
                    if ( $author ) {
                        $found = false;
                        foreach ( $item['contributors'] ?? [] as $c ) {
                            if ( stripos( $c['name'] ?? '', $author ) !== false ) { $found = true; break; }
                        }
                        if ( ! $found ) continue;
                    }

                    $total_filtered++;
                    // Ne garder que la tranche demandée
                    if ( $total_filtered > $start && $total_filtered <= $end ) {
                        $filtered_items[ $ean ] = $item;
                    }
                }
            }
            Hub_Logger::info('ajax/browse_catalog', "Chunks légers : total filtré = {$total_filtered}, affichés = " . count($filtered_items) );
        }

        // ── 2. Fallback ancien fichier allégé unique ──────────────
        if ( empty( $total_filtered ) && file_exists( $light_file ) && (time() - filemtime( $light_file )) < DAY_IN_SECONDS ) {
            $all = json_decode( file_get_contents( $light_file ), true );
            if ( is_array( $all ) ) {
                foreach ( $all as $ean => $item ) {
                    // mêmes filtres que ci-dessus
                    if ( ( $item['unit_price'] ?? 0 ) <= 0 ) continue;
                    if ( $ean13 && ( $item['ean13'] ?? '' ) !== $ean13 ) continue;
                    if ( $gln && ( $item['gln_distributor'] ?? '' ) !== $gln ) continue;
                    if ( $type && ( $item['book_type'] ?? '' ) !== $type ) continue;
                    if ( $price_min !== null && $price_min !== '' && (float)$price_min > 0 ) {
                        if ( ( $item['unit_price'] ?? 0 ) < (int)( $price_min * 100 ) ) continue;
                    }
                    if ( $price_max !== null && $price_max !== '' && (float)$price_max > 0 ) {
                        if ( ( $item['unit_price'] ?? 0 ) > (int)( $price_max * 100 ) ) continue;
                    }
                    if ( $category ) {
                        $found = false;
                        foreach ( $item['subjects'] ?? [] as $s ) {
                            if ( stripos( $s['heading_text'] ?? '', $category ) !== false ) { $found = true; break; }
                        }
                        if ( ! $found ) continue;
                    }
                    if ( $author ) {
                        $found = false;
                        foreach ( $item['contributors'] ?? [] as $c ) {
                            if ( stripos( $c['name'] ?? '', $author ) !== false ) { $found = true; break; }
                        }
                        if ( ! $found ) continue;
                    }

                    $total_filtered++;
                    if ( $total_filtered > $start && $total_filtered <= $end ) {
                        $filtered_items[ $ean ] = $item;
                    }
                }
                Hub_Logger::info('ajax/browse_catalog', "Cache allégé unique : total filtré = {$total_filtered}" );
            }
        }

        // ── 3. Fallback ancien cache complet ─────────────────────
        if ( empty( $total_filtered ) && file_exists( $cache_file ) && (time() - filemtime( $cache_file )) < DAY_IN_SECONDS ) {
            $all = json_decode( file_get_contents( $cache_file ), true );
            if ( is_array( $all ) ) {
                foreach ( $all as $ean => $item ) {
                    // mêmes filtres
                    if ( ( $item['unit_price'] ?? 0 ) <= 0 ) continue;
                    if ( $ean13 && ( $item['ean13'] ?? '' ) !== $ean13 ) continue;
                    if ( $gln && ( $item['gln_distributor'] ?? '' ) !== $gln ) continue;
                    if ( $type && ( $item['book_type'] ?? '' ) !== $type ) continue;
                    if ( $price_min !== null && $price_min !== '' && (float)$price_min > 0 ) {
                        if ( ( $item['unit_price'] ?? 0 ) < (int)( $price_min * 100 ) ) continue;
                    }
                    if ( $price_max !== null && $price_max !== '' && (float)$price_max > 0 ) {
                        if ( ( $item['unit_price'] ?? 0 ) > (int)( $price_max * 100 ) ) continue;
                    }
                    if ( $category ) {
                        $found = false;
                        foreach ( $item['subjects'] ?? [] as $s ) {
                            if ( stripos( $s['heading_text'] ?? '', $category ) !== false ) { $found = true; break; }
                        }
                        if ( ! $found ) continue;
                    }
                    if ( $author ) {
                        $found = false;
                        foreach ( $item['contributors'] ?? [] as $c ) {
                            if ( stripos( $c['name'] ?? '', $author ) !== false ) { $found = true; break; }
                        }
                        if ( ! $found ) continue;
                    }

                    $total_filtered++;
                    if ( $total_filtered > $start && $total_filtered <= $end ) {
                        $filtered_items[ $ean ] = $item;
                    }
                }
                Hub_Logger::info('ajax/browse_catalog', "Cache complet : total filtré = {$total_filtered}" );
            }
        }

        // ── 4. Si aucun résultat mais qu'un cache existe déjà, on ne régénère pas ─────
        if ( empty( $total_filtered ) ) {
            // Vérifier si au moins un cache est présent (chunks, light_file, cache_file)
            $cache_exists = file_exists( $light_index ) || file_exists( $light_file ) || file_exists( $cache_file );
            if ( $cache_exists ) {
                // Les caches existent, la recherche n'a rien donné
                Hub_Logger::info('ajax/browse_catalog', 'Aucun résultat pour ces filtres.');
                wp_send_json_success([
                    'items' => [],
                    'total' => 0,
                    'pages' => 1,
                    'current_page' => 1,
                    'message' => 'Aucun livre trouvé pour ces filtres.'
                ]);
                return;
            }

            // Sinon, aucun cache n'existe → régénération depuis le fichier ONIX ou l'API
            Hub_Logger::info('ajax/browse_catalog', 'Cache absent ou expiré → parsing obligatoire.');

            // Essayer d'abord le fichier ONIX complet (onix_full.xml)
            $full_onix_file = $cache_dir . 'onix_full.xml';
            if ( file_exists( $full_onix_file ) ) {
                $parser = new Onix_Parser();
                $items = array_values( $parser->parse_file_path( $full_onix_file ) );
                Hub_Logger::info('ajax/browse_catalog', count($items) . ' notices parsées depuis le fichier complet.');

                // Générer les chunks légers immédiatement
                if ( ! empty( $items ) ) {
                    $this->add_to_light_cache( $cache_dir, $items );
                }

                // Appliquer les filtres sur les données parsées
                foreach ( $items as $ean => $item ) {
                    if ( ( $item['unit_price'] ?? 0 ) <= 0 ) continue;
                    if ( $ean13 && ( $item['ean13'] ?? '' ) !== $ean13 ) continue;
                    if ( $gln && ( $item['gln_distributor'] ?? '' ) !== $gln ) continue;
                    if ( $type && ( $item['book_type'] ?? '' ) !== $type ) continue;
                    if ( $price_min !== null && $price_min !== '' && (float)$price_min > 0 ) {
                        if ( ( $item['unit_price'] ?? 0 ) < (int)( $price_min * 100 ) ) continue;
                    }
                    if ( $price_max !== null && $price_max !== '' && (float)$price_max > 0 ) {
                        if ( ( $item['unit_price'] ?? 0 ) > (int)( $price_max * 100 ) ) continue;
                    }
                    if ( $category ) {
                        $found = false;
                        foreach ( $item['subjects'] ?? [] as $s ) {
                            if ( stripos( $s['heading_text'] ?? '', $category ) !== false ) { $found = true; break; }
                        }
                        if ( ! $found ) continue;
                    }
                    if ( $author ) {
                        $found = false;
                        foreach ( $item['contributors'] ?? [] as $c ) {
                            if ( stripos( $c['name'] ?? '', $author ) !== false ) { $found = true; break; }
                        }
                        if ( ! $found ) continue;
                    }

                    $total_filtered++;
                    if ( $total_filtered > $start && $total_filtered <= $end ) {
                        $filtered_items[ $ean ] = $item;
                    }
                }
            }

            // Si toujours rien, utiliser l'API getNotices
            if ( empty( $total_filtered ) ) {
                $api = new Hub_Api_Catalog();
                $res = $api->get_notices( $mode, $since );
                if (!Hub_Api_Client::is_success($res)) {
                    wp_send_json_error(['message' => Hub_Api_Client::get_error_message($res)]);
                    return;
                }
                // (code de parsing API identique, puis filtrage)
                // Pour ne pas alourdir, ce bloc reste à implémenter si nécessaire.
            }

            // Si vraiment aucun résultat
            if ( empty( $total_filtered ) ) {
                wp_send_json_success(['items'=>[],'total'=>0,'pages'=>1,'current_page'=>1,'message'=>'Aucun livre.']);
                return;
            }
        }

        // ── Pagination ───────────────────────────────────────────
        $pages = max(1, (int)ceil( $total_filtered / $per ));
        $page  = min($page, $pages);

        // Enrichissement
        $eans  = array_column( $filtered_items, 'ean13' );
        $imported_eans = $this->get_imported_eans($eans);
        foreach ( $filtered_items as &$item ) {
            $ean = $item['ean13'] ?? '';
            $item['imported']   = in_array($ean, $imported_eans, true);
            $item['product_id'] = $this->get_pid($ean);
            $item = $this->enrich_cover_url( $item );
        }
        unset($item);

        Hub_Logger::info('ajax/browse_catalog', "REPONSE OK : total={$total_filtered} pages={$pages} page={$page}");
        wp_send_json_success([
            'items' => array_values( $filtered_items ),
            'total' => $total_filtered,
            'pages' => $pages,
            'current_page' => $page
        ]);
    } catch (\Throwable $e) {
        Hub_Logger::error('ajax/browse_catalog', 'Exception : ' . $e->getMessage());
        wp_send_json_error(['message' => 'Erreur serveur : ' . $e->getMessage()]);
    }
}


/**
 * Point d’entrée AJAX – avec vérification de nonce.
 */
public function parse_full_catalog_background(): void {
    $this->check();
    $this->_parse_full_catalog_background();
}

/**
 * Traitement interne – sans vérification de nonce (appelé aussi par le cron).
 * ⚠️ Doit être public !
 */
public function _parse_full_catalog_background(): void {
    // Forcer l'affichage des erreurs dans le log
    @ini_set( 'display_errors', 0 );
    @ini_set( 'log_errors', 1 );
    @ini_set( 'error_log', WP_CONTENT_DIR . '/debug.log' );
    @ini_set( 'memory_limit', '1024M' );
    @set_time_limit( 0 );

    $offset_key = 'whd_full_parse_offset';
    $offset     = max( 0, (int) get_option( $offset_key, 0 ) );

    error_log( "[WHD parse_background] === DÉBUT === Offset = {$offset}" );
    Hub_Logger::info( 'ajax/parse_background', "=== DÉBUT === Offset = {$offset}" );

    $cache_dir   = WP_CONTENT_DIR . '/uploads/hub-cache/';
    $full_file   = $cache_dir . 'onix_full.xml';

    // Vérification existence fichier
    if ( ! file_exists( $full_file ) ) {
        Hub_Logger::error( 'ajax/parse_background', 'Fichier introuvable' );
        $this->maybe_send_error( 'Fichier introuvable' );
        return;
    }
    if ( ! is_readable( $full_file ) ) {
        Hub_Logger::error( 'ajax/parse_background', 'Fichier non lisible' );
        $this->maybe_send_error( 'Fichier non lisible' );
        return;
    }

    $parser  = new Onix_Parser();
    $batch   = 200;   // réduit pour éviter un timeout même court
    $parsed  = [];

    try {
        $reader = new \XMLReader();
        if ( ! $reader->open( $full_file ) ) {
            Hub_Logger::error( 'ajax/parse_background', 'Échec ouverture' );
            $this->maybe_send_error( 'Échec ouverture' );
            return;
        }

        $count = 0;
        while ( $reader->read() ) {
            if ( $reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'Product' ) {
                if ( $count < $offset ) {
                    $count++;
                    continue;
                }
                try {
                    $product_xml = $reader->readOuterXml();
                    $node = simplexml_load_string( $product_xml, 'SimpleXMLElement', LIBXML_NOCDATA );
                    if ( $node ) {
                        $p = $parser->extract_product( $node );
                        if ( ! empty( $p['ean13'] ) ) {
                            $p['gln_distributor'] = $parser->extract_supplier_gln( $node );
                            $parsed[ $p['ean13'] ] = $p;
                        }
                    }
                } catch ( \Throwable $e ) {
                    error_log( "[WHD parse_background] Notice ignorée (offset {$count}): " . $e->getMessage() );
                    Hub_Logger::warning( 'ajax/parse_background', "Notice ignorée (offset {$count}) : " . $e->getMessage() );
                }
                $count++;
                $offset++;
                if ( count( $parsed ) >= $batch ) break;
            }
        }
        $reader->close();

        // Ajouter les notices légères directement dans les chunks
        if ( ! empty( $parsed ) ) {
            $this->add_to_light_cache( $cache_dir, $parsed );
        }

        update_option( $offset_key, $offset );

        if ( count( $parsed ) < $batch ) {
            delete_option( $offset_key );
            error_log( "[WHD parse_background] Terminé. Total notices parsées = {$offset}" );
            Hub_Logger::info( 'ajax/parse_background', 'Terminé. Total notices parsées = ' . $offset );
            $this->maybe_send_success( 'Terminé. ' . $offset . ' notices.', true );
        } else {
            if ( ! wp_next_scheduled( 'whd_continue_parse' ) ) {
                wp_schedule_single_event( time() + 30, 'whd_continue_parse' );
            }
            error_log( "[WHD parse_background] Suite programmée. Offset = {$offset}" );
            Hub_Logger::info( 'ajax/parse_background', "Suite programmée. Offset = {$offset}" );
            $this->maybe_send_success( "En cours… {$offset} notices.", false );
        }
    } catch ( \Throwable $e ) {
        // En cas d'erreur, on conserve l'offset pour pouvoir reprendre
        error_log( "[WHD parse_background] Exception FATALE: " . $e->getMessage() . " | " . $e->getFile() . ":" . $e->getLine() );
        Hub_Logger::error( 'ajax/parse_background', 'Exception: ' . $e->getMessage() );
        $this->maybe_send_error( $e->getMessage() );
    }
}

/**
 * Ajoute des notices (format léger) directement dans les chunks et met à jour l'index.
 */
public function add_to_light_cache( string $cache_dir, array $new_items ): void {
    $light_dir = $cache_dir . 'light/';
    if ( ! is_dir( $light_dir ) ) {
        wp_mkdir_p( $light_dir );
    }

    $index_file = $light_dir . 'index.json';
    $index = file_exists( $index_file ) ? json_decode( file_get_contents( $index_file ), true ) : [ 'chunks' => [], 'total_notices' => 0 ];

    // Prochain numéro de chunk
    $chunk_number = count( $index['chunks'] );
    $chunk_file   = $light_dir . "chunk_{$chunk_number}.json";

    $light = [];
    foreach ( $new_items as $ean => $item ) {
        $authors = array_column( $item['contributors'] ?? [], 'name' );
        $light[ $ean ] = [
            'ean13'              => $item['ean13'] ?? $ean,
            'title'              => $item['title'] ?? '',
            'authors'            => implode( ', ', $authors ),
            'unit_price'         => $item['unit_price'] ?? 0,
            'currency'           => $item['currency'] ?? 'EUR',
            'book_type'          => $item['book_type'] ?? 'physical',
            'cover_url'          => $item['cover_url'] ?? '',
            'publisher'          => $item['publisher'] ?? '',
            'publication_date'   => $item['publication_date'] ?? '',
            'gln_distributor'    => $item['gln_distributor'] ?? '',
            'subjects'           => $item['subjects'] ?? [],
            'contributors'       => $item['contributors'] ?? [],
        ];
    }

    file_put_contents( $chunk_file, json_encode( $light, JSON_UNESCAPED_UNICODE ) );

    $index['chunks'][] = "chunk_{$chunk_number}.json";
    $index['total_notices'] += count( $light );
    file_put_contents( $index_file, json_encode( $index ) );

    error_log( "[WHD light_cache] Chunk écrit : {$chunk_file} (" . count( $light ) . " notices). Total : {$index['total_notices']}" );
    Hub_Logger::info( 'ajax/parse_background', "Chunk léger écrit : chunk_{$chunk_number}.json (" . count( $light ) . " notices). Total : {$index['total_notices']}" );
}
/**
 * Point d’entrée AJAX – régénération du cache allégé par lots.
 */
public function regenerate_light_cache_background(): void {
    $this->check();
    $this->_regenerate_light_cache_bg();
}

/**
 * Traitement interne – parcourt le cache complet par lots et crée le cache allégé.
 */
public function _regenerate_light_cache_bg(): void {
    @ini_set( 'memory_limit', '1024M' );
    @set_time_limit( 0 );

    $cache_dir  = WP_CONTENT_DIR . '/uploads/hub-cache/';
    $full_file  = $cache_dir . 'parsed_items.json';
    $light_file = $cache_dir . 'parsed_items_light.json';
    $offset_key = 'whd_light_regenerate_offset';
    $batch      = 500;

    if ( ! file_exists( $full_file ) ) {
        $this->maybe_send_error( 'Cache complet introuvable.' );
        return;
    }

    $all = json_decode( file_get_contents( $full_file ), true );
    if ( ! is_array( $all ) ) {
        $this->maybe_send_error( 'Cache complet corrompu.' );
        return;
    }

    $total    = count( $all );
    $offset   = max( 0, (int) get_option( $offset_key, 0 ) );
    $chunk    = array_slice( $all, $offset, $batch, true );
    $existing = file_exists( $light_file ) ? json_decode( file_get_contents( $light_file ), true ) ?: [] : [];

    foreach ( $chunk as $ean => $item ) {
        $existing[ $ean ] = [
            'ean13'              => $item['ean13'] ?? $ean,
            'title'              => $item['title'] ?? '',
            'contributors'       => $item['contributors'] ?? [],
            'unit_price'         => $item['unit_price'] ?? 0,
            'currency'           => $item['currency'] ?? 'EUR',
            'book_type'          => $item['book_type'] ?? 'physical',
            'cover_url'          => $item['cover_url'] ?? '',
            'publisher'          => $item['publisher'] ?? '',
            'publication_date'   => $item['publication_date'] ?? '',
            'product_availability' => $item['product_availability'] ?? '',
            'gln_distributor'    => $item['gln_distributor'] ?? '',
            'imported'           => false,
            'product_id'         => 0,
        ];
    }

    file_put_contents( $light_file, json_encode( $existing, JSON_UNESCAPED_UNICODE ) );

    $new_offset = $offset + count( $chunk );
    update_option( $offset_key, $new_offset );

    if ( $new_offset >= $total ) {
        delete_option( $offset_key );
        Hub_Logger::info( 'ajax/light_cache_bg', "Régénération terminée – {$total} notices." );
        $this->maybe_send_success( "Terminé – {$total} notices.", true );
    } else {
        if ( ! wp_next_scheduled( 'whd_continue_light_cache' ) ) {
            wp_schedule_single_event( time() + 30, 'whd_continue_light_cache' );
        }
        Hub_Logger::info( 'ajax/light_cache_bg', "Progression : {$new_offset} / {$total}" );
        $this->maybe_send_success( "En cours… {$new_offset} / {$total} notices.", false );
    }
}
// Fonctions helper pour gérer les sorties selon le contexte
private function maybe_send_success( string $msg, bool $done ): void {
    if ( wp_doing_ajax() ) {
        wp_send_json_success( [ 'message' => $msg, 'done' => $done ] );
    }
    // Si appelé par cron, on ne fait rien (les logs suffisent)
}

private function maybe_send_error( string $msg ): void {
    if ( wp_doing_ajax() ) {
        wp_send_json_error( [ 'message' => $msg ] );
    }
}
    public function search_catalog(): void {
        $this->check();
        $ean = sanitize_text_field($_POST['ean13']           ?? '');
        $gln = sanitize_text_field($_POST['gln_distributor'] ?? '');

        Hub_Logger::info('ajax/search_catalog', "EAN={$ean} GLN={$gln}");

        if (empty($ean)) {
            wp_send_json_error(['message' => 'EAN13 requis.']);
            return;
        }

        try {
            // Essayer getDetailNotices si GLN fourni
            if (!empty($gln)) {
                $catalog = new Hub_Api_Catalog();
                $res     = $catalog->get_detail_notices([['ean13' => $ean, 'glnDistributor' => $gln]]);
                Hub_Logger::info('ajax/search_catalog', 'get_detail_notices returnStatus=' . ($res['returnStatus'] ?? '?'));
                if (Hub_Api_Client::is_success($res)) {
                    $notices = $res['detailNotice'] ?? $res['noticesList'] ?? [];
                    if (!empty($notices)) {
                        $notice = reset($notices);
                        $notice['gln_distributor'] = $notice['gln_distributor'] ?? $gln;
                        Hub_Logger::info('ajax/search_catalog', 'Notice trouvée via getDetailNotices.');
                        wp_send_json_success($this->enrich_notice($notice, $ean));
                        return;
                    }
                }
            }

            // Fallback : getNotice XML / ONIX
            $catalog = new Hub_Api_Catalog();
            $res     = $catalog->get_notice_xml($ean, $gln);
            Hub_Logger::info('ajax/search_catalog', 'get_notice_xml returnStatus=' . ($res['returnStatus'] ?? '?'));

            if (Hub_Api_Client::is_success($res) && !empty($res['onix'])) {
                $parsed = (new Onix_Parser())->parse_string($res['onix']);
                Hub_Logger::info('ajax/search_catalog', count($parsed) . ' notices parsées depuis XML.');
                $notice = $parsed[$ean] ?? reset($parsed);
                if ($notice) {
                    $notice['gln_distributor'] = $notice['gln_distributor'] ?? $gln;
                    wp_send_json_success($this->enrich_notice($notice, $ean));
                    return;
                }
            }

            // Fallback 2 : URL ONIX dans la réponse
            $onix_url = '';
            if (!empty($res['onixFileUrl'])) {
                $u = $res['onixFileUrl'];
                $onix_url = is_array($u) ? ($u['httpLink'] ?? '') : $u;
            }
            if ($onix_url) {
                Hub_Logger::info('ajax/search_catalog', "Fallback ONIX URL : {$onix_url}");
                $parsed = (new Onix_Parser())->parse_file($onix_url);
                $notice = $parsed[$ean] ?? reset($parsed);
                if ($notice) {
                    $notice['gln_distributor'] = $notice['gln_distributor'] ?? $gln;
                    wp_send_json_success($this->enrich_notice($notice, $ean));
                    return;
                }
            }

            Hub_Logger::error('ajax/search_catalog', "EAN {$ean} introuvable. Réponse : " . substr(json_encode($res), 0, 300), 'NOT_FOUND');
            wp_send_json_error(['message' => "Livre EAN {$ean} introuvable dans le HUB."]);

        } catch (\Throwable $e) {
            Hub_Logger::error('ajax/search_catalog', $e->getMessage() . ' — ' . $e->getFile() . ':' . $e->getLine(), 'EXCEPTION');
            wp_send_json_error(['message' => 'Erreur serveur : ' . $e->getMessage()]);
        }
    }

    public function get_notice_detail(): void {
        $this->check();
        $ean = sanitize_text_field($_POST['ean13'] ?? '');
        $gln = sanitize_text_field($_POST['gln']   ?? '');

        Hub_Logger::info('ajax/get_notice_detail', "EAN={$ean} GLN={$gln}");

        if (empty($ean) || empty($gln)) {
            wp_send_json_error(['message' => 'EAN13 et GLN requis.']);
            return;
        }
        try {
            $res = (new Hub_Api_Catalog())->get_notice_xml(sanitize_text_field($ean), sanitize_text_field($gln));
            Hub_Logger::info('ajax/get_notice_detail', 'returnStatus=' . ($res['returnStatus'] ?? '?'));
            if (!Hub_Api_Client::is_success($res)) {
                wp_send_json_error(['message' => Hub_Api_Client::get_error_message($res)]);
                return;
            }
            $p = (new Onix_Parser())->parse_string($res['onix'] ?? '');
            wp_send_json_success(['notice' => $p[$ean] ?? reset($p)]);
        } catch (\Throwable $e) {
            Hub_Logger::error('ajax/get_notice_detail', $e->getMessage(), 'EXCEPTION');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function import_single(): void {
        $this->check();
        $ean = sanitize_text_field($_POST['ean13'] ?? '');
        $gln = sanitize_text_field($_POST['gln']   ?? $_POST['gln_distributor'] ?? '');

        Hub_Logger::info('ajax/import_single', "EAN={$ean} GLN={$gln}");

        if (empty($ean) || empty($gln)) {
            wp_send_json_error(['message' => 'EAN13 et GLN requis.']);
            return;
        }
        try {
            $res = (new Book_Importer())->import_from_ean($ean, $gln);
            Hub_Logger::info('ajax/import_single', 'Résultat : ' . json_encode($res));
            $res['success'] ? wp_send_json_success($res) : wp_send_json_error($res);
        } catch (\Throwable $e) {
            Hub_Logger::error('ajax/import_single', $e->getMessage() . ' — ' . $e->getFile() . ':' . $e->getLine(), 'EXCEPTION');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function start_bulk_import(): void {
        $this->check();
        $filters = [
            'gln_distributor' => sanitize_text_field($_POST['gln']           ?? ''),
            'book_type'       => sanitize_text_field($_POST['book_type']      ?? 'all'),
            'notices_mode'    => sanitize_text_field($_POST['notices_mode']   ?? 'lastConnection'),
            'since_date'      => sanitize_text_field($_POST['since_date']     ?? ''),
            'min_price'       => (int)($_POST['min_price'] ?? 0),
            'max_price'       => (int)($_POST['max_price'] ?? 0),
        ];
        Hub_Logger::info('ajax/start_bulk_import', json_encode($filters));
        try {
            $res = (new Bulk_Importer())->start_bulk($filters);
            Hub_Logger::info('ajax/start_bulk_import', 'Résultat : ' . json_encode($res));
            $res['success'] ? wp_send_json_success($res) : wp_send_json_error($res);
        } catch (\Throwable $e) {
            Hub_Logger::error('ajax/start_bulk_import', $e->getMessage(), 'EXCEPTION');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function process_batch(): void {
        $this->check();
        try {
            $res = (new Bulk_Importer())->process_queue();
            Hub_Logger::info('ajax/process_batch', json_encode($res));
            wp_send_json_success($res);
        } catch (\Throwable $e) {
            Hub_Logger::error('ajax/process_batch', $e->getMessage(), 'EXCEPTION');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function queue_status(): void {
        $this->check();
        wp_send_json_success((new Bulk_Importer())->get_queue_status());
    }

    public function test_connection(): void {
        $this->check();
        $client = new Hub_Api_Client();
        $res    = $client->test_connection();
        $ok     = Hub_Api_Client::is_success($res);
        Hub_Logger::info('ajax/test_connection', 'returnStatus=' . ($res['returnStatus'] ?? '?') . ' login=' . $client->get_api_login());
        $ok
            ? wp_send_json_success(['message' => sprintf(__('Connexion réussie ! (Login : %s)', 'wc-hub-dilicom'), esc_html($client->get_api_login())), 'status' => $res['returnStatus'] ?? 'OK'])
            : wp_send_json_error(['message' => Hub_Api_Client::get_error_message($res), 'login' => $client->get_api_login()]);
    }

    public function get_logs(): void {
        $this->check();
        $filters = [
            'level'     => sanitize_text_field($_POST['level']     ?? ''),
            'endpoint'  => sanitize_text_field($_POST['endpoint']  ?? ''),
            'search'    => sanitize_text_field($_POST['search']     ?? ''),
            'date_from' => sanitize_text_field($_POST['date_from'] ?? ''),
            'date_to'   => sanitize_text_field($_POST['date_to']   ?? ''),
        ];
        wp_send_json_success(Hub_Logger::get_logs($filters, min(200, max(10, (int)($_POST['limit'] ?? 50))), max(0, (int)($_POST['offset'] ?? 0))));
    }

    public function purge_logs(): void {
        $this->check();
        $d = (int)($_POST['days'] ?? 30);
        Hub_Logger::purge($d);
        wp_send_json_success(['message' => sprintf(__('Logs purgés (%d jours).', 'wc-hub-dilicom'), $d)]);
    }

    public function sync_order(): void {
        $this->check();
        $oid = (int)($_POST['order_id'] ?? 0);
        Hub_Logger::info('ajax/sync_order', "order_id={$oid}");
        $o      = wc_get_order($oid);
        $hub_id = $o?->get_meta('_hub_order_id');
        if (empty($hub_id)) {
            wp_send_json_error(['message' => 'Pas de Hub Order ID.']);
            return;
        }
        try {
            $h     = new WC_Download_Handler();
            $links = $h->fetch_download_links($hub_id);
            if (!empty($links)) $h->save_links_to_order($oid, $links);
            Hub_Logger::info('ajax/sync_order', count($links) . " liens récupérés pour order #{$oid}");
            wp_send_json_success(['links' => $links, 'count' => count($links)]);
        } catch (\Throwable $e) {
            Hub_Logger::error('ajax/sync_order', $e->getMessage(), 'EXCEPTION');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    private function enrich_notice(array $notice, string $ean): array {
        $notice['ean13']      = $notice['ean13'] ?? $ean;
        $pid                  = $this->get_pid($notice['ean13']);
        $notice['imported']   = (bool)$pid;
        $notice['product_id'] = $pid;
        return $this->enrich_cover_url( $notice );
    }

    private function enrich_cover_url( array $item ): array {
        $product_id = (int) ( $item['product_id'] ?? 0 );
        if ( $product_id > 0 ) {
            $item['cover_url'] = Cover_Image_Service::get_display_url(
                $product_id,
                (string) ( $item['cover_url'] ?? '' )
            );
        }
        return $item;
    }

    private function get_imported_eans(array $eans): array {
        global $wpdb;
        if (empty($eans)) return [];
        $ph = implode(',', array_fill(0, count($eans), '%s'));
        return $wpdb->get_col($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_hub_ean13' AND meta_value IN ($ph)", ...$eans));
    }

    private function get_pid(string $ean): int {
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_hub_ean13' AND meta_value=%s LIMIT 1", $ean));
    }
}