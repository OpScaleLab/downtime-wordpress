<?php
/**
 * Plugin Name:       DownTime Uptime Monitoring
 * Plugin URI:        https://downtime.opscale.ir
 * Description:       Connect your site to DownTime in one click: monitor its uptime and embed a live status badge or status page with a shortcode or block.
 * Version:           0.4.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            OpScale
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       downtime-uptime-monitoring
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOWNTIME_PLUGIN_VERSION', '0.4.1' );
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
require_once DOWNTIME_PLUGIN_DIR . 'includes/class-downtime-heartbeat.php';
require_once DOWNTIME_PLUGIN_DIR . 'includes/class-downtime-widget.php';
require_once DOWNTIME_PLUGIN_DIR . 'includes/class-downtime-checks.php';

// Bundled translations (languages/), used until translate.wordpress.org
// ships a language pack for the locale. A pack, once installed under
// wp-content/languages/plugins, is loaded by WordPress itself on first use,
// so the bundled file is skipped then.
add_action(
	'init',
	function () {
		$domain = 'downtime-uptime-monitoring';
		$locale = determine_locale();
		$pack   = WP_LANG_DIR . '/plugins/' . $domain . '-' . $locale;
		$mofile = DOWNTIME_PLUGIN_DIR . 'languages/' . $domain . '-' . $locale . '.mo';
		if ( ! is_readable( $pack . '.mo' ) && ! is_readable( $pack . '.l10n.php' ) && is_readable( $mofile ) ) {
			load_textdomain( $domain, $mofile );
		}
	},
	1
);

Downtime_Embed::init();
// Always: WP-Cron fires the ping on front-end requests too.
Downtime_Heartbeat::init();
if ( is_admin() ) {
	Downtime_Admin::init();
	Downtime_Checks::init();
	Downtime_Widget::init();
}

// Deactivating stops the pings; reactivating resumes them if WP-Cron was watched.
register_deactivation_hook( __FILE__, array( 'Downtime_Heartbeat', 'unschedule' ) );
register_activation_hook( __FILE__, array( 'Downtime_Heartbeat', 'reschedule' ) );
