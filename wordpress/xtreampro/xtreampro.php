<?php
/**
 * Plugin Name:       Xtream UI Pro Connector
 * Description:       Connects WordPress / WooCommerce / Easy Digital Downloads / SureCart to the Xtream UI Pro IPTV panel: pricing shortcodes, a "my lines" page and automatic line provisioning for shop orders.
 * Version:           1.1.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Xtream UI Pro
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       xtreampro
 *
 * @package XtreamPro
 */

defined( 'ABSPATH' ) || exit;

// Changes of each release: CHANGELOG.md (1.0.0 was the first release).
define( 'XTREAMPRO_VERSION', '1.1.1' );
define( 'XTREAMPRO_FILE', __FILE__ );
define( 'XTREAMPRO_DIR', plugin_dir_path( __FILE__ ) );
define( 'XTREAMPRO_URL', plugin_dir_url( __FILE__ ) );

require_once XTREAMPRO_DIR . 'includes/class-xtreampro-api.php';
require_once XTREAMPRO_DIR . 'includes/class-xtreampro-settings.php';
require_once XTREAMPRO_DIR . 'includes/class-xtreampro-shortcodes.php';
require_once XTREAMPRO_DIR . 'includes/class-xtreampro-webhook.php';

/**
 * Declare WooCommerce High-Performance Order Storage compatibility.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', XTREAMPRO_FILE, true );
		}
	}
);

/**
 * Boot the plugin once all plugins are loaded.
 */
function xtreampro_bootstrap() {
	XtreamPro_Settings::init();
	XtreamPro_Shortcodes::init();
	XtreamPro_Webhook::init();

	if ( class_exists( 'WooCommerce' ) ) {
		require_once XTREAMPRO_DIR . 'includes/class-xtreampro-woocommerce.php';
		XtreamPro_WooCommerce::init();
	}

	if ( class_exists( 'Easy_Digital_Downloads' ) || function_exists( 'EDD' ) ) {
		require_once XTREAMPRO_DIR . 'includes/class-xtreampro-edd.php';
		XtreamPro_EDD::init();
	}

	// SureCart's integration base class must exist before our class file is parsed.
	if ( class_exists( '\SureCart\Integrations\IntegrationService' ) ) {
		require_once XTREAMPRO_DIR . 'includes/class-xtreampro-surecart.php';
		XtreamPro_SureCart::init();
	}
}
add_action( 'plugins_loaded', 'xtreampro_bootstrap' );

/**
 * Load translations.
 */
function xtreampro_load_textdomain() {
	load_plugin_textdomain( 'xtreampro', false, dirname( plugin_basename( XTREAMPRO_FILE ) ) . '/languages' );
}
add_action( 'init', 'xtreampro_load_textdomain' );

/**
 * "Settings" link on the plugins screen.
 *
 * @param array $links Existing links.
 * @return array
 */
function xtreampro_action_links( $links ) {
	$url = admin_url( 'options-general.php?page=xtreampro' );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'xtreampro' ) . '</a>' );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'xtreampro_action_links' );
