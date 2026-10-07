<?php
// Every key but heading_title starts with xp_: OpenCart merges the loaded
// language into the data of every template, so a short name could replace a
// string of the page this extension adds a block to.

// Heading (also the name shown in Extensions > Modules)
$_['heading_title']             = 'Xtream UI Pro';

// Settings page
$_['xp_text_extension']         = 'Extensions';
$_['xp_text_edit']              = 'Xtream UI Pro settings';
$_['xp_text_success']           = 'Success: the Xtream UI Pro settings were saved.';
$_['xp_text_connected']         = 'Connected as %s. Credits: %d. Packages available: %d.';
$_['xp_text_version']           = 'Version';
$_['xp_text_key_saved']         = 'A key is saved. Leave this empty to keep it.';
$_['xp_text_key_none']          = 'No key saved yet.';
$_['xp_text_working']           = 'Working...';
$_['xp_button_test']            = 'Test connection';
$_['xp_entry_status']           = 'Status';
$_['xp_entry_api_url']          = 'API URL';
$_['xp_entry_api_key']          = 'API key';
$_['xp_entry_panel_url']        = 'Panel address';
$_['xp_entry_paid']             = 'Paid order statuses';
$_['xp_entry_revoked']          = 'Revoked order statuses';
$_['xp_help_status']            = 'When off, orders are not provisioned and the customer pages show nothing.';
$_['xp_help_api_url']           = 'Address of the panel API, for example https://api.example.com (without /reseller/v1).';
$_['xp_help_api_key']           = 'The reseller API key from the panel (page API key). It is never shown again after saving.';
$_['xp_help_panel_url']         = 'Optional. Address of the panel dashboard; customers who bought a sub-reseller account get a sign-in link to it.';
$_['xp_help_paid']              = 'When an order gets one of these statuses, its lines are created (or switched back on). Normally Processing and Complete.';
$_['xp_help_revoked']           = 'When an order gets one of these statuses, its lines are disabled and unspent credits are taken back. Normally Canceled and Refunded.';
$_['xp_error_permission']       = 'Warning: You do not have permission to modify the Xtream UI Pro extension!';
$_['xp_error_api_url']          = 'The API URL must be a valid http:// or https:// address.';
$_['xp_error_panel_url']        = 'The panel address must be a valid http:// or https:// address.';
$_['xp_error_api_key']          = 'The API key is required.';
$_['xp_error_status_overlap']   = 'An order status cannot be both a paid and a revoked status.';
$_['xp_error_order']            = 'The order was not found.';

// Product form tab
$_['xp_tab']                    = 'Xtream UI Pro';
$_['xp_entry_kind']             = 'Buying this product';
$_['xp_kind_none']              = 'does nothing on the panel';
$_['xp_kind_line']              = 'IPTV line';
$_['xp_kind_reseller']          = 'Sub-reseller account';
$_['xp_entry_package']          = 'Package';
$_['xp_text_select_package']    = '-- Select a package --';
$_['xp_entry_trial']            = 'Trial line';
$_['xp_entry_credits']          = 'Credits';
$_['xp_help_kind']              = 'IPTV line creates one line per purchased unit. Sub-reseller account creates a reseller account for the customer (once) and hands over the credits below.';
$_['xp_help_package']           = 'The package of the lines created when this product is bought. Only for IPTV lines. The list comes from the panel (refreshed hourly and by Test connection).';
$_['xp_help_trial']             = 'Create trial lines (uses the trial settings and trial credits of the package). Only for IPTV lines.';
$_['xp_help_credits']           = 'Sub-reseller account only: credits handed to the customer\'s account per purchased unit, taken from your reseller balance. 0 creates the account without credits.';
$_['xp_text_package_error']     = 'The package list could not be loaded: %s';
$_['xp_text_no_packages']       = 'No packages are known yet. Save the extension settings and press Test connection.';

// Admin order block
$_['xp_text_order_help']        = 'Lines and accounts created for this order. When something failed, fix the cause (credits, package, API key) and provision again; finished items are left alone.';
$_['xp_button_provision']       = 'Provision again';
$_['xp_text_no_units']          = 'Nothing was provisioned for this order (no product of it sells an Xtream UI Pro line or account, or its status is not a paid one yet).';
$_['xp_col_item']               = 'Item';
$_['xp_col_unit']               = 'Unit';
$_['xp_col_type']               = 'Type';
$_['xp_col_status']             = 'Status';
$_['xp_col_panel_id']           = 'Panel id';
$_['xp_col_username']           = 'Username';
$_['xp_col_password']           = 'Password';
$_['xp_col_credits']            = 'Credits';
$_['xp_col_error']              = 'Error';
$_['xp_status_new']             = 'Not provisioned';
$_['xp_status_done']            = 'Provisioned';
$_['xp_status_failed']          = 'Failed';
$_['xp_status_revoked']         = 'Disabled';
$_['xp_text_request_failed']    = 'The request failed. Reload the page and try again.';
