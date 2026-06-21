<?php
namespace WC_Hub_Dilicom\Import;

use WC_Hub_Dilicom\Hub_Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cover_Queue {

	const TABLE       = 'hub_cover_queue';
	const MAX_ATTEMPTS = 5;
	const CRON_HOOK   = 'whd_process_cover_queue';

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function install_table(): void {
		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			"CREATE TABLE {$table} (
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
			) {$charset};"
		);
	}

	public function enqueue( int $product_id, string $cover_url, string $ean13, string $error = '' ): void {
		global $wpdb;
		$table = self::table_name();
		$now   = current_time( 'mysql', true );

		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, attempts FROM {$table} WHERE product_id = %d LIMIT 1", $product_id )
		);

		if ( $existing ) {
			$wpdb->update(
				$table,
				[
					'cover_url'  => esc_url_raw( $cover_url ),
					'ean13'      => sanitize_text_field( $ean13 ),
					'last_error' => $error,
					'status'     => 'pending',
					'updated_at' => $now,
				],
				[ 'id' => (int) $existing->id ],
				[ '%s', '%s', '%s', '%s', '%s' ],
				[ '%d' ]
			);
			return;
		}

		$wpdb->insert(
			$table,
			[
				'product_id' => $product_id,
				'ean13'      => sanitize_text_field( $ean13 ),
				'cover_url'  => esc_url_raw( $cover_url ),
				'attempts'   => 0,
				'last_error' => $error,
				'status'     => 'pending',
				'created_at' => $now,
				'updated_at' => $now,
			],
			[ '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
		);
	}

	public function remove_for_product( int $product_id ): void {
		global $wpdb;
		$wpdb->delete( self::table_name(), [ 'product_id' => $product_id ], [ '%d' ] );
	}

	public function process_batch( ?int $limit = null ): array {
		global $wpdb;
		$table = self::table_name();
		$limit = $limit ?? max( 5, (int) get_option( 'whd_cover_queue_batch', 25 ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'pending' AND attempts < %d ORDER BY id ASC LIMIT %d",
				self::MAX_ATTEMPTS,
				$limit
			)
		);

		$service = new Cover_Image_Service( $this );
		$done    = 0;
		$failed  = 0;

		foreach ( $rows as $row ) {
			$product_id = (int) $row->product_id;
			$url        = (string) $row->cover_url;
			$ean13      = (string) $row->ean13;

			if ( ! $product_id || empty( $url ) || empty( $ean13 ) ) {
				$this->mark_failed( (int) $row->id, 'Invalid queue row.' );
				$failed++;
				continue;
			}

			$ok = $service->import_for_product( $product_id, $url, $ean13 );
			if ( $ok ) {
				$this->remove_for_product( $product_id );
				$done++;
				continue;
			}

			$attempts = (int) $row->attempts + 1;
			if ( $attempts >= self::MAX_ATTEMPTS ) {
				$this->mark_failed( (int) $row->id, (string) $row->last_error );
				Hub_Logger::error( 'cover/queue', sprintf( 'Cover failed permanently for product #%d after %d attempts.', $product_id, $attempts ) );
				$failed++;
			} else {
				$wpdb->update(
					$table,
					[
						'attempts'   => $attempts,
						'updated_at' => current_time( 'mysql', true ),
					],
					[ 'id' => (int) $row->id ],
					[ '%d', '%s' ],
					[ '%d' ]
				);
				$failed++;
			}
		}

		return [
			'processed' => $done,
			'failed'    => $failed,
			'remaining' => $this->count_pending(),
		];
	}

	public function backfill_all(): int {
		global $wpdb;

		$products = $wpdb->get_results(
			"SELECT p.ID AS product_id, ean.meta_value AS ean13, cover.meta_value AS cover_url
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} ean ON ean.post_id = p.ID AND ean.meta_key = '_hub_ean13'
			INNER JOIN {$wpdb->postmeta} cover ON cover.post_id = p.ID AND cover.meta_key = '_hub_cover_url'
			LEFT JOIN {$wpdb->postmeta} attach ON attach.post_id = p.ID AND attach.meta_key = '_hub_cover_attachment_id'
			WHERE p.post_type = 'product' AND p.post_status != 'trash'
			AND cover.meta_value != ''
			AND (attach.meta_value IS NULL OR attach.meta_value = '' OR attach.meta_value = '0')"
		);

		$count = 0;
		foreach ( $products as $row ) {
			$this->enqueue(
				(int) $row->product_id,
				(string) $row->cover_url,
				(string) $row->ean13,
				'Backfill queued.'
			);
			$count++;
		}

		if ( $count > 0 && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 10, self::CRON_HOOK );
		}

		return $count;
	}

	public function get_status(): array {
		global $wpdb;
		$table = self::table_name();

		$total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending'" );
		$failed  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'failed'" );
		$done    = $total - $pending - $failed;

		return [
			'total'   => $total,
			'pending' => $pending,
			'failed'  => $failed,
			'done'    => max( 0, $done ),
		];
	}

	public function count_pending(): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM " . self::table_name() . " WHERE status = 'pending' AND attempts < %d",
				self::MAX_ATTEMPTS
			)
		);
	}

	private function mark_failed( int $id, string $error ): void {
		global $wpdb;
		$wpdb->update(
			self::table_name(),
			[
				'status'     => 'failed',
				'last_error' => $error,
				'updated_at' => current_time( 'mysql', true ),
			],
			[ 'id' => $id ],
			[ '%s', '%s', '%s' ],
			[ '%d' ]
		);
	}
}
