<?php
/**
 * Build the standalone private-test diagnostics receiver.
 */

$root       = dirname( __DIR__ );
$source     = $root . '/tools/diagnostics-endpoint';
$version    = '0.1.0';
$dist_dir   = $root . '/dist';
$zip_path   = $dist_dir . '/jmi-diagnostics-endpoint-' . $version . '.zip';
$base_path  = 'jmi-diagnostics-endpoint/';
$files      = array(
	'config.example.php',
	'README.md',
	'public/index.php',
	'storage/.gitkeep',
);

if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "The PHP Zip extension is required.\n" );
	exit( 1 );
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
	fwrite( STDERR, "Could not create the receiver archive.\n" );
	exit( 1 );
}

foreach ( $files as $relative_path ) {
	if ( ! $zip->addFile( $source . '/' . $relative_path, $base_path . $relative_path ) ) {
		fwrite( STDERR, "Could not add {$relative_path} to the archive.\n" );
		$zip->close();
		exit( 1 );
	}
}

$zip->close();
fwrite( STDOUT, $zip_path . PHP_EOL );
