<?php
/**
 * Front-end shortcodes.
 *
 * @package XtreamPro
 */

defined( 'ABSPATH' ) || exit;

class XtreamPro_Shortcodes {

	public static function init() {
		add_shortcode( 'xtreampro_packages', array( __CLASS__, 'packages' ) );
		add_shortcode( 'xtreampro_my_lines', array( __CLASS__, 'my_lines' ) );
		add_shortcode( 'xtreampro_my_reseller', array( __CLASS__, 'my_reseller' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	public static function register_assets() {
		wp_register_style( 'xtreampro', XTREAMPRO_URL . 'assets/xtreampro.css', array(), XTREAMPRO_VERSION );
		wp_register_script( 'xtreampro', false, array(), XTREAMPRO_VERSION, true );
		wp_add_inline_script(
			'xtreampro',
			"document.addEventListener('click',function(e){var b=e.target.closest?e.target.closest('.xtreampro-toggle'):null;if(!b){return;}var s=b.parentNode.querySelector('.xtreampro-pass');if(!s){return;}var shown=b.getAttribute('data-shown')==='1';s.textContent=shown?'\\u2022\\u2022\\u2022\\u2022\\u2022\\u2022\\u2022\\u2022':s.getAttribute('data-password');b.setAttribute('data-shown',shown?'0':'1');b.textContent=shown?b.getAttribute('data-show'):b.getAttribute('data-hide');});"
		);
	}

	/**
	 * Notice shown to visitors; admins also see the reason.
	 *
	 * @param WP_Error|string $reason Error.
	 * @return string
	 */
	private static function unavailable( $reason ) {
		$out = '<div class="xtreampro-notice">' . esc_html__( 'This information is temporarily unavailable.', 'xtreampro' );
		if ( current_user_can( 'manage_options' ) ) {
			$msg  = is_wp_error( $reason ) ? $reason->get_error_message() : (string) $reason;
			$out .= ' <em>' . esc_html( $msg ) . '</em>';
		}
		return $out . '</div>';
	}

	/**
	 * [xtreampro_packages ids="1,2" trial="1"]
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function packages( $atts ) {
		$atts = shortcode_atts(
			array(
				'ids'   => '',
				'trial' => '0',
			),
			$atts,
			'xtreampro_packages'
		);
		wp_enqueue_style( 'xtreampro' );

		$list = XtreamPro_API::line_packages();
		if ( is_wp_error( $list ) ) {
			return self::unavailable( $list );
		}

		$trial = filter_var( $atts['trial'], FILTER_VALIDATE_BOOLEAN );
		$ids   = array_filter( array_map( 'absint', explode( ',', (string) $atts['ids'] ) ) );

		$items = array();
		foreach ( $list as $pkg ) {
			$flag = $trial ? 'is_trial' : 'is_official';
			if ( empty( $pkg[ $flag ] ) || '0' === $pkg[ $flag ] ) {
				continue;
			}
			if ( $ids && ! in_array( (int) $pkg['id'], $ids, true ) ) {
				continue;
			}
			$items[] = $pkg;
		}
		if ( ! $items ) {
			return '<div class="xtreampro-notice">' . esc_html__( 'No packages available.', 'xtreampro' ) . '</div>';
		}

		$out = '<div class="xtreampro-packages">';
		foreach ( $items as $pkg ) {
			if ( $trial ) {
				$duration = XtreamPro_API::format_duration( isset( $pkg['trial_duration'] ) ? $pkg['trial_duration'] : 0, isset( $pkg['trial_duration_in'] ) ? $pkg['trial_duration_in'] : '' );
			} else {
				$duration = XtreamPro_API::format_duration( isset( $pkg['official_duration'] ) ? $pkg['official_duration'] : 0, isset( $pkg['official_duration_in'] ) ? $pkg['official_duration_in'] : '' );
			}
			$conns = isset( $pkg['max_connections'] ) ? (int) $pkg['max_connections'] : 0;

			$out .= '<div class="xtreampro-package">';
			$out .= '<h3 class="xtreampro-package-name">' . esc_html( isset( $pkg['name'] ) ? (string) $pkg['name'] : '' ) . '</h3>';
			$out .= '<ul class="xtreampro-package-features">';
			$out .= '<li>' . esc_html( $duration ) . '</li>';
			if ( $conns > 0 ) {
				/* translators: %d: number of simultaneous connections */
				$out .= '<li>' . esc_html( sprintf( _n( '%d connection', '%d connections', $conns, 'xtreampro' ), $conns ) ) . '</li>';
			}
			$out .= '</ul></div>';
		}
		return $out . '</div>';
	}

	/**
	 * Human label of a line status.
	 *
	 * @param string $status Status from the API.
	 * @return string
	 */
	private static function status_label( $status ) {
		switch ( $status ) {
			case 'active':
				return __( 'Active', 'xtreampro' );
			case 'expired':
				return __( 'Expired', 'xtreampro' );
			case 'disabled':
				return __( 'Disabled', 'xtreampro' );
			case 'banned':
				return __( 'Banned', 'xtreampro' );
		}
		return __( 'Unknown', 'xtreampro' );
	}

	/**
	 * [xtreampro_my_lines]
	 *
	 * @return string
	 */
	public static function my_lines() {
		wp_enqueue_style( 'xtreampro' );

		if ( ! is_user_logged_in() ) {
			return '<div class="xtreampro-notice">' . sprintf(
				/* translators: %s: login link */
				esc_html__( 'Please %s to see your lines.', 'xtreampro' ),
				'<a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'log in', 'xtreampro' ) . '</a>'
			) . '</div>';
		}

		$seen = array();
		foreach ( XtreamPro_API::user_lines( get_current_user_id() ) as $entry ) {
			if ( ! empty( $entry['line_id'] ) ) {
				$seen[ (int) $entry['line_id'] ] = $entry;
			}
		}
		if ( ! $seen ) {
			return '<div class="xtreampro-notice">' . esc_html__( 'You have no lines yet.', 'xtreampro' ) . '</div>';
		}

		wp_enqueue_script( 'xtreampro' );

		$out = '<div class="xtreampro-lines">';
		foreach ( $seen as $line_id => $entry ) {
			$line = XtreamPro_API::get_line( $line_id );
			$out .= '<div class="xtreampro-line">';
			$out .= '<h3 class="xtreampro-line-title">' . esc_html( sprintf( /* translators: %d: line id */ __( 'Line #%d', 'xtreampro' ), $line_id ) ) . '</h3>';
			if ( is_wp_error( $line ) ) {
				$out .= '<p class="xtreampro-notice">' . esc_html__( 'Details are temporarily unavailable.', 'xtreampro' ) . '</p>';
				if ( ! empty( $entry['status'] ) ) {
					// Last status the panel pushed by webhook.
					$out .= '<p><span class="xtreampro-status xtreampro-status-' . esc_attr( sanitize_html_class( (string) $entry['status'] ) ) . '">' . esc_html( self::status_label( (string) $entry['status'] ) ) . '</span></p>';
				}
				$out .= '</div>';
				continue;
			}

			$status   = isset( $line['status'] ) ? (string) $line['status'] : '';
			$username = isset( $line['username'] ) ? (string) $line['username'] : '';
			$password = isset( $line['password'] ) ? (string) $line['password'] : '';
			$exp      = isset( $line['exp_date'] ) && null !== $line['exp_date'] ? (int) $line['exp_date'] : 0;
			$expires  = $exp > 0 ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $exp ) : __( 'Never', 'xtreampro' );

			$out .= '<dl class="xtreampro-details">';
			$out .= '<dt>' . esc_html__( 'Status', 'xtreampro' ) . '</dt><dd><span class="xtreampro-status xtreampro-status-' . esc_attr( sanitize_html_class( $status ) ) . '">' . esc_html( self::status_label( $status ) ) . '</span></dd>';
			$out .= '<dt>' . esc_html__( 'Expires', 'xtreampro' ) . '</dt><dd>' . esc_html( $expires ) . '</dd>';
			if ( isset( $line['max_connections'] ) ) {
				$out .= '<dt>' . esc_html__( 'Connections', 'xtreampro' ) . '</dt><dd>' . esc_html( (string) (int) $line['max_connections'] ) . '</dd>';
			}
			// The links the panel returned with the line; assembled here only when it sent none.
			$links = array();
			foreach ( XtreamPro_API::link_rows( XtreamPro_API::links( $line ), $username, $password ) as $row ) {
				$links[ $row['key'] ] = $row['url'];
			}
			$out .= '<dt>' . esc_html__( 'Server', 'xtreampro' ) . '</dt><dd><code>' . esc_html( isset( $links['server'] ) ? $links['server'] : XtreamPro_API::base_url() ) . '</code></dd>';
			$out .= '<dt>' . esc_html__( 'Username', 'xtreampro' ) . '</dt><dd><code>' . esc_html( $username ) . '</code></dd>';
			$out .= '<dt>' . esc_html__( 'Password', 'xtreampro' ) . '</dt><dd><span><code class="xtreampro-pass" data-password="' . esc_attr( $password ) . '">&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;</code> '
				. '<button type="button" class="xtreampro-toggle" data-shown="0" data-show="' . esc_attr__( 'Show', 'xtreampro' ) . '" data-hide="' . esc_attr__( 'Hide', 'xtreampro' ) . '">' . esc_html__( 'Show', 'xtreampro' ) . '</button></span></dd>';
			$shown = array(
				'm3u'        => array( __( 'Playlist (M3U)', 'xtreampro' ), __( 'Open playlist', 'xtreampro' ) ),
				'm3u_hls'    => array( __( 'Playlist (HLS)', 'xtreampro' ), __( 'Open playlist', 'xtreampro' ) ),
				'xmltv'      => array( __( 'Programme guide (XMLTV)', 'xtreampro' ), __( 'Open guide', 'xtreampro' ) ),
				'web_player' => array( __( 'Web player', 'xtreampro' ), __( 'Open web player', 'xtreampro' ) ),
			);
			foreach ( $shown as $key => $texts ) {
				if ( isset( $links[ $key ] ) ) {
					$out .= '<dt>' . esc_html( $texts[0] ) . '</dt><dd><a href="' . esc_url( $links[ $key ] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $texts[1] ) . '</a></dd>';
				}
			}
			$out .= '</dl></div>';
		}
		return $out . '</div>';
	}

	/**
	 * [xtreampro_my_reseller]
	 *
	 * @return string
	 */
	public static function my_reseller() {
		wp_enqueue_style( 'xtreampro' );

		if ( ! is_user_logged_in() ) {
			return '<div class="xtreampro-notice">' . sprintf(
				/* translators: %s: login link */
				esc_html__( 'Please %s to see your reseller account.', 'xtreampro' ),
				'<a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'log in', 'xtreampro' ) . '</a>'
			) . '</div>';
		}

		$user_id = get_current_user_id();
		$account = XtreamPro_API::user_reseller( $user_id );
		if ( ! $account ) {
			return '<div class="xtreampro-notice">' . esc_html__( 'You have no reseller account yet.', 'xtreampro' ) . '</div>';
		}

		$key  = 'xtreampro_rs_' . $user_id;
		$info = get_transient( $key );
		if ( ! is_array( $info ) ) {
			$info = XtreamPro_API::find_sub_user( $account['user_id'], $account['username'] );
			if ( ! is_wp_error( $info ) ) {
				set_transient( $key, $info, MINUTE_IN_SECONDS );
			}
		}

		$login = XtreamPro_API::panel_login_url();
		$out   = '<div class="xtreampro-reseller xtreampro-line">';
		$out  .= '<h3 class="xtreampro-line-title">' . esc_html__( 'Your reseller account', 'xtreampro' ) . '</h3>';
		$out  .= '<dl class="xtreampro-details">';
		$out  .= '<dt>' . esc_html__( 'Username', 'xtreampro' ) . '</dt><dd><code>' . esc_html( $account['username'] ) . '</code></dd>';
		if ( is_wp_error( $info ) ) {
			$out .= '<dt>' . esc_html__( 'Details', 'xtreampro' ) . '</dt><dd>' . esc_html__( 'Details are temporarily unavailable.', 'xtreampro' ) . '</dd>';
		} else {
			$status = isset( $info['status'] ) ? (string) $info['status'] : '';
			$out   .= '<dt>' . esc_html__( 'Status', 'xtreampro' ) . '</dt><dd><span class="xtreampro-status xtreampro-status-' . esc_attr( sanitize_html_class( $status ) ) . '">' . esc_html( self::status_label( $status ) ) . '</span></dd>';
			$out   .= '<dt>' . esc_html__( 'Credits', 'xtreampro' ) . '</dt><dd>' . esc_html( number_format_i18n( isset( $info['credits'] ) ? (int) $info['credits'] : 0 ) ) . '</dd>';
		}
		if ( '' !== $login ) {
			$out .= '<dt>' . esc_html__( 'Sign in', 'xtreampro' ) . '</dt><dd><a href="' . esc_url( $login ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $login ) . '</a></dd>';
		}
		return $out . '</dl></div>';
	}
}
