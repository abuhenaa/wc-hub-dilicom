<?php
namespace WC_Hub_Dilicom\Import;
if ( ! defined( 'ABSPATH' ) ) exit;

class Import_Filter {
    public function apply( array $notices, array $filters ): array {
        if ( empty($notices) || empty($filters) ) return $notices;
        $type  = $filters['type'] ?? 'all';
        $pmin  = isset($filters['price_min']) ? (int)$filters['price_min'] : null;
        $pmax  = isset($filters['price_max']) ? (int)$filters['price_max'] : null;
        $cats  = $filters['categories'] ?? [];
        $gln   = $filters['gln_distributor'] ?? '';
        $avail = (bool)($filters['availability'] ?? false);
        foreach ( $notices as $ean => $n ) {
            if ( 'all' !== $type && !$this->filter_by_type($n,$type) )                     { unset($notices[$ean]); continue; }
            if ( (null!==$pmin||null!==$pmax) && !$this->filter_by_price($n,$pmin,$pmax) ) { unset($notices[$ean]); continue; }
            if ( !empty($cats) && !$this->filter_by_category($n,$cats) )                   { unset($notices[$ean]); continue; }
            if ( !empty($gln)  && !$this->filter_by_distributor($n,$gln) )                 { unset($notices[$ean]); continue; }
            if ( $avail        && !$this->filter_by_availability($n) )                      { unset($notices[$ean]); }
        }
        return $notices;
    }
    public function filter_by_type( array $n, string $type ): bool { return ($n['book_type']??'') === $type; }
    public function filter_by_price( array $n, ?int $min, ?int $max ): bool {
        $p = (int)($n['unit_price']??0);
        if (null !== $min && $p < $min) return false;
        if (null !== $max && $p > $max) return false;
        return true;
    }
    public function filter_by_category( array $n, array $cats ): bool {
        $cl = array_map('strtolower',$cats);
        foreach ($n['subjects']??[] as $s) {
            $code = strtolower($s['code']??''); $head = strtolower($s['heading_text']??'');
            foreach ($cl as $c) if (str_contains($code,$c)||str_contains($head,$c)) return true;
        }
        return false;
    }
    public function filter_by_distributor( array $n, string $gln ): bool { return ($n['gln_distributor']??'') === $gln; }
    public function filter_by_availability( array $n ): bool {
        $a = (string)($n['product_availability']??'');
        return empty($a) || !in_array($a,['01','02','03','04'],true);
    }
    public static function sanitize( array $raw ): array {
        return [
            'type'           => in_array($raw['type']??'all',['all','digital','physical'],true) ? $raw['type'] : 'all',
            'price_min'      => isset($raw['price_min']) ? abs((int)$raw['price_min']) : null,
            'price_max'      => isset($raw['price_max']) ? abs((int)$raw['price_max']) : null,
            'categories'     => array_map('sanitize_text_field',(array)($raw['categories']??[])),
            'gln_distributor'=> sanitize_text_field($raw['gln_distributor']??''),
            'availability'   => !empty($raw['availability']),
        ];
    }
}
