<?php
/**
 * Attachment source inventory.
 *
 * @package JustModernImages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves attachment files without deriving filesystem paths from URLs.
 */
final class JMI_Source_Inventory {

	/**
	 * Return convertible files for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, array<string, mixed>>
	 */
	public function for_attachment( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$full_path     = get_attached_file( $attachment_id, true );

		if ( ! $attachment_id || ! is_string( $full_path ) || '' === $full_path ) {
			return array();
		}

		$sources = array();
		$full    = $this->build_source( 'full', $full_path );

		if ( $full ) {
			$sources['full'] = $full;
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $metadata ) || empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
			return $sources;
		}

		$directory = dirname( $full_path );
		$seen      = $full ? array( wp_normalize_path( $full['path'] ) => true ) : array();

		foreach ( $metadata['sizes'] as $size_name => $size_data ) {
			if ( ! is_array( $size_data ) || empty( $size_data['file'] ) || ! is_string( $size_data['file'] ) ) {
				continue;
			}

			$path = $directory . DIRECTORY_SEPARATOR . wp_basename( $size_data['file'] );
			$key  = wp_normalize_path( $path );

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$source = $this->build_source( sanitize_key( $size_name ), $path );
			if ( $source ) {
				$sources[ sanitize_key( $size_name ) ] = $source;
				$seen[ $key ]                         = true;
			}
		}

		return $sources;
	}

	/**
	 * Build and validate a source record.
	 *
	 * @param string $size_name Logical WordPress size name.
	 * @param string $path      Absolute source path.
	 * @return array<string, mixed>|false
	 */
	private function build_source( $size_name, $path ) {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return false;
		}

		$base_real = realpath( $uploads['basedir'] );
		$path_real = realpath( $path );

		if ( false === $base_real || false === $path_real || ! is_readable( $path_real ) ) {
			return false;
		}

		$base = trailingslashit( wp_normalize_path( $base_real ) );
		$file = wp_normalize_path( $path_real );

		if ( 0 !== strpos( $file, $base ) ) {
			return false;
		}

		$image = getimagesize( $path_real );
		$mime  = is_array( $image ) && ! empty( $image['mime'] ) ? $image['mime'] : '';

		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
			return false;
		}

		$relative_path = ltrim( substr( $file, strlen( $base ) ), '/' );
		$file_size     = wp_filesize( $path_real );
		$modified      = filemtime( $path_real );
		$signature     = implode(
			'|',
			array(
				$relative_path,
				(string) $file_size,
				(string) $modified,
				(string) $image[0],
				(string) $image[1],
				$mime,
			)
		);

		return array(
			'size_name'     => $size_name,
			'path'          => $path_real,
			'relative_path' => $relative_path,
			'mime_type'     => $mime,
			'width'         => (int) $image[0],
			'height'        => (int) $image[1],
			'bytes'         => (int) $file_size,
			'modified'      => (int) $modified,
			'signature'     => hash( 'sha256', $signature ),
		);
	}
}

