# DownTime for WordPress

WordPress plugin for [DownTime](https://downtime.opscale.ir). Connect a site in one click, monitor it, and embed a status badge or status page. `readme.txt` is the WordPress.org listing; this file is for developers.

Tracking: OpScaleLab/downtime#363 (epic OpScaleLab/downtime#364).

## Layout

| File | Purpose |
|---|---|
| `downtime.php` | Plugin header and bootstrap. `DOWNTIME_BASE_URL` can be overridden in `wp-config.php` |
| `includes/class-downtime-api.php` | Server-side API client. The key lives in the `downtime_api_key` option (autoload off) and never reaches a browser |
| `includes/class-downtime-admin.php` | Settings → DownTime: Connect/Disconnect, "Monitor this site", status page creation |
| `includes/class-downtime-widget.php` | wp-admin dashboard widget: state, latest check, recent uptime and incidents, cached 5 minutes in the `downtime_widget_cache` transient |
| `includes/class-downtime-heartbeat.php` | Optional WP-Cron watch: an hourly `downtime_heartbeat_ping` event pings a heartbeat monitor (no key needed) |
| `includes/class-downtime-embed.php` | `[downtime_badge]`, `[downtime_status]`, and the dynamic `downtime/status` block's render |
| `blocks/status/` | Block metadata and editor UI (plain `wp.*` globals, no build step) |
| `assets/embed.js` | Auto-resizes status iframes from the page's `downtime:status:resize` postMessage (DownTime origin only) |

## DownTime API contract

| Call | Endpoint | Needs |
|---|---|---|
| Connect redirect | `GET {base}/connect?client=wordpress&site=<home_url>&redirect_uri=<admin-post callback>&state=<random>` | downtime#362 |
| Code exchange (server-side) | `POST /api/auth/integrations/exchange` `{code, redirect_uri}` → `{api_key}` | downtime#362 |
| Disconnect | `DELETE /api/auth/integrations/self` (revokes the calling key) | downtime#362, plus an `integration` scope rule |
| Account | `GET /api/auth/user` | downtime#361 `integration` scope |
| Monitors | `GET`/`POST /api/monitors` | downtime#361 |
| Latest checks | `GET /api/results?monitor_id=` (last 100) | downtime#361 |
| Incidents | `GET /api/incidents` | downtime#384 |
| Email alerts | `POST /api/rules` (email-only; DownTime refuses anything else from an integration key) | downtime#384 |
| WP-Cron heartbeat | `POST /api/heartbeat/{monitor_id}`, no key | public |
| Status pages | `GET`/`POST /api/status-pages` | downtime#361 |
| Badges / embed | `GET /api/status/{slug}/badge.svg`, `uptime.svg`, `/status/{slug}?embed=1` | public (embed: downtime#360) |

For development without the Connect flow, set a key directly:

```sh
wp option update downtime_api_key <integration-scoped key> --autoload=no
```

## Local testing

```sh
docker compose up -d   # see docker-compose.yml: WordPress + MariaDB, plugin mounted
```

Then open http://localhost:8088, finish the WordPress install, and activate **DownTime Uptime Monitoring**.

Before a release, run [Plugin Check](https://wordpress.org/plugins/plugin-check/) against the built ZIP (not the repo checkout) on the latest WordPress: `wp plugin check downtime-uptime-monitoring`.

## Release

The WordPress.org slug and text domain are `downtime-uptime-monitoring`.

1. Bump `Version` in `downtime.php`, `DOWNTIME_PLUGIN_VERSION`, and `Stable tag` plus the changelog in `readme.txt`.
2. Tag the release: `git tag vX.Y.Z && git push origin vX.Y.Z`.
3. Build the ZIP: `bin/build-zip.sh vX.Y.Z`. It contains a single `downtime-uptime-monitoring/` folder; `.gitattributes` leaves out the dev-only files.
4. Publish it: `gh release create vX.Y.Z downtime-uptime-monitoring.zip`.
5. Once the plugin is on WordPress.org, push the same tree to its SVN repo: `bin/deploy-svn.sh vX.Y.Z` (asks for your WordPress.org SVN password; `--dry-run` shows the changes without committing).

The plugin is GPL-2.0-or-later, as WordPress.org requires.
