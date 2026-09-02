<?php
/**
 * Modern image conversion.
 *
 * @package JustModernImages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates validated companion files for one attachment at a time.
 */
final class JMI_Converter {

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
	 * Attachment source inventory.
	 *
	 * @var JMI_Source_Inventory
	 */
	private $inventory;

	/**
	 * Plugin-owned manifest storage.
	 *
	 * @var JMI_Manifest
	 */
	private $manifest;

	/**
	 * Set up the converter.
	 *
	 * @param JMI_Quality_Profiles $profiles     Quality profiles.
	 * @param JMI_Capabilities     $capabilities Server capabilities.
	 * @param JMI_Source_Inventory $inventory    Attachment source inventory.
	 * @param JMI_Manifest         $manifest     Manifest storage.
	 */
	public function __construct( $profiles, $capabilities, $inventory, $manifest ) {
		$this->profiles     = $profiles;
		$this->capabilities = $capabilities;
		$this->inventory    = $inventory;
		$this->manifest     = $manifest;
	}

	/**
	 * Convert every eligible source belonging to an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, int|string>
	 */
	public function convert_attachment( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$summary       = array(
			'attachment_id' => $attachment_id,
			'generated'     => 0,
			'reused'        => 0,
			'retained'      => 0,
			'skipped'       => 0,
			'failed'        => 0,
			'last_reason'   => '',
			'state'         => 'pending',
		);

		if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
			$summary['skipped']     = 1;
			$summary['last_reason'] = 'not_an_image_attachment';
			return $summary;
		}

		$sources = $this->inventory->for_attachment( $attachment_id );
		if ( empty( $sources ) ) {
			$summary['skipped']     = 1;
			$summary['last_reason'] = 'no_local_sources';
			return $summary;
		}

		$capabilities = $this->capabilities->get_all();
		if ( $this->has_unknown_capability( $capabilities ) ) {
			$capabilities = $this->capabilities->probe_all();
		}

		$previous                   = $this->manifest->get( $attachment_id );
		$generation_profile         = $this->profiles->generation_profile();
		$next                       = $this->manifest->empty_manifest();
		$next['generation_profile'] = $generation_profile;
		$expected_variants          = 0;
		$current_variants           = 0;
		$usable_variants            = 0;

		foreach ( $sources as $source_key => $source ) {
			$next['sources'][ $source_key ] = $this->source_manifest_data( $source );

			foreach ( $this->capabilities->formats() as $mime_type => $extension ) {
				$current_capabilities = $this->capabilities->get_all();
				$previous_variant     = $this->previous_variant( $previous, $source_key, $mime_type );
				$capability           = $current_capabilities[ $mime_type ] ?? array(
					'state'  => 'unknown',
					'reason' => 'not_checked',
				);
				$is_required          = 'available' === ( $capability['state'] ?? 'unknown' );
				$was_reused           = false;

				if ( $is_required ) {
					++$expected_variants;
				}

				if ( ! $is_required ) {
					$variant = $this->outcome(
						'skipped',
						(string) ( $capability['reason'] ?? 'editor_unsupported' ),
						$mime_type,
						$generation_profile
					);
				} elseif ( $this->can_reuse( $previous, $previous_variant, $source, $generation_profile ) ) {
					$variant    = $previous_variant;
					$was_reused = true;
					++$summary['reused'];
				} else {
					$variant = $this->convert_variant( $source, $mime_type, $extension, $generation_profile );
				}

				if ( 'ready' === $variant['status'] && ! $was_reused ) {
					++$summary['generated'];
				} elseif ( 'failed' === $variant['status'] ) {
					++$summary['failed'];
					$summary['last_reason'] = $variant['reason'];
					$this->capabilities->record_failure( $mime_type, $variant['reason'] );
				} elseif ( 'ready' !== $variant['status'] ) {
					++$summary['skipped'];
					$summary['last_reason'] = $variant['reason'];
				}

				if ( 'ready' !== $variant['status'] ) {
					$issue   = $variant;
					$variant = $this->keep_previous_on_refresh_issue(
						$previous,
						$previous_variant,
						$source,
						$issue
					);

					if ( 'ready' === $variant['status'] ) {
						++$summary['retained'];
					}
				}

				if ( 'ready' === $variant['status'] ) {
					++$usable_variants;
					if ( ( $variant['generation_profile'] ?? '' ) === $generation_profile ) {
						if ( $is_required ) {
							++$current_variants;
						}
					}
				}

				$next['sources'][ $source_key ]['variants'][ $mime_type ] = $variant;
			}
		}

		if ( $expected_variants && $current_variants === $expected_variants ) {
			$summary['state'] = 'ready';
		} elseif ( $summary['failed'] ) {
			$summary['state'] = $usable_variants ? 'stale' : 'failed';
		} elseif ( $usable_variants ) {
			$summary['state'] = 'partial';
		} else {
			$summary['state'] = 'skipped';
		}

		$next = $this->manifest->prepare_replacement( $previous, $next, $attachment_id );
		if ( ! $this->manifest->save( $attachment_id, $next ) ) {
			++$summary['failed'];
			$summary['last_reason'] = 'manifest_write_failed';
			$summary['state']       = $usable_variants ? 'stale' : 'failed';
			return $summary;
		}

		$this->manifest->cleanup_retired_variants( $attachment_id );

		return $summary;
	}

	/**
	 * Create one modern companion.
	 *
	 * @param array<string, mixed> $source             Source data.
	 * @param string               $mime_type          Output MIME type.
	 * @param string               $extension          Output extension.
	 * @param string               $generation_profile Generation profile.
	 * @return array<string, mixed>
	 */
	private function convert_variant( $source, $mime_type, $extension, $generation_profile ) {
		if ( ! $this->has_memory_for( $source ) ) {
			return $this->outcome( 'skipped', 'memory_budget', $mime_type, $generation_profile );
		}

		$token            = $this->variant_token( $source, $mime_type, $generation_profile );
		$target_path      = $source['path'] . '.jmi-' . $token . '.' . $extension;
		$relative_path    = $source['relative_path'] . '.jmi-' . $token . '.' . $extension;
		$target_directory = dirname( $target_path );
		$temp_filename    = wp_unique_filename(
			$target_directory,
			'.' . wp_basename( $source['path'] ) . '.jmi-' . wp_generate_password( 8, false ) . '.' . $extension
		);
		$temp_path        = $target_directory . DIRECTORY_SEPARATOR . $temp_filename;
		$saved_path       = $temp_path;

		if ( file_exists( $target_path ) ) {
			$existing = $this->validate_output( $target_path, $mime_type, $source );
			if ( 'ready' === $existing['status'] ) {
				return $this->ready_variant( $mime_type, $relative_path, $generation_profile, $existing, 'recovered_existing' );
			}

			wp_delete_file( $target_path );
			if ( file_exists( $target_path ) ) {
				return $this->outcome( 'failed', 'stale_target_unremovable', $mime_type, $generation_profile );
			}
		}

		try {
			$editor = wp_get_image_editor( $source['path'] );
			if ( is_wp_error( $editor ) ) {
				return $this->outcome( 'failed', 'editor_load_failed', $mime_type, $generation_profile );
			}

			$quality_set = $editor->set_quality( $this->profiles->quality_for( $mime_type ) );
			if ( is_wp_error( $quality_set ) ) {
				unset( $editor );
				return $this->outcome( 'failed', 'quality_rejected', $mime_type, $generation_profile );
			}

			$warning = '';
			$saved   = JMI_Error_Trap::run(
				static function () use ( $editor, $temp_path, $mime_type ) {
					return $editor->save( $temp_path, $mime_type );
				},
				$warning
			);
			unset( $editor );

			if ( '' !== $warning ) {
				return $this->outcome( 'failed', 'encode_warning', $mime_type, $generation_profile );
			}

			if ( is_wp_error( $saved ) ) {
				return $this->outcome( 'failed', 'encode_failed', $mime_type, $generation_profile );
			}

			$saved_path = ! empty( $saved['path'] ) ? $saved['path'] : $temp_path;
			$validation = $this->validate_output( $saved_path, $mime_type, $source );

			if ( 'ready' !== $validation['status'] ) {
				return $this->outcome( $validation['status'], $validation['reason'], $mime_type, $generation_profile );
			}

			if ( ! $this->publish_file( $saved_path, $target_path ) ) {
				return $this->outcome( 'failed', 'publish_copy_failed', $mime_type, $generation_profile );
			}

			$variant = $this->ready_variant( $mime_type, $relative_path, $generation_profile, $validation, 'generated' );

			do_action( 'jmi_variant_generated', $variant, $source );

			return $variant;
		} catch ( Throwable $error ) {
			return $this->outcome( 'failed', 'unexpected_editor_failure', $mime_type, $generation_profile );
		} finally {
			if ( file_exists( $saved_path ) && wp_normalize_path( $saved_path ) !== wp_normalize_path( $target_path ) ) {
				wp_delete_file( $saved_path );
			}
			if ( file_exists( $temp_path ) && wp_normalize_path( $temp_path ) !== wp_normalize_path( $target_path ) ) {
				wp_delete_file( $temp_path );
			}
		}
	}

	/**
	 * Validate a temporary modern file.
	 *
	 * @param string               $path      Temporary path.
	 * @param string               $mime_type Expected MIME type.
	 * @param array<string, mixed> $source    Source data.
	 * @return array<string, int|string>
	 */
	private function validate_output( $path, $mime_type, $source ) {
		if ( ! is_readable( $path ) || wp_filesize( $path ) < 1 ) {
			return array(
				'status' => 'failed',
				'reason' => 'empty_output',
			);
		}

		$image = getimagesize( $path );
		if (
			! is_array( $image ) ||
			empty( $image['mime'] ) ||
			$mime_type !== $image['mime'] ||
			(int) $source['width'] !== (int) $image[0] ||
			(int) $source['height'] !== (int) $image[1]
		) {
			return array(
				'status' => 'failed',
				'reason' => 'invalid_output',
			);
		}

		$decoder = wp_get_image_editor( $path );
		if ( is_wp_error( $decoder ) ) {
			return array(
				'status' => 'failed',
				'reason' => 'decode_failed',
			);
		}
		unset( $decoder );

		$bytes = wp_filesize( $path );
		if ( $bytes >= (int) $source['bytes'] ) {
			return array(
				'status' => 'skipped',
				'reason' => 'not_smaller',
			);
		}

		return array(
			'status' => 'ready',
			'reason' => 'validated',
			'width'  => (int) $image[0],
			'height' => (int) $image[1],
			'bytes'  => (int) $bytes,
		);
	}

	/**
	 * Publish a validated file under a new immutable name.
	 *
	 * @param string $temporary_path Temporary file path.
	 * @param string $target_path    Final file path.
	 * @return bool
	 */
	private function publish_file( $temporary_path, $target_path ) {
		if ( file_exists( $target_path ) ) {
			return false;
		}

		$warning = '';
		$copied  = JMI_Error_Trap::run(
			static function () use ( $temporary_path, $target_path ) {
				return copy( $temporary_path, $target_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			},
			$warning
		);

		if ( '' !== $warning || ! $copied ) {
			wp_delete_file( $target_path );
			return false;
		}

		$verification_warning = '';
		$verification         = JMI_Error_Trap::run(
			static function () use ( $temporary_path, $target_path ) {
				return array(
					'source_size' => wp_filesize( $temporary_path ),
					'target_size' => wp_filesize( $target_path ),
					'source_hash' => hash_file( 'sha256', $temporary_path ),
					'target_hash' => hash_file( 'sha256', $target_path ),
				);
			},
			$verification_warning
		);

		if (
			'' !== $verification_warning ||
			! is_array( $verification ) ||
			$verification['source_size'] < 1 ||
			$verification['source_size'] !== $verification['target_size'] ||
			! is_string( $verification['source_hash'] ) ||
			! is_string( $verification['target_hash'] ) ||
			! hash_equals( $verification['source_hash'], $verification['target_hash'] )
		) {
			wp_delete_file( $target_path );
			return false;
		}

		wp_delete_file( $temporary_path );

		return true;
	}

	/**
	 * Determine whether the source fits a conservative decoded-memory budget.
	 *
	 * @param array<string, mixed> $source Source data.
	 * @return bool
	 */
	private function has_memory_for( $source ) {
		$estimated = ( (int) $source['width'] * (int) $source['height'] * 8 ) + MB_IN_BYTES;
		$estimated = (int) apply_filters( 'jmi_estimated_image_memory', $estimated, $source );
		$limit     = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );

		if ( $limit <= 0 ) {
			return true;
		}

		$available = max( 0, $limit - memory_get_usage( true ) - ( 16 * MB_IN_BYTES ) );

		return $available >= $estimated;
	}

	/**
	 * Determine whether an existing result is current and still valid.
	 *
	 * @param array<string, mixed>      $previous           Previous manifest.
	 * @param array<string, mixed>|null $variant            Previous variant.
	 * @param array<string, mixed>      $source             Current source.
	 * @param string                    $generation_profile Generation profile.
	 * @return bool
	 */
	private function can_reuse( $previous, $variant, $source, $generation_profile ) {
		if ( ! is_array( $variant ) || 'ready' !== ( $variant['status'] ?? '' ) ) {
			return false;
		}

		if ( ( $variant['generation_profile'] ?? '' ) !== $generation_profile ) {
			return false;
		}

		$source_key = $source['size_name'];
		if (
			empty( $previous['sources'][ $source_key ]['signature'] ) ||
			$source['signature'] !== $previous['sources'][ $source_key ]['signature']
		) {
			return false;
		}

		if ( empty( $variant['mime_type'] ) ) {
			return false;
		}

		$path = $this->absolute_variant_path( $variant['relative_path'] ?? '' );

		return $path && 'ready' === $this->validate_output( $path, $variant['mime_type'], $source )['status'];
	}

	/**
	 * Keep a valid older-quality file until a replacement is ready.
	 *
	 * @param array<string, mixed>      $previous        Previous manifest.
	 * @param array<string, mixed>|null $previous_variant Previous variant.
	 * @param array<string, mixed>      $source          Current source.
	 * @param array<string, mixed>      $issue           Failed or skipped outcome.
	 * @return array<string, mixed>
	 */
	private function keep_previous_on_refresh_issue( $previous, $previous_variant, $source, $issue ) {
		$source_key = $source['size_name'];
		if (
			! is_array( $previous_variant ) ||
			'ready' !== ( $previous_variant['status'] ?? '' ) ||
			empty( $previous['sources'][ $source_key ]['signature'] ) ||
			$source['signature'] !== $previous['sources'][ $source_key ]['signature']
		) {
			return $issue;
		}

		if ( empty( $previous_variant['mime_type'] ) ) {
			return $issue;
		}

		$path = $this->absolute_variant_path( $previous_variant['relative_path'] ?? '' );
		if ( ! $path || 'ready' !== $this->validate_output( $path, $previous_variant['mime_type'], $source )['status'] ) {
			return $issue;
		}

		$previous_variant['warning'] = $issue['reason'];

		return $previous_variant;
	}

	/**
	 * Return source fields safe to persist.
	 *
	 * @param array<string, mixed> $source Source data.
	 * @return array<string, mixed>
	 */
	private function source_manifest_data( $source ) {
		return array(
			'size_name'     => $source['size_name'],
			'relative_path' => $source['relative_path'],
			'mime_type'     => $source['mime_type'],
			'width'         => (int) $source['width'],
			'height'        => (int) $source['height'],
			'bytes'         => (int) $source['bytes'],
			'modified'      => (int) $source['modified'],
			'signature'     => $source['signature'],
			'variants'      => array(),
		);
	}

	/**
	 * Return a previous variant.
	 *
	 * @param array<string, mixed> $manifest   Manifest.
	 * @param string               $source_key Source key.
	 * @param string               $mime_type  MIME type.
	 * @return array<string, mixed>|null
	 */
	private function previous_variant( $manifest, $source_key, $mime_type ) {
		if ( ! empty( $manifest['sources'][ $source_key ]['variants'][ $mime_type ] ) ) {
			return $manifest['sources'][ $source_key ]['variants'][ $mime_type ];
		}

		return null;
	}

	/**
	 * Build a ready variant record.
	 *
	 * @param string                    $mime_type          Output MIME type.
	 * @param string                    $relative_path      Relative upload path.
	 * @param string                    $generation_profile Generation profile.
	 * @param array<string, int|string> $validation         Validated image details.
	 * @param string                    $reason             Result reason.
	 * @return array<string, mixed>
	 */
	private function ready_variant( $mime_type, $relative_path, $generation_profile, $validation, $reason ) {
		return array(
			'status'             => 'ready',
			'reason'             => $reason,
			'mime_type'          => $mime_type,
			'relative_path'      => $relative_path,
			'width'              => (int) $validation['width'],
			'height'             => (int) $validation['height'],
			'bytes'              => (int) $validation['bytes'],
			'generated_at'       => time(),
			'generation_profile' => $generation_profile,
		);
	}

	/**
	 * Build a stable name token for immutable output.
	 *
	 * @param array<string, mixed> $source             Source data.
	 * @param string               $mime_type          Output MIME type.
	 * @param string               $generation_profile Generation profile.
	 * @return string
	 */
	private function variant_token( $source, $mime_type, $generation_profile ) {
		$identity = implode(
			'|',
			array(
				(string) ( $source['signature'] ?? '' ),
				(string) $mime_type,
				(string) $generation_profile,
				JMI_VERSION,
			)
		);

		return substr( hash( 'sha256', $identity ), 0, 16 );
	}

	/**
	 * Build a non-file outcome.
	 *
	 * @param string $status             Outcome status.
	 * @param string $reason             Reason code.
	 * @param string $mime_type          Output MIME type.
	 * @param string $generation_profile Generation profile.
	 * @return array<string, mixed>
	 */
	private function outcome( $status, $reason, $mime_type, $generation_profile ) {
		return array(
			'status'             => $status,
			'reason'             => $reason,
			'mime_type'          => $mime_type,
			'generation_profile' => $generation_profile,
			'checked_at'         => time(),
		);
	}

	/**
	 * Determine whether capability information needs a real probe.
	 *
	 * @param array<string, array<string, mixed>> $capabilities Capability data.
	 * @return bool
	 */
	private function has_unknown_capability( $capabilities ) {
		foreach ( $this->capabilities->formats() as $mime_type => $extension ) {
			if ( 'unknown' === ( $capabilities[ $mime_type ]['state'] ?? 'unknown' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve a plugin variant inside the uploads directory.
	 *
	 * @param string $relative_path Relative upload path.
	 * @return string|false
	 */
	private function absolute_variant_path( $relative_path ) {
		if (
			! is_string( $relative_path ) ||
			'' === $relative_path ||
			preg_match( '#(^|/)\.\.(/|$)#', wp_normalize_path( $relative_path ) )
		) {
			return false;
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return false;
		}

		$base = trailingslashit( wp_normalize_path( $uploads['basedir'] ) );
		$path = wp_normalize_path( $uploads['basedir'] . '/' . ltrim( $relative_path, '/' ) );

		return 0 === strpos( $path, $base ) ? $path : false;
	}
}
