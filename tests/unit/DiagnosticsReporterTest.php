<?php

use PHPUnit\Framework\TestCase;

final class DiagnosticsReporterTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['jmi_test_multisite']                            = false;
		$GLOBALS['jmi_test_blog_id']                              = 1;
		$GLOBALS['jmi_test_network_options']                      = array();
		$GLOBALS['jmi_test_options']                             = array();
		$GLOBALS['jmi_test_scheduled']                           = array();
		$GLOBALS['jmi_test_remote_requests']                     = array();
		$GLOBALS['jmi_test_remote_response']                     = array( 'response' => array( 'code' => 202 ) );
		$GLOBALS['jmi_test_filters']['jmi_diagnostics_endpoint'] = static function () {
			return 'https://reports.example.test/';
		};
		$GLOBALS['jmi_test_filters']['jmi_diagnostics_fleet_key'] = static function () {
			return str_repeat( 'f', 48 );
		};
	}

	protected function tearDown(): void {
		unset( $GLOBALS['jmi_test_filters']['jmi_diagnostics_endpoint'] );
		unset( $GLOBALS['jmi_test_filters']['jmi_diagnostics_fleet_key'] );
	}

	public function test_reporting_requires_an_administrator_choice(): void {
		$reporter = new JMI_Diagnostics_Reporter();

		$this->assertFalse( $reporter->enabled() );
		$this->assertTrue( $reporter->status()['configured'] );

		$reporter->set_enabled( true );

		$this->assertTrue( $reporter->enabled() );
		$this->assertSame( '1', get_option( JMI_Diagnostics_Reporter::ENABLED_OPTION ) );
	}

	public function test_reporting_stays_off_when_private_fleet_key_is_missing(): void {
		unset( $GLOBALS['jmi_test_filters']['jmi_diagnostics_fleet_key'] );
		$reporter = new JMI_Diagnostics_Reporter();

		$reporter->set_enabled( true );

		$this->assertTrue( $reporter->allowed() );
		$this->assertFalse( $reporter->configured() );
		$this->assertFalse( $reporter->enabled() );
		$this->assertSame( array(), get_option( JMI_Diagnostics_Reporter::OUTBOX_OPTION, array() ) );
	}

	public function test_remote_event_discards_attachment_identifiers_and_file_data(): void {
		$reporter = new JMI_Diagnostics_Reporter();
		$reporter->set_enabled( true );
		$reporter->queue_activity(
			array(
				'id'          => 'worker-event-1',
				'type'        => 'scan',
				'started_at'  => 100,
				'finished_at' => 101,
				'performance' => array(
					'start_delay_ms' => 1500,
					'time_budget_ms' => 20000,
				),
				'items'       => array(
					array(
						'attachment_id' => 42,
						'filename'      => 'private-photo.jpg',
						'after_state'   => 'failed',
						'after_reason'  => 'encode_failed',
						'queue_source'  => 'demand',
						'failed'        => 1,
						'duration_ms'   => 650,
					),
				),
			)
		);

		$outbox = get_option( JMI_Diagnostics_Reporter::OUTBOX_OPTION );
		$json   = wp_json_encode( $outbox );

		$this->assertCount( 1, $outbox );
		$this->assertStringNotContainsString( 'attachment_id', $json );
		$this->assertStringNotContainsString( 'private-photo.jpg', $json );
		$this->assertSame( 1, $outbox[0]['item_results']['reasons']['encode_failed'] );
		$this->assertSame( 1, $outbox[0]['item_results']['sources']['demand'] );
		$this->assertSame( 650, $outbox[0]['item_results']['total_duration_ms'] );
		$this->assertSame( 1500, $outbox[0]['performance']['start_delay_ms'] );
	}

	public function test_cron_observation_queues_a_bounded_heartbeat(): void {
		$reporter = new JMI_Diagnostics_Reporter();
		$reporter->set_enabled( true );

		$reporter->observe_cron();
		$status = $reporter->status();

		$this->assertSame( 1, $status['cron']['observations'] );
		$this->assertGreaterThan( 0, $status['cron']['last_observed_at'] );
		$this->assertSame( 'cron_heartbeat', get_option( JMI_Diagnostics_Reporter::OUTBOX_OPTION )[0]['type'] );
	}

	public function test_cron_heartbeat_uses_live_queue_and_format_state(): void {
		$reporter = new JMI_Diagnostics_Reporter(
			static function () {
				return array(
					'snapshot' => array(
						'library' => array( 'total' => 100, 'ready' => 70, 'waiting' => 30 ),
						'queue'   => array( 'status' => 'running', 'cursor' => 55 ),
					),
					'formats'  => array(
						'image/webp' => array( 'state' => 'available', 'reason' => 'probe_passed' ),
					),
				);
			}
		);
		$reporter->set_enabled( true );
		$reporter->observe_cron();

		$heartbeat = get_option( JMI_Diagnostics_Reporter::OUTBOX_OPTION )[0];
		$this->assertSame( 100, $heartbeat['after']['library']['total'] );
		$this->assertSame( 70, $heartbeat['after']['library']['ready'] );
		$this->assertSame( 'running', $heartbeat['after']['queue']['status'] );
		$this->assertSame( 'available', $heartbeat['formats']['image/webp']['state'] );
	}

	public function test_multisite_payload_contains_a_shared_network_group(): void {
		$GLOBALS['jmi_test_multisite'] = true;
		$reporter                      = new JMI_Diagnostics_Reporter();
		$reporter->set_enabled( true );
		$reporter->queue_activity( array( 'id' => 'network-event', 'type' => 'scan', 'started_at' => 100 ) );
		$reporter->send( true );
		$payload = json_decode( $GLOBALS['jmi_test_remote_requests'][0]['args']['body'], true );

		$this->assertSame( 1, $payload['installation']['site_id'] );
		$this->assertNotSame( '', $payload['installation']['network_group'] );
		$this->assertArrayHasKey( 'storage', $payload['runtime'] );

		$GLOBALS['jmi_test_multisite'] = false;
	}

	public function test_successful_send_includes_site_identity_and_clears_batch(): void {
		$reporter = new JMI_Diagnostics_Reporter();
		$reporter->set_enabled( true );
		$reporter->queue_activity(
			array(
				'id'          => 'worker-event-2',
				'type'        => 'scan',
				'started_at'  => 100,
				'finished_at' => 101,
			)
		);

		$this->assertSame( 'sent', $reporter->send( true ) );
		$this->assertCount( 1, $GLOBALS['jmi_test_remote_requests'] );
		$this->assertSame( array(), get_option( JMI_Diagnostics_Reporter::OUTBOX_OPTION ) );

		$payload = json_decode( $GLOBALS['jmi_test_remote_requests'][0]['args']['body'], true );
		$this->assertSame( 'Example Site', $payload['installation']['site_name'] );
		$this->assertSame( 'https://example.test/', $payload['installation']['site_url'] );
		$this->assertArrayHasKey( 'X-JMI-Key', $GLOBALS['jmi_test_remote_requests'][0]['args']['headers'] );
		$this->assertSame( str_repeat( 'f', 48 ), $GLOBALS['jmi_test_remote_requests'][0]['args']['headers']['X-JMI-Fleet-Key'] );
		$this->assertArrayNotHasKey( 'key', $payload['installation'] );
	}

	public function test_failed_send_keeps_reports_and_uses_backoff(): void {
		$GLOBALS['jmi_test_remote_response'] = array( 'response' => array( 'code' => 503 ) );
		$reporter                            = new JMI_Diagnostics_Reporter();
		$reporter->set_enabled( true );
		$reporter->queue_activity(
			array(
				'id'         => 'worker-event-3',
				'type'       => 'scan',
				'started_at' => 100,
			)
		);

		$this->assertSame( 'failed', $reporter->send( true ) );
		$this->assertCount( 1, get_option( JMI_Diagnostics_Reporter::OUTBOX_OPTION ) );
		$this->assertSame( 'http_503', get_option( JMI_Diagnostics_Reporter::STATE_OPTION )['last_error'] );
		$this->assertGreaterThan( time(), get_option( JMI_Diagnostics_Reporter::STATE_OPTION )['next_attempt'] );
	}
}
