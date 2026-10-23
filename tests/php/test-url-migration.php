<?php
/**
 * Data migration 3: URL associations that only matched through the old `post` row.
 *
 * @package FAQ_JSON_LD
 */

/**
 * URL migration tests.
 */
class Test_FQJ_URL_Migration extends FQJ_TestCase {

	/**
	 * Create a FAQ saved before data version 3: its URLs plus the absolute `url` rows and the `post` rows
	 * the old indexer wrote.
	 *
	 * @param string[] $urls     Saved URLs.
	 * @param int[]    $post_ids Posts the old indexer resolved the URLs to.
	 * @param array    $extra    Other payload keys.
	 * @return int FAQ ID.
	 */
	protected function create_legacy_faq( $urls, $post_ids, $extra = array() ) {
		$faq = $this->create_faq( 'Legacy?', 'Yes.' );
		update_post_meta( $faq, 'fqj_assoc_data_json', wp_json_encode( array_merge( $extra, array( 'urls' => $urls ) ) ) );

		$rows = array();
		foreach ( $urls as $url ) {
			$rows[] = array(
				'type'  => 'url',
				'value' => untrailingslashit( strtok( $url, '#' ) ),
			);
		}
		foreach ( $post_ids as $post_id ) {
			$rows[] = array(
				'type'  => 'post',
				'value' => $post_id,
			);
		}
		fqj_insert_mappings( $faq, $rows );

		return $faq;
	}

	/**
	 * Run the migration from data version 2 to completion.
	 */
	protected function migrate() {
		update_option( FQJ_DATA_VERSION_OPTION, '2' );
		fqj_run_data_migration();
		$this->assertSame( FQJ_DATA_VERSION, get_option( FQJ_DATA_VERSION_OPTION ) );
	}

	/**
	 * Saved URLs of a FAQ.
	 *
	 * @param int $faq_id FAQ ID.
	 * @return string[]
	 */
	protected function saved_urls( $faq_id ) {
		$payload = json_decode( get_post_meta( $faq_id, 'fqj_assoc_data_json', true ), true );

		return $payload['urls'];
	}

	/**
	 * The migration report.
	 *
	 * @return array
	 */
	protected function report() {
		return get_option( FQJ_URL_MIGRATION_REPORT_OPTION, array() );
	}

	public function test_shortlink_is_rewritten_to_permalink() {
		$post      = self::factory()->post->create( array( 'post_name' => 'hello' ) );
		$shortlink = home_url( '/?p=' . $post );
		$faq       = $this->create_legacy_faq( array( $shortlink ), array( $post ) );

		$this->migrate();

		$this->assertSame( array( get_permalink( $post ) ), $this->saved_urls( $faq ) );
		$this->assertSame( array( $faq ), fqj_faq_ids_for_post( $post ) );
		$this->assertSame(
			array(
				array(
					'faq_id' => $faq,
					'old'    => $shortlink,
					'new'    => get_permalink( $post ),
				),
			),
			$this->report()
		);
		$this->assertNotContains( 'post', wp_list_pluck( $this->get_mappings( $faq ), 'mapping_type' ) );
	}

	public function test_front_page_slug_url_is_rewritten_to_home() {
		$page = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'welcome',
			)
		);
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page );
		$slug_url = home_url( '/welcome/' );
		$faq      = $this->create_legacy_faq( array( $slug_url ), array( $page ) );

		$this->assertSame( 0, fqj_post_for_url( $slug_url ) );
		$this->migrate();

		$this->assertSame( array( home_url( '/' ) ), $this->saved_urls( $faq ) );
		$this->assertSame( array( $faq ), fqj_faq_ids_for_post( $page ) );
	}

	public function test_renamed_post_replaces_the_single_dead_url() {
		$post = self::factory()->post->create( array( 'post_name' => 'new-name' ) );
		$dead = home_url( '/old-name/' );
		$live = self::factory()->post->create( array( 'post_name' => 'still-here' ) );
		$faq  = $this->create_legacy_faq( array( $dead, get_permalink( $live ) ), array( $post, $live ) );

		$this->migrate();

		$this->assertSame( array( get_permalink( $post ), get_permalink( $live ) ), $this->saved_urls( $faq ) );
		$this->assertSame( array( $faq ), fqj_faq_ids_for_post( $post ) );
		$this->assertSame( array( $faq ), fqj_faq_ids_for_post( $live ) );
		$this->assertSame( $dead, $this->report()[0]['old'] );
		$this->assertSame( get_permalink( $post ), $this->report()[0]['new'] );
	}

	public function test_ambiguous_renames_keep_dead_urls_and_add_permalinks() {
		$a     = self::factory()->post->create( array( 'post_name' => 'a-new' ) );
		$b     = self::factory()->post->create( array( 'post_name' => 'b-new' ) );
		$dead1 = home_url( '/a-old/' );
		$dead2 = home_url( '/b-old/' );
		$faq   = $this->create_legacy_faq( array( $dead1, $dead2 ), array( $a, $b ) );

		$this->migrate();

		$this->assertSame( array( $dead1, $dead2, get_permalink( $a ), get_permalink( $b ) ), $this->saved_urls( $faq ) );
		$this->assertSame( array( $faq ), fqj_faq_ids_for_post( $a ) );
		$this->assertSame( array( $faq ), fqj_faq_ids_for_post( $b ) );
		$this->assertSame( array( '', '' ), wp_list_pluck( $this->report(), 'old' ) );
	}

	public function test_external_and_matching_urls_are_left_alone() {
		$post     = self::factory()->post->create( array( 'post_name' => 'fine' ) );
		$external = 'https://external.example.net/page/';
		$faq      = $this->create_legacy_faq( array( $external, get_permalink( $post ) ), array( $post ) );

		$this->migrate();

		$this->assertSame( array( $external, get_permalink( $post ) ), $this->saved_urls( $faq ) );
		$this->assertSame( array(), $this->report() );
	}

	public function test_explicit_post_associations_are_not_mistaken_for_url_rows() {
		$post = self::factory()->post->create();
		$dead = home_url( '/gone/' );
		$faq  = $this->create_legacy_faq( array( $dead ), array( $post ), array( 'posts' => array( $post ) ) );

		$this->migrate();

		$this->assertSame( array( $dead ), $this->saved_urls( $faq ) );
		$this->assertSame( array(), $this->report() );
	}

	public function test_deleted_posts_are_not_added() {
		$post = self::factory()->post->create( array( 'post_name' => 'doomed' ) );
		$dead = home_url( '/doomed/' );
		$faq  = $this->create_legacy_faq( array( $dead ), array( $post ) );
		wp_delete_post( $post, true );

		$this->migrate();

		$this->assertSame( array( $dead ), $this->saved_urls( $faq ) );
		$this->assertSame( array(), $this->report() );
	}

	public function test_url_routing_to_a_post_it_never_covered_is_not_rewritten() {
		$post      = self::factory()->post->create( array( 'post_name' => 'newcomer' ) );
		$shortlink = home_url( '/?p=' . $post );
		// Saved before the post existed, so the old indexer wrote no post row for it.
		$faq = $this->create_legacy_faq( array( $shortlink ), array() );

		$this->migrate();

		$this->assertSame( array( $shortlink ), $this->saved_urls( $faq ) );
		$this->assertSame( array(), $this->report() );
		$this->assertSame( array(), fqj_faq_ids_for_post( $post ) );
	}

	public function test_plain_permalinks_keep_query_urls_and_rewrite_old_pretty_ones() {
		$post   = self::factory()->post->create( array( 'post_name' => 'plain' ) );
		$pretty = get_permalink( $post );
		$this->set_permalink_structure( '' );
		$plain = get_permalink( $post );
		$faq   = $this->create_legacy_faq( array( $plain ), array( $post ) );
		$old   = $this->create_legacy_faq( array( $pretty ), array( $post ) );

		$this->migrate();

		$this->assertSame( array( $plain ), $this->saved_urls( $faq ) );
		$this->assertSame( array( $plain ), $this->saved_urls( $old ) );
		$this->assertSame( array( $faq, $old ), fqj_faq_ids_for_post( $post ) );
		$this->assertCount( 1, $this->report() );
	}

	public function test_migration_from_current_version_does_not_rewrite() {
		$post = self::factory()->post->create( array( 'post_name' => 'hello' ) );
		$url  = home_url( '/?p=' . $post );
		$faq  = $this->create_legacy_faq( array( $url ), array( $post ) );
		update_option( FQJ_DATA_VERSION_OPTION, '3' );

		fqj_run_data_migration();

		$this->assertSame( array( $url ), $this->saved_urls( $faq ) );
	}

	public function test_report_notice_shows_on_faq_list_until_dismissed() {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$post   = self::factory()->post->create( array( 'post_name' => 'hello' ) );
		$this->create_legacy_faq( array( home_url( '/?p=' . $post ) ), array( $post ) );
		$this->migrate();

		set_current_screen( 'edit-faq_item' );
		wp_set_current_user( $author );
		ob_start();
		fqj_url_migration_report_notice();
		$this->assertSame( '', ob_get_clean(), 'Hidden from users who cannot edit others\' posts.' );

		wp_set_current_user( $editor );
		ob_start();
		fqj_url_migration_report_notice();
		$html = ob_get_clean();
		$this->assertStringContainsString( esc_html( home_url( '/?p=' . $post ) ), $html );
		$this->assertStringContainsString( 'fqj_dismiss_url_migration_report', $html );

		set_current_screen( 'edit-post' );
		ob_start();
		fqj_url_migration_report_notice();
		$this->assertSame( '', ob_get_clean(), 'Only on the FAQ list screen.' );

		wp_set_current_user( $author );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'fqj_dismiss_url_migration_report' );
		try {
			fqj_dismiss_url_migration_report();
			$this->fail( 'Authors cannot dismiss the report.' );
		} catch ( WPDieException $e ) {
			$this->assertNotEmpty( get_option( FQJ_URL_MIGRATION_REPORT_OPTION ) );
		}

		wp_set_current_user( $editor );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'fqj_dismiss_url_migration_report' );
		fqj_dismiss_url_migration_report();
		$this->assertFalse( get_option( FQJ_URL_MIGRATION_REPORT_OPTION ) );
		set_current_screen( 'front' );
	}

	public function test_report_is_not_autoloaded() {
		$post = self::factory()->post->create( array( 'post_name' => 'hello' ) );
		$this->create_legacy_faq( array( home_url( '/?p=' . $post ) ), array( $post ) );
		$this->migrate();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", FQJ_URL_MIGRATION_REPORT_OPTION ) );
		$this->assertContains( $autoload, array( 'no', 'off' ) );
	}
}
