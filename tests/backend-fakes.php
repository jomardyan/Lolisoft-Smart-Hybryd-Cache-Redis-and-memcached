<?php
/** Deterministic backend doubles for local failure-path coverage. CI uses real servers. */
define( 'SHC_FAKE_BACKENDS', true );
class SHC_Fake_Store {
	public static array $values = array();
	public static bool $fail = false;
	public static bool $reject_write = false;
	public static int $cas = 1;
	public function read( $key ) {
		if ( self::$fail ) { throw new RuntimeException( 'Simulated outage' ); }
		$entry = self::$values[ $key ] ?? null;
		if ( ! $entry || ( $entry[1] && $entry[1] <= time() ) ) { unset( self::$values[ $key ] ); return false; }
		return $entry[0];
	}
	public function write( $key, $value, $ttl = 0, $mode = 'set' ) {
		if ( self::$fail ) { throw new RuntimeException( 'Simulated outage' ); }
		if ( self::$reject_write ) { return false; }
		$exists = false !== $this->read( $key );
		if ( ( 'add' === $mode && $exists ) || ( 'replace' === $mode && ! $exists ) ) { return false; }
		self::$values[ $key ] = array( $value, $ttl ? time() + $ttl : 0, ++self::$cas );
		return true;
	}
	public function delete( $key ) { $found = false !== $this->read( $key ); unset( self::$values[ $key ] ); return $found; }
}
class Redis extends SHC_Fake_Store {
	public const OPT_SERIALIZER = 1, SERIALIZER_NONE = 0, OPT_PREFIX = 2;
	private ?array $queue = null;
	public function connect( ...$args ) { return true; }
	public function pconnect( ...$args ) { return true; }
	public function auth( ...$args ) { return true; }
	public function select( ...$args ) { return true; }
	public function setOption( ...$args ) { return true; }
	public function get( $key ) { return $this->read( $key ); }
	public function set( $key, $value, $args = array() ) {
		if ( null !== $this->queue ) { $this->queue[] = array( $key, $value, $args ); return $this; }
		return $this->write( $key, $value, $args['ex'] ?? 0, in_array( 'nx', $args, true ) ? 'add' : ( in_array( 'xx', $args, true ) ? 'replace' : 'set' ) );
	}
	public function del( $key ) { return (int) $this->delete( $key ); }
	public function watch( ...$args ) { return true; }
	public function unwatch() { return true; }
	public function multi() { $this->queue = array(); return $this; }
	public function exec() { $queue = $this->queue; $this->queue = null; return array_map( fn( $args ) => $this->set( ...$args ), $queue ); }
	public function discard() { $this->queue = null; return true; }
}
class Memcached extends SHC_Fake_Store {
	public const OPT_CONNECT_TIMEOUT = 1, OPT_POLL_TIMEOUT = 2, OPT_RECV_TIMEOUT = 3, OPT_SEND_TIMEOUT = 4, GET_EXTENDED = 5;
	public function __construct( ...$args ) {}
	public function setOption( ...$args ) { return true; }
	public function getServerList() { return array(); }
	public function addServer( ...$args ) { return true; }
	public function getVersion() { return array( '127.0.0.1' => '1.6.0' ); }
	public function get( $key, $callback = null, $flags = 0 ) { $value = $this->read( $key ); return self::GET_EXTENDED === $flags && false !== $value ? array( 'value' => $value, 'cas' => self::$values[ $key ][2] ) : $value; }
	public function set( $key, $value, $ttl = 0 ) { return $this->write( $key, $value, $ttl > 2592000 ? $ttl - time() : $ttl ); }
	public function add( $key, $value, $ttl = 0 ) { return $this->write( $key, $value, $ttl > 2592000 ? $ttl - time() : $ttl, 'add' ); }
	public function replace( $key, $value, $ttl = 0 ) { return $this->write( $key, $value, $ttl > 2592000 ? $ttl - time() : $ttl, 'replace' ); }
	public function cas( $cas, $key, $value, $ttl = 0 ) { return ( self::$values[ $key ][2] ?? null ) === $cas && $this->set( $key, $value, $ttl ); }
}
