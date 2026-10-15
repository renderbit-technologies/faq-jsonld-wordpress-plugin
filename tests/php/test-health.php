<?php
/**
 * Health & Diagnostics page.
 *
 * @package FAQ_JSON_LD
 */

/**
 * Health page tests.
 */
class Test_FQJ_Health extends FQJ_TestCase {

	public function set_up() {
		parent::set_up();
		delete_option( 'fqj_invalidation_log' );
		delete_option( 'fqj_last_queue_run' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		wp_dequeue_script( 'fqj-health-js' );
		parent::tear_down();
	}

	/**
	 * Render the page.
	 *
	 * @return string
	 */
	protected function render() {
		ob_start();
		fqj_render_health_page();

		return ob_get_clean();
	}

	public function test_submenu_is_registered() {
		global $submenu;
		fqj_register_health_page();
		$slugs = wp_list_pluck( $submenu['edit.php?post_type=faq_item'], 2 );
		$this->assertContains( 'fqj-health', $slugs );
	}

	public function test_render_empty_state() {
		$html = $this->render();
		$this->assertStringContainsString( '<strong id="fqj-queue-count">0</strong>', $html );
		$this->assertMatchesRegularExpression( '#<strong id="fqj-last-run">\s*Never\s*</strong>#', $html );
		$this->assertStringContainsString( 'No invalidation runs logged yet.', $html );
		$this->assertStringContainsString( 'id="fqj-process-now"', $html );
		$this->assertStringContainsString( 'id="fqj-purge-transients"', $html );
		$this->assertStringContainsString( 'id="fqj-clear-log"', $html );
	}

	public function test_render_with_queue_and_log() {
		fqj_queue_add_posts( array( 1, 2, 3 ) );
		fqj_log_invalidation_run( 4, array( 10, 11 ) );

		$html = $this->render();
		$this->assertStringContainsString( '<strong id="fqj-queue-count">3</strong>', $html );
		$this->assertStringNotContainsString( 'Never', $html );
		$this->assertStringContainsString( '<td>4</td>', $html );
		$this->assertStringContainsString( '<td>10, 11</td>', $html );
		$this->assertStringNotContainsString( 'No invalidation runs logged yet.', $html );
	}

	public function test_render_tolerates_malformed_log_entries() {
		update_option( 'fqj_invalidation_log', array( array(), array( 'sample' => 'nope' ) ) );
		$html = $this->render();
		$this->assertStringContainsString( '<td>n/a</td>', $html );
		$this->assertStringContainsString( '<td>0</td>', $html );
	}

	public function test_render_handles_non_array_log() {
		update_option( 'fqj_invalidation_log', 'garbage' );
		$this->assertStringContainsString( 'No invalidation runs logged yet.', $this->render() );
	}

	public function test_render_escapes_log_values() {
		update_option(
			'fqj_invalidation_log',
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
		fqj_health_admin_assets( 'edit.php' );
		$this->assertFalse( wp_script_is( 'fqj-health-js', 'enqueued' ) );

		fqj_health_admin_assets( 'faq_item_page_fqj-health' );
		$this->assertTrue( wp_script_is( 'fqj-health-js', 'enqueued' ) );
		$this->assertStringContainsString( 'var fqjHealth', wp_scripts()->get_data( 'fqj-health-js', 'data' ) );
	}
}
