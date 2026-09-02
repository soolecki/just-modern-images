<?php
/**
 * Human-readable processing diagnostics.
 *
 * @package JustModernImages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts stable internal reason codes into useful admin messages.
 */
final class JMI_Diagnostics {

	/**
	 * Return a short explanation for a reason code.
	 *
	 * @param string $reason Reason code.
	 * @return string
	 */
	public static function label( $reason ) {
		$labels = array(
			''                           => __( 'No processing result has been recorded yet.', 'just-modern-images' ),
			'not_checked'                => __( 'This server has not completed its format check yet.', 'just-modern-images' ),
			'probe_passed'               => __( 'A real test image was created successfully.', 'just-modern-images' ),
			'editor_unsupported'         => __( 'The WordPress image editor does not support this format.', 'just-modern-images' ),
			'temporary_file_unavailable' => __( 'WordPress could not create a temporary test file.', 'just-modern-images' ),
			'probe_source_unavailable'   => __( 'The temporary test image could not be written.', 'just-modern-images' ),
			'editor_load_failed'         => __( 'WordPress could not open the image with an available editor.', 'just-modern-images' ),
			'quality_rejected'           => __( 'The image editor rejected the selected quality level.', 'just-modern-images' ),
			'encode_warning'             => __( 'The image library reported a warning while encoding.', 'just-modern-images' ),
			'encode_failed'              => __( 'The image library could not encode this format.', 'just-modern-images' ),
			'invalid_output'             => __( 'The generated file failed image validation.', 'just-modern-images' ),
			'decode_failed'              => __( 'WordPress could not reopen the generated file.', 'just-modern-images' ),
			'empty_output'               => __( 'The image library created an empty file.', 'just-modern-images' ),
			'unexpected_editor_failure'  => __( 'The image library stopped unexpectedly.', 'just-modern-images' ),
			'encoder_circuit_breaker'    => __( 'This format is paused after repeated encoder failures.', 'just-modern-images' ),
			'memory_budget'              => __( 'The image is too large for the currently available PHP memory.', 'just-modern-images' ),
			'not_smaller'                => __( 'The modern file would not be smaller than the original.', 'just-modern-images' ),
			'publish_copy_failed'        => __( 'The validated file could not be published in the uploads directory.', 'just-modern-images' ),
			'stale_target_unremovable'   => __( 'An incomplete output file could not be removed.', 'just-modern-images' ),
			'manifest_write_failed'      => __( 'The generated files could not be recorded in the database.', 'just-modern-images' ),
			'no_local_sources'           => __( 'No local JPEG or PNG files were found for this media item.', 'just-modern-images' ),
			'not_an_image_attachment'    => __( 'This media item is not a supported image.', 'just-modern-images' ),
			'unexpected_worker_failure'  => __( 'The background worker stopped unexpectedly.', 'just-modern-images' ),
			'quality_changed'            => __( 'This image is waiting to be refreshed at the new quality.', 'just-modern-images' ),
			'generated'                  => __( 'The modern file was generated and validated.', 'just-modern-images' ),
			'recovered_existing'         => __( 'A complete file from an interrupted run was recovered.', 'just-modern-images' ),
		);

		$reason = sanitize_key( $reason );

		return isset( $labels[ $reason ] ) ? $labels[ $reason ] : __( 'Processing did not complete.', 'just-modern-images' );
	}
}
