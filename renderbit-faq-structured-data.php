<?php
/**
 * Plugin Name: Renderbit FAQ Structured Data
 * Plugin URI:  https://github.com/renderbit-technologies/faq-jsonld-wordpress-plugin
 * Description: Manage FAQ items as a custom post type and output FAQPage JSON-LD on the posts and pages they apply to.
 * Version:     3.1.1
 * Requires at least: 6.5
 * Requires PHP: 7.2
 * Author:      Renderbit Technologies
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: renderbit-faq-structured-data
 *
 * @package Renderbit_FAQ_Structured_Data
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable PSR1.Files.SideEffects

// Keep in sync with the Version header above; used to version the plugin's own assets.
define( 'RFSD_VERSION', '3.1.1' );
// Upper bound for the invalidation batch size setting.
define( 'RFSD_MAX_BATCH_SIZE', 5000 );
define( 'RFSD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RFSD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'RFSD_OPTION_KEY', 'rfsd_settings' );
define( 'RFSD_DB_VERSION', '3' );
define( 'RFSD_DB_VERSION_OPTION', 'rfsd_db_version' );

// Data migration version: bump to run a one-time reindex and cache purge on existing sites.
define( 'RFSD_DATA_VERSION', '3' );
define( 'RFSD_DATA_VERSION_OPTION', 'rfsd_data_version' );
define( 'RFSD_DATA_MIGRATION_CURSOR_OPTION', 'rfsd_data_migration_cursor' );
// URL associations rewritten by data migration 3, shown on the FAQ list screen until dismissed.
define( 'RFSD_URL_MIGRATION_REPORT_OPTION', 'rfsd_url_migration_report' );

// Post type of FAQ items. Older post type names in RFSD_LEGACY_POST_TYPES are renamed to it on existing sites.
define( 'RFSD_POST_TYPE', 'rfsd_faq' );
define( 'RFSD_LEGACY_POST_TYPES', array( 'faq_item' ) );
define( 'RFSD_POST_TYPE_OPTION', 'rfsd_post_type' );

/**
 * Autoload includes
 */
require_once RFSD_PLUGIN_DIR . 'includes/settings.php';
require_once RFSD_PLUGIN_DIR . 'includes/coverage.php';
require_once RFSD_PLUGIN_DIR . 'includes/indexer.php';
require_once RFSD_PLUGIN_DIR . 'includes/queue.php';
require_once RFSD_PLUGIN_DIR . 'includes/admin.php';
require_once RFSD_PLUGIN_DIR . 'includes/frontend.php';
require_once RFSD_PLUGIN_DIR . 'includes/class-rfsd-cli.php';
require_once RFSD_PLUGIN_DIR . 'includes/health.php';

/**
 * Register CPT
 */
function rfsd_register_cpt_faq_item() {
	$labels = array(
		'name'          => __( 'FAQ Items', 'renderbit-faq-structured-data' ),
		'singular_name' => __( 'FAQ Item', 'renderbit-faq-structured-data' ),
		'add_new_item'  => __( 'Add FAQ Item', 'renderbit-faq-structured-data' ),
		'edit_item'     => __( 'Edit FAQ Item', 'renderbit-faq-structured-data' ),
		'new_item'      => __( 'New FAQ Item', 'renderbit-faq-structured-data' ),
		'view_item'     => __( 'View FAQ Item', 'renderbit-faq-structured-data' ),
		'search_items'  => __( 'Search FAQ Items', 'renderbit-faq-structured-data' ),
		'not_found'     => __( 'No FAQ items found', 'renderbit-faq-structured-data' ),
		'all_items'     => __( 'All FAQ Items', 'renderbit-faq-structured-data' ),
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
	register_post_type( RFSD_POST_TYPE, $args );
}
add_action( 'init', 'rfsd_register_cpt_faq_item' );

/**
 * Rename FAQ items stored under a legacy post type to RFSD_POST_TYPE, once per site.
 *
 * Runs before the schema upgrade and the data migration, which only look at RFSD_POST_TYPE.
 */
function rfsd_migrate_legacy_post_types() {
	global $wpdb;

	if ( RFSD_POST_TYPE === get_option( RFSD_POST_TYPE_OPTION ) ) {
		return;
	}

	foreach ( RFSD_LEGACY_POST_TYPES as $legacy ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", $legacy ) );
		if ( $ids ) {
			$wpdb->update( $wpdb->posts, array( 'post_type' => RFSD_POST_TYPE ), array( 'post_type' => $legacy ) );
		}
		// phpcs:enable
		foreach ( $ids as $id ) {
			clean_post_cache( (int) $id );
		}
	}

	update_option( RFSD_POST_TYPE_OPTION, RFSD_POST_TYPE );
}
add_action( 'plugins_loaded', 'rfsd_migrate_legacy_post_types', 5 );

/**
 * Run a callback for every site of the network, or once when the install is single-site.
 *
 * @param callable $callback Called with no arguments while the site is switched to.
 */
function rfsd_for_each_site( $callback ) {
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
function rfsd_activate_site() {
	rfsd_migrate_legacy_post_types();

	// A site with no FAQs yet has nothing to migrate.
	if ( false === get_option( RFSD_DATA_VERSION_OPTION ) && ! rfsd_has_faq_items() ) {
		// Cached output can outlive deleted FAQs on a reactivated site.
		rfsd_purge_all_faq_transients();
		update_option( RFSD_DATA_VERSION_OPTION, RFSD_DATA_VERSION );
	}

	rfsd_create_table();
	// Reactivating after an upgrade skips the plugins_loaded upgrade path, so carry old data over here too.
	rfsd_migrate_legacy_queue();
	rfsd_unautoload_options();

	$defaults            = array(
		'cache_ttl'   => 12 * HOUR_IN_SECONDS,
		'batch_size'  => 500,
		'output_type' => 'faqpage',
	);
	$opts                = get_option( RFSD_OPTION_KEY, array() );
	$opts                = wp_parse_args( $opts, $defaults );
	$opts['output_type'] = rfsd_normalize_output_type( $opts['output_type'] );
	update_option( RFSD_OPTION_KEY, $opts );

	// schedule cron if not scheduled.
	if ( ! wp_next_scheduled( 'rfsd_process_invalidation_queue' ) ) {
		// Use a custom interval 'rfsd_five_minutes' registered in queue.php.
		wp_schedule_event( time() + 60, 'rfsd_five_minutes', 'rfsd_process_invalidation_queue' );
	}
}

/**
 * Activation. On network activation every existing site is set up.
 *
 * @param bool $network_wide Whether the plugin is being activated network-wide.
 */
function rfsd_activate( $network_wide = false ) {
	if ( $network_wide && is_multisite() ) {
		rfsd_for_each_site( 'rfsd_activate_site' );
		return;
	}

	rfsd_activate_site();
}
register_activation_hook( __FILE__, 'rfsd_activate' );

/**
 * Set up a site created while the plugin is network-active.
 *
 * @param WP_Site $new_site The new site.
 */
function rfsd_on_initialize_site( $new_site ) {
	if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( ! is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {
		return;
	}

	switch_to_blog( (int) $new_site->blog_id );
	rfsd_activate_site();
	restore_current_blog();
}
// Late priority so the new site's default options and tables exist first.
add_action( 'wp_initialize_site', 'rfsd_on_initialize_site', 900 );

/**
 * Clear the plugin's scheduled events for the current site.
 */
function rfsd_clear_scheduled_events() {
	wp_clear_scheduled_hook( 'rfsd_process_invalidation_queue' );
	wp_clear_scheduled_hook( 'rfsd_data_migration' );
}

/**
 * Deactivation: clear cron events (on every site for network deactivation).
 *
 * @param bool $network_wide Whether the plugin is being deactivated network-wide.
 */
function rfsd_deactivate( $network_wide = false ) {
	if ( $network_wide && is_multisite() ) {
		rfsd_for_each_site( 'rfsd_clear_scheduled_events' );
		return;
	}

	rfsd_clear_scheduled_events();
}
register_deactivation_hook( __FILE__, 'rfsd_deactivate' );
