<?php

use PHPUnit\Framework\TestCase;

final class QueuePriorityTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['jmi_test_options']    = array();
		$GLOBALS['jmi_test_post_meta']  = array();
		$GLOBALS['jmi_test_scheduled']  = array();
		$GLOBALS['jmi_test_mime_types'] = array(
			20 => 'image/jpeg',
			21 => 'image/jpeg',
		);
	}

	public function test_frontend_demand_schedules_an_unprocessed_image(): void {
		$queue = $this->queue();
		$queue->note_demand( 20 );
		$queue->note_demand( 20 );
		$queue->flush_demanded();

		$this->assertCount( 1, $GLOBALS['jmi_test_scheduled'] );
		$this->assertSame( array( 20 ), $GLOBALS['jmi_test_scheduled'][0]['args'] );
		$this->assertSame( 'demand', ( new JMI_Media_Status() )->get( 20, $this->profile() )['priority'] );
	}

	public function test_manual_priority_moves_a_background_job_forward(): void {
		$queue = $this->queue();
		$queue->schedule_attachment( 20, 120, 'background' );
		$background_time = $GLOBALS['jmi_test_scheduled'][0]['timestamp'];

		$queue->schedule_attachment( 20, 1, 'manual', true );

		$this->assertCount( 1, $GLOBALS['jmi_test_scheduled'] );
		$this->assertLessThan( $background_time, $GLOBALS['jmi_test_scheduled'][0]['timestamp'] );
		$this->assertSame( 'manual', ( new JMI_Media_Status() )->get( 20, $this->profile() )['priority'] );
	}

	public function test_a_delayed_event_cannot_move_a_ready_image_back_to_waiting(): void {
		$status = new JMI_Media_Status();
		$status->record_result(
			20,
			$this->profile(),
			array(
				'state'       => 'ready',
				'failed'      => 0,
				'last_reason' => '',
			)
		);

		$this->assertFalse( $this->queue()->process_attachment( 20 ) );
		$this->assertSame( 'ready', $status->get( 20, $this->profile() )['state'] );
		$this->assertCount( 0, $GLOBALS['jmi_test_scheduled'] );
	}

	public function test_lock_contention_retry_preserves_a_settled_state(): void {
		$status = new JMI_Media_Status();
		$status->record_result(
			21,
			$this->profile(),
			array(
				'state'       => 'ready',
				'failed'      => 0,
				'last_reason' => '',
			)
		);
		$queue      = new JMI_Queue( new stdClass(), new JMI_Quality_Profiles(), $status );
		$reschedule = new ReflectionMethod( JMI_Queue::class, 'reschedule_attachment' );
		$reschedule->setAccessible( true );

		$reschedule->invoke( $queue, 21, 30 );

		$this->assertSame( 'ready', $status->get( 21, $this->profile() )['state'] );
		$this->assertCount( 1, $GLOBALS['jmi_test_scheduled'] );
	}

	private function queue(): JMI_Queue {
		return new JMI_Queue( new stdClass(), new JMI_Quality_Profiles(), new JMI_Media_Status() );
	}

	private function profile(): string {
		return ( new JMI_Quality_Profiles() )->generation_profile();
	}
}
