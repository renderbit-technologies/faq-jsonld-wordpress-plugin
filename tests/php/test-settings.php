<?php
/**
 * Settings page and transient purge helper.
 *
 * @package Renderbit_FAQ_Structured_Data
 */

/**
 * Settings tests.
 */
class Test_RFSD_Settings extends RFSD_TestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Render the settings page.
	 *
	 * @return string
	 */
	protected function render() {
		ob_start();
		rfsd_render_settings_page();

		return ob_get_clean();
	}

	/**
	 * Post the settings form.
	 *
	 * @param array $fields Form fields.
	 * @return string
	 */
	protected function save( $fields ) {
		$_POST = array_merge(
			array( 'rfsd_settings_nonce' => wp_create_nonce( 'rfsd_save_settings' ) ),
			$fields
		);

		return $this->render();
	}

	public function test_submenu_is_registered() {
		global $submenu;
		rfsd_register_settings_page();
		$slugs = wp_list_pluck( $submenu['edit.php?post_type=rfsd_faq'], 2 );
		$this->assertContains( 'rfsd-settings', $slugs );
	}

	public function test_render_shows_current_values() {
		update_option(
			RFSD_OPTION_KEY,
			array(
				'cache_ttl'   => 900,
				'batch_size'  => 50,
				'output_type' => 'faqpage',
			)
		);
		$html = $this->render();

		$this->assertStringContainsString( 'value="900"', $html );
		$this->assertStringContainsString( 'value="50"', $html );
		$this->assertMatchesRegularExpression( '/<option value="faqpage"\s+selected=\'selected\'>/', $html );
		$this->assertStringContainsString( 'name="rfsd_settings_nonce"', $html );
		$this->assertStringNotContainsString( 'Settings saved', $html );
	}

	public function test_render_uses_defaults_when_option_missing() {
		delete_option( RFSD_OPTION_KEY );
		$html = $this->render();
		$this->assertStringContainsString( 'value="43200"', $html );
		$this->assertStringContainsString( 'value="500"', $html );
		$this->assertMatchesRegularExpression( '/<option value="faqpage"\s+selected=\'selected\'>/', $html );
	}

	public function test_save_valid_settings() {
		$html = $this->save(
			array(
				'cache_ttl'   => '3600',
				'batch_size'  => '250',
				'output_type' => 'faqpage',
			)
		);

		$this->assertStringContainsString( 'Settings saved', $html );
		$opts = get_option( RFSD_OPTION_KEY );
		$this->assertSame( 3600, $opts['cache_ttl'] );
		$this->assertSame( 250, $opts['batch_size'] );
		$this->assertSame( 'faqpage', $opts['output_type'] );
	}

	public function test_save_clamps_and_sanitizes() {
		$this->save(
			array(
				'cache_ttl'   => '5',
				'batch_size'  => '-3',
				'output_type' => '<script>faqpage</script>',
			)
		);

		$opts = get_option( RFSD_OPTION_KEY );
		$this->assertSame( 60, $opts['cache_ttl'] );
		$this->assertSame( 10, $opts['batch_size'] );
		$this->assertSame( 'faqpage', $opts['output_type'] );
	}

	public function test_save_with_missing_fields_uses_minimums() {
		$this->save( array() );
		$opts = get_option( RFSD_OPTION_KEY );
		$this->assertSame( 60, $opts['cache_ttl'] );
		$this->assertSame( 10, $opts['batch_size'] );
		$this->assertSame( 'faqpage', $opts['output_type'] );
	}

	public function test_save_keeps_other_option_keys() {
		update_option( RFSD_OPTION_KEY, array( 'queue_cron_interval' => 'rfsd_five_minutes' ) );
		$this->save( array( 'cache_ttl' => '120' ) );
		$this->assertSame( 'rfsd_five_minutes', get_option( RFSD_OPTION_KEY )['queue_cron_interval'] );
	}

	public function test_save_caps_batch_size() {
		$this->save( array( 'batch_size' => '999999' ) );
		$this->assertSame( RFSD_MAX_BATCH_SIZE, get_option( RFSD_OPTION_KEY )['batch_size'] );
		$this->assertSame( 5000, RFSD_MAX_BATCH_SIZE );
	}

	public function test_save_ignored_with_bad_nonce() {
		update_option( RFSD_OPTION_KEY, array( 'cache_ttl' => 999 ) );
		$_POST = array(
			'rfsd_settings_nonce' => 'bad',
			'cache_ttl'           => '120',
		);
		$this->assertStringNotContainsString( 'Settings saved', $this->render() );
		$this->assertSame( 999, get_option( RFSD_OPTION_KEY )['cache_ttl'] );
	}

	public function test_non_admin_sees_nothing_and_cannot_save() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		update_option( RFSD_OPTION_KEY, array( 'cache_ttl' => 999 ) );

		$this->assertSame( '', $this->save( array( 'cache_ttl' => '120' ) ) );
		$this->assertSame( 999, get_option( RFSD_OPTION_KEY )['cache_ttl'] );
	}

	public function test_purge_action_from_settings_page() {
		$key = rfsd_cache_key( 7 );
		set_transient( $key, 'x' );
		$_POST = array(
			'rfsd_action'                => 'purge_all',
			'rfsd_purge_all_nonce_field' => wp_create_nonce( 'rfsd_purge_all_nonce' ),
		);

		$this->assertStringContainsString( 'All FAQ transients purged', $this->render() );
		$this->assertFalse( get_transient( $key ) );
	}

	public function test_purge_action_requires_valid_nonce() {
		set_transient( rfsd_cache_key( 7 ), 'x' );
		$_POST = array(
			'rfsd_action'                => 'purge_all',
			'rfsd_purge_all_nonce_field' => 'bad',
		);

		$this->assertStringNotContainsString( 'All FAQ transients purged', $this->render() );
		$this->assertSame( 'x', get_transient( rfsd_cache_key( 7 ) ) );
	}

	public function test_purge_helper_removes_only_faq_transients() {
		$key_1  = rfsd_cache_key( 1 );
		$key_22 = rfsd_cache_key( 22 );
		set_transient( $key_1, 'a', 600 );
		set_transient( $key_22, 'b' );
		rfsd_queue_add_posts( array( 1 ) );
		set_transient( 'other_plugin_cache', 'c' );

		rfsd_purge_all_faq_transients();

		$this->assertFalse( get_transient( $key_1 ) );
		$this->assertFalse( get_option( '_transient_timeout_' . $key_1 ) );
		$this->assertFalse( get_option( '_transient_' . $key_22 ) );
		$this->assertSame( array( 1 ), $this->get_queue() );
		$this->assertSame( 'c', get_transient( 'other_plugin_cache' ) );
	}

	public function test_purge_helper_with_nothing_to_purge() {
		$before = rfsd_cache_generation();
		$this->assertSame( $before + 1, rfsd_purge_all_faq_transients() );
		$this->assertFalse( get_transient( rfsd_cache_key( 1 ) ) );
	}

	public function test_cache_key_includes_generation_and_post_id() {
		update_option( 'rfsd_cache_gen', 4 );
		$this->assertSame( 'rfsd_faq_json_4_9', rfsd_cache_key( 9 ) );
	}

	public function test_missing_generation_is_seeded_from_time_and_stored() {
		delete_option( 'rfsd_cache_gen' );
		$before = time();
		$key    = rfsd_cache_key( 9 );
		$gen    = (int) get_option( 'rfsd_cache_gen' );

		// A reinstall must not reuse a low generation that an object cache may still hold output for.
		$this->assertGreaterThanOrEqual( $before, $gen );
		$this->assertSame( 'rfsd_faq_json_' . $gen . '_9', $key );
		$this->assertSame( $gen, rfsd_cache_generation() );
	}

	public function test_purge_makes_cached_output_unreachable_with_external_object_cache() {
		global $wp_using_ext_object_cache;
		$previous                  = $wp_using_ext_object_cache;
		$wp_using_ext_object_cache = true; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		try {
			$old_key = rfsd_cache_key( 3 );
			set_transient( $old_key, 'stale', 600 );
			$this->assertSame( 'stale', get_transient( $old_key ) );

			rfsd_purge_all_faq_transients();

			$this->assertNotSame( $old_key, rfsd_cache_key( 3 ) );
			$this->assertFalse( get_transient( rfsd_cache_key( 3 ) ) );
		} finally {
			$wp_using_ext_object_cache = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	public function test_purge_sweeps_dead_rows_and_escapes_like_wildcards() {
		$old_key = rfsd_cache_key( 3 );
		set_transient( $old_key, 'x', 600 );
		// "_" must not act as a wildcard: this unrelated option must survive.
		update_option( '_transient_rfsdXfaqXjson_1', 'keep' );

		rfsd_purge_all_faq_transients();

		$this->assertFalse( get_option( '_transient_' . $old_key ) );
		$this->assertFalse( get_option( '_transient_timeout_' . $old_key ) );
		$this->assertSame( 'keep', get_option( '_transient_rfsdXfaqXjson_1' ) );
	}

	public function test_equivalent_output_types_do_not_purge_cache() {
		update_option( RFSD_OPTION_KEY, array( 'output_type' => 'faqsection' ) );
		$gen = rfsd_cache_generation();

		update_option(
			RFSD_OPTION_KEY,
			array(
				'output_type' => 'faqpage',
				'cache_ttl'   => 100,
			)
		);
		$this->assertSame( $gen, rfsd_cache_generation() );
	}

	public function test_settings_page_offers_only_faqpage() {
		$html = $this->render();
		$this->assertStringNotContainsString( 'faqsection', $html );
		$this->assertStringNotContainsString( 'FAQSection', $html );
	}

	public function test_posted_faqsection_is_stored_as_faqpage() {
		$this->save( array( 'output_type' => 'faqsection' ) );
		$this->assertSame( 'faqpage', get_option( RFSD_OPTION_KEY )['output_type'] );
	}
}
