# Hibira Realtime for WordPress

[![ci](https://github.com/hibira-io/wordpress/actions/workflows/ci.yml/badge.svg)](https://github.com/hibira-io/wordpress/actions/workflows/ci.yml)

A WordPress plugin that makes a site update while people are looking at it,
on [Hibira](https://hibira.io/?utm_source=github&utm_medium=readme&utm_campaign=plugins),
a hosted realtime service that speaks the Pusher Channels protocol:

- **Live comments**: approved comments appear for every reader, replies under their parent.
- **Post updated notice**: readers of a post get a Reload button when you update it.
- **Readers now**: "12 reading now" on posts, on presence channels.
- **WooCommerce order status**: the order received and view order pages follow status changes.
- **Admin notices**: new orders and comments awaiting approval, in wp-admin.

No Composer, no build step: the PHP side signs Hibira HTTP API calls itself
(`includes/class-hibira-client.php`), and the browser side is pusher-js
8.6.0 plus `assets/js/hibira.js`. PHP 7.4+, WordPress 6.0+.

## Install

Until it is on WordPress.org: download `hibira.zip` from the
[latest release](https://github.com/hibira-io/wordpress/releases/latest), then
**Plugins → Add New → Upload Plugin**. Go to **Settings → Hibira**, paste the app ID, key
and secret from your app in the [Hibira portal](https://hibira.io/portal)
(the free plan is enough) and press **Send a test event**.

The secret can live in wp-config.php instead of the database:

```php
define( 'HIBIRA_APP_ID', '...' );
define( 'HIBIRA_APP_KEY', '...' );
define( 'HIBIRA_APP_SECRET', '...' );
// define( 'HIBIRA_HOST', 'ws.hibira.io' );
```

## Channels and events

| Channel | Event | Sent when |
|---|---|---|
| `hibira-post-{ID}` | `comment-approved` | a comment on the post is approved |
| `hibira-post-{ID}` | `post-updated` | the published post's title or content changes |
| `presence-hibira-post-{ID}` | (members) | readers open the post; the member count is "reading now" |
| `private-hibira-order-{ID}` | `status` | the WooCommerce order status changes |
| `private-hibira-admin-comments` | `comment-pending` | a comment awaits approval |
| `private-hibira-admin-orders` | `new-order` | a checkout creates an order |

`POST /wp-json/hibira/v1/auth` signs private and presence subscriptions.
Readers-now members are `u{user ID}` or `v{random ID kept in the browser}`
with no user info. An order channel lets in the order's customer, shop
managers, or a guest with the order key from the order received URL.

## For developers

```php
// Send your own events (up to 100 channels; data up to 4 KB on the free plan, 10 KB on paid plans).
hibira_trigger( 'scores', 'goal', array( 'team' => 'home' ) );

// Or, without failing when the plugin is off:
do_action( 'hibira_trigger', 'scores', 'goal', array( 'team' => 'home' ) );

// Decide your own private and presence channels.
add_filter( 'hibira_authorize_channel', function ( $decision, $channel, $user ) {
	if ( 'private-team-chat' === $channel ) {
		return user_can( $user, 'edit_posts' );
	}
	if ( 'presence-editors' === $channel && user_can( $user, 'edit_posts' ) ) {
		return array( 'user_id' => (string) $user->ID, 'user_info' => array( 'name' => $user->display_name ) );
	}
	return $decision;
}, 10, 3 );
```

```js
// Enqueue the 'hibira' script handle (after hibira_register_client_script()).
HibiraWP.pusher().subscribe('scores').bind('goal', (data) => console.log(data));
HibiraWP.toast('Goal!');
```

Filters: `hibira_live_post` (turn live features off per post),
`hibira_comment_payload` (what a live comment carries),
`hibira_authorize_channel`. Action: `hibira_trigger_failed`.

## Develop

```sh
php tests/run.php          # HTTP API signing, channel auth, requests (no WordPress needed)
python3 tools/compile-mo.py  # languages/*.po → .mo
```

Translations: English, 繁體中文, 简体中文, 日本語, 한국어, ไทย, Tiếng Việt
(`languages/`). CI runs the tests on PHP 7.4–8.4 and the WordPress Plugin
Check.

## License

GPL-2.0-or-later, like WordPress. pusher-js (`assets/vendor`) is MIT.
