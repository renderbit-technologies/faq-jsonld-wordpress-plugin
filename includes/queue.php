<?php
/**
 * Queue management functions.
 *
 * @package FAQ_JSON_LD
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable PSR1.Files.SideEffects

/**
 * Background invalidation queue (custom table) + WP-Cron
 * Includes logging: last run timestamp and an invalidation history log.
 */

/**
 * Register a custom cron interval (5 minutes) if not present.
 *
 * @param array $schedules Current cron schedules.
 * @return array Modified schedules.
 */
function fqj_add_cron_interval( $schedules ) {
	if ( ! isset( $schedules['fqj_five_minutes'] ) ) {
		$schedules['fqj_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => 'Every 5 Minutes',
		);
	}

	return $schedules;
}
// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- the queue is meant to drain every 5 minutes.
add_filter( 'cron_schedules', 'fqj_add_cron_interval' );

/**
 * Queue table name, resolved at call time so it follows the current blog on multisite.
 *
 * @return string
 */
function fqj_queue_table_name() {
	global $wpdb;

	return $wpdb->prefix . 'fqj_queue';
}

/**
 * Item types the queue accepts. 'post' items are single posts; the others are jobs that the worker expands
 * incrementally into posts, using the row's job_cursor.
 *
 * @return string[]
 */
function fqj_queue_job_types() {
	return array( 'post_type', 'term' );
}

/**
 * Insert queue items. Duplicates are ignored for posts. Re-adding a job restarts it from the beginning,
 * because posts below its cursor may have been added since it started.
 *
 * @param string $type   Item type: post, post_type or term.
 * @param array  $values Item values (post IDs, post type names or term IDs).
 * @return bool True when at least one valid value was submitted.
 */
function fqj_queue_add_items( $type, $values ) {
	global $wpdb;

	$is_job = in_array( $type, fqj_queue_job_types(), true );
	if ( ! $is_job && 'post' !== $type ) {
		return false;
	}

	$clean = array();
	foreach ( (array) $values as $value ) {
		if ( 'post_type' === $type ) {
			$value = sanitize_key( $value );
		} else {
			$value = (string) absint( $value );
		}
		if ( '' !== $value && '0' !== $value ) {
			$clean[ $value ] = $value;
		}
	}
	if ( empty( $clean ) ) {
		return false;
	}

	$table = fqj_queue_table_name();
	foreach ( array_chunk( array_values( $clean ), 200 ) as $chunk ) {
		$placeholders = implode( ',', array_fill( 0, count( $chunk ), '(%s,%s)' ) );
		$args         = array( $table );
		foreach ( $chunk as $value ) {
			$args[] = $type;
			$args[] = $value;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders live in $placeholders.
		if ( $is_job ) {
			$wpdb->query(
				$wpdb->prepare( "INSERT INTO %i (item_type, item_value) VALUES {$placeholders} ON DUPLICATE KEY UPDATE job_cursor = 0", $args )
			);
		} else {
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (item_type, item_value) VALUES {$placeholders}", $args ) );
		}
		// phpcs:enable
	}

	return true;
}

/**
 * Enqueue posts to the invalidation queue.
 *
 * @param array $post_ids Array of post IDs.
 * @return bool True on success.
 */
function fqj_queue_add_posts( $post_ids ) {
	return fqj_queue_add_items( 'post', (array) $post_ids );
}

/**
 * Pop up to $limit posts from queue (returns array of post IDs popped). FIFO.
 *
 * Rows are deleted by id after being read, so items added meanwhile are never lost.
 *
 * @param int $limit Max items to pop.
 * @return array
 */
function fqj_queue_pop_posts( $limit = 100 ) {
	global $wpdb;

	$limit = (int) $limit;
	if ( $limit <= 0 ) {
		return array();
	}

	$table = fqj_queue_table_name();
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare( "SELECT id, item_value FROM %i WHERE item_type = 'post' ORDER BY id ASC LIMIT %d", $table, $limit ),
		ARRAY_A
	);
	if ( empty( $rows ) ) {
		return array();
	}

	$row_ids      = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
	$placeholders = implode( ',', array_fill( 0, count( $row_ids ), '%d' ) );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders live in $placeholders.
	$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id IN ({$placeholders})", array_merge( array( $table ), $row_ids ) ) );
	// phpcs:enable

	return array_map( 'intval', wp_list_pluck( $rows, 'item_value' ) );
}

/**
 * Get the queue length (pending posts plus unfinished jobs).
 *
 * @return int
 */
function fqj_queue_length() {
	global $wpdb;

	$table = fqj_queue_table_name();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
}

/**
 * Move a leftover pre-table queue (transient) into the queue table, then delete the transient.
 */
function fqj_migrate_legacy_queue() {
	$existing = get_transient( 'fqj_invalidate_queue' );
	if ( false === $existing ) {
		return;
	}

	$queue = maybe_unserialize( $existing );
	if ( is_array( $queue ) && ! empty( $queue ) ) {
		fqj_queue_add_posts( $queue );
	}
	delete_transient( 'fqj_invalidate_queue' );
}

/**
 * Save an option without autoload (the log and last-run timestamp are only read on the Health page).
 *
 * Note that update_option() only applies the autoload argument to existing rows from WP 6.4, so an already
 * autoloaded row is switched explicitly (see fqj_unautoload_options()).
 *
 * @param string $name  Option name.
 * @param mixed  $value Value.
 */
function fqj_update_option_no_autoload( $name, $value ) {
	if ( false === get_option( $name ) ) {
		add_option( $name, $value, '', false );
		return;
	}
	update_option( $name, $value, false );
}

/**
 * Turn autoload off for the plugin's rarely read options, whatever the WP version.
 */
function fqj_unautoload_options() {
	global $wpdb;

	foreach ( array( 'fqj_invalidation_log', 'fqj_last_queue_run' ) as $name ) {
		if ( false === get_option( $name ) ) {
			continue;
		}
		if ( function_exists( 'wp_set_option_autoload' ) ) {
			wp_set_option_autoload( $name, false );
			continue;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $wpdb->options, array( 'autoload' => 'no' ), array( 'option_name' => $name ) );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( $name, 'options' );
	}
}

/**
 * Make sure the queue worker is scheduled. Cron events can be lost (cron option rewrites, migrations).
 */
function fqj_ensure_cron_scheduled() {
	if ( ! wp_next_scheduled( 'fqj_process_invalidation_queue' ) ) {
		wp_schedule_event( time() + 60, 'fqj_five_minutes', 'fqj_process_invalidation_queue' );
	}
}
add_action( 'init', 'fqj_ensure_cron_scheduled' );

/**
 * Log an invalidation worker run.
 * Stores an entry into option 'fqj_invalidation_log' as array of entries:
 * [ [ 'ts' => 12345, 'processed' => n, 'sample' => [ids] ], ... ]
 * Keeps only last 200 entries (configurable later). A run that processed nothing only updates the last-run time.
 *
 * @param int   $processed_count Number processed.
 * @param array $sample_ids Sample IDs.
 */
function fqj_log_invalidation_run( $processed_count, $sample_ids = array() ) {
	$processed_count = intval( $processed_count );
	fqj_update_option_no_autoload( 'fqj_last_queue_run', time() );
	if ( $processed_count <= 0 ) {
		return;
	}

	$opt_name = 'fqj_invalidation_log';
	$log      = get_option( $opt_name, array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}

	$entry = array(
		'ts'        => time(),
		'processed' => $processed_count,
		'sample'    => array_slice( array_map( 'intval', $sample_ids ), 0, 20 ),
	);

	array_unshift( $log, $entry ); // newest first.

	// cap length.
	$max = 200; // reasonable cap.
	if ( count( $log ) > $max ) {
		$log = array_slice( $log, 0, $max );
	}

	fqj_update_option_no_autoload( $opt_name, $log );
}

/**
 * Expand queued post_type/term jobs into per-post cache deletions, within a post budget.
 *
 * Each job walks the posts its mapping covers (fqj_posts_covered_by_mapping()) in ascending order from its
 * stored cursor and is deleted once exhausted.
 *
 * @param int $budget Maximum number of posts to process.
 * @return int[] IDs processed.
 */
function fqj_queue_process_jobs( $budget ) {
	global $wpdb;

	$done  = array();
	$table = fqj_queue_table_name();

	$done_count = 0;
	while ( $done_count < $budget ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$job = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, item_type, item_value, job_cursor FROM %i WHERE item_type IN ('post_type','term') ORDER BY id ASC LIMIT 1", $table ),
			ARRAY_A
		);
		// phpcs:enable
		if ( ! $job ) {
			break;
		}

		$remaining = $budget - $done_count;
		$cursor    = (int) $job['job_cursor'];
		$ids       = fqj_posts_covered_by_mapping(
			array(
				'type'  => $job['item_type'],
				'value' => $job['item_value'],
			),
			$cursor,
			$remaining
		);

		foreach ( $ids as $pid ) {
			delete_transient( fqj_cache_key( $pid ) );
		}
		$done       = array_merge( $done, $ids );
		$done_count = count( $done );

		// The cursor guard keeps a concurrent re-add (which resets the cursor to 0) from being overwritten.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( count( $ids ) < $remaining ) {
			$wpdb->delete(
				$table,
				array(
					'id'         => (int) $job['id'],
					'job_cursor' => $cursor,
				),
				array( '%d', '%d' )
			);
		} else {
			$wpdb->update(
				$table,
				array( 'job_cursor' => end( $ids ) ),
				array(
					'id'         => (int) $job['id'],
					'job_cursor' => $cursor,
				),
				array( '%d' ),
				array( '%d', '%d' )
			);
		}
		// phpcs:enable
	}

	return $done;
}

/**
 * Process one batch: queued posts first, then job expansion with the remaining budget.
 *
 * @param int $limit Maximum posts to process.
 * @return int[] Post IDs processed.
 */
function fqj_queue_process_batch( $limit ) {
	$limit = max( 1, (int) $limit );
	$ids   = fqj_queue_pop_posts( $limit );
	foreach ( $ids as $pid ) {
		delete_transient( fqj_cache_key( $pid ) );
	}
	if ( count( $ids ) < $limit ) {
		$ids = array_merge( $ids, fqj_queue_process_jobs( $limit - count( $ids ) ) );
	}

	return $ids;
}

/**
 * Cron worker: processes up to batch_size posts from the queue
 */
function fqj_process_invalidation_queue_cron() {
	$opts       = get_option( FQJ_OPTION_KEY );
	$batch_size = isset( $opts['batch_size'] ) ? intval( $opts['batch_size'] ) : 500;

	$processed = fqj_queue_process_batch( $batch_size );
	fqj_log_invalidation_run( count( $processed ), array_slice( $processed, 0, 20 ) );
}
add_action( 'fqj_process_invalidation_queue', 'fqj_process_invalidation_queue_cron' );

/**
 * Immediate queue processor (used by WP-CLI, admin AJAX or ad-hoc)
 * Returns number processed.
 *
 * @param int $limit Max items.
 * @return int
 */
function fqj_process_invalidation_queue_now( $limit = null ) {
	$opts       = get_option( FQJ_OPTION_KEY );
	$batch_size = isset( $opts['batch_size'] ) ? intval( $opts['batch_size'] ) : 500;
	$limit      = $limit ? intval( $limit ) : $batch_size;

	$processed = fqj_queue_process_batch( $limit );
	fqj_log_invalidation_run( count( $processed ), array_slice( $processed, 0, 20 ) );

	return count( $processed );
}

/**
 * Admin notice helper: show queue length on admin screens for admins.
 */
function fqj_admin_queue_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$len = fqj_queue_length();
	if ( $len > 0 ) {
		printf(
			'<div class="notice notice-info"><p>FAQ JSON-LD queue: <strong>%d</strong> ' .
			'items pending invalidation. The background worker (WP-Cron) will process them in batches.</p></div>',
			intval( $len )
		);
	}
}
add_action( 'admin_notices', 'fqj_admin_queue_notice' );
