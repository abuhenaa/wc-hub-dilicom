<?php
namespace WC_Hub_Dilicom\Import;

use WC_Hub_Dilicom\Hub_Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cover_Image_Service {

	const META_COVER_ATTACHMENT = '_hub_cover_attachment_id';
	const META_COVER_URL        = '_hub_cover_url';
	const META_PLACEHOLDER      = '_hub_placeholder_attachment_id';

	const OPT_ENABLED  = 'whd_cover_optimize_enabled';
	const OPT_MAX_H    = 'whd_cover_max_height';
	const OPT_QUALITY  = 'whd_cover_webp_quality';

	public function __construct(
		private ?Cover_Queue $queue = null
	) {
		$this->queue = $queue ?? new Cover_Queue();
	}

	public static function is_enabled(): bool {
		return 'yes' === get_option( self::OPT_ENABLED, 'yes' );
	}

	public static function supports_webp(): bool {
		if ( function_exists( 'imagewebp' ) ) {
			return true;
		}
		if ( extension_loaded( 'imagick' ) ) {
			$formats = \Imagick::queryFormats( 'WEBP' );
			return ! empty( $formats );
		}
		return false;
	}

	public static function get_settings(): array {
		return [
			'enabled'    => self::is_enabled(),
			'max_height' => max( 100, (int) get_option( self::OPT_MAX_H, 600 ) ),
			'quality'    => min( 100, max( 1, (int) get_option( self::OPT_QUALITY, 80 ) ) ),
			'webp'       => self::supports_webp(),
		];
	}

	public static function get_display_url( int $product_id, string $fallback = '' ): string {
		$attach_id = (int) get_post_meta( $product_id, self::META_COVER_ATTACHMENT, true );
		if ( $attach_id ) {
			$url = wp_get_attachment_image_url( $attach_id, 'thumbnail' );
			if ( $url ) {
				return $url;
			}
		}
		return $fallback;
	}

	/**
	 * Download, optimize, and attach a cover image for a product.
	 */
	public function import_for_product( int $product_id, string $url, string $ean13 ): bool {
		if ( empty( $url ) || empty( $ean13 ) ) {
			return false;
		}

		if ( ! self::is_enabled() ) {
			$this->store_source_url_only( $product_id, $url );
			return false;
		}

		$existing_url = (string) get_post_meta( $product_id, self::META_COVER_URL, true );
		$attach_id    = (int) get_post_meta( $product_id, self::META_COVER_ATTACHMENT, true );
		$has_local    = $attach_id && wp_get_attachment_url( $attach_id );
		$has_placeholder = (bool) get_post_meta( $product_id, self::META_PLACEHOLDER, true );

		if ( $existing_url === $url && $has_local && ! $has_placeholder ) {
			return true;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			Hub_Logger::warning( 'import/cover', sprintf( 'Download failed for #%d: %s', $product_id, $tmp->get_error_message() ) );
			$this->queue->enqueue( $product_id, $url, $ean13, $tmp->get_error_message() );
			$this->store_source_url_only( $product_id, $url );
			return false;
		}

		$optimized = $this->optimize_image( $tmp, $product_id, $ean13 );
		@unlink( $tmp );

		if ( is_wp_error( $optimized ) ) {
			Hub_Logger::warning( 'import/cover', sprintf( 'Optimize failed for #%d: %s', $product_id, $optimized->get_error_message() ) );
			$this->queue->enqueue( $product_id, $url, $ean13, $optimized->get_error_message() );
			$this->store_source_url_only( $product_id, $url );
			return false;
		}

		$attachment_id = $this->sideload_file( $optimized['path'], $optimized['filename'], $product_id );
		@unlink( $optimized['path'] );

		if ( is_wp_error( $attachment_id ) ) {
			Hub_Logger::warning( 'import/cover', sprintf( 'Sideload failed for #%d: %s', $product_id, $attachment_id->get_error_message() ) );
			$this->queue->enqueue( $product_id, $url, $ean13, $attachment_id->get_error_message() );
			$this->store_source_url_only( $product_id, $url );
			return false;
		}

		$this->attach_to_product( $product_id, (int) $attachment_id, $url, $attach_id );
		$this->queue->remove_for_product( $product_id );

		Hub_Logger::info( 'import/cover', sprintf( 'Cover imported for product #%d (attachment #%d)', $product_id, $attachment_id ) );
		return true;
	}

	private function store_source_url_only( int $product_id, string $url ): void {
		update_post_meta( $product_id, self::META_COVER_URL, esc_url_raw( $url ) );
	}

	private function attach_to_product( int $product_id, int $attachment_id, string $source_url, int $old_attachment_id = 0 ): void {
		set_post_thumbnail( $product_id, $attachment_id );
		update_post_meta( $product_id, self::META_COVER_URL, esc_url_raw( $source_url ) );
		update_post_meta( $product_id, self::META_COVER_ATTACHMENT, $attachment_id );

		$placeholder_id = (int) get_post_meta( $product_id, self::META_PLACEHOLDER, true );
		foreach ( array_unique( array_filter( [ $placeholder_id, $old_attachment_id ] ) ) as $old_id ) {
			if ( $old_id && $old_id !== $attachment_id ) {
				wp_delete_attachment( $old_id, true );
			}
		}
		delete_post_meta( $product_id, self::META_PLACEHOLDER );
	}

	/**
	 * Build a filesystem-safe cover filename from the product title.
	 */
	private function get_cover_filename( int $product_id, string $ean13, string $ext ): string {
		$title = get_the_title( $product_id );
		$base  = $title ? sanitize_title( $title ) : '';

		if ( empty( $base ) ) {
			$base = sanitize_file_name( $ean13 );
		}

		return $base . '.' . $ext;
	}

	/**
	 * @return array{path:string,filename:string}|\WP_Error
	 */
	private function optimize_image( string $input_path, int $product_id, string $ean13 ): array|\WP_Error {
		$settings = self::get_settings();
		$editor   = wp_get_image_editor( $input_path );

		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		$editor->resize( null, $settings['max_height'], false );
		$editor->set_quality( $settings['quality'] );

		$use_webp  = self::supports_webp();
		$ext       = $use_webp ? 'webp' : 'jpg';
		$mime      = $use_webp ? 'image/webp' : 'image/jpeg';
		$filename  = $this->get_cover_filename( $product_id, $ean13, $ext );
		$dest      = trailingslashit( get_temp_dir() ) . $filename;

		$saved = $editor->save( $dest, $mime );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return [
			'path'     => $saved['path'],
			'filename' => $filename,
		];
	}

	private function sideload_file( string $path, string $filename, int $product_id ): int|\WP_Error {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$file_array = [
			'name'     => $filename,
			'tmp_name' => $path,
		];

		return media_handle_sideload( $file_array, $product_id );
	}
}
