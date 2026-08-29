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
	 * Set up the queue.
	 *
	 * @param JMI_Converter $converter Attachment converter.
	 */
	public function __construct( $converter ) {
		$this->converter = $converter;
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
			$this->schedule_attachment( $attachment_id, 5 );
		}

		return $metadata;
	}

	/**
	 * Schedule one attachment if it is not already queued.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $delay         Delay in seconds.
	 * @return bool
	 */
	public function schedule_attachment( $attachment_id, $delay = 1 ) {
		$attachment_id = absint( $attachment_id );
		$args          = array( $attachment_id );

		if ( ! $attachment_id || wp_next_scheduled( self::PROCESS_HOOK, $args ) ) {
			return false;
		}

		return (bool) wp_schedule_single_event( time() + max( 1, (int) $delay ), self::PROCESS_HOOK, $args );
	}

	/**
	 * Process one attachment while holding an expiring lock.
	 *
	 * @param mixed $attachment_id Attachment ID.
	 * @return void
	 */
	public function process_attachment( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id || ! $this->acquire_lock( $attachment_id ) ) {
			return;
		}

		try {
			$summary = $this->converter->convert_attachment( $attachment_id );
			$this->record_result( $summary );
		} catch ( Throwable $error ) {
			$this->record_result(
				array(
					'attachment_id' => $attachment_id,
					'generated'     => 0,
					'reused'        => 0,
					'skipped'       => 0,
					'failed'        => 1,
					'last_reason'   => 'unexpected_worker_failure',
				)
			);
		} finally {
			$this->release_lock( $attachment_id );
		}
	}

	/**
	 * Start or restart a scan of the Media Library.
	 *
	 * @param string $reason Reason for the scan.
	 * @return void
	 */
	public function start_scan( $reason = 'manual' ) {
		update_option(
			self::STATUS_OPTION,
			array(
				'status'      => 'queued',
				'reason'      => sanitize_key( $reason ),
				'cursor'      => 0,
				'scheduled'   => 0,
				'processed'   => 0,
				'generated'   => 0,
				'reused'      => 0,
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
			if ( $this->schedule_attachment( $attachment_id, 2 + (int) $index ) ) {
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
			'scheduled'   => 0,
			'processed'   => 0,
			'generated'   => 0,
			'reused'      => 0,
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
	private function record_result( $summary ) {
		$status = $this->status();
		++$status['processed'];

		foreach ( array( 'generated', 'reused', 'skipped', 'failed' ) as $counter ) {
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
	private function is_supported_attachment( $attachment_id ) {
		return in_array( get_post_mime_type( $attachment_id ), array( 'image/jpeg', 'image/png' ), true );
	}
}
