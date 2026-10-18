<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite with the plugin active.
 *
 * @package FAQ_JSON_LD
 */

// phpcs:disable WordPress.Files.FileName, Generic.Files.OneObjectStructurePerFile, PSR1.Files.SideEffects

$root = dirname( __DIR__, 2 );

require_once $root . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

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
		require $root . '/faq-jsonld.php';
	}
);

// Create the mapping table and default options the same way activation does (for the main site on multisite;
// the multisite tests set up other sites themselves).
tests_add_filter(
	'init',
	function () {
		fqj_activate();
	},
	0
);

require $tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/class-fqj-testcase.php';
require_once __DIR__ . '/class-fqj-multisite-testcase.php';
