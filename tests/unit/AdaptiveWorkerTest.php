<?php

use PHPUnit\Framework\TestCase;

final class AdaptiveWorkerTest extends TestCase {

	protected function setUp(): void {
		$this->reset_request_budget();
		$GLOBALS['jmi_test_options']    = array();
		$GLOBALS['jmi_test_post_meta']  = array();
		$GLOBALS['jmi_test_scheduled']  = array();
		$GLOBALS['jmi_test_filters']    = array();
		$GLOBALS['jmi_test_mime_types'] = array(
			1 => 'image/jpeg',
			2 => 'image/jpeg',
			3 => 'image/jpeg',
			4 => 'image/jpeg',
			5 => 'image/jpeg',
		);
	}

	public function test_fast_worker_uses_the_per_run_item_ceiling(): void {
		global $wpdb;

		$wpdb = new JMI_Test_Attachment_Wpdb( array( 1, 2, 3, 4, 5 ) );
		$GLOBALS['jmi_test_filters']['jmi_worker_max_items'] = static function () {
			return 3;
		};
		$GLOBALS['jmi_test_filters']['jmi_worker_time_budget'] = static function () {
			return 45;
		};
		$converter = new JMI_Test_Recording_Converter();
		$queue     = $this->queue( $converter );

		$queue->scan_library();

		$status = $queue->status();
		$this->assertSame( array( 1, 2, 3 ), $converter->attachment_ids );
		$this->assertSame( 3, $status['cursor'] );
		$this->assertSame( 3, $status['last_worker_items'] );
		$this->assertSame( 3, $status['last_worker_attempts'] );
		$this->assertSame( 'item_limit', $status['last_worker_stop'] );
		$this->assertSame( 'running', $status['status'] );
		$this->assertFalse( get_option( JMI_Queue::WORKER_LOCK, false ) );
		$this->assertNotFalse( wp_next_scheduled( JMI_Queue::SCAN_HOOK ) );
	}

	public function test_worker_marks_the_scan_complete_without_another_event(): void {
		global $wpdb;

		$wpdb      = new JMI_Test_Attachment_Wpdb( array( 1, 2 ) );
		$converter = new JMI_Test_Recording_Converter();
		$queue     = $this->queue( $converter );

		$queue->scan_library();

		$status = $queue->status();
		$this->assertSame( array( 1, 2 ), $converter->attachment_ids );
		$this->assertSame( 'complete', $status['last_worker_stop'] );
		$this->assertSame( 'complete', $status['status'] );
		$this->assertFalse( wp_next_scheduled( JMI_Queue::SCAN_HOOK ) );
	}

	public function test_worker_stops_before_time_or_memory_is_exhausted(): void {
		$queue  = $this->queue( new JMI_Test_Recording_Converter() );
		$method = new ReflectionMethod( JMI_Queue::class, 'worker_stop_reason' );
		$method->setAccessible( true );

		$this->assertSame( '', $method->invoke( $queue, 1, 2.0, 1.0, 20.0, 50, 20, 100, 0.8 ) );
		$this->assertSame( 'time_budget', $method->invoke( $queue, 2, 16.0, 3.0, 20.0, 50, 20, 100, 0.8 ) );
		$this->assertSame( 'memory_pressure', $method->invoke( $queue, 2, 2.0, 1.0, 20.0, 50, 80, 100, 0.8 ) );
		$this->assertSame( 'item_limit', $method->invoke( $queue, 50, 2.0, 1.0, 20.0, 50, 20, 100, 0.8 ) );
	}

	public function test_separate_cron_events_share_one_request_budget(): void {
		$GLOBALS['jmi_test_filters']['jmi_worker_max_items'] = static function () {
			return 1;
		};
		$converter = new JMI_Test_Recording_Converter();
		$queue     = $this->queue( $converter );
		$profile   = ( new JMI_Quality_Profiles() )->generation_profile();
		( new JMI_Media_Status() )->mark_queued( 2, 'manual', $profile );

		$this->assertTrue( $queue->process_attachment( 1 ) );
		$this->assertFalse( $queue->process_attachment( 2 ) );

		$this->assertSame( array( 1 ), $converter->attachment_ids );
		$this->assertNotFalse( wp_next_scheduled( JMI_Queue::PROCESS_HOOK, array( 2 ) ) );
		$this->assertSame( 'manual', ( new JMI_Media_Status() )->get( 2, $profile )['priority'] );
	}

	private function queue( $converter ): JMI_Queue {
		return new JMI_Queue( $converter, new JMI_Quality_Profiles(), new JMI_Media_Status() );
	}

	private function reset_request_budget(): void {
		foreach ( array( 'request_worker_started_at', 'request_worker_attempts', 'request_worker_item_time' ) as $property_name ) {
			$property = new ReflectionProperty( JMI_Queue::class, $property_name );
			$property->setAccessible( true );
			$property->setValue( null, 0 );
		}
	}
}

final class JMI_Test_Recording_Converter {

	public $attachment_ids = array();

	public function convert_attachment( $attachment_id ): array {
		$this->attachment_ids[] = $attachment_id;

		return array(
			'attachment_id' => $attachment_id,
			'generated'     => 0,
			'reused'        => 0,
			'retained'      => 0,
			'skipped'       => 1,
			'failed'        => 0,
			'last_reason'   => 'not_smaller',
			'state'         => 'skipped',
		);
	}
}

final class JMI_Test_Attachment_Wpdb {

	public $posts = 'wp_posts';

	private $attachment_ids;

	public function __construct( $attachment_ids ) {
		$this->attachment_ids = $attachment_ids;
	}

	public function prepare( $query, ...$args ) {
		return array(
			'query' => $query,
			'args'  => $args,
		);
	}

	public function get_col( $prepared ) {
		$cursor = (int) $prepared['args'][0];
		$limit  = (int) $prepared['args'][1];
		$ids    = array_values(
			array_filter(
				$this->attachment_ids,
				static function ( $attachment_id ) use ( $cursor ) {
					return $attachment_id > $cursor;
				}
			)
		);

		return array_slice( $ids, 0, $limit );
	}
}
