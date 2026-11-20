=== DownTime Uptime Monitoring ===
Contributors: alirezataleghani
Tags: uptime, monitoring, status page, ssl, woocommerce
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.7.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Monitor your site's uptime with DownTime in one click, and show a live status badge or status page on your site.

== Description ==

DownTime checks your site around the clock from outside, the way your visitors reach it, and alerts you (email, Slack, Telegram, Bale, webhooks) the moment it goes down. Nothing runs inside your site between checks: no cron jobs, no background pings, nothing that slows a page down.

* **One-click connect**: sign in or create a free DownTime account and approve this site. No API keys to copy. The basics, HTTPS, the TLS certificate and DNS, start being checked right away.
* **One switch per check**: the home page, the login page, the REST API, the XML sitemap, page content (catches a blank or defaced page that still answers "200 OK"), the HTTPS certificate's expiry, the domain registration's expiry, DNS, the server port, ping, and the network path. Turn on everything in one click, or only what you need.
* **WooCommerce**: checks that the shop, the checkout and the Store API answer.
* **Network path**: traces the route to your server like mtr and names the hop where packets get lost, so you can tell your host apart from a network problem on the way.
* **Multisite**: network-activate it, connect each site from its own settings, and see every site's status under Network Admin → Settings → DownTime.
* **No duplicates**: checks your DownTime account already has, from the dashboard or an earlier install, are reused instead of added again. Switching a check off pauses it and keeps its history.
* **Alerts your way**: an "Alert me" switch on every check, sent by email, Telegram, Bale or Slack. Connect Telegram or Bale right from the settings page.
* **Quiet updates**: alerts pause for 15 minutes when WordPress starts installing updates, so maintenance mode doesn't alert anyone.
* **Status at a glance**: a green or red dot in the admin bar, and a dashboard widget with current state, response time, certificate expiry, recent uptime and incidents.
* **Cleans up after itself**: Disconnect or delete the plugin and the checks it set up are deleted from DownTime too (Disconnect can keep them), its key is revoked and nothing is left in your database.
* **Public status page**: publish one with the monitors you pick, or reuse one you already have, and embed it anywhere with the "DownTime Status" block or `[downtime_status]`. One click publishes a "Status" page on your site with it.
* **Status badges**: `[downtime_badge]` and `[downtime_badge type="uptime"]`.

The plugin only gets a limited key: it can list and create monitors and status pages, pause, edit or delete only the monitors and alerts this site created, read results and incidents, send alerts only to places your account already set up (your account email, your Telegram or Bale chat, your Slack), and pause alerts for at most 30 minutes during updates, nothing else. It can't touch monitors or alerts made in the DownTime dashboard or by another site, see your Slack or chat details, change your team, or touch billing.

== External services ==

This plugin connects to the DownTime service at https://downtime.opscale.ir:

* When you click **Connect**, your browser goes to DownTime to sign in and approve access. DownTime then sends this site a one-time code, which the plugin exchanges server-side for its key.
* From the settings page, the plugin calls the DownTime API to show your account, create monitors for this site's URLs, pause, resume or delete them, turn alerts on or off, connect Telegram or Bale, and create a status page.
* Turning on the page content check makes your server load its own home page once, to make sure the text is there.
* When WordPress starts installing an update, the plugin asks DownTime to pause this site's alerts for 15 minutes (you can turn this off).
* The dashboard widget reads this site's latest checks and incidents from the DownTime API (cached for five minutes).
* Disconnecting, or deleting the plugin, asks DownTime to delete the monitors this site created and to revoke this site's key.
* The badge and status page embeds load images and a page from DownTime in your visitors' browsers.

== Frequently Asked Questions ==

= Does the key reach my visitors? =

No. All API calls are made from your server. Visitors only load public badges and the public status page.

= Can I use a status page from another DownTime account or site? =

Yes: pass `slug="your-page"` to either shortcode, or set it in the block's sidebar.

= Why can't I pause or delete some monitors from wp-admin? =

DownTime only lets this site change monitors this site created. Monitors made in the DownTime dashboard, by another site, or by a plugin version before 0.3.0 show a "Managed in DownTime" link instead.

= What text should the page content check look for? =

Something that is always on your home page and only there when the page rendered properly: your site name, a footer line, or a menu item. It must match the page's HTML exactly, including capitals. The plugin checks your home page when you switch it on and tells you if the text isn't there.

= I already have alerts in the DownTime dashboard. Will I get them twice? =

The "Alert me" switches only show alerts this site created. Alerts you set up in the dashboard keep working and aren't shown here, so leave a switch off if the dashboard already covers that check.

= Why doesn't the plugin watch WP-Cron or Site Health any more? =

Both were hourly pings sent from WP-Cron, and WP-Cron only runs when someone visits the site (many hosts and caching setups switch it off), so a quiet but healthy site was reported as down. Every check now runs from DownTime, outside your site. Updating to 0.7.0 stops those pings and pauses their monitors on DownTime, keeping their history; delete them from the settings page if you don't need them.

= What happens when I disconnect or delete the plugin? =

Disconnect deletes the monitors this site set up on DownTime, with their history and alerts, moves the "Status" page the plugin published to the trash, and revokes this site's key. Tick "Keep this site's checks running on DownTime" first to keep the monitors. Deleting the plugin does the same clean-up on every site of the network and removes everything the plugin stored in WordPress. Monitors and status pages you made in the DownTime dashboard, and your DownTime account, are never touched.

= Why does DownTime offer no checks in WordPress Playground? =

A Playground site runs in your browser, and its address changes every session, so DownTime can't reach it. Install the plugin on your real site to monitor it. You can still try the status page block and badges there.

= Does it work on a multisite network? =

Yes. Network-activate it, then connect each site from its own Settings → DownTime page. Network Admin → Settings → DownTime lists which sites are connected.

== Upgrade Notice ==

= 0.7.1 =
Connecting a site now switches on the basics (HTTPS, TLS certificate, DNS) right away.

= 0.7.0 =
The WP-Cron and Site Health heartbeats are gone (they raised false alarms on quiet sites); their monitors are paused, not deleted. Disconnect and plugin deletion now remove this site's checks from DownTime.

== Changelog ==

= 0.7.1 =
* Connect now switches on the basics right away: the site over HTTPS, its TLS certificate and DNS, each with an alert. Checks the account already has are reused.
* "Turn on the essentials" is now "Turn on the basics (HTTPS, TLS, DNS)". Login page, REST API, page content, domain expiry, ping and the rest stay one switch away, as does "Turn on everything".

= 0.7.0 =
* Every check now runs from DownTime, outside your site. The WP-Cron and Site Health heartbeats are removed: WP-Cron only runs when the site gets visits, so they reported quiet, healthy sites as down. Updating stops their pings and pauses their monitors on DownTime, history kept.
* Disconnect now deletes the monitors this site created on DownTime and trashes the plugin's Status page, unless you tick "Keep this site's checks running on DownTime". It asks before disconnecting.
* Deleting the plugin deletes this site's monitors on DownTime, revokes its key and removes every option, transient and scheduled event it stored, on every site of a network.
* Nothing is scheduled in WP-Cron any more, except a one-time clean-up right after this update.

= 0.6.0 =
* Site Health check: the Site Health tests run every hour and critical problems are reported to DownTime, with the problem in the alert.
* WooCommerce: shop, checkout and Store API checks, plus payment methods, the checkout page and late scheduled actions checked from inside the store.
* Network path check: traces the route to the server and names the hop that loses packets. Needs DownTime 0.33 or later.
* Multisite: network activation schedules every site's checks, and Network Admin → Settings → DownTime shows each site's connection.

= 0.5.1 =
* Every settings action and the Connect return now verify a WordPress nonce in the handler itself.
* Translations now come from translate.wordpress.org language packs instead of files bundled in the plugin.

= 0.5.0 =
* Monitoring is now one switch per check, with "Turn on the essentials" and "Turn on everything". Off pauses the check and keeps its history; Remove deletes it.
* New checks: page content (catches a blank, broken or defaced page that still answers "200 OK") and domain expiry.
* "Alert me" switch on every check, and alerts by email, Telegram, Bale or Slack. Connect Telegram or Bale from the settings page. Needs DownTime 0.32 or later; earlier versions keep email alerts.
* Alerts pause for 15 minutes while WordPress installs updates.
* Admin bar dot showing whether the site is up.
* "Open DownTime dashboard" now opens from WordPress Playground too.

= 0.4.1 =
* Bundled translations are loaded without load_plugin_textdomain(), and only until a translate.wordpress.org language pack is installed for the locale.

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
