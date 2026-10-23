<?php
// Remove everything the plugin stored. The DownTime account, monitor and
// status page are left alone: they belong to the user, not to this site.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'downtime_api_key' );
delete_option( 'downtime_site_monitor_id' );
delete_option( 'downtime_status_slug' );
