<?php
/**
 * Admin notices: a toast in wp-admin when a comment awaits approval, and
 * (with WooCommerce) when an order comes in.
 *
 * @package Hibira
 */

defined( 'ABSPATH' ) || exit;

class Hibira_Admin_Notices {

	const COMMENTS = 'private-hibira-admin-comments';
	const ORDERS   = 'private-hibira-admin-orders';

	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'comment_post', array( __CLASS__, 'comment' ), 10, 2 );
	}

	public static function enqueue() {
		if ( ! Hibira_Settings::enabled( 'admin_notices' ) ) {
			return;
		}
		$channels = array();
		if ( current_user_can( 'moderate_comments' ) ) {
			$channels[] = self::COMMENTS;
		}
		if ( class_exists( 'WooCommerce' ) && current_user_can( 'manage_woocommerce' ) ) {
			$channels[] = self::ORDERS;
		}
		if ( ! $channels ) {
			return;
		}
		hibira_register_client_script();
		wp_enqueue_script( 'hibira' );
		wp_enqueue_style( 'hibira', HIBIRA_URL . 'assets/css/hibira.css', array(), HIBIRA_VERSION );
		wp_add_inline_script( 'hibira', 'HibiraWP.watchAdmin(' . wp_json_encode( $channels ) . ');' );
	}

	public static function comment( $comment_id, $approved ) {
		if ( 0 !== $approved && '0' !== $approved ) {
			return;
		}
		if ( ! Hibira_Settings::enabled( 'admin_notices' ) ) {
			return;
		}
		$comment = get_comment( $comment_id );
		if ( ! $comment ) {
			return;
		}
		hibira_trigger(
			self::COMMENTS,
			'comment-pending',
			array(
				'post' => wp_strip_all_tags( get_the_title( $comment->comment_post_ID ) ),
				'url'  => admin_url( 'edit-comments.php?comment_status=moderated' ),
			),
			false
		);
	}
}
