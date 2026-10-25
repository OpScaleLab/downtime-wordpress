<?php
/**
 * Plugin Name:       DownTime Uptime Monitoring
 * Plugin URI:        https://downtime.opscale.ir
 * Description:       Connect your site to DownTime in one click: monitor its uptime and embed a live status badge or status page with a shortcode or block.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            OpScale
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       downtime-uptime-monitoring
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOWNTIME_PLUGIN_VERSION', '0.1.0' );
define( 'DOWNTIME_PLUGIN_FILE', __FILE__ );
define( 'DOWNTIME_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DOWNTIME_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Overridable in wp-config.php for testing against another DownTime instance.
if ( ! defined( 'DOWNTIME_BASE_URL' ) ) {
	define( 'DOWNTIME_BASE_URL', 'https://downtime.opscale.ir' );
}

require_once DOWNTIME_PLUGIN_DIR . 'includes/class-downtime-api.php';
require_once DOWNTIME_PLUGIN_DIR . 'includes/class-downtime-embed.php';
require_once DOWNTIME_PLUGIN_DIR . 'includes/class-downtime-admin.php';

Downtime_Embed::init();
if ( is_admin() ) {
	Downtime_Admin::init();
}
