<?php
/**
 * Bounded processing history for administrator diagnostics.
 *
 * @package JustModernImages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores recent worker snapshots without paths, URLs, or visitor data.
 */
final class JMI_Activity_Log {

	const OPTION_NAME = 'jmi_activity_log';
	const SCHEMA      = 1;
	const MAX_ENTRIES = 50;
	const MAX_ITEMS   = 50;

	/**
	 * Add one entry to the beginning of the bounded history.
	 *
	 * @param array<string, mixed> $entry Activity data.
	 * @return void
	 */
	public function record( $entry ) {
		$entry = $this->normalize_entry( is_array( $entry ) ? $entry : array() );
		if ( empty( $entry ) ) {
			return;
		}

		$entries = $this->entries();
		array_unshift( $entries, $entry );
		$entries = array_slice( $entries, 0, self::MAX_ENTRIES );

		update_option( self::OPTION_NAME, $entries, false );
	}

	/**
	 * Return recent entries in newest-first order.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function entries() {
		$stored  = get_option( self::OPTION_NAME, array() );
		$entries = array();

		foreach ( is_array( $stored ) ? $stored : array() as $entry ) {
			$entry = $this->normalize_entry( is_array( $entry ) ? $entry : array() );
			if ( ! empty( $entry ) ) {
				$entries[] = $entry;
			}
		}

		return array_slice( $entries, 0, self::MAX_ENTRIES );
	}

	/**
	 * Build a safe report suitable for downloading from the admin screen.
	 *
	 * @param array<string, mixed> $context Current non-sensitive state.
	 * @return array<string, mixed>
	 */
	public function report( $context = array() ) {
		return array(
			'schema'         => self::SCHEMA,
			'generated_at'   => time(),
			'plugin_version' => defined( 'JMI_VERSION' ) ? (string) JMI_VERSION : '',
			'context'        => $this->normalize_context( is_array( $context ) ? $context : array() ),
			'entries'        => $this->entries(),
		);
	}

	/**
	 * Normalize a history entry and discard unknown fields.
	 *
	 * @param array<string, mixed> $entry Raw entry.
	 * @return array<string, mixed>
	 */
	private function normalize_entry( $entry ) {
		$started_at = max( 0, (int) ( $entry['started_at'] ?? 0 ) );
		if ( ! $started_at ) {
			return array();
		}

		$normalized = array(
			'id'             => $this->token( $entry['id'] ?? '' ),
			'type'           => $this->allowed_key( $entry['type'] ?? '', array( 'scan', 'attachment', 'scan_requested' ), 'scan' ),
			'source'         => sanitize_key( $entry['source'] ?? '' ),
			'started_at'     => $started_at,
			'finished_at'    => max( $started_at, (int) ( $entry['finished_at'] ?? $started_at ) ),
			'duration_ms'    => max( 0, (int) ( $entry['duration_ms'] ?? 0 ) ),
			'server'         => $this->token( $entry['server'] ?? '' ),
			'worker_version' => $this->version( $entry['worker_version'] ?? '' ),
			'stop_reason'    => sanitize_key( $entry['stop_reason'] ?? '' ),
			'complete'       => ! empty( $entry['complete'] ),
			'attempts'       => max( 0, (int) ( $entry['attempts'] ?? 0 ) ),
			'processed'      => max( 0, (int) ( $entry['processed'] ?? 0 ) ),
			'before'         => $this->normalize_snapshot( $entry['before'] ?? array() ),
			'after'          => $this->normalize_snapshot( $entry['after'] ?? array() ),
			'formats'        => $this->normalize_formats( $entry['formats'] ?? array() ),
			'items'          => array(),
		);

		foreach ( is_array( $entry['items'] ?? null ) ? $entry['items'] : array() as $item ) {
			if ( count( $normalized['items'] ) >= self::MAX_ITEMS || ! is_array( $item ) ) {
				break;
			}

			$attachment_id = absint( $item['attachment_id'] ?? 0 );
			if ( ! $attachment_id ) {
				continue;
			}

			$normalized['items'][] = array(
				'attachment_id' => $attachment_id,
				'before_state'  => sanitize_key( $item['before_state'] ?? '' ),
				'after_state'   => sanitize_key( $item['after_state'] ?? '' ),
				'before_reason' => sanitize_key( $item['before_reason'] ?? '' ),
				'after_reason'  => sanitize_key( $item['after_reason'] ?? '' ),
				'queued_from'   => sanitize_key( $item['queued_from'] ?? '' ),
				'queue_source'  => sanitize_key( $item['queue_source'] ?? '' ),
				'generated'     => max( 0, (int) ( $item['generated'] ?? 0 ) ),
				'reused'        => max( 0, (int) ( $item['reused'] ?? 0 ) ),
				'retained'      => max( 0, (int) ( $item['retained'] ?? 0 ) ),
				'skipped'       => max( 0, (int) ( $item['skipped'] ?? 0 ) ),
				'failed'        => max( 0, (int) ( $item['failed'] ?? 0 ) ),
				'duration_ms'   => max( 0, (int) ( $item['duration_ms'] ?? 0 ) ),
			);
		}

		if ( '' === $normalized['id'] ) {
			$normalized['id'] = substr( sha1( wp_json_encode( $normalized ) ), 0, 12 );
		}

		return $normalized;
	}

	/**
	 * Normalize the before or after snapshot.
	 *
	 * @param mixed $snapshot Raw snapshot.
	 * @return array<string, mixed>
	 */
	private function normalize_snapshot( $snapshot ) {
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
				'status'    => sanitize_key( $queue['status'] ?? '' ),
				'reason'    => sanitize_key( $queue['reason'] ?? '' ),
				'cursor'    => max( 0, (int) ( $queue['cursor'] ?? 0 ) ),
				'total'     => max( 0, (int) ( $queue['total'] ?? 0 ) ),
				'processed' => max( 0, (int) ( $queue['processed'] ?? 0 ) ),
				'generated' => max( 0, (int) ( $queue['generated'] ?? 0 ) ),
				'failed'    => max( 0, (int) ( $queue['failed'] ?? 0 ) ),
			),
		);
	}

	/**
	 * Normalize current format support without retaining environment details.
	 *
	 * @param mixed $formats Raw format map.
	 * @return array<string, array<string, string>>
	 */
	private function normalize_formats( $formats ) {
		$formats    = is_array( $formats ) ? $formats : array();
		$normalized = array();

		foreach ( array( 'image/avif', 'image/webp' ) as $mime_type ) {
			$format                   = is_array( $formats[ $mime_type ] ?? null ) ? $formats[ $mime_type ] : array();
			$normalized[ $mime_type ] = array(
				'state'  => sanitize_key( $format['state'] ?? 'unknown' ),
				'reason' => sanitize_key( $format['reason'] ?? 'not_checked' ),
			);
		}

		return $normalized;
	}

	/**
	 * Normalize the current report context.
	 *
	 * @param array<string, mixed> $context Raw context.
	 * @return array<string, mixed>
	 */
	private function normalize_context( $context ) {
		return array(
			'server'        => $this->token( $context['server'] ?? '' ),
			'profile_count' => max( 0, (int) ( $context['profile_count'] ?? 0 ) ),
			'formats'       => $this->normalize_formats( $context['formats'] ?? array() ),
			'current'       => $this->normalize_snapshot( $context['current'] ?? array() ),
		);
	}

	/**
	 * Keep a short identifier made only of safe token characters.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function token( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';

		return substr( preg_replace( '/[^a-zA-Z0-9_-]/', '', $value ), 0, 40 );
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
	 * Return one allowed key.
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
