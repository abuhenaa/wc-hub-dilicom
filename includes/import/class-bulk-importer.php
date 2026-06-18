<?php
namespace WC_Hub_Dilicom\Import;
use WC_Hub_Dilicom\Api\Hub_Api_Catalog;
use WC_Hub_Dilicom\Onix\Onix_Parser;
use WC_Hub_Dilicom\Hub_Logger;
if ( ! defined( 'ABSPATH' ) ) exit;

class Bulk_Importer {
    const BATCH_SIZE = 10;
    private Book_Importer $importer;
    private Hub_Api_Catalog $catalog_api;
    private Onix_Parser $parser;
    private Import_Filter $filter;

    public function __construct( ?Book_Importer $i=null, ?Hub_Api_Catalog $c=null, ?Onix_Parser $p=null, ?Import_Filter $f=null ) {
        $this->importer    = $i ?? new Book_Importer();
        $this->catalog_api = $c ?? new Hub_Api_Catalog();
        $this->parser      = $p ?? new Onix_Parser();
        $this->filter      = $f ?? new Import_Filter();
    }

    public function start_bulk( array $filters ): array {
        $this->clear_queue();
        $gln      = $filters['gln_distributor'] ?? '';
        $response = $this->catalog_api->get_notices('lastConnection');
        $items    = $response['noticesList'] ?? [];
        $ean13s   = array_column($items,'ean13');
        if (empty($ean13s)) return ['success'=>false,'queued'=>0,'message'=>'Aucun EAN13 récupéré.'];

        $detail = $this->catalog_api->get_detail_notices_bulk($ean13s, $gln);
        $url    = $detail['onixFileUrl'] ?? '';
        $all    = empty($url) ? [] : $this->parser->parse_file($url);
        foreach ($all as &$n) $n['gln_distributor'] = $gln; unset($n);
        $all    = $this->filter->apply($all, $filters);

        $queued = $this->enqueue_notices($all, $gln);
        Hub_Logger::info('bulk_import/start', sprintf('%d livre(s) en file.', $queued));
        return ['success'=>true,'queued'=>$queued,'message'=>sprintf('%d livres en file.', $queued)];
    }

    public function process_queue(): array {
        global $wpdb;
        $table = $wpdb->prefix.'hub_import_queue';
        $rows  = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE status='pending' ORDER BY id ASC LIMIT %d", self::BATCH_SIZE));
        $done = $err = 0;
        foreach ($rows as $row) {
            $res = $this->importer->import_from_ean($row->ean13, $row->gln_distributor);
            $wpdb->update($table, ['status'=>$res['success']?'done':'error','error'=>$res['success']?null:$res['message']], ['id'=>$row->id], ['%s','%s'], ['%d']);
            $res['success'] ? $done++ : $err++;
        }
        $remaining = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='pending'");
        return ['processed'=>$done,'errors'=>$err,'remaining'=>$remaining,'done'=>0===$remaining];
    }

    public function get_queue_status(): array {
        global $wpdb; $t = $wpdb->prefix.'hub_import_queue';
        $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t}");
        $done  = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE status='done'");
        $err   = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE status='error'");
        $pend  = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE status='pending'");
        $pct   = $total > 0 ? (int)round((($done+$err)/$total)*100) : 0;
        return ['total'=>$total,'done'=>$done,'errors'=>$err,'pending'=>$pend,'percent'=>$pct];
    }

    public function clear_queue(): void { global $wpdb; $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}hub_import_queue"); }

    private function enqueue_notices( array $notices, string $gln ): int {
        global $wpdb; $t = $wpdb->prefix.'hub_import_queue'; $count = 0;
        foreach ($notices as $ean13 => $n) {
            $g = $n['gln_distributor'] ?? $gln;
            if ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE ean13=%s LIMIT 1", $ean13))) continue;
            $wpdb->insert($t, ['ean13'=>(string)$ean13,'gln_distributor'=>$g,'status'=>'pending','created_at'=>current_time('mysql',true)], ['%s','%s','%s','%s']);
            if ($wpdb->insert_id) $count++;
        }
        return $count;
    }
}
