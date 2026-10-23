<?php
/**
 * Front-end output: [downtime_badge], [downtime_status] and the
 * "DownTime Status" block. Everything here is public DownTime data (status
 * badges and the public status page), so no API key is involved.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Downtime_Embed {

	const OPTION_SLUG = 'downtime_status_slug';

	public static function init() {
		add_shortcode( 'downtime_badge', array( __CLASS__, 'badge' ) );
		add_shortcode( 'downtime_status', array( __CLASS__, 'status' ) );
		add_action( 'init', array( __CLASS__, 'register_block' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_script' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'register_script' ) );
	}

	/** DownTime's slug rule: 1–40 chars, [a-z0-9-], alphanumeric at both ends. */
	public static function sanitize_slug( $slug ) {
		$slug = strtolower( trim( (string) $slug, " \t\n\r\0\x0B-" ) );
		$slug = substr( $slug, 0, 40 );
		$slug = rtrim( $slug, '-' );
		return preg_match( '/^[a-z0-9]([a-z0-9-]{0,38}[a-z0-9])?$/', $slug ) ? $slug : '';
	}

	public static function default_slug() {
		return self::sanitize_slug( get_option( self::OPTION_SLUG, '' ) );
	}

	private static function resolve_slug( $atts ) {
		$slug = isset( $atts['slug'] ) && '' !== $atts['slug'] ? self::sanitize_slug( $atts['slug'] ) : self::default_slug();
		return $slug;
	}

	private static function missing() {
		if ( current_user_can( 'manage_options' ) ) {
			return '<em>' . esc_html__( 'DownTime: no status page set. Create one under Settings → DownTime, or pass slug="…".', 'downtime' ) . '</em>';
		}
		return '';
	}

	/** [downtime_badge slug="" type="status|uptime"] */
	public static function badge( $atts ) {
		$atts = shortcode_atts(
			array(
				'slug' => '',
				'type' => 'status',
			),
			$atts,
			'downtime_badge'
		);
		$slug = self::resolve_slug( $atts );
		if ( '' === $slug ) {
			return self::missing();
		}
		$file = 'uptime' === $atts['type'] ? 'uptime.svg' : 'badge.svg';
		$base = Downtime_API::base_url();
		return sprintf(
			'<a href="%s" target="_blank" rel="noopener"><img src="%s" alt="%s" style="vertical-align:middle"></a>',
			esc_url( $base . '/status/' . $slug ),
			esc_url( $base . '/api/status/' . $slug . '/' . $file ),
			esc_attr( 'uptime' === $atts['type'] ? __( 'Uptime', 'downtime' ) : __( 'Service status', 'downtime' ) )
		);
	}

	/** [downtime_status slug="" height="600"] */
	public static function status( $atts ) {
		$atts = shortcode_atts(
			array(
				'slug'   => '',
				'height' => '600',
			),
			$atts,
			'downtime_status'
		);
		$slug = self::resolve_slug( $atts );
		if ( '' === $slug ) {
			return self::missing();
		}
		$height = max( 100, min( 5000, absint( $atts['height'] ) ) );
		wp_enqueue_script( 'downtime-embed' );
		return sprintf(
			'<iframe class="downtime-status" data-downtime-slug="%s" src="%s" title="%s" loading="lazy" style="width:100%%;height:%dpx;border:0;display:block"></iframe>',
			esc_attr( $slug ),
			esc_url( Downtime_API::base_url() . '/status/' . $slug . '?embed=1' ),
			esc_attr__( 'Service status', 'downtime' ),
			$height
		);
	}

	public static function register_script() {
		wp_register_script( 'downtime-embed', DOWNTIME_PLUGIN_URL . 'assets/embed.js', array(), DOWNTIME_PLUGIN_VERSION, true );
		wp_localize_script( 'downtime-embed', 'downtimeEmbed', array( 'origin' => Downtime_API::base_url() ) );
	}

	public static function register_block() {
		register_block_type(
			DOWNTIME_PLUGIN_DIR . 'blocks/status',
			array( 'render_callback' => array( __CLASS__, 'render_block' ) )
		);
	}

	public static function render_block( $attributes ) {
		$slug = isset( $attributes['slug'] ) ? $attributes['slug'] : '';
		$mode = isset( $attributes['mode'] ) ? $attributes['mode'] : 'page';
		if ( 'page' === $mode ) {
			return self::status(
				array(
					'slug'   => $slug,
					'height' => isset( $attributes['height'] ) ? $attributes['height'] : 600,
				)
			);
		}
		return self::badge(
			array(
				'slug' => $slug,
				'type' => 'uptime' === $mode ? 'uptime' : 'status',
			)
		);
	}
}
