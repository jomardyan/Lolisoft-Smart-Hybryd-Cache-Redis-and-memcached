<?php
/**
 * Cache administration. Connections are opened only for explicit diagnostics.
 *
 * @package SmartHybridCache
 */
defined( 'ABSPATH' ) || exit;

class Smart_Hybrid_Cache_Manager {
	private array $options;
	private string $active_engine   = 'none';
	private mixed $client           = null;
	private bool $connected         = false;
	private bool $flushed_on_change = false;

	public function __construct() {
		$this->options = Smart_Hybrid_Cache_Settings::get_options();
	}

	public function hooks(): void {
		add_action( 'clean_post_cache', array( $this, 'flush_on_post_change' ) );
		add_action( 'switch_theme', array( $this, 'flush_on_theme_switch' ) );
		add_action( 'upgrader_process_complete', array( $this, 'flush_on_plugin_update' ), 10, 2 );
	}

	public function connect(): bool {
		if ( $this->connected ) {
			return null !== $this->client;
		}
		$this->connected = true;
		$engine          = $this->options['engine'];
		if ( 'disabled' === $engine ) {
			return false;
		}
		$engines = 'auto' === $engine ? array( 'redis', 'memcached' ) : array( $engine );
		foreach ( $engines as $candidate ) {
			$client = 'redis' === $candidate ? new Smart_Hybrid_Cache_Redis_Client() : new Smart_Hybrid_Cache_Memcached_Client();
			if ( $client->connect( $this->options ) ) {
				$this->active_engine = $candidate;
				$this->client        = $client;
				return true;
			}
		}
		return false;
	}

	public function get_active_engine(): string {
		$cache = $GLOBALS['wp_object_cache'] ?? null;
		return is_object( $cache ) && method_exists( $cache, 'shc_engine' ) ? $cache->shc_engine() : 'none';
	}

	public function get_options(): array {
		return $this->options;
	}

	public function test( string $engine ): array {
		if ( ! in_array( $engine, array( 'redis', 'memcached', 'auto' ), true ) ) {
			return array(
				'ok'      => false,
				'engine'  => $engine,
				'message' => __( 'Select Redis or Memcached to test.', 'smart-hybrid-cache' ),
			);
		}
		if ( 'auto' === $engine ) {
			$result = $this->test( 'redis' );
			return $result['ok'] ? $result : $this->test( 'memcached' );
		}
		$client = 'redis' === $engine ? new Smart_Hybrid_Cache_Redis_Client() : new Smart_Hybrid_Cache_Memcached_Client();
		$ok     = false;
		$key    = 'shc:probe:' . wp_generate_uuid4();
		$value  = wp_generate_uuid4();
		try {
			$ok = $client->connect( $this->options ) && $client->set( $key, $value, 10 ) && $value === $client->get( $key ) && $client->delete( $key );
		} catch ( Throwable $e ) {
			$ok = false;
		}
		$message = $ok ? __( 'Connection and cache read/write/delete test successful.', 'smart-hybrid-cache' ) : ( $client->get_last_error() ?: __( 'Cache test failed. Check server access, credentials, and permissions.', 'smart-hybrid-cache' ) );
		update_option(
			'smart_hybrid_cache_status',
			array(
				'last_connected_engine' => $ok ? $engine : '',
				'last_error'            => $ok ? '' : $message,
			),
			false
		);
		Smart_Hybrid_Cache_Logger::log( $ok ? 'connection_test_success' : 'connection_test_failure', $message, array( 'engine' => $engine ) );
		return array(
			'ok'      => $ok,
			'engine'  => $engine,
			'message' => $message,
		);
	}

	public function flush_on_post_change(): void {
		if ( ! empty( $this->options['flush_on_post_update'] ) && ! $this->flushed_on_change ) {
			$this->flushed_on_change = true;
			// Run once after all database changes, so intermediate values cannot survive.
			add_action( 'shutdown', array( $this, 'flush_safe' ), PHP_INT_MAX );
		}
	}

	public function flush_on_theme_switch(): void {
		if ( ! empty( $this->options['flush_on_theme_switch'] ) ) {
			$this->flush_safe();
		}
	}

	public function flush_on_plugin_update( mixed $upgrader = null, array $hook_extra = array() ): void {
		if ( ! empty( $this->options['flush_on_plugin_update'] ) && 'plugin' === ( $hook_extra['type'] ?? '' ) ) {
			$this->flush_safe();
		}
	}

	public function flush_safe(): bool {
		$cache  = $GLOBALS['wp_object_cache'] ?? null;
		$engine = $this->get_active_engine();
		if ( 'none' === $engine || ! Smart_Hybrid_Cache_Dropin_Installer::is_owned() || ! apply_filters( 'smart_hybrid_cache_can_flush', true, $engine, $this->options['key_prefix'] ) ) {
			return false;
		}
		do_action( 'smart_hybrid_cache_before_flush', $engine );
		$result = $cache->flush();
		do_action( 'smart_hybrid_cache_after_flush', $engine, $result );
		Smart_Hybrid_Cache_Logger::log( $result ? 'flush_success' : 'flush_failure', $result ? __( 'Cache flushed.', 'smart-hybrid-cache' ) : __( 'Cache flush could not be completed.', 'smart-hybrid-cache' ), array( 'engine' => $engine ) );
		return $result;
	}

	public function stats(): array {
		if ( 'none' === $this->get_active_engine() ) {
			return array();
		}
		$this->options['engine'] = $this->get_active_engine();
		return $this->connect() ? $this->client->stats() : array();
	}
}
