<?php
/**
 * WP-CLI commands.
 *
 * @package Renderbit_FAQ_Structured_Data
 */

/**
 * CLI tests.
 */
class Test_RFSD_Cli extends RFSD_TestCase {

	/**
	 * Command instance.
	 *
	 * @var RFSD\Rfsd_Cli
	 */
	protected $cli;

	public function set_up() {
		parent::set_up();
		WP_CLI::reset();
		$this->cli = new RFSD\Rfsd_Cli();
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
		$this->assertSame( 'RFSD\Rfsd_Cli', WP_CLI::get_command( 'rfsd' ) );
	}

	public function test_purge_transients_bumps_generation() {
		$key = rfsd_cache_key( 1 );
		set_transient( $key, 'a' );
		set_transient( 'keep_me', 'c' );
		$gen = rfsd_cache_generation();

		$this->cli->purge_transients( array(), array() );

		$this->assertFalse( get_transient( $key ) );
		$this->assertSame( 'c', get_transient( 'keep_me' ) );
		$this->assertSame( $gen + 1, rfsd_cache_generation() );
		$this->assertSame( 'Purged FAQ cache (generation ' . ( $gen + 1 ) . ').', $this->last_success() );
	}

	public function test_process_queue_with_limit() {
		rfsd_queue_add_posts( array( 1, 2, 3 ) );
		$this->cli->process_queue( array(), array( 'limit' => '2' ) );

		$this->assertSame( 'Processed 2 invalidation items.', $this->last_success() );
		$this->assertSame( 1, rfsd_queue_length() );
	}

	public function test_process_queue_without_limit_uses_batch_size() {
		update_option( RFSD_OPTION_KEY, array( 'batch_size' => 500 ) );
		rfsd_queue_add_posts( range( 1, 5 ) );
		$this->cli->process_queue( array(), array() );

		$this->assertSame( 'Processed 5 invalidation items.', $this->last_success() );
		$this->assertSame( 0, rfsd_queue_length() );
	}

	public function test_queue_info() {
		rfsd_queue_add_posts( array( 4, 5 ) );
		$this->cli->queue_info( array(), array() );
		$this->assertSame( 'Queue length: 2', $this->last_success() );
	}

	/**
	 * WP-CLI names a subcommand after its method unless @subcommand says otherwise; the readme documents the
	 * hyphenated names.
	 *
	 * @dataProvider subcommand_provider
	 *
	 * @param string $method     Method name.
	 * @param string $subcommand Documented subcommand.
	 */
	public function test_subcommands_use_the_documented_names( $method, $subcommand ) {
		$doc = ( new ReflectionMethod( 'RFSD\Rfsd_Cli', $method ) )->getDocComment();
		$this->assertStringContainsString( "@subcommand {$subcommand}\n", $doc );
		$this->assertStringContainsString( "@alias {$method}\n", $doc, 'The underscore name keeps working.' );
		$this->assertStringContainsString( "`wp rfsd {$subcommand}", file_get_contents( RFSD_PLUGIN_DIR . 'readme.txt' ) );
	}

	public function subcommand_provider() {
		return array(
			array( 'purge_transients', 'purge-transients' ),
			array( 'process_queue', 'process-queue' ),
			array( 'queue_info', 'queue-info' ),
		);
	}
}
