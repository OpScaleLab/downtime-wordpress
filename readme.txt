=== DownTime Uptime Monitoring ===
Contributors: alirezataleghani
Tags: uptime, monitoring, status page, downtime, status badge
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Monitor your site's uptime with DownTime in one click, and show a live status badge or status page on your site.

== Description ==

DownTime checks your site around the clock and alerts you (email, Slack, Telegram, Bale, webhooks) the moment it goes down.

* **One-click connect**: sign in or create a free DownTime account and approve this site. No API keys to copy.
* **One-click monitoring**: pick from a checklist and add them all at once: the home page (with email alerts when it goes down and recovers), the login page, the REST API, the XML sitemap, the HTTPS certificate's expiry, DNS, the server port, ping, and WP-Cron.
* **No duplicates**: checks your DownTime account already has, from the dashboard or an earlier install, are reused instead of added again.
* **Manage monitors in wp-admin**: see this site's monitors, and pause, resume or delete the ones the plugin created.
* **Dashboard widget**: current state, response time, certificate expiry, recent uptime and incidents, right in wp-admin.
* **WP-Cron watch**: an hourly heartbeat, so you hear about it when scheduled posts, backups and updates stop running.
* **Public status page**: publish one with the monitors you pick, or reuse one you already have, and embed it anywhere with the "DownTime Status" block or `[downtime_status]`. One click publishes a "Status" page on your site with it.
* **Status badges**: `[downtime_badge]` and `[downtime_badge type="uptime"]`.

The plugin only gets a limited key: it can list and create monitors and status pages, pause, edit or delete only the monitors this site created, read results and incidents, and turn on email alerts that go to your own account address, nothing else. It can't touch monitors made in the DownTime dashboard or by another site, change your team, or touch billing. Disconnecting revokes the key.

== External services ==

This plugin connects to the DownTime service at https://downtime.opscale.ir:

* When you click **Connect**, your browser goes to DownTime to sign in and approve access. DownTime then sends this site a one-time code, which the plugin exchanges server-side for its key.
* From the settings page, the plugin calls the DownTime API to show your account, create monitors for this site's URLs, pause, resume or delete them, turn on email alerts, and create a status page.
* The dashboard widget reads this site's latest checks and incidents from the DownTime API (cached for five minutes).
* If you turn on the WP-Cron watch, WP-Cron sends a heartbeat request to DownTime once an hour. It carries no site data, only the heartbeat monitor's ID in the URL.
* The badge and status page embeds load images and a page from DownTime in your visitors' browsers.

== Frequently Asked Questions ==

= Does the key reach my visitors? =

No. All API calls are made from your server. Visitors only load public badges and the public status page.

= Can I use a status page from another DownTime account or site? =

Yes: pass `slug="your-page"` to either shortcode, or set it in the block's sidebar.

= Why can't I pause or delete some monitors from wp-admin? =

DownTime only lets this site change monitors this site created. Monitors made in the DownTime dashboard, by another site, or by a plugin version before 0.3.0 show a "Manage on DownTime" link instead.

= Why does DownTime say WP-Cron is down when my site is fine? =

WP-Cron only runs when someone visits the site. If the site gets no visits for over 90 minutes, no heartbeat is sent. Run WP-Cron from a real system cron (see "Hooking WP-Cron Into the System Task Scheduler" in the WordPress handbook) or stop watching WP-Cron on the settings page.

= Why does DownTime offer only WP-Cron in WordPress Playground? =

A Playground site runs in your browser, and its address changes every session, so DownTime can't reach it. Install the plugin on your real site to monitor it.

== Changelog ==

= 0.4.0 =
* One checklist sets up all of this site's monitoring at once: home page, login page, REST API, XML sitemap, TLS certificate, DNS, server port and ping (the last two off by default), and WP-Cron.
* No more duplicates: checks the account already has are reused, whoever created them. HTTP and HTTPS monitors of the same address, and addresses written with or without a trailing slash or in capitals, count as the same check. Watching WP-Cron again, after reinstalling or in a new Playground session, reuses the existing heartbeat.
* The TLS certificate check now emails you before the certificate expires.
* WordPress Playground and plugin previews are recognised: only WP-Cron and the status page are offered there, since DownTime can't reach the site.
* A new status page shows this site's monitors by default.

= 0.3.0 =
* Recommended checks: one click adds monitors for the login page, the REST API, the XML sitemap and the TLS certificate (warns 14 days before expiry).
* This site's monitors are listed on the settings page. Pause, resume or delete the ones the plugin created; the rest link to the DownTime dashboard. Needs DownTime 0.31.0 or later.
* "Add a Status page to this site" publishes a Status page with the DownTime Status block.
* Persian (fa_IR) translation bundled. Layout spacing now follows right-to-left languages.

= 0.2.3 =
* Status page: the WP-Cron heartbeat can be shown on it, and is picked by default when the site itself cannot be monitored (WordPress Playground, local sites). Before, creating a page there failed with "Pick at least one monitor".
* Status page: with no monitors yet, the page says to add one instead of showing a form that cannot succeed.

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
