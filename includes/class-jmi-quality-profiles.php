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
		$profiles = $this->quality_values();

		$profiles['economy']['label']        = __( 'Economy', 'just-modern-images' );
		$profiles['economy']['description']  = __( 'Prioritizes the smallest practical files.', 'just-modern-images' );
		$profiles['standard']['label']       = __( 'Standard', 'just-modern-images' );
		$profiles['standard']['description'] = __( 'A balanced choice for most websites.', 'just-modern-images' );
		$profiles['high']['label']           = __( 'High', 'just-modern-images' );
		$profiles['high']['description']     = __( 'Keeps more detail for image-led websites.', 'just-modern-images' );
		$profiles['ultra']['label']          = __( 'Ultra', 'just-modern-images' );
		$profiles['ultra']['description']    = __( 'Maximum fidelity with larger files.', 'just-modern-images' );

		return $profiles;
	}

	/**
	 * Return profile values without triggering translation loading.
	 *
	 * Queue setup may need these values before the admin interface is rendered.
	 *
	 * @return array<string, array<string, int>>
	 */
	private function quality_values() {
		return array(
			'economy'  => array(
				'webp' => 68,
				'avif' => 38,
			),
			'standard' => array(
				'webp' => 78,
				'avif' => 48,
			),
			'high'     => array(
				'webp' => 86,
				'avif' => 58,
			),
			'ultra'    => array(
				'webp' => 94,
				'avif' => 72,
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
		$profiles = $this->quality_values();
		$profile  = $profiles[ $this->selected_key() ];
		$key      = 'image/avif' === $mime_type ? 'avif' : 'webp';

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
		$profiles = $this->quality_values();

		return isset( $profiles[ $value ] ) ? $value : self::DEFAULT_PROFILE;
	}

	/**
	 * Return an identifier for generated files that depend on quality.
	 *
	 * @return string
	 */
	public function generation_profile() {
		$profiles = $this->quality_values();
		$profile  = $profiles[ $this->selected_key() ];

		return sprintf(
			'v1:%s:webp-%d:avif-%d',
			$this->selected_key(),
			(int) $profile['webp'],
			(int) $profile['avif']
		);
	}
}
