<?php
// End-to-end run of the plugin's Easy Digital Downloads integration inside a
// real WordPress + EDD 3.x, against a real panel API. Not shipped.
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
// Under wp-cli EDD activates without creating its tables (it does that on the
// first admin request): create them now. Harmless when they exist.
foreach (array('order', 'note', 'customer', 'adjustment', 'order_adjustment', 'order_item', 'log', 'log_api_request', 'log_error', 'log_login', 'log_email') as $component) {
    foreach (array('table', 'meta') as $kind) {
        $t = edd_get_component_interface($component, $kind);
        if ($t && method_exists($t, 'maybe_upgrade')) { $t->maybe_upgrade(); }
    }
}
$run = getenv('RUN_ID');
$apiKey = getenv('API_KEY');
update_option('xtreampro_api_url', getenv('API_URL'));
update_option('xtreampro_api_key', $apiKey);
update_option('xtreampro_panel_url', 'https://panel.example.test');
delete_transient('xtreampro_packages');

// Mail is captured instead of sent. EDD queues "after payment" actions (the
// receipt email) in WP cron; run them right away.
$mails = array();
add_filter('pre_wp_mail', function ($pre, $atts) use (&$mails) { $mails[] = $atts; return true; }, 10, 2);
add_filter('edd_use_after_payment_actions', '__return_false');

echo "== environment\n";
$check('Easy Digital Downloads 3.x is active', function_exists('edd_get_order') && defined('EDD_VERSION') && version_compare(EDD_VERSION, '3.0', '>='), defined('EDD_VERSION') ? EDD_VERSION : 'missing');
$check('the EDD integration is loaded, WooCommerce is not', class_exists('XtreamPro_EDD') && !class_exists('WooCommerce'));
$check('the order hooks are registered', has_action('edd_complete_purchase', array('XtreamPro_EDD', 'on_complete')) !== false && has_action('edd_transition_order_status', array('XtreamPro_EDD', 'on_transition')) !== false);
$check('the retry action is registered', has_action('admin_post_xtreampro_edd_provision', array('XtreamPro_EDD', 'handle_retry')) !== false);
$pk = XtreamPro_API::packages(true);
$check('packages from the panel', is_array($pk) && count($pk) >= 1, is_wp_error($pk) ? $pk->get_error_message() : $pk);
// A package sold as an official period (the sample catalogue also holds trial-only ones).
$official = array_values(array_filter($pk, function ($p) { return !empty($p['is_official']) && XtreamPro_API::sells_line($p); }));
$check('the panel offers an official package that sells as a line', count($official) >= 1, $pk);
$lp = XtreamPro_API::line_packages();
$check('the download picker offers only packages that sell as a line (the box-only one is left out)', !is_wp_error($lp) && count($lp) < count($pk) && count(array_filter($lp, function ($p) { return $p['name'] === 'E2E box only'; })) === 0, is_wp_error($lp) ? $lp->get_error_message() : wp_list_pluck($lp, 'name'));
$pkgId = (int) $official[0]['id'];

$admin = get_user_by('login', 'admin');
$uid = wp_insert_user(array('user_login' => 'cust' . $run, 'user_pass' => wp_generate_password(), 'user_email' => 'cust' . $run . '@example.test', 'first_name' => 'Ann', 'last_name' => 'Berg', 'role' => 'subscriber'));
$check('customer', !is_wp_error($uid), is_wp_error($uid) ? $uid->get_error_message() : '');
$other = wp_insert_user(array('user_login' => 'other' . $run, 'user_pass' => wp_generate_password(), 'user_email' => 'other' . $run . '@example.test', 'role' => 'subscriber'));

$mkDownload = function ($name, array $meta) {
    $id = wp_insert_post(array('post_type' => 'download', 'post_title' => $name, 'post_status' => 'publish'));
    update_post_meta($id, 'edd_price', '10.00');
    foreach ($meta as $k => $v) { update_post_meta($id, $k, $v); }
    return $id;
};
// $items: download id => quantity; $user 0 = guest. Returns the pending order.
$mkOrder = function (array $items, $user, $email) {
    $cart = array(); $total = 0; $downloads = array();
    foreach ($items as $did => $qty) {
        $cart[] = array('name' => get_the_title($did), 'id' => $did, 'item_number' => array('id' => $did, 'quantity' => $qty, 'options' => array()),
            'item_price' => 10, 'quantity' => $qty, 'discount' => 0, 'subtotal' => 10 * $qty, 'tax' => 0, 'fees' => array(), 'price' => 10 * $qty);
        $downloads[] = array('id' => $did, 'quantity' => $qty, 'options' => array());
        $total += 10 * $qty;
    }
    $id = edd_build_order(array(
        'price' => $total, 'date' => gmdate('Y-m-d H:i:s'), 'user_email' => $email, 'purchase_key' => strtolower(md5(uniqid('', true))),
        'currency' => 'USD', 'downloads' => $downloads, 'cart_details' => $cart, 'gateway' => 'manual', 'status' => 'pending',
        'user_info' => array('id' => $user, 'email' => $email, 'first_name' => 'Ann', 'last_name' => 'Berg', 'discount' => 'none'),
    ));
    return edd_get_order($id);
};
$complete = function ($order) { edd_update_order_status($order->id, 'complete'); return edd_get_order($order->id); };
$state = function ($order) { $s = edd_get_order_meta($order->id, '_xtreampro_edd', true); return is_array($s) ? $s : array('items' => array()); };
$lineIds = function ($order) use ($state) {
    $ids = array();
    foreach ($state($order)['items'] as $rec) { foreach (($rec['lines'] ?? array()) as $l) { $ids[] = (int) $l['id']; } }
    return $ids;
};
$notes = function ($order) {
    $out = array();
    foreach (edd_get_notes(array('object_id' => $order->id, 'object_type' => 'order', 'number' => 100)) as $n) { $out[] = $n->content; }
    return implode(' | ', $out);
};
$sub = function ($acct) { return XtreamPro_API::find_sub_user($acct['user_id'], $acct['username']); };
$email = 'cust' . $run . '@example.test';

echo "== download settings\n";
$lineDl = $mkDownload('IPTV 12 months', array('_xtreampro_package_id' => $pkgId, '_xtreampro_trial' => 'no'));
$resDl = $mkDownload('Reseller starter', array('_xtreampro_kind' => 'reseller', '_xtreampro_credits' => 30));
$formDl = $mkDownload('Form test', array());
ob_start(); XtreamPro_EDD::render_metabox(get_post($formDl)); $box = ob_get_clean();
$check('the metabox shows type, package, trial and credits plus a nonce', strpos($box, '_xtreampro_kind') !== false && strpos($box, '_xtreampro_package_id') !== false && strpos($box, '_xtreampro_trial') !== false && strpos($box, '_xtreampro_credits') !== false && strpos($box, 'xtreampro_edd_nonce') !== false && strpos($box, esc_html($pk[0]['name'])) !== false, substr($box, 0, 300));
$check('the metabox is registered on downloads', (function () { global $wp_meta_boxes; do_action('add_meta_boxes', 'download', get_post(0)); return isset($wp_meta_boxes['download']['normal']['default']['xtreampro_edd']); })());
$post = function (array $data) { $_POST = $data; };
wp_set_current_user($other);   // a subscriber: no right to edit the download
$post(array('xtreampro_edd_nonce' => wp_create_nonce('xtreampro_edd_save'), '_xtreampro_kind' => 'line', '_xtreampro_package_id' => (string) $pkgId, '_xtreampro_trial' => 'on', '_xtreampro_credits' => '5'));
XtreamPro_EDD::save_download($formDl);
$check('saving without the capability changes nothing', get_post_meta($formDl, '_xtreampro_package_id', true) === '');
wp_set_current_user($admin->ID);
$post(array('_xtreampro_kind' => 'line', '_xtreampro_package_id' => (string) $pkgId));
XtreamPro_EDD::save_download($formDl);
$check('saving without the nonce changes nothing', get_post_meta($formDl, '_xtreampro_package_id', true) === '');
$post(array('xtreampro_edd_nonce' => 'bogus', '_xtreampro_kind' => 'line', '_xtreampro_package_id' => (string) $pkgId));
XtreamPro_EDD::save_download($formDl);
$check('saving with a wrong nonce changes nothing', get_post_meta($formDl, '_xtreampro_package_id', true) === '');
$post(array('xtreampro_edd_nonce' => wp_create_nonce('xtreampro_edd_save'), '_xtreampro_kind' => 'reseller', '_xtreampro_package_id' => (string) $pkgId, '_xtreampro_trial' => 'yes', '_xtreampro_credits' => '25'));
XtreamPro_EDD::save_download($formDl);
$check('an admin with the nonce saves the same meta keys as WooCommerce', get_post_meta($formDl, '_xtreampro_kind', true) === 'reseller' && (int) get_post_meta($formDl, '_xtreampro_package_id', true) === $pkgId && get_post_meta($formDl, '_xtreampro_trial', true) === 'yes' && (int) get_post_meta($formDl, '_xtreampro_credits', true) === 25);
$post(array('xtreampro_edd_nonce' => wp_create_nonce('xtreampro_edd_save'), '_xtreampro_kind' => 'bogus', '_xtreampro_package_id' => '0', '_xtreampro_credits' => 'abc'));
XtreamPro_EDD::save_download($formDl);
$check('bad values are sanitised (kind -> line, no package, trial no, credits 0)', get_post_meta($formDl, '_xtreampro_kind', true) === 'line' && get_post_meta($formDl, '_xtreampro_package_id', true) === '' && get_post_meta($formDl, '_xtreampro_trial', true) === 'no' && (int) get_post_meta($formDl, '_xtreampro_credits', true) === 0, get_post_meta($formDl));
$_POST = array();
wp_set_current_user(0);

echo "== lines\n";
$order = $mkOrder(array($lineDl => 2), $uid, $email);
$check('a pending order provisions nothing', count($lineIds($order)) === 0 && $order->status === 'pending');
$order = $complete($order);
$ids = $lineIds($order);
$check('a completed order creates one line per unit', count($ids) === 2 && $ids[0] !== $ids[1], array($ids, $notes($order)));
$check('the order notes say so', substr_count($notes($order), 'line created') === 2, $notes($order));
XtreamPro_EDD::provision(edd_get_order($order->id));
$check('provisioning again creates nothing more', count($lineIds($order)) === 2 && substr_count($notes($order), 'line created') === 2, $lineIds($order));
$order = edd_get_order($order->id);
edd_update_order_status($order->id, 'pending'); edd_update_order_status($order->id, 'complete');
$check('completing the order a second time does not duplicate', count($lineIds($order)) === 2, $lineIds($order));
$line = XtreamPro_API::get_line($ids[0]);
$check('the line is active on the panel', !is_wp_error($line) && $line['status'] === 'active', is_wp_error($line) ? $line->get_error_message() : $line);
$st = $state($order);
$rec = reset($st['items']);
$check('what was provisioned is kept in the order meta', count($rec['lines']) === 2 && $rec['lines'][0]['username'] === $line['username'] && $rec['lines'][0]['password'] === $line['password']);
$check('the customer owns the lines in the shared user meta', count(XtreamPro_API::user_lines($uid)) === 2 && XtreamPro_API::user_owns_line($uid, $ids[0], $lineDl), XtreamPro_API::user_lines($uid));
$guest = $mkOrder(array($lineDl => 1), 0, 'guest' . $run . '@example.test');
$guest = $complete($guest);
$check('a guest order of a line product still creates the line (no user meta)', count($lineIds($guest)) === 1 && count(XtreamPro_API::user_lines($uid)) === 2, $notes($guest));
$trialDl = $mkDownload('Trial', array('_xtreampro_package_id' => $pkgId, '_xtreampro_trial' => 'yes'));
$tr = $complete($mkOrder(array($trialDl => 1), $uid, $email));
$check('a trial download asks the panel for a trial (refused when the package has none: readable note, no line)', (count($lineIds($tr)) === 1) || (count($lineIds($tr)) === 0 && stripos($notes($tr), 'package') !== false), $notes($tr));

echo "== credentials\n";
$html = (function () use ($order, $uid) { wp_set_current_user($uid); ob_start(); XtreamPro_EDD::receipt_credentials(edd_get_order($order->id)); return ob_get_clean(); })();
$check('the receipt page shows username, password and playlist to the buyer', strpos($html, $line['username']) !== false && strpos($html, $line['password']) !== false && strpos($html, 'get.php') !== false, substr($html, 0, 300));
$check('... and the web player', strpos($html, '/player/') !== false);
$check('the receipt shows the links the panel returned (HLS playlist, guide)', strpos($html, 'output=m3u8') !== false && strpos($html, 'xmltv.php') !== false, substr($html, 0, 500));
$check('they were stored with the line in the order meta', !empty($rec['lines'][0]['links']['m3u']) && !empty($rec['lines'][0]['links']['web_player']), $rec['lines'][0]);
$logs = XtreamPro_API::request('GET', 'api_logs', array('limit' => 50));
$check('the panel call log names the connector', !is_wp_error($logs) && in_array('wordpress/' . XTREAMPRO_VERSION, wp_list_pluck($logs, 'connector'), true), is_wp_error($logs) ? $logs->get_error_message() : array_unique(wp_list_pluck($logs, 'connector')));
$html = (function () use ($order, $other) { wp_set_current_user($other); ob_start(); XtreamPro_EDD::receipt_credentials(edd_get_order($order->id)); return ob_get_clean(); })();
$check("another signed-in user viewing the receipt does not get the passwords", strpos($html, $line['password']) === false && strpos($html, 'get.php') === false, substr($html, 0, 200));
$html = (function () use ($order, $admin) { wp_set_current_user($admin->ID); ob_start(); XtreamPro_EDD::receipt_credentials(edd_get_order($order->id)); return ob_get_clean(); })();
$check('a shop admin viewing it does not get the passwords either', strpos($html, $line['password']) === false);
wp_set_current_user(0);
$check('the receipt of an order without panel items is untouched', (function () use ($mkOrder, $complete, $mkDownload, $uid, $email) {
    $plain = $mkDownload('Plain ebook', array());
    $o = $complete($mkOrder(array($plain => 1), $uid, $email));
    ob_start(); XtreamPro_EDD::receipt_credentials($o); return ob_get_clean() === '';
})());

$check('the {xtreampro_credentials} email tag is registered', edd_email_tag_exists('xtreampro_credentials'));
$receipt = new \EDD\Emails\Types\OrderReceipt(edd_get_order($order->id));
$raw = $receipt->get_raw_body_content();
$check("the receipt email carries the tag even when the shop's template lacks it", strpos($raw, '{xtreampro_credentials}') !== false);
$body = EDD()->email_tags->do_tags('Hello {xtreampro_credentials} bye', $order->id, edd_get_order($order->id), $receipt);
$check('the tag in the customer receipt shows username and password', strpos($body, $line['username']) !== false && strpos($body, $line['password']) !== false && strpos($body, '{xtreampro') === false, substr($body, 0, 300));
$adminMail = new \EDD\Emails\Types\AdminOrderNotice(edd_get_order($order->id));
$body = EDD()->email_tags->do_tags('Sale {xtreampro_credentials}', $order->id, edd_get_order($order->id), $adminMail);
$check('the tag in the admin sale notice has no password', strpos($body, $line['password']) === false && strpos($body, $line['username']) !== false, substr($body, 0, 300));
$body = edd_do_email_tags('{xtreampro_credentials}', $order->id, edd_get_order($order->id), 'order');
$check('the tag without an email object has no password', strpos($body, $line['password']) === false);

// The real thing: a new order is completed and EDD sends the receipt.
$mails = array();
$o2 = $complete($mkOrder(array($lineDl => 1), $uid, $email));
$ids2 = $lineIds($o2);
$l2 = $ids2 ? XtreamPro_API::get_line($ids2[0]) : array('username' => '', 'password' => '');
$toBuyer = array_filter($mails, function ($m) use ($email) { return in_array($email, (array) $m['to'], true); });
$toBuyer = reset($toBuyer);
$check('EDD sent the receipt to the buyer', is_array($toBuyer), count($mails) . ' mails');
$check('the receipt email contains the new line\'s username and password', $toBuyer && strpos($toBuyer['message'], $l2['username']) !== false && strpos($toBuyer['message'], $l2['password']) !== false, $toBuyer ? substr(wp_strip_all_tags($toBuyer['message']), 0, 300) : '');
$others = array_filter($mails, function ($m) use ($email) { return !in_array($email, (array) $m['to'], true); });
$leak = false; foreach ($others as $m) { if ($l2['password'] !== '' && strpos($m['message'], $l2['password']) !== false) { $leak = true; } }
$check('no other email (admin sale notice) contains the password', !$leak, count($others) . ' other mails');

echo "== shortcodes\n";
wp_set_current_user($uid);
$out = do_shortcode('[xtreampro_my_lines]');
$check('[xtreampro_my_lines] lists the lines bought through EDD', strpos($out, $line['username']) !== false && strpos($out, 'Line #' . $ids[1]) !== false, substr($out, 0, 300));
wp_set_current_user(0);

echo "== refund\n";
$order = edd_get_order($order->id);
$refund = edd_refund_order($order->id);
$order = edd_get_order($order->id);
$check('edd_refund_order refunds the order', !is_wp_error($refund) && $order->status === 'refunded', is_wp_error($refund) ? $refund->get_error_message() : $order->status);
$l1 = XtreamPro_API::get_line($ids[0]); $l2b = XtreamPro_API::get_line($ids[1]);
$check('refunding disables both lines (never deletes)', $l1['status'] === 'disabled' && $l2b['status'] === 'disabled', array($l1['status'], $l2b['status'], $notes($order)));
$check('the notes say so', substr_count($notes($order), 'disabled.') === 2, $notes($order));
$n = substr_count($notes($order), 'disabled.');
edd_update_order_status($order->id, 'complete'); edd_update_order_status($order->id, 'refunded');
$check('refunding twice does not repeat the calls', substr_count($notes($order), 'disabled.') === $n, $notes($order));
$guestLine = $lineIds($guest)[0];
edd_update_order_status($guest->id, 'revoked');
$check('a revoked order disables its line', XtreamPro_API::get_line($guestLine)['status'] === 'disabled');

echo "== sub-reseller\n";
$rorder = $complete($mkOrder(array($resDl => 1), $uid, $email));
$acct = XtreamPro_API::user_reseller($uid);
$check('the order creates the reseller account', is_array($acct) && !empty($acct['user_id']), $notes($rorder));
$s = $acct ? $sub($acct) : null;
$check('the account has 30 credits, is active, has the buyer\'s email', is_array($s) && (int) $s['credits'] === 30 && $s['status'] === 'active' && $s['email'] === $email, is_wp_error($s) ? $s->get_error_message() : $s);
XtreamPro_EDD::provision(edd_get_order($rorder->id));
edd_update_order_status($rorder->id, 'pending'); edd_update_order_status($rorder->id, 'complete');
$check('completing again neither creates a second account nor credits twice', (int) $sub($acct)['credits'] === 30 && XtreamPro_API::user_reseller($uid)['user_id'] === $acct['user_id'], $sub($acct)['credits']);
wp_set_current_user($uid);
ob_start(); XtreamPro_EDD::receipt_credentials(edd_get_order($rorder->id)); $html = ob_get_clean();
$st = $state($rorder); $rr = reset($st['items']);
$check('the receipt shows the account, its password and the sign-in link', strpos($html, $acct['username']) !== false && strpos($html, $rr['sub_pass']) !== false && strpos($html, 'panel.example.test/login') !== false && strpos($html, '30') !== false, substr($html, 0, 400));
wp_set_current_user($other);
ob_start(); XtreamPro_EDD::receipt_credentials(edd_get_order($rorder->id)); $html = ob_get_clean();
$check('someone else does not get the account password', strpos($html, $rr['sub_pass']) === false && strpos($html, $acct['username']) !== false);
wp_set_current_user(0);
$body = EDD()->email_tags->do_tags('{xtreampro_credentials}', $rorder->id, edd_get_order($rorder->id), new \EDD\Emails\Types\OrderReceipt(edd_get_order($rorder->id)));
$check('the customer email tag shows the reseller account and password', strpos($body, $acct['username']) !== false && strpos($body, $rr['sub_pass']) !== false);
$top = $complete($mkOrder(array($resDl => 2), $uid, $email));
$check('a second order tops up the same account (30 + 2 x 30)', (int) $sub($acct)['credits'] === 90 && XtreamPro_API::user_reseller($uid)['user_id'] === $acct['user_id'], array($sub($acct)['credits'], $notes($top)));
$st = $state($top); $tr2 = reset($st['items']);
$check('the top-up order did not create an account and shows no password', empty($tr2['created']) && (function () use ($top, $uid) { wp_set_current_user($uid); ob_start(); XtreamPro_EDD::receipt_credentials(edd_get_order($top->id)); $h = ob_get_clean(); wp_set_current_user(0); return strpos($h, 'Password') === false && strpos($h, '60') !== false; })());
delete_transient('xtreampro_rs_' . $uid);
wp_set_current_user($uid);
$html = do_shortcode('[xtreampro_my_reseller]');
$check('[xtreampro_my_reseller] shows the account bought through EDD with 90 credits', strpos($html, $acct['username']) !== false && strpos($html, '90') !== false, substr($html, 0, 300));
wp_set_current_user(0);
$g = $complete($mkOrder(array($resDl => 1), 0, 'guest2' . $run . '@example.test'));
$check('a guest order of a reseller product is refused with a note', strpos($notes($g), 'WordPress account') !== false && empty($state($g)['items']), $notes($g));
edd_update_order_status($top->id, 'refunded');
$s = $sub($acct);
$check('refunding the top-up takes its 60 credits back, the account stays active', (int) $s['credits'] === 30 && $s['status'] === 'active', array($s['credits'], $s['status'], $notes($top)));
edd_update_order_status($top->id, 'complete'); edd_update_order_status($top->id, 'refunded');
$check('refunding it again does not take credits back twice', (int) $sub($acct)['credits'] === 30);
edd_update_order_status($rorder->id, 'refunded');
$s = $sub($acct);
$check('refunding the creating order takes the credits back and disables the account', $s['status'] === 'disabled' && (int) $s['credits'] === 0, array($s['credits'], $s['status'], $notes($rorder)));

echo "== credits are checked before anything is bought; deletion on refund\n";
$before = (int) XtreamPro_API::user_info()['credits'];
$many = $complete($mkOrder(array($lineDl => 500000), $uid, $email));
$check('an order the credits cannot pay for creates no line at all', count($lineIds($many)) === 0 && stripos($notes($many), 'nothing was provisioned') !== false && stripos($notes($many), 'not enough credits') !== false, $notes($many));
$check('and the note names the amounts, and no credit was spent', preg_match('/needs \d+, the reseller account has \d+/', $notes($many)) === 1 && (int) XtreamPro_API::user_info()['credits'] === $before, $notes($many));
$ghostDl = $mkDownload('Ghost package', array('_xtreampro_package_id' => 999999, '_xtreampro_trial' => 'no'));
$go = $complete($mkOrder(array($ghostDl => 1), $uid, $email));
$check('a package the reseller may not sell is refused with a clear note', count($lineIds($go)) === 0 && strpos($notes($go), 'not in the list') !== false, $notes($go));
ob_start(); XtreamPro_EDD::render_metabox(get_post($formDl)); $box = ob_get_clean();
$check('the metabox offers "On refund or revoked order" with the warning that deleting is final', strpos($box, '_xtreampro_on_cancel') !== false && strpos($box, 'FINAL') !== false, substr($box, -600));
$delDl = $mkDownload('IPTV (deleted on refund)', array('_xtreampro_package_id' => $pkgId, '_xtreampro_trial' => 'no', '_xtreampro_on_cancel' => 'delete'));
$do = $complete($mkOrder(array($delDl => 1), $uid, $email));
$did = $lineIds($do);
$check('the order created its line', count($did) === 1, $notes($do));
edd_update_order_status($do->id, 'refunded');
$gone = XtreamPro_API::get_line($did[0]);
$check('refunding deletes the line on the panel for good', is_wp_error($gone) && $gone->get_error_code() === 'RESOURCE_NOT_FOUND', is_wp_error($gone) ? $gone->get_error_code() : $gone);
$check('and the customer no longer lists it, and the note says deleted', !XtreamPro_API::user_owns_line($uid, $did[0]) && strpos($notes(edd_get_order($do->id)), 'deleted') !== false, $notes(edd_get_order($do->id)));

echo "== errors\n";
update_option('xtreampro_api_key', 'xk_wrong');
$bad = $complete($mkOrder(array($lineDl => 1), $uid, $email));
$check('a failed provisioning leaves a readable order note and no line', count($lineIds($bad)) === 0 && stripos($notes($bad), 'API key') !== false, $notes($bad));
$check('... and nothing secret in the notes', strpos($notes($bad), $apiKey) === false);
$bad = edd_get_order($bad->id);
XtreamPro_EDD::provision($bad);
$check('retrying while the panel is still failing adds another note and still no line', count($lineIds($bad)) === 0 && substr_count($notes($bad), 'nothing was provisioned') === 2, $notes($bad));
update_option('xtreampro_api_key', $apiKey);
$_GET = array('order_id' => (string) $bad->id, '_wpnonce' => wp_create_nonce('xtreampro_edd_provision_' . $bad->id));
wp_set_current_user($admin->ID);
ob_start(); XtreamPro_EDD::retry_box($bad->id); $box = ob_get_clean();
$check('the order screen offers the retry button to a shop admin', strpos($box, 'xtreampro_edd_provision') !== false && strpos($box, '_wpnonce') !== false, substr($box, 0, 200));
wp_set_current_user($other);
ob_start(); XtreamPro_EDD::retry_box($bad->id); $box2 = ob_get_clean();
$check('... and not to anyone else', $box2 === '');
wp_set_current_user(0);
$_GET = array();
XtreamPro_EDD::provision(edd_get_order($bad->id));
$check('the retry creates the line', count($lineIds($bad)) === 1, $notes($bad));
XtreamPro_EDD::provision(edd_get_order($bad->id));
$check('and a further retry does nothing', count($lineIds($bad)) === 1);
$rep = edd_get_order($bad->id);
$check('the request id is stable: the line has the credentials the panel returned', XtreamPro_API::get_line($lineIds($bad)[0])['username'] === $state($bad)['items'][array_key_first($state($bad)['items'])]['lines'][0]['username']);
update_option('xtreampro_api_key', '');
$nc = $complete($mkOrder(array($lineDl => 1), $uid, $email));
$check('an unconfigured plugin says so in a note', stripos($notes($nc), 'not configured') !== false, $notes($nc));
update_option('xtreampro_api_key', $apiKey);

$check('no PHP warnings or notices from the plugin', count($notices) === 0, array_slice(array_unique($notices), 0, 6));
echo $fail ? "\n$fail FAILED\n" : "\nALL OK\n";
