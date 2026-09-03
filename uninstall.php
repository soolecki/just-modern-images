<?php
/**
 * Remove plugin-owned files and data.
 *
 * @package JustModernImages
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-jmi-manifest.php';
require_once __DIR__ . '/includes/class-jmi-media-status.php';

/**
 * Remove plugin data for the current site.
 *
 * @return void
 */
function jmi_uninstall_site() {
	global $wpdb;

	$manifest = new JMI_Manifest();
	$cursor   = 0;

	do {
		$query          = $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta}
			WHERE post_id > %d
			AND meta_key = %s
			ORDER BY post_id ASC
			LIMIT 100",
			$cursor,
			JMI_Manifest::META_KEY
		);
		$attachment_ids = $wpdb->get_col( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( $attachment_ids as $attachment_id ) {
			$cursor = absint( $attachment_id );
			$manifest->delete_variants( $cursor );
		}

		$attachment_count = count( $attachment_ids );
	} while ( 100 === $attachment_count );

	foreach (
		array(
			'jmi_capabilities',
			'jmi_data_revision',
			'jmi_format_health',
			'jmi_quality_profile',
			'jmi_queue_status',
			'jmi_scan_worker_lock',
			'jmi_version',
		) as $option_name
	) {
		delete_option( $option_name );
	}

	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s)",
			JMI_Media_Status::STATE_META_KEY,
			JMI_Media_Status::DETAIL_META_KEY
		)
	);

	$lock_pattern = $wpdb->esc_like( 'jmi_attachment_lock_' ) . '%';
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$lock_pattern
		)
	);

	wp_clear_scheduled_hook( 'jmi_process_attachment' );
	wp_clear_scheduled_hook( 'jmi_scan_library' );
}

if ( is_multisite() ) {
	$offset = 0;
	do {
		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $offset,
			)
		);

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			jmi_uninstall_site();
			restore_current_blog();
		}

		$site_count = count( $site_ids );
		$offset    += $site_count;
	} while ( 100 === $site_count );
} else {
	jmi_uninstall_site();
}
