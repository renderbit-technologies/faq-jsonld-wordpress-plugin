<?php
/**
 * Health page.
 *
 * @package FAQ_JSON_LD
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable PSR1.Files.SideEffects

/**
 * Health & Diagnostics admin page for FAQ JSON-LD plugin.
 * Shows:
 *  - Queue length
 *  - Last run timestamp
 *  - Recent invalidation history
 *  - Buttons to process queue now, purge transients, clear log
 */

/**
 * Register submenu under FAQ Items
 */
function fqj_register_health_page() {
	add_submenu_page(
		'edit.php?post_type=faq_item',
		__( 'FAQ JSON-LD Health', 'faq-jsonld' ),
		__( 'Health', 'faq-jsonld' ),
		'manage_options',
		'fqj-health',
		'fqj_render_health_page'
	);
}
add_action( 'admin_menu', 'fqj_register_health_page' );

/**
 * Enqueue health JS only on our page
 *
 * @param string $hook The current admin page.
 */
function fqj_health_admin_assets( $hook ) {
	// check page hook: load only on fqj-health page.
	if ( 'faq_item_page_fqj-health' !== $hook ) {
		return;
	}

	wp_enqueue_script(
		'fqj-health-js',
		FQJ_PLUGIN_URL . 'assets/js/fqj-health.js',
		array( 'jquery' ),
		FQJ_VERSION,
		true
	);
	wp_localize_script(
		'fqj-health-js',
		'fqjHealth',
		array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'fqj_health_nonce' ),
			'i18n'     => array(
				'processing'         => __( 'Processing…', 'faq-jsonld' ),
				/* translators: %d: number of processed items. */
				'processed'          => __( 'Processed %d items.', 'faq-jsonld' ),
				/* translators: %s: error message. */
				'error'              => __( 'Error: %s', 'faq-jsonld' ),
				'unknown'            => __( 'unknown', 'faq-jsonld' ),
				'requestFailed'      => __( 'Request failed. See console.', 'faq-jsonld' ),
				'requestFailedShort' => __( 'Request failed.', 'faq-jsonld' ),
				'purging'            => __( 'Purging…', 'faq-jsonld' ),
				'purged'             => __( 'Purged transients.', 'faq-jsonld' ),
				'purgeError'         => __( 'Error purging.', 'faq-jsonld' ),
				'purgeConfirm'       => __( 'Purge all FAQ transients? This will force pages to rebuild the JSON-LD on next view.', 'faq-jsonld' ),
				'clearing'           => __( 'Clearing…', 'faq-jsonld' ),
				'cleared'            => __( 'Cleared log. Reload to refresh the table.', 'faq-jsonld' ),
				'clearError'         => __( 'Error clearing log.', 'faq-jsonld' ),
				'clearConfirm'       => __( 'Clear invalidation log? This will remove recent run history.', 'faq-jsonld' ),
				'notAvailable'       => __( 'n/a', 'faq-jsonld' ),
			),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'fqj_health_admin_assets' );

/**
 * Format a timestamp as "Y-m-d H:i:s (5 mins ago)" in the site's timezone.
 *
 * @param int $timestamp Unix timestamp.
 * @return string
 */
function fqj_format_time_ago( $timestamp ) {
	/* translators: %s: human-readable time difference, e.g. "5 mins". */
	$ago = sprintf( __( '%s ago', 'faq-jsonld' ), human_time_diff( $timestamp, time() ) );

	return wp_date( 'Y-m-d H:i:s', $timestamp ) . ' (' . $ago . ')';
}

/**
 * Render health page
 */
function fqj_render_health_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$queue_len   = fqj_queue_length();
	$last_run_ts = get_option( 'fqj_last_queue_run', 0 );
	$log         = get_option( 'fqj_invalidation_log', array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}

	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'FAQ JSON-LD — Health & Diagnostics', 'faq-jsonld' ); ?></h1>

		<h2><?php echo esc_html__( 'Queue', 'faq-jsonld' ); ?></h2>
		<p><?php echo esc_html__( 'Pending invalidation items in queue:', 'faq-jsonld' ); ?>
			<strong id="fqj-queue-count"><?php echo intval( $queue_len ); ?></strong>
		</p>
		<p><?php echo esc_html__( 'Last queue run:', 'faq-jsonld' ); ?> <strong id="fqj-last-run">
		<?php
		echo $last_run_ts
			? esc_html( fqj_format_time_ago( (int) $last_run_ts ) )
			: esc_html__( 'Never', 'faq-jsonld' );
		?>
		</strong></p>

		<p>
			<button id="fqj-process-now" class="button button-primary"><?php echo esc_html__( 'Process queue now', 'faq-jsonld' ); ?></button>
			<button id="fqj-purge-transients" class="button"><?php echo esc_html__( 'Purge all FAQ transients', 'faq-jsonld' ); ?></button>
			<button id="fqj-clear-log" class="button"><?php echo esc_html__( 'Clear invalidation log', 'faq-jsonld' ); ?></button>
			<span id="fqj-action-status" style="margin-left:12px;"></span>
		</p>

		<h2><?php echo esc_html__( 'Recent invalidation history', 'faq-jsonld' ); ?></h2>
		<p>
			<?php
			/* translators: %d: number of logged runs. */
			echo esc_html( sprintf( __( 'Shows up to the last %d runs (newest first).', 'faq-jsonld' ), count( $log ) ) );
			?>
		</p>

		<table class="widefat striped">
			<thead>
				<tr>
					<th width="170"><?php echo esc_html__( 'Timestamp', 'faq-jsonld' ); ?></th>
					<th><?php echo esc_html__( 'Processed', 'faq-jsonld' ); ?></th>
					<th><?php echo esc_html__( 'Sample post IDs', 'faq-jsonld' ); ?></th>
				</tr>
			</thead>
			<tbody id="fqj-log-body">
				<?php
				if ( empty( $log ) ) {
					echo '<tr><td colspan="3">' . esc_html__( 'No invalidation runs logged yet.', 'faq-jsonld' ) . '</td></tr>';
				} else {
					foreach ( $log as $entry ) {
						$ts        = isset( $entry['ts'] ) ? intval( $entry['ts'] ) : 0;
						$processed = isset( $entry['processed'] ) ? intval( $entry['processed'] ) : 0;
						$sample    = isset( $entry['sample'] ) && is_array( $entry['sample'] ) ? $entry['sample'] : array();
						echo '<tr>';
						echo '<td>' . esc_html( $ts ? fqj_format_time_ago( $ts ) : __( 'n/a', 'faq-jsonld' ) ) . '</td>';
						echo '<td>' . esc_html( $processed ) . '</td>';
						echo '<td>' . esc_html( implode( ', ', $sample ) ) . '</td>';
						echo '</tr>';
					}
				}
				?>
			</tbody>
		</table>

		<h2><?php echo esc_html__( 'Notes', 'faq-jsonld' ); ?></h2>
		<ul>
			<li>
				<?php echo esc_html__( 'The queue is stored in a database table, so pending items survive cache flushes and restarts. Post type and term mappings are expanded into posts gradually by the worker.', 'faq-jsonld' ); ?>
			</li>
			<li>
				<?php echo esc_html__( 'If WP-Cron is disabled on your host, ensure real cron triggers wp-cron.php or use WP-CLI to process the queue manually.', 'faq-jsonld' ); ?>
			</li>
		</ul>
	</div>
	<?php
}

/**
 * AJAX: process queue now (admin)
 */
function fqj_ajax_process_queue_now() {
	check_ajax_referer( 'fqj_health_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied', 'faq-jsonld' ) ), 403 );
	}

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$limit     = isset( $_POST['limit'] ) ? intval( $_POST['limit'] ) : null;
	$processed = fqj_process_invalidation_queue_now( $limit );
	$queue_len = fqj_queue_length();
	$last_run  = (int) get_option( 'fqj_last_queue_run', 0 );

	wp_send_json_success(
		array(
			'processed'      => $processed,
			'queue_len'      => $queue_len,
			'last_run'       => $last_run,
			// Formatted on the server so the site's timezone and date settings apply.
			'last_run_human' => $last_run ? fqj_format_time_ago( $last_run ) : '',
		)
	);
}
add_action( 'wp_ajax_fqj_process_queue_now', 'fqj_ajax_process_queue_now' );

/**
 * AJAX: purge all FAQ transients (admin)
 */
function fqj_ajax_purge_transients() {
	check_ajax_referer( 'fqj_health_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied', 'faq-jsonld' ) ), 403 );
	}
	fqj_purge_all_faq_transients();
	wp_send_json_success( array( 'message' => __( 'Purged', 'faq-jsonld' ) ) );
}
add_action( 'wp_ajax_fqj_purge_transients', 'fqj_ajax_purge_transients' );

/**
 * AJAX: clear invalidation log
 */
function fqj_ajax_clear_invalidation_log() {
	check_ajax_referer( 'fqj_health_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied', 'faq-jsonld' ) ), 403 );
	}
	fqj_update_option_no_autoload( 'fqj_invalidation_log', array() );
	wp_send_json_success( array( 'message' => __( 'Cleared', 'faq-jsonld' ) ) );
}
add_action( 'wp_ajax_fqj_clear_invalidation_log', 'fqj_ajax_clear_invalidation_log' );
