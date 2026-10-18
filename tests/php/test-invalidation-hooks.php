<?php
/**
 * Invalidation triggered by FAQ lifecycle and post changes.
 *
 * @package FAQ_JSON_LD
 */

/**
 * Invalidation hook tests.
 */
class Test_FQJ_Invalidation_Hooks extends FQJ_TestCase {

	/**
	 * Empty the queue so a test sees only its own entries.
	 */
	private function clear_queue() {
		global $wpdb;
		$table = fqj_queue_table_name();
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
	}

	public function test_get_mappings_for_faq_returns_type_value_rows() {
		$post = self::factory()->post->create();
		$faq  = $this->create_faq(
			'Q',
			'A',
			array(
				'posts'  => array( $post ),
				'global' => true,
			)
		);

		$this->assertSame(
			array(
				array(
					'type'  => 'post',
					'value' => (string) $post,
				),
				array(
					'type'  => 'global',
					'value' => '1',
				),
			),
			fqj_get_mappings_for_faq( $faq )
		);
	}

	public function test_removing_association_enqueues_old_post() {
		$a   = self::factory()->post->create();
		$b   = self::factory()->post->create();
		$faq = $this->create_faq( 'Q', 'A', array( 'posts' => array( $a ) ) );
		$this->clear_queue();

		update_post_meta( $faq, 'fqj_assoc_data_json', wp_json_encode( array( 'posts' => array( $b ) ) ) );
		fqj_rebuild_index_for_faq( $faq );

		$this->assertEqualSets( array( $a, $b ), $this->get_queue() );
	}

	public function test_removing_all_associations_enqueues_old_post() {
		$a   = self::factory()->post->create();
		$faq = $this->create_faq( 'Q', 'A', array( 'posts' => array( $a ) ) );
		$this->clear_queue();

		update_post_meta( $faq, 'fqj_assoc_data_json', wp_json_encode( array() ) );
		fqj_rebuild_index_for_faq( $faq );

		$this->assertSame( array( $a ), $this->get_queue() );
	}

	public function test_removing_term_and_post_type_enqueue_jobs() {
		$term = self::factory()->category->create();
		$faq  = $this->create_faq(
			'Q',
			'A',
			array(
				'terms'      => array( $term ),
				'post_types' => array( 'page' ),
			)
		);
		$this->clear_queue();

		update_post_meta( $faq, 'fqj_assoc_data_json', wp_json_encode( array() ) );
		fqj_rebuild_index_for_faq( $faq );

		$jobs = wp_list_pluck( $this->get_queue_jobs(), 'item_value', 'item_type' );
		$this->assertSame( (string) $term, $jobs['term'] );
		$this->assertSame( 'page', $jobs['post_type'] );
	}

	public function test_removing_global_purges_cache() {
		$post = self::factory()->post->create();
		$faq  = $this->create_faq( 'Q', 'A', array( 'global' => true ) );
		$key  = fqj_cache_key( $post );
		set_transient( $key, 'x' );

		update_post_meta( $faq, 'fqj_assoc_data_json', wp_json_encode( array() ) );
		fqj_rebuild_index_for_faq( $faq );

		$this->assertFalse( get_transient( $key ) );
	}

	public function test_rebuild_without_enqueue_does_not_enqueue() {
		$a   = self::factory()->post->create();
		$faq = $this->create_faq( 'Q', 'A', array( 'posts' => array( $a ) ) );
		$this->clear_queue();

		fqj_rebuild_index_for_faq( $faq, false );
		$this->assertSame( array(), $this->get_queue() );
	}

	public function test_trash_and_untrash_enqueue() {
		$a   = self::factory()->post->create();
		$faq = $this->create_faq( 'Q', 'A', array( 'posts' => array( $a ) ) );

		$this->clear_queue();
		wp_trash_post( $faq );
		$this->assertSame( array( $a ), $this->get_queue() );

		$this->clear_queue();
		wp_untrash_post( $faq );
		$this->assertSame( array( $a ), $this->get_queue() );
	}

	public function test_unpublish_enqueues() {
		$a   = self::factory()->post->create();
		$faq = $this->create_faq( 'Q', 'A', array( 'posts' => array( $a ) ) );
		$this->clear_queue();

		wp_update_post(
			array(
				'ID'          => $faq,
				'post_status' => 'draft',
			)
		);
		$this->assertSame( array( $a ), $this->get_queue() );
	}

	public function test_title_change_without_nonce_enqueues() {
		$a   = self::factory()->post->create();
		$faq = $this->create_faq( 'Q', 'A', array( 'posts' => array( $a ) ) );
		$this->clear_queue();

		wp_update_post(
			array(
				'ID'         => $faq,
				'post_title' => 'Changed',
			)
		);
		$this->assertSame( array( $a ), $this->get_queue() );
	}

	public function test_content_change_enqueues() {
		$a   = self::factory()->post->create();
		$faq = $this->create_faq( 'Q', 'A', array( 'posts' => array( $a ) ) );
		$this->clear_queue();

		wp_update_post(
			array(
				'ID'           => $faq,
				'post_content' => 'New answer',
			)
		);
		$this->assertSame( array( $a ), $this->get_queue() );
	}

	public function test_unchanged_title_and_content_does_not_enqueue() {
		$a   = self::factory()->post->create();
		$faq = $this->create_faq( 'Q', 'A', array( 'posts' => array( $a ) ) );
		$this->clear_queue();

		wp_update_post(
			array(
				'ID'           => $faq,
				'post_excerpt' => 'x',
			)
		);
		$this->assertSame( array(), $this->get_queue() );
	}

	public function test_permanent_delete_enqueues_and_removes_rows() {
		$a   = self::factory()->post->create();
		$faq = $this->create_faq( 'Q', 'A', array( 'posts' => array( $a ) ) );
		$this->clear_queue();

		wp_delete_post( $faq, true );

		$this->assertSame( array( $a ), $this->get_queue() );
		$this->assertSame( array(), $this->get_mappings( $faq ) );
	}

	public function test_non_faq_posts_do_not_touch_queue() {
		$a = self::factory()->post->create();
		$this->clear_queue();

		wp_update_post(
			array(
				'ID'         => $a,
				'post_title' => 'Other',
			)
		);
		wp_trash_post( $a );
		wp_delete_post( $a, true );
		$this->assertSame( array(), $this->get_queue() );
	}

	public function test_adding_term_clears_negative_cache() {
		$cat  = self::factory()->category->create();
		$post = self::factory()->post->create();
		$this->create_faq( 'Q', 'A', array( 'terms' => array( $cat ) ) );

		$this->assertStringContainsString( 'no output', $this->render_head_for( $post ) );

		wp_set_post_categories( $post, array( $cat ) );

		$this->assertNotNull( $this->extract_json_ld( $this->render_head_for( $post ) ) );
	}

	public function test_removing_term_clears_cached_result() {
		$cat  = self::factory()->category->create();
		$post = self::factory()->post->create();
		wp_set_post_categories( $post, array( $cat ) );
		$this->create_faq( 'Q', 'A', array( 'terms' => array( $cat ) ) );

		$this->assertNotNull( $this->extract_json_ld( $this->render_head_for( $post ) ) );

		wp_set_post_categories( $post, array() );

		$this->assertNull( $this->extract_json_ld( $this->render_head_for( $post ) ) );
	}

	public function test_unchanged_terms_keep_cache() {
		$cat  = self::factory()->category->create();
		$post = self::factory()->post->create();
		wp_set_post_categories( $post, array( $cat ) );
		set_transient( fqj_cache_key( $post ), 'x' );

		wp_set_post_categories( $post, array( $cat ) );
		$this->assertSame( 'x', get_transient( fqj_cache_key( $post ) ) );
	}

	public function test_faq_term_changes_do_not_clear_cache() {
		$faq = $this->create_faq();
		set_transient( fqj_cache_key( $faq ), 'x' );
		wp_set_object_terms( $faq, array( 'a' ), 'post_tag' );
		$this->assertSame( 'x', get_transient( fqj_cache_key( $faq ) ) );
	}

	public function test_slug_change_clears_url_mapped_cache() {
		$post = self::factory()->post->create( array( 'post_name' => 'old-slug' ) );
		$this->create_faq( 'Q', 'A', array( 'urls' => array( home_url( '/new-slug/' ) ) ) );

		$this->assertStringContainsString( 'no output', $this->render_head_for( $post ) );

		wp_update_post(
			array(
				'ID'        => $post,
				'post_name' => 'new-slug',
			)
		);

		$this->assertNotNull( $this->extract_json_ld( $this->render_head_for( $post ) ) );
	}

	public function test_status_and_parent_change_clear_cache() {
		$post   = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$parent = self::factory()->post->create( array( 'post_type' => 'page' ) );

		set_transient( fqj_cache_key( $post ), 'x' );
		wp_update_post(
			array(
				'ID'          => $post,
				'post_parent' => $parent,
			)
		);
		$this->assertFalse( get_transient( fqj_cache_key( $post ) ) );

		set_transient( fqj_cache_key( $post ), 'x' );
		wp_update_post(
			array(
				'ID'          => $post,
				'post_status' => 'draft',
			)
		);
		$this->assertFalse( get_transient( fqj_cache_key( $post ) ) );
	}

	public function test_title_change_keeps_cache() {
		$post = self::factory()->post->create();
		set_transient( fqj_cache_key( $post ), 'x' );
		wp_update_post(
			array(
				'ID'         => $post,
				'post_title' => 'Different',
			)
		);
		$this->assertSame( 'x', get_transient( fqj_cache_key( $post ) ) );
	}
}
