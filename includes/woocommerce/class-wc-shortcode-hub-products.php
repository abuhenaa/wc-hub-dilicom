<?php
namespace WC_Hub_Dilicom\WooCommerce;
use WC_Hub_Dilicom\Hub_Logger;
if ( ! defined( 'ABSPATH' ) ) exit;

class WC_Shortcode_Hub_Products {

    public function __construct() {
        add_shortcode( 'hub_products', [ $this, 'render' ] );
    }

    /**
     * [hub_products]
     * Attributs :
     *   author       – nom partiel de l’auteur (insensible à la casse)
     *   publisher    – nom partiel de l’éditeur
     *   type         – digital | physical
     *   max_price    – prix maximum en euros (ex: 5)
     *   min_price    – prix minimum en euros
     *   per_page     – nombre de produits par page (défaut 12)
     *   columns      – nombre de colonnes (défaut 4)
     *   orderby      – price | date | title (défaut date)
     *   order        – ASC | DESC (défaut DESC)
     */
    public function render( array $atts ): string {
        $atts = shortcode_atts( [
            'author'    => '',
            'publisher' => '',
            'type'      => '',
            'max_price' => '',
            'min_price' => '',
            'per_page'  => 12,
            'columns'   => 4,
            'orderby'   => 'date',
            'order'     => 'DESC',
        ], $atts, 'hub_products' );

        // 1. Récupérer tous les produits HUB (ayant un EAN13) via WP_Query
        $meta_query = [
            [
                'key'     => '_hub_ean13',
                'compare' => 'EXISTS',
            ],
        ];

        // Filtres supplémentaires par métadonnées simples
        if ( $atts['type'] ) {
            $meta_query[] = [
                'key'   => '_hub_book_type',
                'value' => $atts['type'],
            ];
        }
        if ( $atts['publisher'] ) {
            $meta_query[] = [
                'key'     => '_hub_publisher',
                'value'   => $atts['publisher'],
                'compare' => 'LIKE',
            ];
        }
        // Prix en centimes
        if ( '' !== $atts['max_price'] ) {
            $meta_query[] = [
                'key'     => '_hub_unit_price',
                'value'   => (int)( (float)$atts['max_price'] * 100 ),
                'compare' => '<=',
                'type'    => 'NUMERIC',
            ];
        }
        if ( '' !== $atts['min_price'] ) {
            $meta_query[] = [
                'key'     => '_hub_unit_price',
                'value'   => (int)( (float)$atts['min_price'] * 100 ),
                'compare' => '>=',
                'type'    => 'NUMERIC',
            ];
        }

        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => (int) $atts['per_page'],
            'orderby'        => $atts['orderby'],
            'order'          => $atts['order'],
            'meta_query'     => $meta_query,
            'fields'         => 'ids',
            // Pagination gérée par WooCommerce
            'paged'          => get_query_var( 'paged' ) ? get_query_var( 'paged' ) : 1,
        ];

        // Si orderby est 'price', il faut utiliser meta_key _price
        if ( 'price' === $atts['orderby'] ) {
            $args['meta_key'] = '_price';
            $args['orderby']  = 'meta_value_num';
        }

        $query = new \WP_Query( $args );
        $product_ids = $query->posts;

        // Si filtre par auteur, on doit filtrer manuellement car le champ est JSON
        if ( $atts['author'] ) {
            $author = mb_strtolower( $atts['author'] );
            $filtered = [];
            foreach ( $product_ids as $pid ) {
                $json = get_post_meta( $pid, '_hub_contributors_json', true );
                if ( ! $json ) continue;
                $contributors = json_decode( $json, true );
                if ( ! is_array( $contributors ) ) continue;
                foreach ( $contributors as $c ) {
                    if ( stripos( $c['name'] ?? '', $author ) !== false ) {
                        $filtered[] = $pid;
                        break;
                    }
                }
            }
            $product_ids = $filtered;
        }

        if ( empty( $product_ids ) ) {
            return '<p>' . esc_html__( 'Aucun produit trouvé.', 'wc-hub-dilicom' ) . '</p>';
        }

        // 2. Utiliser le shortcode WooCommerce [products] avec les IDs
        $ids = implode( ',', $product_ids );
        $shortcode = sprintf(
            '[products ids="%s" columns="%d" limit="%d" paginate="true" orderby="post__in"]',
            $ids,
            (int) $atts['columns'],
            (int) $atts['per_page']
        );

        return do_shortcode( $shortcode );
    }
}