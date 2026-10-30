<?php
/**
 * Settings → Hibira: credentials, features and a connection test.
 *
 * The app ID, key and secret can also be set in wp-config.php
 * (HIBIRA_APP_ID, HIBIRA_APP_KEY, HIBIRA_APP_SECRET, HIBIRA_HOST), which
 * keeps the secret out of the database; constants win over the form.
 *
 * @package Hibira
 */

defined( 'ABSPATH' ) || exit;

class Hibira_Settings {

	const OPTION = 'hibira_settings';

	const CONSTANTS = array(
		'app_id' => 'HIBIRA_APP_ID',
		'key'    => 'HIBIRA_APP_KEY',
		'secret' => 'HIBIRA_APP_SECRET',
		'host'   => 'HIBIRA_HOST',
	);

	public static function defaults() {
		return array(
			'app_id'        => '',
			'key'           => '',
			'secret'        => '',
			'host'          => 'ws.hibira.io',
			'port'          => 443,
			'tls'           => true,
			'live_comments' => true,
			'post_updates'  => true,
			'readers'       => false,
			'woo_orders'    => true,
			'admin_notices' => true,
		);
	}

	/** The saved settings with constants applied. */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		$s     = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		foreach ( self::CONSTANTS as $field => $constant ) {
			if ( defined( $constant ) && '' !== constant( $constant ) ) {
				$s[ $field ] = (string) constant( $constant );
			}
		}
		if ( defined( 'HIBIRA_PORT' ) ) {
			$s['port'] = (int) HIBIRA_PORT;
		}
		if ( defined( 'HIBIRA_TLS' ) ) {
			$s['tls'] = (bool) HIBIRA_TLS;
		}
		return $s;
	}

	public static function configured() {
		$s = self::get();
		return '' !== $s['app_id'] && '' !== $s['key'] && '' !== $s['secret'];
	}

	public static function enabled( $feature ) {
		$s = self::get();
		return self::configured() && ! empty( $s[ $feature ] );
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_hibira_test', array( __CLASS__, 'test' ) );
	}

	public static function menu() {
		add_options_page( 'Hibira', 'Hibira', 'manage_options', 'hibira', array( __CLASS__, 'page' ) );
	}

	public static function register() {
		register_setting(
			'hibira',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function sanitize( $in ) {
		$in  = is_array( $in ) ? $in : array();
		$old = get_option( self::OPTION, array() );
		$out = self::defaults();
		foreach ( array( 'app_id', 'key', 'host' ) as $f ) {
			$out[ $f ] = isset( $in[ $f ] ) ? sanitize_text_field( wp_unslash( $in[ $f ] ) ) : '';
		}
		if ( '' === $out['host'] ) {
			$out['host'] = 'ws.hibira.io';
		}
		// An empty secret field keeps the saved secret; the form never shows it.
		$secret        = isset( $in['secret'] ) ? trim( (string) wp_unslash( $in['secret'] ) ) : '';
		$out['secret'] = '' !== $secret ? $secret : ( isset( $old['secret'] ) ? (string) $old['secret'] : '' );
		$out['port']   = isset( $in['port'] ) ? max( 1, min( 65535, (int) $in['port'] ) ) : 443;
		foreach ( array( 'tls', 'live_comments', 'post_updates', 'readers', 'woo_orders', 'admin_notices' ) as $f ) {
			$out[ $f ] = ! empty( $in[ $f ] );
		}
		return $out;
	}

	/** Sends a test event and lists channels, which checks the ID, key and secret. */
	public static function test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'hibira' ), 403 );
		}
		check_admin_referer( 'hibira_test' );
		$result = hibira_trigger( 'hibira-test', 'test', array( 'from' => 'wordpress', 'at' => time() ) );
		if ( ! is_wp_error( $result ) ) {
			$result = hibira()->get( '/channels' );
		}
		$notice = is_wp_error( $result )
			? array( 'error', $result->get_error_message() )
			: array( 'success', __( 'Connected. A test event was sent to the channel "hibira-test"; you can see it in the debug console of your app in the Hibira portal.', 'hibira' ) );
		set_transient( 'hibira_test_' . get_current_user_id(), $notice, 60 );
		wp_safe_redirect( admin_url( 'options-general.php?page=hibira' ) );
		exit;
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s      = self::get();
		$saved  = get_option( self::OPTION, array() );
		$notice = get_transient( 'hibira_test_' . get_current_user_id() );
		delete_transient( 'hibira_test_' . get_current_user_id() );
		$name = self::OPTION;
		$lock = function ( $field ) {
			return isset( self::CONSTANTS[ $field ] ) && defined( self::CONSTANTS[ $field ] );
		};
		?>
		<div class="wrap">
			<h1>Hibira</h1>
			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice[0] ); ?> is-dismissible"><p><?php echo esc_html( $notice[1] ); ?></p></div>
			<?php endif; ?>
			<p>
				<?php
				printf(
					/* translators: %s: link to the Hibira portal */
					esc_html__( 'Create a free app at %s (no card needed), then copy its app ID, key and secret here.', 'hibira' ),
					'<a href="https://hibira.io/portal?utm_source=wordpress&amp;utm_medium=plugin&amp;utm_campaign=plugins" target="_blank" rel="noopener">hibira.io</a>'
				);
				?>
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'hibira' ); ?>
				<h2><?php esc_html_e( 'Connection', 'hibira' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="hibira-app-id"><?php esc_html_e( 'App ID', 'hibira' ); ?></label></th>
						<td><input id="hibira-app-id" class="regular-text" name="<?php echo esc_attr( $name ); ?>[app_id]" value="<?php echo esc_attr( $s['app_id'] ); ?>" <?php disabled( $lock( 'app_id' ) ); ?>></td>
					</tr>
					<tr>
						<th scope="row"><label for="hibira-key"><?php esc_html_e( 'Key', 'hibira' ); ?></label></th>
						<td><input id="hibira-key" class="regular-text" name="<?php echo esc_attr( $name ); ?>[key]" value="<?php echo esc_attr( $s['key'] ); ?>" <?php disabled( $lock( 'key' ) ); ?>></td>
					</tr>
					<tr>
						<th scope="row"><label for="hibira-secret"><?php esc_html_e( 'Secret', 'hibira' ); ?></label></th>
						<td>
							<input id="hibira-secret" class="regular-text" type="password" autocomplete="new-password" name="<?php echo esc_attr( $name ); ?>[secret]" value="" placeholder="<?php echo ! empty( $saved['secret'] ) || $lock( 'secret' ) ? esc_attr__( 'Saved. Leave empty to keep it.', 'hibira' ) : ''; ?>" <?php disabled( $lock( 'secret' ) ); ?>>
							<p class="description"><?php esc_html_e( 'Never sent to browsers. To keep it out of the database, define HIBIRA_APP_SECRET in wp-config.php instead.', 'hibira' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hibira-host"><?php esc_html_e( 'Host', 'hibira' ); ?></label></th>
						<td>
							<input id="hibira-host" class="regular-text" name="<?php echo esc_attr( $name ); ?>[host]" value="<?php echo esc_attr( $s['host'] ); ?>" <?php disabled( $lock( 'host' ) ); ?>>
							<p class="description"><?php esc_html_e( 'ws.hibira.io for the default cluster. Change it only for another cluster or a self-hosted server.', 'hibira' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hibira-port"><?php esc_html_e( 'Port', 'hibira' ); ?></label></th>
						<td>
							<input id="hibira-port" class="small-text" type="number" min="1" max="65535" name="<?php echo esc_attr( $name ); ?>[port]" value="<?php echo esc_attr( $s['port'] ); ?>">
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[tls]" value="1" <?php checked( $s['tls'] ); ?>> <?php esc_html_e( 'TLS (https and wss)', 'hibira' ); ?></label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Features', 'hibira' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$features = array(
						'live_comments' => array( __( 'Live comments', 'hibira' ), __( 'Approved comments appear for every reader of the post without a reload.', 'hibira' ) ),
						'post_updates'  => array( __( 'Post updated notice', 'hibira' ), __( 'Readers of a post see a notice with a reload button when you update it.', 'hibira' ) ),
						'readers'       => array( __( 'Readers now', 'hibira' ), __( 'Shows how many people are reading a post. Each reader holds a presence channel connection; counts above 100 show as 100+. It shows in a corner of the page, or where you put the [hibira_readers] shortcode or the hibira_readers() template tag.', 'hibira' ) ),
						'woo_orders'    => array( __( 'WooCommerce order status', 'hibira' ), __( 'The order received and view order pages update when the order status changes.', 'hibira' ) ),
						'admin_notices' => array( __( 'Admin notices', 'hibira' ), __( 'Shows a notice in wp-admin for new orders and for comments awaiting approval.', 'hibira' ) ),
					);
					foreach ( $features as $field => $label ) :
						?>
						<tr>
							<th scope="row"><?php echo esc_html( $label[0] ); ?></th>
							<td>
								<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( $field ); ?>]" value="1" <?php checked( ! empty( $s[ $field ] ) ); ?>> <?php echo esc_html( $label[1] ); ?></label>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<?php
				foreach ( array( 'app_id', 'key', 'host' ) as $field ) {
					// Disabled inputs are not submitted; keep the value for sanitize().
					if ( $lock( $field ) ) {
						echo '<input type="hidden" name="' . esc_attr( $name ) . '[' . esc_attr( $field ) . ']" value="' . esc_attr( $s[ $field ] ) . '">';
					}
				}
				submit_button();
				?>
			</form>

			<h2><?php esc_html_e( 'Check the connection', 'hibira' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="hibira_test">
				<?php wp_nonce_field( 'hibira_test' ); ?>
				<?php submit_button( __( 'Send a test event', 'hibira' ), 'secondary', 'submit', false, self::configured() ? array() : array( 'disabled' => 'disabled' ) ); ?>
			</form>
		</div>
		<?php
	}
}
