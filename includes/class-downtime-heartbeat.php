<?php
/**
 * WP-Cron heartbeat: an opt-in DownTime heartbeat monitor pinged hourly by
 * WP-Cron, so DownTime notices when scheduled tasks (publishing, backups,
 * updates) stop running.
 *
 * The ping needs no API key: DownTime's /api/heartbeat/{id} is keyed by the
 * monitor's own random ID. WP-Cron only runs when the site gets visits (or
 * from a real system cron), so a very quiet site can look "down" here.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Downtime_Heartbeat {

	const OPTION_MONITOR_ID = 'downtime_heartbeat_monitor_id';
	const HOOK              = 'downtime_heartbeat_ping';
	// Hourly ping; DownTime alerts after the interval plus 50% grace (90 min).
	const INTERVAL = 3600;

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'ping' ) );
	}

	public static function monitor_id() {
		return (string) get_option( self::OPTION_MONITOR_ID, '' );
	}

	public static function enable( $monitor_id ) {
		update_option( self::OPTION_MONITOR_ID, $monitor_id, false );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::HOOK );
		}
		self::ping();
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function reschedule() {
		if ( '' !== self::monitor_id() && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::HOOK );
		}
	}

	/** Stop pinging. The DownTime monitor itself is left to the user. */
	public static function disable() {
		wp_clear_scheduled_hook( self::HOOK );
		delete_option( self::OPTION_MONITOR_ID );
	}

	public static function ping() {
		$id = self::monitor_id();
		if ( '' === $id ) {
			return;
		}
		wp_remote_post(
			Downtime_API::base_url() . '/api/heartbeat/' . rawurlencode( $id ),
			array(
				'timeout'  => 5,
				'blocking' => false,
			)
		);
	}
}
