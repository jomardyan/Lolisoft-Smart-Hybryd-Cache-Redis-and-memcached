<?php
/**
 * Uninstall Smart Hybrid Cache.
 *
 * Removes plugin options and optionally deletes the object-cache.php drop-in
 * if it was created by this plugin and the user opted in to cleanup on uninstall.
 *
 * @package SmartHybridCache
 * @since   1.0.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$smart_hybrid_cache_option_name = 'smart_hybrid_cache_options';
$smart_hybrid_cache_options     = is_multisite() ? get_blog_option( get_main_site_id(), $smart_hybrid_cache_option_name, array() ) : get_option( $smart_hybrid_cache_option_name, array() );
$smart_hybrid_cache_target      = trailingslashit( WP_CONTENT_DIR ) . 'object-cache.php';
$smart_hybrid_cache_remove      = is_array( $smart_hybrid_cache_options ) && ! empty( $smart_hybrid_cache_options['cleanup_dropin_uninstall'] );

if ( $smart_hybrid_cache_remove && ! is_link( $smart_hybrid_cache_target ) && file_exists( $smart_hybrid_cache_target ) && is_readable( $smart_hybrid_cache_target ) ) {
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	if ( WP_Filesystem( false, WP_CONTENT_DIR, true ) ) {
		global $wp_filesystem;
		$smart_hybrid_cache_header = $wp_filesystem->get_contents( $smart_hybrid_cache_target );
		$smart_hybrid_cache_header = false !== $smart_hybrid_cache_header ? substr( (string) $smart_hybrid_cache_header, 0, 2048 ) : false;
	} else {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local file header only.
		$smart_hybrid_cache_header = file_get_contents( $smart_hybrid_cache_target, false, null, 0, 2048 );
	}
	if ( false !== strpos( (string) $smart_hybrid_cache_header, 'Smart Hybrid Cache Drop-In' ) ) {
		wp_delete_file( $smart_hybrid_cache_target );
	}
}

if ( is_multisite() ) {
	$smart_hybrid_cache_offset = 0;
	do {
		$smart_hybrid_cache_site_ids = (array) get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $smart_hybrid_cache_offset,
			)
		);
		foreach ( $smart_hybrid_cache_site_ids as $smart_hybrid_cache_site_id ) {
			switch_to_blog( (int) $smart_hybrid_cache_site_id );
			delete_option( $smart_hybrid_cache_option_name );
			delete_option( 'smart_hybrid_cache_events' );
			delete_option( 'smart_hybrid_cache_status' );
			delete_option( 'smart_hybrid_cache_dropin_error' );
			restore_current_blog();
		}
		$smart_hybrid_cache_offset += count( $smart_hybrid_cache_site_ids );
	} while ( 100 === count( $smart_hybrid_cache_site_ids ) );
} else {
	delete_option( $smart_hybrid_cache_option_name );
		delete_option( 'smart_hybrid_cache_events' );
		delete_option( 'smart_hybrid_cache_status' );
		delete_option( 'smart_hybrid_cache_dropin_error' );
}
