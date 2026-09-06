<?php
/** Run through wp eval-file tests/wordpress-smoke.php configure|boot|cleanup. */
$phase = $args[0] ?? 'boot';
function shc_check( $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	WP_CLI::line( 'PASS ' . $message );
}
if ( 'configure' === $phase ) {
	Smart_Hybrid_Cache_Settings::register();
	$password = 'literal<>&%2F\\secret"';
	$options = Smart_Hybrid_Cache_Settings::get_options();
	$options['redis_password'] = $password;
	$options['non_persistent_groups'] = 'Mixed-Group,woocommerce-session';
	$options['engine'] = 'disabled';
	$options['enable_dropin'] = false;
	Smart_Hybrid_Cache_Settings::update_options( $options );
	$saved = Smart_Hybrid_Cache_Settings::get_options();
	shc_check( $password === $saved['redis_password'], 'Password bytes preserved through Settings API' );
	shc_check( 'Mixed-Group,woocommerce-session' === $saved['non_persistent_groups'], 'Group spelling preserved' );
	$saved['redis_password'] = '';
	Smart_Hybrid_Cache_Settings::update_options( $saved );
	shc_check( $password === Smart_Hybrid_Cache_Settings::get_options()['redis_password'], 'Blank password preserves saved secret' );
	$saved['clear_redis_password'] = true;
	Smart_Hybrid_Cache_Settings::update_options( $saved );
	shc_check( '' === Smart_Hybrid_Cache_Settings::get_options()['redis_password'], 'Explicit password clearing succeeds' );
	$reset = Smart_Hybrid_Cache_Settings::get_options();
	$reset['redis_password'] = 'new-password-after-clear';
	Smart_Hybrid_Cache_Settings::update_options( $reset );
	shc_check( 'new-password-after-clear' === Smart_Hybrid_Cache_Settings::get_options()['redis_password'], 'Password can be replaced after an earlier clear' );
	Smart_Hybrid_Cache_Settings::update_options( array( 'clear_redis_password' => true ) );
	$before = count( Smart_Hybrid_Cache_Settings::get_options()['log_events'] );
	Smart_Hybrid_Cache_Logger::log( 'regression_test', 'Settings API does not discard logs.' );
	shc_check( count( Smart_Hybrid_Cache_Settings::get_options()['log_events'] ) > $before, 'Logs persist outside settings sanitization' );
	$saved = Smart_Hybrid_Cache_Settings::get_options();
	$saved['engine'] = getenv( 'SHC_WP_ENGINE' ) ?: 'disabled';
	$saved['enable_dropin'] = true;
	Smart_Hybrid_Cache_Settings::update_options( $saved );
	shc_check( ! get_option( 'smart_hybrid_cache_dropin_error' ), 'Configured drop-in installed without error' );
	shc_check( Smart_Hybrid_Cache_Dropin_Installer::is_owned(), 'Installed drop-in ownership detected' );
	shc_check( SMART_HYBRID_CACHE_VERSION === Smart_Hybrid_Cache_Diagnostics::dropin_version(), 'Drop-in version matches plugin' );
	$dropin = file_get_contents( Smart_Hybrid_Cache_Dropin_Installer::target() );
	shc_check( false === strpos( $dropin, 'SHC_CONFIGURATION' ), 'Installed copy contains generated configuration' );
} elseif ( 'boot' === $phase ) {
	shc_check( method_exists( $GLOBALS['wp_object_cache'], 'shc_engine' ), 'WordPress boots with configured drop-in' );
	wp_start_object_cache();
	shc_check( method_exists( $GLOBALS['wp_object_cache'], 'shc_engine' ), 'Repeated core cache bootstrap does not redeclare functions' );
	$options = Smart_Hybrid_Cache_Settings::get_options();
	$expected = getenv( 'SHC_WP_ENGINE' ) ?: 'none';
	shc_check( $expected === $GLOBALS['wp_object_cache']->shc_engine(), 'Expected runtime backend is active' );
	shc_check( wp_cache_set( 'shc-test', (object) array( 'ok' => true ) ), 'Object write succeeds in real WordPress' );
	shc_check( true === wp_cache_get( 'shc-test' )->ok, 'Object read succeeds in real WordPress' );
	shc_check( array( 'one' => true, 'two' => true ) === wp_cache_set_multiple( array( 'one' => 1, 'two' => 2 ) ), 'WordPress bulk API receives per-key results' );
	shc_check( isset( Smart_Hybrid_Cache_Diagnostics::snapshot()['environment']['wp_version'] ), 'Diagnostics export succeeds' );
	$status = Smart_Hybrid_Cache_Health_Check::get_status();
	shc_check( ( 'none' !== $expected ) === $status['object_cache_available'], 'Availability reflects runtime backend' );
	if ( 'none' !== $expected ) {
		$result = ( new Smart_Hybrid_Cache_Manager() )->test( $expected );
		shc_check( $result['ok'], 'Backend read/write/delete connection test' );
		shc_check( ( new Smart_Hybrid_Cache_Manager() )->flush_safe(), 'Admin flush succeeds' );
		shc_check( false === wp_cache_get( 'shc-test' ), 'Admin flush clears current request values' );
	}
	wp_cache_set( 'shc-cross-request', 'present', 'shc-tests', 60 );
} elseif ( 'next-request' === $phase ) {
	if ( getenv( 'SHC_WP_ENGINE' ) ) {
		shc_check( 'present' === wp_cache_get( 'shc-cross-request', 'shc-tests' ), 'Cache survives separate WordPress processes' );
	} else {
		shc_check( false === wp_cache_get( 'shc-cross-request', 'shc-tests' ), 'Disabled cache stays request local' );
	}
} elseif ( 'cleanup' === $phase ) {
	Smart_Hybrid_Cache_Plugin::deactivate();
	shc_check( ! file_exists( Smart_Hybrid_Cache_Dropin_Installer::target() ), 'Deactivation removes owned drop-in' );
}
if ( 'multisite' === $phase ) {
	shc_check( is_multisite(), 'Real WordPress multisite is enabled' );
	wp_set_current_user( 1 );
	shc_check( Smart_Hybrid_Cache_Settings::can_manage(), 'Main-site super administrator can configure cache' );
	$sites = get_sites( array( 'fields' => 'ids', 'site__not_in' => array( get_main_site_id() ) ) );
	shc_check( ! empty( $sites ), 'Secondary site exists' );
	wp_cache_add_non_persistent_groups( array( 'shc-network-local' ) );
	wp_cache_add_global_groups( array( 'shc-network-global' ) );
	wp_cache_set( 'scope', 'main', 'shc-network-local' );
	wp_cache_set( 'scope', 'shared', 'shc-network-global' );
	$main_options = Smart_Hybrid_Cache_Settings::get_options();
	switch_to_blog( (int) $sites[0] );
	shc_check( ! Smart_Hybrid_Cache_Settings::can_manage(), 'Secondary-site settings cannot change shared drop-in' );
	shc_check( $main_options['key_prefix'] === Smart_Hybrid_Cache_Settings::get_options()['key_prefix'], 'Secondary site reads main-site configuration' );
	shc_check( false === wp_cache_get( 'scope', 'shc-network-local' ), 'Multisite isolates local group' );
	shc_check( 'shared' === wp_cache_get( 'scope', 'shc-network-global' ), 'Multisite shares global group' );
	wp_cache_set( 'scope', 'secondary', 'shc-network-local' );
	restore_current_blog();
	shc_check( 'main' === wp_cache_get( 'scope', 'shc-network-local' ), 'Restoring blog preserves request-local values' );
}
