<?php
/**
 * Multisite support.
 *
 * Each site in a network connects to DownTime on its own (Settings →
 * DownTime on that site): every site gets its own limited key, checks and
 * status page, all in the DownTime account of whoever approved it. This
 * class adds Network Admin → Settings → DownTime, which lists every site
 * with whether it is connected and how many checks it has, linking to its
 * settings. Deleting the plugin cleans up every site (uninstall.php).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Downtime_Network {

	const PAGE = 'downtime-network';

	public static function init() {
		if ( is_multisite() ) {
			add_action( 'network_admin_menu', array( __CLASS__, 'menu' ) );
		}
	}

	public static function menu() {
		add_submenu_page(
			'settings.php',
			__( 'DownTime', 'downtime-uptime-monitoring' ),
			__( 'DownTime', 'downtime-uptime-monitoring' ),
			'manage_network_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'DownTime', 'downtime-uptime-monitoring' ) . '</h1>';
		echo '<p>' . esc_html__( 'Each site connects to DownTime on its own, from its Settings → DownTime page, and gets its own checks and status page. Sites you connect while signed in to the same DownTime account all show up in that account.', 'downtime-uptime-monitoring' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Site', 'downtime-uptime-monitoring' ) . '</th>';
		echo '<th>' . esc_html__( 'DownTime', 'downtime-uptime-monitoring' ) . '</th>';
		echo '<th>' . esc_html__( 'Checks', 'downtime-uptime-monitoring' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';
		$plugin = plugin_basename( DOWNTIME_PLUGIN_FILE );
		$wide   = is_plugin_active_for_network( $plugin );
		foreach ( get_sites( array( 'number' => 0 ) ) as $site ) {
			$id        = (int) $site->blog_id;
			$active    = $wide || in_array( $plugin, (array) get_blog_option( $id, 'active_plugins', array() ), true );
			$connected = '' !== (string) get_blog_option( $id, 'downtime_api_key', '' );
			$checks    = count( (array) get_blog_option( $id, 'downtime_site_monitor_ids', array() ) );
			echo '<tr><td><strong>' . esc_html( (string) get_blog_option( $id, 'blogname', '' ) ) . '</strong><br><span class="description">' . esc_html( untrailingslashit( get_home_url( $id, '/' ) ) ) . '</span></td><td>';
			if ( ! $active ) {
				echo esc_html__( 'Plugin not active', 'downtime-uptime-monitoring' );
			} elseif ( $connected ) {
				echo '<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ' . esc_html__( 'Connected', 'downtime-uptime-monitoring' );
			} else {
				echo esc_html__( 'Not connected', 'downtime-uptime-monitoring' );
			}
			echo '</td><td>' . ( $connected ? esc_html( (string) $checks ) : '—' ) . '</td><td>';
			if ( $active ) {
				echo '<a class="button button-small" href="' . esc_url( get_admin_url( $id, 'options-general.php?page=downtime' ) ) . '">' . esc_html( $connected ? __( 'Settings', 'downtime-uptime-monitoring' ) : __( 'Connect', 'downtime-uptime-monitoring' ) ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}
