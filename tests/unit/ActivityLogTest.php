<?php

use PHPUnit\Framework\TestCase;

final class ActivityLogTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['jmi_test_options'] = array();
	}

	public function test_it_keeps_a_bounded_newest_first_history(): void {
		$log = new JMI_Activity_Log();

		for ( $index = 1; $index <= JMI_Activity_Log::MAX_ENTRIES + 5; ++$index ) {
			$log->record(
				array(
					'started_at'  => $index,
					'finished_at' => $index,
					'server'      => 'node/' . $index,
				)
			);
		}

		$entries = $log->entries();

		$this->assertCount( JMI_Activity_Log::MAX_ENTRIES, $entries );
		$this->assertSame( JMI_Activity_Log::MAX_ENTRIES + 5, $entries[0]['started_at'] );
		$this->assertSame( 6, $entries[ JMI_Activity_Log::MAX_ENTRIES - 1 ]['started_at'] );
		$this->assertSame( 'node55', $entries[0]['server'] );
	}

	public function test_report_discards_unknown_and_sensitive_fields(): void {
		$log = new JMI_Activity_Log();
		$log->record(
			array(
				'started_at'  => 100,
				'finished_at' => 101,
				'server'      => 'safe-node',
				'path'        => 'C:\\private\\uploads',
				'url'         => 'https://example.test/private',
				'items'       => array(
					array(
						'attachment_id' => 42,
						'before_state'  => 'queued',
						'after_state'   => 'ready',
						'after_reason'  => 'generated',
						'filename'      => 'private.jpg',
					),
				),
			)
		);

		$report = $log->report();
		$entry  = $report['entries'][0];

		$this->assertArrayNotHasKey( 'path', $entry );
		$this->assertArrayNotHasKey( 'url', $entry );
		$this->assertArrayNotHasKey( 'filename', $entry['items'][0] );
		$this->assertSame( 42, $entry['items'][0]['attachment_id'] );
		$this->assertSame( JMI_Activity_Log::SCHEMA, $report['schema'] );
	}
}
