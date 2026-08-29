<?php
/**
 * Build an installable plugin ZIP from the current source tree.
 */

$root        = dirname( __DIR__ );
$plugin_file = $root . '/just-modern-images.php';
$header      = file_get_contents( $plugin_file );

if ( ! preg_match( '/^ \* Version:\s*([^\s]+)$/m', $header, $matches ) ) {
	fwrite( STDERR, "Could not read the plugin version.\n" );
	exit( 1 );
}

if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "The PHP Zip extension is required.\n" );
	exit( 1 );
}

$version   = $matches[1];
$dist_dir  = $root . '/dist';
$zip_path  = $dist_dir . '/just-modern-images-' . $version . '.zip';
$base_path = 'just-modern-images/';
$files     = array(
	'just-modern-images.php',
	'uninstall.php',
	'readme.txt',
	'LICENSE',
);

foreach ( glob( $root . '/includes/*.php' ) as $include_file ) {
	$files[] = 'includes/' . basename( $include_file );
}

foreach ( glob( $root . '/assets/*' ) as $asset_file ) {
	if ( is_file( $asset_file ) ) {
		$files[] = 'assets/' . basename( $asset_file );
	}
}

if ( ! is_dir( $dist_dir ) && ! mkdir( $dist_dir, 0777, true ) ) {
	fwrite( STDERR, "Could not create the dist directory.\n" );
	exit( 1 );
}

if ( file_exists( $zip_path ) ) {
	unlink( $zip_path );
}

$zip = new ZipArchive();
if ( true !== $zip->open( $zip_path, ZipArchive::CREATE ) ) {
	fwrite( STDERR, "Could not create the plugin archive.\n" );
	exit( 1 );
}

foreach ( $files as $relative_path ) {
	if ( ! $zip->addFile( $root . '/' . $relative_path, $base_path . $relative_path ) ) {
		fwrite( STDERR, "Could not add {$relative_path} to the archive.\n" );
		$zip->close();
		exit( 1 );
	}
}

$zip->close();
fwrite( STDOUT, $zip_path . PHP_EOL );
