<?php
/**
 * Plugin lifecycle and configured drop-in synchronization.
 *
 * @package SmartHybridCache
 */
defined( 'ABSPATH' ) || exit;

class Smart_Hybrid_Cache_Plugin {
	private static ?Smart_Hybrid_Cache_Manager $manager = null;
	private static bool $updating_dropin_state = false;

	public static function init(): void {
		self::$manager = new Smart_Hybrid_Cache_Manager();
		self::$manager->hooks();
		if ( is_admin() ) {
			( new Smart_Hybrid_Cache_Admin( self::$manager ) )->hooks();
			add_action( 'admin_init', array( __CLASS__, 'upgrade_dropin' ) );
		}
		Smart_Hybrid_Cache_Site_Health::register();
		add_action( 'update_option_' . SMART_HYBRID_CACHE_OPTION, array( __CLASS__, 'settings_updated' ), 10, 3 );
		add_action( 'added_option', array( __CLASS__, 'settings_added' ), 10, 2 );
		Smart_Hybrid_Cache_CLI::register();
		do_action( 'smart_hybrid_cache_loaded', self::$manager );
	}

	public static function manager(): ?Smart_Hybrid_Cache_Manager {
		return self::$manager;
	}

	public static function settings_updated( mixed $old_value, mixed $value, string $option ): void {
		if ( self::$updating_dropin_state || ! is_array( $value ) || ( is_multisite() && get_current_blog_id() !== get_main_site_id() ) ) {
			return;
		}
		$old_value = is_array( $old_value ) ? $old_value : array();
		$old = $old_value;
		$new = $value;
		foreach ( array( 'last_error', 'last_connected_engine', 'log_events', 'clear_redis_password' ) as $key ) {
			unset( $old[ $key ], $new[ $key ] );
		}
		if ( $old === $new ) {
			return;
		}
		$result = true;
		if ( ! empty( $value['enable_dropin'] ) ) {
			$result = Smart_Hybrid_Cache_Dropin_Installer::install( false, $value );
		} elseif ( ! empty( $old_value['enable_dropin'] ) && Smart_Hybrid_Cache_Dropin_Installer::is_owned() ) {
			$result = Smart_Hybrid_Cache_Dropin_Installer::remove();
		} elseif ( Smart_Hybrid_Cache_Dropin_Installer::is_owned() ) {
			$result = Smart_Hybrid_Cache_Dropin_Installer::install( false, $value );
		}
		self::report_result( $result );
		Smart_Hybrid_Cache_Logger::log( 'settings_updated', __( 'Settings updated.', 'smart-hybrid-cache' ) );
	}

	public static function settings_added( string $option, mixed $value ): void {
		if ( SMART_HYBRID_CACHE_OPTION === $option ) {
			self::settings_updated( array(), $value, $option );
		}
	}

	/** Explicit actions synchronize the file once, then save their resulting state. */
	public static function set_dropin_enabled( bool $enabled, bool $force = false ): WP_Error|bool {
		$options = Smart_Hybrid_Cache_Settings::get_options();
		$options['enable_dropin'] = $enabled;
		$result = $enabled
			? Smart_Hybrid_Cache_Dropin_Installer::install( $force, $options )
			: Smart_Hybrid_Cache_Dropin_Installer::remove( $force );
		if ( ! is_wp_error( $result ) ) {
			self::$updating_dropin_state = true;
			try {
				Smart_Hybrid_Cache_Settings::update_options( array( 'enable_dropin' => $enabled ) );
				if ( $enabled !== (bool) Smart_Hybrid_Cache_Settings::get_options()['enable_dropin'] ) {
					$result = new WP_Error( 'settings_save_failed', __( 'The drop-in changed, but its setting could not be saved. Check database access and retry.', 'smart-hybrid-cache' ) );
				}
			} finally {
				self::$updating_dropin_state = false;
			}
		}
		self::report_result( $result );
		return $result;
	}

	private static function report_result( mixed $result ): void {
		if ( is_wp_error( $result ) ) {
			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error( 'smart_hybrid_cache', $result->get_error_code(), $result->get_error_message() );
			}
			Smart_Hybrid_Cache_Settings::update_shared_option( 'smart_hybrid_cache_dropin_error', $result->get_error_message() );
		} else {
			Smart_Hybrid_Cache_Settings::delete_shared_option( 'smart_hybrid_cache_dropin_error' );
		}
	}

	public static function upgrade_dropin(): void {
		if ( ! Smart_Hybrid_Cache_Settings::can_manage() || ! Smart_Hybrid_Cache_Dropin_Installer::is_owned() ) {
			return;
		}
		if ( SMART_HYBRID_CACHE_VERSION !== Smart_Hybrid_Cache_Diagnostics::dropin_version() ) {
			self::report_result( Smart_Hybrid_Cache_Dropin_Installer::install() );
		}
	}

	public static function activate( bool $network_wide = false ): void {
		if ( is_multisite() && ! $network_wide ) {
			wp_die( esc_html__( 'Network activate Smart Hybrid Cache and configure it on the main site. The object cache is shared by the network.', 'smart-hybrid-cache' ) );
		}
		Smart_Hybrid_Cache_Settings::ensure_defaults();
		$options = Smart_Hybrid_Cache_Settings::get_options();
		if ( ! empty( $options['enable_dropin'] ) || Smart_Hybrid_Cache_Dropin_Installer::is_owned() ) {
			self::report_result( Smart_Hybrid_Cache_Dropin_Installer::install() );
		}
	}

	public static function deactivate(): void {
		if ( ! Smart_Hybrid_Cache_Dropin_Installer::is_owned() ) {
			return;
		}
		$options = Smart_Hybrid_Cache_Settings::get_options();
		if ( ! empty( $options['cleanup_dropin_deactivate'] ) ) {
			$result = Smart_Hybrid_Cache_Dropin_Installer::remove();
		} else {
			$options['engine'] = 'disabled';
			$result            = Smart_Hybrid_Cache_Dropin_Installer::install( false, $options );
		}
		self::report_result( $result );
	}
}
