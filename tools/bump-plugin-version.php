<?php
/**
 * Update plugin metadata before packaging a release artifact.
 *
 * @package SmartHybridCache
 */

if ( $argc < 2 ) {
	fwrite( STDERR, "Usage: php tools/bump-plugin-version.php <version>\n" );
	exit( 1 );
}

$version = preg_replace( '/^v/', '', trim( (string) $argv[1] ) );
if ( ! preg_match( '/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\z/', $version ) ) {
	fwrite( STDERR, "Invalid version '{$version}'. Expected a stable version like 1.2.3.\n" );
	exit( 1 );
}

$root         = dirname( __DIR__ );
$plugin_file  = $root . '/smart-hybrid-cache/smart-hybrid-cache.php';
$readme_file  = $root . '/smart-hybrid-cache/readme.txt';
$current      = detect_current_version( $plugin_file );

if ( version_compare( normalize_version_for_compare( $version ), normalize_version_for_compare( $current ), '<' ) ) {
	fwrite( STDERR, "Refusing to downgrade version from {$current} to {$version}.\n" );
	exit( 1 );
}

// Validate every source and release note before changing any version field.
$dropin_file = $root . '/smart-hybrid-cache/dropins/object-cache.php';
foreach ( array( $plugin_file, $readme_file, $dropin_file ) as $file ) {
	if ( ! is_file( $file ) || ! is_readable( $file ) || ! is_writable( $file ) ) {
		fwrite( STDERR, "Release source must be readable and writable: {$file}\n" );
		exit( 1 );
	}
}
$readme = file_get_contents( $readme_file );
foreach ( array( 'Changelog', 'Upgrade Notice' ) as $section ) {
	$pattern = '/^== ' . preg_quote( $section, '/' ) . ' ==\R(.*?)(?=^== |\z)/ms';
	if ( ! preg_match( $pattern, $readme, $section_match )
		|| ! preg_match( '/^= ' . preg_quote( $version, '/' ) . ' =\R(.*?)(?=^= |\z)/ms', $section_match[1], $entry )
		|| '' === trim( $entry[1] ) || preg_match( '/pending|TODO|TBD/i', $entry[1] ) ) {
		fwrite( STDERR, "Add completed {$section} notes for {$version} before changing the version.\n" );
		exit( 1 );
	}
}
$checks = array(
	$plugin_file => array( '/^ \* Version:\s*\S+/m', "/define\\( 'SMART_HYBRID_CACHE_VERSION', '[^']+' \\);/" ),
	$dropin_file => array( "/define\\( 'SMART_HYBRID_CACHE_DROPIN_VERSION', '[^']+' \\);/" ),
	$readme_file => array( '/^Stable tag:\s*\S+/m' ),
);
foreach ( $checks as $file => $patterns ) {
	foreach ( $patterns as $pattern ) {
		if ( 1 !== preg_match_all( $pattern, file_get_contents( $file ) ) ) {
			fwrite( STDERR, "Missing or duplicate version field in {$file}\n" );
			exit( 1 );
		}
	}
}

replace_in_file(
	$plugin_file,
	array(
		'/^ \* Version:\s*.+$/m' => ' * Version:     ' . $version,
		"/define\( 'SMART_HYBRID_CACHE_VERSION', '[^']+' \);/" => "define( 'SMART_HYBRID_CACHE_VERSION', '" . $version . "' );",
	)
);

replace_in_file(
 $root . '/smart-hybrid-cache/dropins/object-cache.php',
 array( "/define\\( 'SMART_HYBRID_CACHE_DROPIN_VERSION', '[^']+' \\);/" => "define( 'SMART_HYBRID_CACHE_DROPIN_VERSION', '" . $version . "' );" )
);
update_readme_metadata( $readme_file, $version );

echo "Bumped Smart Hybrid Cache metadata from {$current} to {$version}\n";

/**
 * Detect the current plugin version from the main plugin file.
 *
 * @param string $plugin_file Absolute plugin file path.
 * @return string
 */
function detect_current_version( string $plugin_file ): string {
	$contents = file_get_contents( $plugin_file );
	if ( false === $contents ) {
		fwrite( STDERR, "Unable to read {$plugin_file}\n" );
		exit( 1 );
	}

	if ( ! preg_match( '/^ \* Version:\s*(.+)$/m', $contents, $matches ) ) {
		fwrite( STDERR, "Unable to detect current version in {$plugin_file}\n" );
		exit( 1 );
	}

	return trim( $matches[1] );
}

/**
 * Normalize a semantic version for version_compare().
 *
 * @param string $version Version string.
 * @return string
 */
function normalize_version_for_compare( string $version ): string {
	return preg_replace( '/\+.+$/', '', $version ) ?: $version;
}

/**
 * Update WordPress readme metadata, changelog, and upgrade notice.
 *
 * @param string $file    Absolute readme path.
 * @param string $version Target version.
 */
function update_readme_metadata( string $file, string $version ): void {
	$contents = file_get_contents( $file );
	if ( false === $contents ) {
		fwrite( STDERR, "Unable to read {$file}\n" );
		exit( 1 );
	}

	$updated = preg_replace( '/^Stable tag:\s*.+$/m', 'Stable tag: ' . $version, $contents, 1, $stable_count );
	if ( null === $updated || 1 !== $stable_count ) {
		fwrite( STDERR, "Unable to update Stable tag in {$file}\n" );
		exit( 1 );
	}

	$contents = $updated;

	if ( false === file_put_contents( $file, $contents ) ) {
		fwrite( STDERR, "Unable to write {$file}\n" );
		exit( 1 );
	}
}

/**
 * Replace expected patterns in a file.
 *
 * @param string $file         Absolute file path.
 * @param array  $replacements Map of regex patterns to replacement strings.
 */
function replace_in_file( string $file, array $replacements ): void {
	$contents = file_get_contents( $file );
	if ( false === $contents ) {
		fwrite( STDERR, "Unable to read {$file}\n" );
		exit( 1 );
	}

	foreach ( $replacements as $pattern => $replacement ) {
		$updated = preg_replace( $pattern, $replacement, $contents, 1, $count );
		if ( null === $updated || 1 !== $count ) {
			fwrite( STDERR, "Unable to update {$file} with pattern {$pattern}\n" );
			exit( 1 );
		}
		$contents = $updated;
	}

	if ( false === file_put_contents( $file, $contents ) ) {
		fwrite( STDERR, "Unable to write {$file}\n" );
		exit( 1 );
	}
}
