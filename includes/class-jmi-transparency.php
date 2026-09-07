<?php
/**
 * Image transparency inspection.
 *
 * @package JustModernImages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks whether an image contains pixels that are not fully opaque.
 */
final class JMI_Transparency {

	/**
	 * Inspect an image without assuming which WordPress editor created it.
	 *
	 * @param string $path      Absolute image path.
	 * @param string $mime_type Image MIME type.
	 * @return bool|null True when transparency is present, false when it is not,
	 *                   or null when the file cannot be inspected safely.
	 */
	public static function has_transparency( $path, $mime_type ) {
		if ( ! is_string( $path ) || '' === $path || ! is_readable( $path ) ) {
			return null;
		}

		$mime_type = strtolower( (string) $mime_type );
		if ( 'image/jpeg' === $mime_type ) {
			return false;
		}

		$png_hint = null;
		if ( 'image/png' === $mime_type ) {
			$png_hint = self::png_declares_transparency( $path );
			if ( false === $png_hint ) {
				return false;
			}
		}

		$result = self::inspect_with_imagick( $path );
		if ( null !== $result ) {
			return $result;
		}

		$result = self::inspect_with_gd( $path, $mime_type );
		if ( null !== $result ) {
			return $result;
		}

		if ( true === $png_hint ) {
			return true;
		}

		return null;
	}

	/**
	 * Use ImageMagick channel statistics when available.
	 *
	 * @param string $path Absolute image path.
	 * @return bool|null
	 */
	private static function inspect_with_imagick( $path ) {
		if ( ! class_exists( 'Imagick' ) || ! defined( 'Imagick::CHANNEL_ALPHA' ) ) {
			return null;
		}

		try {
			$image = new Imagick( $path );
			if ( method_exists( $image, 'setIteratorIndex' ) ) {
				$image->setIteratorIndex( 0 );
			}

			if ( method_exists( $image, 'getImageAlphaChannel' ) && ! $image->getImageAlphaChannel() ) {
				$image->clear();
				$image->destroy();
				return false;
			}

			$extrema = $image->getImageChannelExtrema( Imagick::CHANNEL_ALPHA );
			$range   = Imagick::getQuantumRange();
			$image->clear();
			$image->destroy();

			$minimum = is_array( $extrema ) ? (float) ( $extrema['minima'] ?? $extrema['min'] ?? -1 ) : -1;
			$opaque  = is_array( $range ) ? (float) ( $range['quantumRangeLong'] ?? $range['quantumRange'] ?? 0 ) : 0;
			if ( $minimum < 0 || $opaque <= 0 ) {
				return null;
			}

			return $minimum < $opaque;
		} catch ( Throwable $error ) {
			return null;
		}
	}

	/**
	 * Inspect decoded pixels through GD.
	 *
	 * @param string $path      Absolute image path.
	 * @param string $mime_type Image MIME type.
	 * @return bool|null
	 */
	private static function inspect_with_gd( $path, $mime_type ) {
		$decoders = array(
			'image/png'  => 'imagecreatefrompng',
			'image/webp' => 'imagecreatefromwebp',
			'image/avif' => 'imagecreatefromavif',
		);
		$decoder  = $decoders[ $mime_type ] ?? '';

		if ( '' === $decoder || ! function_exists( $decoder ) ) {
			return null;
		}

		try {
			$image = @$decoder( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! $image ) {
				return null;
			}

			$width        = imagesx( $image );
			$height       = imagesy( $image );
			$is_truecolor = function_exists( 'imageistruecolor' ) ? imageistruecolor( $image ) : true;
			$transparent  = false;

			for ( $y = 0; $y < $height && ! $transparent; ++$y ) {
				for ( $x = 0; $x < $width; ++$x ) {
					$color = imagecolorat( $image, $x, $y );
					if ( $is_truecolor ) {
						$alpha = ( $color >> 24 ) & 0x7f;
					} else {
						$components = imagecolorsforindex( $image, $color );
						$alpha      = is_array( $components ) ? (int) ( $components['alpha'] ?? 0 ) : 0;
					}

					if ( $alpha > 0 ) {
						$transparent = true;
						break;
					}
				}
			}

			if ( PHP_VERSION_ID < 80000 ) {
				imagedestroy( $image );
			}

			return $transparent;
		} catch ( Throwable $error ) {
			return null;
		}
	}

	/**
	 * Read PNG structure as a conservative fallback when no decoder is exposed.
	 *
	 * An alpha colour type or a tRNS chunk means transparency may affect the
	 * rendered image. Returning true is safer than publishing a flattened file.
	 *
	 * @param string $path Absolute PNG path.
	 * @return bool|null
	 */
	private static function png_declares_transparency( $path ) {
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_resource( $handle ) ) {
			return null;
		}

		try {
			$signature = fread( $handle, 8 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( "\x89PNG\r\n\x1a\n" !== $signature ) {
				return null;
			}

			while ( ! feof( $handle ) ) {
				$header = fread( $handle, 8 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				if ( ! is_string( $header ) || 8 !== strlen( $header ) ) {
					return null;
				}

				$length_data = unpack( 'Nlength', substr( $header, 0, 4 ) );
				$length      = is_array( $length_data ) ? (int) $length_data['length'] : -1;
				$type        = substr( $header, 4, 4 );
				if ( $length < 0 || $length > 64 * MB_IN_BYTES ) {
					return null;
				}

				if ( 'IHDR' === $type ) {
					$data = fread( $handle, $length ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
					if ( ! is_string( $data ) || strlen( $data ) !== $length || 10 > strlen( $data ) ) {
						return null;
					}
					$color_type = ord( $data[9] );
					if ( 4 === $color_type || 6 === $color_type ) {
						return true;
					}
				} elseif ( 'tRNS' === $type ) {
					return true;
				} elseif ( 'IDAT' === $type || 'IEND' === $type ) {
					return false;
				} elseif ( 0 !== fseek( $handle, $length, SEEK_CUR ) ) {
					return null;
				}

				if ( 0 !== fseek( $handle, 4, SEEK_CUR ) ) {
					return null;
				}
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		return null;
	}
}
