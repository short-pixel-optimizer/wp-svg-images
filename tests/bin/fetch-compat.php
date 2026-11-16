<?php
/**
 * Downloads the third-party plugins and libraries of the `compat` suite at
 * the versions pinned in tests/compat/plugins.json.
 *
 * Usage: php fetch-compat.php <manifest> <cache-dir>
 *
 * Runs inside the `setup` container, the only one with network access.
 * Plugins come from wordpress.org and end up in <cache-dir>/plugins/<slug>/,
 * libraries from the address in the manifest in
 * <cache-dir>/libraries/<name>/. A marker in <cache-dir>/versions/ records
 * what is installed, so a version change in the manifest replaces the copy
 * and an unchanged one costs nothing.
 */

$manifest_path = isset( $argv[1] ) ? $argv[1] : '';
$cache         = isset( $argv[2] ) ? rtrim( $argv[2], '/' ) : '';
$manifest      = json_decode( (string) @file_get_contents( $manifest_path ), true );
if ( ! is_array( $manifest ) || '' === $cache ) {
	fwrite( STDERR, "fetch-compat.php: cannot read the manifest $manifest_path\n" );
	exit( 1 );
}

/**
 * Stops the script with a message.
 *
 * @param string $message
 */
function fail( $message ) {
	fwrite( STDERR, "fetch-compat.php: $message\n" );
	exit( 1 );
}

/**
 * Runs a shell command and stops the script when it fails.
 *
 * @param string $command
 */
function run( $command ) {
	passthru( $command, $code );
	if ( 0 !== $code ) {
		fail( "command failed: $command" );
	}
}

/**
 * Installs one zip archive whose single top-level folder becomes $target.
 *
 * @param string $kind    `plugins` or `libraries`.
 * @param string $name    Folder name below <cache-dir>/<kind>/.
 * @param string $version Pinned version, recorded in the marker.
 * @param string $url     Archive address.
 * @param string $cache   Cache directory.
 */
function install( $kind, $name, $version, $url, $cache ) {
	if ( ! preg_match( '/^[a-z0-9-]+$/', $name ) || ! preg_match( '/^[A-Za-z0-9.-]+$/', $version ) ) {
		fail( "invalid name or version: $name $version" );
	}
	$target = "$cache/$kind/$name";
	$marker = "$cache/versions/$kind-$name";
	if ( is_dir( $target ) && is_file( $marker ) && trim( file_get_contents( $marker ) ) === $version ) {
		return;
	}

	fwrite( STDERR, "Downloading $name $version ...\n" );
	$work = "$cache/work";
	run( 'rm -rf ' . escapeshellarg( $work ) . ' ' . escapeshellarg( $target ) . ' ' . escapeshellarg( $marker ) );
	run( 'mkdir -p ' . escapeshellarg( $work ) . ' ' . escapeshellarg( "$cache/$kind" ) . ' ' . escapeshellarg( "$cache/versions" ) );
	run( 'curl -fsSL -o ' . escapeshellarg( "$work/archive.zip" ) . ' ' . escapeshellarg( $url ) );
	run( 'unzip -q ' . escapeshellarg( "$work/archive.zip" ) . ' -d ' . escapeshellarg( "$work/content" ) );
	$folders = glob( "$work/content/*", GLOB_ONLYDIR );
	if ( 1 !== count( $folders ) ) {
		fail( "$name $version: expected one top-level folder in the archive" );
	}
	rename( $folders[0], $target );
	run( 'rm -rf ' . escapeshellarg( $work ) );
	file_put_contents( $marker, $version . "\n" );
}

$plugins   = isset( $manifest['plugins'] ) ? $manifest['plugins'] : array();
$libraries = isset( $manifest['libraries'] ) ? $manifest['libraries'] : array();

foreach ( $plugins as $slug => $version ) {
	install( 'plugins', $slug, $version, "https://downloads.wordpress.org/plugin/$slug.$version.zip", $cache );
}
foreach ( $libraries as $name => $library ) {
	install( 'libraries', $name, $library['version'], $library['url'], $cache );
}

// Remove copies that are no longer in the manifest. The plugin under test is
// mounted over its own empty folder and is never a download.
foreach ( array( 'plugins' => $plugins, 'libraries' => $libraries ) as $kind => $wanted ) {
	foreach ( glob( "$cache/$kind/*", GLOB_ONLYDIR ) as $folder ) {
		$name = basename( $folder );
		if ( 'wp-svg-images' !== $name && ! isset( $wanted[ $name ] ) ) {
			run( 'rm -rf ' . escapeshellarg( $folder ) . ' ' . escapeshellarg( "$cache/versions/$kind-$name" ) );
		}
	}
}
