<?php
namespace WC_Hub_Dilicom\Import;
use WC_Hub_Dilicom\Api\Hub_Api_Catalog;
use WC_Hub_Dilicom\Api\Hub_Api_Client;
use WC_Hub_Dilicom\Onix\Onix_Parser;
use WC_Hub_Dilicom\Onix\Onix_Mapper;
use WC_Hub_Dilicom\Hub_Logger;
if ( ! defined( 'ABSPATH' ) ) exit;

class Book_Importer {
    private Hub_Api_Catalog $catalog_api;
    private Onix_Parser $parser;
    private Onix_Mapper $mapper;
    private Cover_Image_Service $cover_service;

    public function __construct( ?Hub_Api_Catalog $c=null, ?Onix_Parser $p=null, ?Onix_Mapper $m=null, ?Cover_Image_Service $cover=null ) {
        $this->catalog_api   = $c ?? new Hub_Api_Catalog();
        $this->parser        = $p ?? new Onix_Parser();
        $this->mapper        = $m ?? new Onix_Mapper();
        $this->cover_service = $cover ?? new Cover_Image_Service();
    }

    public function import_from_ean( string $ean13, string $gln ): array {
        $res = $this->catalog_api->get_notice_xml($ean13, $gln);
        if ( !Hub_Api_Client::is_success($res) ) {
            $msg = 'Notice ONIX introuvable pour EAN13 '.$ean13;
            Hub_Logger::error('import/from_ean', $msg, $res['returnStatus']??'ERROR');
            return ['success'=>false,'product_id'=>null,'message'=>$msg];
        }
        $xml = $res['onix'] ?? $res['notice'] ?? '';
        if ( empty($xml) ) return ['success'=>false,'product_id'=>null,'message'=>'Notice vide pour EAN13 '.$ean13];
        $products  = $this->parser->parse_string($xml);
        $onix_data = $products[$ean13] ?? reset($products);
        if ( empty($onix_data) ) return ['success'=>false,'product_id'=>null,'message'=>'Parsing vide EAN13 '.$ean13];
        $onix_data['gln_distributor'] = $gln;
        return $this->import_from_onix_data($onix_data, $gln);
    }

    public function import_from_onix_data( array $d, string $gln ): array {
        $ean13 = $d['ean13'] ?? '';
        if ( empty($ean13) ) return ['success'=>false,'product_id'=>null,'message'=>'EAN13 manquant.'];
        $mapped = $this->mapper->to_wc_product_data($d, $gln);
        $pid    = $this->product_exists($ean13);
        if ( $pid ) {
            $this->update_product($pid, $mapped, $d);
            $action = 'mis à jour';
        } else {
            $r = $this->create_product($mapped, $d);
            if ( is_wp_error($r) ) return ['success'=>false,'product_id'=>null,'message'=>$r->get_error_message()];
            $pid = $r; $action = 'créé';
        }
        Hub_Logger::info('import/product', sprintf('Produit %s WC #%d (EAN13: %s)', $action, $pid, $ean13));
        return ['success'=>true,'product_id'=>(int)$pid,'message'=>sprintf('Produit %s (ID: %d)', $action, $pid)];
    }

    public function create_product( array $mapped, array $d ): int|\WP_Error {
        $product = new \WC_Product_Simple();
        $this->apply_wc_data($product, $mapped);
        $pid = $product->save();
        if (!$pid) return new \WP_Error('create_failed','Échec création produit WC.');
        $this->save_hub_meta($pid, $mapped['meta_data']);
        if (!empty($mapped['tags']))       wp_set_post_terms($pid, $mapped['tags'],        'product_tag');
        $this->import_cover_image($pid, $d['cover_url'] ?? '', $d['ean13'] ?? '');
        return $pid;
    }

    public function update_product( int $pid, array $mapped, array $d ): int|\WP_Error {
        $product = wc_get_product($pid);
        if (!$product) return new \WP_Error('not_found',"Produit WC #{$pid} introuvable.");
        $this->apply_wc_data($product, $mapped);
        $product->save();
        $this->save_hub_meta($pid, $mapped['meta_data']);

        $cover_url = $d['cover_url'] ?? '';
        if ( $cover_url ) {
            $this->import_cover_image( $pid, $cover_url, $d['ean13'] ?? '' );
        }
        return $pid;
    }

    private function apply_wc_data( \WC_Product $p, array $m ): void {
        $post = $m['product_data']; $wc = $m['wc_data'];
        $p->set_name($post['post_title']??'');
        $p->set_description($post['post_content']??'');
        $p->set_short_description($post['post_excerpt']??'');
        $p->set_status($post['post_status']??'publish');
        $p->set_slug($post['post_name']??'');
        if (!empty($wc['regular_price'])) { $p->set_regular_price($wc['regular_price']); $p->set_price($wc['regular_price']); }
        $p->set_downloadable((bool)($wc['downloadable']??false));
        $p->set_virtual((bool)($wc['virtual']??false));
        $p->set_manage_stock(false);
        $p->set_stock_status($wc['stock_status']??'instock');

        $tax_status = get_option( 'whd_import_tax_status', 'taxable' );
        $p->set_tax_status( $tax_status );
    }

    public function save_hub_meta( int $pid, array $meta ): void {
        foreach ($meta as $k => $v) update_post_meta($pid, $k, $v);
    }

    public function import_cover_image( int $pid, string $url, string $ean13 ): void {
        if ( empty( $url ) || empty( $ean13 ) ) {
            return;
        }
        $this->cover_service->import_for_product( $pid, $url, $ean13 );
    }

    public function product_exists( string $ean13 ): int {
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_hub_ean13' AND meta_value=%s LIMIT 1", $ean13
        ));
    }
}
