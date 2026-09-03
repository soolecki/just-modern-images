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

$version               = $matches[1];
$dist_dir              = $root . '/dist';
$zip_path              = $dist_dir . '/just-modern-images-' . $version . '.zip';
$base_path             = 'just-modern-images/';
$diagnostics_endpoint  = trim( (string) getenv( 'JMI_DIAGNOSTICS_ENDPOINT' ) );
$diagnostics_fleet_key = trim( (string) getenv( 'JMI_DIAGNOSTICS_FLEET_KEY' ) );
$files                 = array(
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
	if ( 'includes/class-jmi-diagnostics-reporter.php' === $relative_path && ( '' !== $diagnostics_endpoint || '' !== $diagnostics_fleet_key ) ) {
		if ( '' === $diagnostics_endpoint || ! preg_match( '/^[a-zA-Z0-9_-]{20,100}$/', $diagnostics_fleet_key ) ) {
			fwrite( STDERR, "JMI_DIAGNOSTICS_ENDPOINT and a 20-100 character JMI_DIAGNOSTICS_FLEET_KEY must be provided together.\n" );
			$zip->close();
			exit( 1 );
		}

		$scheme = parse_url( $diagnostics_endpoint, PHP_URL_SCHEME );
		$host   = parse_url( $diagnostics_endpoint, PHP_URL_HOST );
		if ( 'https' !== strtolower( (string) $scheme ) || ! is_string( $host ) || '' === $host ) {
			fwrite( STDERR, "JMI_DIAGNOSTICS_ENDPOINT must be a valid HTTPS URL.\n" );
			$zip->close();
			exit( 1 );
		}

		$contents = file_get_contents( $root . '/' . $relative_path );
		$compiled_endpoint = str_replace( array( '\\\\', "'" ), array( '\\\\\\\\', "\\'" ), $diagnostics_endpoint );
		$compiled_fleet_key = str_replace( array( "'" ), array( "\\'" ), $diagnostics_fleet_key );
		$contents           = preg_replace(
			"/const DEFAULT_ENDPOINT\\s*=\\s*'';/",
			"const DEFAULT_ENDPOINT = '" . $compiled_endpoint . "';",
			(string) $contents,
			1,
			$replacement_count
		);
		$contents = preg_replace(
			"/const DEFAULT_FLEET_KEY\\s*=\\s*'';/",
			"const DEFAULT_FLEET_KEY = '" . $compiled_fleet_key . "';",
			(string) $contents,
			1,
			$fleet_key_replacement_count
		);
		if ( 1 !== $replacement_count || 1 !== $fleet_key_replacement_count || ! $zip->addFromString( $base_path . $relative_path, $contents ) ) {
			fwrite( STDERR, "Could not compile the diagnostics endpoint into the archive.\n" );
			$zip->close();
			exit( 1 );
		}
		continue;
	}

	if ( ! $zip->addFile( $root . '/' . $relative_path, $base_path . $relative_path ) ) {
		fwrite( STDERR, "Could not add {$relative_path} to the archive.\n" );
		$zip->close();
		exit( 1 );
	}
}

$zip->close();
fwrite( STDOUT, $zip_path . PHP_EOL );
