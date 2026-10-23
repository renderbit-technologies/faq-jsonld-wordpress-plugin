<?php
/**
 * Uninstall script for FAQ JSON-LD Manager.
 *
 * WARNING: This removes plugin options, scheduled events, transients, post meta and the mapping table
 * permanently, on every site of a multisite network. FAQ posts are kept unless the
 * `fqj_uninstall_delete_faqs` filter returns true.
 *
 * Plugin functions are not loaded during uninstall, so everything here is self-contained.
 *
 * @package FAQ_JSON_LD
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove all plugin data for the current site.
 */
$fqj_uninstall_site = function () {
	global $wpdb;

	// Options.
	foreach ( array( 'fqj_settings', 'fqj_db_version', 'fqj_data_version', 'fqj_data_migration_cursor', 'fqj_invalidation_log', 'fqj_last_queue_run', 'fqj_cache_gen', 'fqj_url_migration_report', 'fqj_post_type' ) as $option ) {
		delete_option( $option );
	}

	// Scheduled events.
	wp_clear_scheduled_hook( 'fqj_process_invalidation_queue' );
	wp_clear_scheduled_hook( 'fqj_data_migration' );

	// Transients. Cached output held only in an external object cache cannot be enumerated and expires on its own.
	delete_transient( 'fqj_invalidate_queue' );
	foreach ( array( 'fqj_faq_json_', 'fqj_rejected_urls_', 'fqj_unmatched_urls_' ) as $prefix ) {
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
	if ( apply_filters( 'fqj_uninstall_delete_faqs', false ) ) {
		do {
			$faq_ids = get_posts(
				array(
					'post_type'              => array( 'fqj_faq', 'faq_item' ),
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
	delete_post_meta_by_key( 'fqj_assoc_type' );
	delete_post_meta_by_key( 'fqj_assoc_data_json' );

	// Drop tables.
	foreach ( array( 'fqj_mappings', 'fqj_queue' ) as $fqj_table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $fqj_table ) );
	}
};

if ( is_multisite() ) {
	$fqj_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $fqj_site_ids as $fqj_site_id ) {
		switch_to_blog( $fqj_site_id );
		$fqj_uninstall_site();
		restore_current_blog();
	}
} else {
	$fqj_uninstall_site();
}
