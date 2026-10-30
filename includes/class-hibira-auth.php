<?php
/**
 * POST /wp-json/hibira/v1/auth signs private and presence subscriptions.
 *
 * Built-in channels:
 *   presence-hibira-post-{ID}       readers of a published post (Readers now)
 *   private-hibira-order-{ID}       the order's customer, or the order key (WooCommerce)
 *   private-hibira-admin-comments   users who can moderate comments
 *   private-hibira-admin-orders     users who can manage WooCommerce
 *
 * Other plugins and themes decide their own channels with the
 * hibira_authorize_channel filter.
 *
 * @package Hibira
 */

defined( 'ABSPATH' ) || exit;

class Hibira_Auth {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			'hibira/v1',
			'/auth',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'auth' ),
				'permission_callback' => '__return_true', // Each channel is checked in decide().
				'args'                => array(
					'socket_id'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'channel_name' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	public static function auth( WP_REST_Request $request ) {
		$client = hibira();
		if ( ! $client ) {
			return new WP_Error( 'hibira_config', 'Hibira is not configured.', array( 'status' => 503 ) );
		}
		$channel  = (string) $request['channel_name'];
		$decision = self::decide( $channel, $request );
		if ( false === $decision || null === $decision ) {
			return new WP_Error( 'hibira_forbidden', 'Forbidden', array( 'status' => 403 ) );
		}
		$presence = null;
		if ( 0 === strpos( $channel, 'presence-' ) ) {
			if ( ! is_array( $decision ) || empty( $decision['user_id'] ) ) {
				return new WP_Error( 'hibira_forbidden', 'Presence channels need a user ID.', array( 'status' => 403 ) );
			}
			$presence = $decision;
		}
		$signed = $client->authorize_channel( (string) $request['socket_id'], $channel, $presence );
		if ( is_wp_error( $signed ) ) {
			$signed->add_data( array( 'status' => 400 ) );
			return $signed;
		}
		$response = rest_ensure_response( $signed );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Whether the current visitor may subscribe.
	 *
	 * @return bool|array|null True or false for private channels; for presence
	 *                         channels an array with user_id and user_info.
	 */
	public static function decide( $channel, WP_REST_Request $request ) {
		$user     = wp_get_current_user();
		$decision = null;

		if ( preg_match( '/^presence-hibira-post-(\d+)$/', $channel, $m ) ) {
			$post = get_post( (int) $m[1] );
			if ( Hibira_Settings::enabled( 'readers' ) && $post && is_post_publicly_viewable( $post ) && ! post_password_required( $post ) ) {
				$decision = array(
					'user_id'   => self::visitor_id( $user, $request ),
					'user_info' => array(),
				);
			} else {
				$decision = false;
			}
		} elseif ( preg_match( '/^private-hibira-order-(\d+)$/', $channel, $m ) ) {
			$decision = Hibira_Settings::enabled( 'woo_orders' ) && Hibira_WooCommerce::can_watch_order( (int) $m[1], $user, (string) $request['order_key'] );
		} elseif ( 'private-hibira-admin-comments' === $channel ) {
			$decision = Hibira_Settings::enabled( 'admin_notices' ) && user_can( $user, 'moderate_comments' );
		} elseif ( 'private-hibira-admin-orders' === $channel ) {
			$decision = Hibira_Settings::enabled( 'admin_notices' ) && user_can( $user, 'manage_woocommerce' );
		}

		/**
		 * Decides a subscription. Return true or false for a private channel,
		 * an array( 'user_id' => ..., 'user_info' => array() ) to allow a
		 * presence channel, or null to leave it to the plugin (refused unless
		 * it is one of the plugin's channels).
		 *
		 * @param bool|array|null $decision The plugin's decision.
		 * @param string          $channel  The channel name.
		 * @param WP_User         $user     The current user (ID 0 for visitors).
		 * @param WP_REST_Request $request  The auth request.
		 */
		return apply_filters( 'hibira_authorize_channel', $decision, $channel, $user, $request );
	}

	/** A signed-in user's ID, or the random ID the visitor's browser keeps. */
	private static function visitor_id( $user, WP_REST_Request $request ) {
		if ( $user && $user->ID ) {
			return 'u' . $user->ID;
		}
		$visitor = (string) $request['visitor'];
		if ( 1 === preg_match( '/^[A-Za-z0-9-]{8,64}$/', $visitor ) ) {
			return 'v' . $visitor;
		}
		return 'v' . wp_generate_uuid4();
	}
}
