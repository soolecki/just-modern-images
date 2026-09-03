<?php

use PHPUnit\Framework\TestCase;

final class MultisiteTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['jmi_test_multisite']       = true;
		$GLOBALS['jmi_test_blog_id']         = 1;
		$GLOBALS['jmi_test_blog_stack']      = array();
		$GLOBALS['jmi_test_options']         = array();
		$GLOBALS['jmi_test_site_options']    = array();
		$GLOBALS['jmi_test_scheduled']       = array();
		$GLOBALS['jmi_test_site_scheduled']  = array();
		$GLOBALS['jmi_test_site_ids']        = array( 1, 2, 3 );
		$GLOBALS['jmi_test_network_options'] = array();
		$GLOBALS['jmi_test_network_active']  = true;

		global $wpdb;
		$wpdb = new JMI_Test_Multisite_Wpdb();
	}

	protected function tearDown(): void {
		$GLOBALS['jmi_test_multisite']      = false;
		$GLOBALS['jmi_test_blog_id']        = 1;
		$GLOBALS['jmi_test_blog_stack']     = array();
		$GLOBALS['jmi_test_network_active'] = false;
	}

	public function test_network_activation_initializes_every_site_independently(): void {
		JMI_Plugin::activate( true );

		$this->assertSame( JMI_Quality_Profiles::DEFAULT_PROFILE, $GLOBALS['jmi_test_options'][ JMI_Quality_Profiles::OPTION_NAME ] );
		$this->assertSame( JMI_Quality_Profiles::DEFAULT_PROFILE, $GLOBALS['jmi_test_site_options'][2][ JMI_Quality_Profiles::OPTION_NAME ] );
		$this->assertSame( JMI_Quality_Profiles::DEFAULT_PROFILE, $GLOBALS['jmi_test_site_options'][3][ JMI_Quality_Profiles::OPTION_NAME ] );
		$this->assertSame( 'queued', $GLOBALS['jmi_test_options'][ JMI_Queue::STATUS_OPTION ]['status'] );
		$this->assertSame( 'queued', $GLOBALS['jmi_test_site_options'][2][ JMI_Queue::STATUS_OPTION ]['status'] );
		$this->assertSame( 'queued', $GLOBALS['jmi_test_site_options'][3][ JMI_Queue::STATUS_OPTION ]['status'] );
		$this->assertSame( 1, $GLOBALS['jmi_test_blog_id'] );
	}

	public function test_network_deactivation_clears_each_sites_events(): void {
		foreach ( array( 1, 2, 3 ) as $site_id ) {
			switch_to_blog( $site_id );
			wp_schedule_single_event( time() + 10, JMI_Queue::SCAN_HOOK );
			wp_schedule_single_event( time() + 10, JMI_Diagnostics_Reporter::SEND_HOOK );
			restore_current_blog();
		}

		JMI_Plugin::deactivate( true );

		$this->assertSame( array(), $GLOBALS['jmi_test_scheduled'] );
		$this->assertSame( array(), $GLOBALS['jmi_test_site_scheduled'][2] );
		$this->assertSame( array(), $GLOBALS['jmi_test_site_scheduled'][3] );
		$this->assertSame( 1, $GLOBALS['jmi_test_blog_id'] );
	}
}

final class JMI_Test_Multisite_Wpdb {

	public $posts    = 'wp_posts';
	public $postmeta = 'wp_postmeta';

	public function prepare( $query, ...$args ) {
		return array( 'query' => $query, 'args' => $args );
	}

	public function get_results() {
		return array();
	}

	public function get_col() {
		return array();
	}
}
