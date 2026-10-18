<?php
/**
 * Settings page.
 *
 * @package FAQ_JSON_LD
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable PSR1.Files.SideEffects

/**
 * Settings page: TTL, batch size, output_type
 */
function fqj_register_settings_page() {
	add_submenu_page(
		'edit.php?post_type=faq_item',
		'FAQ JSON-LD Settings',
		'Settings',
		'manage_options',
		'fqj-settings',
		'fqj_render_settings_page'
	);
}
add_action( 'admin_menu', 'fqj_register_settings_page' );

/**
 * Render the settings page.
 */
function fqj_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$options = get_option( FQJ_OPTION_KEY );
	if ( ! $options ) {
		$options = array();
	}

	// Handle save.
	if (
		isset( $_POST['fqj_settings_nonce'] )
		&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['fqj_settings_nonce'] ) ), 'fqj_save_settings' )
	) {
		$cache_ttl   = isset( $_POST['cache_ttl'] ) ? intval( $_POST['cache_ttl'] ) : 0;
		$batch_size  = isset( $_POST['batch_size'] ) ? intval( $_POST['batch_size'] ) : 0;
		$posted_type = isset( $_POST['output_type'] ) ? sanitize_text_field( wp_unslash( $_POST['output_type'] ) ) : '';

		$output_type = in_array( $posted_type, array( 'faqsection', 'faqpage' ), true )
			? $posted_type
			: 'faqsection';

		$options['cache_ttl']   = max( 60, $cache_ttl );
		$options['batch_size']  = max( 10, $batch_size );
		$options['output_type'] = $output_type;
		update_option( FQJ_OPTION_KEY, $options );

		echo '<div class="updated"><p>Settings saved.</p></div>';
	}

	$cache_ttl   = isset( $options['cache_ttl'] ) ? intval( $options['cache_ttl'] ) : 12 * HOUR_IN_SECONDS;
	$batch_size  = isset( $options['batch_size'] ) ? intval( $options['batch_size'] ) : 500;
	$output_type = isset( $options['output_type'] ) ? $options['output_type'] : 'faqsection';
	?>
	<div class="wrap">
		<h1>FAQ JSON-LD Settings</h1>
		<form method="post">
			<?php wp_nonce_field( 'fqj_save_settings', 'fqj_settings_nonce' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row"><label for="cache_ttl">Cache TTL (seconds)</label></th>
					<td><input name="cache_ttl" id="cache_ttl" type="number"
						value="<?php echo esc_attr( $cache_ttl ); ?>" class="regular-text" />
						<p class="description">
						Number of seconds to cache per-post JSON-LD. Default is 43200 (12 hours).
						</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="batch_size">Invalidation batch size</label></th>
					<td><input name="batch_size" id="batch_size" type="number"
						value="<?php echo esc_attr( $batch_size ); ?>" class="regular-text" />
						<p class="description">
						When invalidating many posts (e.g., post-type mapping), process posts in batches
						of this size to avoid timeouts.
						</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="output_type">Default output type</label></th>
					<td>
						<select name="output_type" id="output_type">
							<option value="faqsection" <?php selected( $output_type, 'faqsection' ); ?>>
								FAQSection (recommended)
							</option>
							<option value="faqpage" <?php selected( $output_type, 'faqpage' ); ?>>
								FAQPage
							</option>
						</select>
						<p class="description">
						Choose whether the plugin outputs <code>FAQSection</code> or <code>FAQPage</code> by default.
						Individual FAQs still control associations.
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>

		<h2>Tools</h2>
		<p>
			<strong>Purge all FAQ transients:</strong>
		</p>
			<form method="post" style="display:inline;">
				<?php wp_nonce_field( 'fqj_purge_all_nonce', 'fqj_purge_all_nonce_field' ); ?>
				<input type="hidden" name="fqj_action" value="purge_all" />
				<?php submit_button( 'Purge transients', 'secondary', 'submit', false ); ?>
			</form>


		<?php
		if (
			isset( $_POST['fqj_action'] )
			&& 'purge_all' === $_POST['fqj_action']
			&& isset( $_POST['fqj_purge_all_nonce_field'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['fqj_purge_all_nonce_field'] ) ), 'fqj_purge_all_nonce' )
		) {
			fqj_purge_all_faq_transients();
			echo '<div class="updated"><p>All FAQ transients purged.</p></div>';
		}
		?>

	</div>
	<?php
}

/**
 * Current cache generation. Part of every cached output key, so bumping it orphans all cached output.
 *
 * @return int
 */
function fqj_cache_generation() {
	return max( 1, (int) get_option( 'fqj_cache_gen', 1 ) );
}

/**
 * Transient key for the cached JSON-LD output of a post.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function fqj_cache_key( $post_id ) {
	return 'fqj_faq_json_' . fqj_cache_generation() . '_' . intval( $post_id );
}

/**
 * Purge all cached FAQ output.
 *
 * Bumps the cache generation, which works with any storage backend (including persistent object caches,
 * where transients never reach the options table) in O(1). Without an external object cache the orphaned
 * rows are also removed from wp_options so dead transients do not pile up.
 *
 * @return int The new cache generation.
 */
function fqj_purge_all_faq_transients() {
	global $wpdb;

	$generation = fqj_cache_generation() + 1;
	if ( false === get_option( 'fqj_cache_gen' ) ) {
		add_option( 'fqj_cache_gen', $generation, '', true );
	} else {
		update_option( 'fqj_cache_gen', $generation, true );
	}

	if ( ! wp_using_ext_object_cache() ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_fqj_faq_json_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_fqj_faq_json_' ) . '%'
			)
		);
		// phpcs:enable
		foreach ( $rows as $option_name ) {
			delete_option( $option_name );
		}
	}

	return $generation;
}

/**
 * Cached output depends on the output type, so changing it must purge.
 *
 * @param mixed $old_value Previous settings.
 * @param mixed $value     New settings.
 */
function fqj_maybe_purge_on_settings_change( $old_value, $value ) {
	$old_type = is_array( $old_value ) && isset( $old_value['output_type'] ) ? $old_value['output_type'] : 'faqsection';
	$new_type = is_array( $value ) && isset( $value['output_type'] ) ? $value['output_type'] : 'faqsection';
	if ( $old_type !== $new_type ) {
		fqj_purge_all_faq_transients();
	}
}
add_action( 'update_option_' . FQJ_OPTION_KEY, 'fqj_maybe_purge_on_settings_change', 10, 2 );
