<?php
/**
 * Coverage: which posts a mapping applies to, in both directions.
 *
 * The forward direction (lookup candidates and FAQ IDs for a post) and the inverse direction (posts covered
 * by a mapping) live together so they stay in agreement. See CONTEXT.md for the terms used here.
 *
 * @package FAQ_JSON_LD
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable PSR1.Files.SideEffects

/**
 * Query args that identify a single post when permalinks are plain (e.g. `?p=123`, `?book=slug`).
 *
 * @return string[]
 */
function fqj_identifying_query_args() {
	$args = array( 'p', 'page_id', 'attachment_id' );
	foreach ( get_post_types( array( 'publicly_queryable' => true ), 'objects' ) as $type ) {
		if ( is_string( $type->query_var ) && '' !== $type->query_var ) {
			$args[] = $type->query_var;
		}
	}

	return array_values( array_unique( $args ) );
}

/**
 * Normalize a URL for mapping storage and lookup: drop fragment and trailing slash, and drop the
 * query string except for args that identify a post (plain permalinks), so `/?p=1` and `/?p=2` differ.
 *
 * Index and frontend lookup must use the same shape since mappings are compared with exact `=`.
 *
 * @param string $url The URL.
 * @return string
 */
function fqj_normalize_url( $url ) {
	$url   = (string) $url;
	$parts = explode( '?', (string) strtok( $url, '#' ), 2 );
	$base  = rtrim( $parts[0], '/' );

	if ( isset( $parts[1] ) && '' !== $parts[1] ) {
		parse_str( $parts[1], $query );
		$keep = array_intersect_key( $query, array_flip( fqj_identifying_query_args() ) );
		$keep = array_filter( $keep, 'is_scalar' );
		if ( $keep ) {
			ksort( $keep );
			$base .= '/?' . http_build_query( $keep );
		}
	}

	return $base;
}

/**
 * Lookup candidates for a post: the mappings that could match it.
 *
 * @param int $post_id Post ID.
 * @return array List of array( 'type' => ..., 'value' => ... ) with string values.
 */
function fqj_lookup_candidates( $post_id ) {
	$post_id    = (int) $post_id;
	$candidates = array();

	// Direct post id mapping.
	$candidates[] = array(
		'type'  => 'post',
		'value' => (string) $post_id,
	);

	// Current post type mapping.
	$pt = get_post_type( $post_id );
	if ( $pt ) {
		$candidates[] = array(
			'type'  => 'post_type',
			'value' => $pt,
		);
	}

	// Terms across every taxonomy registered for this post type (defaults to post_tag only otherwise).
	$taxonomies = $pt ? get_object_taxonomies( $pt ) : array();
	$term_ids   = $taxonomies ? wp_get_object_terms( $post_id, $taxonomies, array( 'fields' => 'ids' ) ) : array();
	if ( ! is_wp_error( $term_ids ) && ! empty( $term_ids ) ) {
		foreach ( $term_ids as $tid ) {
			$candidates[] = array(
				'type'  => 'term',
				'value' => (string) $tid,
			);
		}
	}

	// URL mapping - the permalink, normalized the same way as the indexer.
	$permalink = get_permalink( $post_id );
	if ( $permalink ) {
		$url = fqj_normalize_url( $permalink );

		$candidates[] = array(
			'type'  => 'url',
			'value' => $url,
		);
		// Rows indexed before URL normalization may still carry a trailing slash.
		$candidates[] = array(
			'type'  => 'url',
			'value' => $url . '/',
		);
	}

	// Always include global mapping.
	$candidates[] = array(
		'type'  => 'global',
		'value' => '1',
	);

	return $candidates;
}

/**
 * FAQ IDs whose mappings match a post's lookup candidates, whatever the post's or the FAQ's status.
 *
 * @param int $post_id Post ID.
 * @return int[] Unique IDs in ascending order.
 */
function fqj_faq_ids_for_post( $post_id ) {
	global $wpdb;

	$candidates = fqj_lookup_candidates( $post_id );
	if ( empty( $candidates ) ) {
		return array();
	}

	// Exact matches only, so post ID 1 never matches 10, 11, etc.
	$params = array( fqj_table_name() );
	foreach ( $candidates as $c ) {
		$params[] = $c['type'];
		$params[] = (string) $c['value'];
	}

	$where_sql = implode( ' OR ', array_fill( 0, count( $candidates ), '(mapping_type = %s AND mapping_value = %s)' ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders live in $where_sql.
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT faq_id FROM %i WHERE {$where_sql} ORDER BY faq_id ASC", $params ) );

	return array_map( 'intval', (array) $ids );
}

/**
 * The post a URL resolves to.
 *
 * @param string $url URL.
 * @return int Post ID, or 0.
 */
function fqj_post_for_url( $url ) {
	return (int) url_to_postid( (string) $url );
}

/**
 * Whether a mapping covers every post. Callers purge all cached output instead of listing posts.
 *
 * @param array $mapping Mapping with 'type' and 'value'.
 * @return bool
 */
function fqj_mapping_covers_everything( $mapping ) {
	return isset( $mapping['type'] ) && 'global' === $mapping['type'];
}

/**
 * Whether a post is coverable, i.e. can have cached output.
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function fqj_post_is_coverable( $post_id ) {
	return (int) $post_id > 0 && 'publish' === get_post_status( (int) $post_id );
}

/**
 * Coverable posts covered by a mapping, above a cursor.
 *
 * Not for mappings that cover everything (see fqj_mapping_covers_everything()).
 *
 * @param array $mapping  Mapping with 'type' and 'value'.
 * @param int   $after_id Return IDs greater than this.
 * @param int   $limit    Maximum number of IDs.
 * @return int[] Published post IDs in ascending order.
 */
function fqj_posts_covered_by_mapping( $mapping, $after_id = 0, $limit = 100 ) {
	global $wpdb;

	$after_id = (int) $after_id;
	$limit    = (int) $limit;
	$type     = isset( $mapping['type'] ) ? $mapping['type'] : '';
	$value    = isset( $mapping['value'] ) ? $mapping['value'] : '';
	if ( $limit <= 0 ) {
		return array();
	}

	if ( fqj_mapping_covers_everything( $mapping ) ) {
		_doing_it_wrong( __FUNCTION__, 'Mappings that cover everything have no post list; purge all cached output instead.', '3.0.2' );
		return array();
	}

	$ids = array();
	switch ( $type ) {
		case 'post':
		case 'url':
			$pid = 'post' === $type ? (int) $value : fqj_post_for_url( $value );
			if ( $pid > $after_id && fqj_post_is_coverable( $pid ) ) {
				$ids = array( $pid );
			}
			break;
		case 'post_type':
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND ID > %d ORDER BY ID ASC LIMIT %d",
					(string) $value,
					$after_id,
					$limit
				)
			);
			// phpcs:enable
			break;
		case 'term':
			$term = get_term( (int) $value );
			if ( $term && ! is_wp_error( $term ) ) {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
						WHERE tr.term_taxonomy_id = %d AND p.post_status = 'publish' AND p.ID > %d ORDER BY p.ID ASC LIMIT %d",
						$term->term_taxonomy_id,
						$after_id,
						$limit
					)
				);
				// phpcs:enable
			}
			break;
	}

	return array_map( 'intval', (array) $ids );
}

/**
 * Drop a post's cached output when its terms change.
 *
 * @param int    $object_id  Object ID.
 * @param array  $terms      Terms passed.
 * @param array  $tt_ids     New term taxonomy IDs.
 * @param string $taxonomy   Taxonomy.
 * @param bool   $append     Whether terms were appended.
 * @param array  $old_tt_ids Previous term taxonomy IDs.
 */
function fqj_on_object_terms_set( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
	$type = get_post_type( $object_id );
	if ( ! $type || 'faq_item' === $type || wp_is_post_revision( $object_id ) ) {
		return;
	}
	if ( ! $append ) {
		$new = array_map( 'intval', (array) $tt_ids );
		$old = array_map( 'intval', (array) $old_tt_ids );
		sort( $new );
		sort( $old );
		if ( $new === $old ) {
			return;
		}
	}
	delete_transient( fqj_cache_key( $object_id ) );
}
add_action( 'set_object_terms', 'fqj_on_object_terms_set', 10, 6 );

/**
 * Drop a post's cached output when its permalink or visibility changes.
 *
 * @param int     $post_id     Post ID.
 * @param WP_Post $post_after  Post after the update.
 * @param WP_Post $post_before Post before the update.
 */
function fqj_on_post_permalink_change( $post_id, $post_after, $post_before ) {
	if ( 'faq_item' === $post_after->post_type || wp_is_post_revision( $post_id ) ) {
		return;
	}
	if ( $post_after->post_name === $post_before->post_name
		&& (int) $post_after->post_parent === (int) $post_before->post_parent
		&& $post_after->post_status === $post_before->post_status ) {
		return;
	}
	delete_transient( fqj_cache_key( $post_id ) );
}
add_action( 'post_updated', 'fqj_on_post_permalink_change', 10, 3 );
