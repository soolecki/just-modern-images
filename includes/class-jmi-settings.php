<?php
/**
 * Minimal plugin settings and status screen.
 *
 * @package JustModernImages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes one product choice and a compact operational status.
 */
final class JMI_Settings {

	const PAGE_SLUG = 'just-modern-images';
	const SETTINGS_GROUP = 'jmi_settings';

	/** @var JMI_Quality_Profiles */
	private $profiles;

	/** @var JMI_Capabilities */
	private $capabilities;

	/** @var JMI_Queue */
	private $queue;

	/**
	 * Set up the settings screen.
	 *
	 * @param JMI_Quality_Profiles $profiles     Quality profiles.
	 * @param JMI_Capabilities     $capabilities Server capabilities.
	 * @param JMI_Queue            $queue        Background queue.
	 */
	public function __construct( $profiles, $capabilities, $queue ) {
		$this->profiles     = $profiles;
		$this->capabilities = $capabilities;
		$this->queue        = $queue;
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_action( 'admin_post_jmi_rebuild', array( $this, 'handle_rebuild' ) );
		add_action( 'update_option_' . JMI_Quality_Profiles::OPTION_NAME, array( $this, 'handle_quality_change' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( JMI_PLUGIN_FILE ), array( $this, 'add_action_link' ) );
	}

	/**
	 * Add the page under Settings.
	 *
	 * @return void
	 */
	public function add_page() {
		add_options_page(
			__( 'Just Modern Images', 'just-modern-images' ),
			__( 'Just Modern Images', 'just-modern-images' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the single preference managed by the plugin.
	 *
	 * @return void
	 */
	public function register_setting() {
		register_setting(
			self::SETTINGS_GROUP,
			JMI_Quality_Profiles::OPTION_NAME,
			array(
				'type'              => 'string',
				'default'           => JMI_Quality_Profiles::DEFAULT_PROFILE,
				'sanitize_callback' => array( $this->profiles, 'sanitize' ),
			)
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$selected     = $this->profiles->selected_key();
		$capabilities = $this->capabilities->get_all();
		$status       = $this->queue->status();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Just Modern Images', 'just-modern-images' ); ?></h1>
			<p><?php esc_html_e( 'Modern image formats are generated in the background. Original files always remain available.', 'just-modern-images' ); ?></p>

			<?php if ( isset( $_GET['jmi-rebuild'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The Media Library scan has been queued.', 'just-modern-images' ); ?></p></div>
			<?php endif; ?>

			<form action="options.php" method="post">
				<?php settings_fields( self::SETTINGS_GROUP ); ?>
				<h2><?php esc_html_e( 'Image quality', 'just-modern-images' ); ?></h2>
				<fieldset>
					<?php foreach ( $this->profiles->all() as $key => $profile ) : ?>
						<p>
							<label>
								<input type="radio" name="<?php echo esc_attr( JMI_Quality_Profiles::OPTION_NAME ); ?>" value="<?php echo esc_attr( $key ); ?>" <?php checked( $selected, $key ); ?>>
								<strong><?php echo esc_html( $profile['label'] ); ?></strong>
								&mdash; <?php echo esc_html( $profile['description'] ); ?>
							</label>
						</p>
					<?php endforeach; ?>
				</fieldset>
				<?php submit_button(); ?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Status', 'just-modern-images' ); ?></h2>
			<table class="widefat striped" style="max-width: 760px">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'AVIF', 'just-modern-images' ); ?></th>
						<td><?php echo esc_html( $this->capability_label( $capabilities['image/avif'] ?? array() ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'WebP', 'just-modern-images' ); ?></th>
						<td><?php echo esc_html( $this->capability_label( $capabilities['image/webp'] ?? array() ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Media Library scan', 'just-modern-images' ); ?></th>
						<td><?php echo esc_html( ucfirst( (string) $status['status'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Attachments processed', 'just-modern-images' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( (int) $status['processed'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Files generated', 'just-modern-images' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( (int) $status['generated'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Skipped / failed', 'just-modern-images' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( (int) $status['skipped'] ) . ' / ' . number_format_i18n( (int) $status['failed'] ) ); ?></td>
					</tr>
				</tbody>
			</table>

			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="jmi_rebuild">
				<?php wp_nonce_field( 'jmi_rebuild_media_library' ); ?>
				<?php submit_button( __( 'Scan Media Library again', 'just-modern-images' ), 'secondary' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Queue a fresh scan after the quality profile changes.
	 *
	 * @param mixed $old_value Previous value.
	 * @param mixed $new_value New value.
	 * @return void
	 */
	public function handle_quality_change( $old_value, $new_value ) {
		if ( $this->profiles->sanitize( $old_value ) !== $this->profiles->sanitize( $new_value ) ) {
			$this->queue->start_scan( 'quality_changed' );
		}
	}

	/**
	 * Handle the rebuild action.
	 *
	 * @return void
	 */
	public function handle_rebuild() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'just-modern-images' ) );
		}

		check_admin_referer( 'jmi_rebuild_media_library' );
		$this->capabilities->invalidate();
		$this->queue->start_scan( 'manual' );

		wp_safe_redirect( add_query_arg( 'jmi-rebuild', '1', admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ) );
		exit;
	}

	/**
	 * Add a settings shortcut on the Plugins screen.
	 *
	 * @param array<int, string> $links Existing links.
	 * @return array<int, string>
	 */
	public function add_action_link( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ) . '">' . esc_html__( 'Settings', 'just-modern-images' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Convert a capability state into a short human-readable label.
	 *
	 * @param array<string, mixed> $capability Capability data.
	 * @return string
	 */
	private function capability_label( $capability ) {
		$state = $capability['state'] ?? 'unknown';

		if ( 'available' === $state ) {
			return __( 'Available and verified', 'just-modern-images' );
		}

		if ( 'unavailable' === $state ) {
			return __( 'Unavailable — this format will be skipped', 'just-modern-images' );
		}

		return __( 'Waiting for a capability check', 'just-modern-images' );
	}
}

