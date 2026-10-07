<?php
/**
 * Removes plugin options on uninstall. Order / user meta is kept on purpose
 * so that customers keep their purchase history.
 *
 * @package XtreamPro
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'xtreampro_api_url' );
delete_option( 'xtreampro_api_key' );
delete_option( 'xtreampro_panel_url' );
delete_option( 'xtreampro_webhook_secret' );
delete_option( 'xtreampro_webhook_id' );
delete_option( 'xtreampro_sc_problems' );
delete_transient( 'xtreampro_packages' );
