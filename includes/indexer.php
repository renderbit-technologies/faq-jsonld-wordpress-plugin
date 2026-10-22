<?php
/**
 * Indexer functions.
 *
 * @package FAQ_JSON_LD
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable PSR1.Files.SideEffects

/**
 * Name of the mapping table for the current site.
 *
 * Resolved at call time so it stays correct after switch_to_blog().
 *
 * @return string
 */
function fqj_table_name() {
	global $wpdb;

	return $wpdb->prefix . 'fqj_mappings';
}

/**
 * Create or update the mapping table.
 *
 * The dbDelta() function needs a strict format: no IF NOT EXISTS, two spaces after PRIMARY KEY, KEY (not INDEX),
 * and no inline comments. Bump FQJ_DB_VERSION in faq-jsonld.php whenever this statement changes.
 * mapping_type holds one of: post, post_type, term, url, global.
 */
function fqj_create_table() {
	global $wpdb;
	$table_name      = fqj_table_name();
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table_name} (
      id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      faq_id bigint(20) unsigned NOT NULL,
      mapping_type VARCHAR(32) NOT NULL,
      mapping_value TEXT NOT NULL,
      PRIMARY KEY  (id),
      KEY idx_faq_id (faq_id),
      KEY idx_mapping_type (mapping_type),
      KEY idx_mapping_value (mapping_value(191))
    ) {$charset_collate};";

	// Invalidation queue: single posts plus post_type/term jobs that the worker expands from job_cursor.
	// Values are IDs or post type names (max 20 chars); the unique key stays under the 767-byte InnoDB limit.
	$queue_table = fqj_queue_table_name();
	$queue_sql   = "CREATE TABLE {$queue_table} (
      id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      item_type VARCHAR(32) NOT NULL,
      item_value VARCHAR(64) NOT NULL,
      job_cursor bigint(20) unsigned NOT NULL DEFAULT 0,
      PRIMARY KEY  (id),
      UNIQUE KEY uniq_item (item_type,item_value)
    ) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
	dbDelta( $queue_sql );

	// Record the version only if the table exists, so a failed run retries on the next request.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	if ( $wpdb->get_results( $wpdb->prepare( 'DESCRIBE %i', $table_name ) ) && $wpdb->get_results( $wpdb->prepare( 'DESCRIBE %i', $queue_table ) ) ) {
		update_option( FQJ_DB_VERSION_OPTION, FQJ_DB_VERSION );
	}
}

/**
 * Re-run the table setup when the stored schema version is missing or older than the code.
 *
 * Activation hooks do not run on plugin updates, so this covers existing sites.
 */
function fqj_maybe_upgrade_db() {
	if ( version_compare( (string) get_option( FQJ_DB_VERSION_OPTION, '0' ), FQJ_DB_VERSION, '<' ) ) {
		fqj_create_table();
		// Carry over data from older versions (no-ops on fresh installs).
		if ( version_compare( (string) get_option( FQJ_DB_VERSION_OPTION, '0' ), FQJ_DB_VERSION, '>=' ) ) {
			fqj_migrate_legacy_queue();
			fqj_unautoload_options();
		}
	}
}
add_action( 'plugins_loaded', 'fqj_maybe_upgrade_db' );

/**
 * Check whether any FAQ item exists, in any status except auto-draft (trashed FAQs can be restored later).
 *
 * @return bool
 */
function fqj_has_faq_items() {
	return ! empty( fqj_get_faq_ids_after( 0, 1 ) );
}

/**
 * Get FAQ item IDs above a cursor, in ascending order.
 *
 * @param int $after_id Return IDs greater than this.
 * @param int $limit    Maximum number of IDs.
 * @return int[]
 */
function fqj_get_faq_ids_after( $after_id, $limit ) {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status <> 'auto-draft' AND ID > %d ORDER BY ID ASC LIMIT %d",
			'faq_item',
			(int) $after_id,
			(int) $limit
		)
	);
	// phpcs:enable
	return array_map( 'intval', (array) $ids );
}

/**
 * Run one batch of the one-time data migration (reindex every FAQ, then purge cached output).
 *
 * Reschedules itself until all FAQs are done. Sites hit by plain permalinks (#9) can hold wrong url rows
 * and stale transients that new saves do not fix.
 */
function fqj_run_data_migration() {
	if ( version_compare( (string) get_option( FQJ_DATA_VERSION_OPTION, '0' ), FQJ_DATA_VERSION, '>=' ) ) {
		return;
	}

	// FAQSection was never a schema.org type; store the normalized value (the purge below fixes cached output).
	$opts = get_option( FQJ_OPTION_KEY );
	if ( is_array( $opts ) && isset( $opts['output_type'] ) && fqj_normalize_output_type( $opts['output_type'] ) !== $opts['output_type'] ) {
		$opts['output_type'] = fqj_normalize_output_type( $opts['output_type'] );
		update_option( FQJ_OPTION_KEY, $opts );
	}

	$batch_size = max( 1, (int) apply_filters( 'fqj_data_migration_batch_size', 25 ) );
	$cursor     = (int) get_option( FQJ_DATA_MIGRATION_CURSOR_OPTION, 0 );
	$ids        = fqj_get_faq_ids_after( $cursor, $batch_size );

	// The final purge bumps the cache generation, which invalidates all cached output on every backend,
	// so there is no need to enqueue per-post invalidation here.
	$from_version = (string) get_option( FQJ_DATA_VERSION_OPTION, '0' );
	foreach ( $ids as $faq_id ) {
		if ( version_compare( $from_version, '3', '<' ) ) {
			fqj_migrate_url_associations( $faq_id );
		}
		fqj_rebuild_index_for_faq( $faq_id, false );
		$cursor = $faq_id;
	}

	if ( count( $ids ) >= $batch_size ) {
		update_option( FQJ_DATA_MIGRATION_CURSOR_OPTION, $cursor, false );
		wp_schedule_single_event( time() + 10, 'fqj_data_migration' );
		return;
	}

	fqj_purge_all_faq_transients();
	delete_option( FQJ_DATA_MIGRATION_CURSOR_OPTION );
	update_option( FQJ_DATA_VERSION_OPTION, FQJ_DATA_VERSION );
}
add_action( 'fqj_data_migration', 'fqj_run_data_migration' );

/**
 * Data migration 3: keep URL associations that only matched through the `post` row indexing used to add.
 *
 * Must run before the FAQ is reindexed, which deletes those rows. Every change is added to the URL
 * migration report shown on the FAQ list screen.
 *
 * @param int $faq_id The FAQ ID.
 */
function fqj_migrate_url_associations( $faq_id ) {
	$payload = json_decode( (string) get_post_meta( $faq_id, 'fqj_assoc_data_json', true ), true );
	if ( ! is_array( $payload ) || empty( $payload['urls'] ) || ! is_array( $payload['urls'] ) ) {
		return;
	}

	// Posts the old index covered through a URL rather than through the FAQ's own post associations.
	$explicit = isset( $payload['posts'] ) && is_array( $payload['posts'] ) ? array_map( 'intval', $payload['posts'] ) : array();
	$old_ids  = array();
	foreach ( fqj_get_mappings_for_faq( $faq_id ) as $m ) {
		$pid = (int) $m['value'];
		if ( 'post' === $m['type'] && $pid > 0 && ! in_array( $pid, $explicit, true ) ) {
			$old_ids[ $pid ] = $pid;
		}
	}

	$urls     = array_values( $payload['urls'] );
	$dead     = array();
	$rewrites = array();
	foreach ( $urls as $i => $url ) {
		$pid = fqj_post_for_url( $url );
		if ( $pid ) {
			unset( $old_ids[ $pid ] );
			continue;
		}

		// A shortlink, the front page's slug URL or an old slug still routes to the post it used to cover.
		$pid = fqj_post_routed_from_url( $url );
		if ( $pid && isset( $old_ids[ $pid ] ) ) {
			$urls[ $i ] = get_permalink( $pid );
			$rewrites[] = array( $url, $urls[ $i ] );
			unset( $old_ids[ $pid ] );
			continue;
		}

		$dead[] = $i;
	}

	$leftover = array();
	foreach ( $old_ids as $pid ) {
		$permalink = get_post( $pid ) ? get_permalink( $pid ) : false;
		if ( $permalink ) {
			$leftover[] = $permalink;
		}
	}

	if ( 1 === count( $dead ) && 1 === count( $leftover ) ) {
		// Exactly one dead URL and one unexplained post: most likely the post was renamed.
		$rewrites[]       = array( $urls[ $dead[0] ], $leftover[0] );
		$urls[ $dead[0] ] = $leftover[0];
	} else {
		// Ambiguous: keep the dead URLs (the save warning flags them) and add each post's permalink.
		foreach ( $leftover as $permalink ) {
			$urls[]     = $permalink;
			$rewrites[] = array( '', $permalink );
		}
	}

	if ( ! $rewrites ) {
		return;
	}

	$payload['urls'] = array_values( array_unique( $urls ) );
	update_post_meta( $faq_id, 'fqj_assoc_data_json', wp_slash( wp_json_encode( $payload ) ) );

	$report = get_option( FQJ_URL_MIGRATION_REPORT_OPTION, array() );
	$report = is_array( $report ) ? $report : array();
	foreach ( $rewrites as $rewrite ) {
		$report[] = array(
			'faq_id' => (int) $faq_id,
			'old'    => $rewrite[0],
			'new'    => $rewrite[1],
		);
	}
	fqj_update_option_no_autoload( FQJ_URL_MIGRATION_REPORT_OPTION, $report );
}

/**
 * Schedule the data migration when the stored data version is older than the code.
 */
function fqj_maybe_schedule_data_migration() {
	if ( version_compare( (string) get_option( FQJ_DATA_VERSION_OPTION, '0' ), FQJ_DATA_VERSION, '<' )
		&& ! wp_next_scheduled( 'fqj_data_migration' ) ) {
		wp_schedule_single_event( time(), 'fqj_data_migration' );
	}
}
add_action( 'plugins_loaded', 'fqj_maybe_schedule_data_migration', 20 );

/**
 * Insert mapping rows for a given faq_id: accepts $mappings array with structure:
 * array( array('type' => 'post', 'value' => '123'), array('type'=>'url', 'value'=>'https://...'), ... )
 *
 * @param int   $faq_id The FAQ ID.
 * @param array $mappings The mappings array.
 */
function fqj_insert_mappings( $faq_id, $mappings ) {
	global $wpdb;
	$table = fqj_table_name();
	foreach ( $mappings as $m ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'faq_id'        => intval( $faq_id ),
				'mapping_type'  => sanitize_text_field( $m['type'] ),
				'mapping_value' => maybe_serialize( $m['value'] ),
			),
			array( '%d', '%s', '%s' )
		);
	}
}

/**
 * Delete mappings for faq_id (used to rebuild on save)
 *
 * @param int $faq_id The FAQ ID.
 */
function fqj_delete_mappings_for_faq( $faq_id ) {
	global $wpdb;
	$table = fqj_table_name();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->delete( $table, array( 'faq_id' => intval( $faq_id ) ), array( '%d' ) );
}

/**
 * Read the stored mapping rows of a FAQ in the same type/value shape that fqj_insert_mappings() takes.
 *
 * @param int $faq_id The FAQ ID.
 * @return array
 */
function fqj_get_mappings_for_faq( $faq_id ) {
	global $wpdb;
	$table = fqj_table_name();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT mapping_type, mapping_value FROM %i WHERE faq_id = %d', $table, intval( $faq_id ) ), ARRAY_A );

	$mappings = array();
	foreach ( (array) $rows as $row ) {
		$mappings[] = array(
			'type'  => $row['mapping_type'],
			'value' => maybe_unserialize( $row['mapping_value'] ),
		);
	}

	return $mappings;
}

/**
 * Build mapping rows from saved payload and index them
 * payload is array with keys 'urls', 'posts', 'post_types', 'terms', 'global'
 *
 * @param int  $faq_id  The FAQ ID.
 * @param bool $enqueue Whether to enqueue affected posts for invalidation.
 */
function fqj_rebuild_index_for_faq( $faq_id, $enqueue = true ) {
	// Remember the old mappings: posts that lost an association need invalidating too.
	$old_mappings = $enqueue ? fqj_get_mappings_for_faq( $faq_id ) : array();

	// delete old.
	fqj_delete_mappings_for_faq( $faq_id );

	$payload_json = get_post_meta( $faq_id, 'fqj_assoc_data_json', true );
	$payload      = $payload_json ? json_decode( $payload_json, true ) : array();
	if ( ! is_array( $payload ) ) {
		$payload = array();
	}

	$mappings = array();

	if ( isset( $payload['urls'] ) && is_array( $payload['urls'] ) ) {
		foreach ( $payload['urls'] as $u ) {
			// A URL association covers whichever post is at the URL now, so no post row (ADR-0001).
			$mappings[] = array(
				'type'  => 'url',
				'value' => fqj_normalize_url( $u ),
			);
		}
	}

	if ( isset( $payload['posts'] ) && is_array( $payload['posts'] ) ) {
		foreach ( $payload['posts'] as $p ) {
			$mappings[] = array(
				'type'  => 'post',
				'value' => intval( $p ),
			);
		}
	}

	if ( isset( $payload['post_types'] ) && is_array( $payload['post_types'] ) ) {
		foreach ( $payload['post_types'] as $pt ) {
			$mappings[] = array(
				'type'  => 'post_type',
				'value' => sanitize_text_field( $pt ),
			);
		}
	}

	if ( isset( $payload['terms'] ) && is_array( $payload['terms'] ) ) {
		foreach ( $payload['terms'] as $t ) {
			$mappings[] = array(
				'type'  => 'term',
				'value' => intval( $t ),
			);
		}
	}

	if ( isset( $payload['global'] ) && $payload['global'] ) {
		$mappings[] = array(
			'type'  => 'global',
			'value' => '1',
		);
	}

	if ( ! empty( $mappings ) ) {
		fqj_insert_mappings( $faq_id, $mappings );
	}

	// Enqueue affected posts for background invalidation.
	if ( $enqueue ) {
		fqj_enqueue_invalidation_for_mappings( array_merge( $old_mappings, $mappings ) );
	}
}

/**
 * Enqueue the posts affected by mappings for invalidation using the background queue.
 * This function does not synchronously delete transients or enumerate posts; it pushes post IDs and
 * post_type/term jobs to the queue.
 *
 * @param array $mappings The mappings array.
 */
function fqj_enqueue_invalidation_for_mappings( $mappings ) {
	if ( empty( $mappings ) ) {
		return;
	}

	$post_ids_to_enqueue   = array();
	$post_types_to_process = array();
	$terms_to_process      = array();
	$must_purge_all        = false;

	foreach ( $mappings as $m ) {
		switch ( $m['type'] ) {
			case 'post':
				$post_ids_to_enqueue[] = intval( $m['value'] );
				break;
			case 'url':
				// item_value is VARCHAR(64), so URLs are resolved to a post now rather than queued.
				$pid = fqj_post_for_url( $m['value'] );
				if ( $pid ) {
					$post_ids_to_enqueue[] = intval( $pid );
				}
				break;
			case 'post_type':
				$post_types_to_process[] = sanitize_text_field( $m['value'] );
				break;
			case 'term':
				$terms_to_process[] = intval( $m['value'] );
				break;
			case 'global':
				$must_purge_all = true;
				break;
		}
	}

	// Direct post IDs go straight in; post type and term mappings become jobs that the queue worker
	// expands incrementally, so saving never enumerates every matching post.
	fqj_queue_add_posts( $post_ids_to_enqueue );
	fqj_queue_add_items( 'post_type', $post_types_to_process );
	fqj_queue_add_items( 'term', $terms_to_process );

	// For global, do a full purge (we can't reasonably enqueue all posts efficiently here).
	if ( $must_purge_all ) {
		fqj_purge_all_faq_transients();
	}
}

/**
 * Enqueue invalidation for the current mapping rows of a FAQ.
 *
 * @param int $faq_id The FAQ ID.
 */
function fqj_invalidate_faq_mappings( $faq_id ) {
	fqj_enqueue_invalidation_for_mappings( fqj_get_mappings_for_faq( $faq_id ) );
}

/**
 * Invalidate when a FAQ changes status (trash, untrash, unpublish, publish).
 *
 * @param string  $new_status New status.
 * @param string  $old_status Old status.
 * @param WP_Post $post       Post object.
 */
function fqj_on_faq_transition_status( $new_status, $old_status, $post ) {
	if ( 'faq_item' !== $post->post_type || $new_status === $old_status ) {
		return;
	}
	fqj_invalidate_faq_mappings( $post->ID );
}
add_action( 'transition_post_status', 'fqj_on_faq_transition_status', 10, 3 );

/**
 * Invalidate when a FAQ's question or answer changes outside the meta box (Quick Edit, REST, code).
 *
 * @param int     $post_id     Post ID.
 * @param WP_Post $post_after  Post after the update.
 * @param WP_Post $post_before Post before the update.
 */
function fqj_on_faq_updated( $post_id, $post_after, $post_before ) {
	if ( 'faq_item' !== $post_after->post_type ) {
		return;
	}
	if ( $post_after->post_title === $post_before->post_title && $post_after->post_content === $post_before->post_content ) {
		return;
	}
	// The meta box save rebuilds the index and invalidates the union itself.
	if ( isset( $_POST['fqj_meta_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return;
	}
	fqj_invalidate_faq_mappings( $post_id );
}
add_action( 'post_updated', 'fqj_on_faq_updated', 10, 3 );

/**
 * Invalidate and drop mapping rows when a FAQ is permanently deleted.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 */
function fqj_on_faq_before_delete( $post_id, $post = null ) {
	$post = $post ? $post : get_post( $post_id );
	if ( ! $post || 'faq_item' !== $post->post_type ) {
		return;
	}
	fqj_invalidate_faq_mappings( $post_id );
	fqj_delete_mappings_for_faq( $post_id );
}
add_action( 'before_delete_post', 'fqj_on_faq_before_delete', 10, 2 );
