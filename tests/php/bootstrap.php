<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite with the plugin active.
 *
 * @package Renderbit_FAQ_Structured_Data
 */

// phpcs:disable WordPress.Files.FileName, Generic.Files.OneObjectStructurePerFile, PSR1.Files.SideEffects

$root = dirname( __DIR__, 2 );

require_once $root . '/vendor/autoload.php';
require_once $root . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

// Load local test settings (e.g. WP_TESTS_DB_*) from .env.testing if present. Variables already set
// in the environment, such as those from CI, take precedence. The unsafe repository also calls
// putenv(), which wp-tests-config.php needs because it reads settings with getenv().
Dotenv\Dotenv::createUnsafeImmutable( $root, '.env.testing' )->safeLoad();

if ( ! getenv( 'WP_PHPUNIT__TESTS_CONFIG' ) ) {
	putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
}

$tests_dir = getenv( 'WP_PHPUNIT__DIR' ) ? getenv( 'WP_PHPUNIT__DIR' ) : $root . '/vendor/wp-phpunit/wp-phpunit';

require_once $tests_dir . '/includes/functions.php';

// Stand-in for WP-CLI so the plugin registers its commands and the tests can call them.
require_once __DIR__ . '/stubs/class-wp-cli.php';

tests_add_filter(
	'muplugins_loaded',
	function () use ( $root ) {
		require $root . '/renderbit-faq-structured-data.php';
	}
);

// Create the mapping table and default options the same way activation does (for the main site on multisite;
// the multisite tests set up other sites themselves).
tests_add_filter(
	'init',
	function () {
		rfsd_activate();
	},
	0
);

require $tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/class-rfsd-testcase.php';
require_once __DIR__ . '/class-rfsd-multisite-testcase.php';
