<?php
/**
 * Lightweight WordPress stubs for isolated unit tests.
 */

define( 'ABSPATH', __DIR__ . '/wordpress/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MB_IN_BYTES', 1048576 );
define( 'JMI_VERSION', 'test' );

$GLOBALS['jmi_test_options']           = array();
$GLOBALS['jmi_test_manifests']         = array();
$GLOBALS['jmi_test_post_meta']         = array();
$GLOBALS['jmi_test_mime_types']        = array();
$GLOBALS['jmi_test_scheduled']         = array();
$GLOBALS['jmi_test_filters']           = array();
$GLOBALS['jmi_test_translation_calls'] = array();
$GLOBALS['jmi_test_remote_requests']   = array();
$GLOBALS['jmi_test_remote_response']   = array( 'response' => array( 'code' => 202 ) );
$GLOBALS['jmi_test_doing_cron']        = false;
$GLOBALS['jmi_test_multisite']         = false;
$GLOBALS['jmi_test_blog_id']           = 1;
$GLOBALS['jmi_test_blog_stack']        = array();
$GLOBALS['jmi_test_site_options']      = array();
$GLOBALS['jmi_test_site_scheduled']    = array();
$GLOBALS['jmi_test_network_options']   = array();
$GLOBALS['jmi_test_site_ids']          = array( 1 );
$GLOBALS['jmi_test_network_active']    = false;
$GLOBALS['jmi_test_is_admin']          = false;

function __( $text, $domain = 'default' ) {
	$GLOBALS['jmi_test_translation_calls'][] = $domain;
	return $text;
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function wp_unslash( $value ) {
	return stripslashes( (string) $value );
}

function wp_strip_all_tags( $text ) {
	return strip_tags( (string) $text );
}

function get_option( $name, $default = false ) {
	$options = jmi_test_current_options();
	return $options[ $name ] ?? $default;
}

function &jmi_test_current_options() {
	$blog_id = (int) ( $GLOBALS['jmi_test_blog_id'] ?? 1 );
	if ( 1 === $blog_id ) {
		return $GLOBALS['jmi_test_options'];
	}

	if ( ! isset( $GLOBALS['jmi_test_site_options'][ $blog_id ] ) ) {
		$GLOBALS['jmi_test_site_options'][ $blog_id ] = array();
	}

	return $GLOBALS['jmi_test_site_options'][ $blog_id ];
}

function &jmi_test_current_schedule() {
	$blog_id = (int) ( $GLOBALS['jmi_test_blog_id'] ?? 1 );
	if ( 1 === $blog_id ) {
		return $GLOBALS['jmi_test_scheduled'];
	}

	if ( ! isset( $GLOBALS['jmi_test_site_scheduled'][ $blog_id ] ) ) {
		$GLOBALS['jmi_test_site_scheduled'][ $blog_id ] = array();
	}

	return $GLOBALS['jmi_test_site_scheduled'][ $blog_id ];
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

function remove_filter() {}

function add_action() {}

function do_action() {}

function apply_filters( $hook, $value, ...$args ) {
	if ( isset( $GLOBALS['jmi_test_filters'][ $hook ] ) && is_callable( $GLOBALS['jmi_test_filters'][ $hook ] ) ) {
		return call_user_func( $GLOBALS['jmi_test_filters'][ $hook ], $value, ...$args );
	}

	return $value;
}

function add_option( $name, $value ) {
	$options =& jmi_test_current_options();
	if ( array_key_exists( $name, $options ) ) {
		return false;
	}

	$options[ $name ] = $value;

	return true;
}

function update_option( $name, $value ) {
	$options =& jmi_test_current_options();
	$options[ $name ] = $value;

	return true;
}

function delete_option( $name ) {
	$options =& jmi_test_current_options();
	unset( $options[ $name ] );

	return true;
}

function get_bloginfo( $show ) {
	if ( 'version' === $show ) {
		return '6.5';
	}

	return 'name' === $show ? 'Example Site' : '';
}

function home_url( $path = '' ) {
	return 'https://example.test/' . ltrim( $path, '/' );
}

function is_multisite() {
	return ! empty( $GLOBALS['jmi_test_multisite'] );
}

function get_current_blog_id() {
	return (int) ( $GLOBALS['jmi_test_blog_id'] ?? 1 );
}

function get_current_network_id() {
	return 1;
}

function get_main_site_id() {
	return 1;
}

function get_sites( $args = array() ) {
	$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );
	$number = max( 1, (int) ( $args['number'] ?? 100 ) );

	return array_slice( $GLOBALS['jmi_test_site_ids'], $offset, $number );
}

function switch_to_blog( $blog_id ) {
	$GLOBALS['jmi_test_blog_stack'][] = (int) $GLOBALS['jmi_test_blog_id'];
	$GLOBALS['jmi_test_blog_id']      = (int) $blog_id;
	return true;
}

function restore_current_blog() {
	if ( empty( $GLOBALS['jmi_test_blog_stack'] ) ) {
		return false;
	}

	$GLOBALS['jmi_test_blog_id'] = array_pop( $GLOBALS['jmi_test_blog_stack'] );
	return true;
}

function get_site_option( $name, $default = false ) {
	return $GLOBALS['jmi_test_network_options'][ $name ] ?? $default;
}

function add_site_option( $name, $value ) {
	if ( array_key_exists( $name, $GLOBALS['jmi_test_network_options'] ) ) {
		return false;
	}

	$GLOBALS['jmi_test_network_options'][ $name ] = $value;
	return true;
}

function delete_site_option( $name ) {
	unset( $GLOBALS['jmi_test_network_options'][ $name ] );
	return true;
}

function plugin_basename( $file ) {
	return basename( dirname( $file ) ) . '/' . basename( $file );
}

function is_plugin_active_for_network() {
	return ! empty( $GLOBALS['jmi_test_network_active'] );
}

function wp_generate_uuid4() {
	return '11111111-2222-4333-8444-555555555555';
}

function wp_generate_password() {
	return 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUV';
}

function wp_safe_remote_post( $url, $args ) {
	$GLOBALS['jmi_test_remote_requests'][] = array(
		'url'  => $url,
		'args' => $args,
	);

	return $GLOBALS['jmi_test_remote_response'];
}

function wp_remote_retrieve_response_code( $response ) {
	return (int) ( $response['response']['code'] ?? 0 );
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
	$schedule = jmi_test_current_schedule();
	foreach ( $schedule as $event ) {
		if ( $hook === $event['hook'] && $args === $event['args'] ) {
			return $event['timestamp'];
		}
	}

	return false;
}

function wp_schedule_single_event( $timestamp, $hook, $args = array() ) {
	$schedule   =& jmi_test_current_schedule();
	$schedule[] = array(
		'timestamp' => $timestamp,
		'hook'      => $hook,
		'args'      => $args,
	);

	return true;
}

function wp_unschedule_event( $timestamp, $hook, $args = array() ) {
	$schedule =& jmi_test_current_schedule();
	foreach ( $schedule as $index => $event ) {
		if ( $timestamp === $event['timestamp'] && $hook === $event['hook'] && $args === $event['args'] ) {
			unset( $schedule[ $index ] );
			$schedule = array_values( $schedule );
			return true;
		}
	}

	return false;
}

function wp_clear_scheduled_hook( $hook ) {
	$schedule  =& jmi_test_current_schedule();
	$schedule = array_values(
		array_filter(
			$schedule,
			static function ( $event ) use ( $hook ) {
				return $hook !== $event['hook'];
			}
		)
	);
}

function wp_convert_hr_to_bytes( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value || '-1' === $value ) {
		return -1;
	}

	$unit   = strtolower( substr( $value, -1 ) );
	$number = (int) $value;
	if ( 'g' === $unit ) {
		return $number * 1024 * 1024 * 1024;
	}
	if ( 'm' === $unit ) {
		return $number * 1024 * 1024;
	}
	if ( 'k' === $unit ) {
		return $number * 1024;
	}

	return $number;
}

function is_wp_error( $value = null ) {
	unset( $value );
	return false;
}

function is_admin() {
	return ! empty( $GLOBALS['jmi_test_is_admin'] );
}

function wp_doing_ajax() {
	return false;
}

function is_feed() {
	return false;
}

function wp_doing_cron() {
	return ! empty( $GLOBALS['jmi_test_doing_cron'] );
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function wp_normalize_path( $path ) {
	return str_replace( '\\', '/', $path );
}

function wp_upload_dir( $time = null, $create_dir = true ) {
	unset( $time, $create_dir );
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
require_once dirname( __DIR__ ) . '/includes/class-jmi-activity-log.php';
require_once dirname( __DIR__ ) . '/includes/class-jmi-diagnostics-reporter.php';
require_once dirname( __DIR__ ) . '/includes/class-jmi-manifest.php';
require_once dirname( __DIR__ ) . '/includes/class-jmi-media-status.php';
require_once dirname( __DIR__ ) . '/includes/class-jmi-source-inventory.php';
require_once dirname( __DIR__ ) . '/includes/class-jmi-converter.php';
require_once dirname( __DIR__ ) . '/includes/class-jmi-queue.php';
require_once dirname( __DIR__ ) . '/includes/class-jmi-renderer.php';
require_once dirname( __DIR__ ) . '/includes/class-jmi-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-jmi-plugin.php';
