<?php
/**
 * Lightweight WordPress stubs for isolated unit tests.
 */

define( 'ABSPATH', __DIR__ . '/wordpress/' );

$GLOBALS['jmi_test_options']   = array();
$GLOBALS['jmi_test_manifests'] = array();

function __( $text ) {
	return $text;
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function get_option( $name, $default = false ) {
	return $GLOBALS['jmi_test_options'][ $name ] ?? $default;
}

function get_post_meta( $attachment_id, $key, $single = false ) {
	return $GLOBALS['jmi_test_manifests'][ $attachment_id ] ?? '';
}

function absint( $value ) {
	return abs( (int) $value );
}

function add_filter() {}

function apply_filters( $hook, $value ) {
	return $value;
}

function is_admin() {
	return false;
}

function wp_doing_ajax() {
	return false;
}

function is_feed() {
	return false;
}

function wp_doing_cron() {
	return false;
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function wp_normalize_path( $path ) {
	return str_replace( '\\', '/', $path );
}

function wp_upload_dir() {
	return array(
		'baseurl' => 'https://example.test/wp-content/uploads',
		'basedir' => sys_get_temp_dir(),
		'error'   => false,
	);
}

function trailingslashit( $value ) {
	return rtrim( $value, '/\\' ) . '/';
}

function esc_attr( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

/**
 * Minimal tag processor used to exercise the renderer contract in isolation.
 */
class WP_HTML_Tag_Processor {

	private $html;
	private $tag;
	private $offset;
	private $updates = array();

	public function __construct( $html ) {
		$this->html = $html;
	}

	public function next_tag( $tag_name ) {
		if ( 'img' !== strtolower( $tag_name ) ) {
			return false;
		}

		if ( ! preg_match( '/<img\b[^>]*>/i', $this->html, $match, PREG_OFFSET_CAPTURE ) ) {
			return false;
		}

		$this->tag    = $match[0][0];
		$this->offset = $match[0][1];

		return true;
	}

	public function get_attribute( $name ) {
		$pattern = '/\s' . preg_quote( $name, '/' ) . '\s*=\s*(["\'])(.*?)\1/i';
		if ( preg_match( $pattern, $this->tag, $match ) ) {
			return html_entity_decode( $match[2], ENT_QUOTES, 'UTF-8' );
		}

		return null;
	}

	public function set_attribute( $name, $value ) {
		$this->updates[ $name ] = $value;
	}

	public function get_updated_html() {
		$tag = $this->tag;
		foreach ( $this->updates as $name => $value ) {
			$attribute = $name . '="' . esc_attr( $value ) . '"';
			$pattern   = '/\s' . preg_quote( $name, '/' ) . '\s*=\s*(["\']).*?\1/i';

			if ( preg_match( $pattern, $tag ) ) {
				$tag = preg_replace( $pattern, ' ' . $attribute, $tag, 1 );
			} else {
				$tag = preg_replace( '/\s*\/>$/', ' ' . $attribute . ' />', $tag, 1, $count );
				if ( 0 === $count ) {
					$tag = preg_replace( '/>$/', ' ' . $attribute . '>', $tag, 1 );
				}
			}
		}

		return substr_replace( $this->html, $tag, $this->offset, strlen( $this->tag ) );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-jmi-quality-profiles.php';
require_once dirname( __DIR__ ) . '/includes/class-jmi-error-trap.php';
require_once dirname( __DIR__ ) . '/includes/class-jmi-manifest.php';
require_once dirname( __DIR__ ) . '/includes/class-jmi-renderer.php';
