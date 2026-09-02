<?php
/**
 * Minimal plugin settings and status screen.
 *
 * @package JustModernImages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$jmi_diagnostics_file = __DIR__ . '/class-jmi-diagnostics.php';
if ( ! class_exists( 'JMI_Diagnostics', false ) && is_readable( $jmi_diagnostics_file ) ) {
	require_once $jmi_diagnostics_file;
}
unset( $jmi_diagnostics_file );

/**
 * Exposes one product choice and a compact operational status.
 */
final class JMI_Settings {

	const PAGE_SLUG      = 'just-modern-images';
	const SETTINGS_GROUP = 'jmi_settings';

	/**
	 * Quality profile provider.
	 *
	 * @var JMI_Quality_Profiles
	 */
	private $profiles;

	/**
	 * Verified server capabilities.
	 *
	 * @var JMI_Capabilities
	 */
	private $capabilities;

	/**
	 * Background conversion queue.
	 *
	 * @var JMI_Queue
	 */
	private $queue;

	/**
	 * Queryable Media Library status.
	 *
	 * @var JMI_Media_Status
	 */
	private $media_status;

	/**
	 * Set up the settings screen.
	 *
	 * @param JMI_Quality_Profiles $profiles     Quality profiles.
	 * @param JMI_Capabilities     $capabilities Server capabilities.
	 * @param JMI_Queue            $queue        Background queue.
	 * @param JMI_Media_Status     $media_status Media Library status.
	 */
	public function __construct( $profiles, $capabilities, $queue, $media_status ) {
		$this->profiles     = $profiles;
		$this->capabilities = $capabilities;
		$this->queue        = $queue;
		$this->media_status = $media_status;
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
		add_action( 'admin_post_jmi_probe', array( $this, 'handle_probe' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
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

		$selected            = $this->profiles->selected_key();
		$server              = $this->server_summary();
		$capabilities        = $server['formats'];
		$status              = $this->queue->status();
		$stats               = $this->media_status->library_stats( $this->profiles->generation_profile() );
		$profiles            = $this->profiles->all();
		$profile             = $profiles[ $selected ];
		$ready_pct           = $stats['total'] ? (int) round( ( $stats['ready'] / $stats['total'] ) * 100 ) : 0;
		$reviewed_pct        = $stats['total'] ? (int) round( ( $stats['reviewed'] / $stats['total'] ) * 100 ) : 0;
		$last_reason         = sanitize_key( $status['last_reason'] ?? '' );
		$attention_media_url = add_query_arg( 'jmi-status', 'attention', admin_url( 'upload.php?mode=list' ) );
		?>
		<div class="wrap jmi-admin">
			<div class="jmi-heading">
				<div>
					<h1><?php esc_html_e( 'Just Modern Images', 'just-modern-images' ); ?></h1>
					<p><?php esc_html_e( 'Modern formats are generated automatically. Your original files always remain untouched.', 'just-modern-images' ); ?></p>
				</div>
				<span class="jmi-version"><?php echo esc_html( 'v' . JMI_VERSION ); ?></span>
			</div>

			<?php if ( isset( $_GET['jmi-rebuild'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The Media Library scan has been queued.', 'just-modern-images' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['jmi-probe'] ) && 'deferred' === sanitize_key( wp_unslash( $_GET['jmi-probe'] ) ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'The server check was deferred until this server reloads the current plugin version.', 'just-modern-images' ); ?></p></div>
			<?php elseif ( isset( $_GET['jmi-probe'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'This server completed a fresh image format check.', 'just-modern-images' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $server['rolling_update'] ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'This server is still using an older cached plugin component. Processing remains safe and will resume after the server reloads the current version.', 'just-modern-images' ); ?></p></div>
			<?php endif; ?>

			<?php if ( $stats['failed'] ) : ?>
				<div class="jmi-alert">
					<div>
						<strong>
							<?php /* translators: %s: number of media items needing attention. */ ?>
							<?php echo esc_html( sprintf( _n( '%s image needs attention.', '%s images need attention.', $stats['failed'], 'just-modern-images' ), number_format_i18n( $stats['failed'] ) ) ); ?>
						</strong>
						<?php if ( $last_reason ) : ?>
							<span><?php echo esc_html( $this->diagnostic_label( $last_reason ) ); ?> <code><?php echo esc_html( $last_reason ); ?></code></span>
						<?php endif; ?>
					</div>
					<a class="button" href="<?php echo esc_url( $attention_media_url ); ?>"><?php esc_html_e( 'Review images', 'just-modern-images' ); ?></a>
				</div>
			<?php endif; ?>

			<section class="jmi-overview">
				<div class="jmi-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( $ready_pct ); ?>" style="--jmi-progress: <?php echo esc_attr( $ready_pct ); ?>%">
					<div><strong><?php echo esc_html( $ready_pct . '%' ); ?></strong><span><?php esc_html_e( 'ready', 'just-modern-images' ); ?></span></div>
				</div>
				<div class="jmi-overview-copy">
					<h2><?php esc_html_e( 'Media Library', 'just-modern-images' ); ?></h2>
					<?php /* translators: 1: ready images, 2: all eligible images. */ ?>
					<p><?php echo esc_html( sprintf( __( '%1$s of %2$s eligible images are fully ready.', 'just-modern-images' ), number_format_i18n( $stats['ready'] ), number_format_i18n( $stats['total'] ) ) ); ?></p>
					<div class="jmi-linear-progress"><span style="width: <?php echo esc_attr( $reviewed_pct ); ?>%"></span></div>
					<?php /* translators: %s: percentage of the Media Library reviewed. */ ?>
					<small><?php echo esc_html( sprintf( __( 'Library reviewed: %s%%', 'just-modern-images' ), $reviewed_pct ) ); ?></small>
				</div>
			</section>

			<div class="jmi-stat-grid">
				<div class="jmi-stat"><span><?php esc_html_e( 'Ready', 'just-modern-images' ); ?></span><strong><?php echo esc_html( number_format_i18n( $stats['ready'] ) ); ?></strong></div>
				<div class="jmi-stat"><span><?php esc_html_e( 'Partly ready', 'just-modern-images' ); ?></span><strong><?php echo esc_html( number_format_i18n( $stats['partial'] ) ); ?></strong></div>
				<div class="jmi-stat"><span><?php esc_html_e( 'Waiting', 'just-modern-images' ); ?></span><strong><?php echo esc_html( number_format_i18n( $stats['pending'] + $stats['queued'] + $stats['processing'] + $stats['stale'] ) ); ?></strong></div>
				<div class="jmi-stat jmi-stat--danger"><span><?php esc_html_e( 'Needs attention', 'just-modern-images' ); ?></span><strong><?php echo esc_html( number_format_i18n( $stats['failed'] ) ); ?></strong></div>
			</div>

			<div class="jmi-panel-grid">
				<section class="jmi-panel">
					<h2><?php esc_html_e( 'Image quality', 'just-modern-images' ); ?></h2>
					<form action="options.php" method="post">
						<?php settings_fields( self::SETTINGS_GROUP ); ?>
						<label class="jmi-field-label" for="jmi-quality-profile"><?php esc_html_e( 'Quality profile', 'just-modern-images' ); ?></label>
						<select class="regular-text" id="jmi-quality-profile" name="<?php echo esc_attr( JMI_Quality_Profiles::OPTION_NAME ); ?>">
							<?php foreach ( $profiles as $key => $option ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $selected, $key ); ?>><?php echo esc_html( $option['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php echo esc_html( $profile['description'] ); ?></p>
						<p class="description"><?php esc_html_e( 'Changing quality refreshes the library in the background. Last known good variants remain active until replacements are ready.', 'just-modern-images' ); ?></p>
						<?php submit_button( __( 'Save quality', 'just-modern-images' ) ); ?>
					</form>
				</section>

				<section class="jmi-panel">
					<h2><?php esc_html_e( 'Server formats', 'just-modern-images' ); ?></h2>
					<div class="jmi-capability">
						<span class="jmi-format-icon">A</span>
						<div><strong>AVIF</strong><span><?php echo esc_html( $this->capability_label( $capabilities['image/avif'] ?? array() ) ); ?></span><?php echo wp_kses_post( $this->capability_reason( $capabilities['image/avif'] ?? array() ) ); ?></div>
					</div>
					<div class="jmi-capability">
						<span class="jmi-format-icon">W</span>
						<div><strong>WebP</strong><span><?php echo esc_html( $this->capability_label( $capabilities['image/webp'] ?? array() ) ); ?></span><?php echo wp_kses_post( $this->capability_reason( $capabilities['image/webp'] ?? array() ) ); ?></div>
					</div>
					<p class="jmi-server-note">
						<?php /* translators: 1: short server environment identifier, 2: number of observed server configurations. */ ?>
						<?php echo esc_html( sprintf( __( 'Current server: %1$s. Server environments observed: %2$s.', 'just-modern-images' ), $server['environment_id'], number_format_i18n( $server['profile_count'] ) ) ); ?>
					</p>
					<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
						<input type="hidden" name="action" value="jmi_probe">
						<?php wp_nonce_field( 'jmi_probe_server' ); ?>
						<?php submit_button( __( 'Check this server now', 'just-modern-images' ), 'secondary', 'submit', false ); ?>
					</form>
				</section>

				<section class="jmi-panel">
					<h2><?php esc_html_e( 'Background processing', 'just-modern-images' ); ?></h2>
					<dl class="jmi-details">
						<div><dt><?php esc_html_e( 'Library scan', 'just-modern-images' ); ?></dt><dd><?php echo esc_html( $this->queue_status_label( $status['status'] ) ); ?></dd></div>
						<div><dt><?php esc_html_e( 'Attachments processed', 'just-modern-images' ); ?></dt><dd><?php echo esc_html( number_format_i18n( (int) $status['processed'] ) ); ?></dd></div>
						<div><dt><?php esc_html_e( 'Files generated', 'just-modern-images' ); ?></dt><dd><?php echo esc_html( number_format_i18n( (int) $status['generated'] ) ); ?></dd></div>
						<div><dt><?php esc_html_e( 'Last cron workload', 'just-modern-images' ); ?></dt><dd><?php echo esc_html( $this->worker_run_label( $status ) ); ?></dd></div>
						<div><dt><?php esc_html_e( 'Worker paused because', 'just-modern-images' ); ?></dt><dd><?php echo esc_html( $this->worker_stop_label( $status['last_worker_stop'] ?? '' ) ); ?></dd></div>
						<div><dt><?php esc_html_e( 'Last activity', 'just-modern-images' ); ?></dt><dd><?php echo esc_html( $this->last_activity_label( $status['last_update'] ) ); ?></dd></div>
						<div><dt><?php esc_html_e( 'Last result', 'just-modern-images' ); ?></dt><dd><?php echo esc_html( $last_reason ? $this->diagnostic_label( $last_reason ) : __( 'No issues recorded', 'just-modern-images' ) ); ?>
						<?php
						if ( $last_reason ) :
							?>
							<code><?php echo esc_html( $last_reason ); ?></code><?php endif; ?></dd></div>
					</dl>
					<?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
						<p class="jmi-warning"><?php esc_html_e( 'Built-in WP-Cron is disabled, so an external runner must call WordPress regularly. If Last activity keeps changing, the runner is working.', 'just-modern-images' ); ?></p>
					<?php endif; ?>
					<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
						<input type="hidden" name="action" value="jmi_rebuild">
						<?php wp_nonce_field( 'jmi_rebuild_media_library' ); ?>
						<?php submit_button( __( 'Scan Media Library again', 'just-modern-images' ), 'secondary', 'submit', false ); ?>
					</form>
				</section>
			</div>

			<p class="jmi-safety-note"><span aria-hidden="true">✓</span><?php esc_html_e( 'Original JPEG and PNG files are never replaced or deleted.', 'just-modern-images' ); ?></p>
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
	 * Run a real capability check on the server handling this request.
	 *
	 * @return void
	 */
	public function handle_probe() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'just-modern-images' ) );
		}

		check_admin_referer( 'jmi_probe_server' );
		$probe_result = 'deferred';
		if ( method_exists( $this->capabilities, 'diagnostic_summary' ) ) {
			$this->capabilities->probe_all();
			$probe_result = '1';
		} else {
			$this->capabilities->invalidate();
		}
		$this->queue->start_scan( 'capability_checked' );

		wp_safe_redirect( add_query_arg( 'jmi-probe', $probe_result, admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ) );
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
	 * Load the settings screen styles.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue_styles( $hook_suffix ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'jmi-admin', plugins_url( 'assets/admin.css', JMI_PLUGIN_FILE ), array(), JMI_VERSION );
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

		if ( 'temporarily_disabled' === $state ) {
			return __( 'Temporarily paused after repeated encoder failures', 'just-modern-images' );
		}

		return __( 'Waiting for a capability check', 'just-modern-images' );
	}

	/**
	 * Return a detailed capability explanation with its stable reason code.
	 *
	 * @param array<string, mixed> $capability Capability data.
	 * @return string
	 */
	private function capability_reason( $capability ) {
		$reason = sanitize_key( $capability['reason'] ?? 'not_checked' );

		return '<small>' . esc_html( $this->diagnostic_label( $reason ) ) . ' <code>' . esc_html( $reason ) . '</code></small>';
	}

	/**
	 * Return capability details without assuming every cached component is current.
	 *
	 * @return array<string, mixed>
	 */
	private function server_summary() {
		if ( method_exists( $this->capabilities, 'diagnostic_summary' ) ) {
			$summary                   = $this->capabilities->diagnostic_summary();
			$summary['rolling_update'] = version_compare( JMI_VERSION, '0.11.3', '<' );
			return $summary;
		}

		$formats = method_exists( $this->capabilities, 'get_all' )
			? $this->capabilities->get_all()
			: array();

		return array(
			'environment_id' => __( 'reloading', 'just-modern-images' ),
			'profile_count'  => 1,
			'formats'        => $formats,
			'rolling_update' => true,
		);
	}

	/**
	 * Translate a diagnostic code, with a safe fallback during rolling updates.
	 *
	 * @param string $reason Diagnostic reason code.
	 * @return string
	 */
	private function diagnostic_label( $reason ) {
		if ( class_exists( 'JMI_Diagnostics', false ) && is_callable( array( 'JMI_Diagnostics', 'label' ) ) ) {
			return JMI_Diagnostics::label( $reason );
		}

		return __( 'Processing did not complete.', 'just-modern-images' );
	}

	/**
	 * Return a translated queue state.
	 *
	 * @param mixed $state Queue state.
	 * @return string
	 */
	private function queue_status_label( $state ) {
		$labels = array(
			'idle'     => __( 'Waiting', 'just-modern-images' ),
			'queued'   => __( 'Queued', 'just-modern-images' ),
			'running'  => __( 'Scanning', 'just-modern-images' ),
			'complete' => __( 'Scan complete', 'just-modern-images' ),
		);
		$state  = sanitize_key( $state );

		return $labels[ $state ] ?? $labels['idle'];
	}

	/**
	 * Return a concise last-activity description.
	 *
	 * @param mixed $timestamp Activity timestamp.
	 * @return string
	 */
	private function last_activity_label( $timestamp ) {
		$timestamp = absint( $timestamp );
		if ( ! $timestamp ) {
			return __( 'No activity yet', 'just-modern-images' );
		}

		return sprintf(
			/* translators: %s: human-readable time difference, such as "2 minutes". */
			__( '%s ago', 'just-modern-images' ),
			human_time_diff( $timestamp, time() )
		);
	}

	/**
	 * Summarize the latest adaptive cron workload.
	 *
	 * @param array<string, mixed> $status Queue status.
	 * @return string
	 */
	private function worker_run_label( $status ) {
		if ( empty( $status['last_worker_at'] ) ) {
			return __( 'No worker run yet', 'just-modern-images' );
		}

		$items   = max( 0, (int) ( $status['last_worker_items'] ?? 0 ) );
		$seconds = max( 0, (int) ( $status['last_worker_ms'] ?? 0 ) ) / 1000;

		return sprintf(
			/* translators: 1: number of processed images, 2: worker runtime in seconds. */
			_n( '%1$s image in %2$s seconds', '%1$s images in %2$s seconds', $items, 'just-modern-images' ),
			number_format_i18n( $items ),
			number_format_i18n( $seconds, 1 )
		);
	}

	/**
	 * Translate the reason an adaptive worker yielded control.
	 *
	 * @param mixed $reason Stable worker stop code.
	 * @return string
	 */
	private function worker_stop_label( $reason ) {
		$labels = array(
			'complete'                  => __( 'The library scan is complete', 'just-modern-images' ),
			'time_budget'               => __( 'The safe time budget was reached', 'just-modern-images' ),
			'memory_pressure'           => __( 'The memory reserve was reached', 'just-modern-images' ),
			'item_limit'                => __( 'The per-run image limit was reached', 'just-modern-images' ),
			'unexpected_worker_failure' => __( 'The worker stopped after an unexpected error', 'just-modern-images' ),
		);
		$reason = sanitize_key( $reason );

		return $labels[ $reason ] ?? __( 'Waiting for a worker run', 'just-modern-images' );
	}
}
