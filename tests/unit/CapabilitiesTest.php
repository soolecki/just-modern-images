<?php

use PHPUnit\Framework\TestCase;

final class CapabilitiesTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['jmi_test_options'] = array();
	}

	public function test_probe_keeps_results_from_other_server_environments(): void {
		$capabilities = new JMI_Capabilities();
		$results      = $capabilities->probe_all();
		$storage      = get_option( JMI_Capabilities::OPTION_NAME );

		$this->assertSame( 'unavailable', $results['image/avif']['state'] );
		$this->assertSame( 'editor_unsupported', $results['image/webp']['reason'] );
		$this->assertCount( 1, $storage['profiles'] );

		$storage['profiles'][ str_repeat( 'a', 64 ) ] = array(
			'checked_at' => time() - 10,
			'formats'    => array(),
		);
		update_option( JMI_Capabilities::OPTION_NAME, $storage );

		$capabilities->probe_all();
		$summary = $capabilities->diagnostic_summary();

		$this->assertSame( 2, $summary['profile_count'] );
		$this->assertSame( 'unavailable', $summary['formats']['image/avif']['state'] );
		$this->assertSame( 12, strlen( $summary['environment_id'] ) );
		$this->assertGreaterThan( 0, $summary['checked_at'] );
	}

	public function test_repeated_failures_are_scoped_to_current_environment(): void {
		$capabilities = new JMI_Capabilities();
		$capabilities->probe_all();

		for ( $attempt = 0; $attempt < JMI_Capabilities::FAILURE_LIMIT; ++$attempt ) {
			$capabilities->record_failure( 'image/webp', 'encode_failed' );
		}

		$health = get_option( JMI_Capabilities::HEALTH_OPTION );

		$this->assertSame( JMI_Capabilities::STORAGE_SCHEMA, $health['schema'] );
		$this->assertCount( 1, $health['profiles'] );
		$this->assertSame( 'temporarily_disabled', $capabilities->get_all()['image/webp']['state'] );
	}
}
