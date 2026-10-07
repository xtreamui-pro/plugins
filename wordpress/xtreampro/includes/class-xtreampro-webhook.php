<?php
/**
 * Receiver of the webhooks the panel pushes: POST /wp-json/xtreampro/v1/webhook.
 *
 * The panel posts JSON {id, type, created, data} with the headers
 *   X-Xtream-Timestamp: <unix seconds>
 *   X-Xtream-Signature: sha256=<hex HMAC-SHA256(secret, timestamp + "." + body)>
 * Nothing in the body is looked at before the signature has been verified.
 *
 * @package XtreamPro
 */

defined( 'ABSPATH' ) || exit;

class XtreamPro_Webhook {

	const NAMESPACE_ROUTE = 'xtreampro/v1';
	const ROUTE           = '/webhook';
	const OPTION_SECRET   = 'xtreampro_webhook_secret';
	const OPTION_ID       = 'xtreampro_webhook_id';

	/** Seconds a delivery may differ from this server's clock. The panel stamps every attempt anew. */
	const TOLERANCE = 300;

	/** Bytes of body accepted; the panel's events are a few hundred. */
	const MAX_BODY = 65536;

	/** How long an applied event id is remembered: a retry comes within minutes, a replay within the 5-minute window. */
	const SEEN_TTL = DAY_IN_SECONDS;

	/** Prefix of the transient that remembers an applied event id (WordPress deletes expired transients itself). */
	const SEEN_PREFIX = 'xtreampro_ev_';

	/** Events this site asks the panel for. */
	const EVENTS = 'line.renewed,line.enabled,line.disabled,line.deleted,line.expired';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
	}

	public static function register_route() {
		register_rest_route(
			self::NAMESPACE_ROUTE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				// Public on purpose: the panel is not a WordPress user. What lets a call
				// in is the HMAC signature, checked in handle() before anything else happens.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Address the panel posts to.
	 *
	 * @return string
	 */
	public static function url() {
		return rest_url( self::NAMESPACE_ROUTE . self::ROUTE );
	}

	/**
	 * Signing secret. The constant wins over the option.
	 *
	 * @return string
	 */
	public static function secret() {
		$secret = ( defined( 'XTREAMPRO_WEBHOOK_SECRET' ) && XTREAMPRO_WEBHOOK_SECRET ) ? XTREAMPRO_WEBHOOK_SECRET : get_option( self::OPTION_SECRET, '' );
		return trim( (string) $secret );
	}

	/**
	 * Check a delivery.
	 *
	 * @param string   $body      Raw request body.
	 * @param string   $timestamp X-Xtream-Timestamp header.
	 * @param string   $signature X-Xtream-Signature header.
	 * @param string   $secret    Signing secret.
	 * @param int|null $now       Clock override for tests.
	 * @return array{status:int,message:string,event:array|null} The event only when status is 200.
	 */
	public static function verify( $body, $timestamp, $signature, $secret, $now = null ) {
		$now       = null === $now ? time() : (int) $now;
		$timestamp = (string) $timestamp;
		$signature = (string) $signature;
		if ( '' === $secret ) {
			return self::refuse( 503, 'no webhook secret is set' );
		}
		if ( strlen( $body ) > self::MAX_BODY ) {
			return self::refuse( 413, 'body too large' );
		}
		if ( ! ctype_digit( $timestamp ) || strlen( $timestamp ) > 12 ) {
			return self::refuse( 400, 'missing or invalid timestamp' );
		}
		if ( abs( $now - (int) $timestamp ) > self::TOLERANCE ) {
			return self::refuse( 400, 'timestamp outside the accepted window' );
		}
		if ( 0 !== strpos( $signature, 'sha256=' ) ) {
			return self::refuse( 401, 'missing signature' );
		}
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
		// hash_equals compares in constant time.
		if ( ! hash_equals( $expected, substr( $signature, 7 ) ) ) {
			return self::refuse( 401, 'invalid signature' );
		}
		$event = json_decode( $body, true );
		if ( ! is_array( $event ) || empty( $event['type'] ) || ! is_string( $event['type'] ) ) {
			return self::refuse( 400, 'unreadable event' );
		}
		return array(
			'status'  => 200,
			'message' => 'ok',
			'event'   => $event,
		);
	}

	/**
	 * @param int    $status  HTTP status.
	 * @param string $message Reason (never shown to a browser user; the panel logs it).
	 * @return array
	 */
	private static function refuse( $status, $message ) {
		return array(
			'status'  => $status,
			'message' => $message,
			'event'   => null,
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle( $request ) {
		$result = self::verify(
			(string) $request->get_body(),
			(string) $request->get_header( 'x_xtream_timestamp' ),
			(string) $request->get_header( 'x_xtream_signature' ),
			self::secret()
		);
		if ( 200 === $result['status'] ) {
			// The same event id means the same event again (a retry after a lost answer): acknowledge, do nothing.
			// The id is stored only after the event was applied.
			$event_id = self::event_id( $result['event'] );
			$seen_key = self::SEEN_PREFIX . md5( $event_id );
			if ( '' !== $event_id && get_transient( $seen_key ) ) {
				$result['message'] = 'duplicate';
			} else {
				$result['message'] = self::apply( $result['event'] );
				if ( '' !== $event_id ) {
					set_transient( $seen_key, 1, self::SEEN_TTL );
				}
			}
		}
		return new WP_REST_Response(
			array(
				'ok'      => 200 === $result['status'],
				'message' => $result['message'],
			),
			$result['status']
		);
	}

	/**
	 * The event's own id ("evt_..."): the panel keeps it the same on every retry, so a receiver
	 * de-duplicates on it. '' when the event carries none (an older panel) or one of an unexpected shape.
	 *
	 * @param array $event Decoded event.
	 * @return string
	 */
	public static function event_id( array $event ) {
		return isset( $event['id'] ) && is_string( $event['id'] ) && preg_match( '/^[A-Za-z0-9_.:-]{1,64}$/', $event['id'] ) ? $event['id'] : '';
	}

	/**
	 * Users whose list holds the line (entries are serialised arrays of {line_id, product_id, order_id}).
	 *
	 * @param int $line_id Line id.
	 * @return int[]
	 */
	private static function owners( $line_id ) {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( 's:7:"line_id";i:' . (int) $line_id . ';' ) . '%';
		$ids  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s",
				XtreamPro_API::USER_LINES_META,
				$like
			)
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * Order note, never to the customer.
	 *
	 * @param int    $order_id Order id (0 = none).
	 * @param string $text     Text.
	 */
	private static function note( $order_id, $text ) {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) {
			return;
		}
		if ( function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->add_order_note( $text );
				return;
			}
		}
		if ( function_exists( 'edd_add_note' ) && function_exists( 'edd_get_order' ) && edd_get_order( $order_id ) ) {
			edd_add_note(
				array(
					'object_id'   => $order_id,
					'object_type' => 'order',
					'content'     => $text,
				)
			);
		}
	}

	/**
	 * Apply one verified event. Idempotent: the panel retries, and a replay
	 * inside the signature's time window repeats it.
	 *
	 * - line.deleted: the line leaves the customer's list (so it cannot be renewed) and the order gets a note.
	 * - line.expired / renewed / enabled / disabled: the status kept with the customer's line follows, and expiry gets an order note.
	 * Events of lines this site did not sell are acknowledged and ignored. That includes the lines of a
	 * sub-reseller (`owner_id` / `owner_username` in the data say whose they are): this plugin does not
	 * register its webhook with the sub-resellers' lines and has no screen to show them on, so it never
	 * uses those two fields.
	 *
	 * @param array $event Decoded event.
	 * @return string What was done.
	 */
	public static function apply( array $event ) {
		$type = (string) $event['type'];
		$data = isset( $event['data'] ) && is_array( $event['data'] ) ? $event['data'] : array();
		if ( 'ping' === $type ) {
			return 'pong';
		}
		if ( 0 !== strpos( $type, 'line.' ) || empty( $data['line_id'] ) ) {
			return 'ignored';
		}
		$statuses = array(
			'line.expired'  => 'expired',
			'line.renewed'  => 'active',
			'line.enabled'  => 'active',
			'line.disabled' => 'disabled',
		);
		if ( 'line.deleted' !== $type && ! isset( $statuses[ $type ] ) ) {
			return 'ignored';
		}
		$line_id = (int) $data['line_id'];
		$owners  = self::owners( $line_id );
		if ( ! $owners ) {
			return 'unknown line';
		}
		foreach ( $owners as $user_id ) {
			$lines = XtreamPro_API::user_lines( $user_id );
			foreach ( $lines as $i => $entry ) {
				if ( ! isset( $entry['line_id'] ) || (int) $entry['line_id'] !== $line_id ) {
					continue;
				}
				$order_id = isset( $entry['order_id'] ) ? (int) $entry['order_id'] : 0;
				if ( 'line.deleted' === $type ) {
					XtreamPro_API::forget_line( $user_id, $line_id );
					/* translators: %d: line id */
					self::note( $order_id, sprintf( __( 'Xtream UI Pro: line #%d was deleted on the panel.', 'xtreampro' ), $line_id ) );
					continue 2;
				}
				$lines[ $i ]['status']    = $statuses[ $type ];
				$lines[ $i ]['status_at'] = time();
				if ( array_key_exists( 'exp_date', $data ) ) {
					$lines[ $i ]['exp_date'] = null === $data['exp_date'] ? 0 : (int) $data['exp_date'];
				}
				if ( 'line.expired' === $type ) {
					/* translators: %d: line id */
					self::note( $order_id, sprintf( __( 'Xtream UI Pro: line #%d expired on the panel.', 'xtreampro' ), $line_id ) );
				}
			}
			if ( 'line.deleted' !== $type ) {
				update_user_meta( $user_id, XtreamPro_API::USER_LINES_META, $lines );
			}
		}
		return 'line.deleted' === $type ? 'forgotten' : 'recorded';
	}
}
