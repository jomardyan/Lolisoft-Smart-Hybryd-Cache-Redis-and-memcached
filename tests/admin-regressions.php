<?php
/** Administration and lifecycle regressions without a running WordPress database. */
error_reporting( E_ALL );
set_error_handler( static function ( $severity, $message, $file, $line ) {
	if ( error_reporting() & $severity ) { throw new ErrorException( $message, 0, $severity, $file, $line ); }
	return false;
} );
define( 'ABSPATH', sys_get_temp_dir() . '/shc-admin-' . getmypid() . '/' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'SMART_HYBRID_CACHE_PATH', dirname( __DIR__ ) . '/smart-hybrid-cache/' );
define( 'SMART_HYBRID_CACHE_OPTION', 'smart_hybrid_cache_options' );
define( 'SMART_HYBRID_CACHE_SIGNATURE', 'Smart Hybrid Cache Drop-In' );
preg_match( "/define\( 'SMART_HYBRID_CACHE_VERSION', '([^']+)'/", file_get_contents( SMART_HYBRID_CACHE_PATH . 'smart-hybrid-cache.php' ), $version );
define( 'SMART_HYBRID_CACHE_VERSION', $version[1] );
define( 'YEAR_IN_SECONDS', 31536000 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
mkdir( WP_CONTENT_DIR, 0700, true );
register_shutdown_function( static function () {
	foreach ( glob( WP_CONTENT_DIR . '/*' ) as $path ) { if ( is_file( $path ) || is_link( $path ) ) { unlink( $path ); } }
	rmdir( WP_CONTENT_DIR );
	rmdir( ABSPATH );
} );
$GLOBALS['shc_options'] = array();
$GLOBALS['shc_hooks'] = array();
$GLOBALS['shc_sanitizers'] = array();
$GLOBALS['shc_blog'] = 1;
$GLOBALS['shc_blog_stack'] = array();
$GLOBALS['shc_multisite'] = false;
$GLOBALS['shc_writes'] = 0;
$GLOBALS['shc_fail_write'] = false;
$GLOBALS['shc_fail_option'] = '';
function is_multisite() { return $GLOBALS['shc_multisite']; }
function get_current_blog_id() { return $GLOBALS['shc_blog']; }
function get_main_site_id() { return 1; }
function is_super_admin() { return true; }
function current_user_can( $capability ) { return true; }
function home_url() { return 'https://example.test'; }
function network_site_url() { return home_url(); }
function trailingslashit( $path ) { return rtrim( $path, '/' ) . '/'; }
function __( $value, $domain = '' ) { return $value; }
function esc_html( $value ) { return htmlspecialchars( (string) $value ); }
function esc_html__( $value, $domain = '' ) { return esc_html( $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); }
function sanitize_text_field( $value ) { return strip_tags( (string) $value ); }
function absint( $value ) { return abs( (int) $value ); }
function current_time( $format ) { return '2026-09-06 12:00:00'; }
function wp_generate_uuid4() { return bin2hex( random_bytes( 16 ) ); }
function wp_parse_args( $input, $defaults = array() ) { return array_merge( $defaults, $input ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_using_ext_object_cache() { return false; }
function is_admin() { return false; }
function add_action( $hook, $callback, $priority = 10, $accepted = 1 ) { $GLOBALS['shc_hooks'][$hook][] = array( $callback, $accepted ); }
function add_filter( $hook, $callback, $priority = 10, $accepted = 1 ) { add_action( $hook, $callback, $priority, $accepted ); }
function do_action( $hook, ...$args ) {
	foreach ( $GLOBALS['shc_hooks'][$hook] ?? array() as $entry ) { $entry[0]( ...array_slice( $args, 0, $entry[1] ) ); }
}
function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['shc_hooks'][$hook] ?? array() as $entry ) { $value = $entry[0]( ...array_slice( array_merge( array( $value ), $args ), 0, $entry[1] ) ); }
	return $value;
}
function register_setting( $group, $name, $args ) { $GLOBALS['shc_sanitizers'][$name] = $args['sanitize_callback']; }
function get_option( $name, $default = false ) { return $GLOBALS['shc_options'][get_current_blog_id()][$name] ?? $default; }
function get_blog_option( $blog, $name, $default = false ) { return $GLOBALS['shc_options'][$blog][$name] ?? $default; }
function update_option( $name, $value, $autoload = null ) {
	if ( $name === $GLOBALS['shc_fail_option'] ) { return false; }
	$blog = get_current_blog_id();
	$exists = array_key_exists( $name, $GLOBALS['shc_options'][$blog] ?? array() );
	$old = get_option( $name );
	if ( isset( $GLOBALS['shc_sanitizers'][$name] ) ) {
		$value = $GLOBALS['shc_sanitizers'][$name]( $value );
		if ( ! $exists ) { $value = $GLOBALS['shc_sanitizers'][$name]( $value ); }
	}
	if ( $exists && $old === $value ) { return false; }
	$GLOBALS['shc_options'][$blog][$name] = $value;
	if ( $exists ) { do_action( 'update_option_' . $name, $old, $value, $name ); }
	else { do_action( 'added_option', $name, $value ); }
	return true;
}
function delete_option( $name ) { unset( $GLOBALS['shc_options'][get_current_blog_id()][$name] ); return true; }
function delete_blog_option( $blog, $name ) { unset( $GLOBALS['shc_options'][$blog][$name] ); return true; }
function switch_to_blog( $blog ) { $GLOBALS['shc_blog_stack'][] = get_current_blog_id(); $GLOBALS['shc_blog'] = $blog; }
function restore_current_blog() { $GLOBALS['shc_blog'] = array_pop( $GLOBALS['shc_blog_stack'] ); }
function add_settings_error( $group, $code, $message ) { $GLOBALS['shc_last_settings_error'] = $message; }
function get_current_screen() { return (object) array( 'id' => 'settings_page_smart-hybrid-cache' ); }
function wp_die( $message ) { throw new RuntimeException( $message ); }
function WP_Filesystem( ...$args ) { $GLOBALS['wp_filesystem'] = new WP_Filesystem_Direct(); return true; }
class WP_Filesystem_Direct {
	public function is_writable( $path ) { return is_writable( $path ); }
	public function get_contents( $path ) { return file_get_contents( $path ); }
	public function put_contents( $path, $value, $mode ) {
		if ( $GLOBALS['shc_fail_write'] ) { return false; }
		++$GLOBALS['shc_writes'];
		$result = file_put_contents( $path, $value ); chmod( $path, $mode ); return false !== $result;
	}
	public function delete( $path ) { return ! file_exists( $path ) || unlink( $path ); }
}
class WP_Error {
	public function __construct( private string $code, private string $message ) {}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WP_CLI {
	public static function error( $message ) { throw new RuntimeException( $message ); }
	public static function success( $message ) { $GLOBALS['shc_cli_success'] = $message; }
}
foreach ( array( 'settings', 'logger', 'redis-client', 'memcached-client', 'dropin-installer', 'cache-manager', 'health-check', 'diagnostics', 'site-health', 'admin', 'cli', 'plugin' ) as $class ) {
	require SMART_HYBRID_CACHE_PATH . 'includes/class-' . $class . '.php';
}
$count = 0;
function shc_same( $expected, $actual, string $label ): void {
	global $count;
	++$count;
	if ( $expected !== $actual ) { throw new RuntimeException( $label . ' expected ' . var_export( $expected, true ) . ' got ' . var_export( $actual, true ) ); }
}
Smart_Hybrid_Cache_Plugin::init();
Smart_Hybrid_Cache_Settings::register();

// First writes fire added_option, not update_option_{name}.
Smart_Hybrid_Cache_Settings::update_options( array( 'engine' => 'disabled', 'enable_dropin' => true ) );
shc_same( true, Smart_Hybrid_Cache_Dropin_Installer::is_owned(), 'First settings save installs configured drop-in' );
shc_same( 1, $GLOBALS['shc_writes'], 'First settings save writes once' );
shc_same( SMART_HYBRID_CACHE_VERSION, Smart_Hybrid_Cache_Diagnostics::dropin_version(), 'Installed release version is correct' );
Smart_Hybrid_Cache_Plugin::set_dropin_enabled( false );
$before = $GLOBALS['shc_writes'];
Smart_Hybrid_Cache_Plugin::set_dropin_enabled( true );
shc_same( $before + 1, $GLOBALS['shc_writes'], 'Explicit install does not regenerate namespace twice' );
shc_same( true, Smart_Hybrid_Cache_Settings::get_options()['enable_dropin'], 'Explicit install saves enabled setting' );
Smart_Hybrid_Cache_Plugin::set_dropin_enabled( false );
shc_same( false, file_exists( Smart_Hybrid_Cache_Dropin_Installer::target() ), 'Explicit removal removes file' );
shc_same( false, Smart_Hybrid_Cache_Settings::get_options()['enable_dropin'], 'Explicit removal saves disabled setting' );

// Errors survive redirects, and successful retries clear them.
$GLOBALS['shc_fail_write'] = true;
$result = Smart_Hybrid_Cache_Plugin::set_dropin_enabled( true );
shc_same( true, is_wp_error( $result ), 'Write failures return an actionable error' );
shc_same( false, Smart_Hybrid_Cache_Settings::get_options()['enable_dropin'], 'Failed installation keeps disabled setting' );
ob_start();
( new Smart_Hybrid_Cache_Admin( new Smart_Hybrid_Cache_Manager() ) )->notices();
$notice = ob_get_clean();
shc_same( true, str_contains( $notice, 'Unable to write the configured drop-in' ), 'Settings page renders saved lifecycle failure' );
$GLOBALS['shc_fail_write'] = false;
Smart_Hybrid_Cache_Plugin::set_dropin_enabled( true );
shc_same( false, Smart_Hybrid_Cache_Settings::get_shared_option( 'smart_hybrid_cache_dropin_error' ), 'Successful retry clears stale error' );

// Invalid payloads and literal passwords must not silently corrupt settings.
$before = Smart_Hybrid_Cache_Settings::get_options();
shc_same( $before, Smart_Hybrid_Cache_Settings::sanitize( 'malformed' ), 'Malformed settings payload preserves configuration' );
Smart_Hybrid_Cache_Settings::update_options( array( 'redis_password' => 'literal<>&%2F\\secret"' ) );
shc_same( 'literal<>&%2F\\secret"', Smart_Hybrid_Cache_Settings::get_options()['redis_password'], 'Literal password survives repeated sanitization' );
Smart_Hybrid_Cache_Settings::update_options( array( 'redis_password' => '' ) );
shc_same( 'literal<>&%2F\\secret"', Smart_Hybrid_Cache_Settings::get_options()['redis_password'], 'Blank password preserves saved value' );
Smart_Hybrid_Cache_Settings::update_options( array( 'clear_redis_password' => true ) );
shc_same( '', Smart_Hybrid_Cache_Settings::get_options()['redis_password'], 'Explicit password clearing survives repeated sanitization' );
Smart_Hybrid_Cache_Settings::update_options( array( 'redis_password' => '0' ) );
shc_same( '***redacted***', Smart_Hybrid_Cache_Diagnostics::snapshot()['options']['redis_password'], 'Password zero is redacted in diagnostics' );
$clean = Smart_Hybrid_Cache_Settings::sanitize( array(
	'redis_database' => -2, 'default_ttl' => -10, 'redis_port' => -6378,
	'non_persistent_groups' => array( 'Mixed-Group', 'woocommerce-session', 'Mixed-Group', array( 'invalid' ) ),
) );
shc_same( 0, $clean['redis_database'], 'Negative database does not select a different positive database' );
shc_same( 0, $clean['default_ttl'], 'Negative TTL clamps to zero' );
shc_same( 6379, $clean['redis_port'], 'Invalid negative port uses default' );
shc_same( 'Mixed-Group,woocommerce-session', $clean['non_persistent_groups'], 'Group arrays retain spelling and deduplicate' );

// CLI must actually enable the drop-in and allow retries after a failed write.
Smart_Hybrid_Cache_Plugin::set_dropin_enabled( false );
$cli = new Smart_Hybrid_Cache_CLI();
$cli->enable( array( 'redis' ) );
shc_same( true, Smart_Hybrid_Cache_Dropin_Installer::is_owned(), 'CLI enable installs a missing drop-in' );
shc_same( true, Smart_Hybrid_Cache_Settings::get_options()['enable_dropin'], 'CLI enable sets persistent flag' );
Smart_Hybrid_Cache_Dropin_Installer::remove();
$GLOBALS['shc_fail_write'] = true;
try { $cli->enable( array( 'redis' ) ); throw new LogicException( 'Expected CLI error' ); }
catch ( RuntimeException $exception ) { shc_same( true, str_contains( $exception->getMessage(), 'Unable to write' ), 'CLI reports failed repeat installation' ); }
$GLOBALS['shc_fail_write'] = false;
$cli->enable( array( 'redis' ) );
shc_same( true, Smart_Hybrid_Cache_Dropin_Installer::is_owned(), 'CLI retry repairs missing drop-in without a settings change' );
shc_same( false, Smart_Hybrid_Cache_Settings::get_shared_option( 'smart_hybrid_cache_dropin_error' ), 'CLI retry clears earlier error' );

// Disable must report storage failure and retry an earlier failed file update.
$GLOBALS['shc_fail_option'] = SMART_HYBRID_CACHE_OPTION;
try { $cli->disable(); throw new LogicException( 'Expected CLI storage error' ); }
catch ( RuntimeException $exception ) { shc_same( true, str_contains( $exception->getMessage(), 'could not be saved' ), 'CLI disable reports database write failure' ); }
$GLOBALS['shc_fail_option'] = '';
shc_same( 'redis', Smart_Hybrid_Cache_Settings::get_options()['engine'], 'Failed disable does not falsely change persisted engine' );
$GLOBALS['shc_fail_write'] = true;
try { $cli->disable(); throw new LogicException( 'Expected CLI file error' ); }
catch ( RuntimeException $exception ) { shc_same( true, str_contains( $exception->getMessage(), 'Unable to write' ), 'CLI disable reports drop-in write failure' ); }
$GLOBALS['shc_fail_write'] = false;
$cli->disable();
shc_same( true, str_contains( file_get_contents( Smart_Hybrid_Cache_Dropin_Installer::target() ), "'engine' => 'disabled'" ), 'Repeated CLI disable repairs an earlier failed drop-in update' );
shc_same( false, Smart_Hybrid_Cache_Settings::get_shared_option( 'smart_hybrid_cache_dropin_error' ), 'Repeated CLI disable clears the earlier error' );

// Network writes must reach the same main-site options used on reads.
$GLOBALS['shc_multisite'] = true;
switch_to_blog( 2 );
Smart_Hybrid_Cache_Settings::update_options( array( 'key_prefix' => 'network_shared' ) );
shc_same( 2, get_current_blog_id(), 'Shared configuration write restores the original site' );
shc_same( 'network_shared', get_blog_option( 1, SMART_HYBRID_CACHE_OPTION )['key_prefix'], 'Subsite updates reach main configuration' );
shc_same( false, get_option( SMART_HYBRID_CACHE_OPTION ), 'Shared updates do not create unused subsite options' );
Smart_Hybrid_Cache_Logger::log( 'subsite_event', 'Network event' );
$events = Smart_Hybrid_Cache_Settings::get_options()['log_events'];
shc_same( 'subsite_event', end( $events )['event'], 'Subsite events are readable in network history' );
shc_same( false, get_option( 'smart_hybrid_cache_events' ), 'Event log is not fragmented among sites' );
Smart_Hybrid_Cache_Settings::update_shared_option( 'smart_hybrid_cache_status', array( 'last_error' => 'network problem' ) );
shc_same( 'network problem', Smart_Hybrid_Cache_Settings::get_options()['last_error'], 'Shared diagnostic status is read from main site' );
$before = file_get_contents( Smart_Hybrid_Cache_Dropin_Installer::target() );
Smart_Hybrid_Cache_Plugin::settings_updated( array(), array( 'enable_dropin' => true, 'engine' => 'disabled' ), SMART_HYBRID_CACHE_OPTION );
shc_same( $before, file_get_contents( Smart_Hybrid_Cache_Dropin_Installer::target() ), 'Unrelated subsite option changes cannot rewrite network drop-in' );
shc_same( false, Smart_Hybrid_Cache_Settings::can_manage(), 'Subsite UI cannot manage shared settings' );
restore_current_blog();
shc_same( true, Smart_Hybrid_Cache_Settings::can_manage(), 'Main site super administrator can manage settings' );
Smart_Hybrid_Cache_Plugin::set_dropin_enabled( false );
delete_option( SMART_HYBRID_CACHE_OPTION );
switch_to_blog( 2 );
Smart_Hybrid_Cache_Plugin::activate( true );
shc_same( 2, get_current_blog_id(), 'Network activation restores originating subsite' );
shc_same( true, is_array( get_blog_option( 1, SMART_HYBRID_CACHE_OPTION ) ), 'Network activation creates defaults on main site' );
shc_same( false, get_option( SMART_HYBRID_CACHE_OPTION ), 'Network activation avoids unused subsite defaults' );
restore_current_blog();

// Drop-in ownership and interrupted writes preserve other cache providers.
$target = Smart_Hybrid_Cache_Dropin_Installer::target();
file_put_contents( $target, '<?php /* Another cache */' );
$result = Smart_Hybrid_Cache_Plugin::set_dropin_enabled( true );
shc_same( 'existing_dropin', $result->get_error_code(), 'Foreign drop-in requires explicit replacement' );
shc_same( '<?php /* Another cache */', file_get_contents( $target ), 'Foreign drop-in is preserved' );
Smart_Hybrid_Cache_Plugin::set_dropin_enabled( true, true );
$before = file_get_contents( $target );
$GLOBALS['shc_fail_write'] = true;
$result = Smart_Hybrid_Cache_Dropin_Installer::install();
shc_same( true, is_wp_error( $result ), 'Replacement write failure is reported' );
shc_same( $before, file_get_contents( $target ), 'Failed replacement preserves working drop-in bytes' );
$GLOBALS['shc_fail_write'] = false;
Smart_Hybrid_Cache_Settings::update_options( array( 'cleanup_dropin_deactivate' => false ) );
Smart_Hybrid_Cache_Plugin::deactivate();
shc_same( true, Smart_Hybrid_Cache_Dropin_Installer::is_owned(), 'Opted-out deactivation retains owned drop-in' );
shc_same( true, str_contains( file_get_contents( $target ), "'engine' => 'disabled'" ), 'Retained drop-in disables persistence on deactivation' );
Smart_Hybrid_Cache_Settings::update_options( array( 'cleanup_dropin_deactivate' => true ) );
Smart_Hybrid_Cache_Plugin::deactivate();
shc_same( false, file_exists( $target ), 'Opted-in deactivation removes owned drop-in' );
echo "PASS {$count} administration and lifecycle assertions\n";
