<?php
/**
 * Settings storage and sanitization.
 *
 * @package SmartHybridCache
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Smart_Hybrid_Cache_Settings
 *
 * Manages plugin option storage, defaults, and sanitization.
 *
 * @since 1.0.0
 */
class Smart_Hybrid_Cache_Settings {
	public const ENGINES = array( 'auto', 'redis', 'memcached', 'disabled' );

	public static function defaults(): array {
		$site_url = function_exists( 'network_site_url' ) ? network_site_url() : home_url();
		$defaults = array(
			'engine'                    => 'auto',
			'redis_host'                => '127.0.0.1',
			'redis_port'                => 6379,
			'redis_password'            => '',
			'redis_database'            => 0,
			'redis_timeout'             => 1.0,
			'redis_tls'                 => false,
			'redis_persistent'          => true,
			'memcached_host'            => '127.0.0.1',
			'memcached_port'            => 11211,
			'memcached_persistent'      => false,
			'default_ttl'               => 3600,
			'key_prefix'                => 'shc_' . substr( md5( (string) $site_url ), 0, 12 ) . '_',
			'enable_dropin'             => false,
			'flush_on_post_update'      => false,
			'flush_on_theme_switch'     => true,
			'flush_on_plugin_update'    => true,
			'cleanup_dropin_uninstall'  => true,
			'cleanup_dropin_deactivate' => true,
			'enable_logging'            => true,
			'non_persistent_groups'     => '',
			'additional_global_groups'  => '',
			'log_events'                => array(),
			'last_error'                => '',
			'last_connected_engine'     => '',
		);

		return apply_filters( 'smart_hybrid_cache_default_options', $defaults );
	}

	public static function get_options(): array {
		$options = self::get_shared_option( SMART_HYBRID_CACHE_OPTION, array() );
		if ( ! is_array( $options ) ) {
			$options = array();
		}

		$options = wp_parse_args( $options, self::defaults() );
		// Clear intent survives repeated sanitization but never a future edit.
		unset( $options['clear_redis_password'] );
		$events                = self::get_shared_option( 'smart_hybrid_cache_events', $options['log_events'] );
		$options['log_events'] = is_array( $events ) ? $events : array();
		$state                 = self::get_shared_option( 'smart_hybrid_cache_status', array() );
		if ( is_array( $state ) ) {
			$options['last_error']            = (string) ( $state['last_error'] ?? $options['last_error'] );
			$options['last_connected_engine'] = (string) ( $state['last_connected_engine'] ?? $options['last_connected_engine'] );
		}
		$options['engine']      = apply_filters( 'smart_hybrid_cache_selected_engine', $options['engine'], $options );
		$options['key_prefix']  = apply_filters( 'smart_hybrid_cache_key_prefix', $options['key_prefix'], $options );
		$options['default_ttl'] = (int) apply_filters( 'smart_hybrid_cache_default_ttl', $options['default_ttl'], $options );

		return $options;
	}

	/** All network sites use the main site's configuration and diagnostic state. */
	public static function get_shared_option( string $name, mixed $default = false ): mixed {
		return is_multisite() && get_current_blog_id() !== get_main_site_id()
			? get_blog_option( get_main_site_id(), $name, $default )
			: get_option( $name, $default );
	}

	public static function update_shared_option( string $name, mixed $value ): bool {
		$switched = is_multisite() && get_current_blog_id() !== get_main_site_id();
		if ( $switched ) {
			switch_to_blog( get_main_site_id() );
		}
		try {
			return update_option( $name, $value, false );
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	public static function delete_shared_option( string $name ): bool {
		return is_multisite() && get_current_blog_id() !== get_main_site_id()
			? delete_blog_option( get_main_site_id(), $name )
			: delete_option( $name );
	}

	public static function ensure_defaults(): void {
		if ( false === self::get_shared_option( SMART_HYBRID_CACHE_OPTION, false ) ) {
			self::update_shared_option( SMART_HYBRID_CACHE_OPTION, self::defaults() );
		}
	}

	public static function update_options( array $options ): bool {
		$options = wp_parse_args( $options, self::get_options() );
		return self::update_shared_option( SMART_HYBRID_CACHE_OPTION, self::sanitize( $options ) );
	}

	public static function sanitize( mixed $input ): array {
		if ( ! is_array( $input ) ) {
			return self::get_options();
		}
		foreach ( $input as $key => $value ) {
			if ( is_array( $value ) && in_array( $key, array( 'non_persistent_groups', 'additional_global_groups' ), true ) ) {
				$input[ $key ] = array_filter( $value, 'is_scalar' );
			} elseif ( ! is_scalar( $value ) && ! is_null( $value ) ) {
				unset( $input[ $key ] );
			}
		}
		$current = self::get_options();
		$output  = array();

		$engine               = sanitize_key( (string) ( $input['engine'] ?? $current['engine'] ) );
		$output['engine']     = in_array( $engine, self::ENGINES, true ) ? $engine : 'auto';
		$output['redis_host'] = self::sanitize_host( (string) ( $input['redis_host'] ?? $current['redis_host'] ), $current['redis_host'] );
		$output['redis_port'] = self::sanitize_port( $input['redis_port'] ?? $current['redis_port'], 6379 );
		// Settings API already unslashes input. Password bytes must not be sanitized.
		$output['redis_password'] = (string) $current['redis_password'];
		if ( isset( $input['redis_password'] ) && '' !== (string) $input['redis_password'] ) {
			$output['redis_password'] = (string) $input['redis_password'];
		}
		if ( ! empty( $input['clear_redis_password'] ) ) {
			$output['redis_password'] = '';
			// Preserve clear intent if the Settings API sanitizes the value a second time.
			$output['clear_redis_password'] = true;
		}
		$output['redis_database']            = max( 0, min( 255, (int) ( $input['redis_database'] ?? $current['redis_database'] ) ) );
		$output['redis_timeout']             = max( 0.1, min( 10, (float) ( $input['redis_timeout'] ?? $current['redis_timeout'] ) ) );
		$output['redis_tls']                 = self::sanitize_bool( $input['redis_tls'] ?? false );
		$output['redis_persistent']          = self::sanitize_bool( $input['redis_persistent'] ?? false );
		$output['memcached_host']            = self::sanitize_host( (string) ( $input['memcached_host'] ?? $current['memcached_host'] ), $current['memcached_host'] );
		$output['memcached_port']            = self::sanitize_port( $input['memcached_port'] ?? $current['memcached_port'], 11211 );
		$output['memcached_persistent']      = self::sanitize_bool( $input['memcached_persistent'] ?? false );
		$output['default_ttl']               = max( 0, min( YEAR_IN_SECONDS, (int) ( $input['default_ttl'] ?? $current['default_ttl'] ) ) );
		$output['key_prefix']                = self::sanitize_prefix( (string) ( $input['key_prefix'] ?? $current['key_prefix'] ) );
		$output['enable_dropin']             = self::sanitize_bool( $input['enable_dropin'] ?? false );
		$output['flush_on_post_update']      = self::sanitize_bool( $input['flush_on_post_update'] ?? false );
		$output['flush_on_theme_switch']     = self::sanitize_bool( $input['flush_on_theme_switch'] ?? false );
		$output['flush_on_plugin_update']    = self::sanitize_bool( $input['flush_on_plugin_update'] ?? false );
		$output['cleanup_dropin_uninstall']  = self::sanitize_bool( $input['cleanup_dropin_uninstall'] ?? false );
		$output['cleanup_dropin_deactivate'] = self::sanitize_bool( $input['cleanup_dropin_deactivate'] ?? false );
		$output['enable_logging']            = self::sanitize_bool( $input['enable_logging'] ?? false );
		$output['non_persistent_groups']     = self::sanitize_group_list( $input['non_persistent_groups'] ?? $current['non_persistent_groups'] );
		$output['additional_global_groups']  = self::sanitize_group_list( $input['additional_global_groups'] ?? $current['additional_global_groups'] );
		$output['log_events']                = self::sanitize_log_events( $current['log_events'] ?? array() );
		$output['last_error']                = sanitize_text_field( (string) ( $current['last_error'] ) );
		$output['last_connected_engine']     = sanitize_key( (string) ( $current['last_connected_engine'] ) );

		return $output;
	}

	private static function sanitize_host( string $host, string $fallback ): string {
		$host = trim( $host );
		if ( '' !== $host && ( '/' === $host[0] || preg_match( '/^[a-zA-Z0-9.\[\]:-]+$/D', $host ) ) && ! preg_match( '/[\x00-\x20]/', $host ) ) {
			return $host;
		}
		return $fallback;
	}

	public static function can_manage(): bool {
		return current_user_can( 'manage_options' ) && ( ! is_multisite() || ( is_super_admin() && get_current_blog_id() === get_main_site_id() ) );
	}

	private static function sanitize_bool( mixed $value ): bool {
		return is_bool( $value ) ? $value : in_array( $value, array( '1', 1, 'true', 'yes', 'on' ), true );
	}

	private static function sanitize_port( mixed $value, int $default ): int {
		$port = (int) $value;
		return ( $port >= 1 && $port <= 65535 ) ? $port : $default;
	}

	private static function sanitize_prefix( string $prefix ): string {
		$prefix = sanitize_key( str_replace( array( '-', ' ' ), '_', $prefix ) );
		if ( '' === $prefix ) {
			$prefix = self::defaults()['key_prefix'];
		}
		return substr( $prefix, 0, 64 );
	}

	private static function sanitize_group_list( mixed $value ): string {
		if ( is_array( $value ) ) {
			$value = implode( ',', $value );
		}
		$value  = (string) $value;
		$tokens = preg_split( '/[\s,]+/', $value ) ?: array();
		$clean  = array();
		foreach ( $tokens as $token ) {
			$token = preg_replace( '/[^A-Za-z0-9_.:-]/', '', (string) $token );
			if ( '' !== $token ) {
				$clean[] = substr( $token, 0, 64 );
			}
		}
		return implode( ',', array_values( array_unique( $clean ) ) );
	}

	public static function group_list( string $key ): array {
		$options = self::get_options();
		$raw     = isset( $options[ $key ] ) ? (string) $options[ $key ] : '';
		if ( '' === $raw ) {
			return array();
		}
		return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
	}

	private static function sanitize_log_events( mixed $events ): array {
		$clean = array();
		if ( ! is_array( $events ) ) {
			return $clean;
		}
		foreach ( array_slice( $events, -50 ) as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}
			$clean[] = array(
				'time'    => sanitize_text_field( (string) ( $event['time'] ?? '' ) ),
				'event'   => sanitize_key( (string) ( $event['event'] ?? '' ) ),
				'message' => sanitize_text_field( (string) ( $event['message'] ?? '' ) ),
				'context' => self::sanitize_log_context( $event['context'] ?? array() ),
			);
		}
		return $clean;
	}

	private static function sanitize_log_context( mixed $context ): array {
		$clean = array();
		if ( ! is_array( $context ) ) {
			return $clean;
		}
		foreach ( $context as $key => $value ) {
			if ( is_scalar( $value ) || null === $value ) {
				$clean[ sanitize_key( (string) $key ) ] = sanitize_text_field( (string) $value );
			}
		}
		return $clean;
	}

	public static function register(): void {
		add_filter(
			'option_page_capability_smart_hybrid_cache',
			static function () {
				return self::can_manage() ? 'manage_options' : 'do_not_allow';
			}
		);
		register_setting(
			'smart_hybrid_cache',
			SMART_HYBRID_CACHE_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}
}
