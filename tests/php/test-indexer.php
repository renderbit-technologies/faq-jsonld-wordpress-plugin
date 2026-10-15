<?php
/**
 * Mapping table and indexer.
 *
 * @package FAQ_JSON_LD
 */

/**
 * Indexer tests.
 */
class Test_FQJ_Indexer extends FQJ_TestCase {

	/**
	 * @dataProvider url_provider
	 *
	 * @param mixed  $input    Raw URL.
	 * @param string $expected Normalized URL.
	 */
	public function test_normalize_url( $input, $expected ) {
		$this->assertSame( $expected, fqj_normalize_url( $input ) );
	}

	public function url_provider() {
		return array(
			'trailing slash'      => array( 'https://example.com/about/', 'https://example.com/about' ),
			'no trailing slash'   => array( 'https://example.com/about', 'https://example.com/about' ),
			'query string'        => array( 'https://example.com/about/?utm=x', 'https://example.com/about' ),
			'fragment'            => array( 'https://example.com/about#faq', 'https://example.com/about' ),
			'query and fragment'  => array( 'https://example.com/a/?b=1#c', 'https://example.com/a' ),
			'many slashes'        => array( 'https://example.com/a///', 'https://example.com/a' ),
			'empty string'        => array( '', '' ),
			'null'                => array( null, '' ),
			'home with slash'     => array( 'https://example.com/', 'https://example.com' ),
			'unicode path'        => array( 'https://example.com/café/', 'https://example.com/café' ),
		);
	}

	public function test_insert_and_delete_mappings() {
		fqj_insert_mappings(
			123,
			array(
				array(
					'type'  => 'post',
					'value' => 5,
				),
				array(
					'type'  => 'url',
					'value' => 'https://example.org/a',
				),
			)
		);

		$this->assertSame(
			array(
				array(
					'mapping_type'  => 'post',
					'mapping_value' => '5',
				),
				array(
					'mapping_type'  => 'url',
					'mapping_value' => 'https://example.org/a',
				),
			),
			$this->get_mappings( 123 )
		);

		fqj_insert_mappings(
			456,
			array(
				array(
					'type'  => 'global',
					'value' => '1',
				),
			)
		);
		fqj_delete_mappings_for_faq( 123 );
		$this->assertSame( array(), $this->get_mappings( 123 ) );
		$this->assertCount( 1, $this->get_mappings( 456 ), 'Deleting one FAQ must not touch another.' );
	}

	public function test_insert_sanitizes_mapping_type() {
		fqj_insert_mappings(
			77,
			array(
				array(
					'type'  => '<b>post</b>',
					'value' => '1',
				),
			)
		);
		$rows = $this->get_mappings( 77 );
		$this->assertSame( 'post', $rows[0]['mapping_type'] );
	}

	public function test_rebuild_with_post_payload() {
		$target = self::factory()->post->create();
		$faq    = $this->create_faq( 'Q', 'A', array( 'posts' => array( $target ) ) );

		$this->assertSame(
			array(
				array(
					'mapping_type'  => 'post',
					'mapping_value' => (string) $target,
				),
			),
			$this->get_mappings( $faq )
		);
	}

	public function test_rebuild_with_url_payload_also_maps_internal_post() {
		$target = self::factory()->post->create( array( 'post_name' => 'about-us' ) );
		$faq    = $this->create_faq(
			'Q',
			'A',
			array(
				'urls' => array(
					get_permalink( $target ) . '?ref=nav',
					'https://external.example.net/page/',
				),
			)
		);

		$this->assertEqualSets(
			array(
				array(
					'mapping_type'  => 'url',
					'mapping_value' => 'http://example.org/about-us',
				),
				array(
					'mapping_type'  => 'post',
					'mapping_value' => (string) $target,
				),
				array(
					'mapping_type'  => 'url',
					'mapping_value' => 'https://external.example.net/page',
				),
			),
			$this->get_mappings( $faq )
		);
	}

	public function test_rebuild_with_post_type_payload() {
		$faq = $this->create_faq( 'Q', 'A', array( 'post_types' => array( 'page', 'post' ) ) );
		$this->assertSame(
			array(
				array(
					'mapping_type'  => 'post_type',
					'mapping_value' => 'page',
				),
				array(
					'mapping_type'  => 'post_type',
					'mapping_value' => 'post',
				),
			),
			$this->get_mappings( $faq )
		);
	}

	public function test_rebuild_with_terms_payload() {
		$term = self::factory()->category->create();
		$faq  = $this->create_faq( 'Q', 'A', array( 'terms' => array( $term ) ) );
		$this->assertSame(
			array(
				array(
					'mapping_type'  => 'term',
					'mapping_value' => (string) $term,
				),
			),
			$this->get_mappings( $faq )
		);
	}

	public function test_rebuild_with_global_payload() {
		$faq = $this->create_faq( 'Q', 'A', array( 'global' => true ) );
		$this->assertSame(
			array(
				array(
					'mapping_type'  => 'global',
					'mapping_value' => '1',
				),
			),
			$this->get_mappings( $faq )
		);
	}

	public function test_rebuild_with_global_false_adds_nothing() {
		$faq = $this->create_faq( 'Q', 'A', array( 'global' => false ) );
		$this->assertSame( array(), $this->get_mappings( $faq ) );
	}

	public function test_rebuild_replaces_previous_mappings() {
		$a   = self::factory()->post->create();
		$b   = self::factory()->post->create();
		$faq = $this->create_faq( 'Q', 'A', array( 'posts' => array( $a ) ) );

		update_post_meta( $faq, 'fqj_assoc_data_json', wp_json_encode( array( 'posts' => array( $b ) ) ) );
		fqj_rebuild_index_for_faq( $faq );

		$this->assertSame(
			array(
				array(
					'mapping_type'  => 'post',
					'mapping_value' => (string) $b,
				),
			),
			$this->get_mappings( $faq )
		);
	}

	public function test_rebuild_handles_missing_or_invalid_payload() {
		$faq = $this->create_faq();
		fqj_insert_mappings(
			$faq,
			array(
				array(
					'type'  => 'global',
					'value' => '1',
				),
			)
		);

		fqj_rebuild_index_for_faq( $faq );
		$this->assertSame( array(), $this->get_mappings( $faq ), 'No payload clears old rows.' );

		update_post_meta( $faq, 'fqj_assoc_data_json', '{not json' );
		fqj_rebuild_index_for_faq( $faq );
		$this->assertSame( array(), $this->get_mappings( $faq ) );

		update_post_meta( $faq, 'fqj_assoc_data_json', '"a string"' );
		fqj_rebuild_index_for_faq( $faq );
		$this->assertSame( array(), $this->get_mappings( $faq ) );
	}

	public function test_rebuild_ignores_non_array_payload_keys() {
		$faq = $this->create_faq(
			'Q',
			'A',
			array(
				'posts'      => 5,
				'urls'       => 'https://example.org/x',
				'post_types' => 'post',
				'terms'      => 3,
			)
		);
		$this->assertSame( array(), $this->get_mappings( $faq ) );
	}

	public function test_enqueue_ignores_empty_mappings() {
		fqj_enqueue_invalidation_for_mappings( array() );
		$this->assertSame( array(), $this->get_queue() );
	}

	public function test_enqueue_post_mapping_queues_post() {
		$target = self::factory()->post->create();
		$this->create_faq( 'Q', 'A', array( 'posts' => array( $target ) ) );
		$this->assertSame( array( $target ), $this->get_queue() );
	}

	public function test_enqueue_url_mapping_resolves_post() {
		$target = self::factory()->post->create( array( 'post_name' => 'pricing' ) );
		fqj_enqueue_invalidation_for_mappings(
			array(
				array(
					'type'  => 'url',
					'value' => get_permalink( $target ),
				),
				array(
					'type'  => 'url',
					'value' => 'https://external.example.net/nope',
				),
			)
		);
		$this->assertSame( array( $target ), $this->get_queue() );
	}

	public function test_enqueue_post_type_mapping_queues_all_posts_in_batches() {
		update_option(
			FQJ_OPTION_KEY,
			array_merge( get_option( FQJ_OPTION_KEY, array() ), array( 'batch_size' => 2 ) )
		);
		// Distinct dates keep WP_Query's date ordering stable across pages.
		$pages = array();
		for ( $i = 0; $i < 6; $i++ ) {
			$pages[] = self::factory()->post->create(
				array(
					'post_type'   => 'page',
					'post_status' => 5 === $i ? 'draft' : 'publish',
					'post_date'   => gmdate( 'Y-m-d H:i:s', strtotime( '2024-01-01' ) + $i * DAY_IN_SECONDS ),
				)
			);
		}
		self::factory()->post->create(); // A 'post' that must not be queued.

		fqj_enqueue_invalidation_for_mappings(
			array(
				array(
					'type'  => 'post_type',
					'value' => 'page',
				),
			)
		);

		$this->assertEqualSets( $pages, $this->get_queue() );
	}

	public function test_enqueue_term_mapping_queues_tagged_posts() {
		$term   = self::factory()->category->create();
		$tagged = self::factory()->post->create_many( 3 );
		foreach ( $tagged as $pid ) {
			wp_set_post_categories( $pid, array( $term ) );
		}
		self::factory()->post->create();

		fqj_enqueue_invalidation_for_mappings(
			array(
				array(
					'type'  => 'term',
					'value' => $term,
				),
				array(
					'type'  => 'term',
					'value' => 999999,
				),
			)
		);

		$this->assertEqualSets( $tagged, $this->get_queue() );
	}

	public function test_enqueue_global_mapping_purges_all_faq_transients() {
		set_transient( 'fqj_faq_json_1', 'x' );
		set_transient( 'fqj_faq_json_2', 'y' );
		set_transient( 'unrelated_transient', 'z' );

		fqj_enqueue_invalidation_for_mappings(
			array(
				array(
					'type'  => 'global',
					'value' => '1',
				),
			)
		);

		$this->assertFalse( get_transient( 'fqj_faq_json_1' ) );
		$this->assertFalse( get_transient( 'fqj_faq_json_2' ) );
		$this->assertSame( 'z', get_transient( 'unrelated_transient' ) );
	}

	public function test_enqueue_ignores_unknown_mapping_type() {
		fqj_enqueue_invalidation_for_mappings(
			array(
				array(
					'type'  => 'bogus',
					'value' => '1',
				),
			)
		);
		$this->assertSame( array(), $this->get_queue() );
	}
}
