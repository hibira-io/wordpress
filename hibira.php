<?php
/**
 * Plugin Name:       Hibira Realtime
 * Plugin URI:        https://github.com/hibira-io/wordpress
 * Description:       Live comments, "this post was updated" notices, a readers-now count and live WooCommerce order status, on Hibira's Pusher-compatible realtime service.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Hibira
 * Author URI:        https://hibira.io/?utm_source=wordpress&utm_medium=plugin&utm_campaign=plugins
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hibira
 * Domain Path:       /languages
 *
 * @package Hibira
 */

defined( 'ABSPATH' ) || exit;

define( 'HIBIRA_VERSION', '0.1.0' );
define( 'HIBIRA_FILE', __FILE__ );
define( 'HIBIRA_DIR', plugin_dir_path( __FILE__ ) );
define( 'HIBIRA_URL', plugin_dir_url( __FILE__ ) );

require_once HIBIRA_DIR . 'includes/class-hibira-client.php';
require_once HIBIRA_DIR . 'includes/class-hibira-settings.php';
require_once HIBIRA_DIR . 'includes/functions.php';
require_once HIBIRA_DIR . 'includes/class-hibira-auth.php';
require_once HIBIRA_DIR . 'includes/class-hibira-posts.php';
require_once HIBIRA_DIR . 'includes/class-hibira-comments.php';
require_once HIBIRA_DIR . 'includes/class-hibira-admin-notices.php';
require_once HIBIRA_DIR . 'includes/class-hibira-woocommerce.php';

add_action(
	'init',
	function () {
		load_plugin_textdomain( 'hibira', false, dirname( plugin_basename( HIBIRA_FILE ) ) . '/languages' );
	}
);

Hibira_Settings::init();
Hibira_Auth::init();
Hibira_Posts::init();
Hibira_Comments::init();
Hibira_Admin_Notices::init();
Hibira_WooCommerce::init();

add_filter(
	'plugin_action_links_' . plugin_basename( HIBIRA_FILE ),
	function ( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=hibira' ) ) . '">' . esc_html__( 'Settings', 'hibira' ) . '</a>' );
		return $links;
	}
);
