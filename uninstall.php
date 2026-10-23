<?php
/**
 * Uninstall script for Renderbit FAQ Structured Data.
 *
 * WARNING: This removes plugin options, scheduled events, transients, post meta and the mapping table
 * permanently, on every site of a multisite network. FAQ posts are kept unless the
 * `rfsd_uninstall_delete_faqs` filter returns true.
 *
 * Plugin functions are not loaded during uninstall, so everything here is self-contained.
 *
 * @package Renderbit_FAQ_Structured_Data
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove all plugin data for the current site.
 */
$rfsd_uninstall_site = function () {
	global $wpdb;

	// Options.
	foreach ( array( 'rfsd_settings', 'rfsd_db_version', 'rfsd_data_version', 'rfsd_data_migration_cursor', 'rfsd_invalidation_log', 'rfsd_last_queue_run', 'rfsd_cache_gen', 'rfsd_url_migration_report', 'rfsd_post_type' ) as $option ) {
		delete_option( $option );
	}

	// Scheduled events.
	wp_clear_scheduled_hook( 'rfsd_process_invalidation_queue' );
	wp_clear_scheduled_hook( 'rfsd_data_migration' );

	// Transients. Cached output held only in an external object cache cannot be enumerated and expires on its own.
	delete_transient( 'rfsd_invalidate_queue' );
	foreach ( array( 'rfsd_faq_json_', 'rfsd_rejected_urls_', 'rfsd_unmatched_urls_' ) as $prefix ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . $prefix ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%'
			)
		);
		foreach ( $rows as $option_name ) {
			delete_option( $option_name );
		}
	}

	// FAQ items (opt-in) and their meta.
	if ( apply_filters( 'rfsd_uninstall_delete_faqs', false ) ) {
		do {
			$faq_ids = get_posts(
				array(
					'post_type'              => array( 'rfsd_faq', 'faq_item' ),
					// 'any' skips statuses excluded from search, such as trash.
					'post_status'            => array_keys( get_post_stati() ),
					'posts_per_page'         => 100,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			foreach ( $faq_ids as $faq_id ) {
				wp_delete_post( $faq_id, true );
			}
		} while ( ! empty( $faq_ids ) );
	}
	delete_post_meta_by_key( 'rfsd_assoc_type' );
	delete_post_meta_by_key( 'rfsd_assoc_data_json' );

	// Drop tables.
	foreach ( array( 'rfsd_mappings', 'rfsd_queue' ) as $rfsd_table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $rfsd_table ) );
	}
};

if ( is_multisite() ) {
	$rfsd_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $rfsd_site_ids as $rfsd_site_id ) {
		switch_to_blog( $rfsd_site_id );
		$rfsd_uninstall_site();
		restore_current_blog();
	}
} else {
	$rfsd_uninstall_site();
}
