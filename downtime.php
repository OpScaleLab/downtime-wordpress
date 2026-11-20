<?php
/**
 * Plugin Name:       DownTime Uptime Monitoring
 * Plugin URI:        https://downtime.opscale.ir
 * Description:       Connect your site to DownTime in one click: switch on uptime, page content, HTTPS certificate, domain expiry, DNS, network path and WooCommerce checks, get alerts by email, Telegram, Bale or Slack, and embed a live status badge or status page.
 * Version:           0.7.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            OpScale Solutions
 * Author URI:        https://downtime.opscale.ir/en/about
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       downtime-uptime-monitoring
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOWNTIME_PLUGIN_VERSION', '0.7.0' );
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
require_once DOWNTIME_PLUGIN_DIR . 'includes/class-downtime-cleanup.php';
require_once DOWNTIME_PLUGIN_DIR . 'includes/class-downtime-network.php';
require_once DOWNTIME_PLUGIN_DIR . 'includes/class-downtime-widget.php';
require_once DOWNTIME_PLUGIN_DIR . 'includes/class-downtime-checks.php';
require_once DOWNTIME_PLUGIN_DIR . 'includes/class-downtime-alerts.php';

Downtime_Embed::init();
// Always: automatic updates run from WP-Cron, and the admin bar shows on
// the front end.
Downtime_Cleanup::init();
Downtime_Alerts::init();
Downtime_Widget::init();
Downtime_Network::init();
if ( is_admin() ) {
	Downtime_Admin::init();
	Downtime_Checks::init();
}
