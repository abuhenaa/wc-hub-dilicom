<?php
namespace WC_Hub_Dilicom\Onix;
use WC_Hub_Dilicom\Hub_Logger;
if ( ! defined( 'ABSPATH' ) ) exit;

class Onix_Parser {
    const ID_TYPE_EAN13        = '03';
    const ID_TYPE_ISBN13       = '15';
    const RESOURCE_TYPE_COVER  = '01';
    const DIGITAL_FORM_PREFIX  = 'E';

    // Code List 150 – Product Form Detail
    private const FORMAT_DETAIL_MAP = [
        'E101' => 'EPUB',
        'E105' => 'HTML',
        'E107' => 'PDF',
        'E113' => 'Kindle (Mobi)',
        'E127' => 'Mobipocket',
        'E130' => 'HTML5',
    ];

    // Code List 21 – Epub Technical Protection
    private const PROTECTION_MAP = [
        '01' => 'DRM Adobe Digital Edition',
        '02' => 'Watermark (pas de DRM)',
        '03' => 'Pas de DRM',
    ];

    // Identifiants FTP fournis par Dilicom
    const FTP_HOST = 'pftp.centprod.com';
    const FTP_USER = '3025599123609';
    const FTP_PASS = 'eingahM1';

    public function download_onix_file( string $url ): string|false {
    // 1. Téléchargement direct sans authentification
    $raw = $this->curl_get( $url, '', '' );
    if ( $raw !== false ) {
        $size = strlen($raw);
        Hub_Logger::info( 'onix/download', "Fichier ONIX obtenu sans auth, taille: {$size} octets, début: " . substr($raw, 0, 80) );
        return $raw;
    }

    // 2. Avec identifiant API (ou GLN)
    $login    = (string) get_option( 'whd_api_login', '' );
    $login    = $login !== '' ? $login : (string) get_option( 'whd_gln_reseller', '' );
    $password = (string) get_option( 'whd_password', '' );
    $raw = $this->curl_get( $url, $login, $password );
    if ( $raw !== false ) {
        $size = strlen($raw);
        Hub_Logger::info( 'onix/download', "Fichier ONIX obtenu via Basic Auth, taille: {$size} octets, début: " . substr($raw, 0, 80) );
        return $raw;
    }

    // 3. FTP
    Hub_Logger::info( 'onix/download', 'Tentative FTP avec mot de passe API...' );
    $ftp = ftp_connect( self::FTP_HOST, 21, 30 );
    if ( $ftp ) {
        if ( @ftp_login( $ftp, self::FTP_USER, self::FTP_PASS ) ) {
            ftp_pasv( $ftp, true );
            $path = parse_url( $url, PHP_URL_PATH );
            $tmpfile = tempnam( sys_get_temp_dir(), 'onix' );
            if ( ftp_get( $ftp, $tmpfile, $path, FTP_BINARY ) ) {
                $content = file_get_contents( $tmpfile );
                unlink( $tmpfile );
                ftp_close( $ftp );
                $size = strlen($content);
                Hub_Logger::info( 'onix/download', "Fichier ONIX obtenu via FTP, taille: {$size} octets, début: " . substr($content, 0, 80) );
                return $content;
            }
        }
        ftp_close( $ftp );
    }

    Hub_Logger::error( 'onix/download', 'Tous les téléchargements ont échoué.' );
    return false;
}

    /**
     * Utilitaire cURL pour un téléchargement HTTP(S) simple.
     */
   private function curl_get( string $url, string $login, string $password ): string|false {
    $ch = curl_init();
    $options = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
    ];
    if ( $login !== '' && $password !== '' ) {
        $options[ CURLOPT_USERPWD ]  = $login . ':' . $password;
        $options[ CURLOPT_HTTPAUTH ] = CURLAUTH_BASIC;
    }
    curl_setopt_array( $ch, $options );
    $raw  = curl_exec( $ch );
    $code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
    $err  = curl_error( $ch );
    curl_close( $ch );

    if ( $code === 200 && $raw !== false ) {
        return $raw;
    }
    Hub_Logger::error( 'onix/curl_get', "HTTP {$code} — {$err}" );
    return false;
}

    public function parse_file( string $url ): array {
        $content = $this->download_onix_file( $url );
        if ( $content === false ) {
            Hub_Logger::error( 'onix/parse_file', "Impossible de télécharger le fichier ONIX : {$url}" );
            return [];
        }
        if ( substr( $content, 0, 2 ) === "\x1f\x8b" ) {
            $content = gzdecode( $content );
        }
        return empty( $content ) ? [] : $this->parse_string( $content );
    }

   public function parse_string( string $xml ): array {
    libxml_use_internal_errors(true);
    $products = [];

    $reader = new \XMLReader();
    $reader->XML( $xml );

    while ( $reader->read() ) {
        if ( $reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'Product' ) {
            $product_xml = $reader->readOuterXml();
            $node = simplexml_load_string( $product_xml, 'SimpleXMLElement', LIBXML_NOCDATA );
            if ( $node ) {
                $p = $this->extract_product( $node );
                if ( ! empty( $p['ean13'] ) ) {
                    // Extrait le vrai GLN distributeur de la notice
                    $p['gln_distributor'] = $this->extract_supplier_gln( $node );
                    $products[ $p['ean13'] ] = $p;
                }
            }
        }
    }
    $reader->close();

    return $products;
}
/**
 * Parse un fichier ONIX volumineux en utilisant XMLReader directement sur le fichier.
 * Évite de charger tout le contenu en mémoire.
 *
 * @param string $filePath Chemin absolu vers le fichier XML.
 * @return array Tableau des notices parsées, indexé par EAN13.
 */
public function parse_file_path( string $filePath ): array {
    if ( ! file_exists( $filePath ) ) {
        Hub_Logger::error( 'onix/parse_file_path', "Fichier introuvable : {$filePath}" );
        return [];
    }

    // Gérer le cas d'un fichier .gz (compressé)
    if ( substr( $filePath, -3 ) === '.gz' ) {
        $content = gzdecode( file_get_contents( $filePath ) );
        if ( $content === false ) {
            Hub_Logger::error( 'onix/parse_file_path', "Impossible de décompresser {$filePath}" );
            return [];
        }
        return $this->parse_string( $content );
    }

    libxml_use_internal_errors( true );
    $products = [];

    $reader = new \XMLReader();
    if ( ! $reader->open( $filePath ) ) {
        Hub_Logger::error( 'onix/parse_file_path', "Impossible d'ouvrir le fichier XML : {$filePath}" );
        return [];
    }

    // Augmenter le temps d'exécution pour les gros fichiers
    set_time_limit( 600 );

    while ( $reader->read() ) {
        if ( $reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'Product' ) {
            $product_xml = $reader->readOuterXml();
            $node = simplexml_load_string( $product_xml, 'SimpleXMLElement', LIBXML_NOCDATA );
            if ( $node ) {
                $p = $this->extract_product( $node );
                if ( ! empty( $p['ean13'] ) ) {
                    // Extrait le vrai GLN distributeur de la notice
                    $p['gln_distributor'] = $this->extract_supplier_gln( $node );
                    $products[ $p['ean13'] ] = $p;
                }
            }
        }
    }
    $reader->close();

    return $products;
}
    public function extract_product( \SimpleXMLElement $n ): array {
        $form_detail_code = (string)($n->DescriptiveDetail->ProductFormDetail ?? '');
        return [
            'ean13'               => $this->extract_ean13($n),
            'isbn13'              => $this->extract_isbn13($n),
            'title'               => $this->extract_title($n),
            'subtitle'            => $this->extract_subtitle($n),
            'description'         => $this->extract_description($n),
            'contributors'        => $this->extract_contributors($n),
            'subjects'            => $this->extract_subjects($n),
            'product_form'        => $this->extract_product_form($n),
            'product_form_detail' => $form_detail_code,
            'format_label'        => $this->map_product_form_detail($form_detail_code),
            'book_type'           => $this->resolve_book_type($n),
            'prices'              => $this->extract_pricing($n),
            'unit_price'          => $this->extract_primary_price($n),
            'currency'            => $this->extract_primary_currency($n),
            'cover_url'           => $this->extract_cover_url($n),
            'product_availability'=> $this->extract_availability($n),
            'publisher'           => $this->extract_publisher($n),
            'publication_date'    => $this->extract_publication_date($n),
            'language'            => $this->extract_language($n),
            'page_count'          => $this->extract_page_count($n),
            'protection'          => $this->extract_protection($n),
            'usage_limits'        => $this->extract_usage_limits($n),
            'collections'         => $this->extract_collections($n),
        ];
    }

    // ── Méthodes existantes (inchangées) ─────────────────────────────

    public function extract_ean13( \SimpleXMLElement $n ): string {
        foreach ($n->ProductIdentifier as $id) {
            if ( (string)$id->ProductIDType === '03' ) return (string)$id->IDValue;
        }
        foreach ($n->ProductIdentifier as $id) {
            if ( (string)$id->ProductIDType === '15' ) return (string)$id->IDValue;
        }
        return '';
    }

    public function extract_isbn13( \SimpleXMLElement $n ): string {
        foreach ($n->ProductIdentifier as $id)
            if ( self::ID_TYPE_ISBN13 === (string)$id->ProductIDType ) return (string)$id->IDValue;
        return '';
    }

    public function extract_title( \SimpleXMLElement $n ): string {
        foreach ($n->DescriptiveDetail->TitleDetail ?? [] as $td)
            if ('01' === (string)$td->TitleType)
                foreach ($td->TitleElement as $el)
                    if (!empty($el->TitleText)) return sanitize_text_field((string)$el->TitleText);
        return '';
    }

    public function extract_subtitle( \SimpleXMLElement $n ): string {
        foreach ($n->DescriptiveDetail->TitleDetail ?? [] as $td)
            foreach ($td->TitleElement as $el)
                if (!empty($el->Subtitle)) return sanitize_text_field((string)$el->Subtitle);
        return '';
    }

    public function extract_description( \SimpleXMLElement $n ): string {
        $fallback = '';
        foreach ($n->CollateralDetail->TextContent ?? [] as $t) {
            $type = (string)$t->TextType;
            if ('03' === $type) return wp_kses_post((string)$t->Text);
            if ('02' === $type && empty($fallback)) $fallback = wp_kses_post((string)$t->Text);
        }
        return $fallback;
    }

    public function extract_contributors( \SimpleXMLElement $n ): array {
        $out = [];
        $roles = ['A01'=>'Auteur','A12'=>'Illustrateur','B01'=>'Éditeur','B06'=>'Traducteur','A38'=>'Photographe','E07'=>'Narrateur'];
        foreach ($n->DescriptiveDetail->Contributor ?? [] as $c) {
            $rc   = (string)$c->ContributorRole;
            $fn   = sanitize_text_field((string)($c->NamesBeforeKey ?? ''));
            $ln   = sanitize_text_field((string)($c->KeyNames ?? ''));
            $name = sanitize_text_field((string)($c->PersonName ?? ''));
            if (empty($name) && ($fn || $ln)) $name = trim("$fn $ln");
            if ($name) $out[] = ['role_code'=>$rc,'role'=>$roles[$rc]??$rc,'name'=>$name,'first_name'=>$fn,'last_name'=>$ln];
        }
        return $out;
    }

    public function extract_subjects( \SimpleXMLElement $n ): array {
        $out = [];
        $schemes = ['10'=>'BISAC','93'=>'THEMA','20'=>'BIC','01'=>'Dewey','26'=>'Clil'];
        foreach ($n->DescriptiveDetail->Subject ?? [] as $s) {
            $sid = (string)$s->SubjectSchemeIdentifier;
            $out[] = ['scheme_id'=>$sid,'scheme_name'=>$schemes[$sid]??'Autre','code'=>sanitize_text_field((string)($s->SubjectCode??'')),'heading_text'=>sanitize_text_field((string)($s->SubjectHeadingText??''))];
        }
        return $out;
    }

    public function extract_product_form( \SimpleXMLElement $n ): string { return sanitize_text_field((string)($n->DescriptiveDetail->ProductForm??'')); }

    public function resolve_book_type( \SimpleXMLElement $n ): string { return str_starts_with(strtoupper($this->extract_product_form($n)),self::DIGITAL_FORM_PREFIX)?'digital':'physical'; }

    public function extract_pricing( \SimpleXMLElement $n ): array {
        $out = [];
        foreach ($n->ProductSupply as $ps)
            foreach ($ps->SupplyDetail as $sd)
                foreach ($sd->Price as $p) {
                    $a    = (float)$p->PriceAmount;
                    $out[] = ['type'=>(string)($p->PriceType??''),'amount'=>$a,'amount_cents'=>(int)round($a*100),'currency'=>sanitize_text_field((string)($p->CurrencyCode??'EUR')),'vat_rate'=>(float)($p->TaxRatePercent??0)];
                }
        return $out;
    }

    public function extract_primary_price( \SimpleXMLElement $n ): int {
    $prices = $this->extract_pricing( $n );
    $target_currency = strtoupper( (string) get_option( 'whd_currency', 'EUR' ) );

    // 1. Chercher un prix dans la devise cible (EUR par défaut)
    foreach ( $prices as $p ) {
        if ( strtoupper( $p['currency'] ?? '' ) === $target_currency ) {
            return $p['amount_cents'] ?? 0;
        }
    }
    // 2. Sinon, prendre le premier prix (fallback)
    return $prices[0]['amount_cents'] ?? 0;
}

    public function extract_primary_currency( \SimpleXMLElement $n ): string {
    return strtoupper( (string) get_option( 'whd_currency', 'EUR' ) );
}

    public function extract_cover_url( \SimpleXMLElement $n ): string {
        foreach ($n->CollateralDetail->SupportingResource ?? [] as $r)
            if (self::RESOURCE_TYPE_COVER === (string)$r->ResourceContentType)
                foreach ($r->ResourceVersion as $v)
                    if (!empty($v->ResourceLink)) return esc_url_raw((string)$v->ResourceLink);
        return '';
    }

    public function extract_availability( \SimpleXMLElement $n ): string {
        foreach ($n->ProductSupply as $ps) foreach ($ps->SupplyDetail as $sd) { $a=(string)($sd->ProductAvailability??''); if($a) return $a; }
        return '';
    }

    public function extract_publisher( \SimpleXMLElement $n ): string {
        foreach ($n->PublishingDetail->Publisher ?? [] as $p) if(!empty($p->PublisherName)) return sanitize_text_field((string)$p->PublisherName);
        return '';
    }
/**
 * Extrait le GLN du distributeur depuis le Header du fichier ONIX.
 */
public function extract_gln_distributor( \SimpleXMLElement $xml ): string {
    foreach ( $xml->Header->Sender->SenderIdentifier as $id ) {
        if ( (string) $id->SenderIDType === '06' ) { // GLN
            return (string) $id->IDValue;
        }
    }
    return '';
}
/**
 * Extrait le GLN du premier distributeur de la notice.
 */
public function extract_supplier_gln( \SimpleXMLElement $n ): string {
    foreach ( $n->ProductSupply as $ps ) {
        foreach ( $ps->SupplyDetail as $sd ) {
            foreach ( $sd->Supplier->SupplierIdentifier as $id ) {
                if ( (string) $id->SupplierIDType === '06' ) { // GLN
                    return (string) $id->IDValue;
                }
            }
        }
    }
    return '';
}
    public function extract_publication_date( \SimpleXMLElement $n ): string {
        foreach ($n->PublishingDetail->PublishingDate ?? [] as $d) if('01'===(string)$d->PublishingDateRole) return sanitize_text_field((string)$d->Date);
        return '';
    }

    public function extract_language( \SimpleXMLElement $n ): string {
        foreach ($n->DescriptiveDetail->Language ?? [] as $l) if('01'===(string)$l->LanguageRole) return sanitize_text_field((string)$l->LanguageCode);
        return '';
    }

    public function extract_page_count( \SimpleXMLElement $n ): int {
        foreach ($n->DescriptiveDetail->Extent ?? [] as $e) if(in_array((string)$e->ExtentType,['00','11'],true)) return (int)$e->ExtentValue;
        return 0;
    }

    // ── NOUVELLES MÉTHODES ─────────────────────────────────────────────

    /**
     * Traduit un code ProductFormDetail (E101, E107…) en libellé lisible.
     */
    public function map_product_form_detail( string $code ): string {
        return self::FORMAT_DETAIL_MAP[ strtoupper($code) ] ?? $code;
    }

    /**
     * Extrait la protection numérique (DRM/Watermark) à partir de la notice.
     */
    public function extract_protection( \SimpleXMLElement $n ): string {
        // 1. <EpubTechnicalProtection>
        $code = (string)($n->DescriptiveDetail->EpubTechnicalProtection ?? '');
        if ($code !== '') {
            return self::PROTECTION_MAP[$code] ?? "Protection inconnue ($code)";
        }
        // 2. Parfois via ProductFormFeature (code 02)
        foreach ($n->DescriptiveDetail->ProductFormFeature ?? [] as $f) {
            if ((string)$f->ProductFormFeatureType === '02') {
                return sanitize_text_field((string)$f->ProductFormFeatureValue);
            }
        }
        return '';
    }

    /**
     * Extrait les limitations d'usage (copie, impression…) sous forme de phrases.
     */
    public function extract_usage_limits( \SimpleXMLElement $n ): array {
        $limits = [];
        $typeNames = [
            '01' => 'Copier/Coller',
            '02' => 'Impression',
            '03' => 'Prêt',
            '04' => 'Partage',
        ];
        foreach ($n->DescriptiveDetail->EpubUsageConstraint ?? [] as $c) {
            $type   = (string)$c->EpubUsageType;
            $status = (string)$c->EpubUsageStatus === '01' ? 'Autorisé' : 'Non autorisé';
            $name   = $typeNames[$type] ?? "Usage inconnu ($type)";
            $limits[] = "$name : $status";
        }
        return $limits;
    }

    /**
     * Extrait les noms de collections (séries, etc.) depuis <Collection>.
     */
    public function extract_collections( \SimpleXMLElement $n ): array {
        $cols = [];
        foreach ($n->DescriptiveDetail->Collection ?? [] as $col) {
            foreach ($col->TitleDetail ?? [] as $td) {
                $title = (string)($td->TitleElement->TitleText ?? '');
                if ($title) {
                    $cols[] = $title;
                }
            }
        }
        return $cols;
    }
}