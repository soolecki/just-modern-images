<?php

use PHPUnit\Framework\TestCase;

final class SettingsStatusTest extends TestCase {

	public function test_progress_never_says_one_hundred_while_images_are_waiting(): void {
		$status = $this->live_status(
			array(
				'total'      => 6081,
				'ready'      => 5578,
				'partial'    => 113,
				'skipped'    => 366,
				'failed'     => 0,
				'pending'    => 24,
				'queued'     => 0,
				'processing' => 0,
				'stale'      => 0,
			),
			array(
				'status'           => 'running',
				'last_worker_stop' => 'item_limit',
				'last_update'      => time(),
			)
		);

		$this->assertSame( 99, $status['progress_pct'] );
		$this->assertSame( 'processing', $status['mode'] );
		$this->assertTrue( $status['active'] );
		$this->assertSame( '5,578', $status['counts']['ready'] );
		$this->assertSame( '479', $status['counts']['fallback'] );
		$this->assertSame( '24', $status['counts']['waiting'] );
	}

	public function test_finished_library_has_one_clear_one_hundred_percent_state(): void {
		$status = $this->live_status(
			array(
				'total'      => 10,
				'ready'      => 8,
				'partial'    => 1,
				'skipped'    => 1,
				'failed'     => 0,
				'pending'    => 0,
				'queued'     => 0,
				'processing' => 0,
				'stale'      => 0,
			),
			array(
				'status'           => 'complete',
				'last_worker_stop' => 'complete',
				'last_update'      => time(),
			)
		);

		$this->assertSame( 100, $status['progress_pct'] );
		$this->assertSame( 'complete', $status['mode'] );
		$this->assertFalse( $status['active'] );
		$this->assertStringStartsWith( 'Finished.', $status['library_message'] );
	}

	public function test_finished_library_can_clearly_report_attention(): void {
		$status = $this->live_status(
			array(
				'total'      => 10,
				'ready'      => 8,
				'partial'    => 0,
				'skipped'    => 1,
				'failed'     => 1,
				'pending'    => 0,
				'queued'     => 0,
				'processing' => 0,
				'stale'      => 0,
			),
			array(
				'status'      => 'complete',
				'last_reason' => 'encode_failed',
				'last_update' => time(),
			)
		);

		$this->assertSame( 100, $status['progress_pct'] );
		$this->assertSame( 'attention', $status['mode'] );
		$this->assertSame( 'encode_failed', $status['attention_code'] );
	}

	private function live_status( $stats, $queue_status ): array {
		$settings = new JMI_Settings( new stdClass(), new stdClass(), new stdClass(), new stdClass() );
		$method   = new ReflectionMethod( JMI_Settings::class, 'live_status' );
		$method->setAccessible( true );

		return $method->invoke(
			$settings,
			$stats,
			$queue_status,
			array(
				'next_event' => 0,
				'lock_state' => 'free',
				'lock_age'   => 0,
			)
		);
	}
}
