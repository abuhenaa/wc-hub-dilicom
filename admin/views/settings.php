<?php
if ( ! defined( 'ABSPATH' ) ) exit;
use WC_Hub_Dilicom\Admin\Admin_Settings;
$opts = Admin_Settings::get_all();
$env  = $opts['environment'];
$has_login = ! empty( $opts['api_login'] );
?>
<div class="wrap whd-settings-wrap">
    <h1 class="wp-heading-inline">
        <span class="dashicons dashicons-book-alt" style="font-size:26px;vertical-align:middle;margin-right:6px;"></span>
        <?php esc_html_e( 'Paramètres HUB Dilicom', 'wc-hub-dilicom' ); ?>
    </h1>

    <?php settings_errors( Admin_Settings::OPTION_GROUP ); ?>

    <form method="post" action="options.php" id="whd-settings-form">
        <?php settings_fields( Admin_Settings::OPTION_GROUP ); ?>

        <!-- ── Bloc Connexion API ──────────────────────────────────── -->
        <div class="whd-card">
            <h2><?php esc_html_e( 'Connexion API HUB Dilicom', 'wc-hub-dilicom' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Identifiants fournis par Dilicom lors de votre inscription au HUB.', 'wc-hub-dilicom' ); ?>
                <a href="mailto:service-clients@dilicom.fr">service-clients@dilicom.fr</a>
            </p>

            <table class="form-table" role="presentation">

                <!-- GLN Revendeur -->
                <tr>
                    <th scope="row">
                        <label for="whd_gln_reseller"><?php esc_html_e( 'GLN Revendeur', 'wc-hub-dilicom' ); ?> <span class="required">*</span></label>
                    </th>
                    <td>
                        <input type="text" id="whd_gln_reseller" name="whd_gln_reseller"
                               value="<?php echo esc_attr( $opts['gln_reseller'] ); ?>"
                               class="regular-text" pattern="\d{13}" maxlength="13"
                               placeholder="Ex : 3025599123609" required />
                        <p class="description"><?php esc_html_e( 'Votre GLN à 13 chiffres (identifiant point de vente HUB).', 'wc-hub-dilicom' ); ?></p>
                    </td>
                </tr>

                <!-- Identifiant de connexion Basic Auth -->
                <tr>
                    <th scope="row">
                        <label for="whd_api_login"><?php esc_html_e( 'Identifiant API (login)', 'wc-hub-dilicom' ); ?></label>
                    </th>
                    <td>
                        <input type="text" id="whd_api_login" name="whd_api_login"
                               value="<?php echo esc_attr( $opts['api_login'] ); ?>"
                               class="regular-text" placeholder="Ex : AWUILLOT" />
                        <p class="description">
                            <?php esc_html_e( 'Login utilisé pour l\'authentification HTTP Basic (fourni par Dilicom). Différent du GLN. Exemples : AWUILLOT, JDUPONT…', 'wc-hub-dilicom' ); ?>
                            <br>
                            <em><?php esc_html_e( 'Si laissé vide, le GLN sera utilisé comme login (ancienne méthode).', 'wc-hub-dilicom' ); ?></em>
                        </p>
                    </td>
                </tr>

                <!-- Mot de passe -->
                <tr>
                    <th scope="row">
                        <label for="whd_password"><?php esc_html_e( 'Mot de passe API', 'wc-hub-dilicom' ); ?> <span class="required">*</span></label>
                    </th>
                    <td>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <input type="password" id="whd_password" name="whd_password"
                                   value="" class="regular-text"
                                   placeholder="<?php echo $opts['password'] ? esc_attr__( '(mot de passe enregistré)', 'wc-hub-dilicom' ) : ''; ?>"
                                   autocomplete="new-password" />
                            <button type="button" class="button whd-toggle-password" data-target="whd_password">
                                <?php esc_html_e( 'Afficher', 'wc-hub-dilicom' ); ?>
                            </button>
                        </div>
                        <p class="description"><?php esc_html_e( 'Laissez vide pour conserver le mot de passe actuel.', 'wc-hub-dilicom' ); ?></p>
                    </td>
                </tr>

                <!-- GLN Contractant -->
                <tr>
                    <th scope="row">
                        <label for="whd_gln_contractor"><?php esc_html_e( 'GLN Contractant', 'wc-hub-dilicom' ); ?></label>
                    </th>
                    <td>
                        <input type="text" id="whd_gln_contractor" name="whd_gln_contractor"
                               value="<?php echo esc_attr( $opts['gln_contractor'] ); ?>"
                               class="regular-text" pattern="\d{13}" maxlength="13"
                               placeholder="Ex : 3025599123609" />
                        <p class="description">
                            <?php esc_html_e( 'GLN du contractant (glnContractor). Obligatoire selon la doc Dilicom. Si identique au GLN revendeur, mettez la même valeur.', 'wc-hub-dilicom' ); ?>
                        </p>
                    </td>
                </tr>

            </table>

            <!-- Bouton test connexion -->
            <div class="whd-test-connection-wrap">
                <button type="button" id="whd-test-connection" class="button button-secondary">
                    <span class="dashicons dashicons-update" style="vertical-align:middle;"></span>
                    <?php esc_html_e( 'Tester la connexion', 'wc-hub-dilicom' ); ?>
                </button>
                <span id="whd-test-result" class="whd-test-result"></span>
            </div>
        </div>

        <!-- ── Bloc Paramètres généraux ───────────────────────────── -->
        <div class="whd-card">
            <h2><?php esc_html_e( 'Paramètres généraux', 'wc-hub-dilicom' ); ?></h2>
            <table class="form-table" role="presentation">

                <!-- Environnement -->
                <tr>
                    <th scope="row"><label for="whd_environment"><?php esc_html_e( 'Mode', 'wc-hub-dilicom' ); ?></label></th>
                    <td>
                        <select id="whd_environment" name="whd_environment">
                            <option value="production" <?php selected( $env, 'production' ); ?>>🟢 <?php esc_html_e( 'Production', 'wc-hub-dilicom' ); ?></option>
                            <option value="test"       <?php selected( $env, 'test' ); ?>>🔵 <?php esc_html_e( 'Test / Recette', 'wc-hub-dilicom' ); ?></option>
                        </select>
                        <?php if ( 'test' === $env ) : ?>
                            <span class="whd-badge whd-badge--warning"><?php esc_html_e( 'Mode TEST actif', 'wc-hub-dilicom' ); ?></span>
                        <?php endif; ?>
                        <p class="description">
                            <strong>Production :</strong> hub-dilicom.centprod.com &nbsp;|&nbsp;
                            <strong>Test :</strong> hub-test.centprod.com
                        </p>
                    </td>
                </tr>

                <!-- Devise -->
                <tr>
                    <th scope="row"><label for="whd_currency"><?php esc_html_e( 'Devise', 'wc-hub-dilicom' ); ?></label></th>
                    <td>
                        <select id="whd_currency" name="whd_currency">
                            <option value="EUR" <?php selected( $opts['currency'], 'EUR' ); ?>>EUR — Euro</option>
                            <option value="USD" <?php selected( $opts['currency'], 'USD' ); ?>>USD — Dollar</option>
                            <option value="GBP" <?php selected( $opts['currency'], 'GBP' ); ?>>GBP — Livre sterling</option>
                            <option value="CHF" <?php selected( $opts['currency'], 'CHF' ); ?>>CHF — Franc suisse</option>
                            <option value="CAD" <?php selected( $opts['currency'], 'CAD' ); ?>>CAD — Dollar canadien</option>
                        </select>
                        <p class="description"><?php esc_html_e( 'Obligatoire pour Eden. ISO 4217.', 'wc-hub-dilicom' ); ?></p>
                    </td>
                </tr>

                <!-- Pays -->
                <tr>
                    <th scope="row"><label for="whd_country"><?php esc_html_e( 'Pays par défaut', 'wc-hub-dilicom' ); ?></label></th>
                    <td>
                        <input type="text" id="whd_country" name="whd_country"
                               value="<?php echo esc_attr( $opts['country'] ); ?>"
                               class="small-text" maxlength="2" placeholder="FR" />
                        <p class="description"><?php esc_html_e( 'Code ISO 3166-1 (FR, BE, CH, CA…)', 'wc-hub-dilicom' ); ?></p>
                    </td>
                </tr>

            </table>
        </div>

        <!-- ── Bloc Taxes à l'import ───────────────────────────── -->
        <div class="whd-card">
            <h2><?php esc_html_e( 'Taxes à l\'import', 'wc-hub-dilicom' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="whd_import_tax_status"><?php esc_html_e( 'Statut de taxe', 'wc-hub-dilicom' ); ?></label></th>
                    <td>
                        <select id="whd_import_tax_status" name="whd_import_tax_status">
                            <option value="taxable" <?php selected( $opts['import_tax_status'], 'taxable' ); ?>><?php esc_html_e( 'Taxable (TVA applicable)', 'wc-hub-dilicom' ); ?></option>
                            <option value="none"    <?php selected( $opts['import_tax_status'], 'none' ); ?>><?php esc_html_e( 'Aucune (prix TTC = prix HT)', 'wc-hub-dilicom' ); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e( 'Détermine si les produits importés seront soumis à la TVA.', 'wc-hub-dilicom' ); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <?php submit_button( __( 'Enregistrer les paramètres', 'wc-hub-dilicom' ) ); ?>
    </form>
</div>