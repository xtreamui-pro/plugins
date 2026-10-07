<?php
// End-to-end run of the plugin inside a real WordPress + WooCommerce.
$notices = array();
set_error_handler(function ($no, $str, $file, $line) use (&$notices) {
    if (strpos($file, 'plugins/xtreampro') !== false) { $notices[] = "$str ($file:$line)"; }
    return false;
});
$fail = 0;
$check = function ($label, $ok, $detail = '') use (&$fail) {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' -> ' . (is_string($detail) ? $detail : wp_json_encode($detail))) . "\n";
    if (!$ok) { $fail++; }
};
$run = getenv('RUN_ID');
update_option('xtreampro_api_url', getenv('API_URL'));
update_option('xtreampro_api_key', getenv('API_KEY'));
update_option('xtreampro_panel_url', 'https://panel.example.test');
delete_transient('xtreampro_packages');

echo "== api\n";
$info = XtreamPro_API::user_info();
$check('user_info', !is_wp_error($info) && !empty($info['username']), is_wp_error($info) ? $info->get_error_message() : $info);
$pk = XtreamPro_API::packages(true);
$check('packages', is_array($pk) && count($pk) >= 1, is_wp_error($pk) ? $pk->get_error_message() : $pk);
$pricing = XtreamPro_API::pricing();
$check('pricing', !is_wp_error($pricing) && isset($pricing['credits'], $pricing['packages'], $pricing['sub_reseller']), is_wp_error($pricing) ? $pricing->get_error_message() : $pricing);
// A package sold as an official period on a plain line: the panel says so itself (`sells`); the sample
// catalogue also holds trial-only ones and box packages, and the e2e setup adds a MAG-only one.
$official = array_values(array_filter($pk, function ($p) { return !empty($p['is_official']) && XtreamPro_API::sells_line($p); }));
$check('the panel offers an official package that sells as a line', count($official) >= 1, $pk);
$boxes = array_values(array_filter($pk, function ($p) { return !empty($p['is_official']) && !XtreamPro_API::sells_line($p); }));
$boxPkg = null; foreach ($boxes as $b) { if ($b['name'] === 'E2E box only') { $boxPkg = $b; } }
$check('a package for boxes only is told apart by `sells` (the e2e setup adds one)', $boxPkg !== null && $boxPkg['sells'] === array('mag'), wp_list_pluck($boxes, 'name'));
$lp = XtreamPro_API::line_packages();
$check('the package pickers offer only the packages that sell as a line', !is_wp_error($lp) && count($lp) === count($pk) - count($boxes) && !in_array((int) $boxPkg['id'], array_map('intval', wp_list_pluck($lp, 'id')), true), is_wp_error($lp) ? $lp->get_error_message() : wp_list_pluck($lp, 'name'));
$check('a panel that sends no sells counts as selling lines', XtreamPro_API::sells_line(array('id' => 1)) && !XtreamPro_API::sells_line(array('sells' => array('mag'))));
$short = XtreamPro_API::check_credits(array(array('kind' => 'line', 'package_id' => (int) $boxPkg['id'], 'trial' => false, 'units' => 1)));
$check('selling a box-only package as a line is refused before the panel is asked, with a readable reason', is_wp_error($short) && $short->get_error_code() === 'INVALID_PACKAGE' && stripos($short->get_error_message(), 'boxes only') !== false, is_wp_error($short) ? $short->get_error_message() : $short);
$check('a renewal is not refused for that reason', XtreamPro_API::check_credits(array(array('kind' => 'line', 'package_id' => (int) $boxPkg['id'], 'trial' => false, 'units' => 1, 'renewal' => true))) === true);
$check('the shortcode leaves the box package out', strpos(do_shortcode('[xtreampro_packages]'), 'E2E box only') === false && strpos(do_shortcode('[xtreampro_packages]'), esc_html($official[0]['name'])) !== false);
$pkgId = (int) $official[0]['id'];
$pkName = $official[0]['name'];
$logs = XtreamPro_API::request('GET', 'api_logs', array('limit' => 50));
$names = is_wp_error($logs) ? array() : wp_list_pluck($logs, 'connector');
$check('the panel call log names the connector', in_array('wordpress/' . XTREAMPRO_VERSION, $names, true), array_unique($names));

$uid = wp_insert_user(array('user_login' => 'cust' . $run, 'user_pass' => wp_generate_password(), 'user_email' => 'cust' . $run . '@example.test', 'first_name' => 'Ann', 'last_name' => 'Berg', 'role' => 'customer'));
$check('customer', !is_wp_error($uid), is_wp_error($uid) ? $uid->get_error_message() : '');
$mkProduct = function ($name, array $meta) {
    $p = new WC_Product_Simple();
    $p->set_name($name); $p->set_regular_price('10'); $p->set_virtual(true); $p->set_status('publish');
    foreach ($meta as $k => $v) { $p->update_meta_data($k, $v); }
    $p->save();
    return $p;
};
$mkOrder = function ($product, $qty, $customer, array $itemMeta = array(), $email = null) use ($run) {
    $o = wc_create_order(array('customer_id' => $customer));
    $itemId = $o->add_product($product, $qty);
    foreach ($itemMeta as $k => $v) { wc_add_order_item_meta($itemId, $k, $v, true); }
    $o->set_billing_email($email === null ? 'cust' . $run . '@example.test' : $email); $o->set_billing_first_name('Ann'); $o->set_billing_last_name('Berg');
    $o->calculate_totals(); $o->save();
    return wc_get_order($o->get_id());
};
$lineIds = function ($order) {
    $ids = array();
    foreach (wc_get_order($order->get_id())->get_items() as $item) {
        foreach (array_values($item->get_meta('_xtreampro_line_id', false)) as $m) { $ids[] = (int) $m->value; }
    }
    return $ids;
};
$notes = function ($order) { return implode(' | ', wp_list_pluck(wc_get_order_notes(array('order_id' => $order->get_id())), 'content')); };

echo "== lines\n";
$prod = $mkProduct('IPTV 12 months', array('_xtreampro_package_id' => $pkgId, '_xtreampro_trial' => 'no'));
$order = $mkOrder($prod, 2, $uid);
$order->update_status('completed');
$ids = $lineIds($order);
$check('completed order creates one line per unit', count($ids) === 2 && $ids[0] !== $ids[1], array($ids, $notes($order)));
$order = wc_get_order($order->get_id());
$order->update_status('processing'); $order->update_status('completed');
$check('completing again provisions nothing more', count($lineIds($order)) === 2, $lineIds($order));
$check('customer owns the lines', count(XtreamPro_API::user_lines($uid)) === 2, XtreamPro_API::user_lines($uid));
$line = XtreamPro_API::get_line($ids[0]);
$check('line is active on the panel', !is_wp_error($line) && $line['status'] === 'active', is_wp_error($line) ? $line->get_error_message() : $line);
ob_start(); XtreamPro_WooCommerce::page_credentials(wc_get_order($order->get_id())); $html = ob_get_clean();
$check('order page shows username, password and playlist', strpos($html, $line['username']) !== false && strpos($html, 'get.php') !== false && strpos($html, $line['password']) !== false, substr($html, 0, 300));
$item0 = array_values(wc_get_order($order->get_id())->get_items())[0];
$stored = json_decode((string) $item0->get_meta('_xtreampro_links_' . $ids[0]), true);
$check('the links the panel returned were stored with the line', is_array($stored) && !empty($stored['m3u']) && !empty($stored['web_player']), $stored);
$check('order page shows the API links (HLS playlist, guide, web player), not assembled ones', strpos($html, 'output=m3u8') !== false && strpos($html, 'xmltv.php') !== false && strpos($html, '/player/') !== false, substr($html, 0, 600));
// An order of version 1.0.0 has no stored links: the M3U and web player links are assembled for it.
foreach ($ids as $lid) { $item0->delete_meta_data('_xtreampro_links_' . $lid); }
$item0->save();
ob_start(); XtreamPro_WooCommerce::page_credentials(wc_get_order($order->get_id())); $legacy = ob_get_clean();
$check('an order without stored links still shows assembled M3U and web player links', strpos($legacy, 'get.php') !== false && strpos($legacy, '/player/') !== false && strpos($legacy, 'output=m3u8') === false, substr($legacy, 0, 300));
$mailer = WC()->mailer(); $emails = $mailer->get_emails(); $completed = $emails['WC_Email_Customer_Completed_Order'];
ob_start(); XtreamPro_WooCommerce::email_credentials(wc_get_order($order->get_id()), false, false, $completed); $mail = ob_get_clean();
$check('completed-order email carries the credentials', strpos($mail, $line['username']) !== false, substr($mail, 0, 200));
ob_start(); XtreamPro_WooCommerce::email_credentials(wc_get_order($order->get_id()), true, false, $completed); $adminMail = ob_get_clean();
$check('admin copy of the email has no credentials', $adminMail === '', substr($adminMail, 0, 100));

$exp = (int) $line['exp_date'];
$renew = $mkOrder($prod, 1, $uid, array('_xtreampro_renew_line_id' => $ids[0]));
$renew->update_status('completed');
$after = XtreamPro_API::get_line($ids[0]);
$check('renewal order extends the line', !is_wp_error($after) && (int) $after['exp_date'] > $exp, array($exp, is_wp_error($after) ? $after->get_error_message() : $after['exp_date'], $notes($renew)));
$other = wp_insert_user(array('user_login' => 'thief' . $run, 'user_pass' => wp_generate_password(), 'user_email' => 'thief' . $run . '@example.test', 'role' => 'customer'));
$steal = $mkOrder($prod, 1, $other, array('_xtreampro_renew_line_id' => $ids[0]));
$steal->update_status('completed');
$check("a customer cannot renew another customer's line", (int) XtreamPro_API::get_line($ids[0])['exp_date'] === (int) $after['exp_date'] && strpos($notes($steal), 'does not belong') !== false, $notes($steal));

$order = wc_get_order($order->get_id());
$order->update_status('cancelled');
$l1 = XtreamPro_API::get_line($ids[0]); $l2 = XtreamPro_API::get_line($ids[1]);
$check('cancelling the order disables its lines', $l1['status'] === 'disabled' && $l2['status'] === 'disabled', array($l1['status'], $l2['status'], $notes($order)));

echo "== shortcodes\n";
$html = do_shortcode('[xtreampro_packages]');
$check('[xtreampro_packages]', strpos($html, esc_html($pkName)) !== false, substr($html, 0, 200));
wp_set_current_user(0);
$check('[xtreampro_my_lines] asks a guest to log in', stripos(do_shortcode('[xtreampro_my_lines]'), 'log in') !== false, substr(do_shortcode('[xtreampro_my_lines]'), 0, 200));
wp_set_current_user($uid);
$html = do_shortcode('[xtreampro_my_lines]');
$check('[xtreampro_my_lines] lists the lines', strpos($html, $line['username']) !== false, substr($html, 0, 300));
$check('[xtreampro_my_lines] shows the links of the API (HLS, guide)', strpos($html, 'output=m3u8') !== false && strpos($html, 'xmltv.php') !== false, substr($html, 0, 300));

echo "== sub-reseller\n";
$rprod = $mkProduct('Reseller starter', array('_xtreampro_kind' => 'reseller', '_xtreampro_credits' => 30));
$ro = $mkOrder($rprod, 1, $uid);
$ro->update_status('completed');
$acct = XtreamPro_API::user_reseller($uid);
$check('order creates the reseller account', is_array($acct) && !empty($acct['user_id']), $notes($ro));
$sub = $acct ? XtreamPro_API::find_sub_user($acct['user_id'], $acct['username']) : null;
$check('account has 30 credits on the panel', is_array($sub) && (int) $sub['credits'] === 30 && $sub['status'] === 'active', is_wp_error($sub) ? $sub->get_error_message() : $sub);
$ro = wc_get_order($ro->get_id()); $ro->update_status('processing'); $ro->update_status('completed');
$sub = XtreamPro_API::find_sub_user($acct['user_id'], $acct['username']);
$check('completing again does not credit twice', (int) $sub['credits'] === 30, $sub['credits']);
ob_start(); XtreamPro_WooCommerce::page_credentials(wc_get_order($ro->get_id())); $html = ob_get_clean();
$check('order page shows the reseller account and sign-in link', strpos($html, $acct['username']) !== false && strpos($html, 'panel.example.test/login') !== false, substr($html, 0, 400));
$top = $mkOrder($rprod, 2, $uid);
$top->update_status('completed');
$sub = XtreamPro_API::find_sub_user($acct['user_id'], $acct['username']);
$check('a later order tops the same account up (30 + 2×30)', (int) $sub['credits'] === 90 && XtreamPro_API::user_reseller($uid)['user_id'] === $acct['user_id'], array($sub['credits'], $notes($top)));
delete_transient('xtreampro_rs_' . $uid);
$html = do_shortcode('[xtreampro_my_reseller]');
$check('[xtreampro_my_reseller]', strpos($html, $acct['username']) !== false && strpos($html, '90') !== false, substr($html, 0, 300));
$guest = $mkOrder($rprod, 1, 0);
$guest->update_status('completed');
$check('a guest order is not provisioned and says why', strpos($notes($guest), 'WordPress account') !== false, $notes($guest));
$top = wc_get_order($top->get_id()); $top->update_status('refunded');
$sub = XtreamPro_API::find_sub_user($acct['user_id'], $acct['username']);
$check('refunding the top-up takes its credits back, account stays active', (int) $sub['credits'] === 30 && $sub['status'] === 'active', array($sub['credits'], $sub['status'], $notes($top)));
$ro = wc_get_order($ro->get_id()); $ro->update_status('refunded');
$sub = XtreamPro_API::find_sub_user($acct['user_id'], $acct['username']);
$check('refunding the creating order disables the account', $sub['status'] === 'disabled' && (int) $sub['credits'] === 0, array($sub['credits'], $sub['status'], $notes($ro)));

echo "== credits are checked before anything is bought\n";
$buyer = wp_insert_user(array('user_login' => 'buyer' . $run, 'user_pass' => wp_generate_password(), 'user_email' => 'buyer' . $run . '@example.test', 'role' => 'customer'));
$before = (int) XtreamPro_API::user_info()['credits'];
$many = $mkOrder($prod, 500000, $buyer);
$many->update_status('completed');
$check('an order the credits cannot pay for creates no line at all', count($lineIds($many)) === 0 && stripos($notes($many), 'nothing was provisioned') !== false && stripos($notes($many), 'not enough credits') !== false, $notes($many));
$check('and the note names the amounts', preg_match('/needs \d+, the reseller account has \d+/', $notes($many)) === 1, $notes($many));
$check('and no credit was spent', (int) XtreamPro_API::user_info()['credits'] === $before);
$ghost = $mkProduct('Ghost package', array('_xtreampro_package_id' => 999999, '_xtreampro_trial' => 'no'));
$go = $mkOrder($ghost, 1, $buyer);
$go->update_status('completed');
$check('a package the reseller may not sell is refused with a clear note', count($lineIds($go)) === 0 && strpos($notes($go), 'not in the list') !== false, $notes($go));
$greedy = $mkProduct('Greedy reseller', array('_xtreampro_kind' => 'reseller', '_xtreampro_credits' => 99999999));
$gr = $mkOrder($greedy, 1, $buyer);
$gr->update_status('completed');
$check('a sub-reseller order that cannot be paid creates no account', XtreamPro_API::user_reseller($buyer) === null && stripos($notes($gr), 'not enough credits') !== false, $notes($gr));
$check('a free check does not block a retry that has nothing left to buy', XtreamPro_API::check_credits(array()) === true);

echo "== on refund or cancellation: delete permanently\n";
$dprod = $mkProduct('IPTV (deleted on refund)', array('_xtreampro_package_id' => $pkgId, '_xtreampro_trial' => 'no', '_xtreampro_on_cancel' => 'delete'));
$do = $mkOrder($dprod, 1, $buyer);
$do->update_status('completed');
$did = $lineIds($do);
$check('the order created its line', count($did) === 1, $notes($do));
$do = wc_get_order($do->get_id()); $do->update_status('cancelled');
$gone = XtreamPro_API::get_line($did[0]);
$check('cancelling deletes the line on the panel for good', is_wp_error($gone) && $gone->get_error_code() === 'RESOURCE_NOT_FOUND', is_wp_error($gone) ? $gone->get_error_code() : $gone);
$check('and the customer no longer lists it', !XtreamPro_API::user_owns_line($buyer, $did[0]) && strpos($notes($do), 'deleted') !== false, $notes($do));
$do = wc_get_order($do->get_id()); $do->update_status('refunded');
$check('refunding afterwards is harmless (already gone)', strpos($notes($do), 'could not') === false, $notes($do));
$drprod = $mkProduct('Reseller (deleted on refund)', array('_xtreampro_kind' => 'reseller', '_xtreampro_credits' => 5, '_xtreampro_on_cancel' => 'delete'));
$dr = $mkOrder($drprod, 1, $buyer, array(), 'buyer' . $run . '@example.test');
$dr->update_status('completed');
$dacct = XtreamPro_API::user_reseller($buyer);
$check('the order created the account', is_array($dacct), $notes($dr));
$dr = wc_get_order($dr->get_id()); $dr->update_status('refunded');
$check('refunding deletes the sub-reseller account for good', strpos($notes($dr), 'reseller account deleted') !== false && XtreamPro_API::user_reseller($buyer) === null, $notes($dr));
$gonesub = $dacct ? XtreamPro_API::find_sub_user($dacct['user_id'], $dacct['username']) : null;
$check('and the panel no longer lists it', is_wp_error($gonesub), is_wp_error($gonesub) ? '' : $gonesub);

echo "== webhooks from the panel\n";
update_option(XtreamPro_Webhook::OPTION_SECRET, 'whsec_test_' . $run);
$wl = XtreamPro_API::get_line($ids[1]);
$hookOrder = wc_get_order($order->get_id());
$post = function ($body, $headers) {
    $req = new WP_REST_Request('POST', '/xtreampro/v1/webhook');
    $req->set_body($body);
    foreach ($headers as $k => $v) { $req->set_header($k, $v); }
    $res = rest_do_request($req);
    return array($res->get_status(), $res->get_data());
};
$signed = function ($type, array $data, $ts = null, $secret = null, $evId = null) {
    $ts = $ts === null ? time() : $ts;
    static $n = 0;
    $n++;
    // every event has its own id (the receiver applies an id once)
    $body = wp_json_encode(array('id' => $evId === null ? 'evt_t_' . getenv('RUN_ID') . '_' . $n : $evId, 'type' => $type, 'created' => time(), 'data' => $data));
    $sig = 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, $secret === null ? 'whsec_test_' . getenv('RUN_ID') : $secret);
    return array($body, array('X-Xtream-Timestamp' => (string) $ts, 'X-Xtream-Signature' => $sig));
};
list($b, $h) = $signed('ping', array());
$check('a signed ping is accepted', $post($b, $h)[0] === 200);
list($b, $h) = $signed('line.expired', array('line_id' => $ids[1], 'exp_date' => 1700000000));
$r = $post($b, $h);
$check('a signed line.expired is accepted and recorded', $r[0] === 200 && $r[1]['message'] === 'recorded', $r);
$entry = null; foreach (XtreamPro_API::user_lines($uid) as $e) { if ((int) $e['line_id'] === $ids[1]) { $entry = $e; } }
$check('the status kept with the customer\'s line is "expired"', is_array($entry) && $entry['status'] === 'expired', $entry);
$check('and the order got a note', strpos($notes($hookOrder), 'expired on the panel') !== false, $notes($hookOrder));
list($b, $h) = $signed('line.expired', array('line_id' => $ids[1]));
$check('a tampered body is refused (401)', $post($b . ' ', $h)[0] === 401);
$check('an unsigned call is refused (401)', $post($b, array('X-Xtream-Timestamp' => (string) time()))[0] === 401);
list($b, $h) = $signed('line.expired', array('line_id' => $ids[1]), null, 'whsec_other');
$check('a wrong secret is refused (401)', $post($b, $h)[0] === 401);
list($b, $h) = $signed('line.expired', array('line_id' => $ids[1]), time() - 3600);
$check('a replay outside the time window is refused (400), the signature being right', $post($b, $h)[0] === 400);
list($b, $h) = $signed('line.expired', array('line_id' => 987654321));
$check('an event of an unknown line is acknowledged and ignored', $post($b, $h)[1]['message'] === 'unknown line');
list($b, $h) = $signed('line.deleted', array('line_id' => $ids[1]));
$r = $post($b, $h);
$check('a signed line.deleted removes the line from the customer\'s list', $r[0] === 200 && !XtreamPro_API::user_owns_line($uid, $ids[1]) && XtreamPro_API::user_owns_line($uid, $ids[0]), $r);
// The panel delivers at least once and keeps the event id the same on every retry: an id is applied once.
$status = function () use ($uid, $ids) { foreach (XtreamPro_API::user_lines($uid) as $e) { if ((int) $e['line_id'] === $ids[0]) { return isset($e['status']) ? $e['status'] : ''; } } return null; };
$dupId = 'evt_dup_' . $run;
list($b, $h) = $signed('line.disabled', array('line_id' => $ids[0], 'owner_id' => '0197a1c2-5f3e-7d10-8a41-6b2c9e0d4f55', 'owner_username' => 'reseller_a'), null, null, $dupId);
$r = $post($b, $h);
$check('an event carrying owner_id / owner_username is applied (the fields are not needed)', $r[0] === 200 && $r[1]['message'] === 'recorded' && $status() === 'disabled', array($r, $status()));
$lines = XtreamPro_API::user_lines($uid); foreach ($lines as $i => $e) { if ((int) $e['line_id'] === $ids[0]) { $lines[$i]['status'] = 'active'; } } update_user_meta($uid, XtreamPro_API::USER_LINES_META, $lines);
$r = $post($b, $h);
$check('the same event id again is acknowledged (200) and not applied twice', $r[0] === 200 && $r[1]['message'] === 'duplicate' && $status() === 'active', array($r, $status()));
list($b2, $h2) = $signed('line.disabled', array('line_id' => $ids[0]));
$check('another event id of the same kind is applied', $post($b2, $h2)[1]['message'] === 'recorded' && $status() === 'disabled');
$noId = wp_json_encode(array('type' => 'line.enabled', 'created' => time(), 'data' => array('line_id' => $ids[0])));
$ts = (string) time(); $sig = 'sha256=' . hash_hmac('sha256', $ts . '.' . $noId, 'whsec_test_' . $run);
$check('an event without an id (older panel) is applied every time', $post($noId, array('X-Xtream-Timestamp' => $ts, 'X-Xtream-Signature' => $sig))[1]['message'] === 'recorded' && $post($noId, array('X-Xtream-Timestamp' => $ts, 'X-Xtream-Signature' => $sig))[1]['message'] === 'recorded');
$check('an id of an unexpected shape is not used for de-duplication', XtreamPro_Webhook::event_id(array('id' => str_repeat('a', 65))) === '' && XtreamPro_Webhook::event_id(array('id' => array('x'))) === '' && XtreamPro_Webhook::event_id(array('id' => 'evt_9f2c41d07b3a5e6c81d2f4a0')) === 'evt_9f2c41d07b3a5e6c81d2f4a0');
$check('the id is kept as a transient that WordPress expires (bounded retention)', (int) get_option('_transient_timeout_' . XtreamPro_Webhook::SEEN_PREFIX . md5($dupId)) > time() && (int) get_option('_transient_timeout_' . XtreamPro_Webhook::SEEN_PREFIX . md5($dupId)) <= time() + XtreamPro_Webhook::SEEN_TTL + 5);
$r = $post($b, array('X-Xtream-Timestamp' => (string) time(), 'X-Xtream-Signature' => 'sha256=00'));
$check('a bad signature is refused before the id is looked at (401)', $r[0] === 401);
list($b, $h) = $signed('line.expired', array('line_id' => 987654322, 'owner_id' => '0197a1c2-5f3e-7d10-8a41-6b2c9e0d4f55', 'owner_username' => 'reseller_a'));
$check('an event of a line owned by an account this site does not know is acknowledged and ignored', $post($b, $h)[1]['message'] === 'unknown line');
delete_option(XtreamPro_Webhook::OPTION_SECRET);
list($b, $h) = $signed('line.expired', array('line_id' => $ids[0]));
$check('with no secret set every call is refused (503)', $post($b, $h)[0] === 503);
$hook = XtreamPro_API::create_webhook('https://wp.example.test/wp-json/xtreampro/v1/webhook', XtreamPro_Webhook::EVENTS);
$check('the panel registers the webhook and returns its id and secret', !is_wp_error($hook) && !empty($hook['id']) && strpos((string) $hook['secret'], 'whsec_') === 0, is_wp_error($hook) ? $hook->get_error_message() : array_keys($hook));
$check('the panel can ping it', !is_wp_error($hook) && !is_wp_error(XtreamPro_API::test_webhook($hook['id'])));
$check('and delete it', !is_wp_error($hook) && !is_wp_error(XtreamPro_API::delete_webhook($hook['id'])));
$http = XtreamPro_API::create_webhook('http://wp.example.test/hook', XtreamPro_Webhook::EVENTS);
$check('an http address is refused by the panel (https only)', is_wp_error($http) && $http->get_error_code() === 'INVALID_REQUEST', is_wp_error($http) ? $http->get_error_code() : $http);

echo "== errors\n";
update_option('xtreampro_api_key', 'xk_wrong');
$bad = $mkOrder($prod, 1, $uid);
$bad->update_status('completed');
$check('a failed provisioning leaves a readable order note and no line', count($lineIds($bad)) === 0 && stripos($notes($bad), 'API key') !== false, $notes($bad));
update_option('xtreampro_api_key', getenv('API_KEY'));
$bad = wc_get_order($bad->get_id());
XtreamPro_WooCommerce::on_manual($bad);
$check('"provision again" retries it', count($lineIds($bad)) === 1, $notes($bad));

echo "== a request id whose line was deleted (REQUEST_ID_SPENT), and box-only packages\n";
$rid = 'wp-spent-' . $run;
$first = XtreamPro_API::create_line($pkgId, false, $rid);
$check('the first sale under the request id creates a line', !is_wp_error($first) && !empty($first['line']['id']), is_wp_error($first) ? $first->get_error_message() : $first);
XtreamPro_API::set_line_state((int) $first['line']['id'], 'delete');
$again = XtreamPro_API::create_line($pkgId, false, $rid);
$check('selling again under the spent request id is refused with a readable message', is_wp_error($again) && $again->get_error_code() === 'REQUEST_ID_SPENT' && stripos($again->get_error_message(), 'already made') !== false && stripos($again->get_error_message(), 'new order') !== false, is_wp_error($again) ? $again->get_error_message() : $again);
$had = count($lineIds($hookOrder));
XtreamPro_WooCommerce::on_manual(wc_get_order($hookOrder->get_id()));
$check('provisioning an order again after its line was removed from the list sends no request id twice: its items keep their line records', count($lineIds($hookOrder)) === $had && stripos($notes($hookOrder), 'REQUEST_ID_SPENT') === false && stripos($notes($hookOrder), 'already made') === false, $notes($hookOrder));
$boxProd = $mkProduct('Box package', array('_xtreampro_package_id' => (int) $boxPkg['id'], '_xtreampro_trial' => 'no'));
$boxOrder = $mkOrder($boxProd, 1, $uid);
$boxOrder->update_status('completed');
$check('an order for a box-only package creates nothing and says why', count($lineIds($boxOrder)) === 0 && stripos($notes($boxOrder), 'nothing was provisioned') !== false && stripos($notes($boxOrder), 'boxes only') !== false, $notes($boxOrder));

$check('no PHP warnings or notices from the plugin', count($notices) === 0, array_slice(array_unique($notices), 0, 6));
echo $fail ? "\n$fail FAILED\n" : "\nALL OK\n";
