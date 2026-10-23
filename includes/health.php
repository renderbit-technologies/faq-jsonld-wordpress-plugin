<?php
/**
 * Health page.
 *
 * @package Renderbit_FAQ_Structured_Data
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable PSR1.Files.SideEffects

/**
 * Health & Diagnostics admin page for the Renderbit FAQ Structured Data plugin.
 * Shows:
 *  - Queue length
 *  - Last run timestamp
 *  - Recent invalidation history
 *  - Buttons to process queue now, purge transients, clear log
 */

/**
 * Register submenu under FAQ Items
 */
function rfsd_register_health_page() {
	add_submenu_page(
		'edit.php?post_type=' . RFSD_POST_TYPE,
		__( 'FAQ Structured Data Health', 'renderbit-faq-structured-data' ),
		__( 'Health', 'renderbit-faq-structured-data' ),
		'manage_options',
		'rfsd-health',
		'rfsd_render_health_page'
	);
}
add_action( 'admin_menu', 'rfsd_register_health_page' );

/**
 * Enqueue health JS only on our page
 *
 * @param string $hook The current admin page.
 */
function rfsd_health_admin_assets( $hook ) {
	// check page hook: load only on rfsd-health page.
	if ( RFSD_POST_TYPE . '_page_rfsd-health' !== $hook ) {
		return;
	}

	wp_enqueue_script(
		'rfsd-health-js',
		RFSD_PLUGIN_URL . 'assets/js/rfsd-health.js',
		array( 'jquery' ),
		RFSD_VERSION,
		true
	);
	wp_localize_script(
		'rfsd-health-js',
		'rfsdHealth',
		array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'rfsd_health_nonce' ),
			'i18n'     => array(
				'processing'         => __( 'Processing…', 'renderbit-faq-structured-data' ),
				/* translators: %d: number of processed items. */
				'processed'          => __( 'Processed %d items.', 'renderbit-faq-structured-data' ),
				/* translators: %s: error message. */
				'error'              => __( 'Error: %s', 'renderbit-faq-structured-data' ),
				'unknown'            => __( 'unknown', 'renderbit-faq-structured-data' ),
				'requestFailed'      => __( 'Request failed. See console.', 'renderbit-faq-structured-data' ),
				'requestFailedShort' => __( 'Request failed.', 'renderbit-faq-structured-data' ),
				'purging'            => __( 'Purging…', 'renderbit-faq-structured-data' ),
				'purged'             => __( 'Purged transients.', 'renderbit-faq-structured-data' ),
				'purgeError'         => __( 'Error purging.', 'renderbit-faq-structured-data' ),
				'purgeConfirm'       => __( 'Purge all FAQ transients? This will force pages to rebuild the JSON-LD on next view.', 'renderbit-faq-structured-data' ),
				'clearing'           => __( 'Clearing…', 'renderbit-faq-structured-data' ),
				'cleared'            => __( 'Cleared log. Reload to refresh the table.', 'renderbit-faq-structured-data' ),
				'clearError'         => __( 'Error clearing log.', 'renderbit-faq-structured-data' ),
				'clearConfirm'       => __( 'Clear invalidation log? This will remove recent run history.', 'renderbit-faq-structured-data' ),
				'notAvailable'       => __( 'n/a', 'renderbit-faq-structured-data' ),
			),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'rfsd_health_admin_assets' );

/**
 * Format a timestamp as "Y-m-d H:i:s (5 mins ago)" in the site's timezone.
 *
 * @param int $timestamp Unix timestamp.
 * @return string
 */
function rfsd_format_time_ago( $timestamp ) {
	/* translators: %s: human-readable time difference, e.g. "5 mins". */
	$ago = sprintf( __( '%s ago', 'renderbit-faq-structured-data' ), human_time_diff( $timestamp, time() ) );

	return wp_date( 'Y-m-d H:i:s', $timestamp ) . ' (' . $ago . ')';
}

/**
 * Render health page
 */
function rfsd_render_health_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$queue_len   = rfsd_queue_length();
	$last_run_ts = get_option( 'rfsd_last_queue_run', 0 );
	$log         = get_option( 'rfsd_invalidation_log', array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}

	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'Renderbit FAQ Structured Data — Health & Diagnostics', 'renderbit-faq-structured-data' ); ?></h1>

		<h2><?php echo esc_html__( 'Queue', 'renderbit-faq-structured-data' ); ?></h2>
		<p><?php echo esc_html__( 'Pending invalidation items in queue:', 'renderbit-faq-structured-data' ); ?>
			<strong id="rfsd-queue-count"><?php echo intval( $queue_len ); ?></strong>
		</p>
		<p><?php echo esc_html__( 'Last queue run:', 'renderbit-faq-structured-data' ); ?> <strong id="rfsd-last-run">
		<?php
		echo $last_run_ts
			? esc_html( rfsd_format_time_ago( (int) $last_run_ts ) )
			: esc_html__( 'Never', 'renderbit-faq-structured-data' );
		?>
		</strong></p>

		<p>
			<button id="rfsd-process-now" class="button button-primary"><?php echo esc_html__( 'Process queue now', 'renderbit-faq-structured-data' ); ?></button>
			<button id="rfsd-purge-transients" class="button"><?php echo esc_html__( 'Purge all FAQ transients', 'renderbit-faq-structured-data' ); ?></button>
			<button id="rfsd-clear-log" class="button"><?php echo esc_html__( 'Clear invalidation log', 'renderbit-faq-structured-data' ); ?></button>
			<span id="rfsd-action-status" style="margin-left:12px;"></span>
		</p>

		<h2><?php echo esc_html__( 'Recent invalidation history', 'renderbit-faq-structured-data' ); ?></h2>
		<p>
			<?php
			/* translators: %d: number of logged runs. */
			echo esc_html( sprintf( __( 'Shows up to the last %d runs (newest first).', 'renderbit-faq-structured-data' ), count( $log ) ) );
			?>
		</p>

		<table class="widefat striped">
			<thead>
				<tr>
					<th width="170"><?php echo esc_html__( 'Timestamp', 'renderbit-faq-structured-data' ); ?></th>
					<th><?php echo esc_html__( 'Processed', 'renderbit-faq-structured-data' ); ?></th>
					<th><?php echo esc_html__( 'Sample post IDs', 'renderbit-faq-structured-data' ); ?></th>
				</tr>
			</thead>
			<tbody id="rfsd-log-body">
				<?php
				if ( empty( $log ) ) {
					echo '<tr><td colspan="3">' . esc_html__( 'No invalidation runs logged yet.', 'renderbit-faq-structured-data' ) . '</td></tr>';
				} else {
					foreach ( $log as $entry ) {
						$ts        = isset( $entry['ts'] ) ? intval( $entry['ts'] ) : 0;
						$processed = isset( $entry['processed'] ) ? intval( $entry['processed'] ) : 0;
						$sample    = isset( $entry['sample'] ) && is_array( $entry['sample'] ) ? $entry['sample'] : array();
						echo '<tr>';
						echo '<td>' . esc_html( $ts ? rfsd_format_time_ago( $ts ) : __( 'n/a', 'renderbit-faq-structured-data' ) ) . '</td>';
						echo '<td>' . esc_html( $processed ) . '</td>';
						echo '<td>' . esc_html( implode( ', ', $sample ) ) . '</td>';
						echo '</tr>';
					}
				}
				?>
			</tbody>
		</table>

		<h2><?php echo esc_html__( 'Notes', 'renderbit-faq-structured-data' ); ?></h2>
		<ul>
			<li>
				<?php echo esc_html__( 'The queue is stored in a database table, so pending items survive cache flushes and restarts. Post type and term mappings are expanded into posts gradually by the worker.', 'renderbit-faq-structured-data' ); ?>
			</li>
			<li>
				<?php echo esc_html__( 'If WP-Cron is disabled on your host, ensure real cron triggers wp-cron.php or use WP-CLI to process the queue manually.', 'renderbit-faq-structured-data' ); ?>
			</li>
		</ul>
	</div>
	<?php
}

/**
 * AJAX: process queue now (admin)
 */
function rfsd_ajax_process_queue_now() {
	check_ajax_referer( 'rfsd_health_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied', 'renderbit-faq-structured-data' ) ), 403 );
	}

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$limit     = isset( $_POST['limit'] ) ? intval( $_POST['limit'] ) : null;
	$processed = rfsd_process_invalidation_queue_now( $limit );
	$queue_len = rfsd_queue_length();
	$last_run  = (int) get_option( 'rfsd_last_queue_run', 0 );

	wp_send_json_success(
		array(
			'processed'      => $processed,
			'queue_len'      => $queue_len,
			'last_run'       => $last_run,
			// Formatted on the server so the site's timezone and date settings apply.
			'last_run_human' => $last_run ? rfsd_format_time_ago( $last_run ) : '',
		)
	);
}
add_action( 'wp_ajax_rfsd_process_queue_now', 'rfsd_ajax_process_queue_now' );

/**
 * AJAX: purge all FAQ transients (admin)
 */
function rfsd_ajax_purge_transients() {
	check_ajax_referer( 'rfsd_health_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied', 'renderbit-faq-structured-data' ) ), 403 );
	}
	rfsd_purge_all_faq_transients();
	wp_send_json_success( array( 'message' => __( 'Purged', 'renderbit-faq-structured-data' ) ) );
}
add_action( 'wp_ajax_rfsd_purge_transients', 'rfsd_ajax_purge_transients' );

/**
 * AJAX: clear invalidation log
 */
function rfsd_ajax_clear_invalidation_log() {
	check_ajax_referer( 'rfsd_health_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied', 'renderbit-faq-structured-data' ) ), 403 );
	}
	rfsd_update_option_no_autoload( 'rfsd_invalidation_log', array() );
	wp_send_json_success( array( 'message' => __( 'Cleared', 'renderbit-faq-structured-data' ) ) );
}
add_action( 'wp_ajax_rfsd_clear_invalidation_log', 'rfsd_ajax_clear_invalidation_log' );
