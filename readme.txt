=== DownTime Uptime Monitoring ===
Contributors: opscale
Tags: uptime, monitoring, status page, downtime, status badge
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Monitor your site's uptime with DownTime in one click, and show a live status badge or status page on your site.

== Description ==

DownTime checks your site around the clock and alerts you (email, Slack, Telegram, Bale, webhooks) the moment it goes down.

* **One-click connect**: sign in or create a free DownTime account and approve this site. No API keys to copy.
* **One-click monitoring**: start monitoring this site's home page.
* **Public status page**: publish one, and embed it anywhere with the "DownTime Status" block or `[downtime_status]`.
* **Status badges**: `[downtime_badge]` and `[downtime_badge type="uptime"]`.

The plugin only gets a limited key: it can list and create monitors and status pages and read results, nothing else. It can't delete anything, change your team, or touch billing. Disconnecting revokes the key.

== External services ==

This plugin connects to the DownTime service at https://downtime.opscale.ir:

* When you click **Connect**, your browser goes to DownTime to sign in and approve access. DownTime then sends this site a one-time code, which the plugin exchanges server-side for its key.
* From the settings page, the plugin calls the DownTime API to show your account, create a monitor for this site's URL, and create a status page.
* The badge and status page embeds load images and a page from DownTime in your visitors' browsers.

== Frequently Asked Questions ==

= Does the key reach my visitors? =

No. All API calls are made from your server. Visitors only load public badges and the public status page.

= Can I use a status page from another DownTime account or site? =

Yes: pass `slug="your-page"` to either shortcode, or set it in the block's sidebar.

== Changelog ==

= 0.1.0 =
* First release: Connect, monitor this site, status page, badges, block.
