<?php
/**
 * Invalidation queue, worker and run log.
 *
 * @package Renderbit_FAQ_Structured_Data
 */

/**
 * Queue tests.
 */
class Test_RFSD_Queue extends RFSD_TestCase {

	public function set_up() {
		parent::set_up();
		delete_option( 'rfsd_invalidation_log' );
		delete_option( 'rfsd_last_queue_run' );
	}

	public function test_add_rejects_empty_input() {
		$this->assertFalse( rfsd_queue_add_posts( array() ) );
		$this->assertFalse( rfsd_queue_add_posts( array( 0, '0', 'abc', null ) ) );
		$this->assertSame( 0, rfsd_queue_length() );
	}

	public function test_add_casts_and_skips_already_queued_ids() {
		$this->assertTrue( rfsd_queue_add_posts( array( '3', 1, 0, '2' ) ) );
		$this->assertTrue( rfsd_queue_add_posts( array( 2, '4', 3 ) ) );

		$this->assertSame( array( 3, 1, 2, 4 ), $this->get_queue() );
	}

	public function test_add_dedupes_ids_within_one_call() {
		$this->assertTrue( rfsd_queue_add_posts( array( 3, 1, 3 ) ) );

		$this->assertSame( array( 3, 1 ), $this->get_queue() );
	}

	public function test_add_items_rejects_unknown_type_and_sanitizes_values() {
		$this->assertFalse( rfsd_queue_add_items( 'bogus', array( 1 ) ) );
		$this->assertTrue( rfsd_queue_add_items( 'post_type', array( "pa'ge; DROP", 'Page' ) ) );
		$this->assertSame( 'pagedrop', $this->get_queue_jobs()[0]['item_value'] );
		$this->assertCount( 2, $this->get_queue_jobs() );
	}

	public function test_add_handles_large_batches() {
		rfsd_queue_add_posts( range( 1, 450 ) );
		$this->assertSame( 450, rfsd_queue_length() );
	}

	public function test_readding_a_job_restarts_its_cursor() {
		global $wpdb;
		rfsd_queue_add_items( 'post_type', array( 'page' ) );
		$table = rfsd_queue_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "UPDATE {$table} SET job_cursor = 50" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		rfsd_queue_add_items( 'post_type', array( 'page' ) );
		$jobs = $this->get_queue_jobs();
		$this->assertCount( 1, $jobs );
		$this->assertSame( '0', $jobs[0]['job_cursor'] );
	}

	public function test_post_pop_ignores_jobs_and_items_added_meanwhile_survive() {
		rfsd_queue_add_items( 'term', array( 5 ) );
		rfsd_queue_add_posts( array( 1, 2 ) );
		$this->assertSame( array( 1, 2 ), rfsd_queue_pop_posts() );
		$this->assertSame( 1, rfsd_queue_length(), 'The job is still queued.' );
		$this->assertSame( array(), rfsd_queue_pop_posts( 0 ) );
	}

	public function test_legacy_transient_queue_is_migrated_on_upgrade() {
		set_transient( 'rfsd_invalidate_queue', array( 4, 5, 4 ), DAY_IN_SECONDS );
		update_option( RFSD_DB_VERSION_OPTION, '1' );
		update_option( 'rfsd_invalidation_log', array( array( 'ts' => 1 ) ), true );

		rfsd_maybe_upgrade_db();

		$this->assertSame( array( 4, 5 ), $this->get_queue() );
		$this->assertFalse( get_transient( 'rfsd_invalidate_queue' ) );
		$this->assertSame( RFSD_DB_VERSION, get_option( RFSD_DB_VERSION_OPTION ) );
		$this->assertNotContains( 'rfsd_invalidation_log', array_keys( wp_load_alloptions( true ) ) );
	}

	public function test_corrupt_legacy_transient_is_discarded() {
		set_transient( 'rfsd_invalidate_queue', 'garbage' );
		rfsd_migrate_legacy_queue();
		$this->assertSame( 0, rfsd_queue_length() );
		$this->assertFalse( get_transient( 'rfsd_invalidate_queue' ) );
	}

	public function test_cron_event_is_rescheduled_when_missing() {
		wp_clear_scheduled_hook( 'rfsd_process_invalidation_queue' );
		do_action( 'init' );
		$this->assertNotFalse( wp_next_scheduled( 'rfsd_process_invalidation_queue' ) );
	}

	public function test_length() {
		$this->assertSame( 0, rfsd_queue_length() );
		rfsd_queue_add_posts( array( 1, 2, 3 ) );
		$this->assertSame( 3, rfsd_queue_length() );
	}

	public function test_pop_is_fifo_and_respects_limit() {
		rfsd_queue_add_posts( array( 10, 20, 30, 40, 50 ) );

		$this->assertSame( array( 10, 20 ), rfsd_queue_pop_posts( 2 ) );
		$this->assertSame( array( 30, 40, 50 ), $this->get_queue() );
		$this->assertSame( array( 30, 40, 50 ), rfsd_queue_pop_posts( 100 ) );
		$this->assertSame( 0, rfsd_queue_length() );
	}

	public function test_pop_default_limit_is_100() {
		rfsd_queue_add_posts( range( 1, 150 ) );
		$this->assertCount( 100, rfsd_queue_pop_posts() );
		$this->assertSame( 50, rfsd_queue_length() );
	}

	public function test_pop_on_empty_queue() {
		$this->assertSame( array(), rfsd_queue_pop_posts() );
	}

	public function test_log_run_records_newest_first() {
		rfsd_log_invalidation_run( 3, array( 1, 2, 3 ) );
		rfsd_log_invalidation_run( 1, array( '7' ) );

		$log = get_option( 'rfsd_invalidation_log' );
		$this->assertCount( 2, $log );
		$this->assertSame( 1, $log[0]['processed'] );
		$this->assertSame( array( 7 ), $log[0]['sample'] );
		$this->assertSame( 3, $log[1]['processed'] );
		$this->assertEqualsWithDelta( time(), $log[0]['ts'], 5 );
		$this->assertEqualsWithDelta( time(), (int) get_option( 'rfsd_last_queue_run' ), 5 );
	}

	public function test_log_run_caps_sample_and_length() {
		rfsd_log_invalidation_run( 50, range( 1, 50 ) );
		$log = get_option( 'rfsd_invalidation_log' );
		$this->assertSame( range( 1, 20 ), $log[0]['sample'] );

		update_option( 'rfsd_invalidation_log', array_fill( 0, 200, array( 'ts' => 1 ) ) );
		rfsd_log_invalidation_run( 1 );
		$log = get_option( 'rfsd_invalidation_log' );
		$this->assertCount( 200, $log );
		$this->assertSame( 1, $log[0]['processed'] );
	}

	public function test_log_run_recovers_from_corrupt_log() {
		update_option( 'rfsd_invalidation_log', 'garbage' );
		rfsd_log_invalidation_run( 2 );
		$this->assertCount( 1, get_option( 'rfsd_invalidation_log' ) );
	}

	public function test_cron_worker_deletes_transients_in_batches() {
		update_option( RFSD_OPTION_KEY, array( 'batch_size' => 2 ) );
		foreach ( array( 1, 2, 3 ) as $id ) {
			set_transient( rfsd_cache_key( $id ), 'cached' );
		}
		rfsd_queue_add_posts( array( 1, 2, 3 ) );

		do_action( 'rfsd_process_invalidation_queue' );

		$this->assertFalse( get_transient( rfsd_cache_key( 1 ) ) );
		$this->assertFalse( get_transient( rfsd_cache_key( 2 ) ) );
		$this->assertSame( 'cached', get_transient( rfsd_cache_key( 3 ) ) );
		$this->assertSame( array( 3 ), $this->get_queue() );

		$log = get_option( 'rfsd_invalidation_log' );
		$this->assertSame( 2, $log[0]['processed'] );
		$this->assertSame( array( 1, 2 ), $log[0]['sample'] );
	}

	public function test_empty_run_updates_last_run_but_not_the_log() {
		rfsd_process_invalidation_queue_cron();
		$this->assertFalse( get_option( 'rfsd_invalidation_log' ) );
		$this->assertNotEmpty( get_option( 'rfsd_last_queue_run' ) );

		rfsd_process_invalidation_queue_now();
		$this->assertFalse( get_option( 'rfsd_invalidation_log' ) );
	}

	public function test_log_options_are_not_autoloaded() {
		rfsd_log_invalidation_run( 1, array( 1 ) );
		$autoloaded = wp_load_alloptions( true );
		$this->assertArrayNotHasKey( 'rfsd_invalidation_log', $autoloaded );
		$this->assertArrayNotHasKey( 'rfsd_last_queue_run', $autoloaded );

		rfsd_log_invalidation_run( 2, array( 1 ) );
		$autoloaded = wp_load_alloptions( true );
		$this->assertArrayNotHasKey( 'rfsd_invalidation_log', $autoloaded );
		$this->assertArrayNotHasKey( 'rfsd_last_queue_run', $autoloaded );
	}

	public function test_post_type_job_expands_incrementally_with_cursor() {
		update_option( RFSD_OPTION_KEY, array( 'batch_size' => 2 ) );
		$pages = self::factory()->post->create_many( 5, array( 'post_type' => 'page' ) );
		$draft = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
			)
		);
		$other = self::factory()->post->create();
		foreach ( array_merge( $pages, array( $draft, $other ) ) as $id ) {
			set_transient( rfsd_cache_key( $id ), 'cached' );
		}
		rfsd_queue_add_items( 'post_type', array( 'page' ) );

		$this->assertSame( 2, rfsd_process_invalidation_queue_now() );
		$this->assertFalse( get_transient( rfsd_cache_key( $pages[0] ) ) );
		$this->assertFalse( get_transient( rfsd_cache_key( $pages[1] ) ) );
		$this->assertSame( 'cached', get_transient( rfsd_cache_key( $pages[2] ) ) );
		$this->assertSame( (string) $pages[1], $this->get_queue_jobs()[0]['job_cursor'] );

		$this->assertSame( 2, rfsd_process_invalidation_queue_now() );
		$this->assertSame( 1, rfsd_process_invalidation_queue_now() );
		$this->assertSame( 0, rfsd_queue_length(), 'A short batch means the job is exhausted and removed.' );
		$this->assertSame( 'cached', get_transient( rfsd_cache_key( $draft ) ) );
		$this->assertSame( 'cached', get_transient( rfsd_cache_key( $other ) ) );
	}

	public function test_term_job_expands_published_tagged_posts_and_finishes() {
		update_option( RFSD_OPTION_KEY, array( 'batch_size' => 10 ) );
		$term   = self::factory()->category->create();
		$tagged = self::factory()->post->create_many( 3 );
		$draft  = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$other  = self::factory()->post->create();
		foreach ( array_merge( $tagged, array( $draft ) ) as $id ) {
			wp_set_post_categories( $id, array( $term ) );
		}
		foreach ( array_merge( $tagged, array( $draft, $other ) ) as $id ) {
			set_transient( rfsd_cache_key( $id ), 'cached' );
		}
		rfsd_queue_add_items( 'term', array( $term, 999999 ) );

		$this->assertSame( 3, rfsd_process_invalidation_queue_now() );

		foreach ( $tagged as $id ) {
			$this->assertFalse( get_transient( rfsd_cache_key( $id ) ) );
		}
		$this->assertSame( 'cached', get_transient( rfsd_cache_key( $draft ) ) );
		$this->assertSame( 'cached', get_transient( rfsd_cache_key( $other ) ) );
		$this->assertSame( 0, rfsd_queue_length(), 'Finished and missing-term jobs are removed.' );
		$this->assertSame( 3, get_option( 'rfsd_invalidation_log' )[0]['processed'] );
	}

	public function test_posts_are_processed_before_jobs_within_budget() {
		update_option( RFSD_OPTION_KEY, array( 'batch_size' => 3 ) );
		$pages = self::factory()->post->create_many( 4, array( 'post_type' => 'page' ) );
		rfsd_queue_add_items( 'post_type', array( 'page' ) );
		rfsd_queue_add_posts( array( 900, 901 ) );

		$this->assertSame( 3, rfsd_process_invalidation_queue_now() );
		$this->assertSame( (string) $pages[0], $this->get_queue_jobs()[0]['job_cursor'] );
		$this->assertSame( array(), $this->get_queue() );
	}

	public function test_cron_worker_defaults_batch_size_without_settings() {
		delete_option( RFSD_OPTION_KEY );
		rfsd_queue_add_posts( range( 1, 600 ) );
		rfsd_process_invalidation_queue_cron();
		$this->assertSame( 100, rfsd_queue_length() );
	}

	public function test_process_now_with_limit() {
		set_transient( rfsd_cache_key( 5 ), 'x' );
		set_transient( rfsd_cache_key( 6 ), 'y' );
		rfsd_queue_add_posts( array( 5, 6 ) );

		$this->assertSame( 1, rfsd_process_invalidation_queue_now( 1 ) );
		$this->assertFalse( get_transient( rfsd_cache_key( 5 ) ) );
		$this->assertSame( 'y', get_transient( rfsd_cache_key( 6 ) ) );

		$this->assertSame( 1, rfsd_process_invalidation_queue_now() );
		$this->assertSame( 0, rfsd_process_invalidation_queue_now() );

		$log = get_option( 'rfsd_invalidation_log' );
		$this->assertCount( 2, $log, 'The empty run is not logged.' );
	}

	public function test_process_now_uses_batch_size_when_no_limit() {
		update_option( RFSD_OPTION_KEY, array( 'batch_size' => 3 ) );
		rfsd_queue_add_posts( range( 1, 10 ) );
		$this->assertSame( 3, rfsd_process_invalidation_queue_now() );
		$this->assertSame( 7, rfsd_queue_length() );
	}

	public function test_admin_notice_shows_for_admins_with_pending_items() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'edit-' . RFSD_POST_TYPE );

		ob_start();
		rfsd_admin_queue_notice();
		$this->assertSame( '', ob_get_clean(), 'No notice when queue is empty.' );

		rfsd_queue_add_posts( array( 1, 2 ) );
		ob_start();
		rfsd_admin_queue_notice();
		$out = ob_get_clean();
		$this->assertStringContainsString( 'notice-info', $out );
		$this->assertStringContainsString( '<strong>2</strong>', $out );
	}

	public function test_admin_notice_hidden_for_non_admins() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		rfsd_queue_add_posts( array( 1 ) );

		ob_start();
		rfsd_admin_queue_notice();
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * Screens the queue notice appears on.
	 *
	 * @dataProvider notice_screen_provider
	 *
	 * @param string $screen   Screen ID.
	 * @param bool   $expected Whether the notice is shown.
	 */
	public function test_admin_notice_only_on_plugin_screens( $screen, $expected ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		rfsd_queue_add_posts( array( 1 ) );
		set_current_screen( $screen );

		ob_start();
		rfsd_admin_queue_notice();
		$out = ob_get_clean();
		set_current_screen( 'front' );

		if ( $expected ) {
			$this->assertStringContainsString( '<strong>1</strong> item pending', $out );
		} else {
			$this->assertSame( '', $out );
		}
	}

	public function notice_screen_provider() {
		return array(
			'FAQ list'      => array( 'edit-rfsd_faq', true ),
			'FAQ editor'    => array( 'rfsd_faq', true ),
			'Health page'   => array( 'rfsd_faq_page_rfsd-health', true ),
			'Settings page' => array( 'rfsd_faq_page_rfsd-settings', true ),
			'dashboard'     => array( 'dashboard', false ),
			'posts list'    => array( 'edit-post', false ),
			'plugins'       => array( 'plugins', false ),
		);
	}
}
