<?php
defined('ABSPATH') || exit;

use WC_Hub_Dilicom\Admin\Admin_Settings;

class WHD_Admin {
    private Admin_Settings $settings;

    public function __construct() {
        $this->settings = new Admin_Settings();
        add_action('admin_menu',            [$this, 'register_menus']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function register_menus(): void {
        add_menu_page(
            __('Hub Dilicom','wc-hub-dilicom'),
            __('Hub Dilicom','wc-hub-dilicom'),
            'manage_woocommerce',
            'wc-hub-dilicom',
            [$this,'page_dashboard'],
            'dashicons-book-alt',
            56
        );
        add_submenu_page('wc-hub-dilicom', __('Tableau de bord','wc-hub-dilicom'), __('Tableau de bord','wc-hub-dilicom'), 'manage_woocommerce', 'wc-hub-dilicom',  [$this,'page_dashboard']);
        
        add_submenu_page('wc-hub-dilicom', __('Import groupé', 'wc-hub-dilicom'), __('Import groupé', 'wc-hub-dilicom'), 'manage_woocommerce', 'whd-import',    [$this,'page_import']);
        add_submenu_page('wc-hub-dilicom', __('Commandes Hub', 'wc-hub-dilicom'), __('Commandes Hub', 'wc-hub-dilicom'), 'manage_woocommerce', 'whd-orders',    [$this,'page_orders']);
        add_submenu_page('wc-hub-dilicom', __('Logs',          'wc-hub-dilicom'), __('Logs',          'wc-hub-dilicom'), 'manage_woocommerce', 'whd-logs',      [$this,'page_logs']);
        add_submenu_page('wc-hub-dilicom', __('Réglages',      'wc-hub-dilicom'), __('Réglages',      'wc-hub-dilicom'), 'manage_woocommerce', 'whd-settings',  [$this,'page_settings']);
        // Nouveau sous-menu pour la synchronisation manuelle des prix
        add_submenu_page('wc-hub-dilicom', __('Synchro prix',  'wc-hub-dilicom'), __('Synchro prix',  'wc-hub-dilicom'), 'manage_woocommerce', 'whd-sync',      [$this,'page_sync']);
    }

    public function enqueue_assets(string $hook): void {
        $pages = [
            'toplevel_page_wc-hub-dilicom',
            'hub-dilicom_page_whd-catalog',
            'hub-dilicom_page_whd-import',
            'hub-dilicom_page_whd-orders',
            'hub-dilicom_page_whd-logs',
            'hub-dilicom_page_whd-settings',
            'hub-dilicom_page_whd-sync', // ajout de la nouvelle page
        ];
        if (!in_array($hook, $pages, true)) return;

        wp_enqueue_style('whd-admin', WHD_URL.'admin/assets/css/admin.css', [], WHD_VERSION);

        $nonce   = wp_create_nonce('whd_admin_nonce');
        $ajaxurl = admin_url('admin-ajax.php');

        // Gestion spécifique pour la page Import (comme avant)
        if ( false !== strpos( $hook, 'whd-import' ) ) {
            add_action('admin_footer', function() use ($ajaxurl, $nonce) {
                ?>
                <script type="text/javascript">
                var whdImport = {
                    ajaxurl: '<?php echo $ajaxurl; ?>',
                    nonce: '<?php echo $nonce; ?>',
                    adminUrl: '<?php echo admin_url("post.php"); ?>'
                };
                </script>
                
                <?php
            });
        } else {
            // Chargement normal pour les autres pages
            wp_enqueue_script('whd-admin',    WHD_URL.'admin/assets/js/admin.js',    ['jquery'], WHD_VERSION, true);
            wp_enqueue_script('whd-catalog',  WHD_URL.'admin/assets/js/catalog.js',  ['jquery','whd-admin'], WHD_VERSION, true);
            wp_enqueue_script('whd-settings', WHD_URL.'admin/assets/js/settings.js', ['jquery'], WHD_VERSION, true);

            $common = [
                'ajaxurl'  => $ajaxurl,
                'nonce'    => $nonce,
                'adminUrl' => admin_url('post.php'),
            ];
            wp_localize_script('whd-admin',    'whdAdmin',    $common);
            wp_localize_script('whd-catalog',  'whdCatalog',  $common);
            wp_localize_script('whd-settings', 'whdSettings', [
                'ajaxurl' => $ajaxurl,
                'nonce'   => $nonce,
            ]);
        }
    }

    // Pages existantes
    public function page_dashboard(): void { include WHD_PATH.'admin/views/dashboard.php'; }
    public function page_catalog():   void { include WHD_PATH.'admin/views/catalog.php'; }
    public function page_import():    void { include WHD_PATH.'admin/views/import.php'; }
    public function page_orders():    void { include WHD_PATH.'admin/views/orders.php'; }
    public function page_logs():      void { include WHD_PATH.'admin/views/logs.php'; }
    public function page_settings():  void { include WHD_PATH.'admin/views/settings.php'; }

    // Nouvelle page pour la synchronisation des prix
    public function page_sync(): void {
        $synced = false;
        $message = '';

        if ( isset($_GET['do_sync']) && wp_verify_nonce($_GET['_wpnonce'], 'whd_manual_sync') ) {
            if ( class_exists('WC_Hub_Dilicom\\Sync\\Price_Sync') ) {
                \WC_Hub_Dilicom\Sync\Price_Sync::run();
                $synced = true;
                $message = __('Synchronisation des prix terminée. Consultez les logs pour les détails.', 'wc-hub-dilicom');
            } else {
                $message = __('La classe de synchronisation est introuvable.', 'wc-hub-dilicom');
            }
        }

        ?>
        <div class="wrap whd-wrap">
            <h1 class="whd-page-title">
                <span class="dashicons dashicons-update"></span>
                <?php esc_html_e( 'Synchronisation des prix HUB', 'wc-hub-dilicom' ); ?>
            </h1>
            <div class="whd-card">
                <p><?php esc_html_e( 'Cette opération met à jour les prix, les disponibilités et les métadonnées de tous les produits HUB présents dans votre boutique, en interrogeant le HUB Dilicom pour chaque livre.', 'wc-hub-dilicom' ); ?></p>
                <p><?php esc_html_e( 'Le processus peut prendre quelques minutes. Une fois lancé, veuillez patienter.', 'wc-hub-dilicom' ); ?></p>

                <?php if ( $synced ) : ?>
                <div class="notice notice-success"><p><?php echo esc_html( $message ); ?></p></div>
                <?php elseif ( $message ) : ?>
                <div class="notice notice-error"><p><?php echo esc_html( $message ); ?></p></div>
                <?php endif; ?>

                <a href="<?php echo wp_nonce_url( admin_url('admin.php?page=whd-sync&do_sync=1'), 'whd_manual_sync' ); ?>" class="button button-primary button-large">
                    <span class="dashicons dashicons-cloud-download"></span>
                    <?php esc_html_e( 'Lancer la synchronisation maintenant', 'wc-hub-dilicom' ); ?>
                </a>
            </div>
        </div>
        <?php
    }
}