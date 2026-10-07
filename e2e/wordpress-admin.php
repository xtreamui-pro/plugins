<?php
$notices = array();
set_error_handler(function ($no, $str, $file, $line) use (&$notices) { if (strpos($file, 'plugins/xtreampro') !== false) { $notices[] = "$str ($file:$line)"; } return false; });
update_option('xtreampro_api_url', getenv('API_URL'));
update_option('xtreampro_api_key', getenv('API_KEY'));
update_option('xtreampro_webhook_secret', 'whsec_must_never_be_printed');
wp_set_current_user(1);
if (!defined('WP_ADMIN')) { define('WP_ADMIN', true); }
require_once ABSPATH . 'wp-admin/includes/admin.php';
do_action('admin_init');
ob_start(); if (!class_exists("XtreamPro_Settings")) { require_once WP_PLUGIN_DIR . "/xtreampro/includes/class-xtreampro-settings.php"; XtreamPro_Settings::register(); } XtreamPro_Settings::render(); $html = ob_get_clean();
echo (strpos($html, 'xtreampro_api_url') !== false ? '  ok   ' : '  FAIL ') . "settings page renders\n";
echo (strpos($html, getenv('API_KEY')) === false ? '  ok   ' : '  FAIL ') . "settings page never prints the API key\n";
echo (strpos($html, 'whsec_must_never_be_printed') === false ? '  ok   ' : '  FAIL ') . "settings page never prints the webhook secret\n";
echo (strpos($html, 'name="xtreampro_panel_url"') !== false && strpos($html, 'name="xtreampro_webhook_secret"') !== false ? '  ok   ' : '  FAIL ') . "settings page has the Panel address and Webhook secret fields\n";
echo (strpos($html, 'wp-json/xtreampro/v1/webhook') !== false && strpos($html, 'xtreampro_webhook_register') !== false && strpos($html, 'xtreampro_webhook_test') !== false && strpos($html, 'xtreampro_webhook_remove') !== false ? '  ok   ' : '  FAIL ') . "settings page shows the webhook address and its three buttons\n";
delete_option('xtreampro_webhook_secret');
$ids = wc_get_products(array('limit' => 1, 'return' => 'ids'));
$GLOBALS['post'] = get_post($ids[0]);
require_once WC_ABSPATH . "includes/admin/wc-meta-box-functions.php"; ob_start(); XtreamPro_WooCommerce::product_fields(); $html = ob_get_clean();
echo (strpos($html, '_xtreampro_package_id') !== false && strpos($html, '_xtreampro_kind') !== false ? '  ok   ' : '  FAIL ') . "product fields render\n";
echo (strpos($html, 'E2E box only') === false && strpos($html, '_xtreampro_package_id') !== false ? '  ok   ' : '  FAIL ') . "the product's package picker leaves out the box-only package\n";
echo (strpos($html, '_xtreampro_on_cancel') !== false && strpos($html, 'FINAL') !== false ? '  ok   ' : '  FAIL ') . "product fields offer On refund or cancellation and say that deleting is final\n";
echo (count($notices) === 0 ? '  ok   ' : '  FAIL ') . 'no notices ' . wp_json_encode(array_slice(array_unique($notices), 0, 5)) . "\n";
