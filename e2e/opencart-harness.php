<?php
// End-to-end check of the OpenCart connector, run against a real panel API.
// Not shipped.
//
// OpenCart itself is not started. The harness loads the REAL files of
// plugins/opencart/system/library (the Reseller API client, the Provisioner
// and the order glue: Store, OrderService, CredentialsView) and the real
// install model, and drives them in two parts:
//
//   1. the core (Client + Provisioner) for a line and a sub-reseller account:
//      create, replay, revoke ("suspend"), paid again ("unsuspend"), top-up and
//      its repeat, refund, create again, wrong key, unknown package;
//   2. the glue (OrderService + Store) on a real MariaDB with OpenCart's table
//      layout: order status changes, quantities, guest orders, variants,
//      refunds, the order lock, the customer views, and the install model's
//      CREATE TABLE statements and event list.
//
// Part 2 needs a database (OC_DB_PORT); plugins/e2e/opencart-run.sh starts a
// throwaway MariaDB for it. Part 1 alone: leave OC_DB_PORT unset.
//
//   API_PORT_NUM=18095 API_KEY=... [OC_DB_PORT=55462] php plugins/e2e/opencart-harness.php
//   (API_URL overrides http://127.0.0.1:API_PORT_NUM)
//
// Everything created on the panel is cleaned up at the end (lines deleted,
// sub-resellers disabled: the API has no delete for them that this connector uses).

namespace Opencart\System\Engine {
	// Stand-in for the part of OpenCart's base model the install model uses.
	class Model {
		public static array $registry = [];

		public function __get(string $key) {
			return self::$registry[$key];
		}
	}
}

namespace {
	use Opencart\System\Library\Extension\Xtreampro\ApiException;
	use Opencart\System\Library\Extension\Xtreampro\Client;
	use Opencart\System\Library\Extension\Xtreampro\CredentialsView;
	use Opencart\System\Library\Extension\Xtreampro\OrderService;
	use Opencart\System\Library\Extension\Xtreampro\Provisioner;
	use Opencart\System\Library\Extension\Xtreampro\Store;

	define('DB_PREFIX', 'oc_');

	$ROOT = dirname(__DIR__) . '/opencart';
	foreach (['api_exception', 'client', 'provisioner', 'store', 'credentials_view', 'order_service'] as $file) {
		require $ROOT . '/system/library/' . $file . '.php';
	}

	$API_BASE = rtrim(getenv('API_URL') ?: ('http://127.0.0.1:' . getenv('API_PORT_NUM')), '/');
	$API_KEY = (string)getenv('API_KEY');
	$RUN = getmypid() . random_int(100, 999);

	$fail = 0;
	$check = function (string $label, $ok, $detail = '') use (&$fail) {
		echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' -> ' . (is_string($detail) ? $detail : json_encode($detail))) . "\n";

		if (!$ok) {
			$fail++;
		}
	};

	$logs = [];
	$log = function (string $message) use (&$logs): void {
		$logs[] = $message;
	};

	// ---- panel access for verification (independent of the connector) ----------------------------

	function panel(string $action, array $query = [], bool $post = false): array {
		global $API_BASE, $API_KEY;

		$url = $API_BASE . '/reseller/v1';
		$query = array_merge(['action' => $action], $query);
		$curl = curl_init();

		if ($post) {
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($query));
		} else {
			$url .= '?' . http_build_query($query);
		}

		curl_setopt_array($curl, [CURLOPT_URL => $url, CURLOPT_HTTPHEADER => ['X-API-Key: ' . $API_KEY], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);

		$body = curl_exec($curl);

		curl_close($curl);

		$json = json_decode((string)$body, true);

		return is_array($json) ? $json : ['status' => 'STATUS_FAILURE', 'error' => 'BAD_RESPONSE'];
	}

	function credits(): int {
		return (int)panel('user_info')['data']['credits'];
	}

	function line_of($id): ?array {
		$r = panel('get_line', ['id' => $id]);

		return $r['status'] === 'STATUS_SUCCESS' ? $r['data'] : null;
	}

	function sub_by_name(string $username): ?array {
		$r = panel('get_users', ['search' => $username, 'start' => 0, 'limit' => 50]);

		foreach ($r['data'] ?? [] as $user) {
			if (($user['username'] ?? '') === $username) {
				return $user;
			}
		}

		return null;
	}

	function sub_by_email(string $email): ?array {
		foreach (panel('get_users', ['search' => $email, 'start' => 0, 'limit' => 50])['data'] ?? [] as $u) {
			if (($u['email'] ?? '') === $email) {
				return $u;
			}
		}

		return null;
	}

	function sub_by_id(string $id): ?array {
		$r = panel('get_user', ['id' => $id]);

		return $r['status'] === 'STATUS_SUCCESS' ? $r['data'] : null;
	}

	$secrets = [$API_KEY];
	$cleanupLines = [];
	$cleanupUsers = [];

	$packages = panel('packages')['data'] ?? [];
	$pkg = null;

	foreach ($packages as $p) {
		if (!empty($p['is_official'])) {
			$pkg = $p;
			break;
		}
	}

	if (!$pkg) {
		echo "no official package on the panel\n";
		exit(1);
	}

	$client = new Client($API_BASE, $API_KEY, $log);
	$prov = new Provisioner($client, $log, 'oc' . $RUN);

	$noSave = function (array $unit): void {};
	$lineCfg = ['package_id' => (int)$pkg['id'], 'trial' => false];

	// ============================================================================================
	echo "== files\n";
	$entry = file_get_contents($ROOT . '/admin/controller/module/xtreampro.php');
	$check('version 1.1.0 in the main entry file and in the API client (X-Connector)', strpos($entry, '1.1.0') !== false && Client::VERSION === '1.1.0');
	$install = json_decode(file_get_contents($ROOT . '/install.json'), true);
	$check('install.json has name, version, author, link (OpenCart refuses the zip otherwise)', is_array($install) && !empty($install['name']) && !empty($install['version']) && !empty($install['author']) && !empty($install['link']), $install);
	$check('install.json version is 1.1.0', ($install['version'] ?? '') === '1.1.0');

	// ============================================================================================
	echo "== settings page helpers\n";
	$info = $prov->testConnection();
	$check('test connection answers name and credits', $info['username'] !== '' && $info['credits'] > 0, $info);
	$options = $prov->packageOptions();
	$check('package list for the product form', count($options) >= 1 && isset($options[0]['id'], $options[0]['label']) && strpos(implode('|', array_column($options, 'label')), $pkg['name']) !== false, $options);

	$bad = new Provisioner(new Client($API_BASE, 'xk_wrong_key_' . $RUN, $log), $log, 'oc' . $RUN);
	$thrown = '';
	try {
		$bad->testConnection();
	} catch (ApiException $e) {
		$thrown = $e->getMessage();
	}
	$check('test connection with a wrong key fails readably', stripos($thrown, 'API key') !== false && strpos($thrown, 'xk_wrong_key') === false, $thrown);
	$thrown = '';
	try {
		(new Provisioner(new Client('http://127.0.0.1:1', 'xk_wrong_key', $log), $log))->testConnection();
	} catch (ApiException $e) {
		$thrown = $e->getMessage();
	}
	$check('test connection to a dead port fails readably', stripos($thrown, 'Could not connect') !== false, $thrown);
	$thrown = '';
	try {
		new Client('ftp://example.test', 'k');
	} catch (ApiException $e) {
		$thrown = $e->getErrorCode();
	}
	$check('a non-http URL is refused', $thrown === 'CONFIG', $thrown);

	// ============================================================================================
	echo "== 1.1.0: sells, pricing, connector name, spent request id\n";
	$boxPkg = null;

	foreach ($packages as $p) {
		if (isset($p['sells']) && !in_array('line', $p['sells'], true)) {
			$boxPkg = $p;
			break;
		}
	}

	$check('the panel marks a box-only package with sells without "line"', $boxPkg !== null, $packages);
	$check('a panel that sends no sells counts as selling lines', Client::sellsLine(['id' => 1]) && !Client::sellsLine(['sells' => ['mag']]));
	$opts = array_column($prov->packageOptions(), 'id');
	$check('the product form leaves box-only packages out and keeps the others', $boxPkg !== null && !in_array((int)$boxPkg['id'], $opts, true) && in_array((int)$pkg['id'], $opts, true), $opts);
	$balBox = credits();
	$f = $prov->provisionLine(Provisioner::newUnit('line', 9000090, 9100090, 1, 77), ['package_id' => (int)$boxPkg['id'], 'trial' => false]);
	$check('a box-only package is refused before anything is sold, with a readable reason', $f['status'] === 'failed' && $f['panel_id'] === '' && stripos($f['error'], 'boxes only') !== false && credits() === $balBox, $f);
	$names = array_column(panel('api_logs', ['limit' => 50])['data'] ?? [], 'connector');
	$check('the panel call log names the connector', in_array('opencart/1.1.0', $names, true), array_unique($names));
	$sp1 = $prov->provisionLine(Provisioner::newUnit('line', 9000091, 9100091, 1, 77), $lineCfg);
	panel('delete_line', ['id' => (int)$sp1['panel_id']], true);
	$sp2 = $prov->provisionLine(Provisioner::newUnit('line', 9000091, 9100091, 1, 77), $lineCfg);
	$check('selling again under the request id of a line deleted on the panel gives a readable message (REQUEST_ID_SPENT)', $sp2['status'] === 'failed' && stripos($sp2['error'], 'already made') !== false && strpos($sp2['error'], 'REQUEST_ID') === false, $sp2);

	// ============================================================================================
	echo "== IPTV line (core)\n";
	$u = Provisioner::newUnit('line', 9000001, 9100001, 1, 77);
	$before = credits();
	$u = $prov->provisionLine($u, $lineCfg);
	$lineId = (int)$u['panel_id'];
	$cleanupLines[] = $lineId;
	$pl = line_of($lineId);
	$check('create: unit done with a panel id', $u['status'] === 'done' && $lineId > 0 && $u['error'] === '', $u);
	$check('panel: line exists, active, right package', $pl && $pl['status'] === 'active' && (int)$pl['package_id'] === (int)$pkg['id'], $pl);
	$check('credentials kept are the ones the panel holds', $pl && $u['username'] === $pl['username'] && $u['password'] === $pl['password'] && $u['password'] !== '', $u);
	$secrets[] = $u['password'];
	$charged = $before - credits();
	$check('creating charged the reseller once', $charged > 0, $charged);

	$again = $prov->provisionLine($u, $lineCfg);
	$check('replay of a done unit changes nothing and charges nothing', $again === $u && credits() === $before - $charged);
	$lost = $prov->provisionLine(Provisioner::newUnit('line', 9000001, 9100001, 1, 77), $lineCfg);
	$check('replay after the shop lost its state sells the same line (same request id)', $lost['panel_id'] === $u['panel_id'] && $lost['username'] === $u['username'] && credits() === $before - $charged, [$lost['panel_id'], $u['panel_id']]);

	$d = $prov->lineDetails($u);
	$check('live details: status, expiry, connections and the panel links', $d['live'] && $d['status'] === 'active' && $d['expires'] > time() && $d['max_connections'] > 0
		&& isset($d['links']['m3u'], $d['links']['web_player'], $d['links']['server']) && strpos($d['links']['m3u'], $u['username']) !== false, $d);

	$rv = $prov->revokeLine($u);
	$check('refund (revoke): panel disables the line, unit revoked', $rv['status'] === 'revoked' && $rv['error'] === '' && (line_of($lineId)['status'] ?? '') === 'disabled', [$rv['status'], line_of($lineId)['status'] ?? null]);
	$check('revoke twice is quiet', $prov->revokeLine($rv) === $rv);
	$back = $prov->provisionLine($rv, $lineCfg);
	$check('paid again: the same line is switched back on, nothing is charged', $back['status'] === 'done' && $back['panel_id'] === $u['panel_id'] && (line_of($lineId)['status'] ?? '') === 'active' && credits() === $before - $charged, [$back['status'], line_of($lineId)['status'] ?? null]);

	// The reseller deleted the line in the panel; the order is refunded and paid again.
	$rv = $prov->revokeLine($back);
	panel('delete_line', ['id' => $lineId], true);
	$check('the line is deleted in the panel (setup)', line_of($lineId) === null);
	$rv2 = $prov->revokeLine($rv);
	$check('revoke of a revoked unit whose line is gone stays quiet', $rv2['status'] === 'revoked' && $rv2['error'] === '', $rv2);
	$new = $prov->provisionLine($rv, $lineCfg);
	$newId = (int)$new['panel_id'];
	$cleanupLines[] = $newId;
	$check('paid again after the line was deleted sells a NEW line (generation 1, new request id)', $new['status'] === 'done' && $newId > 0 && $newId !== $lineId && $new['generation'] === 1 && (line_of($newId)['status'] ?? '') === 'active', $new);
	$secrets[] = $new['password'];

	// Another shop with the same reseller key and the same order ids must not replay this shop's order.
	$other = (new Provisioner($client, $log, 'oc' . $RUN . 'x'))->provisionLine(Provisioner::newUnit('line', 9000001, 9100001, 1, 77), $lineCfg);
	$cleanupLines[] = (int)$other['panel_id'];
	$secrets[] = $other['password'];
	$check('another shop (other request prefix) with the same order ids gets its own line', $other['status'] === 'done' && $other['panel_id'] !== $u['panel_id'] && $other['panel_id'] !== $new['panel_id'], [$other['panel_id'], $u['panel_id']]);

	// Two units of one order item are two lines.
	$a = $prov->provisionLine(Provisioner::newUnit('line', 9000002, 9100002, 1, 77), $lineCfg);
	$b = $prov->provisionLine(Provisioner::newUnit('line', 9000002, 9100002, 2, 77), $lineCfg);
	$cleanupLines[] = (int)$a['panel_id'];
	$cleanupLines[] = (int)$b['panel_id'];
	$secrets[] = $a['password'];
	$secrets[] = $b['password'];
	$check('quantity 2: two different lines', $a['status'] === 'done' && $b['status'] === 'done' && $a['panel_id'] !== $b['panel_id'], [$a['panel_id'], $b['panel_id']]);
	$c = credits();
	$prov->provisionLine($b, $lineCfg);
	$check('a repeated unit does not create a third', credits() === $c);

	// Errors.
	$f = $bad->provisionLine(Provisioner::newUnit('line', 9000003, 9100003, 1, 77), $lineCfg);
	$check('wrong key: failed, readable, nothing stored', $f['status'] === 'failed' && $f['panel_id'] === '' && stripos($f['error'], 'API key') !== false && strpos($f['error'], 'xk_wrong_key') === false, $f);
	$f = $prov->provisionLine(Provisioner::newUnit('line', 9000004, 9100004, 1, 77), ['package_id' => 999999, 'trial' => false]);
	$check('unknown package: failed, readable, nothing created', $f['status'] === 'failed' && $f['panel_id'] === '' && stripos($f['error'], 'package') !== false, $f);
	$f = $prov->provisionLine(Provisioner::newUnit('line', 9000005, 9100005, 1, 77), ['package_id' => 0, 'trial' => false]);
	$check('no package selected: failed, readable', $f['status'] === 'failed' && stripos($f['error'], 'package') !== false, $f);
	$retry = $prov->provisionLine($f, $lineCfg);
	$cleanupLines[] = (int)$retry['panel_id'];
	$secrets[] = $retry['password'];
	$check('a failed unit is retried by the next provision', $retry['status'] === 'done' && $retry['error'] === '' && (int)$retry['panel_id'] > 0, $retry);
	$d = $bad->lineDetails($a);
	$check('live details with a wrong key: not live, readable error, stored credentials still valid', !$d['live'] && stripos($d['error'], 'API key') !== false, $d);

	// ============================================================================================
	echo "== sub-reseller account (core)\n";
	$customer = ['username_seed' => 'Ann Berg', 'email' => "oc-$RUN-a@example.test", 'fullname' => 'Ann Berg'];
	$saved = [];
	$save = function (array $unit) use (&$saved): void {
		$saved[] = $unit;
	};

	$s1 = Provisioner::newUnit('reseller', 9000010, 9100010, 1, 78);
	$before = credits();
	$s1 = $prov->provisionReseller($s1, ['credits' => 50], [], $customer, $save);
	$cleanupUsers[] = $s1['panel_id'];
	$secrets[] = $s1['password'];
	$pu = sub_by_id($s1['panel_id']);
	$check('create: unit done, account created by this order', $s1['status'] === 'done' && $s1['created'] === 1 && $s1['credits'] === 50 && $s1['error'] === '', $s1);
	$check('generated credentials are valid for the panel', preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $s1['username']) === 1 && strlen($s1['password']) >= 8 && strpos($s1['username'], 'AnnBerg') === 0, [$s1['username'], strlen($s1['password'])]);
	$check('panel: active account with the customer email and the starting credits', $pu && $pu['status'] === 'active' && $pu['username'] === $s1['username'] && $pu['email'] === $customer['email'] && (int)$pu['credits'] === 50, $pu);
	$check('credentials were stored before the panel was called (a retry sends the same ones)', count($saved) >= 1 && $saved[0]['panel_id'] === '' && $saved[0]['username'] === $s1['username'] && $saved[0]['password'] === $s1['password'], $saved[0] ?? null);
	$spent = $before - credits();
	$check('creating the account was charged to the reseller (price + the 50 credits)', $spent >= 50, $spent);

	$c = credits();
	$r = $prov->provisionReseller($s1, ['credits' => 50], [], $customer, $save);
	$check('replay of a done unit changes nothing', $r === $s1 && credits() === $c);

	// The shop crashes after the account exists on the panel but before it stored the result.
	$crash = Provisioner::newUnit('reseller', 9000011, 9100011, 1, 79);
	$customer2 = ['username_seed' => 'Bo Carl', 'email' => "oc-$RUN-b@example.test", 'fullname' => 'Bo Carl'];
	$partial = null;
	$n = 0;
	try {
		$prov->provisionReseller($crash, ['credits' => 30], [], $customer2, function (array $unit) use (&$partial, &$n): void {
			$n++;
			if ($n === 1) {
				$partial = $unit;
			} else {
				throw new \RuntimeException('simulated crash after the account was created');
			}
		});
	} catch (\RuntimeException $e) {
		// expected
	}
	$check('crash simulated: the first save held the credentials, the account exists on the panel', $partial && $partial['panel_id'] === '' && $partial['username'] !== '' && sub_by_name($partial['username']) !== null);
	$secrets[] = $partial['password'];
	$cleanupUsers[] = (sub_by_name($partial['username'])['id'] ?? '');
	$c = credits();
	$done = $prov->provisionReseller($partial, ['credits' => 30], [], $customer2, $noSave);
	$check('the retry finds the same account (same request id), makes no second one and hands over the credits once',
		$done['status'] === 'done' && $done['username'] === $partial['username'] && $done['password'] === $partial['password'] && (int)sub_by_id($done['panel_id'])['credits'] === 30, $done);
	$check('and the retry charged only the credits, not a second account price', $c - credits() === 30, $c - credits());

	// A second order of the same customer tops up the same account.
	$account = ['panel_id' => $s1['panel_id'], 'username' => $s1['username']];
	$s2 = Provisioner::newUnit('reseller', 9000012, 9100012, 1, 78);
	$s2 = $prov->provisionReseller($s2, ['credits' => 20], $account, $customer, $save);
	$check('second order: same account topped up, nothing created', $s2['status'] === 'done' && $s2['created'] === 0 && $s2['panel_id'] === $s1['panel_id'] && $s2['password'] === '' && (int)sub_by_id($s1['panel_id'])['credits'] === 70, [$s2, sub_by_id($s1['panel_id'])['credits']]);
	$r = $prov->provisionReseller($s2, ['credits' => 20], $account, $customer, $save);
	$replayFresh = $prov->provisionReseller(Provisioner::newUnit('reseller', 9000012, 9100012, 1, 78), ['credits' => 20], $account, $customer, $save);
	$check('repeating the top-up does not top up twice (also with the shop state lost: same request id)', $r === $s2 && $replayFresh['status'] === 'done' && (int)sub_by_id($s1['panel_id'])['credits'] === 70, sub_by_id($s1['panel_id'])['credits']);

	$rv2 = $prov->revokeReseller($s2);
	$check('refund of the second order: credits taken back, account stays enabled (this order did not create it)', $rv2['status'] === 'revoked' && $rv2['credits'] === 0 && $rv2['generation'] === 1 && (int)sub_by_id($s1['panel_id'])['credits'] === 50 && sub_by_id($s1['panel_id'])['status'] === 'active', [$rv2, sub_by_id($s1['panel_id'])]);
	$check('revoke twice is quiet', $prov->revokeReseller($rv2) === $rv2);
	$back2 = $prov->provisionReseller($rv2, ['credits' => 20], $account, $customer, $save);
	$check('paid again: credits handed over again under a new request id', $back2['status'] === 'done' && $back2['credits'] === 20 && (int)sub_by_id($s1['panel_id'])['credits'] === 70, sub_by_id($s1['panel_id'])['credits']);

	$rv1 = $prov->revokeReseller($s1);
	$check('refund of the first order: credits taken back and the account disabled', $rv1['status'] === 'revoked' && $rv1['credits'] === 0 && $rv1['error'] === '' && sub_by_id($s1['panel_id'])['status'] === 'disabled' && (int)sub_by_id($s1['panel_id'])['credits'] === 20, sub_by_id($s1['panel_id']));
	$s3 = $prov->provisionReseller(Provisioner::newUnit('reseller', 9000013, 9100013, 1, 78), ['credits' => 5], $account, $customer, $save);
	$check('a new order for a disabled account is refused readably, no credits given', $s3['status'] === 'failed' && stripos($s3['error'], 'disabled') !== false && (int)sub_by_id($s1['panel_id'])['credits'] === 20, [$s3['error'], sub_by_id($s1['panel_id'])['credits']]);
	$back1 = $prov->provisionReseller($rv1, ['credits' => 50], [], $customer, $save);
	$check('paid again: the account is enabled and gets its credits again', $back1['status'] === 'done' && sub_by_id($s1['panel_id'])['status'] === 'active' && (int)sub_by_id($s1['panel_id'])['credits'] === 70, [$back1, sub_by_id($s1['panel_id'])]);

	$d = $prov->accountDetails($s1['panel_id']);
	$check('account details: username, status, balance', $d['live'] && $d['username'] === $s1['username'] && $d['status'] === 'active' && $d['credits'] === 70, $d);

	// Credits that are already spent cannot be taken back.
	$sp = Provisioner::newUnit('reseller', 9000014, 9100014, 1, 80);
	$sp = $prov->provisionReseller($sp, ['credits' => 10], [], ['username_seed' => 'Cy Dahl', 'email' => "oc-$RUN-c@example.test", 'fullname' => 'Cy Dahl'], $save);
	$cleanupUsers[] = $sp['panel_id'];
	$secrets[] = $sp['password'];
	$sp['credits'] = 500; // the shop believes it gave 500; the account holds 10
	$spr = $prov->revokeReseller($sp);
	$check('credits that cannot be taken back: reported readably, account still disabled, unit revoked', $spr['status'] === 'revoked' && stripos($spr['error'], 'Could not take the credits back') !== false && sub_by_id($sp['panel_id'])['status'] === 'disabled', [$spr['error'], sub_by_id($sp['panel_id'])['status']]);

	// Errors.
	$f = $prov->provisionReseller(Provisioner::newUnit('reseller', 9000015, 9100015, 1, 0), ['credits' => 5], [], $customer, $save);
	$check('guest order: refused readably, nothing created', $f['status'] === 'failed' && stripos($f['error'], 'guest') !== false && $f['panel_id'] === '', $f);
	$f = $prov->provisionReseller(Provisioner::newUnit('reseller', 9000016, 9100016, 1, 78), ['credits' => -1], [], $customer, $save);
	$check('invalid credits: refused before anything is created', $f['status'] === 'failed' && $f['panel_id'] === '' && stripos($f['error'], 'whole number') !== false, $f);
	$f = $bad->provisionReseller(Provisioner::newUnit('reseller', 9000017, 9100017, 1, 78), ['credits' => 5], [], $customer, $save);
	$check('wrong key: failed, readable', $f['status'] === 'failed' && stripos($f['error'], 'API key') !== false, $f);
	$secrets[] = $f['password'];
	$big = $prov->provisionReseller(Provisioner::newUnit('reseller', 9000018, 9100018, 1, 81), ['credits' => 999999999], [], ['username_seed' => 'Di Eng', 'email' => "oc-$RUN-d@example.test", 'fullname' => 'Di Eng'], $save);
	$check('more credits than the reseller has: fails at once with the amounts and creates no account',
		$big['status'] === 'failed' && $big['panel_id'] === '' && stripos($big['error'], 'needs 1000000009 credits') !== false && sub_by_email("oc-$RUN-d@example.test") === null, $big);
	$ok = $prov->provisionReseller($big, ['credits' => 30], [], ['username_seed' => 'Di Eng', 'email' => "oc-$RUN-d@example.test", 'fullname' => 'Di Eng'], $save);
	$cleanupUsers[] = $ok['panel_id'];
	$secrets[] = $ok['password'];
	$check('provision again with a usable amount creates the account with its credits', $ok['status'] === 'done' && $ok['panel_id'] !== '' && (int)sub_by_id($ok['panel_id'])['credits'] === 30, $ok);

	// ============================================================================================
	// Part 2: the glue on a real database.
	// ============================================================================================
	$dbPort = getenv('OC_DB_PORT');

	if ($dbPort) {
		echo "== glue on MariaDB (OrderService, Store, install model)\n";

		mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

		// OpenCart's DB class, as far as the extension uses it.
		class OcDb {
			private mysqli $link;

			public function __construct(mysqli $link) {
				$this->link = $link;
			}

			public function query(string $sql) {
				$result = $this->link->query($sql);

				if ($result instanceof mysqli_result) {
					$query = new stdClass();
					$query->rows = $result->fetch_all(MYSQLI_ASSOC);
					$query->row = $query->rows[0] ?? [];
					$query->num_rows = count($query->rows);

					return $query;
				}

				return true;
			}

			public function escape(string $value): string {
				return $this->link->real_escape_string($value);
			}

			public function getLastId(): int {
				return $this->link->insert_id;
			}
		}

		$dbName = 'xc_oc_' . $RUN;
		$root = new mysqli('127.0.0.1', 'root', getenv('OC_DB_PASSWORD') ?: 'xc_opencart_test', '', (int)$dbPort);
		$root->query('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4');
		$link = new mysqli('127.0.0.1', 'root', getenv('OC_DB_PASSWORD') ?: 'xc_opencart_test', $dbName, (int)$dbPort);
		$link->set_charset('utf8mb4');
		$db = new OcDb($link);

		// Tables of OpenCart the extension reads (subset of the real layout).
		$link->query("CREATE TABLE oc_order (order_id int NOT NULL AUTO_INCREMENT, customer_id int NOT NULL DEFAULT 0, order_status_id int NOT NULL DEFAULT 0, email varchar(96) NOT NULL DEFAULT '', firstname varchar(32) NOT NULL DEFAULT '', lastname varchar(32) NOT NULL DEFAULT '', PRIMARY KEY (order_id))");
		$link->query("CREATE TABLE oc_order_product (order_product_id int NOT NULL AUTO_INCREMENT, order_id int NOT NULL, product_id int NOT NULL, master_id int NOT NULL DEFAULT 0, name varchar(255) NOT NULL, quantity int NOT NULL, PRIMARY KEY (order_product_id))");

		// The real install model.
		require $ROOT . '/admin/model/module/xtreampro.php';

		$fakeEvents = new class {
			public array $events = [];
			public array $deleted = [];

			public function addEvent(array $e): int {
				$this->events[$e['code']] = $e;

				return count($this->events);
			}

			public function deleteEventByCode(string $code): void {
				$this->deleted[] = $code;
				unset($this->events[$code]);
			}
		};
		$fakeSettings = new class {
			public array $deleted = [];
			public array $saved = [];

			public function deleteSetting(string $code): void {
				$this->deleted[] = $code;
				unset($this->saved[$code]);
			}

			public function getSetting(string $code): array {
				return $this->saved[$code] ?? [];
			}

			public function editSetting(string $code, array $data): void {
				$this->saved[$code] = $data;
			}
		};
		$loader = new class {
			public function model(string $route): void {}
		};
		\Opencart\System\Engine\Model::$registry = ['db' => $db, 'load' => $loader, 'model_setting_event' => $fakeEvents, 'model_setting_setting' => $fakeSettings];

		$model = new \Opencart\Admin\Model\Extension\Xtreampro\Module\Xtreampro();
		$model->install();
		$model->install();
		$check('install model: tables created (and a second install is harmless)', $db->query("SHOW TABLES LIKE 'oc_xtreampro_%'")->num_rows === 2);
		$instance = $fakeSettings->saved['module_xtreampro_instance']['module_xtreampro_instance'] ?? '';
		$check('install model: a random shop token for the request ids, created once', preg_match('/^[0-9a-f]{8}$/', $instance) === 1, $instance);
		$check('install model: 8 events registered once each', count($fakeEvents->events) === 8, array_keys($fakeEvents->events));

		$missing = [];
		foreach ($fakeEvents->events as $event) {
			[$app] = explode('/', $event['trigger'], 2);
			[$route, $method] = explode('.', $event['action']);
			$file = $ROOT . '/' . $app . '/controller/' . preg_replace('~^extension/xtreampro/~', '', $route) . '.php';

			if (!in_array($app, ['admin', 'catalog'], true) || !is_file($file) || !preg_match('/public function ' . $method . '\(/', (string)file_get_contents($file))) {
				$missing[] = $event['action'];
			}
		}
		$check('every event points to a real controller method', !$missing && $fakeEvents->events['xtreampro_order_history']['trigger'] === 'catalog/model/checkout/order/addHistory/after', $missing);

		// Settings.
		$settings = [
			'status'           => true,
			'api_url'          => $API_BASE,
			'api_key'          => $API_KEY,
			'panel_url'        => 'https://panel.example.test/',
			'instance'         => $RUN,
			'paid_statuses'    => [2, 5],
			'revoked_statuses' => [7, 11]
		];
		$svc = new OrderService($db, DB_PREFIX, $settings, $log);
		$store = $svc->getStore();

		$config = new class {
			public function get(string $key) {
				return ['module_xtreampro_status' => 1, 'module_xtreampro_api_url' => 'http://x', 'module_xtreampro_api_key' => 'k', 'module_xtreampro_panel_url' => '', 'module_xtreampro_paid_statuses' => ['2', '5'], 'module_xtreampro_revoked_statuses' => null, 'module_xtreampro_instance' => 'Ab12-%'][$key] ?? null;
			}
		};
		$fromConfig = OrderService::settingsFrom($config);
		$check('settings are read from the OpenCart config (statuses as ints, a missing list is empty, the token is sanitised)', $fromConfig['paid_statuses'] === [2, 5] && $fromConfig['revoked_statuses'] === [] && $fromConfig['instance'] === 'ab12' && $fromConfig['status'] === true, $fromConfig);
		$check('panel sign-in link is built from the panel address', $svc->getPanelLoginUrl() === 'https://panel.example.test/login');

		// Product settings.
		$store->saveProduct(501, 'line', (int)$pkg['id'], false, 0);
		$store->saveProduct(502, 'reseller', 0, false, 10);
		$store->saveProduct(503, 'line', (int)$pkg['id'], true, 0);
		$check('product settings round trip', $store->getProduct(501) === ['kind' => 'line', 'package_id' => (int)$pkg['id'], 'trial' => false, 'credits' => 0] && $store->getProduct(502)['credits'] === 10 && $store->getProduct(503)['trial'] === true);
		$store->saveProduct(503, '', 0, false, 0);
		$check('"does nothing" removes the settings, delete forgets them', $store->getProduct(503) === null && ($store->deleteProduct(502) || true) && $store->getProduct(502) === null);
		$store->saveProduct(502, 'reseller', 0, false, 10);

		function new_order(OcDb $db, int $customer, int $status, string $email, array $items, string $first = 'Ann', string $last = 'Berg'): int {
			$db->query("INSERT INTO oc_order SET customer_id = '" . $customer . "', order_status_id = '" . $status . "', email = '" . $db->escape($email) . "', firstname = '" . $db->escape($first) . "', lastname = '" . $db->escape($last) . "'");
			$order_id = $db->getLastId();

			foreach ($items as $item) {
				$db->query("INSERT INTO oc_order_product SET order_id = '" . $order_id . "', product_id = '" . $item[0] . "', master_id = '" . ($item[2] ?? 0) . "', name = '" . $db->escape('Product ' . $item[0]) . "', quantity = '" . $item[1] . "'");
			}

			return $order_id;
		}

		function set_status(OcDb $db, int $order_id, int $status): void {
			$db->query("UPDATE oc_order SET order_status_id = '" . $status . "' WHERE order_id = '" . $order_id . "'");
		}

		function units_of(Store $store, int $order_id): array {
			return $store->getUnitsByOrder($order_id);
		}

		// ---- lines, quantity 2, customer 901
		$o1 = new_order($db, 901, 1, "oc-$RUN-cust@example.test", [[501, 2], [999, 1]]);
		$c = credits();
		$svc->handleStatus($o1);
		$check('a pending order provisions nothing', count(units_of($store, $o1)) === 0 && credits() === $c);

		set_status($db, $o1, 2);
		$svc->handleStatus($o1);
		$units = units_of($store, $o1);
		foreach ($units as $unit) {
			$cleanupLines[] = (int)$unit['panel_id'];
			$secrets[] = $unit['password'];
		}
		$check('paid: one line per unit, none for products that sell nothing', count($units) === 2 && $units[0]['unit'] === 1 && $units[1]['unit'] === 2 && $units[0]['panel_id'] !== $units[1]['panel_id'] && $units[0]['status'] === 'done' && $units[1]['status'] === 'done', $units);
		$check('units are keyed by order product id + unit number and carry the customer', $units[0]['order_product_id'] === $units[1]['order_product_id'] && $units[0]['customer_id'] === 901 && $units[0]['product_name'] === 'Product 501');
		$check('panel: both lines active', (line_of($units[0]['panel_id'])['status'] ?? '') === 'active' && (line_of($units[1]['panel_id'])['status'] ?? '') === 'active');
		$c = credits();
		$svc->handleStatus($o1);
		$svc->handleStatus($o1);
		$check('the same event again (the order history gets many entries): nothing created, nothing charged', credits() === $c && count(units_of($store, $o1)) === 2);

		// Customer views.
		$view = $svc->getOrderView($o1, 901);
		$check('customer sees the lines of the order with the panel links', count($view['lines']) === 2 && $view['lines'][0]['live'] && strpos($view['lines'][0]['links']['m3u'], $units[0]['username']) !== false && $view['lines'][0]['password'] === $units[0]['password'], $view['lines'][0] ?? null);
		$check('another customer sees nothing', $svc->getOrderView($o1, 902) === ['lines' => [], 'accounts' => []]);
		$check('a guest (customer 0) sees nothing', $svc->getOrderView($o1, 0) === ['lines' => [], 'accounts' => []]);
		$cv = CredentialsView::build($view, 'Y-m-d', function (int $id): string { return '/order/' . $id; });
		$check('credentials view: formatted expiry, http links only, order link', preg_match('/^\d{4}-\d{2}-\d{2}$/', $cv['lines'][0]['expires']) === 1 && strpos($cv['lines'][0]['playlist'], 'http') === 0 && $cv['lines'][0]['order_url'] === '/order/' . $o1, $cv['lines'][0]);
		$check('CredentialsView drops anything that is not an http(s) address', CredentialsView::safeUrl('javascript:alert(1)') === '' && CredentialsView::safeUrl('HTTPS://x.test/a') === 'HTTPS://x.test/a');
		$av = $svc->getAccountView(901);
		$check('account page view lists the customer\'s lines', count($av['lines']) === 2 && $av['accounts'] === []);

		// Refund and paid again.
		set_status($db, $o1, 11);
		$svc->handleStatus($o1);
		$units = units_of($store, $o1);
		$check('refunded: both lines disabled on the panel, units revoked', $units[0]['status'] === 'revoked' && $units[1]['status'] === 'revoked' && (line_of($units[0]['panel_id'])['status'] ?? '') === 'disabled' && (line_of($units[1]['panel_id'])['status'] ?? '') === 'disabled');
		$check('the customer still sees the lines, marked disabled', ($svc->getOrderView($o1, 901)['lines'][0]['revoked'] ?? false) === true);
		set_status($db, $o1, 5);
		$svc->handleStatus($o1);
		$units = units_of($store, $o1);
		$check('complete again: both lines are enabled again (no new lines, no charge)', $units[0]['status'] === 'done' && (line_of($units[0]['panel_id'])['status'] ?? '') === 'active' && (line_of($units[1]['panel_id'])['status'] ?? '') === 'active' && credits() === $c);

		// provision again (admin button).
		$r = $svc->provisionAgain($o1);
		$check('"provision again" on a finished order says so', $r['ok'] === true && strpos($r['message'], 'All 2') === 0, $r);
		$o1b = new_order($db, 901, 1, "oc-$RUN-cust@example.test", [[501, 1]]);
		$r = $svc->provisionAgain($o1b);
		$check('"provision again" refuses an order that is not in a paid status', $r['ok'] === false && stripos($r['message'], 'paid') !== false && count(units_of($store, $o1b)) === 0, $r);
		$r = $svc->provisionAgain(99999999);
		$check('"provision again" for a missing order is refused', $r['ok'] === false);

		// Variant: the order product's own product has no settings, the master has.
		$o2 = new_order($db, 903, 2, "oc-$RUN-v@example.test", [[7001, 1, 501]]);
		$svc->handleStatus($o2);
		$u2 = units_of($store, $o2);
		$cleanupLines[] = (int)($u2[0]['panel_id'] ?? 0);
		$secrets[] = $u2[0]['password'] ?? '';
		$check('a variant uses the settings of its master product', count($u2) === 1 && $u2[0]['status'] === 'done', $u2);

		// Guest order with a line: created (credentials are visible to the admin in the order block).
		$o3 = new_order($db, 0, 2, "oc-$RUN-g@example.test", [[501, 1]]);
		$svc->handleStatus($o3);
		$u3 = units_of($store, $o3);
		$cleanupLines[] = (int)($u3[0]['panel_id'] ?? 0);
		$secrets[] = $u3[0]['password'] ?? '';
		$check('a guest order for a line is provisioned (customer 0)', count($u3) === 1 && $u3[0]['status'] === 'done' && $u3[0]['customer_id'] === 0);
		$check('a guest order for a sub-reseller product is refused readably', (function () use ($db, $svc, $store, $RUN) {
			$o = new_order($db, 0, 2, "oc-$RUN-gr@example.test", [[502, 1]]);
			$svc->handleStatus($o);
			$u = units_of($store, $o);

			return count($u) === 1 && $u[0]['status'] === 'failed' && stripos($u[0]['error'], 'guest') !== false && $u[0]['panel_id'] === '';
		})());

		// Sub-reseller: quantity 2 x 10 credits, customer 904, then a second order tops up.
		$o4 = new_order($db, 904, 2, "oc-$RUN-s1@example.test", [[502, 2]], 'Bo', 'Carl');
		$svc->handleStatus($o4);
		$s = units_of($store, $o4);
		$cleanupUsers[] = $s[0]['panel_id'] ?? '';
		$secrets[] = $s[0]['password'] ?? '';
		$acc = sub_by_id($s[0]['panel_id'] ?? '');
		$check('sub-reseller order: one unit, one account with credits = per unit x quantity (20)', count($s) === 1 && $s[0]['status'] === 'done' && $s[0]['created'] === 1 && $acc && (int)$acc['credits'] === 20 && $acc['email'] === "oc-$RUN-s1@example.test", [$s, $acc]);
		$o5 = new_order($db, 904, 2, "oc-$RUN-s1@example.test", [[502, 1]], 'Bo', 'Carl');
		$svc->handleStatus($o5);
		$s5 = units_of($store, $o5);
		$check('a later order of the same customer tops up the same account (30), no second account', count($s5) === 1 && $s5[0]['panel_id'] === $s[0]['panel_id'] && $s5[0]['created'] === 0 && (int)sub_by_id($s[0]['panel_id'])['credits'] === 30, [$s5, sub_by_id($s[0]['panel_id'])['credits']]);
		$svc->handleStatus($o5);
		$svc->handleStatus($o4);
		$check('repeated events do not top up again', (int)sub_by_id($s[0]['panel_id'])['credits'] === 30);
		$ov = $svc->getOrderView($o4, 904);
		$ov5 = $svc->getOrderView($o5, 904);
		$check('order page of the creating order shows the password once; the later order does not', count($ov['accounts']) === 1 && $ov['accounts'][0]['password'] === $s[0]['password'] && $ov['accounts'][0]['password'] !== '' && $ov['accounts'][0]['balance'] === 30 && $ov['accounts'][0]['login'] === 'https://panel.example.test/login' && $ov5['accounts'][0]['password'] === '', [$ov, $ov5]);
		$aview = $svc->getAccountView(904);
		$check('account page shows the account without a password and the balance', count($aview['accounts']) === 1 && $aview['accounts'][0]['password'] === '' && $aview['accounts'][0]['balance'] === 30 && $aview['lines'] === [], $aview);

		set_status($db, $o5, 7);
		$svc->handleStatus($o5);
		$check('later order cancelled: its 10 credits are taken back, the account stays active', (int)sub_by_id($s[0]['panel_id'])['credits'] === 20 && sub_by_id($s[0]['panel_id'])['status'] === 'active');
		set_status($db, $o4, 7);
		$svc->handleStatus($o4);
		$check('creating order cancelled: its credits taken back and the account disabled', (int)sub_by_id($s[0]['panel_id'])['credits'] === 0 && sub_by_id($s[0]['panel_id'])['status'] === 'disabled', sub_by_id($s[0]['panel_id']));
		set_status($db, $o4, 2);
		$svc->handleStatus($o4);
		$check('creating order paid again: account enabled, credits handed over again', sub_by_id($s[0]['panel_id'])['status'] === 'active' && (int)sub_by_id($s[0]['panel_id'])['credits'] === 20, sub_by_id($s[0]['panel_id']));

		// Order lock: another connection is provisioning this order.
		$other = new mysqli('127.0.0.1', 'root', getenv('OC_DB_PASSWORD') ?: 'xc_opencart_test', $dbName, (int)$dbPort);
		$o6 = new_order($db, 905, 2, "oc-$RUN-l@example.test", [[501, 1]]);
		$other->query("SELECT GET_LOCK('xtreampro_order_" . $o6 . "', 0)");
		$c = credits();
		$svc->handleStatus($o6);
		$check('an order another request is provisioning is skipped (no double work)', count(units_of($store, $o6)) === 0 && credits() === $c && $svc->provision(['order_id' => $o6, 'customer_id' => 905, 'email' => 'x', 'firstname' => '', 'lastname' => '']) === false);
		$other->query("SELECT RELEASE_LOCK('xtreampro_order_" . $o6 . "')");
		$svc->handleStatus($o6);
		$u6 = units_of($store, $o6);
		$cleanupLines[] = (int)($u6[0]['panel_id'] ?? 0);
		$secrets[] = $u6[0]['password'] ?? '';
		$check('after the lock is released the order is provisioned', count($u6) === 1 && $u6[0]['status'] === 'done');
		$other->close();

		// Failure recorded and retried: unknown package.
		$store->saveProduct(504, 'line', 999999, false, 0);
		$o7 = new_order($db, 906, 2, "oc-$RUN-f@example.test", [[504, 1]]);
		$svc->handleStatus($o7);
		$u7 = units_of($store, $o7);
		$check('a failure is recorded on the unit for the admin', count($u7) === 1 && $u7[0]['status'] === 'failed' && stripos($u7[0]['error'], 'package') !== false, $u7);
		$r = $svc->provisionAgain($o7);
		$check('"provision again" reports what is still wrong', $r['ok'] === false && strpos($r['message'], '1 of 1') === 0, $r);
		$store->saveProduct(504, 'line', (int)$pkg['id'], false, 0);
		$r = $svc->provisionAgain($o7);
		$u7 = units_of($store, $o7);
		$cleanupLines[] = (int)($u7[0]['panel_id'] ?? 0);
		$secrets[] = $u7[0]['password'] ?? '';
		$check('after the product is fixed, "provision again" completes it', $r['ok'] === true && $u7[0]['status'] === 'done' && $u7[0]['error'] === '', [$r, $u7]);

		// Not configured.
		$off = new OrderService($db, DB_PREFIX, array_merge($settings, ['api_key' => '']), $log);
		$o8 = new_order($db, 907, 2, "oc-$RUN-n@example.test", [[501, 1], [502, 1]]);
		$off->handleStatus($o8);
		$u8 = units_of($store, $o8);
		$check('without an API key every unit records that the extension is not configured', count($u8) === 2 && $u8[0]['status'] === 'failed' && $u8[1]['status'] === 'failed' && stripos($u8[0]['error'], 'not configured') !== false, $u8);
		$off->handleStatus($o8);
		$check('and a revoke without a key does not crash', true);
		$ov = $off->getOrderView($o8, 907);
		$check('the customer views without a key show nothing (no unit has a panel id)', $ov === ['lines' => [], 'accounts' => []]);
		$svc->handleStatus($o8);
		$u8 = units_of($store, $o8);
		$cleanupLines[] = (int)($u8[0]['panel_id'] ?? 0);
		$cleanupUsers[] = $u8[1]['panel_id'] ?? '';
		$secrets[] = $u8[0]['password'] ?? '';
		$secrets[] = $u8[1]['password'] ?? '';
		$check('once configured, the next status event completes them', $u8[0]['status'] === 'done' && $u8[1]['status'] === 'done', $u8);

		// SQL text safety: quotes in names and emails are escaped, not executed.
		$o9 = new_order($db, 908, 2, "o'c-$RUN@example.test", [[501, 1]], "O'Neil", 'Smith"; DROP TABLE oc_order; --');
		$svc->handleStatus($o9);
		$u9 = units_of($store, $o9);
		$cleanupLines[] = (int)($u9[0]['panel_id'] ?? 0);
		$secrets[] = $u9[0]['password'] ?? '';
		$check('quotes in customer data do not break the SQL', count($u9) === 1 && $u9[0]['status'] === 'done' && $db->query("SHOW TABLES LIKE 'oc_order'")->num_rows === 1);

		// Uninstall.
		$model->uninstall();
		$check('uninstall removes the events and the settings but keeps the shop token and the sold lines', count($fakeEvents->events) === 0 && $fakeSettings->deleted === ['module_xtreampro', 'module_xtreampro_cache'] && ($fakeSettings->saved['module_xtreampro_instance']['module_xtreampro_instance'] ?? '') === $instance && $db->query("SELECT COUNT(*) AS c FROM oc_xtreampro_unit")->row['c'] > 0);

		$link->close();
		$root->query('DROP DATABASE `' . $dbName . '`');
		$root->close();
	} else {
		echo "== glue on MariaDB: skipped (OC_DB_PORT not set)\n";
	}

	// ============================================================================================
	echo "== language keys and templates\n";
	$langs = ['admin' => [$ROOT . '/admin/language/en-gb/module/xtreampro.php', [$ROOT . '/admin/controller', $ROOT . '/admin/view', $ROOT . '/admin/model']],
		'catalog' => [$ROOT . '/catalog/language/en-gb/xtreampro.php', [$ROOT . '/catalog/controller', $ROOT . '/catalog/view']]];

	foreach ($langs as $name => [$file, $dirs]) {
		$_ = [];
		include $file;
		$used = [];
		foreach ($dirs as $dir) {
			$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
			foreach ($it as $f) {
				if (preg_match('/\.(php|twig)$/', $f->getFilename())) {
					preg_match_all('/\bxp_[a-z_]+/', file_get_contents($f->getPathname()), $m);
					$used = array_merge($used, $m[0]);
				}
			}
		}
		$undefined = array_values(array_diff(array_unique($used), array_keys($_)));
		$unused = array_values(array_diff(array_keys($_), array_unique($used), ['heading_title']));
		$check("$name: every language key used in the code and templates is defined", !$undefined, $undefined);
		$check("$name: no unused language key", !$unused, $unused);
		$bare = array_values(array_filter(array_keys($_), function ($k) { return strpos($k, 'xp_') !== 0 && $k !== 'heading_title'; }));
		$check("$name: no language key that could replace a string of an OpenCart page", !$bare, $bare);
	}

	// ============================================================================================
	echo "== secrets\n";
	$all = implode("\n", $logs);
	$check('the leak check had real secrets to look for', count(array_unique(array_filter($secrets))) >= 10, (string)count($secrets));
	$check('the connector logged its work', count($logs) > 60, (string)count($logs));
	$leak = '';
	foreach (array_unique(array_filter($secrets)) as $s) {
		if (strpos($all, $s) !== false) {
			$leak = $s === $API_KEY ? 'API key' : 'a password';
			break;
		}
	}
	$check('neither the API key nor any password reached the log', $leak === '', $leak);
	$check('no clear password= in any logged text', !preg_match('/password=(?!\*)[^&"\s]/', $all));
	$check('the log has no X-API-Key header text', stripos($all, 'x-api-key') === false);
	$sources = '';
	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT . '/system/library', FilesystemIterator::SKIP_DOTS)) as $f) {
		$sources .= file_get_contents($f->getPathname());
	}
	$check('no real secret is written in the library sources', strpos($sources, $API_KEY) === false);

	// ---- clean up what is left on the shared panel -----------------------------------------------
	foreach (array_unique($cleanupLines) as $id) {
		if ($id) {
			panel('delete_line', ['id' => $id], true);
		}
	}
	foreach (array_unique(array_filter($cleanupUsers)) as $id) {
		panel('disable_user', ['id' => $id], true);
	}

	echo $fail ? "\n$fail FAILED\n" : "\nALL OK\n";
	exit($fail ? 1 : 0);
}
