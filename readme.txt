=== DownTime Uptime Monitoring ===
Contributors: alirezataleghani
Tags: uptime, monitoring, status page, downtime, status badge
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Monitor your site's uptime with DownTime in one click, and show a live status badge or status page on your site.

== Description ==

DownTime checks your site around the clock and alerts you (email, Slack, Telegram, Bale, webhooks) the moment it goes down.

* **One-click connect**: sign in or create a free DownTime account and approve this site. No API keys to copy.
* **One-click monitoring**: start monitoring this site's home page, with email alerts when it goes down and when it recovers.
* **Dashboard widget**: current state, response time, certificate expiry, recent uptime and incidents, right in wp-admin.
* **WP-Cron watch**: an optional hourly heartbeat, so you hear about it when scheduled posts, backups and updates stop running.
* **Public status page**: publish one with the monitors you pick, or reuse one you already have, and embed it anywhere with the "DownTime Status" block or `[downtime_status]`.
* **Status badges**: `[downtime_badge]` and `[downtime_badge type="uptime"]`.

The plugin only gets a limited key: it can list and create monitors and status pages, read results and incidents, and turn on email alerts that go to your own account address, nothing else. It can't delete anything, change your team, or touch billing. Disconnecting revokes the key.

== External services ==

This plugin connects to the DownTime service at https://downtime.opscale.ir:

* When you click **Connect**, your browser goes to DownTime to sign in and approve access. DownTime then sends this site a one-time code, which the plugin exchanges server-side for its key.
* From the settings page, the plugin calls the DownTime API to show your account, create a monitor for this site's URL, turn on email alerts for it, and create a status page.
* The dashboard widget reads this site's latest checks and incidents from the DownTime API (cached for five minutes).
* If you turn on the WP-Cron watch, WP-Cron sends a heartbeat request to DownTime once an hour. It carries no site data, only the heartbeat monitor's ID in the URL.
* The badge and status page embeds load images and a page from DownTime in your visitors' browsers.

== Frequently Asked Questions ==

= Does the key reach my visitors? =

No. All API calls are made from your server. Visitors only load public badges and the public status page.

= Can I use a status page from another DownTime account or site? =

Yes: pass `slug="your-page"` to either shortcode, or set it in the block's sidebar.

= Why does DownTime say WP-Cron is down when my site is fine? =

WP-Cron only runs when someone visits the site. If the site gets no visits for over 90 minutes, no heartbeat is sent. Run WP-Cron from a real system cron (see "Hooking WP-Cron Into the System Task Scheduler" in the WordPress handbook) or stop watching WP-Cron on the settings page.

== Changelog ==

= 0.2.2 =
* Connect works when wp-admin runs inside a frame, such as WordPress Playground: it opens DownTime in a new tab.
* The key is also sent as a standard Bearer token, for hosts whose outbound proxy drops custom headers.

= 0.2.1 =
* Readme: list the real WordPress.org contributor account.

= 0.2.0 =
* "Monitor this site" now also turns on email alerts (down and recovered), and sites monitored with 0.1.0 get a "Turn on email alerts" button.
* Dashboard widget with the site's state, response time, certificate expiry, recent uptime and incidents.
* Optional WP-Cron watch: an hourly heartbeat monitor.
* Pick which monitors go on a new status page, or reuse an existing status page.

= 0.1.0 =
* First release: Connect, monitor this site, status page, badges, block.
