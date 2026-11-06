<?php
/**
 * Settings → DownTime, the parts that manage this site's checks:
 *
 * - Set up monitoring: one checklist of WordPress-specific monitors
 *   (homepage, login page, REST API, sitemap, TLS certificate, DNS, TCP
 *   port, ping, WP-Cron heartbeat) added in one click. A check the account
 *   already has is reused, never created twice, wherever it came from.
 * - This site's monitors: pause, resume and delete them from wp-admin.
 *   DownTime only lets this site's key change monitors it created itself
 *   (chart 0.31.0+); others link to the DownTime dashboard instead.
 * - A "Status" page on this site with the status page block in it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Downtime_Checks {

	const OPTION_WP_PAGE   = 'downtime_wp_status_page_id';
	const OPTION_TLS_ALERT = 'downtime_tls_alert_rule_id';

	public static function init() {
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
	 * Every check the plugin offers, keyed by a stable ID. `monitor` is the
	 * full definition sent to DownTime; `alert` is the email alert threshold
	 * and the option that remembers the rule, for the checks that alert.
	 * Only the heartbeat is offered on a site DownTime can't reach.
	 */
	public static function checks() {
		$name   = self::site_name();
		$checks = array();
		if ( Downtime_Admin::is_public_site() ) {
			$https = 'https' === wp_parse_url( home_url( '/' ), PHP_URL_SCHEME );
			$type  = $https ? 'https' : 'http';
			$host  = self::host();

			$checks['home']  = array(
				'label'       => __( 'Homepage', 'downtime-uptime-monitoring' ),
				'help'        => __( 'The site answers. Emails you when it goes down and when it recovers.', 'downtime-uptime-monitoring' ),
				'recommended' => true,
				'alert'       => array( 2, Downtime_Admin::OPTION_ALERT_ID ),
				'option'      => Downtime_Admin::OPTION_MONITOR_ID,
				'monitor'     => array(
					'name'     => $name,
					'url'      => home_url( '/' ),
					'type'     => $type,
					// 300s is the smallest interval every plan allows (Core's minimum).
					'interval' => 300,
				),
			);
			$checks['login'] = array(
				'label'       => __( 'Login page', 'downtime-uptime-monitoring' ),
				'help'        => __( 'wp-login.php answers, so you and your users can sign in.', 'downtime-uptime-monitoring' ),
				'recommended' => true,
				'monitor'     => array(
					/* translators: %s: site name */
					'name'          => sprintf( __( '%s: login page', 'downtime-uptime-monitoring' ), $name ),
					'url'           => wp_login_url(),
					'type'          => $type,
					'expected_code' => 200,
					'interval'      => 300,
				),
			);
			$checks['rest']  = array(
				'label'       => __( 'REST API', 'downtime-uptime-monitoring' ),
				'help'        => __( '/wp-json/ answers. The block editor, many plugins and mobile apps depend on it.', 'downtime-uptime-monitoring' ),
				'recommended' => true,
				'monitor'     => array(
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
					'label'       => __( 'XML sitemap', 'downtime-uptime-monitoring' ),
					'help'        => __( 'The sitemap search engines read is reachable.', 'downtime-uptime-monitoring' ),
					'recommended' => true,
					'monitor'     => array(
						/* translators: %s: site name */
						'name'          => sprintf( __( '%s: sitemap', 'downtime-uptime-monitoring' ), $name ),
						'url'           => get_sitemap_url( 'index' ),
						'type'          => $type,
						'expected_code' => 200,
						'interval'      => 3600,
					),
				);
			}
			if ( $https ) {
				$checks['tls'] = array(
					'label'       => __( 'TLS certificate', 'downtime-uptime-monitoring' ),
					'help'        => __( 'Emails you 14 days before the HTTPS certificate expires.', 'downtime-uptime-monitoring' ),
					'recommended' => true,
					'alert'       => array( 1, self::OPTION_TLS_ALERT ),
					'monitor'     => array(
						/* translators: %s: site name */
						'name'                       => sprintf( __( '%s: TLS certificate', 'downtime-uptime-monitoring' ), $name ),
						'url'                        => home_url( '/' ),
						'type'                       => 'tls',
						'cert_expiry_threshold_days' => 14,
						'interval'                   => 3600,
					),
				);
			}
			$checks['dns'] = array(
				'label'       => __( 'DNS', 'downtime-uptime-monitoring' ),
				'help'        => __( 'The domain name still resolves, e.g. after a registrar or DNS host change.', 'downtime-uptime-monitoring' ),
				'recommended' => true,
				'monitor'     => array(
					/* translators: %s: site name */
					'name'     => sprintf( __( '%s: DNS', 'downtime-uptime-monitoring' ), $name ),
					'url'      => $host,
					'type'     => 'dns',
					'interval' => 600,
				),
			);
			$port          = $https ? 443 : 80;
			$checks['tcp'] = array(
				/* translators: %d: port number */
				'label'       => sprintf( __( 'Server port %d', 'downtime-uptime-monitoring' ), $port ),
				'help'        => __( 'The web server accepts connections. Tells a server outage apart from a WordPress error.', 'downtime-uptime-monitoring' ),
				'recommended' => false,
				'monitor'     => array(
					/* translators: %s: site name */
					'name'     => sprintf( __( '%s: server port', 'downtime-uptime-monitoring' ), $name ),
					'url'      => $host,
					'type'     => 'tcp',
					'port'     => $port,
					'interval' => 300,
				),
			);
			$checks['ping'] = array(
				'label'       => __( 'Ping', 'downtime-uptime-monitoring' ),
				'help'        => __( 'The server answers ping. Many hosts and CDNs block ping, so only add it if yours allows it.', 'downtime-uptime-monitoring' ),
				'recommended' => false,
				'monitor'     => array(
					/* translators: %s: site name */
					'name'     => sprintf( __( '%s: ping', 'downtime-uptime-monitoring' ), $name ),
					'url'      => $host,
					'type'     => 'ping',
					'interval' => 300,
				),
			);
		}
		$checks['heartbeat'] = array(
			'label'       => __( 'WP-Cron', 'downtime-uptime-monitoring' ),
			'help'        => __( 'Scheduled posts, backups and updates keep running. This site pings DownTime every hour; you get an email if pings stop for 90 minutes. WP-Cron only runs when the site gets visits, so on a very quiet site, run it from a real system cron.', 'downtime-uptime-monitoring' ),
			'recommended' => true,
			'alert'       => array( 1, Downtime_Admin::OPTION_HB_ALERT ),
			'monitor'     => array(
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

	/**
	 * The account monitor that already does what a check would, if any:
	 * same kind of probe against the same target, wherever it was created
	 * (this site, an earlier install of it, or the dashboard). HTTP and
	 * HTTPS count as the same kind. A heartbeat has no target, so it is the
	 * one this site pings, else one this site created, else one with this
	 * site's heartbeat name (e.g. from an earlier install or preview).
	 */
	public static function find_existing( $check, $monitors ) {
		$want = $check['monitor'];
		if ( 'heartbeat' === $want['type'] ) {
			$by_name = null;
			foreach ( $monitors as $m ) {
				if ( empty( $m['id'] ) || 'heartbeat' !== ( $m['type'] ?? '' ) ) {
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
		$target = self::target( $want['type'], $want['url'], $want['port'] ?? 0 );
		foreach ( $monitors as $m ) {
			$type = (string) ( $m['type'] ?? '' );
			if ( empty( $m['id'] ) ) {
				continue;
			}
			$same_kind = $type === $want['type'] || ( in_array( $type, $web, true ) && in_array( $want['type'], $web, true ) );
			if ( $same_kind && self::target( $want['type'], $m['url'] ?? '', $m['port'] ?? 0 ) === $target ) {
				return $m;
			}
		}
		return null;
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
			$mine   = self::site() === ( $m['integrationSite'] ?? '' );
			$points = '' !== $host && self::target( 'dns', $m['url'] ?? '', 0 ) === $host;
			if ( $mine || $points || Downtime_Heartbeat::monitor_id() === $m['id'] ) {
				$out[] = $m;
			}
		}
		return $out;
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

	// --- Handlers -------------------------------------------------------------

	public static function handle_add_checks() {
		Downtime_Admin::require_admin( 'downtime_add_checks' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked by require_admin() above.
		$picked = isset( $_POST['checks'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['checks'] ) ) : array();
		self::add_checks( $picked );
	}

	/** Add the picked checks, reusing any the account already has. Redirects. */
	public static function add_checks( $picked ) {
		$checks   = self::checks();
		$monitors = Downtime_Admin::list_monitors();
		$added    = array();
		$reused   = array();
		$no_alert = array();
		foreach ( $picked as $key ) {
			if ( ! isset( $checks[ $key ] ) ) {
				continue;
			}
			$check    = $checks[ $key ];
			$existing = self::find_existing( $check, $monitors );
			if ( $existing ) {
				self::adopt( $key, $check, sanitize_text_field( $existing['id'] ) );
				$reused[] = $check['label'];
				continue;
			}
			$result = Downtime_API::create_monitor( $check['monitor'] );
			if ( ! Downtime_API::ok( $result ) || empty( $result['body']['id'] ) ) {
				$message = Downtime_API::error_message( $result );
				if ( $added ) {
					/* translators: 1: checks that were added, 2: error message */
					$message = sprintf( __( 'Added %1$s, then stopped: %2$s', 'downtime-uptime-monitoring' ), implode( ', ', $added ), $message );
				}
				Downtime_Admin::done( 'error', $message );
			}
			$monitor_id = sanitize_text_field( $result['body']['id'] );
			self::adopt( $key, $check, $monitor_id );
			// This site's key can't list alert rules, so a rule is only ever
			// added along with a monitor this site just created.
			if ( ! empty( $check['alert'] ) && ! Downtime_Admin::add_email_alert( $monitor_id, $check['alert'][1], $check['alert'][0] ) ) {
				$no_alert[] = $check['label'];
			}
			$added[] = $check['label'];
		}
		if ( ! $added && ! $reused ) {
			Downtime_Admin::done( 'warning', __( 'Pick at least one check.', 'downtime-uptime-monitoring' ) );
		}
		$parts = array();
		if ( $added ) {
			/* translators: %s: comma-separated list of checks */
			$parts[] = sprintf( __( 'Added: %s. The first results appear within a few minutes.', 'downtime-uptime-monitoring' ), implode( ', ', $added ) );
		}
		if ( $reused ) {
			/* translators: %s: comma-separated list of checks */
			$parts[] = sprintf( __( 'Already on your DownTime account, reused: %s.', 'downtime-uptime-monitoring' ), implode( ', ', $reused ) );
		}
		if ( $no_alert ) {
			/* translators: %s: comma-separated list of checks */
			$parts[] = sprintf( __( 'Email alerts could not be turned on for: %s. Add them in the DownTime dashboard under Alerts.', 'downtime-uptime-monitoring' ), implode( ', ', $no_alert ) );
		}
		Downtime_Admin::done( $no_alert ? 'warning' : 'success', implode( ' ', $parts ) );
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
	private static function set_active( $active ) {
		$m = self::find( self::posted_id() );
		if ( ! $m ) {
			Downtime_Admin::done( 'error', __( 'That monitor no longer exists on DownTime.', 'downtime-uptime-monitoring' ) );
		}
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
		$result = Downtime_API::update_monitor( $m['id'], $body );
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
		$result = Downtime_API::delete_monitor( $id );
		if ( ! Downtime_API::ok( $result ) && ! ( ! is_wp_error( $result ) && 404 === $result['code'] ) ) {
			Downtime_Admin::done( 'error', self::change_error( $result ) );
		}
		// Forget what this plugin tracked about the monitor.
		if ( get_option( Downtime_Admin::OPTION_MONITOR_ID ) === $id ) {
			delete_option( Downtime_Admin::OPTION_MONITOR_ID );
			delete_option( Downtime_Admin::OPTION_ALERT_ID );
		}
		if ( Downtime_Heartbeat::monitor_id() === $id ) {
			Downtime_Heartbeat::disable();
			delete_option( Downtime_Admin::OPTION_HB_ALERT );
		}
		Downtime_Admin::done( 'success', __( 'Monitor deleted.', 'downtime-uptime-monitoring' ) );
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

	/**
	 * The checklist. Checks the account already covers are listed as such
	 * (the homepage one is remembered as this site's monitor); the rest are
	 * offered with the recommended ones ticked. A heartbeat found only by
	 * name isn't pinged until the admin asks, so it stays offered, with a
	 * note that adding it reuses the existing monitor.
	 */
	public static function render_setup( $monitors ) {
		if ( self::is_preview_site() ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'This is a temporary WordPress Playground site: it runs in your browser and its address changes every session, so DownTime can\'t check it. Install the plugin on your real site to monitor it. You can still try the WP-Cron heartbeat and the status page here.', 'downtime-uptime-monitoring' ) . '</p></div>';
		} elseif ( ! Downtime_Admin::is_public_site() ) {
			/* translators: %s: site URL */
			echo '<p>' . esc_html( sprintf( __( '%s is a local or private address, so DownTime cannot reach it. Site checks become available once the site is on a public address.', 'downtime-uptime-monitoring' ), untrailingslashit( home_url( '/' ) ) ) ) . '</p>';
		}
		$rows    = '';
		$covered = '';
		foreach ( self::checks() as $key => $check ) {
			$existing = self::find_existing( $check, $monitors );
			$note     = '';
			if ( $existing && 'heartbeat' === $key && Downtime_Heartbeat::monitor_id() !== $existing['id'] ) {
				/* translators: %s: monitor name */
				$note     = ' <em>' . esc_html( sprintf( __( 'Reuses your existing "%s" monitor.', 'downtime-uptime-monitoring' ), (string) ( $existing['name'] ?? '' ) ) ) . '</em>';
				$existing = null;
			}
			if ( $existing ) {
				if ( 'home' === $key ) {
					self::adopt( $key, $check, sanitize_text_field( $existing['id'] ) );
				}
				$covered .= '<li><span class="dashicons dashicons-yes" style="color:#00a32a" aria-hidden="true"></span> <strong>' . esc_html( $check['label'] ) . '</strong> <span class="description">' . esc_html( (string) ( $existing['name'] ?? '' ) ) . '</span></li>';
				continue;
			}
			$rows .= '<label style="display:block;margin:6px 0"><input type="checkbox" name="checks[]" value="' . esc_attr( $key ) . '"' . checked( $check['recommended'], true, false ) . '> <strong>' . esc_html( $check['label'] ) . '</strong> <span class="description">' . esc_html( $check['help'] ) . '</span>' . $note . '</label>';
		}
		if ( '' !== $covered ) {
			echo '<p>' . esc_html__( 'Already monitored:', 'downtime-uptime-monitoring' ) . '</p><ul>' . $covered . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
		}
		if ( '' === $rows ) {
			return;
		}
		echo '<p>' . esc_html( '' === $covered ? __( 'Pick what DownTime should watch. Checks your account already has are reused, never added twice.', 'downtime-uptime-monitoring' ) : __( 'Add more checks:', 'downtime-uptime-monitoring' ) ) . '</p>';
		Downtime_Admin::form( 'downtime_add_checks', __( 'Add selected checks', 'downtime-uptime-monitoring' ), 'button button-primary', '<fieldset style="margin:0 0 8px">' . $rows . '</fieldset>' );
	}

	public static function render_table( $monitors ) {
		$mine = self::site_monitors( $monitors );
		if ( ! $mine ) {
			return;
		}
		echo '<h3>' . esc_html__( "This site's monitors", 'downtime-uptime-monitoring' ) . '</h3>';
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr>';
		echo '<th>' . esc_html__( 'Monitor', 'downtime-uptime-monitoring' ) . '</th>';
		echo '<th>' . esc_html__( 'Checks', 'downtime-uptime-monitoring' ) . '</th>';
		echo '<th>' . esc_html__( 'State', 'downtime-uptime-monitoring' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';
		$confirm = esc_js( __( 'Delete this monitor and its history on DownTime?', 'downtime-uptime-monitoring' ) );
		foreach ( $mine as $m ) {
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
			if ( self::site() === ( $m['integrationSite'] ?? '' ) ) {
				$id = '<input type="hidden" name="monitor_id" value="' . esc_attr( $m['id'] ) . '">';
				if ( $active ) {
					Downtime_Admin::form( 'downtime_monitor_pause', __( 'Pause', 'downtime-uptime-monitoring' ), 'button button-small', $id );
				} else {
					Downtime_Admin::form( 'downtime_monitor_resume', __( 'Resume', 'downtime-uptime-monitoring' ), 'button button-small', $id );
				}
				Downtime_Admin::form( 'downtime_monitor_delete', __( 'Delete', 'downtime-uptime-monitoring' ), 'button button-small button-link-delete', $id, 'return confirm(\'' . $confirm . '\')' );
			} else {
				echo '<a href="' . esc_url( Downtime_API::base_url() . '/' ) . '" target="_blank" rel="noopener">' . esc_html__( 'Manage on DownTime', 'downtime-uptime-monitoring' ) . '</a>';
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
