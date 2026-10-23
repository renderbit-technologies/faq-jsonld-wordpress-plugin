<?php
/**
 * Health & Diagnostics page.
 *
 * @package Renderbit_FAQ_Structured_Data
 */

/**
 * Health page tests.
 */
class Test_RFSD_Health extends RFSD_TestCase {

	public function set_up() {
		parent::set_up();
		delete_option( 'rfsd_invalidation_log' );
		delete_option( 'rfsd_last_queue_run' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		wp_dequeue_script( 'rfsd-health-js' );
		parent::tear_down();
	}

	/**
	 * Render the page.
	 *
	 * @return string
	 */
	protected function render() {
		ob_start();
		rfsd_render_health_page();

		return ob_get_clean();
	}

	public function test_submenu_is_registered() {
		global $submenu;
		rfsd_register_health_page();
		$slugs = wp_list_pluck( $submenu['edit.php?post_type=rfsd_faq'], 2 );
		$this->assertContains( 'rfsd-health', $slugs );
	}

	public function test_render_empty_state() {
		$html = $this->render();
		$this->assertStringContainsString( '<strong id="rfsd-queue-count">0</strong>', $html );
		$this->assertMatchesRegularExpression( '#<strong id="rfsd-last-run">\s*Never\s*</strong>#', $html );
		$this->assertStringContainsString( 'No invalidation runs logged yet.', $html );
		$this->assertStringContainsString( 'id="rfsd-process-now"', $html );
		$this->assertStringContainsString( 'id="rfsd-purge-transients"', $html );
		$this->assertStringContainsString( 'id="rfsd-clear-log"', $html );
	}

	public function test_render_with_queue_and_log() {
		rfsd_queue_add_posts( array( 1, 2, 3 ) );
		rfsd_log_invalidation_run( 4, array( 10, 11 ) );

		$html = $this->render();
		$this->assertStringContainsString( '<strong id="rfsd-queue-count">3</strong>', $html );
		$this->assertStringNotContainsString( 'Never', $html );
		$this->assertStringContainsString( '<td>4</td>', $html );
		$this->assertStringContainsString( '<td>10, 11</td>', $html );
		$this->assertStringNotContainsString( 'No invalidation runs logged yet.', $html );
	}

	public function test_render_tolerates_malformed_log_entries() {
		update_option( 'rfsd_invalidation_log', array( array(), array( 'sample' => 'nope' ) ) );
		$html = $this->render();
		$this->assertStringContainsString( '<td>n/a</td>', $html );
		$this->assertStringContainsString( '<td>0</td>', $html );
	}

	public function test_render_handles_non_array_log() {
		update_option( 'rfsd_invalidation_log', 'garbage' );
		$this->assertStringContainsString( 'No invalidation runs logged yet.', $this->render() );
	}

	public function test_render_escapes_log_values() {
		update_option(
			'rfsd_invalidation_log',
			array(
				array(
					'ts'        => time(),
					'processed' => '<b>5</b>',
					'sample'    => array( '<script>' ),
				),
			)
		);
		$html = $this->render();
		$this->assertStringNotContainsString( '<script>', $html );
	}

	public function test_non_admin_sees_nothing() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( '', $this->render() );
	}

	public function test_assets_only_on_health_page() {
		rfsd_health_admin_assets( 'edit.php' );
		$this->assertFalse( wp_script_is( 'rfsd-health-js', 'enqueued' ) );

		rfsd_health_admin_assets( 'rfsd_faq_page_rfsd-health' );
		$this->assertTrue( wp_script_is( 'rfsd-health-js', 'enqueued' ) );
		$this->assertStringContainsString( 'var rfsdHealth', wp_scripts()->get_data( 'rfsd-health-js', 'data' ) );
	}
}
