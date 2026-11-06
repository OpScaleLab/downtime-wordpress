<?php
/**
 * Settings → DownTime, the parts that manage this site's checks:
 *
 * - Recommended checks: one click adds WordPress-specific monitors (login
 *   page, REST API, sitemap, TLS certificate) with the details filled in.
 * - This site's monitors: pause, resume and delete them from wp-admin.
 *   DownTime only lets this site's key change monitors it created itself
 *   (chart 0.31.0+); others link to the DownTime dashboard instead.
 * - A "Status" page on this site with the status page block in it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Downtime_Checks {

	const OPTION_WP_PAGE = 'downtime_wp_status_page_id';

	public static function init() {
		add_action( 'admin_post_downtime_add_checks', array( __CLASS__, 'handle_add_checks' ) );
		add_action( 'admin_post_downtime_monitor_pause', array( __CLASS__, 'handle_pause' ) );
		add_action( 'admin_post_downtime_monitor_resume', array( __CLASS__, 'handle_resume' ) );
		add_action( 'admin_post_downtime_monitor_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_downtime_wp_status_page', array( __CLASS__, 'handle_wp_status_page' ) );
	}

	/** The site this plugin connected as; DownTime stamps it on monitors this site's key creates. */
	private static function site() {
		return home_url( '/' );
	}

	private static function site_name() {
		$name = get_bloginfo( 'name' );
		return '' !== $name ? $name : (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
	}

	/**
	 * WordPress-specific checks, keyed by a stable ID. Each is a full
	 * monitor definition; DownTime fills in the rest from plan defaults.
	 */
	public static function presets() {
		$https   = 'https' === wp_parse_url( home_url( '/' ), PHP_URL_SCHEME );
		$type    = $https ? 'https' : 'http';
		$name    = self::site_name();
		$presets = array(
			'login' => array(
				'label'   => __( 'Login page', 'downtime-uptime-monitoring' ),
				'help'    => __( 'wp-login.php answers, so you and your users can sign in.', 'downtime-uptime-monitoring' ),
				'monitor' => array(
					/* translators: %s: site name */
					'name'          => sprintf( __( '%s: login page', 'downtime-uptime-monitoring' ), $name ),
					'url'           => wp_login_url(),
					'type'          => $type,
					'expected_code' => 200,
					'interval'      => 300,
				),
			),
			'rest'  => array(
				'label'   => __( 'REST API', 'downtime-uptime-monitoring' ),
				'help'    => __( '/wp-json/ answers. The block editor, many plugins and mobile apps depend on it.', 'downtime-uptime-monitoring' ),
				'monitor' => array(
					/* translators: %s: site name */
					'name'          => sprintf( __( '%s: REST API', 'downtime-uptime-monitoring' ), $name ),
					'url'           => get_rest_url(),
					'type'          => $type,
					'expected_code' => 200,
					'interval'      => 300,
				),
			),
		);
		if ( get_option( 'blog_public' ) && function_exists( 'get_sitemap_url' ) && get_sitemap_url( 'index' ) ) {
			$presets['sitemap'] = array(
				'label'   => __( 'XML sitemap', 'downtime-uptime-monitoring' ),
				'help'    => __( 'The sitemap search engines read is reachable.', 'downtime-uptime-monitoring' ),
				'monitor' => array(
					/* translators: %s: site name */
					'name'          => sprintf( __( '%s: sitemap', 'downtime-uptime-monitoring' ), $name ),
					'url'           => get_sitemap_url( 'index' ),
					'type'          => $type,
					'expected_code' => 200,
					'interval'      => 3600,
				),
			);
		}
		if ( $https ) {
			$presets['tls'] = array(
				'label'   => __( 'TLS certificate', 'downtime-uptime-monitoring' ),
				'help'    => __( 'Warns you 14 days before the HTTPS certificate expires.', 'downtime-uptime-monitoring' ),
				'monitor' => array(
					/* translators: %s: site name */
					'name'                       => sprintf( __( '%s: TLS certificate', 'downtime-uptime-monitoring' ), $name ),
					'url'                        => home_url( '/' ),
					'type'                       => 'tls',
					'cert_expiry_threshold_days' => 14,
					'interval'                   => 3600,
				),
			);
		}
		return $presets;
	}

	/** The account monitor that already covers a preset, if any. */
	private static function find_preset( $preset, $monitors ) {
		$want = $preset['monitor'];
		foreach ( $monitors as $m ) {
			if ( ( $m['type'] ?? '' ) === $want['type'] && untrailingslashit( (string) ( $m['url'] ?? '' ) ) === untrailingslashit( $want['url'] ) ) {
				return $m;
			}
		}
		return null;
	}

	/**
	 * The account's monitors that belong to this site: created by this
	 * site's key, pointing at this site, or the WP-Cron heartbeat.
	 */
	public static function site_monitors( $monitors ) {
		$host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$out  = array();
		foreach ( $monitors as $m ) {
			if ( empty( $m['id'] ) ) {
				continue;
			}
			$mine   = self::site() === ( $m['integrationSite'] ?? '' );
			$points = '' !== $host && strtolower( (string) wp_parse_url( (string) ( $m['url'] ?? '' ), PHP_URL_HOST ) ) === $host;
			if ( $mine || $points || Downtime_Heartbeat::monitor_id() === $m['id'] ) {
				$out[] = $m;
			}
		}
		return $out;
	}

	private static function find( $id ) {
		foreach ( Downtime_Admin::list_monitors() as $m ) {
			if ( isset( $m['id'] ) && $m['id'] === $id ) {
				return $m;
			}
		}
		return null;
	}

	private static function posted_id() {
		// Nonce checked by the caller's require_admin().
		$id = isset( $_POST['monitor_id'] ) ? sanitize_text_field( wp_unslash( $_POST['monitor_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return Downtime_Admin::is_uuid( $id ) ? $id : '';
	}

	// --- Handlers -------------------------------------------------------------

	public static function handle_add_checks() {
		Downtime_Admin::require_admin( 'downtime_add_checks' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked by require_admin() above.
		$picked   = isset( $_POST['checks'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['checks'] ) ) : array();
		$presets  = self::presets();
		$monitors = Downtime_Admin::list_monitors();
		$added    = array();
		foreach ( $picked as $key ) {
			if ( ! isset( $presets[ $key ] ) || self::find_preset( $presets[ $key ], $monitors ) ) {
				continue;
			}
			$result = Downtime_API::create_monitor( $presets[ $key ]['monitor'] );
			if ( ! Downtime_API::ok( $result ) ) {
				$message = Downtime_API::error_message( $result );
				if ( $added ) {
					/* translators: 1: checks that were added, 2: error message */
					$message = sprintf( __( 'Added %1$s, then stopped: %2$s', 'downtime-uptime-monitoring' ), implode( ', ', $added ), $message );
				}
				Downtime_Admin::done( 'error', $message );
			}
			$added[] = $presets[ $key ]['label'];
		}
		if ( ! $added ) {
			Downtime_Admin::done( 'warning', __( 'Pick at least one check that is not added yet.', 'downtime-uptime-monitoring' ) );
		}
		/* translators: %s: comma-separated list of checks */
		Downtime_Admin::done( 'success', sprintf( __( 'Added: %s. The first results appear within a few minutes.', 'downtime-uptime-monitoring' ), implode( ', ', $added ) ) );
	}

	public static function handle_pause() {
		Downtime_Admin::require_admin( 'downtime_monitor_pause' );
		self::set_active( false );
	}

	public static function handle_resume() {
		Downtime_Admin::require_admin( 'downtime_monitor_resume' );
		self::set_active( true );
	}

	/**
	 * DownTime's monitor update replaces the whole definition, so resend
	 * the monitor as listed with only `active` changed.
	 */
	private static function set_active( $active ) {
		$m = self::find( self::posted_id() );
		if ( ! $m ) {
			Downtime_Admin::done( 'error', __( 'That monitor no longer exists on DownTime.', 'downtime-uptime-monitoring' ) );
		}
		$map  = array(
			'name'                      => 'name',
			'url'                       => 'url',
			'type'                      => 'type',
			'region'                    => 'region',
			'interval'                  => 'interval',
			'timeout'                   => 'timeout',
			'method'                    => 'method',
			'expectedCode'              => 'expected_code',
			'headers'                   => 'headers',
			'body'                      => 'body',
			'port'                      => 'port',
			'followRedirects'           => 'follow_redirects',
			'conditions'                => 'conditions',
			'maxResponseTimeMs'         => 'max_response_time_ms',
			'certExpiryThresholdDays'   => 'cert_expiry_threshold_days',
			'domainExpiryThresholdDays' => 'domain_expiry_threshold_days',
			'resourceVersion'           => 'resource_version',
		);
		$body = array( 'active' => (bool) $active );
		foreach ( $map as $from => $to ) {
			if ( isset( $m[ $from ] ) ) {
				$body[ $to ] = $m[ $from ];
			}
		}
		$result = Downtime_API::update_monitor( $m['id'], $body );
		if ( ! Downtime_API::ok( $result ) ) {
			Downtime_Admin::done( 'error', self::change_error( $result ) );
		}
		Downtime_Admin::done( 'success', $active ? __( 'Monitor resumed.', 'downtime-uptime-monitoring' ) : __( 'Monitor paused. It keeps its history and alerts, and stops checking until you resume it.', 'downtime-uptime-monitoring' ) );
	}

	public static function handle_delete() {
		Downtime_Admin::require_admin( 'downtime_monitor_delete' );
		$id = self::posted_id();
		if ( '' === $id ) {
			Downtime_Admin::done( 'error', __( 'That monitor no longer exists on DownTime.', 'downtime-uptime-monitoring' ) );
		}
		$result = Downtime_API::delete_monitor( $id );
		if ( ! Downtime_API::ok( $result ) && ! ( ! is_wp_error( $result ) && 404 === $result['code'] ) ) {
			Downtime_Admin::done( 'error', self::change_error( $result ) );
		}
		// Forget what this plugin tracked about the monitor.
		if ( get_option( Downtime_Admin::OPTION_MONITOR_ID ) === $id ) {
			delete_option( Downtime_Admin::OPTION_MONITOR_ID );
			delete_option( Downtime_Admin::OPTION_ALERT_ID );
		}
		if ( Downtime_Heartbeat::monitor_id() === $id ) {
			Downtime_Heartbeat::disable();
			delete_option( Downtime_Admin::OPTION_HB_ALERT );
		}
		Downtime_Admin::done( 'success', __( 'Monitor deleted.', 'downtime-uptime-monitoring' ) );
	}

	private static function change_error( $result ) {
		if ( ! is_wp_error( $result ) && 403 === $result['code'] ) {
			return __( 'DownTime only lets this site change monitors it created. Change this one in the DownTime dashboard.', 'downtime-uptime-monitoring' );
		}
		return Downtime_API::error_message( $result );
	}

	public static function handle_wp_status_page() {
		Downtime_Admin::require_admin( 'downtime_wp_status_page' );
		if ( '' === Downtime_Embed::default_slug() ) {
			Downtime_Admin::done( 'error', __( 'Create or pick a DownTime status page first.', 'downtime-uptime-monitoring' ) );
		}
		$existing = self::wp_page();
		if ( $existing ) {
			Downtime_Admin::done( 'success', __( 'This site already has a status page.', 'downtime-uptime-monitoring' ) );
		}
		// The block with no slug follows whichever status page is picked here.
		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => __( 'Status', 'downtime-uptime-monitoring' ),
				'post_name'    => 'status',
				'post_content' => '<!-- wp:downtime/status /-->',
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			Downtime_Admin::done( 'error', $id->get_error_message() );
		}
		update_option( self::OPTION_WP_PAGE, (int) $id, false );
		Downtime_Admin::done( 'success', __( 'Published a Status page on this site. Add it to your menu under Appearance.', 'downtime-uptime-monitoring' ) );
	}

	/** The page this plugin published, unless it was deleted or trashed since. */
	private static function wp_page() {
		$id   = (int) get_option( self::OPTION_WP_PAGE, 0 );
		$page = $id ? get_post( $id ) : null;
		if ( ! $page || 'page' !== $page->post_type || 'trash' === $page->post_status ) {
			return null;
		}
		return $page;
	}

	// --- Rendering ------------------------------------------------------------

	/** Recommended checks not added yet, for a site DownTime can reach. */
	public static function render_presets( $monitors ) {
		$boxes = '';
		foreach ( self::presets() as $key => $p ) {
			if ( self::find_preset( $p, $monitors ) ) {
				continue;
			}
			$boxes .= '<label style="display:block;margin:4px 0"><input type="checkbox" name="checks[]" value="' . esc_attr( $key ) . '" checked> <strong>' . esc_html( $p['label'] ) . '</strong> <span class="description">' . esc_html( $p['help'] ) . '</span></label>';
		}
		if ( '' === $boxes ) {
			return;
		}
		echo '<h3>' . esc_html__( 'Recommended checks', 'downtime-uptime-monitoring' ) . '</h3>';
		Downtime_Admin::form( 'downtime_add_checks', __( 'Add selected checks', 'downtime-uptime-monitoring' ), 'button', '<fieldset style="margin:0 0 8px">' . $boxes . '</fieldset>' );
	}

	public static function render_table( $monitors ) {
		$mine = self::site_monitors( $monitors );
		if ( ! $mine ) {
			return;
		}
		echo '<h3>' . esc_html__( "This site's monitors", 'downtime-uptime-monitoring' ) . '</h3>';
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr>';
		echo '<th>' . esc_html__( 'Monitor', 'downtime-uptime-monitoring' ) . '</th>';
		echo '<th>' . esc_html__( 'Checks', 'downtime-uptime-monitoring' ) . '</th>';
		echo '<th>' . esc_html__( 'State', 'downtime-uptime-monitoring' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';
		$confirm = esc_js( __( 'Delete this monitor and its history on DownTime?', 'downtime-uptime-monitoring' ) );
		foreach ( $mine as $m ) {
			$type   = (string) ( $m['type'] ?? '' );
			$what   = 'heartbeat' === $type ? __( 'WP-Cron heartbeat', 'downtime-uptime-monitoring' ) : strtoupper( $type ) . ' ' . (string) ( $m['url'] ?? '' );
			$every  = isset( $m['interval'] ) ? (int) $m['interval'] : 0;
			$active = ! empty( $m['active'] );
			echo '<tr><td><strong>' . esc_html( (string) ( $m['name'] ?? $m['id'] ) ) . '</strong></td>';
			echo '<td>' . esc_html( $what );
			if ( $every > 0 ) {
				/* translators: %s: human-readable interval, e.g. "5 mins" */
				echo '<br><span class="description">' . esc_html( sprintf( __( 'every %s', 'downtime-uptime-monitoring' ), human_time_diff( 0, $every ) ) ) . '</span>';
			}
			echo '</td><td>' . esc_html( $active ? __( 'Active', 'downtime-uptime-monitoring' ) : __( 'Paused', 'downtime-uptime-monitoring' ) ) . '</td><td>';
			if ( self::site() === ( $m['integrationSite'] ?? '' ) ) {
				$id = '<input type="hidden" name="monitor_id" value="' . esc_attr( $m['id'] ) . '">';
				if ( $active ) {
					Downtime_Admin::form( 'downtime_monitor_pause', __( 'Pause', 'downtime-uptime-monitoring' ), 'button button-small', $id );
				} else {
					Downtime_Admin::form( 'downtime_monitor_resume', __( 'Resume', 'downtime-uptime-monitoring' ), 'button button-small', $id );
				}
				Downtime_Admin::form( 'downtime_monitor_delete', __( 'Delete', 'downtime-uptime-monitoring' ), 'button button-small button-link-delete', $id, 'return confirm(\'' . $confirm . '\')' );
			} else {
				echo '<a href="' . esc_url( Downtime_API::base_url() . '/' ) . '" target="_blank" rel="noopener">' . esc_html__( 'Manage on DownTime', 'downtime-uptime-monitoring' ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** Under the status page section, once one is picked. */
	public static function render_wp_page() {
		echo '<h3>' . esc_html__( 'On this site', 'downtime-uptime-monitoring' ) . '</h3>';
		$page = self::wp_page();
		if ( $page ) {
			$state = 'publish' === $page->post_status ? '' : ' (' . get_post_status_object( $page->post_status )->label . ')';
			/* translators: %s: page title */
			echo '<p>' . esc_html( sprintf( __( 'Your status is shown on the "%s" page', 'downtime-uptime-monitoring' ), get_the_title( $page ) ) . $state ) . ': ';
			echo '<a href="' . esc_url( get_permalink( $page ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'View', 'downtime-uptime-monitoring' ) . '</a> · ';
			echo '<a href="' . esc_url( (string) get_edit_post_link( $page->ID ) ) . '">' . esc_html__( 'Edit', 'downtime-uptime-monitoring' ) . '</a></p>';
			return;
		}
		echo '<p>' . esc_html__( 'Publish a "Status" page on this site that shows the status page above, so visitors can check it without leaving your site.', 'downtime-uptime-monitoring' ) . '</p>';
		Downtime_Admin::form( 'downtime_wp_status_page', __( 'Add a Status page to this site', 'downtime-uptime-monitoring' ), 'button' );
	}
}
