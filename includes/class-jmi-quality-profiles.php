<?php
/**
 * Image quality profiles.
 *
 * @package JustModernImages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides the small set of quality choices exposed by the plugin.
 */
final class JMI_Quality_Profiles {

	const OPTION_NAME     = 'jmi_quality_profile';
	const DEFAULT_PROFILE = 'standard';

	/**
	 * Return all available profiles.
	 *
	 * @return array<string, array<string, int|string>>
	 */
	public function all() {
		return array(
			'economy'  => array(
				'label'       => __( 'Economy', 'just-modern-images' ),
				'description' => __( 'Prioritizes the smallest practical files.', 'just-modern-images' ),
				'webp'        => 68,
				'avif'        => 38,
			),
			'standard' => array(
				'label'       => __( 'Standard', 'just-modern-images' ),
				'description' => __( 'A balanced choice for most websites.', 'just-modern-images' ),
				'webp'        => 78,
				'avif'        => 48,
			),
			'high'     => array(
				'label'       => __( 'High', 'just-modern-images' ),
				'description' => __( 'Keeps more detail for image-led websites.', 'just-modern-images' ),
				'webp'        => 86,
				'avif'        => 58,
			),
			'ultra'    => array(
				'label'       => __( 'Ultra', 'just-modern-images' ),
				'description' => __( 'Maximum fidelity with larger files.', 'just-modern-images' ),
				'webp'        => 94,
				'avif'        => 72,
			),
		);
	}

	/**
	 * Return the selected profile key.
	 *
	 * @return string
	 */
	public function selected_key() {
		return $this->sanitize( get_option( self::OPTION_NAME, self::DEFAULT_PROFILE ) );
	}

	/**
	 * Return the selected profile.
	 *
	 * @return array<string, int|string>
	 */
	public function selected() {
		$profiles = $this->all();

		return $profiles[ $this->selected_key() ];
	}

	/**
	 * Return the quality for an output MIME type.
	 *
	 * @param string $mime_type Output MIME type.
	 * @return int
	 */
	public function quality_for( $mime_type ) {
		$profile = $this->selected();
		$key     = 'image/avif' === $mime_type ? 'avif' : 'webp';

		return (int) $profile[ $key ];
	}

	/**
	 * Normalize a profile key.
	 *
	 * @param mixed $value Profile key.
	 * @return string
	 */
	public function sanitize( $value ) {
		$value    = is_string( $value ) ? sanitize_key( $value ) : '';
		$profiles = $this->all();

		return isset( $profiles[ $value ] ) ? $value : self::DEFAULT_PROFILE;
	}

	/**
	 * Return an identifier for generated files that depend on quality.
	 *
	 * @return string
	 */
	public function generation_profile() {
		$profile = $this->selected();

		return sprintf(
			'v1:%s:webp-%d:avif-%d',
			$this->selected_key(),
			(int) $profile['webp'],
			(int) $profile['avif']
		);
	}
}
