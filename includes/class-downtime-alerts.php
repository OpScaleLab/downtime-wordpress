<?php
/**
 * Alerts: the "Alert me" switch on each check, where alerts go, and
 * silencing this site's checks while WordPress updates itself.
 *
 * DownTime (chart 0.32.0+) lets this site's key list, change and delete only
 * the alert rules it created (each rule is stamped with the site), send them
 * only to channels the account set up itself (the account email, its paired
 * Telegram or Bale chat, its Slack install), see which of those are
 * connected (yes/no only), and connect Telegram or Bale while not connected
 * yet. On an older DownTime, the rule list is refused: the switches then
 * link to the dashboard, and new checks still get an email alert.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Downtime_Alerts {

	const OPTION_CHANNELS = 'downtime_alert_channels';
	const OPTION_SILENCE  = 'downtime_silence_updates';
	const SILENCED        = 'downtime_silenced';
	const PAIRING         = 'downtime_pairing_';
	// How long checks stay silenced after an update starts.
	const SILENCE_MINUTES = 15;

	const CHANNELS = array( 'email', 'telegram', 'bale', 'slack' );

	public static function init() {
		// Updates run from wp-admin, WP-Cron (auto-updates) and WP-CLI alike.
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'silence_for_update' ), 10, 1 );
		if ( is_admin() ) {
			add_action( 'admin_post_downtime_alert_on', array( __CLASS__, 'handle_alert_on' ) );
			add_action( 'admin_post_downtime_alert_off', array( __CLASS__, 'handle_alert_off' ) );
			add_action( 'admin_post_downtime_alert_channels', array( __CLASS__, 'handle_channels' ) );
			add_action( 'admin_post_downtime_pair', array( __CLASS__, 'handle_pair' ) );
			add_action( 'admin_post_downtime_silence_updates', array( __CLASS__, 'handle_silence' ) );
		}
	}

	private static function rule_name() {
		return sprintf( '%s (WordPress)', wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	}

	// --- Rules ------------------------------------------------------------------

	/**
	 * This site's own alert rules, fetched at most once per request, or null
	 * when DownTime doesn't let this key list them (before chart 0.32.0).
	 */
	public static function rules() {
		static $rules = false;
		if ( false === $rules ) {
			$result = Downtime_API::request( 'GET', '/api/rules' );
			$rules  = Downtime_API::ok( $result ) && is_array( $result['body'] ) ? array_values( array_filter( $result['body'], 'is_array' ) ) : null;
		}
		return $rules;
	}

	/** Whether the per-check alert switches can work: true, or null on an older DownTime. */
	public static function available() {
		return null === self::rules() ? null : true;
	}

	/** This site's rule for a monitor, preferring an active one. */
	public static function rule_for( $monitor_id ) {
		$found = null;
		foreach ( (array) self::rules() as $r ) {
			if ( ( $r['monitor_id'] ?? '' ) !== $monitor_id ) {
				continue;
			}
			if ( ! empty( $r['active'] ) ) {
				return $r;
			}
			$found = $found ? $found : $r;
		}
		return $found;
	}

	/** The channels picked under "Where to send alerts", limited to connected ones. */
	public static function channels() {
		$picked = (array) get_option( self::OPTION_CHANNELS, array( 'email' ) );
		$status = self::channel_status();
		$out    = array();
		foreach ( $picked as $c ) {
			if ( 'email' === $c || ( $status && ! empty( $status[ $c . '_connected' ] ) ) ) {
				$out[] = $c;
			}
		}
		return $out ? $out : array( 'email' );
	}

	/**
	 * Turn alerts on for a monitor: re-activate this site's rule for it, or
	 * create one. Returns true on success.
	 */
	public static function enable( $monitor_id, $threshold ) {
		$rule = null === self::rules() ? null : self::rule_for( $monitor_id );
		if ( $rule ) {
			$result = Downtime_API::request(
				'PUT',
				'/api/rules/' . rawurlencode( $rule['id'] ),
				array(
					'active'          => true,
					'notify_channels' => self::channels(),
				)
			);
			return Downtime_API::ok( $result );
		}
		$result = Downtime_API::request(
			'POST',
			'/api/rules',
			array(
				'name'            => self::rule_name(),
				'monitor_id'      => $monitor_id,
				'condition'       => 'consecutive_failures',
				'threshold'       => max( 1, (int) $threshold ),
				'notify_channels' => null === self::rules() ? array( 'email' ) : self::channels(),
				'active'          => true,
			)
		);
		return Downtime_API::ok( $result );
	}

	/** Turn alerts off for a monitor. The rule stays, inactive, so On restores it as it was. */
	public static function disable( $monitor_id ) {
		$rule = self::rule_for( $monitor_id );
		if ( ! $rule ) {
			return true;
		}
		$result = Downtime_API::request( 'PUT', '/api/rules/' . rawurlencode( $rule['id'] ), array( 'active' => false ) );
		return Downtime_API::ok( $result );
	}

	private static function posted_monitor() {
		// Nonce checked by the caller's require_admin().
		$id = isset( $_POST['monitor_id'] ) ? sanitize_text_field( wp_unslash( $_POST['monitor_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! Downtime_Admin::is_uuid( $id ) ) {
			Downtime_Admin::done( 'error', __( 'That monitor no longer exists on DownTime.', 'downtime-uptime-monitoring' ) );
		}
		return $id;
	}

	public static function handle_alert_on() {
		Downtime_Admin::require_admin( 'downtime_alert_on' );
		$id        = self::posted_monitor();
		$threshold = isset( $_POST['threshold'] ) ? absint( wp_unslash( $_POST['threshold'] ) ) : 2; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! self::enable( $id, $threshold ) ) {
			Downtime_Admin::done( 'error', __( 'Alerts could not be turned on. You can add them in the DownTime dashboard under Alerts.', 'downtime-uptime-monitoring' ) );
		}
		Downtime_Admin::done( 'success', __( 'Alerts are on for this check.', 'downtime-uptime-monitoring' ) );
	}

	public static function handle_alert_off() {
		Downtime_Admin::require_admin( 'downtime_alert_off' );
		if ( ! self::disable( self::posted_monitor() ) ) {
			Downtime_Admin::done( 'error', __( 'Alerts could not be turned off. Change them in the DownTime dashboard under Alerts.', 'downtime-uptime-monitoring' ) );
		}
		Downtime_Admin::done( 'success', __( 'Alerts are off for this check. It keeps being monitored.', 'downtime-uptime-monitoring' ) );
	}

	// --- Channels -----------------------------------------------------------------

	/**
	 * Which channels the account has connected, from DownTime (booleans
	 * only), or null when this DownTime doesn't tell an integration key.
	 */
	public static function channel_status() {
		static $status = false;
		if ( false !== $status ) {
			return $status;
		}
		$status = null;
		$org    = self::org_id();
		if ( '' !== $org ) {
			$result = Downtime_API::request( 'GET', '/api/alerts/orgs/' . rawurlencode( $org ) . '/notification-config' );
			if ( Downtime_API::ok( $result ) && is_array( $result['body'] ) && array_key_exists( 'telegram_connected', $result['body'] ) ) {
				$status = $result['body'];
			}
		}
		return $status;
	}

	private static function org_id() {
		static $org = null;
		if ( null === $org ) {
			$user = Downtime_API::get_user();
			$org  = Downtime_API::ok( $user ) && ! empty( $user['body']['org_id'] ) ? sanitize_text_field( $user['body']['org_id'] ) : '';
		}
		return $org;
	}

	/** Save the picked channels and apply them to every alert rule this site has. */
	public static function handle_channels() {
		Downtime_Admin::require_admin( 'downtime_alert_channels' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked by require_admin() above.
		$picked = isset( $_POST['channels'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['channels'] ) ) : array();
		$picked = array_values( array_intersect( self::CHANNELS, $picked ) );
		if ( ! $picked ) {
			Downtime_Admin::done( 'error', __( 'Pick at least one place to send alerts.', 'downtime-uptime-monitoring' ) );
		}
		update_option( self::OPTION_CHANNELS, $picked, false );
		$channels = self::channels();
		$failed   = 0;
		foreach ( (array) self::rules() as $r ) {
			$result = Downtime_API::request( 'PUT', '/api/rules/' . rawurlencode( $r['id'] ), array( 'notify_channels' => $channels ) );
			if ( ! Downtime_API::ok( $result ) ) {
				++$failed;
			}
		}
		if ( $failed ) {
			/* translators: %d: number of alert rules */
			Downtime_Admin::done( 'warning', sprintf( _n( 'Saved, but %d alert could not be updated.', 'Saved, but %d alerts could not be updated.', $failed, 'downtime-uptime-monitoring' ), $failed ) );
		}
		Downtime_Admin::done( 'success', __( 'Alert settings saved.', 'downtime-uptime-monitoring' ) );
	}

	/**
	 * Start connecting Telegram or Bale: DownTime returns a one-time link
	 * that opens a chat with its bot. The link is shown on the settings page
	 * (in a new tab), since the chat app must open it, not this admin.
	 */
	public static function handle_pair() {
		Downtime_Admin::require_admin( 'downtime_pair' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked by require_admin() above.
		$channel = isset( $_POST['channel'] ) ? sanitize_key( wp_unslash( $_POST['channel'] ) ) : '';
		if ( ! in_array( $channel, array( 'telegram', 'bale' ), true ) || '' === self::org_id() ) {
			Downtime_Admin::done( 'error', __( 'That channel can\'t be connected from here.', 'downtime-uptime-monitoring' ) );
		}
		$result = Downtime_API::request( 'POST', '/api/alerts/orgs/' . rawurlencode( self::org_id() ) . '/' . $channel . '/pairing-code' );
		if ( ! Downtime_API::ok( $result ) || empty( $result['body']['code'] ) ) {
			Downtime_Admin::done( 'error', Downtime_API::error_message( $result ) );
		}
		set_transient(
			self::PAIRING . get_current_user_id(),
			array(
				'channel' => $channel,
				'code'    => sanitize_text_field( $result['body']['code'] ),
				'link'    => esc_url_raw( (string) ( $result['body']['deep_link'] ?? '' ) ),
			),
			10 * MINUTE_IN_SECONDS
		);
		Downtime_Admin::done( 'success', __( 'Almost done: open the link below and press Start in the chat.', 'downtime-uptime-monitoring' ) );
	}

	// --- Silence during updates -----------------------------------------------------

	public static function silence_enabled() {
		return '0' !== (string) get_option( self::OPTION_SILENCE, '1' );
	}

	public static function handle_silence() {
		Downtime_Admin::require_admin( 'downtime_silence_updates' );
		$on = ! self::silence_enabled();
		update_option( self::OPTION_SILENCE, $on ? '1' : '0', false );
		Downtime_Admin::done( 'success', $on ? __( 'Alerts pause while WordPress updates.', 'downtime-uptime-monitoring' ) : __( 'Alerts no longer pause during updates.', 'downtime-uptime-monitoring' ) );
	}

	/**
	 * Before WordPress downloads an update (core, plugin, theme, translation;
	 * manual or automatic), open a short DownTime maintenance window for
	 * this site's monitors, so the few seconds of maintenance mode don't
	 * page anyone. At most one window per SILENCE_MINUTES, however many
	 * packages update. Passes $reply through untouched.
	 */
	public static function silence_for_update( $reply ) {
		if ( ! Downtime_API::is_connected() || ! self::silence_enabled() || get_transient( self::SILENCED ) ) {
			return $reply;
		}
		$ids = array_values( array_filter( (array) get_option( Downtime_Checks::OPTION_SITE_IDS, array() ), array( 'Downtime_Admin', 'is_uuid' ) ) );
		if ( ! $ids ) {
			return $reply;
		}
		set_transient( self::SILENCED, 1, ( self::SILENCE_MINUTES - 1 ) * MINUTE_IN_SECONDS );
		$now = time();
		Downtime_API::request(
			'POST',
			'/api/maintenance',
			array(
				/* translators: %s: site host name */
				'name'        => sprintf( __( 'WordPress update on %s', 'downtime-uptime-monitoring' ), wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ),
				'description' => __( 'Opened by the DownTime WordPress plugin while updates install.', 'downtime-uptime-monitoring' ),
				'monitor_ids' => $ids,
				'start_time'  => gmdate( 'c', $now ),
				'end_time'    => gmdate( 'c', $now + self::SILENCE_MINUTES * MINUTE_IN_SECONDS ),
			)
		);
		return $reply;
	}

	// --- Rendering ------------------------------------------------------------------

	public static function render() {
		echo '<h2>' . esc_html__( 'Alerts', 'downtime-uptime-monitoring' ) . '</h2>';
		$status = self::channel_status();
		if ( null === self::rules() || null === $status ) {
			echo '<p>' . esc_html__( 'Alerts go to your DownTime account email. Choose other channels in the DownTime dashboard under Settings.', 'downtime-uptime-monitoring' ) . ' <a href="' . esc_url( Downtime_API::dashboard_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open DownTime dashboard', 'downtime-uptime-monitoring' ) . '</a></p>';
		} else {
			self::render_channels( $status );
		}

		echo '<h3>' . esc_html__( 'While WordPress updates', 'downtime-uptime-monitoring' ) . '</h3>';
		echo '<div class="downtime-row">';
		$on = self::silence_enabled();
		Downtime_Checks::toggle( 'downtime_silence_updates', $on, __( 'Pause alerts while WordPress updates', 'downtime-uptime-monitoring' ), '' );
		/* translators: %d: minutes */
		echo '<span>' . esc_html( sprintf( __( 'Pause this site\'s alerts for %d minutes when an update starts, so the brief maintenance mode doesn\'t alert anyone.', 'downtime-uptime-monitoring' ), self::SILENCE_MINUTES ) ) . '</span></div>';
	}

	private static function render_channels( $status ) {
		$pairing = get_transient( self::PAIRING . get_current_user_id() );
		if ( is_array( $pairing ) && ! empty( $status[ $pairing['channel'] . '_connected' ] ) ) {
			delete_transient( self::PAIRING . get_current_user_id() );
			$pairing = null;
		}
		$names   = array(
			'email'    => __( 'Email', 'downtime-uptime-monitoring' ),
			'telegram' => 'Telegram',
			'bale'     => 'Bale',
			'slack'    => 'Slack',
		);
		$picked  = self::channels();
		$boxes   = '';
		$connect = '';
		foreach ( self::CHANNELS as $c ) {
			$connected = 'email' === $c || ! empty( $status[ $c . '_connected' ] );
			$available = 'email' === $c || ! empty( $status[ $c . '_available' ] ) || ( 'slack' === $c && ! empty( $status['slack_oauth_available'] ) );
			if ( $connected ) {
				$note   = 'email' === $c ? __( 'your DownTime account address', 'downtime-uptime-monitoring' ) : __( 'connected', 'downtime-uptime-monitoring' );
				$boxes .= '<label style="display:block;margin:4px 0"><input type="checkbox" name="channels[]" value="' . esc_attr( $c ) . '"' . checked( in_array( $c, $picked, true ), true, false ) . '> <strong>' . esc_html( $names[ $c ] ) . '</strong> <span class="description">' . esc_html( $note ) . '</span></label>';
			} elseif ( $available ) {
				$connect .= '<li>';
				if ( 'slack' === $c ) {
					$connect .= '<a href="' . esc_url( Downtime_API::dashboard_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Connect Slack in the DownTime dashboard', 'downtime-uptime-monitoring' ) . '</a> <span class="description">' . esc_html__( '(Settings → Notifications), then come back here.', 'downtime-uptime-monitoring' ) . '</span>';
				} else {
					ob_start();
					/* translators: %s: Telegram or Bale */
					Downtime_Admin::form( 'downtime_pair', sprintf( __( 'Connect %s', 'downtime-uptime-monitoring' ), $names[ $c ] ), 'button', '<input type="hidden" name="channel" value="' . esc_attr( $c ) . '">' );
					$connect .= ob_get_clean();
					/* translators: %s: Telegram or Bale */
					$connect .= ' <span class="description">' . esc_html( sprintf( __( 'Get alerts in a %s chat.', 'downtime-uptime-monitoring' ), $names[ $c ] ) ) . '</span>';
				}
				$connect .= '</li>';
			}
		}

		if ( is_array( $pairing ) ) {
			echo '<div class="notice notice-info inline"><p>';
			if ( '' !== $pairing['link'] ) {
				/* translators: %s: Telegram or Bale */
				echo '<a class="button button-primary" href="' . esc_url( $pairing['link'] ) . '" target="_blank" rel="noopener">' . esc_html( sprintf( __( 'Open %s', 'downtime-uptime-monitoring' ), $names[ $pairing['channel'] ] ) ) . '</a> ';
			}
			/* translators: 1: Telegram or Bale, 2: one-time code */
			echo esc_html( sprintf( __( 'In %1$s, press Start (or send the code %2$s to the DownTime bot). The link works for 10 minutes. Then reload this page and tick it below.', 'downtime-uptime-monitoring' ), $names[ $pairing['channel'] ], $pairing['code'] ) );
			echo '</p></div>';
		}

		echo '<p>' . esc_html__( 'Where to send alerts from this site:', 'downtime-uptime-monitoring' ) . '</p>';
		Downtime_Admin::form( 'downtime_alert_channels', __( 'Save', 'downtime-uptime-monitoring' ), 'button', '<fieldset style="margin:0 0 8px">' . $boxes . '</fieldset>' );
		if ( '' !== $connect ) {
			echo '<p>' . esc_html__( 'Add a channel:', 'downtime-uptime-monitoring' ) . '</p><ul>' . $connect . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
		}
	}
}
