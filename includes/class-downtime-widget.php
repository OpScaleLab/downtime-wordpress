<?php
/**
 * Dashboard widget: this site's current state, recent uptime and incidents.
 *
 * Fetched server-side with the integration key and cached for five minutes
 * in a transient, so opening wp-admin doesn't call DownTime every time.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Downtime_Widget {

	const CACHE = 'downtime_widget_cache';
	const TTL   = 5 * MINUTE_IN_SECONDS;

	public static function init() {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_downtime_widget_refresh', array( __CLASS__, 'handle_refresh' ) );
	}

	public static function register() {
		if ( ! current_user_can( 'manage_options' ) || ! Downtime_API::is_connected() ) {
			return;
		}
		wp_add_dashboard_widget( 'downtime_status', __( 'DownTime', 'downtime-uptime-monitoring' ), array( __CLASS__, 'render' ) );
	}

	public static function flush() {
		delete_transient( self::CACHE );
	}

	public static function handle_refresh() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'downtime-uptime-monitoring' ), 403 );
		}
		check_admin_referer( 'downtime_widget_refresh' );
		self::flush();
		wp_safe_redirect( admin_url( 'index.php' ) );
		exit;
	}

	/** @return array{monitors:array, incidents:array, error:string} */
	private static function data() {
		$cached = get_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$ids = array_filter( array( (string) get_option( Downtime_Admin::OPTION_MONITOR_ID, '' ), Downtime_Heartbeat::monitor_id() ) );
		$out = array(
			'monitors'  => array(),
			'incidents' => array(),
			'error'     => '',
			'fetched'   => time(),
		);

		$list = Downtime_API::list_monitors();
		if ( ! Downtime_API::ok( $list ) ) {
			$out['error'] = Downtime_API::error_message( $list );
			set_transient( self::CACHE, $out, MINUTE_IN_SECONDS );
			return $out;
		}
		foreach ( (array) $list['body'] as $m ) {
			if ( ! is_array( $m ) || empty( $m['id'] ) || ! in_array( $m['id'], $ids, true ) ) {
				continue;
			}
			$results = Downtime_API::request( 'GET', '/api/results?monitor_id=' . rawurlencode( $m['id'] ) );
			$checks  = Downtime_API::ok( $results ) && is_array( $results['body'] ) ? $results['body'] : array();
			usort(
				$checks,
				function ( $a, $b ) {
					return strcmp( (string) ( $b['timestamp'] ?? '' ), (string) ( $a['timestamp'] ?? '' ) );
				}
			);
			$ok = 0;
			foreach ( $checks as $c ) {
				if ( isset( $c['status'] ) && 'ok' === $c['status'] ) {
					++$ok;
				}
			}
			$out['monitors'][] = array(
				'id'     => $m['id'],
				'name'   => (string) ( $m['name'] ?? '' ),
				'type'   => (string) ( $m['type'] ?? '' ),
				'active' => ! empty( $m['active'] ),
				'latest' => $checks ? $checks[0] : null,
				'checks' => count( $checks ),
				'ok'     => $ok,
			);
		}

		$incidents = Downtime_API::request( 'GET', '/api/incidents' );
		if ( Downtime_API::ok( $incidents ) && is_array( $incidents['body'] ) ) {
			foreach ( $incidents['body'] as $i ) {
				if ( is_array( $i ) && isset( $i['monitor_id'] ) && in_array( $i['monitor_id'], $ids, true ) ) {
					$out['incidents'][] = $i;
				}
				if ( count( $out['incidents'] ) >= 5 ) {
					break;
				}
			}
		}

		set_transient( self::CACHE, $out, self::TTL );
		return $out;
	}

	private static function ago( $iso ) {
		$t = strtotime( (string) $iso );
		/* translators: %s: human-readable time difference, e.g. "5 mins" */
		return $t ? sprintf( __( '%s ago', 'downtime-uptime-monitoring' ), human_time_diff( $t ) ) : '';
	}

	public static function render() {
		$data = self::data();
		if ( '' !== $data['error'] ) {
			echo '<p>' . esc_html( $data['error'] ) . '</p>';
		} elseif ( ! $data['monitors'] ) {
			echo '<p>' . esc_html__( 'This site is not monitored yet.', 'downtime-uptime-monitoring' ) . ' <a href="' . esc_url( admin_url( 'options-general.php?page=' . Downtime_Admin::PAGE ) ) . '">' . esc_html__( 'Set it up', 'downtime-uptime-monitoring' ) . '</a></p>';
		}

		foreach ( $data['monitors'] as $m ) {
			$latest = $m['latest'];
			if ( ! $m['active'] ) {
				$state = array( '#787c82', __( 'Paused', 'downtime-uptime-monitoring' ) );
			} elseif ( ! $latest ) {
				$state = array( '#787c82', __( 'Waiting for the first check', 'downtime-uptime-monitoring' ) );
			} elseif ( 'ok' === ( $latest['status'] ?? '' ) ) {
				$state = array( '#00a32a', __( 'Up', 'downtime-uptime-monitoring' ) );
			} else {
				$state = array( '#d63638', __( 'Down', 'downtime-uptime-monitoring' ) );
			}
			echo '<p style="margin:0 0 4px"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' . esc_attr( $state[0] ) . ';margin-inline-end:6px"></span>';
			echo '<strong>' . esc_html( $m['name'] ) . '</strong> — ' . esc_html( $state[1] ) . '</p>';

			$facts = array();
			if ( $latest ) {
				$facts[] = self::ago( $latest['timestamp'] ?? '' );
				if ( 'heartbeat' !== $m['type'] && isset( $latest['duration_ms'] ) ) {
					/* translators: %d: response time in milliseconds */
					$facts[] = sprintf( __( '%d ms', 'downtime-uptime-monitoring' ), (int) $latest['duration_ms'] );
				}
				if ( ! empty( $latest['cert_expiry_days'] ) ) {
					/* translators: %d: days until the TLS certificate expires */
					$facts[] = sprintf( __( 'certificate expires in %d days', 'downtime-uptime-monitoring' ), (int) $latest['cert_expiry_days'] );
				}
			}
			if ( $m['checks'] > 0 ) {
				/* translators: 1: uptime percentage, 2: number of recent checks */
				$facts[] = sprintf( _n( '%1$s%% up over the last %2$d check', '%1$s%% up over the last %2$d checks', $m['checks'], 'downtime-uptime-monitoring' ), number_format_i18n( 100 * $m['ok'] / $m['checks'], 1 ), $m['checks'] );
			}
			if ( $latest && 'ok' !== ( $latest['status'] ?? '' ) && ! empty( $latest['error'] ) ) {
				$facts[] = $latest['error'];
			}
			if ( $facts ) {
				echo '<p style="margin:0 0 10px;padding-inline-start:16px;color:#50575e">' . esc_html( implode( ' · ', array_filter( $facts ) ) ) . '</p>';
			}
		}

		if ( $data['incidents'] ) {
			echo '<h3 style="margin-top:12px">' . esc_html__( 'Recent incidents', 'downtime-uptime-monitoring' ) . '</h3><ul style="margin-top:4px">';
			foreach ( $data['incidents'] as $i ) {
				$resolved = 'resolved' === ( $i['status'] ?? '' );
				echo '<li>' . esc_html( self::ago( $i['created_at'] ?? '' ) ) . ' — ' . esc_html( $resolved ? __( 'resolved', 'downtime-uptime-monitoring' ) : __( 'open', 'downtime-uptime-monitoring' ) ) . ': ' . esc_html( wp_trim_words( (string) ( $i['message'] ?? '' ), 14 ) ) . '</li>';
			}
			echo '</ul>';
		}

		echo '<p style="margin-top:12px"><a href="' . esc_url( Downtime_API::base_url() . '/' ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open DownTime dashboard', 'downtime-uptime-monitoring' ) . '</a> · ';
		echo '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=downtime_widget_refresh' ), 'downtime_widget_refresh' ) ) . '">' . esc_html__( 'Refresh', 'downtime-uptime-monitoring' ) . '</a></p>';
	}
}
