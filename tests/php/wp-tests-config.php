<?php
/**
 * WordPress test suite config. Database settings come from the environment so the
 * same file works locally and in CI.
 *
 * @package Renderbit_FAQ_Structured_Data
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/vendor/roots/wordpress-no-content/' );

define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );

define( 'DB_NAME', getenv( 'WP_TESTS_DB_NAME' ) ? getenv( 'WP_TESTS_DB_NAME' ) : 'wordpress_test' );
define( 'DB_USER', getenv( 'WP_TESTS_DB_USER' ) ? getenv( 'WP_TESTS_DB_USER' ) : 'root' );
define( 'DB_PASSWORD', false !== getenv( 'WP_TESTS_DB_PASSWORD' ) ? getenv( 'WP_TESTS_DB_PASSWORD' ) : '' );
define( 'DB_HOST', getenv( 'WP_TESTS_DB_HOST' ) ? getenv( 'WP_TESTS_DB_HOST' ) : '127.0.0.1' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
