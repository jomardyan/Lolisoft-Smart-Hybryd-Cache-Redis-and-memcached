<?php
/** Verify failed release preparation leaves all version fields unchanged. */
$root = dirname( __DIR__ );
$tmp = sys_get_temp_dir() . '/shc-release-' . bin2hex( random_bytes( 8 ) );
$files = array( 'tools/bump-plugin-version.php', 'smart-hybrid-cache/smart-hybrid-cache.php', 'smart-hybrid-cache/dropins/object-cache.php', 'smart-hybrid-cache/readme.txt' );
$count = 0;
function release_assert( bool $condition, string $message ): void {
	global $count;
	++$count;
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function release_run( string $version ): int {
	global $tmp;
	$process = proc_open( array( PHP_BINARY, $tmp . '/tools/bump-plugin-version.php', $version ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Cannot start release tool.' ); }
	fclose( $pipes[0] );
	stream_get_contents( $pipes[1] ); fclose( $pipes[1] );
	stream_get_contents( $pipes[2] ); fclose( $pipes[2] );
	return proc_close( $process );
}
function release_hashes(): array {
	global $tmp, $files;
	return array_map( static fn( $file ) => hash_file( 'sha256', $tmp . '/' . $file ), $files );
}
try {
	foreach ( $files as $file ) {
		$path = $tmp . '/' . $file;
		if ( ! is_dir( dirname( $path ) ) ) { mkdir( dirname( $path ), 0700, true ); }
		copy( $root . '/' . $file, $path );
	}
	$before = release_hashes();
	release_assert( 1 === release_run( '999.0.0' ), 'Unprepared release must fail.' );
	release_assert( $before === release_hashes(), 'Unprepared release must not modify source.' );
	foreach ( array( 'vv999.0.0', '0999.0.0', '999.0.0-beta', "999.0.0\ninvalid" ) as $version ) {
		release_assert( 1 === release_run( $version ), 'Malformed release version must fail.' );
		release_assert( $before === release_hashes(), 'Malformed version must not modify source.' );
	}
	$readme_file = $tmp . '/smart-hybrid-cache/readme.txt';
	$readme = file_get_contents( $readme_file );
	$readme = str_replace( '== Changelog ==', "== Changelog ==\n\n= 999.0.0 =\n* Verified release regression fixture.", $readme );
	$readme = str_replace( '== Upgrade Notice ==', "== Upgrade Notice ==\n\n= 999.0.0 =\nUpdate the installed drop-in.", $readme );
	file_put_contents( $readme_file, $readme );
	$dropin_file = $tmp . '/smart-hybrid-cache/dropins/object-cache.php';
	$dropin = file_get_contents( $dropin_file );
	file_put_contents( $dropin_file, str_replace( 'SMART_HYBRID_CACHE_DROPIN_VERSION', 'MISSING_VERSION_CONSTANT', $dropin ) );
	$before = release_hashes();
	release_assert( 1 === release_run( '999.0.0' ), 'Missing drop-in metadata must fail.' );
	release_assert( $before === release_hashes(), 'Metadata failure must not partially bump versions.' );
	file_put_contents( $dropin_file, $dropin );
	release_assert( 0 === release_run( 'v999.0.0' ), 'Prepared release must succeed.' );
	foreach ( array_slice( $files, 1 ) as $file ) {
		release_assert( false !== strpos( file_get_contents( $tmp . '/' . $file ), '999.0.0' ), 'Version must be updated in ' . $file );
	}
	release_assert( 0 === release_run( '999.0.0' ), 'Prepared release must be idempotent.' );
	echo 'Passed ' . $count . " release assertions\n";
} finally {
	if ( is_dir( $tmp ) ) {
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $tmp, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $iterator as $file ) { $file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() ); }
		rmdir( $tmp );
	}
}
