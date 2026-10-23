<?php
/**
 * Renaming FAQ items stored under a legacy post type (faq_item) to RFSD_POST_TYPE.
 *
 * @package Renderbit_FAQ_Structured_Data
 */

/**
 * Post type migration tests.
 */
class Test_RFSD_Post_Type_Migration extends RFSD_TestCase {

	/**
	 * Create a FAQ stored under the legacy post type, as an older version saved it.
	 *
	 * @param array $payload Association payload stored as JSON meta.
	 * @param array $args    Extra post fields.
	 * @return int FAQ ID.
	 */
	protected function create_legacy_faq( $payload = null, $args = array() ) {
		$faq = self::factory()->post->create(
			array_merge(
				array(
					'post_type'    => 'faq_item',
					'post_status'  => 'publish',
					'post_title'   => 'Legacy?',
					'post_content' => 'Yes.',
				),
				$args
			)
		);
		if ( null !== $payload ) {
			update_post_meta( $faq, 'rfsd_assoc_data_json', wp_json_encode( $payload ) );
		}
		delete_option( RFSD_POST_TYPE_OPTION );

		return $faq;
	}

	public function test_legacy_post_type_is_not_the_current_one() {
		$this->assertSame( 'rfsd_faq', RFSD_POST_TYPE );
		$this->assertContains( 'faq_item', RFSD_LEGACY_POST_TYPES );
		$this->assertNotContains( RFSD_POST_TYPE, RFSD_LEGACY_POST_TYPES );
		$this->assertFalse( post_type_exists( 'faq_item' ) );
	}

	public function test_migration_renames_legacy_faqs_in_every_status() {
		$published = $this->create_legacy_faq();
		$draft     = $this->create_legacy_faq( null, array( 'post_status' => 'draft' ) );
		$trashed   = $this->create_legacy_faq( null, array( 'post_status' => 'trash' ) );
		$post      = self::factory()->post->create();
		// Prime the object cache with the old post type.
		$this->assertSame( 'faq_item', get_post( $published )->post_type );

		rfsd_migrate_legacy_post_types();

		foreach ( array( $published, $draft, $trashed ) as $id ) {
			$this->assertSame( RFSD_POST_TYPE, get_post( $id )->post_type, "post {$id}" );
		}
		$this->assertSame( 'post', get_post( $post )->post_type );
		$this->assertSame( RFSD_POST_TYPE, get_option( RFSD_POST_TYPE_OPTION ) );
	}

	public function test_migration_runs_once_per_site() {
		rfsd_migrate_legacy_post_types();
		$this->assertSame( RFSD_POST_TYPE, get_option( RFSD_POST_TYPE_OPTION ) );

		// A legacy row added after the migration (e.g. by an import) is left alone; the option short-circuits.
		$late = self::factory()->post->create( array( 'post_type' => 'faq_item' ) );
		rfsd_migrate_legacy_post_types();
		$this->assertSame( 'faq_item', get_post( $late )->post_type );
	}

	public function test_migration_runs_before_the_schema_upgrade_and_data_migration() {
		$migrate = has_action( 'plugins_loaded', 'rfsd_migrate_legacy_post_types' );
		$this->assertNotFalse( $migrate );
		$this->assertLessThan( has_action( 'plugins_loaded', 'rfsd_maybe_upgrade_db' ), $migrate );
		$this->assertLessThan( has_action( 'plugins_loaded', 'rfsd_maybe_schedule_data_migration' ), $migrate );
	}

	public function test_upgrade_from_data_version_2_reindexes_renamed_faqs() {
		$post = self::factory()->post->create();
		$faq  = $this->create_legacy_faq( array( 'posts' => array( $post ) ) );
		update_option( RFSD_DATA_VERSION_OPTION, '2' );

		rfsd_migrate_legacy_post_types();
		rfsd_run_data_migration();

		$this->assertSame( RFSD_DATA_VERSION, get_option( RFSD_DATA_VERSION_OPTION ) );
		$this->assertSame( array( $faq ), rfsd_faq_ids_for_post( $post ) );
		$this->assertStringContainsString( 'Legacy?', rfsd_render_faq_jsonld( $post ) );
	}

	public function test_activation_renames_legacy_faqs_before_checking_for_faqs() {
		$faq = $this->create_legacy_faq();
		delete_option( RFSD_DATA_VERSION_OPTION );

		rfsd_activate();

		$this->assertSame( RFSD_POST_TYPE, get_post( $faq )->post_type );
		// The site has FAQs, so the data migration still has to run on it.
		$this->assertFalse( get_option( RFSD_DATA_VERSION_OPTION ) );
	}
}
