<?php
/**
 * Lightweight WordPress stubs for isolated unit tests.
 */

define( 'ABSPATH', __DIR__ . '/wordpress/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MB_IN_BYTES', 1048576 );
define( 'JMI_VERSION', 'test' );

$GLOBALS['jmi_test_options']   = array();
$GLOBALS['jmi_test_manifests'] = array();
$GLOBALS['jmi_test_post_meta'] = array();
$GLOBALS['jmi_test_mime_types'] = array();
$GLOBALS['jmi_test_scheduled'] = array();

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
	unset( $single );
	if ( JMI_Manifest::META_KEY === $key ) {
		return $GLOBALS['jmi_test_manifests'][ $attachment_id ] ?? '';
	}

	return $GLOBALS['jmi_test_post_meta'][ $attachment_id ][ $key ] ?? '';
}

function update_post_meta( $attachment_id, $key, $value ) {
	if ( class_exists( 'JMI_Manifest', false ) && JMI_Manifest::META_KEY === $key ) {
		$GLOBALS['jmi_test_manifests'][ $attachment_id ] = $value;
	}
	$GLOBALS['jmi_test_post_meta'][ $attachment_id ][ $key ] = $value;

	return true;
}

function delete_post_meta( $attachment_id, $key ) {
	if ( class_exists( 'JMI_Manifest', false ) && JMI_Manifest::META_KEY === $key ) {
		unset( $GLOBALS['jmi_test_manifests'][ $attachment_id ] );
	}
	unset( $GLOBALS['jmi_test_post_meta'][ $attachment_id ][ $key ] );

	return true;
}

function absint( $value ) {
	return abs( (int) $value );
}

function add_filter() {}

function add_action() {}

function do_action() {}

function apply_filters( $hook, $value ) {
	unset( $hook );
	return $value;
}

function update_option( $name, $value ) {
	$GLOBALS['jmi_test_options'][ $name ] = $value;

	return true;
}

function delete_option( $name ) {
	unset( $GLOBALS['jmi_test_options'][ $name ] );

	return true;
}

function get_bloginfo( $show ) {
	return 'version' === $show ? '6.5' : '';
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

function wp_image_editor_supports() {
	return false;
}

function wp_delete_file( $path ) {
	if ( is_file( $path ) ) {
		unlink( $path );
	}
}

function wp_filesize( $path ) {
	return filesize( $path );
}

function get_post_mime_type( $attachment_id ) {
	return $GLOBALS['jmi_test_mime_types'][ $attachment_id ] ?? '';
}

function wp_next_scheduled( $hook, $args = array() ) {
	foreach ( $GLOBALS['jmi_test_scheduled'] as $event ) {
		if ( $hook === $event['hook'] && $args === $event['args'] ) {
			return $event['timestamp'];
		}
	}

	return false;
}

function wp_schedule_single_event( $timestamp, $hook, $args = array() ) {
	$GLOBALS['jmi_test_scheduled'][] = array(
		'timestamp' => $timestamp,
		'hook'      => $hook,
		'args'      => $args,
	);

	return true;
}

function wp_unschedule_event( $timestamp, $hook, $args = array() ) {
	foreach ( $GLOBALS['jmi_test_scheduled'] as $index => $event ) {
		if ( $timestamp === $event['timestamp'] && $hook === $event['hook'] && $args === $event['args'] ) {
			unset( $GLOBALS['jmi_test_scheduled'][ $index ] );
			$GLOBALS['jmi_test_scheduled'] = array_values( $GLOBALS['jmi_test_scheduled'] );
			return true;
		}
	}

	return false;
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
require_once dirname( __DIR__ ) . '/includes/class-jmi-capabilities.php';
require_once dirname( __DIR__ ) . '/includes/class-jmi-diagnostics.php';
require_once dirname( __DIR__ ) . '/includes/class-jmi-manifest.php';
require_once dirname( __DIR__ ) . '/includes/class-jmi-media-status.php';
require_once dirname( __DIR__ ) . '/includes/class-jmi-converter.php';
require_once dirname( __DIR__ ) . '/includes/class-jmi-queue.php';
require_once dirname( __DIR__ ) . '/includes/class-jmi-renderer.php';
require_once dirname( __DIR__ ) . '/includes/class-jmi-settings.php';
