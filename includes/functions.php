<?php
/**
 * Public functions for themes and other plugins.
 *
 * @package Hibira
 */

defined( 'ABSPATH' ) || exit;

/**
 * The HTTP API client, or null until the app ID, key and secret are set.
 *
 * @return Hibira_Client|null
 */
function hibira() {
	static $client = null;
	static $signature = null;
	$s   = Hibira_Settings::get();
	$sig = md5( wp_json_encode( array( $s['app_id'], $s['key'], $s['secret'], $s['host'], $s['port'], $s['tls'] ) ) );
	if ( $sig !== $signature ) {
		$signature = $sig;
		$client    = Hibira_Settings::configured()
			? new Hibira_Client( $s['app_id'], $s['key'], $s['secret'], $s['host'], $s['port'], $s['tls'] )
			: null;
	}
	return $client;
}

/**
 * Sends an event to one or more channels.
 *
 *     hibira_trigger( 'scores', 'goal', array( 'team' => 'home' ) );
 *
 * Listen in the browser with the `hibira` script handle:
 *
 *     HibiraWP.pusher().subscribe('scores').bind('goal', (d) => ...);
 *
 * @param string|string[] $channels Channel names (public, private-, presence-).
 * @param string          $event    Event name.
 * @param mixed           $data     Event data, JSON encoded (10 KB at most).
 * @param bool            $blocking False sends without waiting for Hibira's answer.
 * @return true|WP_Error
 */
function hibira_trigger( $channels, $event, $data = array(), $blocking = true ) {
	$client = hibira();
	if ( ! $client ) {
		return new WP_Error( 'hibira_config', __( 'Hibira is not configured.', 'hibira' ) );
	}
	$result = $client->trigger( $channels, $event, $data, null, $blocking );
	if ( is_wp_error( $result ) ) {
		/**
		 * Fires when an event could not be sent.
		 *
		 * @param WP_Error        $error
		 * @param string|string[] $channels
		 * @param string          $event
		 */
		do_action( 'hibira_trigger_failed', $result, $channels, $event );
	}
	return $result;
}

// do_action( 'hibira_trigger', $channels, $event, $data ) for code that should
// not fail when this plugin is off.
add_action(
	'hibira_trigger',
	function ( $channels, $event, $data = array() ) {
		hibira_trigger( $channels, $event, $data, false );
	},
	10,
	3
);

/**
 * Registers the browser client (pusher-js and the plugin's helper) once.
 */
function hibira_register_client_script() {
	if ( wp_script_is( 'hibira', 'registered' ) ) {
		return;
	}
	$s = Hibira_Settings::get();
	wp_register_script( 'pusher-js', HIBIRA_URL . 'assets/vendor/pusher.min.js', array(), '8.6.0', true );
	wp_register_script( 'hibira', HIBIRA_URL . 'assets/js/hibira.js', array( 'pusher-js' ), HIBIRA_VERSION, true );
	wp_localize_script(
		'hibira',
		'HibiraConfig',
		array(
			'key'      => $s['key'],
			'host'     => $s['host'],
			'port'     => (int) $s['port'],
			'tls'      => (bool) $s['tls'],
			'authUrl'  => esc_url_raw( rest_url( 'hibira/v1/auth' ) ),
			// Only signed-in users send a nonce: a cached page can hold a stale
			// one, which the REST API refuses even for visitors.
			'nonce'    => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'i18n'     => array(
				'updated'     => __( 'This post was updated.', 'hibira' ),
				'reload'      => __( 'Reload', 'hibira' ),
				'newComment'  => __( 'New comment', 'hibira' ),
				/* translators: %s: number of readers */
				'readers'     => __( '%s reading now', 'hibira' ),
				'readersMany' => __( '100+ reading now', 'hibira' ),
				/* translators: %s: order status name */
				'orderStatus' => __( 'Order status: %s', 'hibira' ),
				/* translators: %s: order number */
				'newOrder'    => __( 'New order %s', 'hibira' ),
				/* translators: %s: post title */
				'pending'     => __( 'New comment awaiting approval on "%s"', 'hibira' ),
				'view'        => __( 'View', 'hibira' ),
				'dismiss'     => __( 'Dismiss', 'hibira' ),
			),
		)
	);
}
