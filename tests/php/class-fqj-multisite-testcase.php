<?php
/**
 * Shared helpers for multisite tests.
 *
 * @package FAQ_JSON_LD
 */

// phpcs:disable WordPress.Files.FileName

/**
 * Base test case for tests that need a multisite network.
 */
abstract class FQJ_Multisite_TestCase extends FQJ_TestCase {

	/**
	 * Skip on single-site and keep activation inside the test transaction.
	 */
	public function set_up() {
		parent::set_up();
		$this->skipWithoutMultisite();
		add_filter( 'query', array( $this, 'skip_noop_alters' ) );
	}

	/**
	 * Drop the ALTERs dbDelta() issues for the plugin's existing tables.
	 *
	 * Some MySQL/MariaDB versions report BIGINT UNSIGNED as bigint(20) unsigned, so dbDelta() re-applies the same
	 * column definitions. The tables were created from the same statement, so these changes are no-ops, but each
	 * ALTER implicitly commits the test transaction and would leak the sites and data created by a test.
	 *
	 * @param string $query SQL query.
	 * @return string
	 */
	public function skip_noop_alters( $query ) {
		if ( preg_match( '/^\s*ALTER TABLE \S*fqj_(mappings|queue) CHANGE COLUMN /', $query ) ) {
			return '';
		}

		return $query;
	}

	/**
	 * Mark the plugin as network-active.
	 */
	protected function network_activate_plugin() {
		update_site_option( 'active_sitewide_plugins', array( plugin_basename( FQJ_PLUGIN_DIR . 'faq-jsonld.php' ) => time() ) );
	}

	/**
	 * Whether a table exists. Works for the temporary tables the test suite creates, which SHOW TABLES omits.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	protected function table_exists( $table ) {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query( "SELECT 1 FROM {$table} LIMIT 0" );
		$wpdb->suppress_errors( $suppress );

		return false !== $result;
	}

	/**
	 * Both plugin tables of a site.
	 *
	 * @param int $site_id Site ID.
	 * @return string[]
	 */
	protected function plugin_tables( $site_id ) {
		global $wpdb;
		$prefix = $wpdb->get_blog_prefix( $site_id );

		return array( $prefix . 'fqj_mappings', $prefix . 'fqj_queue' );
	}
}
