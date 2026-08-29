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
		$profiles       = new JMI_Quality_Profiles();
		$capabilities   = new JMI_Capabilities();
		$this->manifest = new JMI_Manifest();
		$inventory      = new JMI_Source_Inventory();
		$converter      = new JMI_Converter( $profiles, $capabilities, $inventory, $this->manifest );
		$this->queue    = new JMI_Queue( $converter );
		$renderer       = new JMI_Renderer( $this->manifest );
		$settings       = new JMI_Settings( $profiles, $capabilities, $this->queue );

		$this->queue->register();
		$renderer->register();
		$settings->register();

		add_action( 'delete_attachment', array( $this, 'delete_attachment_variants' ), 10, 1 );
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
		$queue    = new JMI_Queue(
			new JMI_Converter( $profiles, $capabilities, new JMI_Source_Inventory(), $manifest )
		);
		$queue->start_scan( 'activation' );

		update_option( 'jmi_version', JMI_VERSION, false );
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
		$this->manifest->delete_variants( absint( $attachment_id ) );
	}
}
