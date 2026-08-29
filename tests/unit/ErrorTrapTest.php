<?php

use PHPUnit\Framework\TestCase;

final class ErrorTrapTest extends TestCase {

	public function test_it_captures_a_warning_and_returns_the_operation_result(): void {
		$warning = '';
		$result  = JMI_Error_Trap::run(
			static function () {
				trigger_error( 'Expected encoder warning', E_USER_WARNING );
				return 'result';
			},
			$warning
		);

		$this->assertSame( 'result', $result );
		$this->assertSame( 'Expected encoder warning', $warning );
	}

	public function test_it_restores_the_previous_handler_after_an_exception(): void {
		$previous = set_error_handler(
			static function () {
				return true;
			}
		);
		restore_error_handler();

		try {
			JMI_Error_Trap::run(
				static function () {
					throw new RuntimeException( 'Expected exception' );
				}
			);
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'Expected exception', $error->getMessage() );
		}

		$current = set_error_handler(
			static function () {
				return true;
			}
		);
		restore_error_handler();

		$this->assertSame( $previous, $current );
	}
}
