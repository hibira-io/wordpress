<?php
/**
 * A small client for the Pusher Channels HTTP API, as Hibira serves it:
 * signed event triggers, channel queries and channel authorization.
 *
 * It has no dependencies, so the plugin ships without Composer. Requests go
 * through the WordPress HTTP API unless a transport is passed in (tests).
 *
 * @package Hibira
 */

defined( 'ABSPATH' ) || exit;

class Hibira_Client {

	/** Hibira refuses events with more channels than this. */
	const MAX_CHANNELS = 100;

	/** Hibira refuses event data larger than this, in bytes. */
	const MAX_DATA = 10240;

	private $app_id;
	private $key;
	private $secret;
	private $host;
	private $port;
	private $tls;

	/** @var callable|null fn( string $method, string $url, array $headers, string $body, bool $blocking ): array{0:int,1:string} */
	private $transport;

	public function __construct( $app_id, $key, $secret, $host = 'ws.hibira.io', $port = 443, $tls = true, $transport = null ) {
		$this->app_id    = (string) $app_id;
		$this->key       = (string) $key;
		$this->secret    = (string) $secret;
		$this->host      = (string) $host;
		$this->port      = (int) $port;
		$this->tls       = (bool) $tls;
		$this->transport = $transport;
	}

	public function key() {
		return $this->key;
	}

	/**
	 * Sends one event to up to 100 channels.
	 *
	 * @param string|string[] $channels  Channel names.
	 * @param string          $event     Event name.
	 * @param mixed           $data      Anything json_encode accepts; a string is sent as is.
	 * @param string|null     $socket_id Leave this connection out (the sender's own tab).
	 * @param bool            $blocking  False returns at once without waiting for the answer.
	 * @return true|WP_Error
	 */
	public function trigger( $channels, $event, $data, $socket_id = null, $blocking = true ) {
		$channels = array_values( (array) $channels );
		if ( count( $channels ) === 0 || count( $channels ) > self::MAX_CHANNELS ) {
			return $this->error( 'hibira_channels', 'An event needs 1 to 100 channels.' );
		}
		foreach ( $channels as $channel ) {
			if ( ! self::valid_channel( $channel ) ) {
				return $this->error( 'hibira_channel', sprintf( 'Invalid channel name: %s', $channel ) );
			}
		}
		$payload = is_string( $data ) ? $data : wp_json_encode( $data );
		if ( strlen( $payload ) > self::MAX_DATA ) {
			return $this->error( 'hibira_size', 'Event data is larger than 10 KB.' );
		}
		$body = array(
			'name'     => (string) $event,
			'data'     => $payload,
			'channels' => $channels,
		);
		if ( null !== $socket_id && '' !== $socket_id ) {
			if ( ! self::valid_socket_id( $socket_id ) ) {
				return $this->error( 'hibira_socket', 'Invalid socket ID.' );
			}
			$body['socket_id'] = $socket_id;
		}
		$res = $this->request( 'POST', '/events', array(), wp_json_encode( $body ), $blocking );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return true;
	}

	/**
	 * GET on the app's HTTP API, such as /channels or /channels/{name}.
	 *
	 * @return array|WP_Error The decoded JSON answer.
	 */
	public function get( $path, $params = array() ) {
		$res = $this->request( 'GET', $path, $params, '', true );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$json = json_decode( $res, true );
		return is_array( $json ) ? $json : array();
	}

	/**
	 * Signs a private or presence subscription for pusher-js.
	 *
	 * @param string     $socket_id    From the request.
	 * @param string     $channel      From the request.
	 * @param array|null $presence     For presence channels: user_id and user_info.
	 * @return array|WP_Error The JSON body pusher-js expects.
	 */
	public function authorize_channel( $socket_id, $channel, $presence = null ) {
		if ( ! self::valid_socket_id( $socket_id ) ) {
			return $this->error( 'hibira_socket', 'Invalid socket ID.' );
		}
		if ( ! self::valid_channel( $channel ) ) {
			return $this->error( 'hibira_channel', 'Invalid channel name.' );
		}
		$to_sign = $socket_id . ':' . $channel;
		$out     = array();
		if ( null !== $presence ) {
			$channel_data = wp_json_encode(
				array(
					'user_id'   => (string) $presence['user_id'],
					'user_info' => isset( $presence['user_info'] ) ? (object) $presence['user_info'] : new stdClass(),
				)
			);
			$to_sign            .= ':' . $channel_data;
			$out['channel_data'] = $channel_data;
		}
		$out['auth'] = $this->key . ':' . hash_hmac( 'sha256', $to_sign, $this->secret );
		return $out;
	}

	/**
	 * The query string the HTTP API expects: auth_* fields and an HMAC-SHA256
	 * signature of "METHOD\nPATH\nsorted query".
	 */
	public function signed_query( $method, $path, $params, $body, $timestamp = null ) {
		$params['auth_key']       = $this->key;
		$params['auth_timestamp'] = null === $timestamp ? time() : (int) $timestamp;
		$params['auth_version']   = '1.0';
		if ( '' !== $body ) {
			$params['body_md5'] = md5( $body );
		}
		ksort( $params );
		$pairs = array();
		foreach ( $params as $k => $v ) {
			$pairs[] = $k . '=' . $v;
		}
		$query                    = implode( '&', $pairs );
		$params['auth_signature'] = hash_hmac( 'sha256', strtoupper( $method ) . "\n" . $path . "\n" . $query, $this->secret );
		return $params;
	}

	private function request( $method, $path, $params, $body, $blocking ) {
		if ( '' === $this->app_id || '' === $this->key || '' === $this->secret ) {
			return $this->error( 'hibira_config', 'Hibira is not configured: set the app ID, key and secret.' );
		}
		$full  = '/apps/' . rawurlencode( $this->app_id ) . $path;
		$query = $this->signed_query( $method, $full, $params, $body );
		$base  = ( $this->tls ? 'https://' : 'http://' ) . $this->host;
		if ( ( $this->tls && 443 !== $this->port ) || ( ! $this->tls && 80 !== $this->port ) ) {
			$base .= ':' . $this->port;
		}
		$url     = $base . $full . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		$headers = array( 'Content-Type' => 'application/json' );

		if ( $this->transport ) {
			list( $status, $text ) = call_user_func( $this->transport, $method, $url, $headers, $body, $blocking );
		} else {
			$res = wp_remote_request(
				$url,
				array(
					'method'   => $method,
					'headers'  => $headers,
					'body'     => '' === $body ? null : $body,
					'timeout'  => $blocking ? 5 : 1,
					'blocking' => $blocking,
				)
			);
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			if ( ! $blocking ) {
				return '';
			}
			$status = (int) wp_remote_retrieve_response_code( $res );
			$text   = (string) wp_remote_retrieve_body( $res );
		}
		if ( ! $blocking ) {
			return '';
		}
		if ( $status < 200 || $status >= 300 ) {
			return $this->error( 'hibira_http', sprintf( 'Hibira answered %d: %s', $status, trim( substr( $text, 0, 200 ) ) ), $status );
		}
		return $text;
	}

	public static function valid_socket_id( $socket_id ) {
		return is_string( $socket_id ) && 1 === preg_match( '/^\d+\.\d+$/', $socket_id );
	}

	public static function valid_channel( $channel ) {
		return is_string( $channel ) && 1 === preg_match( '/^[A-Za-z0-9_\-=@,.;]{1,164}$/', $channel );
	}

	private function error( $code, $message, $status = 0 ) {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
