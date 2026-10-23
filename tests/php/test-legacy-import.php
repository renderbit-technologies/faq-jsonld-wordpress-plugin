<?php
/**
 * Importing a site's data from the earlier FAQ JSON-LD Manager plugin (slug faq-jsonld).
 *
 * @package Renderbit_FAQ_Structured_Data
 */

/**
 * Legacy import tests.
 */
class Test_RFSD_Legacy_Import extends RFSD_TestCase {

	/**
	 * Legacy names, as the old plugin stored them.
	 *
	 * @var array
	 */
	private $legacy;

	public function set_up() {
		parent::set_up();
		$this->legacy = rfsd_legacy_storage_names();
		delete_option( RFSD_LEGACY_IMPORT_OPTION );
	}

	public function tear_down() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->legacy['mappings_table'] ) );
		parent::tear_down();
	}

	/**
	 * Create the old plugin's mapping table with the given rows.
	 *
	 * @param array $rows Arrays of faq_id, mapping_type, mapping_value.
	 */
	private function create_legacy_table( $rows ) {
		global $wpdb;
		$table = $this->legacy['mappings_table'];
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				faq_id bigint(20) unsigned NOT NULL,
				mapping_type VARCHAR(32) NOT NULL,
				mapping_value TEXT NOT NULL,
				PRIMARY KEY  (id)
			)"
		);
		foreach ( $rows as $row ) {
			$wpdb->insert( $table, array_combine( array( 'faq_id', 'mapping_type', 'mapping_value' ), $row ) );
		}
		// phpcs:enable
	}

	/**
	 * Create a FAQ the way the old plugin saved it.
	 *
	 * @param array $payload Association payload.
	 * @param array $args    Extra post fields.
	 * @return int FAQ ID.
	 */
	private function create_legacy_faq( $payload = array(), $args = array() ) {
		$faq = self::factory()->post->create(
			array_merge(
				array(
					'post_type'    => $this->legacy['post_type'],
					'post_status'  => 'publish',
					'post_title'   => 'Legacy?',
					'post_content' => 'Yes.',
				),
				$args
			)
		);
		update_post_meta( $faq, 'fqj_assoc_data_json', wp_slash( wp_json_encode( $payload ) ) );
		update_post_meta( $faq, 'fqj_assoc_type', 'urls' );

		return $faq;
	}

	/**
	 * Number of rows still stored under the old plugin's names, which its uninstall would delete.
	 *
	 * @return int
	 */
	private function legacy_rows_left() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$posts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", $this->legacy['post_type'] ) );
		$meta  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN ('fqj_assoc_data_json', 'fqj_assoc_type')" );
		// phpcs:enable

		return $posts + $meta;
	}

	public function test_fresh_site_only_records_the_import() {
		$data_version = get_option( RFSD_DATA_VERSION_OPTION );

		rfsd_import_legacy_data();

		$this->assertSame( '1', get_option( RFSD_LEGACY_IMPORT_OPTION ) );
		$this->assertSame( $data_version, get_option( RFSD_DATA_VERSION_OPTION ) );
		$this->assertFalse( rfsd_has_faq_items() );
	}

	public function test_import_from_data_version_2_keeps_url_associations() {
		$post      = self::factory()->post->create( array( 'post_name' => 'hello' ) );
		$shortlink = home_url( '/?p=' . $post );
		$draft     = $this->create_legacy_faq( array(), array( 'post_status' => 'draft' ) );
		$faq       = $this->create_legacy_faq( array( 'urls' => array( $shortlink ) ) );
		// Version 2 indexing: the absolute URL plus the post it resolved to.
		$this->create_legacy_table(
			array(
				array( $faq, 'url', $shortlink ),
				array( $faq, 'post', (string) $post ),
			)
		);
		delete_option( RFSD_OPTION_KEY );
		delete_option( RFSD_DATA_VERSION_OPTION );
		update_option( 'fqj_settings', array( 'cache_ttl' => 60 ) );
		update_option( 'fqj_data_version', '2' );
		wp_schedule_event( time() + 60, 'hourly', 'fqj_process_invalidation_queue' );
		// Prime the caches with the old post type.
		$this->assertSame( $this->legacy['post_type'], get_post( $faq )->post_type );

		rfsd_import_legacy_data();

		$this->assertSame( RFSD_POST_TYPE, get_post( $faq )->post_type );
		$this->assertSame( RFSD_POST_TYPE, get_post( $draft )->post_type );
		$this->assertSame( 'urls', get_post_meta( $faq, 'rfsd_assoc_type', true ) );
		$this->assertSame( 0, $this->legacy_rows_left(), 'Nothing is left for the old plugin to print or uninstall.' );
		$this->assertSame( array( 'cache_ttl' => 60 ), get_option( RFSD_OPTION_KEY ) );
		$this->assertSame( '2', get_option( RFSD_DATA_VERSION_OPTION ) );
		$this->assertCount( 2, $this->get_mappings( $faq ) );
		$this->assertFalse( wp_next_scheduled( 'fqj_process_invalidation_queue' ) );

		rfsd_run_data_migration();

		$this->assertSame( RFSD_DATA_VERSION, get_option( RFSD_DATA_VERSION_OPTION ) );
		$this->assertSame( array( get_permalink( $post ) ), json_decode( get_post_meta( $faq, 'rfsd_assoc_data_json', true ), true )['urls'] );
		$this->assertSame( array( $faq ), rfsd_faq_ids_for_post( $post ) );
		$this->assertStringContainsString( 'Legacy?', rfsd_render_faq_jsonld( $post ) );
	}

	public function test_import_without_the_old_table_rebuilds_the_index() {
		$post = self::factory()->post->create();
		$faq  = $this->create_legacy_faq( array( 'posts' => array( $post ) ) );
		delete_option( RFSD_DATA_VERSION_OPTION );
		update_option( 'fqj_data_version', '3' );

		rfsd_import_legacy_data();

		// No copied rows, so the data version stays unset and the data migration reindexes every FAQ.
		$this->assertFalse( get_option( RFSD_DATA_VERSION_OPTION ) );
		rfsd_run_data_migration();
		$this->assertSame( array( $faq ), rfsd_faq_ids_for_post( $post ) );
	}

	public function test_import_keeps_this_plugins_own_settings() {
		update_option( RFSD_OPTION_KEY, array( 'cache_ttl' => 120 ) );
		update_option( 'fqj_settings', array( 'cache_ttl' => 60 ) );

		rfsd_import_legacy_data();

		$this->assertSame( array( 'cache_ttl' => 120 ), get_option( RFSD_OPTION_KEY ) );
	}

	public function test_import_runs_once_per_site() {
		rfsd_import_legacy_data();

		// FAQs the old plugin creates later (while it is still active) are its own.
		$late = $this->create_legacy_faq();
		rfsd_import_legacy_data();
		$this->assertSame( $this->legacy['post_type'], get_post( $late )->post_type );
	}

	public function test_import_runs_before_the_schema_upgrade_and_data_migration() {
		$import = has_action( 'plugins_loaded', 'rfsd_import_legacy_data' );
		$this->assertNotFalse( $import );
		$this->assertLessThan( has_action( 'plugins_loaded', 'rfsd_maybe_upgrade_db' ), $import );
		$this->assertLessThan( has_action( 'plugins_loaded', 'rfsd_maybe_schedule_data_migration' ), $import );
	}

	public function test_activation_imports_before_checking_for_faqs() {
		$faq = $this->create_legacy_faq();
		delete_option( RFSD_DATA_VERSION_OPTION );

		rfsd_activate();

		$this->assertSame( RFSD_POST_TYPE, get_post( $faq )->post_type );
		// The site has FAQs, so the data migration still has to run on it.
		$this->assertFalse( get_option( RFSD_DATA_VERSION_OPTION ) );
	}
}
