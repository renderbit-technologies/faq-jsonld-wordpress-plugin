<?php
/**
 * Coverage module: the forward lookup and the inverse (posts covered by a mapping) must agree.
 *
 * @package Renderbit_FAQ_Structured_Data
 */

/**
 * Coverage tests.
 */
class Test_RFSD_Coverage extends RFSD_TestCase {

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
		register_post_type( 'rfsd_book', array( 'public' => true ) );
		register_taxonomy( 'rfsd_genre', 'rfsd_book', array( 'public' => true ) );
	}

	/**
	 * Remove the custom post type and taxonomy.
	 */
	public function tear_down() {
		unregister_taxonomy( 'rfsd_genre' );
		unregister_post_type( 'rfsd_book' );
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
				'taxonomy' => 'rfsd_genre',
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
				'post_type'  => 'rfsd_book',
				'post_title' => 'Dragons',
			)
		);
		wp_set_object_terms( $this->posts['book'], array( $genre ), 'rfsd_genre' );
		$this->posts['private'] = self::factory()->post->create(
			array(
				'post_title'    => 'Secret',
				'post_status'   => 'private',
				'post_category' => array( $category ),
			)
		);
		$this->posts['draft']   = self::factory()->post->create(
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
		foreach ( array( 'page', 'post', 'rfsd_book' ) as $post_type ) {
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
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT faq_id, mapping_type, mapping_value FROM %i', rfsd_table_name() ), ARRAY_A );

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

			if ( rfsd_mapping_covers_everything( $mapping ) ) {
				$this->assertSame( 'global', $mapping['type'], $label );
				foreach ( $this->posts as $name => $post_id ) {
					$this->assertNotEmpty( array_intersect( $entry['faq_ids'], rfsd_faq_ids_for_post( $post_id ) ), "{$label} must match {$name}" );
				}
				continue;
			}

			$covered = rfsd_posts_covered_by_mapping( $mapping, 0, 1000 );
			foreach ( $this->posts as $name => $post_id ) {
				$matches = rfsd_post_is_coverable( $post_id )
					&& array() !== array_intersect( $entry['faq_ids'], rfsd_faq_ids_for_post( $post_id ) );
				$this->assertSame( $matches, in_array( $post_id, $covered, true ), "{$label} covering {$name}" );
			}
		}
	}

	public function test_only_published_posts_are_coverable() {
		$this->create_fixtures();

		$this->assertTrue( rfsd_post_is_coverable( $this->posts['page'] ) );
		$this->assertTrue( rfsd_post_is_coverable( $this->posts['book'] ) );
		$this->assertFalse( rfsd_post_is_coverable( $this->posts['private'] ) );
		$this->assertFalse( rfsd_post_is_coverable( $this->posts['draft'] ) );
		$this->assertFalse( rfsd_post_is_coverable( 0 ) );
	}

	public function test_faq_ids_for_post_are_ascending_and_unique() {
		$post_id = self::factory()->post->create();
		$second  = $this->create_faq( 'B?', 'B.', array( 'posts' => array( $post_id ) ) );
		$first   = $this->create_faq( 'A?', 'A.', array( 'post_types' => array( 'post' ) ) );
		$this->create_faq( 'C?', 'C.', array( 'posts' => array( self::factory()->post->create() ) ) );
		update_post_meta(
			$first,
			'rfsd_assoc_data_json',
			wp_json_encode(
				array(
					'posts'      => array( $post_id ),
					'post_types' => array( 'post' ),
				)
			)
		);
		rfsd_rebuild_index_for_faq( $first );

		$this->assertSame( array( $second, $first ), rfsd_faq_ids_for_post( $post_id ) );
	}

	public function test_covered_posts_respect_cursor_and_limit() {
		$ids = self::factory()->post->create_many( 3 );
		sort( $ids );
		$mapping = array(
			'type'  => 'post_type',
			'value' => 'post',
		);

		$this->assertSame( array( $ids[1] ), rfsd_posts_covered_by_mapping( $mapping, $ids[0], 1 ) );
		$this->assertSame( array( $ids[2] ), rfsd_posts_covered_by_mapping( $mapping, $ids[1], 10 ) );
		$this->assertSame(
			array(),
			rfsd_posts_covered_by_mapping(
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
	 * @expectedIncorrectUsage rfsd_posts_covered_by_mapping
	 */
	public function test_covered_posts_for_global_is_a_programming_error() {
		self::factory()->post->create();
		$mapping = array(
			'type'  => 'global',
			'value' => '1',
		);

		$this->assertTrue( rfsd_mapping_covers_everything( $mapping ) );
		$this->assertSame( array(), rfsd_posts_covered_by_mapping( $mapping ) );
	}

	public function test_normalize_url_is_idempotent() {
		foreach ( array( 'https://example.org/a/b/#x', 'https://example.org/?p=5&utm_source=x', 'https://example.org/' ) as $url ) {
			$once = rfsd_normalize_url( $url );
			$this->assertSame( $once, rfsd_normalize_url( $once ), $url );
		}
	}

	public function test_post_for_url_needs_the_permalink_itself() {
		$post = self::factory()->post->create( array( 'post_name' => 'hello' ) );
		$page = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'welcome',
			)
		);
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page );

		$this->assertSame( $post, rfsd_post_for_url( get_permalink( $post ) ) );
		$this->assertSame( $post, rfsd_post_for_url( '/hello/' ) );
		$this->assertSame( $post, rfsd_post_for_url( 'https://www.example.org/hello?utm=x#top' ) );
		$this->assertSame( 0, rfsd_post_for_url( home_url( '/?p=' . $post ) ), 'A shortlink is not the permalink.' );
		$this->assertSame( $post, rfsd_post_routed_from_url( home_url( '/?p=' . $post ) ) );
		$this->assertSame( $page, rfsd_post_for_url( home_url( '/' ) ) );
		$this->assertSame( 0, rfsd_post_for_url( home_url( '/welcome/' ) ), 'The front page slug URL is not its permalink.' );
		$this->assertSame( $page, rfsd_post_routed_from_url( home_url( '/welcome/' ) ) );
		$this->assertSame( 0, rfsd_post_for_url( 'https://external.example.net/hello' ) );
		$this->assertSame( 0, rfsd_post_routed_from_url( 'https://external.example.net/hello' ) );
	}

	public function test_post_for_url_resolves_custom_post_types_with_plain_permalinks() {
		$this->set_permalink_structure( '' );
		$book = self::factory()->post->create(
			array(
				'post_type' => 'rfsd_book',
				'post_name' => 'dragons',
			)
		);

		$this->assertSame( '/?rfsd_book=dragons', rfsd_normalize_url( get_permalink( $book ) ) );
		$this->assertSame( $book, rfsd_post_for_url( get_permalink( $book ) ) );
		$this->assertSame( 0, rfsd_post_for_url( home_url( '/?rfsd_book=nothing' ) ) );
	}

	public function test_normalize_url_below_a_subdirectory_home() {
		update_option( 'home', 'http://example.org/blog' );

		$this->assertSame( '/about', rfsd_normalize_url( 'http://example.org/blog/about/' ) );
		$this->assertSame( '/', rfsd_normalize_url( 'https://example.org/blog/' ) );
		$this->assertSame( '/?p=3', rfsd_normalize_url( 'http://example.org/blog/?p=3' ) );
		$this->assertSame( 'http://example.org/blogroll', rfsd_normalize_url( 'http://example.org/blogroll/' ) );
		$this->assertSame( 'http://example.org/other', rfsd_normalize_url( 'http://example.org/other' ) );
	}

	/**
	 * Settings that change every post's URL.
	 *
	 * @return array
	 */
	public function url_settings() {
		return array(
			'permalink structure' => array( 'permalink_structure', '/%year%/%postname%/' ),
			'category base'       => array( 'category_base', 'topics' ),
			'tag base'            => array( 'tag_base', 'labels' ),
			'home'                => array( 'home', 'https://example.org' ),
			'show on front'       => array( 'show_on_front', 'page' ),
			'page on front'       => array( 'page_on_front', '123' ),
		);
	}

	/**
	 * @dataProvider url_settings
	 *
	 * @param string $option Option name.
	 * @param string $value  New value.
	 */
	public function test_url_settings_change_purges_all_cached_output( $option, $value ) {
		$generation = rfsd_cache_generation();
		update_option( $option, $value );
		$this->assertGreaterThan( $generation, rfsd_cache_generation() );
	}

	public function test_renaming_a_parent_invalidates_descendants() {
		$parent     = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'parent',
			)
		);
		$child      = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'child',
				'post_parent' => $parent,
			)
		);
		$grandchild = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'grandchild',
				'post_parent' => $child,
			)
		);
		$other      = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->create_faq( 'Child?', 'Yes.', array( 'urls' => array( get_permalink( $child ) ) ) );
		$this->assertSame( array( $child, $grandchild ), rfsd_descendant_post_ids( $parent ) );

		rfsd_queue_pop_posts( 1000 );
		wp_update_post(
			array(
				'ID'         => $parent,
				'post_title' => 'Same slug',
			)
		);
		$this->assertSame( array(), $this->get_queue(), 'A title change leaves descendants alone.' );

		wp_update_post(
			array(
				'ID'        => $parent,
				'post_name' => 'renamed',
			)
		);
		$this->assertEqualSets( array( $child, $grandchild ), $this->get_queue() );
		$this->assertNotContains( $other, $this->get_queue() );
		$this->assertSame( array(), rfsd_faq_ids_for_post( $child ), 'The URL association no longer matches the moved child.' );
	}
}
