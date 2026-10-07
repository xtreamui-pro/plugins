<?php
/**
 * Command line of the Xtream UI Pro webhook bridge. Version 1.1.0.
 *
 *   php bin/bridge.php check            config, panel connection, packages, secrets
 *   php bin/bridge.php retry            run failed events again, send queued mails
 *   php bin/bridge.php map              print the product map
 *   php bin/bridge.php replay <id>      run one stored event again (safe: the panel
 *                                       answers repeated requests with the first result)
 *
 * Use the same system user as the web server, so var/ stays writable for both.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';

use XtreamPro\Bridge\ApiException;
use XtreamPro\Bridge\App;

$command = $argv[1] ?? '';
if (!in_array($command, ['check', 'retry', 'map', 'replay'], true)) {
    fwrite(STDERR, "Usage: php bin/bridge.php check | retry | map | replay <event id>\n");
    exit(2);
}

try {
    $app = new App($root);
} catch (Throwable $e) {
    fwrite(STDERR, 'Cannot start: ' . $e->getMessage() . "\n");
    exit(1);
}

switch ($command) {
    case 'check':
        exit(bridge_check($app));
    case 'map':
        bridge_map($app);
        exit(0);
    case 'retry':
        $r = $app->retryFailed();
        echo "Retried {$r['retried']} event(s): {$r['done']} done, {$r['failed']} still failing. Mails sent: {$r['mails']}.\n";
        exit($r['failed'] > 0 ? 1 : 0);
    case 'replay':
        $id = $argv[2] ?? '';
        if ($id === '') {
            fwrite(STDERR, "Usage: php bin/bridge.php replay <event id>\n");
            exit(2);
        }
        $found = 0;
        $failed = 0;
        foreach (array_keys($app->adapters()) as $platform) {
            $row = $app->store->event($platform, $id);
            if ($row) {
                $found++;
                $result = $app->rerun($row);
                $failed += $result === 'failed' ? 1 : 0;
                $after = $app->store->event($platform, $id);
                echo "$platform: $result" . (!empty($after['error']) ? ' - ' . $after['error'] : '') . "\n";
            }
        }
        if ($found === 0) {
            fwrite(STDERR, "No stored event with that id.\n");
            exit(1);
        }
        exit($failed > 0 ? 1 : 0);
}

function bridge_check(App $app): int
{
    $problems = 0;
    $ok = static function (string $msg): void {
        echo "  ok    $msg\n";
    };
    $bad = static function (string $msg) use (&$problems): void {
        echo "  FAIL  $msg\n";
        $problems++;
    };

    echo "Configuration\n";
    $ok('config.php loaded (not readable by others, not inside public/)');
    foreach ($app->adapters() as $name => $adapter) {
        $has = $app->config->string('secrets.' . $name) !== '';
        $has ? $ok("$name: webhook secret set") : print("  --    $name: no secret, /hook/$name answers 401 to everything\n");
    }

    echo "Panel\n";
    try {
        $info = $app->api->userInfo();
        $ok('connected to ' . $app->api->baseUrl() . (is_array($info) && isset($info['username']) ? ' as ' . $info['username'] : ''));
        $packages = $app->api->packages();
        $byId = [];
        foreach ($packages as $p) {
            if (is_array($p) && isset($p['id'])) {
                // A package the panel says is for MAG / Enigma boxes only cannot be mapped to a line product.
                $byId[(int) $p['id']] = (string) ($p['name'] ?? '') . (\XtreamPro\Bridge\ApiClient::sellsLine($p) ? '' : ' (boxes only: not for lines)');
            }
        }
        $ok(count($byId) . ' package(s):');
        foreach ($byId as $id => $name) {
            echo "          #$id $name\n";
        }
        foreach (['shopify', 'upmind', 'invoiceninja', 'generic'] as $platform) {
            foreach ($app->config->products($platform) as $key => $cfg) {
                $type = is_array($cfg) && ($cfg['type'] ?? 'line') === 'reseller' ? 'reseller' : 'line';
                if ($type === 'line' && !isset($byId[(int) ($cfg['package_id'] ?? 0)])) {
                    $bad("$platform product \"$key\": package_id " . (int) ($cfg['package_id'] ?? 0) . ' is not available to this reseller');
                }
            }
        }
    } catch (ApiException $e) {
        $bad($e->getMessage());
    }

    echo "Bridge state\n";
    $failed = $app->store->failedCount();
    $failed > 0 ? $bad("$failed failed event(s): run `php bin/bridge.php retry`") : $ok('no failed events');
    $mails = count($app->store->pendingMails());
    $mails > 0 ? $bad("$mails credentials mail(s) not delivered yet: run `php bin/bridge.php retry`") : $ok('no undelivered mails');

    echo $problems === 0 ? "All good.\n" : "$problems problem(s).\n";
    return $problems === 0 ? 0 : 1;
}

function bridge_map(App $app): void
{
    foreach (['shopify', 'upmind', 'invoiceninja', 'generic'] as $platform) {
        $map = $app->config->products($platform);
        echo "$platform (" . count($map) . " product(s))\n";
        foreach ($map as $key => $cfg) {
            if (!is_array($cfg)) {
                continue;
            }
            if (($cfg['type'] ?? 'line') === 'reseller') {
                echo sprintf("  %-28s sub-reseller account, %d credits, %d per renewal\n", $key, (int) ($cfg['credits'] ?? 0), (int) ($cfg['renew_credits'] ?? 0));
            } else {
                echo sprintf("  %-28s IPTV line, package #%d%s\n", $key, (int) ($cfg['package_id'] ?? 0), !empty($cfg['trial']) ? ' (trial)' : '');
            }
        }
    }
}
