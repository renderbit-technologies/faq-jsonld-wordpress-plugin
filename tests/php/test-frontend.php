<?php
/**
 * JSON-LD output on the frontend.
 *
 * @package Renderbit_FAQ_Structured_Data
 */

/**
 * Frontend tests.
 */
class Test_RFSD_Frontend extends RFSD_TestCase {

	public function test_no_output_without_queried_post() {
		$this->go_to( home_url( '/' ) );
		ob_start();
		rfsd_maybe_print_faq_jsonld();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_no_output_in_admin() {
		$post = self::factory()->post->create();
		$this->create_faq( 'Q', 'A', array( 'global' => true ) );

		$this->go_to( get_permalink( $post ) );
		set_current_screen( 'dashboard' );
		ob_start();
		rfsd_maybe_print_faq_jsonld();
		$this->assertSame( '', ob_get_clean() );
		set_current_screen( 'front' );
	}

	public function test_post_mapping_outputs_faq_page() {
		$post = self::factory()->post->create();
		$this->create_faq( 'How much?', 'Ten dollars.', array( 'posts' => array( $post ) ) );

		$html = $this->render_head_for( $post );
		$this->assertStringContainsString( '(generated)', $html );

		$this->assertSame(
			array(
				'@context'   => 'https://schema.org',
				'@type'      => 'FAQPage',
				'mainEntity' => array(
					array(
						'@type'          => 'Question',
						'name'           => 'How much?',
						'acceptedAnswer' => array(
							'@type' => 'Answer',
							'text'  => 'Ten dollars.',
						),
					),
				),
			),
			$this->extract_json_ld( $html )
		);
	}

	public function test_faqpage_output_type_setting() {
		update_option( RFSD_OPTION_KEY, array( 'output_type' => 'faqpage' ) );
		$post = self::factory()->post->create();
		$this->create_faq( 'Q', 'A', array( 'posts' => array( $post ) ) );

		$json = $this->extract_json_ld( $this->render_head_for( $post ) );
		$this->assertSame( 'FAQPage', $json['@type'] );
	}

	public function test_unknown_output_type_falls_back_to_faq_page() {
		update_option( RFSD_OPTION_KEY, array( 'output_type' => 'nonsense' ) );
		$post = self::factory()->post->create();
		$this->create_faq( 'Q', 'A', array( 'posts' => array( $post ) ) );

		$json = $this->extract_json_ld( $this->render_head_for( $post ) );
		$this->assertSame( 'FAQPage', $json['@type'] );
	}

	public function test_unmapped_post_gets_negative_cache() {
		$post  = self::factory()->post->create();
		$other = self::factory()->post->create();
		$this->create_faq( 'Q', 'A', array( 'posts' => array( $other ) ) );

		$html = $this->render_head_for( $post );
		$this->assertStringContainsString( 'generated, no output', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertSame( '__RFSD_EMPTY__', get_transient( rfsd_cache_key( $post ) ) );

		$this->assertStringContainsString( 'cached, no output', $this->render_head_for( $post ) );
	}

	public function test_cached_output_is_served_from_transient() {
		$post = self::factory()->post->create();
		set_transient( rfsd_cache_key( $post ), '<script type="application/ld+json">{"cached":true}</script>' );

		$html = $this->render_head_for( $post );
		$this->assertStringContainsString( '(cached)', $html );
		$this->assertSame( array( 'cached' => true ), $this->extract_json_ld( $html ) );
	}

	public function test_blank_cached_value_reports_unknown_state() {
		$post = self::factory()->post->create();
		set_transient( rfsd_cache_key( $post ), '   ' );
		$this->assertStringContainsString( 'cached, unknown state', $this->render_head_for( $post ) );
	}

	public function test_generated_output_is_cached_with_ttl() {
		update_option( RFSD_OPTION_KEY, array( 'cache_ttl' => 600 ) );
		$post = self::factory()->post->create();
		$this->create_faq( 'Q', 'A', array( 'posts' => array( $post ) ) );

		$html   = $this->render_head_for( $post );
		$cached = get_transient( rfsd_cache_key( $post ) );
		$this->assertStringStartsWith( '<script type="application/ld+json">', $cached );
		$this->assertStringContainsString( $cached, $html );

		$timeout = (int) get_option( '_transient_timeout_' . rfsd_cache_key( $post ) );
		$this->assertEqualsWithDelta( time() + 600, $timeout, 5 );
	}

	public function test_private_posts_render_without_cached_output() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$post = self::factory()->post->create( array( 'post_status' => 'private' ) );
		$this->create_faq( 'Private?', 'Yes.', array( 'posts' => array( $post ) ) );
		set_transient( rfsd_cache_key( $post ), '__RFSD_EMPTY__' );

		$html = $this->render_head_for( $post );
		$json = $this->extract_json_ld( $html );

		$this->assertSame( 'Private?', $json['mainEntity'][0]['name'], 'Never served from cache.' );
		$this->assertStringContainsString( '(uncached)', $html );
		$this->assertSame( '__RFSD_EMPTY__', get_transient( rfsd_cache_key( $post ) ), 'Never written to cache.' );
	}

	public function test_draft_preview_renders_without_cached_output() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$post = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$this->go_to( get_preview_post_link( $post ) );
		ob_start();
		rfsd_maybe_print_faq_jsonld();
		$html = ob_get_clean();

		$this->assertTrue( is_preview() );
		$this->assertStringContainsString( '(uncached, no output)', $html );
		$this->assertFalse( get_transient( rfsd_cache_key( $post ) ) );
	}

	public function test_post_type_mapping() {
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$post = self::factory()->post->create();
		$this->create_faq( 'Page Q', 'Page A', array( 'post_types' => array( 'page' ) ) );

		$this->assertNotNull( $this->extract_json_ld( $this->render_head_for( $page ) ) );
		$this->assertNull( $this->extract_json_ld( $this->render_head_for( $post ) ) );
	}

	public function test_term_mapping_for_category_and_custom_taxonomy() {
		register_taxonomy( 'rfsd_topic', 'post' );
		$cat   = self::factory()->category->create();
		$topic = self::factory()->term->create( array( 'taxonomy' => 'rfsd_topic' ) );

		$in_cat   = self::factory()->post->create();
		$in_topic = self::factory()->post->create();
		$neither  = self::factory()->post->create();
		wp_set_post_categories( $in_cat, array( $cat ) );
		wp_set_object_terms( $in_topic, array( $topic ), 'rfsd_topic' );

		$this->create_faq( 'Cat Q', 'Cat A', array( 'terms' => array( $cat ) ) );
		$this->create_faq( 'Topic Q', 'Topic A', array( 'terms' => array( $topic ) ) );

		$cat_json   = $this->extract_json_ld( $this->render_head_for( $in_cat ) );
		$topic_json = $this->extract_json_ld( $this->render_head_for( $in_topic ) );
		$this->assertSame( 'Cat Q', $cat_json['mainEntity'][0]['name'] );
		$this->assertSame( 'Topic Q', $topic_json['mainEntity'][0]['name'] );
		$this->assertNull( $this->extract_json_ld( $this->render_head_for( $neither ) ) );

		unregister_taxonomy( 'rfsd_topic' );
	}

	public function test_url_mapping_matches_permalink() {
		$post = self::factory()->post->create( array( 'post_name' => 'contact' ) );
		$this->create_faq( 'Where?', 'Here.', array( 'urls' => array( 'http://example.org/contact/?from=menu' ) ) );

		$json = $this->extract_json_ld( $this->render_head_for( $post ) );
		$this->assertSame( 'Where?', $json['mainEntity'][0]['name'] );
	}

	/**
	 * Rows indexed before data version 3 hold absolute URLs, sometimes with a trailing slash. They keep
	 * matching only until the migration has reindexed every FAQ.
	 */
	public function test_legacy_url_rows_match_only_while_migration_runs() {
		$post = self::factory()->post->create( array( 'post_name' => 'legacy' ) );
		foreach ( array( 'http://example.org/legacy/', 'http://example.org/legacy' ) as $value ) {
			rfsd_insert_mappings(
				$this->create_faq( 'Old?', 'Yes.' ),
				array(
					array(
						'type'  => 'url',
						'value' => $value,
					),
				)
			);
		}

		update_option( RFSD_DATA_VERSION_OPTION, '2' );
		$this->assertCount( 2, rfsd_faq_ids_for_post( $post ) );

		update_option( RFSD_DATA_VERSION_OPTION, '3' );
		$this->assertSame( array(), rfsd_faq_ids_for_post( $post ) );
	}

	public function test_global_mapping_applies_everywhere() {
		$this->create_faq( 'Global?', 'Yes.', array( 'global' => true ) );
		$post = self::factory()->post->create();
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->assertNotNull( $this->extract_json_ld( $this->render_head_for( $post ) ) );
		$this->assertNotNull( $this->extract_json_ld( $this->render_head_for( $page ) ) );
	}

	public function test_post_id_does_not_partially_match_longer_ids() {
		$post = self::factory()->post->create();
		$faq  = $this->create_faq();
		rfsd_insert_mappings(
			$faq,
			array(
				array(
					'type'  => 'post',
					'value' => $post . '1',
				),
				array(
					'type'  => 'post',
					'value' => '1' . $post,
				),
			)
		);

		$this->assertNull( $this->extract_json_ld( $this->render_head_for( $post ) ) );
	}

	public function test_mapping_type_must_match_value() {
		$post = self::factory()->post->create();
		$faq  = $this->create_faq();
		rfsd_insert_mappings(
			$faq,
			array(
				array(
					'type'  => 'term',
					'value' => $post,
				),
			)
		);

		$this->assertNull( $this->extract_json_ld( $this->render_head_for( $post ) ) );
	}

	public function test_multiple_faqs_are_combined_once() {
		$post = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$a    = $this->create_faq( 'A?', 'a', array( 'posts' => array( $post ) ) );
		$b    = $this->create_faq( 'B?', 'b', array( 'post_types' => array( 'page' ) ) );
		// Matches through two mapping rows but must appear only once.
		rfsd_insert_mappings(
			$b,
			array(
				array(
					'type'  => 'post',
					'value' => $post,
				),
			)
		);

		$json  = $this->extract_json_ld( $this->render_head_for( $post ) );
		$names = wp_list_pluck( $json['mainEntity'], 'name' );
		$this->assertEqualSets( array( 'A?', 'B?' ), $names );
		$this->assertCount( 2, $names );
		$this->assertNotEmpty( $a );
	}

	public function test_unpublished_faqs_are_excluded() {
		$post  = self::factory()->post->create();
		$draft = $this->create_faq( 'Draft?', 'x', array( 'posts' => array( $post ) ) );
		wp_update_post(
			array(
				'ID'          => $draft,
				'post_status' => 'draft',
			)
		);

		$html = $this->render_head_for( $post );
		$this->assertNull( $this->extract_json_ld( $html ) );
		$this->assertSame( '__RFSD_EMPTY__', get_transient( rfsd_cache_key( $post ) ) );
	}

	public function test_faqs_with_empty_question_or_answer_are_skipped() {
		$post = self::factory()->post->create();
		$this->create_faq( '', 'Answer only', array( 'posts' => array( $post ) ) );
		$this->create_faq( 'Question only', '', array( 'posts' => array( $post ) ) );
		$this->create_faq( 'Tags only', '<p><br></p>', array( 'posts' => array( $post ) ) );

		$html = $this->render_head_for( $post );
		$this->assertNull( $this->extract_json_ld( $html ) );
		$this->assertSame( '__RFSD_EMPTY__', get_transient( rfsd_cache_key( $post ) ) );
	}

	public function test_answer_html_is_stripped_and_unicode_kept() {
		$post = self::factory()->post->create();
		$this->create_faq(
			'Café hours?',
			'<p>We open at <strong>9am</strong>.</p>',
			array( 'posts' => array( $post ) )
		);

		$html = $this->render_head_for( $post );
		$json = $this->extract_json_ld( $html );
		$this->assertSame( 'Café hours?', $json['mainEntity'][0]['name'] );
		$this->assertSame( 'We open at 9am.', $json['mainEntity'][0]['acceptedAnswer']['text'] );
		$this->assertStringContainsString( 'Café', $html, 'Unicode is not escaped.' );
		$this->assertStringContainsString( '"https://schema.org"', $html, 'Slashes are not escaped.' );
	}

	public function test_faq_mapped_to_trashed_post_id_has_no_effect_on_others() {
		$post = self::factory()->post->create();
		$gone = self::factory()->post->create();
		$this->create_faq( 'Q', 'A', array( 'posts' => array( $gone ) ) );
		wp_delete_post( $gone, true );

		$this->assertNull( $this->extract_json_ld( $this->render_head_for( $post ) ) );
	}

	public function test_legacy_faqsection_value_outputs_faq_page() {
		update_option( RFSD_OPTION_KEY, array( 'output_type' => 'faqsection' ) );
		$post = self::factory()->post->create();
		$this->create_faq( 'Q', 'A', array( 'posts' => array( $post ) ) );

		$json = $this->extract_json_ld( $this->render_head_for( $post ) );
		$this->assertSame( 'FAQPage', $json['@type'] );
	}

	public function test_html_entities_are_decoded_and_whitespace_normalized() {
		$post = self::factory()->post->create();
		$this->create_faq(
			'Don&#8217;t &amp; won&#039;t?',
			"<p>Tom &amp; Jerry&nbsp;said &quot;hi&quot;.</p>\n\n<p>  Second   paragraph </p>",
			array( 'posts' => array( $post ) )
		);

		$json = $this->extract_json_ld( $this->render_head_for( $post ) );
		$this->assertSame( "Don\u{2019}t & won't?", $json['mainEntity'][0]['name'] );
		$this->assertSame( 'Tom & Jerry said "hi". Second paragraph', $json['mainEntity'][0]['acceptedAnswer']['text'] );
	}

	public function test_decoded_script_close_cannot_break_out_of_script_element() {
		$post = self::factory()->post->create();
		$this->create_faq(
			'Title &lt;/script&gt;&lt;script&gt;alert(1)&lt;/script&gt;',
			'<p>Answer &lt;/script&gt;&lt;script&gt;alert(1)&lt;/script&gt; &amp;amp;</p>',
			array( 'posts' => array( $post ) )
		);

		$html = $this->render_head_for( $post );
		$this->assertSame( 1, substr_count( strtolower( $html ), '</script>' ) );
		$this->assertSame( 1, substr_count( strtolower( $html ), '<script' ) );

		$json = $this->extract_json_ld( $html );
		$this->assertSame( 'Title </script><script>alert(1)</script>', $json['mainEntity'][0]['name'] );
		$this->assertSame( 'Answer </script><script>alert(1)</script> &amp;', $json['mainEntity'][0]['acceptedAnswer']['text'] );
	}

	public function test_blog_home_category_archive_and_search_output_nothing() {
		$cat  = self::factory()->category->create();
		$post = self::factory()->post->create( array( 'post_category' => array( $cat ) ) );
		$this->create_faq( 'Global Q', 'Global A', array( 'global' => true ) );
		$this->create_faq( 'Post Q', 'Post A', array( 'posts' => array( $post ) ) );

		foreach ( array( home_url( '/' ), get_category_link( $cat ), home_url( '/?s=' . rawurlencode( 'anything' ) ) ) as $url ) {
			$this->go_to( $url );
			$this->assertFalse( is_singular(), $url );
			ob_start();
			rfsd_maybe_print_faq_jsonld();
			$this->assertSame( '', ob_get_clean(), $url );
		}
	}

	public function test_singular_page_still_outputs_global_faq() {
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->create_faq( 'Global Q', 'Global A', array( 'global' => true ) );

		$json = $this->extract_json_ld( $this->render_head_for( $page ) );
		$this->assertSame( 'Global Q', $json['mainEntity'][0]['name'] );
	}

	public function test_the_content_filters_do_not_pollute_answer() {
		$callback = static function ( $content ) {
			return $content . '<p>Share this</p>';
		};
		add_filter( 'the_content', $callback );

		$post = self::factory()->post->create();
		$this->create_faq( 'Q', 'Plain answer.', array( 'posts' => array( $post ) ) );

		$json = $this->extract_json_ld( $this->render_head_for( $post ) );
		remove_filter( 'the_content', $callback );
		$this->assertSame( 'Plain answer.', $json['mainEntity'][0]['acceptedAnswer']['text'] );
	}

	public function test_shortcodes_render_in_answer() {
		add_shortcode(
			'rfsd_test_sc',
			static function () {
				return '<b>rendered</b>';
			}
		);
		$post = self::factory()->post->create();
		$this->create_faq( 'Q', 'Value: [rfsd_test_sc]', array( 'posts' => array( $post ) ) );

		$json = $this->extract_json_ld( $this->render_head_for( $post ) );
		remove_shortcode( 'rfsd_test_sc' );
		$this->assertSame( 'Value: rendered', $json['mainEntity'][0]['acceptedAnswer']['text'] );
	}

	public function test_text_filters_can_modify_question_and_answer() {
		$q = static function ( $text, $faq ) {
			return $text . ' [' . $faq->post_type . ']';
		};
		$a = static function ( $text ) {
			return strtoupper( $text );
		};
		add_filter( 'rfsd_question_text', $q, 10, 2 );
		add_filter( 'rfsd_answer_text', $a );

		$post = self::factory()->post->create();
		$this->create_faq( 'Q', 'answer', array( 'posts' => array( $post ) ) );

		$json = $this->extract_json_ld( $this->render_head_for( $post ) );
		remove_filter( 'rfsd_question_text', $q, 10 );
		remove_filter( 'rfsd_answer_text', $a );
		$this->assertSame( 'Q [rfsd_faq]', $json['mainEntity'][0]['name'] );
		$this->assertSame( 'ANSWER', $json['mainEntity'][0]['acceptedAnswer']['text'] );
	}
}
