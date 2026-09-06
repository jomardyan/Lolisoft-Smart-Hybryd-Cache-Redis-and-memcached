<?php
/**
 * Install a standalone, configured drop-in using an atomic local file swap.
 *
 * @package SmartHybridCache
 */
defined( 'ABSPATH' ) || exit;

class Smart_Hybrid_Cache_Dropin_Installer {
	public static function target(): string {
		return trailingslashit( WP_CONTENT_DIR ) . 'object-cache.php';
	}

	public static function source(): string {
		return SMART_HYBRID_CACHE_PATH . 'dropins/object-cache.php';
	}

	private static function init_filesystem() {
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! WP_Filesystem( false, WP_CONTENT_DIR, true ) || ! $wp_filesystem instanceof WP_Filesystem_Direct ) {
			return false;
		}
		return $wp_filesystem;
	}

	public static function status(): array {
		$target  = self::target();
		$exists  = is_file( $target );
		$owned   = $exists && self::is_owned();
		$cache   = $GLOBALS['wp_object_cache'] ?? null;
		$active  = $owned ? ( is_object( $cache ) && method_exists( $cache, 'shc_engine' ) && 'none' !== $cache->shc_engine() ) : wp_using_ext_object_cache();
		$label   = __( 'Not installed.', 'smart-hybrid-cache' );
		$message = __( 'Install the drop-in to enable persistent object caching.', 'smart-hybrid-cache' );
		if ( $owned ) {
			$label   = __( 'Created by Smart Hybrid Cache.', 'smart-hybrid-cache' );
			$message = $active ? __( 'Smart Hybrid Cache persistent caching is active.', 'smart-hybrid-cache' ) : __( 'The drop-in is installed but persistent caching is inactive. Check the engine settings and server connection.', 'smart-hybrid-cache' );
		} elseif ( $exists ) {
			$label   = __( 'Created by another plugin or manually.', 'smart-hybrid-cache' );
			$message = __( 'Another object cache drop-in is installed. Explicit confirmation is required to replace it.', 'smart-hybrid-cache' );
		}
		$fs = self::init_filesystem();
		return array(
			'exists'      => $exists,
			'owned'       => $owned,
			'active'      => $active,
			'available'   => $active,
			'writable'    => $fs && $fs->is_writable( WP_CONTENT_DIR ) && ! is_link( $target ),
			'path'        => $target,
			'advanced'    => is_file( WP_CONTENT_DIR . '/advanced-cache.php' ),
			'owner_label' => $label,
			'message'     => $message,
		);
	}

	public static function is_owned(): bool {
		$target = self::target();
		if ( ! is_file( $target ) || ! is_readable( $target ) || is_link( $target ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read a fixed local header without filesystem credentials.
		$header = file_get_contents( $target, false, null, 0, 2048 );
		return is_string( $header ) && false !== strpos( $header, 'Signature: ' . SMART_HYBRID_CACHE_SIGNATURE );
	}

	public static function install( bool $force = false, ?array $options = null ): WP_Error|bool {
		$target = self::target();
		if ( is_link( $target ) ) {
			return new WP_Error( 'symlink_dropin', __( 'Remove the object-cache.php symbolic link manually before installing.', 'smart-hybrid-cache' ) );
		}
		if ( file_exists( $target ) && ! self::is_owned() && ! $force ) {
			return new WP_Error( 'existing_dropin', __( 'Another object-cache.php is installed. Confirm replacement to continue.', 'smart-hybrid-cache' ) );
		}
		$fs = self::init_filesystem();
		if ( ! $fs || ! $fs->is_writable( WP_CONTENT_DIR ) ) {
			return new WP_Error( 'filesystem_error', __( 'The local wp-content directory must be writable by PHP to install or update the cache drop-in.', 'smart-hybrid-cache' ) );
		}
		$source = $fs->get_contents( self::source() );
		if ( ! is_string( $source ) || 1 !== substr_count( $source, '/* SHC_CONFIGURATION */ array()' ) ) {
			return new WP_Error( 'missing_source', __( 'The drop-in source is missing or invalid. Reinstall the plugin files.', 'smart-hybrid-cache' ) );
		}
		$options = $options ?? Smart_Hybrid_Cache_Settings::get_options();
		$keys    = array( 'engine', 'redis_host', 'redis_port', 'redis_password', 'redis_database', 'redis_timeout', 'redis_tls', 'redis_persistent', 'memcached_host', 'memcached_port', 'memcached_persistent', 'default_ttl', 'key_prefix', 'non_persistent_groups', 'additional_global_groups' );
		$config  = array_intersect_key( $options, array_flip( $keys ) );
		if ( 'auto' === $config['engine'] ) {
			// Pick once. An outage must not expose stale data in a secondary store.
			$redis            = new Smart_Hybrid_Cache_Redis_Client();
			$config['engine'] = $redis->connect( $options ) ? 'redis' : 'memcached';
		}
		$config['plugin_file'] = SMART_HYBRID_CACHE_PATH . 'smart-hybrid-cache.php';
		// Every settings change starts a fresh namespace, including disable/enable.
		$config['cache_generation'] = wp_generate_uuid4();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Generate escaped standalone PHP configuration, not debug output.
		$contents = str_replace( '/* SHC_CONFIGURATION */ array()', var_export( $config, true ), $source );
		$tmp      = trailingslashit( WP_CONTENT_DIR ) . 'shc-install-' . wp_generate_uuid4() . '.php';
		if ( ! $fs->put_contents( $tmp, $contents, 0600 ) || $fs->get_contents( $tmp ) !== $contents ) {
			$fs->delete( $tmp );
			return new WP_Error( 'write_failed', __( 'Unable to write the configured drop-in. The existing cache file was retained.', 'smart-hybrid-cache' ) );
		}
		// A same-directory rename prevents requests from loading a partial PHP file.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- WP_Filesystem_Direct::move deletes the destination before renaming.
		if ( ! rename( $tmp, $target ) ) {
			$fs->delete( $tmp );
			return new WP_Error( 'replace_failed', __( 'Unable to replace the drop-in. The existing cache file was retained.', 'smart-hybrid-cache' ) );
		}
		if ( function_exists( 'opcache_invalidate' ) ) {
			opcache_invalidate( $target, true );
		}
		return true;
	}

	public static function remove( bool $force = false ): WP_Error|bool {
		$target = self::target();
		if ( ! file_exists( $target ) && ! is_link( $target ) ) {
			return true;
		}
		if ( is_link( $target ) || ( ! self::is_owned() && ! $force ) ) {
			return new WP_Error( 'not_owned', __( 'This object-cache.php is not owned by Smart Hybrid Cache.', 'smart-hybrid-cache' ) );
		}
		$fs = self::init_filesystem();
		if ( ! $fs || ! $fs->delete( $target ) ) {
			return new WP_Error( 'remove_failed', __( 'Unable to remove object-cache.php. Check directory permissions.', 'smart-hybrid-cache' ) );
		}
		if ( function_exists( 'opcache_invalidate' ) ) {
			opcache_invalidate( $target, true );
		}
		return true;
	}
}
