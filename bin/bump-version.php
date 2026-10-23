<?php
/**
 * Bump the plugin version everywhere it is recorded.
 *
 * Usage: php bin/bump-version.php major|minor|patch
 *
 * Updates the composer.json "version", the Version header and RFSD_VERSION in renderbit-faq-structured-data.php, and the
 * Stable tag in readme.txt. deploy-wporg.yml refuses to publish when these differ from the release tag.
 * Run through `composer bump-major|bump-minor|bump-patch`, which also refresh the composer.lock hash.
 *
 * @package FAQ_JSONLD
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- Standalone CLI script; WordPress is not loaded.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Prints to the terminal, not a page.

$rfsd_part = isset( $argv[1] ) ? $argv[1] : '';
if ( ! in_array( $rfsd_part, array( 'major', 'minor', 'patch' ), true ) ) {
	fwrite( STDERR, "Usage: php bin/bump-version.php major|minor|patch\n" );
	exit( 1 );
}

$rfsd_root = dirname( __DIR__ );

$rfsd_composer = json_decode( file_get_contents( $rfsd_root . '/composer.json' ), true );
$rfsd_old      = isset( $rfsd_composer['version'] ) ? $rfsd_composer['version'] : '';
if ( ! preg_match( '/^(\d+)\.(\d+)\.(\d+)$/', $rfsd_old, $rfsd_m ) ) {
	fwrite( STDERR, "composer.json version \"$rfsd_old\" is not MAJOR.MINOR.PATCH.\n" );
	exit( 1 );
}

list( , $rfsd_major, $rfsd_minor, $rfsd_patch ) = array_map( 'intval', $rfsd_m );
if ( 'major' === $rfsd_part ) {
	$rfsd_new = ( $rfsd_major + 1 ) . '.0.0';
} elseif ( 'minor' === $rfsd_part ) {
	$rfsd_new = $rfsd_major . '.' . ( $rfsd_minor + 1 ) . '.0';
} else {
	$rfsd_new = $rfsd_major . '.' . $rfsd_minor . '.' . ( $rfsd_patch + 1 );
}

// Each pattern must match exactly once, and in the form deploy-wporg.yml reads it back.
$rfsd_files = array(
	'composer.json'                     => array( '/^(\s*"version":\s*")[^"]*(")/m' ),
	'renderbit-faq-structured-data.php' => array(
		'/^( \* Version: *).*$/m',
		"/^(define\\( 'RFSD_VERSION', ').*(' \\);)$/m",
	),
	'readme.txt'                        => array( '/^(Stable tag: *).*$/m' ),
);

// Check every file before writing any, so a failure leaves the versions consistent.
$rfsd_updated = array();
foreach ( $rfsd_files as $rfsd_file => $rfsd_patterns ) {
	$rfsd_contents = file_get_contents( $rfsd_root . '/' . $rfsd_file );
	foreach ( $rfsd_patterns as $rfsd_pattern ) {
		$rfsd_contents = preg_replace_callback(
			$rfsd_pattern,
			function ( $m ) use ( $rfsd_new ) {
				return $m[1] . $rfsd_new . ( isset( $m[2] ) ? $m[2] : '' );
			},
			$rfsd_contents,
			-1,
			$rfsd_count
		);
		if ( 1 !== $rfsd_count ) {
			fwrite( STDERR, "Expected one match for $rfsd_pattern in $rfsd_file, found $rfsd_count. Nothing was changed.\n" );
			exit( 1 );
		}
	}
	$rfsd_updated[ $rfsd_file ] = $rfsd_contents;
}

foreach ( $rfsd_updated as $rfsd_file => $rfsd_contents ) {
	file_put_contents( $rfsd_root . '/' . $rfsd_file, $rfsd_contents );
}

echo "Bumped $rfsd_old -> $rfsd_new in " . implode( ', ', array_keys( $rfsd_updated ) ) . ".\n";
echo "Add a readme.txt changelog entry for $rfsd_new.\n";
