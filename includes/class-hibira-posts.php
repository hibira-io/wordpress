<?php
/**
 * Post pages: the "this post was updated" notice and the readers-now count.
 *
 * Each published post has a public channel, hibira-post-{ID}, that carries
 * post-updated and comment events, and (with Readers now on) a presence
 * channel, presence-hibira-post-{ID}, whose member count is the number of
 * open tabs on the post.
 *
 * @package Hibira
 */

defined( 'ABSPATH' ) || exit;

class Hibira_Posts {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'post_updated', array( __CLASS__, 'updated' ), 10, 3 );
		add_shortcode( 'hibira_readers', array( __CLASS__, 'readers_shortcode' ) );
	}

	public static function channel( $post_id ) {
		return 'hibira-post-' . (int) $post_id;
	}

	/** Whether this request shows a single post that gets live features. */
	public static function live_post() {
		if ( ! is_singular() || ! Hibira_Settings::configured() ) {
			return null;
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || post_password_required( $post ) ) {
			return null;
		}
		/**
		 * Turns live features off for some posts.
		 *
		 * @param bool    $live Whether the post gets live features.
		 * @param WP_Post $post The post.
		 */
		return apply_filters( 'hibira_live_post', true, $post ) ? $post : null;
	}

	public static function enqueue() {
		$post = self::live_post();
		if ( ! $post ) {
			return;
		}
		$features = array(
			'postUpdates'  => Hibira_Settings::enabled( 'post_updates' ),
			'liveComments' => Hibira_Settings::enabled( 'live_comments' ) && comments_open( $post ),
			'readers'      => Hibira_Settings::enabled( 'readers' ),
		);
		if ( ! array_filter( $features ) ) {
			return;
		}
		hibira_register_client_script();
		wp_enqueue_script( 'hibira' );
		wp_enqueue_style( 'hibira', HIBIRA_URL . 'assets/css/hibira.css', array(), HIBIRA_VERSION );
		wp_add_inline_script(
			'hibira',
			'HibiraWP.watchPost(' . wp_json_encode(
				array_merge(
					$features,
					array(
						'id'       => $post->ID,
						'modified' => get_post_modified_time( 'U', true, $post ),
					)
				)
			) . ');'
		);
	}

	/** Tells readers when the content or title of a published post changes. */
	public static function updated( $post_id, $after, $before ) {
		if ( wp_is_post_revision( $post_id ) || 'publish' !== $after->post_status || 'publish' !== $before->post_status ) {
			return;
		}
		if ( $after->post_content === $before->post_content && $after->post_title === $before->post_title ) {
			return;
		}
		if ( ! Hibira_Settings::enabled( 'post_updates' ) ) {
			return;
		}
		hibira_trigger(
			self::channel( $post_id ),
			'post-updated',
			array( 'modified' => get_post_modified_time( 'U', true, $after ) ),
			false
		);
	}

	public static function readers_shortcode() {
		return self::readers_html();
	}

	public static function readers_html() {
		if ( ! Hibira_Settings::enabled( 'readers' ) || ! self::live_post() ) {
			return '';
		}
		return '<span class="hibira-readers" data-hibira-readers hidden></span>';
	}
}

/**
 * Prints the readers-now count of the current post (when Readers now is on).
 */
function hibira_readers() {
	echo Hibira_Posts::readers_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
}
