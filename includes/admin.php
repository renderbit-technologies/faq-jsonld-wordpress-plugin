<?php
/**
 * Admin functions.
 *
 * @package Renderbit_FAQ_Structured_Data
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable PSR1.Files.SideEffects

/**
 * Enqueue admin assets for the FAQ edit screen
 *
 * @param string $hook The current admin page.
 */
function rfsd_admin_assets( $hook ) {
	global $post;
	if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}
	if ( ! $post || RFSD_POST_TYPE !== $post->post_type ) {
		return;
	}

	/*
	 * Select2 is bundled in assets/vendor/select2 (no third-party CDN). If another plugin (e.g. WooCommerce's
	 * selectWoo) has already registered a Select2 build, reuse it as the dependency instead of loading a second
	 * copy that would clash on jQuery.fn.select2.
	 */
	$select2_handle = 'rfsd-select2-js';
	if ( wp_script_is( 'select2', 'registered' ) ) {
		$select2_handle = 'select2';
	} elseif ( wp_script_is( 'selectWoo', 'registered' ) ) {
		$select2_handle = 'selectWoo';
	}

	// The unminified Select2 sources ship alongside the minified build; SCRIPT_DEBUG loads them.
	$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';

	if ( 'rfsd-select2-js' === $select2_handle ) {
		wp_enqueue_style(
			'rfsd-select2-css',
			RFSD_PLUGIN_URL . 'assets/vendor/select2/select2' . $suffix . '.css',
			array(),
			'4.1.0'
		);
		wp_enqueue_script(
			'rfsd-select2-js',
			RFSD_PLUGIN_URL . 'assets/vendor/select2/select2' . $suffix . '.js',
			array( 'jquery' ),
			'4.1.0',
			true
		);
	} else {
		wp_enqueue_script( $select2_handle );
		// The style handle can differ from the script's (WooCommerce registers selectWoo JS with select2 CSS).
		$style_handle = '';
		foreach ( array( $select2_handle, 'select2', 'selectWoo' ) as $candidate ) {
			if ( wp_style_is( $candidate, 'registered' ) ) {
				$style_handle = $candidate;
				break;
			}
		}
		if ( $style_handle ) {
			wp_enqueue_style( $style_handle );
		} else {
			wp_enqueue_style(
				'rfsd-select2-css',
				RFSD_PLUGIN_URL . 'assets/vendor/select2/select2' . $suffix . '.css',
				array(),
				'4.1.0'
			);
		}
	}

	wp_enqueue_script(
		'rfsd-admin-js',
		RFSD_PLUGIN_URL . 'assets/js/rfsd-admin.js',
		array( 'jquery', $select2_handle ),
		RFSD_VERSION,
		true
	);
	wp_localize_script(
		'rfsd-admin-js',
		'rfsdAdmin',
		array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'rfsd_admin_nonce' ),
			'post_id'  => get_the_ID(),
			'i18n'     => array(
				'urlsHelp'         => __( 'Enter one URL per line (e.g. https://example.com/about/). The FAQ appears on whichever post has that permalink.', 'renderbit-faq-structured-data' ),
				'postsHelp'        => __( 'Search posts/pages and add multiple results.', 'renderbit-faq-structured-data' ),
				'postsPlaceholder' => __( 'Search posts by title...', 'renderbit-faq-structured-data' ),
				'postTypesHelp'    => __( 'Select post type(s) to associate this FAQ with.', 'renderbit-faq-structured-data' ),
				'termsHelp'        => __( 'Search taxonomy terms and add multiple results.', 'renderbit-faq-structured-data' ),
				'termsPlaceholder' => __( 'Search terms by name...', 'renderbit-faq-structured-data' ),
				'globalHelp'       => __( 'This FAQ will be included site-wide.', 'renderbit-faq-structured-data' ),
			),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'rfsd_admin_assets' );

/**
 * Add association meta box
 */
function rfsd_add_meta_boxes() {
	add_meta_box(
		'rfsd_assoc_rules',
		__( 'Associations & Output', 'renderbit-faq-structured-data' ),
		'rfsd_assoc_rules_meta_box_cb',
		RFSD_POST_TYPE,
		'normal',
		'default'
	);
}
add_action( 'add_meta_boxes', 'rfsd_add_meta_boxes' );

/**
 * Render association meta box.
 *
 * @param WP_Post $post The post object.
 */
function rfsd_assoc_rules_meta_box_cb( $post ) {
	wp_nonce_field( 'rfsd_save_meta', 'rfsd_meta_nonce' );

	// Load stored JSON payload for this FAQ.
	$data_json = get_post_meta( $post->ID, 'rfsd_assoc_data_json', true );
	$data_json = $data_json ? $data_json : '{}';
	$data      = json_decode( $data_json, true );
	if ( ! is_array( $data ) ) {
		$data = array();
	}

	// Hydrate posts and terms with titles/names for Select2.
	// Every stored ID is kept (even drafts, private, trashed or deleted items) so a re-save never drops it.
	if ( isset( $data['posts'] ) && is_array( $data['posts'] ) && ! empty( $data['posts'] ) ) {
		$hydrated_posts = array();
		foreach ( array_map( 'intval', $data['posts'] ) as $pid ) {
			$p = get_post( $pid );
			if ( $p ) {
				$text = get_the_title( $p ) . ' – ' . get_permalink( $p );
				if ( 'publish' !== $p->post_status ) {
					$text .= ' (' . $p->post_status . ')';
				}
			} else {
				/* translators: %d: post ID. */
				$text = sprintf( __( 'Post #%d (not found)', 'renderbit-faq-structured-data' ), $pid );
			}
			$hydrated_posts[] = array(
				'id'   => $pid,
				'text' => $text,
			);
		}
		$data['posts'] = $hydrated_posts;
	}

	if ( isset( $data['terms'] ) && is_array( $data['terms'] ) && ! empty( $data['terms'] ) ) {
		$hydrated_terms = array();
		foreach ( array_map( 'intval', $data['terms'] ) as $tid ) {
			$term = get_term( $tid );
			if ( $term && ! is_wp_error( $term ) ) {
				$text = $term->name . ' (' . $term->taxonomy . ')';
			} else {
				/* translators: %d: term ID. */
				$text = sprintf( __( 'Term #%d (not found)', 'renderbit-faq-structured-data' ), $tid );
			}
			$hydrated_terms[] = array(
				'id'   => $tid,
				'text' => $text,
			);
		}
		$data['terms'] = $hydrated_terms;
	}

	// Always hand the JS an object, even when the payload is empty.
	$data_json_hydrated = empty( $data ) ? '{}' : wp_json_encode( $data );

	$assoc_type = get_post_meta( $post->ID, 'rfsd_assoc_type', true );
	$assoc_type = $assoc_type ? $assoc_type : 'urls';

	$assoc_types = array(
		'urls'       => __( 'By URLs (one per line)', 'renderbit-faq-structured-data' ),
		'posts'      => __( 'By Posts (search & multi-select)', 'renderbit-faq-structured-data' ),
		'post_types' => __( 'By Post Types (apply to all posts of selected types)', 'renderbit-faq-structured-data' ),
		'tax_terms'  => __( 'By Taxonomy Terms (search & multi-select)', 'renderbit-faq-structured-data' ),
		'global'     => __( 'Global (site-wide)', 'renderbit-faq-structured-data' ),
	);

	echo '<p><label for="rfsd_assoc_type"><strong>' . esc_html__( 'Association Type', 'renderbit-faq-structured-data' ) . '</strong></label></p>';
	echo '<select id="rfsd_assoc_type" name="rfsd_assoc_type" style="width:100%;max-width:400px;">';
	foreach ( $assoc_types as $k => $label ) {
		echo '<option value="' . esc_attr( $k ) . '"' . selected( $assoc_type, $k, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select>';

	echo '<input type="hidden" id="rfsd_assoc_data" name="rfsd_assoc_data" value="' .
		esc_attr( $data_json_hydrated ) . '">';

	echo '<div id="rfsd_assoc_container" style="margin-top:12px;"></div>';

	echo '<p class="description">' . esc_html__( 'Enter association details. Use the "By Posts" option to search and multi-select posts. For large sites, prefer Post Types or Taxonomy Terms.', 'renderbit-faq-structured-data' ) . '</p>';
}

/**
 * Save meta handler (similar to previous implementation but store payload JSON for indexing)
 *
 * @param int     $post_id The post ID.
 * @param WP_Post $post The post object.
 */
function rfsd_save_meta_handler( $post_id, $post ) {
	if ( ! isset( $_POST['rfsd_meta_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rfsd_meta_nonce'] ) ), 'rfsd_save_meta' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( RFSD_POST_TYPE !== $post->post_type ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$assoc_type = isset( $_POST['rfsd_assoc_type'] ) ? sanitize_text_field( wp_unslash( $_POST['rfsd_assoc_type'] ) ) : 'urls';
	if ( ! in_array( $assoc_type, array( 'urls', 'posts', 'post_types', 'tax_terms', 'global' ), true ) ) {
		// Unknown type: leave the stored meta and mappings untouched.
		return;
	}
	$payload = array();

	// Drop any notices left over from an earlier save.
	delete_transient( 'rfsd_rejected_urls_' . get_current_user_id() . '_' . $post_id );
	delete_transient( 'rfsd_unmatched_urls_' . get_current_user_id() . '_' . $post_id );

	if ( 'urls' === $assoc_type ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw   = isset( $_POST['rfsd_assoc_urls'] ) ? trim( wp_unslash( $_POST['rfsd_assoc_urls'] ) ) : '';
		$lines = preg_split( "/\r\n|\n|\r/", $raw );
		$urls  = array();
		$bad   = array();
		foreach ( $lines as $l ) {
			$l = trim( $l );
			if ( empty( $l ) ) {
				continue;
			}
			$clean = esc_url_raw( $l );
			if ( '' === $clean ) {
				// Rejected (e.g. disallowed protocol); do not store an empty mapping.
				$bad[] = $l;
				continue;
			}
			$urls[] = $clean;
		}
		$payload['urls'] = array_values( array_unique( $urls ) );
		if ( $bad ) {
			set_transient( 'rfsd_rejected_urls_' . get_current_user_id() . '_' . $post_id, $bad, MINUTE_IN_SECONDS );
		}

		// Warn, never reject: a URL that is no post's permalink covers nothing until a post takes it.
		$unmatched = array();
		foreach ( $payload['urls'] as $url ) {
			if ( rfsd_post_for_url( $url ) ) {
				continue;
			}
			$routed      = rfsd_post_routed_from_url( $url );
			$unmatched[] = array(
				'url'        => $url,
				'suggestion' => $routed ? (string) get_permalink( $routed ) : '',
			);
		}
		if ( $unmatched ) {
			set_transient( 'rfsd_unmatched_urls_' . get_current_user_id() . '_' . $post_id, $unmatched, MINUTE_IN_SECONDS );
		}
	} elseif ( 'posts' === $assoc_type ) {
		$post_ids = array();
		if ( isset( $_POST['rfsd_assoc_posts_select'] ) && is_array( $_POST['rfsd_assoc_posts_select'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			foreach ( wp_unslash( $_POST['rfsd_assoc_posts_select'] ) as $pid ) {
				$post_ids[] = intval( $pid );
			}
		}
		$payload['posts'] = array_values( array_unique( $post_ids ) );
	} elseif ( 'post_types' === $assoc_type ) {
		$ptypes = array();
		if ( isset( $_POST['rfsd_assoc_post_types'] ) && is_array( $_POST['rfsd_assoc_post_types'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			foreach ( wp_unslash( $_POST['rfsd_assoc_post_types'] ) as $pt ) {
				$ptypes[] = sanitize_text_field( $pt );
			}
		}
		$payload['post_types'] = array_values( array_unique( $ptypes ) );
	} elseif ( 'tax_terms' === $assoc_type ) {
		$terms = array();
		if ( isset( $_POST['rfsd_assoc_terms_select'] ) && is_array( $_POST['rfsd_assoc_terms_select'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			foreach ( wp_unslash( $_POST['rfsd_assoc_terms_select'] ) as $t ) {
				$terms[] = intval( $t );
			}
		}
		$payload['terms'] = array_values( array_unique( $terms ) );
	} elseif ( 'global' === $assoc_type ) {
		$payload['global'] = true;
	}

	update_post_meta( $post_id, 'rfsd_assoc_type', $assoc_type );
	update_post_meta( $post_id, 'rfsd_assoc_data_json', wp_json_encode( $payload ) );

	// Rebuild index (indexer will delete and reinsert mappings, then invalidate transients).
	rfsd_rebuild_index_for_faq( $post_id );
}
add_action( 'save_post', 'rfsd_save_meta_handler', 10, 2 );

/**
 * Admin notice listing URLs rejected on the last save of this FAQ.
 */
function rfsd_rejected_urls_notice() {
	$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! $post_id ) {
		return;
	}
	$key = 'rfsd_rejected_urls_' . get_current_user_id() . '_' . $post_id;
	$bad = get_transient( $key );
	if ( empty( $bad ) || ! is_array( $bad ) ) {
		return;
	}
	delete_transient( $key );
	printf(
		'<div class="notice notice-warning is-dismissible"><p>%s</p><ul>%s</ul></div>',
		esc_html__( 'These URLs were not valid and were not saved:', 'renderbit-faq-structured-data' ),
		'<li><code>' . implode( '</code></li><li><code>', array_map( 'esc_html', $bad ) ) . '</code></li>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	);
}
add_action( 'admin_notices', 'rfsd_rejected_urls_notice' );

/**
 * Admin notice listing saved URLs of this FAQ that are not the permalink of any post on this site.
 */
function rfsd_unmatched_urls_notice() {
	$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! $post_id ) {
		return;
	}
	$key       = 'rfsd_unmatched_urls_' . get_current_user_id() . '_' . $post_id;
	$unmatched = get_transient( $key );
	if ( empty( $unmatched ) || ! is_array( $unmatched ) ) {
		return;
	}
	delete_transient( $key );

	$items = '';
	foreach ( $unmatched as $entry ) {
		$items .= '<li><code>' . esc_html( $entry['url'] ) . '</code>';
		if ( '' !== $entry['suggestion'] ) {
			/* translators: %s: permalink of the post the URL leads to. */
			$items .= ' ' . sprintf( esc_html__( '(did you mean %s?)', 'renderbit-faq-structured-data' ), '<code>' . esc_html( $entry['suggestion'] ) . '</code>' );
		}
		$items .= '</li>';
	}
	printf(
		'<div class="notice notice-warning is-dismissible"><p>%s</p><ul>%s</ul></div>',
		esc_html__( 'These URLs were saved, but they are not the permalink of any post on this site, so the FAQ will not appear there:', 'renderbit-faq-structured-data' ),
		$items // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	);
}
add_action( 'admin_notices', 'rfsd_unmatched_urls_notice' );

/**
 * Admin notice on the FAQ list screen listing URL associations rewritten by data migration 3.
 */
function rfsd_url_migration_report_notice() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'edit-' . RFSD_POST_TYPE !== $screen->id || ! current_user_can( 'edit_others_posts' ) ) {
		return;
	}
	$report = get_option( RFSD_URL_MIGRATION_REPORT_OPTION );
	if ( empty( $report ) || ! is_array( $report ) ) {
		return;
	}

	$items = '';
	foreach ( $report as $entry ) {
		$faq  = '<a href="' . esc_url( (string) get_edit_post_link( $entry['faq_id'] ) ) . '">' . esc_html( get_the_title( $entry['faq_id'] ) ) . '</a>';
		$new  = '<code>' . esc_html( $entry['new'] ) . '</code>';
		$line = '' === $entry['old']
			/* translators: 1: FAQ title, 2: added URL. */
			? sprintf( esc_html__( '%1$s: added %2$s', 'renderbit-faq-structured-data' ), $faq, $new )
			/* translators: 1: FAQ title, 2: old URL, 3: new URL. */
			: sprintf( esc_html__( '%1$s: %2$s became %3$s', 'renderbit-faq-structured-data' ), $faq, '<code>' . esc_html( $entry['old'] ) . '</code>', $new );
		$items .= '<li>' . $line . '</li>';
	}
	$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=rfsd_dismiss_url_migration_report' ), 'rfsd_dismiss_url_migration_report' );

	printf(
		'<div class="notice notice-info"><p>%s</p><ul>%s</ul><p><a class="button" href="%s">%s</a></p></div>',
		esc_html__( 'Renderbit FAQ Structured Data now matches URL associations by the URL itself. To keep each FAQ on the posts it appeared on before, these URLs were updated:', 'renderbit-faq-structured-data' ),
		$items, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		esc_url( $dismiss ),
		esc_html__( 'Dismiss', 'renderbit-faq-structured-data' )
	);
}
add_action( 'admin_notices', 'rfsd_url_migration_report_notice' );

/**
 * Dismiss the URL migration report for everyone.
 */
function rfsd_dismiss_url_migration_report() {
	check_admin_referer( 'rfsd_dismiss_url_migration_report' );
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		wp_die( esc_html__( 'Permission denied', 'renderbit-faq-structured-data' ), 403 );
	}
	delete_option( RFSD_URL_MIGRATION_REPORT_OPTION );
}

/**
 * Handle the dismiss link, then return to the FAQ list.
 */
function rfsd_admin_post_dismiss_url_migration_report() {
	rfsd_dismiss_url_migration_report();
	wp_safe_redirect( admin_url( 'edit.php?post_type=' . RFSD_POST_TYPE ) );
	exit;
}
add_action( 'admin_post_rfsd_dismiss_url_migration_report', 'rfsd_admin_post_dismiss_url_migration_report' );

/**
 * AJAX: search posts for Select2
 */
function rfsd_ajax_search_posts() {
	check_ajax_referer( 'rfsd_admin_nonce', 'nonce' );
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied', 'renderbit-faq-structured-data' ) ), 403 );
	}
	$q       = isset( $_REQUEST['q'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['q'] ) ) : '';
	$results = array();
	if ( strlen( $q ) < 1 ) {
		wp_send_json( $results );
	}

	$args  = array(
		's'              => $q,
		'post_type'      => array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment', RFSD_POST_TYPE ) ) ),
		'posts_per_page' => 20,
		'post_status'    => 'publish',
	);
	$posts = get_posts( $args );
	foreach ( $posts as $p ) {
		$results[] = array(
			'id'   => $p->ID,
			'text' => get_the_title( $p ) . ' – ' . get_permalink( $p ),
		);
	}
	wp_send_json( $results );
}
add_action( 'wp_ajax_rfsd_search_posts', 'rfsd_ajax_search_posts' );

/**
 * AJAX: search terms
 */
function rfsd_ajax_search_terms() {
	check_ajax_referer( 'rfsd_admin_nonce', 'nonce' );
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied', 'renderbit-faq-structured-data' ) ), 403 );
	}
	$q       = isset( $_REQUEST['q'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['q'] ) ) : '';
	$results = array();
	if ( strlen( $q ) < 1 ) {
		wp_send_json( $results );
	}

	$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
	foreach ( $taxonomies as $tax ) {
		$terms = get_terms(
			array(
				'taxonomy'   => $tax,
				'name__like' => $q,
				'number'     => 10,
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) ) {
			continue;
		}
		foreach ( $terms as $t ) {
			$results[] = array(
				'id'   => $t->term_id,
				'text' => $t->name . ' (' . $tax . ')',
			);
		}
	}
	wp_send_json( $results );
}
add_action( 'wp_ajax_rfsd_search_terms', 'rfsd_ajax_search_terms' );

/**
 * AJAX: get public post types
 */
function rfsd_ajax_get_post_types() {
	check_ajax_referer( 'rfsd_admin_nonce', 'nonce' );
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied', 'renderbit-faq-structured-data' ) ), 403 );
	}
	$pts = get_post_types( array( 'public' => true ), 'objects' );
	$out = array();
	foreach ( $pts as $k => $obj ) {
		$out[] = array(
			'name'  => $k,
			'label' => $obj->labels->singular_name,
		);
	}
	wp_send_json( $out );
}
add_action( 'wp_ajax_rfsd_get_post_types', 'rfsd_ajax_get_post_types' );
