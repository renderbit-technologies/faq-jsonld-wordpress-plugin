<?php
/**
 * Plugin bootstrap, CPT, activation and deactivation.
 *
 * @package FAQ_JSON_LD
 */

/**
 * Plugin-level tests.
 */
class Test_FQJ_Plugin extends FQJ_TestCase {

	public function test_constants_are_defined() {
		global $wpdb;
		$this->assertSame( $wpdb->prefix . 'fqj_mappings', fqj_table_name() );
		$this->assertSame( 'fqj_settings', FQJ_OPTION_KEY );
		$this->assertStringEndsWith( '/', FQJ_PLUGIN_DIR );
		$this->assertFileExists( FQJ_PLUGIN_DIR . 'faq-jsonld.php' );
	}

	public function test_faq_item_post_type_is_registered() {
		$this->assertTrue( post_type_exists( 'faq_item' ) );

		$pt = get_post_type_object( 'faq_item' );
		$this->assertFalse( $pt->public );
		$this->assertTrue( $pt->show_ui );
		$this->assertFalse( $pt->hierarchical );
		$this->assertFalse( $pt->has_archive );
		$this->assertSame( 'FAQ Items', $pt->labels->name );
		$this->assertTrue( post_type_supports( 'faq_item', 'title' ) );
		$this->assertTrue( post_type_supports( 'faq_item', 'editor' ) );
	}

	public function test_activation_creates_table() {
		global $wpdb;
		$table = fqj_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns = $wpdb->get_col( "DESCRIBE {$table}" );
		$this->assertSame( array( 'id', 'faq_id', 'mapping_type', 'mapping_value' ), $columns );
	}

	public function test_activation_sets_default_options() {
		delete_option( FQJ_OPTION_KEY );
		fqj_activate();

		$opts = get_option( FQJ_OPTION_KEY );
		$this->assertSame( 12 * HOUR_IN_SECONDS, $opts['cache_ttl'] );
		$this->assertSame( 500, $opts['batch_size'] );
		$this->assertSame( 'faqsection', $opts['output_type'] );
		$this->assertSame( 'fqj_five_minutes', $opts['queue_cron_interval'] );
	}

	public function test_activation_keeps_existing_options() {
		update_option(
			FQJ_OPTION_KEY,
			array(
				'cache_ttl'   => 120,
				'output_type' => 'faqpage',
			)
		);
		fqj_activate();

		$opts = get_option( FQJ_OPTION_KEY );
		$this->assertSame( 120, $opts['cache_ttl'] );
		$this->assertSame( 'faqpage', $opts['output_type'] );
		$this->assertSame( 500, $opts['batch_size'] );
	}

	public function test_activation_schedules_queue_cron_once() {
		wp_clear_scheduled_hook( 'fqj_process_invalidation_queue' );
		fqj_activate();

		$first = wp_next_scheduled( 'fqj_process_invalidation_queue' );
		$this->assertNotFalse( $first );
		$this->assertSame( 'fqj_five_minutes', wp_get_schedule( 'fqj_process_invalidation_queue' ) );

		fqj_activate();
		$this->assertSame( $first, wp_next_scheduled( 'fqj_process_invalidation_queue' ) );
	}

	public function test_deactivation_clears_cron() {
		fqj_activate();
		fqj_deactivate();
		$this->assertFalse( wp_next_scheduled( 'fqj_process_invalidation_queue' ) );
	}

	public function test_deactivation_clears_duplicate_and_migration_events() {
		wp_schedule_event( time() + 60, 'fqj_five_minutes', 'fqj_process_invalidation_queue' );
		wp_schedule_event( time() + 120, 'hourly', 'fqj_process_invalidation_queue' );
		wp_schedule_single_event( time() + 10, 'fqj_data_migration' );

		fqj_deactivate();

		$this->assertFalse( wp_next_scheduled( 'fqj_process_invalidation_queue' ) );
		$this->assertFalse( wp_next_scheduled( 'fqj_data_migration' ) );
	}

	public function test_network_wide_flag_is_harmless_on_single_site() {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Single-site behaviour.' );
		}
		wp_clear_scheduled_hook( 'fqj_process_invalidation_queue' );
		fqj_activate( true );
		$this->assertNotFalse( wp_next_scheduled( 'fqj_process_invalidation_queue' ) );
		fqj_deactivate( true );
		$this->assertFalse( wp_next_scheduled( 'fqj_process_invalidation_queue' ) );
	}

	public function test_for_each_site_runs_once_on_single_site() {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Single-site behaviour.' );
		}
		$calls = 0;
		fqj_for_each_site(
			function () use ( &$calls ) {
				++$calls;
			}
		);
		$this->assertSame( 1, $calls );
	}

	public function test_new_site_is_ignored_when_not_network_active() {
		$this->assertNotFalse( has_action( 'wp_initialize_site', 'fqj_on_initialize_site' ) );
		wp_clear_scheduled_hook( 'fqj_process_invalidation_queue' );
		fqj_on_initialize_site( (object) array( 'blog_id' => get_current_blog_id() ) );
		$this->assertFalse( wp_next_scheduled( 'fqj_process_invalidation_queue' ) );
	}

	public function test_table_name_follows_current_blog_prefix() {
		global $wpdb;
		$original     = $wpdb->prefix;
		$wpdb->prefix = 'other_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$name         = fqj_table_name();
		$wpdb->prefix = $original; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->assertSame( 'other_fqj_mappings', $name );
	}

	public function test_deactivation_without_scheduled_event_is_safe() {
		wp_clear_scheduled_hook( 'fqj_process_invalidation_queue' );
		fqj_deactivate();
		$this->assertFalse( wp_next_scheduled( 'fqj_process_invalidation_queue' ) );
	}

	public function test_five_minute_cron_interval_is_registered() {
		$schedules = wp_get_schedules();
		$this->assertArrayHasKey( 'fqj_five_minutes', $schedules );
		$this->assertSame( 300, $schedules['fqj_five_minutes']['interval'] );
	}

	public function test_cron_interval_filter_keeps_existing_definition() {
		$existing = array(
			'fqj_five_minutes' => array(
				'interval' => 42,
				'display'  => 'Custom',
			),
		);
		$this->assertSame( $existing, fqj_add_cron_interval( $existing ) );
	}

	/**
	 * @dataProvider hook_provider
	 *
	 * @param string $hook     Hook name.
	 * @param string $callback Function name.
	 */
	public function test_hooks_are_registered( $hook, $callback ) {
		$this->assertNotFalse( has_action( $hook, $callback ) );
	}

	public function hook_provider() {
		return array(
			array( 'init', 'fqj_register_cpt_faq_item' ),
			array( 'wp_head', 'fqj_maybe_print_faq_jsonld' ),
			array( 'save_post', 'fqj_save_meta_handler' ),
			array( 'add_meta_boxes', 'fqj_add_meta_boxes' ),
			array( 'admin_enqueue_scripts', 'fqj_admin_assets' ),
			array( 'admin_enqueue_scripts', 'fqj_health_admin_assets' ),
			array( 'admin_menu', 'fqj_register_settings_page' ),
			array( 'admin_menu', 'fqj_register_health_page' ),
			array( 'admin_notices', 'fqj_admin_queue_notice' ),
			array( 'fqj_process_invalidation_queue', 'fqj_process_invalidation_queue_cron' ),
			array( 'wp_ajax_fqj_search_posts', 'fqj_ajax_search_posts' ),
			array( 'wp_ajax_fqj_search_terms', 'fqj_ajax_search_terms' ),
			array( 'wp_ajax_fqj_get_post_types', 'fqj_ajax_get_post_types' ),
			array( 'wp_ajax_fqj_process_queue_now', 'fqj_ajax_process_queue_now' ),
			array( 'wp_ajax_fqj_purge_transients', 'fqj_ajax_purge_transients' ),
			array( 'wp_ajax_fqj_clear_invalidation_log', 'fqj_ajax_clear_invalidation_log' ),
		);
	}

	public function test_wp_head_priority_is_early() {
		$this->assertSame( 1, has_action( 'wp_head', 'fqj_maybe_print_faq_jsonld' ) );
	}

	public function test_ajax_actions_are_not_public() {
		foreach ( array( 'fqj_search_posts', 'fqj_search_terms', 'fqj_get_post_types', 'fqj_process_queue_now', 'fqj_purge_transients', 'fqj_clear_invalidation_log' ) as $action ) {
			$this->assertFalse( has_action( 'wp_ajax_nopriv_' . $action ), $action );
		}
	}
}
