<?php
/**
 * Meta box, save handler and admin assets.
 *
 * @package FAQ_JSON_LD
 */

/**
 * Admin tests.
 */
class Test_FQJ_Admin extends FQJ_TestCase {

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	protected $admin_id;

	public function set_up() {
		parent::set_up();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	public function tear_down() {
		wp_dequeue_script( 'fqj-admin-js' );
		wp_dequeue_script( 'fqj-select2-js' );
		wp_dequeue_style( 'fqj-select2-css' );
		parent::tear_down();
	}

	/**
	 * Submit the meta box for a FAQ via save_post.
	 *
	 * @param int   $faq_id FAQ ID.
	 * @param array $fields POST fields.
	 */
	protected function submit( $faq_id, $fields ) {
		$_POST = array_merge(
			array( 'fqj_meta_nonce' => wp_create_nonce( 'fqj_save_meta' ) ),
			$fields
		);
		fqj_save_meta_handler( $faq_id, get_post( $faq_id ) );
	}

	/**
	 * Saved payload.
	 *
	 * @param int $faq_id FAQ ID.
	 * @return array
	 */
	protected function payload( $faq_id ) {
		return json_decode( get_post_meta( $faq_id, 'fqj_assoc_data_json', true ), true );
	}

	public function test_meta_box_is_registered_for_faq_items() {
		global $wp_meta_boxes;
		fqj_add_meta_boxes();
		$this->assertArrayHasKey( 'fqj_assoc_rules', $wp_meta_boxes['faq_item']['normal']['default'] );
	}

	public function test_save_urls() {
		$faq = $this->create_faq();
		$this->submit(
			$faq,
			array(
				'fqj_assoc_type' => 'urls',
				'fqj_assoc_urls' => "https://example.org/a/\r\n\r\n  https://example.org/b  \nhttps://example.org/a/\njavascript:alert(1)",
			)
		);

		$this->assertSame( 'urls', get_post_meta( $faq, 'fqj_assoc_type', true ) );
		$urls = $this->payload( $faq )['urls'];
		$this->assertSame( array( 'https://example.org/a/', 'https://example.org/b' ), array_slice( $urls, 0, 2 ), 'Trimmed, blank lines skipped, deduped.' );
		$this->assertSame( array( 'https://example.org/a/', 'https://example.org/b' ), $urls, 'Rejected URLs are dropped, not stored as empty strings.' );

		$values = wp_list_pluck( $this->get_mappings( $faq ), 'mapping_value' );
		$this->assertContains( 'https://example.org/a', $values );
		$this->assertContains( 'https://example.org/b', $values );
		$this->assertNotContains( '', $values );
	}

	public function test_rejected_urls_show_admin_notice_once() {
		$faq = $this->create_faq();
		$this->submit(
			$faq,
			array(
				'fqj_assoc_type' => 'urls',
				'fqj_assoc_urls' => "https://example.org/a\njavascript:alert(1)",
			)
		);

		$_GET['post'] = $faq;
		ob_start();
		fqj_rejected_urls_notice();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'javascript:alert(1)', $html );
		$this->assertStringNotContainsString( 'example.org/a', $html );

		ob_start();
		fqj_rejected_urls_notice();
		$this->assertSame( '', ob_get_clean(), 'Notice shows once.' );
		unset( $_GET['post'] );
	}

	public function test_clean_save_clears_stale_rejected_url_notice() {
		$faq = $this->create_faq();
		$this->submit(
			$faq,
			array(
				'fqj_assoc_type' => 'urls',
				'fqj_assoc_urls' => 'javascript:alert(1)',
			)
		);
		$this->submit( $faq, array( 'fqj_assoc_type' => 'global' ) );

		$_GET['post'] = $faq;
		ob_start();
		fqj_rejected_urls_notice();
		$this->assertSame( '', ob_get_clean() );
		unset( $_GET['post'] );
	}

	public function test_save_posts() {
		$a   = self::factory()->post->create();
		$faq = $this->create_faq();
		$this->submit(
			$faq,
			array(
				'fqj_assoc_type'         => 'posts',
				'fqj_assoc_posts_select' => array( (string) $a, (string) $a, 'abc' ),
			)
		);

		$this->assertSame( array( 'posts' => array( $a, 0 ) ), $this->payload( $faq ) );
		$this->assertContains(
			array(
				'mapping_type'  => 'post',
				'mapping_value' => (string) $a,
			),
			$this->get_mappings( $faq )
		);
	}

	public function test_save_posts_without_selection() {
		$faq = $this->create_faq();
		$this->submit( $faq, array( 'fqj_assoc_type' => 'posts' ) );
		$this->assertSame( array( 'posts' => array() ), $this->payload( $faq ) );
	}

	public function test_save_post_types() {
		$faq = $this->create_faq();
		$this->submit(
			$faq,
			array(
				'fqj_assoc_type'       => 'post_types',
				'fqj_assoc_post_types' => array( 'page', '<b>post</b>', 'page' ),
			)
		);
		$this->assertSame( array( 'post_types' => array( 'page', 'post' ) ), $this->payload( $faq ) );
	}

	public function test_save_terms() {
		$term = self::factory()->category->create();
		$faq  = $this->create_faq();
		$this->submit(
			$faq,
			array(
				'fqj_assoc_type'         => 'tax_terms',
				'fqj_assoc_terms_select' => array( (string) $term ),
			)
		);
		$this->assertSame( array( 'terms' => array( $term ) ), $this->payload( $faq ) );
	}

	public function test_save_global() {
		$faq = $this->create_faq();
		$this->submit( $faq, array( 'fqj_assoc_type' => 'global' ) );
		$this->assertSame( array( 'global' => true ), $this->payload( $faq ) );
		$this->assertSame(
			array(
				array(
					'mapping_type'  => 'global',
					'mapping_value' => '1',
				),
			),
			$this->get_mappings( $faq )
		);
	}

	public function test_save_defaults_to_urls_type() {
		$faq = $this->create_faq();
		$this->submit( $faq, array() );
		$this->assertSame( 'urls', get_post_meta( $faq, 'fqj_assoc_type', true ) );
		$this->assertSame( array( 'urls' => array() ), $this->payload( $faq ) );
	}

	public function test_save_unknown_type_leaves_existing_data_untouched() {
		$faq = $this->create_faq( 'Q', 'A', array( 'global' => true ) );
		$before_meta     = get_post_meta( $faq, 'fqj_assoc_data_json', true );
		$before_mappings = $this->get_mappings( $faq );
		$this->assertNotEmpty( $before_mappings );

		$this->submit( $faq, array( 'fqj_assoc_type' => 'bogus' ) );

		$this->assertSame( $before_meta, get_post_meta( $faq, 'fqj_assoc_data_json', true ) );
		$this->assertSame( $before_mappings, $this->get_mappings( $faq ) );
	}

	public function test_save_switching_type_drops_old_mappings() {
		$post = self::factory()->post->create();
		$faq  = $this->create_faq();
		$this->submit( $faq, array( 'fqj_assoc_type' => 'global' ) );
		$this->submit(
			$faq,
			array(
				'fqj_assoc_type'         => 'posts',
				'fqj_assoc_posts_select' => array( $post ),
			)
		);
		$this->assertSame(
			array(
				array(
					'mapping_type'  => 'post',
					'mapping_value' => (string) $post,
				),
			),
			$this->get_mappings( $faq )
		);
	}

	public function test_save_via_save_post_hook() {
		$faq   = $this->create_faq();
		$_POST = array(
			'fqj_meta_nonce' => wp_create_nonce( 'fqj_save_meta' ),
			'fqj_assoc_type' => 'global',
		);
		wp_update_post(
			array(
				'ID'         => $faq,
				'post_title' => 'Updated',
			)
		);
		$this->assertSame( 'global', get_post_meta( $faq, 'fqj_assoc_type', true ) );
	}

	public function test_save_ignored_without_nonce() {
		$faq   = $this->create_faq();
		$_POST = array( 'fqj_assoc_type' => 'global' );
		fqj_save_meta_handler( $faq, get_post( $faq ) );
		$this->assertSame( '', get_post_meta( $faq, 'fqj_assoc_type', true ) );
	}

	public function test_save_ignored_with_bad_nonce() {
		$faq   = $this->create_faq();
		$_POST = array(
			'fqj_meta_nonce' => 'nope',
			'fqj_assoc_type' => 'global',
		);
		fqj_save_meta_handler( $faq, get_post( $faq ) );
		$this->assertSame( '', get_post_meta( $faq, 'fqj_assoc_type', true ) );
	}

	public function test_save_ignored_for_other_post_types() {
		$post = self::factory()->post->create();
		$this->submit( $post, array( 'fqj_assoc_type' => 'global' ) );
		$this->assertSame( '', get_post_meta( $post, 'fqj_assoc_type', true ) );
	}

	public function test_save_ignored_without_capability() {
		$faq = $this->create_faq();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->submit( $faq, array( 'fqj_assoc_type' => 'global' ) );
		$this->assertSame( '', get_post_meta( $faq, 'fqj_assoc_type', true ) );
		$this->assertSame( array(), $this->get_mappings( $faq ) );
	}

	/**
	 * Render the meta box and return its markup.
	 *
	 * @param int $faq_id FAQ ID.
	 * @return string
	 */
	protected function render_meta_box( $faq_id ) {
		ob_start();
		fqj_assoc_rules_meta_box_cb( get_post( $faq_id ) );

		return ob_get_clean();
	}

	/**
	 * Decoded value of the hidden fqj_assoc_data field.
	 *
	 * @param string $html Meta box markup.
	 * @return mixed
	 */
	protected function hidden_data( $html ) {
		preg_match( '/id="fqj_assoc_data" name="fqj_assoc_data" value="([^"]*)"/', $html, $m );

		return json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );
	}

	public function test_meta_box_defaults() {
		$html = $this->render_meta_box( $this->create_faq() );

		$this->assertStringContainsString( 'name="fqj_meta_nonce"', $html );
		$this->assertMatchesRegularExpression( '/<option value="urls" selected=\'selected\'>/', $html );
		foreach ( array( 'urls', 'posts', 'post_types', 'tax_terms', 'global' ) as $type ) {
			$this->assertStringContainsString( 'value="' . $type . '"', $html );
		}
		$this->assertStringContainsString( 'value="{}"', $html );
		$this->assertStringContainsString( 'id="fqj_assoc_container"', $html );
	}

	public function test_meta_box_selects_saved_type() {
		$faq = $this->create_faq();
		update_post_meta( $faq, 'fqj_assoc_type', 'global' );
		$this->assertMatchesRegularExpression( '/<option value="global" selected=\'selected\'>/', $this->render_meta_box( $faq ) );
	}

	public function test_meta_box_hydrates_posts_including_drafts_and_missing() {
		$live  = self::factory()->post->create(
			array(
				'post_title' => 'Live Post',
				'post_name'  => 'live-post',
			)
		);
		$draft = self::factory()->post->create(
			array(
				'post_title'  => 'Draft Post',
				'post_status' => 'draft',
			)
		);
		$faq   = $this->create_faq( 'Q', 'A', array( 'posts' => array( $live, $draft, 999999 ) ) );

		$data = $this->hidden_data( $this->render_meta_box( $faq ) );

		$this->assertSame( $live, $data['posts'][0]['id'] );
		$this->assertSame( 'Live Post – http://example.org/live-post/', $data['posts'][0]['text'] );
		$this->assertSame( $draft, $data['posts'][1]['id'] );
		$this->assertStringEndsWith( '(draft)', $data['posts'][1]['text'] );
		$this->assertSame(
			array(
				'id'   => 999999,
				'text' => 'Post #999999 (not found)',
			),
			$data['posts'][2]
		);
	}

	public function test_meta_box_hydrates_terms() {
		$term = self::factory()->category->create( array( 'name' => 'Billing' ) );
		$faq  = $this->create_faq( 'Q', 'A', array( 'terms' => array( $term, 888888 ) ) );

		$data = $this->hidden_data( $this->render_meta_box( $faq ) );
		$this->assertSame(
			array(
				array(
					'id'   => $term,
					'text' => 'Billing (category)',
				),
				array(
					'id'   => 888888,
					'text' => 'Term #888888 (not found)',
				),
			),
			$data['terms']
		);
	}

	public function test_meta_box_passes_other_payloads_through() {
		$faq  = $this->create_faq( 'Q', 'A', array( 'urls' => array( 'https://example.org/x' ) ) );
		$data = $this->hidden_data( $this->render_meta_box( $faq ) );
		$this->assertSame( array( 'urls' => array( 'https://example.org/x' ) ), $data );
	}

	public function test_meta_box_handles_corrupt_json() {
		$faq = $this->create_faq();
		update_post_meta( $faq, 'fqj_assoc_data_json', 'not json' );
		$this->assertStringContainsString( 'value="{}"', $this->render_meta_box( $faq ) );
	}

	public function test_admin_assets_load_on_faq_edit_screen() {
		$GLOBALS['post'] = get_post( $this->create_faq() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		fqj_admin_assets( 'post.php' );

		$this->assertTrue( wp_script_is( 'fqj-admin-js', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'fqj-select2-js', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'fqj-select2-css', 'enqueued' ) );

		$this->assertStringStartsWith( FQJ_PLUGIN_URL, wp_scripts()->registered['fqj-select2-js']->src );
		$this->assertStringStartsWith( FQJ_PLUGIN_URL, wp_styles()->registered['fqj-select2-css']->src );
		$this->assertStringNotContainsString( 'cdnjs', wp_scripts()->registered['fqj-select2-js']->src );

		$data = wp_scripts()->get_data( 'fqj-admin-js', 'data' );
		$this->assertStringContainsString( 'var fqjAdmin', $data );
		$this->assertStringContainsString( 'admin-ajax.php', $data );
	}

	public function test_admin_assets_reuse_registered_select2() {
		// Earlier tests leave these handles registered with the bundled copy as a dependency.
		foreach ( array( 'fqj-admin-js', 'fqj-select2-js' ) as $handle ) {
			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
		}
		wp_register_script( 'select2', 'https://example.org/select2.js', array( 'jquery' ), '4', true );
		$GLOBALS['post'] = get_post( $this->create_faq() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		fqj_admin_assets( 'post.php' );
		wp_deregister_script( 'select2' );

		$this->assertFalse( wp_script_is( 'fqj-select2-js', 'enqueued' ) );
		$this->assertContains( 'select2', wp_scripts()->registered['fqj-admin-js']->deps );
	}

	public function test_admin_assets_skip_other_screens_and_types() {
		$GLOBALS['post'] = get_post( $this->create_faq() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		fqj_admin_assets( 'edit.php' );
		$this->assertFalse( wp_script_is( 'fqj-admin-js', 'enqueued' ) );

		$GLOBALS['post'] = get_post( self::factory()->post->create() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		fqj_admin_assets( 'post-new.php' );
		$this->assertFalse( wp_script_is( 'fqj-admin-js', 'enqueued' ) );

		unset( $GLOBALS['post'] );
		fqj_admin_assets( 'post.php' );
		$this->assertFalse( wp_script_is( 'fqj-admin-js', 'enqueued' ) );
	}
}
