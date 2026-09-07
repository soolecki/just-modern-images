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

	public function test_source_and_storage_failures_do_not_trip_the_encoder_breaker(): void {
		$capabilities = new JMI_Capabilities();
		$capabilities->probe_all();

		for ( $attempt = 0; $attempt < 10; ++$attempt ) {
			$capabilities->record_failure( 'image/webp', 'decode_failed' );
			$capabilities->record_failure( 'image/webp', 'storage_write_failed' );
		}

		$this->assertSame( array(), get_option( JMI_Capabilities::HEALTH_OPTION, array() ) );
	}

	public function test_all_formats_pause_returns_the_earliest_recovery_time(): void {
		$capabilities = new JMI_Capabilities();
		$capabilities->probe_all();

		foreach ( array_keys( $capabilities->formats() ) as $mime_type ) {
			for ( $attempt = 0; $attempt < JMI_Capabilities::FAILURE_LIMIT; ++$attempt ) {
				$capabilities->record_failure( $mime_type, 'encode_failed' );
			}
		}

		$this->assertGreaterThan( time(), $capabilities->all_formats_paused_until() );
		$capabilities->record_success( 'image/webp' );
		$this->assertSame( 0, $capabilities->all_formats_paused_until() );
	}

	public function test_alpha_failure_disables_only_transparent_sources_for_one_format(): void {
		$capabilities = new JMI_Capabilities();
		$fingerprint  = new ReflectionMethod( JMI_Capabilities::class, 'fingerprint' );
		$fingerprint->setAccessible( true );
		$environment = $fingerprint->invoke( $capabilities );
		update_option(
			JMI_Capabilities::OPTION_NAME,
			array(
				'schema'   => JMI_Capabilities::STORAGE_SCHEMA,
				'profiles' => array(
					$environment => array(
						'checked_at' => time(),
						'formats'    => array(
							'image/avif' => array(
								'state'                 => 'available',
								'reason'                => 'probe_passed',
								'supports_transparency' => true,
							),
							'image/webp' => array(
								'state'                 => 'available',
								'reason'                => 'probe_passed',
								'supports_transparency' => true,
							),
						),
					),
				),
			)
		);

		$capabilities->record_transparency_failure( 'image/avif', 'alpha_lost' );
		$formats = $capabilities->get_all();

		$this->assertSame( 'available', $formats['image/avif']['state'] );
		$this->assertFalse( $formats['image/avif']['supports_transparency'] );
		$this->assertSame( 'alpha_lost', $formats['image/avif']['transparency_reason'] );
		$this->assertTrue( $capabilities->supports( 'image/avif' ) );
		$this->assertFalse( $capabilities->supports_transparency( 'image/avif' ) );
		$this->assertTrue( $capabilities->supports_transparency( 'image/webp' ) );
	}
}
