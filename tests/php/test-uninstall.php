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
 *
 * Uninstall must drop the real tables, so the DROP TABLE statements commit the test transaction. Everything a test
 * creates is therefore removed explicitly in tear_down() and committed.
 */
class Test_FQJ_Uninstall extends FQJ_TestCase {

	/**
	 * Posts created by the current test that uninstall keeps.
	 *
	 * @var int[]
	 */
	private $post_ids = array();

	/**
	 * The test suite rewrites CREATE/DROP TABLE to temporary tables; uninstall must hit the real one.
	 */
	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	/**
	 * Remove committed fixtures and restore the plugin data the way the bootstrap set it up.
	 */
	public function tear_down() {
		global $wpdb;

		parent::tear_down();

		fqj_activate();
		foreach ( $this->post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		delete_transient( 'keep_me' );
		$wpdb->query( 'COMMIT' );
		self::flush_cache();
	}

	/**
	 * Run uninstall.php.
	 */
	private function run_uninstall() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'faq-jsonld/faq-jsonld.php' );
		}
		include FQJ_PLUGIN_DIR . 'uninstall.php';
	}

	public function test_uninstall_removes_options_table_and_transients() {
		global $wpdb;

		update_option( FQJ_OPTION_KEY, array( 'cache_ttl' => 60 ) );
		$key = fqj_cache_key( 1 );
		set_transient( $key, 'x', 600 );
		set_transient( 'keep_me', 'y' );

		$this->run_uninstall();

		$this->assertFalse( get_option( FQJ_OPTION_KEY ) );
		$this->assertFalse( get_transient( $key ) );
		$this->assertFalse( get_option( '_transient_timeout_' . $key ) );
		$this->assertFalse( get_option( 'fqj_cache_gen' ) );
		$this->assertSame( 'y', get_transient( 'keep_me' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', fqj_table_name() ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', fqj_queue_table_name() ) ) );
	}

	public function test_uninstall_removes_log_queue_events_and_meta_but_keeps_faqs() {
		update_option( 'fqj_invalidation_log', array( array( 'processed' => 1 ) ) );
		update_option( 'fqj_last_queue_run', time() );
		set_transient( 'fqj_invalidate_queue', array( 1 ) );
		fqj_queue_add_posts( array( 1 ) );
		set_transient( 'fqj_rejected_urls_1_2', array( 'bad' ), 600 );
		wp_schedule_event( time() + 60, 'hourly', 'fqj_process_invalidation_queue' );
		wp_schedule_single_event( time() + 60, 'fqj_data_migration' );
		$faq              = self::factory()->post->create( array( 'post_type' => 'faq_item' ) );
		$this->post_ids[] = $faq;
		update_post_meta( $faq, 'fqj_assoc_type', 'urls' );
		update_post_meta( $faq, 'fqj_assoc_data_json', '{}' );
		update_post_meta( $faq, 'unrelated_meta', 'keep' );

		$this->run_uninstall();

		$this->assertFalse( get_option( 'fqj_invalidation_log' ) );
		$this->assertFalse( get_option( 'fqj_last_queue_run' ) );
		$this->assertFalse( get_transient( 'fqj_invalidate_queue' ) );
		$this->assertFalse( get_transient( 'fqj_rejected_urls_1_2' ) );
		$this->assertFalse( get_option( '_transient_timeout_fqj_rejected_urls_1_2' ) );
		$this->assertFalse( wp_next_scheduled( 'fqj_process_invalidation_queue' ) );
		$this->assertFalse( wp_next_scheduled( 'fqj_data_migration' ) );
		$this->assertNotNull( get_post( $faq ) );
		$this->assertSame( '', get_post_meta( $faq, 'fqj_assoc_type', true ) );
		$this->assertSame( '', get_post_meta( $faq, 'fqj_assoc_data_json', true ) );
		$this->assertSame( 'keep', get_post_meta( $faq, 'unrelated_meta', true ) );
	}

	public function test_uninstall_deletes_faqs_when_filter_allows() {
		$faq   = self::factory()->post->create( array( 'post_type' => 'faq_item' ) );
		$draft = self::factory()->post->create(
			array(
				'post_type'   => 'faq_item',
				'post_status' => 'draft',
			)
		);
		$trashed = self::factory()->post->create(
			array(
				'post_type'   => 'faq_item',
				'post_status' => 'trash',
			)
		);
		$post    = self::factory()->post->create();
		$this->post_ids[] = $post;
		update_post_meta( $faq, 'fqj_assoc_type', 'urls' );
		add_filter( 'fqj_uninstall_delete_faqs', '__return_true' );

		$this->run_uninstall();

		remove_filter( 'fqj_uninstall_delete_faqs', '__return_true' );
		$this->assertNull( get_post( $faq ) );
		$this->assertNull( get_post( $draft ) );
		$this->assertNull( get_post( $trashed ) );
		$this->assertNotNull( get_post( $post ) );
	}
}
