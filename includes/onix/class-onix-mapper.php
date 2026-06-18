<?php
namespace WC_Hub_Dilicom\Onix;
use WC_Hub_Dilicom\Hub_Logger;
if ( ! defined( 'ABSPATH' ) ) exit;

class Onix_Mapper {
    const AVAILABLE_CODES = ['20','21','22','23','30','40','41','42','43','44','45'];

    public function to_wc_product_data( array $d, string $gln = '' ): array {
        $price  = (int)($d['unit_price']??0);
        $pfloat = $price>0 ? round($price/100,2) : 0;
        $name   = $d['title']??'';
        if (!empty($d['subtitle'])) $name .= ' — ' . $d['subtitle'];
        $slug   = sanitize_title($name) . ($d['ean13'] ? '-'.$d['ean13'] : '');
        $is_dig = 'digital' === ($d['book_type']??'');
        return [
            'product_data' => ['post_title'=>$name,'post_content'=>$d['description']??'','post_excerpt'=>wp_trim_words(wp_strip_all_tags($d['description']??''),30),'post_status'=>'publish','post_type'=>'product','post_name'=>$slug],
            'wc_data'      => ['regular_price'=>$pfloat>0?(string)$pfloat:'','price'=>$pfloat>0?(string)$pfloat:'','downloadable'=>$is_dig,'virtual'=>$is_dig,'manage_stock'=>false,'stock_status'=>$this->map_stock_status($d['product_availability']??'')],
            'meta_data'    => $this->build_meta_data($d,$gln),
            'categories'   => [],   // plus d'import de catégories dans WC
            'tags'         => $this->map_tags($d),
        ];
    }

    public function build_meta_data( array $d, string $gln ): array {
        return [
            '_hub_ean13'                => sanitize_text_field($d['ean13']??''),
            '_hub_gln_distributor'      => sanitize_text_field($gln),
            '_hub_book_type'            => in_array($d['book_type']??'',['digital','physical'],true)?$d['book_type']:'physical',
            '_hub_cover_url'            => esc_url_raw($d['cover_url']??''),
            '_hub_onix_form'            => sanitize_text_field($d['product_form']??''),
            '_hub_format_label'         => sanitize_text_field($d['format_label']??$this->map_product_form_label($d['product_form']??'')),
            '_hub_unit_price'           => (int)($d['unit_price']??0),
            '_hub_currency'             => sanitize_text_field($d['currency']??'EUR'),
            '_hub_product_availability' => sanitize_text_field($d['product_availability']??''),
            '_hub_last_sync'            => current_time('mysql',true),
            '_hub_isbn13'               => sanitize_text_field($d['isbn13']??''),
            '_hub_publisher'            => sanitize_text_field($d['publisher']??''),
            '_hub_publication_date'     => sanitize_text_field($d['publication_date']??''),
            '_hub_language'             => sanitize_text_field($d['language']??''),
            '_hub_page_count'           => (int)($d['page_count']??0),
            '_hub_product_form_detail'  => sanitize_text_field($d['product_form_detail']??''),
            '_hub_protection'           => sanitize_text_field($d['protection'] ?? ''),
            '_hub_usage_limits'         => wp_json_encode($d['usage_limits'] ?? []),
            '_hub_collections'          => implode(', ', $d['collections'] ?? []),
            '_hub_contributors_json'    => wp_json_encode($d['contributors'] ?? [], JSON_UNESCAPED_UNICODE),
        ];
    }

    public function map_stock_status( string $code ): string {
        if ( empty($code) ) return 'instock';
        if ( in_array($code,['10','11','12'],true) ) return 'onbackorder';
        return in_array($code,self::AVAILABLE_CODES,true) ? 'instock' : 'outofstock';
    }

    public function map_categories( array $subjects ): array {
        $ids = [];
        foreach ($subjects as $s) {
            $name = $s['heading_text']??$s['code']??'';
            if (empty($name)) continue;
            $term = get_term_by('name',$name,'product_cat');
            if ($term && !is_wp_error($term)) { $ids[] = (int)$term->term_id; continue; }
            $res  = wp_insert_term($name,'product_cat',['description'=>$s['scheme_name']??'']);
            if (!is_wp_error($res)) $ids[] = (int)$res['term_id'];
            else Hub_Logger::warning('onix/mapper','Catégorie non créée : '.$res->get_error_message());
        }
        return array_unique($ids);
    }

    public function map_tags( array $d ): array {
        $tags  = array_column($d['contributors']??[],'name');
        $langs = ['fre'=>'Français','fra'=>'Français','eng'=>'Anglais','spa'=>'Espagnol','ger'=>'Allemand','ita'=>'Italien'];
        if (!empty($d['language'])) $tags[] = $langs[$d['language']]??strtoupper($d['language']);
        if ('digital'===($d['book_type']??'')) $tags[] = 'Livre numérique';
        return array_unique(array_filter($tags));
    }

    public function map_product_form_label( string $form ): string {
        $l = ['E101'=>'EPUB','E107'=>'PDF','E127'=>'Audio','E130'=>'HTML','ED'=>'Numérique','BA'=>'Broché','BB'=>'Relié','BC'=>'Broché','BD'=>'Spirale'];
        return $l[$form]??$form;
    }

    public function is_importable( string $code ): bool {
        return ! in_array($code,['01','02','03','04'],true);
    }
}