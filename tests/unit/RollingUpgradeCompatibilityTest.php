<?php

use PHPUnit\Framework\TestCase;

final class RollingUpgradeCompatibilityTest extends TestCase {

	public function test_settings_accept_legacy_capability_component(): void {
		$capabilities = new JMI_Test_Legacy_Capabilities();
		$settings     = new JMI_Settings( null, $capabilities, null, null );
		$method       = new ReflectionMethod( JMI_Settings::class, 'server_summary' );
		$method->setAccessible( true );

		$summary = $method->invoke( $settings );

		$this->assertTrue( $summary['rolling_update'] );
		$this->assertSame( 1, $summary['profile_count'] );
		$this->assertSame( 'unknown', $summary['formats']['image/avif']['state'] );
	}

	public function test_converter_accepts_legacy_manifest_component(): void {
		$manifest  = new JMI_Test_Legacy_Manifest();
		$converter = new JMI_Converter( null, null, null, $manifest );
		$method    = new ReflectionMethod( JMI_Converter::class, 'publish_manifest' );
		$method->setAccessible( true );

		$published = $method->invoke( $converter, 71, array(), array( 'sources' => array() ) );

		$this->assertTrue( $published );
		$this->assertSame( 71, $manifest->saved_attachment_id );
		$this->assertSame( 1, $manifest->cleanup_calls );
	}
}

final class JMI_Test_Legacy_Capabilities {

	public function get_all(): array {
		return array(
			'image/avif' => array(
				'state'  => 'unknown',
				'reason' => 'not_checked',
			),
		);
	}
}

final class JMI_Test_Legacy_Manifest {

	public $saved_attachment_id = 0;
	public $cleanup_calls       = 0;

	public function save( $attachment_id, $manifest ): bool {
		unset( $manifest );
		$this->saved_attachment_id = $attachment_id;

		return false;
	}

	public function delete_unreferenced_variants( $previous, $next, $attachment_id ): int {
		unset( $previous, $next, $attachment_id );
		++$this->cleanup_calls;

		return 0;
	}
}
