<?php
/**
 * Per-attachment processing state and library statistics.
 *
 * @package JustModernImages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps small, queryable status records separate from the image manifest.
 */
final class JMI_Media_Status {

	const STATE_META_KEY  = '_jmi_state';
	const DETAIL_META_KEY = '_jmi_status';

	/**
	 * Return a normalized attachment status for the active profile.
	 *
	 * @param int    $attachment_id    Attachment ID.
	 * @param string $generation_profile Active generation profile.
	 * @return array<string, int|string>
	 */
	public function get( $attachment_id, $generation_profile ) {
		$raw     = get_post_meta( absint( $attachment_id ), self::STATE_META_KEY, true );
		$details = get_post_meta( absint( $attachment_id ), self::DETAIL_META_KEY, true );
		$parsed  = $this->parse_state( is_string( $raw ) ? $raw : '', $generation_profile );

		$base = array(
			'state'         => $parsed['state'],
			'stored_state'  => $parsed['stored_state'],
			'reason'        => '',
			'updated_at'    => 0,
			'retry_after'   => 0,
			'failure_count' => 0,
			'priority'      => '',
			'profile'       => '',
		);

		if ( ! is_array( $details ) ) {
			return $base;
		}

		$details                = array_intersect_key( $details, $base );
		$status                 = array_merge( $base, $details );
		$status['state']        = $parsed['state'];
		$status['stored_state'] = $parsed['stored_state'];

		if ( 'stale' === $status['state'] && '' === $status['reason'] ) {
			$status['reason'] = 'quality_changed';
		}

		return $status;
	}

	/**
	 * Mark an attachment as waiting for a worker.
	 *
	 * @param int    $attachment_id    Attachment ID.
	 * @param string $priority         Queue priority.
	 * @param string $generation_profile Active generation profile.
	 * @return void
	 */
	public function mark_queued( $attachment_id, $priority, $generation_profile ) {
		$previous      = $this->get( $attachment_id, $generation_profile );
		$same_profile  = $previous['profile'] === $generation_profile;
		$failure_count = $same_profile ? (int) $previous['failure_count'] : 0;
		$this->write(
			$attachment_id,
			'queued',
			'',
			array(
				'reason'        => 'scheduled',
				'priority'      => sanitize_key( $priority ),
				'updated_at'    => time(),
				'failure_count' => $failure_count,
				'retry_after'   => 0,
				'profile'       => $generation_profile,
			)
		);
	}

	/**
	 * Mark an attachment as being processed.
	 *
	 * @param int    $attachment_id    Attachment ID.
	 * @param string $priority         Queue priority.
	 * @param string $generation_profile Active generation profile.
	 * @return void
	 */
	public function mark_processing( $attachment_id, $priority, $generation_profile ) {
		$previous = $this->get( $attachment_id, $generation_profile );
		$this->write(
			$attachment_id,
			'processing',
			'',
			array(
				'reason'        => 'worker_started',
				'priority'      => sanitize_key( $priority ),
				'updated_at'    => time(),
				'failure_count' => (int) $previous['failure_count'],
				'retry_after'   => 0,
				'profile'       => $generation_profile,
			)
		);
	}

	/**
	 * Persist the outcome of one attachment job.
	 *
	 * @param int                  $attachment_id    Attachment ID.
	 * @param string               $generation_profile Active generation profile.
	 * @param array<string, mixed> $summary          Converter summary.
	 * @return void
	 */
	public function record_result( $attachment_id, $generation_profile, $summary ) {
		$state = sanitize_key( $summary['state'] ?? '' );
		if ( ! in_array( $state, array( 'ready', 'partial', 'skipped', 'failed', 'stale' ), true ) ) {
			$state = ! empty( $summary['failed'] ) ? 'failed' : 'skipped';
		}

		$previous      = $this->get( $attachment_id, $generation_profile );
		$has_failures  = ! empty( $summary['failed'] );
		$failure_count = $has_failures ? max( 1, (int) $previous['failure_count'] + 1 ) : 0;
		$retry_after   = 0;

		if ( $has_failures ) {
			$delay       = min( DAY_IN_SECONDS, 15 * MINUTE_IN_SECONDS * ( 2 ** min( 6, $failure_count - 1 ) ) );
			$retry_after = time() + $delay;
		}

		$this->write(
			$attachment_id,
			$state,
			$generation_profile,
			array(
				'reason'        => sanitize_key( $summary['last_reason'] ?? '' ),
				'updated_at'    => time(),
				'retry_after'   => $retry_after,
				'failure_count' => $failure_count,
				'priority'      => '',
				'profile'       => $generation_profile,
			)
		);
	}

	/**
	 * Check whether a frontend request should move an attachment forward.
	 *
	 * @param int    $attachment_id    Attachment ID.
	 * @param string $generation_profile Active generation profile.
	 * @return bool
	 */
	public function needs_processing( $attachment_id, $generation_profile ) {
		$status = $this->get( $attachment_id, $generation_profile );

		if ( in_array( $status['state'], array( 'ready', 'partial', 'skipped', 'queued', 'processing' ), true ) ) {
			return false;
		}

		return (int) $status['retry_after'] <= time();
	}

	/**
	 * Return counts for eligible Media Library attachments.
	 *
	 * @param string $generation_profile Active generation profile.
	 * @return array<string, int>
	 */
	public function library_stats( $generation_profile ) {
		global $wpdb;

		$stats = array(
			'total'      => 0,
			'ready'      => 0,
			'partial'    => 0,
			'skipped'    => 0,
			'queued'     => 0,
			'processing' => 0,
			'failed'     => 0,
			'stale'      => 0,
			'pending'    => 0,
			'reviewed'   => 0,
		);

		$query = $wpdb->prepare(
			"SELECT pm.meta_value AS state_value, COUNT(DISTINCT p.ID) AS amount
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm
				ON p.ID = pm.post_id AND pm.meta_key = %s
			WHERE p.post_type = 'attachment'
			AND p.post_mime_type IN ('image/jpeg', 'image/png')
			GROUP BY pm.meta_value",
			self::STATE_META_KEY
		);
		$rows  = $wpdb->get_results( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$amount = max( 0, (int) $row->amount );
			$state  = $this->parse_state( is_string( $row->state_value ) ? $row->state_value : '', $generation_profile )['state'];
			$state  = isset( $stats[ $state ] ) ? $state : 'pending';

			$stats[ $state ] += $amount;
			$stats['total']  += $amount;
		}

		$stats['reviewed'] = $stats['ready'] + $stats['partial'] + $stats['skipped'] + $stats['failed'];

		return $stats;
	}

	/**
	 * Return the exact stored values that are current for selected states.
	 *
	 * @param array<int, string> $states             State names.
	 * @param string             $generation_profile Active generation profile.
	 * @return array<int, string>
	 */
	public function current_values( $states, $generation_profile ) {
		$values = array();
		foreach ( $states as $state ) {
			$values[] = $this->stored_value( $state, $generation_profile );
		}

		return $values;
	}

	/**
	 * Delete plugin status records for one attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function delete( $attachment_id ) {
		delete_post_meta( absint( $attachment_id ), self::STATE_META_KEY );
		delete_post_meta( absint( $attachment_id ), self::DETAIL_META_KEY );
	}

	/**
	 * Store status and details.
	 *
	 * @param int                  $attachment_id    Attachment ID.
	 * @param string               $state            State name.
	 * @param string               $generation_profile Active generation profile.
	 * @param array<string, mixed> $details          Human-facing details.
	 * @return void
	 */
	private function write( $attachment_id, $state, $generation_profile, $details ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id ) {
			return;
		}

		update_post_meta( $attachment_id, self::STATE_META_KEY, $this->stored_value( $state, $generation_profile ) );
		update_post_meta( $attachment_id, self::DETAIL_META_KEY, $details );
	}

	/**
	 * Build a compact state value suitable for SQL grouping.
	 *
	 * @param string $state              State name.
	 * @param string $generation_profile Active generation profile.
	 * @return string
	 */
	private function stored_value( $state, $generation_profile ) {
		$state = sanitize_key( $state );
		if ( in_array( $state, array( 'queued', 'processing', 'pending' ), true ) ) {
			return $state;
		}

		return $state . ':' . substr( sha1( (string) $generation_profile ), 0, 12 );
	}

	/**
	 * Interpret a stored state against the active quality profile.
	 *
	 * @param string $raw                Stored state.
	 * @param string $generation_profile Active generation profile.
	 * @return array<string, string>
	 */
	private function parse_state( $raw, $generation_profile ) {
		if ( '' === $raw ) {
			return array(
				'state'        => 'pending',
				'stored_state' => 'pending',
			);
		}

		$parts        = explode( ':', $raw, 2 );
		$stored_state = sanitize_key( $parts[0] );
		$state        = $stored_state;

		if (
			isset( $parts[1] ) &&
			substr( sha1( (string) $generation_profile ), 0, 12 ) !== $parts[1]
		) {
			$state = 'stale';
		}

		return array(
			'state'        => $state,
			'stored_state' => $stored_state,
		);
	}
}
