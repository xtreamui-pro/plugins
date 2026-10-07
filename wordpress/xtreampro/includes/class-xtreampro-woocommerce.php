<?php
/**
 * WooCommerce integration: sell packages, provision / renew / disable lines.
 *
 * @package XtreamPro
 */

defined( 'ABSPATH' ) || exit;

class XtreamPro_WooCommerce {

	public static function init() {
		// Product data.
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'product_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product' ) );

		// Renewal choice on the product page -> cart -> order item.
		add_action( 'woocommerce_before_add_to_cart_button', array( __CLASS__, 'renew_field' ) );
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'cart_item_data' ), 10, 2 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'cart_display' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'order_item_meta' ), 10, 3 );

		// Provisioning.
		// Priority 1: before WooCommerce sends the customer email (priority 10),
		// so the email already carries the sign-in details.
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_completed' ), 1, 1 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_processing' ), 1, 1 );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'on_revoked' ), 10, 1 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'on_revoked' ), 10, 1 );

		// Admin retry action.
		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'order_actions' ) );
		add_action( 'woocommerce_order_action_xtreampro_provision', array( __CLASS__, 'on_manual' ) );

		// Show credentials.
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'email_credentials' ), 10, 4 );
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'page_credentials' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Product data                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Package configuration of a product (variations use their parent).
	 *
	 * @param WC_Product|false $product Product.
	 * @return array{kind:string,package_id:int,trial:bool,credits:int,on_cancel:string}|null
	 */
	private static function product_config( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return null;
		}
		if ( $product->is_type( 'variation' ) ) {
			$product = wc_get_product( $product->get_parent_id() );
			if ( ! $product ) {
				return null;
			}
		}
		// What a refund or cancellation does to what the order created: 'disable' (default) or 'delete' (final).
		$on_cancel = 'delete' === $product->get_meta( '_xtreampro_on_cancel' ) ? 'delete' : 'disable';
		if ( 'reseller' === $product->get_meta( '_xtreampro_kind' ) ) {
			return array(
				'kind'       => 'reseller',
				'package_id' => 0,
				'trial'      => false,
				'credits'    => max( 0, (int) $product->get_meta( '_xtreampro_credits' ) ),
				'on_cancel'  => $on_cancel,
			);
		}
		$package_id = (int) $product->get_meta( '_xtreampro_package_id' );
		if ( $package_id <= 0 ) {
			return null;
		}
		return array(
			'kind'       => 'line',
			'package_id' => $package_id,
			'trial'      => 'yes' === $product->get_meta( '_xtreampro_trial' ),
			'credits'    => 0,
			'on_cancel'  => $on_cancel,
		);
	}

	public static function product_fields() {
		global $post;
		$current = $post ? (int) get_post_meta( $post->ID, '_xtreampro_package_id', true ) : 0;

		$options = array( '' => __( '— None —', 'xtreampro' ) );
		$packages = XtreamPro_API::is_configured() ? XtreamPro_API::line_packages() : array();
		if ( is_array( $packages ) ) {
			foreach ( $packages as $pkg ) {
				$options[ (string) (int) $pkg['id'] ] = sprintf( '%s (#%d)', isset( $pkg['name'] ) ? $pkg['name'] : '', (int) $pkg['id'] );
			}
		}
		if ( $current > 0 && ! isset( $options[ (string) $current ] ) ) {
			$options[ (string) $current ] = sprintf( '#%d', $current );
		}

		$kind = $post ? (string) get_post_meta( $post->ID, '_xtreampro_kind', true ) : '';

		echo '<div class="options_group show_if_simple show_if_variable">';
		woocommerce_wp_select(
			array(
				'id'          => '_xtreampro_kind',
				'label'       => __( 'Xtream UI Pro product type', 'xtreampro' ),
				'value'       => 'reseller' === $kind ? 'reseller' : 'line',
				'options'     => array(
					'line'     => __( 'IPTV line', 'xtreampro' ),
					'reseller' => __( 'Sub-reseller account / credits', 'xtreampro' ),
				),
				'desc_tip'    => true,
				'description' => __( 'IPTV line creates lines with the package below. Sub-reseller account creates a reseller account for the customer (once) and hands over the credits below.', 'xtreampro' ),
			)
		);
		woocommerce_wp_select(
			array(
				'id'          => '_xtreampro_package_id',
				'label'       => __( 'Xtream UI Pro package', 'xtreampro' ),
				'options'     => $options,
				'desc_tip'    => true,
				'description' => __( 'Buying this product creates a line with this package on the panel. Variations use the package of the parent product. Ignored for sub-reseller products.', 'xtreampro' ),
			)
		);
		woocommerce_wp_checkbox(
			array(
				'id'          => '_xtreampro_trial',
				'label'       => __( 'Trial line', 'xtreampro' ),
				'description' => __( 'Create a trial line (uses the package trial settings and trial credits). Ignored for sub-reseller products.', 'xtreampro' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => '_xtreampro_credits',
				'label'             => __( 'Credits', 'xtreampro' ),
				'type'              => 'number',
				'desc_tip'          => true,
				'description'       => __( 'Sub-reseller products only: credits handed to the customer\'s reseller account per purchased unit (taken from your reseller balance). 0 creates the account without credits. Ignored for IPTV lines.', 'xtreampro' ),
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
			)
		);
		woocommerce_wp_select(
			array(
				'id'          => '_xtreampro_on_cancel',
				'label'       => __( 'On refund or cancellation', 'xtreampro' ),
				'value'       => 'delete' === get_post_meta( $post ? $post->ID : 0, '_xtreampro_on_cancel', true ) ? 'delete' : 'disable',
				'options'     => array(
					'disable' => __( 'Disable (default)', 'xtreampro' ),
					'delete'  => __( 'Delete permanently', 'xtreampro' ),
				),
				'desc_tip'    => true,
				'description' => __( 'What a refunded or cancelled order does to the line (or sub-reseller account) it created. Disable can be undone on the panel. Delete is FINAL: the line and everything recorded about its customer are erased on the panel and cannot be restored.', 'xtreampro' ),
			)
		);
		if ( is_wp_error( $packages ) ) {
			echo '<p class="form-field"><em>' . esc_html( $packages->get_error_message() ) . '</em></p>';
		}
		echo '</div>';
	}

	/**
	 * @param WC_Product $product Product being saved (nonce checked by WooCommerce).
	 */
	public static function save_product( $product ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$package_id = isset( $_POST['_xtreampro_package_id'] ) ? absint( wp_unslash( $_POST['_xtreampro_package_id'] ) ) : 0;
		$trial      = isset( $_POST['_xtreampro_trial'] ) ? 'yes' : 'no';
		$kind       = isset( $_POST['_xtreampro_kind'] ) && 'reseller' === sanitize_key( wp_unslash( $_POST['_xtreampro_kind'] ) ) ? 'reseller' : 'line';
		$credits    = isset( $_POST['_xtreampro_credits'] ) ? absint( wp_unslash( $_POST['_xtreampro_credits'] ) ) : 0;
		$on_cancel  = isset( $_POST['_xtreampro_on_cancel'] ) && 'delete' === sanitize_key( wp_unslash( $_POST['_xtreampro_on_cancel'] ) ) ? 'delete' : 'disable';
		// phpcs:enable
		if ( $package_id > 0 ) {
			$product->update_meta_data( '_xtreampro_package_id', $package_id );
		} else {
			$product->delete_meta_data( '_xtreampro_package_id' );
		}
		$product->update_meta_data( '_xtreampro_trial', $trial );
		$product->update_meta_data( '_xtreampro_kind', $kind );
		$product->update_meta_data( '_xtreampro_credits', $credits );
		$product->update_meta_data( '_xtreampro_on_cancel', $on_cancel );
	}

	/* ------------------------------------------------------------------ */
	/* Renewal choice                                                      */
	/* ------------------------------------------------------------------ */

	public static function renew_field() {
		global $product;
		if ( ! is_user_logged_in() || ! $product instanceof WC_Product ) {
			return;
		}
		$cfg = self::product_config( $product );
		if ( ! $cfg || 'line' !== $cfg['kind'] || $cfg['trial'] ) {
			return;
		}
		$owned = array();
		foreach ( XtreamPro_API::user_lines( get_current_user_id() ) as $entry ) {
			if ( isset( $entry['product_id'], $entry['line_id'] ) && (int) $entry['product_id'] === $product->get_id() ) {
				$owned[ (int) $entry['line_id'] ] = true;
			}
		}
		if ( ! $owned ) {
			return;
		}
		echo '<p class="xtreampro-renew"><label for="xtreampro_renew_line">' . esc_html__( 'Renew my existing line', 'xtreampro' ) . '</label> ';
		echo '<select name="xtreampro_renew_line" id="xtreampro_renew_line">';
		echo '<option value="0">' . esc_html__( 'No, create a new line', 'xtreampro' ) . '</option>';
		foreach ( array_keys( $owned ) as $line_id ) {
			echo '<option value="' . esc_attr( (string) $line_id ) . '">' . esc_html( sprintf( /* translators: %d: line id */ __( 'Renew line #%d', 'xtreampro' ), $line_id ) ) . '</option>';
		}
		echo '</select></p>';
	}

	/**
	 * @param array $data       Cart item data.
	 * @param int   $product_id Product id.
	 * @return array
	 */
	public static function cart_item_data( $data, $product_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$line_id = isset( $_POST['xtreampro_renew_line'] ) ? absint( wp_unslash( $_POST['xtreampro_renew_line'] ) ) : 0;
		if ( $line_id > 0 && is_user_logged_in() && XtreamPro_API::user_owns_line( get_current_user_id(), $line_id, (int) $product_id ) ) {
			$data['xtreampro_renew_line'] = $line_id;
		}
		return $data;
	}

	/**
	 * @param array $item_data Display data.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public static function cart_display( $item_data, $cart_item ) {
		if ( ! empty( $cart_item['xtreampro_renew_line'] ) ) {
			$item_data[] = array(
				'key'   => __( 'Renewal', 'xtreampro' ),
				'value' => sprintf( /* translators: %d: line id */ __( 'Line #%d', 'xtreampro' ), (int) $cart_item['xtreampro_renew_line'] ),
			);
		}
		return $item_data;
	}

	/**
	 * @param WC_Order_Item_Product $item          Order item.
	 * @param string                $cart_item_key Cart key.
	 * @param array                 $values        Cart item values.
	 */
	public static function order_item_meta( $item, $cart_item_key, $values ) {
		if ( ! empty( $values['xtreampro_renew_line'] ) ) {
			$item->add_meta_data( '_xtreampro_renew_line_id', (int) $values['xtreampro_renew_line'], true );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Provisioning                                                        */
	/* ------------------------------------------------------------------ */

	public static function on_completed( $order_id ) {
		self::provision( wc_get_order( $order_id ) );
	}

	/**
	 * Processing orders are provisioned only when every item is virtual.
	 */
	public static function on_processing( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product || ! $product->is_virtual() ) {
				return;
			}
		}
		self::provision( $order );
	}

	public static function on_manual( $order ) {
		self::provision( $order );
	}

	public static function order_actions( $actions ) {
		$actions['xtreampro_provision'] = __( 'Provision Xtream UI Pro lines again', 'xtreampro' );
		return $actions;
	}

	/**
	 * Stored lines of an order item: list of {id, username, password, links}.
	 *
	 * @param WC_Order_Item $item Order item.
	 * @return array[]
	 */
	private static function item_lines( $item ) {
		// get_meta( $key, false ) keeps the positions of the full meta list as
		// keys; re-index so the three lists pair up by line.
		$ids   = array_values( $item->get_meta( '_xtreampro_line_id', false ) );
		$users = array_values( $item->get_meta( '_xtreampro_username', false ) );
		$pass  = array_values( $item->get_meta( '_xtreampro_password', false ) );
		$out   = array();
		foreach ( $ids as $i => $meta ) {
			// The links the panel returned when it made (or renewed) the line; a line of an
			// order made by version 1.0.0 has none and gets them assembled when shown.
			$links = json_decode( (string) $item->get_meta( '_xtreampro_links_' . (int) $meta->value ), true );
			$out[] = array(
				'id'       => (int) $meta->value,
				'username' => isset( $users[ $i ] ) ? (string) $users[ $i ]->value : '',
				'password' => isset( $pass[ $i ] ) ? (string) $pass[ $i ]->value : '',
				'links'    => is_array( $links ) && $links ? $links : null,
			);
		}
		return $out;
	}

	/**
	 * What provisioning the order would still buy, for XtreamPro_API::check_credits().
	 *
	 * @param WC_Order $order   Order.
	 * @param array    $work    Product config by item id.
	 * @param int      $user_id Customer id.
	 * @return array[]
	 */
	private static function needs( $order, array $work, $user_id ) {
		$needs = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! isset( $work[ $item_id ] ) ) {
				continue;
			}
			$cfg = $work[ $item_id ];
			if ( 'reseller' === $cfg['kind'] ) {
				if ( $user_id <= 0 ) {
					continue; // not provisioned at all (see provision_reseller)
				}
				$exists = '' !== (string) $item->get_meta( '_xtreampro_sub_user_id' ) || XtreamPro_API::user_reseller( $user_id );
				$given  = '' !== (string) $item->get_meta( '_xtreampro_sub_credited' );
				$needs[] = array(
					'kind'    => 'reseller',
					'create'  => ! $exists,
					'credits' => $given ? 0 : (int) $cfg['credits'] * max( 1, (int) $item->get_quantity() ),
				);
				continue;
			}
			$done  = count( self::item_lines( $item ) );
			$renew = $cfg['trial'] ? 0 : (int) $item->get_meta( '_xtreampro_renew_line_id' );
			$left  = $renew > 0 ? ( $done < 1 ? 1 : 0 ) : max( 0, max( 1, (int) $item->get_quantity() ) - $done );
			if ( $left > 0 ) {
				$needs[] = array(
					'kind'       => 'line',
					'package_id' => $cfg['package_id'],
					'trial'      => $cfg['trial'],
					'units'      => $left,
					'renewal'    => $renew > 0,
				);
			}
		}
		return $needs;
	}

	/**
	 * Create / renew the lines of every package item of the order.
	 *
	 * @param WC_Order|false $order Order.
	 */
	public static function provision( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$work = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			$cfg = self::product_config( wc_get_product( $item->get_product_id() ) );
			if ( $cfg ) {
				$work[ $item_id ] = $cfg;
			}
		}
		if ( ! $work ) {
			return;
		}
		if ( ! XtreamPro_API::is_configured() ) {
			$order->add_order_note( __( 'Xtream UI Pro: not configured, lines were not provisioned.', 'xtreampro' ) );
			return;
		}

		$lock = 'xtreampro_lock_' . $order->get_id();
		if ( get_transient( $lock ) ) {
			return;
		}
		set_transient( $lock, 1, 2 * MINUTE_IN_SECONDS );

		try {
			$user_id = (int) $order->get_customer_id();

			// Ask what the order still has to buy before buying any of it, so a short
			// balance (or a package that is not on sale) leaves the order untouched.
			$short = XtreamPro_API::check_credits( self::needs( $order, $work, $user_id ) );
			if ( is_wp_error( $short ) ) {
				$order->add_order_note(
					sprintf(
						/* translators: %s: reason */
						__( 'Xtream UI Pro: nothing was provisioned. %s Fix it, then use "Provision Xtream UI Pro lines again".', 'xtreampro' ),
						$short->get_error_message()
					)
				);
				return;
			}
			foreach ( $order->get_items() as $item_id => $item ) {
				if ( ! isset( $work[ $item_id ] ) ) {
					continue;
				}
				$cfg = $work[ $item_id ];
				if ( 'reseller' === $cfg['kind'] ) {
					self::provision_reseller( $order, $item, $item_id, $cfg, $user_id );
					continue;
				}
				$done  = count( self::item_lines( $item ) );
				$renew = $cfg['trial'] ? 0 : (int) $item->get_meta( '_xtreampro_renew_line_id' );
				$units = $renew > 0 ? 1 : max( 1, (int) $item->get_quantity() );

				if ( $renew > 0 && $done < 1 && ( $user_id <= 0 || ! XtreamPro_API::user_owns_line( $user_id, $renew ) ) ) {
					/* translators: 1: item name, 2: line id */
					$order->add_order_note( sprintf( __( 'Xtream UI Pro: "%1$s" was not provisioned, line #%2$d does not belong to the customer.', 'xtreampro' ), $item->get_name(), $renew ) );
					continue;
				}

				for ( $n = $done + 1; $n <= $units; $n++ ) {
					if ( $renew > 0 ) {
						$data = XtreamPro_API::renew_line( $renew, 'wc-renew-' . $order->get_id() . '-' . $item_id );
					} else {
						$data = XtreamPro_API::create_line( $cfg['package_id'], $cfg['trial'], 'wc-' . $order->get_id() . '-' . $item_id . '-' . $n );
					}
					if ( is_wp_error( $data ) ) {
						$order->add_order_note(
							sprintf(
								/* translators: 1: item name, 2: error message */
								__( 'Xtream UI Pro: could not provision "%1$s": %2$s', 'xtreampro' ),
								$item->get_name(),
								$data->get_error_message()
							)
						);
						break;
					}

					$cred = XtreamPro_API::credentials( $data );
					if ( $renew > 0 && $cred['id'] <= 0 ) {
						$cred['id'] = $renew;
					}
					if ( $cred['id'] <= 0 ) {
						$order->add_order_note( sprintf( /* translators: %s: item name */ __( 'Xtream UI Pro: the panel returned no line for "%s".', 'xtreampro' ), $item->get_name() ) );
						break;
					}

					$item->add_meta_data( '_xtreampro_line_id', $cred['id'], false );
					$item->add_meta_data( '_xtreampro_username', $cred['username'], false );
					$item->add_meta_data( '_xtreampro_password', $cred['password'], false );
					$item->update_meta_data( '_xtreampro_links_' . $cred['id'], $cred['links'] ? wp_json_encode( $cred['links'] ) : '' );
					$item->save();

					XtreamPro_API::remember_line( $user_id, $cred['id'], $item->get_product_id(), $order->get_id() );

					$order->add_order_note(
						sprintf(
							/* translators: 1: created or renewed, 2: line id, 3: username, 4: item name */
							__( 'Xtream UI Pro: line %1$s #%2$d (%3$s) for "%4$s".', 'xtreampro' ),
							$renew > 0 ? __( 'renewed', 'xtreampro' ) : __( 'created', 'xtreampro' ),
							$cred['id'],
							$cred['username'],
							$item->get_name()
						)
					);
				}
			}
		} finally {
			delete_transient( $lock );
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
	 * @param WC_Order              $order   Order.
	 * @param WC_Order_Item_Product $item    Order item.
	 * @param int                   $item_id Item id.
	 * @param array                 $cfg     Product config.
	 * @param int                   $user_id Customer id.
	 */
	private static function provision_reseller( $order, $item, $item_id, array $cfg, $user_id ) {
		if ( $user_id <= 0 ) {
			/* translators: %s: item name */
			$order->add_order_note( sprintf( __( 'Xtream UI Pro: "%s" needs a WordPress account on the order to hold the reseller account. It was not provisioned; ask the customer to order while logged in.', 'xtreampro' ), $item->get_name() ) );
			return;
		}

		$sub_id  = (string) $item->get_meta( '_xtreampro_sub_user_id' );
		$account = XtreamPro_API::user_reseller( $user_id );
		if ( '' === $sub_id && $account ) {
			$sub_id = $account['user_id'];
		}

		if ( '' === $sub_id ) {
			// Credentials are stored before the call so a retry sends the same ones.
			$username = (string) $item->get_meta( '_xtreampro_sub_username' );
			$password = (string) $item->get_meta( '_xtreampro_sub_password' );
			if ( '' === $username || '' === $password ) {
				$username = self::generate_sub_username( $user_id );
				$password = wp_generate_password( 14, false );
				$item->update_meta_data( '_xtreampro_sub_username', $username );
				$item->update_meta_data( '_xtreampro_sub_password', $password );
				$item->save();
			}
			$fullname = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
			if ( '' === $fullname ) {
				$fullname = $username;
			}
			$data = XtreamPro_API::create_sub_user( $username, $password, $order->get_billing_email(), mb_substr( $fullname, 0, 128 ), 'wc-sub-' . $order->get_id() . '-' . $item_id );
			if ( is_wp_error( $data ) ) {
				$order->add_order_note(
					sprintf(
						/* translators: 1: item name, 2: error message */
						__( 'Xtream UI Pro: could not create the reseller account for "%1$s": %2$s', 'xtreampro' ),
						$item->get_name(),
						$data->get_error_message()
					)
				);
				return;
			}
			$created = isset( $data['user'] ) && is_array( $data['user'] ) ? $data['user'] : array();
			$sub_id  = isset( $created['id'] ) ? (string) $created['id'] : '';
			if ( '' === $sub_id ) {
				$order->add_order_note( sprintf( /* translators: %s: item name */ __( 'Xtream UI Pro: the panel returned no account for "%s".', 'xtreampro' ), $item->get_name() ) );
				return;
			}
			if ( ! empty( $created['username'] ) ) {
				$username = (string) $created['username'];
			}
			if ( ! empty( $data['password'] ) ) {
				$password = (string) $data['password'];
			}
			$item->update_meta_data( '_xtreampro_sub_user_id', $sub_id );
			$item->update_meta_data( '_xtreampro_sub_username', $username );
			$item->update_meta_data( '_xtreampro_sub_password', $password );
			$item->save();
			XtreamPro_API::remember_reseller( $user_id, $sub_id, $username, $order->get_id() );
			delete_transient( 'xtreampro_rs_' . $user_id );

			$order->add_order_note(
				sprintf(
					/* translators: 1: username, 2: item name */
					__( 'Xtream UI Pro: reseller account %1$s created for "%2$s".', 'xtreampro' ),
					$username,
					$item->get_name()
				)
			);
		}

		if ( '' !== (string) $item->get_meta( '_xtreampro_sub_credited' ) ) {
			return;
		}
		$credits = (int) $cfg['credits'] * max( 1, (int) $item->get_quantity() );
		if ( $credits <= 0 ) {
			return;
		}
		$res = XtreamPro_API::adjust_credits( $sub_id, $credits, 'Order #' . $order->get_order_number(), 'wc-subc-' . $order->get_id() . '-' . $item_id );
		if ( is_wp_error( $res ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: credits, 2: item name, 3: error message */
					__( 'Xtream UI Pro: could not give %1$d credits for "%2$s": %3$s', 'xtreampro' ),
					$credits,
					$item->get_name(),
					$res->get_error_message()
				)
			);
			return;
		}
		$item->update_meta_data( '_xtreampro_sub_credited', $credits );
		$item->save();
		delete_transient( 'xtreampro_rs_' . $user_id );
		$order->add_order_note(
			sprintf(
				/* translators: 1: credits, 2: item name */
				__( 'Xtream UI Pro: %1$d credits given to the reseller account for "%2$s".', 'xtreampro' ),
				$credits,
				$item->get_name()
			)
		);
	}

	/**
	 * Does a refund or cancellation delete (for good) what this item created?
	 * The product says so with "On refund or cancellation"; the default is to disable.
	 *
	 * @param WC_Order_Item $item Order item.
	 * @return bool
	 */
	private static function deletes_on_cancel( $item ) {
		$cfg = self::product_config( wc_get_product( $item->get_product_id() ) );
		return $cfg && 'delete' === $cfg['on_cancel'];
	}

	/**
	 * Refunded / cancelled: take back the credits of reseller items and
	 * disable (or, when the product says so, delete) the account the order created.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function revoke_resellers( $order ) {
		$user_id = (int) $order->get_customer_id();
		foreach ( $order->get_items() as $item_id => $item ) {
			$created = (string) $item->get_meta( '_xtreampro_sub_user_id' );
			$credits = (int) $item->get_meta( '_xtreampro_sub_credited' );
			$target  = $created;
			if ( '' === $target && $user_id > 0 ) {
				$account = XtreamPro_API::user_reseller( $user_id );
				$target  = $account ? $account['user_id'] : '';
			}

			if ( $credits > 0 && '' !== $target && '' === (string) $item->get_meta( '_xtreampro_sub_revoked' ) ) {
				$res = XtreamPro_API::adjust_credits( $target, -$credits, 'Order #' . $order->get_order_number() . ' refunded', 'wc-subx-' . $order->get_id() . '-' . $item_id );
				if ( is_wp_error( $res ) ) {
					$order->add_order_note(
						sprintf(
							/* translators: 1: credits, 2: item name, 3: error message */
							__( 'Xtream UI Pro: could not take back %1$d credits for "%2$s" (they may already be spent): %3$s', 'xtreampro' ),
							$credits,
							$item->get_name(),
							$res->get_error_message()
						)
					);
				} else {
					$item->update_meta_data( '_xtreampro_sub_revoked', $credits );
					$item->save();
					delete_transient( 'xtreampro_rs_' . $user_id );
					$order->add_order_note( sprintf( /* translators: 1: credits, 2: item name */ __( 'Xtream UI Pro: %1$d credits taken back for "%2$s".', 'xtreampro' ), $credits, $item->get_name() ) );
				}
			}

			if ( '' !== $created ) {
				$delete = self::deletes_on_cancel( $item );
				$res    = XtreamPro_API::set_sub_user_state( $created, $delete ? 'delete' : 'disable' );
				if ( is_wp_error( $res ) && ! ( $delete && 'RESOURCE_NOT_FOUND' === $res->get_error_code() ) ) {
					$order->add_order_note(
						sprintf(
							/* translators: 1: disable or delete, 2: error */
							__( 'Xtream UI Pro: could not %1$s the reseller account: %2$s', 'xtreampro' ),
							$delete ? __( 'delete', 'xtreampro' ) : __( 'disable', 'xtreampro' ),
							$res->get_error_message()
						)
					);
				} else {
					delete_transient( 'xtreampro_rs_' . $user_id );
					if ( $delete ) {
						// Gone for good on the panel: the customer has no account to show any more.
						delete_user_meta( $user_id, XtreamPro_API::USER_RESELLER_META );
					}
					$order->add_order_note( $delete ? __( 'Xtream UI Pro: reseller account deleted.', 'xtreampro' ) : __( 'Xtream UI Pro: reseller account disabled.', 'xtreampro' ) );
				}
			}
		}
	}

	/**
	 * Refunded / cancelled orders: disable their lines, or delete them for good
	 * when the product's "On refund or cancellation" says so.
	 */
	public static function on_revoked( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! XtreamPro_API::is_configured() ) {
			return;
		}
		self::revoke_resellers( $order );
		foreach ( $order->get_items() as $item ) {
			$delete = self::deletes_on_cancel( $item );
			foreach ( self::item_lines( $item ) as $line ) {
				$res = XtreamPro_API::set_line_state( $line['id'], $delete ? 'delete' : 'disable' );
				if ( is_wp_error( $res ) && ! ( $delete && 'RESOURCE_NOT_FOUND' === $res->get_error_code() ) ) {
					$order->add_order_note(
						sprintf(
							/* translators: 1: disable or delete, 2: line id, 3: error */
							__( 'Xtream UI Pro: could not %1$s line #%2$d: %3$s', 'xtreampro' ),
							$delete ? __( 'delete', 'xtreampro' ) : __( 'disable', 'xtreampro' ),
							$line['id'],
							$res->get_error_message()
						)
					);
				} elseif ( $delete ) {
					// A line that is already gone counts as deleted.
					XtreamPro_API::forget_line( (int) $order->get_customer_id(), $line['id'] );
					/* translators: %d: line id */
					$order->add_order_note( sprintf( __( 'Xtream UI Pro: line #%d deleted.', 'xtreampro' ), $line['id'] ) );
				} else {
					$order->add_order_note( sprintf( /* translators: %d: line id */ __( 'Xtream UI Pro: line #%d disabled.', 'xtreampro' ), $line['id'] ) );
				}
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Credentials output                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * All lines of an order.
	 *
	 * @param WC_Order $order Order.
	 * @return array[]
	 */
	private static function order_lines( $order ) {
		$out = array();
		foreach ( $order->get_items() as $item ) {
			foreach ( self::item_lines( $item ) as $line ) {
				$line['name'] = $item->get_name();
				$out[]        = $line;
			}
		}
		return $out;
	}

	/**
	 * Reseller accounts of an order: rows of {name, username, password, credits}.
	 * The password is only set on the order that created the account.
	 *
	 * @param WC_Order $order Order.
	 * @return array[]
	 */
	private static function order_resellers( $order ) {
		$out     = array();
		$account = null;
		foreach ( $order->get_items() as $item ) {
			$created = '' !== (string) $item->get_meta( '_xtreampro_sub_user_id' );
			$credits = (int) $item->get_meta( '_xtreampro_sub_credited' );
			if ( ! $created && $credits <= 0 ) {
				continue;
			}
			if ( $created ) {
				$username = (string) $item->get_meta( '_xtreampro_sub_username' );
				$password = (string) $item->get_meta( '_xtreampro_sub_password' );
			} else {
				if ( null === $account ) {
					$account = XtreamPro_API::user_reseller( (int) $order->get_customer_id() );
					$account = $account ? $account : array( 'username' => '' );
				}
				$username = $account['username'];
				$password = '';
			}
			$out[] = array(
				'name'     => $item->get_name(),
				'username' => $username,
				'password' => $password,
				'credits'  => $credits,
			);
		}
		return $out;
	}

	/**
	 * @param array[] $rows Rows of order_resellers().
	 */
	private static function render_reseller_text( array $rows ) {
		echo "\n" . esc_html__( 'Your reseller account', 'xtreampro' ) . "\n";
		$login = XtreamPro_API::panel_login_url();
		foreach ( $rows as $row ) {
			echo "\n" . esc_html( $row['name'] ) . "\n";
			echo esc_html__( 'Username', 'xtreampro' ) . ': ' . esc_html( $row['username'] ) . "\n";
			if ( '' !== $row['password'] ) {
				echo esc_html__( 'Password', 'xtreampro' ) . ': ' . esc_html( $row['password'] ) . "\n";
			}
			echo esc_html__( 'Credits added by this order', 'xtreampro' ) . ': ' . esc_html( (string) $row['credits'] ) . "\n";
			if ( '' !== $login ) {
				echo esc_html__( 'Sign in', 'xtreampro' ) . ': ' . esc_url_raw( $login ) . "\n";
			}
		}
		echo "\n";
	}

	/**
	 * @param array[] $rows   Rows of order_resellers().
	 * @param bool    $inline Use inline styles (emails ignore the stylesheet).
	 */
	private static function render_reseller_html( array $rows, $inline ) {
		$cell  = $inline ? ' style="text-align:left;padding:8px;border:1px solid #e5e5e5;"' : '';
		$login = XtreamPro_API::panel_login_url();
		echo '<section class="xtreampro-credentials"><h2' . ( $inline ? ' style="margin:24px 0 8px;"' : '' ) . '>' . esc_html__( 'Your reseller account', 'xtreampro' ) . '</h2>';
		foreach ( $rows as $row ) {
			echo '<table class="shop_table" cellspacing="0" cellpadding="6"' . ( $inline ? ' style="width:100%;border-collapse:collapse;margin-bottom:12px;"' : '' ) . '><tbody>';
			echo '<tr><th' . $cell . ' colspan="2">' . esc_html( $row['name'] ) . '</th></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $cell is a static string.
			echo '<tr><th' . $cell . '>' . esc_html__( 'Username', 'xtreampro' ) . '</th><td' . $cell . '>' . esc_html( $row['username'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			if ( '' !== $row['password'] ) {
				echo '<tr><th' . $cell . '>' . esc_html__( 'Password', 'xtreampro' ) . '</th><td' . $cell . '>' . esc_html( $row['password'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '<tr><th' . $cell . '>' . esc_html__( 'Credits added by this order', 'xtreampro' ) . '</th><td' . $cell . '>' . esc_html( (string) $row['credits'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			if ( '' !== $login ) {
				echo '<tr><th' . $cell . '>' . esc_html__( 'Sign in', 'xtreampro' ) . '</th><td' . $cell . '><a href="' . esc_url( $login ) . '">' . esc_html( $login ) . '</a></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</tbody></table>';
		}
		echo '</section>';
	}

	/**
	 * @param WC_Order      $order         Order.
	 * @param bool          $sent_to_admin Admin email?
	 * @param bool          $plain_text    Plain text email?
	 * @param WC_Email|null $email         Email.
	 */
	public static function email_credentials( $order, $sent_to_admin, $plain_text, $email = null ) {
		if ( $sent_to_admin || ! $order instanceof WC_Order ) {
			return;
		}
		if ( ! $email || ! in_array( $email->id, array( 'customer_completed_order', 'customer_processing_order' ), true ) ) {
			return;
		}
		$lines     = self::order_lines( $order );
		$resellers = self::order_resellers( $order );
		if ( ! $lines && ! $resellers ) {
			return;
		}
		if ( $plain_text ) {
			if ( $resellers ) {
				self::render_reseller_text( $resellers );
			}
			if ( ! $lines ) {
				return;
			}
			echo "\n" . esc_html__( 'Your IPTV subscription', 'xtreampro' ) . "\n";
			foreach ( $lines as $line ) {
				echo "\n" . esc_html( $line['name'] ) . "\n";
				foreach ( self::rows_with_login( $line ) as $row ) {
					echo esc_html( $row[0] ) . ': ' . ( 'url' === $row[2] ? esc_url_raw( $row[1] ) : esc_html( $row[1] ) ) . "\n";
				}
			}
			echo "\n";
			return;
		}
		if ( $lines ) {
			self::render_html( $lines, true );
		}
		if ( $resellers ) {
			self::render_reseller_html( $resellers, true );
		}
	}

	/**
	 * Order-received / view-order page.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function page_credentials( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$lines     = self::order_lines( $order );
		$resellers = self::order_resellers( $order );
		if ( $lines || $resellers ) {
			wp_enqueue_style( 'xtreampro' );
		}
		if ( $lines ) {
			self::render_html( $lines, false );
		}
		if ( $resellers ) {
			self::render_reseller_html( $resellers, false );
		}
	}

	/**
	 * What a customer is shown about one line: server, username, password, then the
	 * play links (the ones the panel returned; assembled only for a line without any).
	 *
	 * @param array $line {id, username, password, links}.
	 * @return array[] Rows of {label, value, 'text'|'url'}.
	 */
	private static function rows_with_login( array $line ) {
		$rows = array();
		$play = array();
		foreach ( XtreamPro_API::link_rows( $line['links'], $line['username'], $line['password'] ) as $row ) {
			if ( 'server' === $row['key'] ) {
				$rows[] = array( $row['label'], $row['url'], 'text' );
			} else {
				$play[] = array( $row['label'], $row['url'], 'url' );
			}
		}
		$rows[] = array( __( 'Username', 'xtreampro' ), $line['username'], 'text' );
		$rows[] = array( __( 'Password', 'xtreampro' ), $line['password'], 'text' );
		return array_merge( $rows, $play );
	}

	/**
	 * @param array[] $lines  Lines.
	 * @param bool    $inline Use inline styles (emails ignore the stylesheet).
	 */
	private static function render_html( array $lines, $inline ) {
		$cell = $inline ? ' style="text-align:left;padding:8px;border:1px solid #e5e5e5;"' : '';
		echo '<section class="xtreampro-credentials"><h2' . ( $inline ? ' style="margin:24px 0 8px;"' : '' ) . '>' . esc_html__( 'Your IPTV subscription', 'xtreampro' ) . '</h2>';
		foreach ( $lines as $line ) {
			echo '<table class="shop_table" cellspacing="0" cellpadding="6"' . ( $inline ? ' style="width:100%;border-collapse:collapse;margin-bottom:12px;"' : '' ) . '><tbody>';
			echo '<tr><th' . $cell . ' colspan="2">' . esc_html( $line['name'] ) . ' (#' . esc_html( (string) $line['id'] ) . ')</th></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $cell is a static string.
			foreach ( self::rows_with_login( $line ) as $row ) {
				$value = 'url' === $row[2] ? '<a href="' . esc_url( $row[1] ) . '">' . esc_html( $row[1] ) . '</a>' : esc_html( $row[1] );
				echo '<tr><th' . $cell . '>' . esc_html( $row[0] ) . '</th><td' . $cell . '>' . $value . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- both parts are escaped above.
			}
			echo '</tbody></table>';
		}
		echo '</section>';
	}
}
