<?php
/**
 * Server-side DownTime API client.
 *
 * Every call happens here, from PHP, with the key in the X-API-Key header.
 * The key never reaches a browser. It is an `integration`-scoped key
 * (DownTime gateway, services/gateway-go/scopes.go): it can list/create
 * monitors and status pages and read results, nothing else.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Downtime_API {

	const OPTION_KEY = 'downtime_api_key';

	public static function base_url() {
		return untrailingslashit( DOWNTIME_BASE_URL );
	}

	public static function get_key() {
		return (string) get_option( self::OPTION_KEY, '' );
	}

	public static function is_connected() {
		return '' !== self::get_key();
	}

	/**
	 * Store the key with autoload off: it's only needed on the settings page
	 * and in API calls, never on every front-end request.
	 */
	public static function set_key( $key ) {
		delete_option( self::OPTION_KEY );
		add_option( self::OPTION_KEY, $key, '', false );
	}

	public static function clear_key() {
		delete_option( self::OPTION_KEY );
	}

	/**
	 * @return array{code:int, body:mixed}|WP_Error
	 */
	public static function request( $method, $path, $body = null, $authenticated = true ) {
		$headers = array( 'Accept' => 'application/json' );
		if ( $authenticated ) {
			$key = self::get_key();
			if ( '' === $key ) {
				return new WP_Error( 'downtime_not_connected', __( 'This site is not connected to DownTime.', 'downtime-uptime-monitoring' ) );
			}
			$headers['X-API-Key'] = $key;
		}
		$args = array(
			'method'  => $method,
			'headers' => $headers,
			'timeout' => 15,
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::base_url() . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		return array(
			'code' => $code,
			'body' => $decoded,
		);
	}

	/** Human-readable error from a non-2xx response or WP_Error. */
	public static function error_message( $result ) {
		if ( is_wp_error( $result ) ) {
			return $result->get_error_message();
		}
		if ( is_array( $result['body'] ) && ! empty( $result['body']['error'] ) ) {
			return sprintf( '%s (HTTP %d)', $result['body']['error'], $result['code'] );
		}
		return sprintf( __( 'Unexpected response from DownTime (HTTP %d).', 'downtime-uptime-monitoring' ), $result['code'] );
	}

	public static function ok( $result ) {
		return ! is_wp_error( $result ) && $result['code'] >= 200 && $result['code'] < 300;
	}

	// --- Connect handshake (DownTime #362) ---------------------------------

	/**
	 * Exchange the single-use code from the Connect redirect for a key.
	 * Server-to-server, so the key never appears in a URL.
	 */
	public static function exchange_code( $code, $redirect_uri ) {
		return self::request(
			'POST',
			'/api/auth/integrations/exchange',
			array(
				'code'         => $code,
				'redirect_uri' => $redirect_uri,
			),
			false
		);
	}

	/** Revoke this site's own key on DownTime (Disconnect). */
	public static function revoke_self() {
		return self::request( 'DELETE', '/api/auth/integrations/self' );
	}

	// --- Resources -----------------------------------------------------------

	public static function get_user() {
		return self::request( 'GET', '/api/auth/user' );
	}

	public static function list_monitors() {
		return self::request( 'GET', '/api/monitors' );
	}

	public static function create_monitor( $monitor ) {
		return self::request( 'POST', '/api/monitors', $monitor );
	}

	public static function list_status_pages() {
		return self::request( 'GET', '/api/status-pages' );
	}

	public static function create_status_page( $page ) {
		return self::request( 'POST', '/api/status-pages', $page );
	}
}
