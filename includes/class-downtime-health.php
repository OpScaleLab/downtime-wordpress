<?php
/**
 * Problems only the site itself can see, sent hourly with the "health"
 * heartbeat (see Downtime_Heartbeat):
 *
 * - WordPress Site Health: every quick ("direct") test the Tools → Site
 *   Health screen runs, the same way WordPress's own weekly check does.
 *   Only "critical" results count; "recommended" ones never alert. The
 *   slower tests that make HTTP requests (loopback, HTTPS, WordPress.org
 *   reachability) are left to the Site Health screen.
 * - WooCommerce, when active: no payment method switched on, no published
 *   checkout page, or scheduled actions (order emails, subscription
 *   renewals, webhooks) piling up more than an hour late.
 *
 * A problem turns the beat into a failed check on DownTime, with the
 * problems as its error, so the usual alert rules apply.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Downtime_Health {

	const OPTION_LAST = 'downtime_health_last';
	// Late scheduled actions before WooCommerce counts as stuck.
	const LATE_ACTIONS = 10;

	/** @return string[] One line per problem; empty when all is well. */
	public static function problems() {
		$problems = array_merge( self::site_health(), self::woocommerce() );
		update_option(
			self::OPTION_LAST,
			array(
				'time'     => time(),
				'problems' => $problems,
			),
			false
		);
		return $problems;
	}

	/** The error text sent with a failed beat (DownTime keeps it short). */
	public static function summary( $problems ) {
		$text = implode( '; ', $problems );
		return strlen( $text ) > 400 ? substr( $text, 0, 397 ) . '...' : $text;
	}

	/** @return array{time:int, problems:string[]}|null The last hourly result. */
	public static function last() {
		$last = get_option( self::OPTION_LAST );
		return is_array( $last ) ? $last : null;
	}

	private static function site_health() {
		$files = array(
			'wp-admin/includes/class-wp-site-health.php',
			'wp-admin/includes/update.php',
			'wp-admin/includes/misc.php',
			'wp-admin/includes/plugin.php',
			'wp-admin/includes/theme.php',
			'wp-admin/includes/file.php',
		);
		foreach ( $files as $file ) {
			if ( is_readable( ABSPATH . $file ) ) {
				require_once ABSPATH . $file;
			}
		}
		if ( ! class_exists( 'WP_Site_Health' ) ) {
			return array();
		}
		$health = WP_Site_Health::get_instance();
		$tests  = WP_Site_Health::get_tests();
		$out    = array();
		foreach ( (array) ( $tests['direct'] ?? array() ) as $test ) {
			if ( ! empty( $test['skip_cron'] ) ) {
				continue;
			}
			$result = null;
			try {
				if ( is_string( $test['test'] ) && method_exists( $health, 'get_test_' . $test['test'] ) ) {
					$result = call_user_func( array( $health, 'get_test_' . $test['test'] ) );
				} elseif ( is_callable( $test['test'] ) ) {
					$result = call_user_func( $test['test'] );
				}
			} catch ( Throwable $e ) {
				continue; // A broken third-party test is not the site's health.
			}
			if ( is_array( $result ) && 'critical' === ( $result['status'] ?? '' ) && ! empty( $result['label'] ) ) {
				$out[] = wp_strip_all_tags( (string) $result['label'] );
			}
		}
		return $out;
	}

	private static function woocommerce() {
		if ( ! function_exists( 'WC' ) || ! function_exists( 'wc_get_page_id' ) ) {
			return array();
		}
		$out = array();

		$enabled = 0;
		foreach ( (array) WC()->payment_gateways()->payment_gateways() as $gateway ) {
			if ( isset( $gateway->enabled ) && 'yes' === $gateway->enabled ) {
				++$enabled;
			}
		}
		if ( 0 === $enabled ) {
			$out[] = __( 'WooCommerce: no payment method is switched on', 'downtime-uptime-monitoring' );
		}

		$checkout = wc_get_page_id( 'checkout' );
		if ( $checkout <= 0 || 'publish' !== get_post_status( $checkout ) ) {
			$out[] = __( 'WooCommerce: the checkout page is missing or not published', 'downtime-uptime-monitoring' );
		}

		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$late = as_get_scheduled_actions(
				array(
					'status'       => 'pending',
					'date'         => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
					'date_compare' => '<=',
					'per_page'     => self::LATE_ACTIONS,
				),
				'ids'
			);
			if ( count( (array) $late ) >= self::LATE_ACTIONS ) {
				/* translators: %d: number of scheduled actions */
				$out[] = sprintf( __( 'WooCommerce: %d+ scheduled actions are over an hour late (order emails, renewals and webhooks wait on them)', 'downtime-uptime-monitoring' ), self::LATE_ACTIONS );
			}
		}
		return $out;
	}
}
