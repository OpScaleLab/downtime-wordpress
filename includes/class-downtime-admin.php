<?php
/**
 * Settings → DownTime: connect/disconnect, one-click "monitor this site",
 * status page setup, and copy-paste embed snippets.
 *
 * Every state-changing action is an admin-post.php form protected by a
 * WordPress nonce and the manage_options capability. The Connect callback
 * is the one exception: it arrives as a redirect from DownTime, so it is
 * protected by the `state` value instead (random, per-user, single-use,
 * 10-minute transient).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Downtime_Admin {

	const PAGE              = 'downtime';
	const OPTION_MONITOR_ID = 'downtime_site_monitor_id';
	const STATE_TRANSIENT   = 'downtime_connect_state_';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_downtime_connect', array( __CLASS__, 'handle_connect' ) );
		add_action( 'admin_post_downtime_connect_callback', array( __CLASS__, 'handle_connect_callback' ) );
		add_action( 'admin_post_downtime_disconnect', array( __CLASS__, 'handle_disconnect' ) );
		add_action( 'admin_post_downtime_monitor_site', array( __CLASS__, 'handle_monitor_site' ) );
		add_action( 'admin_post_downtime_status_page', array( __CLASS__, 'handle_status_page' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DOWNTIME_PLUGIN_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		add_options_page( __( 'DownTime', 'downtime-uptime-monitoring' ), __( 'DownTime', 'downtime-uptime-monitoring' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
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
		set_transient( 'downtime_notice_' . get_current_user_id(), array( $type, $message ), 60 );
		wp_safe_redirect( self::page_url() );
		exit;
	}

	// --- Connect / disconnect ----------------------------------------------

	public static function handle_connect() {
		self::require_admin( 'downtime_connect' );
		$state = bin2hex( random_bytes( 16 ) );
		set_transient( self::STATE_TRANSIENT . get_current_user_id(), $state, 10 * MINUTE_IN_SECONDS );

		$url = add_query_arg(
			array(
				'client'       => 'wordpress',
				'site'         => rawurlencode( home_url( '/' ) ),
				'redirect_uri' => rawurlencode( self::callback_url() ),
				'state'        => $state,
			),
			Downtime_API::base_url() . '/connect'
		);
		// External host, so wp_redirect rather than wp_safe_redirect.
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
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
		update_option( self::OPTION_MONITOR_ID, sanitize_text_field( $result['body']['id'] ), false );
		self::done( 'success', __( 'This site is now monitored by DownTime.', 'downtime-uptime-monitoring' ) );
	}

	// --- Status page ----------------------------------------------------------

	public static function handle_status_page() {
		self::require_admin( 'downtime_status_page' );
		$slug = isset( $_POST['slug'] ) ? Downtime_Embed::sanitize_slug( wp_unslash( $_POST['slug'] ) ) : '';
		if ( '' === $slug ) {
			self::done( 'error', __( 'Slug must be 1–40 lowercase letters, digits or hyphens, starting and ending with a letter or digit.', 'downtime-uptime-monitoring' ) );
		}
		$page = array(
			'slug'  => $slug,
			'title' => get_bloginfo( 'name' ) . ' ' . __( 'Status', 'downtime-uptime-monitoring' ),
		);
		$monitor_id = (string) get_option( self::OPTION_MONITOR_ID, '' );
		if ( '' !== $monitor_id ) {
			// Only this site's monitor; an empty list would publish every
			// monitor in the org.
			$page['monitor_ids'] = array( $monitor_id );
		}
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
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px">';
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
			self::form( 'downtime_connect', __( 'Connect to DownTime', 'downtime-uptime-monitoring' ) );
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
		} elseif ( ! self::is_public_site() ) {
			/* translators: %s: site URL */
			echo '<p>' . esc_html( sprintf( __( '%s is a local or private address, so DownTime cannot reach it. Monitoring becomes available once the site is on a public address.', 'downtime-uptime-monitoring' ), $home ) ) . '</p>';
		} else {
			/* translators: %s: site URL */
			echo '<p>' . esc_html( sprintf( __( '%s is not monitored yet.', 'downtime-uptime-monitoring' ), $home ) ) . '</p>';
			self::form( 'downtime_monitor_site', __( 'Monitor this site', 'downtime-uptime-monitoring' ) );
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
		} else {
			$host      = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
			$suggested = Downtime_Embed::sanitize_slug( preg_replace( '/[^a-z0-9]+/', '-', strtolower( preg_replace( '/^www\./', '', $host ) ) ) );
			echo '<p>' . esc_html__( 'Publish a public status page for this site. Its address will be:', 'downtime-uptime-monitoring' ) . ' <code>' . esc_html( Downtime_API::base_url() . '/status/' ) . '&lt;slug&gt;</code></p>';
			$field = '<input type="text" name="slug" value="' . esc_attr( $suggested ) . '" pattern="[a-z0-9]([a-z0-9\\-]{0,38}[a-z0-9])?" required> ';
			self::form( 'downtime_status_page', __( 'Create status page', 'downtime-uptime-monitoring' ), 'button button-primary', $field );
		}
		echo '</div>';
	}
}
