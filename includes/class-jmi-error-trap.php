<?php
/**
 * Scoped handling for warnings emitted by image libraries.
 *
 * @package JustModernImages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prevents a recoverable encoder warning from leaking into an HTTP response.
 */
final class JMI_Error_Trap {

	/**
	 * Run an operation while capturing recoverable PHP warnings.
	 *
	 * @param callable $operation       Operation to run.
	 * @param string   $warning_message Captured warning message.
	 * @return mixed
	 */
	public static function run( $operation, &$warning_message = '' ) {
		$warning_message = '';
		$error_levels    = E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE | E_DEPRECATED | E_USER_DEPRECATED;

		// Image extensions may emit recoverable warnings even when WordPress returns normally.
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
			static function ( $severity, $message ) use ( &$warning_message ) {
				$warning_message = (string) $message;
				return true;
			},
			$error_levels
		);

		try {
			return call_user_func( $operation );
		} finally {
			restore_error_handler();
		}
	}
}
