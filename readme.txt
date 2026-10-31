=== Hibira Realtime ===
Contributors: hibira
Tags: realtime, live comments, websocket, woocommerce, notifications
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Live comments, "this post was updated" notices, a readers-now count and live WooCommerce order status, on a hosted WebSocket service.

== Description ==

Hibira Realtime makes your site update while people are looking at it. It uses [Hibira](https://hibira.io/?utm_source=wordpress&utm_medium=readme&utm_campaign=plugins), a hosted realtime service that speaks the Pusher Channels protocol, so your server never holds WebSocket connections open. Pages stay cacheable.

* **Live comments.** An approved comment shows up for everyone reading the post, without a reload. Replies go under their parent comment.
* **Post updated notice.** When you update a published post, its readers see a notice with a Reload button.
* **Readers now.** A small "12 reading now" counter on posts, in a corner of the page or wherever you put the `[hibira_readers]` shortcode or `hibira_readers()` template tag.
* **WooCommerce order status.** The order received and view order pages change when the order status changes, so customers waiting on "Processing" see "Completed" the moment you mark it.
* **Admin notices.** A notice in wp-admin when an order comes in or a comment awaits approval.
* **For developers.** `hibira_trigger( $channels, $event, $data )` sends your own events, and `HibiraWP.pusher()` in the browser subscribes to them. Private and presence channels are signed by `/wp-json/hibira/v1/auth` and decided by the `hibira_authorize_channel` filter.

Hibira's free plan (no card) covers a typical blog: 100 concurrent connections and 200,000 messages a day.

= External service =

This plugin connects to Hibira (ws.hibira.io by default, or the host you set):

* Your server sends events (comment author name, avatar URL and comment text of approved comments; post modified time; order status; order number and total for admin notices) to Hibira's HTTP API when they happen.
* Visitors' browsers open a WebSocket connection to Hibira on posts and order pages, to receive those events. For the readers-now count, each browser joins with a random ID; no names or personal data are sent.

Hibira's [terms](https://hibira.io/legal/terms) and [privacy policy](https://hibira.io/legal/privacy).

== Installation ==

1. Install and activate the plugin.
2. Create a free app at [hibira.io](https://hibira.io/portal), open it and copy the app ID, key and secret.
3. Go to **Settings → Hibira**, paste them, choose the features, and press **Send a test event**.

To keep the secret out of the database, put it in wp-config.php instead:

`define( 'HIBIRA_APP_SECRET', 'your-secret' );`

`HIBIRA_APP_ID`, `HIBIRA_APP_KEY` and `HIBIRA_HOST` work the same way.

== Frequently Asked Questions ==

= Does it work with page caching? =

Yes. Pages are served from the cache as usual; the live parts connect from the browser. Visitors' auth requests (readers now, guest order pages) do not need a nonce.

= Do I need Pusher? =

No. Hibira speaks the same protocol as Pusher Channels, so the plugin uses the standard pusher-js library pointed at Hibira. It also works with another Pusher-compatible server if you set its host and port.

= How much does it cost? =

Each event counts as one message, however many readers receive it. Readers now uses presence channels: each open tab is one connection, and a channel shows at most 100 members, so busier posts show "100+".

= My theme's comments look different from the live ones =

Live comments use WordPress's classic comment markup (`li.comment > article.comment-body`). To change what is sent, use the `hibira_comment_payload` filter.

== Changelog ==

= 0.1.1 =
* README: cover image and screenshots.

= 0.1.0 =
* First release: live comments, post updated notice, readers now, WooCommerce order status, admin notices, `hibira_trigger()`.
