<?php
/**
 * Plugin composition root.
 *
 * @package JustModernImages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires plugin services to WordPress.
 */
final class JMI_Plugin {

	const DATA_REVISION_OPTION = 'jmi_data_revision';
	const DATA_REVISION        = 1;
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
		$this->manifest     = new JMI_Manifest();
		$this->media_status = new JMI_Media_Status();
		$inventory          = new JMI_Source_Inventory();
		$converter          = new JMI_Converter( $profiles, $capabilities, $inventory, $this->manifest );
		$this->queue        = new JMI_Queue( $converter, $profiles, $this->media_status );
		$renderer           = new JMI_Renderer( $this->manifest, $this->queue );
		$settings           = new JMI_Settings( $profiles, $capabilities, $this->queue, $this->media_status );
		$media_admin        = new JMI_Media_Admin( $this->media_status, $this->manifest, $this->queue, $profiles, $capabilities );

		$this->queue->register();
		$renderer->register();
		$settings->register();
		$media_admin->register();
		$this->maybe_upgrade();
		if ( method_exists( $this->queue, 'ensure_scan_scheduled' ) ) {
			$this->queue->ensure_scan_scheduled();
		}

		add_action( 'delete_attachment', array( $this, 'delete_attachment_variants' ), 10, 1 );
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
		update_option( 'jmi_version', self::LEGACY_VERSION, false );
	}

	/**
	 * Prepare capability data and queue existing media after activation.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( false === get_option( JMI_Quality_Profiles::OPTION_NAME, false ) ) {
			add_option( JMI_Quality_Profiles::OPTION_NAME, JMI_Quality_Profiles::DEFAULT_PROFILE, '', false );
		}

		$capabilities = new JMI_Capabilities();
		$capabilities->probe_all();

		$profiles = new JMI_Quality_Profiles();
		$manifest = new JMI_Manifest();
		$status   = new JMI_Media_Status();
		$queue    = new JMI_Queue(
			new JMI_Converter( $profiles, $capabilities, new JMI_Source_Inventory(), $manifest ),
			$profiles,
			$status
		);
		$queue->start_scan( 'activation' );

		update_option( self::DATA_REVISION_OPTION, self::DATA_REVISION, false );
		update_option( 'jmi_version', self::LEGACY_VERSION, false );
	}

	/**
	 * Stop background work. Generated files remain harmless companions.
	 *
	 * @return void
	 */
	public static function deactivate() {
		JMI_Queue::unschedule();
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
