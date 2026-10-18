<?php
/**
 * Bump the plugin version everywhere it is recorded.
 *
 * Usage: php bin/bump-version.php major|minor|patch
 *
 * Updates the composer.json "version", the Version header and FQJ_VERSION in faq-jsonld.php, and the
 * Stable tag in readme.txt. deploy-wporg.yml refuses to publish when these differ from the release tag.
 * Run through `composer bump-major|bump-minor|bump-patch`, which also refresh the composer.lock hash.
 *
 * @package FAQ_JSONLD
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- Standalone CLI script; WordPress is not loaded.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Prints to the terminal, not a page.

$fqj_part = isset( $argv[1] ) ? $argv[1] : '';
if ( ! in_array( $fqj_part, array( 'major', 'minor', 'patch' ), true ) ) {
	fwrite( STDERR, "Usage: php bin/bump-version.php major|minor|patch\n" );
	exit( 1 );
}

$fqj_root = dirname( __DIR__ );

$fqj_composer = json_decode( file_get_contents( $fqj_root . '/composer.json' ), true );
$fqj_old      = isset( $fqj_composer['version'] ) ? $fqj_composer['version'] : '';
if ( ! preg_match( '/^(\d+)\.(\d+)\.(\d+)$/', $fqj_old, $fqj_m ) ) {
	fwrite( STDERR, "composer.json version \"$fqj_old\" is not MAJOR.MINOR.PATCH.\n" );
	exit( 1 );
}

list( , $fqj_major, $fqj_minor, $fqj_patch ) = array_map( 'intval', $fqj_m );
if ( 'major' === $fqj_part ) {
	$fqj_new = ( $fqj_major + 1 ) . '.0.0';
} elseif ( 'minor' === $fqj_part ) {
	$fqj_new = $fqj_major . '.' . ( $fqj_minor + 1 ) . '.0';
} else {
	$fqj_new = $fqj_major . '.' . $fqj_minor . '.' . ( $fqj_patch + 1 );
}

// Each pattern must match exactly once, and in the form deploy-wporg.yml reads it back.
$fqj_files = array(
	'composer.json'  => array( '/^(\s*"version":\s*")[^"]*(")/m' ),
	'faq-jsonld.php' => array(
		'/^( \* Version: *).*$/m',
		"/^(define\\( 'FQJ_VERSION', ').*(' \\);)$/m",
	),
	'readme.txt'     => array( '/^(Stable tag: *).*$/m' ),
);

// Check every file before writing any, so a failure leaves the versions consistent.
$fqj_updated = array();
foreach ( $fqj_files as $fqj_file => $fqj_patterns ) {
	$fqj_contents = file_get_contents( $fqj_root . '/' . $fqj_file );
	foreach ( $fqj_patterns as $fqj_pattern ) {
		$fqj_contents = preg_replace_callback(
			$fqj_pattern,
			function ( $m ) use ( $fqj_new ) {
				return $m[1] . $fqj_new . ( isset( $m[2] ) ? $m[2] : '' );
			},
			$fqj_contents,
			-1,
			$fqj_count
		);
		if ( 1 !== $fqj_count ) {
			fwrite( STDERR, "Expected one match for $fqj_pattern in $fqj_file, found $fqj_count. Nothing was changed.\n" );
			exit( 1 );
		}
	}
	$fqj_updated[ $fqj_file ] = $fqj_contents;
}

foreach ( $fqj_updated as $fqj_file => $fqj_contents ) {
	file_put_contents( $fqj_root . '/' . $fqj_file, $fqj_contents );
}

echo "Bumped $fqj_old -> $fqj_new in " . implode( ', ', array_keys( $fqj_updated ) ) . ".\n";
echo "Add a readme.txt changelog entry for $fqj_new.\n";
