<?php
// Remove everything the plugin stored. The DownTime account, monitor and
// status page are left alone: they belong to the user, not to this site.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'downtime_api_key' );
delete_option( 'downtime_site_monitor_id' );
delete_option( 'downtime_status_slug' );
delete_option( 'downtime_site_alert_rule_id' );
delete_option( 'downtime_heartbeat_monitor_id' );
delete_option( 'downtime_heartbeat_alert_rule_id' );
delete_option( 'downtime_tls_alert_rule_id' );
delete_option( 'downtime_wp_status_page_id' );
delete_option( 'downtime_content_text' );
delete_option( 'downtime_site_monitor_ids' );
delete_option( 'downtime_paused_checks' );
delete_option( 'downtime_alert_channels' );
delete_option( 'downtime_silence_updates' );
delete_transient( 'downtime_silenced' );
delete_transient( 'downtime_widget_cache' );
wp_clear_scheduled_hook( 'downtime_heartbeat_ping' );
