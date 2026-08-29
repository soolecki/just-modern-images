<?php
/**
 * Plugin-owned attachment manifest.
 *
 * @package JustModernImages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores and cleans up modern companions without changing core image metadata.
 */
final class JMI_Manifest {

	const META_KEY = '_jmi_manifest';
	const SCHEMA_VERSION = 1;

	/**
	 * Return an attachment manifest.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, mixed>
	 */
	public function get( $attachment_id ) {
		$manifest = get_post_meta( $attachment_id, self::META_KEY, true );

		if (
			! is_array( $manifest ) ||
			empty( $manifest['schema'] ) ||
			self::SCHEMA_VERSION !== (int) $manifest['schema'] ||
			empty( $manifest['sources'] ) ||
			! is_array( $manifest['sources'] )
		) {
			return $this->empty_manifest();
		}

		return $manifest;
	}

	/**
	 * Persist an attachment manifest.
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string, mixed> $manifest      Manifest data.
	 * @return bool
	 */
	public function save( $attachment_id, array $manifest ) {
		$manifest['schema']     = self::SCHEMA_VERSION;
		$manifest['updated_at'] = time();

		return false !== update_post_meta( $attachment_id, self::META_KEY, $manifest );
	}

	/**
	 * Return a new manifest.
	 *
	 * @return array<string, mixed>
	 */
	public function empty_manifest() {
		return array(
			'schema'             => self::SCHEMA_VERSION,
			'generation_profile' => '',
			'updated_at'         => 0,
			'sources'            => array(),
		);
	}

	/**
	 * Delete companion files recorded for an attachment.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $delete_meta   Whether to remove the manifest.
	 * @return int Number of deleted files.
	 */
	public function delete_variants( $attachment_id, $delete_meta = true ) {
		$manifest = $this->get( $attachment_id );
		$deleted  = 0;

		foreach ( $manifest['sources'] as $source ) {
			if ( empty( $source['variants'] ) || ! is_array( $source['variants'] ) ) {
				continue;
			}

			foreach ( $source['variants'] as $variant ) {
				if ( empty( $variant['relative_path'] ) || 'ready' !== ( $variant['status'] ?? '' ) ) {
					continue;
				}

				$path = $this->resolve_variant_path( $variant['relative_path'] );
				if ( $path && file_exists( $path ) ) {
					wp_delete_file( $path );
					if ( ! file_exists( $path ) ) {
						++$deleted;
					}
				}
			}
		}

		if ( $delete_meta ) {
			delete_post_meta( $attachment_id, self::META_KEY );
		}

		do_action( 'jmi_variants_deleted', $attachment_id, $deleted );

		return $deleted;
	}

	/**
	 * Resolve a recorded relative path, restricted to modern files in uploads.
	 *
	 * @param mixed $relative_path Relative upload path.
	 * @return string|false
	 */
	private function resolve_variant_path( $relative_path ) {
		if ( ! is_string( $relative_path ) || '' === $relative_path ) {
			return false;
		}

		$relative_path = wp_normalize_path( $relative_path );
		if (
			0 === strpos( $relative_path, '/' ) ||
			preg_match( '#(^|/)\.\.(/|$)#', $relative_path ) ||
			preg_match( '#^[a-zA-Z]:/#', $relative_path )
		) {
			return false;
		}

		$extension = strtolower( pathinfo( $relative_path, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, array( 'webp', 'avif' ), true ) ) {
			return false;
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return false;
		}

		$base = trailingslashit( wp_normalize_path( $uploads['basedir'] ) );
		$path = wp_normalize_path( $uploads['basedir'] . '/' . $relative_path );

		if ( 0 !== strpos( $path, $base ) ) {
			return false;
		}

		return $path;
	}
}
