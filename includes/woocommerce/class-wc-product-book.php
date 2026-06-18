<?php
namespace WC_Hub_Dilicom\WooCommerce;
if ( ! defined( 'ABSPATH' ) ) exit;

class WC_Product_Book {
    public function init_hooks(): void {
        // Remplace l'URL de l'image placeholder par l'URL externe
        add_filter('wp_get_attachment_image_src', [$this, 'replace_placeholder_image'], 10, 4);
        // Remplace le HTML de l'image placeholder par celui de l'image externe (5 arguments)
        add_filter('wp_get_attachment_image',      [$this, 'replace_placeholder_image_html'], 10, 5);

        // Badge et onglet
        add_action('woocommerce_single_product_summary',     [$this,'display_badge'], 6);
        add_action('woocommerce_after_shop_loop_item_title', [$this,'display_badge'], 5);
        add_filter('woocommerce_product_tabs',               [$this,'add_book_details_tab']);
    }

    /**
     * Remplace l'URL du placeholder par l'URL externe.
     */
    public function replace_placeholder_image( $image, int $attachment_id, $size, bool $icon ) {
        $product_id = $this->get_product_id_by_placeholder( $attachment_id );
        if ( ! $product_id ) {
            return $image;
        }
        $external_url = get_post_meta( $product_id, '_hub_cover_url', true );
        if ( empty( $external_url ) ) {
            return $image;
        }
        return [ $external_url, 300, 400, false ];
    }

    /**
     * Remplace le HTML de l'image placeholder par une balise <img> avec l'URL externe.
     * Signature compatible WP 6.7+ (5 arguments : $html, $attachment_id, $size, $icon, $attr).
     */
    public function replace_placeholder_image_html( string $html, int $attachment_id, $size, bool $icon, array $attr ): string {
        $product_id = $this->get_product_id_by_placeholder( $attachment_id );
        if ( ! $product_id ) {
            return $html;
        }
        $external_url = get_post_meta( $product_id, '_hub_cover_url', true );
        if ( empty( $external_url ) ) {
            return $html;
        }
        $alt = get_the_title( $product_id );
        return sprintf(
            '<img src="%s" alt="%s" class="attachment-%s size-%s wp-post-image hub-external-cover" loading="lazy" />',
            esc_url( $external_url ),
            esc_attr( $alt ),
            esc_attr( $size ),
            esc_attr( $size )
        );
    }

    /**
     * Retourne l'ID du produit associé à un attachment placeholder.
     */
    private function get_product_id_by_placeholder( int $attachment_id ): int {
        $cache_key = 'whd_placeholder_' . $attachment_id;
        $product_id = wp_cache_get( $cache_key, 'whd' );
        if ( false === $product_id ) {
            global $wpdb;
            $product_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_hub_placeholder_attachment_id' AND meta_value = %d LIMIT 1",
                $attachment_id
            ) );
            wp_cache_set( $cache_key, $product_id, 'whd', 3600 );
        }
        return $product_id;
    }

    // Les autres méthodes (display_badge, add_book_details_tab, render_book_details_tab, etc.) restent inchangées.
    // … [je les inclus ci-dessous pour le fichier complet]
    public function display_badge(): void {
        global $product; if (!$product) return;
        $b = $this->get_badge($product->get_id()); if ($b) echo $b;
    }

    public function add_book_details_tab( array $tabs ): array {
        global $product; if (!$product) return $tabs;
        if (!get_post_meta($product->get_id(),'_hub_ean13',true)) return $tabs;
        $tabs['hub_book_details'] = ['title'=>__('Détails du livre','wc-hub-dilicom'),'priority'=>25,'callback'=>[$this,'render_book_details_tab']];
        return $tabs;
    }

    public function render_book_details_tab(): void {
        global $product; $id = $product->get_id();
        $dt = get_post_meta($id,'_hub_publication_date',true);
        if (8===strlen($dt)) $dt = \DateTime::createFromFormat('Ymd',$dt)?->format('d/m/Y') ?: $dt;

        $contributors_json = get_post_meta($id, '_hub_contributors_json', true);
        $author_names = [];
        if ($contributors_json) {
            $contributors = json_decode($contributors_json, true);
            if (is_array($contributors)) {
                foreach ($contributors as $c) {
                    if (($c['role_code'] ?? '') === 'A01') {
                        $author_names[] = $c['name'] ?? '';
                    }
                }
            }
        }
        $author_str = $author_names ? implode(', ', $author_names) : '—';

        $fields = [
            __('EAN13','wc-hub-dilicom')      => get_post_meta($id,'_hub_ean13',true),
            __('ISBN-13','wc-hub-dilicom')     => get_post_meta($id,'_hub_isbn13',true),
            __('Auteur(s)','wc-hub-dilicom')   => $author_str,
            __('Éditeur','wc-hub-dilicom')     => get_post_meta($id,'_hub_publisher',true),
            __('Publication','wc-hub-dilicom') => $dt,
            __('Langue','wc-hub-dilicom')      => strtoupper((string)get_post_meta($id,'_hub_language',true)),
            __('Pages','wc-hub-dilicom')       => get_post_meta($id,'_hub_page_count',true),
            __('Format ONIX','wc-hub-dilicom') => get_post_meta($id,'_hub_onix_form',true),
        ];

        echo '<table class="shop_attributes">';
        foreach ($fields as $label => $value) {
            if (empty($value) && $value !== '0') continue;
            printf('<tr><th>%s</th><td>%s</td></tr>', esc_html($label), esc_html((string)$value));
        }
        echo '</table>';
    }

    public static function is_hub_book( int $pid ): bool {
        return !empty(get_post_meta($pid,'_hub_ean13',true));
    }

    public static function get_book_type( int $pid ): string {
        return (string)get_post_meta($pid,'_hub_book_type',true);
    }

    private function get_badge( int $pid ): string {
        $t = self::get_book_type($pid);
        if ('digital'  === $t) return '<span class="hub-badge hub-badge--digital">📱 Numérique</span>';
        if ('physical' === $t) return '<span class="hub-badge hub-badge--physical">📖 Physique</span>';
        return '';
    }
}