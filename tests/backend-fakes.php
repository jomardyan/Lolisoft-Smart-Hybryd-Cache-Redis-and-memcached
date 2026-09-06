<?php
/** Deterministic backend doubles for local failure-path coverage. CI uses real servers. */
define( 'SHC_FAKE_BACKENDS', true );
class SHC_Fake_Store {
	public static array $values = array();
	public static bool $fail = false;
	public static bool $reject_write = false;
	public static array $fail_operations = array();
	public static array $calls = array();
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
	public function watch( ...$args ) { return ! in_array( 'watch', self::$fail_operations, true ); }
	public function unwatch() { self::$calls['unwatch'] = ( self::$calls['unwatch'] ?? 0 ) + 1; return true; }
	public function multi() { if ( in_array( 'multi', self::$fail_operations, true ) ) { return false; } $this->queue = array(); return $this; }
	public function exec() { $queue = $this->queue; $this->queue = null; return array_map( fn( $args ) => $this->set( ...$args ), $queue ); }
	public function discard() { if ( in_array( 'discard', self::$fail_operations, true ) ) { throw new RuntimeException( 'DISCARD without MULTI' ); } $this->queue = null; return true; }
}
class Memcached extends SHC_Fake_Store {
	public const OPT_CONNECT_TIMEOUT = 1, OPT_POLL_TIMEOUT = 2, OPT_RECV_TIMEOUT = 3, OPT_SEND_TIMEOUT = 4, GET_EXTENDED = 5;
	public const RES_SUCCESS = 0, RES_NOTFOUND = 16, RES_NOTSTORED = 14, RES_DATA_EXISTS = 12, RES_E2BIG = 37, RES_CONNECTION_FAILURE = 3;
	private int $result_code = self::RES_SUCCESS;
	public function __construct( ...$args ) {}
	public function setOption( ...$args ) { return true; }
	public function getServerList() { return array(); }
	public function addServer( ...$args ) { return true; }
	public function getVersion() { return array( '127.0.0.1' => in_array( 'version', self::$fail_operations, true ) ? false : '1.6.0' ); }
	public function getResultCode() { return $this->result_code; }
	private function failed( $operation ) {
		self::$calls[ $operation ] = ( self::$calls[ $operation ] ?? 0 ) + 1;
		if ( in_array( $operation, self::$fail_operations, true ) ) { $this->result_code = self::RES_CONNECTION_FAILURE; return true; }
		return false;
	}
	public function get( $key, $callback = null, $flags = 0 ) {
		if ( $this->failed( self::GET_EXTENDED === $flags ? 'get_extended' : 'get' ) ) { return false; }
		$value = $this->read( $key );
		$this->result_code = false === $value ? self::RES_NOTFOUND : self::RES_SUCCESS;
		return self::GET_EXTENDED === $flags && false !== $value ? array( 'value' => $value, 'cas' => self::$values[ $key ][2] ) : $value;
	}
	private function store( $mode, $key, $value, $ttl ) {
		if ( $this->failed( $mode ) ) { return false; }
		$result = $this->write( $key, $value, $ttl > 2592000 ? $ttl - time() : $ttl, $mode );
		$this->result_code = $result ? self::RES_SUCCESS : ( self::$reject_write ? self::RES_E2BIG : self::RES_NOTSTORED );
		return $result;
	}
	public function set( $key, $value, $ttl = 0 ) { return $this->store( 'set', $key, $value, $ttl ); }
	public function add( $key, $value, $ttl = 0 ) { return $this->store( 'add', $key, $value, $ttl ); }
	public function replace( $key, $value, $ttl = 0 ) { return $this->store( 'replace', $key, $value, $ttl ); }
	public function delete( $key ) {
		if ( $this->failed( 'delete' ) ) { return false; }
		$result = parent::delete( $key );
		$this->result_code = $result ? self::RES_SUCCESS : self::RES_NOTFOUND;
		return $result;
	}
	public function cas( $cas, $key, $value, $ttl = 0 ) {
		if ( $this->failed( 'cas' ) ) { return false; }
		if ( ( self::$values[ $key ][2] ?? null ) !== $cas ) { $this->result_code = self::RES_DATA_EXISTS; return false; }
		return $this->set( $key, $value, $ttl );
	}
}
