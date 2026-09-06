<?php
/**
 * Memcached backend wrapper.
 *
 * @package SmartHybridCache
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Smart_Hybrid_Cache_Memcached_Client
 *
 * Connects to Memcached and provides get/set/delete/flush operations.
 *
 * @since 1.0.0
 */
class Smart_Hybrid_Cache_Memcached_Client {
	private ?Memcached $memcached = null;
	private string $last_error    = '';

	public function is_available(): bool {
		return class_exists( 'Memcached' );
	}

	public function connect( array $options ): bool {
		$this->last_error = '';
		$this->memcached  = null;
		if ( ! $this->is_available() ) {
			$this->last_error = __( 'Memcached PHP extension is not available.', 'smart-hybrid-cache' );
			return false;
		}

		try {
			$persistent_id = ! empty( $options['memcached_persistent'] ) ? 'shc_admin_' . hash( 'sha256', $options['memcached_host'] . ':' . $options['memcached_port'] ) : '';
			$memcached     = '' !== $persistent_id ? new Memcached( $persistent_id ) : new Memcached();
			$memcached->setOption( Memcached::OPT_CONNECT_TIMEOUT, 1000 );
			$memcached->setOption( Memcached::OPT_POLL_TIMEOUT, 1000 );
			$memcached->setOption( Memcached::OPT_RECV_TIMEOUT, 1000000 );
			$memcached->setOption( Memcached::OPT_SEND_TIMEOUT, 1000000 );
			$servers = $memcached->getServerList();
			if ( empty( $servers ) ) {
				$host = (string) $options['memcached_host'];
				$port = (int) $options['memcached_port'];
				$args = apply_filters( 'smart_hybrid_cache_connection_args', array( $host, $port ), 'memcached', $options );
				$args = is_array( $args ) && count( $args ) >= 2 ? array_values( $args ) : array( $host, $port );
				if ( ! $memcached->addServer( ...$args ) ) {
					$this->last_error = __( 'Unable to add Memcached server.', 'smart-hybrid-cache' );
					return false;
				}
			}

			$version = $memcached->getVersion();
			if ( empty( $version ) || in_array( '255.255.255', $version, true ) || in_array( false, $version, true ) ) {
				$this->last_error = __( 'Unable to connect to Memcached.', 'smart-hybrid-cache' );
				return false;
			}

			$this->memcached = $memcached;
			do_action( 'smart_hybrid_cache_engine_connected', 'memcached' );
			return true;
		} catch ( Throwable $e ) {
			$this->last_error = $this->safe_error( $e );
			do_action( 'smart_hybrid_cache_engine_failed', 'memcached', $this->last_error );
			return false;
		}
	}

	public function ping(): bool {
		try {
			$versions = $this->memcached instanceof Memcached ? $this->memcached->getVersion() : false;
			return ! empty( $versions ) && ! in_array( '255.255.255', $versions, true ) && ! in_array( false, $versions, true );
		} catch ( Throwable $e ) {
			$this->last_error = $this->safe_error( $e );
			return false; }
	}

	public function get( string $key ): mixed {
		return $this->memcached instanceof Memcached ? $this->memcached->get( $key ) : false;
	}

	public function get_result_code(): int {
		return $this->memcached instanceof Memcached ? $this->memcached->getResultCode() : 1;
	}

	public function set( string $key, mixed $value, int $ttl = 0 ): bool {
		return $this->memcached instanceof Memcached && $this->memcached->set( $key, $value, $ttl > 2592000 ? time() + $ttl : $ttl );
	}

	public function add( string $key, mixed $value, int $ttl = 0 ): bool {
		return $this->memcached instanceof Memcached && $this->memcached->add( $key, $value, $ttl > 2592000 ? time() + $ttl : $ttl );
	}

	public function replace( string $key, mixed $value, int $ttl = 0 ): bool {
		return $this->memcached instanceof Memcached && $this->memcached->replace( $key, $value, $ttl > 2592000 ? time() + $ttl : $ttl );
	}

	public function delete( string $key ): bool {
		return $this->memcached instanceof Memcached && $this->memcached->delete( $key );
	}

	public function incr( string $key, int $offset = 1 ): int|false {
		return $this->memcached instanceof Memcached ? $this->memcached->increment( $key, $offset ) : false;
	}

	public function decr( string $key, int $offset = 1 ): int|false {
		return $this->memcached instanceof Memcached ? $this->memcached->decrement( $key, $offset ) : false;
	}

	public function flush(): bool {
		$manager = Smart_Hybrid_Cache_Plugin::manager();
		return $manager && $manager->flush_safe();
	}

	public function stats(): array {
		try {
			return $this->memcached instanceof Memcached ? (array) $this->memcached->getStats() : array();
		} catch ( Throwable $e ) {
			return array(); }
	}

	public function get_last_error(): string {
		return $this->last_error;
	}

	private function safe_error( Throwable $e ): string {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Smart Hybrid Cache Memcached error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		return __( 'Memcached connection failed. Check host and port settings.', 'smart-hybrid-cache' );
	}
}
