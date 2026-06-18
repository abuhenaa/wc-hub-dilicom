<?php
/**
 * Template email — Liens de téléchargement livres numériques
 * Variables disponibles : $o (WC_Order), $links (array)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$first_name  = esc_html( $o->get_billing_first_name() );
$site_name   = esc_html( get_bloginfo( 'name' ) );
$site_url    = esc_url( home_url() );
$order_id    = $o->get_id();
$order_url   = esc_url( $o->get_view_order_url() );
$text_color  = '#1d2327';
$accent      = '#2271b1';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php printf( esc_html__( 'Vos livres numériques — Commande #%d', 'wc-hub-dilicom' ), $order_id ); ?></title>
</head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif;color:<?php echo $text_color; ?>;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:30px 0;">
    <tr>
        <td align="center">
            <table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);">

                <!-- Header -->
                <tr>
                    <td style="background:<?php echo $accent; ?>;padding:28px 32px;">
                        <h1 style="margin:0;color:#fff;font-size:22px;font-weight:700;">
                            📚 <?php esc_html_e( 'Vos livres numériques sont prêts !', 'wc-hub-dilicom' ); ?>
                        </h1>
                        <p style="margin:8px 0 0;color:rgba(255,255,255,.85);font-size:14px;">
                            <?php echo esc_html( $site_name ); ?>
                        </p>
                    </td>
                </tr>

                <!-- Corps -->
                <tr>
                    <td style="padding:32px;">
                        <p style="font-size:16px;margin:0 0 16px;">
                            <?php printf( esc_html__( 'Bonjour %s,', 'wc-hub-dilicom' ), $first_name ); ?>
                        </p>
                        <p style="font-size:14px;color:#646970;margin:0 0 24px;line-height:1.6;">
                            <?php printf( esc_html__( 'Votre commande #%d est confirmée. Vous pouvez télécharger vos livres numériques en cliquant sur les liens ci-dessous.', 'wc-hub-dilicom' ), $order_id ); ?>
                        </p>

                        <!-- Liste des liens -->
                        <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e0e0e0;border-radius:6px;overflow:hidden;margin-bottom:24px;">
                            <thead>
                                <tr style="background:#f8f9fa;">
                                    <th style="padding:12px 16px;font-size:12px;text-align:left;color:#646970;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid #e0e0e0;">
                                        <?php esc_html_e( 'Livre', 'wc-hub-dilicom' ); ?>
                                    </th>
                                    <th style="padding:12px 16px;font-size:12px;text-align:left;color:#646970;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid #e0e0e0;">
                                        <?php esc_html_e( 'Format', 'wc-hub-dilicom' ); ?>
                                    </th>
                                    <th style="padding:12px 16px;font-size:12px;text-align:right;color:#646970;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid #e0e0e0;">
                                        <?php esc_html_e( 'Lien', 'wc-hub-dilicom' ); ?>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ( $links as $i => $link ) :
                                $fmt     = strtoupper( $link['format'] ?: $link['type'] ?: 'DL' );
                                $ean     = $link['ean13'] ?? '';
                                $expires = $link['expires_at'] ?? '';
                                $bg      = $i % 2 === 0 ? '#fff' : '#fafafa';
                            ?>
                                <tr style="background:<?php echo $bg; ?>;">
                                    <td style="padding:14px 16px;font-size:13px;border-bottom:1px solid #f0f0f0;">
                                        <span style="color:#1d2327;font-weight:500;">EAN13: <?php echo esc_html( $ean ); ?></span>
                                        <?php if ( $expires ) : ?>
                                        <br /><small style="color:#999;font-size:11px;">
                                            <?php printf( esc_html__( 'Expire : %s', 'wc-hub-dilicom' ), esc_html( $expires ) ); ?>
                                        </small>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:14px 16px;font-size:13px;border-bottom:1px solid #f0f0f0;">
                                        <span style="display:inline-block;background:#e8f4fd;color:#2271b1;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;"><?php echo esc_html( $fmt ); ?></span>
                                    </td>
                                    <td style="padding:14px 16px;text-align:right;border-bottom:1px solid #f0f0f0;">
                                        <a href="<?php echo esc_url( $link['url'] ); ?>"
                                           style="display:inline-block;background:<?php echo $accent; ?>;color:#fff;padding:8px 16px;border-radius:4px;font-size:13px;font-weight:600;text-decoration:none;">
                                            ⬇ <?php esc_html_e( 'Télécharger', 'wc-hub-dilicom' ); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>

                        <!-- Avertissement -->
                        <div style="background:#fef9e7;border:1px solid #f8e6a0;border-radius:6px;padding:14px 16px;margin-bottom:24px;font-size:13px;color:#996300;">
                            ⚠ <?php esc_html_e( 'Ces liens sont personnels et limités dans le temps. Ne les partagez pas.', 'wc-hub-dilicom' ); ?>
                        </div>

                        <p style="font-size:13px;color:#646970;line-height:1.6;margin:0;">
                            <?php esc_html_e( 'Si vous avez des questions, consultez votre', 'wc-hub-dilicom' ); ?>
                            <a href="<?php echo $order_url; ?>" style="color:<?php echo $accent; ?>;">
                                <?php esc_html_e( 'espace commandes', 'wc-hub-dilicom' ); ?>
                            </a>
                            <?php esc_html_e( 'ou contactez notre support.', 'wc-hub-dilicom' ); ?>
                        </p>
                    </td>
                </tr>

                <!-- Footer -->
                <tr>
                    <td style="background:#f8f9fa;padding:20px 32px;border-top:1px solid #e0e0e0;text-align:center;">
                        <p style="margin:0;font-size:12px;color:#999;">
                            © <?php echo date( 'Y' ); ?>
                            <a href="<?php echo $site_url; ?>" style="color:#999;"><?php echo $site_name; ?></a>
                            — <?php esc_html_e( 'Propulsé par WooCommerce + HUB Dilicom', 'wc-hub-dilicom' ); ?>
                        </p>
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>
</body>
</html>
