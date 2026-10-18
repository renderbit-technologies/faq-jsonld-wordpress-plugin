<?php
/**
 * Shared helpers for plugin tests.
 *
 * @package FAQ_JSON_LD
 */

// phpcs:disable WordPress.Files.FileName

/**
 * Base test case.
 */
abstract class FQJ_TestCase extends WP_UnitTestCase {

	/**
	 * Use pretty permalinks so URL mappings resolve to distinct paths.
	 */
	public function set_up() {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		delete_transient( 'fqj_invalidate_queue' );
	}

	/**
	 * Reset request globals the handlers read.
	 */
	public function tear_down() {
		$_POST    = array();
		$_REQUEST = array();
		unset( $GLOBALS['post'] );
		parent::tear_down();
	}

	/**
	 * Create a published FAQ item.
	 *
	 * @param string $question Title.
	 * @param string $answer   Content.
	 * @param array  $payload  Association payload stored as JSON meta.
	 * @return int
	 */
	protected function create_faq( $question = 'What is it?', $answer = 'It is a thing.', $payload = null ) {
		$faq_id = self::factory()->post->create(
			array(
				'post_type'    => 'faq_item',
				'post_status'  => 'publish',
				'post_title'   => $question,
				'post_content' => $answer,
			)
		);
		if ( null !== $payload ) {
			update_post_meta( $faq_id, 'fqj_assoc_data_json', wp_json_encode( $payload ) );
			fqj_rebuild_index_for_faq( $faq_id );
		}

		return $faq_id;
	}

	/**
	 * Mapping rows for a FAQ as type/value pairs.
	 *
	 * @param int $faq_id FAQ ID.
	 * @return array
	 */
	protected function get_mappings( $faq_id ) {
		global $wpdb;
		$table = fqj_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT mapping_type, mapping_value FROM {$table} WHERE faq_id = %d ORDER BY id", $faq_id ), ARRAY_A );
	}

	/**
	 * Current queue contents.
	 *
	 * @return array
	 */
	protected function get_queue() {
		$queue = get_transient( 'fqj_invalidate_queue' );

		return is_array( $queue ) ? $queue : array();
	}

	/**
	 * Run the frontend printer for a post and return what it echoed.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	protected function render_head_for( $post_id ) {
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		ob_start();
		fqj_maybe_print_faq_jsonld();

		return ob_get_clean();
	}

	/**
	 * Decode the JSON-LD from printed output.
	 *
	 * @param string $html Output.
	 * @return array|null
	 */
	protected function extract_json_ld( $html ) {
		if ( ! preg_match( '#<script type="application/ld\+json">(.*?)</script>#s', $html, $m ) ) {
			return null;
		}

		return json_decode( $m[1], true );
	}
}
