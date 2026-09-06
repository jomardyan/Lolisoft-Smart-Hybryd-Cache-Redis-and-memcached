<?php
/**
 * WordPress Site Health integration.
 *
 * @package SmartHybridCache
 * @since   1.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Smart_Hybrid_Cache_Site_Health
 *
 * Registers Site Health tests and debug information panels.
 *
 * @since 1.1.0
 */
class Smart_Hybrid_Cache_Site_Health {
	public static function register(): void {
		add_filter( 'site_status_tests', array( __CLASS__, 'tests' ) );
		add_filter( 'debug_information', array( __CLASS__, 'debug_information' ) );
	}

	public static function tests( array $tests ): array {
		$tests['direct']['smart_hybrid_cache_object_cache'] = array(
			'label' => __( 'Persistent object cache (Smart Hybrid Cache)', 'smart-hybrid-cache' ),
			'test'  => array( __CLASS__, 'run_test' ),
		);
		return $tests;
	}

	public static function run_test(): array {
		$manager = Smart_Hybrid_Cache_Plugin::manager() ?: new Smart_Hybrid_Cache_Manager();
		$status  = Smart_Hybrid_Cache_Health_Check::get_status( $manager );
		$active  = 'none' !== $status['active_engine'];
		$dropin  = $status['dropin'];

		$result = array(
			'label'       => __( 'Smart Hybrid Cache is providing a persistent object cache', 'smart-hybrid-cache' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Performance', 'smart-hybrid-cache' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html( $dropin['message'] ) . '</p>',
			'actions'     => '',
			'test'        => 'smart_hybrid_cache_object_cache',
		);

		if ( ! $dropin['active'] ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'A persistent object cache is not active', 'smart-hybrid-cache' );
			$result['description'] = '<p>' . esc_html__( 'WordPress benefits significantly from a persistent object cache. Install the Smart Hybrid Cache drop-in or another supported object cache.', 'smart-hybrid-cache' ) . '</p>';
			$result['actions']     = sprintf(
				'<p><a class="button" href="%1$s">%2$s</a></p>',
				esc_url( admin_url( 'options-general.php?page=smart-hybrid-cache' ) ),
				esc_html__( 'Open Smart Hybrid Cache settings', 'smart-hybrid-cache' )
			);
		} elseif ( ! $dropin['owned'] ) {
			$result['label'] = __( 'Another drop-in provides the object cache', 'smart-hybrid-cache' );
		} elseif ( ! empty( $status['last_error'] ) ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'Smart Hybrid Cache reported a connection error', 'smart-hybrid-cache' );
			$result['description'] = '<p>' . esc_html( $status['last_error'] ) . '</p>';
		}

		return $result;
	}

	public static function debug_information( array $info ): array {
		$manager = Smart_Hybrid_Cache_Plugin::manager() ?: new Smart_Hybrid_Cache_Manager();
		$status  = Smart_Hybrid_Cache_Health_Check::get_status( $manager );

		$info['smart-hybrid-cache'] = array(
			'label'       => __( 'Smart Hybrid Cache', 'smart-hybrid-cache' ),
			'description' => __( 'Persistent object cache configuration and runtime status.', 'smart-hybrid-cache' ),
			'fields'      => array(
				'version'         => array(
					'label' => __( 'Plugin version', 'smart-hybrid-cache' ),
					'value' => SMART_HYBRID_CACHE_VERSION,
				),
				'selected_engine' => array(
					'label' => __( 'Selected engine', 'smart-hybrid-cache' ),
					'value' => (string) $status['selected_engine'],
				),
				'active_engine'   => array(
					'label' => __( 'Active engine', 'smart-hybrid-cache' ),
					'value' => (string) $status['active_engine'],
				),
				'redis_ext'       => array(
					'label' => __( 'Redis extension', 'smart-hybrid-cache' ),
					'value' => $status['redis_available'] ? 'available' : 'missing',
				),
				'memcached_ext'   => array(
					'label' => __( 'Memcached extension', 'smart-hybrid-cache' ),
					'value' => $status['memcached_available'] ? 'available' : 'missing',
				),
				'dropin'          => array(
					'label' => __( 'Drop-in', 'smart-hybrid-cache' ),
					'value' => $status['dropin']['owner_label'],
				),
				'available'       => array(
					'label' => __( 'Object cache available', 'smart-hybrid-cache' ),
					'value' => ! empty( $status['object_cache_available'] ) ? 'yes' : 'no',
				),
				'last_error'      => array(
					'label' => __( 'Last error', 'smart-hybrid-cache' ),
					'value' => $status['last_error'] ?: 'none',
				),
			),
		);
		return $info;
	}
}
