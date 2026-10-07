<?php
// Module
$lang['Xtreampro.name'] = 'Xtream UI Pro';
$lang['Xtreampro.description'] = 'Sells IPTV lines and sub-reseller accounts of an Xtream UI Pro panel through its Reseller API.';
$lang['Xtreampro.module_row'] = 'Panel';
$lang['Xtreampro.module_row_plural'] = 'Panels';
$lang['Xtreampro.module_group'] = 'Panel group';
$lang['Xtreampro.order_options.first'] = 'First non-full panel';
$lang['Xtreampro.tab_client_account'] = 'IPTV details';

// Manage module page
$lang['Xtreampro.add_module_row'] = 'Add panel';
$lang['Xtreampro.add_module_group'] = 'Add panel group';
$lang['Xtreampro.back_to_manage'] = 'Back to panels';
$lang['Xtreampro.manage.tab_rows'] = 'Panels';
$lang['Xtreampro.manage.tab_groups'] = 'Groups';
$lang['Xtreampro.manage.module_rows_heading.name'] = 'Name';
$lang['Xtreampro.manage.module_rows_heading.hostname'] = 'Host name';
$lang['Xtreampro.manage.module_rows_heading.options'] = 'Options';
$lang['Xtreampro.manage.module_rows.edit'] = 'Edit';
$lang['Xtreampro.manage.module_rows.delete'] = 'Delete';
$lang['Xtreampro.manage.module_rows.confirm_delete'] = 'Are you sure you want to delete this panel?';
$lang['Xtreampro.manage.module_rows_no_results'] = 'There are no panels yet.';
$lang['Xtreampro.manage.module_groups_heading.name'] = 'Name';
$lang['Xtreampro.manage.module_groups_heading.servers'] = 'Panels';
$lang['Xtreampro.manage.module_groups_heading.options'] = 'Options';
$lang['Xtreampro.manage.module_groups.edit'] = 'Edit';
$lang['Xtreampro.manage.module_groups.delete'] = 'Delete';
$lang['Xtreampro.manage.module_groups.confirm_delete'] = 'Are you sure you want to delete this group?';
$lang['Xtreampro.manage.module_groups_no_results'] = 'There are no panel groups yet.';

// Add / edit panel
$lang['Xtreampro.add_row.box_title'] = 'Xtream UI Pro - Add panel';
$lang['Xtreampro.add_row.basic_title'] = 'Panel API';
$lang['Xtreampro.add_row.add_btn'] = 'Add panel';
$lang['Xtreampro.edit_row.box_title'] = 'Xtream UI Pro - Edit panel';
$lang['Xtreampro.edit_row.basic_title'] = 'Panel API';
$lang['Xtreampro.edit_row.add_btn'] = 'Update panel';
$lang['Xtreampro.row_meta.server_name'] = 'Name';
$lang['Xtreampro.row_meta.host_name'] = 'Host name';
$lang['Xtreampro.row_meta.host_name_hint'] = 'The host name of the panel API (cmd/api), for example api.example.com. No http:// and no path.';
$lang['Xtreampro.row_meta.port'] = 'Port';
$lang['Xtreampro.row_meta.port_hint'] = 'Leave empty for 80 (443 with SSL).';
$lang['Xtreampro.row_meta.use_ssl'] = 'Use SSL (https). The certificate is always verified.';
$lang['Xtreampro.row_meta.api_key'] = 'API key';
$lang['Xtreampro.row_meta.api_key_hint'] = 'The API key of the reseller account that owns the lines (panel page /api-key). Stored encrypted.';
$lang['Xtreampro.row_meta.api_key_keep'] = 'Leave empty to keep the stored key.';

// Package fields
$lang['Xtreampro.service_type.line'] = 'IPTV line';
$lang['Xtreampro.service_type.reseller'] = 'Sub-reseller account';
$lang['Xtreampro.package_fields.service_type'] = 'Service type';
$lang['Xtreampro.package_fields.tooltip.service_type'] = 'What one service sells. Panel package, Trial and Delete on cancel apply to IPTV lines; the credit fields apply to sub-reseller accounts.';
$lang['Xtreampro.package_fields.package_id'] = 'Panel package';
$lang['Xtreampro.package_fields.package_id_none'] = '-- select (needs a panel) --';
$lang['Xtreampro.package_fields.trial_only'] = 'trial only';
$lang['Xtreampro.package_fields.tooltip.package_id'] = 'The package of the line, loaded from the first panel of this package\'s server or group. Save the panel first so the list can load.';
$lang['Xtreampro.package_fields.trial'] = 'Trial line';
$lang['Xtreampro.package_fields.tooltip.trial'] = 'Create the line as a trial.';
$lang['Xtreampro.package_fields.delete_on_cancel'] = 'Delete permanently on cancel';
$lang['Xtreampro.package_fields.tooltip.delete_on_cancel'] = 'Unticked (default): the line (or account) is only disabled when the service is canceled and can be enabled again on the panel. Ticked: it is deleted on the panel - deleting is final, nothing can be brought back, and the customer who returns gets a new line.';
$lang['Xtreampro.package_fields.credits_on_creation'] = 'Credits on creation';
$lang['Xtreampro.package_fields.tooltip.credits_on_creation'] = 'Whole number, 0 or more: credits handed to the new sub-reseller account. The reseller also pays the group price of the account.';
$lang['Xtreampro.package_fields.credits_per_renewal'] = 'Credits per renewal';
$lang['Xtreampro.package_fields.tooltip.credits_per_renewal'] = 'Whole number, 0 or more: credits handed to the account at every renewal (0 = a renewal does nothing on the panel).';

// Service fields
$lang['Xtreampro.service_field.username'] = 'Username';
$lang['Xtreampro.service_field.password'] = 'Password';
$lang['Xtreampro.service_field.tooltip.username'] = 'Leave empty to have one generated.';
$lang['Xtreampro.service_field.tooltip.password'] = 'Leave empty to have one generated.';
$lang['Xtreampro.service_field.tooltip.new_password'] = 'Leave empty to keep the current password.';

// Service info and client tab
$lang['Xtreampro.service_info.username'] = 'Username';
$lang['Xtreampro.service_info.password'] = 'Password';
$lang['Xtreampro.service_info.status'] = 'Status';
$lang['Xtreampro.service_info.expiry'] = 'Expires';
$lang['Xtreampro.service_info.never_expires'] = 'Never expires';
$lang['Xtreampro.service_info.max_connections'] = 'Max connections';
$lang['Xtreampro.service_info.line_id'] = 'Line ID';
$lang['Xtreampro.service_info.account_id'] = 'Account ID';
$lang['Xtreampro.service_info.credits'] = 'Credit balance';
$lang['Xtreampro.service_info.server'] = 'Server';
$lang['Xtreampro.service_info.none'] = 'Not provisioned on the panel yet.';
$lang['Xtreampro.service_info.error'] = 'Could not load the details from the panel: %1$s';
$lang['Xtreampro.tab_client_account.title_line'] = 'Your IPTV line';
$lang['Xtreampro.tab_client_account.title_reseller'] = 'Your reseller account';
$lang['Xtreampro.tab_client_account.server_url'] = 'Server URL';
$lang['Xtreampro.tab_client_account.playlist'] = 'M3U playlist';
$lang['Xtreampro.tab_client_account.playlist_hls'] = 'M3U playlist (HLS)';
$lang['Xtreampro.tab_client_account.epg'] = 'XMLTV guide';
$lang['Xtreampro.tab_client_account.player_api'] = 'Player API';
$lang['Xtreampro.tab_client_account.web_player'] = 'Web player';
$lang['Xtreampro.tab_client_account.unavailable'] = 'Your service is not available yet.';

// Errors
$lang['Xtreampro.!error.module_row.missing'] = 'There is no panel configured for this package.';
$lang['Xtreampro.!error.server_name_valid'] = 'Please enter a name for the panel.';
$lang['Xtreampro.!error.host_name_valid'] = 'The host name is not valid. Enter only a host name or IP address, without http:// and without a path.';
$lang['Xtreampro.!error.port_valid'] = 'The port must be empty or a number from 1 to 65535.';
$lang['Xtreampro.!error.api_key_valid'] = 'Please enter the API key.';
$lang['Xtreampro.!error.api_key_valid_connection'] = 'The panel could not be reached with these details, or it rejected the API key.';
$lang['Xtreampro.!error.meta[service_type].valid'] = 'The service type must be IPTV line or Sub-reseller account.';
$lang['Xtreampro.!error.meta[package_id].valid'] = 'Select the panel package of the line.';
$lang['Xtreampro.!error.meta[credits_on_creation].valid'] = 'Credits on creation must be a whole number of 0 or more.';
$lang['Xtreampro.!error.meta[credits_per_renewal].valid'] = 'Credits per renewal must be a whole number of 0 or more.';
$lang['Xtreampro.!error.package_missing'] = 'No panel package is selected in the package configuration.';
$lang['Xtreampro.!error.xtreampro_username.reseller'] = 'The username of a sub-reseller needs 3 to 32 letters, digits, ".", "_" or "-".';
$lang['Xtreampro.!error.xtreampro_username.length'] = 'The username must have 1 to 64 characters.';
$lang['Xtreampro.!error.xtreampro_password.length'] = 'The password must have 4 to 128 characters.';
$lang['Xtreampro.!error.credits_failed'] = 'The account "%1$s" was created on the panel but handing over its credits failed: %2$s Fix this on the panel (give the credits there) and do not repeat the order.';
$lang['Xtreampro.!error.api.INVALID_API_KEY'] = 'The panel rejected the API key. Check the API key of the panel in the module settings.';
$lang['Xtreampro.!error.api.FORBIDDEN'] = 'The API key does not belong to a reseller account, or the reseller is not allowed to do this (for sub-reseller accounts: the reseller\'s group may not create or delete sub-resellers, or the hierarchy is too deep).';
$lang['Xtreampro.!error.api.RESOURCE_NOT_FOUND'] = 'The line or sub-reseller account was not found on the panel (it may have been deleted there).';
$lang['Xtreampro.!error.api.INVALID_REQUEST'] = 'The panel rejected the request, for example a username or password shorter than the reseller group allows, or an invalid email address.';
$lang['Xtreampro.!error.api.INVALID_PACKAGE'] = 'The selected package does not exist, is not available to this reseller, or cannot be sold this way (for example a package for MAG / Enigma boxes only sold as a line).';
$lang['Xtreampro.!error.api.INSUFFICIENT_CREDITS'] = 'The reseller account has not enough credits.';
$lang['Xtreampro.!error.api.CONFLICT'] = 'The username (or, for sub-reseller accounts, the email address) is already taken on the panel, or the request id was already used for another operation.';
$lang['Xtreampro.!error.api.REQUEST_ID_SPENT'] = 'This sale was already made and its line has since been deleted on the panel, so the same request id cannot sell another line. Cancel the service and order a new one.';
$lang['Xtreampro.!error.api.READ_ONLY_KEY'] = 'The API key is read-only. Create a key that may change things on the panel\'s API key page.';
$lang['Xtreampro.!error.api.POST_REQUIRED'] = 'The panel refused the request because it was not sent as POST.';
$lang['Xtreampro.!error.api.RATE_LIMITED'] = 'The panel is rate limiting this API key. Try again in a minute.';
$lang['Xtreampro.!error.api.UNKNOWN_ACTION'] = 'The panel does not know this API action. Is the panel up to date?';
$lang['Xtreampro.!error.api.SERVER_ERROR'] = 'The panel reported an internal error.';
$lang['Xtreampro.!error.api.CONNECTION_FAILED'] = 'Could not connect to the panel. Check host name, port and the SSL setting of the panel.';
$lang['Xtreampro.!error.api.BAD_RESPONSE'] = 'The panel answered with something that is not a valid API response. Check the panel address.';
$lang['Xtreampro.!error.api.CONFIG'] = 'The panel is not configured correctly (host name or API key missing).';
$lang['Xtreampro.!error.api.unknown'] = 'The panel returned an error: %1$s';
