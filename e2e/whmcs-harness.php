<?php
// Stand-in for the parts of WHMCS the module uses, to run its functions for
// real against a panel API. Not shipped.
namespace WHMCS\Database {
    class Raw { public $expr; function __construct($e) { $this->expr = $e; } }
    class Blueprint {
        public $cols = array();
        function __call($name, $args) { if ($args) { $this->cols[] = $args[0]; } return $this; }
    }
    class Schema {
        function hasTable($t) { return isset(Capsule::$tables[$t]); }
        function create($t, $fn) { $b = new Blueprint(); $fn($b); Capsule::$tables[$t] = array(); Capsule::$columns[$t] = $b->cols; }
        function hasColumn($t, $c) { return in_array($c, Capsule::$columns[$t] ?? array(), true); }
        function table($t, $fn) { $b = new Blueprint(); $fn($b); Capsule::$columns[$t] = array_merge(Capsule::$columns[$t] ?? array(), $b->cols); }
    }
    class Query {
        private $t; private $w = array();
        function __construct($t) { $this->t = $t; }
        function where($c, $a, $b = null) { $this->w[$c] = $b === null ? $a : array($a, $b); return $this; }
        private function match() {
            $out = array();
            foreach (Capsule::$tables[$this->t] ?? array() as $i => $row) {
                $ok = true;
                foreach ($this->w as $c => $v) {
                    if (is_array($v)) { // where(column, operator, value): only '<' is used
                        if (!array_key_exists($c, $row) || $v[0] !== '<' || !($row[$c] < $v[1])) { $ok = false; }
                    } elseif (!array_key_exists($c, $row) || $row[$c] != $v) { $ok = false; }
                }
                if ($ok) { $out[] = $i; }
            }
            return $out;
        }
        function exists() { return (bool) $this->match(); }
        function get() { $o = array(); foreach ($this->match() as $i) { $o[] = (object) Capsule::$tables[$this->t][$i]; } return $o; }
        function value($c) { $m = $this->match(); return $m ? (Capsule::$tables[$this->t][$m[0]][$c] ?? null) : null; }
        function update($vals) {
            foreach ($this->match() as $i) {
                foreach ($vals as $c => $v) {
                    if ($v instanceof Raw) {
                        if (!preg_match('/^(\w+) \+ 1$/', $v->expr, $mm)) { throw new \Exception('raw: ' . $v->expr); }
                        $v = (Capsule::$tables[$this->t][$i][$mm[1]] ?? 0) + 1;
                    }
                    Capsule::$tables[$this->t][$i][$c] = $v;
                }
            }
        }
        function insert($vals) { Capsule::$tables[$this->t][] = $vals + array('generation' => 0, 'user_id' => null); }
        function delete() { foreach ($this->match() as $i) { unset(Capsule::$tables[$this->t][$i]); } }
    }
    class Capsule {
        public static $tables = array('tblhosting' => array());
        public static $columns = array();
        static function schema() { return new Schema(); }
        static function table($t) { return new Query($t); }
        static function raw($e) { return new Raw($e); }
    }
}
namespace {
    use WHMCS\Database\Capsule;
    define('WHMCS', true);
    $GLOBALS['service'] = array();
    $GLOBALS['modlog'] = array();
    $GLOBALS['activity'] = array();
    function logModuleCall($m, $fn, $req, $resp, $processed, $redact = array()) {
        $all = json_encode(array($req, $resp, $processed));
        foreach ($redact as $secret) { if ($secret !== '' && strpos($all, $secret) !== false) { $GLOBALS['leak'] = "API key in module log of $fn"; } }
        $GLOBALS['modlog'][] = array($fn, $all);
    }
    function logActivity($m) { $GLOBALS['activity'][] = $m; echo "  [activity] $m\n"; }
    function localAPI($cmd, $vals) {
        if ($cmd === 'UpdateClientProduct') {
            if (isset($vals['serviceusername'])) { $GLOBALS['service']['username'] = $vals['serviceusername']; }
            if (isset($vals['servicepassword'])) { $GLOBALS['service']['password'] = $vals['servicepassword']; }
            if (isset($vals['status'])) { $GLOBALS['service']['status'] = $vals['status']; }
            return array('result' => 'success');
        }
        // Stand-ins for WHMCS' encryption: reversible, and not the plain text.
        if ($cmd === 'EncryptPassword') { return array('result' => 'success', 'password' => 'enc:' . strrev(base64_encode($vals['password2']))); }
        if ($cmd === 'DecryptPassword') { return array('result' => 'success', 'password' => base64_decode(strrev(substr($vals['password2'], 4)))); }
        return array('result' => 'error');
    }
    $GLOBALS['CONFIG'] = array('SystemURL' => 'https://whmcs.example.test');
    require getenv('MODULE');

    $base = array(
        'serverhostname' => '127.0.0.1', 'serverport' => getenv('API_PORT_NUM'), 'serversecure' => '', 'serverhttpprefix' => 'http',
        'serverpassword' => getenv('API_KEY'), 'serveraccesshash' => '',
        'clientsdetails' => array('email' => 'whmcs-' . getmypid() . '@example.test', 'firstname' => 'Ann', 'lastname' => 'Berg'),
    );
    $fail = 0;
    $check = function ($label, $ok, $detail = '') use (&$fail) {
        echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' -> ' . (is_string($detail) ? $detail : json_encode($detail))) . "\n";
        if (!$ok) { $fail++; }
    };
    $params = function (array $extra) use ($base) {
        return array_merge($base, array('username' => $GLOBALS['service']['username'] ?? '', 'password' => $GLOBALS['service']['password'] ?? ''), $extra);
    };

    echo "== meta\n";
    $check('MetaData', xtreampro_MetaData()['DisplayName'] === 'Xtream UI Pro');
    $opts = xtreampro_ConfigOptions();
    $check('ConfigOptions keep their order and the new ones come last', array_keys($opts)[0] === 'Package' && array_keys($opts)[2] === 'Delete on terminate'
        && array_slice(array_keys($opts), 6) === array('Sub-reseller on cancellation', 'Panel address', 'Sub-reseller group') && count($opts) === 9, array_keys($opts));
    $check('Delete on terminate is off by default (disable is the default on cancellation)', !isset($opts['Delete on terminate']['Default']) || $opts['Delete on terminate']['Default'] !== 'on');
    $check('the cancellation options say that deleting is final', stripos($opts['Delete on terminate']['Description'], 'final') !== false && stripos($opts['Sub-reseller on cancellation']['Description'], 'final') !== false);
    $groups = xtreampro_groupLoader($base);
    $check('group loader offers the default and the groups the panel allows', isset($groups[0]) && count($groups) >= 2, $groups);
    $pk = xtreampro_packageLoader($base);
    $check('package loader lists packages', is_array($pk) && count($pk) >= 1, $pk);
    // `sells` of the panel decides what is offered: the box-only package of the setup is left out, not discovered at sale time.
    $apiPackages = xtreampro_client($base)->packages();
    $boxOnly = array_values(array_filter($apiPackages, function ($p) { return isset($p['sells']) && !in_array('line', $p['sells'], true); }));
    $check('the panel marks the box-only package with sells without "line"', count($boxOnly) >= 1 && $boxOnly[0]['sells'] === array('mag'), $apiPackages);
    $boxId = (int) $boxOnly[0]['id'];
    $check('the package loader leaves out a package that cannot be sold as a line', !isset($pk[$boxId]), array_keys($pk));
    $check('and keeps every package that can', count($pk) === count($apiPackages) - count($boxOnly), array(count($pk), count($apiPackages), count($boxOnly)));
    $check('a panel that sends no sells counts as selling lines', XtreamPro\Whmcs\Client::sellsLine(array('id' => 1)) && !XtreamPro\Whmcs\Client::sellsLine(array('sells' => array('mag'))));
    $r = xtreampro_TestConnection($base);
    $check('TestConnection', $r['success'] === true, $r);
    $bad = xtreampro_TestConnection(array_merge($base, array('serverpassword' => 'xk_wrong')));
    $check('TestConnection with a wrong key fails readably', $bad['success'] === false && stripos($bad['error'], 'API key') !== false, $bad);

    echo "== line service\n";
    // Packages sold as an official period (the loader labels trial-only ones).
    // Packages that can be sold as an official period on a plain line, by the panel's own `sells` (the loader already leaves box-only ones out).
    $official = array_keys(array_filter($pk, function ($label) { return strpos($label, 'trial only') === false; }));
    $check('the panel offers at least one official package', count($official) >= 1, $pk);
    $pkgId = (string) $official[0];
    $line = array('serviceid' => 1001 + (getmypid() % 100000), 'configoption1' => $pkgId, 'configoption2' => '', 'configoption3' => 'on', 'configoption4' => '', 'configoption5' => '', 'configoption6' => '');

    // Nothing is sold when the package is not on sale: the check runs before the panel is asked to create anything.
    $missing = array_merge($line, array('serviceid' => 7000000 + (getmypid() % 100000), 'configoption1' => '999999'));
    $r = xtreampro_CreateAccount($params($missing));
    $check('a package the reseller may not sell is refused before anything is created', $r !== 'success' && stripos($r, 'not in the list') !== false, $r);
    $check('and no mapping row was made for it', xtreampro_storedLineId($missing['serviceid']) === 0);
    $boxy = array_merge($line, array('serviceid' => 7100000 + (getmypid() % 100000), 'configoption1' => (string) $boxId));
    $r = xtreampro_CreateAccount($params($boxy));
    $check('a box-only package is refused before anything is created, with a readable reason', $r !== 'success' && stripos($r, 'boxes only') !== false && xtreampro_storedLineId($boxy['serviceid']) === 0, $r);
    $GLOBALS['service'] = array('username' => 'whmcs' . getmypid() . 'x', 'password' => 'Passw0rd-' . getmypid());
    $r = xtreampro_CreateAccount($params($line));
    $check('CreateAccount', $r === 'success', $r);
    $check('credentials saved on the service', ($GLOBALS['service']['username'] ?? '') !== '' && ($GLOBALS['service']['password'] ?? '') !== '', $GLOBALS['service']);
    $r2 = xtreampro_CreateAccount($params($line));
    $check('CreateAccount repeated does not fail (replay)', $r2 === 'success', $r2);
    $ca = xtreampro_ClientArea($params($line));
    $v = $ca['vars'];
    $check('ClientArea', $v['error'] === '' && $v['username'] === $GLOBALS['service']['username'] && strpos($v['playlistUrl'], '/get.php?') !== false, $v);
    $check('ClientArea shows the links the panel returned (HLS playlist, guide, web player)', strpos($v['hlsUrl'], 'output=m3u8') !== false && strpos($v['epgUrl'], '/xmltv.php?') !== false && substr($v['playerUrl'], -8) === '/player/', $v);
    $c0 = xtreampro_client($base);
    $logs = $c0->get('api_logs', array('limit' => 50));
    $names = array();
    foreach ((isset($logs['data']) && is_array($logs['data']) ? $logs['data'] : array()) as $row) { $names[] = isset($row['connector']) ? $row['connector'] : ''; }
    $check('the panel call log names the connector', in_array('whmcs/' . XtreamPro\Whmcs\Client::VERSION, $names, true), array_unique($names));
    $check('client area template exists', is_file(dirname(getenv('MODULE')) . '/' . $ca['templatefile'] . '.tpl'));
    $adm = xtreampro_AdminServicesTabFields($params($line));
    $check('AdminServicesTabFields', isset($adm['Line status']) && $adm['Line status'] === 'active', $adm);
    $check('Suspend', ($r = xtreampro_SuspendAccount($params($line))) === 'success', $r);
    $adm = xtreampro_AdminServicesTabFields($params($line));
    $check('status after suspend is disabled', ($adm['Line status'] ?? '') === 'disabled', $adm);
    $check('Unsuspend', ($r = xtreampro_UnsuspendAccount($params($line))) === 'success', $r);
    $before = xtreampro_AdminServicesTabFields($params($line))['Line expiry'];
    $check('Renew', ($r = xtreampro_Renew($params($line))) === 'success', $r);
    $after = xtreampro_AdminServicesTabFields($params($line))['Line expiry'];
    $check('expiry moved by the renewal', $before !== $after, "$before -> $after");
    $check('Renew repeated the same day does not extend twice', xtreampro_Renew($params($line)) === 'success' && xtreampro_AdminServicesTabFields($params($line))['Line expiry'] === $after);
    // Upgrade / downgrade: the line takes the package selected on the product and is charged for it.
    $other = $pkgId;
    foreach ($official as $candidate) { if ((string) $candidate !== $pkgId) { $other = (string) $candidate; break; } }
    $lineBefore = (new XtreamPro\Whmcs\Client(xtreampro_baseUrl($base), $base['serverpassword']))->getLine(xtreampro_storedLineId($line['serviceid']));
    $balanceBefore = (int) xtreampro_client($base)->userInfo()['credits'];
    $changed = array_merge($line, array('configoption1' => $other));
    // The question the module asks before selling: is the time left kept, and what does it cost?
    $cl = xtreampro_client($base);
    $compat = $cl->packageCompatibility(xtreampro_storedLineId($line['serviceid']), (int) $other);
    $check('package_compatibility answers keeps_time_left, reason and price', is_array($compat) && is_bool($compat['keeps_time_left']) && isset($compat['reason']) && isset($compat['price']) && array_key_exists('can_afford', $compat), $compat);
    $check('and it describes the answer for an admin', stripos(XtreamPro\Whmcs\Client::describeCompatibility($compat), 'costs ' . (int) $compat['price'] . ' credits') !== false, XtreamPro\Whmcs\Client::describeCompatibility($compat));
    $check('the same question for a box-only package is INVALID_PACKAGE', (function () use ($cl, $line, $boxId) {
        try { $cl->packageCompatibility(xtreampro_storedLineId($line['serviceid']), $boxId); return false; } catch (XtreamPro\Whmcs\ApiException $e) { return $e->getErrorCode() === 'INVALID_PACKAGE'; }
    })());
    $restart = XtreamPro\Whmcs\Client::describeCompatibility(array('keeps_time_left' => false, 'reason' => 'incompatible', 'time_left_seconds' => 864000, 'time_lost_seconds' => 864000, 'price' => 7));
    $check('a change that restarts the period says so, with the time lost and the price', strpos($restart, 'starts today') !== false && strpos($restart, '10 days') !== false && strpos($restart, 'lost') !== false && strpos($restart, 'costs 7 credits') !== false, $restart);
    $oldPanel = new class(xtreampro_baseUrl($base), $base['serverpassword']) extends XtreamPro\Whmcs\Client {
        public function get($action, array $query = array()) { throw new XtreamPro\Whmcs\ApiException('UNKNOWN_ACTION', 400); }
    };
    $check('a panel that does not know the action gives null, not an error', $oldPanel->packageCompatibility(1, 1) === null);
    $GLOBALS['activity'] = array();
    $r = xtreampro_ChangePackage($params($changed));
    $check('ChangePackage', $r === 'success', $r);
    $logged = implode(' | ', $GLOBALS['activity']);
    $check('the admin finds what the change did to the time left and what it cost in the activity log', strpos($logged, 'moved to package ' . $other) !== false && strpos($logged, 'On the panel the ') !== false && strpos($logged, 'credits.') !== false, $logged);
    $check('ChangePackage to a box-only package fails readably and changes nothing', stripos(xtreampro_ChangePackage($params(array_merge($line, array('configoption1' => (string) $boxId)))), 'boxes only') !== false && xtreampro_changeCount($line['serviceid']) === 1);
    $lineAfter = xtreampro_client($base)->getLine(xtreampro_storedLineId($line['serviceid']));
    $check('the line has the new package' . ($other === $pkgId ? ' (only one package exists here, so the same one)' : ''), (string) $lineAfter['package_id'] === $other, array($lineBefore['package_id'], $lineAfter['package_id']));
    $check('and the change was charged', (int) xtreampro_client($base)->userInfo()['credits'] < $balanceBefore, $balanceBefore);
    $check('the change counter moved on', xtreampro_changeCount($line['serviceid']) === 1);
    $check('ChangePackage of a sub-reseller service is a no-op that succeeds', xtreampro_ChangePackage($params(array('serviceid' => 1, 'configoption4' => 'reseller'))) === 'success');
    $check('ChangePackage to a package that is not on sale fails readably', stripos(xtreampro_ChangePackage($params(array_merge($line, array('configoption1' => '999999')))), 'not in the list') !== false);

    echo "== webhooks\n";
    $reg = xtreampro_registerWebhook($params($line + array('serverid' => 1)));
    $check('Register panel webhook', $reg === 'success', $reg);
    $secrets = xtreampro_webhookSecrets();
    $check('the secret is kept encrypted and read back', count($secrets) === 1 && strpos($secrets[0], 'whsec_') === 0, $secrets);
    $check('registering twice is refused', xtreampro_registerWebhook($params($line + array('serverid' => 1))) !== 'success');
    $check('Send webhook test (ping)', ($r = xtreampro_testWebhook($params($line + array('serverid' => 1)))) === 'success', $r);
    $lineId = xtreampro_storedLineId($line['serviceid']);
    $sign = function ($body, $ts, $secret) { return 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, $secret); };
    $event = function ($type, $data = null) use ($lineId) { return json_encode(array('id' => 'evt_test', 'type' => $type, 'created' => time(), 'data' => $data === null ? array('line_id' => $lineId) : $data)); };
    $now = time();
    $body = $event('line.expired');
    $ok = XtreamPro\Whmcs\Webhook::receive($body, (string) $now, $sign($body, $now, $secrets[0]), $secrets, $now);
    $check('a signed event is accepted', $ok['status'] === 200 && $ok['event']['type'] === 'line.expired', $ok);
    $check('a wrong signature is refused (401)', XtreamPro\Whmcs\Webhook::receive($body, (string) $now, $sign($body, $now, 'whsec_other'), $secrets, $now)['status'] === 401);
    $check('a changed body is refused (401)', XtreamPro\Whmcs\Webhook::receive($body . ' ', (string) $now, $sign($body, $now, $secrets[0]), $secrets, $now)['status'] === 401);
    $check('an unsigned call is refused (401)', XtreamPro\Whmcs\Webhook::receive($body, (string) $now, '', $secrets, $now)['status'] === 401);
    $check('a call without timestamp is refused (400)', XtreamPro\Whmcs\Webhook::receive($body, '', $sign($body, $now, $secrets[0]), $secrets, $now)['status'] === 400);
    $old = $now - 3600;
    $check('a replay outside the time window is refused (400), even though the signature is right', XtreamPro\Whmcs\Webhook::receive($body, (string) $old, $sign($body, $old, $secrets[0]), $secrets, $now)['status'] === 400);
    $check('without a registered secret nothing is accepted (503)', XtreamPro\Whmcs\Webhook::receive($body, (string) $now, $sign($body, $now, $secrets[0]), array(), $now)['status'] === 503);
    $check('ping is acknowledged', xtreampro_applyWebhookEvent(array('type' => 'ping', 'data' => array())) === 'pong');
    $check('an event of an unknown line is acknowledged and ignored', xtreampro_applyWebhookEvent(array('type' => 'line.deleted', 'data' => array('line_id' => 987654321))) === 'unknown line');
    $check('line.expired is recorded, the service is not suspended', xtreampro_applyWebhookEvent(json_decode($body, true)) === 'recorded' && !isset($GLOBALS['service']['status']));
    $adm = xtreampro_AdminServicesTabFields($params($line));
    $check('the admin tab shows the last webhook', isset($adm['Last panel webhook']) && strpos($adm['Last panel webhook'], 'expired') !== false, $adm);
    $check('line.renewed moves the recorded status on', xtreampro_applyWebhookEvent(json_decode($event('line.renewed'), true)) === 'recorded' && strpos(xtreampro_AdminServicesTabFields($params($line))['Last panel webhook'], 'active') !== false);
    // The panel delivers at least once and keeps the event id the same on every retry: the receiver applies an id once.
    $post = function ($body) use ($sign, $secrets) { $ts = time(); return xtreampro_processWebhook($body, (string) $ts, $sign($body, $ts, $secrets[0])); };
    $status = function () use ($line) { return Capsule::table('mod_xtreampro_lines')->where('service_id', $line['serviceid'])->value('panel_status'); };
    $dupBody = json_encode(array('id' => 'evt_dup_' . getmypid(), 'type' => 'line.disabled', 'created' => time(), 'data' => array('line_id' => $lineId, 'owner_id' => 'someone')));
    $r1 = $post($dupBody);
    $check('a signed event is applied the first time', $r1['status'] === 200 && $r1['message'] === 'recorded' && $status() === 'disabled', $r1);
    Capsule::table('mod_xtreampro_lines')->where('service_id', $line['serviceid'])->update(array('panel_status' => 'active'));
    $r2 = $post($dupBody);
    $check('the same event id again is acknowledged (200) and not applied twice', $r2['status'] === 200 && $r2['message'] === 'duplicate' && $status() === 'active', array($r2, $status()));
    $r3 = $post(str_replace('evt_dup_', 'evt_other_', $dupBody));
    $check('another event id of the same kind is applied', $r3['message'] === 'recorded' && $status() === 'disabled', $r3);
    $noId = json_encode(array('type' => 'line.enabled', 'created' => time(), 'data' => array('line_id' => $lineId)));
    $check('an event without an id (older panel) is applied every time', $post($noId)['message'] === 'recorded' && $post($noId)['message'] === 'recorded');
    $check('an id of an unexpected shape is not used for de-duplication', XtreamPro\Whmcs\Webhook::eventId(array('id' => str_repeat('a', 65))) === '' && XtreamPro\Whmcs\Webhook::eventId(array('id' => array('x'))) === '' && XtreamPro\Whmcs\Webhook::eventId(array('id' => 'evt_9f2c41d07b3a5e6c81d2f4a0')) === 'evt_9f2c41d07b3a5e6c81d2f4a0');
    $check('a bad signature is refused before the id is looked at (401)', xtreampro_processWebhook($dupBody, (string) time(), 'sha256=00')['status'] === 401);
    // Retention: ids older than a day are dropped when a new one is stored, so the table stays bounded.
    xtreampro_ensureEventTable();
    Capsule::table('mod_xtreampro_webhook_events')->insert(array('event_id' => 'evt_ancient', 'seen_at' => date('Y-m-d H:i:s', time() - 2 * 86400)));
    xtreampro_rememberEvent('evt_fresh');
    $check('an id older than the retention is forgotten, a fresh one is kept', !xtreampro_eventSeen('evt_ancient') && xtreampro_eventSeen('evt_fresh') && xtreampro_eventSeen('evt_dup_' . getmypid()));
    $check('an event of a line owned by an account this WHMCS does not know is acknowledged and ignored', xtreampro_applyWebhookEvent(array('type' => 'line.expired', 'data' => array('line_id' => 987654322, 'owner_id' => '0197a1c2-5f3e-7d10-8a41-6b2c9e0d4f55', 'owner_username' => 'reseller_a'))) === 'unknown line');
    $check('line.deleted terminates the service and closes the mapping', xtreampro_applyWebhookEvent(json_decode($event('line.deleted'), true)) === 'terminated'
        && ($GLOBALS['service']['status'] ?? '') === 'Terminated' && xtreampro_storedLineId($line['serviceid']) === 0, $GLOBALS['service']);
    $hookRows = function () { $d = xtreampro_client($GLOBALS['baseParams'])->get('get_webhooks')['data']; return is_array($d) ? $d : array(); };
    $GLOBALS['baseParams'] = $base;
    $mine = array_values(array_filter($hookRows(), function ($h) { return strpos((string) $h['url'], 'whmcs.example.test') !== false; }));
    $check('the default registration does not ask for the events of sub-resellers\' lines', count($mine) === 1 && empty($mine[0]['include_sub_resellers']), $mine);
    $check('Remove panel webhook', ($r = xtreampro_removeWebhook($params($line + array('serverid' => 1)))) === 'success' && !xtreampro_webhookSecrets(), $r);
    $check('Register panel webhook with the sub-resellers\' lines', ($r = xtreampro_registerWebhookSubs($params($line + array('serverid' => 1)))) === 'success', $r);
    $mine = array_values(array_filter($hookRows(), function ($h) { return strpos((string) $h['url'], 'whmcs.example.test') !== false; }));
    $check('and the panel holds it with include_sub_resellers', count($mine) === 1 && !empty($mine[0]['include_sub_resellers']), $mine);
    $check('Remove it again', ($r = xtreampro_removeWebhook($params($line + array('serverid' => 1)))) === 'success' && !xtreampro_webhookSecrets(), $r);
    // The line of the service was deleted on the panel by the event above only locally: delete it for real so the rest of the run is clean.
    unset($GLOBALS['service']['status']);

    echo "== line service (continued)\n";
    $check('Terminate', ($r = xtreampro_TerminateAccount($params($line))) === 'success', $r);
    $gone = xtreampro_ClientArea($params($line));
    $check('ClientArea after terminate shows a friendly error', $gone['vars']['error'] !== '', $gone['vars']);
    $check('Terminate again is still success', xtreampro_TerminateAccount($params($line)) === 'success');
    $gen = function ($sid) { return xtreampro_generation($sid); };
    $GLOBALS['service'] = array('username' => 'whmcs' . getmypid() . 'y', 'password' => 'Passw0rd-' . getmypid());
    $r = xtreampro_CreateAccount($params($line));
    $check('Create after terminate sells a new line', $r === 'success', $r);
    xtreampro_TerminateAccount($params($line));

    // The line is deleted on the panel behind WHMCS' back, then Create is asked again for the same service:
    // the panel answers REQUEST_ID_SPENT (it never sells a second line under a paid request id).
    $spent = array_merge($line, array('serviceid' => 7200000 + (getmypid() % 100000)));
    $GLOBALS['service'] = array('username' => 'whmcs' . getmypid() . 'z', 'password' => 'Passw0rd-' . getmypid());
    $check('(spent request id) Create sells a line', ($r = xtreampro_CreateAccount($params($spent))) === 'success', $r);
    xtreampro_client($base)->lineAction('delete_line', xtreampro_storedLineId($spent['serviceid']));
    $r = xtreampro_CreateAccount($params($spent));
    $check('Create again for a line deleted on the panel gives a readable message (REQUEST_ID_SPENT)', $r !== 'success' && stripos($r, 'already made') !== false && stripos($r, 'Terminate') !== false, $r);
    $before = $gen($spent['serviceid']);
    $check('Terminate of that service succeeds and moves the generation on', xtreampro_TerminateAccount($params($spent)) === 'success' && $gen($spent['serviceid']) === $before + 1, array($before, $gen($spent['serviceid'])));
    $GLOBALS['service'] = array('username' => 'whmcs' . getmypid() . 'w', 'password' => 'Passw0rd-' . getmypid());
    $check('and then Create sells a new line', ($r = xtreampro_CreateAccount($params($spent))) === 'success' && xtreampro_storedLineId($spent['serviceid']) > 0, $r);
    xtreampro_TerminateAccount($params($spent));

    echo "== sub-reseller service\n";
    $GLOBALS['service'] = array();
    $sub = array('serviceid' => 2001 + (getmypid() % 100000), 'configoption1' => '', 'configoption2' => '', 'configoption3' => 'on', 'configoption4' => 'reseller', 'configoption5' => '50', 'configoption6' => '20');
    $r = xtreampro_CreateAccount($params($sub));
    $check('CreateAccount (sub-reseller, generated credentials)', $r === 'success', $r);
    $check('generated credentials saved', strlen($GLOBALS['service']['username'] ?? '') >= 3 && strlen($GLOBALS['service']['password'] ?? '') >= 8, $GLOBALS['service']);
    $adm = xtreampro_AdminServicesTabFields($params($sub));
    $check('admin tab shows the balance of 50', in_array('50', array_map('strval', $adm), true), $adm);
    $r = xtreampro_CreateAccount($params($sub));
    $adm2 = xtreampro_AdminServicesTabFields($params($sub));
    $check('Create repeated neither fails nor credits twice', $r === 'success' && $adm2 == $adm, array($r, $adm2));
    $ca = xtreampro_ClientArea($params($sub));
    $check('ClientArea (sub-reseller)', ($ca['vars']['error'] ?? 'x') === '' && is_file(dirname(getenv('MODULE')) . '/' . $ca['templatefile'] . '.tpl'), $ca);
    $check('Suspend', ($r = xtreampro_SuspendAccount($params($sub))) === 'success', $r);
    $check('Unsuspend', ($r = xtreampro_UnsuspendAccount($params($sub))) === 'success', $r);
    $check('Renew tops up', ($r = xtreampro_Renew($params($sub))) === 'success', $r);
    $adm3 = xtreampro_AdminServicesTabFields($params($sub));
    $check('balance is 70 after one renewal', in_array('70', array_map('strval', $adm3), true), $adm3);
    xtreampro_Renew($params($sub));
    $adm4 = xtreampro_AdminServicesTabFields($params($sub));
    $check('Renew repeated the same day does not top up twice', $adm4 == $adm3, $adm4);
    // A webhook registered with the sub-resellers' lines delivers events of lines this WHMCS never sold; the owner says whose they are.
    $owner = Capsule::table('mod_xtreampro_lines')->where('service_id', $sub['serviceid'])->value('user_id');
    $check('the sub-reseller service keeps the panel id of its account', is_string($owner) && strlen($owner) >= 32, $owner);
    $check('an event of a line owned by that account is noted on its service, nothing else is kept', xtreampro_applyWebhookEvent(array('type' => 'line.expired', 'data' => array('line_id' => 987654323, 'username' => 'enduser', 'owner_id' => $owner, 'owner_username' => 'x'))) === 'recorded (line of a sub-reseller account)');
    $adm5 = xtreampro_AdminServicesTabFields($params($sub));
    $check('the admin tab of the account shows the last activity', isset($adm5['Last panel activity of the account\'s lines']), $adm5);
    $check('Terminate', ($r = xtreampro_TerminateAccount($params($sub))) === 'success', $r);

    echo "== sub-reseller: price check, group, panel address, deletion\n";
    $count = function () use ($base) { return count(xtreampro_client($base)->subUsers('', 0, 500)); };
    $before = $count();
    $GLOBALS['service'] = array();
    $big = array_merge($sub, array('serviceid' => 3001 + (getmypid() % 100000), 'configoption5' => '99999999'));
    $r = xtreampro_CreateAccount($params($big));
    $check('credits the balance cannot cover are refused before the account is created', $r !== 'success' && stripos($r, 'not enough credits') !== false && strpos($r, 'needs') !== false, $r);
    $check('so no account was created', $count() === $before, array($before, $count()));
    $GLOBALS['service'] = array();
    $badGroup = array_merge($sub, array('serviceid' => 3101 + (getmypid() % 100000), 'configoption9' => '999999'));
    $r = xtreampro_CreateAccount($params($badGroup));
    $check('a group the reseller may not use is refused', $r !== 'success' && stripos($r, 'group') !== false, $r);
    $groupIds = array_values(array_filter(array_keys($groups)));
    $GLOBALS['service'] = array();
    $del = array_merge($sub, array('serviceid' => 3201 + (getmypid() % 100000), 'configoption5' => '0', 'configoption6' => '0', 'configoption7' => 'delete', 'configoption8' => 'https://panel.example.test/', 'configoption9' => (string) $groupIds[0],
        // the panel keeps an email unique, also for accounts that were disabled
        'clientsdetails' => array('email' => 'whmcs-del-' . getmypid() . '@example.test', 'firstname' => 'Cy', 'lastname' => 'Dahl')));
    $r = xtreampro_CreateAccount($params($del));
    $check('CreateAccount in a chosen group', $r === 'success', $r);
    $check('the client area links to the panel sign-in', xtreampro_ClientArea($params($del))['vars']['loginUrl'] === 'https://panel.example.test/login');
    $check('an address that is not http(s) gives no link', xtreampro_ClientArea($params(array_merge($del, array('configoption8' => 'javascript:alert(1)'))))['vars']['loginUrl'] === '');
    $canDelete = !empty(xtreampro_client($base)->pricing()['sub_reseller']['can_delete']);
    $r = xtreampro_TerminateAccount($params($del));
    if ($canDelete) {
        $check('Terminate with "delete" removes the account on the panel', $r === 'success' && $count() === $before, array($r, $before, $count()));
    } else {
        $check('Terminate with "delete" tells that the group may not delete (nothing was disabled instead)', $r !== 'success' && stripos($r, 'not allowed') !== false, $r);
        $check('the account is still there', $count() === $before + 1, array($before, $count()));
        $del['configoption7'] = 'disable';
        $check('and with "disable" it can still be terminated', xtreampro_TerminateAccount($params($del)) === 'success');
    }

    $check('the API key never reached the module log', empty($GLOBALS['leak']), $GLOBALS['leak'] ?? '');
    $pw = false;
    foreach ($GLOBALS["modlog"] as $entry) { if (strpos($entry[1], "Passw0rd-") !== false) { $pw = $entry[0]; break; } }
    $check('no line password in the module log', $pw === false, (string) $pw);
    echo $fail ? "\n$fail FAILED\n" : "\nALL OK\n";
    exit($fail ? 1 : 0);
}
