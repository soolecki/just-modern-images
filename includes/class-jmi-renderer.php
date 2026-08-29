<?php
/**
 * Frontend image rendering.
 *
 * @package JustModernImages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds modern sources while retaining the original image fallback.
 */
final class JMI_Renderer {

	const PROCESSED_ATTRIBUTE = 'data-jmi-processed';

	/** @var JMI_Manifest */
	private $manifest;

	/**
	 * Set up the renderer.
	 *
	 * @param JMI_Manifest $manifest Manifest storage.
	 */
	public function __construct( $manifest ) {
		$this->manifest = $manifest;
	}

	/**
	 * Register frontend image filters.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'wp_content_img_tag', array( $this, 'filter_content_image' ), 20, 3 );
		add_filter( 'wp_get_attachment_image', array( $this, 'filter_attachment_image' ), 20, 5 );
	}

	/**
	 * Filter an image found in post content.
	 *
	 * @param mixed $image         Image HTML.
	 * @param mixed $context       Rendering context.
	 * @param mixed $attachment_id Attachment ID.
	 * @return mixed
	 */
	public function filter_content_image( $image, $context, $attachment_id ) {
		return $this->render( $image, $attachment_id, is_string( $context ) ? $context : 'the_content' );
	}

	/**
	 * Filter HTML returned directly by wp_get_attachment_image().
	 *
	 * Public hook arguments are deliberately left untyped because themes and
	 * plugins do not always pass the shapes documented by WordPress.
	 *
	 * @param mixed $html          Image HTML.
	 * @param mixed $attachment_id Attachment ID.
	 * @param mixed $size          Requested image size.
	 * @param mixed $icon          Whether the image represents an icon.
	 * @param mixed $attr          Image attributes.
	 * @return mixed
	 */
	public function filter_attachment_image( $html, $attachment_id, $size, $icon, $attr ) {
		return $this->render( $html, $attachment_id, 'wp_get_attachment_image' );
	}

	/**
	 * Add a picture wrapper when validated variants are available.
	 *
	 * @param mixed  $html          Image HTML.
	 * @param mixed  $attachment_id Attachment ID.
	 * @param string $context       Rendering context.
	 * @return mixed
	 */
	private function render( $html, $attachment_id, $context ) {
		if ( ! is_string( $html ) || '' === $html || ! $this->should_render() ) {
			return $html;
		}

		if (
			false !== stripos( $html, '<picture' ) ||
			false !== stripos( $html, self::PROCESSED_ATTRIBUTE )
		) {
			return $html;
		}

		$attachment_id = $this->normalize_attachment_id( $attachment_id );
		if ( ! $attachment_id ) {
			return $html;
		}

		if ( ! apply_filters( 'jmi_should_render_attachment', true, $attachment_id, $context, $html ) ) {
			return $html;
		}

		$manifest = $this->manifest->get( $attachment_id );
		if ( empty( $manifest['sources'] ) ) {
			return $html;
		}

		$tags = new WP_HTML_Tag_Processor( $html );
		if ( ! $tags->next_tag( 'img' ) ) {
			return $html;
		}

		$src    = $tags->get_attribute( 'src' );
		$srcset = $tags->get_attribute( 'srcset' );
		$sizes  = $tags->get_attribute( 'sizes' );

		$candidates = $this->parse_candidates(
			is_string( $src ) ? $src : '',
			is_string( $srcset ) ? $srcset : '',
			$manifest['sources']
		);

		if ( empty( $candidates ) ) {
			return $html;
		}

		$source_markup = '';
		foreach ( array( 'image/avif', 'image/webp' ) as $mime_type ) {
			$modern_srcset = $this->modern_srcset( $candidates, $mime_type );
			if ( '' === $modern_srcset ) {
				continue;
			}

			$source_markup .= '<source type="' . esc_attr( $mime_type ) . '" srcset="' . esc_attr( $modern_srcset ) . '"';
			if ( is_string( $sizes ) && '' !== trim( $sizes ) && false !== strpos( $modern_srcset, 'w' ) ) {
				$source_markup .= ' sizes="' . esc_attr( $sizes ) . '"';
			}
			$source_markup .= '>';
		}

		if ( '' === $source_markup ) {
			return $html;
		}

		$tags->set_attribute( self::PROCESSED_ATTRIBUTE, '1' );
		$image = $tags->get_updated_html();

		return '<picture class="jmi-picture">' . $source_markup . $image . '</picture>';
	}

	/**
	 * Pair original srcset candidates with manifest source records.
	 *
	 * @param string                      $src     Image src.
	 * @param string                      $srcset  Image srcset.
	 * @param array<string, array<mixed>> $sources Manifest source records.
	 * @return array<int, array<string, mixed>>
	 */
	private function parse_candidates( $src, $srcset, $sources ) {
		$raw_candidates = array();

		if ( '' !== trim( $srcset ) ) {
			foreach ( explode( ',', html_entity_decode( $srcset, ENT_QUOTES, 'UTF-8' ) ) as $candidate ) {
				$parts = preg_split( '/\s+/', trim( $candidate ), 2 );
				if ( ! empty( $parts[0] ) ) {
					$raw_candidates[] = array(
						'url'        => $parts[0],
						'descriptor' => isset( $parts[1] ) ? trim( $parts[1] ) : '',
					);
				}
			}
		} elseif ( '' !== trim( $src ) ) {
			$raw_candidates[] = array(
				'url'        => html_entity_decode( $src, ENT_QUOTES, 'UTF-8' ),
				'descriptor' => '',
			);
		}

		$matched = array();
		foreach ( $raw_candidates as $candidate ) {
			$source_key = $this->match_source_key( $candidate['url'], $sources );
			if ( null === $source_key ) {
				continue;
			}

			$matched[] = array(
				'source'     => $sources[ $source_key ],
				'descriptor' => $candidate['descriptor'],
			);
		}

		return $matched;
	}

	/**
	 * Match an original URL by its relative uploads path.
	 *
	 * @param string                      $url     Candidate URL.
	 * @param array<string, array<mixed>> $sources Manifest sources.
	 * @return string|null
	 */
	private function match_source_key( $url, $sources ) {
		$url_path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $url_path ) ) {
			return null;
		}

		$url_path = wp_normalize_path( rawurldecode( $url_path ) );

		foreach ( $sources as $source_key => $source ) {
			if ( empty( $source['relative_path'] ) || ! is_string( $source['relative_path'] ) ) {
				continue;
			}

			$relative_path = '/' . ltrim( wp_normalize_path( $source['relative_path'] ), '/' );
			if ( strlen( $url_path ) >= strlen( $relative_path ) && substr( $url_path, -strlen( $relative_path ) ) === $relative_path ) {
				return $source_key;
			}
		}

		return null;
	}

	/**
	 * Build a modern srcset using descriptors from the original image.
	 *
	 * @param array<int, array<string, mixed>> $candidates Original candidates.
	 * @param string                           $mime_type  Output MIME type.
	 * @return string
	 */
	private function modern_srcset( $candidates, $mime_type ) {
		$srcset = array();

		foreach ( $candidates as $candidate ) {
			$source  = $candidate['source'];
			$variant = $source['variants'][ $mime_type ] ?? null;

			if (
				! is_array( $variant ) ||
				'ready' !== ( $variant['status'] ?? '' ) ||
				empty( $variant['relative_path'] )
			) {
				continue;
			}

			$url = $this->variant_url( $variant['relative_path'] );
			if ( ! $url ) {
				continue;
			}

			$item = $url;
			if ( '' !== $candidate['descriptor'] ) {
				$item .= ' ' . $candidate['descriptor'];
			}
			$srcset[] = $item;
		}

		return implode( ', ', array_unique( $srcset ) );
	}

	/**
	 * Build a public URL for a relative companion path.
	 *
	 * @param string $relative_path Relative upload path.
	 * @return string|false
	 */
	private function variant_url( $relative_path ) {
		$relative_path = wp_normalize_path( (string) $relative_path );
		if (
			'' === $relative_path ||
			preg_match( '#(^|/)\.\.(/|$)#', $relative_path ) ||
			! in_array( strtolower( pathinfo( $relative_path, PATHINFO_EXTENSION ) ), array( 'avif', 'webp' ), true )
		) {
			return false;
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['baseurl'] ) ) {
			return false;
		}

		$segments = array_map( 'rawurlencode', explode( '/', ltrim( $relative_path, '/' ) ) );
		$url      = trailingslashit( $uploads['baseurl'] ) . implode( '/', $segments );

		return apply_filters( 'jmi_variant_url', $url, $relative_path );
	}

	/**
	 * Normalize third-party attachment IDs without throwing a type error.
	 *
	 * @param mixed $attachment_id Attachment ID.
	 * @return int
	 */
	private function normalize_attachment_id( $attachment_id ) {
		if ( is_int( $attachment_id ) || is_string( $attachment_id ) || is_float( $attachment_id ) ) {
			return absint( $attachment_id );
		}

		return 0;
	}

	/**
	 * Exclude non-frontend document contexts.
	 *
	 * @return bool
	 */
	private function should_render() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}

		if ( is_feed() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		return true;
	}
}

