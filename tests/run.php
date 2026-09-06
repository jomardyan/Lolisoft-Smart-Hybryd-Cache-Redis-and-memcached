<?php
/** Contract regressions. Run once per engine; real extensions are used in CI. */
error_reporting( E_ALL );
set_error_handler( static function ( $severity, $message, $file, $line ) {
	if ( error_reporting() & $severity ) { throw new ErrorException( $message, 0, $severity, $file, $line ); }
	return false;
} );
define( 'ABSPATH', sys_get_temp_dir() . '/shc-contract-' . getmypid() . '/' );
function get_option() { throw new RuntimeException( 'Options API called before cache bootstrap.' ); }
function home_url() { throw new RuntimeException( 'URL API called before cache bootstrap.' ); }
function network_site_url() { throw new RuntimeException( 'URL API called before cache bootstrap.' ); }
function wp_using_ext_object_cache( $value = null ) { static $active = false; if ( null !== $value ) { $active = $value; } return $active; }
function wp_suspend_cache_addition( $value = null ) { static $suspended = false; if ( null !== $value ) { $suspended = $value; } return $suspended; }
function esc_html( $value ) { return htmlspecialchars( $value ); }
$engine = getenv( 'SHC_TEST_ENGINE' ) ?: 'disabled';
if ( 'disabled' !== $engine && ! class_exists( 'redis' === $engine ? 'Redis' : 'Memcached' ) ) {
	require __DIR__ . '/backend-fakes.php';
}
$options = array(
	'engine' => $engine, 'default_ttl' => 3600, 'key_prefix' => 'contract_tests',
	'redis_host' => '127.0.0.1', 'redis_port' => 6379, 'redis_password' => '', 'redis_database' => 0,
	'redis_timeout' => 0.2, 'redis_persistent' => false, 'redis_tls' => false,
	'memcached_host' => '127.0.0.1', 'memcached_port' => 11211, 'memcached_persistent' => false,
	'non_persistent_groups' => 'Mixed-Group,local-only', 'additional_global_groups' => 'shared-group',
);
$tmp = tempnam( sys_get_temp_dir(), 'shc-test-' );
file_put_contents( $tmp, str_replace( '/* SHC_CONFIGURATION */ array()', var_export( $options, true ), file_get_contents( dirname( __DIR__ ) . '/smart-hybrid-cache/dropins/object-cache.php' ) ) );
require $tmp;
unlink( $tmp );
$count = 0;
function same( $expected, $actual, string $label ): void {
	global $count;
	++$count;
	if ( $expected !== $actual ) { throw new RuntimeException( $label . ' expected ' . var_export( $expected, true ) . ' got ' . var_export( $actual, true ) ); }
}
// Core calls init after loading the drop-in, not during file inclusion.
same( false, isset( $GLOBALS['wp_object_cache'] ), 'No premature global construction' );
wp_cache_init();
same( 'disabled' === $engine ? 'none' : $engine, $GLOBALS['wp_object_cache']->shc_engine(), 'Engine connected' );
same( true, wp_using_ext_object_cache(), 'Drop-in implementation flag remains active' );
foreach ( array( false, null, 0, '', array() ) as $i => $value ) {
	same( true, wp_cache_set( 'value-' . $i, $value ), 'Store falsy value' );
	same( $value, wp_cache_get( 'value-' . $i, '', false, $found ), 'Read falsy value' );
	same( true, $found, 'Falsy value is a hit' );
	same( false, wp_cache_add( 'value-' . $i, 'replacement' ), 'Add cannot overwrite falsy value' );
}
foreach ( array( '', '  ', null, false, array(), new stdClass() ) as $key ) {
	same( false, wp_cache_set( $key, 'bad' ), 'Reject invalid key' );
	same( false, wp_cache_get( $key, '', false, $found ), 'Reject invalid read key' );
	same( false, $found, 'Invalid key is a miss' );
}
same( true, wp_cache_set( 0, 'zero' ), 'Integer zero key' );
same( 'zero', wp_cache_get( '0' ), 'String and integer keys agree' );
$object = (object) array( 'name' => 'original' );
wp_cache_set( 'object', $object );
$object->name = 'outside';
$read = wp_cache_get( 'object' );
same( 'original', $read->name, 'Clone on write' );
$read->name = 'changed';
same( 'original', wp_cache_get( 'object' )->name, 'Clone on read' );
same( array( 'a' => true, 'b' => true ), wp_cache_set_multiple( array( 'a' => 1, 'b' => 2 ) ), 'Set multiple results' );
same( array( 'a' => false, 'c' => true ), wp_cache_add_multiple( array( 'a' => 3, 'c' => 4 ) ), 'Add multiple results' );
same( array( 'a' => 1, 'c' => 4, 'missing' => false ), wp_cache_get_multiple( array( 'a', 'c', 'missing' ) ), 'Get multiple results' );
same( array( 'a' => true, 'missing' => false ), wp_cache_delete_multiple( array( 'a', 'missing' ) ), 'Delete multiple results' );
same( false, wp_cache_replace( 'missing', 'no' ), 'Replace does not create' );
same( false, wp_cache_incr( 'missing' ), 'Increment does not create' );
same( false, wp_cache_decr( 'missing' ), 'Decrement does not create' );
wp_cache_set( 'counter', 5 );
same( 8, wp_cache_incr( 'counter', 3 ), 'Increment value' );
same( 0, wp_cache_decr( 'counter', 20 ), 'Decrement floor' );
wp_suspend_cache_addition( true );
same( false, wp_cache_add( 'suspended', 1 ), 'Honor addition suspension' );
wp_suspend_cache_addition( false );
wp_cache_set( 'scope', 'one', 'local-only' );
wp_cache_set( 'scope', 'global', 'shared-group' );
wp_cache_switch_to_blog( 2 );
same( false, wp_cache_get( 'scope', 'local-only' ), 'Blog isolation' );
same( 'global', wp_cache_get( 'scope', 'shared-group' ), 'Global group shared' );
wp_cache_set( 'scope', 'two', 'local-only' );
wp_cache_switch_to_blog( 1 );
same( 'one', wp_cache_get( 'scope', 'local-only', true ), 'Restore preserves runtime data even with force' );
wp_cache_add_global_groups( array( 'dynamic-global' ) );
$instance = $GLOBALS['wp_object_cache'];
wp_cache_switch_to_blog( 2 );
wp_cache_flush_runtime();
same( true, $instance === $GLOBALS['wp_object_cache'], 'Runtime flush preserves instance' );
wp_cache_set( 'after', 1, 'dynamic-global' );
wp_cache_switch_to_blog( 3 );
same( 1, wp_cache_get( 'after', 'dynamic-global' ), 'Runtime flush preserves groups' );
wp_cache_set( 'long-a', 'A', str_repeat( 'long group ', 70 ) );
wp_cache_set( 'long-b', 'B', str_repeat( 'long group ', 70 ) );
same( 'A', wp_cache_get( 'long-a', str_repeat( 'long group ', 70 ), true ), 'Long group does not truncate keys' );
same( 'B', wp_cache_get( 'long-b', str_repeat( 'long group ', 70 ), true ), 'Long group keys distinct' );
wp_cache_set( 'long-ttl', 'survives', '', 2592001 );
same( 'survives', wp_cache_get( 'long-ttl', '', true ), 'Memcached TTL over 30 days' );
wp_cache_set( 'expires', 1, '', 1 );
wp_cache_set( 'counter-expires', 1, '', 1 );
wp_cache_incr( 'counter-expires' );
usleep( 1100000 );
same( false, wp_cache_get( 'expires' ), 'Runtime expiry' );
same( false, wp_cache_get( 'counter-expires', '', true ), 'Increment preserves expiry' );
if ( 'disabled' !== $engine ) {
	wp_cache_switch_to_blog( 1 );
	wp_cache_set( 'cross-request', 'stored' );
	$other = new WP_Object_Cache();
	same( 'stored', $other->get( 'cross-request' ), 'Persistence across instances' );
	wp_cache_set( 'ephemeral', 'local', 'Mixed-Group' );
	same( false, $other->get( 'ephemeral', 'Mixed-Group' ), 'Configured nonpersistent group keeps spelling' );
	wp_cache_set( 'removed-remotely', 'old' );
	$other->delete( 'removed-remotely' );
	same( false, wp_cache_get( 'removed-remotely', '', true ), 'Forced miss after remote delete' );
	same( false, wp_cache_get( 'removed-remotely' ), 'Forced miss clears runtime stale entry' );
	$raw = 'redis' === $engine ? new Redis() : new Memcached();
	if ( 'redis' === $engine ) { $raw->connect( '127.0.0.1', 6379 ); } else { $raw->addServer( '127.0.0.1', 11211 ); }
	$unrelated = 'other-application-' . getmypid();
	$raw->set( $unrelated, 'must survive', 'redis' === $engine ? array( 'ex' => 20 ) : 20 );
	same( true, wp_cache_flush(), 'Namespace flush succeeds' );
	same( 'must survive', $raw->get( $unrelated ), 'Flush protects unrelated application' );
	$fresh = new WP_Object_Cache();
	same( false, $fresh->get( 'cross-request' ), 'Flush invalidates persistent keys' );
	$other->set( 'local-survivor', 'runtime', 'local-only' );
	same( false, $other->get( 'cross-request', '', true ), 'Existing instance forced read observes remote flush' );
	same( 'runtime', $other->get( 'local-survivor', 'local-only' ), 'Remote generation refresh preserves nonpersistent groups' );
	same( true, $other->set( 'after-remote-flush', 'current' ), 'Existing instance writes after remote flush' );
	same( 'current', $fresh->get( 'after-remote-flush', '', true ), 'Post-flush write reaches active namespace' );
	$other->set( 'remote-counter', 1 );
	$fresh->flush();
	$fresh->set( 'remote-counter', 10 );
	same( 11, $other->incr( 'remote-counter' ), 'Existing counter operation uses remotely rotated namespace' );
	$fresh->flush();
	$fresh->set( 'remote-delete', 'current' );
	same( true, $other->delete( 'remote-delete' ), 'Existing delete operation uses remotely rotated namespace' );
	same( false, $fresh->get( 'remote-delete', '', true ), 'Delete removes entry from active namespace' );
	wp_cache_set( 'cannot-serialize', 'original' );
	same( false, wp_cache_set( 'cannot-serialize', static function () {} ), 'Unserializable persistent write reports failure' );
	same( $engine, $GLOBALS['wp_object_cache']->shc_engine(), 'Serialization error does not disconnect healthy backend' );
	same( 'original', wp_cache_get( 'cannot-serialize', '', true ), 'Failed serialization does not claim to replace persisted data' );
	if ( ! defined( 'SHC_FAKE_BACKENDS' ) && function_exists( 'pcntl_fork' ) ) {
		wp_cache_set( 'parallel-counter', 0 );
		$children = array();
		for ( $worker = 0; $worker < 3; ++$worker ) {
			$pid = pcntl_fork();
			if ( 0 === $pid ) {
				$worker_cache = new WP_Object_Cache();
				for ( $i = 0; $i < 20; ++$i ) {
					$success = false;
					for ( $retry = 0; $retry < 30; ++$retry ) {
						if ( false !== $worker_cache->incr( 'parallel-counter' ) ) { $success = true; break; }
						usleep( 1000 );
					}
					if ( ! $success ) { exit( 1 ); }
				}
				exit( 0 );
			}
			if ( $pid < 0 ) { throw new RuntimeException( 'Could not start concurrency worker.' ); }
			$children[] = $pid;
		}
		foreach ( $children as $pid ) {
			pcntl_waitpid( $pid, $status );
			same( 0, pcntl_wexitstatus( $status ), 'Concurrent worker completed' );
		}
		$reader = new WP_Object_Cache();
		same( 60, $reader->get( 'parallel-counter' ), 'Atomic counter retains all concurrent updates' );
	}
	if ( defined( 'SHC_FAKE_BACKENDS' ) ) {
		if ( 'redis' === $engine ) {
			foreach ( array( 'watch', 'multi' ) as $operation ) {
				$failing = new WP_Object_Cache();
				$failing->set( 'guarded-counter', 7 );
				SHC_Fake_Store::$calls = array();
				SHC_Fake_Store::$fail_operations = array( $operation, 'discard' );
				same( false, $failing->incr( 'guarded-counter' ), 'Rejected Redis transaction setup fails increment' );
				same( 'none', $failing->shc_engine(), 'Rejected Redis transaction opens circuit' );
				same( 1, SHC_Fake_Store::$calls['unwatch'], 'Redis clears WATCH even when DISCARD fails' );
				SHC_Fake_Store::$fail_operations = array();
				same( 7, wp_cache_get( 'guarded-counter', '', true ), 'Rejected Redis transaction does not change counter' );
			}
		} else {
			foreach ( array( 'get_extended', 'cas', 'delete', 'set' ) as $operation ) {
				$failing = new WP_Object_Cache();
				$failing->set( 'outage-entry', 7 );
				SHC_Fake_Store::$calls = array();
				SHC_Fake_Store::$fail_operations = array( $operation );
				if ( 'set' === $operation ) {
					same( true, $failing->set( 'outage-entry', 9 ), 'Memcached connection failure falls back to runtime write' );
				} elseif ( 'delete' === $operation ) {
					same( false, $failing->delete( 'outage-entry' ), 'Failed persistent delete does not report success' );
				} else {
					same( false, $failing->incr( 'outage-entry' ), 'Memcached counter network failure reported' );
				}
				same( 'none', $failing->shc_engine(), 'Memcached nonthrowing network error opens circuit' );
				same( 1, SHC_Fake_Store::$calls[ $operation ], 'Network failure is not retried repeatedly' );
				SHC_Fake_Store::$fail_operations = array();
			}
			SHC_Fake_Store::$fail_operations = array( 'version' );
			same( 'none', ( new WP_Object_Cache() )->shc_engine(), 'False Memcached server version is unavailable' );
			function __( $message, $domain = '' ) { return $message; }
			function apply_filters( $hook, $value, ...$args ) { return $value; }
			require dirname( __DIR__ ) . '/smart-hybrid-cache/includes/class-memcached-client.php';
			$admin_client = new Smart_Hybrid_Cache_Memcached_Client();
			same( false, $admin_client->connect( $options ), 'Admin Memcached client rejects false server version' );
			same( true, '' !== $admin_client->get_last_error(), 'Admin Memcached connection failure explains error' );
			SHC_Fake_Store::$fail_operations = array();
		}
		SHC_Fake_Store::$reject_write = true;
		same( false, wp_cache_set( 'rejected', 'value' ), 'Backend write rejection reported' );
		SHC_Fake_Store::$reject_write = false;
		same( false, wp_cache_get( 'rejected' ), 'Rejected write never becomes local success' );
		SHC_Fake_Store::$fail = true;
		same( false, wp_cache_flush(), 'Backend flush exception contained' );
		same( 'none', $GLOBALS['wp_object_cache']->shc_engine(), 'Backend failure opens circuit' );
		same( true, wp_cache_set( 'fallback', 'working' ), 'Runtime fallback writes' );
		same( 'working', wp_cache_get( 'fallback' ), 'Runtime fallback reads' );
	}
}
same( false, wp_cache_supports( 'flush_group' ), 'Unsupported group flush never advertised' );
echo "Passed $count assertions for $engine" . ( defined( 'SHC_FAKE_BACKENDS' ) ? ' with backend doubles' : '' ) . PHP_EOL;
