<?php

use PHPUnit\Framework\TestCase;

final class AdaptiveWorkerTest extends TestCase {

	protected function setUp(): void {
		$this->reset_request_budget();
		$GLOBALS['jmi_test_options']    = array();
		$GLOBALS['jmi_test_post_meta']  = array();
		$GLOBALS['jmi_test_scheduled']  = array();
		$GLOBALS['jmi_test_filters']    = array();
		$GLOBALS['jmi_test_doing_cron'] = false;
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
		$GLOBALS['jmi_test_filters']['jmi_worker_max_items']   = static function () {
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

	public function test_worker_records_library_counts_before_and_after_a_run(): void {
		global $wpdb;

		$wpdb      = new JMI_Test_Attachment_Wpdb( array( 1, 2 ) );
		$converter = new JMI_Test_Recording_Converter();
		$history   = new JMI_Activity_Log();
		$queue     = new JMI_Queue( $converter, new JMI_Quality_Profiles(), new JMI_Media_Status(), null, $history );

		$queue->scan_library();

		$entries = $history->entries();
		$this->assertCount( 1, $entries );
		$this->assertSame( 'scan', $entries[0]['type'] );
		$this->assertSame( 2, $entries[0]['before']['library']['waiting'] );
		$this->assertSame( 2, $entries[0]['after']['library']['skipped'] );
		$this->assertSame( 2, $entries[0]['after']['queue']['cursor'] );
		$this->assertCount( 2, $entries[0]['items'] );
		$this->assertSame( 'pending', $entries[0]['items'][0]['before_state'] );
		$this->assertSame( 'skipped', $entries[0]['items'][0]['after_state'] );
		$this->assertGreaterThan( 0, $entries[0]['performance']['time_budget_ms'] );
		$this->assertGreaterThanOrEqual( $entries[0]['performance']['memory_start'], $entries[0]['performance']['memory_peak'] );
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

	public function test_missing_scan_event_is_restored_without_duplicates(): void {
		$queue   = $this->queue( new JMI_Test_Recording_Converter() );
		$profile = ( new JMI_Quality_Profiles() )->generation_profile();
		update_option(
			JMI_Queue::STATUS_OPTION,
			array(
				'status'             => 'running',
				'generation_profile' => $profile,
			)
		);

		$queue->ensure_scan_scheduled();
		$queue->ensure_scan_scheduled();

		$this->assertCount( 1, $GLOBALS['jmi_test_scheduled'] );
		$this->assertSame( JMI_Queue::SCAN_HOOK, $GLOBALS['jmi_test_scheduled'][0]['hook'] );
		$this->assertSame( 'scheduled', $queue->status()['last_schedule_result'] );
		$this->assertSame( $GLOBALS['jmi_test_scheduled'][0]['timestamp'], $queue->status()['next_worker_due'] );
	}

	public function test_overdue_scan_event_and_stale_lock_are_recovered(): void {
		$queue = $this->queue( new JMI_Test_Recording_Converter() );
		update_option( JMI_Queue::STATUS_OPTION, array( 'status' => 'queued' ) );
		update_option( JMI_Queue::WORKER_LOCK, time() - JMI_Queue::WORKER_TTL - 1 );
		wp_schedule_single_event( time() - JMI_Queue::SCHEDULE_GRACE - 1, JMI_Queue::SCAN_HOOK );

		$queue->ensure_scan_scheduled();

		$this->assertFalse( get_option( JMI_Queue::WORKER_LOCK, false ) );
		$this->assertGreaterThan( time(), wp_next_scheduled( JMI_Queue::SCAN_HOOK ) );
		$this->assertGreaterThan( 0, $queue->status()['last_lock_recovery_at'] );
	}

	public function test_live_cron_request_claims_a_missing_scan_event(): void {
		global $wpdb;

		$wpdb                             = new JMI_Test_Attachment_Wpdb( array( 1, 2 ) );
		$GLOBALS['jmi_test_doing_cron'] = true;
		$queue                            = $this->queue( new JMI_Test_Recording_Converter() );
		update_option( JMI_Queue::STATUS_OPTION, array( 'status' => 'queued' ) );

		$queue->run_due_scan_during_cron();

		$status = $queue->status();
		$this->assertSame( 'complete', $status['status'] );
		$this->assertSame( 'missing_event_claimed', $status['last_recovery_reason'] );
		$this->assertSame( 1, $status['recovery_count'] );
		$this->assertSame( 2, $status['last_worker_attempts'] );
	}

	public function test_worker_budget_expands_on_servers_with_room(): void {
		$queue  = $this->queue( new JMI_Test_Recording_Converter() );
		$method = new ReflectionMethod( JMI_Queue::class, 'default_worker_time_budget' );
		$method->setAccessible( true );

		$this->assertSame( 45, $method->invoke( $queue, 0, 'LiteSpeed' ) );
		$this->assertSame( 45, $method->invoke( $queue, 300, 'nginx' ) );
		$this->assertSame( 20, $method->invoke( $queue, 30, 'nginx' ) );
		$this->assertSame( 20, $method->invoke( $queue, 0, 'Microsoft-IIS/10.0' ) );
	}

	public function test_repeated_upgrade_does_not_reset_an_active_scan(): void {
		$queue   = $this->queue( new JMI_Test_Recording_Converter() );
		$profile = ( new JMI_Quality_Profiles() )->generation_profile();
		update_option(
			JMI_Queue::STATUS_OPTION,
			array(
				'status'               => 'running',
				'cursor'               => 42,
				'processed'            => 12,
				'generation_profile'   => $profile,
				'last_worker_attempts' => 12,
			)
		);

		$queue->start_scan( 'upgrade' );

		$status = $queue->status();
		$this->assertSame( 42, $status['cursor'] );
		$this->assertSame( 12, $status['processed'] );
		$this->assertSame( 12, $status['last_worker_attempts'] );
		$this->assertNotFalse( wp_next_scheduled( JMI_Queue::SCAN_HOOK ) );
	}

	private function queue( $converter ): JMI_Queue {
		return new JMI_Queue( $converter, new JMI_Quality_Profiles(), new JMI_Media_Status() );
	}

	private function reset_request_budget(): void {
		foreach ( array( 'request_worker_started_at', 'request_worker_attempts', 'request_worker_item_time', 'cron_recovery_ran' ) as $property_name ) {
			$property = new ReflectionProperty( JMI_Queue::class, $property_name );
			$property->setAccessible( true );
			$property->setValue( null, 'cron_recovery_ran' === $property_name ? false : 0 );
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

	public $posts    = 'wp_posts';
	public $postmeta = 'wp_postmeta';

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

	public function get_results( $prepared ) {
		unset( $prepared );
		$grouped = array();

		foreach ( $this->attachment_ids as $attachment_id ) {
			$state = $GLOBALS['jmi_test_post_meta'][ $attachment_id ][ JMI_Media_Status::STATE_META_KEY ] ?? null;
			$key   = is_string( $state ) ? $state : '__pending__';
			if ( ! isset( $grouped[ $key ] ) ) {
				$grouped[ $key ] = 0;
			}
			++$grouped[ $key ];
		}

		$rows = array();
		foreach ( $grouped as $state => $amount ) {
			$rows[] = (object) array(
				'state_value' => '__pending__' === $state ? null : $state,
				'amount'      => $amount,
			);
		}

		return $rows;
	}
}
