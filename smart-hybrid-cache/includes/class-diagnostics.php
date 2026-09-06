<?php
/**
 * Diagnostics snapshot builder.
 *
 * @package SmartHybridCache
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Smart_Hybrid_Cache_Diagnostics
 *
 * Builds a diagnostics snapshot for support and debugging.
 *
 * @since 1.1.0
 */
class Smart_Hybrid_Cache_Diagnostics {
	public static function snapshot(): array {
		global $wp_version;
		$manager = Smart_Hybrid_Cache_Plugin::manager() ?: new Smart_Hybrid_Cache_Manager();
		$status  = Smart_Hybrid_Cache_Health_Check::get_status( $manager );
		$options = Smart_Hybrid_Cache_Settings::get_options();

		// Redact secret-like fields before exporting.
		$options_safe = $options;
		if ( ! empty( $options_safe['redis_password'] ) ) {
			$options_safe['redis_password'] = '***redacted***';
		}
		unset( $options_safe['log_events'] );

		return array(
			'generated_at' => current_time( 'mysql' ),
			'plugin'       => array(
				'version'        => SMART_HYBRID_CACHE_VERSION,
				'dropin_version' => self::dropin_version(),
			),
			'environment'  => array(
				'wp_version'          => isset( $wp_version ) ? (string) $wp_version : 'unknown',
				'php_version'         => PHP_VERSION,
				'multisite'           => is_multisite(),
				'redis_extension'     => phpversion( 'redis' ) ?: null,
				'memcached_extension' => phpversion( 'memcached' ) ?: null,
			),
			'status'       => array(
				'selected_engine'        => $status['selected_engine'],
				'active_engine'          => $status['active_engine'],
				'connection_status'      => $status['connection_status'],
				'object_cache_available' => $status['object_cache_available'] ?? false,
				'dropin'                 => $status['dropin'],
				'advanced_cache_exists'  => $status['advanced_cache_exists'],
				'last_error'             => $status['last_error'],
				'monitoring'             => $status['monitoring'],
			),
			'options'      => $options_safe,
		);
	}

	public static function dropin_version(): string {
		$target = trailingslashit( WP_CONTENT_DIR ) . 'object-cache.php';
		if ( ! file_exists( $target ) || ! is_readable( $target ) ) {
			return 'not_installed';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local file only.
		$contents = file_get_contents( $target );
		if ( false === $contents ) {
			return 'unknown';
		}
		if ( preg_match( '/SMART_HYBRID_CACHE_DROPIN_VERSION[^\\d]+([\\d.]+)/', $contents, $m ) ) {
			return $m[1];
		}
		return 'unknown';
	}
}
