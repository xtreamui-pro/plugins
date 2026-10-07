<?php
/**
 * SureCart integration: sell IPTV lines and sub-reseller accounts / credits.
 *
 * SureCart keeps products and orders on its own hosted backend, so nothing here is a WordPress
 * post. The shop owner attaches the "Xtream UI Pro" integration to a product (or one of its
 * prices) in SureCart's product editor and picks what it delivers (the items below).
 * SureCart calls onPurchaseCreated / onPurchaseInvoked / onPurchaseRevoked for the WordPress user
 * linked to the customer of the purchase; `surecart/subscription_renewed` carries renewals.
 *
 * What a purchase provisioned is kept in the option `xtreampro_sc_p_<purchase id>`; request ids are
 * `sc-<purchase>-<n>`, `sc-sub-<purchase>-<n>`, `sc-subc-<purchase>-<n>`..., so nothing is ever created
 * or credited twice. Lines and the reseller account are remembered in the same user meta as the
 * WooCommerce / EDD integrations, so [xtreampro_my_lines] and [xtreampro_my_reseller] list them.
 *
 * Item ids stored by SureCart in the integration: `line:<package id>`, `trial:<package id>`,
 * `sub:<credits>`.
 *
 * @package XtreamPro
 */

defined( 'ABSPATH' ) || exit;

class XtreamPro_SureCart extends \SureCart\Integrations\IntegrationService implements \SureCart\Integrations\Contracts\IntegrationInterface, \SureCart\Integrations\Contracts\PurchaseSyncInterface {

	const SLUG            = 'xtreampro/panel';
	const RECORD_PREFIX   = 'xtreampro_sc_p_';
	const PROBLEMS_OPTION = 'xtreampro_sc_problems';
	const MAX_PROBLEMS    = 50;
	const MAX_CREDITS     = 1000000;
	/** A renewal event this soon after the lines were created is the first payment, not a renewal. */
	const INITIAL_WINDOW = 900;

	/** @var XtreamPro_SureCart|null */
	private static $instance = null;

	public static function init() {
		if ( null !== self::$instance ) {
			return;
		}
		self::$instance = new self();
		// Registers the provider, its item list and the purchase_* actions with SureCart.
		self::$instance->bootstrap();
		add_action( 'surecart/subscription_renewed', array( self::$instance, 'on_subscription_renewed' ), 10, 1 );
		add_action( 'admin_post_xtreampro_sc_retry', array( self::$instance, 'handle_retry' ) );
		add_action( 'xtreampro_settings_page', array( self::$instance, 'render_problems' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Integration description (what SureCart's product editor shows)      */
	/* ------------------------------------------------------------------ */

	public function getName() {
		return self::SLUG;
	}

	public function getModel() {
		return 'product';
	}

	public function getLogo() {
		return '';
	}

	public function getLabel() {
		return __( 'Xtream UI Pro', 'xtreampro' );
	}

	public function getItemLabel() {
		return __( 'IPTV line or reseller account', 'xtreampro' );
	}

	public function getItemHelp() {
		return __( 'Create an IPTV line or a sub-reseller account (with credits) on the Xtream UI Pro panel for the buyer.', 'xtreampro' );
	}

	/**
	 * Credit amounts offered for "Sub-reseller account" items.
	 *
	 * Filter `xtreampro_surecart_credit_amounts` (array of positive integers) changes the list:
	 * add_filter( 'xtreampro_surecart_credit_amounts', fn() => array( 20, 100, 500 ) );
	 * An item already attached to a product keeps working after its amount is removed from the list.
	 *
	 * @return int[]
	 */
	public static function credit_amounts() {
		$list = apply_filters( 'xtreampro_surecart_credit_amounts', array( 10, 25, 50, 100, 250, 500, 1000 ) );
		$out  = array();
		foreach ( (array) $list as $n ) {
			$n = absint( $n );
			if ( $n > 0 && $n <= self::MAX_CREDITS ) {
				$out[ $n ] = $n;
			}
		}
		ksort( $out );
		return array_values( $out );
	}

	/**
	 * Decode an item id.
	 *
	 * @param mixed $id Item id.
	 * @return array{kind:string,package_id:int,credits:int}|null
	 */
	public static function parse_item( $id ) {
		$id = (string) $id;
		if ( preg_match( '/^(line|trial):([0-9]{1,9})$/', $id, $m ) && (int) $m[2] > 0 ) {
			return array(
				'kind'       => $m[1],
				'package_id' => (int) $m[2],
				'credits'    => 0,
			);
		}
		if ( preg_match( '/^sub:([0-9]{1,7})$/', $id, $m ) && (int) $m[1] > 0 && (int) $m[1] <= self::MAX_CREDITS ) {
			return array(
				'kind'       => 'sub',
				'package_id' => 0,
				'credits'    => (int) $m[1],
			);
		}
		return null;
	}

	/**
	 * The items a product can deliver, as SureCart's item picker lists them.
	 *
	 * @param array  $items  Items so far.
	 * @param string $search Search text.
	 * @return array
	 */
	public function getItems( $items = array(), $search = '' ) {
		$out      = array();
		$packages = XtreamPro_API::is_configured() ? XtreamPro_API::line_packages() : array();
		if ( is_array( $packages ) ) {
			foreach ( $packages as $pkg ) {
				$name = isset( $pkg['name'] ) ? (string) $pkg['name'] : '#' . (int) $pkg['id'];
				if ( ! empty( $pkg['is_official'] ) && '0' !== $pkg['is_official'] ) {
					/* translators: %s: package name */
					$out[] = (object) array(
						'id'    => 'line:' . (int) $pkg['id'],
						'label' => sprintf( __( 'IPTV line: %s', 'xtreampro' ), $name ),
					);
				}
				if ( ! empty( $pkg['is_trial'] ) && '0' !== $pkg['is_trial'] ) {
					$out[] = (object) array(
						'id'    => 'trial:' . (int) $pkg['id'],
						/* translators: %s: package name */
						'label' => sprintf( __( 'IPTV trial line: %s', 'xtreampro' ), $name ),
					);
				}
			}
		}
		foreach ( self::credit_amounts() as $credits ) {
			$out[] = (object) array(
				'id'    => 'sub:' . $credits,
				/* translators: %s: number of credits */
				'label' => sprintf( __( 'Sub-reseller account: %s credits', 'xtreampro' ), number_format_i18n( $credits ) ),
			);
		}
		$search = trim( (string) $search );
		if ( '' !== $search ) {
			$out = array_values(
				array_filter(
					$out,
					function ( $item ) use ( $search ) {
						return false !== stripos( $item->label, $search );
					}
				)
			);
		}
		return $out;
	}

	/**
	 * @param string $id Item id.
	 * @return object|array
	 */
	public function getItem( $id ) {
		foreach ( $this->getItems() as $item ) {
			if ( $item->id === (string) $id ) {
				return (object) array(
					'id'             => $item->id,
					'provider_label' => $this->getLabel(),
					'label'          => $item->label,
				);
			}
		}
		return array();
	}

	/**
	 * Only items this integration offers can be saved. Credit amounts may be any number up to the
	 * limit when it is in the filtered list.
	 *
	 * @param string $id Item id.
	 * @return bool
	 */
	public function isValidItem( $id ): bool {
		$item = self::parse_item( $id );
		if ( ! $item ) {
			return false;
		}
		if ( 'sub' === $item['kind'] ) {
			return in_array( $item['credits'], self::credit_amounts(), true );
		}
		foreach ( $this->getItems() as $offered ) {
			if ( $offered->id === (string) $id ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------ */
	/* Purchase events                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * @param \SureCart\Models\Integration $integration Integration row (product -> item).
	 * @param \WP_User                     $wp_user     Buyer.
	 * @param \SureCart\Models\Purchase    $purchase    Passed by SureCart's dispatcher as third argument.
	 */
	public function onPurchaseCreated( $integration, $wp_user, $purchase = null ) {
		$this->run( 'provision', $integration, $wp_user, $purchase );
	}

	public function onPurchaseInvoked( $integration, $wp_user, $purchase = null ) {
		$this->run( 'invoke', $integration, $wp_user, $purchase );
	}

	public function onPurchaseRevoked( $integration, $wp_user, $purchase = null ) {
		$this->run( 'revoke', $integration, $wp_user, $purchase );
	}

	/**
	 * Runs one integration row of a purchase. Never throws: SureCart does not isolate integrations
	 * from each other, so a failure here is recorded and shown on the settings page instead.
	 *
	 * @param string $mode        provision, invoke or revoke.
	 * @param object $integration Integration row.
	 * @param object $wp_user     Buyer.
	 * @param object $purchase    Purchase.
	 */
	private function run( $mode, $integration, $wp_user, $purchase ) {
		$pid = '';
		$iid = is_object( $integration ) && isset( $integration->id ) ? (string) $integration->id : '';
		$uid = is_object( $wp_user ) && ! empty( $wp_user->ID ) ? (int) $wp_user->ID : 0;
		try {
			if ( null === $purchase ) {
				$purchase = $this->getPurchase();
			}
			$pid = is_object( $purchase ) && isset( $purchase->id ) ? (string) $purchase->id : '';
			if ( '' === $pid || '' === $iid ) {
				return;
			}
			$this->clear_problem( $pid, $iid );
			if ( $uid <= 0 ) {
				$this->problem( $pid, $uid, $iid, __( 'The customer of this purchase has no WordPress account.', 'xtreampro' ) );
				return;
			}
			$item = self::parse_item( isset( $integration->integration_id ) ? $integration->integration_id : '' );
			if ( ! $item ) {
				$this->problem( $pid, $uid, $iid, __( 'The item chosen in the product\'s Xtream UI Pro integration is not valid. Edit the integration.', 'xtreampro' ) );
				return;
			}
			if ( ! XtreamPro_API::is_configured() ) {
				$this->problem( $pid, $uid, $iid, __( 'Xtream UI Pro is not configured. Set the API URL and API key in Settings → Xtream UI Pro.', 'xtreampro' ) );
				return;
			}

			$lock = 'xtreampro_sc_lock_' . md5( $pid );
			if ( get_transient( $lock ) ) {
				$this->problem( $pid, $uid, $iid, __( 'Another update of this purchase was running. Press retry.', 'xtreampro' ) );
				return;
			}
			set_transient( $lock, 1, 2 * MINUTE_IN_SECONDS );
			try {
				$ctx = array(
					'pid'     => $pid,
					'iid'     => $iid,
					'uid'     => $uid,
					'user'    => $wp_user,
					'item'    => $item,
					'qty'     => max( 1, isset( $purchase->quantity ) ? (int) $purchase->quantity : 1 ),
				);
				$rec = $this->load( $pid, $uid );
				if ( 'revoke' === $mode ) {
					$this->revoke( $rec, $ctx );
				} else {
					if ( 'invoke' === $mode ) {
						$this->reinstate( $rec, $ctx );
					}
					if ( 'sub' === $item['kind'] ) {
						$this->provision_sub( $rec, $ctx );
					} else {
						$this->provision_lines( $rec, $ctx );
					}
				}
			} finally {
				delete_transient( $lock );
			}
			$this->send_credentials( $pid, $wp_user );
		} catch ( \Throwable $e ) {
			if ( '' !== $pid ) {
				/* translators: %s: error message */
				$this->problem( $pid, $uid, $iid, sprintf( __( 'Unexpected error: %s', 'xtreampro' ), $e->getMessage() ) );
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Purchase record                                                     */
	/* ------------------------------------------------------------------ */

	private static function record_name( $pid ) {
		return self::RECORD_PREFIX . preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $pid );
	}

	/**
	 * What the purchase provisioned: lines[] and subs[integration id].
	 *
	 * @param string $pid Purchase id.
	 * @param int    $uid WordPress user id.
	 * @return array
	 */
	private function load( $pid, $uid = 0 ) {
		$rec = get_option( self::record_name( $pid ), array() );
		if ( ! is_array( $rec ) ) {
			$rec = array();
		}
		$rec = array_merge(
			array(
				'purchase' => $pid,
				'user'     => $uid,
				'lines'    => array(),
				'subs'     => array(),
			),
			$rec
		);
		if ( $uid > 0 && empty( $rec['user'] ) ) {
			$rec['user'] = $uid;
		}
		return $rec;
	}

	private function save( $pid, array $rec ) {
		update_option( self::record_name( $pid ), $rec, false );
	}

	/* ------------------------------------------------------------------ */
	/* Lines                                                               */
	/* ------------------------------------------------------------------ */

	private function package_label( $package_id ) {
		$pkg = XtreamPro_API::find_package( $package_id );
		return $pkg && isset( $pkg['name'] ) ? (string) $pkg['name'] : '#' . (int) $package_id;
	}

	/**
	 * One line per purchased unit; what exists already is kept.
	 *
	 * @param array $rec Purchase record (by reference).
	 * @param array $ctx Context of run().
	 */
	private function provision_lines( array &$rec, array $ctx ) {
		$have = 0;
		foreach ( $rec['lines'] as $line ) {
			if ( (string) $line['i'] === $ctx['iid'] ) {
				$have++;
			}
		}
		if ( $have < $ctx['qty'] ) {
			// Nothing is bought when the package is not on sale or the credits cannot pay for all the units.
			$short = XtreamPro_API::check_credits(
				array(
					array(
						'kind'       => 'line',
						'package_id' => $ctx['item']['package_id'],
						'trial'      => 'trial' === $ctx['item']['kind'],
						'units'      => $ctx['qty'] - $have,
					),
				)
			);
			if ( is_wp_error( $short ) ) {
				/* translators: %s: error message */
				$this->problem( $ctx['pid'], $ctx['uid'], $ctx['iid'], sprintf( __( 'Could not create the line: %s', 'xtreampro' ), $short->get_error_message() ) );
				return;
			}
		}
		for ( $unit = $have + 1; $unit <= $ctx['qty']; $unit++ ) {
			// The request id numbers every line of the purchase, whichever integration made it.
			$n    = count( $rec['lines'] ) + 1;
			$data = XtreamPro_API::create_line( $ctx['item']['package_id'], 'trial' === $ctx['item']['kind'], 'sc-' . $ctx['pid'] . '-' . $n );
			if ( is_wp_error( $data ) ) {
				/* translators: %s: error message */
				$this->problem( $ctx['pid'], $ctx['uid'], $ctx['iid'], sprintf( __( 'Could not create the line: %s', 'xtreampro' ), $data->get_error_message() ) );
				return;
			}
			$cred = XtreamPro_API::credentials( $data );
			if ( $cred['id'] <= 0 ) {
				$this->problem( $ctx['pid'], $ctx['uid'], $ctx['iid'], __( 'The panel returned no line.', 'xtreampro' ) );
				return;
			}
			$rec['lines'][] = array(
				'i'        => $ctx['iid'],
				'n'        => $n,
				'id'       => $cred['id'],
				'username' => $cred['username'],
				'password' => $cred['password'],
				'label'    => $this->package_label( $ctx['item']['package_id'] ),
				'trial'    => 'trial' === $ctx['item']['kind'],
				'disabled' => false,
				'mailed'   => false,
				'at'       => time(),
				'periods'  => array(),
			);
			$this->save( $ctx['pid'], $rec );
			// Product and order columns of the shared user meta are numeric; SureCart ids are not.
			XtreamPro_API::remember_line( $ctx['uid'], $cred['id'], 0, 0 );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Sub-reseller account                                                */
	/* ------------------------------------------------------------------ */

	private static function random_chars( $length ) {
		$alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
		$out      = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$out .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
		}
		return $out;
	}

	/**
	 * Panel username (3-32 chars of letters, digits and _ . -) from the WordPress login.
	 *
	 * @param object $wp_user Buyer.
	 * @param int    $uid     User id.
	 * @return string
	 */
	private static function generate_username( $wp_user, $uid ) {
		$base = preg_replace( '/[^A-Za-z0-9_.-]/', '', isset( $wp_user->user_login ) ? (string) $wp_user->user_login : '' );
		if ( '' === $base ) {
			$base = 'r' . (int) $uid;
		}
		return substr( substr( $base, 0, 27 ) . self::random_chars( 4 ), 0, 32 );
	}

	private static function sub_template() {
		return array(
			'seq'      => 0,
			'sub_id'   => '',
			'username' => '',
			'password' => '',
			'created'  => false,
			'amount'   => 0,
			'granted'  => false,
			'credited' => 0,
			'added'    => 0,
			'gen'      => 0,
			'disabled' => false,
			'mailed'   => false,
			'at'       => 0,
			'periods'  => array(),
		);
	}

	/**
	 * Give the credits of a sub item (initial grant or re-grant after a revoke).
	 *
	 * @param array $sub Sub record (by reference).
	 * @param array $ctx Context.
	 * @return bool Whether the credits are held now.
	 */
	private function grant( array &$sub, array $ctx ) {
		if ( $sub['granted'] || $sub['amount'] <= 0 ) {
			return $sub['granted'];
		}
		$rid = 'sc-subc-' . $ctx['pid'] . '-' . $sub['seq'] . ( $sub['gen'] > 0 ? '-' . $sub['gen'] : '' );
		$res = XtreamPro_API::adjust_credits( $sub['sub_id'], $sub['amount'], 'SureCart purchase ' . $ctx['pid'], $rid );
		if ( is_wp_error( $res ) ) {
			/* translators: 1: credits, 2: error message */
			$this->problem( $ctx['pid'], $ctx['uid'], $ctx['iid'], sprintf( __( 'Could not give %1$d credits: %2$s', 'xtreampro' ), $sub['amount'], $res->get_error_message() ) );
			return false;
		}
		$sub['granted']  = true;
		$sub['credited'] = $sub['amount'];
		$sub['added']    = $sub['amount'];
		$sub['mailed']   = false;
		delete_transient( 'xtreampro_rs_' . $ctx['uid'] );
		return true;
	}

	/**
	 * First sub item of a customer creates the reseller account; every item hands over its credits.
	 *
	 * @param array $rec Purchase record (by reference).
	 * @param array $ctx Context of run().
	 */
	private function provision_sub( array &$rec, array $ctx ) {
		$iid = $ctx['iid'];
		$sub = isset( $rec['subs'][ $iid ] ) ? array_merge( self::sub_template(), $rec['subs'][ $iid ] ) : self::sub_template();
		if ( $sub['seq'] <= 0 ) {
			$sub['seq']    = count( $rec['subs'] ) + 1;
			$sub['amount'] = $ctx['item']['credits'] * $ctx['qty'];
			$sub['at']     = time();
		}

		if ( '' === $sub['sub_id'] ) {
			$account = XtreamPro_API::user_reseller( $ctx['uid'] );
			if ( $account ) {
				// The customer already has an account: this purchase only tops it up.
				$sub['sub_id']   = $account['user_id'];
				$sub['username'] = $account['username'];
				$sub['created']  = false;
			}
		}

		if ( '' === $sub['sub_id'] ) {
			// Credentials are stored before the call so a retry sends the same ones.
			if ( empty( $sub['pending_user'] ) || empty( $sub['pending_pass'] ) ) {
				$sub['pending_user'] = self::generate_username( $ctx['user'], $ctx['uid'] );
				$sub['pending_pass'] = wp_generate_password( 14, false );
				$rec['subs'][ $iid ] = $sub;
				$this->save( $ctx['pid'], $rec );
			}
			$fullname = isset( $ctx['user']->display_name ) ? trim( (string) $ctx['user']->display_name ) : '';
			if ( '' === $fullname ) {
				$fullname = $sub['pending_user'];
			}
			$email = isset( $ctx['user']->user_email ) ? (string) $ctx['user']->user_email : '';
			$data  = XtreamPro_API::create_sub_user( $sub['pending_user'], $sub['pending_pass'], $email, mb_substr( $fullname, 0, 128 ), 'sc-sub-' . $ctx['pid'] . '-' . $sub['seq'] );
			if ( is_wp_error( $data ) ) {
				/* translators: %s: error message */
				$this->problem( $ctx['pid'], $ctx['uid'], $iid, sprintf( __( 'Could not create the reseller account: %s', 'xtreampro' ), $data->get_error_message() ) );
				return;
			}
			$created = isset( $data['user'] ) && is_array( $data['user'] ) ? $data['user'] : array();
			if ( empty( $created['id'] ) ) {
				$this->problem( $ctx['pid'], $ctx['uid'], $iid, __( 'The panel returned no account.', 'xtreampro' ) );
				return;
			}
			$sub['sub_id']   = (string) $created['id'];
			$sub['username'] = ! empty( $created['username'] ) ? (string) $created['username'] : $sub['pending_user'];
			$sub['password'] = ! empty( $data['password'] ) ? (string) $data['password'] : $sub['pending_pass'];
			$sub['created']  = true;
			$sub['mailed']   = false;
			unset( $sub['pending_user'], $sub['pending_pass'] );
			$rec['subs'][ $iid ] = $sub;
			$this->save( $ctx['pid'], $rec );
			XtreamPro_API::remember_reseller( $ctx['uid'], $sub['sub_id'], $sub['username'], 0 );
			delete_transient( 'xtreampro_rs_' . $ctx['uid'] );
		}

		// The initial grant happens once; after a revoke only onPurchaseInvoked gives credits again.
		if ( 0 === $sub['gen'] ) {
			$this->grant( $sub, $ctx );
		}
		$rec['subs'][ $iid ] = $sub;
		$this->save( $ctx['pid'], $rec );
	}

	/* ------------------------------------------------------------------ */
	/* Revoke / invoke                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Refund, cancelled subscription: disable (never delete) this integration's lines, take its
	 * credits back and disable the account the purchase created.
	 *
	 * @param array $rec Purchase record (by reference).
	 * @param array $ctx Context.
	 */
	private function revoke( array &$rec, array $ctx ) {
		$iid = $ctx['iid'];
		foreach ( $rec['lines'] as $k => $line ) {
			if ( (string) $line['i'] !== $iid || ! empty( $line['disabled'] ) ) {
				continue;
			}
			$res = XtreamPro_API::set_line_state( $line['id'], 'disable' );
			if ( is_wp_error( $res ) ) {
				/* translators: 1: line id, 2: error message */
				$this->problem( $ctx['pid'], $ctx['uid'], $iid, sprintf( __( 'Could not disable line #%1$d: %2$s', 'xtreampro' ), $line['id'], $res->get_error_message() ) );
				continue;
			}
			$rec['lines'][ $k ]['disabled'] = true;
			$this->save( $ctx['pid'], $rec );
		}

		if ( ! isset( $rec['subs'][ $iid ] ) ) {
			return;
		}
		$sub = array_merge( self::sub_template(), $rec['subs'][ $iid ] );
		if ( '' === $sub['sub_id'] ) {
			return;
		}
		if ( $sub['granted'] && $sub['credited'] > 0 ) {
			$res = XtreamPro_API::adjust_credits( $sub['sub_id'], -$sub['credited'], 'SureCart purchase ' . $ctx['pid'] . ' revoked', 'sc-subx-' . $ctx['pid'] . '-' . $sub['seq'] . '-' . $sub['gen'] );
			if ( is_wp_error( $res ) ) {
				/* translators: 1: credits, 2: error message */
				$this->problem( $ctx['pid'], $ctx['uid'], $iid, sprintf( __( 'Could not take back %1$d credits (they may already be spent): %2$s', 'xtreampro' ), $sub['credited'], $res->get_error_message() ) );
			} else {
				$sub['granted']  = false;
				$sub['credited'] = 0;
				$sub['gen']++;
				delete_transient( 'xtreampro_rs_' . $ctx['uid'] );
			}
		}
		if ( $sub['created'] && ! $sub['disabled'] ) {
			$res = XtreamPro_API::set_sub_user_state( $sub['sub_id'], 'disable' );
			if ( is_wp_error( $res ) ) {
				/* translators: %s: error message */
				$this->problem( $ctx['pid'], $ctx['uid'], $iid, sprintf( __( 'Could not disable the reseller account: %s', 'xtreampro' ), $res->get_error_message() ) );
			} else {
				$sub['disabled'] = true;
				delete_transient( 'xtreampro_rs_' . $ctx['uid'] );
			}
		}
		$rec['subs'][ $iid ] = $sub;
		$this->save( $ctx['pid'], $rec );
	}

	/**
	 * Purchase invoked again after a revoke: enable what was disabled and give the credits back.
	 *
	 * @param array $rec Purchase record (by reference).
	 * @param array $ctx Context.
	 */
	private function reinstate( array &$rec, array $ctx ) {
		$iid = $ctx['iid'];
		foreach ( $rec['lines'] as $k => $line ) {
			if ( (string) $line['i'] !== $iid || empty( $line['disabled'] ) ) {
				continue;
			}
			$res = XtreamPro_API::set_line_state( $line['id'], 'enable' );
			if ( is_wp_error( $res ) ) {
				/* translators: 1: line id, 2: error message */
				$this->problem( $ctx['pid'], $ctx['uid'], $iid, sprintf( __( 'Could not enable line #%1$d: %2$s', 'xtreampro' ), $line['id'], $res->get_error_message() ) );
				continue;
			}
			$rec['lines'][ $k ]['disabled'] = false;
			$this->save( $ctx['pid'], $rec );
		}

		if ( ! isset( $rec['subs'][ $iid ] ) ) {
			return;
		}
		$sub = array_merge( self::sub_template(), $rec['subs'][ $iid ] );
		if ( $sub['created'] && $sub['disabled'] ) {
			$res = XtreamPro_API::set_sub_user_state( $sub['sub_id'], 'enable' );
			if ( is_wp_error( $res ) ) {
				/* translators: %s: error message */
				$this->problem( $ctx['pid'], $ctx['uid'], $iid, sprintf( __( 'Could not enable the reseller account: %s', 'xtreampro' ), $res->get_error_message() ) );
			} else {
				$sub['disabled'] = false;
				delete_transient( 'xtreampro_rs_' . $ctx['uid'] );
			}
		}
		if ( $sub['gen'] > 0 && ! $sub['granted'] && '' !== $sub['sub_id'] ) {
			$this->grant( $sub, $ctx );
		}
		$rec['subs'][ $iid ] = $sub;
		$this->save( $ctx['pid'], $rec );
	}

	/* ------------------------------------------------------------------ */
	/* Renewals                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * `surecart/subscription_renewed` (the webhook subscription.renewed; one argument, the
	 * subscription, whose `purchase` is the purchase id). Renews the purchase's lines and tops up
	 * its credits, once per billing period.
	 *
	 * @param object $subscription SureCart subscription.
	 */
	public function on_subscription_renewed( $subscription ) {
		$pid = '';
		try {
			$p   = is_object( $subscription ) && isset( $subscription->purchase ) ? $subscription->purchase : '';
			$pid = is_object( $p ) ? ( isset( $p->id ) ? (string) $p->id : '' ) : (string) $p;
			if ( '' === $pid ) {
				return;
			}
			$stored = get_option( self::record_name( $pid ), null );
			if ( ! is_array( $stored ) ) {
				return; // Not a purchase of ours.
			}
			$rec = $this->load( $pid );
			$uid = (int) $rec['user'];

			$cp     = isset( $subscription->current_period ) ? $subscription->current_period : '';
			$period = is_object( $cp ) ? ( isset( $cp->id ) ? (string) $cp->id : '' ) : (string) $cp;
			if ( '' === $period && isset( $subscription->current_period_end_at ) ) {
				$period = (string) $subscription->current_period_end_at;
			}
			if ( '' === $period ) {
				$this->problem( $pid, $uid, 'renewal', __( 'A subscription renewal arrived without a billing period; nothing was renewed. Renew the lines in the panel.', 'xtreampro' ) );
				return;
			}
			if ( ! XtreamPro_API::is_configured() ) {
				$this->problem( $pid, $uid, 'renewal', __( 'Xtream UI Pro is not configured. Set the API URL and API key in Settings → Xtream UI Pro.', 'xtreampro' ) );
				return;
			}
			$lock = 'xtreampro_sc_lock_' . md5( $pid );
			if ( get_transient( $lock ) ) {
				$this->problem( $pid, $uid, 'renewal', __( 'Another update of this purchase was running while it renewed. Renew the lines in the panel.', 'xtreampro' ) );
				return;
			}
			set_transient( $lock, 1, 2 * MINUTE_IN_SECONDS );
			try {
				$this->renew_record( $rec, $pid, $uid, substr( md5( $period ), 0, 8 ) );
			} finally {
				delete_transient( $lock );
			}
		} catch ( \Throwable $e ) {
			if ( '' !== $pid ) {
				/* translators: %s: error message */
				$this->problem( $pid, 0, 'renewal', sprintf( __( 'Unexpected error: %s', 'xtreampro' ), $e->getMessage() ) );
			}
		}
	}

	/**
	 * @param array  $rec    Purchase record (by reference).
	 * @param string $pid    Purchase id.
	 * @param int    $uid    WordPress user id.
	 * @param string $period Short hash of the billing period.
	 */
	private function renew_record( array &$rec, $pid, $uid, $period ) {
		$failed = false;
		foreach ( $rec['lines'] as $k => $line ) {
			if ( ! empty( $line['disabled'] ) || ! empty( $line['trial'] ) || in_array( $period, (array) $line['periods'], true ) ) {
				continue;
			}
			if ( time() - (int) $line['at'] < self::INITIAL_WINDOW ) {
				// The first payment created this line; it is not a renewal.
				$rec['lines'][ $k ]['periods'][] = $period;
				continue;
			}
			$res = XtreamPro_API::renew_line( $line['id'], 'sc-ren-' . $pid . '-' . $period . '-' . $line['n'] );
			if ( is_wp_error( $res ) ) {
				/* translators: 1: line id, 2: error message */
				$this->problem( $pid, $uid, 'renewal', sprintf( __( 'Could not renew line #%1$d: %2$s', 'xtreampro' ), $line['id'], $res->get_error_message() ) );
				$failed = true;
				continue;
			}
			$rec['lines'][ $k ]['periods']   = array_slice( array_merge( (array) $line['periods'], array( $period ) ), -24 );
			$this->save( $pid, $rec );
		}
		foreach ( $rec['subs'] as $iid => $sub ) {
			$sub = array_merge( self::sub_template(), $sub );
			if ( ! $sub['granted'] || $sub['amount'] <= 0 || '' === $sub['sub_id'] || in_array( $period, (array) $sub['periods'], true ) ) {
				continue;
			}
			if ( time() - (int) $sub['at'] < self::INITIAL_WINDOW ) {
				// The first payment gave these credits; it is not a renewal.
				$rec['subs'][ $iid ]['periods'][] = $period;
				continue;
			}
			$res = XtreamPro_API::adjust_credits( $sub['sub_id'], $sub['amount'], 'SureCart purchase ' . $pid . ' renewed', 'sc-subr-' . $pid . '-' . $period . '-' . $sub['seq'] );
			if ( is_wp_error( $res ) ) {
				/* translators: 1: credits, 2: error message */
				$this->problem( $pid, $uid, 'renewal', sprintf( __( 'Could not give %1$d credits for the renewal: %2$s', 'xtreampro' ), $sub['amount'], $res->get_error_message() ) );
				$failed = true;
				continue;
			}
			$sub['credited']    += $sub['amount'];
			$sub['periods']      = array_slice( array_merge( (array) $sub['periods'], array( $period ) ), -24 );
			$rec['subs'][ $iid ] = $sub;
			$this->save( $pid, $rec );
			delete_transient( 'xtreampro_rs_' . $uid );
		}
		$this->save( $pid, $rec );
		if ( ! $failed ) {
			$this->clear_problem( $pid, 'renewal' );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Email to the buyer                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Mail the lines / reseller account that were not mailed yet to the buyer, and to nobody else.
	 * SureCart's own emails and customer dashboard are rendered by SureCart; they are not touched.
	 *
	 * @param string $pid     Purchase id.
	 * @param object $wp_user Buyer.
	 */
	private function send_credentials( $pid, $wp_user ) {
		$rec   = $this->load( $pid );
		$lines = array();
		$subs  = array();
		foreach ( $rec['lines'] as $k => $line ) {
			if ( empty( $line['mailed'] ) && empty( $line['disabled'] ) ) {
				$lines[ $k ] = $line;
			}
		}
		foreach ( $rec['subs'] as $iid => $sub ) {
			$sub = array_merge( self::sub_template(), $sub );
			if ( ! $sub['mailed'] && '' !== $sub['sub_id'] && ! $sub['disabled'] && ( $sub['created'] || $sub['added'] > 0 ) ) {
				$subs[ $iid ] = $sub;
			}
		}
		if ( ! $lines && ! $subs ) {
			return;
		}
		$to = isset( $wp_user->user_email ) ? (string) $wp_user->user_email : '';
		if ( ! is_email( $to ) ) {
			$this->problem( $pid, (int) $rec['user'], 'mail', __( 'The buyer has no valid email address; the credentials were not sent.', 'xtreampro' ) );
			return;
		}
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: %s: site name */
		$subject = sprintf( __( 'Your IPTV access from %s', 'xtreampro' ), $site );
		if ( ! wp_mail( $to, $subject, $this->mail_body( $lines, $subs ) ) ) {
			$this->problem( $pid, (int) $rec['user'], 'mail', __( 'The email with the credentials could not be sent. Press retry.', 'xtreampro' ) );
			return;
		}
		$this->clear_problem( $pid, 'mail' );
		foreach ( array_keys( $lines ) as $k ) {
			$rec['lines'][ $k ]['mailed'] = true;
		}
		foreach ( array_keys( $subs ) as $iid ) {
			$rec['subs'][ $iid ]['mailed'] = true;
		}
		$this->save( $pid, $rec );
	}

	/**
	 * Plain-text body.
	 *
	 * @param array $lines Lines to list.
	 * @param array $subs  Reseller accounts to list.
	 * @return string
	 */
	private function mail_body( array $lines, array $subs ) {
		$base  = XtreamPro_API::base_url();
		$login = XtreamPro_API::panel_login_url();
		$out   = __( 'Thank you for your purchase. Here are your sign-in details. Keep this email private.', 'xtreampro' ) . "\n";
		if ( $lines ) {
			$out .= "\n" . __( 'Your IPTV subscription', 'xtreampro' ) . "\n";
			foreach ( $lines as $line ) {
				$out .= "\n" . $line['label'] . "\n";
				$out .= __( 'Server URL', 'xtreampro' ) . ': ' . $base . "\n";
				$out .= __( 'Username', 'xtreampro' ) . ': ' . $line['username'] . "\n";
				$out .= __( 'Password', 'xtreampro' ) . ': ' . $line['password'] . "\n";
				$out .= __( 'Playlist URL', 'xtreampro' ) . ': ' . XtreamPro_API::playlist_url( $line['username'], $line['password'] ) . "\n";
				$out .= __( 'Web player', 'xtreampro' ) . ': ' . XtreamPro_API::player_url() . "\n";
			}
		}
		if ( $subs ) {
			$out .= "\n" . __( 'Your reseller account', 'xtreampro' ) . "\n";
			foreach ( $subs as $sub ) {
				$out .= "\n" . __( 'Username', 'xtreampro' ) . ': ' . $sub['username'] . "\n";
				if ( $sub['created'] && '' !== $sub['password'] ) {
					$out .= __( 'Password', 'xtreampro' ) . ': ' . $sub['password'] . "\n";
				}
				$out .= __( 'Credits added by this purchase', 'xtreampro' ) . ': ' . (int) $sub['added'] . "\n";
				if ( '' !== $login ) {
					$out .= __( 'Sign in', 'xtreampro' ) . ': ' . $login . "\n";
				}
			}
		}
		return $out . "\n" . __( 'You can see your lines and reseller account any time on the pages of this site that show them.', 'xtreampro' ) . "\n";
	}

	/* ------------------------------------------------------------------ */
	/* Problems and retry                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Remember a failure of a purchase (shown on the settings page). Never put a secret in $text.
	 *
	 * @param string $pid  Purchase id.
	 * @param int    $uid  WordPress user id.
	 * @param string $iid  Integration row id, or renewal / mail.
	 * @param string $text Message.
	 */
	private function problem( $pid, $uid, $iid, $text ) {
		$all = get_option( self::PROBLEMS_OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		$row = isset( $all[ $pid ] ) && is_array( $all[ $pid ] ) ? $all[ $pid ] : array( 'msgs' => array() );
		$row['time']         = time();
		$row['user']         = (int) $uid;
		$row['msgs'][ $iid ] = (string) $text;
		unset( $all[ $pid ] );
		$all[ $pid ] = $row;
		$all         = array_slice( $all, -self::MAX_PROBLEMS, null, true );
		update_option( self::PROBLEMS_OPTION, $all, false );
	}

	private function clear_problem( $pid, $iid ) {
		$all = get_option( self::PROBLEMS_OPTION, array() );
		if ( ! is_array( $all ) || ! isset( $all[ $pid ]['msgs'][ $iid ] ) ) {
			return;
		}
		unset( $all[ $pid ]['msgs'][ $iid ] );
		if ( ! $all[ $pid ]['msgs'] ) {
			unset( $all[ $pid ] );
		}
		update_option( self::PROBLEMS_OPTION, $all, false );
	}

	/**
	 * Re-run a purchase with what SureCart says now: provisions it, or revokes it when it is
	 * revoked. Everything already done is skipped (request ids).
	 *
	 * @param string $pid Purchase id.
	 * @return array{ok:bool,message:string}
	 */
	public function retry( $pid ) {
		$pid = (string) $pid;
		try {
			$purchase = \SureCart\Models\Purchase::find( $pid );
			if ( is_wp_error( $purchase ) || ! is_object( $purchase ) ) {
				return self::result( false, __( 'SureCart could not return this purchase. Try again later.', 'xtreampro' ) );
			}
			$user = $purchase->getWPUser();
			if ( empty( $user->ID ) ) {
				return self::result( false, __( 'The customer of this purchase has no WordPress account.', 'xtreampro' ) );
			}
			$this->purchase = $purchase;
			$rows           = $this->getIntegrationData( $purchase );
			$mode           = ! empty( $purchase->revoked ) ? 'revoke' : 'provision';
			$ran            = 0;
			foreach ( is_array( $rows ) ? $rows : array() as $integration ) {
				if ( empty( $integration->id ) || $this->purchaseIsNotMatchedWithPriceOrVariant( $integration, $purchase ) ) {
					continue;
				}
				$this->run( $mode, $integration, $user, $purchase );
				$ran++;
			}
			if ( 0 === $ran ) {
				return self::result( false, __( 'The product of this purchase has no Xtream UI Pro integration (any more).', 'xtreampro' ) );
			}
		} catch ( \Throwable $e ) {
			/* translators: %s: error message */
			return self::result( false, sprintf( __( 'Unexpected error: %s', 'xtreampro' ), $e->getMessage() ) );
		}
		$all = get_option( self::PROBLEMS_OPTION, array() );
		return isset( $all[ $pid ] ) ? self::result( false, __( 'Retried, but there is still a problem. See the list.', 'xtreampro' ) ) : self::result( true, __( 'Done. The purchase is provisioned.', 'xtreampro' ) );
	}

	private static function result( $ok, $message ) {
		return array(
			'ok'      => $ok,
			'message' => $message,
		);
	}

	public function handle_retry() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked right below.
		$pid = isset( $_GET['purchase_id'] ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', sanitize_text_field( wp_unslash( $_GET['purchase_id'] ) ) ) : '';
		check_admin_referer( 'xtreampro_sc_retry_' . $pid );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'xtreampro' ), '', array( 'response' => 403 ) );
		}
		$result = '' === $pid ? self::result( false, __( 'No purchase given.', 'xtreampro' ) ) : $this->retry( $pid );
		set_transient( 'xtreampro_result_' . get_current_user_id(), $result, MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( 'xtreampro_notice', 'surecart', admin_url( 'options-general.php?page=xtreampro' ) ) );
		exit;
	}

	/**
	 * "Recent problems" table at the end of Settings → Xtream UI Pro.
	 */
	public function render_problems() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$all = get_option( self::PROBLEMS_OPTION, array() );
		if ( ! is_array( $all ) || ! $all ) {
			return;
		}
		echo '<hr /><h2>' . esc_html__( 'Recent SureCart problems', 'xtreampro' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Purchases that could not be fully provisioned. Fix the cause (for example not enough credits), then press Retry: lines and credits are never given twice.', 'xtreampro' ) . '</p>';
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr>';
		echo '<th>' . esc_html__( 'When', 'xtreampro' ) . '</th><th>' . esc_html__( 'Purchase', 'xtreampro' ) . '</th><th>' . esc_html__( 'Customer', 'xtreampro' ) . '</th><th>' . esc_html__( 'Problem', 'xtreampro' ) . '</th><th></th>';
		echo '</tr></thead><tbody>';
		foreach ( array_reverse( $all, true ) as $pid => $row ) {
			$pid  = (string) $pid;
			$user = ! empty( $row['user'] ) ? get_userdata( (int) $row['user'] ) : false;
			$url  = wp_nonce_url( admin_url( 'admin-post.php?action=xtreampro_sc_retry&purchase_id=' . rawurlencode( $pid ) ), 'xtreampro_sc_retry_' . $pid );
			echo '<tr>';
			echo '<td>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $row['time'] ) ) . '</td>';
			echo '<td><code>' . esc_html( $pid ) . '</code></td>';
			echo '<td>' . esc_html( $user && isset( $user->user_login ) ? (string) $user->user_login : '' ) . '</td>';
			echo '<td>' . esc_html( implode( ' ', array_values( (array) $row['msgs'] ) ) ) . '</td>';
			echo '<td><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Retry', 'xtreampro' ) . '</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
}
