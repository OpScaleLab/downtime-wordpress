<?php
/**
 * Settings → DownTime: connect/disconnect, one-click "monitor this site",
 * status page setup, and copy-paste embed snippets.
 *
 * Every state-changing action is an admin-post.php form protected by a
 * WordPress nonce and the manage_options capability. Connect is the one
 * exception: the button is a plain link to DownTime's approval page, and
 * the callback arrives as a redirect from DownTime, so it is protected by
 * the `state` value instead (random, per-user, single-use, 30-minute
 * transient, minted when the settings page renders).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Downtime_Admin {

	const PAGE              = 'downtime';
	const OPTION_MONITOR_ID = 'downtime_site_monitor_id';
	const OPTION_ALERT_ID   = 'downtime_site_alert_rule_id';
	const OPTION_HB_ALERT   = 'downtime_heartbeat_alert_rule_id';
	const STATE_TRANSIENT   = 'downtime_connect_state_';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_downtime_connect_callback', array( __CLASS__, 'handle_connect_callback' ) );
		add_action( 'admin_post_downtime_disconnect', array( __CLASS__, 'handle_disconnect' ) );
		add_action( 'admin_post_downtime_monitor_site', array( __CLASS__, 'handle_monitor_site' ) );
		add_action( 'admin_post_downtime_status_page', array( __CLASS__, 'handle_status_page' ) );
		add_action( 'admin_post_downtime_use_status_page', array( __CLASS__, 'handle_use_status_page' ) );
		add_action( 'admin_post_downtime_email_alerts', array( __CLASS__, 'handle_email_alerts' ) );
		add_action( 'admin_post_downtime_heartbeat_on', array( __CLASS__, 'handle_heartbeat_on' ) );
		add_action( 'admin_post_downtime_heartbeat_off', array( __CLASS__, 'handle_heartbeat_off' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DOWNTIME_PLUGIN_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function menu() {
		add_options_page( __( 'DownTime', 'downtime-uptime-monitoring' ), __( 'DownTime', 'downtime-uptime-monitoring' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function enqueue( $hook ) {
		if ( 'settings_page_' . self::PAGE === $hook ) {
			wp_enqueue_script( 'downtime-admin', DOWNTIME_PLUGIN_URL . 'assets/admin.js', array(), DOWNTIME_PLUGIN_VERSION, true );
		}
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Settings', 'downtime-uptime-monitoring' ) . '</a>' );
		return $links;
	}

	private static function page_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'options-general.php' ) );
	}

	private static function callback_url() {
		return admin_url( 'admin-post.php?action=downtime_connect_callback' );
	}

	private static function require_admin( $nonce_action = null ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'downtime-uptime-monitoring' ), 403 );
		}
		if ( null !== $nonce_action ) {
			check_admin_referer( $nonce_action );
		}
	}

	/** Redirect back to the settings page with a one-shot notice. */
	private static function done( $type, $message ) {
		Downtime_Widget::flush();
		set_transient( 'downtime_notice_' . get_current_user_id(), array( $type, $message ), 60 );
		wp_safe_redirect( self::page_url() );
		exit;
	}

	// --- Connect / disconnect ----------------------------------------------

	/**
	 * The DownTime approval URL for this site, with a fresh state. The
	 * Connect button links straight to it rather than going through an
	 * admin-post redirect: WordPress Playground's service worker mangles a
	 * redirect to another host. The state check in the callback is what
	 * ties the approval back to this admin.
	 */
	private static function connect_url() {
		$state = bin2hex( random_bytes( 16 ) );
		set_transient( self::STATE_TRANSIENT . get_current_user_id(), $state, 30 * MINUTE_IN_SECONDS );

		return add_query_arg(
			array(
				'client'       => 'wordpress',
				'site'         => rawurlencode( home_url( '/' ) ),
				'redirect_uri' => rawurlencode( self::callback_url() ),
				'state'        => $state,
			),
			Downtime_API::base_url() . '/connect'
		);
	}

	public static function handle_connect_callback() {
		self::require_admin();
		$transient = self::STATE_TRANSIENT . get_current_user_id();
		$expected  = get_transient( $transient );
		delete_transient( $transient ); // single-use, whatever happens next

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- protected by `state`.
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
		// phpcs:enable

		if ( ! $expected || ! hash_equals( $expected, $state ) ) {
			self::done( 'error', __( 'Connection expired or was not started from this site. Please try again.', 'downtime-uptime-monitoring' ) );
		}
		if ( '' !== $error ) {
			self::done( 'warning', __( 'Connection was cancelled on DownTime.', 'downtime-uptime-monitoring' ) );
		}
		if ( '' === $code ) {
			self::done( 'error', __( 'DownTime did not return a connection code.', 'downtime-uptime-monitoring' ) );
		}

		$result = Downtime_API::exchange_code( $code, self::callback_url() );
		if ( ! Downtime_API::ok( $result ) || empty( $result['body']['api_key'] ) ) {
			self::done( 'error', Downtime_API::error_message( $result ) );
		}
		Downtime_API::set_key( $result['body']['api_key'] );
		self::done( 'success', __( 'Connected to DownTime.', 'downtime-uptime-monitoring' ) );
	}

	public static function handle_disconnect() {
		self::require_admin( 'downtime_disconnect' );
		// Best effort: revoke the key on DownTime, but always forget it here
		// so a DownTime outage can't trap the site in a connected state.
		$result = Downtime_API::revoke_self();
		Downtime_API::clear_key();
		delete_option( self::OPTION_MONITOR_ID );
		delete_option( self::OPTION_ALERT_ID );
		delete_option( self::OPTION_HB_ALERT );
		Downtime_Heartbeat::disable();
		if ( ! Downtime_API::ok( $result ) ) {
			self::done( 'warning', __( 'Disconnected here, but DownTime could not revoke the key. Revoke it under Settings → Integrations on DownTime.', 'downtime-uptime-monitoring' ) );
		}
		self::done( 'success', __( 'Disconnected from DownTime.', 'downtime-uptime-monitoring' ) );
	}

	// --- Monitor this site --------------------------------------------------

	public static function handle_monitor_site() {
		self::require_admin( 'downtime_monitor_site' );
		if ( ! self::is_public_site() ) {
			self::done( 'error', __( 'This site is on a local or private address, so DownTime cannot reach it.', 'downtime-uptime-monitoring' ) );
		}
		$url    = home_url( '/' );
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$name   = get_bloginfo( 'name' );
		$result = Downtime_API::create_monitor(
			array(
				'name'     => '' !== $name ? $name : wp_parse_url( $url, PHP_URL_HOST ),
				'url'      => $url,
				'type'     => 'https' === $scheme ? 'https' : 'http',
				// 300s is the smallest interval every plan allows (Core's minimum).
				'interval' => 300,
			)
		);
		if ( ! Downtime_API::ok( $result ) || empty( $result['body']['id'] ) ) {
			self::done( 'error', Downtime_API::error_message( $result ) );
		}
		$monitor_id = sanitize_text_field( $result['body']['id'] );
		update_option( self::OPTION_MONITOR_ID, $monitor_id, false );
		if ( ! self::add_email_alert( $monitor_id, self::OPTION_ALERT_ID, 2 ) ) {
			self::done( 'warning', __( 'This site is now monitored, but email alerts could not be turned on. Try "Turn on email alerts" below.', 'downtime-uptime-monitoring' ) );
		}
		self::done( 'success', __( 'This site is now monitored by DownTime. You will get an email if it goes down.', 'downtime-uptime-monitoring' ) );
	}

	/** Create an email alert rule for a monitor and remember its ID. */
	private static function add_email_alert( $monitor_id, $option, $threshold ) {
		$name   = sprintf( '%s (WordPress)', wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$result = Downtime_API::create_email_alert( $monitor_id, $name, $threshold );
		if ( ! Downtime_API::ok( $result ) || empty( $result['body']['id'] ) ) {
			return false;
		}
		update_option( $option, sanitize_text_field( $result['body']['id'] ), false );
		return true;
	}

	public static function handle_email_alerts() {
		self::require_admin( 'downtime_email_alerts' );
		$monitor_id = (string) get_option( self::OPTION_MONITOR_ID, '' );
		if ( '' === $monitor_id ) {
			self::done( 'error', __( 'Monitor this site first.', 'downtime-uptime-monitoring' ) );
		}
		if ( ! self::add_email_alert( $monitor_id, self::OPTION_ALERT_ID, 2 ) ) {
			self::done( 'error', __( 'Email alerts could not be turned on. You can add them in the DownTime dashboard under Alerts.', 'downtime-uptime-monitoring' ) );
		}
		self::done( 'success', __( 'Email alerts are on.', 'downtime-uptime-monitoring' ) );
	}

	// --- WP-Cron heartbeat ----------------------------------------------------

	public static function handle_heartbeat_on() {
		self::require_admin( 'downtime_heartbeat_on' );
		$name   = get_bloginfo( 'name' );
		$name   = ( '' !== $name ? $name : (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) . ' WP-Cron';
		$result = Downtime_API::create_monitor(
			array(
				'name'     => $name,
				'type'     => 'heartbeat',
				'interval' => Downtime_Heartbeat::INTERVAL,
			)
		);
		if ( ! Downtime_API::ok( $result ) || empty( $result['body']['id'] ) ) {
			self::done( 'error', Downtime_API::error_message( $result ) );
		}
		$monitor_id = sanitize_text_field( $result['body']['id'] );
		Downtime_Heartbeat::enable( $monitor_id );
		// A heartbeat is only checked once per interval, so alert on the
		// first miss.
		self::add_email_alert( $monitor_id, self::OPTION_HB_ALERT, 1 );
		self::done( 'success', __( 'WP-Cron is now watched. DownTime emails you if it stops running for more than 90 minutes.', 'downtime-uptime-monitoring' ) );
	}

	public static function handle_heartbeat_off() {
		self::require_admin( 'downtime_heartbeat_off' );
		Downtime_Heartbeat::disable();
		delete_option( self::OPTION_HB_ALERT );
		self::done( 'success', __( 'Stopped sending WP-Cron heartbeats. Pause or delete the heartbeat monitor in the DownTime dashboard, or it will report WP-Cron as down.', 'downtime-uptime-monitoring' ) );
	}

	// --- Status page ----------------------------------------------------------

	public static function handle_status_page() {
		self::require_admin( 'downtime_status_page' );
		// Nonce checked by require_admin() above.
		$slug = isset( $_POST['slug'] ) ? Downtime_Embed::sanitize_slug( sanitize_text_field( wp_unslash( $_POST['slug'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' === $slug ) {
			self::done( 'error', __( 'Slug must be 1–40 lowercase letters, digits or hyphens, starting and ending with a letter or digit.', 'downtime-uptime-monitoring' ) );
		}
		$page = array(
			'slug'  => $slug,
			'title' => get_bloginfo( 'name' ) . ' ' . __( 'Status', 'downtime-uptime-monitoring' ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked by require_admin() above.
		$picked = isset( $_POST['monitor_ids'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['monitor_ids'] ) ) : array();
		$picked = array_values( array_filter( $picked, array( __CLASS__, 'is_uuid' ) ) );
		if ( ! $picked ) {
			// Default to the site monitor, or the WP-Cron heartbeat when the
			// site has no public address (e.g. WordPress Playground).
			$monitor_id = (string) get_option( self::OPTION_MONITOR_ID, '' );
			if ( '' === $monitor_id ) {
				$monitor_id = Downtime_Heartbeat::monitor_id();
			}
			if ( '' !== $monitor_id ) {
				$picked = array( $monitor_id );
			}
		}
		if ( ! $picked ) {
			// An empty list would publish every monitor in the account.
			self::done( 'error', __( 'Pick at least one monitor to show on the status page.', 'downtime-uptime-monitoring' ) );
		}
		$page['monitor_ids'] = $picked;
		$result = Downtime_API::create_status_page( $page );
		if ( ! Downtime_API::ok( $result ) ) {
			if ( ! is_wp_error( $result ) && 409 === $result['code'] && ! empty( $result['body']['suggestions'] ) ) {
				$suggestions = array_map( 'sanitize_text_field', (array) $result['body']['suggestions'] );
				/* translators: %s: comma-separated slug suggestions */
				self::done( 'error', sprintf( __( 'That address is taken. Try: %s', 'downtime-uptime-monitoring' ), implode( ', ', $suggestions ) ) );
			}
			self::done( 'error', Downtime_API::error_message( $result ) );
		}
		update_option( Downtime_Embed::OPTION_SLUG, $slug, false );
		self::done( 'success', __( 'Status page saved.', 'downtime-uptime-monitoring' ) );
	}

	/** Embed an existing status page from the account instead of creating one. */
	public static function handle_use_status_page() {
		self::require_admin( 'downtime_use_status_page' );
		$slug = isset( $_POST['slug'] ) ? Downtime_Embed::sanitize_slug( sanitize_text_field( wp_unslash( $_POST['slug'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' === $slug ) {
			delete_option( Downtime_Embed::OPTION_SLUG );
			self::done( 'success', __( 'Status page cleared. You can create or pick another one.', 'downtime-uptime-monitoring' ) );
		}
		update_option( Downtime_Embed::OPTION_SLUG, $slug, false );
		self::done( 'success', __( 'Status page saved.', 'downtime-uptime-monitoring' ) );
	}

	private static function is_uuid( $id ) {
		return (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id );
	}

	/**
	 * Whether DownTime's probes could reach this site at all. A monitor for
	 * localhost, a .local/.test name or a private IP can be created, but
	 * every check would fail (DownTime refuses to probe private networks),
	 * so the settings page explains that instead of offering the button.
	 */
	private static function is_public_site() {
		$host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$host = trim( $host, '[]' );
		if ( '' === $host || 'localhost' === $host || preg_match( '/\.(localhost|local|test|internal|lan|home\.arpa)$/', $host ) ) {
			return false;
		}
		$ip = filter_var( $host, FILTER_VALIDATE_IP ) ? $host : gethostbyname( $host );
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return (bool) filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		return true; // unresolvable from here; let DownTime decide
	}

	// --- Rendering ------------------------------------------------------------

	/** Accept a bare JSON array or one wrapped as {"<key>": [...]}. */
	private static function items( $result, $key ) {
		if ( ! Downtime_API::ok( $result ) || ! is_array( $result['body'] ) ) {
			return array();
		}
		$body = $result['body'];
		if ( isset( $body[ $key ] ) && is_array( $body[ $key ] ) ) {
			return $body[ $key ];
		}
		return array_values( array_filter( $body, 'is_array' ) );
	}

	private static function form( $action, $label, $class = 'button button-primary', $fields = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-inline-end:8px">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( $action );
		echo $fields; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts by callers.
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	private static function notice() {
		$key    = 'downtime_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! $notice ) {
			return;
		}
		delete_transient( $key );
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $notice[0] ), esc_html( $notice[1] ) );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'DownTime Uptime Monitoring', 'downtime-uptime-monitoring' ) . '</h1>';
		self::notice();

		if ( ! Downtime_API::is_connected() ) {
			echo '<p>' . esc_html__( 'Connect this site to DownTime to monitor its uptime and show a live status badge or status page. You will sign in (or create a free account) on DownTime and approve access for this site.', 'downtime-uptime-monitoring' ) . '</p>';
			echo '<p><a id="downtime-connect" class="button button-primary" href="' . esc_url( self::connect_url() ) . '">' . esc_html__( 'Connect to DownTime', 'downtime-uptime-monitoring' ) . '</a></p>';
			echo '</div>';
			return;
		}

		$user = Downtime_API::get_user();
		echo '<h2>' . esc_html__( 'Connection', 'downtime-uptime-monitoring' ) . '</h2>';
		if ( Downtime_API::ok( $user ) ) {
			$who = ! empty( $user['body']['email'] ) ? $user['body']['email'] : ( $user['body']['name'] ?? '' );
			/* translators: %s: DownTime account email */
			echo '<p>' . esc_html( sprintf( __( 'Connected as %s.', 'downtime-uptime-monitoring' ), $who ) ) . '</p>';
		} elseif ( ! is_wp_error( $user ) && 401 === $user['code'] ) {
			echo '<p>' . esc_html__( 'The stored key is no longer valid (it may have been revoked on DownTime). Disconnect and connect again.', 'downtime-uptime-monitoring' ) . '</p>';
		} else {
			echo '<p>' . esc_html( Downtime_API::error_message( $user ) ) . '</p>';
		}
		self::form( 'downtime_disconnect', __( 'Disconnect', 'downtime-uptime-monitoring' ), 'button' );

		// Monitor.
		echo '<h2>' . esc_html__( 'Monitoring', 'downtime-uptime-monitoring' ) . '</h2>';
		$home     = untrailingslashit( home_url( '/' ) );
		$monitors = self::items( Downtime_API::list_monitors(), 'monitors' );
		$mine     = null;
		foreach ( $monitors as $m ) {
			if ( isset( $m['url'] ) && untrailingslashit( $m['url'] ) === $home ) {
				$mine = $m;
				if ( ! empty( $m['id'] ) && ! get_option( self::OPTION_MONITOR_ID ) ) {
					update_option( self::OPTION_MONITOR_ID, sanitize_text_field( $m['id'] ), false );
				}
				break;
			}
		}
		if ( $mine ) {
			/* translators: %s: site URL */
			echo '<p>' . esc_html( sprintf( __( '%s is monitored.', 'downtime-uptime-monitoring' ), $home ) ) . ' <a href="' . esc_url( Downtime_API::base_url() . '/' ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open DownTime dashboard', 'downtime-uptime-monitoring' ) . '</a></p>';
			if ( get_option( self::OPTION_ALERT_ID ) ) {
				echo '<p>' . esc_html__( 'Email alerts are on: DownTime emails your account address when the site goes down and when it recovers.', 'downtime-uptime-monitoring' ) . '</p>';
			} else {
				echo '<p>' . esc_html__( 'No email alerts set up from this site. If you already set up alerts in the DownTime dashboard, you can ignore this.', 'downtime-uptime-monitoring' ) . '</p>';
				self::form( 'downtime_email_alerts', __( 'Turn on email alerts', 'downtime-uptime-monitoring' ), 'button' );
			}
		} elseif ( ! self::is_public_site() ) {
			/* translators: %s: site URL */
			echo '<p>' . esc_html( sprintf( __( '%s is a local or private address, so DownTime cannot reach it. Monitoring becomes available once the site is on a public address.', 'downtime-uptime-monitoring' ), $home ) ) . '</p>';
		} else {
			/* translators: %s: site URL */
			echo '<p>' . esc_html( sprintf( __( '%s is not monitored yet.', 'downtime-uptime-monitoring' ), $home ) ) . '</p>';
			self::form( 'downtime_monitor_site', __( 'Monitor this site', 'downtime-uptime-monitoring' ) );
		}

		// WP-Cron heartbeat.
		echo '<h2>' . esc_html__( 'WP-Cron', 'downtime-uptime-monitoring' ) . '</h2>';
		if ( '' !== Downtime_Heartbeat::monitor_id() ) {
			$next = wp_next_scheduled( Downtime_Heartbeat::HOOK );
			echo '<p>' . esc_html__( 'Watched: this site pings DownTime every hour from WP-Cron, and DownTime emails you if pings stop for more than 90 minutes.', 'downtime-uptime-monitoring' );
			if ( $next ) {
				/* translators: %s: human-readable time until the next ping */
				echo ' ' . esc_html( sprintf( __( 'Next ping in %s.', 'downtime-uptime-monitoring' ), human_time_diff( time(), $next ) ) );
			}
			echo '</p>';
			self::form( 'downtime_heartbeat_off', __( 'Stop watching WP-Cron', 'downtime-uptime-monitoring' ), 'button' );
		} else {
			echo '<p>' . esc_html__( 'WP-Cron runs scheduled posts, backups and updates. Watch it to get an email when it stops running. WP-Cron only runs when the site gets visits, so on a very quiet site, run it from a real system cron to avoid false alarms.', 'downtime-uptime-monitoring' ) . '</p>';
			self::form( 'downtime_heartbeat_on', __( 'Watch WP-Cron', 'downtime-uptime-monitoring' ), 'button' );
		}

		// Status page.
		echo '<h2>' . esc_html__( 'Status page', 'downtime-uptime-monitoring' ) . '</h2>';
		$slug = Downtime_Embed::default_slug();
		if ( '' !== $slug ) {
			$public = Downtime_API::base_url() . '/status/' . $slug;
			echo '<p><a href="' . esc_url( $public ) . '" target="_blank" rel="noopener">' . esc_html( $public ) . '</a></p>';
			echo '<p>' . wp_kses_post( Downtime_Embed::badge( array( 'slug' => $slug ) ) ) . ' ' . wp_kses_post( Downtime_Embed::badge( array( 'slug' => $slug, 'type' => 'uptime' ) ) ) . '</p>';
			echo '<h3>' . esc_html__( 'Embed it', 'downtime-uptime-monitoring' ) . '</h3>';
			echo '<p>' . esc_html__( 'Use the "DownTime Status" block, or one of these shortcodes:', 'downtime-uptime-monitoring' ) . '</p>';
			echo '<p><code>[downtime_badge]</code> <code>[downtime_badge type="uptime"]</code> <code>[downtime_status]</code></p>';
			self::form( 'downtime_use_status_page', __( 'Use a different status page', 'downtime-uptime-monitoring' ), 'button', '<input type="hidden" name="slug" value="">' );
		} else {
			$pages = self::items( Downtime_API::list_status_pages(), 'status_pages' );
			if ( $pages ) {
				echo '<p>' . esc_html__( 'Use one of your existing status pages:', 'downtime-uptime-monitoring' ) . '</p>';
				$options = '';
				foreach ( $pages as $p ) {
					if ( empty( $p['slug'] ) ) {
						continue;
					}
					$label    = ! empty( $p['title'] ) ? $p['title'] . ' (' . $p['slug'] . ')' : $p['slug'];
					$options .= '<option value="' . esc_attr( $p['slug'] ) . '">' . esc_html( $label ) . '</option>';
				}
				self::form( 'downtime_use_status_page', __( 'Use this page', 'downtime-uptime-monitoring' ), 'button', '<select name="slug">' . $options . '</select> ' );
				echo '<p>' . esc_html__( 'Or create a new one:', 'downtime-uptime-monitoring' ) . '</p>';
			}
			$host      = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
			$suggested = Downtime_Embed::sanitize_slug( preg_replace( '/[^a-z0-9]+/', '-', strtolower( preg_replace( '/^www\./', '', $host ) ) ) );
			echo '<p>' . esc_html__( 'Publish a public status page for this site. Its address will be:', 'downtime-uptime-monitoring' ) . ' <code>' . esc_html( Downtime_API::base_url() . '/status/' ) . '&lt;slug&gt;</code></p>';
			$field = '<input type="text" name="slug" value="' . esc_attr( $suggested ) . '" pattern="[a-z0-9]([a-z0-9\\-]{0,38}[a-z0-9])?" required> ';
			$default_id = (string) get_option( self::OPTION_MONITOR_ID, '' );
			if ( '' === $default_id ) {
				$default_id = Downtime_Heartbeat::monitor_id();
			}
			$boxes = '';
			foreach ( $monitors as $m ) {
				if ( empty( $m['id'] ) ) {
					continue;
				}
				$label  = ! empty( $m['name'] ) ? $m['name'] : ( $m['url'] ?? $m['id'] );
				$boxes .= '<label style="display:block"><input type="checkbox" name="monitor_ids[]" value="' . esc_attr( $m['id'] ) . '"' . checked( $m['id'], $default_id, false ) . '> ' . esc_html( $label ) . '</label>';
			}
			if ( '' === $boxes ) {
				// Creating would fail: an empty list is refused, since the
				// API would otherwise publish every monitor in the account.
				echo '<p>' . esc_html__( 'Add a monitor first (above), then you can publish a status page for it.', 'downtime-uptime-monitoring' ) . '</p>';
			} else {
				$field .= '<fieldset style="margin:8px 0"><legend>' . esc_html__( 'Monitors to show:', 'downtime-uptime-monitoring' ) . '</legend>' . $boxes . '</fieldset>';
				self::form( 'downtime_status_page', __( 'Create status page', 'downtime-uptime-monitoring' ), 'button button-primary', $field );
			}
		}
		echo '</div>';
	}
}
