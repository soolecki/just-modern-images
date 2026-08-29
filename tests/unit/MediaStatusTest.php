<?php

use PHPUnit\Framework\TestCase;

final class MediaStatusTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['jmi_test_post_meta'] = array();
	}

	public function test_it_marks_an_old_quality_result_as_stale(): void {
		$status = new JMI_Media_Status();
		$status->record_result(
			10,
			'v1:standard',
			array(
				'state'       => 'ready',
				'failed'      => 0,
				'last_reason' => '',
			)
		);

		$this->assertSame( 'ready', $status->get( 10, 'v1:standard' )['state'] );
		$this->assertSame( 'stale', $status->get( 10, 'v1:ultra' )['state'] );
		$this->assertTrue( $status->needs_processing( 10, 'v1:ultra' ) );
	}

	public function test_a_current_safe_skip_is_not_queued_on_every_visit(): void {
		$status = new JMI_Media_Status();
		$status->record_result(
			11,
			'v1:standard',
			array(
				'state'       => 'skipped',
				'failed'      => 0,
				'last_reason' => 'not_smaller',
			)
		);

		$this->assertFalse( $status->needs_processing( 11, 'v1:standard' ) );
	}

	public function test_failures_receive_an_exponential_retry_delay(): void {
		$status = new JMI_Media_Status();
		$status->record_result(
			12,
			'v1:standard',
			array(
				'state'       => 'failed',
				'failed'      => 1,
				'last_reason' => 'encode_failed',
			)
		);

		$first = $status->get( 12, 'v1:standard' );
		$this->assertSame( 1, $first['failure_count'] );
		$this->assertGreaterThan( time(), $first['retry_after'] );
		$this->assertFalse( $status->needs_processing( 12, 'v1:standard' ) );
	}
}
