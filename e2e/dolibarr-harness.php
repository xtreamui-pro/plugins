<?php
// Runs the real platform independent core of the Dolibarr module
// (plugins/dolibarr/xtreampro/lib/core/*) against a panel API. Not shipped.
//
// Dolibarr itself is not started: the triggers, pages and database glue need a
// Dolibarr installation. This harness proves the part that holds all the rules:
// the Reseller API client and the provisioner, with an in-memory stand-in for the
// module's database tables (it "encrypts" passwords the way the glue's store does).
//
//   API_PORT_NUM=18095 API_KEY=... php plugins/e2e/dolibarr-harness.php
//   (API_URL overrides http://127.0.0.1:API_PORT_NUM; CORE_DIR overrides the core folder)

$CORE = rtrim((string) (getenv('CORE_DIR') ?: __DIR__ . '/../dolibarr/xtreampro/lib/core'), '/');
require $CORE . '/XtreamproProvisioner.php';

$API_BASE = getenv('API_URL') ?: ('http://127.0.0.1:' . getenv('API_PORT_NUM'));
$API_KEY = (string) getenv('API_KEY');

// ---- stand-in for the module's tables --------------------------------------
$TABLES = array('line' => array(), 'reseller' => array(), 'credit' => array());
$LOG = array();
$NEXT = 100;

$persist = function ($kind, array $rec) use (&$TABLES, &$NEXT) {
	if (empty($rec['id'])) {
		$rec['id'] = ++$NEXT;
	}
	$stored = $rec;
	if (isset($stored['password']) && $stored['password'] !== '') {
		$stored['password'] = 'dolcrypt:' . strrev(base64_encode($stored['password']));   // the glue uses dolEncrypt()
	}
	$TABLES[$kind][$rec['id']] = $stored;
	return $rec;
};
$logger = function ($msg) use (&$LOG) { $LOG[] = $msg; };

// A new installation id per run: the stand-in tables start their ids at 101 every time.
$INSTANCE = substr(md5((string) microtime(true) . getmypid()), 0, 10);
function newProvisioner($base, $key, $persist, $logger)
{
	global $INSTANCE;
	return new XtreamproProvisioner(new XtreamproApiClient($base, $key), $persist, $logger, $INSTANCE);
}
$P = newProvisioner($API_BASE, $API_KEY, $persist, $logger);

// ---- direct panel access for verification (independent of the module) ------
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
function credits() { return (int) panel('user_info')['data']['credits']; }
function line_of($id) { $r = panel('get_line', array('id' => $id)); return $r['status'] === 'STATUS_SUCCESS' ? $r['data'] : null; }
function sub_of($id)
{
	$r = panel('get_user', array('id' => $id));
	return $r['status'] === 'STATUS_SUCCESS' ? $r['data'] : null;
}

$fail = 0;
$check = function ($label, $ok, $detail = '') use (&$fail) {
	echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' -> ' . (is_string($detail) ? $detail : json_encode($detail))) . "\n";
	if (!$ok) { $fail++; }
};
$secrets = array($API_KEY);
$RUN = getmypid() . random_int(100, 999);
$cleanupLines = array();
$cleanupUsers = array();
$uid = 5000000 + random_int(0, 3000000);   // invoice line ids: unique per run

$packages = panel('packages')['data'];
$pkg = null;
foreach ($packages as $p) { if (!empty($p['is_official'])) { $pkg = $p; break; } }

// ==========================================================================
echo "== client\n";
$thrown = '';
try { new XtreamproApiClient('ftp://example.test', 'k'); } catch (XtreamproApiException $e) { $thrown = $e->getErrorCode(); }
$check('a non-http URL is refused', $thrown === 'CONFIG', $thrown);
$thrown = '';
try { new XtreamproApiClient($API_BASE, ''); } catch (XtreamproApiException $e) { $thrown = $e->getErrorCode(); }
$check('an empty key is refused', $thrown === 'CONFIG', $thrown);
$c = new XtreamproApiClient($API_BASE . '/', $API_KEY);
$check('base URL without trailing slash', $c->baseUrl() === rtrim($API_BASE, '/'));
$info = $c->userInfo();
$check('user_info (test connection)', isset($info['credits']), $info);
$check('packages list', count($c->packages()) >= 1);
$thrown = '';
try { (new XtreamproApiClient($API_BASE, 'xk_wrong_key'))->userInfo(); } catch (XtreamproApiException $e) { $thrown = $e->getMessage(); }
$check('wrong key: readable error', stripos($thrown, 'API key') !== false, $thrown);
$thrown = '';
try { (new XtreamproApiClient('http://127.0.0.1:1', 'xk_wrong_key'))->userInfo(); } catch (XtreamproApiException $e) { $thrown = $e->getMessage(); }
$check('dead port: readable error', stripos($thrown, 'Could not connect') !== false, $thrown);
$check('the key is not in the last request kept for the log', strpos(json_encode($c->lastRequest), $API_KEY) === false);

// ==========================================================================
echo "== line\n";
$line = array('socid' => 7, 'invoice_id' => 70, 'invoice_line_id' => ++$uid, 'unit' => 1, 'package_id' => (int) $pkg['id'], 'trial' => 0, 'delete_on_terminate' => 1);
$before = credits();
$lost = $line;   // the record as it was before the call: what a retry after a crash starts from
$line = $P->provisionLine($line);
$check('create', $line['error'] === '' && $line['status'] === 'active' && $line['panel_line_id'] > 0, $line);
$lineId = $line['panel_line_id'];
$cleanupLines[] = $lineId;
$pl = line_of($lineId);
$check('panel: the line is active with the package', $pl && $pl['status'] === 'active' && (int) $pl['package_id'] === (int) $pkg['id'], $pl);
$check('credentials read back from the panel', $line['username'] === $pl['username'] && $line['password'] === $pl['password'] && $line['password'] !== '');
$secrets[] = $line['password'];
$charged = $before - credits();
$check('the reseller was charged once', $charged > 0, (string) $charged);

$again = $P->provisionLine($line);
$check('create replayed on the same record: no second line, no charge', $again['panel_line_id'] === $lineId && credits() === $before - $charged);
$replay = $P->provisionLine($lost);
$check('create replayed after a lost response (same request id): same line, no charge, password recovered',
	$replay['error'] === '' && $replay['panel_line_id'] === $lineId && $replay['password'] === $line['password'] && credits() === $before - $charged, $replay);

$desc = $P->describeLine($line);
$check('describeLine returns the play links', is_array($desc['links'] ?? null) && !empty($desc['links']['m3u']), $desc['links'] ?? null);

$r = $P->suspendLine($line);
$check('suspend', $r['error'] === '' && $r['status'] === 'suspended' && (line_of($lineId)['status'] ?? '') === 'disabled', $r);
$r = $P->unsuspendLine($r);
$check('unsuspend', $r['error'] === '' && $r['status'] === 'active' && (line_of($lineId)['status'] ?? '') === 'active', $r);
$line = $r;

$e0 = (int) line_of($lineId)['exp_date']; $c0 = credits();
$beforeRenew = $line;
$line = $P->renewLine($line);
$e1 = (int) line_of($lineId)['exp_date']; $c1 = credits();
$check('renew: expiry moved and the reseller charged', $line['error'] === '' && $e1 > $e0 && $c1 < $c0 && $line['renewals'] === 1, "$e0 -> $e1, $c0 -> $c1");
$r = $P->renewLine($beforeRenew);
$check('renew replayed (retry after a lost response): no extension, no second charge', $r['error'] === '' && (int) line_of($lineId)['exp_date'] === $e1 && credits() === $c1, array($r, credits(), $c1));
$line = $P->renewLine($line);
$check('a deliberate second renewal extends again', $line['error'] === '' && (int) line_of($lineId)['exp_date'] > $e1 && credits() === $c1 - ($c0 - $c1) && $line['renewals'] === 2);

$rev = $P->revokeLine($line);
$check('revoke (invoice reversed): disabled, never deleted', $rev['error'] === '' && $rev['revoked'] === 1 && (line_of($lineId)['status'] ?? '') === 'disabled', $rev);
$rev = $P->provisionLine($rev);
$check('invoice paid again: the same line is enabled, nothing is sold twice', $rev['panel_line_id'] === $lineId && $rev['revoked'] === 0 && (line_of($lineId)['status'] ?? '') === 'active', $rev);
$line = $rev;

$line = $P->terminateLine($line);
$check('terminate (delete)', $line['error'] === '' && $line['status'] === 'terminated' && $line['panel_line_id'] === 0 && $line['generation'] === 1, $line);
$check('panel: the line is deleted', (panel('get_line', array('id' => $lineId))['error'] ?? '') === 'RESOURCE_NOT_FOUND');
$check('the stored password is cleared', $line['password'] === '');
$check('terminate again is still fine', $P->terminateLine($line)['error'] === '');

$new = $P->provisionLine($line);
$cleanupLines[] = $new['panel_line_id'];
$check('create after terminate sells a NEW line', $new['error'] === '' && $new['panel_line_id'] > 0 && $new['panel_line_id'] !== $lineId && (line_of($new['panel_line_id'])['status'] ?? '') === 'active', $new);
$secrets[] = $new['password'];

echo "== line, only disable on terminate\n";
$l2 = $P->provisionLine(array('socid' => 7, 'invoice_id' => 70, 'invoice_line_id' => ++$uid, 'unit' => 1, 'package_id' => (int) $pkg['id'], 'delete_on_terminate' => 0));
$cleanupLines[] = $l2['panel_line_id'];
$secrets[] = $l2['password'];
$t = $P->terminateLine($l2);
$check('terminate only disables the line', $t['error'] === '' && (line_of($l2['panel_line_id'])['status'] ?? '') === 'disabled', $t);

echo "== 1.1.0: sells, pricing, connector name, default cancellation, spent request id\n";
$boxPkg = null;
foreach ($packages as $p) { if (isset($p['sells']) && !in_array('line', $p['sells'], true)) { $boxPkg = $p; break; } }
$check('the panel marks a box-only package with sells without "line"', $boxPkg !== null, $packages);
$check('a panel that sends no sells counts as selling lines', XtreamproApiClient::sellsLine(array('id' => 1)) && !XtreamproApiClient::sellsLine(array('sells' => array('mag'))));
$cBox = credits();
$boxed = $P->provisionLine(array('socid' => 7, 'invoice_id' => 70, 'invoice_line_id' => ++$uid, 'unit' => 1, 'package_id' => (int) $boxPkg['id']));
$check('a box-only package is refused before anything is sold, with a readable reason', $boxed['status'] === 'failed' && stripos($boxed['error'], 'boxes only') !== false && $boxed['panel_line_id'] === 0 && credits() === $cBox, $boxed);
$names = array();
foreach ((panel('api_logs', array('limit' => 50))['data'] ?? array()) as $row) { $names[] = $row['connector'] ?? ''; }
$check('the panel call log names the connector', in_array('dolibarr/1.1.0', $names, true), array_unique($names));
$dflt = $P->provisionLine(array('socid' => 7, 'invoice_id' => 70, 'invoice_line_id' => ++$uid, 'unit' => 1, 'package_id' => (int) $pkg['id']));
$cleanupLines[] = $dflt['panel_line_id'];
$secrets[] = $dflt['password'];
$P->terminateLine($dflt);
$check('a line record without a cancellation setting is only disabled (disable is the default)', (line_of($dflt['panel_line_id'])['status'] ?? '') === 'disabled');
$spentRec = array('socid' => 7, 'invoice_id' => 70, 'invoice_line_id' => ++$uid, 'unit' => 1, 'package_id' => (int) $pkg['id']);
$sp = $P->provisionLine($spentRec);
panel('delete_line', array('id' => $sp['panel_line_id']), true);
$spAgain = $P->provisionLine($spentRec);
$check('selling again under the request id of a line deleted on the panel gives a readable message (REQUEST_ID_SPENT)', $spAgain['status'] === 'failed' && stripos($spAgain['error'], 'already made') !== false && strpos($spAgain['error'], 'REQUEST_ID') === false, $spAgain);

echo "== line, errors\n";
$bad = $P->provisionLine(array('socid' => 7, 'invoice_id' => 70, 'invoice_line_id' => ++$uid, 'unit' => 1, 'package_id' => 999999));
$check('an unknown package fails readably and nothing is kept', $bad['error'] !== '' && stripos($bad['error'], 'package') !== false && $bad['panel_line_id'] === 0 && $bad['status'] === 'failed', $bad);
$none = $P->provisionLine(array('socid' => 7, 'invoice_id' => 70, 'invoice_line_id' => ++$uid, 'unit' => 1, 'package_id' => 0));
$check('no package selected: readable error', stripos($none['error'], 'No package') !== false, $none);
$wrong = newProvisioner($API_BASE, 'xk_wrong_key', $persist, $logger);
$w = $wrong->provisionLine(array('socid' => 7, 'invoice_id' => 70, 'invoice_line_id' => ++$uid, 'unit' => 1, 'package_id' => (int) $pkg['id']));
$check('a wrong key fails readably', stripos($w['error'], 'API key') !== false && $w['status'] === 'failed', $w);
$check('suspend of a line that was never created fails readably', stripos($P->suspendLine(array('socid' => 7))['error'], 'not been created') !== false);
$check('renew of a line that was never created fails readably', stripos($P->renewLine(array('socid' => 7))['error'], 'not been created') !== false);
$retry = $P->provisionLine(array_merge($bad, array('package_id' => (int) $pkg['id'])));
$cleanupLines[] = $retry['panel_line_id'];
$secrets[] = $retry['password'];
$check('retry of a failed line after fixing the cause works', $retry['error'] === '' && $retry['status'] === 'active' && $retry['panel_line_id'] > 0, $retry);

// ==========================================================================
echo "== sub-reseller\n";
$acct = array('socid' => 9000000 + random_int(0, 99999), 'invoice_id' => 71);
$cust = array('email' => "dolibarr-$RUN-sub@example.test", 'name' => 'Bo Carl');
$lostAcct = null;
$a = $P->provisionReseller($acct, $cust);
$check('create (generated credentials)', $a['error'] === '' && $a['status'] === 'active' && $a['panel_user_id'] !== '', $a);
$check('generated username and password fit the panel rules', preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $a['username']) === 1 && strlen($a['password']) >= 8, array($a['username'], strlen($a['password'])));
$secrets[] = $a['password'];
$cleanupUsers[] = $a['panel_user_id'];
$pu = sub_of($a['panel_user_id']);
$check('panel: account exists with the customer email, active, 0 credits', $pu && $pu['email'] === $cust['email'] && $pu['status'] === 'active' && (int) $pu['credits'] === 0, $pu);
$check('the credentials were stored before the panel call (and are encrypted at rest)',
	strpos(json_encode($TABLES['reseller']), $a['password']) === false && strpos(json_encode($TABLES['reseller']), 'dolcrypt:') !== false);

$same = $P->provisionReseller($a, $cust);
$check('create replayed on the same record: nothing changes', $same['panel_user_id'] === $a['panel_user_id']);
$lostAcct = array_merge($acct, array('username' => $a['username'], 'password' => $a['password'], 'email' => $a['email'], 'id' => $a['id']));
$replay = $P->provisionReseller($lostAcct, $cust);
$check('create replayed after a lost response (same stored credentials): the same account', $replay['error'] === '' && $replay['panel_user_id'] === $a['panel_user_id'], $replay);

$credit = array('id' => 0, 'socid' => $acct['socid'], 'invoice_id' => 71, 'invoice_line_id' => ++$uid, 'credits' => 50);
$credit = $persist('credit', $credit);
$lostCredit = $credit;
$cr = $P->topUp($credit, $a);
$check('top up 50 credits', $cr['error'] === '' && $cr['credited'] === 50 && (int) sub_of($a['panel_user_id'])['credits'] === 50, $cr);
$r2 = $P->topUp($cr, $a);
$check('top up replayed on the same record: nothing more', $r2['credited'] === 50 && (int) sub_of($a['panel_user_id'])['credits'] === 50);
$r3 = $P->topUp($lostCredit, $a);
$check('top up replayed after a lost response (same request id): still 50', $r3['error'] === '' && (int) sub_of($a['panel_user_id'])['credits'] === 50, array($r3, sub_of($a['panel_user_id'])));
$credit = $cr;

$s = $P->suspendReseller($a);
$check('suspend', $s['error'] === '' && $s['status'] === 'suspended' && (sub_of($a['panel_user_id'])['status'] ?? '') === 'disabled', $s);
$a = $P->unsuspendReseller($s);
$check('unsuspend', $a['error'] === '' && $a['status'] === 'active' && (sub_of($a['panel_user_id'])['status'] ?? '') === 'active', $a);

$d = $P->describeReseller($a);
$check('describeReseller shows the balance', (int) ($d['credits'] ?? -1) === 50, $d);

$credit = $P->revokeCredits($credit, $a);
$check('credit take-back', $credit['error'] === '' && $credit['revoked'] === 50 && (int) sub_of($a['panel_user_id'])['credits'] === 0, $credit);
$credit = $P->revokeCredits($credit, $a);
$check('credit take-back repeated: nothing more', $credit['error'] === '' && (int) sub_of($a['panel_user_id'])['credits'] === 0);
$credit = $P->topUp($credit, $a);
$check('invoice paid again: the credits are given again (new request)', $credit['error'] === '' && $credit['credited'] === 100 && (int) sub_of($a['panel_user_id'])['credits'] === 50, $credit);

$a = $P->revokeReseller($a);
$check('revoke account: disabled', $a['error'] === '' && $a['revoked'] === 1 && (sub_of($a['panel_user_id'])['status'] ?? '') === 'disabled', $a);
$a = $P->provisionReseller($a, $cust);
$check('invoice paid again: the same account is enabled', $a['error'] === '' && $a['revoked'] === 0 && (sub_of($a['panel_user_id'])['status'] ?? '') === 'active');

$oldId = $a['panel_user_id'];
$a = $P->terminateReseller($a);
$check('terminate disables the account (never deletes it) and forgets it', $a['error'] === '' && $a['status'] === 'terminated' && $a['panel_user_id'] === '' && $a['generation'] === 1 && $a['password'] === ''
	&& (sub_of($oldId)['status'] ?? '') === 'disabled', $a);
$check('terminate again is still fine', $P->terminateReseller($a)['error'] === '');
$a2 = $P->provisionReseller($a, $cust);
$cleanupUsers[] = $a2['panel_user_id'];
$secrets[] = $a2['password'];
$check('create after terminate makes a NEW account (new username, tagged email)',
	$a2['error'] === '' && $a2['panel_user_id'] !== '' && $a2['panel_user_id'] !== $oldId && $a2['username'] !== '' && $a2['email'] === "dolibarr-$RUN-sub+g1@example.test", $a2);

echo "== sub-reseller, errors\n";
$noMail = $P->provisionReseller(array('socid' => 1), array('email' => ''));
$check('no email address: readable error', stripos($noMail['error'], 'email') !== false && $noMail['status'] === 'failed', $noMail);
$orphan = $P->topUp(array('id' => 999, 'invoice_line_id' => 1, 'credits' => 5), array('socid' => 1));
$check('credits for an account that does not exist: readable error', stripos($orphan['error'], 'does not exist') !== false, $orphan);
$huge = $persist('credit', array('socid' => 1, 'invoice_id' => 71, 'invoice_line_id' => ++$uid, 'credits' => 999999999));
$h = $P->topUp($huge, $a2);
$check('more credits than the reseller owns: readable error, nothing recorded as given', stripos($h['error'], 'credits') !== false && $h['credited'] === 0, $h);
$wa = $wrong->provisionReseller(array('socid' => 2), array('email' => "dolibarr-$RUN-w@example.test"));
$check('a wrong key fails readably', stripos($wa['error'], 'API key') !== false, $wa);

// ==========================================================================
echo "== secrets\n";
$all = implode("\n", $LOG);
$check('the leak check had real secrets to look for', count(array_unique(array_filter($secrets))) >= 5, (string) count($secrets));
$check('the core logged its calls', count($LOG) > 25, (string) count($LOG));
$leak = '';
foreach (array_unique(array_filter($secrets)) as $s) {
	if (strpos($all, $s) !== false) { $leak = $s === $API_KEY ? 'API key' : 'a password'; break; }
}
$check('neither the API key nor any password reached the log', $leak === '', $leak);
$check('no clear password= in any logged link', !preg_match('/password=(?!\*)[^&"\\\\]/', $all));
$errs = '';
foreach ($TABLES as $rows) { foreach ($rows as $row) { $errs .= ($row['error'] ?? '') . "\n"; } }
$leak = '';
foreach (array_unique(array_filter($secrets)) as $s) { if (strpos($errs, $s) !== false) { $leak = 'an error text'; } }
$check('no secret in any stored error text', $leak === '', $leak);
$dump = json_encode($TABLES);
$stored = '';
foreach (array_unique(array_filter($secrets)) as $s) { if ($s !== $API_KEY && strpos($dump, $s) !== false) { $stored = 'a password'; } }
$check('no password is stored in clear', $stored === '', $stored);
$check('the API key is not stored on any record', strpos($dump, $API_KEY) === false);
$check('version 1.1.0 in the module descriptor and in the API client (X-Connector)', is_file($CORE . '/../../core/modules/modXtreampro.class.php') && strpos(file_get_contents($CORE . '/../../core/modules/modXtreampro.class.php'), '1.1.0') !== false && XtreamproApiClient::VERSION === '1.1.0');

// ---- clean up what is left on the shared panel -----------------------------
foreach (array_unique($cleanupLines) as $id) { if ($id) { panel('delete_line', array('id' => $id), true); } }
foreach (array_unique(array_filter($cleanupUsers)) as $id) { panel('disable_user', array('id' => $id), true); }

echo $fail ? "\n$fail FAILED\n" : "\nALL OK\n";
exit($fail ? 1 : 0);
