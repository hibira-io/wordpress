<?php
// php tests/run.php — tests for the HTTP API client, with no dependencies.
// The signatures are the worked examples in Pusher's HTTP API and channel
// authorization docs, which Hibira follows.

require __DIR__ . '/bootstrap.php';

$failures = 0;
$count    = 0;
function check( $name, $ok, $detail = '' ) {
	global $failures, $count;
	$count++;
	if ( ! $ok ) {
		$failures++;
		fwrite( STDERR, "FAIL {$name}" . ( $detail ? ": {$detail}" : '' ) . "\n" );
	}
}

$key    = '278d425bdf160c739803';
$secret = '7ad3773142a6692b25b8';

// Signed request (docs example).
$c    = new Hibira_Client( '3', $key, $secret );
$body = '{"name":"foo","channels":["project-3"],"data":"{\"some\":\"data\"}"}';
$q    = $c->signed_query( 'POST', '/apps/3/events', array(), $body, 1353088179 );
check( 'body_md5', 'ec365a775a4cd0599faeb73354201b6f' === $q['body_md5'], $q['body_md5'] );
check( 'auth_signature', 'da454824c97ba181a32ccc17a72625ba02771f50b50e1e7430e47a1f3f457e6c' === $q['auth_signature'], $q['auth_signature'] );

// Private channel (docs example).
$a = $c->authorize_channel( '1234.1234', 'private-foobar' );
check( 'private auth', $key . ':58df8b0c36d6982b82c3ecf6b4662e34fe8c25bba48f5369f135bf843651c3a4' === $a['auth'], $a['auth'] );
check( 'private no channel_data', ! isset( $a['channel_data'] ) );

// Presence channel: channel_data is signed as sent.
$p    = $c->authorize_channel( '1234.1234', 'presence-foobar', array( 'user_id' => 10, 'user_info' => array( 'name' => 'Mr. Channels' ) ) );
$data = '{"user_id":"10","user_info":{"name":"Mr. Channels"}}';
check( 'presence channel_data', $data === $p['channel_data'], $p['channel_data'] );
check( 'presence auth', $key . ':' . hash_hmac( 'sha256', '1234.1234:presence-foobar:' . $data, $secret ) === $p['auth'] );
$empty = $c->authorize_channel( '1.2', 'presence-x', array( 'user_id' => 'v1' ) );
check( 'presence empty user_info is an object', '{"user_id":"v1","user_info":{}}' === $empty['channel_data'], $empty['channel_data'] );

// Validation.
check( 'bad socket id', is_wp_error( $c->authorize_channel( '1234', 'private-x' ) ) );
check( 'bad channel', is_wp_error( $c->authorize_channel( '1.1', 'private x' ) ) );
check( 'no channels', is_wp_error( $c->trigger( array(), 'e', array() ) ) );
check( 'too many channels', is_wp_error( $c->trigger( array_fill( 0, 101, 'a' ), 'e', array() ) ) );
check( 'too big', is_wp_error( $c->trigger( 'a', 'e', str_repeat( 'x', 10241 ) ) ) );
check( 'not configured', 'hibira_config' === ( new Hibira_Client( '', '', '' ) )->trigger( 'a', 'e', 1 )->get_error_code() );

// Requests: URL, query and body as the server sees them.
$seen = null;
$t    = new Hibira_Client(
	'app-1',
	'k',
	's',
	'localhost',
	8080,
	false,
	function ( $method, $url, $headers, $body, $blocking ) use ( &$seen ) {
		$seen = compact( 'method', 'url', 'headers', 'body', 'blocking' );
		return array( 200, '{"channels":{"a":{}}}' );
	}
);
check( 'trigger ok', true === $t->trigger( array( 'a', 'b' ), 'hello', array( 'x' => 1 ), '1.2' ) );
check( 'trigger url', 0 === strpos( $seen['url'], 'http://localhost:8080/apps/app-1/events?' ), $seen['url'] );
$sent = json_decode( $seen['body'], true );
check( 'trigger body', array( 'name' => 'hello', 'data' => '{"x":1}', 'channels' => array( 'a', 'b' ), 'socket_id' => '1.2' ) === $sent, $seen['body'] );
parse_str( parse_url( $seen['url'], PHP_URL_QUERY ), $query );
$expect = hash_hmac( 'sha256', "POST\n/apps/app-1/events\nauth_key=k&auth_timestamp={$query['auth_timestamp']}&auth_version=1.0&body_md5=" . md5( $seen['body'] ), 's' );
check( 'trigger signature', $expect === $query['auth_signature'] );
check( 'get', array( 'channels' => array( 'a' => array() ) ) === $t->get( '/channels', array( 'filter_by_prefix' => 'presence-' ) ) );
check( 'get query', false !== strpos( $seen['url'], 'filter_by_prefix=presence-' ) && '' === $seen['body'] );
$https = new Hibira_Client( 'a', 'k', 's', 'ws.hibira.io', 443, true, function ( $m, $url ) use ( &$seen ) {
	$seen = $url;
	return array( 401, 'Unauthorized' );
} );
$err = $https->trigger( 'a', 'e', 1 );
check( 'default port left out', 0 === strpos( $seen, 'https://ws.hibira.io/apps/a/events?' ), $seen );
check( 'http error', is_wp_error( $err ) && 'hibira_http' === $err->get_error_code() && 401 === $err->get_error_data()['status'] );

echo $failures ? "{$failures} of {$count} failed\n" : "{$count} passed\n";
exit( $failures ? 1 : 0 );
