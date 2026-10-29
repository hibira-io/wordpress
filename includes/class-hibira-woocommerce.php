<?php
/**
 * WooCommerce: live order status for the customer, and a new-order notice
 * for shop managers.
 *
 * The customer's order received and view order pages subscribe to
 * private-hibira-order-{ID}; the auth endpoint lets in the order's customer,
 * or a guest who has the order key from the order received URL.
 *
 * @package Hibira
 */

defined( 'ABSPATH' ) || exit;

class Hibira_WooCommerce {

	public static function init() {
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'status_changed' ), 10, 4 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'new_order' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'new_order' ), 10, 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function channel( $order_id ) {
		return 'private-hibira-order-' . (int) $order_id;
	}

	public static function can_watch_order( $order_id, $user, $order_key ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return false;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}
		if ( $user && $user->ID && (int) $order->get_customer_id() === (int) $user->ID ) {
			return true;
		}
		if ( $user && user_can( $user, 'edit_shop_orders' ) ) {
			return true;
		}
		return '' !== $order_key && hash_equals( (string) $order->get_order_key(), $order_key );
	}

	public static function status_changed( $order_id, $from, $to, $order = null ) {
		if ( ! Hibira_Settings::enabled( 'woo_orders' ) ) {
			return;
		}
		hibira_trigger(
			self::channel( $order_id ),
			'status',
			array(
				'status' => $to,
				'label'  => wc_get_order_status_name( $to ),
			),
			false
		);
	}

	public static function new_order( $order ) {
		if ( ! Hibira_Settings::enabled( 'admin_notices' ) ) {
			return;
		}
		$order = is_numeric( $order ) ? wc_get_order( $order ) : $order;
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		hibira_trigger(
			Hibira_Admin_Notices::ORDERS,
			'new-order',
			array(
				'number' => '#' . $order->get_order_number(),
				'total'  => html_entity_decode( wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' ),
				'url'    => $order->get_edit_order_url(),
			),
			false
		);
	}

	public static function enqueue() {
		if ( ! function_exists( 'is_order_received_page' ) || ! Hibira_Settings::enabled( 'woo_orders' ) ) {
			return;
		}
		$order = null;
		$key   = '';
		if ( is_order_received_page() ) {
			$order_id = absint( get_query_var( 'order-received' ) );
			$key      = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WooCommerce's own order received URL.
			$order    = wc_get_order( $order_id );
		} elseif ( is_view_order_page() ) {
			$order = wc_get_order( absint( get_query_var( 'view-order' ) ) );
		}
		if ( ! $order || ! self::can_watch_order( $order->get_id(), wp_get_current_user(), $key ) ) {
			return;
		}
		hibira_register_client_script();
		wp_enqueue_script( 'hibira' );
		wp_enqueue_style( 'hibira', HIBIRA_URL . 'assets/css/hibira.css', array(), HIBIRA_VERSION );
		wp_add_inline_script(
			'hibira',
			'HibiraWP.watchOrder(' . wp_json_encode(
				array(
					'id'  => $order->get_id(),
					'key' => $key,
				)
			) . ');'
		);
	}
}
