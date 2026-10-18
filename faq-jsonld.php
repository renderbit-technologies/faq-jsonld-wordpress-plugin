<?php
/**
 * Plugin Name: FAQ JSON-LD Manager (Enterprise, queue-enabled)
 * Plugin URI:  https://github.com/renderbit-technologies/faq-jsonld-wordpress-plugin
 * Description: Manage FAQ items as CPT and inject FAQ JSON-LD. Uses a custom mapping table (fast), per-post
 *              transient caching, background invalidation queue (WP-Cron), settings UI and WP-CLI tools.
 * Version:     2.2.0
 * Author:      Renderbit Technologies
 * License:     GPLv2+
 *
 * @package FAQ_JSON_LD
 *
 * NOTE: original source content (optional import reference): /mnt/data/FAQs section content.docx
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable PSR1.Files.SideEffects

define( 'FQJ_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FQJ_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'FQJ_OPTION_KEY', 'fqj_settings' );
define( 'FQJ_DB_VERSION', '2' );
define( 'FQJ_DB_VERSION_OPTION', 'fqj_db_version' );

// Data migration version: bump to run a one-time reindex and cache purge on existing sites.
define( 'FQJ_DATA_VERSION', '2' );
define( 'FQJ_DATA_VERSION_OPTION', 'fqj_data_version' );
define( 'FQJ_DATA_MIGRATION_CURSOR_OPTION', 'fqj_data_migration_cursor' );

/**
 * Autoload includes
 */
require_once FQJ_PLUGIN_DIR . 'includes/settings.php';
require_once FQJ_PLUGIN_DIR . 'includes/indexer.php';
require_once FQJ_PLUGIN_DIR . 'includes/queue.php';
require_once FQJ_PLUGIN_DIR . 'includes/admin.php';
require_once FQJ_PLUGIN_DIR . 'includes/frontend.php';
require_once FQJ_PLUGIN_DIR . 'includes/class-fqjcli.php';
require_once FQJ_PLUGIN_DIR . 'includes/health.php';

/**
 * Register CPT
 */
function fqj_register_cpt_faq_item() {
	$labels = array(
		'name'          => 'FAQ Items',
		'singular_name' => 'FAQ Item',
		'add_new_item'  => 'Add FAQ Item',
		'edit_item'     => 'Edit FAQ Item',
		'new_item'      => 'New FAQ Item',
		'view_item'     => 'View FAQ Item',
		'search_items'  => 'Search FAQ Items',
		'not_found'     => 'No FAQ items found',
		'all_items'     => 'All FAQ Items',
	);
	$args   = array(
		'labels'          => $labels,
		'public'          => false,
		'show_ui'         => true,
		'show_in_menu'    => true,
		'capability_type' => 'post',
		'hierarchical'    => false,
		'supports'        => array( 'title', 'editor' ),
		'menu_position'   => 25,
		'menu_icon'       => 'dashicons-editor-help',
		'has_archive'     => false,
	);
	register_post_type( 'faq_item', $args );
}
add_action( 'init', 'fqj_register_cpt_faq_item' );

/**
 * Run a callback for every site of the network, or once when the install is single-site.
 *
 * @param callable $callback Called with no arguments while the site is switched to.
 */
function fqj_for_each_site( $callback ) {
	if ( ! is_multisite() ) {
		call_user_func( $callback );
		return;
	}

	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		call_user_func( $callback );
		restore_current_blog();
	}
}

/**
 * Activate the plugin for the current site: create DB table, defaults, schedule cron.
 */
function fqj_activate_site() {
	// A site with no FAQs yet has nothing to migrate.
	if ( false === get_option( FQJ_DATA_VERSION_OPTION ) && ! fqj_has_faq_items() ) {
		// Cached output can outlive deleted FAQs on a reactivated site.
		fqj_purge_all_faq_transients();
		update_option( FQJ_DATA_VERSION_OPTION, FQJ_DATA_VERSION );
	}

	fqj_create_table();

	$defaults            = array(
		'cache_ttl'           => 12 * HOUR_IN_SECONDS,
		'batch_size'          => 500,
		'output_type'         => 'faqpage',
		'queue_cron_interval' => 'fqj_five_minutes', // Interval name.
	);
	$opts                = get_option( FQJ_OPTION_KEY, array() );
	$opts                = wp_parse_args( $opts, $defaults );
	$opts['output_type'] = fqj_normalize_output_type( $opts['output_type'] );
	update_option( FQJ_OPTION_KEY, $opts );

	// schedule cron if not scheduled.
	if ( ! wp_next_scheduled( 'fqj_process_invalidation_queue' ) ) {
		// Use a custom interval 'fqj_five_minutes' registered in queue.php.
		wp_schedule_event( time() + 60, 'fqj_five_minutes', 'fqj_process_invalidation_queue' );
	}
}

/**
 * Activation. On network activation every existing site is set up.
 *
 * @param bool $network_wide Whether the plugin is being activated network-wide.
 */
function fqj_activate( $network_wide = false ) {
	if ( $network_wide && is_multisite() ) {
		fqj_for_each_site( 'fqj_activate_site' );
		return;
	}

	fqj_activate_site();
}
register_activation_hook( __FILE__, 'fqj_activate' );

/**
 * Set up a site created while the plugin is network-active.
 *
 * @param WP_Site $new_site The new site.
 */
function fqj_on_initialize_site( $new_site ) {
	if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( ! is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {
		return;
	}

	switch_to_blog( (int) $new_site->blog_id );
	fqj_activate_site();
	restore_current_blog();
}
// Late priority so the new site's default options and tables exist first.
add_action( 'wp_initialize_site', 'fqj_on_initialize_site', 900 );

/**
 * Clear the plugin's scheduled events for the current site.
 */
function fqj_clear_scheduled_events() {
	wp_clear_scheduled_hook( 'fqj_process_invalidation_queue' );
	wp_clear_scheduled_hook( 'fqj_data_migration' );
}

/**
 * Deactivation: clear cron events (on every site for network deactivation).
 *
 * @param bool $network_wide Whether the plugin is being deactivated network-wide.
 */
function fqj_deactivate( $network_wide = false ) {
	if ( $network_wide && is_multisite() ) {
		fqj_for_each_site( 'fqj_clear_scheduled_events' );
		return;
	}

	fqj_clear_scheduled_events();
}
register_deactivation_hook( __FILE__, 'fqj_deactivate' );
