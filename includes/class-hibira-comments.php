<?php
/**
 * Live comments: an approved comment goes to hibira-post-{ID} as a
 * comment-approved event, and readers' browsers add it to the comment list.
 *
 * @package Hibira
 */

defined( 'ABSPATH' ) || exit;

class Hibira_Comments {

	public static function init() {
		add_action( 'comment_post', array( __CLASS__, 'posted' ), 10, 2 );
		add_action( 'transition_comment_status', array( __CLASS__, 'transition' ), 10, 3 );
	}

	public static function posted( $comment_id, $approved ) {
		if ( 1 === $approved || '1' === $approved ) {
			self::send( get_comment( $comment_id ) );
		}
	}

	public static function transition( $new_status, $old_status, $comment ) {
		if ( 'approved' === $new_status && 'approved' !== $old_status ) {
			self::send( $comment );
		}
	}

	/** The event data: what a reader's browser needs to show the comment. */
	public static function payload( WP_Comment $comment ) {
		$data = array(
			'id'      => (int) $comment->comment_ID,
			'parent'  => (int) $comment->comment_parent,
			'author'  => get_comment_author( $comment ),
			'url'     => get_comment_author_url( $comment ),
			'avatar'  => get_avatar_url( $comment, array( 'size' => 64 ) ),
			'date'    => get_comment_date( '', $comment ) . ' ' . get_comment_time( '', false, true, $comment ),
			'iso'     => get_comment_date( 'c', $comment ),
			'link'    => get_comment_link( $comment ),
			// Comment text is filtered by kses when saved; comment_text adds the formatting.
			'html'    => apply_filters( 'comment_text', get_comment_text( $comment ), $comment, array() ),
		);
		/**
		 * Changes the data sent for a live comment. Keep it under 10 KB.
		 *
		 * @param array      $data
		 * @param WP_Comment $comment
		 */
		return apply_filters( 'hibira_comment_payload', $data, $comment );
	}

	private static function send( $comment ) {
		if ( ! $comment instanceof WP_Comment || ! Hibira_Settings::enabled( 'live_comments' ) ) {
			return;
		}
		$post = get_post( $comment->comment_post_ID );
		if ( ! $post || 'publish' !== $post->post_status || post_password_required( $post ) ) {
			return;
		}
		if ( ! in_array( $comment->comment_type, array( '', 'comment' ), true ) ) {
			return;
		}
		$data = self::payload( $comment );
		if ( strlen( wp_json_encode( $data ) ) > Hibira_Client::MAX_DATA ) {
			// Too long to carry: readers get a notice with a link instead.
			$data['html'] = '';
		}
		hibira_trigger( Hibira_Posts::channel( $post->ID ), 'comment-approved', $data, false );
	}
}
