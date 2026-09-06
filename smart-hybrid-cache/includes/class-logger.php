<?php
/**
 * Lightweight plugin event logging.
 *
 * @package SmartHybridCache
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Smart_Hybrid_Cache_Logger
 *
 * Records plugin events to the options table.
 *
 * @since 1.0.0
 */
class Smart_Hybrid_Cache_Logger {
	private const MAX_EVENTS     = 50;
	private static bool $writing = false;

	public static function log( string $event, string $message, array $context = array() ): void {
		if ( self::$writing ) {
			return;
		}
		$options = Smart_Hybrid_Cache_Settings::get_options();
		if ( empty( $options['enable_logging'] ) ) {
			return;
		}

		$events   = is_array( $options['log_events'] ?? null ) ? $options['log_events'] : array();
		$events[] = array(
			'time'    => current_time( 'mysql' ),
			'event'   => sanitize_key( $event ),
			'message' => sanitize_text_field( $message ),
			'context' => self::sanitize_context( $context ),
		);
		$events   = array_slice( $events, - self::MAX_EVENTS );

				self::$writing = true;
		update_option( 'smart_hybrid_cache_events', $events, false );
		self::$writing = false;
	}

	public static function is_writing(): bool {
		return self::$writing;
	}

	private static function sanitize_context( array $context ): array {
		$clean = array();
		foreach ( $context as $key => $value ) {
			if ( is_scalar( $value ) || null === $value ) {
				$clean[ sanitize_key( (string) $key ) ] = sanitize_text_field( (string) $value );
			}
		}
		return $clean;
	}
}
