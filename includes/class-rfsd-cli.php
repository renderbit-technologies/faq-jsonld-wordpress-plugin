<?php
/**
 * WP-CLI commands.
 *
 * @package Renderbit_FAQ_Structured_Data
 */

namespace RFSD;

use WP_CLI;

// phpcs:disable PSR1.Files.SideEffects

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * WP-CLI commands for FAQ plugin
	 */
	class Rfsd_Cli {

		/**
		 * Purge all cached FAQ output.
		 *
		 * @param array $args       Positional arguments.
		 * @param array $assoc_args Associative arguments.
		 */
		public function purge_transients( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
			$generation = rfsd_purge_all_faq_transients();
			WP_CLI::success( "Purged FAQ cache (generation {$generation})." );
		}

		/**
		 * Process invalidation queue via CLI.
		 * Usage: wp rfsd process-queue --limit=1000
		 *
		 * @param array $args       Positional arguments.
		 * @param array $assoc_args Associative arguments.
		 */
		public function process_queue( $args, $assoc_args ) {
			$limit = isset( $assoc_args['limit'] ) ? intval( $assoc_args['limit'] ) : null;
			WP_CLI::log( 'Processing invalidate queue...' );
			$processed = rfsd_process_invalidation_queue_now( $limit );
			WP_CLI::success( "Processed {$processed} invalidation items." );
		}

		/**
		 * Show queue length
		 *
		 * @param array $args       Positional arguments.
		 * @param array $assoc_args Associative arguments.
		 */
		public function queue_info( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
			$len = rfsd_queue_length();
			WP_CLI::success( "Queue length: {$len}" );
		}
	}

	WP_CLI::add_command( 'rfsd', 'RFSD\Rfsd_Cli' );
}
