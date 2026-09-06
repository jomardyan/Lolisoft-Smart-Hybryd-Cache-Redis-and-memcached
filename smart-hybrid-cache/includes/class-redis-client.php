<?php
/**
 * Redis backend wrapper.
 *
 * @package SmartHybridCache
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Smart_Hybrid_Cache_Redis_Client
 *
 * Connects to Redis and provides get/set/delete/flush operations.
 *
 * @since 1.0.0
 */
class Smart_Hybrid_Cache_Redis_Client {
	private ?Redis $redis      = null;
	private string $last_error = '';

	public function is_available(): bool {
		return class_exists( 'Redis' );
	}

	public function connect( array $options ): bool {
		$this->last_error = '';
		$this->redis      = null;
		if ( ! $this->is_available() ) {
			$this->last_error = __( 'Redis PHP extension is not available.', 'smart-hybrid-cache' );
			return false;
		}

		try {
			$redis   = new Redis();
			$scheme  = ! empty( $options['redis_tls'] ) ? 'tls://' : '';
			$host    = $scheme . (string) $options['redis_host'];
			$port    = (int) $options['redis_port'];
			$timeout = (float) $options['redis_timeout'];
			$args    = apply_filters( 'smart_hybrid_cache_connection_args', array( $host, $port, $timeout, 'shc_admin_' . hash( 'sha256', $host . $port . $options['redis_database'] . $options['redis_password'] ), 0, $timeout ), 'redis', $options );
			$args    = is_array( $args ) && count( $args ) >= 3 ? array_values( $args ) : array( $host, $port, $timeout );
			$method  = ! empty( $options['redis_persistent'] ) && method_exists( $redis, 'pconnect' ) ? 'pconnect' : 'connect';

			if ( ! $redis->{$method}( ...$args ) ) {
				$this->last_error = __( 'Unable to connect to Redis.', 'smart-hybrid-cache' );
				return false;
			}

			if ( '' !== (string) $options['redis_password'] && ! $redis->auth( (string) $options['redis_password'] ) ) {
				$this->last_error = __( 'Redis authentication failed.', 'smart-hybrid-cache' );
				return false;
			}

			if ( ! $redis->select( (int) $options['redis_database'] ) ) {
				$this->last_error = __( 'Redis database selection failed.', 'smart-hybrid-cache' );
				return false;
			}

			$redis->setOption( Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE );
			$redis->setOption( Redis::OPT_PREFIX, '' );
			$this->redis = $redis;
			do_action( 'smart_hybrid_cache_engine_connected', 'redis' );
			return true;
		} catch ( Throwable $e ) {
			$this->last_error = $this->safe_error( $e );
			do_action( 'smart_hybrid_cache_engine_failed', 'redis', $this->last_error );
			return false;
		}
	}

	public function ping(): bool {
		try {
			return $this->redis instanceof Redis && false !== $this->redis->ping();
		} catch ( Throwable $e ) {
			$this->last_error = $this->safe_error( $e );
			return false;
		}
	}

	public function get( string $key ): mixed {
		return $this->redis instanceof Redis ? $this->redis->get( $key ) : false;
	}

	public function set( string $key, mixed $value, int $ttl = 0 ): bool {
		if ( ! $this->redis instanceof Redis ) {
			return false;
		}
		return $ttl > 0 ? (bool) $this->redis->setex( $key, $ttl, $value ) : (bool) $this->redis->set( $key, $value );
	}

	public function add( string $key, mixed $value, int $ttl = 0 ): bool {
		if ( ! $this->redis instanceof Redis ) {
			return false;
		}
		$args = array( 'nx' );
		if ( $ttl > 0 ) {
			$args['ex'] = $ttl;
		}
		return (bool) $this->redis->set( $key, $value, $args );
	}

	public function replace( string $key, mixed $value, int $ttl = 0 ): bool {
		if ( ! $this->redis instanceof Redis ) {
			return false;
		}
		$args = array( 'xx' );
		if ( $ttl > 0 ) {
			$args['ex'] = $ttl;
		}
		return (bool) $this->redis->set( $key, $value, $args );
	}

	public function delete( string $key ): bool {
		return $this->redis instanceof Redis && (bool) $this->redis->del( $key );
	}

	public function incr( string $key, int $offset = 1 ): int|false {
		return $this->redis instanceof Redis ? $this->redis->incrBy( $key, $offset ) : false;
	}

	public function decr( string $key, int $offset = 1 ): int|false {
		return $this->redis instanceof Redis ? $this->redis->decrBy( $key, $offset ) : false;
	}

	/** Legacy flush entry point. Only the active owned drop-in may invalidate data. */
	public function flush_prefix( string $prefix ): bool {
		$manager = Smart_Hybrid_Cache_Plugin::manager();
		return $manager && $prefix === $manager->get_options()['key_prefix'] && $manager->flush_safe();
	}

	public function stats(): array {
		try {
			return $this->redis instanceof Redis ? (array) $this->redis->info() : array();
		} catch ( Throwable $e ) {
			return array();
		}
	}

	public function get_last_error(): string {
		return $this->last_error;
	}

	private function safe_error( Throwable $e ): string {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Smart Hybrid Cache Redis error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		return __( 'Redis connection failed. Check host, port, credentials, and TLS settings.', 'smart-hybrid-cache' );
	}
}
