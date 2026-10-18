<?php
/**
 * Uninstall cleanup across a multisite network.
 *
 * @package FAQ_JSON_LD
 */

/**
 * Multisite uninstall tests.
 *
 * Uninstall must drop the real tables, so the DROP TABLE statements commit the test transaction. Everything a test
 * creates is therefore removed explicitly in tear_down() and committed.
 *
 * @group ms-required
 * @group multisite
 * @group uninstall
 */
class Test_FQJ_Multisite_Uninstall extends FQJ_Multisite_TestCase {

	/**
	 * Sites created by the current test.
	 *
	 * @var int[]
	 */
	private $site_ids = array();

	/**
	 * Main-site posts created by the current test.
	 *
	 * @var int[]
	 */
	private $main_post_ids = array();

	/**
	 * Network-active plugins before the test.
	 *
	 * @var mixed
	 */
	private $sitewide_plugins;

	/**
	 * Remember the network-active plugins, which the test changes and uninstall commits.
	 */
	public function set_up() {
		parent::set_up();
		$this->sitewide_plugins = get_site_option( 'active_sitewide_plugins' );
	}

	/**
	 * Remove committed fixtures and restore the main site's plugin data the way the bootstrap set it up.
	 */
	public function tear_down() {
		global $wpdb;

		parent::tear_down();

		if ( ! is_multisite() ) {
			return;
		}

		update_site_option( 'active_sitewide_plugins', $this->sitewide_plugins );
		foreach ( $this->site_ids as $site_id ) {
			if ( get_site( $site_id ) ) {
				wp_delete_site( $site_id );
			}
		}
		fqj_activate();
		foreach ( $this->main_post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		delete_transient( 'keep_me' );
		$wpdb->query( 'COMMIT' );
		self::flush_cache();
	}

	/**
	 * Create sites with the plugin set up (network-active), each with plugin data and a FAQ.
	 *
	 * @param int $count Number of sites besides the main site.
	 * @return array Site ID => array( 'faq' => FAQ ID, 'post' => regular post ID ).
	 */
	private function create_sites_with_data( $count ) {
		$this->network_activate_plugin();
		$this->site_ids = self::factory()->blog->create_many( $count );

		$data = array();
		foreach ( array_merge( array( get_main_site_id() ), $this->site_ids ) as $site_id ) {
			switch_to_blog( $site_id );
			update_option( 'fqj_invalidation_log', array( array( 'processed' => 1 ) ) );
			update_option( 'fqj_last_queue_run', time() );
			update_option( 'fqj_cache_gen', 3 );
			set_transient( fqj_cache_key( 1 ), 'x', 600 );
			set_transient( 'fqj_rejected_urls_1_2', array( 'bad' ), 600 );
			set_transient( 'fqj_invalidate_queue', array( 1 ) );
			set_transient( 'keep_me', 'y', 600 );
			wp_schedule_single_event( time() + 60, 'fqj_data_migration' );
			fqj_queue_add_posts( array( 7 ) );

			$faq  = $this->create_faq( 'Q', 'A', array( 'global' => true ) );
			$post = self::factory()->post->create();
			update_post_meta( $faq, 'fqj_assoc_type', 'global' );
			update_post_meta( $faq, 'unrelated_meta', 'keep' );
			restore_current_blog();

			if ( get_main_site_id() === $site_id ) {
				$this->main_post_ids = array( $faq, $post );
			}
			$data[ $site_id ] = array(
				'faq'  => $faq,
				'post' => $post,
			);
		}

		return $data;
	}

	/**
	 * Run uninstall.php against the real tables.
	 */
	private function run_uninstall() {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'faq-jsonld/faq-jsonld.php' );
		}
		include FQJ_PLUGIN_DIR . 'uninstall.php';
	}

	public function test_uninstall_cleans_every_site_and_keeps_faqs() {
		$data = $this->create_sites_with_data( 2 );
		foreach ( array_keys( $data ) as $site_id ) {
			foreach ( $this->plugin_tables( $site_id ) as $table ) {
				$this->assertTrue( $this->table_exists( $table ), "{$table} should exist before uninstall" );
			}
		}

		$this->run_uninstall();

		$this->assertSame( get_main_site_id(), get_current_blog_id() );
		foreach ( $data as $site_id => $ids ) {
			foreach ( $this->plugin_tables( $site_id ) as $table ) {
				$this->assertFalse( $this->table_exists( $table ), "{$table} should be dropped" );
			}

			switch_to_blog( $site_id );
			foreach ( array( FQJ_OPTION_KEY, FQJ_DB_VERSION_OPTION, FQJ_DATA_VERSION_OPTION, 'fqj_invalidation_log', 'fqj_last_queue_run', 'fqj_cache_gen' ) as $option ) {
				$this->assertFalse( get_option( $option ), "{$option} on site {$site_id}" );
			}
			$this->assertFalse( get_transient( fqj_cache_key( 1 ) ), "site {$site_id}" );
			$this->assertFalse( get_transient( 'fqj_rejected_urls_1_2' ), "site {$site_id}" );
			$this->assertFalse( get_option( '_transient_timeout_fqj_rejected_urls_1_2' ), "site {$site_id}" );
			$this->assertFalse( get_transient( 'fqj_invalidate_queue' ), "site {$site_id}" );
			$this->assertSame( 'y', get_transient( 'keep_me' ), "site {$site_id}" );
			$this->assertFalse( wp_next_scheduled( 'fqj_process_invalidation_queue' ), "site {$site_id}" );
			$this->assertFalse( wp_next_scheduled( 'fqj_data_migration' ), "site {$site_id}" );
			$this->assertNotNull( get_post( $ids['faq'] ), "site {$site_id}" );
			$this->assertNotNull( get_post( $ids['post'] ), "site {$site_id}" );
			$this->assertSame( '', get_post_meta( $ids['faq'], 'fqj_assoc_type', true ), "site {$site_id}" );
			$this->assertSame( '', get_post_meta( $ids['faq'], 'fqj_assoc_data_json', true ), "site {$site_id}" );
			$this->assertSame( 'keep', get_post_meta( $ids['faq'], 'unrelated_meta', true ), "site {$site_id}" );
			restore_current_blog();
		}
	}

	public function test_uninstall_deletes_faqs_on_every_site_when_filter_allows() {
		$data = $this->create_sites_with_data( 2 );
		add_filter( 'fqj_uninstall_delete_faqs', '__return_true' );

		$this->run_uninstall();

		remove_filter( 'fqj_uninstall_delete_faqs', '__return_true' );
		foreach ( $data as $site_id => $ids ) {
			switch_to_blog( $site_id );
			$this->assertNull( get_post( $ids['faq'] ), "site {$site_id}" );
			$this->assertNotNull( get_post( $ids['post'] ), "site {$site_id}" );
			restore_current_blog();
		}
	}
}
