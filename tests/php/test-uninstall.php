<?php
/**
 * Uninstall cleanup.
 *
 * @package Renderbit_FAQ_Structured_Data
 *
 * @group uninstall
 */

/**
 * Uninstall tests.
 *
 * Uninstall must drop the real tables, so the DROP TABLE statements commit the test transaction. Everything a test
 * creates is therefore removed explicitly in tear_down() and committed.
 */
class Test_RFSD_Uninstall extends RFSD_TestCase {

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

		// Delete the kept posts first: activation skips the data version option while FAQs exist.
		foreach ( $this->post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		delete_transient( 'keep_me' );
		// Uninstall clears every site of a network.
		rfsd_activate( true );
		$wpdb->query( 'COMMIT' );
		self::flush_cache();
	}

	/**
	 * Run uninstall.php.
	 */
	private function run_uninstall() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'renderbit-faq-structured-data/renderbit-faq-structured-data.php' );
		}
		include RFSD_PLUGIN_DIR . 'uninstall.php';
	}

	public function test_uninstall_removes_options_table_and_transients() {
		global $wpdb;

		update_option( RFSD_OPTION_KEY, array( 'cache_ttl' => 60 ) );
		update_option( RFSD_LEGACY_IMPORT_OPTION, '1' );
		rfsd_update_option_no_autoload(
			RFSD_URL_MIGRATION_REPORT_OPTION,
			array(
				array(
					'faq_id' => 1,
					'old'    => '',
					'new'    => '/x',
				),
			)
		);
		set_transient( 'rfsd_unmatched_urls_1_2', array( 'x' ), 600 );
		$key = rfsd_cache_key( 1 );
		set_transient( $key, 'x', 600 );
		set_transient( 'keep_me', 'y' );

		$this->run_uninstall();

		$this->assertFalse( get_option( RFSD_OPTION_KEY ) );
		$this->assertFalse( get_transient( $key ) );
		$this->assertFalse( get_option( '_transient_timeout_' . $key ) );
		$this->assertFalse( get_option( 'rfsd_cache_gen' ) );
		$this->assertFalse( get_option( RFSD_URL_MIGRATION_REPORT_OPTION ) );
		$this->assertFalse( get_option( RFSD_LEGACY_IMPORT_OPTION ) );
		$this->assertFalse( get_transient( 'rfsd_unmatched_urls_1_2' ) );
		$this->assertSame( 'y', get_transient( 'keep_me' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', rfsd_table_name() ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', rfsd_queue_table_name() ) ) );
	}

	public function test_uninstall_removes_log_queue_events_and_meta_but_keeps_faqs() {
		update_option( 'rfsd_invalidation_log', array( array( 'processed' => 1 ) ) );
		update_option( 'rfsd_last_queue_run', time() );
		set_transient( 'rfsd_invalidate_queue', array( 1 ) );
		rfsd_queue_add_posts( array( 1 ) );
		set_transient( 'rfsd_rejected_urls_1_2', array( 'bad' ), 600 );
		wp_schedule_event( time() + 60, 'hourly', 'rfsd_process_invalidation_queue' );
		wp_schedule_single_event( time() + 60, 'rfsd_data_migration' );
		$faq              = self::factory()->post->create( array( 'post_type' => 'rfsd_faq' ) );
		$this->post_ids[] = $faq;
		update_post_meta( $faq, 'rfsd_assoc_type', 'urls' );
		update_post_meta( $faq, 'rfsd_assoc_data_json', '{}' );
		update_post_meta( $faq, 'unrelated_meta', 'keep' );

		$this->run_uninstall();

		$this->assertFalse( get_option( 'rfsd_invalidation_log' ) );
		$this->assertFalse( get_option( 'rfsd_last_queue_run' ) );
		$this->assertFalse( get_transient( 'rfsd_invalidate_queue' ) );
		$this->assertFalse( get_transient( 'rfsd_rejected_urls_1_2' ) );
		$this->assertFalse( get_option( '_transient_timeout_rfsd_rejected_urls_1_2' ) );
		$this->assertFalse( wp_next_scheduled( 'rfsd_process_invalidation_queue' ) );
		$this->assertFalse( wp_next_scheduled( 'rfsd_data_migration' ) );
		$this->assertNotNull( get_post( $faq ) );
		$this->assertSame( '', get_post_meta( $faq, 'rfsd_assoc_type', true ) );
		$this->assertSame( '', get_post_meta( $faq, 'rfsd_assoc_data_json', true ) );
		$this->assertSame( 'keep', get_post_meta( $faq, 'unrelated_meta', true ) );
	}

	public function test_uninstall_deletes_faqs_when_filter_allows() {
		$faq              = self::factory()->post->create( array( 'post_type' => 'rfsd_faq' ) );
		$draft            = self::factory()->post->create(
			array(
				'post_type'   => 'rfsd_faq',
				'post_status' => 'draft',
			)
		);
		$trashed          = self::factory()->post->create(
			array(
				'post_type'   => 'rfsd_faq',
				'post_status' => 'trash',
			)
		);
		$legacy           = self::factory()->post->create( array( 'post_type' => 'faq_item' ) );
		$post             = self::factory()->post->create();
		$this->post_ids[] = $post;
		$this->post_ids[] = $legacy;
		update_post_meta( $faq, 'rfsd_assoc_type', 'urls' );
		add_filter( 'rfsd_uninstall_delete_faqs', '__return_true' );

		$this->run_uninstall();

		remove_filter( 'rfsd_uninstall_delete_faqs', '__return_true' );
		$this->assertNull( get_post( $faq ) );
		$this->assertNull( get_post( $draft ) );
		$this->assertNull( get_post( $trashed ) );
		$this->assertNotNull( get_post( $legacy ), 'Posts of the old faq-jsonld plugin are left to that plugin.' );
		$this->assertNotNull( get_post( $post ) );
	}
}
