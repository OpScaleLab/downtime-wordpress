<?php
/**
 * Settings → DownTime: connect/disconnect, status page setup and
 * copy-paste embed snippets. The monitoring switches live in
 * Downtime_Checks, alerts in Downtime_Alerts.
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
	const STATE_TRANSIENT   = 'downtime_connect_state_';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_downtime_connect_callback', array( __CLASS__, 'handle_connect_callback' ) );
		add_action( 'admin_post_downtime_disconnect', array( __CLASS__, 'handle_disconnect' ) );
		add_action( 'admin_post_downtime_monitor_site', array( __CLASS__, 'handle_monitor_site' ) );
		add_action( 'admin_post_downtime_status_page', array( __CLASS__, 'handle_status_page' ) );
		add_action( 'admin_post_downtime_use_status_page', array( __CLASS__, 'handle_use_status_page' ) );
		add_action( 'admin_post_downtime_email_alerts', array( __CLASS__, 'handle_email_alerts' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DOWNTIME_PLUGIN_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function menu() {
		add_options_page( __( 'DownTime', 'downtime-uptime-monitoring' ), __( 'DownTime', 'downtime-uptime-monitoring' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function enqueue( $hook ) {
		if ( 'settings_page_' . self::PAGE === $hook ) {
			wp_enqueue_script( 'downtime-admin', DOWNTIME_PLUGIN_URL . 'assets/admin.js', array(), DOWNTIME_PLUGIN_VERSION, true );
			wp_enqueue_style( 'downtime-admin', DOWNTIME_PLUGIN_URL . 'assets/admin.css', array(), DOWNTIME_PLUGIN_VERSION );
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

	public static function require_admin( $nonce_action = null ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'downtime-uptime-monitoring' ), 403 );
		}
		if ( null !== $nonce_action ) {
			check_admin_referer( $nonce_action );
		}
	}

	/** Redirect back to the settings page with a one-shot notice. */
	public static function done( $type, $message ) {
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
		// A random single-use value plus a WordPress nonce for this admin.
		$state = bin2hex( random_bytes( 16 ) ) . '-' . wp_create_nonce( 'downtime_connect' );
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

	/** The WordPress nonce part of a Connect `state` (after the last "-"). */
	private static function state_nonce( $state ) {
		$pos = strrpos( $state, '-' );
		return false === $pos ? '' : substr( $state, $pos + 1 );
	}

	public static function handle_connect_callback() {
		self::require_admin();
		$transient = self::STATE_TRANSIENT . get_current_user_id();
		$expected  = get_transient( $transient );
		delete_transient( $transient ); // single-use, whatever happens next

		if ( ! isset( $_GET['state'] ) || ! wp_verify_nonce( self::state_nonce( sanitize_text_field( wp_unslash( $_GET['state'] ) ) ), 'downtime_connect' ) ) {
			self::done( 'error', __( 'Connection expired or was not started from this site. Please try again.', 'downtime-uptime-monitoring' ) );
		}
		$state = sanitize_text_field( wp_unslash( $_GET['state'] ) );
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';

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

	/**
	 * Disconnect: by default also delete the monitors this site created on
	 * DownTime and trash the Status page it published, so nothing keeps
	 * checking (or alerting about) a site that left. Then revoke the key,
	 * best effort, and always forget everything here, so a DownTime outage
	 * can't trap the site in a connected state.
	 */
	public static function handle_disconnect() {
		self::require_admin();
		check_admin_referer( 'downtime_disconnect' );
		$keep    = ! empty( $_POST['keep_monitors'] );
		$deleted = 0;
		$failed  = false;
		if ( ! $keep ) {
			$deleted = Downtime_Cleanup::delete_site_monitors();
			if ( is_wp_error( $deleted ) ) {
				$failed  = true;
				$deleted = 0;
			}
			Downtime_Cleanup::trash_wp_page();
		}
		$result = Downtime_API::revoke_self();
		$slug   = Downtime_Embed::default_slug();
		$user   = get_current_user_id();
		Downtime_Cleanup::forget_site();
		delete_transient( Downtime_Alerts::PAIRING . $user );
		if ( $keep && '' !== $slug ) {
			// Kept monitors keep their status page; the badge and block go on showing it.
			update_option( Downtime_Embed::OPTION_SLUG, $slug, false );
		}

		$parts = array( __( 'Disconnected from DownTime.', 'downtime-uptime-monitoring' ) );
		if ( $failed ) {
			$parts[] = __( 'DownTime could not be reached, so this site\'s checks are still there: delete them in the DownTime dashboard.', 'downtime-uptime-monitoring' );
		} elseif ( $deleted ) {
			/* translators: %d: number of monitors */
			$parts[] = sprintf( _n( 'Deleted %d check this site had set up.', 'Deleted %d checks this site had set up.', $deleted, 'downtime-uptime-monitoring' ), $deleted );
		}
		if ( ! $keep && '' !== $slug ) {
			/* translators: %s: status page slug */
			$parts[] = sprintf( __( 'The status page "%s" stays on your DownTime account; delete it in the dashboard if you no longer need it.', 'downtime-uptime-monitoring' ), $slug );
		}
		if ( ! Downtime_API::ok( $result ) ) {
			$parts[] = __( 'DownTime could not revoke this site\'s key; revoke it under Settings → Integrations on DownTime.', 'downtime-uptime-monitoring' );
		}
		self::done( $failed || ! Downtime_API::ok( $result ) ? 'warning' : 'success', implode( ' ', $parts ) );
	}

	// --- Monitor this site --------------------------------------------------

	/** The one-button form older versions rendered; same reuse logic as the checklist. */
	public static function handle_monitor_site() {
		self::require_admin();
		check_admin_referer( 'downtime_monitor_site' );
		Downtime_Checks::add_checks( array( 'home' ) );
	}

	/** The pre-0.5.0 "Turn on email alerts" button, for a page left open across an update. */
	public static function handle_email_alerts() {
		self::require_admin();
		check_admin_referer( 'downtime_email_alerts' );
		$monitor_id = (string) get_option( self::OPTION_MONITOR_ID, '' );
		if ( '' === $monitor_id || ! Downtime_Alerts::enable( $monitor_id, 2 ) ) {
			self::done( 'error', __( 'Alerts could not be turned on. You can add them in the DownTime dashboard under Alerts.', 'downtime-uptime-monitoring' ) );
		}
		self::done( 'success', __( 'Alerts are on for this check.', 'downtime-uptime-monitoring' ) );
	}

	// --- Status page ----------------------------------------------------------

	public static function handle_status_page() {
		self::require_admin();
		check_admin_referer( 'downtime_status_page' );
		$slug = isset( $_POST['slug'] ) ? Downtime_Embed::sanitize_slug( sanitize_text_field( wp_unslash( $_POST['slug'] ) ) ) : '';
		if ( '' === $slug ) {
			self::done( 'error', __( 'Slug must be 1–40 lowercase letters, digits or hyphens, starting and ending with a letter or digit.', 'downtime-uptime-monitoring' ) );
		}
		$page = array(
			'slug'  => $slug,
			'title' => get_bloginfo( 'name' ) . ' ' . __( 'Status', 'downtime-uptime-monitoring' ),
		);
		$picked = isset( $_POST['monitor_ids'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['monitor_ids'] ) ) : array();
		$picked = array_values( array_filter( $picked, array( __CLASS__, 'is_uuid' ) ) );
		if ( ! $picked ) {
			// Default to the homepage check.
			$monitor_id = (string) get_option( self::OPTION_MONITOR_ID, '' );
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
		self::require_admin();
		check_admin_referer( 'downtime_use_status_page' );
		$slug = isset( $_POST['slug'] ) ? Downtime_Embed::sanitize_slug( sanitize_text_field( wp_unslash( $_POST['slug'] ) ) ) : '';
		if ( '' === $slug ) {
			delete_option( Downtime_Embed::OPTION_SLUG );
			self::done( 'success', __( 'Status page cleared. You can create or pick another one.', 'downtime-uptime-monitoring' ) );
		}
		update_option( Downtime_Embed::OPTION_SLUG, $slug, false );
		self::done( 'success', __( 'Status page saved.', 'downtime-uptime-monitoring' ) );
	}

	public static function is_uuid( $id ) {
		return (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id );
	}

	/**
	 * Whether DownTime's probes could reach this site at all. A monitor for
	 * localhost, a .local/.test name or a private IP can be created, but
	 * every check would fail (DownTime refuses to probe private networks),
	 * so the settings page explains that instead of offering site checks.
	 * Neither can a WordPress Playground site.
	 */
	public static function is_public_site() {
		if ( Downtime_Checks::is_preview_site() ) {
			return false;
		}
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

	/** The account's monitors, fetched at most once per request. */
	public static function list_monitors() {
		static $monitors = null;
		if ( null === $monitors ) {
			$monitors = self::items( Downtime_API::list_monitors(), 'monitors' );
		}
		return $monitors;
	}

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

	/**
	 * A one-button admin-post form. $onsubmit, when given, is a JavaScript
	 * expression the caller has already escaped (e.g. a confirm() prompt).
	 */
	public static function form( $action, $label, $class = 'button button-primary', $fields = '', $onsubmit = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-inline-end:8px"' . ( '' !== $onsubmit ? ' onsubmit="' . esc_attr( $onsubmit ) . '"' : '' ) . '>';
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
		Downtime_Cleanup::render_notice();

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
		$confirm = esc_js( __( 'Disconnect this site from DownTime?', 'downtime-uptime-monitoring' ) );
		$keep    = '<label class="downtime-keep"><input type="checkbox" name="keep_monitors" value="1"> ' . esc_html__( 'Keep this site\'s checks running on DownTime', 'downtime-uptime-monitoring' ) . '</label> ';
		self::form( 'downtime_disconnect', __( 'Disconnect', 'downtime-uptime-monitoring' ), 'button', $keep, 'return confirm(\'' . $confirm . '\')' );
		echo '<p class="description">' . esc_html__( 'Disconnecting deletes the checks this site set up on DownTime, with their history, unless you keep them. Checks you added in the DownTime dashboard are never touched. Deleting the plugin does the same clean-up.', 'downtime-uptime-monitoring' ) . '</p>';

		// Monitoring.
		echo '<h2>' . esc_html__( 'Monitoring', 'downtime-uptime-monitoring' ) . '</h2>';
		$monitors = self::list_monitors();
		Downtime_Checks::render_panel( $monitors );
		echo '<p><a href="' . esc_url( Downtime_API::dashboard_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open DownTime dashboard', 'downtime-uptime-monitoring' ) . '</a></p>';

		Downtime_Alerts::render();

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
			Downtime_Checks::render_wp_page();
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
			// Ticked by default: this site's own monitors.
			$default_ids = wp_list_pluck( Downtime_Checks::site_monitors( $monitors ), 'id' );
			$boxes       = '';
			foreach ( $monitors as $m ) {
				if ( empty( $m['id'] ) ) {
					continue;
				}
				$label  = ! empty( $m['name'] ) ? $m['name'] : ( $m['url'] ?? $m['id'] );
				$boxes .= '<label style="display:block"><input type="checkbox" name="monitor_ids[]" value="' . esc_attr( $m['id'] ) . '"' . checked( in_array( $m['id'], $default_ids, true ), true, false ) . '> ' . esc_html( $label ) . '</label>';
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
