<?php
/**
 * Optional reporting for privately tested installations.
 *
 * @package JustModernImages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends bounded, redacted diagnostic batches to an explicitly configured endpoint.
 */
final class JMI_Diagnostics_Reporter {

	const SEND_HOOK            = 'jmi_send_diagnostics';
	const ENABLED_OPTION       = 'jmi_diagnostics_enabled';
	const OUTBOX_OPTION        = 'jmi_diagnostics_outbox';
	const STATE_OPTION         = 'jmi_diagnostics_state';
	const CRON_OPTION          = 'jmi_diagnostics_cron_metrics';
	const INSTALLATION_OPTION  = 'jmi_diagnostics_installation_id';
	const INSTALLATION_SECRET  = 'jmi_diagnostics_installation_secret';
	const NETWORK_INSTALLATION = 'jmi_diagnostics_network_id';
	const INITIALIZED_OPTION   = 'jmi_diagnostics_initialized';
	const LOCK_OPTION          = 'jmi_diagnostics_send_lock';
	const DEFAULT_ENDPOINT     = '';
	const DEFAULT_FLEET_KEY    = '';
	const SCHEMA               = 1;
	const MAX_OUTBOX           = 200;
	const BATCH_SIZE           = 20;
	const LOCK_TTL             = 120;
	const HEARTBEAT_INTERVAL   = 3600;
	const MAX_CRON_SAMPLES     = 30;

	/**
	 * Provides live queue, library, and format state for heartbeats.
	 *
	 * @var callable|null
	 */
	private $context_provider;

	/**
	 * Set up live diagnostic context.
	 *
	 * @param callable|null $context_provider Live diagnostic context provider.
	 */
	public function __construct( $context_provider = null ) {
		$this->context_provider = is_callable( $context_provider ) ? $context_provider : null;
	}

	/**
	 * Register reporting hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'jmi_activity_recorded', array( $this, 'queue_activity' ), 10, 1 );
		add_action( self::SEND_HOOK, array( $this, 'send' ) );

		if ( ! $this->enabled() ) {
			return;
		}

		register_shutdown_function( array( $this, 'capture_shutdown_error' ) );
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			$this->observe_cron();
		}
		$this->ensure_scheduled();
	}

	/**
	 * Seed reporting with the latest local history after it is configured.
	 *
	 * @param array<int, array<string, mixed>> $entries Existing activity entries.
	 * @return void
	 */
	public function bootstrap_history( $entries ) {
		if ( ! $this->enabled() || get_option( self::INITIALIZED_OPTION, false ) ) {
			return;
		}

		$entries = is_array( $entries ) ? array_slice( $entries, 0, self::BATCH_SIZE ) : array();
		foreach ( array_reverse( $entries ) as $entry ) {
			$this->queue_activity( $entry );
		}

		update_option( self::INITIALIZED_OPTION, time(), false );
	}

	/**
	 * Apply the administrator's reporting choice.
	 *
	 * @param bool                                  $enabled Whether sending is allowed.
	 * @param array<int, array<string, mixed>>|null $entries Existing local history.
	 * @return void
	 */
	public function set_enabled( $enabled, $entries = null ) {
		update_option( self::ENABLED_OPTION, $enabled ? '1' : '0', false );

		if ( $enabled ) {
			$this->installation_id();
			$this->installation_secret();
			$this->bootstrap_history( is_array( $entries ) ? $entries : array() );
			$this->ensure_scheduled();
			return;
		}

		delete_option( self::OUTBOX_OPTION );
		delete_option( self::CRON_OPTION );
		delete_option( self::INITIALIZED_OPTION );
		wp_clear_scheduled_hook( self::SEND_HOOK );
	}

	/**
	 * Record the real interval between WordPress cron requests.
	 *
	 * An hourly heartbeat keeps quiet installations visible without sending on
	 * every cron request.
	 *
	 * @return void
	 */
	public function observe_cron() {
		if ( ! $this->enabled() ) {
			return;
		}

		$now     = time();
		$metrics = get_option( self::CRON_OPTION, array() );
		$metrics = is_array( $metrics ) ? $metrics : array();
		$last    = max( 0, (int) ( $metrics['last_observed_at'] ?? 0 ) );
		$samples = is_array( $metrics['intervals_ms'] ?? null ) ? $metrics['intervals_ms'] : array();

		if ( $last > 0 && $now >= $last ) {
			$samples[] = ( $now - $last ) * 1000;
			$samples   = array_slice( array_map( 'absint', $samples ), -self::MAX_CRON_SAMPLES );
		}

		$metrics['first_observed_at'] = max( 0, (int) ( $metrics['first_observed_at'] ?? $now ) );
		$metrics['last_observed_at']  = $now;
		$metrics['observations']      = max( 0, (int) ( $metrics['observations'] ?? 0 ) ) + 1;
		$metrics['intervals_ms']      = $samples;
		$last_heartbeat               = max( 0, (int) ( $metrics['last_heartbeat_at'] ?? 0 ) );

		if ( 0 === $last_heartbeat || $last_heartbeat <= $now - self::HEARTBEAT_INTERVAL ) {
			$context                      = $this->live_context();
			$metrics['last_heartbeat_at'] = $now;
			update_option( self::CRON_OPTION, $metrics, false );
			$this->append_event(
				array(
					'id'             => substr( hash( 'sha256', $this->installation_id() . '|heartbeat|' . $now ), 0, 20 ),
					'type'           => 'cron_heartbeat',
					'source'         => 'wp_cron',
					'started_at'     => $now,
					'finished_at'    => $now,
					'duration_ms'    => 0,
					'server'         => '',
					'worker_version' => defined( 'JMI_VERSION' ) ? (string) JMI_VERSION : '',
					'stop_reason'    => 'heartbeat',
					'complete'       => true,
					'attempts'       => 0,
					'processed'      => 0,
					'before'         => $this->snapshot( $context['snapshot'] ),
					'after'          => $this->snapshot( $context['snapshot'] ),
					'formats'        => $this->formats( $context['formats'] ),
				)
			);
			return;
		}

		update_option( self::CRON_OPTION, $metrics, false );
	}

	/**
	 * Add one aggregated worker event to the remote outbox.
	 *
	 * Attachment IDs and other local identifiers are deliberately discarded.
	 *
	 * @param mixed $entry Normalized local activity entry.
	 * @return void
	 */
	public function queue_activity( $entry ) {
		if ( ! $this->enabled() || ! is_array( $entry ) ) {
			return;
		}

		$items   = is_array( $entry['items'] ?? null ) ? $entry['items'] : array();
		$results = array(
			'generated'         => 0,
			'reused'            => 0,
			'retained'          => 0,
			'skipped'           => 0,
			'failed'            => 0,
			'total_duration_ms' => 0,
			'max_duration_ms'   => 0,
			'states'            => array(),
			'reasons'           => array(),
			'sources'           => array(),
		);

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			foreach ( array( 'generated', 'reused', 'retained', 'skipped', 'failed' ) as $counter ) {
				$results[ $counter ] += max( 0, (int) ( $item[ $counter ] ?? 0 ) );
			}

			$item_duration                 = max( 0, (int) ( $item['duration_ms'] ?? 0 ) );
			$results['total_duration_ms'] += $item_duration;
			$results['max_duration_ms']    = max( $results['max_duration_ms'], $item_duration );
			$this->increment_key( $results['states'], $item['after_state'] ?? '' );
			$this->increment_key( $results['reasons'], $item['after_reason'] ?? '' );
			$this->increment_key( $results['sources'], $item['queue_source'] ?? '' );
		}

		$event = array(
			'id'             => $this->token( $entry['id'] ?? '' ),
			'type'           => $this->allowed_key( $entry['type'] ?? '', array( 'scan', 'attachment', 'scan_requested' ), 'scan' ),
			'source'         => sanitize_key( $entry['source'] ?? '' ),
			'started_at'     => max( 0, (int) ( $entry['started_at'] ?? 0 ) ),
			'finished_at'    => max( 0, (int) ( $entry['finished_at'] ?? 0 ) ),
			'duration_ms'    => max( 0, (int) ( $entry['duration_ms'] ?? 0 ) ),
			'server'         => $this->token( $entry['server'] ?? '' ),
			'worker_version' => $this->version( $entry['worker_version'] ?? '' ),
			'stop_reason'    => sanitize_key( $entry['stop_reason'] ?? '' ),
			'complete'       => ! empty( $entry['complete'] ),
			'attempts'       => max( 0, (int) ( $entry['attempts'] ?? 0 ) ),
			'processed'      => max( 0, (int) ( $entry['processed'] ?? 0 ) ),
			'performance'    => $this->performance( $entry['performance'] ?? array() ),
			'before'         => $this->snapshot( $entry['before'] ?? array() ),
			'after'          => $this->snapshot( $entry['after'] ?? array() ),
			'formats'        => $this->formats( $entry['formats'] ?? array() ),
			'item_results'   => $results,
		);

		if ( ! $event['started_at'] ) {
			return;
		}

		if ( '' === $event['id'] ) {
			$event['id'] = substr( sha1( wp_json_encode( $event ) ), 0, 20 );
		}

		$this->append_event( $event );
	}

	/**
	 * Store a fatal PHP error when it originated inside this plugin.
	 *
	 * @return void
	 */
	public function capture_shutdown_error() {
		if ( ! $this->enabled() ) {
			return;
		}

		$error = error_get_last();
		if ( ! is_array( $error ) || ! in_array( (int) ( $error['type'] ?? 0 ), array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
			return;
		}

		$file        = wp_normalize_path( (string) ( $error['file'] ?? '' ) );
		$plugin_path = wp_normalize_path( JMI_PLUGIN_DIR );
		if ( '' === $file || 0 !== strpos( $file, $plugin_path ) ) {
			return;
		}

		$relative_file = ltrim( substr( $file, strlen( $plugin_path ) ), '/' );
		$message       = $this->redact_message( $error['message'] ?? '' );
		$event         = array(
			'id'             => substr( sha1( wp_json_encode( $error ) . '|' . microtime( true ) ), 0, 20 ),
			'type'           => 'runtime_problem',
			'source'         => 'shutdown',
			'started_at'     => time(),
			'finished_at'    => time(),
			'duration_ms'    => 0,
			'server'         => '',
			'worker_version' => defined( 'JMI_VERSION' ) ? (string) JMI_VERSION : '',
			'stop_reason'    => 'php_fatal',
			'complete'       => false,
			'attempts'       => 0,
			'processed'      => 0,
			'before'         => $this->snapshot( array() ),
			'after'          => $this->snapshot( array() ),
			'formats'        => $this->formats( array() ),
			'problem'        => array(
				'code'        => 'php_fatal',
				'error_type'  => max( 0, (int) ( $error['type'] ?? 0 ) ),
				'file'        => substr( preg_replace( '/[^a-zA-Z0-9_.\/-]/', '', $relative_file ), 0, 120 ),
				'line'        => max( 0, (int) ( $error['line'] ?? 0 ) ),
				'message'     => $message,
				'fingerprint' => substr( hash( 'sha256', $relative_file . '|' . (int) ( $error['line'] ?? 0 ) . '|' . $message ), 0, 20 ),
			),
		);

		$this->append_event( $event );
	}

	/**
	 * Send the oldest pending batch.
	 *
	 * @param bool $force Ignore a retry delay for an administrator-triggered send.
	 * @return string Stable result code.
	 */
	public function send( $force = false ) {
		if ( ! $this->enabled() ) {
			return 'disabled';
		}

		$state = $this->state();
		if ( ! $force && $state['next_attempt'] > time() ) {
			$this->schedule( max( 1, $state['next_attempt'] - time() ) );
			return 'deferred';
		}

		$events = $this->outbox();
		if ( empty( $events ) ) {
			return 'empty';
		}

		if ( ! $this->acquire_lock() ) {
			$this->schedule( 60 );
			return 'busy';
		}

		try {
			$batch     = array_slice( $events, 0, self::BATCH_SIZE );
			$event_ids = array_column( $batch, 'id' );
			$payload   = $this->payload( $batch );
			$response  = wp_safe_remote_post(
				$this->endpoint(),
				array(
					'timeout'     => 3,
					'redirection' => 0,
					'blocking'    => true,
					'headers'     => array(
						'Accept'          => 'application/json',
						'Content-Type'    => 'application/json',
						'X-JMI-Fleet-Key' => $this->fleet_key(),
						'X-JMI-Key'       => $this->installation_secret(),
						'X-JMI-Schema'    => (string) self::SCHEMA,
					),
					'body'        => wp_json_encode( $payload ),
				)
			);

			$state['last_attempt'] = time();
			if ( is_wp_error( $response ) ) {
				return $this->record_failure( $state, 'transport_error', 0 );
			}

			$http_code               = (int) wp_remote_retrieve_response_code( $response );
			$state['last_http_code'] = $http_code;
			if ( $http_code < 200 || $http_code >= 300 ) {
				return $this->record_failure( $state, 'http_' . $http_code, $http_code );
			}

			$current = $this->outbox();
			$current = array_values(
				array_filter(
					$current,
					static function ( $event ) use ( $event_ids ) {
						return ! in_array( $event['id'] ?? '', $event_ids, true );
					}
				)
			);
			update_option( self::OUTBOX_OPTION, $current, false );

			$state['failures']       = 0;
			$state['last_error']     = '';
			$state['last_success']   = time();
			$state['last_http_code'] = $http_code;
			$state['next_attempt']   = 0;
			update_option( self::STATE_OPTION, $state, false );

			if ( ! empty( $current ) ) {
				$this->schedule( 60 );
			}

			return 'sent';
		} catch ( Throwable $error ) {
			$state['last_attempt'] = time();
			return $this->record_failure( $state, 'reporter_failure', 0 );
		} finally {
			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * Return safe reporting state for the administrator screen.
	 *
	 * @return array<string, int|string|bool>
	 */
	public function status() {
		$state = $this->state();
		$host  = wp_parse_url( $this->endpoint(), PHP_URL_HOST );

		return array(
			'enabled'        => $this->enabled(),
			'allowed'        => $this->allowed(),
			'configured'     => $this->configured(),
			'endpoint_host'  => is_string( $host ) ? $host : '',
			'installation'   => $this->enabled() ? $this->installation_id() : '',
			'site_name'      => $this->site_name(),
			'site_url'       => $this->site_url(),
			'pending'        => count( $this->outbox() ),
			'last_attempt'   => $state['last_attempt'],
			'last_success'   => $state['last_success'],
			'last_http_code' => $state['last_http_code'],
			'last_error'     => $state['last_error'],
			'next_attempt'   => $state['next_attempt'],
			'cron'           => $this->cron_metrics(),
		);
	}

	/**
	 * Whether the administrator allowed private-test reporting.
	 *
	 * @return bool
	 */
	public function enabled() {
		return $this->allowed() && $this->configured();
	}

	/**
	 * Whether reporting consent is stored for this site.
	 *
	 * @return bool
	 */
	public function allowed() {
		return '1' === (string) get_option( self::ENABLED_OPTION, '0' );
	}

	/**
	 * Whether this test build contains a valid HTTPS receiver URL.
	 *
	 * @return bool
	 */
	public function configured() {
		$endpoint  = $this->endpoint();
		$scheme    = wp_parse_url( $endpoint, PHP_URL_SCHEME );
		$host      = wp_parse_url( $endpoint, PHP_URL_HOST );
		$fleet_key = $this->fleet_key();

		return 'https' === strtolower( (string) $scheme )
			&& is_string( $host )
			&& '' !== $host
			&& (bool) preg_match( '/^[a-zA-Z0-9_-]{20,100}$/', $fleet_key );
	}

	/**
	 * Add an event while bounding option growth and suppressing duplicates.
	 *
	 * @param array<string, mixed> $event Safe event.
	 * @return void
	 */
	private function append_event( $event ) {
		if ( ! $this->enabled() || empty( $event['id'] ) ) {
			return;
		}

		try {
			$events = $this->outbox();
			foreach ( $events as $queued ) {
				if ( ( $queued['id'] ?? '' ) === $event['id'] ) {
					return;
				}
			}

			$events[] = $event;
			$events   = array_slice( $events, -self::MAX_OUTBOX );
			update_option( self::OUTBOX_OPTION, $events, false );
			$this->schedule( 60 );
		} catch ( Throwable $error ) {
			// Reporting must never interfere with image processing.
			return;
		}
	}

	/**
	 * Build the versioned remote payload.
	 *
	 * @param array<int, array<string, mixed>> $events Pending events.
	 * @return array<string, mixed>
	 */
	private function payload( $events ) {
		$installation_id = $this->installation_id();
		$event_ids       = array_column( $events, 'id' );

		return array(
			'schema'       => self::SCHEMA,
			'batch_id'     => substr( hash( 'sha256', $installation_id . '|' . implode( '|', $event_ids ) ), 0, 24 ),
			'sent_at'      => time(),
			'installation' => array(
				'id'            => $installation_id,
				'site_name'     => $this->site_name(),
				'site_url'      => $this->site_url(),
				'site_id'       => function_exists( 'get_current_blog_id' ) ? max( 0, (int) get_current_blog_id() ) : 0,
				'network_id'    => function_exists( 'get_current_network_id' ) ? max( 0, (int) get_current_network_id() ) : 0,
				'main_site_id'  => function_exists( 'get_main_site_id' ) ? max( 0, (int) get_main_site_id() ) : 0,
				'network_group' => $this->network_installation_id(),
			),
			'runtime'      => array(
				'plugin'          => defined( 'JMI_VERSION' ) ? $this->version( JMI_VERSION ) : '',
				'wordpress'       => $this->version( get_bloginfo( 'version' ) ),
				'php'             => $this->version( PHP_VERSION ),
				'php_sapi'        => $this->token( PHP_SAPI ),
				'os_family'       => $this->token( defined( 'PHP_OS_FAMILY' ) ? PHP_OS_FAMILY : PHP_OS ),
				'server_software' => $this->token( isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '' ),
				'memory_limit'    => substr( preg_replace( '/[^0-9KMGkmg-]/', '', (string) ini_get( 'memory_limit' ) ), 0, 12 ),
				'execution_limit' => max( 0, (int) ini_get( 'max_execution_time' ) ),
				'multisite'       => function_exists( 'is_multisite' ) && is_multisite(),
				'gd'              => extension_loaded( 'gd' ),
				'imagick'         => extension_loaded( 'imagick' ),
				'storage'         => $this->storage_summary(),
				'cron'            => $this->cron_metrics(),
			),
			'events'       => $events,
		);
	}

	/**
	 * Persist a failed attempt and calculate bounded exponential backoff.
	 *
	 * @param array<string, int|string> $state     Previous state.
	 * @param string                    $error     Stable error code.
	 * @param int                       $http_code HTTP status when available.
	 * @return string
	 */
	private function record_failure( $state, $error, $http_code ) {
		$state['failures']       = min( 10, max( 0, (int) $state['failures'] ) + 1 );
		$state['last_error']     = sanitize_key( $error );
		$state['last_http_code'] = max( 0, (int) $http_code );
		$delay                   = min( DAY_IN_SECONDS, 5 * MINUTE_IN_SECONDS * ( 2 ** ( $state['failures'] - 1 ) ) );
		$state['next_attempt']   = time() + $delay;
		update_option( self::STATE_OPTION, $state, false );
		$this->schedule( $delay );

		return 'failed';
	}

	/**
	 * Schedule a sender only while reports are waiting.
	 *
	 * @param int $delay Delay in seconds.
	 * @return void
	 */
	private function schedule( $delay ) {
		if ( ! $this->enabled() || empty( $this->outbox() ) || wp_next_scheduled( self::SEND_HOOK ) ) {
			return;
		}

		wp_schedule_single_event( time() + max( 1, (int) $delay ), self::SEND_HOOK );
	}

	/**
	 * Restore a missing sender event when a previous cron request was interrupted.
	 *
	 * @return void
	 */
	private function ensure_scheduled() {
		$state = $this->state();
		$delay = $state['next_attempt'] > time() ? $state['next_attempt'] - time() : 60;
		$this->schedule( $delay );
	}

	/**
	 * Acquire an option-backed sender lock with stale recovery.
	 *
	 * @return bool
	 */
	private function acquire_lock() {
		$now = time();
		if ( add_option( self::LOCK_OPTION, $now, '', false ) ) {
			return true;
		}

		$locked_at = (int) get_option( self::LOCK_OPTION, 0 );
		if ( $locked_at > $now - self::LOCK_TTL && $locked_at <= $now + 60 ) {
			return false;
		}

		delete_option( self::LOCK_OPTION );
		return add_option( self::LOCK_OPTION, $now, '', false );
	}

	/**
	 * Return normalized sender state.
	 *
	 * @return array<string, int|string>
	 */
	private function state() {
		$state = get_option( self::STATE_OPTION, array() );
		$base  = array(
			'failures'       => 0,
			'last_attempt'   => 0,
			'last_success'   => 0,
			'last_http_code' => 0,
			'last_error'     => '',
			'next_attempt'   => 0,
		);

		return is_array( $state ) ? array_merge( $base, $state ) : $base;
	}

	/**
	 * Return only structurally valid queued events.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function outbox() {
		$stored = get_option( self::OUTBOX_OPTION, array() );

		return array_values(
			array_filter(
				is_array( $stored ) ? array_slice( $stored, -self::MAX_OUTBOX ) : array(),
				static function ( $event ) {
					return is_array( $event ) && ! empty( $event['id'] ) && ! empty( $event['started_at'] );
				}
			)
		);
	}

	/**
	 * Return bounded cron observations suitable for remote diagnostics.
	 *
	 * @return array<string, int|bool|array<int, int>>
	 */
	private function cron_metrics() {
		$stored  = get_option( self::CRON_OPTION, array() );
		$stored  = is_array( $stored ) ? $stored : array();
		$samples = is_array( $stored['intervals_ms'] ?? null )
			? array_slice( array_map( 'absint', $stored['intervals_ms'] ), -self::MAX_CRON_SAMPLES )
			: array();
		$average = empty( $samples ) ? 0 : (int) round( array_sum( $samples ) / count( $samples ) );

		return array(
			'built_in_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'first_observed_at' => max( 0, (int) ( $stored['first_observed_at'] ?? 0 ) ),
			'last_observed_at'  => max( 0, (int) ( $stored['last_observed_at'] ?? 0 ) ),
			'observations'      => max( 0, (int) ( $stored['observations'] ?? 0 ) ),
			'average_ms'        => $average,
			'minimum_ms'        => empty( $samples ) ? 0 : min( $samples ),
			'maximum_ms'        => empty( $samples ) ? 0 : max( $samples ),
			'intervals_ms'      => $samples,
		);
	}

	/**
	 * Return or create the pseudonymous installation ID.
	 *
	 * @return string
	 */
	private function installation_id() {
		$stored = $this->token( get_option( self::INSTALLATION_OPTION, '' ) );
		if ( '' !== $stored ) {
			return $stored;
		}

		$stored = $this->token( wp_generate_uuid4() );
		add_option( self::INSTALLATION_OPTION, $stored, '', false );

		return $this->token( get_option( self::INSTALLATION_OPTION, $stored ) );
	}

	/**
	 * Return one shared identifier for sites belonging to the same network.
	 *
	 * @return string Empty on a single-site installation.
	 */
	private function network_installation_id() {
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() || ! function_exists( 'get_site_option' ) ) {
			return '';
		}

		$stored = $this->token( get_site_option( self::NETWORK_INSTALLATION, '' ) );
		if ( '' !== $stored ) {
			return $stored;
		}

		$stored = $this->token( wp_generate_uuid4() );
		if ( function_exists( 'add_site_option' ) ) {
			add_site_option( self::NETWORK_INSTALLATION, $stored );
		}

		return $this->token( get_site_option( self::NETWORK_INSTALLATION, $stored ) );
	}

	/**
	 * Describe uploads storage without exposing a local path.
	 *
	 * @return array<string, bool|string>
	 */
	private function storage_summary() {
		$uploads = wp_upload_dir( null, false );
		$basedir = wp_normalize_path( (string) ( $uploads['basedir'] ?? '' ) );

		return array(
			'type'     => 0 === strpos( $basedir, '//' ) ? 'network_share' : 'local_or_mapped',
			'writable' => '' !== $basedir && is_dir( $basedir ) && is_writable( $basedir ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			'error'    => ! empty( $uploads['error'] ),
		);
	}

	/**
	 * Read live data for an hourly heartbeat without risking plugin work.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function live_context() {
		$empty = array(
			'snapshot' => array(),
			'formats'  => array(),
		);

		if ( ! $this->context_provider ) {
			return $empty;
		}

		try {
			$context = call_user_func( $this->context_provider );
			return is_array( $context ) ? array_merge( $empty, $context ) : $empty;
		} catch ( Throwable $error ) {
			return $empty;
		}
	}

	/**
	 * Remove only scheduled reporting work and its transient lock.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::SEND_HOOK );
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * Return the public WordPress site name after consent.
	 *
	 * @return string
	 */
	private function site_name() {
		$label = (string) get_bloginfo( 'name' );
		$label = preg_replace( '/[^\p{L}\p{N} ._@&()-]/u', '', wp_strip_all_tags( $label ) );

		return substr( trim( $label ), 0, 80 );
	}

	/**
	 * Return the public home URL without queries or credentials.
	 *
	 * @return string
	 */
	private function site_url() {
		$url    = function_exists( 'home_url' ) ? home_url( '/' ) : '';
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = wp_parse_url( $url, PHP_URL_HOST );
		$port   = wp_parse_url( $url, PHP_URL_PORT );
		$path   = wp_parse_url( $url, PHP_URL_PATH );

		if ( ! in_array( strtolower( (string) $scheme ), array( 'http', 'https' ), true ) || ! is_string( $host ) || '' === $host ) {
			return '';
		}

		$path = is_string( $path ) ? preg_replace( '/[^a-zA-Z0-9._~!$&\'()*+,;=:@%\/-]/', '', $path ) : '/';

		return strtolower( (string) $scheme ) . '://' . strtolower( $host ) . ( $port ? ':' . (int) $port : '' ) . trailingslashit( '/' . ltrim( $path, '/' ) );
	}

	/**
	 * Return the configured endpoint.
	 *
	 * @return string
	 */
	private function endpoint() {
		return trim( (string) apply_filters( 'jmi_diagnostics_endpoint', self::DEFAULT_ENDPOINT ) );
	}

	/**
	 * Return the shared private-fleet key compiled into test builds.
	 *
	 * @return string
	 */
	private function fleet_key() {
		return trim( (string) apply_filters( 'jmi_diagnostics_fleet_key', self::DEFAULT_FLEET_KEY ) );
	}

	/**
	 * Return or create a per-installation secret used by the receiver.
	 *
	 * @return string
	 */
	private function installation_secret() {
		$secret = (string) get_option( self::INSTALLATION_SECRET, '' );
		if ( preg_match( '/^[a-zA-Z0-9]{32,100}$/', $secret ) ) {
			return $secret;
		}

		$secret = wp_generate_password( 48, false, false );
		add_option( self::INSTALLATION_SECRET, $secret, '', false );

		return (string) get_option( self::INSTALLATION_SECRET, $secret );
	}

	/**
	 * Normalize a before or after snapshot.
	 *
	 * @param mixed $snapshot Raw snapshot.
	 * @return array<string, mixed>
	 */
	private function snapshot( $snapshot ) {
		$snapshot = is_array( $snapshot ) ? $snapshot : array();
		$library  = is_array( $snapshot['library'] ?? null ) ? $snapshot['library'] : array();
		$queue    = is_array( $snapshot['queue'] ?? null ) ? $snapshot['queue'] : array();
		$counts   = array();

		foreach ( array( 'total', 'ready', 'partial', 'waiting', 'attention', 'skipped', 'reviewed' ) as $key ) {
			$counts[ $key ] = max( 0, (int) ( $library[ $key ] ?? 0 ) );
		}

		return array(
			'library' => $counts,
			'queue'   => array(
				'status'     => sanitize_key( $queue['status'] ?? '' ),
				'reason'     => sanitize_key( $queue['reason'] ?? '' ),
				'cursor'     => max( 0, (int) ( $queue['cursor'] ?? 0 ) ),
				'total'      => max( 0, (int) ( $queue['total'] ?? 0 ) ),
				'processed'  => max( 0, (int) ( $queue['processed'] ?? 0 ) ),
				'generated'  => max( 0, (int) ( $queue['generated'] ?? 0 ) ),
				'failed'     => max( 0, (int) ( $queue['failed'] ?? 0 ) ),
				'recoveries' => max( 0, (int) ( $queue['recoveries'] ?? 0 ) ),
				'recovery'   => sanitize_key( $queue['recovery'] ?? '' ),
			),
		);
	}

	/**
	 * Normalize format capability states.
	 *
	 * @param mixed $formats Raw formats.
	 * @return array<string, array<string, string>>
	 */
	private function formats( $formats ) {
		$formats = is_array( $formats ) ? $formats : array();
		$result  = array();

		foreach ( array( 'image/avif', 'image/webp' ) as $mime_type ) {
			$format               = is_array( $formats[ $mime_type ] ?? null ) ? $formats[ $mime_type ] : array();
			$result[ $mime_type ] = array(
				'state'  => sanitize_key( $format['state'] ?? 'unknown' ),
				'reason' => sanitize_key( $format['reason'] ?? 'not_checked' ),
			);
		}

		return $result;
	}

	/**
	 * Normalize worker speed, scheduling, and memory measurements.
	 *
	 * @param mixed $performance Raw performance values.
	 * @return array<string, int>
	 */
	private function performance( $performance ) {
		$performance = is_array( $performance ) ? $performance : array();
		$result      = array();

		foreach ( array( 'scheduled_for', 'start_delay_ms', 'time_budget_ms', 'memory_start', 'memory_peak', 'memory_limit' ) as $key ) {
			$result[ $key ] = max( 0, (int) ( $performance[ $key ] ?? 0 ) );
		}

		return $result;
	}

	/**
	 * Increment one normalized key in a small frequency map.
	 *
	 * @param array<string, int> $counts Frequency map.
	 * @param mixed              $value  Raw key.
	 * @return void
	 */
	private function increment_key( &$counts, $value ) {
		$key = sanitize_key( $value );
		if ( '' === $key || ( count( $counts ) >= 30 && ! isset( $counts[ $key ] ) ) ) {
			return;
		}

		$counts[ $key ] = isset( $counts[ $key ] ) ? $counts[ $key ] + 1 : 1;
	}

	/**
	 * Redact common sensitive values from a fatal error message.
	 *
	 * @param mixed $message Raw PHP message.
	 * @return string
	 */
	private function redact_message( $message ) {
		$message = is_scalar( $message ) ? wp_strip_all_tags( (string) $message ) : '';
		$message = str_ireplace( array( wp_normalize_path( JMI_PLUGIN_DIR ), wp_normalize_path( ABSPATH ) ), array( '[plugin]/', '[wordpress]/' ), wp_normalize_path( $message ) );
		$message = preg_replace( '#https?://\S+#i', '[url]', $message );
		$message = preg_replace( '/[A-Z]:\\\\(?:[^\\\\\s]+\\\\)*[^\\\\\s]*/i', '[path]', $message );
		$message = preg_replace( '#/(?:[^/\s]+/)+[^/\s]*#', '[path]', $message );
		$message = preg_replace( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $message );
		$message = preg_replace( '/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '[ip]', $message );

		return substr( trim( preg_replace( '/\s+/', ' ', $message ) ), 0, 500 );
	}

	/**
	 * Keep a short identifier made only of safe token characters.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function token( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';

		return substr( preg_replace( '/[^a-zA-Z0-9_-]/', '', $value ), 0, 64 );
	}

	/**
	 * Keep a conservative version value.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function version( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';

		return substr( preg_replace( '/[^0-9A-Za-z._-]/', '', $value ), 0, 30 );
	}

	/**
	 * Return one allowed event type.
	 *
	 * @param mixed              $value    Raw value.
	 * @param array<int, string> $allowed  Allowed values.
	 * @param string             $fallback Default value.
	 * @return string
	 */
	private function allowed_key( $value, $allowed, $fallback ) {
		$value = sanitize_key( $value );

		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}
}
