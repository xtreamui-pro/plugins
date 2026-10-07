<?php
/**
 * Easy Digital Downloads integration (EDD 3.x): sell packages, provision / disable lines.
 *
 * A download is configured with the same meta keys as a WooCommerce product
 * (_xtreampro_kind, _xtreampro_package_id, _xtreampro_trial, _xtreampro_credits).
 * What an order provisioned is kept in the order meta _xtreampro_edd.
 *
 * @package XtreamPro
 */

defined( 'ABSPATH' ) || exit;

class XtreamPro_EDD {

	const ORDER_META = '_xtreampro_edd';
	const EMAIL_TAG  = 'xtreampro_credentials';

	public static function init() {
		// Download settings.
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_metabox' ) );
		add_action( 'save_post_download', array( __CLASS__, 'save_download' ), 10, 1 );

		// Provisioning. edd_complete_purchase runs once per order, when it first
		// becomes complete, before EDD queues the receipt email (priority 1: the
		// email already carries the sign-in details).
		add_action( 'edd_complete_purchase', array( __CLASS__, 'on_complete' ), 1, 1 );
		add_action( 'edd_transition_order_status', array( __CLASS__, 'on_transition' ), 10, 3 );

		// Admin retry button on the order screen.
		add_action( 'edd_view_order_details_sidebar_after', array( __CLASS__, 'retry_box' ), 10, 1 );
		add_action( 'admin_post_xtreampro_edd_provision', array( __CLASS__, 'handle_retry' ) );

		// Show credentials.
		add_action( 'edd_order_receipt_after_table', array( __CLASS__, 'receipt_credentials' ), 10, 1 );
		add_action( 'edd_add_email_tags', array( __CLASS__, 'register_email_tag' ) );
		add_filter( 'edd_order_receipt', array( __CLASS__, 'receipt_email_body' ), 10, 2 );
	}

	/* ------------------------------------------------------------------ */
	/* Download settings                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Package configuration of a download.
	 *
	 * @param int $download_id Download (post) id.
	 * @return array{kind:string,package_id:int,trial:bool,credits:int,on_cancel:string}|null
	 */
	private static function download_config( $download_id ) {
		$download_id = (int) $download_id;
		if ( $download_id <= 0 ) {
			return null;
		}
		// What a refund or revoked order does to what it created: 'disable' (default) or 'delete' (final).
		$on_cancel = 'delete' === get_post_meta( $download_id, '_xtreampro_on_cancel', true ) ? 'delete' : 'disable';
		if ( 'reseller' === get_post_meta( $download_id, '_xtreampro_kind', true ) ) {
			return array(
				'kind'       => 'reseller',
				'package_id' => 0,
				'trial'      => false,
				'credits'    => max( 0, (int) get_post_meta( $download_id, '_xtreampro_credits', true ) ),
				'on_cancel'  => $on_cancel,
			);
		}
		$package_id = (int) get_post_meta( $download_id, '_xtreampro_package_id', true );
		if ( $package_id <= 0 ) {
			return null;
		}
		return array(
			'kind'       => 'line',
			'package_id' => $package_id,
			'trial'      => 'yes' === get_post_meta( $download_id, '_xtreampro_trial', true ),
			'credits'    => 0,
			'on_cancel'  => $on_cancel,
		);
	}

	public static function add_metabox() {
		add_meta_box(
			'xtreampro_edd',
			__( 'Xtream UI Pro', 'xtreampro' ),
			array( __CLASS__, 'render_metabox' ),
			'download',
			'normal',
			'default'
		);
	}

	/**
	 * @param WP_Post $post Download being edited.
	 */
	public static function render_metabox( $post ) {
		$id      = (int) $post->ID;
		$kind    = (string) get_post_meta( $id, '_xtreampro_kind', true );
		$current = (int) get_post_meta( $id, '_xtreampro_package_id', true );
		$trial   = 'yes' === get_post_meta( $id, '_xtreampro_trial', true );
		$credits = (int) get_post_meta( $id, '_xtreampro_credits', true );

		$options  = array( '' => __( '— None —', 'xtreampro' ) );
		$packages = XtreamPro_API::is_configured() ? XtreamPro_API::line_packages() : array();
		if ( is_array( $packages ) ) {
			foreach ( $packages as $pkg ) {
				$options[ (string) (int) $pkg['id'] ] = sprintf( '%s (#%d)', isset( $pkg['name'] ) ? $pkg['name'] : '', (int) $pkg['id'] );
			}
		}
		if ( $current > 0 && ! isset( $options[ (string) $current ] ) ) {
			$options[ (string) $current ] = sprintf( '#%d', $current );
		}

		wp_nonce_field( 'xtreampro_edd_save', 'xtreampro_edd_nonce' );
		echo '<p><label for="xtreampro_kind"><strong>' . esc_html__( 'Xtream UI Pro product type', 'xtreampro' ) . '</strong></label><br />';
		echo '<select name="_xtreampro_kind" id="xtreampro_kind">';
		echo '<option value="line"' . selected( 'reseller' === $kind, false, false ) . '>' . esc_html__( 'IPTV line', 'xtreampro' ) . '</option>';
		echo '<option value="reseller"' . selected( 'reseller' === $kind, true, false ) . '>' . esc_html__( 'Sub-reseller account / credits', 'xtreampro' ) . '</option>';
		echo '</select><br /><span class="description">' . esc_html__( 'IPTV line creates lines with the package below. Sub-reseller account creates a reseller account for the customer (once) and hands over the credits below.', 'xtreampro' ) . '</span></p>';

		echo '<p><label for="xtreampro_package_id"><strong>' . esc_html__( 'Xtream UI Pro package', 'xtreampro' ) . '</strong></label><br />';
		echo '<select name="_xtreampro_package_id" id="xtreampro_package_id">';
		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( (string) $value ) . '"' . selected( (string) $current, (string) $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><br /><span class="description">' . esc_html__( 'Buying this download creates a line with this package on the panel. Ignored for sub-reseller products.', 'xtreampro' ) . '</span></p>';

		echo '<p><label for="xtreampro_trial"><input type="checkbox" name="_xtreampro_trial" id="xtreampro_trial" value="yes"' . checked( $trial, true, false ) . ' /> <strong>' . esc_html__( 'Trial line', 'xtreampro' ) . '</strong></label><br />';
		echo '<span class="description">' . esc_html__( 'Create a trial line (uses the package trial settings and trial credits). Ignored for sub-reseller products.', 'xtreampro' ) . '</span></p>';

		echo '<p><label for="xtreampro_credits"><strong>' . esc_html__( 'Credits', 'xtreampro' ) . '</strong></label><br />';
		echo '<input type="number" min="0" step="1" name="_xtreampro_credits" id="xtreampro_credits" value="' . esc_attr( (string) $credits ) . '" /><br />';
		echo '<span class="description">' . esc_html__( 'Sub-reseller products only: credits handed to the customer\'s reseller account per purchased unit (taken from your reseller balance). 0 creates the account without credits. Ignored for IPTV lines.', 'xtreampro' ) . '</span></p>';

		$on_cancel = (string) get_post_meta( $id, '_xtreampro_on_cancel', true );
		echo '<p><label for="xtreampro_on_cancel"><strong>' . esc_html__( 'On refund or revoked order', 'xtreampro' ) . '</strong></label><br />';
		echo '<select name="_xtreampro_on_cancel" id="xtreampro_on_cancel">';
		echo '<option value="disable"' . selected( 'delete' === $on_cancel, false, false ) . '>' . esc_html__( 'Disable (default)', 'xtreampro' ) . '</option>';
		echo '<option value="delete"' . selected( 'delete' === $on_cancel, true, false ) . '>' . esc_html__( 'Delete permanently', 'xtreampro' ) . '</option>';
		echo '</select><br /><span class="description">' . esc_html__( 'What a refunded or revoked order does to the line (or sub-reseller account) it created. Disable can be undone on the panel. Delete is FINAL: the line and everything recorded about its customer are erased on the panel and cannot be restored.', 'xtreampro' ) . '</span></p>';

		if ( is_wp_error( $packages ) ) {
			echo '<p><em>' . esc_html( $packages->get_error_message() ) . '</em></p>';
		}
	}

	/**
	 * @param int $post_id Download id.
	 */
	public static function save_download( $post_id ) {
		$post_id = (int) $post_id;
		if ( ! isset( $_POST['xtreampro_edd_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['xtreampro_edd_nonce'] ) ), 'xtreampro_edd_save' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$package_id = isset( $_POST['_xtreampro_package_id'] ) ? absint( wp_unslash( $_POST['_xtreampro_package_id'] ) ) : 0;
		$trial      = isset( $_POST['_xtreampro_trial'] ) ? 'yes' : 'no';
		$kind       = isset( $_POST['_xtreampro_kind'] ) && 'reseller' === sanitize_key( wp_unslash( $_POST['_xtreampro_kind'] ) ) ? 'reseller' : 'line';
		$credits    = isset( $_POST['_xtreampro_credits'] ) ? absint( wp_unslash( $_POST['_xtreampro_credits'] ) ) : 0;
		$on_cancel  = isset( $_POST['_xtreampro_on_cancel'] ) && 'delete' === sanitize_key( wp_unslash( $_POST['_xtreampro_on_cancel'] ) ) ? 'delete' : 'disable';
		// phpcs:enable
		if ( $package_id > 0 ) {
			update_post_meta( $post_id, '_xtreampro_package_id', $package_id );
		} else {
			delete_post_meta( $post_id, '_xtreampro_package_id' );
		}
		update_post_meta( $post_id, '_xtreampro_trial', $trial );
		update_post_meta( $post_id, '_xtreampro_kind', $kind );
		update_post_meta( $post_id, '_xtreampro_credits', $credits );
		update_post_meta( $post_id, '_xtreampro_on_cancel', $on_cancel );
	}

	/* ------------------------------------------------------------------ */
	/* Order state                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * What the order provisioned: items[order item id] = {download, lines[], sub_*, credited, revoked, ...}.
	 *
	 * @param int $order_id Order id.
	 * @return array
	 */
	private static function get_state( $order_id ) {
		$state = edd_get_order_meta( $order_id, self::ORDER_META, true );
		if ( ! is_array( $state ) || ! isset( $state['items'] ) || ! is_array( $state['items'] ) ) {
			$state = array( 'items' => array() );
		}
		return $state;
	}

	private static function save_state( $order_id, array $state ) {
		edd_update_order_meta( $order_id, self::ORDER_META, $state );
	}

	/**
	 * Order note (shown on the order screen, never to the customer).
	 *
	 * @param object $order Order.
	 * @param string $text  Text, without secrets.
	 */
	private static function note( $order, $text ) {
		edd_add_note(
			array(
				'object_id'   => (int) $order->id,
				'object_type' => 'order',
				'content'     => $text,
			)
		);
	}

	/**
	 * Is this a sale order of ours (not a refund record)?
	 *
	 * @param mixed $order Order.
	 * @return bool
	 */
	private static function is_sale( $order ) {
		return is_object( $order ) && isset( $order->id ) && ( ! isset( $order->type ) || 'sale' === $order->type );
	}

	/* ------------------------------------------------------------------ */
	/* Provisioning                                                        */
	/* ------------------------------------------------------------------ */

	public static function on_complete( $order_id ) {
		self::provision( edd_get_order( (int) $order_id ) );
	}

	/**
	 * What provisioning the order would still buy, for XtreamPro_API::check_credits().
	 *
	 * @param object $order   Order.
	 * @param array  $work    Download config by item id.
	 * @param array  $state   Order state.
	 * @param int    $user_id WordPress user id (0 for guests).
	 * @return array[]
	 */
	private static function needs( $order, array $work, array $state, $user_id ) {
		$needs = array();
		foreach ( $order->get_items() as $item ) {
			$item_id = (int) $item->id;
			if ( ! isset( $work[ $item_id ] ) ) {
				continue;
			}
			$cfg = $work[ $item_id ];
			$rec = isset( $state['items'][ $item_id ] ) ? $state['items'][ $item_id ] : array();
			if ( 'reseller' === $cfg['kind'] ) {
				if ( $user_id <= 0 ) {
					continue; // not provisioned at all (see provision_reseller)
				}
				$exists  = ! empty( $rec['sub_id'] ) || XtreamPro_API::user_reseller( $user_id );
				$needs[] = array(
					'kind'    => 'reseller',
					'create'  => ! $exists,
					'credits' => ! empty( $rec['credited'] ) ? 0 : (int) $cfg['credits'] * max( 1, (int) $item->quantity ),
				);
				continue;
			}
			$left = max( 1, (int) $item->quantity ) - ( isset( $rec['lines'] ) && is_array( $rec['lines'] ) ? count( $rec['lines'] ) : 0 );
			if ( $left > 0 ) {
				$needs[] = array(
					'kind'       => 'line',
					'package_id' => $cfg['package_id'],
					'trial'      => $cfg['trial'],
					'units'      => $left,
				);
			}
		}
		return $needs;
	}

	/**
	 * Create the lines / reseller accounts of every package item of the order.
	 * Idempotent: what was provisioned is remembered, so calling it again only
	 * does what is still missing.
	 *
	 * @param object|false $order EDD order.
	 */
	public static function provision( $order ) {
		if ( ! self::is_sale( $order ) || 'complete' !== $order->status ) {
			return;
		}
		$work = array();
		foreach ( $order->get_items() as $item ) {
			$cfg = self::download_config( $item->product_id );
			if ( $cfg ) {
				$work[ (int) $item->id ] = $cfg;
			}
		}
		if ( ! $work ) {
			return;
		}
		if ( ! XtreamPro_API::is_configured() ) {
			self::note( $order, __( 'Xtream UI Pro: not configured, lines were not provisioned.', 'xtreampro' ) );
			return;
		}

		$lock = 'xtreampro_edd_lock_' . (int) $order->id;
		if ( get_transient( $lock ) ) {
			return;
		}
		set_transient( $lock, 1, 2 * MINUTE_IN_SECONDS );

		try {
			$state   = self::get_state( $order->id );
			$user_id = (int) $order->user_id;

			// Ask what the order still has to buy before buying any of it, so a short
			// balance (or a package that is not on sale) leaves the order untouched.
			$short = XtreamPro_API::check_credits( self::needs( $order, $work, $state, $user_id ) );
			if ( is_wp_error( $short ) ) {
				self::note(
					$order,
					sprintf(
						/* translators: %s: reason */
						__( 'Xtream UI Pro: nothing was provisioned. %s Fix it, then use "Provision Xtream UI Pro lines again".', 'xtreampro' ),
						$short->get_error_message()
					)
				);
				return;
			}
			foreach ( $order->get_items() as $item ) {
				$item_id = (int) $item->id;
				if ( ! isset( $work[ $item_id ] ) ) {
					continue;
				}
				if ( 'reseller' === $work[ $item_id ]['kind'] ) {
					self::provision_reseller( $order, $item, $work[ $item_id ], $user_id, $state );
				} else {
					self::provision_lines( $order, $item, $work[ $item_id ], $user_id, $state );
				}
			}
		} finally {
			delete_transient( $lock );
		}
	}

	/**
	 * @param object $order   Order.
	 * @param object $item    Order item.
	 * @param array  $cfg     Download config.
	 * @param int    $user_id WordPress user id (0 for guests).
	 * @param array  $state   Order state (by reference).
	 */
	private static function provision_lines( $order, $item, array $cfg, $user_id, array &$state ) {
		$item_id     = (int) $item->id;
		$download_id = (int) $item->product_id;
		$rec         = isset( $state['items'][ $item_id ] ) ? $state['items'][ $item_id ] : array();
		$rec         = array_merge(
			array(
				'download' => $download_id,
				'lines'    => array(),
			),
			$rec
		);
		$units = max( 1, (int) $item->quantity );

		// Lines of the same download in other items of this order come first, so
		// every line of the order has its own request id.
		$others = 0;
		foreach ( $state['items'] as $other_id => $other ) {
			if ( (int) $other_id !== $item_id && isset( $other['download'], $other['lines'] ) && (int) $other['download'] === $download_id ) {
				$others += count( $other['lines'] );
			}
		}

		for ( $n = count( $rec['lines'] ) + 1; $n <= $units; $n++ ) {
			$data = XtreamPro_API::create_line( $cfg['package_id'], $cfg['trial'], 'edd-' . (int) $order->id . '-' . $download_id . '-' . ( $others + $n ) );
			if ( is_wp_error( $data ) ) {
				self::note(
					$order,
					sprintf(
						/* translators: 1: item name, 2: error message */
						__( 'Xtream UI Pro: could not provision "%1$s": %2$s', 'xtreampro' ),
						$item->product_name,
						$data->get_error_message()
					)
				);
				return;
			}
			$cred = XtreamPro_API::credentials( $data );
			if ( $cred['id'] <= 0 ) {
				self::note( $order, sprintf( /* translators: %s: item name */ __( 'Xtream UI Pro: the panel returned no line for "%s".', 'xtreampro' ), $item->product_name ) );
				return;
			}

			$rec['lines'][]             = array(
				'id'       => $cred['id'],
				'username' => $cred['username'],
				'password' => $cred['password'],
				// What the panel returned with the line; absent on an order made by version 1.0.0.
				'links'    => $cred['links'],
			);
			$state['items'][ $item_id ] = $rec;
			self::save_state( $order->id, $state );
			XtreamPro_API::remember_line( $user_id, $cred['id'], $download_id, $order->id );

			self::note(
				$order,
				sprintf(
					/* translators: 1: line id, 2: username, 3: item name */
					__( 'Xtream UI Pro: line created #%1$d (%2$s) for "%3$s".', 'xtreampro' ),
					$cred['id'],
					$cred['username'],
					$item->product_name
				)
			);
		}
	}

	/**
	 * Random lowercase letters / digits.
	 *
	 * @param int $length Length.
	 * @return string
	 */
	private static function random_chars( $length ) {
		$alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
		$out      = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$out .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
		}
		return $out;
	}

	/**
	 * Panel username (3-32 chars of letters, digits and _ . -) from the WP login.
	 *
	 * @param int $user_id WordPress user id.
	 * @return string
	 */
	private static function generate_sub_username( $user_id ) {
		$user = get_userdata( $user_id );
		$base = $user ? preg_replace( '/[^A-Za-z0-9_.-]/', '', (string) $user->user_login ) : '';
		if ( '' === $base ) {
			$base = 'r' . (int) $user_id;
		}
		// 4 random chars are appended, so the base keeps at most 27 of the 32 allowed.
		return substr( substr( $base, 0, 27 ) . self::random_chars( 4 ), 0, 32 );
	}

	/**
	 * Create the customer's reseller account (first reseller item) and hand
	 * over the credits of the item.
	 *
	 * @param object $order   Order.
	 * @param object $item    Order item.
	 * @param array  $cfg     Download config.
	 * @param int    $user_id WordPress user id (0 for guests).
	 * @param array  $state   Order state (by reference).
	 */
	private static function provision_reseller( $order, $item, array $cfg, $user_id, array &$state ) {
		if ( $user_id <= 0 ) {
			/* translators: %s: item name */
			self::note( $order, sprintf( __( 'Xtream UI Pro: "%s" needs a WordPress account on the order to hold the reseller account. It was not provisioned; ask the customer to order while logged in.', 'xtreampro' ), $item->product_name ) );
			return;
		}

		$item_id     = (int) $item->id;
		$download_id = (int) $item->product_id;
		$rec         = isset( $state['items'][ $item_id ] ) ? $state['items'][ $item_id ] : array();
		$rec         = array_merge( array( 'download' => $download_id ), $rec );

		$sub_id  = isset( $rec['sub_id'] ) ? (string) $rec['sub_id'] : '';
		$account = XtreamPro_API::user_reseller( $user_id );
		if ( '' === $sub_id && $account ) {
			// The customer already has an account: this order only tops it up.
			$sub_id         = $account['user_id'];
			$rec['sub_id']  = $sub_id;
			$rec['created'] = false;
		}

		if ( '' === $sub_id ) {
			// Credentials are stored before the call so a retry sends the same ones.
			$username = isset( $rec['pending_user'] ) ? (string) $rec['pending_user'] : '';
			$password = isset( $rec['pending_pass'] ) ? (string) $rec['pending_pass'] : '';
			if ( '' === $username || '' === $password ) {
				$username            = self::generate_sub_username( $user_id );
				$password            = wp_generate_password( 14, false );
				$rec['pending_user'] = $username;
				$rec['pending_pass'] = $password;
				$state['items'][ $item_id ] = $rec;
				self::save_state( $order->id, $state );
			}
			$customer = edd_get_customer( (int) $order->customer_id );
			$fullname = $customer && ! empty( $customer->name ) ? trim( (string) $customer->name ) : '';
			if ( '' === $fullname ) {
				$fullname = $username;
			}
			$data = XtreamPro_API::create_sub_user( $username, $password, (string) $order->email, mb_substr( $fullname, 0, 128 ), 'edd-sub-' . (int) $order->id . '-' . $download_id );
			if ( is_wp_error( $data ) ) {
				self::note(
					$order,
					sprintf(
						/* translators: 1: item name, 2: error message */
						__( 'Xtream UI Pro: could not create the reseller account for "%1$s": %2$s', 'xtreampro' ),
						$item->product_name,
						$data->get_error_message()
					)
				);
				return;
			}
			$created = isset( $data['user'] ) && is_array( $data['user'] ) ? $data['user'] : array();
			$sub_id  = isset( $created['id'] ) ? (string) $created['id'] : '';
			if ( '' === $sub_id ) {
				self::note( $order, sprintf( /* translators: %s: item name */ __( 'Xtream UI Pro: the panel returned no account for "%s".', 'xtreampro' ), $item->product_name ) );
				return;
			}
			if ( ! empty( $created['username'] ) ) {
				$username = (string) $created['username'];
			}
			if ( ! empty( $data['password'] ) ) {
				$password = (string) $data['password'];
			}
			$rec['sub_id']   = $sub_id;
			$rec['sub_user'] = $username;
			$rec['sub_pass'] = $password;
			$rec['created']  = true;
			unset( $rec['pending_user'], $rec['pending_pass'] );
			$state['items'][ $item_id ] = $rec;
			self::save_state( $order->id, $state );
			XtreamPro_API::remember_reseller( $user_id, $sub_id, $username, $order->id );
			delete_transient( 'xtreampro_rs_' . $user_id );

			self::note(
				$order,
				sprintf(
					/* translators: 1: username, 2: item name */
					__( 'Xtream UI Pro: reseller account %1$s created for "%2$s".', 'xtreampro' ),
					$username,
					$item->product_name
				)
			);
		}

		if ( ! empty( $rec['credited'] ) ) {
			return;
		}
		$credits = (int) $cfg['credits'] * max( 1, (int) $item->quantity );
		if ( $credits <= 0 ) {
			$state['items'][ $item_id ] = $rec;
			self::save_state( $order->id, $state );
			return;
		}
		$res = XtreamPro_API::adjust_credits( $sub_id, $credits, 'Order #' . $order->get_number(), 'edd-subc-' . (int) $order->id . '-' . $download_id );
		if ( is_wp_error( $res ) ) {
			self::note(
				$order,
				sprintf(
					/* translators: 1: credits, 2: item name, 3: error message */
					__( 'Xtream UI Pro: could not give %1$d credits for "%2$s": %3$s', 'xtreampro' ),
					$credits,
					$item->product_name,
					$res->get_error_message()
				)
			);
			return;
		}
		$rec['credited']            = $credits;
		$state['items'][ $item_id ] = $rec;
		self::save_state( $order->id, $state );
		delete_transient( 'xtreampro_rs_' . $user_id );
		self::note(
			$order,
			sprintf(
				/* translators: 1: credits, 2: item name */
				__( 'Xtream UI Pro: %1$d credits given to the reseller account for "%2$s".', 'xtreampro' ),
				$credits,
				$item->product_name
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Refund / revoke                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * @param string $old_status Previous order status.
	 * @param string $new_status New order status.
	 * @param int    $order_id   Order id.
	 */
	public static function on_transition( $old_status, $new_status, $order_id ) {
		if ( $old_status === $new_status || ! in_array( $new_status, array( 'refunded', 'revoked' ), true ) ) {
			return;
		}
		self::revoke( edd_get_order( (int) $order_id ) );
	}

	/**
	 * Refunded / revoked: disable the lines (or delete them for good when the
	 * download's "On refund or revoked order" says so), take back the credits of
	 * reseller items and disable or delete the account the order created.
	 *
	 * @param object|false $order Order.
	 */
	private static function revoke( $order ) {
		if ( ! self::is_sale( $order ) || ! XtreamPro_API::is_configured() ) {
			return;
		}
		$state   = self::get_state( $order->id );
		$user_id = (int) $order->user_id;
		$changed = false;

		foreach ( $state['items'] as $item_id => $rec ) {
			$cfg    = self::download_config( isset( $rec['download'] ) ? (int) $rec['download'] : 0 );
			$delete = $cfg && 'delete' === $cfg['on_cancel'];
			if ( ! empty( $rec['lines'] ) && is_array( $rec['lines'] ) ) {
				foreach ( $rec['lines'] as $i => $line ) {
					if ( ! empty( $line['disabled'] ) ) {
						continue;
					}
					$res = XtreamPro_API::set_line_state( $line['id'], $delete ? 'delete' : 'disable' );
					// A line that is already gone counts as deleted.
					if ( is_wp_error( $res ) && ! ( $delete && 'RESOURCE_NOT_FOUND' === $res->get_error_code() ) ) {
						self::note(
							$order,
							sprintf(
								/* translators: 1: disable or delete, 2: line id, 3: error */
								__( 'Xtream UI Pro: could not %1$s line #%2$d: %3$s', 'xtreampro' ),
								$delete ? __( 'delete', 'xtreampro' ) : __( 'disable', 'xtreampro' ),
								$line['id'],
								$res->get_error_message()
							)
						);
						continue;
					}
					$rec['lines'][ $i ]['disabled'] = true;
					$changed                        = true;
					if ( $delete ) {
						XtreamPro_API::forget_line( $user_id, $line['id'] );
						self::note( $order, sprintf( /* translators: %d: line id */ __( 'Xtream UI Pro: line #%d deleted.', 'xtreampro' ), $line['id'] ) );
					} else {
						self::note( $order, sprintf( /* translators: %d: line id */ __( 'Xtream UI Pro: line #%d disabled.', 'xtreampro' ), $line['id'] ) );
					}
				}
			}

			$target  = isset( $rec['sub_id'] ) ? (string) $rec['sub_id'] : '';
			$credits = isset( $rec['credited'] ) ? (int) $rec['credited'] : 0;
			if ( '' !== $target && $credits > 0 && empty( $rec['revoked'] ) ) {
				$res = XtreamPro_API::adjust_credits( $target, -$credits, 'Order #' . $order->get_number() . ' refunded', 'edd-subx-' . (int) $order->id . '-' . (int) $rec['download'] );
				if ( is_wp_error( $res ) ) {
					self::note(
						$order,
						sprintf(
							/* translators: 1: credits, 2: error message */
							__( 'Xtream UI Pro: could not take back %1$d credits (they may already be spent): %2$s', 'xtreampro' ),
							$credits,
							$res->get_error_message()
						)
					);
				} else {
					$rec['revoked'] = $credits;
					$changed        = true;
					delete_transient( 'xtreampro_rs_' . $user_id );
					self::note( $order, sprintf( /* translators: %d: credits */ __( 'Xtream UI Pro: %d credits taken back.', 'xtreampro' ), $credits ) );
				}
			}

			if ( '' !== $target && ! empty( $rec['created'] ) && empty( $rec['sub_disabled'] ) ) {
				$res = XtreamPro_API::set_sub_user_state( $target, $delete ? 'delete' : 'disable' );
				if ( is_wp_error( $res ) && ! ( $delete && 'RESOURCE_NOT_FOUND' === $res->get_error_code() ) ) {
					self::note(
						$order,
						sprintf(
							/* translators: 1: disable or delete, 2: error */
							__( 'Xtream UI Pro: could not %1$s the reseller account: %2$s', 'xtreampro' ),
							$delete ? __( 'delete', 'xtreampro' ) : __( 'disable', 'xtreampro' ),
							$res->get_error_message()
						)
					);
				} else {
					$rec['sub_disabled'] = true;
					$changed             = true;
					delete_transient( 'xtreampro_rs_' . $user_id );
					if ( $delete ) {
						// Gone for good on the panel: the customer has no account to show any more.
						delete_user_meta( $user_id, XtreamPro_API::USER_RESELLER_META );
					}
					self::note( $order, $delete ? __( 'Xtream UI Pro: reseller account deleted.', 'xtreampro' ) : __( 'Xtream UI Pro: reseller account disabled.', 'xtreampro' ) );
				}
			}
			$state['items'][ $item_id ] = $rec;
		}
		if ( $changed ) {
			self::save_state( $order->id, $state );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Admin retry                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Does the order contain downloads that are sold through the panel?
	 *
	 * @param object $order Order.
	 * @return bool
	 */
	private static function has_package_items( $order ) {
		foreach ( $order->get_items() as $item ) {
			if ( self::download_config( $item->product_id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param int $order_id Order id.
	 */
	public static function retry_box( $order_id ) {
		if ( ! current_user_can( 'edit_shop_payments' ) ) {
			return;
		}
		$order = edd_get_order( (int) $order_id );
		if ( ! self::is_sale( $order ) || 'complete' !== $order->status || ! self::has_package_items( $order ) ) {
			return;
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=xtreampro_edd_provision&order_id=' . (int) $order_id ), 'xtreampro_edd_provision_' . (int) $order_id );
		echo '<div class="postbox"><h3 class="hndle"><span>' . esc_html__( 'Xtream UI Pro', 'xtreampro' ) . '</span></h3><div class="inside"><p>';
		echo '<a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Provision Xtream UI Pro lines again', 'xtreampro' ) . '</a>';
		echo '</p><p class="description">' . esc_html__( 'Retries what failed (see the order notes). Lines and credits are never given twice.', 'xtreampro' ) . '</p></div></div>';
	}

	public static function handle_retry() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked right below.
		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		check_admin_referer( 'xtreampro_edd_provision_' . $order_id );
		if ( ! current_user_can( 'edit_shop_payments' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'xtreampro' ), '', array( 'response' => 403 ) );
		}
		self::provision( edd_get_order( $order_id ) );
		wp_safe_redirect( admin_url( 'edit.php?post_type=download&page=edd-payment-history&view=view-order-details&id=' . $order_id ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Credentials output                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Lines and reseller accounts an order provisioned.
	 *
	 * @param object $order Order.
	 * @return array{lines:array[],resellers:array[]}
	 */
	private static function order_credentials( $order ) {
		$out   = array(
			'lines'     => array(),
			'resellers' => array(),
		);
		$state = self::get_state( $order->id );
		$names = array();
		foreach ( $order->get_items() as $item ) {
			$names[ (int) $item->id ] = (string) $item->product_name;
		}
		$account = null;
		foreach ( $state['items'] as $item_id => $rec ) {
			$name = isset( $names[ (int) $item_id ] ) ? $names[ (int) $item_id ] : '';
			if ( ! empty( $rec['lines'] ) && is_array( $rec['lines'] ) ) {
				foreach ( $rec['lines'] as $line ) {
					$line['name']   = $name;
					$out['lines'][] = $line;
				}
			}
			$credits = isset( $rec['credited'] ) ? (int) $rec['credited'] : 0;
			$created = ! empty( $rec['created'] );
			if ( empty( $rec['sub_id'] ) || ( ! $created && $credits <= 0 ) ) {
				continue;
			}
			if ( $created ) {
				$username = isset( $rec['sub_user'] ) ? (string) $rec['sub_user'] : '';
				$password = isset( $rec['sub_pass'] ) ? (string) $rec['sub_pass'] : '';
			} else {
				if ( null === $account ) {
					$account = XtreamPro_API::user_reseller( (int) $order->user_id );
					$account = $account ? $account : array( 'username' => '' );
				}
				$username = $account['username'];
				$password = '';
			}
			$out['resellers'][] = array(
				'name'     => $name,
				'username' => $username,
				'password' => $password,
				'credits'  => $credits,
			);
		}
		return $out;
	}

	/**
	 * May the current visitor see the passwords of this order? EDD only gets
	 * here for someone allowed to view the receipt; passwords are further
	 * limited to the buyer (a shop admin looking at a customer's receipt does
	 * not see them).
	 *
	 * @param object $order Order.
	 * @return bool
	 */
	private static function viewer_may_see_secrets( $order ) {
		$owner = (int) $order->user_id;
		if ( $owner > 0 ) {
			return is_user_logged_in() && get_current_user_id() === $owner;
		}
		// Guest order: reached with the receipt key. A signed-in admin is not the buyer.
		return ! current_user_can( 'manage_options' );
	}

	/**
	 * What a customer is shown about one line: server, username, password (only
	 * for the buyer), then the play links the panel returned (those that carry the
	 * password only for the buyer; assembled only for a line without any).
	 *
	 * @param array $line    {id, username, password, links}.
	 * @param bool  $secrets Include the password and the links that carry it.
	 * @return array[] Rows of {label, value, 'text'|'url'}.
	 */
	private static function rows_with_login( array $line, $secrets ) {
		$links = isset( $line['links'] ) && is_array( $line['links'] ) && $line['links'] ? $line['links'] : null;
		$rows  = array();
		$play  = array();
		foreach ( XtreamPro_API::link_rows( $links, $line['username'], $line['password'], $secrets ) as $row ) {
			if ( 'server' === $row['key'] ) {
				$rows[] = array( $row['label'], $row['url'], 'text' );
			} else {
				$play[] = array( $row['label'], $row['url'], 'url' );
			}
		}
		$rows[] = array( __( 'Username', 'xtreampro' ), $line['username'], 'text' );
		if ( $secrets ) {
			$rows[] = array( __( 'Password', 'xtreampro' ), $line['password'], 'text' );
		}
		return array_merge( $rows, $play );
	}

	/**
	 * Credentials as HTML (inline styles for emails) or plain text.
	 *
	 * @param array $creds   Result of order_credentials().
	 * @param bool  $secrets Include passwords.
	 * @param bool  $inline  Inline styles (emails ignore the stylesheet).
	 * @param bool  $text    Plain text instead of HTML.
	 * @return string
	 */
	private static function render( array $creds, $secrets, $inline, $text ) {
		$out   = '';
		$login = XtreamPro_API::panel_login_url();
		$cell  = $inline ? ' style="text-align:left;padding:8px;border:1px solid #e5e5e5;"' : '';

		if ( $creds['resellers'] ) {
			if ( $text ) {
				$out .= "\n" . __( 'Your reseller account', 'xtreampro' ) . "\n";
				foreach ( $creds['resellers'] as $row ) {
					$out .= "\n" . $row['name'] . "\n" . __( 'Username', 'xtreampro' ) . ': ' . $row['username'] . "\n";
					if ( $secrets && '' !== $row['password'] ) {
						$out .= __( 'Password', 'xtreampro' ) . ': ' . $row['password'] . "\n";
					}
					$out .= __( 'Credits added by this order', 'xtreampro' ) . ': ' . $row['credits'] . "\n";
					if ( '' !== $login ) {
						$out .= __( 'Sign in', 'xtreampro' ) . ': ' . $login . "\n";
					}
				}
			} else {
				$out .= '<section class="xtreampro-credentials"><h2' . ( $inline ? ' style="margin:24px 0 8px;"' : '' ) . '>' . esc_html__( 'Your reseller account', 'xtreampro' ) . '</h2>';
				foreach ( $creds['resellers'] as $row ) {
					$out .= '<table class="xtreampro-table" cellspacing="0" cellpadding="6"' . ( $inline ? ' style="width:100%;border-collapse:collapse;margin-bottom:12px;"' : '' ) . '><tbody>';
					$out .= '<tr><th' . $cell . ' colspan="2">' . esc_html( $row['name'] ) . '</th></tr>';
					$out .= '<tr><th' . $cell . '>' . esc_html__( 'Username', 'xtreampro' ) . '</th><td' . $cell . '>' . esc_html( $row['username'] ) . '</td></tr>';
					if ( $secrets && '' !== $row['password'] ) {
						$out .= '<tr><th' . $cell . '>' . esc_html__( 'Password', 'xtreampro' ) . '</th><td' . $cell . '>' . esc_html( $row['password'] ) . '</td></tr>';
					}
					$out .= '<tr><th' . $cell . '>' . esc_html__( 'Credits added by this order', 'xtreampro' ) . '</th><td' . $cell . '>' . esc_html( (string) $row['credits'] ) . '</td></tr>';
					if ( '' !== $login ) {
						$out .= '<tr><th' . $cell . '>' . esc_html__( 'Sign in', 'xtreampro' ) . '</th><td' . $cell . '><a href="' . esc_url( $login ) . '">' . esc_html( $login ) . '</a></td></tr>';
					}
					$out .= '</tbody></table>';
				}
				$out .= '</section>';
			}
		}

		if ( $creds['lines'] ) {
			if ( $text ) {
				$out .= "\n" . __( 'Your IPTV subscription', 'xtreampro' ) . "\n";
				foreach ( $creds['lines'] as $line ) {
					$out .= "\n" . $line['name'] . "\n";
					foreach ( self::rows_with_login( $line, $secrets ) as $row ) {
						$out .= $row[0] . ': ' . $row[1] . "\n";
					}
				}
			} else {
				$out .= '<section class="xtreampro-credentials"><h2' . ( $inline ? ' style="margin:24px 0 8px;"' : '' ) . '>' . esc_html__( 'Your IPTV subscription', 'xtreampro' ) . '</h2>';
				foreach ( $creds['lines'] as $line ) {
					$out .= '<table class="xtreampro-table" cellspacing="0" cellpadding="6"' . ( $inline ? ' style="width:100%;border-collapse:collapse;margin-bottom:12px;"' : '' ) . '><tbody>';
					$out .= '<tr><th' . $cell . ' colspan="2">' . esc_html( $line['name'] ) . ' (#' . esc_html( (string) $line['id'] ) . ')</th></tr>';
					foreach ( self::rows_with_login( $line, $secrets ) as $row ) {
						$value = 'url' === $row[2] ? '<a href="' . esc_url( $row[1] ) . '">' . esc_html( $row[1] ) . '</a>' : esc_html( $row[1] );
						$out  .= '<tr><th' . $cell . '>' . esc_html( $row[0] ) . '</th><td' . $cell . '>' . $value . '</td></tr>';
					}
					$out .= '</tbody></table>';
				}
				$out .= '</section>';
			}
		}
		return $out;
	}

	/**
	 * Receipt page ([edd_receipt] / receipt block), after the order table.
	 *
	 * @param object $order Order.
	 */
	public static function receipt_credentials( $order ) {
		if ( ! self::is_sale( $order ) ) {
			return;
		}
		$creds = self::order_credentials( $order );
		if ( ! $creds['lines'] && ! $creds['resellers'] ) {
			return;
		}
		wp_enqueue_style( 'xtreampro' );
		echo self::render( $creds, self::viewer_may_see_secrets( $order ), false, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every value is escaped inside render().
	}

	/**
	 * Register the {xtreampro_credentials} email tag.
	 */
	public static function register_email_tag() {
		edd_add_email_tag(
			self::EMAIL_TAG,
			__( 'The IPTV lines / reseller account of the order with the sign-in details (customer emails only)', 'xtreampro' ),
			array( __CLASS__, 'email_tag' ),
			__( 'Xtream UI Pro credentials', 'xtreampro' ),
			null,
			array( 'customer' )
		);
	}

	/**
	 * Tag callback. EDD does not stop a tag from being rendered in an email to
	 * the shop admin, so passwords are only written into the customer's email.
	 *
	 * @param int          $order_id     Order id.
	 * @param mixed        $email_object Order (or other email object).
	 * @param object|mixed $email        The email (EDD 3.2+) or the context.
	 * @return string
	 */
	public static function email_tag( $order_id, $email_object = null, $email = null ) {
		$order = edd_get_order( (int) $order_id );
		if ( ! self::is_sale( $order ) ) {
			return '';
		}
		$creds = self::order_credentials( $order );
		if ( ! $creds['lines'] && ! $creds['resellers'] ) {
			return '';
		}
		$to_customer = is_object( $email ) && method_exists( $email, 'get_recipient_type' ) && 'customer' === $email->get_recipient_type();
		$plain       = 'none' === edd_get_option( 'email_template', 'default' );
		return self::render( $creds, $to_customer, true, $plain );
	}

	/**
	 * Make sure the customer's receipt carries the credentials even when the
	 * shop's receipt template does not contain the tag.
	 *
	 * @param string       $content Receipt body (tags not replaced yet).
	 * @param object|false $order   Order.
	 * @return string
	 */
	public static function receipt_email_body( $content, $order = null ) {
		if ( ! self::is_sale( $order ) || false !== strpos( (string) $content, '{' . self::EMAIL_TAG . '}' ) ) {
			return $content;
		}
		$creds = self::order_credentials( $order );
		if ( ! $creds['lines'] && ! $creds['resellers'] ) {
			return $content;
		}
		return $content . '{' . self::EMAIL_TAG . '}';
	}
}
