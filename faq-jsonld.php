<?php
/**
 * Plugin Name: FAQ JSON-LD Manager
 * Description: Create FAQ items (CPT) and automatically inject FAQSection JSON-LD for pages that have associated FAQs.
 * Version: 1.0
 * Author: Renderbit / Soham
 * License: GPLv2+
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Register CPT: faq_item
 */
function fqj_register_cpt_faq_item() {
    $labels = array(
        'name'               => 'FAQ Items',
        'singular_name'      => 'FAQ Item',
        'add_new_item'       => 'Add FAQ Item',
        'edit_item'          => 'Edit FAQ Item',
        'new_item'           => 'New FAQ Item',
        'view_item'          => 'View FAQ Item',
        'search_items'       => 'Search FAQ Items',
        'not_found'          => 'No FAQ items found',
        'all_items'          => 'All FAQ Items',
    );
    $args = array(
        'labels'             => $labels,
        'public'             => false,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'capability_type'    => 'post',
        'hierarchical'       => false,
        'supports'           => array( 'title', 'editor' ), // title = question, editor = answer
        'menu_position'      => 25,
        'menu_icon'          => 'dashicons-editor-help',
        'has_archive'        => false,
    );
    register_post_type( 'faq_item', $args );
}
add_action( 'init', 'fqj_register_cpt_faq_item' );

/**
 * Add meta box to associate URLs with the FAQ item
 */
function fqj_add_meta_boxes() {
    add_meta_box(
        'fqj_assoc_urls',
        'Associated URLs',
        'fqj_assoc_urls_meta_box_cb',
        'faq_item',
        'normal',
        'default'
    );
}
add_action( 'add_meta_boxes', 'fqj_add_meta_boxes' );

function fqj_assoc_urls_meta_box_cb( $post ) {
    wp_nonce_field( 'fqj_save_meta', 'fqj_meta_nonce' );

    // Retrieve stored CSV (with enclosing commas) and display original URLs
    $csv = get_post_meta( $post->ID, 'fqj_assoc_posts_csv', true );
    $urls = '';
    if ( $csv ) {
        // stored as ",1,2,3," => explode
        $ids = array_filter( explode( ',', trim( $csv, ',' ) ) );
        $lines = array();
        foreach ( $ids as $id ) {
            $p = get_post( intval( $id ) );
            if ( $p ) {
                $lines[] = get_permalink( $p );
            }
        }
        $urls = implode( "\n", $lines );
    }

    echo '<p>Enter one URL per line (full URL). Example: <code>https://example.com/about/</code></p>';
    echo '<textarea name="fqj_assoc_urls" rows="6" style="width:100%;">' . esc_textarea( $urls ) . '</textarea>';
    echo '<p class="description">When this page/post is viewed, any FAQ items that contain its URL will be included in the page JSON-LD.</p>';
}

/**
 * Save meta (convert URLs to post IDs and store CSV like ",1,2,3,")
 */
function fqj_save_meta( $post_id, $post ) {
    if ( ! isset( $_POST['fqj_meta_nonce'] ) ) return;
    if ( ! wp_verify_nonce( $_POST['fqj_meta_nonce'], 'fqj_save_meta' ) ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( $post->post_type !== 'faq_item' ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    $raw = isset( $_POST['fqj_assoc_urls'] ) ? wp_kses_post( trim( wp_unslash( $_POST['fqj_assoc_urls'] ) ) ) : '';

    // split lines and map to post IDs via url_to_postid
    $lines = preg_split( "/\r\n|\n|\r/", $raw );
    $ids = array();
    foreach ( $lines as $line ) {
        $u = trim( $line );
        if ( empty( $u ) ) continue;
        $pid = url_to_postid( $u );
        if ( $pid ) {
            $ids[] = intval( $pid );
        } else {
            // attempt to normalize (strip query) and try again
            $u2 = strtok( $u, '?' );
            $pid2 = url_to_postid( $u2 );
            if ( $pid2 ) $ids[] = intval( $pid2 );
        }
    }
    $ids = array_values( array_unique( $ids ) );
    if ( empty( $ids ) ) {
        delete_post_meta( $post_id, 'fqj_assoc_posts_csv' );
    } else {
        // store as enclosing-comma CSV so LIKE queries are safe: ",1,2,3,"
        $csv = ',' . implode( ',', $ids ) . ',';
        update_post_meta( $post_id, 'fqj_assoc_posts_csv', $csv );
    }
}
add_action( 'save_post', 'fqj_save_meta', 10, 2 );

/**
 * Inject JSON-LD into <head> when viewing a singular post/page (or any public post type) if associated FAQs exist.
 */
function fqj_maybe_print_faq_jsonld() {
    if ( ! is_singular() ) return;

    global $post;
    if ( ! $post ) return;

    $current_id = intval( $post->ID );

    // Query FAQ items that contain this ID in the CSV meta
    $args = array(
        'post_type'      => 'faq_item',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'meta_query'     => array(
            array(
                'key'     => 'fqj_assoc_posts_csv',
                'value'   => ',' . $current_id . ',',
                'compare' => 'LIKE',
            ),
        ),
    );

    $faqs = get_posts( $args );
    if ( empty( $faqs ) ) return;

    $main_entities = array();
    foreach ( $faqs as $f ) {
        $question = get_the_title( $f );
        $answer  = wp_strip_all_tags( apply_filters( 'the_content', $f->post_content ) );
        if ( empty( $question ) || empty( $answer ) ) continue;

        $main_entities[] = array(
            '@type' => 'Question',
            'name'  => $question,
            'acceptedAnswer' => array(
                '@type' => 'Answer',
                'text'  => $answer,
            ),
        );
    }

    if ( empty( $main_entities ) ) return;

    $json = array(
        '@context' => 'https://schema.org',
        '@type'    => 'FAQSection',
        'mainEntity' => $main_entities,
    );

    // Output safely in head
    echo "\n<!-- FAQ JSON-LD injected by FAQ JSON-LD Manager plugin -->\n";
    echo '<script type="application/ld+json">' . wp_json_encode( $json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . '</script>' . "\n";
}
add_action( 'wp_head', 'fqj_maybe_print_faq_jsonld', 1 );
