<?php
/**
 * Background conversion queue.
 *
 * @package JustModernImages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schedules small, retry-safe image jobs through WP-Cron.
 */
final class JMI_Queue {

	const PROCESS_HOOK   = 'jmi_process_attachment';
	const SCAN_HOOK      = 'jmi_scan_library';
	const STATUS_OPTION  = 'jmi_queue_status';
	const LOCK_PREFIX    = 'jmi_attachment_lock_';
	const LOCK_TTL       = 900;
	const WORKER_LOCK    = 'jmi_scan_worker_lock';
	const WORKER_TTL     = 300;
	const SCHEDULE_GRACE = 300;

	/**
	 * Attachment converter.
	 *
	 * @var JMI_Converter
	 */
	private $converter;

	/**
	 * Quality profile provider.
	 *
	 * @var JMI_Quality_Profiles
	 */
	private $profiles;

	/**
	 * Queryable per-attachment status.
	 *
	 * @var JMI_Media_Status
	 */
	private $media_status;

	/**
	 * Server capability provider.
	 *
	 * @var JMI_Capabilities|null
	 */
	private $capabilities;

	/**
	 * Bounded administrator activity history.
	 *
	 * @var JMI_Activity_Log|null
	 */
	private $activity_log;

	/**
	 * Item transitions collected by the active library scan.
	 *
	 * @var array<int, array<string, int|string>>
	 */
	private $active_run_items = array();

	/**
	 * Attachments encountered during the current frontend request.
	 *
	 * @var array<int, bool>
	 */
	private $demanded = array();

	/**
	 * Start of this request's Just Modern Images workload.
	 *
	 * Static state is shared by all cron events dispatched in one PHP request.
	 *
	 * @var float
	 */
	private static $request_worker_started_at = 0.0;

	/**
	 * Attachments attempted by all plugin workers in this PHP request.
	 *
	 * @var int
	 */
	private static $request_worker_attempts = 0;

	/**
	 * Total attachment processing time in this PHP request.
	 *
	 * @var float
	 */
	private static $request_worker_item_time = 0.0;

	/**
	 * Set up the queue.
	 *
	 * @param JMI_Converter         $converter    Attachment converter.
	 * @param JMI_Quality_Profiles  $profiles     Quality profile provider.
	 * @param JMI_Media_Status      $media_status Per-attachment status.
	 * @param JMI_Capabilities|null $capabilities Server capabilities.
	 * @param JMI_Activity_Log|null $activity_log Bounded activity history.
	 */
	public function __construct( $converter, $profiles, $media_status, $capabilities = null, $activity_log = null ) {
		$this->converter    = $converter;
		$this->profiles     = $profiles;
		$this->media_status = $media_status;
		$this->capabilities = $capabilities;
		$this->activity_log = $activity_log;
	}

	/**
	 * Register queue hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'schedule_after_metadata' ), 100, 2 );
		add_action( self::PROCESS_HOOK, array( $this, 'process_attachment' ) );
		add_action( self::SCAN_HOOK, array( $this, 'scan_library' ) );
		add_action( 'shutdown', array( $this, 'flush_demanded' ), 20 );
	}

	/**
	 * Schedule conversion after WordPress has generated attachment metadata.
	 *
	 * @param mixed $metadata      Attachment metadata.
	 * @param mixed $attachment_id Attachment ID.
	 * @return mixed
	 */
	public function schedule_after_metadata( $metadata, $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		if ( $attachment_id && $this->is_supported_attachment( $attachment_id ) ) {
			$this->schedule_attachment( $attachment_id, 5, 'upload' );
		}

		return $metadata;
	}

	/**
	 * Schedule one attachment if it is not already queued.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param int    $delay         Delay in seconds.
	 * @param string $priority      Priority lane.
	 * @param bool   $expedite      Whether an existing later event may be moved.
	 * @param bool   $update_status Whether to mark the attachment as queued.
	 * @return bool
	 */
	public function schedule_attachment( $attachment_id, $delay = 1, $priority = 'background', $expedite = false, $update_status = true ) {
		$attachment_id = absint( $attachment_id );
		$args          = array( $attachment_id );

		if ( ! $attachment_id || ! $this->is_supported_attachment( $attachment_id ) ) {
			return false;
		}

		$priority       = $this->normalize_priority( $priority );
		$scheduled_for  = time() + max( 1, (int) $delay );
		$current_event  = wp_next_scheduled( self::PROCESS_HOOK, $args );
		$current_status = $this->media_status->get( $attachment_id, $this->profiles->generation_profile() );
		$is_higher      = $this->priority_value( $priority ) > $this->priority_value( $current_status['priority'] );

		if ( $current_event ) {
			if ( (int) $current_event <= $scheduled_for ) {
				if ( $is_higher && $update_status ) {
					$this->media_status->mark_queued( $attachment_id, $priority, $this->profiles->generation_profile() );
				}
				return false;
			}

			if ( ! $expedite && ! $is_higher ) {
				return false;
			}

			wp_unschedule_event( $current_event, self::PROCESS_HOOK, $args );
		}

		$scheduled = (bool) wp_schedule_single_event( $scheduled_for, self::PROCESS_HOOK, $args );
		if ( $scheduled && $update_status ) {
			$this->media_status->mark_queued( $attachment_id, $priority, $this->profiles->generation_profile() );
		}

		return $scheduled;
	}

	/**
	 * Remember that an attachment was needed by a real frontend response.
	 *
	 * No visitor or page data is stored. IDs are deduplicated in memory and
	 * scheduled only after WordPress has finished sending the response.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function note_demand( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$limit         = min( 25, max( 1, (int) apply_filters( 'jmi_demand_limit', 10 ) ) );

		if ( $attachment_id && count( $this->demanded ) < $limit ) {
			$this->demanded[ $attachment_id ] = true;
		}
	}

	/**
	 * Move incomplete images seen on the frontend ahead of the backfill scan.
	 *
	 * @return void
	 */
	public function flush_demanded() {
		$generation_profile = $this->profiles->generation_profile();

		foreach ( array_keys( $this->demanded ) as $attachment_id ) {
			if (
				$this->is_supported_attachment( $attachment_id ) &&
				$this->media_status->needs_processing( $attachment_id, $generation_profile ) &&
				apply_filters( 'jmi_should_prioritize_attachment', true, $attachment_id )
			) {
				$this->schedule_attachment( $attachment_id, 1, 'demand', true );
			}
		}

		$this->demanded = array();
	}

	/**
	 * Process one attachment while holding an expiring lock.
	 *
	 * @param mixed $attachment_id   Attachment ID.
	 * @param bool  $owns_worker_lock Whether the adaptive scanner already owns the shared worker lock.
	 * @return bool Whether the attachment reached the converter.
	 */
	public function process_attachment( $attachment_id, $owns_worker_lock = false ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id ) {
			return false;
		}

		$generation_profile = $this->profiles->generation_profile();
		$initial_status     = $this->media_status->get( $attachment_id, $generation_profile );
		if ( ! $owns_worker_lock && $this->is_current_terminal( $initial_status ) ) {
			return false;
		}

		$acquired_worker_lock = false;
		$standalone_started   = 0.0;
		$standalone_before    = array();
		if ( ! $owns_worker_lock ) {
			$this->start_request_budget();
			$time_budget = $this->worker_time_budget( self::$request_worker_started_at );
			$max_items   = min( 100, max( 1, (int) apply_filters( 'jmi_worker_max_items', 50 ) ) );
			$threshold   = min( 0.95, max( 0.5, (float) apply_filters( 'jmi_worker_memory_threshold', 0.8 ) ) );

			if ( $this->request_stop_reason( $time_budget, $max_items, $threshold ) ) {
				$this->reschedule_attachment( $attachment_id, 5 );
				return false;
			}

			if ( ! $this->acquire_worker_lock() ) {
				$this->reschedule_attachment( $attachment_id, 30 );
				return false;
			}
			$acquired_worker_lock = true;
			$initial_status       = $this->media_status->get( $attachment_id, $generation_profile );
			if ( $this->is_current_terminal( $initial_status ) ) {
				$this->release_worker_lock();
				return false;
			}
			if ( $this->activity_log ) {
				$standalone_started = microtime( true );
				$standalone_before  = $this->safe_activity_snapshot();
			}
		}

		try {
			$item_started = microtime( true );
			if ( ! $this->acquire_lock( $attachment_id ) ) {
				$this->record_request_attempt( microtime( true ) - $item_started );
				$this->reschedule_attachment( $attachment_id, 30 );
				return false;
			}

			$current_status = $this->media_status->get( $attachment_id, $generation_profile );
			$this->media_status->mark_processing( $attachment_id, $current_status['priority'], $generation_profile );

			try {
				$summary = $this->converter->convert_attachment( $attachment_id );
			} catch ( Throwable $error ) {
				$summary = array(
					'attachment_id' => $attachment_id,
					'generated'     => 0,
					'reused'        => 0,
					'retained'      => 0,
					'skipped'       => 0,
					'failed'        => 1,
					'last_reason'   => 'unexpected_worker_failure',
					'state'         => 'failed',
				);
			} finally {
				$this->release_lock( $attachment_id );
			}

			$this->media_status->record_result( $attachment_id, $generation_profile, $summary );
			$this->record_counters( $summary );
			$item_duration = microtime( true ) - $item_started;
			$this->record_request_attempt( $item_duration );
			$final_status = $this->media_status->get( $attachment_id, $generation_profile );
			$item_record  = $this->activity_item( $attachment_id, $initial_status, $final_status, $summary, $item_duration );

			if ( $owns_worker_lock && $this->activity_log ) {
				$this->active_run_items[] = $item_record;
			} elseif ( $this->activity_log ) {
				$this->record_activity(
					array(
						'type'        => 'attachment',
						'source'      => (string) ( $initial_status['priority'] ?? 'background' ),
						'started_at'  => (int) floor( $standalone_started ),
						'finished_at' => time(),
						'duration_ms' => (int) round( ( microtime( true ) - $standalone_started ) * 1000 ),
						'stop_reason' => sanitize_key( $summary['last_reason'] ?? '' ),
						'complete'    => true,
						'attempts'    => 1,
						'processed'   => 1,
						'before'      => $standalone_before,
						'after'       => $this->safe_activity_snapshot(),
						'items'       => array( $item_record ),
					)
				);
			}

			return true;
		} finally {
			if ( $acquired_worker_lock ) {
				$this->release_worker_lock();
			}
		}
	}

	/**
	 * Start or restart a scan of the Media Library.
	 *
	 * @param string $reason Reason for the scan.
	 * @return void
	 */
	public function start_scan( $reason = 'manual' ) {
		$generation_profile = $this->profiles->generation_profile();
		$current            = $this->status();
		$started_at         = microtime( true );

		if (
			'upgrade' === sanitize_key( $reason ) &&
			in_array( $current['status'], array( 'queued', 'running' ), true ) &&
			$generation_profile === $current['generation_profile']
		) {
			$this->ensure_scan_scheduled();
			return;
		}

		$stats  = $this->media_status->library_stats( $generation_profile );
		$before = $this->safe_activity_snapshot( $stats, $current );
		update_option(
			self::STATUS_OPTION,
			array(
				'status'                  => 'queued',
				'reason'                  => sanitize_key( $reason ),
				'cursor'                  => 0,
				'total'                   => $stats['total'],
				'scheduled'               => 0,
				'processed'               => 0,
				'generated'               => 0,
				'reused'                  => 0,
				'retained'                => 0,
				'skipped'                 => 0,
				'failed'                  => 0,
				'last_reason'             => '',
				'last_update'             => time(),
				'generation_profile'      => $generation_profile,
				'scan_started_at'         => time(),
				'last_worker_at'          => (int) $current['last_worker_at'],
				'last_worker_items'       => (int) $current['last_worker_items'],
				'last_worker_attempts'    => (int) $current['last_worker_attempts'],
				'last_worker_ms'          => (int) $current['last_worker_ms'],
				'last_worker_stop'        => (string) $current['last_worker_stop'],
				'last_worker_version'     => (string) $current['last_worker_version'],
				'last_schedule_at'        => (int) $current['last_schedule_at'],
				'last_schedule_result'    => (string) $current['last_schedule_result'],
				'last_lock_contention_at' => (int) $current['last_lock_contention_at'],
				'last_lock_recovery_at'   => (int) $current['last_lock_recovery_at'],
			),
			false
		);

		$this->schedule_scan( 3 );
		$this->record_activity(
			array(
				'type'        => 'scan_requested',
				'source'      => sanitize_key( $reason ),
				'started_at'  => (int) floor( $started_at ),
				'finished_at' => time(),
				'duration_ms' => (int) round( ( microtime( true ) - $started_at ) * 1000 ),
				'stop_reason' => 'scheduled',
				'complete'    => false,
				'attempts'    => 0,
				'processed'   => 0,
				'before'      => $before,
				'after'       => $this->safe_activity_snapshot( $stats ),
				'items'       => array(),
			)
		);
	}

	/**
	 * Restore a missing scan event and clear an abandoned worker lock.
	 *
	 * Single cron events are removed before their callback runs. A fatal error
	 * or process termination must therefore not stop the queue permanently.
	 *
	 * @return void
	 */
	public function ensure_scan_scheduled() {
		$status = $this->status();
		if ( ! in_array( $status['status'], array( 'queued', 'running' ), true ) ) {
			return;
		}

		$now       = time();
		$locked_at = (int) get_option( self::WORKER_LOCK, 0 );
		if ( $locked_at && ( $locked_at <= $now - self::WORKER_TTL || $locked_at > $now + 60 ) ) {
			delete_option( self::WORKER_LOCK );
			$status['last_lock_recovery_at'] = $now;
			$status['last_update']           = $now;
			update_option( self::STATUS_OPTION, $status, false );
		}

		$next_event = wp_next_scheduled( self::SCAN_HOOK );
		if ( $next_event && (int) $next_event >= $now - self::SCHEDULE_GRACE ) {
			return;
		}

		if ( $next_event ) {
			wp_unschedule_event( $next_event, self::SCAN_HOOK );
		}

		$this->schedule_scan( 1 );
	}

	/**
	 * Process existing attachments within adaptive time and memory budgets.
	 *
	 * @return void
	 */
	public function scan_library() {
		global $wpdb;

		if ( ! $this->acquire_worker_lock() ) {
			$status                            = $this->status();
			$status['last_lock_contention_at'] = time();
			$status['last_update']             = time();
			update_option( self::STATUS_OPTION, $status, false );
			$this->schedule_scan( 30 );
			return;
		}

		$this->start_request_budget();
		$started_at             = microtime( true );
		$status                 = $this->status();
		$cursor                 = isset( $status['cursor'] ) ? absint( $status['cursor'] ) : 0;
		$page_size              = min( 100, max( 1, (int) apply_filters( 'jmi_scan_batch_size', 20 ) ) );
		$max_items              = min( 100, max( 1, (int) apply_filters( 'jmi_worker_max_items', 50 ) ) );
		$time_budget            = $this->worker_time_budget( $started_at );
		$memory_threshold       = min( 0.95, max( 0.5, (float) apply_filters( 'jmi_worker_memory_threshold', 0.8 ) ) );
		$attempts               = 0;
		$processed              = 0;
		$stop_reason            = '';
		$complete               = false;
		$run_source             = sanitize_key( $status['reason'] ?? 'background' );
		$run_before             = $this->activity_log ? $this->safe_activity_snapshot( null, $status ) : array();
		$this->active_run_items = array();

		try {
			while ( ! $stop_reason ) {
				$stop_reason = $this->request_stop_reason( $time_budget, $max_items, $memory_threshold );

				if ( $stop_reason ) {
					break;
				}

				$query = $wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts}
					WHERE ID > %d
					AND post_type = 'attachment'
					AND post_mime_type IN ('image/jpeg', 'image/png')
					ORDER BY ID ASC
					LIMIT %d",
					$cursor,
					min( $page_size, $max_items - $attempts )
				);
				// A cursor avoids increasingly expensive offsets on large media libraries.
				$attachment_ids = $wpdb->get_col( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

				if ( empty( $attachment_ids ) ) {
					$complete    = true;
					$stop_reason = 'complete';
					break;
				}

				foreach ( $attachment_ids as $attachment_id ) {
					$stop_reason = $this->request_stop_reason( $time_budget, $max_items, $memory_threshold );

					if ( $stop_reason ) {
						break;
					}

					$attachment_id = absint( $attachment_id );
					$event_time    = wp_next_scheduled( self::PROCESS_HOOK, array( $attachment_id ) );
					if ( $event_time ) {
						wp_unschedule_event( $event_time, self::PROCESS_HOOK, array( $attachment_id ) );
					}

					if ( $this->process_attachment( $attachment_id, true ) ) {
						++$processed;
					}
					++$attempts;
					$cursor = $attachment_id;
					$this->record_scan_cursor( $cursor );
				}
			}
		} catch ( Throwable $error ) {
			$stop_reason           = 'unexpected_worker_failure';
			$status                = $this->status();
			$status['last_reason'] = 'unexpected_worker_failure';
			update_option( self::STATUS_OPTION, $status, false );
		} finally {
			try {
				$this->record_worker_run(
					$processed,
					$attempts,
					microtime( true ) - $started_at,
					$stop_reason,
					$complete
				);
				if ( $this->activity_log ) {
					$this->record_activity(
						array(
							'type'           => 'scan',
							'source'         => $run_source,
							'started_at'     => (int) floor( $started_at ),
							'finished_at'    => time(),
							'duration_ms'    => (int) round( ( microtime( true ) - $started_at ) * 1000 ),
							'worker_version' => defined( 'JMI_VERSION' ) ? (string) JMI_VERSION : '',
							'stop_reason'    => $stop_reason,
							'complete'       => $complete,
							'attempts'       => $attempts,
							'processed'      => $processed,
							'before'         => $run_before,
							'after'          => $this->safe_activity_snapshot(),
							'items'          => $this->active_run_items,
						)
					);
				}
			} finally {
				$this->active_run_items = array();
				$this->release_worker_lock();
			}
		}

		if ( ! $complete ) {
			$this->schedule_scan( 5 );
		}
	}

	/**
	 * Return the safe amount of time available to this worker.
	 *
	 * @param float $started_at Worker start time.
	 * @return float Seconds available.
	 */
	private function worker_time_budget( $started_at ) {
		$configured = min( 45, max( 5, (int) apply_filters( 'jmi_worker_time_budget', 20 ) ) );
		$php_limit  = (int) ini_get( 'max_execution_time' );

		if ( $php_limit <= 0 ) {
			return (float) $configured;
		}

		$request_started = isset( $_SERVER['REQUEST_TIME_FLOAT'] )
			? (float) $_SERVER['REQUEST_TIME_FLOAT']
			: $started_at;
		$available       = ( $request_started + $php_limit - 5 ) - $started_at;

		return max( 0.0, min( (float) $configured, $available ) );
	}

	/**
	 * Initialize the budget shared by all plugin jobs in this PHP request.
	 *
	 * @return void
	 */
	private function start_request_budget() {
		if ( self::$request_worker_started_at <= 0 ) {
			self::$request_worker_started_at = microtime( true );
		}
	}

	/**
	 * Return the current request-wide reason to yield control.
	 *
	 * @param float $time_budget      Safe worker time budget.
	 * @param int   $max_items        Per-request item ceiling.
	 * @param float $memory_threshold Fraction of memory that may be occupied.
	 * @return string Empty when work may continue, otherwise a stable stop code.
	 */
	private function request_stop_reason( $time_budget, $max_items, $memory_threshold ) {
		$this->start_request_budget();
		$average_duration = self::$request_worker_attempts
			? self::$request_worker_item_time / self::$request_worker_attempts
			: 0.0;

		return $this->worker_stop_reason(
			self::$request_worker_attempts,
			microtime( true ) - self::$request_worker_started_at,
			$average_duration,
			$time_budget,
			$max_items,
			memory_get_usage( true ),
			$this->memory_limit_bytes(),
			$memory_threshold
		);
	}

	/**
	 * Add one completed attempt to the request-wide workload.
	 *
	 * @param float $duration Attachment processing time in seconds.
	 * @return void
	 */
	private function record_request_attempt( $duration ) {
		++self::$request_worker_attempts;
		self::$request_worker_item_time += max( 0.0, (float) $duration );
	}

	/**
	 * Decide whether another image can start safely.
	 *
	 * @param int   $attempts         Images attempted so far.
	 * @param float $elapsed          Worker runtime in seconds.
	 * @param float $average_duration Average image time in seconds.
	 * @param float $time_budget      Safe worker time budget.
	 * @param int   $max_items        Per-run item ceiling.
	 * @param int   $memory_usage     Current allocated memory.
	 * @param int   $memory_limit     PHP memory limit in bytes, or zero when unlimited.
	 * @param float $memory_threshold Fraction of memory that may be occupied.
	 * @return string Empty when work may continue, otherwise a stable stop code.
	 */
	private function worker_stop_reason( $attempts, $elapsed, $average_duration, $time_budget, $max_items, $memory_usage, $memory_limit, $memory_threshold ) {
		if ( $attempts >= $max_items ) {
			return 'item_limit';
		}

		if ( $memory_limit > 0 && $memory_usage >= (int) floor( $memory_limit * $memory_threshold ) ) {
			return 'memory_pressure';
		}

		$next_estimate = $attempts ? max( 0.5, $average_duration * 1.5 ) : 0.0;
		if ( $elapsed + $next_estimate + 2.0 >= $time_budget ) {
			return 'time_budget';
		}

		return '';
	}

	/**
	 * Return the configured PHP memory limit in bytes.
	 *
	 * @return int Zero means unlimited or unknown.
	 */
	private function memory_limit_bytes() {
		$limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );

		return $limit > 0 ? (int) $limit : 0;
	}

	/**
	 * Persist the resumable cursor after each attempted attachment.
	 *
	 * @param int $cursor Last visited attachment ID.
	 * @return void
	 */
	private function record_scan_cursor( $cursor ) {
		$status                = $this->status();
		$status['cursor']      = absint( $cursor );
		$status['status']      = 'running';
		$status['scheduled']   = (int) $status['scheduled'] + 1;
		$status['last_update'] = time();
		update_option( self::STATUS_OPTION, $status, false );
	}

	/**
	 * Persist observability for the most recent adaptive worker run.
	 *
	 * @param int    $processed   Attachments that reached the converter.
	 * @param int    $attempts    Attachments claimed by this run.
	 * @param float  $duration    Runtime in seconds.
	 * @param string $stop_reason Stable stop code.
	 * @param bool   $complete    Whether the library cursor reached the end.
	 * @return void
	 */
	private function record_worker_run( $processed, $attempts, $duration, $stop_reason, $complete ) {
		$status                         = $this->status();
		$status['status']               = $complete ? 'complete' : 'running';
		$status['last_update']          = time();
		$status['last_worker_at']       = time();
		$status['last_worker_items']    = max( 0, (int) $processed );
		$status['last_worker_attempts'] = max( 0, (int) $attempts );
		$status['last_worker_ms']       = max( 0, (int) round( $duration * 1000 ) );
		$status['last_worker_stop']     = sanitize_key( $stop_reason );
		$status['last_worker_version']  = defined( 'JMI_VERSION' ) ? (string) JMI_VERSION : '';
		update_option( self::STATUS_OPTION, $status, false );
	}

	/**
	 * Schedule the next scan without creating duplicate events.
	 *
	 * @param int $delay Delay in seconds.
	 * @return void
	 */
	private function schedule_scan( $delay ) {
		if ( ! wp_next_scheduled( self::SCAN_HOOK ) ) {
			$scheduled = wp_schedule_single_event( time() + max( 1, (int) $delay ), self::SCAN_HOOK, array(), true );
			$succeeded = ! is_wp_error( $scheduled ) && false !== $scheduled;
			if ( ! $succeeded && wp_next_scheduled( self::SCAN_HOOK ) ) {
				// Another request may have restored the same event concurrently.
				$succeeded = true;
			}
			$status = $this->status();

			$status['last_schedule_at']     = time();
			$status['last_schedule_result'] = $succeeded ? 'scheduled' : 'failed';
			if ( ! $succeeded ) {
				$status['last_reason'] = 'schedule_failed';
			}
			update_option( self::STATUS_OPTION, $status, false );
		}
	}

	/**
	 * Return current scheduler and worker-lock state for the settings screen.
	 *
	 * @return array<string, int|string>
	 */
	public function diagnostics() {
		$now        = time();
		$next_event = wp_next_scheduled( self::SCAN_HOOK );
		$locked_at  = (int) get_option( self::WORKER_LOCK, 0 );
		$lock_state = 'free';

		if ( $locked_at ) {
			$lock_state = ( $locked_at <= $now - self::WORKER_TTL || $locked_at > $now + 60 ) ? 'stale' : 'held';
		}

		return array(
			'next_event' => $next_event ? (int) $next_event : 0,
			'lock_state' => $lock_state,
			'lock_age'   => $locked_at ? max( 0, $now - $locked_at ) : 0,
		);
	}

	/**
	 * Requeue an attachment without discarding its current priority lane.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $delay         Delay in seconds.
	 * @return void
	 */
	private function reschedule_attachment( $attachment_id, $delay ) {
		$status   = $this->media_status->get( $attachment_id, $this->profiles->generation_profile() );
		$priority = $this->normalize_priority( $status['priority'] );

		$this->schedule_attachment( $attachment_id, $delay, $priority, true, false );
	}

	/**
	 * Check whether a delayed event targets an already settled image.
	 *
	 * @param array<string, mixed> $status Attachment status.
	 * @return bool
	 */
	private function is_current_terminal( $status ) {
		return in_array( $status['state'] ?? '', array( 'ready', 'partial', 'skipped' ), true );
	}

	/**
	 * Return normalized queue status.
	 *
	 * @return array<string, int|string>
	 */
	public function status() {
		$status = get_option( self::STATUS_OPTION, array() );
		$base   = array(
			'status'                  => 'idle',
			'reason'                  => '',
			'cursor'                  => 0,
			'total'                   => 0,
			'scheduled'               => 0,
			'processed'               => 0,
			'generated'               => 0,
			'reused'                  => 0,
			'retained'                => 0,
			'skipped'                 => 0,
			'failed'                  => 0,
			'last_reason'             => '',
			'last_update'             => 0,
			'generation_profile'      => '',
			'scan_started_at'         => 0,
			'last_worker_at'          => 0,
			'last_worker_items'       => 0,
			'last_worker_attempts'    => 0,
			'last_worker_ms'          => 0,
			'last_worker_stop'        => '',
			'last_worker_version'     => '',
			'last_schedule_at'        => 0,
			'last_schedule_result'    => '',
			'last_lock_contention_at' => 0,
			'last_lock_recovery_at'   => 0,
		);

		return is_array( $status ) ? array_merge( $base, $status ) : $base;
	}

	/**
	 * Clear scheduled work on deactivation.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::PROCESS_HOOK );
		wp_clear_scheduled_hook( self::SCAN_HOOK );
		delete_option( self::WORKER_LOCK );
	}

	/**
	 * Update aggregate queue counters.
	 *
	 * @param array<string, int|string> $summary Conversion summary.
	 * @return void
	 */
	private function record_counters( $summary ) {
		$status = $this->status();
		++$status['processed'];

		foreach ( array( 'generated', 'reused', 'retained', 'skipped', 'failed' ) as $counter ) {
			$status[ $counter ] += isset( $summary[ $counter ] ) ? (int) $summary[ $counter ] : 0;
		}

		$status['last_reason'] = sanitize_key( $summary['last_reason'] ?? '' );
		$status['last_update'] = time();
		update_option( self::STATUS_OPTION, $status, false );
	}

	/**
	 * Build a compact before or after snapshot for the activity history.
	 *
	 * @param array<string, int>|null        $stats  Known library statistics.
	 * @param array<string, int|string>|null $status Known queue status.
	 * @return array<string, mixed>
	 */
	private function activity_snapshot( $stats = null, $status = null ) {
		$stats  = is_array( $stats ) ? $stats : $this->media_status->library_stats( $this->profiles->generation_profile() );
		$status = is_array( $status ) ? $status : $this->status();

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
	 * Capture diagnostics without allowing them to affect queue execution.
	 *
	 * @param array<string, int>|null        $stats  Known library statistics.
	 * @param array<string, int|string>|null $status Known queue status.
	 * @return array<string, mixed>
	 */
	private function safe_activity_snapshot( $stats = null, $status = null ) {
		try {
			return $this->activity_snapshot( $stats, $status );
		} catch ( Throwable $error ) {
			return array(
				'library' => array(),
				'queue'   => array(),
			);
		}
	}

	/**
	 * Build one per-attachment transition without retaining file information.
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string, mixed> $before        Status before processing.
	 * @param array<string, mixed> $after         Status after processing.
	 * @param array<string, mixed> $summary       Converter result.
	 * @param float                $duration      Processing time in seconds.
	 * @return array<string, int|string>
	 */
	private function activity_item( $attachment_id, $before, $after, $summary, $duration ) {
		return array(
			'attachment_id' => absint( $attachment_id ),
			'before_state'  => sanitize_key( $before['state'] ?? '' ),
			'after_state'   => sanitize_key( $after['state'] ?? '' ),
			'before_reason' => sanitize_key( $before['reason'] ?? '' ),
			'after_reason'  => sanitize_key( $after['reason'] ?? '' ),
			'queued_from'   => sanitize_key( $before['queued_from'] ?? '' ),
			'queue_source'  => sanitize_key( $before['queue_source'] ?? '' ),
			'generated'     => max( 0, (int) ( $summary['generated'] ?? 0 ) ),
			'reused'        => max( 0, (int) ( $summary['reused'] ?? 0 ) ),
			'retained'      => max( 0, (int) ( $summary['retained'] ?? 0 ) ),
			'skipped'       => max( 0, (int) ( $summary['skipped'] ?? 0 ) ),
			'failed'        => max( 0, (int) ( $summary['failed'] ?? 0 ) ),
			'duration_ms'   => max( 0, (int) round( $duration * 1000 ) ),
		);
	}

	/**
	 * Add environment information and store one bounded history entry.
	 *
	 * @param array<string, mixed> $entry Activity entry.
	 * @return void
	 */
	private function record_activity( $entry ) {
		if ( ! $this->activity_log || ! method_exists( $this->activity_log, 'record' ) ) {
			return;
		}

		try {
			$server  = substr( hash( 'sha256', php_uname( 'n' ) . '|' . PHP_VERSION . '|' . PHP_SAPI ), 0, 12 );
			$formats = array();
			if ( $this->capabilities && method_exists( $this->capabilities, 'diagnostic_summary' ) ) {
				$environment = $this->capabilities->diagnostic_summary();
				$server      = (string) ( $environment['environment_id'] ?? $server );
				$formats     = is_array( $environment['formats'] ?? null ) ? $environment['formats'] : array();
			}

			$entry['server']         = $server;
			$entry['formats']        = $formats;
			$entry['worker_version'] = (string) ( $entry['worker_version'] ?? ( defined( 'JMI_VERSION' ) ? JMI_VERSION : '' ) );

			$this->activity_log->record( $entry );
		} catch ( Throwable $error ) {
			// Diagnostics must never interrupt image processing.
			return;
		}
	}

	/**
	 * Acquire an option-backed lock with stale-lock recovery.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function acquire_lock( $attachment_id ) {
		$key = self::LOCK_PREFIX . $attachment_id;
		$now = time();

		if ( add_option( $key, $now, '', false ) ) {
			return true;
		}

		$locked_at = (int) get_option( $key, 0 );
		if ( $locked_at && $locked_at > $now - self::LOCK_TTL ) {
			return false;
		}

		delete_option( $key );

		return add_option( $key, $now, '', false );
	}

	/**
	 * Acquire the shared adaptive-worker lock with stale recovery.
	 *
	 * @return bool
	 */
	private function acquire_worker_lock() {
		$now = time();

		if ( add_option( self::WORKER_LOCK, $now, '', false ) ) {
			return true;
		}

		$locked_at = (int) get_option( self::WORKER_LOCK, 0 );
		if ( $locked_at && $locked_at > $now - self::WORKER_TTL && $locked_at <= $now + 60 ) {
			return false;
		}

		delete_option( self::WORKER_LOCK );

		return add_option( self::WORKER_LOCK, $now, '', false );
	}

	/**
	 * Release the shared adaptive-worker lock.
	 *
	 * @return void
	 */
	private function release_worker_lock() {
		delete_option( self::WORKER_LOCK );
	}

	/**
	 * Release an attachment lock.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	private function release_lock( $attachment_id ) {
		delete_option( self::LOCK_PREFIX . $attachment_id );
	}

	/**
	 * Check the attachment's declared MIME type before scheduling it.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public function is_supported_attachment( $attachment_id ) {
		return in_array( get_post_mime_type( $attachment_id ), array( 'image/jpeg', 'image/png' ), true );
	}

	/**
	 * Normalize a priority lane.
	 *
	 * @param string $priority Priority lane.
	 * @return string
	 */
	private function normalize_priority( $priority ) {
		$priority = sanitize_key( $priority );

		return in_array( $priority, array( 'manual', 'upload', 'demand', 'background' ), true ) ? $priority : 'background';
	}

	/**
	 * Convert a lane into a comparable value.
	 *
	 * @param string $priority Priority lane.
	 * @return int
	 */
	private function priority_value( $priority ) {
		$values = array(
			'manual'     => 400,
			'upload'     => 300,
			'demand'     => 200,
			'background' => 100,
		);

		return $values[ sanitize_key( $priority ) ] ?? 0;
	}
}
