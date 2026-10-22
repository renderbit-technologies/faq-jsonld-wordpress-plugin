<?php
/**
 * Frontend output functions.
 *
 * @package FAQ_JSON_LD
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable PSR1.Files.SideEffects

/**
 * Reduce markup-bearing text to a plain string: strip tags, decode entities, collapse whitespace.
 *
 * Tags are stripped before decoding so entity-encoded markup stays literal text. The JSON encoder escapes
 * "<" and ">" so decoded text can never close the surrounding script element.
 *
 * @param string $html Text that may contain markup and entities.
 * @return string
 */
function fqj_plain_text( $html ) {
	// Keep block boundaries as spaces so adjacent paragraphs do not run together once tags are gone.
	$html = preg_replace( '#<br\s*/?>|</(?:p|div|li|h[1-6]|tr|blockquote)>#i', '$0 ', (string) $html );
	$text = wp_strip_all_tags( $html );
	$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) );

	return trim( preg_replace( '/\s+/u', ' ', $text ) );
}

/**
 * Plain-text question for a FAQ item.
 *
 * @param WP_Post $faq FAQ post.
 * @return string
 */
function fqj_question_text( $faq ) {
	$text = fqj_plain_text( get_the_title( $faq ) );

	/**
	 * Filters the question text output in JSON-LD.
	 *
	 * @param string  $text Plain-text question.
	 * @param WP_Post $faq  FAQ post.
	 */
	return (string) apply_filters( 'fqj_question_text', $text, $faq );
}

/**
 * Plain-text answer for a FAQ item.
 *
 * Deliberately avoids the the_content filter so theme and plugin additions (share buttons, related posts,
 * ads) never leak into structured data.
 *
 * @param WP_Post $faq FAQ post.
 * @return string
 */
function fqj_answer_text( $faq ) {
	$content = $faq->post_content;
	if ( function_exists( 'do_blocks' ) ) {
		$content = do_blocks( $content );
	}
	$text = fqj_plain_text( do_shortcode( $content ) );

	/**
	 * Filters the answer text output in JSON-LD.
	 *
	 * @param string  $text Plain-text answer.
	 * @param WP_Post $faq  FAQ post.
	 */
	return (string) apply_filters( 'fqj_answer_text', $text, $faq );
}

/**
 * Build and print JSON-LD for current post (FAQ IDs come from the coverage module)
 */
function fqj_maybe_print_faq_jsonld() {
	if ( is_admin() ) {
		return;
	}

	// FAQs map to singular content only; archives, the blog home and search must never inherit a loop post's FAQ.
	if ( ! is_singular() ) {
		return;
	}

	$current_id = (int) get_queried_object_id();
	if ( $current_id <= 0 ) {
		return;
	}

	$opts      = get_option( FQJ_OPTION_KEY );
	$cache_ttl = isset( $opts['cache_ttl'] ) ? intval( $opts['cache_ttl'] ) : 12 * HOUR_IN_SECONDS;

	$transient_key = fqj_cache_key( $current_id );
	$cached        = get_transient( $transient_key );
	if ( false !== $cached ) {
		if ( '__FQJ_EMPTY__' === $cached ) {
			echo "\n<!-- FAQ JSON-LD (cached, no output) -->\n";
		} elseif ( '' !== trim( $cached ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo "\n<!-- FAQ JSON-LD (cached) -->\n" . $cached . "\n";
		} else {
			// Should not happen, but for safety.
			echo "\n<!-- FAQ JSON-LD (cached, unknown state) -->\n";
		}

		return;
	}

	$faq_ids = fqj_faq_ids_for_post( $current_id );
	if ( empty( $faq_ids ) ) {
		// cache negative result to avoid repeated queries.
		set_transient( $transient_key, '__FQJ_EMPTY__', $cache_ttl );
		echo "\n<!-- FAQ JSON-LD (generated, no output) -->\n";

		return;
	}

	// Now fetch the FAQ posts.
	$args = array(
		'post_type'      => 'faq_item',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'post__in'       => $faq_ids,
		'orderby'        => 'post__in',
	);
	$faqs = get_posts( $args );
	if ( ! $faqs ) {
		set_transient( $transient_key, '__FQJ_EMPTY__', $cache_ttl );
		echo "\n<!-- FAQ JSON-LD (generated, no output) -->\n";

		return;
	}

	$main_entities = array();
	foreach ( $faqs as $f ) {
		$q = fqj_question_text( $f );
		$a = fqj_answer_text( $f );
		if ( empty( $q ) || empty( $a ) ) {
			continue;
		}

		$main_entities[] = array(
			'@type'          => 'Question',
			'name'           => $q,
			'acceptedAnswer' => array(
				'@type' => 'Answer',
				'text'  => $a,
			),
		);
	}

	if ( empty( $main_entities ) ) {
		set_transient( $transient_key, '__FQJ_EMPTY__', $cache_ttl );
		echo "\n<!-- FAQ JSON-LD (generated, no output) -->\n";

		return;
	}

	$json_ld = array(
		'@context'   => 'https://schema.org',
		'@type'      => 'FAQPage',
		'mainEntity' => $main_entities,
	);

	$script = '<script type="application/ld+json">' .
		wp_json_encode( $json_ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>';

	echo "\n<!-- FAQ JSON-LD injected by FAQ JSON-LD Manager plugin (generated) -->\n";
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo $script . "\n";

	set_transient( $transient_key, $script, $cache_ttl );
}
add_action( 'wp_head', 'fqj_maybe_print_faq_jsonld', 1 );
