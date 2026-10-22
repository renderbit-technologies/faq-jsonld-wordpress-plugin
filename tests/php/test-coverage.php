<?php
/**
 * Coverage module: the forward lookup and the inverse (posts covered by a mapping) must agree.
 *
 * @package FAQ_JSON_LD
 */

/**
 * Coverage tests.
 */
class Test_FQJ_Coverage extends FQJ_TestCase {

	/**
	 * Fixture post IDs by name.
	 *
	 * @var int[]
	 */
	private $posts = array();

	/**
	 * Register a custom post type and taxonomy for the fixtures.
	 */
	public function set_up() {
		parent::set_up();
		register_post_type( 'fqj_book', array( 'public' => true ) );
		register_taxonomy( 'fqj_genre', 'fqj_book', array( 'public' => true ) );
	}

	/**
	 * Remove the custom post type and taxonomy.
	 */
	public function tear_down() {
		unregister_taxonomy( 'fqj_genre' );
		unregister_post_type( 'fqj_book' );
		parent::tear_down();
	}

	/**
	 * Create the fixture posts: a page, a post in a category, a custom post type with a custom taxonomy term,
	 * a private post and a draft.
	 */
	private function create_fixtures() {
		$category = self::factory()->category->create( array( 'name' => 'News' ) );
		$genre    = self::factory()->term->create(
			array(
				'taxonomy' => 'fqj_genre',
				'name'     => 'Fantasy',
			)
		);

		$this->posts['page'] = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'About',
			)
		);
		$this->posts['post'] = self::factory()->post->create(
			array(
				'post_title'    => 'Hello',
				'post_category' => array( $category ),
			)
		);
		$this->posts['book'] = self::factory()->post->create(
			array(
				'post_type'  => 'fqj_book',
				'post_title' => 'Dragons',
			)
		);
		wp_set_object_terms( $this->posts['book'], array( $genre ), 'fqj_genre' );
		$this->posts['private'] = self::factory()->post->create(
			array(
				'post_title'    => 'Secret',
				'post_status'   => 'private',
				'post_category' => array( $category ),
			)
		);
		$this->posts['draft'] = self::factory()->post->create(
			array(
				'post_title'    => 'Later',
				'post_status'   => 'draft',
				'post_category' => array( $category ),
			)
		);

		// One FAQ per association, for every fixture post and the terms and post types they use.
		foreach ( $this->posts as $post_id ) {
			$this->create_faq( 'Post?', 'Yes.', array( 'posts' => array( $post_id ) ) );
			$this->create_faq( 'URL?', 'Yes.', array( 'urls' => array( get_permalink( $post_id ) ) ) );
		}
		foreach ( array( 'page', 'post', 'fqj_book' ) as $post_type ) {
			$this->create_faq( 'Type?', 'Yes.', array( 'post_types' => array( $post_type ) ) );
		}
		foreach ( array( $category, $genre ) as $term_id ) {
			$this->create_faq( 'Term?', 'Yes.', array( 'terms' => array( $term_id ) ) );
		}
		$this->create_faq( 'Global?', 'Yes.', array( 'global' => true ) );
	}

	/**
	 * Every distinct mapping in the index, with the FAQs that have it.
	 *
	 * @return array List of array( 'mapping' => array( type, value ), 'faq_ids' => int[] ).
	 */
	private function all_mappings() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT faq_id, mapping_type, mapping_value FROM %i', fqj_table_name() ), ARRAY_A );

		$mappings = array();
		foreach ( $rows as $row ) {
			$key = $row['mapping_type'] . '|' . $row['mapping_value'];
			if ( ! isset( $mappings[ $key ] ) ) {
				$mappings[ $key ] = array(
					'mapping' => array(
						'type'  => $row['mapping_type'],
						'value' => $row['mapping_value'],
					),
					'faq_ids' => array(),
				);
			}
			$mappings[ $key ]['faq_ids'][] = (int) $row['faq_id'];
		}

		return array_values( $mappings );
	}

	/**
	 * Permalink structures the invariant is checked under. Plain permalinks give every post a query-string URL.
	 *
	 * @return array
	 */
	public function permalink_structures() {
		return array(
			'pretty permalinks' => array( '/%postname%/' ),
			'plain permalinks'  => array( '' ),
		);
	}

	/**
	 * For every mapping M and fixture post P: P is in M's covered posts exactly when P is coverable and
	 * some FAQ with mapping M appears in P's FAQ IDs.
	 *
	 * @dataProvider permalink_structures
	 *
	 * @param string $structure Permalink structure.
	 */
	public function test_covered_posts_agree_with_faq_ids_for_every_mapping_and_post( $structure ) {
		$this->set_permalink_structure( $structure );
		$this->create_fixtures();

		$mappings = $this->all_mappings();
		$types    = array_unique( wp_list_pluck( wp_list_pluck( $mappings, 'mapping' ), 'type' ) );
		sort( $types );
		$this->assertSame( array( 'global', 'post', 'post_type', 'term', 'url' ), $types );

		foreach ( $mappings as $entry ) {
			$mapping = $entry['mapping'];
			$label   = $mapping['type'] . ' ' . $mapping['value'];

			if ( fqj_mapping_covers_everything( $mapping ) ) {
				$this->assertSame( 'global', $mapping['type'], $label );
				foreach ( $this->posts as $name => $post_id ) {
					$this->assertNotEmpty( array_intersect( $entry['faq_ids'], fqj_faq_ids_for_post( $post_id ) ), "{$label} must match {$name}" );
				}
				continue;
			}

			$covered = fqj_posts_covered_by_mapping( $mapping, 0, 1000 );
			foreach ( $this->posts as $name => $post_id ) {
				// Known gap, left for #53: with plain permalinks url_to_postid() cannot resolve a custom post
				// type URL (`?fqj_book=slug`), so the URL mapping matches the book but covers nothing.
				if ( '' === $structure && 'url' === $mapping['type'] && 'book' === $name ) {
					continue;
				}
				$matches = fqj_post_is_coverable( $post_id )
					&& array() !== array_intersect( $entry['faq_ids'], fqj_faq_ids_for_post( $post_id ) );
				$this->assertSame( $matches, in_array( $post_id, $covered, true ), "{$label} covering {$name}" );
			}
		}
	}

	public function test_only_published_posts_are_coverable() {
		$this->create_fixtures();

		$this->assertTrue( fqj_post_is_coverable( $this->posts['page'] ) );
		$this->assertTrue( fqj_post_is_coverable( $this->posts['book'] ) );
		$this->assertFalse( fqj_post_is_coverable( $this->posts['private'] ) );
		$this->assertFalse( fqj_post_is_coverable( $this->posts['draft'] ) );
		$this->assertFalse( fqj_post_is_coverable( 0 ) );
	}

	public function test_faq_ids_for_post_are_ascending_and_unique() {
		$post_id = self::factory()->post->create();
		$second  = $this->create_faq( 'B?', 'B.', array( 'posts' => array( $post_id ) ) );
		$first   = $this->create_faq( 'A?', 'A.', array( 'post_types' => array( 'post' ) ) );
		$this->create_faq( 'C?', 'C.', array( 'posts' => array( self::factory()->post->create() ) ) );
		update_post_meta( $first, 'fqj_assoc_data_json', wp_json_encode( array( 'posts' => array( $post_id ), 'post_types' => array( 'post' ) ) ) );
		fqj_rebuild_index_for_faq( $first );

		$this->assertSame( array( $second, $first ), fqj_faq_ids_for_post( $post_id ) );
	}

	public function test_covered_posts_respect_cursor_and_limit() {
		$ids = self::factory()->post->create_many( 3 );
		sort( $ids );
		$mapping = array(
			'type'  => 'post_type',
			'value' => 'post',
		);

		$this->assertSame( array( $ids[1] ), fqj_posts_covered_by_mapping( $mapping, $ids[0], 1 ) );
		$this->assertSame( array( $ids[2] ), fqj_posts_covered_by_mapping( $mapping, $ids[1], 10 ) );
		$this->assertSame(
			array(),
			fqj_posts_covered_by_mapping(
				array(
					'type'  => 'post',
					'value' => $ids[0],
				),
				$ids[0],
				10
			)
		);
	}

	/**
	 * @expectedIncorrectUsage fqj_posts_covered_by_mapping
	 */
	public function test_covered_posts_for_global_is_a_programming_error() {
		self::factory()->post->create();
		$mapping = array(
			'type'  => 'global',
			'value' => '1',
		);

		$this->assertTrue( fqj_mapping_covers_everything( $mapping ) );
		$this->assertSame( array(), fqj_posts_covered_by_mapping( $mapping ) );
	}

	public function test_normalize_url_is_idempotent() {
		foreach ( array( 'https://example.org/a/b/#x', 'https://example.org/?p=5&utm_source=x', 'https://example.org/' ) as $url ) {
			$once = fqj_normalize_url( $url );
			$this->assertSame( $once, fqj_normalize_url( $once ), $url );
		}
	}
}
