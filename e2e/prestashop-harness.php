<?php
// End-to-end run of the PrestaShop connector's platform independent core against a
// real panel API. Not shipped.
//
// PrestaShop is not started here. The harness loads the real core files of the module
// (src/ApiClient.php, src/Store.php, src/Provisioner.php) and the pure presenter
// (classes/Presenter.php) - not copies - and gives the provisioner an in-memory
// XtreamproStore, exactly what the module's database store would be. What PrestaShop
// itself does (hooks, Db, Order, Smarty) is NOT covered; the README lists it.
//
//   MODULE_DIR=.../plugins/prestashop/xtreampro API_PORT_NUM=18095 API_KEY=... \
//     php plugins/e2e/prestashop-harness.php
//   (API_URL overrides http://127.0.0.1:API_PORT_NUM)

define('MODULE_DIR', rtrim((string) getenv('MODULE_DIR') ?: dirname(__DIR__) . '/prestashop/xtreampro', '/'));
require MODULE_DIR . '/src/ApiClient.php';
require MODULE_DIR . '/src/Store.php';
require MODULE_DIR . '/src/Provisioner.php';
require MODULE_DIR . '/classes/Presenter.php';

$API_BASE = getenv('API_URL') ?: ('http://127.0.0.1:' . getenv('API_PORT_NUM'));
$API_KEY = (string) getenv('API_KEY');

/** The store the module keeps in MySQL, kept in memory (same merge-on-save behaviour). */
class MemoryStore implements XtreamproStore
{
    public $units = array();
    public $accounts = array();

    public function getUnit($detailId, $unit)
    {
        $k = $detailId . '-' . $unit;
        return isset($this->units[$k]) ? $this->units[$k] : null;
    }

    public function saveUnit($detailId, $unit, array $fields)
    {
        $k = $detailId . '-' . $unit;
        $row = isset($this->units[$k]) ? $this->units[$k] : array(
            'detail_id' => (int) $detailId, 'unit_no' => (int) $unit, 'order_id' => 0, 'customer_id' => 0,
            'kind' => '', 'status' => 'error', 'panel_id' => '', 'username' => '', 'password' => '',
            'links' => array(), 'credits' => 0, 'generation' => 0, 'renewals' => 0, 'error' => '',
        );
        $this->units[$k] = array_merge($row, $fields);
    }

    public function getAccount($customerId)
    {
        return isset($this->accounts[$customerId]) ? $this->accounts[$customerId] : null;
    }

    public function saveAccount($customerId, array $fields)
    {
        $row = isset($this->accounts[$customerId]) ? $this->accounts[$customerId] : array(
            'customer_id' => (int) $customerId, 'panel_user_id' => '', 'username' => '', 'password' => '', 'origin' => '', 'disabled' => 0,
        );
        $this->accounts[$customerId] = array_merge($row, $fields);
    }
}

// ---- panel access for verification (independent of the module) ------------------------

function panel($action, array $q = array(), $post = false)
{
    global $API_BASE, $API_KEY;
    $url = $API_BASE . '/reseller/v1';
    $ch = curl_init();
    $q = array_merge(array('action' => $action), $q);
    if ($post) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($q));
    } else {
        $url .= '?' . http_build_query($q);
    }
    curl_setopt_array($ch, array(CURLOPT_URL => $url, CURLOPT_HTTPHEADER => array('X-API-Key: ' . $API_KEY),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20));
    $body = curl_exec($ch);
    curl_close($ch);
    $j = json_decode((string) $body, true);
    return is_array($j) ? $j : array('status' => 'STATUS_FAILURE', 'error' => 'BAD_RESPONSE');
}
function line_of($id) { $r = panel('get_line', array('id' => $id)); return $r['status'] === 'STATUS_SUCCESS' ? $r['data'] : null; }
function sub_of($username)
{
    $r = panel('get_users', array('search' => $username, 'start' => 0, 'limit' => 50));
    foreach ($r['data'] ?? array() as $u) {
        if (($u['username'] ?? '') === $username) { return $u; }
    }
    return null;
}
function subs_with_email($email)
{
    $r = panel('get_users', array('search' => $email, 'start' => 0, 'limit' => 50));
    $n = 0;
    foreach ($r['data'] ?? array() as $u) { if (($u['email'] ?? '') === $email) { $n++; } }
    return $n;
}

$fail = 0;
$check = function ($label, $ok, $detail = '') use (&$fail) {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' -> ' . (is_string($detail) ? $detail : json_encode($detail))) . "\n";
    if (!$ok) { $fail++; }
};

$logs = array();               // everything the core logged
$logger = function ($m) use (&$logs) { $logs[] = $m; };
$secrets = array($API_KEY);
$RUN = getmypid() . random_int(100, 999);
$instance = bin2hex(random_bytes(4));
$cleanupLines = array();
$cleanupUsers = array();

$store = new MemoryStore();
$api = new XtreamproApiClient($API_BASE, $API_KEY, $logger);
$prov = new XtreamproProvisioner($api, $store, $instance, $logger);

$packages = panel('packages')['data'];
$pkg = null;
foreach ($packages as $p) { if (!empty($p['is_official'])) { $pkg = $p; break; } }

$customer = function ($n) use ($RUN) {
    return array('id' => 700000 + $n, 'email' => "ps-$RUN-c$n@example.test", 'fullname' => 'Ann Berg', 'login_hint' => "ps$RUN-c$n", 'is_guest' => false);
};
$lineJob = function ($detail, $unit, $package, $cust = null) use ($pkg, $customer) {
    return array('detail_id' => $detail, 'unit' => $unit, 'order_id' => 5000 + $detail, 'order_ref' => 'ABCDEFGHI', 'kind' => 'line',
        'package_id' => $package === null ? (int) $pkg['id'] : $package, 'trial' => false, 'credits' => 0, 'customer' => $cust ?: $customer(1));
};
$subJob = function ($detail, $unit, $credits, $cust) {
    return array('detail_id' => $detail, 'unit' => $unit, 'order_id' => 5000 + $detail, 'order_ref' => 'ABCDEFGHI', 'kind' => 'reseller',
        'package_id' => 0, 'trial' => false, 'credits' => $credits, 'customer' => $cust);
};
$revokeJob = function ($detail, $unit) { return array('detail_id' => $detail, 'unit' => $unit, 'order_ref' => 'ABCDEFGHI'); };
$exp = function ($id) { $l = line_of($id); return $l ? (int) $l['exp_date'] : -1; };
$D = random_int(100000, 800000);

// ==========================================================================================
echo "== core files\n";
$check('version 1.1.0 in the module entry file and in the API client (X-Connector)', strpos(file_get_contents(MODULE_DIR . '/xtreampro.php'), '1.1.0') !== false && XtreamproApiClient::VERSION === '1.1.0');
$check('config.xml carries 1.1.0', strpos(file_get_contents(MODULE_DIR . '/config.xml'), '<version><![CDATA[1.1.0]]></version>') !== false);
$check('core files do not reference PrestaShop classes', !preg_match('/\b(Db|Configuration|Tools|Context|Order|Customer|PrestaShopLogger|Validate)::/', file_get_contents(MODULE_DIR . '/src/ApiClient.php') . file_get_contents(MODULE_DIR . '/src/Provisioner.php')));
$thrown = '';
try { new XtreamproApiClient('', 'k'); } catch (XtreamproApiException $e) { $thrown = $e->getErrorCode(); }
$check('a client without URL is refused (CONFIG)', $thrown === 'CONFIG', $thrown);
$thrown = '';
try { new XtreamproApiClient('ftp://x.test', 'k'); } catch (XtreamproApiException $e) { $thrown = $e->getErrorCode(); }
$check('a non-http URL is refused', $thrown === 'CONFIG', $thrown);
$info = $api->userInfo();
$check('test connection (user_info)', !empty($info['username']) && isset($info['credits']), $info);
$check('package list', count($api->packages()) >= 1);
$check('mask: json password and password= in links', ApiMask($api) );
function ApiMask($api)
{
    $m = XtreamproApiClient::maskSecrets('{"password":"s3cr3t!","m3u":"http://h/get.php?username=u&password=s3cr3t!&type=m3u_plus","x":"password%3Dabc"}');
    return strpos($m, 's3cr3t') === false && strpos($m, 'abc') === false && substr_count($m, '********') === 3;
}
$thrown = '';
try { (new XtreamproApiClient('http://127.0.0.1:1', 'k'))->userInfo(); } catch (XtreamproApiException $e) { $thrown = $e->getMessage(); }
$check('a dead port fails readably', stripos($thrown, 'Could not connect') !== false, $thrown);

// ==========================================================================================
echo "== 1.1.0: sells, pricing, connector name, spent request id\n";
$boxPkg = null;
foreach ($packages as $p) { if (isset($p['sells']) && !in_array('line', $p['sells'], true)) { $boxPkg = $p; break; } }
$check('the panel marks a box-only package with sells without "line"', $boxPkg !== null, $packages);
$check('a panel that sends no sells counts as selling lines', XtreamproApiClient::sellsLine(array('id' => 1)) && !XtreamproApiClient::sellsLine(array('sells' => array('mag'))));
$balBox = (int) panel('user_info')['data']['credits'];
$boxStore = new MemoryStore();
$boxProv = new XtreamproProvisioner($api, $boxStore, $instance, $logger);
$r = $boxProv->provision($lineJob($D + 900, 1, (int) $boxPkg['id']));
$check('a box-only package is refused before anything is sold, with a readable reason', $r['ok'] === false && stripos($r['message'], 'boxes only') !== false && (int) panel('user_info')['data']['credits'] === $balBox, $r);
$names = array();
foreach ((panel('api_logs', array('limit' => 50))['data'] ?? array()) as $row) { $names[] = $row['connector'] ?? ''; }
$check('the panel call log names the connector', in_array('prestashop/1.1.0', $names, true), array_unique($names));
$spStore = new MemoryStore();
$spProv = new XtreamproProvisioner($api, $spStore, $instance, $logger);
$r = $spProv->provision($lineJob($D + 901, 1, null));
$spLine = (int) ($spStore->getUnit($D + 901, 1)['panel_id'] ?? 0);
panel('delete_line', array('id' => $spLine), true);
$lostStore = new MemoryStore();
$r = (new XtreamproProvisioner($api, $lostStore, $instance, $logger))->provision($lineJob($D + 901, 1, null));
$check('selling again under the request id of a line deleted on the panel gives a readable message (REQUEST_ID_SPENT)', $r['ok'] === false && stripos($r['message'], 'already made') !== false && strpos($r['message'], 'REQUEST_ID') === false, $r);

// ==========================================================================================
echo "== line unit\n";
$r = $prov->provision($lineJob($D, 1, null));
$u = $store->getUnit($D, 1);
$lineId = (int) ($u['panel_id'] ?? 0);
$cleanupLines[] = $lineId;
$check('create', $r['ok'] === true && $lineId > 0 && $u['status'] === 'ok', array($r, $u));
$pl = line_of($lineId);
$check('panel: the line exists, is active and has the package', $pl && $pl['status'] === 'active' && (int) $pl['package_id'] === (int) $pkg['id'], $pl);
$check('the credentials kept for the buyer are the panel\'s', $u['username'] === $pl['username'] && $u['password'] === $pl['password'] && $u['password'] !== '', $u);
$secrets[] = $pl['password'];
$check('the panel\'s play links were stored', !empty($u['links']['m3u']) && strpos($u['links']['m3u'], 'get.php') !== false && !empty($u['links']['web_player']) && !empty($u['links']['server']), $u['links']);

$r = $prov->provision($lineJob($D, 1, null));
$check('create repeated does nothing', $r['ok'] === true && $store->getUnit($D, 1)['panel_id'] === (string) $lineId && stripos($r['message'], 'already') !== false, $r);

// The shop lost its record (restore from a backup): the same request id must not sell a second line.
$lost = new MemoryStore();
$provLost = new XtreamproProvisioner($api, $lost, $instance, $logger);
$r = $provLost->provision($lineJob($D, 1, null));
$check('create replayed after the record was lost returns the SAME line (idempotent request id)', $r['ok'] === true && $lost->getUnit($D, 1)['panel_id'] === (string) $lineId, array($r, $lost->getUnit($D, 1)));
$check('and the credentials match again', $lost->getUnit($D, 1)['username'] === $pl['username']);

$r = $prov->suspend($D, 1);
$check('suspend', $r['ok'] === true && (line_of($lineId)['status'] ?? '') === 'disabled' && $store->getUnit($D, 1)['status'] === 'suspended', $r);
$r = $prov->provision($lineJob($D, 1, null));
$check('a paid event does not resume a suspended unit', $r['ok'] === true && (line_of($lineId)['status'] ?? '') === 'disabled');
$r = $prov->unsuspend($D, 1);
$check('unsuspend', $r['ok'] === true && (line_of($lineId)['status'] ?? '') === 'active' && $store->getUnit($D, 1)['status'] === 'ok', $r);

$e0 = $exp($lineId);
$r = $prov->renew($D, 1);
$e1 = $exp($lineId);
$check('renew extends the line (renewal number 1)', $r['ok'] === true && $e1 > $e0 && $store->getUnit($D, 1)['renewals'] === 1, array($r, $e0, $e1));
$r = $prov->renew($D, 1, 1);
$check('renew repeated with the same number does not extend or charge again', $r['ok'] === true && $exp($lineId) === $e1, array($r, $e1, $exp($lineId)));
$r = $prov->renew($D, 1);
$check('the next renewal (number 2) extends again', $r['ok'] === true && $exp($lineId) > $e1 && $store->getUnit($D, 1)['renewals'] === 2, array($r, $exp($lineId)));

$r = $prov->revoke($revokeJob($D, 1));
$check('revoke (cancelled / refunded) disables the line, never deletes it', $r['ok'] === true && (line_of($lineId)['status'] ?? '') === 'disabled' && $store->getUnit($D, 1)['status'] === 'revoked', array($r, line_of($lineId)));
$check('revoke again is still success', $prov->revoke($revokeJob($D, 1))['ok'] === true);
$check('renew of a revoked unit is refused readably', ($r = $prov->renew($D, 1))['ok'] === false && stripos($r['message'], 'active') !== false, $r);

$r = $prov->provision($lineJob($D, 1, null));
$check('paid again after a cancellation gives the same line back, enabled', $r['ok'] === true && (line_of($lineId)['status'] ?? '') === 'active' && $store->getUnit($D, 1)['panel_id'] === (string) $lineId && $store->getUnit($D, 1)['status'] === 'ok', array($r, line_of($lineId)));

echo "== line unit, quantity 3 (units 2 and 3)\n";
$D3 = $D + 1;
$ids = array();
foreach (array(1, 2, 3) as $n) {
    $prov->provision($lineJob($D3, $n, null));
    $ids[] = (int) $store->getUnit($D3, $n)['panel_id'];
}
$cleanupLines = array_merge($cleanupLines, $ids);
$check('every unit is its own line', count(array_unique($ids)) === 3 && !in_array(0, $ids, true), $ids);
$usernames = array_map(function ($n) use ($store, $D3) { return $store->getUnit($D3, $n)['username']; }, array(1, 2, 3));
$check('with three different usernames', count(array_unique($usernames)) === 3, $usernames);
foreach ($ids as $i) { $secrets[] = line_of($i)['password']; }

echo "== line unit, errors\n";
$wrongStore = new MemoryStore();
$provWrong = new XtreamproProvisioner(new XtreamproApiClient($API_BASE, 'xk_wrong_key_' . $RUN, $logger), $wrongStore, $instance, $logger);
$DW = $D + 10;
$r = $provWrong->provision($lineJob($DW, 1, null));
$check('wrong API key fails readably', $r['ok'] === false && stripos($r['message'], 'API key') !== false, $r);
$check('and is recorded on the unit as an error, without a panel id', ($wrongStore->getUnit($DW, 1)['status'] ?? '') === 'error' && $wrongStore->getUnit($DW, 1)['panel_id'] === '' && stripos($wrongStore->getUnit($DW, 1)['error'], 'API key') !== false, $wrongStore->getUnit($DW, 1));
$check('the error text never contains the key', strpos($wrongStore->getUnit($DW, 1)['error'], 'xk_wrong_key') === false);

$DU = $D + 11;
$r = $prov->provision($lineJob($DU, 1, 999999));
$check('an unknown package fails readably and stores an error', $r['ok'] === false && stripos($r['message'], 'package') !== false && $store->getUnit($DU, 1)['status'] === 'error' && $store->getUnit($DU, 1)['panel_id'] === '', $r);
$r = $prov->provision($lineJob($DU, 1, 0));
$check('a product without a package fails readably', $r['ok'] === false && stripos($r['message'], 'No package') !== false, $r);
$r = $prov->provision($lineJob($DU, 1, null));
$retried = (int) $store->getUnit($DU, 1)['panel_id'];
$cleanupLines[] = $retried;
$check('provision again after fixing the package succeeds and clears the error', $r['ok'] === true && $retried > 0 && $store->getUnit($DU, 1)['error'] === '' && $store->getUnit($DU, 1)['status'] === 'ok', $store->getUnit($DU, 1));
$secrets[] = line_of($retried)['password'];
$r = $prov->revoke($revokeJob($D + 99, 1));
$check('revoke of a unit that never existed is a no-op success', $r['ok'] === true, $r);
$DE = $D + 12;
$prov->provision($lineJob($DE, 1, 0));
$check('revoke of a unit that failed never reaches the panel', $prov->revoke($revokeJob($DE, 1))['ok'] === true && $store->getUnit($DE, 1)['status'] === 'revoked');

// ==========================================================================================
echo "== sub-reseller unit\n";
$C = $customer(2);
$D2 = $D + 20;
$r = $prov->provision($subJob($D2, 1, 50, $C));
$acct = $store->getAccount($C['id']);
$u1 = $store->getUnit($D2, 1);
$cleanupUsers[] = $acct['panel_user_id'] ?? '';
$check('create (generated credentials)', $r['ok'] === true && $u1['status'] === 'ok' && $acct && $acct['panel_user_id'] !== '', array($r, $u1, $acct));
$secrets[] = $acct['password'];
$pu = sub_of($acct['username']);
$check('generated credentials satisfy the panel rules', preg_match('/^[A-Za-z0-9._-]{3,32}$/', $acct['username']) && strlen($acct['password']) >= 8, $acct['username']);
$check('panel: account exists with the customer email and the credits', $pu && $pu['email'] === $C['email'] && (int) $pu['credits'] === 50 && $pu['status'] === 'active' && $pu['id'] === $acct['panel_user_id'], $pu);
$check('the creating unit holds the password for the buyer, with the credits', $u1['password'] === $acct['password'] && $u1['credits'] === 50 && $u1['panel_id'] === $acct['panel_user_id'], $u1);
$check('the account remembers which unit created it', $acct['origin'] === $D2 . '-1', $acct);

$r = $prov->provision($subJob($D2, 1, 50, $C));
$check('create repeated neither fails nor credits twice', $r['ok'] === true && (int) sub_of($acct['username'])['credits'] === 50, array($r, sub_of($acct['username'])));

$lost = new MemoryStore();
$provLost = new XtreamproProvisioner($api, $lost, $instance, $logger);
$r = $provLost->provision($subJob($D2, 1, 50, $C));
$la = $lost->getAccount($C['id']);
$check('create replayed after the record was lost: same account, credits not given twice, credentials read back',
    $r['ok'] === true && $la['panel_user_id'] === $acct['panel_user_id'] && (int) sub_of($la['username'])['credits'] === 50 && subs_with_email($C['email']) === 1, array($r, $la, subs_with_email($C['email'])));
$secrets[] = $la['password'];

// Second unit of the same customer = a later order: tops up the same account.
$r = $prov->provision($subJob($D2 + 1, 1, 50, $C));
$check('a second order of the same customer tops up the SAME account', $r['ok'] === true && $store->getAccount($C['id'])['panel_user_id'] === $acct['panel_user_id'] && (int) sub_of($acct['username'])['credits'] === 100 && subs_with_email($C['email']) === 1, array($r, sub_of($acct['username'])));
$check('that unit shows no password (only the creating order does)', $store->getUnit($D2 + 1, 1)['password'] === '' && $store->getUnit($D2 + 1, 1)['username'] === $acct['username']);
$prov->provision($subJob($D2 + 1, 1, 50, $C));
$check('and repeating it does not top up twice', (int) sub_of($acct['username'])['credits'] === 100);

$r = $prov->renew($D2, 1);
$check('renew of a sub-reseller unit is refused readably (order the credits again)', $r['ok'] === false && stripos($r['message'], 'credits product') !== false, $r);

$r = $prov->suspend($D2, 1);
$check('suspend disables the account', $r['ok'] === true && (sub_of($acct['username'])['status'] ?? '') === 'disabled' && $store->getAccount($C['id'])['disabled'] === 1, $r);
$r = $prov->unsuspend($D2, 1);
$check('unsuspend enables it again', $r['ok'] === true && (sub_of($acct['username'])['status'] ?? '') === 'active' && $store->getAccount($C['id'])['disabled'] === 0, $r);

$r = $prov->revoke($revokeJob($D2 + 1, 1));
$check('revoke of the second order takes its 50 credits back, keeps the account enabled', $r['ok'] === true && (int) sub_of($acct['username'])['credits'] === 50 && (sub_of($acct['username'])['status'] ?? '') === 'active' && $store->getUnit($D2 + 1, 1)['status'] === 'revoked', array($r, sub_of($acct['username'])));
$r = $prov->revoke($revokeJob($D2, 1));
$check('revoke of the creating order takes the credits back and disables the account', $r['ok'] === true && (int) sub_of($acct['username'])['credits'] === 0 && (sub_of($acct['username'])['status'] ?? '') === 'disabled' && $store->getAccount($C['id'])['disabled'] === 1, array($r, sub_of($acct['username'])));
$check('revoke again is still success', $prov->revoke($revokeJob($D2, 1))['ok'] === true && (int) sub_of($acct['username'])['credits'] === 0);

$r = $prov->provision($subJob($D2, 1, 50, $C));
$check('paid again: the account is enabled again and gets its credits (new transfer)', $r['ok'] === true && (sub_of($acct['username'])['status'] ?? '') === 'active' && (int) sub_of($acct['username'])['credits'] === 50 && subs_with_email($C['email']) === 1, array($r, sub_of($acct['username'])));
$prov->provision($subJob($D2, 1, 50, $C));
$check('paid again, repeated: no second transfer', (int) sub_of($acct['username'])['credits'] === 50);
$check('the generation counts the revocation', $store->getUnit($D2, 1)['generation'] === 1, $store->getUnit($D2, 1));

echo "== sub-reseller unit, take-back that cannot work\n";
$C3 = $customer(3);
$D4 = $D + 30;
$prov->provision($subJob($D4, 1, 20, $C3));
$a3 = $store->getAccount($C3['id']);
$cleanupUsers[] = $a3['panel_user_id'];
$secrets[] = $a3['password'];
panel('adjust_credits', array('id' => $a3['panel_user_id'], 'credits' => -15, 'note' => 'harness', 'request_id' => "h-$RUN-spend"), true);
$r = $prov->revoke($revokeJob($D4, 1));
$check('credits already spent: reported, nothing changed, unit stays active with the message', $r['ok'] === false && stripos($r['message'], 'credits') !== false && $store->getUnit($D4, 1)['status'] === 'ok' && stripos($store->getUnit($D4, 1)['error'], 'spent') !== false && (int) sub_of($a3['username'])['credits'] === 5, array($r, sub_of($a3['username'])));
panel('adjust_credits', array('id' => $a3['panel_user_id'], 'credits' => 15, 'note' => 'harness', 'request_id' => "h-$RUN-restore"), true);
$r = $prov->revoke($revokeJob($D4, 1));
$check('revoke again after the credits are back works', $r['ok'] === true && (int) sub_of($a3['username'])['credits'] === 0 && $store->getUnit($D4, 1)['status'] === 'revoked' && $store->getUnit($D4, 1)['error'] === '', array($r, sub_of($a3['username'])));

echo "== sub-reseller unit, too few credits fails at once; a transfer that did not happen resumes\n";
$C4 = $customer(4);
$D5 = $D + 40;
$usersBefore = count(panel('get_users', array('start' => 0, 'limit' => 500))['data'] ?? array());
$r = $prov->provision($subJob($D5, 1, 999999999, $C4));
$check('too few credits for the account plus its starting credits fail at once with the amounts and create no account',
    $r['ok'] === false && stripos($r['message'], 'needs 1000000009 credits') !== false && $store->getAccount($C4['id']) === null && count(panel('get_users', array('start' => 0, 'limit' => 500))['data'] ?? array()) === $usersBefore, $r);
// The resume path (the transfer failed after the account was made, e.g. a race with another sale): an account that exists and a unit in error.
$r = $prov->provision($subJob($D5, 1, 0, $C4));
$a4 = $store->getAccount($C4['id']);
$cleanupUsers[] = $a4['panel_user_id'] ?? '';
$secrets[] = $a4['password'] ?? '';
$check('an account is created with no credits to hand over', $r['ok'] === true && !empty($a4['panel_user_id']) && (int) sub_of($a4['username'])['credits'] === 0, array($r, $a4));
$store->saveUnit($D5, 1, array('status' => 'error', 'error' => 'transfer did not happen'));
$r = $prov->provision($subJob($D5, 1, 30, $C4));
$check('provision again only completes the transfer (no second account)', $r['ok'] === true && (int) sub_of($a4['username'])['credits'] === 30 && subs_with_email($C4['email']) === 1 && $store->getUnit($D5, 1)['status'] === 'ok', array($r, sub_of($a4['username'])));

echo "== sub-reseller unit, errors\n";
$C5 = $customer(5);
$r = $prov->provision($subJob($D + 50, 1, 10, array_merge($C5, array('is_guest' => true))));
$check('a guest order is refused readably and creates nothing', $r['ok'] === false && stripos($r['message'], 'guest') !== false && $store->getAccount($C5['id']) === null && subs_with_email($C5['email']) === 0, $r);
$wrongStore = new MemoryStore();
$provWrong = new XtreamproProvisioner(new XtreamproApiClient($API_BASE, 'xk_wrong_key_' . $RUN, $logger), $wrongStore, $instance, $logger);
$r = $provWrong->provision($subJob($D + 51, 1, 10, $C5));
$check('wrong API key fails readably', $r['ok'] === false && stripos($r['message'], 'API key') !== false && $wrongStore->getUnit($D + 51, 1)['status'] === 'error', $r);
$check('a key the panel refuses is found out by the early pricing check: no account row is kept', $wrongStore->getAccount($C5['id']) === null);
$bad = $customer(6);
$bad['email'] = 'not-an-email';
$r = $prov->provision($subJob($D + 52, 1, 10, $bad));
$check('an invalid email is refused by the panel with a readable text', $r['ok'] === false && stripos($r['message'], 'rejected') !== false, $r);
$check('the credentials were saved before the create call, so a retry sends the same ones', ($store->getAccount($bad['id'])['username'] ?? '') !== '' && $store->getAccount($bad['id'])['panel_user_id'] === '');
$secrets[] = $store->getAccount($bad['id'])['password'] ?? '';

// ==========================================================================================
echo "== pages and mail (presenter)\n";
$labels = array_fill_keys(XtreamproPresenter::labelKeys(), 'L');
$labels = array_merge($labels, array('username' => 'Username', 'password' => 'Password', 'title_lines' => 'Lines', 'title_reseller' => 'Reseller', 'server' => 'Server', 'playlist' => 'Playlist',
    'notice_error' => 'Could not be set up', 'notice_revoked' => 'Cancelled', 'signin' => 'Sign in', 'player' => 'Player'));
$units = array($store->getUnit($D, 1), $store->getUnit($D2, 1), $store->getUnit($DW + 5, 1));
$units = array_values(array_filter($units));
$names = array($D => 'IPTV 1 year <b>', $D2 => 'Reseller pack');
$cards = XtreamproPresenter::cards($units, $names, 'https://panel.example.test', $API_BASE, $labels, false);
$line = $cards[0];
$byLabel = function ($card) { $o = array(); foreach ($card['fields'] as $f) { $o[$f['label']] = $f; } return $o; };
$f = $byLabel($line);
$check('line card: credentials and the panel\'s links', $f['Username']['value'] === $store->getUnit($D, 1)['username'] && $f['Password']['value'] === $store->getUnit($D, 1)['password'] && $f['Playlist']['url'] !== '', $line);
$check('reseller card: username, password (creating order), credits, sign-in link', isset($cards[1]['fields']) && $byLabel($cards[1])['Sign in']['url'] === 'https://panel.example.test', $cards[1]);
$check('no live data, no status fields', !isset($f['Status']) && !isset($f['Expires']));
$live = array($D . '-1' => array('status' => 'active', 'exp_date' => 1893456000, 'max_connections' => 3));
$f2 = $byLabel(XtreamproPresenter::cards(array($units[0]), $names, '', '', array_merge($labels, array('status' => 'Status', 'expires' => 'Expires', 'connections' => 'Conn')), false, $live)[0]);
$check('live data adds status, expiry and connections', $f2['Status']['value'] === 'active' && $f2['Expires']['value'] === '2030-01-01 00:00 UTC' && $f2['Conn']['value'] === '3', $f2);

$err = array(array_merge($store->getUnit($DU + 0, 1), array('status' => 'error', 'error' => 'INSUFFICIENT secret-detail', 'password' => 'pw-x')));
$cc = XtreamproPresenter::cards($err, array($DU => 'X'), '', '', $labels, false)[0];
$ca = XtreamproPresenter::cards($err, array($DU => 'X'), '', '', $labels, true)[0];
$check('a failed unit shows the customer a generic notice and no credentials or error text', $cc['notice'] === 'Could not be set up' && !$cc['fields'] && $cc['error'] === '', $cc);
$check('the admin sees the error text', $ca['error'] === 'INSUFFICIENT secret-detail');
$rv = XtreamproPresenter::cards(array($store->getUnit($DE, 1)), array(), '', '', $labels, false)[0];
$check('a revoked unit shows no credentials', $rv['notice'] === 'Cancelled' && !$rv['fields']);

$evil = $store->getUnit($D, 1);
$evil['username'] = '<script>alert(1)</script>';
$evil['password'] = "pw\nInjected: line";
$evil['links'] = array('m3u' => 'javascript:alert(1)', 'web_player' => 'https://ok.example.test/player/?a=1&b=2', 'server' => 'http://s.example.test');
$html = XtreamproPresenter::mailHtml(XtreamproPresenter::cards(array($evil), array($D => 'N<i>'), '', '', $labels, false), $labels);
$check('mail HTML escapes every value', strpos($html, '<script>') === false && strpos($html, '&lt;script&gt;') !== false && strpos($html, 'N<i>') === false);
$check('mail HTML never makes a javascript: link clickable, and escapes a good one', stripos($html, 'href="javascript') === false && strpos($html, 'href="https://ok.example.test/player/?a=1&amp;b=2"') !== false, $html);
$check('a link containing a quote is not made clickable', XtreamproPresenter::safeUrl('https://x.test/"onmouseover="a') === '' && XtreamproPresenter::safeUrl('ftp://x.test/') === '');
$text = XtreamproPresenter::mailText(XtreamproPresenter::cards(array($evil), array($D => 'N'), '', '', $labels, false), $labels);
$check('mail text keeps one value per line', strpos($text, "Injected: line") !== false && strpos($text, "\nInjected: line") === false && strpos($text, 'Password: pw Injected: line') !== false, $text);
$check('a mail without any credentials is empty', XtreamproPresenter::mailHtml(array(), $labels) === '' && XtreamproPresenter::mailText(array(), $labels) === '');

// ==========================================================================================
echo "== secrets\n";
$all = implode("\n", $logs);
foreach ($store->units as $row) { $all .= "\n" . $row['error']; }
$check('the leak check had real secrets to look for', count(array_unique(array_filter($secrets))) >= 8, (string) count($secrets));
$check('the core logged its calls', count($logs) > 40, (string) count($logs));
$leak = '';
foreach (array_unique(array_filter($secrets)) as $s) {
    if (strpos($all, $s) !== false) { $leak = $s === $API_KEY ? 'API key' : 'a password'; break; }
}
$check('neither the API key nor any password reached a log or an error text', $leak === '', $leak);
$check('no clear password= in any logged link', !preg_match('/password=(?!\*)[^&"\\\\]/', $all));
$check('no key header in any log', stripos($all, 'X-API-Key') === false);

// ---- clean up what is left on the shared panel ----------------------------------------------
foreach (array_unique(array_filter($cleanupLines)) as $id) { panel('delete_line', array('id' => $id), true); }
foreach (array_unique(array_filter($cleanupUsers)) as $id) { panel('disable_user', array('id' => $id), true); }

echo $fail ? "\n$fail FAILED\n" : "\nALL OK\n";
exit($fail ? 1 : 0);
