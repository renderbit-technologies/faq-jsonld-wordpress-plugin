<?php
/**
 * AJAX endpoints for the meta box and Health page.
 *
 * @package FAQ_JSON_LD
 */

/**
 * AJAX tests.
 */
class Test_FQJ_Ajax extends WP_Ajax_UnitTestCase {

	public function set_up() {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		$this->_setRole( 'administrator' );

		// admin_init would otherwise try to reach wordpress.org for update checks.
		remove_action( 'admin_init', '_maybe_update_core' );
		remove_action( 'admin_init', '_maybe_update_plugins' );
		remove_action( 'admin_init', '_maybe_update_themes' );
		remove_action( 'admin_init', 'wp_maybe_auto_update' );
	}

	/**
	 * Call an action and return its decoded JSON response.
	 *
	 * @param string $action AJAX action.
	 * @return mixed
	 */
	protected function call( $action ) {
		try {
			$this->_handleAjax( $action );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		return json_decode( $this->_last_response, true );
	}

	/**
	 * Expect the action to die on a failed nonce check.
	 *
	 * @param string $action AJAX action.
	 */
	protected function assert_rejects_bad_nonce( $action ) {
		$_POST['nonce'] = 'bad';
		$this->expectException( WPAjaxDieStopException::class );
		$this->expectExceptionMessage( '-1' );
		$this->_handleAjax( $action );
	}

	/**
	 * Set a valid nonce for an action. _handleAjax() rebuilds $_REQUEST from $_POST and $_GET.
	 *
	 * @param string $nonce_action Nonce action.
	 */
	protected function nonce( $nonce_action ) {
		$_POST['nonce'] = wp_create_nonce( $nonce_action );
	}

	public function test_search_posts() {
		$match = self::factory()->post->create(
			array(
				'post_title' => 'Shipping policy',
				'post_name'  => 'shipping-policy',
			)
		);
		$page  = self::factory()->post->create(
			array(
				'post_title' => 'Shipping rates',
				'post_type'  => 'page',
			)
		);
		self::factory()->post->create(
			array(
				'post_title'  => 'Shipping draft',
				'post_status' => 'draft',
			)
		);
		self::factory()->post->create( array( 'post_title' => 'Unrelated' ) );

		$this->nonce( 'fqj_admin_nonce' );
		$_GET['q'] = 'Shipping';
		$results   = $this->call( 'fqj_search_posts' );

		$this->assertEqualSets( array( $match, $page ), wp_list_pluck( $results, 'id' ) );
		$by_id = array_column( $results, 'text', 'id' );
		$this->assertSame( 'Shipping policy – http://example.org/shipping-policy/', $by_id[ $match ] );
	}

	public function test_search_posts_includes_custom_public_post_types_only() {
		register_post_type( 'fqj_book', array( 'public' => true ) );
		register_post_type( 'fqj_secret', array( 'public' => false ) );
		$book   = self::factory()->post->create( array( 'post_title' => 'Zebra book', 'post_type' => 'fqj_book' ) );
		self::factory()->post->create( array( 'post_title' => 'Zebra secret', 'post_type' => 'fqj_secret' ) );
		self::factory()->post->create( array( 'post_title' => 'Zebra faq', 'post_type' => 'fqj_faq' ) );
		$attach = self::factory()->post->create( array( 'post_title' => 'Zebra file', 'post_type' => 'attachment', 'post_status' => 'inherit' ) );

		$this->nonce( 'fqj_admin_nonce' );
		$_GET['q'] = 'Zebra';
		$ids       = wp_list_pluck( $this->call( 'fqj_search_posts' ), 'id' );
		unregister_post_type( 'fqj_book' );
		unregister_post_type( 'fqj_secret' );

		$this->assertSame( array( $book ), $ids );
		$this->assertNotContains( $attach, $ids );
	}

	public function test_search_posts_with_empty_query() {
		$this->nonce( 'fqj_admin_nonce' );
		$this->assertSame( array(), $this->call( 'fqj_search_posts' ) );
	}

	public function test_search_posts_rejects_bad_nonce() {
		$this->assert_rejects_bad_nonce( 'fqj_search_posts' );
	}

	public function test_search_terms_across_public_taxonomies() {
		$cat = self::factory()->category->create( array( 'name' => 'Returns' ) );
		$tag = self::factory()->tag->create( array( 'name' => 'Returns policy' ) );
		self::factory()->category->create( array( 'name' => 'Other' ) );

		$this->nonce( 'fqj_admin_nonce' );
		$_GET['q'] = 'Return';
		$results   = $this->call( 'fqj_search_terms' );

		$by_id = array_column( $results, 'text', 'id' );
		$this->assertCount( 2, $by_id );
		$this->assertSame( 'Returns (category)', $by_id[ $cat ] );
		$this->assertSame( 'Returns policy (post_tag)', $by_id[ $tag ] );
	}

	public function test_search_terms_with_empty_query() {
		$this->nonce( 'fqj_admin_nonce' );
		$this->assertSame( array(), $this->call( 'fqj_search_terms' ) );
	}

	public function test_search_terms_rejects_bad_nonce() {
		$this->assert_rejects_bad_nonce( 'fqj_search_terms' );
	}

	public function test_get_post_types_lists_public_types() {
		$this->nonce( 'fqj_admin_nonce' );
		$results = $this->call( 'fqj_get_post_types' );
		$names   = wp_list_pluck( $results, 'name' );

		$this->assertContains( 'post', $names );
		$this->assertContains( 'page', $names );
		$this->assertNotContains( 'fqj_faq', $names, 'FAQ items are not public.' );

		$by_name = array_column( $results, 'label', 'name' );
		$this->assertSame( 'Page', $by_name['page'] );
	}

	public function test_get_post_types_rejects_bad_nonce() {
		$this->assert_rejects_bad_nonce( 'fqj_get_post_types' );
	}

	/**
	 * Assert the admin lookup action is denied to a role that cannot edit posts.
	 *
	 * @param string $action AJAX action.
	 */
	protected function assert_lookup_denied_to_subscriber( $action ) {
		$this->_setRole( 'subscriber' );
		$this->nonce( 'fqj_admin_nonce' );
		$_GET['q'] = 'a';

		$resp = $this->call( $action );
		$this->assertFalse( $resp['success'] );
		$this->assertSame( 'Permission denied', $resp['data']['message'] );
	}

	public function test_search_posts_requires_edit_posts() {
		self::factory()->post->create( array( 'post_title' => 'Alpha' ) );
		$this->assert_lookup_denied_to_subscriber( 'fqj_search_posts' );
	}

	public function test_search_terms_requires_edit_posts() {
		$this->assert_lookup_denied_to_subscriber( 'fqj_search_terms' );
	}

	public function test_get_post_types_requires_edit_posts() {
		$this->assert_lookup_denied_to_subscriber( 'fqj_get_post_types' );
	}

	public function test_search_posts_allows_editors() {
		$id = self::factory()->post->create( array( 'post_title' => 'Alpha' ) );
		$this->_setRole( 'editor' );
		$this->nonce( 'fqj_admin_nonce' );
		$_GET['q'] = 'Alpha';

		$this->assertSame( array( $id ), wp_list_pluck( $this->call( 'fqj_search_posts' ), 'id' ) );
	}

	public function test_search_terms_allows_editors() {
		$cat = self::factory()->category->create( array( 'name' => 'Alpha' ) );
		$this->_setRole( 'editor' );
		$this->nonce( 'fqj_admin_nonce' );
		$_GET['q'] = 'Alpha';

		$this->assertContains( $cat, wp_list_pluck( $this->call( 'fqj_search_terms' ), 'id' ) );
	}

	public function test_get_post_types_allows_editors() {
		$this->_setRole( 'editor' );
		$this->nonce( 'fqj_admin_nonce' );

		$this->assertContains( 'post', wp_list_pluck( $this->call( 'fqj_get_post_types' ), 'name' ) );
	}

	public function test_process_queue_now() {
		set_transient( fqj_cache_key( 11 ), 'x' );
		fqj_queue_add_posts( array( 11, 12, 13 ) );

		$this->nonce( 'fqj_health_nonce' );
		$_POST['limit'] = '2';
		$resp           = $this->call( 'fqj_process_queue_now' );

		$this->assertTrue( $resp['success'] );
		$this->assertSame( 2, $resp['data']['processed'] );
		$this->assertSame( 1, $resp['data']['queue_len'] );
		$this->assertGreaterThan( 0, $resp['data']['last_run'] );
		$this->assertSame( wp_date( 'Y-m-d H:i:s', $resp['data']['last_run'] ), substr( $resp['data']['last_run_human'], 0, 19 ) );
		$this->assertStringEndsWith( ' ago)', $resp['data']['last_run_human'] );
		$this->assertFalse( get_transient( fqj_cache_key( 11 ) ) );
	}

	public function test_process_queue_now_requires_manage_options() {
		$this->_setRole( 'editor' );
		$this->nonce( 'fqj_health_nonce' );
		fqj_queue_add_posts( array( 1 ) );

		$resp = $this->call( 'fqj_process_queue_now' );
		$this->assertFalse( $resp['success'] );
		$this->assertSame( 'Permission denied', $resp['data']['message'] );
		$this->assertSame( 1, fqj_queue_length() );
	}

	public function test_process_queue_now_rejects_bad_nonce() {
		$this->assert_rejects_bad_nonce( 'fqj_process_queue_now' );
	}

	public function test_purge_transients() {
		$key = fqj_cache_key( 1 );
		set_transient( $key, 'x' );
		$this->nonce( 'fqj_health_nonce' );
		$resp = $this->call( 'fqj_purge_transients' );

		$this->assertTrue( $resp['success'] );
		$this->assertFalse( get_transient( $key ) );
	}

	public function test_purge_transients_requires_manage_options() {
		$this->_setRole( 'editor' );
		set_transient( fqj_cache_key( 1 ), 'x' );
		$this->nonce( 'fqj_health_nonce' );

		$resp = $this->call( 'fqj_purge_transients' );
		$this->assertFalse( $resp['success'] );
		$this->assertSame( 'x', get_transient( fqj_cache_key( 1 ) ) );
	}

	public function test_purge_transients_rejects_bad_nonce() {
		$this->assert_rejects_bad_nonce( 'fqj_purge_transients' );
	}

	public function test_clear_invalidation_log() {
		fqj_log_invalidation_run( 1, array( 1 ) );
		$this->nonce( 'fqj_health_nonce' );
		$resp = $this->call( 'fqj_clear_invalidation_log' );

		$this->assertTrue( $resp['success'] );
		$this->assertSame( array(), get_option( 'fqj_invalidation_log' ) );
	}

	public function test_clear_invalidation_log_requires_manage_options() {
		$this->_setRole( 'editor' );
		fqj_log_invalidation_run( 1, array( 1 ) );
		$this->nonce( 'fqj_health_nonce' );

		$resp = $this->call( 'fqj_clear_invalidation_log' );
		$this->assertFalse( $resp['success'] );
		$this->assertCount( 1, get_option( 'fqj_invalidation_log' ) );
	}

	public function test_clear_invalidation_log_rejects_bad_nonce() {
		$this->assert_rejects_bad_nonce( 'fqj_clear_invalidation_log' );
	}

	public function test_health_actions_reject_admin_nonce() {
		$this->nonce( 'fqj_admin_nonce' );
		$this->expectException( WPAjaxDieStopException::class );
		$this->_handleAjax( 'fqj_purge_transients' );
	}
}
