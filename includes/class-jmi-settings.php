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

$jmi_activity_log_file = __DIR__ . '/class-jmi-activity-log.php';
if ( ! class_exists( 'JMI_Activity_Log', false ) && is_readable( $jmi_activity_log_file ) ) {
	require_once $jmi_activity_log_file;
}
unset( $jmi_activity_log_file );

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
	 * Recent worker activity.
	 *
	 * @var JMI_Activity_Log|null
	 */
	private $activity_log;

	/**
	 * Set up the settings screen.
	 *
	 * @param JMI_Quality_Profiles  $profiles     Quality profiles.
	 * @param JMI_Capabilities      $capabilities Server capabilities.
	 * @param JMI_Queue             $queue        Background queue.
	 * @param JMI_Media_Status      $media_status Media Library status.
	 * @param JMI_Activity_Log|null $activity_log Recent worker activity.
	 */
	public function __construct( $profiles, $capabilities, $queue, $media_status, $activity_log = null ) {
		$this->profiles     = $profiles;
		$this->capabilities = $capabilities;
		$this->queue        = $queue;
		$this->media_status = $media_status;
		$this->activity_log = $activity_log;
		if ( ! $this->activity_log && class_exists( 'JMI_Activity_Log', false ) ) {
			$this->activity_log = new JMI_Activity_Log();
		}
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
		add_action( 'admin_post_jmi_export_activity', array( $this, 'handle_export_activity' ) );
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
		$active_tab          = isset( $_GET['tab'] ) && 'activity' === sanitize_key( wp_unslash( $_GET['tab'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? 'activity'
			: 'overview';
		$server              = $this->server_summary();
		$capabilities        = $server['formats'];
		$status              = $this->queue->status();
		$stats               = $this->media_status->library_stats( $this->profiles->generation_profile() );
		$profiles            = $this->profiles->all();
		$profile             = $profiles[ $selected ];
		$ready_pct           = $stats['total'] ? (int) round( ( $stats['ready'] / $stats['total'] ) * 100 ) : 0;
		$reviewed_pct        = $stats['total'] ? (int) round( ( $stats['reviewed'] / $stats['total'] ) * 100 ) : 0;
		$last_reason         = sanitize_key( $status['last_reason'] ?? '' );
		$worker_diagnostics  = method_exists( $this->queue, 'diagnostics' )
			? $this->queue->diagnostics()
			: array(
				'next_event' => 0,
				'lock_state' => 'unknown',
				'lock_age'   => 0,
			);
		$scan_started_at     = ! empty( $status['scan_started_at'] )
			? (int) $status['scan_started_at']
			: (int) $status['last_update'];
		$worker_stalled      = in_array( $status['status'], array( 'queued', 'running' ), true ) &&
			empty( $status['last_worker_at'] ) &&
			$scan_started_at > 0 &&
			$scan_started_at < time() - 5 * MINUTE_IN_SECONDS;
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

			<nav class="nav-tab-wrapper jmi-tabs" aria-label="<?php esc_attr_e( 'Just Modern Images sections', 'just-modern-images' ); ?>">
				<a class="nav-tab <?php echo 'overview' === $active_tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ); ?>"><?php esc_html_e( 'Overview', 'just-modern-images' ); ?></a>
				<a class="nav-tab <?php echo 'activity' === $active_tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'activity', admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ) ); ?>"><?php esc_html_e( 'Activity log', 'just-modern-images' ); ?></a>
			</nav>

			<?php if ( 'activity' === $active_tab ) : ?>
				<?php $this->render_activity_log(); ?>
			</div>
				<?php return; ?>
			<?php endif; ?>

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
			<?php if ( $worker_stalled ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'The image worker has not reported a run. A recovery event has been queued automatically. If this message remains after the next cron calls, refresh PHP OPcache on the servers handling cron.', 'just-modern-images' ); ?></p></div>
			<?php endif; ?>
			<?php if ( 'failed' === ( $status['last_schedule_result'] ?? '' ) ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'WordPress rejected the background worker event. The plugin will retry scheduling it on the next request.', 'just-modern-images' ); ?></p></div>
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
						<div><dt><?php esc_html_e( 'Next worker event', 'just-modern-images' ); ?></dt><dd><?php echo esc_html( $this->worker_event_label( $worker_diagnostics, $status['status'] ) ); ?></dd></div>
						<div><dt><?php esc_html_e( 'Worker lock', 'just-modern-images' ); ?></dt><dd><?php echo esc_html( $this->worker_lock_label( $worker_diagnostics ) ); ?></dd></div>
						<div><dt><?php esc_html_e( 'Worker code', 'just-modern-images' ); ?></dt><dd><?php echo esc_html( $this->worker_version_label( $status['last_worker_version'] ?? '' ) ); ?></dd></div>
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
	 * Render recent worker runs in a separate diagnostic tab.
	 *
	 * @return void
	 */
	private function render_activity_log() {
		$entries = $this->activity_log && method_exists( $this->activity_log, 'entries' )
			? $this->activity_log->entries()
			: array();
		?>
		<section class="jmi-panel jmi-history-panel">
			<div class="jmi-panel-heading">
				<div>
					<h2><?php esc_html_e( 'Recent processing activity', 'just-modern-images' ); ?></h2>
					<p><?php esc_html_e( 'The newest 50 worker runs are kept automatically. Counts are captured immediately before and after each run.', 'just-modern-images' ); ?></p>
				</div>
				<?php if ( ! empty( $entries ) ) : ?>
					<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
						<input type="hidden" name="action" value="jmi_export_activity">
						<?php wp_nonce_field( 'jmi_export_activity' ); ?>
						<?php submit_button( __( 'Download diagnostic report', 'just-modern-images' ), 'secondary', 'submit', false ); ?>
					</form>
				<?php endif; ?>
			</div>

			<?php if ( empty( $entries ) ) : ?>
				<div class="jmi-empty-state">
					<strong><?php esc_html_e( 'No runs have been recorded yet.', 'just-modern-images' ); ?></strong>
					<p><?php esc_html_e( 'The next image worker run will appear here automatically.', 'just-modern-images' ); ?></p>
				</div>
			<?php else : ?>
				<div class="jmi-run-list">
					<?php foreach ( $entries as $index => $entry ) : ?>
						<?php
						$before       = $entry['before']['library'] ?? array();
						$after        = $entry['after']['library'] ?? array();
						$before_queue = $entry['before']['queue'] ?? array();
						$after_queue  = $entry['after']['queue'] ?? array();
						$items        = is_array( $entry['items'] ?? null ) ? $entry['items'] : array();
						$started_at   = (int) ( $entry['started_at'] ?? 0 );
						$duration     = max( 0, (int) ( $entry['duration_ms'] ?? 0 ) ) / 1000;
						$regression   = (int) ( $after['ready'] ?? 0 ) < (int) ( $before['ready'] ?? 0 );
						?>
						<details class="jmi-run<?php echo $regression ? ' jmi-run--regression' : ''; ?>" <?php echo 0 === $index ? 'open' : ''; ?>>
							<summary>
								<span class="jmi-run-time"><?php echo esc_html( $started_at ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $started_at ) : __( 'Unknown time', 'just-modern-images' ) ); ?></span>
								<span class="jmi-run-server"><code><?php echo esc_html( (string) ( $entry['server'] ?? '' ) ); ?></code></span>
								<span class="jmi-run-progress">
									<?php
									/* translators: 1: ready count before, 2: ready count after, 3: total eligible images. */
									echo esc_html( sprintf( __( 'Ready %1$s → %2$s of %3$s', 'just-modern-images' ), number_format_i18n( (int) ( $before['ready'] ?? 0 ) ), number_format_i18n( (int) ( $after['ready'] ?? 0 ) ), number_format_i18n( (int) ( $after['total'] ?? 0 ) ) ) );
									?>
								</span>
								<span class="jmi-run-result"><?php echo esc_html( $this->activity_result_label( $entry ) ); ?></span>
							</summary>

							<div class="jmi-run-body">
								<div class="jmi-run-metrics">
									<div>
										<span><?php esc_html_e( 'Before', 'just-modern-images' ); ?></span>
										<strong><?php echo esc_html( $this->activity_snapshot_label( $before ) ); ?></strong>
									</div>
									<div>
										<span><?php esc_html_e( 'After', 'just-modern-images' ); ?></span>
										<strong><?php echo esc_html( $this->activity_snapshot_label( $after ) ); ?></strong>
									</div>
									<div>
										<span><?php esc_html_e( 'Queue', 'just-modern-images' ); ?></span>
										<strong>
											<?php
											/* translators: 1: queue cursor before, 2: queue cursor after, 3: processed count after. */
											echo esc_html( sprintf( __( 'Cursor %1$s → %2$s; processed %3$s', 'just-modern-images' ), number_format_i18n( (int) ( $before_queue['cursor'] ?? 0 ) ), number_format_i18n( (int) ( $after_queue['cursor'] ?? 0 ) ), number_format_i18n( (int) ( $after_queue['processed'] ?? 0 ) ) ) );
											?>
										</strong>
									</div>
									<div>
										<span><?php esc_html_e( 'Run', 'just-modern-images' ); ?></span>
										<strong>
											<?php
											/* translators: 1: processed images, 2: duration in seconds, 3: worker version. */
											echo esc_html( sprintf( __( '%1$s images; %2$s s; v%3$s', 'just-modern-images' ), number_format_i18n( (int) ( $entry['processed'] ?? 0 ) ), number_format_i18n( $duration, 1 ), (string) ( $entry['worker_version'] ?? '' ) ) );
											?>
										</strong>
									</div>
								</div>

								<div class="jmi-run-formats">
									<?php
									foreach ( array(
										'image/avif' => 'AVIF',
										'image/webp' => 'WebP',
									) as $mime_type => $format_label ) :
										?>
										<?php $format = $entry['formats'][ $mime_type ] ?? array(); ?>
										<span><strong><?php echo esc_html( $format_label ); ?></strong> <?php echo esc_html( sanitize_key( $format['state'] ?? 'unknown' ) ); ?> <code><?php echo esc_html( sanitize_key( $format['reason'] ?? 'not_checked' ) ); ?></code></span>
									<?php endforeach; ?>
								</div>

								<?php if ( ! empty( $items ) ) : ?>
									<div class="jmi-table-scroll">
										<table class="widefat striped jmi-item-log">
											<thead><tr>
												<th><?php esc_html_e( 'Media', 'just-modern-images' ); ?></th>
												<th><?php esc_html_e( 'Status change', 'just-modern-images' ); ?></th>
												<th><?php esc_html_e( 'Result', 'just-modern-images' ); ?></th>
												<th><?php esc_html_e( 'Time', 'just-modern-images' ); ?></th>
											</tr></thead>
											<tbody>
											<?php foreach ( $items as $item ) : ?>
												<tr>
													<td><a href="<?php echo esc_url( get_edit_post_link( (int) $item['attachment_id'] ) ); ?>">#<?php echo esc_html( number_format_i18n( (int) $item['attachment_id'] ) ); ?></a></td>
													<td><code><?php echo esc_html( $this->activity_item_transition_label( $item ) ); ?></code></td>
													<td><?php echo esc_html( $this->activity_item_result_label( $item ) ); ?>
													<?php
													if ( ! empty( $item['after_reason'] ) ) :
														?>
														<code><?php echo esc_html( sanitize_key( $item['after_reason'] ) ); ?></code><?php endif; ?></td>
													<td><?php echo esc_html( number_format_i18n( max( 0, (int) ( $item['duration_ms'] ?? 0 ) ) / 1000, 2 ) ); ?> s</td>
												</tr>
											<?php endforeach; ?>
											</tbody>
										</table>
									</div>
								<?php endif; ?>
							</div>
						</details>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
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
	 * Download a safe JSON report of recent processing activity.
	 *
	 * @return void
	 */
	public function handle_export_activity() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'just-modern-images' ) );
		}

		check_admin_referer( 'jmi_export_activity' );
		if ( ! $this->activity_log || ! method_exists( $this->activity_log, 'report' ) ) {
			wp_die( esc_html__( 'The activity report is not available on this server yet.', 'just-modern-images' ) );
		}

		$server = $this->server_summary();
		$stats  = $this->media_status->library_stats( $this->profiles->generation_profile() );
		$report = $this->activity_log->report(
			array(
				'server'        => (string) ( $server['environment_id'] ?? '' ),
				'profile_count' => (int) ( $server['profile_count'] ?? 0 ),
				'formats'       => $server['formats'] ?? array(),
				'current'       => $this->activity_snapshot( $stats, $this->queue->status() ),
			)
		);

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="just-modern-images-diagnostics-' . gmdate( 'Ymd-His' ) . '.json"' );
		echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
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
	 * Build a safe current-state snapshot for a diagnostic export.
	 *
	 * @param array<string, int>   $stats  Library statistics.
	 * @param array<string, mixed> $status Queue status.
	 * @return array<string, mixed>
	 */
	private function activity_snapshot( $stats, $status ) {
		return array(
			'library' => array(
				'total'     => (int) ( $stats['total'] ?? 0 ),
				'ready'     => (int) ( $stats['ready'] ?? 0 ),
				'partial'   => (int) ( $stats['partial'] ?? 0 ),
				'waiting'   => (int) ( $stats['pending'] ?? 0 ) + (int) ( $stats['queued'] ?? 0 ) + (int) ( $stats['processing'] ?? 0 ) + (int) ( $stats['stale'] ?? 0 ),
				'attention' => (int) ( $stats['failed'] ?? 0 ),
				'skipped'   => (int) ( $stats['skipped'] ?? 0 ),
				'reviewed'  => (int) ( $stats['reviewed'] ?? 0 ),
			),
			'queue'   => array(
				'status'    => (string) ( $status['status'] ?? '' ),
				'reason'    => (string) ( $status['reason'] ?? '' ),
				'cursor'    => (int) ( $status['cursor'] ?? 0 ),
				'total'     => (int) ( $status['total'] ?? 0 ),
				'processed' => (int) ( $status['processed'] ?? 0 ),
				'generated' => (int) ( $status['generated'] ?? 0 ),
				'failed'    => (int) ( $status['failed'] ?? 0 ),
			),
		);
	}

	/**
	 * Summarize one library snapshot.
	 *
	 * @param array<string, mixed> $library Library counts.
	 * @return string
	 */
	private function activity_snapshot_label( $library ) {
		return sprintf(
			/* translators: 1: ready, 2: partly ready, 3: waiting, 4: needs attention, 5: safely skipped. */
			__( '%1$s ready, %2$s partial, %3$s waiting, %4$s attention, %5$s skipped', 'just-modern-images' ),
			number_format_i18n( (int) ( $library['ready'] ?? 0 ) ),
			number_format_i18n( (int) ( $library['partial'] ?? 0 ) ),
			number_format_i18n( (int) ( $library['waiting'] ?? 0 ) ),
			number_format_i18n( (int) ( $library['attention'] ?? 0 ) ),
			number_format_i18n( (int) ( $library['skipped'] ?? 0 ) )
		);
	}

	/**
	 * Summarize the top-level result of one activity entry.
	 *
	 * @param array<string, mixed> $entry Activity entry.
	 * @return string
	 */
	private function activity_result_label( $entry ) {
		$type = sanitize_key( $entry['type'] ?? '' );
		if ( 'scan_requested' === $type ) {
			return sprintf(
				/* translators: %s: reason the scan was requested. */
				__( 'Scan queued: %s', 'just-modern-images' ),
				sanitize_key( $entry['source'] ?? '' )
			);
		}

		$reason = sanitize_key( $entry['stop_reason'] ?? '' );
		if ( 'attachment' === $type ) {
			return __( 'Single image event', 'just-modern-images' );
		}

		return $this->worker_stop_label( $reason );
	}

	/**
	 * Summarize counters for one processed attachment.
	 *
	 * @param array<string, mixed> $item Attachment activity.
	 * @return string
	 */
	private function activity_item_result_label( $item ) {
		return sprintf(
			/* translators: 1: generated files, 2: reused files, 3: retained files, 4: skipped files, 5: failed files. */
			__( 'Generated %1$s, reused %2$s, retained %3$s, skipped %4$s, failed %5$s', 'just-modern-images' ),
			number_format_i18n( (int) ( $item['generated'] ?? 0 ) ),
			number_format_i18n( (int) ( $item['reused'] ?? 0 ) ),
			number_format_i18n( (int) ( $item['retained'] ?? 0 ) ),
			number_format_i18n( (int) ( $item['skipped'] ?? 0 ) ),
			number_format_i18n( (int) ( $item['failed'] ?? 0 ) )
		);
	}

	/**
	 * Describe how an attachment entered the queue and how it finished.
	 *
	 * @param array<string, mixed> $item Attachment activity.
	 * @return string
	 */
	private function activity_item_transition_label( $item ) {
		$before      = sanitize_key( $item['before_state'] ?? '' );
		$after       = sanitize_key( $item['after_state'] ?? '' );
		$queued_from = sanitize_key( $item['queued_from'] ?? '' );
		$source      = sanitize_key( $item['queue_source'] ?? '' );
		$states      = array();

		if ( $queued_from && $queued_from !== $before ) {
			$states[] = $queued_from;
		}
		if ( $before ) {
			$states[] = $before;
		}
		if ( $after && $after !== $before ) {
			$states[] = $after;
		}

		$label = implode( ' → ', $states );
		if ( $source ) {
			$label .= ' (' . $source . ')';
		}

		return $label;
	}

	/**
	 * Return capability details without assuming every cached component is current.
	 *
	 * @return array<string, mixed>
	 */
	private function server_summary() {
		if ( method_exists( $this->capabilities, 'diagnostic_summary' ) ) {
			$summary                   = $this->capabilities->diagnostic_summary();
			$summary['rolling_update'] = version_compare( JMI_VERSION, '0.11.5', '<' );
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

	/**
	 * Describe when WordPress will next dispatch the scanner.
	 *
	 * @param array<string, mixed> $diagnostics Queue diagnostics.
	 * @param mixed                $scan_status Current scan status.
	 * @return string
	 */
	private function worker_event_label( $diagnostics, $scan_status ) {
		if ( 'complete' === sanitize_key( $scan_status ) ) {
			return __( 'No more library work is queued', 'just-modern-images' );
		}

		$next_event = (int) ( $diagnostics['next_event'] ?? 0 );
		if ( ! $next_event ) {
			return __( 'No event is currently scheduled', 'just-modern-images' );
		}

		if ( $next_event <= time() ) {
			return __( 'Ready for the next cron call', 'just-modern-images' );
		}

		return sprintf(
			/* translators: %s: human-readable time until the next event. */
			__( 'In %s', 'just-modern-images' ),
			human_time_diff( time(), $next_event )
		);
	}

	/**
	 * Describe whether another request currently owns the worker.
	 *
	 * @param array<string, mixed> $diagnostics Queue diagnostics.
	 * @return string
	 */
	private function worker_lock_label( $diagnostics ) {
		$state = sanitize_key( $diagnostics['lock_state'] ?? 'unknown' );
		if ( 'held' === $state ) {
			return sprintf(
				/* translators: %s: worker lock age in seconds. */
				__( 'Another request is processing images (%s seconds)', 'just-modern-images' ),
				number_format_i18n( (int) ( $diagnostics['lock_age'] ?? 0 ) )
			);
		}

		if ( 'stale' === $state ) {
			return __( 'An abandoned lock was detected', 'just-modern-images' );
		}

		if ( 'free' === $state ) {
			return __( 'Available', 'just-modern-images' );
		}

		return __( 'Waiting for the current plugin code', 'just-modern-images' );
	}

	/**
	 * Show the code revision that most recently completed a worker run.
	 *
	 * @param mixed $version Worker version.
	 * @return string
	 */
	private function worker_version_label( $version ) {
		$version = is_string( $version ) ? trim( $version ) : '';

		return $version ? 'v' . $version : __( 'Not observed yet', 'just-modern-images' );
	}
}
