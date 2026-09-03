<?php
/**
 * Plugin composition root.
 *
 * @package JustModernImages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$jmi_activity_log_file = __DIR__ . '/class-jmi-activity-log.php';
if ( ! class_exists( 'JMI_Activity_Log', false ) && is_readable( $jmi_activity_log_file ) ) {
	require_once $jmi_activity_log_file;
}
unset( $jmi_activity_log_file );

/**
 * Wires plugin services to WordPress.
 */
final class JMI_Plugin {

	const DATA_REVISION_OPTION = 'jmi_data_revision';
	const DATA_REVISION        = 2;
	const LEGACY_VERSION       = '0.11.3';

	/**
	 * Shared plugin instance.
	 *
	 * @var JMI_Plugin|null
	 */
	private static $instance;

	/**
	 * Background conversion queue.
	 *
	 * @var JMI_Queue
	 */
	private $queue;

	/**
	 * Attachment manifest storage.
	 *
	 * @var JMI_Manifest
	 */
	private $manifest;

	/**
	 * Per-attachment status storage.
	 *
	 * @var JMI_Media_Status
	 */
	private $media_status;

	/**
	 * Return the plugin instance.
	 *
	 * @return JMI_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize plugin services.
	 */
	private function __construct() {
		$profiles           = new JMI_Quality_Profiles();
		$capabilities       = new JMI_Capabilities();
		$activity_log       = class_exists( 'JMI_Activity_Log', false ) ? new JMI_Activity_Log() : null;
		$this->manifest     = new JMI_Manifest();
		$this->media_status = new JMI_Media_Status();
		$inventory          = new JMI_Source_Inventory();
		$converter          = new JMI_Converter( $profiles, $capabilities, $inventory, $this->manifest );
		$this->queue        = new JMI_Queue( $converter, $profiles, $this->media_status, $capabilities, $activity_log );
		$reporter           = class_exists( 'JMI_Diagnostics_Reporter', false )
			? new JMI_Diagnostics_Reporter(
				function () use ( $capabilities ) {
					$environment = $capabilities->diagnostic_summary();
					return array(
						'snapshot' => $this->queue->diagnostic_snapshot(),
						'formats'  => $environment['formats'] ?? array(),
					);
				}
			)
			: null;
		$renderer           = new JMI_Renderer( $this->manifest, $this->queue );
		$settings           = new JMI_Settings( $profiles, $capabilities, $this->queue, $this->media_status, $activity_log, $reporter );
		$media_admin        = new JMI_Media_Admin( $this->media_status, $this->manifest, $this->queue, $profiles, $capabilities );

		$this->queue->register();
		if ( $reporter ) {
			$reporter->register();
			$reporter->bootstrap_history( $activity_log ? $activity_log->entries() : array() );
		}
		$renderer->register();
		$settings->register();
		$media_admin->register();
		$this->maybe_upgrade();
		if ( method_exists( $this->queue, 'ensure_scan_scheduled' ) ) {
			$this->queue->ensure_scan_scheduled();
		}

		add_action( 'delete_attachment', array( $this, 'delete_attachment_variants' ), 10, 1 );
		add_action( 'wp_initialize_site', array( __CLASS__, 'initialize_site' ), 200, 1 );
	}

	/**
	 * Start a fresh resumable scan after installing a new plugin version.
	 *
	 * @return void
	 */
	private function maybe_upgrade() {
		if ( (int) get_option( self::DATA_REVISION_OPTION, 0 ) >= self::DATA_REVISION ) {
			return;
		}

		$this->queue->start_scan( 'upgrade' );
		update_option( self::DATA_REVISION_OPTION, self::DATA_REVISION, false );

		// Keep cached 0.11.3 bootstraps quiet during a rolling OPcache refresh.
		self::store_legacy_version();
	}

	/**
	 * Prepare capability data and queue existing media after activation.
	 *
	 * @param bool $network_wide Whether the plugin was activated for the network.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		if ( $network_wide && is_multisite() ) {
			self::for_each_site(
				static function () {
					self::activate_site( false );
				}
			);
			return;
		}

		self::activate_site( true );
	}

	/**
	 * Initialize one site's isolated options and queue.
	 *
	 * @param bool $probe Whether to run the capability probe immediately.
	 * @return void
	 */
	private static function activate_site( $probe ) {
		if ( false === get_option( JMI_Quality_Profiles::OPTION_NAME, false ) ) {
			add_option( JMI_Quality_Profiles::OPTION_NAME, JMI_Quality_Profiles::DEFAULT_PROFILE, '', false );
		}

		$capabilities = new JMI_Capabilities();
		if ( $probe ) {
			$capabilities->probe_all();
		}

		$profiles = new JMI_Quality_Profiles();
		$manifest = new JMI_Manifest();
		$status   = new JMI_Media_Status();
		$queue    = new JMI_Queue(
			new JMI_Converter( $profiles, $capabilities, new JMI_Source_Inventory(), $manifest ),
			$profiles,
			$status,
			$capabilities,
			class_exists( 'JMI_Activity_Log', false ) ? new JMI_Activity_Log() : null
		);
		$queue->start_scan( 'activation' );

		update_option( self::DATA_REVISION_OPTION, self::DATA_REVISION, false );
		self::store_legacy_version();
	}

	/**
	 * Persist the final legacy migration value without the request consistency filter.
	 *
	 * @return void
	 */
	private static function store_legacy_version() {
		$has_filter = function_exists( 'jmi_legacy_version_for_request' );
		if ( $has_filter ) {
			remove_filter( 'pre_option_jmi_version', 'jmi_legacy_version_for_request' );
		}

		update_option( 'jmi_version', self::LEGACY_VERSION, false );

		if ( $has_filter ) {
			add_filter( 'pre_option_jmi_version', 'jmi_legacy_version_for_request' );
		}
	}

	/**
	 * Stop background work. Generated files remain harmless companions.
	 *
	 * @param bool $network_wide Whether the plugin was deactivated for the network.
	 * @return void
	 */
	public static function deactivate( $network_wide = false ) {
		if ( $network_wide && is_multisite() ) {
			self::for_each_site( array( __CLASS__, 'deactivate_site' ) );
			return;
		}

		self::deactivate_site();
	}

	/**
	 * Initialize a newly created site when the plugin is network active.
	 *
	 * @param WP_Site|mixed $new_site New site object.
	 * @return void
	 */
	public static function initialize_site( $new_site ) {
		if ( ! is_multisite() || ! self::is_network_active() || ! is_object( $new_site ) || empty( $new_site->blog_id ) ) {
			return;
		}

		switch_to_blog( (int) $new_site->blog_id );
		try {
			self::activate_site( false );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Stop work for the current site.
	 *
	 * @return void
	 */
	public static function deactivate_site() {
		JMI_Queue::unschedule();
		if ( class_exists( 'JMI_Diagnostics_Reporter', false ) ) {
			JMI_Diagnostics_Reporter::unschedule();
		}
	}

	/**
	 * Run a callback in every site's option and media context.
	 *
	 * @param callable $callback Site callback.
	 * @return void
	 */
	private static function for_each_site( $callback ) {
		$offset = 0;
		do {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 100,
					'offset' => $offset,
				)
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				try {
					call_user_func( $callback );
				} finally {
					restore_current_blog();
				}
			}

			$count   = count( $site_ids );
			$offset += $count;
		} while ( 100 === $count );
	}

	/**
	 * Determine whether this plugin is active for the whole network.
	 *
	 * @return bool
	 */
	private static function is_network_active() {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			$file = ABSPATH . 'wp-admin/includes/plugin.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}

		return function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( plugin_basename( JMI_PLUGIN_FILE ) );
	}

	/**
	 * Remove only files owned by the deleted attachment manifest.
	 *
	 * @param mixed $attachment_id Attachment ID.
	 * @return void
	 */
	public function delete_attachment_variants( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$this->manifest->delete_variants( $attachment_id );
		$this->media_status->delete( $attachment_id );
	}
}
