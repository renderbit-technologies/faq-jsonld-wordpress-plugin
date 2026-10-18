<?php
/**
 * WP-CLI commands.
 *
 * @package FAQ_JSON_LD
 */

/**
 * CLI tests.
 */
class Test_FQJ_Cli extends FQJ_TestCase {

	/**
	 * Command instance.
	 *
	 * @var FQJ\FqjCli
	 */
	protected $cli;

	public function set_up() {
		parent::set_up();
		WP_CLI::reset();
		$this->cli = new FQJ\FqjCli();
	}

	/**
	 * Last success message.
	 *
	 * @return string
	 */
	protected function last_success() {
		$success = array_filter(
			WP_CLI::get_messages(),
			function ( $m ) {
				return 'success' === $m[0];
			}
		);
		$last    = end( $success );

		return $last ? $last[1] : '';
	}

	public function test_command_is_registered() {
		$this->assertSame( 'FQJ\FqjCli', WP_CLI::get_command( 'fqj' ) );
	}

	public function test_purge_transients_bumps_generation() {
		$key = fqj_cache_key( 1 );
		set_transient( $key, 'a' );
		set_transient( 'keep_me', 'c' );
		$gen = fqj_cache_generation();

		$this->cli->purge_transients( array(), array() );

		$this->assertFalse( get_transient( $key ) );
		$this->assertSame( 'c', get_transient( 'keep_me' ) );
		$this->assertSame( $gen + 1, fqj_cache_generation() );
		$this->assertSame( 'Purged FAQ cache (generation ' . ( $gen + 1 ) . ').', $this->last_success() );
	}

	public function test_process_queue_with_limit() {
		fqj_queue_add_posts( array( 1, 2, 3 ) );
		$this->cli->process_queue( array(), array( 'limit' => '2' ) );

		$this->assertSame( 'Processed 2 invalidation items.', $this->last_success() );
		$this->assertSame( 1, fqj_queue_length() );
	}

	public function test_process_queue_without_limit_uses_batch_size() {
		update_option( FQJ_OPTION_KEY, array( 'batch_size' => 500 ) );
		fqj_queue_add_posts( range( 1, 5 ) );
		$this->cli->process_queue( array(), array() );

		$this->assertSame( 'Processed 5 invalidation items.', $this->last_success() );
		$this->assertSame( 0, fqj_queue_length() );
	}

	public function test_queue_info() {
		fqj_queue_add_posts( array( 4, 5 ) );
		$this->cli->queue_info( array(), array() );
		$this->assertSame( 'Queue length: 2', $this->last_success() );
	}
}
