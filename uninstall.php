<?php
/**
 * Deleting the plugin: on every site that was connected, delete the
 * monitors that site created on DownTime, trash the Status page the
 * plugin published, revoke the site's key and remove everything the plugin
 * stored (see Downtime_Cleanup). The DownTime account itself, its status
 * pages and any monitors set up in the DownTime dashboard are left alone.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'DOWNTIME_BASE_URL' ) ) {
	define( 'DOWNTIME_BASE_URL', 'https://downtime.opscale.ir' );
}
if ( ! defined( 'DOWNTIME_PLUGIN_VERSION' ) ) {
	define( 'DOWNTIME_PLUGIN_VERSION', '0.7.0' );
}
require_once __DIR__ . '/includes/class-downtime-api.php';
require_once __DIR__ . '/includes/class-downtime-admin.php';
require_once __DIR__ . '/includes/class-downtime-checks.php';
require_once __DIR__ . '/includes/class-downtime-cleanup.php';

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $downtime_site_id ) {
		switch_to_blog( $downtime_site_id );
		Downtime_Cleanup::uninstall_site();
		restore_current_blog();
	}
} else {
	Downtime_Cleanup::uninstall_site();
}
