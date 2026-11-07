<?php
/**
 * Settings → DownTime, the parts that manage this site's checks:
 *
 * - Monitoring: one switch per WordPress-specific check (homepage, login
 *   page, REST API, sitemap, page content, TLS certificate, domain expiry,
 *   DNS, TCP port, ping, WP-Cron heartbeat), plus "Essentials" and
 *   "Everything" presets. On reuses a monitor the account already has,
 *   wherever it came from, and resumes it if paused; it creates one only
 *   when none exists. Off pauses the monitor, so its history survives;
 *   Remove deletes it. DownTime only lets this site's key change monitors
 *   it created itself (chart 0.31.0+); others link to the dashboard.
 * - Other monitors for this site: the rest, with pause/resume/delete.
 * - A "Status" page on this site with the status page block in it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Downtime_Checks {

	const OPTION_WP_PAGE   = 'downtime_wp_status_page_id';
	const OPTION_TLS_ALERT = 'downtime_tls_alert_rule_id'; // pre-0.5.0; only deleted now
	const OPTION_CONTENT   = 'downtime_content_text';
	// IDs of every monitor that belongs to this site, refreshed whenever the
	// settings page renders: the dashboard widget, the admin bar and the
	// update silencer use them without listing monitors again.
	const OPTION_SITE_IDS = 'downtime_site_monitor_ids';
	// check key => ID of the monitor this site paused. DownTime before 0.32
	// left paused monitors out of its monitor list, so without this a check
	// switched back on would get a second monitor instead of its old one.
	const OPTION_PAUSED = 'downtime_paused_checks';

	public static function init() {
		add_action( 'admin_post_downtime_check_on', array( __CLASS__, 'handle_check_on' ) );
		add_action( 'admin_post_downtime_check_off', array( __CLASS__, 'handle_check_off' ) );
		add_action( 'admin_post_downtime_check_remove', array( __CLASS__, 'handle_check_remove' ) );
		add_action( 'admin_post_downtime_preset', array( __CLASS__, 'handle_preset' ) );
		// The pre-0.5.0 checklist form, in case a page from before an update is still open.
		add_action( 'admin_post_downtime_add_checks', array( __CLASS__, 'handle_add_checks' ) );
		add_action( 'admin_post_downtime_monitor_pause', array( __CLASS__, 'handle_pause' ) );
		add_action( 'admin_post_downtime_monitor_resume', array( __CLASS__, 'handle_resume' ) );
		add_action( 'admin_post_downtime_monitor_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_downtime_wp_status_page', array( __CLASS__, 'handle_wp_status_page' ) );
	}

	/** The site this plugin connected as; DownTime stamps it on monitors this site's key creates. */
	private static function site() {
		return home_url( '/' );
	}

	private static function host() {
		return strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	}

	private static function site_name() {
		$name = get_bloginfo( 'name' );
		return '' !== $name ? $name : self::host();
	}

	/**
	 * WordPress Playground and the WordPress.org plugin preview run the site
	 * inside the visitor's browser, under an address that changes every
	 * session. DownTime can't reach it, and a monitor for it would be
	 * orphaned as soon as the tab closes.
	 */
	public static function is_preview_site() {
		return (bool) preg_match( '/(^|\.)(playground|wpsandbox)\.wordpress\.net$/', self::host() );
	}

	/**
	 * The text the page content check looks for: what the admin saved, else
	 * the site name when it would appear in the HTML exactly as typed
	 * (WordPress escapes &, quotes and dashes in titles).
	 */
	public static function content_text() {
		$saved = (string) get_option( self::OPTION_CONTENT, '' );
		if ( '' !== $saved ) {
			return $saved;
		}
		$name = get_bloginfo( 'name' );
		return preg_match( '/^[\p{L}\p{N} .,!]{3,80}$/u', $name ) ? $name : '';
	}

	/** Text safe to put inside a DownTime pat() condition: no wildcards or parentheses. */
	public static function sanitize_content_text( $text ) {
		$text = trim( preg_replace( '/[*()\r\n]+/', ' ', (string) $text ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 80 ) : substr( $text, 0, 80 );
	}

	/**
	 * Every check the plugin offers, keyed by a stable ID, in display order.
	 * `monitor` is the full definition sent to DownTime. `alert` is the
	 * failure threshold for the alert rule turned on with the check (0 = no
	 * alert unless the admin turns it on). `essentials` puts it in the
	 * Essentials preset. `marker` tells monitors apart that probe the same
	 * target: a content check carries a [BODY] condition, a domain check a
	 * domain expiry threshold. Only the heartbeat is offered on a site
	 * DownTime can't reach.
	 */
	public static function checks() {
		$name   = self::site_name();
		$checks = array();
		if ( Downtime_Admin::is_public_site() ) {
			$https = 'https' === wp_parse_url( home_url( '/' ), PHP_URL_SCHEME );
			$type  = $https ? 'https' : 'http';
			$host  = self::host();

			$checks['home']  = array(
				'label'      => __( 'Homepage', 'downtime-uptime-monitoring' ),
				'help'       => __( 'The site answers.', 'downtime-uptime-monitoring' ),
				'essentials' => true,
				'alert'      => 2,
				'option'     => Downtime_Admin::OPTION_MONITOR_ID,
				'monitor'    => array(
					'name'     => $name,
					'url'      => home_url( '/' ),
					'type'     => $type,
					// 300s is the smallest interval every plan allows (Core's minimum).
					'interval' => 300,
				),
			);
			$checks['login'] = array(
				'label'      => __( 'Login page', 'downtime-uptime-monitoring' ),
				'help'       => __( 'wp-login.php answers, so you and your users can sign in.', 'downtime-uptime-monitoring' ),
				'essentials' => true,
				'alert'      => 0,
				'monitor'    => array(
					/* translators: %s: site name */
					'name'          => sprintf( __( '%s: login page', 'downtime-uptime-monitoring' ), $name ),
					'url'           => wp_login_url(),
					'type'          => $type,
					'expected_code' => 200,
					'interval'      => 300,
				),
			);
			$checks['rest']  = array(
				'label'      => __( 'REST API', 'downtime-uptime-monitoring' ),
				'help'       => __( '/wp-json/ answers. The block editor, many plugins and mobile apps depend on it.', 'downtime-uptime-monitoring' ),
				'essentials' => true,
				'alert'      => 0,
				'monitor'    => array(
					/* translators: %s: site name */
					'name'          => sprintf( __( '%s: REST API', 'downtime-uptime-monitoring' ), $name ),
					'url'           => get_rest_url(),
					'type'          => $type,
					'expected_code' => 200,
					'interval'      => 300,
				),
			);
			if ( get_option( 'blog_public' ) && function_exists( 'get_sitemap_url' ) && get_sitemap_url( 'index' ) ) {
				$checks['sitemap'] = array(
					'label'      => __( 'XML sitemap', 'downtime-uptime-monitoring' ),
					'help'       => __( 'The sitemap search engines read is reachable.', 'downtime-uptime-monitoring' ),
					'essentials' => false,
					'alert'      => 0,
					'monitor'    => array(
						/* translators: %s: site name */
						'name'          => sprintf( __( '%s: sitemap', 'downtime-uptime-monitoring' ), $name ),
						'url'           => get_sitemap_url( 'index' ),
						'type'          => $type,
						'expected_code' => 200,
						'interval'      => 3600,
					),
				);
			}
			$text              = self::content_text();
			$checks['content'] = array(
				'label'      => __( 'Page content', 'downtime-uptime-monitoring' ),
				'help'       => __( 'The homepage still shows this text. Catches a blank page, a broken theme or a defaced site that still answers "200 OK".', 'downtime-uptime-monitoring' ),
				'essentials' => '' !== $text,
				'alert'      => 2,
				'marker'     => 'content',
				'needs_text' => true,
				'monitor'    => array(
					/* translators: %s: site name */
					'name'          => sprintf( __( '%s: page content', 'downtime-uptime-monitoring' ), $name ),
					'url'           => home_url( '/' ),
					'type'          => $type,
					'expected_code' => 200,
					'conditions'    => array( '[BODY] == pat(*' . $text . '*)' ),
					'interval'      => 600,
				),
			);
			if ( $https ) {
				$checks['tls'] = array(
					'label'      => __( 'TLS certificate', 'downtime-uptime-monitoring' ),
					'help'       => __( 'Warns you 14 days before the HTTPS certificate expires.', 'downtime-uptime-monitoring' ),
					'essentials' => true,
					'alert'      => 1,
					'monitor'    => array(
						/* translators: %s: site name */
						'name'                       => sprintf( __( '%s: TLS certificate', 'downtime-uptime-monitoring' ), $name ),
						'url'                        => home_url( '/' ),
						'type'                       => 'tls',
						'cert_expiry_threshold_days' => 14,
						'interval'                   => 3600,
					),
				);
			}
			$checks['domain'] = array(
				'label'      => __( 'Domain expiry', 'downtime-uptime-monitoring' ),
				'help'       => __( 'Warns you 30 days before the domain registration runs out.', 'downtime-uptime-monitoring' ),
				'essentials' => true,
				'alert'      => 1,
				'marker'     => 'domain',
				'monitor'    => array(
					/* translators: %s: site name */
					'name'                         => sprintf( __( '%s: domain expiry', 'downtime-uptime-monitoring' ), $name ),
					'url'                          => $host,
					'type'                         => 'dns',
					'domain_expiry_threshold_days' => 30,
					// A registry lookup; twice a day is plenty and keeps WHOIS servers happy.
					'interval'                     => 43200,
				),
			);
			$checks['dns']    = array(
				'label'      => __( 'DNS', 'downtime-uptime-monitoring' ),
				'help'       => __( 'The domain name still resolves, e.g. after a registrar or DNS host change.', 'downtime-uptime-monitoring' ),
				'essentials' => true,
				'alert'      => 0,
				'monitor'    => array(
					/* translators: %s: site name */
					'name'     => sprintf( __( '%s: DNS', 'downtime-uptime-monitoring' ), $name ),
					'url'      => $host,
					'type'     => 'dns',
					'interval' => 600,
				),
			);
			$port             = $https ? 443 : 80;
			$checks['tcp']    = array(
				/* translators: %d: port number */
				'label'      => sprintf( __( 'Server port %d', 'downtime-uptime-monitoring' ), $port ),
				'help'       => __( 'The web server accepts connections. Tells a server outage apart from a WordPress error.', 'downtime-uptime-monitoring' ),
				'essentials' => false,
				'alert'      => 0,
				'monitor'    => array(
					/* translators: %s: site name */
					'name'     => sprintf( __( '%s: server port', 'downtime-uptime-monitoring' ), $name ),
					'url'      => $host,
					'type'     => 'tcp',
					'port'     => $port,
					'interval' => 300,
				),
			);
			$checks['ping']   = array(
				'label'      => __( 'Ping', 'downtime-uptime-monitoring' ),
				'help'       => __( 'The server answers ping. Many hosts and CDNs block ping, so only turn it on if yours allows it.', 'downtime-uptime-monitoring' ),
				'essentials' => false,
				'alert'      => 0,
				'monitor'    => array(
					/* translators: %s: site name */
					'name'     => sprintf( __( '%s: ping', 'downtime-uptime-monitoring' ), $name ),
					'url'      => $host,
					'type'     => 'ping',
					'interval' => 300,
				),
			);
		}
		$checks['heartbeat'] = array(
			'label'      => __( 'WP-Cron', 'downtime-uptime-monitoring' ),
			'help'       => __( 'Scheduled posts, backups and updates keep running. This site pings DownTime every hour, and DownTime flags it if pings stop for 90 minutes. WP-Cron only runs when the site gets visits, so on a very quiet site, run it from a real system cron.', 'downtime-uptime-monitoring' ),
			'essentials' => true,
			'alert'      => 1,
			'monitor'    => array(
				'name'     => $name . ' WP-Cron',
				'type'     => 'heartbeat',
				'interval' => Downtime_Heartbeat::INTERVAL,
			),
		);
		return $checks;
	}

	/** What a check probes, normalised so equivalent monitors compare equal. */
	private static function target( $type, $url, $port ) {
		$url = trim( (string) $url );
		if ( 'http' === $type || 'https' === $type ) {
			$parts = wp_parse_url( false === strpos( $url, '://' ) ? $type . '://' . $url : $url );
			$host  = strtolower( (string) ( $parts['host'] ?? '' ) );
			$path  = untrailingslashit( '/' . ltrim( (string) ( $parts['path'] ?? '' ), '/' ) );
			$query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
			return $host . $path . $query;
		}
		$host = false === strpos( $url, '://' ) ? $url : (string) wp_parse_url( $url, PHP_URL_HOST );
		$host = strtolower( trim( (string) preg_replace( '#[/:].*$#', '', $host ), '[]' ) );
		return 'tcp' === $type ? $host . ':' . (int) $port : $host;
	}

	/** A monitor that checks page content: it carries a [BODY] condition. */
	private static function is_content_monitor( $m ) {
		foreach ( (array) ( $m['conditions'] ?? array() ) as $c ) {
			if ( false !== strpos( (string) $c, '[BODY]' ) ) {
				return true;
			}
		}
		return false;
	}

	private static function has_domain_expiry( $m ) {
		return ! empty( $m['domainExpiryThresholdDays'] );
	}

	/**
	 * The account monitor that already does what a check would, if any:
	 * same kind of probe against the same target, wherever it was created
	 * (this site, an earlier install of it, or the dashboard). HTTP and
	 * HTTPS count as the same kind. Content and domain-expiry checks only
	 * match monitors with their marker, and the plain checks never take
	 * one. A heartbeat has no target, so it is the one this site pings,
	 * else one this site created, else one with this site's heartbeat name
	 * (e.g. from an earlier install or preview). $taken skips monitors
	 * another check already claimed.
	 */
	public static function find_existing( $check, $monitors, $taken = array() ) {
		$want = $check['monitor'];
		if ( 'heartbeat' === $want['type'] ) {
			$by_name = null;
			foreach ( $monitors as $m ) {
				if ( empty( $m['id'] ) || 'heartbeat' !== ( $m['type'] ?? '' ) || isset( $taken[ $m['id'] ] ) ) {
					continue;
				}
				if ( Downtime_Heartbeat::monitor_id() === $m['id'] || self::site() === ( $m['integrationSite'] ?? '' ) ) {
					return $m;
				}
				if ( ! $by_name && $want['name'] === ( $m['name'] ?? '' ) ) {
					$by_name = $m;
				}
			}
			return $by_name;
		}
		$web    = array( 'http', 'https' );
		$marker = $check['marker'] ?? '';
		$target = self::target( $want['type'], $want['url'], $want['port'] ?? 0 );
		foreach ( $monitors as $m ) {
			$type = (string) ( $m['type'] ?? '' );
			if ( empty( $m['id'] ) || isset( $taken[ $m['id'] ] ) ) {
				continue;
			}
			$same_kind = $type === $want['type'] || ( in_array( $type, $web, true ) && in_array( $want['type'], $web, true ) );
			if ( ! $same_kind || self::target( $want['type'], $m['url'] ?? '', $m['port'] ?? 0 ) !== $target ) {
				continue;
			}
			$content = self::is_content_monitor( $m );
			$domain  = 'dns' === $type && self::has_domain_expiry( $m );
			if ( ( 'content' === $marker ) !== $content || ( 'domain' === $marker ) !== $domain ) {
				continue;
			}
			return $m;
		}
		return null;
	}

	/**
	 * Which account monitor each check maps to (null = none yet). Each
	 * monitor goes to one check at most, so two switches never control
	 * the same monitor.
	 */
	public static function assign( $checks, $monitors ) {
		$out    = array();
		$taken  = array();
		$listed = array_flip( wp_list_pluck( $monitors, 'id' ) );
		$paused = (array) get_option( self::OPTION_PAUSED, array() );
		foreach ( $checks as $key => $check ) {
			$m = self::find_existing( $check, $monitors, $taken );
			// A monitor this site paused that the list leaves out (older
			// DownTime): stands in as itself, paused, so On resumes it.
			if ( ! $m && ! empty( $paused[ $key ] ) && ! isset( $listed[ $paused[ $key ] ] ) ) {
				$m = array_merge(
					self::listed_shape( $check['monitor'] ),
					array(
						'id'              => $paused[ $key ],
						'active'          => false,
						'integrationSite' => self::site(),
						'unlisted'        => true,
					)
				);
			}
			$out[ $key ] = $m;
			if ( $m ) {
				$taken[ $m['id'] ] = true;
			}
		}
		return $out;
	}

	/** A check's monitor definition in the shape DownTime lists monitors in. */
	private static function listed_shape( $def ) {
		$out = array();
		foreach ( $def as $k => $v ) {
			$out[ lcfirst( str_replace( ' ', '', ucwords( str_replace( '_', ' ', $k ) ) ) ) ] = $v;
		}
		return $out;
	}

	private static function remember_paused( $key, $id ) {
		$paused = (array) get_option( self::OPTION_PAUSED, array() );
		if ( null === $id ) {
			unset( $paused[ $key ] );
		} else {
			$paused[ $key ] = $id;
		}
		update_option( self::OPTION_PAUSED, $paused, false );
	}

	/**
	 * Remember a check's monitor locally once it exists, whether this site
	 * just created it or found it on the account: the homepage monitor is
	 * the status page default, and the heartbeat is what WP-Cron pings.
	 */
	private static function adopt( $key, $check, $monitor_id ) {
		if ( 'heartbeat' === $key ) {
			if ( Downtime_Heartbeat::monitor_id() !== $monitor_id ) {
				Downtime_Heartbeat::enable( $monitor_id );
			}
		} elseif ( ! empty( $check['option'] ) && get_option( $check['option'] ) !== $monitor_id ) {
			update_option( $check['option'], $monitor_id, false );
		}
	}

	/** Whether this site's key may change monitor $m: only ones it created. */
	public static function owns( $m ) {
		return self::site() === ( $m['integrationSite'] ?? '' );
	}

	/**
	 * The account's monitors that belong to this site: created by this
	 * site's key, pointing at this site, or the WP-Cron heartbeat.
	 */
	public static function site_monitors( $monitors ) {
		$host = self::host();
		$out  = array();
		foreach ( $monitors as $m ) {
			if ( empty( $m['id'] ) ) {
				continue;
			}
			$points = '' !== $host && self::target( 'dns', $m['url'] ?? '', 0 ) === $host;
			if ( self::owns( $m ) || $points || Downtime_Heartbeat::monitor_id() === $m['id'] ) {
				$out[] = $m;
			}
		}
		return $out;
	}

	/** Remember this site's monitor IDs for the widget, admin bar and update silencer. */
	private static function remember_site_ids( $monitors ) {
		$ids = wp_list_pluck( self::site_monitors( $monitors ), 'id' );
		sort( $ids );
		if ( get_option( self::OPTION_SITE_IDS ) !== $ids ) {
			update_option( self::OPTION_SITE_IDS, $ids, false );
		}
	}

	private static function find( $id ) {
		foreach ( Downtime_Admin::list_monitors() as $m ) {
			if ( isset( $m['id'] ) && $m['id'] === $id ) {
				return $m;
			}
		}
		return null;
	}

	private static function posted_id() {
		// Nonce checked by the caller's require_admin().
		$id = isset( $_POST['monitor_id'] ) ? sanitize_text_field( wp_unslash( $_POST['monitor_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return Downtime_Admin::is_uuid( $id ) ? $id : '';
	}

	private static function posted_check() {
		// Nonce checked by the caller's require_admin().
		$key    = isset( $_POST['check'] ) ? sanitize_key( wp_unslash( $_POST['check'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$checks = self::checks();
		if ( ! isset( $checks[ $key ] ) ) {
			Downtime_Admin::done( 'error', __( 'That check is not available on this site.', 'downtime-uptime-monitoring' ) );
		}
		return $key;
	}

	// --- Turning checks on and off ---------------------------------------------

	/**
	 * Turn one check on: reuse (and resume) the monitor the account already
	 * has, or create it and turn on its alert. Returns
	 * array( 'added'|'reused'|'resumed'|'on', message-or-null ), or a
	 * WP_Error.
	 */
	private static function turn_on( $key, $check, $existing ) {
		if ( $existing ) {
			$id = sanitize_text_field( $existing['id'] );
			if ( empty( $existing['active'] ) ) {
				if ( ! self::owns( $existing ) ) {
					return new WP_Error(
						'downtime_not_owned',
						/* translators: %s: monitor name */
						sprintf( __( '"%s" is paused and was set up outside this site. Resume it in the DownTime dashboard.', 'downtime-uptime-monitoring' ), (string) ( $existing['name'] ?? '' ) )
					);
				}
				$result = self::update_active( $existing, true );
				if ( ! empty( $existing['unlisted'] ) && ! is_wp_error( $result ) && 404 === $result['code'] ) {
					// Deleted on DownTime since this site paused it.
					self::remember_paused( $key, null );
					return self::turn_on( $key, $check, null );
				}
				if ( ! Downtime_API::ok( $result ) ) {
					return new WP_Error( 'downtime_api', self::change_error( $result ) );
				}
				self::remember_paused( $key, null );
				self::adopt( $key, $check, $id );
				return 'resumed';
			}
			self::adopt( $key, $check, $id );
			return 'reused';
		}
		$result = Downtime_API::create_monitor( $check['monitor'] );
		if ( ! Downtime_API::ok( $result ) || empty( $result['body']['id'] ) ) {
			return new WP_Error( 'downtime_api', Downtime_API::error_message( $result ) );
		}
		$id = sanitize_text_field( $result['body']['id'] );
		self::adopt( $key, $check, $id );
		if ( $check['alert'] > 0 ) {
			Downtime_Alerts::enable( $id, $check['alert'] );
		}
		return 'added';
	}

	/** Turn checks on in order, then redirect with one summary notice. */
	public static function add_checks( $picked ) {
		$checks   = self::checks();
		$assigned = self::assign( $checks, Downtime_Admin::list_monitors() );
		$done     = array(
			'added'   => array(),
			'reused'  => array(),
			'resumed' => array(),
		);
		$errors   = array();
		foreach ( $picked as $key ) {
			if ( ! isset( $checks[ $key ] ) ) {
				continue;
			}
			if ( ! empty( $checks[ $key ]['needs_text'] ) && '' === self::content_text() ) {
				/* translators: %s: check name */
				$errors[] = sprintf( __( '%s: enter the text to look for first.', 'downtime-uptime-monitoring' ), $checks[ $key ]['label'] );
				continue;
			}
			$outcome = self::turn_on( $key, $checks[ $key ], $assigned[ $key ] );
			if ( is_wp_error( $outcome ) ) {
				$errors[] = $checks[ $key ]['label'] . ': ' . $outcome->get_error_message();
				// Most likely the plan's monitor limit or an outage: the rest would fail the same way.
				if ( 'downtime_api' === $outcome->get_error_code() ) {
					break;
				}
				continue;
			}
			$done[ $outcome ][] = $checks[ $key ]['label'];
		}
		$parts = array();
		if ( $done['added'] ) {
			/* translators: %s: comma-separated list of checks */
			$parts[] = sprintf( __( 'Turned on: %s. The first results appear within a few minutes.', 'downtime-uptime-monitoring' ), implode( ', ', $done['added'] ) );
		}
		if ( $done['resumed'] ) {
			/* translators: %s: comma-separated list of checks */
			$parts[] = sprintf( __( 'Resumed: %s.', 'downtime-uptime-monitoring' ), implode( ', ', $done['resumed'] ) );
		}
		if ( $done['reused'] ) {
			/* translators: %s: comma-separated list of checks */
			$parts[] = sprintf( __( 'Already on your DownTime account, reused: %s.', 'downtime-uptime-monitoring' ), implode( ', ', $done['reused'] ) );
		}
		$parts = array_merge( $parts, $errors );
		if ( ! $parts ) {
			Downtime_Admin::done( 'warning', __( 'Nothing to turn on.', 'downtime-uptime-monitoring' ) );
		}
		Downtime_Admin::done( $errors ? ( count( $errors ) === count( $parts ) ? 'error' : 'warning' ) : 'success', implode( ' ', $parts ) );
	}

	public static function handle_check_on() {
		Downtime_Admin::require_admin( 'downtime_check_on' );
		$key = self::posted_check();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked by require_admin() above.
		if ( isset( $_POST['content_text'] ) ) {
			$text = self::sanitize_content_text( sanitize_text_field( wp_unslash( $_POST['content_text'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$err  = self::content_text_error( $text );
			if ( $err ) {
				Downtime_Admin::done( 'error', $err );
			}
			update_option( self::OPTION_CONTENT, $text, false );
		}
		self::add_checks( array( $key ) );
	}

	/**
	 * Fetch the homepage now and make sure the text is on it, so the check
	 * doesn't start out failing.
	 */
	private static function content_text_error( $text ) {
		if ( strlen( $text ) < 3 ) {
			return __( 'Enter at least 3 characters of text that always appears on your homepage.', 'downtime-uptime-monitoring' );
		}
		$page = wp_remote_get(
			home_url( '/' ),
			array(
				'timeout'    => 10,
				'user-agent' => 'DownTime-WordPress/' . DOWNTIME_PLUGIN_VERSION,
			)
		);
		if ( is_wp_error( $page ) ) {
			return null; // can't check from here (e.g. loopback blocked); let DownTime decide
		}
		// DownTime reads the first 256 KB of the page.
		if ( false === strpos( substr( (string) wp_remote_retrieve_body( $page ), 0, 256 * 1024 ), $text ) ) {
			/* translators: %s: the text the admin entered */
			return sprintf( __( '"%s" is not on your homepage right now (it must match the page source exactly, including capitals). Pick text that always appears, such as your site name or a footer line.', 'downtime-uptime-monitoring' ), $text );
		}
		return null;
	}

	public static function handle_preset() {
		Downtime_Admin::require_admin( 'downtime_preset' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked by require_admin() above.
		$all    = isset( $_POST['preset'] ) && 'all' === sanitize_key( wp_unslash( $_POST['preset'] ) );
		$picked = array();
		foreach ( self::checks() as $key => $check ) {
			if ( ! empty( $check['needs_text'] ) && '' === self::content_text() ) {
				continue;
			}
			if ( $all || ! empty( $check['essentials'] ) ) {
				$picked[] = $key;
			}
		}
		self::add_checks( $picked );
	}

	/** The pre-0.5.0 checklist form. */
	public static function handle_add_checks() {
		Downtime_Admin::require_admin( 'downtime_add_checks' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked by require_admin() above.
		$picked = isset( $_POST['checks'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['checks'] ) ) : array();
		self::add_checks( $picked );
	}

	/** Off pauses the check's monitor: it keeps its history and comes back with On. */
	public static function handle_check_off() {
		Downtime_Admin::require_admin( 'downtime_check_off' );
		$key      = self::posted_check();
		$checks   = self::checks();
		$assigned = self::assign( $checks, Downtime_Admin::list_monitors() );
		$m        = $assigned[ $key ];
		if ( ! $m ) {
			Downtime_Admin::done( 'success', __( 'That check is already off.', 'downtime-uptime-monitoring' ) );
		}
		if ( ! self::owns( $m ) ) {
			if ( 'heartbeat' === $key ) {
				Downtime_Heartbeat::disable();
				Downtime_Admin::done( 'warning', __( 'Stopped sending WP-Cron heartbeats. The heartbeat monitor was set up outside this site, so pause or delete it in the DownTime dashboard, or it will report WP-Cron as down.', 'downtime-uptime-monitoring' ) );
			}
			Downtime_Admin::done( 'error', __( 'DownTime only lets this site change monitors it created. Pause this one in the DownTime dashboard.', 'downtime-uptime-monitoring' ) );
		}
		$result = self::update_active( $m, false );
		if ( ! Downtime_API::ok( $result ) ) {
			Downtime_Admin::done( 'error', self::change_error( $result ) );
		}
		self::remember_paused( $key, (string) $m['id'] );
		if ( 'heartbeat' === $key ) {
			// A paused heartbeat doesn't need pings.
			Downtime_Heartbeat::disable();
		}
		/* translators: %s: check name */
		Downtime_Admin::done( 'success', sprintf( __( '%s is off. Its monitor is paused on DownTime and keeps its history; turn it back on any time.', 'downtime-uptime-monitoring' ), $checks[ $key ]['label'] ) );
	}

	/** Remove deletes the check's monitor, history included. */
	public static function handle_check_remove() {
		Downtime_Admin::require_admin( 'downtime_check_remove' );
		$key      = self::posted_check();
		$assigned = self::assign( self::checks(), Downtime_Admin::list_monitors() );
		$m        = $assigned[ $key ];
		if ( ! $m ) {
			Downtime_Admin::done( 'success', __( 'That check has no monitor to remove.', 'downtime-uptime-monitoring' ) );
		}
		self::remember_paused( $key, null );
		self::delete_monitor( (string) $m['id'] );
	}

	public static function handle_pause() {
		Downtime_Admin::require_admin( 'downtime_monitor_pause' );
		self::set_active( false );
	}

	public static function handle_resume() {
		Downtime_Admin::require_admin( 'downtime_monitor_resume' );
		self::set_active( true );
	}

	/**
	 * DownTime's monitor update replaces the whole definition, so resend
	 * the monitor as listed with only `active` changed.
	 */
	private static function update_active( $m, $active ) {
		$map  = array(
			'name'                      => 'name',
			'url'                       => 'url',
			'type'                      => 'type',
			'region'                    => 'region',
			'interval'                  => 'interval',
			'timeout'                   => 'timeout',
			'method'                    => 'method',
			'expectedCode'              => 'expected_code',
			'headers'                   => 'headers',
			'body'                      => 'body',
			'port'                      => 'port',
			'followRedirects'           => 'follow_redirects',
			'conditions'                => 'conditions',
			'maxResponseTimeMs'         => 'max_response_time_ms',
			'certExpiryThresholdDays'   => 'cert_expiry_threshold_days',
			'domainExpiryThresholdDays' => 'domain_expiry_threshold_days',
			'resourceVersion'           => 'resource_version',
		);
		$body = array( 'active' => (bool) $active );
		foreach ( $map as $from => $to ) {
			if ( isset( $m[ $from ] ) ) {
				$body[ $to ] = $m[ $from ];
			}
		}
		return Downtime_API::update_monitor( $m['id'], $body );
	}

	private static function set_active( $active ) {
		$m = self::find( self::posted_id() );
		if ( ! $m ) {
			Downtime_Admin::done( 'error', __( 'That monitor no longer exists on DownTime.', 'downtime-uptime-monitoring' ) );
		}
		$result = self::update_active( $m, $active );
		if ( ! Downtime_API::ok( $result ) ) {
			Downtime_Admin::done( 'error', self::change_error( $result ) );
		}
		Downtime_Admin::done( 'success', $active ? __( 'Monitor resumed.', 'downtime-uptime-monitoring' ) : __( 'Monitor paused. It keeps its history and alerts, and stops checking until you resume it.', 'downtime-uptime-monitoring' ) );
	}

	public static function handle_delete() {
		Downtime_Admin::require_admin( 'downtime_monitor_delete' );
		$id = self::posted_id();
		if ( '' === $id ) {
			Downtime_Admin::done( 'error', __( 'That monitor no longer exists on DownTime.', 'downtime-uptime-monitoring' ) );
		}
		self::delete_monitor( $id );
	}

	/** Delete a monitor (DownTime removes its alert rules too) and forget it here. Redirects. */
	private static function delete_monitor( $id ) {
		$result = Downtime_API::delete_monitor( $id );
		if ( ! Downtime_API::ok( $result ) && ! ( ! is_wp_error( $result ) && 404 === $result['code'] ) ) {
			Downtime_Admin::done( 'error', self::change_error( $result ) );
		}
		if ( get_option( Downtime_Admin::OPTION_MONITOR_ID ) === $id ) {
			delete_option( Downtime_Admin::OPTION_MONITOR_ID );
			delete_option( Downtime_Admin::OPTION_ALERT_ID );
		}
		if ( Downtime_Heartbeat::monitor_id() === $id ) {
			Downtime_Heartbeat::disable();
			delete_option( Downtime_Admin::OPTION_HB_ALERT );
		}
		Downtime_Admin::done( 'success', __( 'Monitor deleted, along with its history and alerts.', 'downtime-uptime-monitoring' ) );
	}

	private static function change_error( $result ) {
		if ( ! is_wp_error( $result ) && 403 === $result['code'] ) {
			return __( 'DownTime only lets this site change monitors it created. Change this one in the DownTime dashboard.', 'downtime-uptime-monitoring' );
		}
		return Downtime_API::error_message( $result );
	}

	public static function handle_wp_status_page() {
		Downtime_Admin::require_admin( 'downtime_wp_status_page' );
		if ( '' === Downtime_Embed::default_slug() ) {
			Downtime_Admin::done( 'error', __( 'Create or pick a DownTime status page first.', 'downtime-uptime-monitoring' ) );
		}
		$existing = self::wp_page();
		if ( $existing ) {
			Downtime_Admin::done( 'success', __( 'This site already has a status page.', 'downtime-uptime-monitoring' ) );
		}
		// The block with no slug follows whichever status page is picked here.
		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => __( 'Status', 'downtime-uptime-monitoring' ),
				'post_name'    => 'status',
				'post_content' => '<!-- wp:downtime/status /-->',
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			Downtime_Admin::done( 'error', $id->get_error_message() );
		}
		update_option( self::OPTION_WP_PAGE, (int) $id, false );
		Downtime_Admin::done( 'success', __( 'Published a Status page on this site. Add it to your menu under Appearance.', 'downtime-uptime-monitoring' ) );
	}

	/** The page this plugin published, unless it was deleted or trashed since. */
	private static function wp_page() {
		$id   = (int) get_option( self::OPTION_WP_PAGE, 0 );
		$page = $id ? get_post( $id ) : null;
		if ( ! $page || 'page' !== $page->post_type || 'trash' === $page->post_status ) {
			return null;
		}
		return $page;
	}

	// --- Rendering ------------------------------------------------------------

	/** An on/off switch: a submit button, so it works without JavaScript. */
	public static function toggle( $action, $on, $label, $fields, $disabled = false ) {
		$cls = 'downtime-toggle' . ( $on ? ' is-on' : '' );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="downtime-toggle-form">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( $action );
		echo $fields; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts by callers.
		echo '<button type="submit" class="' . esc_attr( $cls ) . '" role="switch" aria-checked="' . ( $on ? 'true' : 'false' ) . '" aria-label="' . esc_attr( $label ) . '"' . disabled( $disabled, true, false ) . '><span class="downtime-toggle-knob" aria-hidden="true"></span></button>';
		echo '</form>';
	}

	/** One line describing a monitor's latest state, from the widget's cached data. */
	private static function state( $m, $latest ) {
		if ( ! $m ) {
			return array( 'off', __( 'Off', 'downtime-uptime-monitoring' ) );
		}
		if ( empty( $m['active'] ) ) {
			return array( 'paused', __( 'Paused', 'downtime-uptime-monitoring' ) );
		}
		if ( ! $latest ) {
			return array( 'waiting', __( 'On, waiting for the first check', 'downtime-uptime-monitoring' ) );
		}
		if ( 'ok' === ( $latest['status'] ?? '' ) ) {
			return array( 'up', __( 'Up', 'downtime-uptime-monitoring' ) );
		}
		$down = __( 'Down', 'downtime-uptime-monitoring' );
		return array( 'down', ! empty( $latest['error'] ) ? $down . ': ' . Downtime_Widget::short_error( $latest['error'] ) : $down );
	}

	/**
	 * The Monitoring panel: presets, then one row per check with its state,
	 * an on/off switch and an alert switch.
	 */
	public static function render_panel( $monitors ) {
		self::remember_site_ids( $monitors );
		if ( self::is_preview_site() ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'This is a temporary WordPress Playground site: it runs in your browser and its address changes every session, so DownTime can\'t check it. Install the plugin on your real site to monitor it. You can still try the WP-Cron heartbeat and the status page here.', 'downtime-uptime-monitoring' ) . '</p></div>';
		} elseif ( ! Downtime_Admin::is_public_site() ) {
			/* translators: %s: site URL */
			echo '<p>' . esc_html( sprintf( __( '%s is a local or private address, so DownTime cannot reach it. Site checks become available once the site is on a public address.', 'downtime-uptime-monitoring' ), untrailingslashit( home_url( '/' ) ) ) ) . '</p>';
		}

		$checks   = self::checks();
		$assigned = self::assign( $checks, $monitors );
		$latest   = Downtime_Widget::latest_by_monitor();
		$alerts   = Downtime_Alerts::available();

		echo '<p>' . esc_html__( 'Switch on what DownTime should watch. Checks your account already has are reused, never added twice. Off pauses a check and keeps its history.', 'downtime-uptime-monitoring' ) . '</p>';
		if ( count( $checks ) > 1 ) {
			echo '<p>';
			Downtime_Admin::form( 'downtime_preset', __( 'Turn on the essentials', 'downtime-uptime-monitoring' ), 'button button-primary', '<input type="hidden" name="preset" value="essentials">' );
			Downtime_Admin::form( 'downtime_preset', __( 'Turn on everything', 'downtime-uptime-monitoring' ), 'button', '<input type="hidden" name="preset" value="all">' );
			echo '</p>';
		}

		echo '<table class="widefat striped downtime-checks"><thead><tr>';
		echo '<th>' . esc_html__( 'Check', 'downtime-uptime-monitoring' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'downtime-uptime-monitoring' ) . '</th>';
		echo '<th>' . esc_html__( 'On', 'downtime-uptime-monitoring' ) . '</th>';
		echo '<th>' . esc_html__( 'Alert me', 'downtime-uptime-monitoring' ) . '</th>';
		echo '</tr></thead><tbody>';
		$confirm = esc_js( __( 'Delete this monitor and its history on DownTime?', 'downtime-uptime-monitoring' ) );
		foreach ( $checks as $key => $check ) {
			$m     = $assigned[ $key ];
			$owned = $m && self::owns( $m );
			$on    = $m && ! empty( $m['active'] );
			// A heartbeat found only by name isn't pinged until switched on here.
			if ( $m && 'heartbeat' === $key && Downtime_Heartbeat::monitor_id() !== $m['id'] ) {
				$on = false;
			}
			$state = self::state( $m, $m ? ( $latest[ $m['id'] ] ?? null ) : null );
			$label = $check['label'];
			$check_field = '<input type="hidden" name="check" value="' . esc_attr( $key ) . '">';

			echo '<tr><td><strong>' . esc_html( $label ) . '</strong><br><span class="description">' . esc_html( $check['help'] ) . '</span>';
			if ( $m && ! $owned ) {
				/* translators: %s: monitor name */
				echo '<br><span class="description">' . esc_html( sprintf( __( 'Uses "%s", set up outside this site.', 'downtime-uptime-monitoring' ), (string) ( $m['name'] ?? '' ) ) ) . '</span>';
			}
			echo '</td>';

			echo '<td><span class="downtime-dot is-' . esc_attr( $state[0] ) . '" aria-hidden="true"></span> ' . esc_html( $state[1] ) . '</td><td>';
			if ( $on ) {
				if ( $owned || 'heartbeat' === $key ) {
					/* translators: %s: check name */
					self::toggle( 'downtime_check_off', true, sprintf( __( 'Turn off %s', 'downtime-uptime-monitoring' ), $label ), $check_field );
				} else {
					echo '<a href="' . esc_url( Downtime_API::dashboard_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Managed in DownTime', 'downtime-uptime-monitoring' ) . '</a>';
				}
			} else {
				$fields = $check_field;
				if ( ! empty( $check['needs_text'] ) ) {
					$fields .= '<label class="screen-reader-text" for="downtime-content-text">' . esc_html__( 'Text to look for', 'downtime-uptime-monitoring' ) . '</label>';
					$fields .= '<input type="text" id="downtime-content-text" name="content_text" value="' . esc_attr( self::content_text() ) . '" placeholder="' . esc_attr__( 'Text on your homepage', 'downtime-uptime-monitoring' ) . '" maxlength="80" required class="regular-text downtime-content-text"> ';
				}
				/* translators: %s: check name */
				self::toggle( 'downtime_check_on', false, sprintf( __( 'Turn on %s', 'downtime-uptime-monitoring' ), $label ), $fields );
			}
			if ( $owned ) {
				Downtime_Admin::form( 'downtime_check_remove', __( 'Remove', 'downtime-uptime-monitoring' ), 'button-link button-link-delete downtime-remove', $check_field, 'return confirm(\'' . $confirm . '\')' );
			}
			echo '</td><td>';
			if ( ! $m ) {
				echo '<span class="description">—</span>';
			} elseif ( null === $alerts ) {
				echo '<a href="' . esc_url( Downtime_API::dashboard_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'In DownTime', 'downtime-uptime-monitoring' ) . '</a>';
			} else {
				$rule = Downtime_Alerts::rule_for( $m['id'] );
				$fields = '<input type="hidden" name="monitor_id" value="' . esc_attr( $m['id'] ) . '"><input type="hidden" name="threshold" value="' . esc_attr( (string) max( 1, (int) $check['alert'] ) ) . '">';
				$is_on  = $rule && ! empty( $rule['active'] );
				/* translators: %s: check name */
				self::toggle( $is_on ? 'downtime_alert_off' : 'downtime_alert_on', $is_on, sprintf( $is_on ? __( 'Stop alerts for %s', 'downtime-uptime-monitoring' ) : __( 'Alert me about %s', 'downtime-uptime-monitoring' ), $label ), $fields );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		if ( null !== $alerts ) {
			echo '<p class="description">' . esc_html__( 'Alert me sends a message when the check fails and again when it recovers, to the places chosen under Alerts below. Alerts set up in the DownTime dashboard are not shown here and keep working.', 'downtime-uptime-monitoring' ) . '</p>';
		}

		self::render_others( $monitors, $assigned );
	}

	/** This site's monitors that no switch above controls, e.g. ones added in the dashboard. */
	private static function render_others( $monitors, $assigned ) {
		$used = array();
		foreach ( $assigned as $m ) {
			if ( $m ) {
				$used[ $m['id'] ] = true;
			}
		}
		$others = array();
		foreach ( self::site_monitors( $monitors ) as $m ) {
			if ( ! isset( $used[ $m['id'] ] ) ) {
				$others[] = $m;
			}
		}
		if ( ! $others ) {
			return;
		}
		echo '<h3>' . esc_html__( 'Other monitors for this site', 'downtime-uptime-monitoring' ) . '</h3>';
		echo '<table class="widefat striped downtime-checks"><thead><tr>';
		echo '<th>' . esc_html__( 'Monitor', 'downtime-uptime-monitoring' ) . '</th>';
		echo '<th>' . esc_html__( 'Checks', 'downtime-uptime-monitoring' ) . '</th>';
		echo '<th>' . esc_html__( 'State', 'downtime-uptime-monitoring' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';
		$confirm = esc_js( __( 'Delete this monitor and its history on DownTime?', 'downtime-uptime-monitoring' ) );
		foreach ( $others as $m ) {
			$type = (string) ( $m['type'] ?? '' );
			$url  = (string) ( $m['url'] ?? '' );
			if ( 'heartbeat' === $type ) {
				$what = __( 'WP-Cron heartbeat', 'downtime-uptime-monitoring' );
			} elseif ( 'tcp' === $type && ! empty( $m['port'] ) ) {
				$what = 'TCP ' . $url . ':' . (int) $m['port'];
			} else {
				$what = strtoupper( $type ) . ' ' . $url;
			}
			$every  = isset( $m['interval'] ) ? (int) $m['interval'] : 0;
			$active = ! empty( $m['active'] );
			echo '<tr><td><strong>' . esc_html( (string) ( $m['name'] ?? $m['id'] ) ) . '</strong></td>';
			echo '<td>' . esc_html( $what );
			if ( $every > 0 ) {
				/* translators: %s: human-readable interval, e.g. "5 mins" */
				echo '<br><span class="description">' . esc_html( sprintf( __( 'every %s', 'downtime-uptime-monitoring' ), human_time_diff( 0, $every ) ) ) . '</span>';
			}
			echo '</td><td>' . esc_html( $active ? __( 'Active', 'downtime-uptime-monitoring' ) : __( 'Paused', 'downtime-uptime-monitoring' ) ) . '</td><td>';
			if ( self::owns( $m ) ) {
				$id = '<input type="hidden" name="monitor_id" value="' . esc_attr( $m['id'] ) . '">';
				if ( $active ) {
					Downtime_Admin::form( 'downtime_monitor_pause', __( 'Pause', 'downtime-uptime-monitoring' ), 'button button-small', $id );
				} else {
					Downtime_Admin::form( 'downtime_monitor_resume', __( 'Resume', 'downtime-uptime-monitoring' ), 'button button-small', $id );
				}
				Downtime_Admin::form( 'downtime_monitor_delete', __( 'Delete', 'downtime-uptime-monitoring' ), 'button button-small button-link-delete', $id, 'return confirm(\'' . $confirm . '\')' );
			} else {
				echo '<a href="' . esc_url( Downtime_API::dashboard_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Manage on DownTime', 'downtime-uptime-monitoring' ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** Under the status page section, once one is picked. */
	public static function render_wp_page() {
		echo '<h3>' . esc_html__( 'On this site', 'downtime-uptime-monitoring' ) . '</h3>';
		$page = self::wp_page();
		if ( $page ) {
			$state = 'publish' === $page->post_status ? '' : ' (' . get_post_status_object( $page->post_status )->label . ')';
			/* translators: %s: page title */
			echo '<p>' . esc_html( sprintf( __( 'Your status is shown on the "%s" page', 'downtime-uptime-monitoring' ), get_the_title( $page ) ) . $state ) . ': ';
			echo '<a href="' . esc_url( get_permalink( $page ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'View', 'downtime-uptime-monitoring' ) . '</a> · ';
			echo '<a href="' . esc_url( (string) get_edit_post_link( $page->ID ) ) . '">' . esc_html__( 'Edit', 'downtime-uptime-monitoring' ) . '</a></p>';
			return;
		}
		echo '<p>' . esc_html__( 'Publish a "Status" page on this site that shows the status page above, so visitors can check it without leaving your site.', 'downtime-uptime-monitoring' ) . '</p>';
		Downtime_Admin::form( 'downtime_wp_status_page', __( 'Add a Status page to this site', 'downtime-uptime-monitoring' ), 'button' );
	}
}
