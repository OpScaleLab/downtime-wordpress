<?php
/**
 * Leaving no trace: what Disconnect, plugin deletion and updates clean up.
 *
 * - Disconnect (Settings → DownTime) and deleting the plugin delete the
 *   monitors this site created on DownTime (their alert rules go with
 *   them), unpublish the Status page the plugin added to this site,
 *   revoke this site's key and forget everything stored here. Disconnect
 *   can keep the monitors instead. Monitors set up in the DownTime
 *   dashboard are never touched: this site's key can't change them.
 * - Updating from before 0.7.0 retires the WP-Cron and Site Health
 *   heartbeats. They were sent from WP-Cron, which only runs when the site
 *   gets visits (and many hosts switch it off), so they raised false
 *   alarms. Their schedules stop, and their monitors on DownTime are
 *   paused (history kept), so they don't report the site as down.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Downtime_Cleanup {

	const OPTION_VERSION = 'downtime_version';
	const RETIRE_HOOK    = 'downtime_retire_heartbeats';
	// Shown once on the settings page after the heartbeats were retired.
	const OPTION_RETIRED = 'downtime_heartbeats_retired';

	/** Options (and the transients after them) this plugin keeps on each site. */
	const OPTIONS = array(
		'downtime_api_key',
		'downtime_site_monitor_id',
		'downtime_status_slug',
		'downtime_site_alert_rule_id',
		'downtime_tls_alert_rule_id',
		'downtime_wp_status_page_id',
		'downtime_content_text',
		'downtime_site_monitor_ids',
		'downtime_paused_checks',
		'downtime_alert_channels',
		'downtime_silence_updates',
		'downtime_version',
		'downtime_heartbeats_retired',
		// Before 0.7.0.
		'downtime_heartbeat_monitor_id',
		'downtime_heartbeat_alert_rule_id',
		'downtime_health_monitor_id',
		'downtime_health_last',
	);
	const TRANSIENTS = array( 'downtime_silenced', 'downtime_widget_cache' );
	const CRON_HOOKS = array( 'downtime_heartbeat_ping', 'downtime_health_ping', 'downtime_retire_heartbeats' );

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_upgrade' ) );
		add_action( self::RETIRE_HOOK, array( __CLASS__, 'retire_heartbeats' ) );
	}

	// --- Updates ------------------------------------------------------------

	/** Once per site per version: a cheap option read on every other request. */
	public static function maybe_upgrade() {
		$was = (string) get_option( self::OPTION_VERSION, '' );
		if ( DOWNTIME_PLUGIN_VERSION === $was ) {
			return;
		}
		update_option( self::OPTION_VERSION, DOWNTIME_PLUGIN_VERSION, true );
		$beats = array_values(
			array_filter(
				array(
					(string) get_option( 'downtime_heartbeat_monitor_id', '' ),
					(string) get_option( 'downtime_health_monitor_id', '' ),
				),
				array( 'Downtime_Admin', 'is_uuid' )
			)
		);
		wp_clear_scheduled_hook( 'downtime_heartbeat_ping' );
		wp_clear_scheduled_hook( 'downtime_health_ping' );
		foreach ( array( 'downtime_heartbeat_monitor_id', 'downtime_heartbeat_alert_rule_id', 'downtime_health_monitor_id', 'downtime_health_last' ) as $option ) {
			delete_option( $option );
		}
		if ( $beats && Downtime_API::is_connected() ) {
			// The API calls run in the background, not in a visitor's request.
			wp_schedule_single_event( time(), self::RETIRE_HOOK, array( $beats ) );
		}
	}

	/** Pause the retired heartbeat monitors this site created. */
	public static function retire_heartbeats( $ids ) {
		$ids     = array_filter( (array) $ids, array( 'Downtime_Admin', 'is_uuid' ) );
		$paused  = array();
		$listing = Downtime_API::list_monitors();
		if ( ! Downtime_API::ok( $listing ) || ! is_array( $listing['body'] ) ) {
			return;
		}
		$monitors = isset( $listing['body']['monitors'] ) ? (array) $listing['body']['monitors'] : $listing['body'];
		foreach ( $monitors as $m ) {
			if ( ! is_array( $m ) || ! in_array( $m['id'] ?? '', $ids, true ) || 'heartbeat' !== ( $m['type'] ?? '' ) ) {
				continue;
			}
			if ( ! empty( $m['active'] ) && Downtime_Checks::owns( $m ) && Downtime_API::ok( Downtime_Checks::update_active( $m, false ) ) ) {
				$paused[] = (string) ( $m['name'] ?? '' );
			}
		}
		if ( $paused ) {
			update_option( self::OPTION_RETIRED, $paused, false );
		}
	}

	/** The one-time notice about the retired heartbeats, on the settings page. */
	public static function render_notice() {
		$paused = get_option( self::OPTION_RETIRED );
		if ( ! $paused ) {
			return;
		}
		delete_option( self::OPTION_RETIRED );
		/* translators: %s: comma-separated monitor names */
		$text = sprintf( __( 'DownTime no longer watches WP-Cron or Site Health from inside your site: WP-Cron only runs when the site gets visits, so those heartbeats raised false alarms. Their monitors (%s) are paused on DownTime with their history; delete them below under "Other monitors for this site" if you don\'t need them.', 'downtime-uptime-monitoring' ), implode( ', ', (array) $paused ) );
		echo '<div class="notice notice-info"><p>' . esc_html( $text ) . '</p></div>';
	}

	// --- Disconnect and delete ----------------------------------------------

	/**
	 * Delete the monitors this site created on DownTime. Monitors set up
	 * elsewhere are left alone. Returns how many were deleted, or a WP_Error
	 * when DownTime couldn't be reached.
	 */
	public static function delete_site_monitors() {
		$listing = Downtime_API::list_monitors();
		if ( ! Downtime_API::ok( $listing ) || ! is_array( $listing['body'] ) ) {
			return new WP_Error( 'downtime_api', Downtime_API::error_message( $listing ) );
		}
		$monitors = isset( $listing['body']['monitors'] ) ? (array) $listing['body']['monitors'] : $listing['body'];
		$deleted  = 0;
		foreach ( $monitors as $m ) {
			if ( is_array( $m ) && ! empty( $m['id'] ) && Downtime_Checks::owns( $m ) ) {
				$result = Downtime_API::delete_monitor( (string) $m['id'] );
				if ( Downtime_API::ok( $result ) || ( ! is_wp_error( $result ) && 404 === $result['code'] ) ) {
					++$deleted;
				}
			}
		}
		return $deleted;
	}

	/** Move the Status page this plugin published to the trash (restorable). */
	public static function trash_wp_page() {
		$id = (int) get_option( Downtime_Checks::OPTION_WP_PAGE, 0 );
		if ( $id && 'page' === get_post_type( $id ) && false !== strpos( (string) get_post_field( 'post_content', $id ), 'wp:downtime/status' ) ) {
			wp_trash_post( $id );
		}
	}

	/** Forget everything this plugin stored on the current site. */
	public static function forget_site() {
		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}
		foreach ( self::TRANSIENTS as $transient ) {
			delete_transient( $transient );
		}
		foreach ( self::CRON_HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Plugin deletion, per site: delete this site's monitors, unpublish its
	 * Status page, revoke its key, forget everything. Best effort: a
	 * DownTime outage never blocks the uninstall.
	 */
	public static function uninstall_site() {
		if ( Downtime_API::is_connected() ) {
			self::delete_site_monitors();
			Downtime_API::revoke_self();
		}
		self::trash_wp_page();
		self::forget_site();
	}
}
