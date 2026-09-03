<?php

use PHPUnit\Framework\TestCase;

final class PluginUpgradeTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['jmi_test_options'] = array();
	}

	public function test_data_revision_is_monotonic_and_does_not_follow_release_versions(): void {
		$plugin   = ( new ReflectionClass( JMI_Plugin::class ) )->newInstanceWithoutConstructor();
		$queue    = new JMI_Test_Upgrade_Queue();
		$property = new ReflectionProperty( JMI_Plugin::class, 'queue' );
		$property->setAccessible( true );
		$property->setValue( $plugin, $queue );
		$method = new ReflectionMethod( JMI_Plugin::class, 'maybe_upgrade' );
		$method->setAccessible( true );

		$method->invoke( $plugin );
		$method->invoke( $plugin );

		$this->assertSame( array( 'upgrade' ), $queue->scan_reasons );
		$this->assertSame( JMI_Plugin::DATA_REVISION, get_option( JMI_Plugin::DATA_REVISION_OPTION ) );
		$this->assertSame( JMI_Plugin::LEGACY_VERSION, get_option( 'jmi_version' ) );
	}

	public function test_newer_data_revision_is_never_downgraded(): void {
		update_option( JMI_Plugin::DATA_REVISION_OPTION, JMI_Plugin::DATA_REVISION + 1 );
		$plugin   = ( new ReflectionClass( JMI_Plugin::class ) )->newInstanceWithoutConstructor();
		$queue    = new JMI_Test_Upgrade_Queue();
		$property = new ReflectionProperty( JMI_Plugin::class, 'queue' );
		$property->setAccessible( true );
		$property->setValue( $plugin, $queue );
		$method = new ReflectionMethod( JMI_Plugin::class, 'maybe_upgrade' );
		$method->setAccessible( true );

		$method->invoke( $plugin );

		$this->assertSame( array(), $queue->scan_reasons );
		$this->assertSame( JMI_Plugin::DATA_REVISION + 1, get_option( JMI_Plugin::DATA_REVISION_OPTION ) );
	}
}

final class JMI_Test_Upgrade_Queue {

	public $scan_reasons = array();

	public function start_scan( $reason ): void {
		$this->scan_reasons[] = $reason;
	}
}
