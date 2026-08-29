<?php

use PHPUnit\Framework\TestCase;

final class QualityProfilesTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['jmi_test_options'] = array();
	}

	public function test_standard_is_the_default_profile(): void {
		$profiles = new JMI_Quality_Profiles();

		$this->assertSame( 'standard', $profiles->selected_key() );
		$this->assertSame( 78, $profiles->quality_for( 'image/webp' ) );
		$this->assertSame( 48, $profiles->quality_for( 'image/avif' ) );
	}

	public function test_unknown_profile_falls_back_to_standard(): void {
		$GLOBALS['jmi_test_options'][ JMI_Quality_Profiles::OPTION_NAME ] = 'something-else';
		$profiles = new JMI_Quality_Profiles();

		$this->assertSame( 'standard', $profiles->selected_key() );
	}

	public function test_generation_profile_changes_with_quality(): void {
		$profiles = new JMI_Quality_Profiles();
		$standard = $profiles->generation_profile();

		$GLOBALS['jmi_test_options'][ JMI_Quality_Profiles::OPTION_NAME ] = 'ultra';

		$this->assertNotSame( $standard, $profiles->generation_profile() );
		$this->assertStringContainsString( 'webp-94', $profiles->generation_profile() );
		$this->assertStringContainsString( 'avif-72', $profiles->generation_profile() );
	}
}

