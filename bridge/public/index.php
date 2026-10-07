<?php
/**
 * Xtream UI Pro webhook bridge - the only web-exposed file.
 *
 *   POST /hook/shopify | /hook/upmind | /hook/invoiceninja | /hook/generic
 *   GET  /health
 *
 * Everything else lives outside public/. Version 1.1.0.
 */

declare(strict_types=1);

const BRIDGE_VERSION = '1.1.0';

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';

use XtreamPro\Bridge\App;

function bridge_reply(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body);
}

// Cheap checks before anything is loaded.
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
if (strpos((string) parse_url($uri, PHP_URL_PATH), '/hook/') === 0 && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > App::MAX_BODY) {
    bridge_reply(413, ['ok' => false, 'error' => 'body too large']);
    exit;
}

try {
    $app = new App($root);
} catch (Throwable $e) {
    // The reason goes to the PHP error log (it holds no secret), not to the caller.
    error_log('xtreampro-bridge: ' . $e->getMessage());
    bridge_reply(503, ['ok' => false, 'error' => 'bridge not available']);
    exit;
}

// At most MAX_BODY + 1 bytes are read, whatever the client claims.
$raw = $method === 'POST' ? (string) stream_get_contents(fopen('php://input', 'rb'), App::MAX_BODY + 1) : '';

try {
    [$status, $body] = $app->handleRequest($method, $uri, App::headersFromServer($_SERVER), $raw);
} catch (Throwable $e) {
    $app->log->error('request failed: ' . get_class($e) . ': ' . $e->getMessage());
    [$status, $body] = [500, ['ok' => false, 'error' => 'internal error']];
}
bridge_reply($status, $body);
