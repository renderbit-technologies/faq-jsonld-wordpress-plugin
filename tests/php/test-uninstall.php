<?php
/**
 * Uninstall cleanup.
 *
 * @package FAQ_JSON_LD
 *
 * @group uninstall
 */

/**
 * Uninstall tests.
 */
class Test_FQJ_Uninstall extends FQJ_TestCase {

	/**
	 * The test suite rewrites CREATE/DROP TABLE to temporary tables; uninstall must hit the real one.
	 */
	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	/**
	 * Recreate the real mapping table for the tests that follow.
	 */
	public function tear_down() {
		fqj_create_table();
		parent::tear_down();
	}

	public function test_uninstall_removes_options_table_and_transients() {
		global $wpdb;

		update_option( FQJ_OPTION_KEY, array( 'cache_ttl' => 60 ) );
		set_transient( 'fqj_faq_json_1', 'x', 600 );
		set_transient( 'keep_me', 'y' );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'faq-jsonld/faq-jsonld.php' );
		}
		include FQJ_PLUGIN_DIR . 'uninstall.php';

		$this->assertFalse( get_option( FQJ_OPTION_KEY ) );
		$this->assertFalse( get_transient( 'fqj_faq_json_1' ) );
		$this->assertFalse( get_option( '_transient_timeout_fqj_faq_json_1' ) );
		$this->assertSame( 'y', get_transient( 'keep_me' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', FQJ_DB_TABLE ) ) );
	}
}
