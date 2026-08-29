<?php
/**
 * Image editor capability detection.
 *
 * @package JustModernImages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verifies modern image support with a real encode operation.
 */
final class JMI_Capabilities {

	const OPTION_NAME    = 'jmi_capabilities';
	const HEALTH_OPTION  = 'jmi_format_health';
	const PROBE_VERSION  = 1;
	const FAILURE_LIMIT  = 3;
	const FAILURE_WINDOW = 600;
	const PAUSE_SECONDS  = 3600;

	/**
	 * MIME types managed by the plugin.
	 *
	 * @return array<string, string>
	 */
	public function formats() {
		return array(
			'image/avif' => 'avif',
			'image/webp' => 'webp',
		);
	}

	/**
	 * Return the last verified capability result.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_all() {
		$stored = get_option( self::OPTION_NAME, array() );

		if (
			! is_array( $stored ) ||
			empty( $stored['fingerprint'] ) ||
			! hash_equals( (string) $stored['fingerprint'], $this->fingerprint() ) ||
			empty( $stored['formats'] ) ||
			! is_array( $stored['formats'] )
		) {
			return $this->unknown_results();
		}

		return $this->apply_health( $stored['formats'] );
	}

	/**
	 * Determine whether a MIME type passed the last real probe.
	 *
	 * @param string $mime_type MIME type.
	 * @return bool
	 */
	public function supports( $mime_type ) {
		$results = $this->get_all();

		return isset( $results[ $mime_type ]['state'] ) && 'available' === $results[ $mime_type ]['state'];
	}

	/**
	 * Probe all output formats and persist the results.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function probe_all() {
		$results = array();
		delete_option( self::HEALTH_OPTION );

		foreach ( $this->formats() as $mime_type => $extension ) {
			$results[ $mime_type ] = $this->probe_format( $mime_type, $extension );
		}

		update_option(
			self::OPTION_NAME,
			array(
				'fingerprint' => $this->fingerprint(),
				'checked_at'  => time(),
				'formats'     => $results,
			),
			false
		);

		return $results;
	}

	/**
	 * Remove a cached result so the next worker can probe again.
	 *
	 * @return void
	 */
	public function invalidate() {
		delete_option( self::OPTION_NAME );
		delete_option( self::HEALTH_OPTION );
	}

	/**
	 * Record an encoder failure and temporarily pause an unstable format.
	 *
	 * @param string $mime_type Output MIME type.
	 * @param string $reason    Failure reason.
	 * @return void
	 */
	public function record_failure( $mime_type, $reason ) {
		$encoder_failures = array(
			'decode_failed',
			'empty_output',
			'encode_failed',
			'encode_warning',
			'invalid_output',
			'unexpected_editor_failure',
		);

		if ( ! isset( $this->formats()[ $mime_type ] ) || ! in_array( $reason, $encoder_failures, true ) ) {
			return;
		}

		$now    = time();
		$health = get_option( self::HEALTH_OPTION, array() );
		$health = is_array( $health ) ? $health : array();
		$format = isset( $health[ $mime_type ] ) && is_array( $health[ $mime_type ] ) ? $health[ $mime_type ] : array();

		if ( empty( $format['last_failure'] ) || (int) $format['last_failure'] < $now - self::FAILURE_WINDOW ) {
			$format['failures'] = 0;
		}

		$format['failures']     = (int) ( $format['failures'] ?? 0 ) + 1;
		$format['last_failure'] = $now;
		$format['last_reason']  = sanitize_key( $reason );

		if ( $format['failures'] >= self::FAILURE_LIMIT ) {
			$format['paused_until'] = $now + self::PAUSE_SECONDS;
		}

		$health[ $mime_type ] = $format;
		update_option( self::HEALTH_OPTION, $health, false );
	}

	/**
	 * Verify a single output format.
	 *
	 * @param string $mime_type MIME type.
	 * @param string $extension File extension.
	 * @return array<string, mixed>
	 */
	private function probe_format( $mime_type, $extension ) {
		if ( ! wp_image_editor_supports( array( 'mime_type' => $mime_type ) ) ) {
			return $this->result( 'unavailable', 'editor_unsupported' );
		}

		$source_path = wp_tempnam( 'jmi-probe.png' );
		if ( ! $source_path ) {
			return $this->result( 'unknown', 'temporary_file_unavailable' );
		}

		$target_path = $source_path . '.' . $extension;
		// A fixed 1x1 PNG fixture used only for a local image-editor probe.
		$probe_image = base64_decode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
			true
		);

		// The probe path comes from wp_tempnam() and requires no filesystem credentials.
		if ( false === $probe_image || false === file_put_contents( $source_path, $probe_image ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			wp_delete_file( $source_path );
			return $this->result( 'unknown', 'probe_source_unavailable' );
		}

		$saved_path = $target_path;

		try {
			$editor = wp_get_image_editor( $source_path );
			if ( is_wp_error( $editor ) ) {
				return $this->result( 'unavailable', 'editor_load_failed' );
			}

			$editor->set_quality( 75 );
			$warning = '';
			$saved   = JMI_Error_Trap::run(
				static function () use ( $editor, $target_path, $mime_type ) {
					return $editor->save( $target_path, $mime_type );
				},
				$warning
			);
			unset( $editor );

			if ( '' !== $warning ) {
				return $this->result( 'unavailable', 'encode_warning' );
			}

			if ( is_wp_error( $saved ) ) {
				return $this->result( 'unavailable', 'encode_failed' );
			}

			$saved_path = ! empty( $saved['path'] ) ? $saved['path'] : $target_path;
			$image_data = is_readable( $saved_path ) ? getimagesize( $saved_path ) : false;

			if (
				! is_array( $image_data ) ||
				empty( $image_data['mime'] ) ||
				$mime_type !== $image_data['mime'] ||
				wp_filesize( $saved_path ) < 1
			) {
				return $this->result( 'unavailable', 'invalid_output' );
			}

			return $this->result( 'available', 'probe_passed' );
		} catch ( Throwable $error ) {
			return $this->result( 'unavailable', 'unexpected_editor_failure' );
		} finally {
			wp_delete_file( $source_path );
			if ( $saved_path !== $target_path ) {
				wp_delete_file( $saved_path );
			}
			wp_delete_file( $target_path );
		}
	}

	/**
	 * Return unknown results when the environment has changed.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function unknown_results() {
		$results = array();

		foreach ( $this->formats() as $mime_type => $extension ) {
			$results[ $mime_type ] = $this->result( 'unknown', 'not_checked' );
		}

		return $results;
	}

	/**
	 * Overlay temporary circuit-breaker state on verified capabilities.
	 *
	 * @param array<string, array<string, mixed>> $formats Verified formats.
	 * @return array<string, array<string, mixed>>
	 */
	private function apply_health( $formats ) {
		$health = get_option( self::HEALTH_OPTION, array() );
		if ( ! is_array( $health ) ) {
			return $formats;
		}

		foreach ( $health as $mime_type => $format ) {
			if (
				isset( $formats[ $mime_type ] ) &&
				is_array( $format ) &&
				! empty( $format['paused_until'] ) &&
				(int) $format['paused_until'] > time()
			) {
				$formats[ $mime_type ]['state']        = 'temporarily_disabled';
				$formats[ $mime_type ]['reason']       = 'encoder_circuit_breaker';
				$formats[ $mime_type ]['paused_until'] = (int) $format['paused_until'];
			}
		}

		return $formats;
	}

	/**
	 * Build a capability result.
	 *
	 * @param string $state  Capability state.
	 * @param string $reason Short reason code.
	 * @return array<string, mixed>
	 */
	private function result( $state, $reason ) {
		return array(
			'state'      => $state,
			'reason'     => $reason,
			'checked_at' => time(),
		);
	}

	/**
	 * Identify changes that can alter image editor support.
	 *
	 * @return string
	 */
	private function fingerprint() {
		$parts = array(
			'probe'     => self::PROBE_VERSION,
			'php'       => PHP_VERSION,
			'wordpress' => get_bloginfo( 'version' ),
			'gd'        => function_exists( 'gd_info' ) ? gd_info() : false,
			'imagick'   => false,
		);

		if ( class_exists( 'Imagick' ) ) {
			try {
				$parts['imagick'] = Imagick::getVersion();
			} catch ( Throwable $error ) {
				$parts['imagick'] = 'unavailable';
			}
		}

		return hash( 'sha256', wp_json_encode( $parts ) );
	}
}
