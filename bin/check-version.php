<?php
/**
 * Verifies the plugin version is consistent across every place it must match.
 *
 * Usage:
 *   php bin/check-version.php            # the 4 sources must agree with each other
 *   php bin/check-version.php 1.2.9      # ...and must all equal the given version (e.g. a git tag)
 *
 * Exits non-zero on any mismatch (or a missing changelog entry), so CI can gate on it.
 */

$root   = dirname( __DIR__ );
$plugin = $root . '/spamanvil';

$sources = array();

$php = file_get_contents( $plugin . '/spamanvil.php' );
if ( preg_match( '/^\s*\*\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)/mi', $php, $m ) ) {
	$sources['spamanvil.php header'] = $m[1];
}
if ( preg_match( "/define\(\s*'SPAMANVIL_VERSION',\s*'([0-9]+\.[0-9]+\.[0-9]+)'\s*\)/", $php, $m ) ) {
	$sources['SPAMANVIL_VERSION constant'] = $m[1];
}

$readme = file_get_contents( $plugin . '/readme.txt' );
if ( preg_match( '/^Stable tag:\s*([0-9]+\.[0-9]+\.[0-9]+)/mi', $readme, $m ) ) {
	$sources['readme.txt Stable tag'] = $m[1];
}

$po = file_get_contents( $plugin . '/languages/spamanvil-pt_BR.po' );
if ( preg_match( '/Project-Id-Version:\s*SpamAnvil\s+([0-9]+\.[0-9]+\.[0-9]+)/i', $po, $m ) ) {
	$sources['pt_BR.po Project-Id-Version'] = $m[1];
}

$expected_places = array( 'spamanvil.php header', 'SPAMANVIL_VERSION constant', 'readme.txt Stable tag', 'pt_BR.po Project-Id-Version' );
$errors          = array();

foreach ( $expected_places as $place ) {
	if ( ! isset( $sources[ $place ] ) ) {
		$errors[] = "Could not read version from: {$place}";
	}
}

$unique = array_unique( array_values( $sources ) );
if ( count( $unique ) > 1 ) {
	$errors[] = 'Versions disagree: ' . json_encode( $sources );
}

$version = reset( $sources );

// Optional: must match the version passed on the CLI (e.g. the git tag).
$expected = isset( $argv[1] ) ? ltrim( $argv[1], 'v' ) : null;
if ( null !== $expected && $version !== $expected ) {
	$errors[] = "Tag/CLI version '{$expected}' does not match plugin version '{$version}'.";
}

// The changelog must document this version.
if ( false === strpos( $readme, "= {$version} =" ) ) {
	$errors[] = "readme.txt is missing a changelog entry: = {$version} =";
}

if ( $errors ) {
	fwrite( STDERR, "Version check FAILED:\n - " . implode( "\n - ", $errors ) . "\n" );
	exit( 1 );
}

echo "Version check OK: {$version} (consistent across " . count( $sources ) . " sources + changelog).\n";
exit( 0 );
