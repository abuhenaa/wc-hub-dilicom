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

    public function __construct( ?Hub_Api_Catalog $c=null, ?Onix_Parser $p=null, ?Onix_Mapper $m=null ) {
        $this->catalog_api = $c ?? new Hub_Api_Catalog();
        $this->parser      = $p ?? new Onix_Parser();
        $this->mapper      = $m ?? new Onix_Mapper();
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
        // Catégories : plus d'assignation
        if (!empty($mapped['tags']))       wp_set_post_terms($pid, $mapped['tags'],        'product_tag');
        $this->set_external_image($pid, $d['cover_url']??'');
        return $pid;
    }

    public function update_product( int $pid, array $mapped, array $d ): int|\WP_Error {
        $product = wc_get_product($pid);
        if (!$product) return new \WP_Error('not_found',"Produit WC #{$pid} introuvable.");
        $this->apply_wc_data($product, $mapped);
        $product->save();
        $this->save_hub_meta($pid, $mapped['meta_data']);
        // Catégories : plus d'assignation (ni mise à jour)
        $new = $d['cover_url']??''; $old = get_post_meta($pid,'_hub_cover_url',true);
        if ($new && $new !== $old) $this->set_external_image($pid, $new);
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

        // Statut de taxe défini dans les réglages du plugin
        $tax_status = get_option( 'whd_import_tax_status', 'taxable' );
        $p->set_tax_status( $tax_status );
    }

    public function save_hub_meta( int $pid, array $meta ): void {
        foreach ($meta as $k => $v) update_post_meta($pid, $k, $v);
    }

   public function set_external_image( int $pid, string $url ): void {
    if ( empty( $url ) ) return;

    // Si déjà un placeholder avec la même URL, on ne fait rien
    $existing_url = get_post_meta( $pid, '_hub_cover_url', true );
    if ( $existing_url === $url && get_post_meta( $pid, '_hub_placeholder_attachment_id', true ) ) {
        return;
    }

    // Créer un placeholder 1x1 si nécessaire
    $placeholder_id = get_post_meta( $pid, '_hub_placeholder_attachment_id', true );
    if ( ! $placeholder_id ) {
        $placeholder_id = $this->create_placeholder_image( $pid );
        if ( ! $placeholder_id ) return;
    }

    // Définir l'image à la une avec le placeholder
    set_post_thumbnail( $pid, $placeholder_id );
    update_post_meta( $pid, '_hub_cover_url', esc_url_raw( $url ) );
    update_post_meta( $pid, '_hub_placeholder_attachment_id', $placeholder_id );
}

private function create_placeholder_image( int $product_id ): int {
    $placeholder_path = WHD_PATH . 'assets/img/placeholder.png';
    if ( ! file_exists( $placeholder_path ) ) {
        wp_mkdir_p( dirname( $placeholder_path ) );
        $im = imagecreatetruecolor(1, 1);
        imagesavealpha($im, true);
        $transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
        imagefill($im, 0, 0, $transparent);
        imagepng($im, $placeholder_path);
        imagedestroy($im);
    }

    $file_array = [
        'name'     => 'placeholder.png',
        'tmp_name' => $placeholder_path,
    ];
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $attachment_id = media_handle_sideload( $file_array, $product_id );
    if ( is_wp_error( $attachment_id ) ) {
        Hub_Logger::warning( 'import/image', "Placeholder creation failed: " . $attachment_id->get_error_message() );
        return 0;
    }
    return $attachment_id;
}

    public function product_exists( string $ean13 ): int {
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_hub_ean13' AND meta_value=%s LIMIT 1", $ean13
        ));
    }
}