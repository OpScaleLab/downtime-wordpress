<?php
/**
 * Heartbeat monitors this site pings from WP-Cron. DownTime's
 * /api/heartbeat/{id} needs no API key (it's keyed by the monitor's own
 * random ID) and takes an optional ?status=fail&error=..., so a beat can
 * report a problem, not only that it is alive.
 *
 * - heartbeat: the WP-Cron watch. A plain hourly ping, so DownTime notices
 *   when scheduled tasks (publishing, backups, updates) stop running.
 * - health: WordPress Site Health (and WooCommerce, when active), checked
 *   from inside the site every hour. A critical issue is sent as a failed
 *   beat with the issues as its error. See Downtime_Health.
 *
 * WP-Cron only runs when the site gets visits (or from a real system
 * cron), so on a very quiet site both beats can arrive late.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Downtime_Heartbeat {

	const OPTION_MONITOR_ID = 'downtime_heartbeat_monitor_id';
	const HOOK              = 'downtime_heartbeat_ping';
	// Hourly ping; DownTime alerts after the interval plus 50% grace (90 min).
	const INTERVAL = 3600;

	/** beat => [option holding its monitor ID, WP-Cron hook]. */
	const BEATS = array(
		'heartbeat' => array( 'downtime_heartbeat_monitor_id', 'downtime_heartbeat_ping' ),
		'health'    => array( 'downtime_health_monitor_id', 'downtime_health_ping' ),
	);

	public static function init() {
		add_action( 'downtime_heartbeat_ping', array( __CLASS__, 'ping_heartbeat' ) );
		add_action( 'downtime_health_ping', array( __CLASS__, 'ping_health' ) );
	}

	public static function ping_heartbeat() {
		self::ping( 'heartbeat' );
	}

	public static function ping_health() {
		self::ping( 'health' );
	}

	public static function monitor_id( $beat = 'heartbeat' ) {
		return (string) get_option( self::BEATS[ $beat ][0], '' );
	}

	/** The beat a monitor ID belongs to, or ''. */
	public static function beat_of( $monitor_id ) {
		foreach ( array_keys( self::BEATS ) as $beat ) {
			if ( '' !== (string) $monitor_id && self::monitor_id( $beat ) === $monitor_id ) {
				return $beat;
			}
		}
		return '';
	}

	public static function enable( $monitor_id, $beat = 'heartbeat' ) {
		update_option( self::BEATS[ $beat ][0], $monitor_id, false );
		if ( ! wp_next_scheduled( self::BEATS[ $beat ][1] ) ) {
			wp_schedule_event( time(), 'hourly', self::BEATS[ $beat ][1] );
		}
		self::ping( $beat );
	}

	/** Deactivation: stop every beat's schedule, keep the monitor IDs. */
	public static function unschedule() {
		foreach ( self::BEATS as $def ) {
			wp_clear_scheduled_hook( $def[1] );
		}
	}

	/** Activation: bring back the schedule of every beat that has a monitor. */
	public static function reschedule() {
		foreach ( self::BEATS as $beat => $def ) {
			if ( '' !== self::monitor_id( $beat ) && ! wp_next_scheduled( $def[1] ) ) {
				wp_schedule_event( time(), 'hourly', $def[1] );
			}
		}
	}

	/** Stop pinging. The DownTime monitor itself is left to the user. */
	public static function disable( $beat = 'heartbeat' ) {
		wp_clear_scheduled_hook( self::BEATS[ $beat ][1] );
		delete_option( self::BEATS[ $beat ][0] );
	}

	public static function ping( $beat = 'heartbeat' ) {
		$id = self::monitor_id( $beat );
		if ( '' === $id ) {
			return;
		}
		$url = Downtime_API::base_url() . '/api/heartbeat/' . rawurlencode( $id );
		if ( 'health' === $beat ) {
			$problems = Downtime_Health::problems();
			if ( $problems ) {
				$url .= '?status=fail&error=' . rawurlencode( Downtime_Health::summary( $problems ) );
			}
		}
		wp_remote_post(
			$url,
			array(
				'timeout'  => 5,
				'blocking' => false,
			)
		);
	}
}
