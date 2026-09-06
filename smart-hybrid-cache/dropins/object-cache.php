<?php
/**
 * Smart Hybrid Cache Drop-In
 *
 * Standalone early-bootstrap object cache. Installed configuration is generated
 * locally by the plugin. Never call the Options API while starting this cache.
 * Signature: Smart Hybrid Cache Drop-In
 *
 * @package SmartHybridCache
 */

defined( 'ABSPATH' ) || exit;

define( 'SMART_HYBRID_CACHE_DROPIN_VERSION', '1.2.0' );

class WP_Object_Cache {
	private array $cache                 = array();
	private array $global_groups         = array();
	private array $non_persistent_groups = array( 'counts', 'plugins', 'themes', 'theme_json' );
	private array $options;
	private mixed $client  = null;
	private string $engine = 'none';
	private string $namespace;
	private ?string $generation = null;
	private int $blog_id        = 1;
	public int $cache_hits      = 0;
	public int $cache_misses    = 0;

	public function __construct() {
		// This marker is replaced only in the installed copy, never in the release.
		$this->options   = /* SHC_CONFIGURATION */ array();
		$this->options  += array(
			'engine'                   => 'disabled',
			'default_ttl'              => 3600,
			'key_prefix'               => 'shc_',
			'non_persistent_groups'    => '',
			'additional_global_groups' => '',
		);
		$this->blog_id   = (int) ( $GLOBALS['blog_id'] ?? 1 );
		$this->namespace = 'shc:v2:' . hash( 'sha256', ABSPATH . '|' . ( defined( 'WP_CACHE_KEY_SALT' ) ? WP_CACHE_KEY_SALT : '' ) . '|' . $this->options['key_prefix'] . '|' . ( $this->options['cache_generation'] ?? '' ) ) . ':';
		$this->add_global_groups( $this->groups( $this->options['additional_global_groups'] ) );
		$this->add_non_persistent_groups( $this->groups( $this->options['non_persistent_groups'] ) );
		$this->connect();
	}

	private function groups( mixed $value ): array {
		return is_array( $value ) ? $value : ( preg_split( '/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY ) ?: array() );
	}

	private function connect(): void {
		if ( defined( 'SMART_HYBRID_CACHE_DISABLED' ) && SMART_HYBRID_CACHE_DISABLED ) {
			return;
		}
		$options = $this->options;
		if ( ! empty( $options['plugin_file'] ) && ! is_file( $options['plugin_file'] ) ) {
			return;
		}
		$engine = $options['engine'];
		// Auto is resolved at installation. Never switch data stores on an outage.
		try {
			if ( 'redis' === $engine && class_exists( 'Redis' ) ) {
				$client = new Redis();
				$host   = ( ! empty( $options['redis_tls'] ) ? 'tls://' : '' ) . $options['redis_host'];
				$method = ! empty( $options['redis_persistent'] ) ? 'pconnect' : 'connect';
				$id     = 'shc_' . hash( 'sha256', $host . $options['redis_port'] . $options['redis_database'] . $options['redis_password'] );
				if ( ! $client->{$method}( $host, (int) $options['redis_port'], (float) $options['redis_timeout'], $id, 0, (float) $options['redis_timeout'] ) ) {
					return;
				}
				if ( '' !== $options['redis_password'] && ! $client->auth( $options['redis_password'] ) ) {
					return;
				}
				// Persistent sockets may previously have selected another database.
				if ( ! $client->select( (int) $options['redis_database'] ) ) {
					return;
				}
				$client->setOption( Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE );
				$client->setOption( Redis::OPT_PREFIX, '' );
				$this->client = $client;
				$this->engine = 'redis';
			} elseif ( 'memcached' === $engine && class_exists( 'Memcached' ) ) {
				$id     = 'shc_' . hash( 'sha256', $options['memcached_host'] . ':' . $options['memcached_port'] );
				$client = ! empty( $options['memcached_persistent'] ) ? new Memcached( $id ) : new Memcached();
				$client->setOption( Memcached::OPT_CONNECT_TIMEOUT, 1000 );
				$client->setOption( Memcached::OPT_POLL_TIMEOUT, 1000 );
				$client->setOption( Memcached::OPT_RECV_TIMEOUT, 1000000 );
				$client->setOption( Memcached::OPT_SEND_TIMEOUT, 1000000 );
				if ( empty( $client->getServerList() ) && ! $client->addServer( $options['memcached_host'], (int) $options['memcached_port'] ) ) {
					return;
				}
				$versions = $client->getVersion();
				if ( ! $versions || in_array( '255.255.255', $versions, true ) || in_array( false, $versions, true ) ) {
					return;
				}
				$this->client = $client;
				$this->engine = 'memcached';
			}
		} catch ( Throwable $e ) {
			$this->disconnect();
		}
	}

	private function disconnect(): void {
		$this->client = null;
		$this->engine = 'none';
	}

	public function shc_engine(): string {
		return $this->engine;
	}

	private function valid_key( mixed $key ): bool {
		return is_int( $key ) || ( is_string( $key ) && '' !== trim( $key ) );
	}

	private function group( mixed $group ): string {
		return empty( $group ) ? 'default' : (string) $group;
	}

	private function scope( string $group ): string {
		return ( in_array( $group, $this->global_groups, true ) ? 'global' : 'blog_' . $this->blog_id ) . ':' . $group;
	}

	private function persistent( string $group ): bool {
		return 'none' !== $this->engine && ! in_array( $group, $this->non_persistent_groups, true );
	}

	private function copy_value( mixed $value ): mixed {
		return is_object( $value ) ? clone $value : $value;
	}

	private function decode( mixed $value ): array|false {
		// Cached WordPress objects must retain their classes. Only trusted cache
		// servers may be configured. Invalid payloads are cache misses.
		$value = is_string( $value ) ? @unserialize( $value, array( 'allowed_classes' => true ) ) : false;
		return is_array( $value ) && array_key_exists( 'value', $value ) && isset( $value['expires'] ) && is_int( $value['expires'] ) ? $value : false;
	}

	private function live( mixed $entry ): bool {
		return is_array( $entry ) && ( 0 === $entry['expires'] || $entry['expires'] > time() );
	}

	private function backend_get( string $key ): mixed {
		$value = $this->client->get( $key );
		if ( 'memcached' === $this->engine && method_exists( $this->client, 'getResultCode' ) && ! in_array( $this->client->getResultCode(), array( Memcached::RES_SUCCESS, Memcached::RES_NOTFOUND ), true ) ) {
			throw new RuntimeException( 'Memcached read failed.' );
		}
		return $value;
	}

	private function generation(): string {
		if ( null !== $this->generation ) {
			return $this->generation;
		}
		$key   = $this->namespace . 'generation';
		$value = $this->backend_get( $key );
		if ( ! is_string( $value ) || ! preg_match( '/^[a-f0-9]{32}$/D', $value ) ) {
			$value = bin2hex( random_bytes( 16 ) );
			$added = 'redis' === $this->engine ? $this->client->set( $key, $value, array( 'nx' ) ) : $this->client->add( $key, $value );
			if ( ! $added ) {
				$value = $this->backend_get( $key );
			}
			if ( ! is_string( $value ) || ! preg_match( '/^[a-f0-9]{32}$/D', $value ) ) {
				throw new RuntimeException( 'Cache namespace is unavailable.' );
			}
		}
		$this->generation = $value;
		return $value;
	}

	private function persistent_key( mixed $key, string $group ): string {
		return $this->namespace . $this->generation() . ':' . hash( 'sha256', serialize( array( $this->scope( $group ), (string) $key ) ) );
	}

	private function expiration( array $entry ): int {
		$ttl = 0 === $entry['expires'] ? 0 : max( 1, $entry['expires'] - time() );
		return 'memcached' === $this->engine && $ttl > 2592000 ? $entry['expires'] : $ttl;
	}

	public function get( $key, $group = 'default', $force = false, &$found = null ) {
		$found = false;
		if ( ! $this->valid_key( $key ) ) {
			return false;
		}
		$group = $this->group( $group );
		$scope = $this->scope( $group );
		$entry = $this->cache[ $scope ][ $key ] ?? false;
		if ( ( ! $force || ! $this->persistent( $group ) ) && $this->live( $entry ) ) {
			$found = true;
		} elseif ( $this->persistent( $group ) ) {
			try {
				$entry = $this->decode( $this->backend_get( $this->persistent_key( $key, $group ) ) );
				$found = $this->live( $entry );
			} catch ( Throwable $e ) {
				$this->disconnect();
				$found = $this->live( $entry );
			}
		}
		if ( $found ) {
			$this->cache[ $scope ][ $key ] = $entry;
			++$this->cache_hits;
			return $this->copy_value( $entry['value'] );
		}
		unset( $this->cache[ $scope ][ $key ] );
		++$this->cache_misses;
		return false;
	}

	public function set( $key, $data, $group = 'default', $expire = 0, $mode = 'set' ): bool {
		if ( ! $this->valid_key( $key ) || ! in_array( $mode, array( 'set', 'add', 'replace' ), true ) ) {
			return false;
		}
		if ( 'add' === $mode && function_exists( 'wp_suspend_cache_addition' ) && wp_suspend_cache_addition() ) {
			return false;
		}
		$group = $this->group( $group );
		$scope = $this->scope( $group );
		$ttl   = (int) $expire > 0 ? (int) $expire : max( 0, (int) $this->options['default_ttl'] );
		$entry = array(
			'value'   => $this->copy_value( $data ),
			'expires' => $ttl ? time() + $ttl : 0,
		);
		if ( ! $this->persistent( $group ) ) {
			$exists = $this->live( $this->cache[ $scope ][ $key ] ?? false );
			if ( ( 'add' === $mode && $exists ) || ( 'replace' === $mode && ! $exists ) ) {
				return false;
			}
		} else {
			try {
				$pkey  = $this->persistent_key( $key, $group );
				$value = serialize( $entry );
				if ( 'redis' === $this->engine ) {
					$args = $ttl ? array( 'ex' => $ttl ) : array();
					if ( 'set' !== $mode ) {
						$args[] = 'add' === $mode ? 'nx' : 'xx';
					}
					$result = $this->client->set( $pkey, $value, $args );
				} else {
					$result = $this->client->{$mode}( $pkey, $value, $this->expiration( $entry ) );
				}
				if ( ! $result ) {
					unset( $this->cache[ $scope ][ $key ] );
					return false;
				}
			} catch ( Throwable $e ) {
				$this->disconnect();
				return $this->set( $key, $data, $group, $expire, $mode );
			}
		}
		$this->cache[ $scope ][ $key ] = $entry;
		return true;
	}

	public function add( $key, $data, $group = 'default', $expire = 0 ): bool {
		return $this->set( $key, $data, $group, $expire, 'add' );
	}

	public function replace( $key, $data, $group = 'default', $expire = 0 ): bool {
		return $this->set( $key, $data, $group, $expire, 'replace' );
	}

	public function delete( $key, $group = 'default', $deprecated = false ): bool {
		if ( ! $this->valid_key( $key ) ) {
			return false;
		}
		$group  = $this->group( $group );
		$scope  = $this->scope( $group );
		$exists = $this->live( $this->cache[ $scope ][ $key ] ?? false );
		unset( $this->cache[ $scope ][ $key ] );
		if ( $this->persistent( $group ) ) {
			try {
				$pkey = $this->persistent_key( $key, $group );
				return 'redis' === $this->engine ? (bool) $this->client->del( $pkey ) : $this->client->delete( $pkey );
			} catch ( Throwable $e ) {
				$this->disconnect();
			}
		}
		return $exists;
	}

	public function flush(): bool {
		$this->cache = array();
		if ( 'none' === $this->engine ) {
			return true;
		}
		try {
			$value = bin2hex( random_bytes( 16 ) );
			if ( ! $this->client->set( $this->namespace . 'generation', $value ) ) {
				return false;
			}
			$this->generation = $value;
			return true;
		} catch ( Throwable $e ) {
			$this->disconnect();
			return false;
		}
	}

	public function flush_runtime(): bool {
		$this->cache      = array();
		$this->generation = null;
		return true;
	}

	public function incr( $key, $offset = 1, $group = 'default' ) {
		return $this->change_counter( $key, (int) $offset, $group );
	}

	public function decr( $key, $offset = 1, $group = 'default' ) {
		return $this->change_counter( $key, - (int) $offset, $group );
	}

	private function change_counter( $key, int $offset, $group ) {
		if ( ! $this->valid_key( $key ) ) {
			return false;
		}
		$group = $this->group( $group );
		$scope = $this->scope( $group );
		if ( ! $this->persistent( $group ) ) {
			$entry = $this->cache[ $scope ][ $key ] ?? false;
			if ( ! $this->live( $entry ) ) {
				return false;
			}
			$entry['value']                = max( 0, ( is_numeric( $entry['value'] ) ? (int) $entry['value'] : 0 ) + $offset );
			$this->cache[ $scope ][ $key ] = $entry;
			return $entry['value'];
		}
		try {
			$pkey = $this->persistent_key( $key, $group );
			for ( $attempt = 0; $attempt < 10; ++$attempt ) {
				if ( 'redis' === $this->engine ) {
					$this->client->watch( $pkey );
					$entry = $this->decode( $this->client->get( $pkey ) );
				} else {
					$result = $this->client->get( $pkey, null, Memcached::GET_EXTENDED );
					$entry  = $this->decode( is_array( $result ) ? $result['value'] : false );
				}
				if ( ! $this->live( $entry ) ) {
					if ( 'redis' === $this->engine ) {
						$this->client->unwatch();
					}
					unset( $this->cache[ $scope ][ $key ] );
					return false;
				}
				$entry['value'] = max( 0, ( is_numeric( $entry['value'] ) ? (int) $entry['value'] : 0 ) + $offset );
				$ttl            = $this->expiration( $entry );
				if ( 'redis' === $this->engine ) {
					$this->client->multi();
					$this->client->set( $pkey, serialize( $entry ), $ttl ? array( 'ex' => $ttl ) : array() );
					$result = $this->client->exec();
					$ok     = is_array( $result ) && ! empty( $result[0] );
				} else {
					$ok = $this->client->cas( $result['cas'], $pkey, serialize( $entry ), $ttl );
				}
				if ( $ok ) {
					$this->cache[ $scope ][ $key ] = $entry;
					return $entry['value'];
				}
			}
		} catch ( Throwable $e ) {
			// A failed transaction must never leave a persistent socket in MULTI.
			if ( 'redis' === $this->engine ) {
				try {
					$this->client->discard();
					$this->client->unwatch(); } catch ( Throwable $ignored ) {
					/* Connection already closed. */ }
			}
			$this->disconnect();
		}
		unset( $this->cache[ $scope ][ $key ] );
		return false;
	}

	public function get_multiple( $keys, $group = 'default', $force = false ): array {
		$result = array();
		foreach ( (array) $keys as $key ) {
			if ( $this->valid_key( $key ) ) {
				$result[ $key ] = $this->get( $key, $group, $force );
			}
		}
		return $result;
	}

	public function add_multiple( array $data, $group = 'default', $expire = 0 ): array {
		return $this->write_multiple( $data, $group, $expire, 'add' );
	}

	public function set_multiple( array $data, $group = 'default', $expire = 0 ): array {
		return $this->write_multiple( $data, $group, $expire, 'set' );
	}

	private function write_multiple( array $data, $group, $expire, string $mode ): array {
		$result = array();
		foreach ( $data as $key => $value ) {
			$result[ $key ] = $this->set( $key, $value, $group, $expire, $mode );
		}
		return $result;
	}

	public function delete_multiple( array $keys, $group = 'default' ): array {
		$result = array();
		foreach ( $keys as $key ) {
			if ( $this->valid_key( $key ) ) {
				$result[ $key ] = $this->delete( $key, $group );
			}
		}
		return $result;
	}

	public function switch_to_blog( $blog_id ): void {
		$this->blog_id = (int) $blog_id;
	}

	public function add_global_groups( $groups ): void {
		$this->global_groups = array_values( array_unique( array_merge( $this->global_groups, (array) $groups ) ) );
	}

	public function add_non_persistent_groups( $groups ): void {
		$this->non_persistent_groups = array_values( array_unique( array_merge( $this->non_persistent_groups, (array) $groups ) ) );
	}

	public function stats(): void {
		echo esc_html( 'Smart Hybrid Cache hits ' . $this->cache_hits . ', misses ' . $this->cache_misses . ', engine ' . $this->engine );
	}
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress requires these exact Object Cache API function names in a drop-in.
function wp_cache_init() {
	$GLOBALS['wp_object_cache'] = new WP_Object_Cache();
	if ( function_exists( 'wp_using_ext_object_cache' ) ) {
		// This flag identifies the loaded implementation, not backend health.
		// Clearing it makes multisite's second bootstrap include core cache.php
		// and redeclare this drop-in's functions. Health uses shc_engine instead.
		wp_using_ext_object_cache( true );
	}
}
function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
	return $GLOBALS['wp_object_cache']->add( $key, $data, $group, $expire ); }
function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
	return $GLOBALS['wp_object_cache']->set( $key, $data, $group, $expire ); }
function wp_cache_replace( $key, $data, $group = '', $expire = 0 ) {
	return $GLOBALS['wp_object_cache']->replace( $key, $data, $group, $expire ); }
function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
	return $GLOBALS['wp_object_cache']->get( $key, $group, $force, $found ); }
function wp_cache_delete( $key, $group = '', $deprecated = false ) {
	return $GLOBALS['wp_object_cache']->delete( $key, $group, $deprecated ); }
function wp_cache_flush() {
	return $GLOBALS['wp_object_cache']->flush(); }
function wp_cache_incr( $key, $offset = 1, $group = '' ) {
	return $GLOBALS['wp_object_cache']->incr( $key, $offset, $group ); }
function wp_cache_decr( $key, $offset = 1, $group = '' ) {
	return $GLOBALS['wp_object_cache']->decr( $key, $offset, $group ); }
function wp_cache_get_multiple( $keys, $group = '', $force = false ) {
	return $GLOBALS['wp_object_cache']->get_multiple( $keys, $group, $force ); }
function wp_cache_add_multiple( array $data, $group = '', $expire = 0 ) {
	return $GLOBALS['wp_object_cache']->add_multiple( $data, $group, $expire ); }
function wp_cache_set_multiple( array $data, $group = '', $expire = 0 ) {
	return $GLOBALS['wp_object_cache']->set_multiple( $data, $group, $expire ); }
function wp_cache_delete_multiple( array $keys, $group = '' ) {
	return $GLOBALS['wp_object_cache']->delete_multiple( $keys, $group ); }
function wp_cache_add_global_groups( $groups ) {
	$GLOBALS['wp_object_cache']->add_global_groups( $groups ); }
function wp_cache_add_non_persistent_groups( $groups ) {
	$GLOBALS['wp_object_cache']->add_non_persistent_groups( $groups ); }
function wp_cache_switch_to_blog( $blog_id ) {
	$GLOBALS['wp_object_cache']->switch_to_blog( $blog_id ); }
function wp_cache_close() {
	return true; }
function wp_cache_stats() {
	$GLOBALS['wp_object_cache']->stats(); }
function wp_cache_flush_runtime() {
	return $GLOBALS['wp_object_cache']->flush_runtime(); }
function wp_cache_supports( $feature ) {
	return in_array( $feature, array( 'add_multiple', 'set_multiple', 'get_multiple', 'delete_multiple', 'flush_runtime' ), true ); }
