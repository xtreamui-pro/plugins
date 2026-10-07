<?php
// Runs the platform independent core of the Magento connector (the real files
// under plugins/magento/app/code/XtreamPro/Connector/Core, not copies) against
// a panel API. Magento itself is not started: observers, cron, controllers,
// blocks and templates are only linted. Not shipped.
//
//   API_PORT_NUM=18095 API_KEY_FILE=/path/to/key php plugins/e2e/magento-harness.php
//   (or API_KEY=... instead of API_KEY_FILE; API_HOST defaults to 127.0.0.1)
//
// Prints ALL OK and exits 0 when every check passed.
error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) {
    echo "  PHP warning: $str ($file:$line)\n";
    $GLOBALS['warnings']++;
    return true;
});
$GLOBALS['warnings'] = 0;

$core = dirname(__DIR__) . '/magento/app/code/XtreamPro/Connector/Core';
require_once $core . '/ApiException.php';
require_once $core . '/ApiClient.php';
require_once $core . '/Provisioner.php';

use XtreamPro\Connector\Core\ApiClient;
use XtreamPro\Connector\Core\ApiException;
use XtreamPro\Connector\Core\Provisioner;

$key = getenv('API_KEY');
if (!$key && getenv('API_KEY_FILE')) {
    $key = trim((string) file_get_contents(getenv('API_KEY_FILE')));
}
if (!$key) {
    fwrite(STDERR, "Set API_KEY or API_KEY_FILE\n");
    exit(2);
}
$base = 'http://' . (getenv('API_HOST') ?: '127.0.0.1') . ':' . (getenv('API_PORT_NUM') ?: '18095');

// Everything the core logs is collected and searched for secrets at the end.
$logs = array();
$logger = function ($level, $message, array $context) use (&$logs) {
    $logs[] = json_encode(array($level, $message, $context));
};

$fail = 0;
$check = function ($label, $ok, $detail = '') use (&$fail) {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' -> ' . (is_string($detail) ? $detail : json_encode($detail))) . "\n";
    if (!$ok) {
        $fail++;
    }
};
// Runs $fn and returns the ApiException code it throws ('' when it does not).
$codeOf = function (callable $fn) {
    try {
        $fn();
    } catch (ApiException $e) {
        return $e->getErrorCode();
    }
    return '';
};

// Independent view of the panel: raw curl, not the core.
$panel = function ($action, array $query = array()) use ($base, $key) {
    $ch = curl_init($base . '/reseller/v1?' . http_build_query(array('action' => $action) + $query));
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => array('X-API-Key: ' . $key)));
    $body = curl_exec($ch);
    curl_close($ch);
    return json_decode((string) $body, true);
};

$run = getmypid() . mt_rand(100, 999);              // unique per run, other agents share the panel
$itemId = (int) substr($run, -8) + 900000000;        // fake order item id for the request ids
$customerId = (int) substr($run, -8) + 800000000;    // fake customer id

$api = new ApiClient($base, $key, $logger);
$core = new Provisioner($api);
$secrets = array($key);                              // strings that must never appear in the logs

echo "== connection and catalogue\n";
$info = $core->testConnection();
$check('testConnection returns the reseller', isset($info['username']) && $info['username'] !== '', $info);
$packages = $core->packages();
$check('package list is not empty', count($packages) >= 1, $packages);
$official = null;
foreach ($packages as $p) {
    if (strpos($p['label'], 'credits') !== false && strpos($p['label'], 'trial only') === false) {
        $official = $p;
        break;
    }
}
$check('an official package exists', $official !== null);
$pkgId = $official ? $official['id'] : $packages[0]['id'];

$badKey = new Provisioner(new ApiClient($base, 'xk_wrong_' . $run, $logger));
$msg = '';
$code = $codeOf(function () use ($badKey, &$msg) {
    try {
        $badKey->testConnection();
    } catch (ApiException $e) {
        $msg = $e->getMessage();
        throw $e;
    }
});
$check('wrong key: INVALID_API_KEY with a readable message', $code === 'INVALID_API_KEY' && stripos($msg, 'API key') !== false, array($code, $msg));
$check('unknown package: INVALID_PACKAGE', $codeOf(function () use ($core, $itemId) {
    $core->createLine(99999999, false, Provisioner::lineRequestId($itemId, 99));
}) === 'INVALID_PACKAGE');
$check('no package selected: INVALID_PACKAGE without calling the panel', $codeOf(function () use ($core, $itemId) {
    $core->createLine(0, false, Provisioner::lineRequestId($itemId, 98));
}) === 'INVALID_PACKAGE');
$check('empty configuration: CONFIG', $codeOf(function () use ($logger) {
    new ApiClient('', '', $logger);
}) === 'CONFIG');
$check('unreachable panel: CONNECTION_FAILED', $codeOf(function () use ($key, $logger) {
    $c = new Provisioner(new ApiClient('http://127.0.0.1:1', $key, $logger));
    $c->testConnection();
}) === 'CONNECTION_FAILED');

echo "== 1.1.0: sells, pricing, connector name, spent request id\n";
$check('version 1.1.0 in the module files and in the API client (X-Connector)', ApiClient::VERSION === '1.1.0'
    && strpos(file_get_contents(__DIR__ . '/../magento/app/code/XtreamPro/Connector/registration.php'), '1.1.0') !== false
    && strpos(file_get_contents(__DIR__ . '/../magento/app/code/XtreamPro/Connector/composer.json'), '"version": "1.1.0"') !== false);
$rawPk = $panel('packages')['data'];
$boxPkg = null;
foreach ($rawPk as $p) {
    if (isset($p['sells']) && !in_array('line', $p['sells'], true)) {
        $boxPkg = $p;
        break;
    }
}
$check('the panel marks a box-only package with sells without "line"', $boxPkg !== null, $rawPk);
$check('a panel that sends no sells counts as selling lines', ApiClient::sellsLine(array('id' => 1)) && !ApiClient::sellsLine(array('sells' => array('mag'))));
$check('the package list of the product form leaves box-only packages out and keeps the others', $boxPkg !== null && !in_array((int) $boxPkg['id'], array_column($packages, 'id'), true) && count($packages) === count(array_filter($rawPk, function ($p) { return ApiClient::sellsLine($p); })), array_column($packages, 'id'));
$msg = '';
$code = $codeOf(function () use ($core, $itemId, $boxPkg, &$msg) {
    try {
        $core->createLine((int) $boxPkg['id'], false, Provisioner::lineRequestId($itemId, 97));
    } catch (ApiException $e) {
        $msg = $e->getMessage();
        throw $e;
    }
});
$check('a box-only package is refused before anything is sold, with a readable reason', $code === 'INVALID_PACKAGE' && stripos($msg, 'boxes only') !== false, array($code, $msg));
$names = array_column($panel('api_logs', array('limit' => 50))['data'] ?? array(), 'connector');
$check('the panel call log names the connector', in_array('magento/1.1.0', $names, true), array_unique($names));
$spRid = Provisioner::lineRequestId($itemId, 96);
$spLine = $core->createLine($pkgId, false, $spRid);
$core->terminateLine($spLine['id'], true);
$msg = '';
$code = $codeOf(function () use ($core, $pkgId, $spRid, &$msg) {
    try {
        $core->createLine($pkgId, false, $spRid);
    } catch (ApiException $e) {
        $msg = $e->getMessage();
        throw $e;
    }
});
$check('selling again under the request id of a line deleted on the panel gives a readable message (REQUEST_ID_SPENT)', $code === 'REQUEST_ID_SPENT' && stripos($msg, 'already made') !== false && strpos($msg, 'REQUEST_ID') === false, array($code, $msg));
$check('terminate disables by default (deleting is final and has to be asked for)', ($t = $core->createLine($pkgId, false, Provisioner::lineRequestId($itemId, 95))) && $core->terminateLine($t['id']) === true && $panel('get_line', array('id' => $t['id']))['data']['status'] === 'disabled' && $core->terminateLine($t['id'], true) === true);
$check('too few credits for an account plus its starting credits: INSUFFICIENT_CREDITS before the account is created', $codeOf(function () use ($core) {
    $core->createSubReseller(array('username' => 'nobody' . mt_rand(1000, 9999), 'password' => 'longenough1', 'email' => 'nobody@example.test', 'fullname' => ''), 'mage-never', 999999999);
}) === 'INSUFFICIENT_CREDITS');

echo "== request ids\n";
$check('line request id', Provisioner::lineRequestId(12, 3) === 'mage-l-12-3');
$check('generation changes the request id', Provisioner::lineRequestId(12, 3, 1) === 'mage-l-12-3-g1');
$check('request ids stay within 64 characters', strlen(Provisioner::renewRequestId(PHP_INT_MAX, str_repeat('9', 100))) <= 64);
$u = Provisioner::newUsername('Ann.Berg+shop@example.com');
$check('generated username is valid for the panel', (bool) preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $u), $u);
$check('generated username from nothing is valid', (bool) preg_match('/^[A-Za-z0-9_.-]{3,32}$/', Provisioner::newUsername('+++')));
$check('generated passwords differ and have 14 characters', ($p1 = Provisioner::newPassword()) !== Provisioner::newPassword() && strlen($p1) === 14);

echo "== IPTV line\n";
$rid = Provisioner::lineRequestId($itemId, 1);
$line = $core->createLine($pkgId, false, $rid);
$lineId = $line['id'];
$check('create returns id, username, password and links', $lineId > 0 && $line['username'] !== '' && $line['password'] !== '' && !empty($line['links']['m3u']), $line['links']);
$secrets[] = $line['password'];
$raw = $panel('get_line', array('id' => $lineId));
$check('the panel has the line, active', ($raw['data']['status'] ?? '') === 'active' && $raw['data']['username'] === $line['username'], $raw['status'] ?? '');
$exp1 = $raw['data']['exp_date'];
$again = $core->createLine($pkgId, false, $rid);
$check('replay of create returns the same line and password', $again['id'] === $lineId && $again['password'] === $line['password'] && $again['username'] === $line['username']);
$raw = $panel('get_line', array('id' => $lineId));
$check('replay did not extend or duplicate the line', $raw['data']['exp_date'] === $exp1);
$found = $panel('get_lines', array('search' => $line['username'], 'limit' => 50));
$check('exactly one line has this username', count(array_filter($found['data'], function ($r) use ($line) { return $r['username'] === $line['username']; })) === 1);
$read = $core->readLine($lineId);
$check('readLine matches', $read['id'] === $lineId && $read['status'] === 'active' && $read['password'] === $line['password'] && !empty($read['links']['web_player']), $read);

$core->suspendLine($lineId);
$check('suspend disables the line', $panel('get_line', array('id' => $lineId))['data']['status'] === 'disabled');
$core->unsuspendLine($lineId);
$check('unsuspend enables it again', $panel('get_line', array('id' => $lineId))['data']['status'] === 'active');

$renewId = Provisioner::renewRequestId($lineId, date('Ymd') . $run);
$core->renewLine($lineId, $renewId);
$exp2 = $panel('get_line', array('id' => $lineId))['data']['exp_date'];
$check('renew moves the expiry', $exp2 !== $exp1, "$exp1 -> $exp2");
$renewed = $core->renewLine($lineId, $renewId);
$check('repeated renew returns the line', $renewed['id'] === $lineId);
$check('repeated renew does not extend twice', $panel('get_line', array('id' => $lineId))['data']['exp_date'] === $exp2);

$check('terminate deletes the line', $core->terminateLine($lineId, true) === true);
$gone = $panel('get_line', array('id' => $lineId));
$check('the panel no longer has it', ($gone['status'] ?? '') !== 'STATUS_SUCCESS' && ($gone['error'] ?? '') === 'RESOURCE_NOT_FOUND', $gone);
$check('terminate again is not an error (returns false)', $core->terminateLine($lineId, true) === false);
$check('suspend of a line that is gone: RESOURCE_NOT_FOUND', $codeOf(function () use ($core, $lineId) { $core->suspendLine($lineId); }) === 'RESOURCE_NOT_FOUND');

$new = $core->createLine($pkgId, false, Provisioner::lineRequestId($itemId, 1, 1));
$check('create again with generation 1 sells a new line', $new['id'] > 0 && $new['id'] !== $lineId, array($new['id'], $lineId));
$secrets[] = $new['password'];
$check('terminate with delete off only disables', $core->terminateLine($new['id'], false) === true && $panel('get_line', array('id' => $new['id']))['data']['status'] === 'disabled');
$core->terminateLine($new['id'], true);

echo "== sub-reseller account\n";
$username = Provisioner::newUsername('magento-' . $run);
$password = Provisioner::newPassword();
$secrets[] = $password;
$email = 'magento-' . $run . '@example.test';
$account = array('username' => $username, 'password' => $password, 'email' => $email, 'fullname' => 'Ann Berg');
$arid = Provisioner::accountRequestId($customerId);

$check('a username that is too short is refused before the call', $codeOf(function () use ($core, $arid) {
    $core->createSubReseller(array('username' => 'ab', 'password' => 'longenough1', 'email' => 'x@example.test', 'fullname' => ''), $arid);
}) === 'INVALID_REQUEST');
$check('a password that is too short is refused before the call', $codeOf(function () use ($core, $arid) {
    $core->createSubReseller(array('username' => 'validname', 'password' => 'short', 'email' => 'x@example.test', 'fullname' => ''), $arid);
}) === 'INVALID_REQUEST');

$sub = $core->createSubReseller($account, $arid);
$subId = $sub['id'];
$check('create returns id, username and password', $subId !== '' && $sub['username'] === $username && $sub['password'] === $password, $sub['username']);
$raw = $panel('get_user', array('id' => $subId));
$check('the panel has the account, active, no credits', ($raw['data']['status'] ?? '') === 'active' && (int) ($raw['data']['credits'] ?? -1) === 0 && $raw['data']['email'] === $email, $raw['data'] ?? $raw);
$again = $core->createSubReseller($account, $arid);
$check('replay of create returns the same account', $again['id'] === $subId && $again['password'] === $password);
$found = $panel('get_users', array('search' => $username, 'limit' => 50));
$check('exactly one account has this username', count(array_filter($found['data'], function ($r) use ($username) { return $r['username'] === $username; })) === 1);

$gid = Provisioner::creditRequestId($itemId, 1);
$core->giveCredits($subId, 50, 'Order #' . $run, $gid);
$check('credits are handed over', (int) $panel('get_user', array('id' => $subId))['data']['credits'] === 50);
$core->giveCredits($subId, 50, 'Order #' . $run, $gid);
$check('repeated credit transfer is not paid twice', (int) $panel('get_user', array('id' => $subId))['data']['credits'] === 50);
$core->giveCredits($subId, 20, 'Order #' . $run . ' second unit', Provisioner::creditRequestId($itemId, 2));
$check('the next unit adds its credits', $core->readSubReseller($subId)['credits'] === 70);
$xid = Provisioner::takeBackRequestId($itemId, 2);
$core->takeBackCredits($subId, 20, 'Order #' . $run . ' refunded', $xid);
$core->takeBackCredits($subId, 20, 'Order #' . $run . ' refunded', $xid);
$check('credits are taken back once (repeat is not taken twice)', $core->readSubReseller($subId)['credits'] === 50);
$check('taking back more than the account has: INSUFFICIENT_CREDITS', $codeOf(function () use ($core, $subId, $itemId) {
    $core->takeBackCredits($subId, 5000, 'too much', Provisioner::takeBackRequestId($itemId, 3));
}) === 'INSUFFICIENT_CREDITS');
$read = $core->readSubReseller($subId);
$check('readSubReseller matches', $read['id'] === $subId && $read['username'] === $username && $read['status'] === 'active' && $read['credits'] === 50, $read);

$core->suspendSubReseller($subId);
$check('suspend disables the account', $panel('get_user', array('id' => $subId))['data']['status'] === 'disabled');
$core->unsuspendSubReseller($subId);
$check('unsuspend enables it again', $panel('get_user', array('id' => $subId))['data']['status'] === 'active');
$check('terminate disables the account', $core->terminateSubReseller($subId) === true && $panel('get_user', array('id' => $subId))['data']['status'] === 'disabled');
$check('terminate again is not an error', $core->terminateSubReseller($subId) === true);
$check('an account that is not in the panel: terminate returns false', $core->terminateSubReseller('00000000-0000-4000-8000-000000000000') === false);
$check('the same username again: CONFLICT', $codeOf(function () use ($core, $account, $customerId) {
    $core->createSubReseller($account, Provisioner::accountRequestId($customerId, 1));
}) === 'CONFLICT');
$account2 = array('username' => Provisioner::newUsername('magento-' . $run), 'password' => Provisioner::newPassword(), 'email' => 'magento2-' . $run . '@example.test', 'fullname' => 'Ann Berg');
$secrets[] = $account2['password'];
$sub2 = $core->createSubReseller($account2, Provisioner::accountRequestId($customerId, 1));
$check('create again with generation 1 makes a new account', $sub2['id'] !== '' && $sub2['id'] !== $subId);
$core->terminateSubReseller($sub2['id']);

echo "== logs\n";
$check('the core logged the calls', count($logs) > 20, count($logs));
$leak = '';
foreach ($logs as $entry) {
    foreach ($secrets as $secret) {
        if ($secret !== '' && strpos($entry, $secret) !== false) {
            $leak = substr($entry, 0, 160);
            break 2;
        }
    }
}
$check('no API key and no password in anything the core logged', $leak === '', $leak);
$check('passwords are masked in the logged requests', (bool) array_filter($logs, function ($e) { return strpos($e, '********') !== false; }));
$check('no PHP warnings', $GLOBALS['warnings'] === 0, $GLOBALS['warnings']);

echo $fail ? "\n$fail FAILED\n" : "\nALL OK\n";
exit($fail ? 1 : 0);
