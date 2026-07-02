<?php
/**
 * Plugin Name:       WC Hub Dilicom
 * Plugin URI:        https://github.com/
 * Description:       Connecte WooCommerce au HUB Dilicom (ONIX).
 * Version:           3.0.2
 * Author:            Xavier Burlot
 * Text Domain:       wc-hub-dilicom
 * Domain Path:       /languages
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * WC requires at least: 8.0
 *
 * @package WC_Hub_Dilicom
 */

defined( 'ABSPATH' ) || exit;

define( 'WHD_VERSION', '3.0.0' );
define( 'WHD_FILE',    __FILE__ );
define( 'WHD_PATH',    plugin_dir_path( __FILE__ ) );
define( 'WHD_URL',     plugin_dir_url( __FILE__ ) );

// ── Fix textdomain esig/woocommerce (intégré, pas de mu-plugin séparé) ──────
add_action( 'init', static function() {
    foreach ( [ 'esig', 'woocommerce' ] as $domain ) {
        unload_textdomain( $domain );
        load_plugin_textdomain( $domain, false, WP_PLUGIN_DIR . '/' . $domain . '/languages' );
    }
}, 1 );

// ── Charge un fichier avec notice admin si manquant ──────────────────────────
function whd_require( string $file ): bool {
    if ( file_exists( $file ) ) {
        require_once $file;
        return true;
    }
    add_action( 'admin_notices', static function() use ( $file ) {
        echo '<div class="notice notice-error"><p><strong>WC Hub Dilicom :</strong> Fichier manquant : '
            . esc_html( str_replace( ABSPATH, '', $file ) ) . '</p></div>';
    } );
    return false;
}

// ── Activation — AUCUNE classe externe ───────────────────────────────────────
register_activation_hook( __FILE__, 'whd_activate' );
register_deactivation_hook( __FILE__, 'whd_deactivate' );

function whd_activate(): void {
    global $wpdb;
    $c = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}hub_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        level VARCHAR(10) NOT NULL DEFAULT 'info',
        endpoint VARCHAR(255) NOT NULL DEFAULT '',
        message TEXT NOT NULL,
        return_code VARCHAR(50) DEFAULT NULL,
        context LONGTEXT DEFAULT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id), KEY idx_level(level), KEY idx_created(created_at)
    ) $c;" );
    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}hub_import_queue (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ean13 VARCHAR(20) NOT NULL,
        gln_distributor VARCHAR(20) NOT NULL DEFAULT '',
        status VARCHAR(10) NOT NULL DEFAULT 'pending',
        error TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id), UNIQUE KEY ean13(ean13), KEY idx_status(status)
    ) $c;" );
    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}hub_order_lines (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id BIGINT UNSIGNED NOT NULL,
        order_item_id BIGINT UNSIGNED NOT NULL,
        ean13 VARCHAR(20) NOT NULL,
        gln_distributor VARCHAR(20) NOT NULL,
        pnb_order_id VARCHAR(100) DEFAULT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id), KEY idx_order(order_id), KEY idx_status(status)
    ) $c;" );
    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}hub_cover_queue (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id BIGINT UNSIGNED NOT NULL,
        ean13 VARCHAR(20) NOT NULL DEFAULT '',
        cover_url TEXT NOT NULL,
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        last_error TEXT DEFAULT NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY product_id (product_id),
        KEY idx_status (status)
    ) $c;" );
    $wpdb->insert( $wpdb->prefix . 'hub_logs', [
        'level' => 'info', 'endpoint' => 'plugin/activate',
        'message' => 'WC Hub Dilicom v3.0.0 activé.', 'created_at' => current_time('mysql'),
    ] );
}

function whd_deactivate(): void {
    wp_clear_scheduled_hook( 'whd_daily_sync' );
    wp_clear_scheduled_hook( 'whd_price_sync' );
    wp_clear_scheduled_hook( 'whd_continue_parse' );
    wp_clear_scheduled_hook( 'whd_continue_light_cache' );
    wp_clear_scheduled_hook( 'whd_process_cover_queue' );
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', 'whd_init', 5 );

function whd_init(): void {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', static function () {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'WC Hub Dilicom nécessite WooCommerce actif.', 'wc-hub-dilicom' ) . '</p></div>';
        } );
        return;
    }

    // Namespaced classes
    use_namespaces_setup();

    whd_require( WHD_PATH . 'includes/class-hub-logger.php' );
    whd_require( WHD_PATH . 'includes/api/class-hub-api-client.php' );
    whd_require( WHD_PATH . 'includes/api/class-hub-api-catalog.php' );
    whd_require( WHD_PATH . 'includes/api/class-hub-api-availability.php' );
    whd_require( WHD_PATH . 'includes/api/class-hub-api-orders.php' );
    whd_require( WHD_PATH . 'includes/onix/class-onix-parser.php' );
    whd_require( WHD_PATH . 'includes/onix/class-onix-mapper.php' );
    whd_require( WHD_PATH . 'includes/import/class-import-filter.php' );
    whd_require( WHD_PATH . 'includes/import/class-cover-queue.php' );
    whd_require( WHD_PATH . 'includes/import/class-cover-image-service.php' );
    whd_require( WHD_PATH . 'includes/import/class-book-importer.php' );
    whd_require( WHD_PATH . 'includes/import/class-bulk-importer.php' );

    if ( class_exists( 'WC_Hub_Dilicom\\Import\\Cover_Queue' ) ) {
        WC_Hub_Dilicom\Import\Cover_Queue::install_table();
    }
    whd_require( WHD_PATH . 'includes/woocommerce/class-wc-product-book.php' );
    whd_require( WHD_PATH . 'includes/woocommerce/class-wc-cart-handler.php' );
    whd_require( WHD_PATH . 'includes/woocommerce/class-wc-checkout-handler.php' );
    whd_require( WHD_PATH . 'includes/woocommerce/class-wc-order-handler.php' );
    whd_require( WHD_PATH . 'includes/woocommerce/class-wc-download-handler.php' );
    whd_require( WHD_PATH . 'includes/woocommerce/class-wc-shortcode-hub-products.php' );

    // Synchronisation des prix
    whd_require( WHD_PATH . 'includes/sync/class-price-sync.php' );

    if ( is_admin() ) {
        whd_require( WHD_PATH . 'admin/class-admin-settings.php' );
        whd_require( WHD_PATH . 'admin/class-admin-orders.php' );
        whd_require( WHD_PATH . 'admin/class-whd-admin.php' );
        if ( class_exists( 'WHD_Admin' ) ) new WHD_Admin();
    }

    whd_require( WHD_PATH . 'includes/class-whd-ajax.php' );
    if ( class_exists( 'WHD_Ajax' ) ) new WHD_Ajax();

    // Instanciation avec namespaces corrects
    $ns_wc = 'WC_Hub_Dilicom\\WooCommerce\\';
    foreach ( ['WC_Product_Book','WC_Cart_Handler','WC_Checkout_Handler','WC_Order_Handler','WC_Download_Handler'] as $cls ) {
        $fqcn = $ns_wc . $cls;
        if ( class_exists( $fqcn ) ) ( new $fqcn() )->init_hooks();
    }

    // Cron quotidien (import nouvelles notices)
    if ( ! wp_next_scheduled( 'whd_daily_sync' ) ) {
        wp_schedule_event( time(), 'daily', 'whd_daily_sync' );
    }

    // Cron de synchronisation des prix et disponibilités (toutes les 6 heures)
    if ( ! wp_next_scheduled( 'whd_price_sync' ) ) {
        wp_schedule_event( time(), 'six_hours', 'whd_price_sync' );
    }

    if ( ! wp_next_scheduled( 'whd_process_cover_queue' ) ) {
        wp_schedule_event( time() + 60, 'five_minutes', 'whd_process_cover_queue' );
    }

    // Shortcode d'affichage des produits HUB
    if ( ! is_admin() || wp_doing_ajax() ) {
        new WC_Hub_Dilicom\WooCommerce\WC_Shortcode_Hub_Products();
    }
}

function use_namespaces_setup(): void {} // placeholder pour clarté

add_action( 'whd_daily_sync', static function () {
    $cls = 'WC_Hub_Dilicom\\Hub_Logger';
    if ( class_exists( $cls ) ) $cls::info( 'cron/daily', 'Synchronisation quotidienne démarrée.' );
} );

// Action pour le cron de synchronisation des prix
add_action( 'whd_price_sync', [ 'WC_Hub_Dilicom\\Sync\\Price_Sync', 'run' ] );

add_filter( 'cron_schedules', static function ( array $schedules ): array {
    if ( ! isset( $schedules['five_minutes'] ) ) {
        $schedules['five_minutes'] = [
            'interval' => 300,
            'display'  => __( 'Every 5 minutes', 'wc-hub-dilicom' ),
        ];
    }
    return $schedules;
} );

add_action( 'whd_process_cover_queue', static function () {
    if ( ! class_exists( 'WC_Hub_Dilicom\\Import\\Cover_Queue' ) ) {
        return;
    }
    $queue = new WC_Hub_Dilicom\Import\Cover_Queue();
    $result = $queue->process_batch();
    if ( $result['remaining'] > 0 && ! wp_next_scheduled( 'whd_process_cover_queue' ) ) {
        wp_schedule_single_event( time() + 60, 'whd_process_cover_queue' );
    }
} );

// Action pour le parsing en arrière‑plan (appelée par le cron)
add_action( 'whd_continue_parse', function() {
    if ( class_exists( 'WHD_Ajax' ) ) {
        $ajax = new WHD_Ajax();
        if ( method_exists( $ajax, '_parse_full_catalog_background' ) ) {
            $ajax->_parse_full_catalog_background();
        }
    }
} );

// Action pour la régénération du cache allégé en arrière‑plan (appelée par le cron)
add_action( 'whd_continue_light_cache', function() {
    if ( class_exists( 'WHD_Ajax' ) ) {
        $ajax = new WHD_Ajax();
        if ( method_exists( $ajax, '_regenerate_light_cache_bg' ) ) {
            $ajax->_regenerate_light_cache_bg();
        }
    }
} );