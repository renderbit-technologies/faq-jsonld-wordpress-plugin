<?php
/**
 * Invalidation queue, worker and run log.
 *
 * @package FAQ_JSON_LD
 */

/**
 * Queue tests.
 */
class Test_FQJ_Queue extends FQJ_TestCase {

	public function set_up() {
		parent::set_up();
		delete_option( 'fqj_invalidation_log' );
		delete_option( 'fqj_last_queue_run' );
	}

	public function test_add_rejects_empty_input() {
		$this->assertFalse( fqj_queue_add_posts( array() ) );
		$this->assertFalse( fqj_queue_add_posts( array( 0, '0', 'abc', null ) ) );
		$this->assertFalse( get_transient( 'fqj_invalidate_queue' ) );
	}

	public function test_add_casts_and_skips_already_queued_ids() {
		$this->assertTrue( fqj_queue_add_posts( array( '3', 1, 0, '2' ) ) );
		$this->assertTrue( fqj_queue_add_posts( array( 2, '4', 3 ) ) );

		$this->assertSame( array( 3, 1, 2, 4 ), $this->get_queue() );
	}

	public function test_add_recovers_from_corrupt_queue() {
		set_transient( 'fqj_invalidate_queue', 'garbage' );
		fqj_queue_add_posts( array( 9 ) );
		$this->assertSame( array( 9 ), $this->get_queue() );
	}

	public function test_length() {
		$this->assertSame( 0, fqj_queue_length() );
		fqj_queue_add_posts( array( 1, 2, 3 ) );
		$this->assertSame( 3, fqj_queue_length() );

		set_transient( 'fqj_invalidate_queue', 'garbage' );
		$this->assertSame( 0, fqj_queue_length() );
	}

	public function test_pop_is_fifo_and_respects_limit() {
		fqj_queue_add_posts( array( 10, 20, 30, 40, 50 ) );

		$this->assertSame( array( 10, 20 ), fqj_queue_pop_posts( 2 ) );
		$this->assertSame( array( 30, 40, 50 ), $this->get_queue() );
		$this->assertSame( array( 30, 40, 50 ), fqj_queue_pop_posts( 100 ) );
		$this->assertFalse( get_transient( 'fqj_invalidate_queue' ), 'Emptied queue removes its transient.' );
	}

	public function test_pop_default_limit_is_100() {
		fqj_queue_add_posts( range( 1, 150 ) );
		$this->assertCount( 100, fqj_queue_pop_posts() );
		$this->assertSame( 50, fqj_queue_length() );
	}

	public function test_pop_on_empty_or_corrupt_queue() {
		$this->assertSame( array(), fqj_queue_pop_posts() );
		set_transient( 'fqj_invalidate_queue', 'garbage' );
		$this->assertSame( array(), fqj_queue_pop_posts() );
		set_transient( 'fqj_invalidate_queue', array() );
		$this->assertSame( array(), fqj_queue_pop_posts() );
	}

	public function test_log_run_records_newest_first() {
		fqj_log_invalidation_run( 3, array( 1, 2, 3 ) );
		fqj_log_invalidation_run( 1, array( '7' ) );

		$log = get_option( 'fqj_invalidation_log' );
		$this->assertCount( 2, $log );
		$this->assertSame( 1, $log[0]['processed'] );
		$this->assertSame( array( 7 ), $log[0]['sample'] );
		$this->assertSame( 3, $log[1]['processed'] );
		$this->assertEqualsWithDelta( time(), $log[0]['ts'], 5 );
		$this->assertEqualsWithDelta( time(), (int) get_option( 'fqj_last_queue_run' ), 5 );
	}

	public function test_log_run_caps_sample_and_length() {
		fqj_log_invalidation_run( 50, range( 1, 50 ) );
		$log = get_option( 'fqj_invalidation_log' );
		$this->assertSame( range( 1, 20 ), $log[0]['sample'] );

		update_option( 'fqj_invalidation_log', array_fill( 0, 200, array( 'ts' => 1 ) ) );
		fqj_log_invalidation_run( 1 );
		$log = get_option( 'fqj_invalidation_log' );
		$this->assertCount( 200, $log );
		$this->assertSame( 1, $log[0]['processed'] );
	}

	public function test_log_run_recovers_from_corrupt_log() {
		update_option( 'fqj_invalidation_log', 'garbage' );
		fqj_log_invalidation_run( 2 );
		$this->assertCount( 1, get_option( 'fqj_invalidation_log' ) );
	}

	public function test_cron_worker_deletes_transients_in_batches() {
		update_option( FQJ_OPTION_KEY, array( 'batch_size' => 2 ) );
		foreach ( array( 1, 2, 3 ) as $id ) {
			set_transient( 'fqj_faq_json_' . $id, 'cached' );
		}
		fqj_queue_add_posts( array( 1, 2, 3 ) );

		do_action( 'fqj_process_invalidation_queue' );

		$this->assertFalse( get_transient( 'fqj_faq_json_1' ) );
		$this->assertFalse( get_transient( 'fqj_faq_json_2' ) );
		$this->assertSame( 'cached', get_transient( 'fqj_faq_json_3' ) );
		$this->assertSame( array( 3 ), $this->get_queue() );

		$log = get_option( 'fqj_invalidation_log' );
		$this->assertSame( 2, $log[0]['processed'] );
		$this->assertSame( array( 1, 2 ), $log[0]['sample'] );
	}

	public function test_cron_worker_logs_empty_run() {
		fqj_process_invalidation_queue_cron();
		$log = get_option( 'fqj_invalidation_log' );
		$this->assertSame( 0, $log[0]['processed'] );
		$this->assertNotEmpty( get_option( 'fqj_last_queue_run' ) );
	}

	public function test_cron_worker_defaults_batch_size_without_settings() {
		delete_option( FQJ_OPTION_KEY );
		fqj_queue_add_posts( range( 1, 600 ) );
		fqj_process_invalidation_queue_cron();
		$this->assertSame( 100, fqj_queue_length() );
	}

	public function test_process_now_with_limit() {
		set_transient( 'fqj_faq_json_5', 'x' );
		set_transient( 'fqj_faq_json_6', 'y' );
		fqj_queue_add_posts( array( 5, 6 ) );

		$this->assertSame( 1, fqj_process_invalidation_queue_now( 1 ) );
		$this->assertFalse( get_transient( 'fqj_faq_json_5' ) );
		$this->assertSame( 'y', get_transient( 'fqj_faq_json_6' ) );

		$this->assertSame( 1, fqj_process_invalidation_queue_now() );
		$this->assertSame( 0, fqj_process_invalidation_queue_now() );

		$log = get_option( 'fqj_invalidation_log' );
		$this->assertCount( 3, $log );
		$this->assertSame( 0, $log[0]['processed'] );
	}

	public function test_process_now_uses_batch_size_when_no_limit() {
		update_option( FQJ_OPTION_KEY, array( 'batch_size' => 3 ) );
		fqj_queue_add_posts( range( 1, 10 ) );
		$this->assertSame( 3, fqj_process_invalidation_queue_now() );
		$this->assertSame( 7, fqj_queue_length() );
	}

	public function test_admin_notice_shows_for_admins_with_pending_items() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		fqj_admin_queue_notice();
		$this->assertSame( '', ob_get_clean(), 'No notice when queue is empty.' );

		fqj_queue_add_posts( array( 1, 2 ) );
		ob_start();
		fqj_admin_queue_notice();
		$out = ob_get_clean();
		$this->assertStringContainsString( 'notice-info', $out );
		$this->assertStringContainsString( '<strong>2</strong>', $out );
	}

	public function test_admin_notice_hidden_for_non_admins() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		fqj_queue_add_posts( array( 1 ) );

		ob_start();
		fqj_admin_queue_notice();
		$this->assertSame( '', ob_get_clean() );
	}
}
