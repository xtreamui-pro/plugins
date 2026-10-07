<?php
/**
 * End-to-end run of the webhook bridge (plugins/bridge) against a panel API.
 *
 * Starts the REAL application with PHP's built-in web server
 * (php -S 127.0.0.1:<free port> -t plugins/bridge/public) on a temporary
 * config, posts signed sample webhooks of all four kinds and checks the result
 * on the panel through the Reseller API. Not shipped.
 *
 *   API_KEY=... API_PORT_NUM=18095 php plugins/e2e/bridge-harness.php
 *   (API_URL overrides http://127.0.0.1:$API_PORT_NUM)
 */

declare(strict_types=1);

$root = dirname(__DIR__) . '/bridge';
require $root . '/src/bootstrap.php';

use XtreamPro\Bridge\ApiClient;
use XtreamPro\Bridge\Config;

$apiKey = (string) getenv('API_KEY');
$apiUrl = (string) (getenv('API_URL') ?: 'http://127.0.0.1:' . (getenv('API_PORT_NUM') ?: '18095'));
if ($apiKey === '') {
    fwrite(STDERR, "API_KEY is not set\n");
    exit(2);
}

$fail = 0;
$check = function (string $label, bool $ok, $detail = '') use (&$fail): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' -> ' . (is_string($detail) ? $detail : json_encode($detail))) . "\n";
    if (!$ok) {
        $fail++;
    }
};

// ---- temporary installation ---------------------------------------------------
$tmp = sys_get_temp_dir() . '/xc-bridge-e2e-' . getmypid();
mkdir($tmp . '/var', 0700, true);
$suffix = bin2hex(random_bytes(3));
$secrets = [
    'shopify'      => 'shop-secret-' . bin2hex(random_bytes(8)),
    'upmind'       => 'upmind-secret-' . bin2hex(random_bytes(8)),
    'invoiceninja' => 'inv-secret-' . bin2hex(random_bytes(8)),
    'generic'      => 'gen-secret-' . bin2hex(random_bytes(8)),
];
$api = new ApiClient($apiUrl, $apiKey);
$packages = $api->packages();
$pkg = 0;
foreach ($packages as $p) {
    if (!empty($p['is_official'])) {
        $pkg = (int) $p['id'];
        break;
    }
}
if ($pkg === 0) {
    fwrite(STDERR, "the panel has no official package\n");
    exit(2);
}
$upLine = 'up-line-' . $suffix;
$upRes = 'up-res-' . $suffix;
$skuLine = 'E2E-LINE';
$configFile = $tmp . '/config.php';

function write_config(string $file, array $over): void
{
    global $tmp, $apiUrl, $apiKey, $secrets, $pkg, $upLine, $upRes, $skuLine;
    $cfg = array_replace_recursive([
        'api_url' => $apiUrl,
        'api_key' => $apiKey,
        'panel_url' => 'https://panel.example.test',
        'secrets' => $secrets,
        'products' => [
            'shopify'      => [$skuLine => ['type' => 'line', 'package_id' => $pkg], 'variant:909000111' => ['type' => 'reseller', 'credits' => 100, 'renew_credits' => 30]],
            'upmind'       => [$upLine => ['type' => 'line', 'package_id' => $pkg], $upRes => ['type' => 'reseller', 'credits' => 50, 'renew_credits' => 20]],
            'invoiceninja' => ['e2e-line' => ['type' => 'line', 'package_id' => $pkg]],
            'generic'      => ['g-line' => ['type' => 'line', 'package_id' => $pkg], 'g-reseller' => ['type' => 'reseller', 'credits' => 40]],
        ],
        'mail' => ['transport' => 'file', 'from' => 'bridge@example.test', 'file' => $tmp . '/mail.jsonl'],
        'data_dir' => $tmp . '/var',
    ], $over);
    file_put_contents($file, '<?php return ' . var_export($cfg, true) . ";\n");
    chmod($file, 0600);
}
write_config($configFile, []);

// ---- start the app ----------------------------------------------------------------
$sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
$port = (int) substr((string) strrchr((string) stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
$env = array_merge(getenv(), ['BRIDGE_CONFIG' => $configFile]);
$proc = proc_open(
    [PHP_BINARY, '-d', 'opcache.enable=0', '-S', '127.0.0.1:' . $port, '-t', $root . '/public'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $tmp . '/server.out', 'w'], 2 => ['file', $tmp . '/server.err', 'w']],
    $pipes,
    null,
    $env
);
register_shutdown_function(function () use ($proc, $tmp): void {
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
    foreach (glob($tmp . '/var/*') ?: [] as $f) {
        @unlink($f);
    }
    foreach (glob($tmp . '/*') ?: [] as $f) {
        is_dir($f) ? @rmdir($f) : @unlink($f);
    }
    @rmdir($tmp);
});
$base = 'http://127.0.0.1:' . $port;

function http(string $method, string $url, string $body = '', array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $out = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, is_string($out) ? $out : '', json_decode((string) $out, true)];
}

$up = false;
for ($i = 0; $i < 50 && !$up; $i++) {
    $up = @file_get_contents($base . '/health') !== false;
    $up || usleep(100000);
}
if (!$up) {
    fwrite(STDERR, "the application did not start\n");
    exit(2);
}

// ---- helpers ----------------------------------------------------------------------
$sample = function (string $name, array $vars = []): string {
    $text = (string) file_get_contents(__DIR__ . '/bridge-samples/' . $name);
    return strtr($text, $vars);
};
$db = function () use ($tmp): PDO {
    $pdo = new PDO('sqlite:' . $tmp . '/var/bridge.sqlite3');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
};
$count = fn (string $sql, array $args = []) => (int) (function () use ($db, $sql, $args) {
    $st = $db()->prepare($sql);
    $st->execute($args);
    return $st->fetchColumn();
})();
$mails = function () use ($tmp): array {
    $f = $tmp . '/mail.jsonl';
    return is_file($f) ? array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", (string) file_get_contents($f)))) : [];
};
$shopify = function (string $topic, string $body, string $webhookId, ?string $secret = null) use ($base, $secrets): array {
    $sig = base64_encode(hash_hmac('sha256', $body, $secret ?? $secrets['shopify'], true));
    return http('POST', $base . '/hook/shopify', $body, ['Content-Type: application/json', 'X-Shopify-Topic: ' . $topic, 'X-Shopify-Webhook-Id: ' . $webhookId, 'X-Shopify-Hmac-Sha256: ' . $sig]);
};
$upmind = fn (string $body, ?string $secret = null) => http('POST', $base . '/hook/upmind', $body, ['Content-Type: application/json', 'X-Webhook-Signature: ' . hash_hmac('sha256', $body, $secret ?? $secrets['upmind'])]);
$ninja = fn (string $body, ?string $secret = null) => http('POST', $base . '/hook/invoiceninja', $body, ['Content-Type: application/json', 'X-Bridge-Secret: ' . ($secret ?? $secrets['invoiceninja'])]);
$generic = function (string $body, ?string $secret = null, ?int $ts = null) use ($base, $secrets): array {
    return http('POST', $base . '/hook/generic', $body, ['Content-Type: application/json', 'X-Bridge-Timestamp: ' . ($ts ?? time()), 'X-Bridge-Signature: sha256=' . hash_hmac('sha256', $body, $secret ?? $secrets['generic'])]);
};
$lineIds = function (string $platform, string $order) use ($db): array {
    $st = $db()->prepare('SELECT line_id FROM lines WHERE platform = ? AND order_id = ? AND line_id > 0 ORDER BY unit');
    $st->execute([$platform, $order]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
};
$statusOf = fn (int $id) => (string) (($api->getLine($id))['status'] ?? '?');
$balance = function (string $email) use ($db, $api): ?int {
    $st = $db()->prepare('SELECT user_id FROM accounts WHERE email = ?');
    $st->execute([strtolower($email)]);
    $uid = $st->fetchColumn();
    if (!$uid) {
        return null;
    }
    $u = $api->getUser((string) $uid);
    return is_array($u) && isset($u['credits']) ? (int) $u['credits'] : null;
};
$userStatus = function (string $email) use ($db, $api): string {
    $st = $db()->prepare('SELECT user_id FROM accounts WHERE email = ?');
    $st->execute([strtolower($email)]);
    $u = $api->getUser((string) $st->fetchColumn());
    return (string) ($u['status'] ?? '?');
};
$myCredits = fn () => (int) ($api->userInfo()['credits'] ?? -1);
$bridgeCli = function (string ...$args) use ($root, $configFile): array {
    $cmd = array_merge([PHP_BINARY, $root . '/bin/bridge.php'], $args);
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), ['BRIDGE_CONFIG' => $configFile]));
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($proc), $out];
};

$rnd = fn () => (string) random_int(100000000000, 999999999999);

// ============================================================================
echo "== routing and limits\n";
[$s, , $j] = http('GET', $base . '/health');
$check('GET /health', $s === 200 && ($j['ok'] ?? false) === true, $s);
[$s] = http('POST', $base . '/hook/unknown', '{}');
$check('unknown hook -> 404', $s === 404, $s);
[$s] = http('GET', $base . '/nothing/here');
$check('unknown route -> 404', $s === 404, $s);
[$s] = http('GET', $base . '/hook/shopify');
$check('GET on a hook -> 405', $s === 405, $s);
[$s] = http('POST', $base . '/hook/generic', str_repeat('x', 1048577), ['Content-Type: application/json']);
$check('body over 1 MB -> 413', $s === 413, $s);

echo "== bad signatures create nothing\n";
$email = "shop-$suffix@example.test";
$order = $rnd();
$body = $sample('shopify-orders-paid.json', ['__ORDER__' => $order, '__ITEM1__' => '11' . $order, '__ITEM2__' => '12' . $order, '__EMAIL__' => $email]);
[$s] = $shopify('orders/paid', $body, 'wh-bad-' . $suffix, 'not-the-secret');
$check('Shopify wrong signature -> 401', $s === 401, $s);
[$s] = http('POST', $base . '/hook/shopify', $body, ['X-Shopify-Topic: orders/paid']);
$check('Shopify no signature -> 401', $s === 401, $s);
$gbody = $sample('generic-paid.json', ['__EVENT__' => 'g-bad-' . $suffix, '__ORDER__' => 'GB' . $suffix, '__QTY__' => '1', '__SKU__' => 'g-line', '__EMAIL__' => "gbad-$suffix@example.test"]);
[$s] = $generic($gbody, 'wrong');
$check('generic wrong signature -> 401', $s === 401, $s);
[$s] = $generic($gbody, null, time() - 400);
$check('generic timestamp older than 5 minutes -> 401', $s === 401, $s);
[$s] = $upmind($gbody, 'wrong');
$check('Upmind wrong signature -> 401', $s === 401, $s);
[$s] = $ninja($gbody, 'wrong');
$check('Invoice Ninja wrong secret header -> 401', $s === 401, $s);
$check('nothing stored or created', $count('SELECT COUNT(*) FROM events') === 0 && $count('SELECT COUNT(*) FROM lines') === 0 && $mails() === []);

echo "== a platform without a secret is switched off\n";
$tmpRoot = $tmp . '/off';
mkdir($tmpRoot . '/public', 0700, true);
write_config($tmpRoot . '/c.php', ['secrets' => ['shopify' => '', 'upmind' => '', 'invoiceninja' => '', 'generic' => ''], 'data_dir' => $tmpRoot]);
$off = Config::load($tmpRoot . '/c.php', $tmpRoot);
$allOff = true;
foreach ([new XtreamPro\Bridge\Adapter\Shopify($off), new XtreamPro\Bridge\Adapter\Upmind($off), new XtreamPro\Bridge\Adapter\InvoiceNinja($off), new XtreamPro\Bridge\Adapter\Generic($off)] as $ad) {
    $b = '{"id":"1"}';
    $headers = ['x-shopify-hmac-sha256' => base64_encode(hash_hmac('sha256', $b, '', true)), 'x-webhook-signature' => hash_hmac('sha256', $b, ''), 'x-bridge-secret' => '', 'x-bridge-signature' => 'sha256=' . hash_hmac('sha256', $b, ''), 'x-bridge-timestamp' => (string) time()];
    $allOff = $allOff && $ad->verify($headers, $b) === false;
}
$check('no secret configured: every adapter rejects, even an empty-key signature', $allOff);

echo "== config safety\n";
chmod($configFile, 0644);
[$s] = http('GET', $base . '/health');
$check('config.php readable by others: the app refuses to run (503)', $s === 503, $s);
chmod($configFile, 0600);
[$s] = http('GET', $base . '/health');
$check('mode 0600 runs again', $s === 200, $s);
write_config($tmpRoot . '/public/config.php', []);
try {
    Config::load($tmpRoot . '/public/config.php', $tmpRoot);
    $check('config.php inside public/ is refused', false);
} catch (RuntimeException $e) {
    $check('config.php inside public/ is refused', strpos($e->getMessage(), 'public/') !== false, $e->getMessage());
}
@unlink($tmpRoot . '/public/config.php');
@unlink($tmpRoot . '/c.php');
@rmdir($tmpRoot . '/public');
@rmdir($tmpRoot);

echo "== Shopify: IPTV lines\n";
$credits0 = $myCredits();
[$s, , $j] = $shopify('orders/paid', $body, 'wh-1-' . $suffix);
$check('orders/paid -> 200, 2 events (1 provisioned, 1 unrelated item ignored)', $s === 200 && ($j['done'] ?? 0) === 2 && ($j['failed'] ?? 1) === 0, $j);
$ids = $lineIds('shopify', $order);
$check('2 lines created for quantity 2', count($ids) === 2, $ids);
$check('both lines are active on the panel', count($ids) === 2 && $statusOf($ids[0]) === 'active' && $statusOf($ids[1]) === 'active');
$m = $mails();
$check('one credentials mail to the buyer only', count($m) === 1 && $m[0]['to'] === $email, $m);
$line1 = $api->getLine($ids[0]);
$check('mail carries the panel username and password of line 1', strpos($m[0]['body'], 'Username: ' . ($line1['username'] ?? '?')) !== false && strpos($m[0]['body'], 'Password: ' . ($line1['password'] ?? '?')) !== false);
$check('mail has the play links', strpos($m[0]['body'], 'Playlist (M3U)') !== false && strpos($m[0]['body'], 'Web player') !== false);
$spent = $credits0 - $myCredits();
$check('the reseller paid for exactly 2 periods', $spent === 2 * (int) ($packages[0]['official_credits'] ?? 0) || $spent > 0, $spent);
[$s, , $j] = $shopify('orders/paid', $body, 'wh-1-' . $suffix);
$check('same delivery again -> 200, every event a duplicate', $s === 200 && ($j['duplicate'] ?? 0) === 2 && ($j['done'] ?? 1) === 0, $j);
$after = $myCredits();
[$s, , $j] = $shopify('orders/paid', $body, 'wh-2-other-delivery-' . $suffix);
$check('same order delivered under another webhook id: no new line, no charge, no mail', $s === 200 && count($lineIds('shopify', $order)) === 2 && $myCredits() === $after && count($mails()) === 1, $j);
$check('events table has one row per event id', $count('SELECT COUNT(*) FROM events WHERE platform = "shopify" AND event_id LIKE ?', ['wh-1-%']) === 2);

$refund = $sample('shopify-refunds-create.json', ['__REFUND__' => $rnd(), '__ORDER__' => $order, '__ITEM1__' => '11' . $order, '__QTY__' => '1']);
[$s, , $j] = $shopify('refunds/create', $refund, 'wh-3-' . $suffix);
$st = [$statusOf($ids[0]), $statusOf($ids[1])];
$check('partial refund (1 unit) disables exactly one line', $s === 200 && count(array_filter($st, fn ($x) => $x === 'disabled')) === 1, $st);
$cancel = $sample('shopify-orders-cancelled.json', ['__ORDER__' => $order, '__EMAIL__' => $email]);
[$s] = $shopify('orders/cancelled', $cancel, 'wh-4-' . $suffix);
$check('orders/cancelled disables the rest', $s === 200 && $statusOf($ids[0]) === 'disabled' && $statusOf($ids[1]) === 'disabled', [$statusOf($ids[0]), $statusOf($ids[1])]);

echo "== Shopify: sub-reseller account and credits\n";
$remail = "rita-$suffix@example.test";
$o1 = $rnd();
$o2 = $rnd();
$paidRes = fn (string $o) => $sample('shopify-orders-paid-reseller.json', ['__ORDER__' => $o, '__ITEM1__' => '21' . $o, '__EMAIL__' => $remail]);
[$s, , $j] = $shopify('orders/paid', $paidRes($o1), 'wh-r1-' . $suffix);
$check('reseller product paid -> 200, done', $s === 200 && ($j['done'] ?? 0) === 1 && ($j['failed'] ?? 1) === 0, $j);
$check('one account for the customer email', $count('SELECT COUNT(*) FROM accounts WHERE email = ? AND user_id IS NOT NULL', [$remail]) === 1);
$check('the account holds 100 credits on the panel', $balance($remail) === 100, $balance($remail));
$m = $mails();
$last = end($m);
$check('mail to the buyer has username, password and sign-in address', $last['to'] === $remail && strpos($last['body'], 'Username: ') !== false && strpos($last['body'], 'Password: ') !== false && strpos($last['body'], 'panel.example.test') !== false, $last);
[$s] = $shopify('orders/paid', $paidRes($o2), 'wh-r2-' . $suffix);
$check('second order tops up to 200 credits', $s === 200 && $balance($remail) === 200, $balance($remail));
$check('still exactly one account', $count('SELECT COUNT(*) FROM accounts WHERE email = ?', [$remail]) === 1);
$m = $mails();
$last = end($m);
$check('top-up mail names the credits and has no password', strpos($last['body'], 'Credits added to your account: 100') !== false && strpos($last['body'], 'Password:') === false, $last['body']);
$cancel2 = $sample('shopify-orders-cancelled.json', ['__ORDER__' => $o2, '__EMAIL__' => $remail]);
[$s] = $shopify('orders/cancelled', $cancel2, 'wh-r3-' . $suffix);
$check('cancelling the top-up order takes its 100 credits back', $s === 200 && $balance($remail) === 100, $balance($remail));
$check('the account stays active (the other order created it)', $userStatus($remail) === 'active', $userStatus($remail));
$cancel1 = $sample('shopify-orders-cancelled.json', ['__ORDER__' => $o1, '__EMAIL__' => $remail]);
[$s] = $shopify('orders/cancelled', $cancel1, 'wh-r4-' . $suffix);
$check('cancelling the first order takes the rest back and disables the account', $s === 200 && $balance($remail) === 0 && $userStatus($remail) === 'disabled', [$balance($remail), $userStatus($remail)]);
[$s] = $shopify('orders/cancelled', $cancel1, 'wh-r5-' . $suffix);
$check('revoking again takes nothing twice', $s === 200 && $balance($remail) === 0);

echo "== Upmind\n";
$uemail = "upmind-$suffix@example.test";
$contract = 'c-' . bin2hex(random_bytes(8));
$vars = ['__EVENT__' => 'ue-1-' . $suffix, '__CONTRACT__' => $contract, '__PRODUCT__' => $upLine, '__EMAIL__' => $uemail];
[$s, , $j] = $upmind($sample('upmind-contract-product-activated.json', $vars));
$ul = $lineIds('upmind', $contract);
$check('contract_product_activated_hook creates a line', $s === 200 && count($ul) === 1 && $statusOf($ul[0]) === 'active', [$s, $j]);
[, , $j] = $upmind($sample('upmind-contract-product-activated.json', $vars));
$check('same webhook_event_id again -> duplicate', ($j['duplicate'] ?? 0) === 1 && count($lineIds('upmind', $contract)) === 1, $j);
$exp0 = (int) ($api->getLine($ul[0])['exp_date'] ?? 0);
[$s] = $upmind($sample('upmind-contract-product-renewed.json', ['__EVENT__' => 'ue-2-' . $suffix] + $vars));
$exp1 = (int) ($api->getLine($ul[0])['exp_date'] ?? 0);
$check('renewed hook extends the line', $s === 200 && $exp1 > $exp0, [$exp0, $exp1]);
[$s] = $upmind($sample('upmind-contract-product-renewed.json', ['__EVENT__' => 'ue-2-' . $suffix] + $vars));
$check('the same renewal again does not extend twice', (int) ($api->getLine($ul[0])['exp_date'] ?? 0) === $exp1);
$susp = json_encode(['webhook_event_id' => 'ue-3-' . $suffix, 'hook_code' => 'contract_product_suspended_hook', 'object' => ['id' => $contract, 'product_id' => $upLine, 'client' => ['email' => $uemail]]]);
[$s] = $upmind((string) $susp);
$check('suspended hook disables the line', $s === 200 && $statusOf($ul[0]) === 'disabled');
$unsusp = json_encode(['webhook_event_id' => 'ue-4-' . $suffix, 'hook_code' => 'contract_product_unsuspended_hook', 'object' => ['id' => $contract, 'product_id' => $upLine, 'client' => ['email' => $uemail]]]);
[$s] = $upmind((string) $unsusp);
$check('unsuspended hook enables it again', $s === 200 && $statusOf($ul[0]) === 'active');
[$s] = $upmind($sample('upmind-contract-product-cancelled.json', ['__EVENT__' => 'ue-5-' . $suffix] + $vars));
$check('cancelled hook disables it', $s === 200 && $statusOf($ul[0]) === 'disabled');
$rcontract = 'c-' . bin2hex(random_bytes(8));
$remail2 = "upres-$suffix@example.test";
[$s] = $upmind($sample('upmind-contract-product-activated.json', ['__EVENT__' => 'ue-6-' . $suffix, '__CONTRACT__' => $rcontract, '__PRODUCT__' => $upRes, '__EMAIL__' => $remail2]));
$check('reseller product: account with 50 credits', $s === 200 && $balance($remail2) === 50, $balance($remail2));
$rsusp = json_encode(['webhook_event_id' => 'ue-7-' . $suffix, 'hook_code' => 'contract_product_suspended_hook', 'object' => ['id' => $rcontract, 'client' => ['email' => $remail2]]]);
[$s] = $upmind((string) $rsusp);
$check('suspending disables the account but keeps its credits', $userStatus($remail2) === 'disabled' && $balance($remail2) === 50, [$userStatus($remail2), $balance($remail2)]);
[$s] = $upmind(str_replace('contract_product_activated_hook', 'contract_product_renewed_hook', $sample('upmind-contract-product-activated.json', ['__EVENT__' => 'ue-8-' . $suffix, '__CONTRACT__' => $rcontract, '__PRODUCT__' => $upRes, '__EMAIL__' => $remail2])));
$check('reseller renewal gives the renewal credits (50 + 20)', $s === 200 && $balance($remail2) === 70, $balance($remail2));

echo "== Invoice Ninja\n";
$nemail = "ninja-$suffix@example.test";
$inv = 'inv' . bin2hex(random_bytes(5));
$paid = $sample('invoiceninja-invoice-paid.json', ['__INVOICE__' => $inv, '__EMAIL__' => $nemail]);
[$s, , $j] = $ninja($paid);
$nl = $lineIds('invoiceninja', $inv);
$check('paid invoice: 2 lines for quantity 2, the free-text line is skipped', $s === 200 && count($nl) === 2 && ($j['events'] ?? 0) === 1, [$s, $j, $nl]);
[, , $j] = $ninja($paid);
$check('invoice sent again -> duplicate', ($j['duplicate'] ?? 0) === 1 && count($lineIds('invoiceninja', $inv)) === 2, $j);
$mm = array_values(array_filter($mails(), fn ($x) => $x['to'] === $nemail));
$check('credentials mail to the invoice contact', count($mm) === 1 && substr_count($mm[0]['body'], 'Username:') === 2);
[$s] = $ninja($sample('invoiceninja-invoice-cancelled.json', ['__INVOICE__' => $inv]));
$check('cancelled invoice disables the lines', $s === 200 && $statusOf($nl[0]) === 'disabled' && $statusOf($nl[1]) === 'disabled');

echo "== generic\n";
$gemail = "gen-$suffix@example.test";
$gorder = 'G' . $suffix;
$gp = $sample('generic-paid.json', ['__EVENT__' => 'g-1-' . $suffix, '__ORDER__' => $gorder, '__QTY__' => '1', '__SKU__' => 'g-line', '__EMAIL__' => $gemail]);
[$s, , $j] = $generic($gp);
$gl = $lineIds('generic', $gorder);
$check('paid creates a line', $s === 200 && count($gl) === 1 && $statusOf($gl[0]) === 'active', [$s, $j]);
[$s] = $generic(str_replace('"paid"', '"revoked"', str_replace('g-1-', 'g-2-', $gp)));
$check('revoked disables it', $s === 200 && $statusOf($gl[0]) === 'disabled');
[$s] = $generic(str_replace('"paid"', '"restored"', str_replace('g-1-', 'g-3-', $gp)));
$check('restored enables it', $s === 200 && $statusOf($gl[0]) === 'active');
$gr = 'GR' . $suffix;
$gremail = "genres-$suffix@example.test";
[$s] = $generic($sample('generic-paid.json', ['__EVENT__' => 'g-4-' . $suffix, '__ORDER__' => $gr, '__QTY__' => '2', '__SKU__' => 'g-reseller', '__EMAIL__' => $gremail]));
$check('reseller product with quantity 2 gives 80 credits', $s === 200 && $balance($gremail) === 80, $balance($gremail));
$rev = json_encode(['event' => 'revoked', 'id' => 'g-5-' . $suffix, 'order_id' => $gr, 'item_id' => '1', 'customer' => ['email' => $gremail], 'take_back' => true]);
[$s] = $generic((string) $rev);
$check('revoke takes the credits back', $s === 200 && $balance($gremail) === 0, $balance($gremail));
[$s, , $j] = $generic($sample('generic-paid.json', ['__EVENT__' => 'g-6-' . $suffix, '__ORDER__' => 'GU' . $suffix, '__QTY__' => '1', '__SKU__' => 'not-mapped', '__EMAIL__' => $gemail]));
$check('an unmapped product is acknowledged and ignored', $s === 200 && ($j['failed'] ?? 1) === 0 && $count('SELECT COUNT(*) FROM lines WHERE order_id = ?', ['GU' . $suffix]) === 0);

echo "== failure is stored, then retried\n";
write_config($configFile, ['api_key' => 'xk_wrong_' . $suffix]);
$forder = 'GF' . $suffix;
$femail = "fail-$suffix@example.test";
$mailsBefore = count($mails());
[$s, $raw, $j] = $generic($sample('generic-paid.json', ['__EVENT__' => 'g-7-' . $suffix, '__ORDER__' => $forder, '__QTY__' => '1', '__SKU__' => 'g-line', '__EMAIL__' => $femail]));
$ev = $db()->query('SELECT status, error FROM events WHERE event_id = "g-7-' . $suffix . '"')->fetch();
$check('wrong API key -> still 200', $s === 200 && ($j['failed'] ?? 0) === 1, [$s, $j]);
$check('response does not leak the error or any secret', strpos($raw, 'API key') === false && strpos($raw, 'xk_wrong') === false, $raw);
$check('failure stored with a readable reason', ($ev['status'] ?? '') === 'failed' && stripos((string) ($ev['error'] ?? ''), 'API key') !== false, $ev);
$check('nothing created, no mail', count($mails()) === $mailsBefore && count($lineIds('generic', $forder)) === 0);
[$code, $out] = $bridgeCli('retry');
$check('retry while the key is still wrong keeps failing (exit 1)', $code === 1, $out);
write_config($configFile, []);
[$code, $out] = $bridgeCli('retry');
$fl = $lineIds('generic', $forder);
$ev = $db()->query('SELECT status FROM events WHERE event_id = "g-7-' . $suffix . '"')->fetch();
$check('retry after fixing the key creates the line and mails the buyer', $code === 0 && count($fl) === 1 && ($ev['status'] ?? '') === 'done' && count($mails()) === $mailsBefore + 1, [$code, $out]);
[$code, $out] = $bridgeCli('replay', 'g-7-' . $suffix);
$check('replay of a finished event is safe: nothing new', $code === 0 && count($lineIds('generic', $forder)) === 1 && count($mails()) === $mailsBefore + 1, $out);

echo "== 1.1.0: sells, pricing, connector name, spent request id\n";
$check('version 1.1.0 in the entry file, the CLI and the API client (X-Connector)', ApiClient::VERSION === '1.1.0'
    && strpos((string) file_get_contents($root . '/public/index.php'), "BRIDGE_VERSION = '1.1.0'") !== false
    && strpos((string) file_get_contents($root . '/bin/bridge.php'), '1.1.0') !== false);
$boxId = 0;
foreach ($packages as $p) {
    if (isset($p['sells']) && !in_array('line', $p['sells'], true)) {
        $boxId = (int) $p['id'];
        break;
    }
}
$check('the panel marks a box-only package with sells without "line"', $boxId > 0, $packages);
$check('a panel that sends no sells counts as selling lines', ApiClient::sellsLine(['id' => 1]) && !ApiClient::sellsLine(['sells' => ['mag']]));
write_config($configFile, ['products' => ['generic' => ['g-box' => ['type' => 'line', 'package_id' => $boxId]]]]);
$border = 'GB' . $suffix;
$bemail = "box-$suffix@example.test";
$cBefore = $myCredits();
[$s, , $j] = $generic($sample('generic-paid.json', ['__EVENT__' => 'g-8-' . $suffix, '__ORDER__' => $border, '__QTY__' => '1', '__SKU__' => 'g-box', '__EMAIL__' => $bemail]));
$ev = $db()->query('SELECT status, error FROM events WHERE event_id = "g-8-' . $suffix . '"')->fetch();
$check('a box-only package is refused before anything is sold: stored as failed with a readable reason, nothing created', $s === 200 && ($ev['status'] ?? '') === 'failed' && stripos((string) ($ev['error'] ?? ''), 'boxes only') !== false && count($lineIds('generic', $border)) === 0 && $myCredits() === $cBefore, [$s, $ev]);
write_config($configFile, []);
$names = array_column($api->get('api_logs', ['limit' => 50])['data'] ?? [], 'connector');
$check('the panel call log names the connector', in_array('bridge/1.1.0', $names, true), array_unique($names));
$sorder = 'GS' . $suffix;
$semail = "spent-$suffix@example.test";
$generic($sample('generic-paid.json', ['__EVENT__' => 'g-9-' . $suffix, '__ORDER__' => $sorder, '__QTY__' => '1', '__SKU__' => 'g-line', '__EMAIL__' => $semail]));
$sl = $lineIds('generic', $sorder);
$check('a line for the spent-id test', count($sl) === 1, $sl);
$api->lineAction('delete_line', $sl[0]);
$db()->prepare('DELETE FROM lines WHERE platform = ? AND order_id = ?')->execute(['generic', $sorder]);
$generic($sample('generic-paid.json', ['__EVENT__' => 'g-10-' . $suffix, '__ORDER__' => $sorder, '__QTY__' => '1', '__SKU__' => 'g-line', '__EMAIL__' => $semail]));
$ev = $db()->query('SELECT status, error FROM events WHERE event_id = "g-10-' . $suffix . '"')->fetch();
$check('selling again under the request id of a line deleted on the panel is stored as a readable message (REQUEST_ID_SPENT)', ($ev['status'] ?? '') === 'failed' && stripos((string) ($ev['error'] ?? ''), 'already made') !== false && strpos((string) ($ev['error'] ?? ''), 'REQUEST_ID') === false, $ev);

// These two events were failures on purpose; `check` below reports failed events, so clear them.
$db()->prepare('DELETE FROM events WHERE event_id IN (?, ?)')->execute(['g-8-' . $suffix, 'g-10-' . $suffix]);

echo "== command line\n";
[$code, $out] = $bridgeCli('check');
$check('check: config, connection and packages', $code === 0 && strpos($out, 'connected to') !== false && strpos($out, 'package(s)') !== false, $out);
[$code, $out] = $bridgeCli('map');
$check('map prints the product map', $code === 0 && strpos($out, $skuLine) !== false && strpos($out, 'sub-reseller account, 100 credits') !== false, $out);
[$code] = $bridgeCli('bogus');
$check('unknown command -> exit 2', $code === 2);

echo "== no secret in the logs\n";
$leak = '';
$haystacks = [];
foreach ([$tmp . '/var/bridge.log', $tmp . '/server.out', $tmp . '/server.err'] as $f) {
    $haystacks[$f] = is_file($f) ? (string) file_get_contents($f) : '';
}
$passwords = [];
foreach ($mails() as $mm) {
    if (preg_match_all('/Password: (\S+)/', $mm['body'], $pm)) {
        $passwords = array_merge($passwords, $pm[1]);
    }
}
foreach ($haystacks as $f => $text) {
    foreach (array_merge([$apiKey, 'xk_wrong_' . $suffix], array_values($secrets), $passwords) as $secret) {
        if ($secret !== '' && strpos($text, $secret) !== false) {
            $leak = basename($f) . ' contains a secret or password';
        }
    }
}
$check('API key, webhook secrets and line passwords are in no log or server output', $leak === '', $leak);
$check('the log is not empty (events were logged)', strlen($haystacks[$tmp . '/var/bridge.log']) > 100);
$perm = substr(sprintf('%o', fileperms($tmp . '/var/bridge.sqlite3')), -4);
$check('the database file is private (0600)', $perm === '0600', $perm);

echo $fail ? "\n$fail FAILED\n" : "\nALL OK\n";
exit($fail ? 1 : 0);
