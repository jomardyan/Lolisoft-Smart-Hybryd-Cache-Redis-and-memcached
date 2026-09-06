<?php
/**
 * Runtime status collection.
 *
 * @package SmartHybridCache
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Smart_Hybrid_Cache_Health_Check
 *
 * Collects runtime cache status and monitoring metrics.
 *
 * @since 1.0.0
 */
class Smart_Hybrid_Cache_Health_Check {
	public static function get_status( ?Smart_Hybrid_Cache_Manager $manager = null ): array {
		$options = Smart_Hybrid_Cache_Settings::get_options();
		$manager = $manager ?: new Smart_Hybrid_Cache_Manager();
		$dropin  = Smart_Hybrid_Cache_Dropin_Installer::status();
		$redis   = new Smart_Hybrid_Cache_Redis_Client();
		$mem     = new Smart_Hybrid_Cache_Memcached_Client();
		$stats   = $manager->stats();

		return array(
			'selected_engine'        => $options['engine'],
			'active_engine'          => $manager->get_active_engine(),
			'redis_available'        => $redis->is_available(),
			'memcached_available'    => $mem->is_available(),
			'dropin'                 => $dropin,
			'object_cache_available' => ! empty( $dropin['available'] ),
			'connection_status'      => 'none' !== $manager->get_active_engine() ? __( 'Connected', 'smart-hybrid-cache' ) : __( 'Not connected', 'smart-hybrid-cache' ),
			'last_error'             => $options['last_error'],
			'cache_prefix'           => $options['key_prefix'],
			'current_ttl'            => (int) $options['default_ttl'],
			'multisite'              => is_multisite(),
			'advanced_cache_exists'  => $dropin['advanced'],
			'stats'                  => $stats,
			'monitoring'             => self::monitoring_summary( $stats, $manager->get_active_engine(), $options ),
			'log_events'             => is_array( $options['log_events'] ?? null ) ? array_reverse( $options['log_events'] ) : array(),
		);
	}

	private static function monitoring_summary( array $stats, string $engine, array $options ): array {
		$summary = array(
			'status'       => 'none' !== $engine ? __( 'Online', 'smart-hybrid-cache' ) : __( 'Offline', 'smart-hybrid-cache' ),
			'engine'       => $engine,
			'logging'      => ! empty( $options['enable_logging'] ) ? __( 'Enabled', 'smart-hybrid-cache' ) : __( 'Disabled', 'smart-hybrid-cache' ),
			'memory'       => __( 'Unavailable', 'smart-hybrid-cache' ),
			'uptime'       => __( 'Unavailable', 'smart-hybrid-cache' ),
			'connections'  => __( 'Unavailable', 'smart-hybrid-cache' ),
			'key_count'    => __( 'Unavailable', 'smart-hybrid-cache' ),
			'hit_rate'     => __( 'Unavailable', 'smart-hybrid-cache' ),
			'raw_counters' => array(),
		);

		if ( 'redis' === $engine && ! empty( $stats ) ) {
			$hits     = (int) ( $stats['keyspace_hits'] ?? 0 );
			$misses   = (int) ( $stats['keyspace_misses'] ?? 0 );
			$total    = $hits + $misses;
			$db_key   = 'db' . (int) $options['redis_database'];
			$db_stats = self::redis_database_stats( $stats[ $db_key ] ?? array() );
			$summary  = array_merge(
				$summary,
				array(
					'memory'       => (string) ( $stats['used_memory_human'] ?? __( 'Unavailable', 'smart-hybrid-cache' ) ),
					'uptime'       => self::format_seconds( (int) ( $stats['uptime_in_seconds'] ?? 0 ) ),
					'connections'  => (string) ( $stats['connected_clients'] ?? __( 'Unavailable', 'smart-hybrid-cache' ) ),
					'key_count'    => (string) ( $db_stats['keys'] ?? __( 'Unavailable', 'smart-hybrid-cache' ) ),
					'hit_rate'     => $total > 0 ? round( ( $hits / $total ) * 100, 2 ) . '%' : __( 'Unavailable', 'smart-hybrid-cache' ),
					'raw_counters' => array(
						'hits'   => $hits,
						'misses' => $misses,
					),
				)
			);
		}

		if ( 'memcached' === $engine && ! empty( $stats ) ) {
			$server_stats = reset( $stats );
			if ( is_array( $server_stats ) ) {
				$hits    = (int) ( $server_stats['get_hits'] ?? 0 );
				$misses  = (int) ( $server_stats['get_misses'] ?? 0 );
				$total   = $hits + $misses;
				$summary = array_merge(
					$summary,
					array(
						'memory'       => isset( $server_stats['bytes'] ) ? size_format( (int) $server_stats['bytes'] ) : __( 'Unavailable', 'smart-hybrid-cache' ),
						'uptime'       => self::format_seconds( (int) ( $server_stats['uptime'] ?? 0 ) ),
						'connections'  => (string) ( $server_stats['curr_connections'] ?? __( 'Unavailable', 'smart-hybrid-cache' ) ),
						'key_count'    => (string) ( $server_stats['curr_items'] ?? __( 'Unavailable', 'smart-hybrid-cache' ) ),
						'hit_rate'     => $total > 0 ? round( ( $hits / $total ) * 100, 2 ) . '%' : __( 'Unavailable', 'smart-hybrid-cache' ),
						'raw_counters' => array(
							'hits'   => $hits,
							'misses' => $misses,
						),
					)
				);
			}
		}

		return $summary;
	}

	private static function redis_database_stats( mixed $stats ): array {
		if ( is_array( $stats ) ) {
			return $stats;
		}
		if ( ! is_string( $stats ) ) {
			return array();
		}
		$parsed = array();
		foreach ( explode( ',', $stats ) as $part ) {
			$pair = explode( '=', $part, 2 );
			if ( 2 === count( $pair ) ) {
				$parsed[ $pair[0] ] = $pair[1];
			}
		}
		return $parsed;
	}

	private static function format_seconds( int $seconds ): string {
		if ( $seconds <= 0 ) {
			return __( 'Unavailable', 'smart-hybrid-cache' );
		}
		$days    = intdiv( $seconds, DAY_IN_SECONDS );
		$hours   = intdiv( $seconds % DAY_IN_SECONDS, HOUR_IN_SECONDS );
		$minutes = intdiv( $seconds % HOUR_IN_SECONDS, MINUTE_IN_SECONDS );
		if ( $days > 0 ) {
			return sprintf( '%1$dd %2$dh', $days, $hours );
		}
		if ( $hours > 0 ) {
			return sprintf( '%1$dh %2$dm', $hours, $minutes );
		}
		return sprintf( '%dm', max( 1, $minutes ) );
	}
}
