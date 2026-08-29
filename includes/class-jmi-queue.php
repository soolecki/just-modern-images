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

	const PROCESS_HOOK  = 'jmi_process_attachment';
	const SCAN_HOOK     = 'jmi_scan_library';
	const STATUS_OPTION = 'jmi_queue_status';
	const LOCK_PREFIX   = 'jmi_attachment_lock_';
	const LOCK_TTL      = 900;

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
	 * Attachments encountered during the current frontend request.
	 *
	 * @var array<int, bool>
	 */
	private $demanded = array();

	/**
	 * Set up the queue.
	 *
	 * @param JMI_Converter        $converter    Attachment converter.
	 * @param JMI_Quality_Profiles $profiles     Quality profile provider.
	 * @param JMI_Media_Status     $media_status Per-attachment status.
	 */
	public function __construct( $converter, $profiles, $media_status ) {
		$this->converter    = $converter;
		$this->profiles     = $profiles;
		$this->media_status = $media_status;
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
	 * @return bool
	 */
	public function schedule_attachment( $attachment_id, $delay = 1, $priority = 'background', $expedite = false ) {
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
				if ( $is_higher ) {
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
		if ( $scheduled ) {
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
	 * @param mixed $attachment_id Attachment ID.
	 * @return void
	 */
	public function process_attachment( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id ) {
			return;
		}

		if ( ! $this->acquire_lock( $attachment_id ) ) {
			wp_schedule_single_event( time() + 30, self::PROCESS_HOOK, array( $attachment_id ) );
			return;
		}

		$generation_profile = $this->profiles->generation_profile();
		$current_status     = $this->media_status->get( $attachment_id, $generation_profile );
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
	}

	/**
	 * Start or restart a scan of the Media Library.
	 *
	 * @param string $reason Reason for the scan.
	 * @return void
	 */
	public function start_scan( $reason = 'manual' ) {
		$stats = $this->media_status->library_stats( $this->profiles->generation_profile() );
		update_option(
			self::STATUS_OPTION,
			array(
				'status'      => 'queued',
				'reason'      => sanitize_key( $reason ),
				'cursor'      => 0,
				'total'       => $stats['total'],
				'scheduled'   => 0,
				'processed'   => 0,
				'generated'   => 0,
				'reused'      => 0,
				'retained'    => 0,
				'skipped'     => 0,
				'failed'      => 0,
				'last_reason' => '',
				'last_update' => time(),
			),
			false
		);

		if ( ! wp_next_scheduled( self::SCAN_HOOK ) ) {
			wp_schedule_single_event( time() + 3, self::SCAN_HOOK );
		}
	}

	/**
	 * Schedule a bounded page of existing image attachments.
	 *
	 * @return void
	 */
	public function scan_library() {
		global $wpdb;

		$status = $this->status();
		$cursor = isset( $status['cursor'] ) ? absint( $status['cursor'] ) : 0;
		$limit  = (int) apply_filters( 'jmi_scan_batch_size', 20 );
		$limit  = min( 100, max( 1, $limit ) );

		$query = $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			WHERE ID > %d
			AND post_type = 'attachment'
			AND post_mime_type IN ('image/jpeg', 'image/png')
			ORDER BY ID ASC
			LIMIT %d",
			$cursor,
			$limit
		);
		// A cursor avoids increasingly expensive offsets on large media libraries.
		$attachment_ids = $wpdb->get_col( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $attachment_ids ) ) {
			$status['status']      = 'complete';
			$status['last_update'] = time();
			update_option( self::STATUS_OPTION, $status, false );
			return;
		}

		foreach ( $attachment_ids as $index => $attachment_id ) {
			if ( $this->schedule_attachment( $attachment_id, 2 + (int) $index, 'background' ) ) {
				++$status['scheduled'];
			}
			$status['cursor'] = absint( $attachment_id );
		}

		$status['status']      = 'running';
		$status['last_update'] = time();
		update_option( self::STATUS_OPTION, $status, false );

		wp_schedule_single_event( time() + 30, self::SCAN_HOOK );
	}

	/**
	 * Return normalized queue status.
	 *
	 * @return array<string, int|string>
	 */
	public function status() {
		$status = get_option( self::STATUS_OPTION, array() );
		$base   = array(
			'status'      => 'idle',
			'reason'      => '',
			'cursor'      => 0,
			'total'       => 0,
			'scheduled'   => 0,
			'processed'   => 0,
			'generated'   => 0,
			'reused'      => 0,
			'retained'    => 0,
			'skipped'     => 0,
			'failed'      => 0,
			'last_reason' => '',
			'last_update' => 0,
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
