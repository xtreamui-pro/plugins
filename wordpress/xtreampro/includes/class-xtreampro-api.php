<?php
/**
 * Xtream UI Pro Reseller API client.
 *
 * @package XtreamPro
 */

defined( 'ABSPATH' ) || exit;

class XtreamPro_API {

	const PACKAGES_TRANSIENT = 'xtreampro_packages';
	const USER_LINES_META    = '_xtreampro_lines';
	const USER_RESELLER_META = '_xtreampro_reseller';

	/**
	 * Panel base URL (no trailing slash). The constant wins over the option.
	 *
	 * @return string
	 */
	public static function base_url() {
		$url = ( defined( 'XTREAMPRO_API_URL' ) && XTREAMPRO_API_URL ) ? XTREAMPRO_API_URL : get_option( 'xtreampro_api_url', '' );
		return untrailingslashit( esc_url_raw( trim( (string) $url ) ) );
	}

	/**
	 * Reseller API key. The constant wins over the option.
	 *
	 * @return string
	 */
	public static function api_key() {
		$key = ( defined( 'XTREAMPRO_API_KEY' ) && XTREAMPRO_API_KEY ) ? XTREAMPRO_API_KEY : get_option( 'xtreampro_api_key', '' );
		return trim( (string) $key );
	}

	/**
	 * Dashboard address shown to sub-resellers (no trailing slash), optional.
	 * The constant wins over the option.
	 *
	 * @return string
	 */
	public static function panel_url() {
		$url = ( defined( 'XTREAMPRO_PANEL_URL' ) && XTREAMPRO_PANEL_URL ) ? XTREAMPRO_PANEL_URL : get_option( 'xtreampro_panel_url', '' );
		return untrailingslashit( esc_url_raw( trim( (string) $url ), array( 'http', 'https' ) ) );
	}

	/**
	 * Sign-in link of the dashboard, empty when the panel address is not set.
	 *
	 * @return string
	 */
	public static function panel_login_url() {
		$base = self::panel_url();
		return '' === $base ? '' : $base . '/login';
	}

	/**
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== self::base_url() && '' !== self::api_key();
	}

	/**
	 * Readable text for an API error code.
	 *
	 * @param string $code API error code.
	 * @return string
	 */
	public static function error_message( $code ) {
		switch ( $code ) {
			case 'INVALID_API_KEY':
				return __( 'The API key was rejected. Check the key in the plugin settings.', 'xtreampro' );
			case 'FORBIDDEN':
				return __( 'The reseller account is not allowed to do this. To sell sub-reseller accounts, the reseller\'s group must allow creating sub-resellers.', 'xtreampro' );
			case 'RESOURCE_NOT_FOUND':
				return __( 'The line, account or package was not found on the panel.', 'xtreampro' );
			case 'INVALID_REQUEST':
				return __( 'The panel rejected the request as invalid.', 'xtreampro' );
			case 'INVALID_PACKAGE':
				return __( 'The package is not valid for this reseller account.', 'xtreampro' );
			case 'INSUFFICIENT_CREDITS':
				return __( 'The reseller account has not enough credits.', 'xtreampro' );
			case 'CONFLICT':
				return __( 'The username or email is already used on the panel, or the request id was reused.', 'xtreampro' );
			case 'REQUEST_ID_SPENT':
				return __( 'This sale was already made and its line has since been deleted on the panel, so the same request id cannot sell another line. A new order is a new sale and makes a new line.', 'xtreampro' );
			case 'READ_ONLY_KEY':
				return __( 'The API key is read-only. Use a key that may change things (panel → API key).', 'xtreampro' );
			case 'TOO_MANY_WEBHOOKS':
				return __( 'The reseller account already has the most webhook endpoints the panel allows. Remove one on the panel\'s API key page.', 'xtreampro' );
			case 'POST_REQUIRED':
				return __( 'The panel requires a POST request for this action.', 'xtreampro' );
			case 'RATE_LIMITED':
				return __( 'Too many requests. Please try again in a moment.', 'xtreampro' );
			case 'UNKNOWN_ACTION':
				return __( 'The panel does not know this API action. Is the panel up to date?', 'xtreampro' );
			case 'SERVER_ERROR':
				return __( 'The panel reported an internal error.', 'xtreampro' );
		}
		return __( 'Unexpected response from the panel.', 'xtreampro' );
	}

	/**
	 * Call the Reseller API.
	 *
	 * @param string $method GET or POST.
	 * @param string $action API action.
	 * @param array  $params Parameters.
	 * @return array|WP_Error Decoded "data" member, or WP_Error whose code is the API error code.
	 */
	public static function request( $method, $action, array $params = array() ) {
		$base = self::base_url();
		$key  = self::api_key();
		if ( '' === $base || '' === $key ) {
			return new WP_Error( 'NOT_CONFIGURED', __( 'Xtream UI Pro is not configured. Set the API URL and API key in Settings → Xtream UI Pro.', 'xtreampro' ) );
		}

		$url  = $base . '/reseller/v1';
		$args = array(
			'timeout'     => 20,
			'sslverify'   => true,
			'redirection' => 0,
			'headers'     => array(
				'X-API-Key'   => $key,
				'Accept'      => 'application/json',
				// Names this plugin in the panel's API call log (never parameters, never the key).
				'X-Connector' => 'wordpress/' . ( defined( 'XTREAMPRO_VERSION' ) ? XTREAMPRO_VERSION : '0' ),
			),
		);

		if ( 'POST' === $method ) {
			$args['body'] = array_merge( array( 'action' => $action ), $params );
			$response     = wp_remote_post( $url, $args );
		} else {
			$url      = add_query_arg( array_merge( array( 'action' => $action ), $params ), $url );
			$response = wp_remote_get( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'HTTP_ERROR', $response->get_error_message() );
		}

		$http = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $http >= 300 && $http < 400 ) {
			return new WP_Error( 'REDIRECT', __( 'The panel URL redirects somewhere else. Use the final address (usually https://) as API URL.', 'xtreampro' ) );
		}
		if ( ! is_array( $body ) || ! isset( $body['status'] ) ) {
			return new WP_Error( 'BAD_RESPONSE', __( 'The panel did not answer with a valid API response. Check the API URL.', 'xtreampro' ) );
		}
		if ( 'STATUS_SUCCESS' !== $body['status'] ) {
			$code = isset( $body['error'] ) ? (string) $body['error'] : 'SERVER_ERROR';
			return new WP_Error( $code, self::error_message( $code ), array( 'http' => $http ) );
		}

		return isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array();
	}

	/**
	 * @return array|WP_Error
	 */
	public static function user_info() {
		return self::request( 'GET', 'user_info' );
	}

	/**
	 * What the reseller can sell and afford: credits, packages[] with the
	 * reseller's price, sub_reseller (can_create, price, groups) and line rules.
	 * Never cached: the balance changes with every sale.
	 *
	 * @return array|WP_Error
	 */
	public static function pricing() {
		return self::request( 'GET', 'pricing' );
	}

	/**
	 * Fail before anything is sold when the packages are not on sale for this
	 * reseller or the balance cannot pay for all of it, so an order is never
	 * left half provisioned for want of credits. The answer is a look, not a
	 * reservation: the panel charges again, atomically, when it creates the
	 * line, so a sale racing another one can still be refused there.
	 *
	 * A need is {kind:'line', package_id:int, trial:bool, units:int, renewal?:bool} (units =
	 * how many official periods or trial lines to pay for; a renewal is one
	 * official period, and skips the check that the package sells as a plain
	 * line because a renewal sells another period of a line that exists) or {kind:'reseller', create:bool, credits:int} (create =
	 * the account does not exist yet, credits = what is handed over).
	 *
	 * @param array[] $needs Needs of one order.
	 * @return true|WP_Error INVALID_PACKAGE, FORBIDDEN or INSUFFICIENT_CREDITS; true also when the panel is too old to know `pricing`.
	 */
	public static function check_credits( array $needs ) {
		if ( ! $needs ) {
			return true;
		}
		$pricing = self::pricing();
		if ( is_wp_error( $pricing ) ) {
			return 'UNKNOWN_ACTION' === $pricing->get_error_code() ? true : $pricing;
		}
		$balance  = isset( $pricing['credits'] ) ? (int) $pricing['credits'] : 0;
		$packages = array();
		foreach ( isset( $pricing['packages'] ) && is_array( $pricing['packages'] ) ? $pricing['packages'] : array() as $row ) {
			if ( is_array( $row ) && isset( $row['id'] ) ) {
				$packages[ (int) $row['id'] ] = $row;
			}
		}
		$sub   = isset( $pricing['sub_reseller'] ) && is_array( $pricing['sub_reseller'] ) ? $pricing['sub_reseller'] : array();
		$total = 0;
		foreach ( $needs as $need ) {
			if ( 'reseller' === $need['kind'] ) {
				if ( ! empty( $need['create'] ) ) {
					if ( empty( $sub['can_create'] ) ) {
						return new WP_Error( 'FORBIDDEN', self::error_message( 'FORBIDDEN' ) );
					}
					$total += isset( $sub['price'] ) ? (int) $sub['price'] : 0;
				}
				$total += (int) $need['credits'];
				continue;
			}
			$id = (int) $need['package_id'];
			if ( ! isset( $packages[ $id ] ) ) {
				/* translators: %d: package id */
				return new WP_Error( 'INVALID_PACKAGE', sprintf( __( 'Package #%d is not in the list of packages this reseller may sell.', 'xtreampro' ), $id ) );
			}
			$pkg = $packages[ $id ];
			if ( empty( $need['renewal'] ) && ! self::sells_line( $pkg ) ) {
				return new WP_Error( 'INVALID_PACKAGE', __( 'The package is for MAG / Enigma boxes only and cannot be sold as an IPTV line.', 'xtreampro' ) );
			}
			if ( ! empty( $need['trial'] ) ) {
				if ( empty( $pkg['is_trial'] ) ) {
					return new WP_Error( 'INVALID_PACKAGE', __( 'The package is not offered as a trial.', 'xtreampro' ) );
				}
				$price = isset( $pkg['trial_credits'] ) ? (int) $pkg['trial_credits'] : 0;
			} else {
				if ( empty( $pkg['is_official'] ) ) {
					return new WP_Error( 'INVALID_PACKAGE', __( 'The package is not offered as an official period.', 'xtreampro' ) );
				}
				$price = isset( $pkg['official_credits'] ) ? (int) $pkg['official_credits'] : 0;
			}
			$total += $price * max( 1, (int) $need['units'] );
		}
		// A balance of 0 sells nothing, not even a free package: the panel refuses it.
		if ( $balance <= 0 || $balance < $total ) {
			return new WP_Error(
				'INSUFFICIENT_CREDITS',
				sprintf(
					/* translators: 1: credits the order needs, 2: credits on the reseller account */
					__( 'Not enough credits: this needs %1$d, the reseller account has %2$d.', 'xtreampro' ),
					$total,
					$balance
				),
				array( 'http' => 402 )
			);
		}
		return true;
	}

	/**
	 * Package list, cached for 10 minutes.
	 *
	 * @param bool $force Bypass the cache.
	 * @return array|WP_Error
	 */
	public static function packages( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::PACKAGES_TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$data = self::request( 'GET', 'packages' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$list = array();
		foreach ( $data as $pkg ) {
			if ( is_array( $pkg ) && isset( $pkg['id'] ) ) {
				$list[] = $pkg;
			}
		}
		set_transient( self::PACKAGES_TRANSIENT, $list, 10 * MINUTE_IN_SECONDS );
		return $list;
	}

	/**
	 * Whether a package of `packages` / `pricing` can be sold as a plain IPTV line. The panel lists what a
	 * package can be sold as in `sells` (a subset of line, mag, enigma); a panel too old to send it leaves
	 * the key out, and the package then counts as sellable (the sale itself is still checked by the panel).
	 *
	 * @param array $pkg Package row.
	 * @return bool
	 */
	public static function sells_line( array $pkg ) {
		return ! isset( $pkg['sells'] ) || ! is_array( $pkg['sells'] ) || in_array( 'line', $pkg['sells'], true );
	}

	/**
	 * The packages a shop can sell as a plain line (what every package picker offers): `packages()`
	 * without those the panel sells only to MAG / Enigma boxes.
	 *
	 * @return array|WP_Error
	 */
	public static function line_packages() {
		$list = self::packages();
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		return array_values( array_filter( $list, array( __CLASS__, 'sells_line' ) ) );
	}

	/**
	 * One package by id.
	 *
	 * @param int $id Package id.
	 * @return array|null
	 */
	public static function find_package( $id ) {
		$list = self::packages();
		if ( is_wp_error( $list ) ) {
			return null;
		}
		foreach ( $list as $pkg ) {
			if ( (int) $pkg['id'] === (int) $id ) {
				return $pkg;
			}
		}
		return null;
	}

	/**
	 * @param int $id Line id.
	 * @return array|WP_Error
	 */
	public static function get_line( $id ) {
		$data = self::request( 'GET', 'get_line', array( 'id' => (int) $id ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( isset( $data['line'] ) && is_array( $data['line'] ) ) {
			return $data['line'];
		}
		return $data;
	}

	/**
	 * @param int    $package_id Package id.
	 * @param bool   $trial      Create a trial line.
	 * @param string $request_id Idempotency key (max 64 chars).
	 * @return array|WP_Error
	 */
	public static function create_line( $package_id, $trial, $request_id ) {
		return self::request(
			'POST',
			'create_line',
			array(
				'package_id' => (int) $package_id,
				'trial'      => $trial ? 1 : 0,
				'request_id' => substr( (string) $request_id, 0, 64 ),
			)
		);
	}

	/**
	 * @param int    $line_id    Line id.
	 * @param string $request_id Idempotency key.
	 * @return array|WP_Error
	 */
	public static function renew_line( $line_id, $request_id ) {
		return self::request(
			'POST',
			'renew_line',
			array(
				'id'         => (int) $line_id,
				'request_id' => substr( (string) $request_id, 0, 64 ),
			)
		);
	}

	/**
	 * @param int    $line_id Line id.
	 * @param string $state   enable, disable or delete (deleting is final on the panel).
	 * @return array|WP_Error
	 */
	public static function set_line_state( $line_id, $state ) {
		if ( ! in_array( $state, array( 'enable', 'disable', 'delete' ), true ) ) {
			return new WP_Error( 'INVALID_REQUEST', self::error_message( 'INVALID_REQUEST' ) );
		}
		return self::request( 'POST', $state . '_line', array( 'id' => (int) $line_id ) );
	}

	/**
	 * Sub-reseller accounts below the API key's reseller.
	 *
	 * @param string $search Substring of username / email / full name.
	 * @param int    $start  Offset.
	 * @param int    $limit  Page size (max 500).
	 * @return array[]|WP_Error
	 */
	public static function sub_users( $search = '', $start = 0, $limit = 100 ) {
		$params = array(
			'start' => max( 0, (int) $start ),
			'limit' => max( 1, min( 500, (int) $limit ) ),
		);
		if ( '' !== (string) $search ) {
			$params['search'] = (string) $search;
		}
		$data = self::request( 'GET', 'get_users', $params );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$rows = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : $data;
		$list = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && isset( $row['id'] ) ) {
				$list[] = $row;
			}
		}
		return $list;
	}

	/**
	 * One sub-reseller account. The panel has no get-one action, so this
	 * searches by username and matches the id.
	 *
	 * @param string $id       Account UUID.
	 * @param string $username Username (the search term).
	 * @return array|WP_Error
	 */
	public static function find_sub_user( $id, $username ) {
		$list = self::sub_users( $username, 0, 500 );
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		foreach ( $list as $row ) {
			if ( 0 === strcasecmp( (string) $row['id'], (string) $id ) ) {
				return $row;
			}
		}
		return new WP_Error( 'RESOURCE_NOT_FOUND', self::error_message( 'RESOURCE_NOT_FOUND' ) );
	}

	/**
	 * Create a sub-reseller account. Username and password must both be sent
	 * when a request id is used, so a retry repeats the same account.
	 *
	 * @param string $username   Username.
	 * @param string $password   Password.
	 * @param string $email      Email.
	 * @param string $fullname   Full name.
	 * @param string $request_id Idempotency key (max 64 chars).
	 * @return array|WP_Error
	 */
	public static function create_sub_user( $username, $password, $email, $fullname, $request_id ) {
		return self::request(
			'POST',
			'create_user',
			array(
				'username'   => (string) $username,
				'password'   => (string) $password,
				'email'      => (string) $email,
				'fullname'   => (string) $fullname,
				'request_id' => substr( (string) $request_id, 0, 64 ),
			)
		);
	}

	/**
	 * Hand credits from the API key's reseller to an account (> 0) or take
	 * them back (< 0).
	 *
	 * @param string $id         Account UUID.
	 * @param int    $credits    Credits, not zero.
	 * @param string $note       Note (max 200 chars).
	 * @param string $request_id Idempotency key.
	 * @return array|WP_Error
	 */
	public static function adjust_credits( $id, $credits, $note, $request_id ) {
		return self::request(
			'POST',
			'adjust_credits',
			array(
				'id'         => (string) $id,
				'credits'    => (int) $credits,
				'note'       => substr( (string) $note, 0, 200 ),
				'request_id' => substr( (string) $request_id, 0, 64 ),
			)
		);
	}

	/**
	 * @param string $id    Account UUID.
	 * @param string $state enable, disable or delete. Deleting is final: the account's credits, lines and sub-accounts go to the reseller, its personal data is erased.
	 * @return array|WP_Error
	 */
	public static function set_sub_user_state( $id, $state ) {
		if ( ! in_array( $state, array( 'enable', 'disable', 'delete' ), true ) ) {
			return new WP_Error( 'INVALID_REQUEST', self::error_message( 'INVALID_REQUEST' ) );
		}
		return self::request( 'POST', $state . '_user', array( 'id' => (string) $id ) );
	}

	/**
	 * Registers an https address of this site as a webhook endpoint of the
	 * reseller account. The answer holds the id and, this once, the secret
	 * that signs every delivery (`secret`).
	 *
	 * @param string $url    Address the panel posts to.
	 * @param string $events Comma-separated event types.
	 * @return array|WP_Error
	 */
	public static function create_webhook( $url, $events ) {
		return self::request(
			'POST',
			'create_webhook',
			array(
				'url'    => (string) $url,
				'events' => (string) $events,
			)
		);
	}

	/**
	 * @param string $id Endpoint id.
	 * @return array|WP_Error
	 */
	public static function delete_webhook( $id ) {
		return self::request( 'POST', 'delete_webhook', array( 'id' => (string) $id ) );
	}

	/**
	 * Makes the panel post a ping event to the endpoint.
	 *
	 * @param string $id Endpoint id.
	 * @return array|WP_Error
	 */
	public static function test_webhook( $id ) {
		return self::request( 'POST', 'test_webhook', array( 'id' => (string) $id ) );
	}

	/**
	 * Play links the panel returned with a line (`links` of get_line, create_line
	 * and renew_line): server, m3u, m3u_hls, xmltv, player_api, web_player.
	 *
	 * @param mixed $data Answer of the panel.
	 * @return array<string,string>|null Null when it carried none (an older panel, or a line whose password is stored hashed).
	 */
	public static function links( $data ) {
		if ( ! is_array( $data ) || empty( $data['links'] ) || ! is_array( $data['links'] ) ) {
			return null;
		}
		$out = array();
		foreach ( array( 'server', 'm3u', 'm3u_hls', 'xmltv', 'player_api', 'web_player' ) as $key ) {
			if ( isset( $data['links'][ $key ] ) && is_string( $data['links'][ $key ] ) && '' !== $data['links'][ $key ] ) {
				$out[ $key ] = esc_url_raw( $data['links'][ $key ] );
			}
		}
		return $out ? $out : null;
	}

	/**
	 * What to show a customer about how to play a line: the links the panel
	 * returned, or, for a panel that returned none, the M3U and web player links
	 * assembled here from the credentials.
	 *
	 * @param array<string,string>|null $links        Result of links().
	 * @param string                    $username     Line username.
	 * @param string                    $password     Line password.
	 * @param bool                      $with_secrets False hides the links that carry the password (m3u, m3u_hls, xmltv).
	 * @return array[] Rows of {key, label, url}.
	 */
	public static function link_rows( $links, $username, $password, $with_secrets = true ) {
		if ( null === $links ) {
			$links = array(
				'server'     => self::base_url(),
				'web_player' => self::player_url(),
			);
			if ( '' !== (string) $password ) {
				$links['m3u'] = self::playlist_url( $username, $password );
			}
		}
		$labels = array(
			'server'     => __( 'Server URL', 'xtreampro' ),
			'm3u'        => __( 'Playlist URL', 'xtreampro' ),
			'm3u_hls'    => __( 'Playlist URL (HLS)', 'xtreampro' ),
			'xmltv'      => __( 'Programme guide (XMLTV)', 'xtreampro' ),
			'web_player' => __( 'Web player', 'xtreampro' ),
		);
		$secret = array( 'm3u', 'm3u_hls', 'xmltv' );
		$rows   = array();
		foreach ( $labels as $key => $label ) {
			if ( isset( $links[ $key ] ) && ( $with_secrets || ! in_array( $key, $secret, true ) ) ) {
				$rows[] = array(
					'key'   => $key,
					'label' => $label,
					'url'   => $links[ $key ],
				);
			}
		}
		return $rows;
	}

	/**
	 * Final credentials from a create_line / renew_line result. The panel may
	 * replace custom credentials, so the line object is authoritative.
	 *
	 * @param array $data Result of create_line / renew_line.
	 * @return array{id:int,username:string,password:string,links:array<string,string>|null}
	 */
	public static function credentials( array $data ) {
		$line     = isset( $data['line'] ) && is_array( $data['line'] ) ? $data['line'] : array();
		$password = '';
		if ( isset( $line['password'] ) && '' !== (string) $line['password'] ) {
			$password = (string) $line['password'];
		} elseif ( isset( $data['password'] ) ) {
			$password = (string) $data['password'];
		}
		return array(
			'id'       => isset( $line['id'] ) ? (int) $line['id'] : 0,
			'username' => isset( $line['username'] ) ? (string) $line['username'] : '',
			'password' => $password,
			'links'    => self::links( $data ),
		);
	}

	/**
	 * M3U playlist URL of a line, assembled here. Used only when the panel
	 * returned no `links` (see link_rows()).
	 *
	 * @param string $username Username.
	 * @param string $password Password.
	 * @return string
	 */
	public static function playlist_url( $username, $password ) {
		return self::base_url() . '/get.php?' . http_build_query(
			array(
				'username' => $username,
				'password' => $password,
				'type'     => 'm3u_plus',
				'output'   => 'ts',
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	/**
	 * @return string
	 */
	public static function player_url() {
		return self::base_url() . '/player/';
	}

	/**
	 * "3 months" style text.
	 *
	 * @param mixed  $count Number of units.
	 * @param string $unit  Unit name as sent by the panel.
	 * @return string
	 */
	public static function format_duration( $count, $unit ) {
		$n    = (int) $count;
		$unit = strtolower( trim( (string) $unit ) );
		$base = rtrim( $unit, 's' );
		switch ( $base ) {
			case 'hour':
				return sprintf( _n( '%d hour', '%d hours', $n, 'xtreampro' ), $n );
			case 'day':
				return sprintf( _n( '%d day', '%d days', $n, 'xtreampro' ), $n );
			case 'month':
				return sprintf( _n( '%d month', '%d months', $n, 'xtreampro' ), $n );
			case 'year':
				return sprintf( _n( '%d year', '%d years', $n, 'xtreampro' ), $n );
		}
		return trim( $n . ' ' . sanitize_text_field( $unit ) );
	}

	/**
	 * Lines a WordPress user bought through this site.
	 *
	 * @param int $user_id User id.
	 * @return array[] Entries of {line_id, product_id, order_id}.
	 */
	public static function user_lines( $user_id ) {
		$lines = get_user_meta( (int) $user_id, self::USER_LINES_META, true );
		return is_array( $lines ) ? $lines : array();
	}

	/**
	 * Does the user own the line (optionally for one product)?
	 *
	 * @param int $user_id    User id.
	 * @param int $line_id    Line id.
	 * @param int $product_id Product id, 0 to ignore.
	 * @return bool
	 */
	public static function user_owns_line( $user_id, $line_id, $product_id = 0 ) {
		foreach ( self::user_lines( $user_id ) as $entry ) {
			if ( isset( $entry['line_id'] ) && (int) $entry['line_id'] === (int) $line_id ) {
				if ( ! $product_id || ( isset( $entry['product_id'] ) && (int) $entry['product_id'] === (int) $product_id ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Drop a line from the user's list (it was deleted).
	 *
	 * @param int $user_id User id.
	 * @param int $line_id Line id.
	 */
	public static function forget_line( $user_id, $line_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return;
		}
		$kept = array();
		foreach ( self::user_lines( $user_id ) as $entry ) {
			if ( ! isset( $entry['line_id'] ) || (int) $entry['line_id'] !== (int) $line_id ) {
				$kept[] = $entry;
			}
		}
		update_user_meta( $user_id, self::USER_LINES_META, $kept );
	}

	/**
	 * Append a purchased line to the user's meta (no duplicates).
	 *
	 * @param int $user_id    User id.
	 * @param int $line_id    Line id.
	 * @param int $product_id Product id.
	 * @param int $order_id   Order id.
	 */
	public static function remember_line( $user_id, $line_id, $product_id, $order_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || $line_id <= 0 ) {
			return;
		}
		$lines = self::user_lines( $user_id );
		foreach ( $lines as $entry ) {
			if ( isset( $entry['line_id'] ) && (int) $entry['line_id'] === (int) $line_id ) {
				return;
			}
		}
		$lines[] = array(
			'line_id'    => (int) $line_id,
			'product_id' => (int) $product_id,
			'order_id'   => (int) $order_id,
		);
		update_user_meta( $user_id, self::USER_LINES_META, $lines );
	}

	/**
	 * The reseller account of a WordPress user (one per user).
	 *
	 * @param int $user_id User id.
	 * @return array{user_id:string,username:string,order_id:int}|null
	 */
	public static function user_reseller( $user_id ) {
		$meta = get_user_meta( (int) $user_id, self::USER_RESELLER_META, true );
		if ( ! is_array( $meta ) || empty( $meta['user_id'] ) ) {
			return null;
		}
		return array(
			'user_id'  => (string) $meta['user_id'],
			'username' => isset( $meta['username'] ) ? (string) $meta['username'] : '',
			'order_id' => isset( $meta['order_id'] ) ? (int) $meta['order_id'] : 0,
		);
	}

	/**
	 * Remember the reseller account created for a user (keeps the first one).
	 *
	 * @param int    $user_id  WordPress user id.
	 * @param string $sub_id   Panel account UUID.
	 * @param string $username Panel username.
	 * @param int    $order_id Order id.
	 */
	public static function remember_reseller( $user_id, $sub_id, $username, $order_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || '' === (string) $sub_id || self::user_reseller( $user_id ) ) {
			return;
		}
		update_user_meta(
			$user_id,
			self::USER_RESELLER_META,
			array(
				'user_id'  => (string) $sub_id,
				'username' => (string) $username,
				'order_id' => (int) $order_id,
			)
		);
	}
}
