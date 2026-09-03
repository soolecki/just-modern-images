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
	const STORAGE_SCHEMA = 2;
	const MAX_PROFILES   = 32;
	const PROBE_VERSION  = 2;
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
	 * Return the last verified capability result for this server environment.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_all() {
		$fingerprint = $this->fingerprint();
		$profile     = $this->get_profile( $fingerprint );

		if ( empty( $profile['formats'] ) || ! is_array( $profile['formats'] ) ) {
			return $this->unknown_results();
		}

		return $this->apply_health( $profile['formats'], $fingerprint );
	}

	/**
	 * Return safe operational information for the current server environment.
	 *
	 * @return array<string, mixed>
	 */
	public function diagnostic_summary() {
		$fingerprint = $this->fingerprint();
		$storage     = $this->get_storage();
		$profile     = isset( $storage['profiles'][ $fingerprint ] ) && is_array( $storage['profiles'][ $fingerprint ] )
			? $storage['profiles'][ $fingerprint ]
			: array();

		return array(
			'environment_id' => substr( $fingerprint, 0, 12 ),
			'checked_at'     => isset( $profile['checked_at'] ) ? (int) $profile['checked_at'] : 0,
			'profile_count'  => count( $storage['profiles'] ),
			'formats'        => $this->get_all(),
		);
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
	 * Return the first recovery time when every managed encoder is paused.
	 *
	 * A zero result means that at least one format can still be processed.
	 *
	 * @return int Unix timestamp, or zero.
	 */
	public function all_formats_paused_until() {
		$formats      = $this->get_all();
		$paused_until = array();

		foreach ( array_keys( $this->formats() ) as $mime_type ) {
			$format = $formats[ $mime_type ] ?? array();
			if ( 'temporarily_disabled' !== ( $format['state'] ?? '' ) ) {
				return 0;
			}

			$paused_until[] = max( time() + 1, (int) ( $format['paused_until'] ?? 0 ) );
		}

		return $paused_until ? min( $paused_until ) : 0;
	}

	/**
	 * Probe all output formats and persist the results for this environment.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function probe_all() {
		$results     = array();
		$fingerprint = $this->fingerprint();

		$this->clear_health( $fingerprint );

		foreach ( $this->formats() as $mime_type => $extension ) {
			$results[ $mime_type ] = $this->probe_format( $mime_type, $extension );
		}

		$storage                             = $this->get_storage();
		$storage['profiles'][ $fingerprint ] = array(
			'checked_at' => time(),
			'formats'    => $results,
		);
		$storage['profiles']                 = $this->prune_profiles( $storage['profiles'] );

		update_option( self::OPTION_NAME, $storage, false );

		return $results;
	}

	/**
	 * Remove cached results so each active server can probe again.
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
			'encode_failed',
			'encode_warning',
			'invalid_output',
			'unexpected_editor_failure',
		);

		if ( ! isset( $this->formats()[ $mime_type ] ) || ! in_array( $reason, $encoder_failures, true ) ) {
			return;
		}

		$now         = time();
		$fingerprint = $this->fingerprint();
		$storage     = $this->get_health_storage( $fingerprint );
		$health      = isset( $storage['profiles'][ $fingerprint ] ) && is_array( $storage['profiles'][ $fingerprint ] )
			? $storage['profiles'][ $fingerprint ]
			: array();
		$format      = isset( $health[ $mime_type ] ) && is_array( $health[ $mime_type ] ) ? $health[ $mime_type ] : array();

		if ( empty( $format['last_failure'] ) || (int) $format['last_failure'] < $now - self::FAILURE_WINDOW ) {
			$format['failures'] = 0;
		}

		$format['failures']     = (int) ( $format['failures'] ?? 0 ) + 1;
		$format['last_failure'] = $now;
		$format['last_reason']  = sanitize_key( $reason );

		if ( $format['failures'] >= self::FAILURE_LIMIT ) {
			$format['paused_until'] = $now + self::PAUSE_SECONDS;
		}

		$health[ $mime_type ]                = $format;
		$health['_updated_at']               = $now;
		$storage['profiles'][ $fingerprint ] = $health;
		$storage['profiles']                 = $this->prune_profiles( $storage['profiles'] );
		update_option( self::HEALTH_OPTION, $storage, false );
	}

	/**
	 * Clear an expired failure streak after a real encode succeeds.
	 *
	 * @param string $mime_type Output MIME type.
	 * @return void
	 */
	public function record_success( $mime_type ) {
		if ( ! isset( $this->formats()[ $mime_type ] ) ) {
			return;
		}

		$fingerprint = $this->fingerprint();
		$storage     = $this->get_health_storage( $fingerprint );
		if ( empty( $storage['profiles'][ $fingerprint ][ $mime_type ] ) ) {
			return;
		}

		unset( $storage['profiles'][ $fingerprint ][ $mime_type ] );
		$storage['profiles'][ $fingerprint ]['_updated_at'] = time();
		update_option( self::HEALTH_OPTION, $storage, false );
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

		$source_path = $this->temporary_probe_path();
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
	 * Create a probe path even when the WordPress filesystem API was not loaded.
	 *
	 * Cron, CLI, and some activation paths do not include wp-admin/includes/file.php.
	 *
	 * @return string|false
	 */
	private function temporary_probe_path() {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			$file_api = ABSPATH . 'wp-admin/includes/file.php';
			if ( file_exists( $file_api ) ) {
				require_once $file_api;
			}
		}

		return function_exists( 'wp_tempnam' ) ? wp_tempnam( 'jmi-probe.png' ) : false;
	}

	/**
	 * Return unknown results when this server environment has not been checked.
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
	 * @param array<string, array<string, mixed>> $formats     Verified formats.
	 * @param string                              $fingerprint Environment fingerprint.
	 * @return array<string, array<string, mixed>>
	 */
	private function apply_health( $formats, $fingerprint ) {
		$storage = $this->get_health_storage( $fingerprint );
		$health  = isset( $storage['profiles'][ $fingerprint ] ) && is_array( $storage['profiles'][ $fingerprint ] )
			? $storage['profiles'][ $fingerprint ]
			: array();

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
	 * Read capability storage and migrate the previous single-profile shape.
	 *
	 * @return array<string, mixed>
	 */
	private function get_storage() {
		$stored  = get_option( self::OPTION_NAME, array() );
		$storage = array(
			'schema'   => self::STORAGE_SCHEMA,
			'profiles' => array(),
		);

		if ( ! is_array( $stored ) ) {
			return $storage;
		}

		if ( isset( $stored['profiles'] ) && is_array( $stored['profiles'] ) ) {
			$storage['profiles'] = $stored['profiles'];
			return $storage;
		}

		if ( ! empty( $stored['fingerprint'] ) && ! empty( $stored['formats'] ) && is_array( $stored['formats'] ) ) {
			$storage['profiles'][ (string) $stored['fingerprint'] ] = array(
				'checked_at' => isset( $stored['checked_at'] ) ? (int) $stored['checked_at'] : 0,
				'formats'    => $stored['formats'],
			);
		}

		return $storage;
	}

	/**
	 * Return a capability profile for one environment.
	 *
	 * @param string $fingerprint Environment fingerprint.
	 * @return array<string, mixed>
	 */
	private function get_profile( $fingerprint ) {
		$storage = $this->get_storage();

		return isset( $storage['profiles'][ $fingerprint ] ) && is_array( $storage['profiles'][ $fingerprint ] )
			? $storage['profiles'][ $fingerprint ]
			: array();
	}

	/**
	 * Read health storage and migrate the previous global shape.
	 *
	 * @param string $fingerprint Current environment fingerprint.
	 * @return array<string, mixed>
	 */
	private function get_health_storage( $fingerprint ) {
		$stored  = get_option( self::HEALTH_OPTION, array() );
		$storage = array(
			'schema'   => self::STORAGE_SCHEMA,
			'profiles' => array(),
		);

		if ( ! is_array( $stored ) ) {
			return $storage;
		}

		if ( isset( $stored['profiles'] ) && is_array( $stored['profiles'] ) ) {
			$storage['profiles'] = $stored['profiles'];
			return $storage;
		}

		foreach ( $this->formats() as $mime_type => $extension ) {
			if ( isset( $stored[ $mime_type ] ) && is_array( $stored[ $mime_type ] ) ) {
				$storage['profiles'][ $fingerprint ][ $mime_type ] = $stored[ $mime_type ];
			}
		}

		return $storage;
	}

	/**
	 * Remove circuit-breaker data for one server environment.
	 *
	 * @param string $fingerprint Environment fingerprint.
	 * @return void
	 */
	private function clear_health( $fingerprint ) {
		$storage = $this->get_health_storage( $fingerprint );
		unset( $storage['profiles'][ $fingerprint ] );

		if ( empty( $storage['profiles'] ) ) {
			delete_option( self::HEALTH_OPTION );
			return;
		}

		update_option( self::HEALTH_OPTION, $storage, false );
	}

	/**
	 * Keep option size bounded on long-lived and autoscaled installations.
	 *
	 * @param array<string, array<string, mixed>> $profiles Stored profiles.
	 * @return array<string, array<string, mixed>>
	 */
	private function prune_profiles( $profiles ) {
		if ( count( $profiles ) <= self::MAX_PROFILES ) {
			return $profiles;
		}

		uasort(
			$profiles,
			static function ( $left, $right ) {
				$left_time  = isset( $left['checked_at'] ) ? (int) $left['checked_at'] : (int) ( $left['_updated_at'] ?? 0 );
				$right_time = isset( $right['checked_at'] ) ? (int) $right['checked_at'] : (int) ( $right['_updated_at'] ?? 0 );

				return $right_time <=> $left_time;
			}
		);

		return array_slice( $profiles, 0, self::MAX_PROFILES, true );
	}

	/**
	 * Identify changes that can alter image editor support.
	 *
	 * @return string
	 */
	private function fingerprint() {
		$parts = array(
			'probe'     => self::PROBE_VERSION,
			'server'    => function_exists( 'gethostname' ) ? gethostname() : php_uname( 'n' ),
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
