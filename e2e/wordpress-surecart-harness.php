<?php
// Harness for the SureCart integration of the WordPress plugin. Not shipped.
//
// SureCart needs an account on its hosted backend, so it is not started. This file runs the REAL
// includes/class-xtreampro-api.php and includes/class-xtreampro-surecart.php against a real panel
// API, with plain-PHP stand-ins for:
//   - the few WordPress functions the two files use (options, user meta, transients, hooks,
//     wp_mail capture, wp_remote_* over curl),
//   - SureCart's IntegrationService / AbstractIntegration / the two interfaces and the Purchase and
//     Integration models. The stand-in IntegrationService copies bootstrap(), callMethod(),
//     getIntegrationsFromPurchase() and purchaseIsNotMatchedWithPriceOrVariant() from SureCart 4.9.2
//     (app/src/Integrations/IntegrationService.php), so events reach the integration the way
//     SureCart's dispatcher delivers them: ($integration, $wp_user, $purchase).
//
//   php plugins/e2e/wordpress-surecart-harness.php
//   env: API_URL (default http://127.0.0.1:18095), API_KEY_FILE (default: the shared test key).
// Prints ALL OK and exits 0 when everything passes.

namespace SureCart\Integrations\Contracts {
	interface IntegrationInterface {
		public function getName();
		public function getModel();
		public function getLogo();
		public function getLabel();
		public function getItemLabel();
		public function getItemHelp();
		public function getItems( $items = [], $search = '' );
		public function getItem( $id );
	}
	interface PurchaseSyncInterface {
		public function onPurchaseCreated( $integration, $wp_user );
		public function onPurchaseInvoked( $integration, $wp_user );
		public function onPurchaseRevoked( $integration, $wp_user );
	}
}

namespace SureCart\Models {
	class Integration {
		public static $rows = array();
		private $filters = array();
		public static function where( $k, $v ) {
			$q = new self();
			$q->filters[ $k ] = $v;
			return $q;
		}
		public function andWhere( $k, $v ) {
			$this->filters[ $k ] = $v;
			return $this;
		}
		public function get() {
			return array_values( array_filter( self::$rows, function ( $r ) {
				foreach ( $this->filters as $k => $v ) {
					if ( $r->$k !== $v ) {
						return false;
					}
				}
				return true;
			} ) );
		}
	}
	class Purchase {
		public static $all = array();
		public $id;
		public $quantity = 1;
		public $product_id;
		public $price = null;
		public $variant = null;
		public $revoked = null;
		public $wp_user;
		public static function find( $id ) {
			return isset( self::$all[ $id ] ) ? self::$all[ $id ] : new \WP_Error( 'not_found', 'Not found' );
		}
		public function getWPUser() {
			return $this->wp_user;
		}
	}
}

namespace SureCart\Integrations {
	use SureCart\Integrations\Contracts\IntegrationInterface;
	use SureCart\Integrations\Contracts\PurchaseSyncInterface;
	use SureCart\Models\Integration;

	abstract class AbstractIntegration {
		public function onPurchaseCreated( $integration, $wp_user ) {
			return new \WP_Error( 'invalid-method', 'not implemented' );
		}
		public function onPurchaseRevoked( $integration, $wp_user ) {
			return new \WP_Error( 'invalid-method', 'not implemented' );
		}
		public function onPurchaseInvoked( $integration, $wp_user ) {
			return new \WP_Error( 'invalid-method', 'not implemented' );
		}
	}

	abstract class IntegrationService extends AbstractIntegration implements IntegrationInterface {
		protected $purchase = null;
		public function getName() { return ''; }
		public function getModel() { return ''; }
		public function getLogo() { return ''; }
		public function getLabel() { return ''; }
		public function getItemLabel() { return ''; }
		public function getItemHelp() { return ''; }
		public function getItems( $items = [], $search = '' ) { return $items; }
		public function getItem( $id ) { return []; }
		public function isValidItem( $id ): bool { return true; }
		public function enabled() { return true; }
		protected $methods_map = [
			'surecart/purchase_created' => 'onPurchaseCreated',
			'surecart/purchase_invoked' => 'onPurchaseInvoked',
			'surecart/purchase_revoked' => 'onPurchaseRevoked',
		];
		public function bootstrap() {
			add_filter( "surecart/integrations/providers/list/{$this->getModel()}", [ $this, 'indexProviders' ], 9 );
			add_filter( "surecart/integrations/providers/find/{$this->getName()}", [ $this, 'findProvider' ], 9 );
			add_filter( "surecart/integrations/providers/{$this->getName()}/{$this->getModel()}/items", [ $this, 'getItems' ], 9, 2 );
			add_filter( "surecart/integrations/providers/{$this->getName()}/item", [ $this, '_getItem' ], 9, 2 );
			add_filter( "surecart/integrations/providers/{$this->getName()}/is_valid_item", [ $this, '_isValidItem' ], 9, 2 );
			if ( is_subclass_of( $this, PurchaseSyncInterface::class ) ) {
				add_action( 'surecart/purchase_created', [ $this, 'callMethod' ], 9 );
				add_action( 'surecart/purchase_invoked', [ $this, 'callMethod' ], 9 );
				add_action( 'surecart/purchase_revoked', [ $this, 'callMethod' ], 9 );
			}
		}
		public function getPurchase() { return $this->purchase; }
		public function getPurchaseId() { return $this->purchase->id ?? null; }
		public function _getItem( $id ) {
			$item       = (object) $this->getItem( $id );
			$item->logo = '';
			return $item;
		}
		public function _isValidItem( $valid, $id ): bool { return $valid && $this->isValidItem( $id ); }
		public function callMethod( $purchase ) {
			$this->purchase = $purchase;
			$method = $this->methods_map[ \current_action() ] ?? null;
			if ( ! $method || ! method_exists( $this, $method ) ) {
				return;
			}
			$integrations = (array) $this->getIntegrationData( $purchase ) ?? [];
			foreach ( $integrations as $integration ) {
				if ( ! $integration->id ) {
					continue;
				}
				if ( $this->purchaseIsNotMatchedWithPriceOrVariant( $integration, $purchase ) ) {
					continue;
				}
				$user = $purchase->getWPUser();
				if ( ! $user ) {
					continue;
				}
				$this->$method( $integration, $purchase->getWPUser(), $purchase );
			}
		}
		public function getIntegrationData( $purchase ) {
			if ( is_wp_error( $purchase ) ) {
				return;
			}
			return $this->getIntegrationsFromPurchase( $purchase );
		}
		public function indexProviders( $list = [] ) {
			$list[] = $this->findProvider();
			return $list;
		}
		public function findProvider() {
			return [ 'name' => $this->getName(), 'label' => $this->getLabel(), 'disabled' => ! $this->enabled(), 'item_label' => $this->getItemLabel(), 'item_help' => $this->getItemHelp() ];
		}
		public function getIntegrationsFromPurchase( $purchase ) {
			$product_id = $purchase->product_id ?? null;
			if ( ! $product_id ) {
				return [];
			}
			return (array) Integration::where( 'model_id', $product_id )->andWhere( 'provider', $this->getName() )->get();
		}
		public function purchaseIsNotMatchedWithPriceOrVariant( $integration, $purchase ): bool {
			$price_id   = $purchase->price->id ?? $purchase->price ?? null;
			$variant_id = $purchase->variant->id ?? $purchase->variant ?? null;
			if ( ( ! empty( $integration->price_id ) && $integration->price_id !== $price_id ) || ( ! empty( $integration->variant_id ) && $integration->variant_id !== $variant_id ) ) {
				return true;
			}
			return false;
		}
	}
}

namespace {

	// ---------------------------------------------------------------- WordPress stand-ins
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'ENT_QUOTES_WP', ENT_QUOTES );

	$logFile = tempnam( sys_get_temp_dir(), 'xc-sc-log' );
	ini_set( 'error_log', $logFile );
	ini_set( 'log_errors', '1' );
	$phpNotices = array();
	set_error_handler( function ( $no, $str, $file, $line ) use ( &$phpNotices ) {
		if ( false !== strpos( $file, 'xtreampro' ) ) {
			$phpNotices[] = "$str ($file:$line)";
		}
		return false;
	} );

	class WP_Error {
		public $code;
		public $message;
		public $data;
		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_message() { return $this->message; }
		public function get_error_code() { return $this->code; }
	}
	class WP_User {
		public $ID;
		public $user_login;
		public $user_email;
		public $display_name;
		public function __construct( $id, $login, $email, $name ) {
			$this->ID = $id; $this->user_login = $login; $this->user_email = $email; $this->display_name = $name;
		}
	}
	class StopRedirect extends \Exception {}
	class StopDie extends \Exception {}

	$GLOBALS['options']    = array();
	$GLOBALS['transients'] = array();
	$GLOBALS['usermeta']   = array();
	$GLOBALS['users']      = array();
	$GLOBALS['hooks']      = array();
	$GLOBALS['actions']    = array();
	$GLOBALS['mails']      = array();
	$GLOBALS['mail_ok']    = true;
	$GLOBALS['can']        = true;
	$GLOBALS['api_calls']  = array();

	function __( $t ) { return $t; }
	function _n( $a, $b, $n ) { return 1 === $n ? $a : $b; }
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
	function esc_html__( $s ) { return esc_html( $s ); }
	function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
	function esc_url_raw( $s ) { return trim( (string) $s ); }
	function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
	function wp_unslash( $s ) { return $s; }
	function absint( $n ) { return abs( (int) $n ); }
	function untrailingslashit( $s ) { return rtrim( (string) $s, '/' ); }
	function is_wp_error( $x ) { return $x instanceof WP_Error; }
	function add_query_arg( $k, $v, $url = '' ) {
		if ( is_array( $k ) ) { $args = $k; $url = $v; } else { $args = array( $k => $v ); }
		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
	}
	function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['options'] ) ? $GLOBALS['options'][ $k ] : $d; }
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
	function delete_option( $k ) { unset( $GLOBALS['options'][ $k ] ); return true; }
	function get_transient( $k ) { return isset( $GLOBALS['transients'][ $k ] ) ? $GLOBALS['transients'][ $k ] : false; }
	function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
	function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
	function get_user_meta( $u, $k, $single = false ) { return isset( $GLOBALS['usermeta'][ $u ][ $k ] ) ? $GLOBALS['usermeta'][ $u ][ $k ] : ''; }
	function update_user_meta( $u, $k, $v ) { $GLOBALS['usermeta'][ $u ][ $k ] = $v; return true; }
	function get_userdata( $id ) { return isset( $GLOBALS['users'][ $id ] ) ? $GLOBALS['users'][ $id ] : false; }
	function wp_generate_password( $len = 12, $special = true ) {
		$a = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $o = '';
		for ( $i = 0; $i < $len; $i++ ) { $o .= $a[ random_int( 0, strlen( $a ) - 1 ) ]; }
		return $o;
	}
	function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
	function get_bloginfo( $k = '' ) { return 'Test Shop'; }
	function wp_specialchars_decode( $s, $q = 0 ) { return htmlspecialchars_decode( $s, $q ); }
	function number_format_i18n( $n, $d = 0 ) { return number_format( $n, $d ); }
	function wp_date( $f, $t ) { return date( is_string( $f ) && '' !== trim( $f ) ? $f : 'Y-m-d H:i', $t ); }
	function admin_url( $p = '' ) { return 'http://wp.test/wp-admin/' . $p; }
	function wp_nonce_url( $url, $action ) { return $url . '&_wpnonce=' . substr( md5( $action ), 0, 10 ); }
	function check_admin_referer( $action ) { return true; }
	function current_user_can( $c ) { return $GLOBALS['can']; }
	function get_current_user_id() { return 1; }
	function wp_die( $m = '', $t = '', $a = array() ) { throw new StopDie( (string) $m ); }
	function wp_safe_redirect( $u ) { throw new StopRedirect( $u ); }
	function wp_mail( $to, $subject, $message, $headers = '' ) {
		$GLOBALS['mails'][] = array( 'to' => $to, 'subject' => $subject, 'message' => $message, 'headers' => $headers );
		return $GLOBALS['mail_ok'];
	}
	function wp_json_encode( $v ) { return json_encode( $v ); }

	// Hooks.
	function add_filter( $tag, $cb, $prio = 10, $n = 1 ) { $GLOBALS['hooks'][ $tag ][] = array( $prio, $cb, $n ); return true; }
	function add_action( $tag, $cb, $prio = 10, $n = 1 ) { return add_filter( $tag, $cb, $prio, $n ); }
	function has_filter( $tag ) { return ! empty( $GLOBALS['hooks'][ $tag ] ); }
	function remove_all_filters( $tag ) { unset( $GLOBALS['hooks'][ $tag ] ); }
	function apply_filters( $tag, $value, ...$args ) {
		$list = isset( $GLOBALS['hooks'][ $tag ] ) ? $GLOBALS['hooks'][ $tag ] : array();
		usort( $list, function ( $a, $b ) { return $a[0] <=> $b[0]; } );
		foreach ( $list as $h ) {
			$value = call_user_func_array( $h[1], array_slice( array_merge( array( $value ), $args ), 0, $h[2] ) );
		}
		return $value;
	}
	function do_action( $tag, ...$args ) {
		$GLOBALS['actions'][] = $tag;
		$list = isset( $GLOBALS['hooks'][ $tag ] ) ? $GLOBALS['hooks'][ $tag ] : array();
		usort( $list, function ( $a, $b ) { return $a[0] <=> $b[0]; } );
		foreach ( $list as $h ) {
			call_user_func_array( $h[1], array_slice( $args, 0, $h[2] ) );
		}
		array_pop( $GLOBALS['actions'] );
	}
	function current_action() { return end( $GLOBALS['actions'] ); }

	// HTTP over curl, like WordPress' wp_remote_*: no redirects, TLS verification on.
	function xc_http( $method, $url, array $args ) {
		$ch      = curl_init( $url );
		$headers = array();
		foreach ( (array) ( $args['headers'] ?? array() ) as $k => $v ) { $headers[] = "$k: $v"; }
		curl_setopt_array( $ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT        => (int) ( $args['timeout'] ?? 20 ),
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_HTTPHEADER     => $headers,
		) );
		if ( 'POST' === $method ) {
			curl_setopt( $ch, CURLOPT_POST, true );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, http_build_query( $args['body'] ?? array() ) );
			$GLOBALS['api_calls'][] = (string) ( $args['body']['action'] ?? '' );
		}
		$body = curl_exec( $ch );
		if ( false === $body ) {
			$err = curl_error( $ch );
			return new WP_Error( 'http_request_failed', $err );
		}
		$code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		return array( 'code' => $code, 'body' => (string) $body );
	}
	function wp_remote_get( $url, $args = array() ) { return xc_http( 'GET', $url, $args ); }
	function wp_remote_post( $url, $args = array() ) { return xc_http( 'POST', $url, $args ); }
	function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
	function wp_remote_retrieve_body( $r ) { return $r['body']; }

	// ---------------------------------------------------------------- the real files
	$root = dirname( __DIR__ ) . '/wordpress/xtreampro/includes/';
	require $root . 'class-xtreampro-api.php';
	require $root . 'class-xtreampro-surecart.php';

	// ---------------------------------------------------------------- test helpers
	$fail  = 0;
	$check = function ( $label, $ok, $detail = '' ) use ( &$fail ) {
		echo ( $ok ? '  ok   ' : '  FAIL ' ) . $label . ( $ok ? '' : ' -> ' . ( is_string( $detail ) ? $detail : json_encode( $detail ) ) ) . "\n";
		if ( ! $ok ) { $fail++; }
	};
	$apiUrl  = getenv( 'API_URL' ) ?: 'http://127.0.0.1:18095';
	$keyFile = getenv( 'API_KEY_FILE' ) ?: '/private/tmp/claude-501/-Users-me-Projects-dashboard-panel--claude-worktrees-plugins-packaging-5de309/33b22579-2379-4cc6-ac68-083305557a5f/scratchpad/shared.key';
	$apiKey  = trim( (string) @file_get_contents( $keyFile ) );
	if ( '' === $apiKey ) { fwrite( STDERR, "no API key\n" ); exit( 2 ); }
	update_option( 'xtreampro_api_url', $apiUrl );
	update_option( 'xtreampro_api_key', $apiKey );
	update_option( 'xtreampro_panel_url', 'https://panel.example.test' );

	$run   = bin2hex( random_bytes( 3 ) );
	$uuid  = function () { return sprintf( '%08x-%04x-4%03x-a%03x-%012x', random_int( 0, 0xffffffff ), random_int( 0, 0xffff ), random_int( 0, 0xfff ), random_int( 0, 0xfff ), random_int( 0, 0xffffffffffff ) ); };
	$mkUser = function ( $label ) use ( $run ) {
		static $n = 100;
		$n++;
		$u = new WP_User( $n, $label . $run, $label . $run . '@example.test', ucfirst( $label ) . ' Test' );
		$GLOBALS['users'][ $n ] = $u;
		return $u;
	};
	$sc = new XtreamPro_SureCart();   // only to read the integration name; init() builds the real instance
	$product = $uuid();
	$rowId   = 0;
	$mkIntegration = function ( $item, $productId ) use ( &$rowId ) {
		$rowId++;
		$r = (object) array( 'id' => 'int-' . $rowId, 'integration_id' => $item, 'provider' => XtreamPro_SureCart::SLUG, 'model_id' => $productId, 'price_id' => null, 'variant_id' => null );
		\SureCart\Models\Integration::$rows[] = $r;
		return $r;
	};
	$mkPurchase = function ( $productId, $user, $qty = 1 ) use ( $uuid ) {
		$p = new \SureCart\Models\Purchase();
		$p->id = $uuid(); $p->product_id = $productId; $p->quantity = $qty; $p->wp_user = $user;
		\SureCart\Models\Purchase::$all[ $p->id ] = $p;
		return $p;
	};
	$calls = function ( $name ) { return count( array_filter( $GLOBALS['api_calls'], function ( $a ) use ( $name ) { return $a === $name; } ) ); };
	$rec   = function ( $p ) { $r = get_option( 'xtreampro_sc_p_' . $p->id, array() ); return is_array( $r ) ? $r : array(); };
	$age   = function ( $p ) { $r = get_option( 'xtreampro_sc_p_' . $p->id ); foreach ( $r['lines'] as $k => $l ) { $r['lines'][ $k ]['at'] = time() - 7200; } foreach ( $r['subs'] as $k => $s ) { $r['subs'][ $k ]['at'] = time() - 7200; } update_option( 'xtreampro_sc_p_' . $p->id, $r ); };
	$sub   = function ( $uid ) { $a = XtreamPro_API::user_reseller( $uid ); return $a ? XtreamPro_API::find_sub_user( $a['user_id'], $a['username'] ) : null; };
	$sent = function ( $to = null ) { return array_values( array_filter( $GLOBALS['mails'], function ( $m ) use ( $to ) { return null === $to || $m['to'] === $to; } ) ); };
	$secrets = array( $apiKey );

	echo "== registration\n";
	XtreamPro_SureCart::init();
	$check( 'the provider is registered with SureCart (find filter, list filter, items, item, validation)',
		has_filter( 'surecart/integrations/providers/find/xtreampro/panel' ) && has_filter( 'surecart/integrations/providers/list/product' ) && has_filter( 'surecart/integrations/providers/xtreampro/panel/product/items' ) && has_filter( 'surecart/integrations/providers/xtreampro/panel/item' ) && has_filter( 'surecart/integrations/providers/xtreampro/panel/is_valid_item' ) );
	$check( 'purchase_created / invoked / revoked, subscription_renewed and the retry action are hooked',
		has_filter( 'surecart/purchase_created' ) && has_filter( 'surecart/purchase_invoked' ) && has_filter( 'surecart/purchase_revoked' ) && has_filter( 'surecart/subscription_renewed' ) && has_filter( 'admin_post_xtreampro_sc_retry' ) && has_filter( 'xtreampro_settings_page' ) );
	$list = apply_filters( 'surecart/integrations/providers/list/product', array() );
	$check( 'the provider list names "Xtream UI Pro"', 1 === count( $list ) && 'Xtream UI Pro' === $list[0]['label'] && 'xtreampro/panel' === $list[0]['name'], $list );

	echo "== items\n";
	$packages = XtreamPro_API::packages( true );
	$check( 'packages come from the panel', is_array( $packages ) && count( $packages ) >= 1, is_wp_error( $packages ) ? $packages->get_error_message() : '' );
	$official = null; $trialPkg = null;
	foreach ( $packages as $pkg ) {
		if ( ! $official && ! empty( $pkg['is_official'] ) && XtreamPro_API::sells_line( $pkg ) ) { $official = $pkg; }
		if ( ! $trialPkg && ! empty( $pkg['is_trial'] ) ) { $trialPkg = $pkg; }
	}
	$pkgId = (int) $official['id'];
	$items = apply_filters( 'surecart/integrations/providers/xtreampro/panel/product/items', array(), '' );
	$ids   = array_map( function ( $i ) { return $i->id; }, $items );
	$check( 'the picker lists "IPTV line: <package>" for the official package', in_array( 'line:' . $pkgId, $ids, true ) && 'IPTV line: ' . $official['name'] === $items[ array_search( 'line:' . $pkgId, $ids, true ) ]->label );
	$boxIds = array_map( function ( $p ) { return (int) $p['id']; }, array_filter( $packages, function ( $p ) { return ! XtreamPro_API::sells_line( $p ); } ) );
	$check( 'a package for boxes only is not offered (neither as a line nor as a trial)', $boxIds && ! array_intersect( array_merge( array_map( function ( $i ) { return 'line:' . $i; }, $boxIds ), array_map( function ( $i ) { return 'trial:' . $i; }, $boxIds ) ), $ids ), $boxIds );
	$check( 'a trial package also gets an "IPTV trial line" item (if the panel has one)', null === $trialPkg || in_array( 'trial:' . (int) $trialPkg['id'], $ids, true ) );
	$check( 'standard credit amounts are offered', in_array( 'sub:10', $ids, true ) && in_array( 'sub:1000', $ids, true ) );
	$found = apply_filters( 'surecart/integrations/providers/xtreampro/panel/product/items', array(), 'reseller account: 100' );
	$check( 'searching narrows the list', 1 === count( $found ) && 'sub:100' === $found[0]->id, array_map( function ( $i ) { return $i->label; }, $found ) );
	$check( 'a single item is described', apply_filters( 'surecart/integrations/providers/xtreampro/panel/item', 'sub:25' )->label === 'Sub-reseller account: 25 credits' );
	$valid = function ( $id ) { return apply_filters( 'surecart/integrations/providers/xtreampro/panel/is_valid_item', true, $id ); };
	$check( 'saving validates the item (known package yes; unknown package, odd amount, junk no)', $valid( 'line:' . $pkgId ) && ! $valid( 'line:99999999' ) && ! $valid( 'sub:7' ) && ! $valid( 'bogus' ) && ! $valid( 'sub:0' ) );
	add_filter( 'xtreampro_surecart_credit_amounts', function () { return array( 20, 7, 'x', 0 ); } );
	$check( 'the credit list is filterable (and sanitised)', $valid( 'sub:7' ) && $valid( 'sub:20' ) && ! $valid( 'sub:10' ) && array( 7, 20 ) === XtreamPro_SureCart::credit_amounts() );
	remove_all_filters( 'xtreampro_surecart_credit_amounts' );

	// ---------------------------------------------------------------- lines
	echo "== line item, quantity 2\n";
	$buyer = $mkUser( 'buyer' );
	$prodA = $uuid();
	$mkIntegration( 'line:' . $pkgId, $prodA );
	$P = $mkPurchase( $prodA, $buyer, 2 );
	$secrets[] = 'x';
	do_action( 'surecart/purchase_created', $P );
	$R = $rec( $P );
	$ids = array_map( function ( $l ) { return (int) $l['id']; }, $R['lines'] );
	$check( 'purchase_created creates one line per unit', 2 === count( $ids ) && $ids[0] !== $ids[1], $R );
	$l0 = XtreamPro_API::get_line( $ids[0] ); $l1 = XtreamPro_API::get_line( $ids[1] );
	$check( 'both lines are active on the panel and match the record', ! is_wp_error( $l0 ) && 'active' === $l0['status'] && 'active' === $l1['status'] && $l0['username'] === $R['lines'][0]['username'] && $l0['password'] === $R['lines'][0]['password'], is_wp_error( $l0 ) ? $l0->get_error_message() : $l0 );
	$secrets[] = $R['lines'][0]['password']; $secrets[] = $R['lines'][1]['password'];
	$check( 'the lines are in the shared user meta ([xtreampro_my_lines])', 2 === count( XtreamPro_API::user_lines( $buyer->ID ) ) && XtreamPro_API::user_owns_line( $buyer->ID, $ids[0] ) );
	$m = $sent();
	$check( 'exactly one email, to the buyer only', 1 === count( $m ) && $buyer->user_email === $m[0]['to'] && '' === $m[0]['headers'], $m );
	$check( 'the email carries both lines\' username, password and the playlist', 1 === count( $m ) && false !== strpos( $m[0]['message'], $R['lines'][0]['password'] ) && false !== strpos( $m[0]['message'], $R['lines'][1]['username'] ) && false !== strpos( $m[0]['message'], 'get.php' ) );
	$check( 'the API key is not in the email', false === strpos( $m[0]['message'], $apiKey ) );
	$before = count( $GLOBALS['api_calls'] );
	do_action( 'surecart/purchase_created', $P );
	do_action( 'surecart/purchase_created', \SureCart\Models\Purchase::$all[ $P->id ] );
	$check( 'a replayed purchase_created creates nothing, calls the panel not at all and sends no email', 2 === count( $rec( $P )['lines'] ) && count( $GLOBALS['api_calls'] ) === $before && 1 === count( $sent() ), count( $GLOBALS['api_calls'] ) - $before );
	// A second purchase of the same product by the same buyer is a different purchase: its own line.
	$P2 = $mkPurchase( $prodA, $buyer, 1 );
	do_action( 'surecart/purchase_created', $P2 );
	$check( 'another purchase of the same product sells another line', 1 === count( $rec( $P2 )['lines'] ) && 3 === count( XtreamPro_API::user_lines( $buyer->ID ) ) && (int) $rec( $P2 )['lines'][0]['id'] !== $ids[0] );

	echo "== revoked / invoked\n";
	$P->revoked = time();
	do_action( 'surecart/purchase_revoked', $P );
	$check( 'purchase_revoked disables (never deletes) both lines', 'disabled' === XtreamPro_API::get_line( $ids[0] )['status'] && 'disabled' === XtreamPro_API::get_line( $ids[1] )['status'] );
	$check( 'the other purchase\'s line is untouched', 'active' === XtreamPro_API::get_line( (int) $rec( $P2 )['lines'][0]['id'] )['status'] );
	$n = $calls( 'disable_line' );
	do_action( 'surecart/purchase_revoked', $P );
	$check( 'a replayed revoke makes no further panel call', $calls( 'disable_line' ) === $n );
	$P->revoked = null;
	$mailsBefore = count( $sent() );
	do_action( 'surecart/purchase_invoked', $P );
	$check( 'purchase_invoked enables them again', 'active' === XtreamPro_API::get_line( $ids[0] )['status'] && 'active' === XtreamPro_API::get_line( $ids[1] )['status'] && 2 === count( $rec( $P )['lines'] ) );
	$check( '... without creating a line or sending the credentials a second time', count( $sent() ) === $mailsBefore + 0 && 2 === count( $rec( $P )['lines'] ), count( $sent() ) );
	$n = $calls( 'enable_line' );
	do_action( 'surecart/purchase_invoked', $P );
	$check( 'a replayed invoke makes no further panel call', $calls( 'enable_line' ) === $n );

	echo "== renewal\n";
	$sub1 = (object) array( 'purchase' => $P->id, 'current_period' => 'per-' . $run . '-1' );
	$r0   = $calls( 'renew_line' );
	do_action( 'surecart/subscription_renewed', $sub1 );
	$check( 'a renewal event right after creation is the first payment: no renew_line', $calls( 'renew_line' ) === $r0 );
	$age( $P );
	$e0 = XtreamPro_API::get_line( $ids[0] )['exp_date'];
	$sub2 = (object) array( 'purchase' => $P->id, 'current_period' => 'per-' . $run . '-2' );
	do_action( 'surecart/subscription_renewed', $sub2 );
	$e1 = XtreamPro_API::get_line( $ids[0] )['exp_date'];
	$check( 'a renewal renews each line once (2 renew_line calls) and the expiry moves', $calls( 'renew_line' ) === $r0 + 2 && (int) $e1 > (int) $e0, array( $e0, $e1 ) );
	do_action( 'surecart/subscription_renewed', $sub2 );
	$check( 'the same period again: no double charge', $calls( 'renew_line' ) === $r0 + 2 && XtreamPro_API::get_line( $ids[0] )['exp_date'] === $e1 );
	do_action( 'surecart/subscription_renewed', (object) array( 'purchase' => $P->id, 'current_period' => (object) array( 'id' => 'per-' . $run . '-3' ) ) );
	$check( 'the next period (period given as object) renews again', $calls( 'renew_line' ) === $r0 + 4 );
	do_action( 'surecart/subscription_renewed', (object) array( 'purchase' => $uuid(), 'current_period' => 'p' ) );
	do_action( 'surecart/subscription_renewed', (object) array( 'purchase' => $P->id ) );
	$check( 'a renewal of an unknown purchase is ignored; one without a period is reported, not guessed', $calls( 'renew_line' ) === $r0 + 4 && false !== strpos( implode( ' ', get_option( 'xtreampro_sc_problems' )[ $P->id ]['msgs'] ), 'billing period' ) );
	$P->revoked = time();
	do_action( 'surecart/purchase_revoked', $P );
	do_action( 'surecart/subscription_renewed', (object) array( 'purchase' => $P->id, 'current_period' => 'per-' . $run . '-4' ) );
	$check( 'a revoked purchase\'s lines are not renewed', $calls( 'renew_line' ) === $r0 + 4 );
	$P->revoked = null;
	do_action( 'surecart/purchase_invoked', $P );
	delete_option( 'xtreampro_sc_problems' );

	if ( $trialPkg ) {
		echo "== trial item\n";
		$prodT = $uuid();
		$mkIntegration( 'trial:' . (int) $trialPkg['id'], $prodT );
		$PT = $mkPurchase( $prodT, $buyer, 1 );
		do_action( 'surecart/purchase_created', $PT );
		$check( 'a trial item creates a trial line (or a readable problem when the reseller cannot)', 1 === count( $rec( $PT )['lines'] ) || isset( get_option( 'xtreampro_sc_problems', array() )[ $PT->id ] ), get_option( 'xtreampro_sc_problems' ) );
		delete_option( 'xtreampro_sc_problems' );
	}

	// ---------------------------------------------------------------- reseller
	echo "== sub-reseller item\n";
	$rbuyer = $mkUser( 'reseller' );
	$prodR  = $uuid();
	$mkIntegration( 'sub:25', $prodR );
	$R1 = $mkPurchase( $prodR, $rbuyer, 2 );
	do_action( 'surecart/purchase_created', $R1 );
	$acct = XtreamPro_API::user_reseller( $rbuyer->ID );
	$check( 'purchase_created creates the reseller account and remembers it ([xtreampro_my_reseller])', is_array( $acct ) && '' !== $acct['user_id'], get_option( 'xtreampro_sc_problems' ) );
	$s = $sub( $rbuyer->ID );
	$check( 'the account is active, has the buyer\'s email and 2 x 25 = 50 credits', is_array( $s ) && 'active' === $s['status'] && (int) $s['credits'] === 50 && $s['email'] === $rbuyer->user_email, is_wp_error( $s ) ? $s->get_error_message() : $s );
	$RR = $rec( $R1 );
	$secrets[] = current( $RR['subs'] )['password'];
	$m = $sent( $rbuyer->user_email );
	$check( 'one email to the buyer only, with username, password, credits and sign-in link', 1 === count( $m ) && false !== strpos( $m[0]['message'], $acct['username'] ) && false !== strpos( $m[0]['message'], current( $RR['subs'] )['password'] ) && false !== strpos( $m[0]['message'], 'panel.example.test/login' ) && false !== strpos( $m[0]['message'], ': 50' ) );
	do_action( 'surecart/purchase_created', $R1 );
	$check( 'a replay creates no second account and gives no credits twice', (int) $sub( $rbuyer->ID )['credits'] === 50 && 1 === count( $sent( $rbuyer->user_email ) ) );
	$prodR2 = $uuid();
	$mkIntegration( 'sub:10', $prodR2 );
	$R2 = $mkPurchase( $prodR2, $rbuyer, 1 );
	do_action( 'surecart/purchase_created', $R2 );
	$check( 'a second purchase tops up the same account (50 + 10) and its email has no password', 60 === (int) $sub( $rbuyer->ID )['credits'] && XtreamPro_API::user_reseller( $rbuyer->ID )['user_id'] === $acct['user_id'] && 2 === count( $sent( $rbuyer->user_email ) ) && false === strpos( $sent( $rbuyer->user_email )[1]['message'], 'Password' ) && empty( current( $rec( $R2 )['subs'] )['created'] ), $sent( $rbuyer->user_email )[1]['message'] ?? '' );
	$R2->revoked = time();
	do_action( 'surecart/purchase_revoked', $R2 );
	$s = $sub( $rbuyer->ID );
	$check( 'revoking the top-up takes its 10 credits back and leaves the account active', 50 === (int) $s['credits'] && 'active' === $s['status'], $s );
	$n = $calls( 'adjust_credits' );
	do_action( 'surecart/purchase_revoked', $R2 );
	$check( 'a replayed revoke takes nothing back twice', $calls( 'adjust_credits' ) === $n && 50 === (int) $sub( $rbuyer->ID )['credits'] );
	$R1->revoked = time();
	do_action( 'surecart/purchase_revoked', $R1 );
	$s = $sub( $rbuyer->ID );
	$check( 'revoking the creating purchase takes its credits back and disables the account', 0 === (int) $s['credits'] && 'disabled' === $s['status'], $s );
	$R1->revoked = null;
	do_action( 'surecart/purchase_invoked', $R1 );
	$s = $sub( $rbuyer->ID );
	$check( 'purchase_invoked enables the account and gives the credits again', 'active' === $s['status'] && 50 === (int) $s['credits'], $s );
	do_action( 'surecart/purchase_invoked', $R1 );
	$check( 'a replayed invoke does not credit twice', 50 === (int) $sub( $rbuyer->ID )['credits'] );
	$R1->revoked = null; $R2->revoked = null;
	$age( $R1 );
	$rp1 = (object) array( 'purchase' => $R1->id, 'current_period' => 'rper-' . $run . '-1' );
	do_action( 'surecart/subscription_renewed', $rp1 );
	$check( 'a renewal tops up the credits (50 + 50)', 100 === (int) $sub( $rbuyer->ID )['credits'], $sub( $rbuyer->ID )['credits'] );
	do_action( 'surecart/subscription_renewed', $rp1 );
	$check( 'the same period again does not top up twice', 100 === (int) $sub( $rbuyer->ID )['credits'] );
	$s = $sub( $rbuyer->ID );
	$R1->revoked = time();
	do_action( 'surecart/purchase_revoked', $R1 );
	$check( 'revoking after a renewal takes back all credits it gave (100)', 0 === (int) $sub( $rbuyer->ID )['credits'] && 'disabled' === $sub( $rbuyer->ID )['status'], $sub( $rbuyer->ID ) );
	$R1->revoked = null;
	do_action( 'surecart/purchase_invoked', $R1 );   // leave things enabled

	echo "== errors\n";
	$ebuyer = $mkUser( 'err' );
	$prodE  = $uuid();
	$mkIntegration( 'line:' . $pkgId, $prodE );
	$E = $mkPurchase( $prodE, $ebuyer, 1 );
	$mailsBefore = count( $sent() );
	update_option( 'xtreampro_api_key', 'xk_wrong_' . $run );
	do_action( 'surecart/purchase_created', $E );
	$probs = get_option( 'xtreampro_sc_problems', array() );
	$check( 'a wrong API key leaves a readable problem and no line, no email', isset( $probs[ $E->id ] ) && false !== stripos( implode( ' ', $probs[ $E->id ]['msgs'] ), 'API key' ) && 0 === count( $rec( $E )['lines'] ?? array() ) && count( $sent() ) === $mailsBefore, $probs );
	$check( 'nothing secret in the problem text', false === strpos( json_encode( $probs ), $apiKey ) && false === strpos( json_encode( $probs ), 'xk_wrong' ) );
	ob_start(); ( new XtreamPro_SureCart() )->render_problems(); $html = ob_get_clean();
	$check( 'the settings page lists it with a nonce-protected Retry link', false !== strpos( $html, esc_html( $E->id ) ) && false !== strpos( $html, 'action=xtreampro_sc_retry' ) && false !== strpos( $html, '_wpnonce=' ) && false !== strpos( $html, 'API key' ) );
	$GLOBALS['can'] = false;
	ob_start(); ( new XtreamPro_SureCart() )->render_problems(); $none = ob_get_clean();
	$_GET = array( 'purchase_id' => $E->id );
	$denied = false;
	try { ( new XtreamPro_SureCart() )->handle_retry(); } catch ( StopDie $e ) { $denied = true; }
	$check( 'without manage_options the table is hidden and retry is refused', '' === $none && $denied && 0 === count( $rec( $E )['lines'] ?? array() ) );
	$GLOBALS['can'] = true;
	update_option( 'xtreampro_api_key', $apiKey );
	$redirect = '';
	try { ( new XtreamPro_SureCart() )->handle_retry(); } catch ( StopRedirect $e ) { $redirect = $e->getMessage(); }
	$check( 'an admin\'s retry (after fixing the key) provisions the purchase through SureCart\'s data and redirects to the settings page', 1 === count( $rec( $E )['lines'] ) && false !== strpos( $redirect, 'options-general.php?page=xtreampro' ) && ! isset( get_option( 'xtreampro_sc_problems', array() )[ $E->id ] ) && true === get_transient( 'xtreampro_result_1' )['ok'], array( $redirect, get_transient( 'xtreampro_result_1' ) ) );
	$check( '... and the buyer got the email only now', 1 === count( $sent( $ebuyer->user_email ) ) && count( $sent() ) === $mailsBefore + 1 );
	$secrets[] = $rec( $E )['lines'][0]['password'];
	$_GET = array();
	$n = count( $GLOBALS['api_calls'] );
	$res = ( new XtreamPro_SureCart() )->retry( $E->id );
	$check( 'a further retry changes nothing', 1 === count( $rec( $E )['lines'] ) && count( $GLOBALS['api_calls'] ) === $n && $res['ok'] );

	$prodU = $uuid();
	$mkIntegration( 'line:99999999', $prodU );
	$U = $mkPurchase( $prodU, $ebuyer, 1 );
	do_action( 'surecart/purchase_created', $U );
	$check( 'an unknown package gives a readable problem and no line', isset( get_option( 'xtreampro_sc_problems' )[ $U->id ] ) && 0 === count( $rec( $U )['lines'] ?? array() ) && false !== stripos( implode( ' ', get_option( 'xtreampro_sc_problems' )[ $U->id ]['msgs'] ), 'package' ), get_option( 'xtreampro_sc_problems' )[ $U->id ] ?? '' );
	$prodB = $uuid();
	$mkIntegration( 'bogus', $prodB );
	$B = $mkPurchase( $prodB, $ebuyer, 1 );
	do_action( 'surecart/purchase_created', $B );
	$check( 'an invalid item id is reported, not provisioned', isset( get_option( 'xtreampro_sc_problems' )[ $B->id ] ) );
	$GLOBALS['api_calls'] = array();
	$noUser = $mkPurchase( $prodE, null, 1 );
	$svcInteg = \SureCart\Models\Integration::$rows[ count( \SureCart\Models\Integration::$rows ) - 1 ];
	$real = ( new ReflectionClass( 'XtreamPro_SureCart' ) )->getProperty( 'instance' );
	$real->setAccessible( true );
	$inst = $real->getValue();
	$inst->onPurchaseCreated( \SureCart\Models\Integration::$rows[0], null, $noUser );
	$check( 'a purchase without a WordPress user is reported and calls nothing', 0 === count( $GLOBALS['api_calls'] ) && isset( get_option( 'xtreampro_sc_problems' )[ $noUser->id ] ) );
	$GLOBALS['mail_ok'] = false;
	$prodM = $uuid();
	$mkIntegration( 'line:' . $pkgId, $prodM );
	$M = $mkPurchase( $prodM, $ebuyer, 1 );
	do_action( 'surecart/purchase_created', $M );
	$check( 'a failing wp_mail is a problem; the line exists', 1 === count( $rec( $M )['lines'] ) && false !== stripos( implode( ' ', get_option( 'xtreampro_sc_problems' )[ $M->id ]['msgs'] ), 'email' ) );
	$GLOBALS['mail_ok'] = true;
	$mb = count( $sent() );
	( new XtreamPro_SureCart() )->retry( $M->id );
	$last = $sent()[ count( $sent() ) - 1 ];
	$check( 'retry sends the email again (to the buyer) and clears the problem', count( $sent() ) === $mb + 1 && $last['to'] === $ebuyer->user_email && ! isset( get_option( 'xtreampro_sc_problems' )[ $M->id ] ) );
	$secrets[] = $rec( $M )['lines'][0]['password'];
	update_option( 'xtreampro_api_key', '' );
	$PN = $mkPurchase( $prodE, $ebuyer, 1 );
	$inst->onPurchaseCreated( \SureCart\Models\Integration::$rows[0], $ebuyer, $PN );
	$check( 'an unconfigured plugin says so', false !== stripos( implode( ' ', get_option( 'xtreampro_sc_problems' )[ $PN->id ]['msgs'] ), 'not configured' ) );
	update_option( 'xtreampro_api_key', $apiKey );

	echo "== hygiene\n";
	$recipients = array_unique( array_map( function ( $m ) { return $m['to']; }, $GLOBALS['mails'] ) );
	$allowed    = array( $buyer->user_email, $rbuyer->user_email, $ebuyer->user_email );
	$check( 'every email went to a buyer of the purchase it is about (no admin, no copies)', ! array_diff( $recipients, $allowed ) && ! array_filter( $GLOBALS['mails'], function ( $m ) { return '' !== $m['headers'] || is_array( $m['to'] ); } ), $recipients );
	$ownMails = array_filter( $GLOBALS['mails'], function ( $m ) use ( $rbuyer, $buyer ) { return $m['to'] === $rbuyer->user_email && false !== strpos( $m['message'], 'IPTV subscription' ); } );
	$check( 'a reseller buyer\'s emails never contain someone else\'s line passwords', 0 === count( $ownMails ) );
	$log = (string) @file_get_contents( $logFile );
	$blob = $log . json_encode( get_option( 'xtreampro_sc_problems', array() ) ) . json_encode( $GLOBALS['transients'] );
	$leak = false;
	foreach ( $secrets as $sec ) { if ( strlen( $sec ) > 3 && false !== strpos( $blob, $sec ) ) { $leak = true; } }
	$check( 'no API key or password in the PHP error log, the problems list or transients', ! $leak, strlen( $log ) . ' bytes of log' );
	$check( 'no PHP warnings or notices from the plugin files', 0 === count( $phpNotices ), array_slice( array_unique( $phpNotices ), 0, 5 ) );

	echo $fail ? "\n$fail FAILED\n" : "\nALL OK\n";
	@unlink( $logFile );
	exit( $fail ? 1 : 0 );
}
