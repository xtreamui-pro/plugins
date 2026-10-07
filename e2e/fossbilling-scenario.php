<?php
// End-to-end run of the Xtream UI Pro module inside a real FOSSBilling (run by fossbilling-run.sh
// with `php` inside the FOSSBilling container). Everything goes through FOSSBilling's own admin and
// client APIs and web pages; the panel is checked directly through its Reseller API.
//
// env: API_URL (panel as seen from this container), API_KEY (reseller key), FB_TOKEN (admin API token)
$fail = 0;
$check = function ($label, $ok, $detail = '') use (&$fail) {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' -> ' . (is_string($detail) ? $detail : json_encode($detail))) . "\n";
    if (!$ok) { $fail++; }
};
$run = substr(bin2hex(random_bytes(4)), 0, 6);
$FB = 'http://127.0.0.1';
$PANEL = rtrim(getenv('API_URL'), '/');
$KEY = getenv('API_KEY');
$TOKEN = getenv('FB_TOKEN');

function http($method, $url, $fields = null, array $headers = array(), $userpwd = null, $cookie = null, &$status = null) {
    $ch = curl_init();
    $opts = array(CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers);
    if ($method === 'POST') { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = http_build_query($fields ?: array()); }
    if ($userpwd) { $opts[CURLOPT_USERPWD] = $userpwd; }
    if ($cookie) { $opts[CURLOPT_COOKIEJAR] = $cookie; $opts[CURLOPT_COOKIEFILE] = $cookie; }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return $body;
}
/** FOSSBilling API call. Returns array(result, error message or ''). */
$fb = function ($area, $method, array $p = array(), $cookie = null) use ($FB, $TOKEN) {
    $body = http('POST', "$FB/api/$area/$method", $p, array(), $area === 'admin' ? "admin:$TOKEN" : null, $cookie);
    $j = json_decode((string) $body, true);
    if (!is_array($j)) { return array(null, 'bad response: ' . substr((string) $body, 0, 200)); }
    return array($j['result'] ?? null, $j['error']['message'] ?? '');
};
/** Client API calls need the session's CSRF token (it is in the fossbilling_csrf cookie after a page load). */
$csrf = function ($jar) use ($FB) {
    http('GET', "$FB/", null, array(), null, $jar);
    foreach (file($jar, FILE_IGNORE_NEW_LINES) ?: array() as $l) {
        $f = explode("\t", ltrim($l, '#'));
        if (count($f) >= 7 && $f[5] === 'fossbilling_csrf') { return trim($f[6]); }
    }
    return '';
};
/** Panel Reseller API. Returns the decoded envelope. */
$panel = function ($action, array $p = array(), $post = false) use ($PANEL, $KEY) {
    $h = array('X-API-Key: ' . $KEY, 'Accept: application/json');
    if ($post) { $body = http('POST', "$PANEL/reseller/v1", array('action' => $action) + $p, $h); }
    else { $body = http('GET', "$PANEL/reseller/v1?" . http_build_query(array('action' => $action) + $p), null, $h); }
    return json_decode((string) $body, true) ?: array('status' => 'NO_RESPONSE');
};
$credits = function () use ($panel) { $r = $panel('user_info'); return (int) ($r['data']['credits'] ?? -1); };
$line = function ($id) use ($panel) { $r = $panel('get_line', array('id' => $id)); return $r['status'] === 'STATUS_SUCCESS' ? $r['data'] : null; };
$user = function ($id) use ($panel) { $r = $panel('get_user', array('id' => $id)); return $r['status'] === 'STATUS_SUCCESS' ? $r['data'] : null; };
$svc = function ($orderId) use ($fb) { // the service row of an order, through FOSSBilling's admin API
    list($o) = $fb('admin', 'order/get', array('id' => $orderId));
    list($s) = $fb('admin', 'order/service', array('id' => $orderId));
    return array($o['status'] ?? '', is_array($s) ? $s : array());
};
$status = function ($orderId) use ($fb) { list($o) = $fb('admin', 'order/get', array('id' => $orderId)); return $o['status'] ?? ''; };
$lastNote = function ($orderId) use ($fb) {
    list($r) = $fb('admin', 'order/status_history_get_list', array('id' => $orderId, 'per_page' => 1));
    return $r['list'][0]['notes'] ?? '';
};
$mkOrder = function ($clientId, $productId, $activate = true) use ($fb) {
    list($id, $err) = $fb('admin', 'order/create', array('client_id' => $clientId, 'product_id' => $productId, 'period' => '1Y'));
    if (!$id) { return array(0, $err); }
    return array((int) $id, '');
};

echo "== settings\n";
list($ok, $err) = $fb('admin', 'servicextreampro/save_settings', array('panel_url' => 'ftp://nope'));
$check('a panel URL that is not http(s) is refused', !$ok && stripos($err, 'http') !== false, $err);
$fb('admin', 'servicextreampro/save_settings', array('panel_url' => $PANEL, 'api_key' => 'xk_wrong'));
list($st) = $fb('admin', 'servicextreampro/status');
$check('a wrong API key is reported readably', empty($st['ok']) && stripos($st['error'], 'API key') !== false, $st);
list($ok, $err) = $fb('admin', 'servicextreampro/save_settings', array('panel_url' => $PANEL . '/', 'api_key' => $KEY));
$check('settings saved', $ok === true, $err);
list($st) = $fb('admin', 'servicextreampro/status');
$check('test connection: reseller, credits and packages', !empty($st['ok']) && $st['username'] !== '' && count($st['packages']) >= 1, $st);
list($ok) = $fb('admin', 'servicextreampro/save_settings', array('panel_url' => $PANEL));
list($st) = $fb('admin', 'servicextreampro/status');
$check('saving without an API key keeps the stored one', !empty($st['ok']), $st);
list($types) = $fb('admin', 'product/get_types');
$check('"Xtreampro" is a product type', isset($types['xtreampro']), $types);
// A package that is sold as an official period (the first one of the seed is a trial).
$pkgId = 0;
foreach ($st['packages'] as $pk) { if ($pk['detail'] !== 'trial only') { $pkgId = (int) $pk['id']; break; } }
$check('the panel offers an official package', $pkgId > 0, $st['packages']);

$mkProduct = function ($title, array $config, $period = '1Y') use ($fb) {
    list($pid) = $fb('admin', 'product/prepare', array('title' => $title, 'type' => 'xtreampro'));
    $fb('admin', 'product/update_config', array('id' => $pid, 'config' => $config));
    $fb('admin', 'product/update', array('id' => $pid, 'status' => 'enabled', 'setup' => 'manual',
        'pricing' => array('type' => 'recurrent', 'recurrent' => array($period => array('price' => 20, 'setup' => 0, 'enabled' => 1)))));
    return (int) $pid;
};
list($cid, $err) = $fb('admin', 'client/create', array('email' => "fbtest+$run@gmail.com", 'first_name' => 'Ann', 'last_name' => 'Berg',
    'password' => 'Client-pass-123', 'password_confirm' => 'Client-pass-123'));
$check('customer created', (int) $cid > 0, $err);
list($cid2) = $fb('admin', 'client/create', array('email' => "fbtest+other$run@gmail.com", 'first_name' => 'Bob', 'last_name' => 'Other',
    'password' => 'Client-pass-123', 'password_confirm' => 'Client-pass-123'));

echo "== IPTV line\n";
$pLine = $mkProduct("IPTV line $run", array('service_type' => 'line', 'package_id' => $pkgId, 'trial' => '0', 'delete_on_cancel' => '1'));
$before = $credits();
list($o1) = $mkOrder($cid, $pLine);
$check('order created (pending)', $o1 > 0 && $status($o1) === 'pending_setup');
list($ok, $err) = $fb('admin', 'order/activate', array('id' => $o1));
list($ost, $s1) = $svc($o1);
$lineId = (int) ($s1['line_id'] ?? 0);
$l = $line($lineId);
$check('activate sells a line in the panel', $ok === true && $ost === 'active' && $l && $l['status'] === 'active', array($err, $s1));
$price = $before - $credits();
$check('the reseller was charged once', $price > 0, $price);
$check('the line has a username', $l && !empty($l['username']), $l);
$user1 = $l['username'] ?? '';
$fb('admin', 'order/activate', array('id' => $o1, 'force' => 1));
$check('activating again sells no second line and charges nothing', $credits() === $before - $price);

echo "== customer area\n";
$jar = tempnam(sys_get_temp_dir(), 'fbjar');
list($login, $err) = $fb('guest', 'client/login', array('email' => "fbtest+$run@gmail.com", 'password' => 'Client-pass-123'), $jar);
$check('customer signs in', !empty($login), $err);
list($d, $err) = $fb('client', 'servicextreampro/details', array('order_id' => $o1, 'CSRFToken' => $csrf($jar)), $jar);
$check('details: username, password and play links from the panel', is_array($d) && $d['username'] === $user1 && $d['password'] !== ''
    && !empty($d['links']['m3u']) && !empty($d['links']['web_player']) && $d['error'] === '', array($d, $err));
$m3u = $d['links']['m3u'] ?? '';
$page = http('GET', "$FB/order/service/manage/$o1", null, array(), null, $jar, $code);
$check('the order page shows the credentials and the playlist link', $code === 200 && strpos($page, $user1) !== false
    && strpos($page, htmlspecialchars((string) ($d['password'] ?? 'x'))) !== false && strpos($page, 'Your IPTV access') !== false, 'http ' . $code);
$jar2 = tempnam(sys_get_temp_dir(), 'fbjar');
$fb('guest', 'client/login', array('email' => "fbtest+other$run@gmail.com", 'password' => 'Client-pass-123'), $jar2);
list($x, $err) = $fb('client', 'servicextreampro/details', array('order_id' => $o1, 'CSRFToken' => $csrf($jar2)), $jar2);
$check('another customer cannot read the order', $x === null && stripos($err, 'not found') !== false, array($x, $err));
list($apiOrder) = $fb('client', 'order/get', array('id' => $o1, 'CSRFToken' => $csrf($jar)), $jar);
$check('the order data given to the customer carries no password', strpos(json_encode($apiOrder), (string) ($d['password'] ?? 'x')) === false);

echo "== suspend, unsuspend, renew\n";
$fb('admin', 'order/suspend', array('id' => $o1));
$check('suspend disables the line', ($line($lineId)['status'] ?? '') === 'disabled', $line($lineId));
$fb('admin', 'order/unsuspend', array('id' => $o1));
$check('unsuspend enables it again', ($line($lineId)['status'] ?? '') === 'active');
$exp0 = (int) $line($lineId)['exp_date']; $c0 = $credits();
list($ok, $err) = $fb('admin', 'order/renew', array('id' => $o1));
$check('renew extends the line and costs credits', $ok === true && (int) $line($lineId)['exp_date'] > $exp0 && $credits() < $c0, array($err, $exp0, $line($lineId)['exp_date']));
$fb('admin', 'order/suspend', array('id' => $o1));
$fb('admin', 'order/renew', array('id' => $o1));
$check('renewing a suspended order enables the line again', ($line($lineId)['status'] ?? '') === 'active' && $status($o1) === 'active', $line($lineId));
$fb('admin', 'servicextreampro/save_settings', array('panel_url' => $PANEL, 'api_key' => 'xk_wrong'));
$c1 = $credits();
list($ok, $err) = $fb('admin', 'order/renew', array('id' => $o1));
$check('a renewal with a wrong key fails readably and charges nothing', $ok === null && stripos($err, 'API key') !== false && $credits() === $c1 && $status($o1) === 'failed_renew', array($err, $status($o1)));
$fb('admin', 'servicextreampro/save_settings', array('panel_url' => $PANEL, 'api_key' => $KEY));
list($ok, $err) = $fb('admin', 'order/renew', array('id' => $o1));
$check('renewing again after the fix works', $ok === true && $status($o1) === 'active', $err);

echo "== cancel (delete), uncancel\n";
list($ok, $err) = $fb('admin', 'order/cancel', array('id' => $o1));
$check('cancel deletes the line in the panel', $ok === true && $line($lineId) === null, $err);
list($ok, $err) = $fb('admin', 'order/uncancel', array('id' => $o1));
list($ost, $s1b) = $svc($o1);
$newLine = (int) ($s1b['line_id'] ?? 0);
$check('uncancel sells a new line (the old one is gone for good)', $ok === true && $newLine > 0 && $newLine !== $lineId && ($line($newLine)['status'] ?? '') === 'active', array($err, $s1b));
list($ok, $err) = $fb('admin', 'order/delete', array('id' => $o1));
$check('deleting the order deletes the line', $ok === true && $line($newLine) === null, $err);

echo "== cancel (only disable)\n";
$pKeep = $mkProduct("IPTV keep $run", array('service_type' => 'line', 'package_id' => $pkgId, 'delete_on_cancel' => '0'));
list($o2) = $mkOrder($cid, $pKeep);
$fb('admin', 'order/activate', array('id' => $o2));
list(, $s2) = $svc($o2);
$l2 = (int) ($s2['line_id'] ?? 0);
$fb('admin', 'order/cancel', array('id' => $o2));
$check('cancel only disables the line', ($line($l2)['status'] ?? '') === 'disabled', $line($l2));
$fb('admin', 'order/uncancel', array('id' => $o2));
list(, $s2b) = $svc($o2);
$check('uncancel enables the same line', ($line($l2)['status'] ?? '') === 'active' && (int) $s2b['line_id'] === $l2, $s2b);
$fb('admin', 'order/delete', array('id' => $o2));

echo "== sub-reseller account\n";
$pSub = $mkProduct("Sub-reseller $run", array('service_type' => 'reseller', 'credits_on_creation' => '25', 'credits_per_renewal' => '10'));
$before = $credits();
list($o3) = $mkOrder($cid, $pSub);
list($ok, $err) = $fb('admin', 'order/activate', array('id' => $o3));
list($ost, $s3) = $svc($o3);
$uid = (string) ($s3['user_id'] ?? '');
$u = $uid !== '' ? $user($uid) : null;
$check('activate creates the sub-reseller with the starting credits', $ok === true && $u && (int) $u['credits'] === 25, array($err, $s3, $u));
$check('the reseller paid the account price plus the 25 credits', $before - $credits() >= 25, $before - $credits());
$jar3 = $jar;
list($d3) = $fb('client', 'servicextreampro/details', array('order_id' => $o3, 'CSRFToken' => $csrf($jar3)), $jar3);
$check('the customer sees username, password and credit balance', is_array($d3) && $d3['username'] === ($u['username'] ?? '?') && strlen($d3['password']) >= 8 && $d3['credits'] === '25', $d3);
$pw = $d3['password'] ?? '';
$fb('admin', 'order/renew', array('id' => $o3));
$check('renew hands over the renewal credits', (int) ($user($uid)['credits'] ?? 0) === 35, $user($uid));
$fb('admin', 'order/suspend', array('id' => $o3));
$check('suspend disables the account', ($user($uid)['status'] ?? '') === 'disabled', $user($uid));
$fb('admin', 'order/unsuspend', array('id' => $o3));
$check('unsuspend enables it', ($user($uid)['status'] ?? '') === 'active', $user($uid));
$fb('admin', 'order/cancel', array('id' => $o3));
$check('cancel disables the account (never deletes it)', ($user($uid)['status'] ?? '') === 'disabled', $user($uid));

echo "== billing flow: the customer pays, the line follows the order\n";
$pBill = $mkProduct("Paid IPTV $run", array('service_type' => 'line', 'package_id' => $pkgId, 'delete_on_cancel' => '1'));
$fb('admin', 'product/update', array('id' => $pBill, 'setup' => 'after_payment'));
$jar4 = tempnam(sys_get_temp_dir(), 'fbjar');
$fb('guest', 'client/login', array('email' => "fbtest+$run@gmail.com", 'password' => 'Client-pass-123'), $jar4);
$cs = $csrf($jar4);
list($ok, $err) = $fb('guest', 'cart/add_item', array('id' => $pBill, 'period' => '1Y', 'CSRFToken' => $cs), $jar4);
list($co, $err2) = $fb('client', 'cart/checkout', array('gateway_id' => 1, 'CSRFToken' => $cs), $jar4);
$o6 = (int) ($co['order_id'] ?? 0);
$check('the customer checks out a cart', $ok === true && $o6 > 0, array($err, $err2));
$before = $credits();
list($o) = $fb('admin', 'order/get', array('id' => $o6));
$check('the order waits for payment', ($o['status'] ?? '') === 'pending_setup' && $before === $credits(), $o['status'] ?? '');
$pay = function ($invoiceId) use ($fb) { return $fb('admin', 'invoice/mark_as_paid', array('id' => $invoiceId, 'execute' => 1, 'gateway_id' => 1, 'transactionId' => 'E2E-' . bin2hex(random_bytes(4)))); };
list($ok, $err) = $pay($o['unpaid_invoice_id']);
list($ost, $s6) = $svc($o6);
$l6 = (int) ($s6['line_id'] ?? 0);
$check('paying the invoice activates the order and sells the line', $ok === true && $ost === 'active' && $l6 > 0 && ($line($l6)['status'] ?? '') === 'active', array($err, $ost, $s6));
$exp6 = (int) ($line($l6)['exp_date'] ?? 0); $c6 = $credits();
list($rinv, $err) = $fb('admin', 'invoice/renewal_invoice', array('id' => $o6));
$check('a renewal invoice is issued', (int) $rinv > 0 && $credits() === $c6, $err);
$pay($rinv);
$check('paying the renewal invoice renews the line in the panel', (int) ($line($l6)['exp_date'] ?? 0) > $exp6 && $credits() < $c6, array($exp6, $line($l6)['exp_date'] ?? null));
$fb('admin', 'order/update', array('id' => $o6, 'expires_at' => '2020-01-01'));
$fb('admin', 'order/batch_suspend_expired');
$check('an overdue order is suspended by FOSSBilling and the line is disabled', $status($o6) === 'suspended' && ($line($l6)['status'] ?? '') === 'disabled', array($status($o6), $line($l6)['status'] ?? null));
$pay((int) $fb('admin', 'invoice/renewal_invoice', array('id' => $o6))[0]);
$check('paying after that brings the line back', $status($o6) === 'active' && ($line($l6)['status'] ?? '') === 'active', array($status($o6), $line($l6)['status'] ?? null));
$fb('admin', 'order/delete', array('id' => $o6));
$check('deleting the order removes the line', $line($l6) === null);

echo "== 1.1.0: sells, pricing, connector name, default cancellation, spent request id\n";
$allPk = $panel('packages')['data'] ?? array();
$boxPkg = null;
foreach ($allPk as $pk) { if (isset($pk['sells']) && !in_array('line', $pk['sells'], true)) { $boxPkg = $pk; break; } }
$check('the panel marks a box-only package with sells without "line"', $boxPkg !== null, $allPk);
list($st) = $fb('admin', 'servicextreampro/status');
$ids = array_column($st['packages'], 'id');
$check('the packages of the admin page leave box-only packages out', $boxPkg !== null && !in_array((int) $boxPkg['id'], $ids, true) && in_array($pkgId, $ids, true), $ids);
$pBox = $mkProduct("Box only $run", array('service_type' => 'line', 'package_id' => $boxPkg['id']));
list($ob) = $mkOrder($cid, $pBox);
$cBox = $credits();
list($ok, $err) = $fb('admin', 'order/activate', array('id' => $ob));
$check('a box-only package is refused before anything is sold, with a readable reason', $ok === null && stripos($err, 'boxes only') !== false && $credits() === $cBox && $status($ob) === 'failed_setup', array($err, $status($ob)));
$names = array_column($panel('api_logs', array('limit' => 50))['data'] ?? array(), 'connector');
$check('the panel call log names the connector', in_array('fossbilling/1.1.0', $names, true), array_unique($names));
$pDef = $mkProduct("IPTV default cancel $run", array('service_type' => 'line', 'package_id' => $pkgId));
list($od) = $mkOrder($cid, $pDef);
$fb('admin', 'order/activate', array('id' => $od));
list(, $sd) = $svc($od);
$ld = (int) ($sd['line_id'] ?? 0);
$fb('admin', 'order/cancel', array('id' => $od));
$check('a product without a cancellation setting only disables the line (disable is the default)', $ld > 0 && ($line($ld)['status'] ?? '') === 'disabled', $line($ld));
$fb('admin', 'order/delete', array('id' => $od));
$pSp = $mkProduct("IPTV spent $run", array('service_type' => 'line', 'package_id' => $pkgId, 'delete_on_cancel' => '1'));
list($osp) = $mkOrder($cid, $pSp);
$fb('admin', 'order/activate', array('id' => $osp));
list(, $ssp) = $svc($osp);
$lsp = (int) ($ssp['line_id'] ?? 0);
$panel('delete_line', array('id' => $lsp), true);
$pdoSp = new PDO('mysql:host=' . getenv('DB_HOST') . ';dbname=fb', 'root', 'root');
$pdoSp->prepare('UPDATE service_xtreampro SET line_id = 0 WHERE id = ?')->execute(array((int) $ssp['id']));
list($ok, $err) = $fb('admin', 'order/activate', array('id' => $osp, 'force' => 1));
$check('selling again under the request id of a line deleted on the panel gives a readable message (REQUEST_ID_SPENT)', $ok === null && stripos($err, 'already made') !== false && strpos($err, 'REQUEST_ID') === false, $err);
$fb('admin', 'order/delete', array('id' => $osp));

echo "== failures\n";
$pBad = $mkProduct("Bad package $run", array('service_type' => 'line', 'package_id' => '99999'));
list($o4) = $mkOrder($cid, $pBad);
list($ok, $err) = $fb('admin', 'order/activate', array('id' => $o4));
$check('an unavailable package fails the activation readably', $ok === null && stripos($err, 'package') !== false && $status($o4) === 'failed_setup', array($err, $status($o4)));
$pNone = $mkProduct("No package $run", array('service_type' => 'line'));
list($o5) = $mkOrder($cid, $pNone);
list($ok, $err) = $fb('admin', 'order/activate', array('id' => $o5));
$check('a product without a package says so', $ok === null && stripos($err, 'package') !== false, $err);

echo "== admin pages\n";
$jarA = tempnam(sys_get_temp_dir(), 'fbjar');
http('GET', "$FB/admin", null, array(), null, $jarA);
list($sess, $err) = $fb('guest', 'staff/login', array('email' => 'admin@example.test', 'password' => 'Admin-pass-1234'), $jarA);
$check('staff signs in', !empty($sess), $err);
$p = http('GET', "$FB/admin/servicextreampro", null, array(), null, $jarA, $code);
$check('Extensions > Xtream UI Pro: settings, connection status and packages', $code === 200 && strpos($p, 'Panel API URL') !== false && strpos($p, 'Connected as') !== false && strpos($p, 'Packages you can sell') !== false, 'http ' . $code);
$check('the settings page never prints the API key', strpos($p, $KEY) === false);
$p = http('GET', "$FB/admin/product/manage/$pLine", null, array(), null, $jarA, $code);
$check('the product Configuration tab offers type, package, trial, cancel behaviour and credits', $code === 200 && strpos($p, 'config[package_id]') !== false
    && strpos($p, 'config[credits_per_renewal]') !== false && strpos($p, 'config[delete_on_cancel]') !== false, 'http ' . $code);
$p = http('GET', "$FB/admin/order/manage/$o3", null, array(), null, $jarA, $code);
$check('the admin order page shows the live account', $code === 200 && strpos($p, 'Status in the panel') !== false && strpos($p, $uid) !== false, 'http ' . $code);

echo "== hygiene\n";
$logs = '';
foreach (glob('/var/www/html/data/log/*.log') ?: array() as $f) { $logs .= (string) @file_get_contents($f); }
$pdo = new PDO('mysql:host=' . getenv('DB_HOST') . ';dbname=fb', 'root', 'root');
$activity = json_encode($pdo->query('SELECT * FROM activity_system')->fetchAll(PDO::FETCH_ASSOC));
$rows = json_encode($pdo->query('SELECT * FROM service_xtreampro')->fetchAll(PDO::FETCH_ASSOC));
$status_hist = json_encode($pdo->query('SELECT notes FROM client_order_status')->fetchAll(PDO::FETCH_ASSOC));
$check('the API key is in no log, activity entry or order note', strpos($logs . $activity . $status_hist, $KEY) === false);
$check('the sub-reseller password is stored encrypted, line passwords not at all', $pw !== '' && strpos($rows, $pw) === false && strpos($rows, '"password"') === false, $rows);
$check('no PHP warnings, notices or deprecations from the module', !preg_match('/Servicextreampro/', $logs), implode("\n", array_slice(array_filter(explode("\n", $logs), fn ($l) => strpos($l, 'Servicextreampro') !== false), 0, 5)));

echo $fail ? "\n$fail FAILED\n" : "\nALL OK\n";
exit($fail ? 1 : 0);
