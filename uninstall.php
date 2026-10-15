<?php
/**
 * Uninstall script for FAQ JSON-LD Manager (Enterprise).
 *
 * WARNING: This will remove plugin options and the mapping table permanently.
 *
 * @package FAQ_JSON_LD
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Delete options.
delete_option( 'fqj_settings' );

// Drop table. The name is built from $wpdb->prefix only, so it is safe to interpolate.
$table = $wpdb->prefix . 'fqj_mappings';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

// Purge transients.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$rows = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", '%_transient_fqj_faq_json_%' ) );
if ( $rows ) {
	foreach ( $rows as $opt ) {
		$key = preg_replace( '/^_transient_|^_transient_timeout_/', '', $opt );
		delete_transient( $key );
	}
}
