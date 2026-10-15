<?php
/**
 * Minimal WP_CLI stand-in that records calls instead of printing them.
 *
 * @package FAQ_JSON_LD
 */

// phpcs:disable WordPress.Files.FileName, Generic.Files.OneObjectStructurePerFile

if ( ! defined( 'WP_CLI' ) ) {
	define( 'WP_CLI', true );
}

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * Records commands and messages.
	 */
	class WP_CLI {

		/**
		 * Registered commands, keyed by name.
		 *
		 * @var array
		 */
		public static $commands = array();

		/**
		 * Messages sent, as array( type, message ) pairs.
		 *
		 * @var array
		 */
		public static $messages = array();

		/**
		 * Register a command.
		 *
		 * @param string $name     Command name.
		 * @param mixed  $callable Handler.
		 */
		public static function add_command( $name, $callable ) {
			self::$commands[ $name ] = $callable;
		}

		/**
		 * Record a log line.
		 *
		 * @param string $message Message.
		 */
		public static function log( $message ) {
			self::$messages[] = array( 'log', $message );
		}

		/**
		 * Record a success line.
		 *
		 * @param string $message Message.
		 */
		public static function success( $message ) {
			self::$messages[] = array( 'success', $message );
		}

		/**
		 * Clear recorded messages.
		 */
		public static function reset() {
			self::$messages = array();
		}
	}
}
