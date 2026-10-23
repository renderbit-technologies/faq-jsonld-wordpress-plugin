<?php
/**
 * Plugin bootstrap, CPT, activation and deactivation.
 *
 * @package Renderbit_FAQ_Structured_Data
 */

/**
 * Plugin-level tests.
 */
class Test_RFSD_Plugin extends RFSD_TestCase {

	public function test_constants_are_defined() {
		global $wpdb;
		$this->assertSame( $wpdb->prefix . 'rfsd_mappings', rfsd_table_name() );
		$this->assertSame( 'rfsd_settings', RFSD_OPTION_KEY );
		$this->assertStringEndsWith( '/', RFSD_PLUGIN_DIR );
		$this->assertFileExists( RFSD_PLUGIN_DIR . 'renderbit-faq-structured-data.php' );
	}

	public function test_faq_post_type_is_registered() {
		$this->assertTrue( post_type_exists( 'rfsd_faq' ) );

		$pt = get_post_type_object( 'rfsd_faq' );
		$this->assertFalse( $pt->public );
		$this->assertTrue( $pt->show_ui );
		$this->assertFalse( $pt->hierarchical );
		$this->assertFalse( $pt->has_archive );
		$this->assertSame( 'FAQ Items', $pt->labels->name );
		$this->assertTrue( post_type_supports( 'rfsd_faq', 'title' ) );
		$this->assertTrue( post_type_supports( 'rfsd_faq', 'editor' ) );
	}

	public function test_activation_creates_table() {
		global $wpdb;
		$table = rfsd_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns = $wpdb->get_col( "DESCRIBE {$table}" );
		$this->assertSame( array( 'id', 'faq_id', 'mapping_type', 'mapping_value' ), $columns );
	}

	public function test_activation_sets_default_options() {
		delete_option( RFSD_OPTION_KEY );
		rfsd_activate();

		$opts = get_option( RFSD_OPTION_KEY );
		$this->assertSame( 12 * HOUR_IN_SECONDS, $opts['cache_ttl'] );
		$this->assertSame( 500, $opts['batch_size'] );
		$this->assertSame( 'faqpage', $opts['output_type'] );
		$this->assertArrayNotHasKey( 'queue_cron_interval', $opts );
	}

	public function test_version_constant_matches_plugin_header() {
		$data = get_file_data(
			RFSD_PLUGIN_DIR . 'renderbit-faq-structured-data.php',
			array(
				'Version'    => 'Version',
				'TextDomain' => 'Text Domain',
			)
		);
		$this->assertSame( $data['Version'], RFSD_VERSION );
		$this->assertSame( 'renderbit-faq-structured-data', $data['TextDomain'] );
	}

	public function test_activation_migrates_legacy_transient_queue() {
		// Reactivation after an upgrade: the schema is already current, so plugins_loaded skipped the migration.
		// The legacy queue comes from a filter, not the database: activation's dbDelta can issue DDL, which
		// commits the test transaction and would leak a stored transient into later tests.
		$legacy = function () {
			return array( 5, 6 );
		};
		add_filter( 'pre_transient_rfsd_invalidate_queue', $legacy );
		rfsd_activate();
		remove_filter( 'pre_transient_rfsd_invalidate_queue', $legacy );
		$this->assertSame( array( 5, 6 ), $this->get_queue() );
	}

	public function test_activation_keeps_existing_options() {
		update_option(
			RFSD_OPTION_KEY,
			array(
				'cache_ttl'   => 120,
				'output_type' => 'faqpage',
			)
		);
		rfsd_activate();

		$opts = get_option( RFSD_OPTION_KEY );
		$this->assertSame( 120, $opts['cache_ttl'] );
		$this->assertSame( 'faqpage', $opts['output_type'] );
		$this->assertSame( 500, $opts['batch_size'] );
	}

	public function test_activation_schedules_queue_cron_once() {
		wp_clear_scheduled_hook( 'rfsd_process_invalidation_queue' );
		rfsd_activate();

		$first = wp_next_scheduled( 'rfsd_process_invalidation_queue' );
		$this->assertNotFalse( $first );
		$this->assertSame( 'rfsd_five_minutes', wp_get_schedule( 'rfsd_process_invalidation_queue' ) );

		rfsd_activate();
		$this->assertSame( $first, wp_next_scheduled( 'rfsd_process_invalidation_queue' ) );
	}

	public function test_deactivation_clears_cron() {
		rfsd_activate();
		rfsd_deactivate();
		$this->assertFalse( wp_next_scheduled( 'rfsd_process_invalidation_queue' ) );
	}

	public function test_deactivation_clears_duplicate_and_migration_events() {
		wp_schedule_event( time() + 60, 'rfsd_five_minutes', 'rfsd_process_invalidation_queue' );
		wp_schedule_event( time() + 120, 'hourly', 'rfsd_process_invalidation_queue' );
		wp_schedule_single_event( time() + 10, 'rfsd_data_migration' );

		rfsd_deactivate();

		$this->assertFalse( wp_next_scheduled( 'rfsd_process_invalidation_queue' ) );
		$this->assertFalse( wp_next_scheduled( 'rfsd_data_migration' ) );
	}

	/**
	 * @group ms-excluded
	 */
	public function test_network_wide_flag_is_harmless_on_single_site() {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Single-site behaviour.' );
		}
		wp_clear_scheduled_hook( 'rfsd_process_invalidation_queue' );
		rfsd_activate( true );
		$this->assertNotFalse( wp_next_scheduled( 'rfsd_process_invalidation_queue' ) );
		rfsd_deactivate( true );
		$this->assertFalse( wp_next_scheduled( 'rfsd_process_invalidation_queue' ) );
	}

	/**
	 * @group ms-excluded
	 */
	public function test_for_each_site_runs_once_on_single_site() {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Single-site behaviour.' );
		}
		$calls = 0;
		rfsd_for_each_site(
			function () use ( &$calls ) {
				++$calls;
			}
		);
		$this->assertSame( 1, $calls );
	}

	public function test_new_site_is_ignored_when_not_network_active() {
		$this->assertNotFalse( has_action( 'wp_initialize_site', 'rfsd_on_initialize_site' ) );
		wp_clear_scheduled_hook( 'rfsd_process_invalidation_queue' );
		rfsd_on_initialize_site( (object) array( 'blog_id' => get_current_blog_id() ) );
		$this->assertFalse( wp_next_scheduled( 'rfsd_process_invalidation_queue' ) );
	}

	public function test_table_name_follows_current_blog_prefix() {
		global $wpdb;
		$original     = $wpdb->prefix;
		$wpdb->prefix = 'other_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$name         = rfsd_table_name();
		$wpdb->prefix = $original; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->assertSame( 'other_rfsd_mappings', $name );
	}

	public function test_deactivation_without_scheduled_event_is_safe() {
		wp_clear_scheduled_hook( 'rfsd_process_invalidation_queue' );
		rfsd_deactivate();
		$this->assertFalse( wp_next_scheduled( 'rfsd_process_invalidation_queue' ) );
	}

	public function test_five_minute_cron_interval_is_registered() {
		$schedules = wp_get_schedules();
		$this->assertArrayHasKey( 'rfsd_five_minutes', $schedules );
		$this->assertSame( 300, $schedules['rfsd_five_minutes']['interval'] );
	}

	public function test_cron_interval_filter_keeps_existing_definition() {
		$existing = array(
			'rfsd_five_minutes' => array(
				'interval' => 42,
				'display'  => 'Custom',
			),
		);
		$this->assertSame( $existing, rfsd_add_cron_interval( $existing ) );
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
			array( 'init', 'rfsd_register_cpt_faq_item' ),
			array( 'wp_head', 'rfsd_maybe_print_faq_jsonld' ),
			array( 'save_post', 'rfsd_save_meta_handler' ),
			array( 'add_meta_boxes', 'rfsd_add_meta_boxes' ),
			array( 'admin_enqueue_scripts', 'rfsd_admin_assets' ),
			array( 'admin_enqueue_scripts', 'rfsd_health_admin_assets' ),
			array( 'admin_menu', 'rfsd_register_settings_page' ),
			array( 'admin_menu', 'rfsd_register_health_page' ),
			array( 'admin_notices', 'rfsd_admin_queue_notice' ),
			array( 'rfsd_process_invalidation_queue', 'rfsd_process_invalidation_queue_cron' ),
			array( 'wp_ajax_rfsd_search_posts', 'rfsd_ajax_search_posts' ),
			array( 'wp_ajax_rfsd_search_terms', 'rfsd_ajax_search_terms' ),
			array( 'wp_ajax_rfsd_get_post_types', 'rfsd_ajax_get_post_types' ),
			array( 'wp_ajax_rfsd_process_queue_now', 'rfsd_ajax_process_queue_now' ),
			array( 'wp_ajax_rfsd_purge_transients', 'rfsd_ajax_purge_transients' ),
			array( 'wp_ajax_rfsd_clear_invalidation_log', 'rfsd_ajax_clear_invalidation_log' ),
		);
	}

	public function test_wp_head_priority_is_early() {
		$this->assertSame( 1, has_action( 'wp_head', 'rfsd_maybe_print_faq_jsonld' ) );
	}

	public function test_ajax_actions_are_not_public() {
		foreach ( array( 'rfsd_search_posts', 'rfsd_search_terms', 'rfsd_get_post_types', 'rfsd_process_queue_now', 'rfsd_purge_transients', 'rfsd_clear_invalidation_log' ) as $action ) {
			$this->assertFalse( has_action( 'wp_ajax_nopriv_' . $action ), $action );
		}
	}
}
