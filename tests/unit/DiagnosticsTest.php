<?php

use PHPUnit\Framework\TestCase;

final class DiagnosticsTest extends TestCase {

	public function test_known_reason_has_a_specific_message(): void {
		$this->assertSame(
			'The validated file could not be published in the uploads directory.',
			JMI_Diagnostics::label( 'publish_copy_failed' )
		);
	}

	public function test_unknown_reason_has_a_safe_fallback(): void {
		$this->assertSame( 'Processing did not complete.', JMI_Diagnostics::label( 'future_reason' ) );
	}
}
