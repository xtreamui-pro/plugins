<?php
/**
 * Wires the bridge together and handles one webhook request. Used by
 * public/index.php (web) and bin/bridge.php (command line).
 */

declare(strict_types=1);

namespace XtreamPro\Bridge;

use XtreamPro\Bridge\Adapter\Adapter;
use XtreamPro\Bridge\Adapter\Generic;
use XtreamPro\Bridge\Adapter\InvoiceNinja;
use XtreamPro\Bridge\Adapter\Shopify;
use XtreamPro\Bridge\Adapter\Upmind;

class App
{
    public const MAX_BODY = 1048576; // 1 MB

    public Config $config;
    public Logger $log;
    public Store $store;
    public ApiClient $api;
    public Provisioner $provisioner;
    /** @var array<string,Adapter> */
    private array $adapters = [];
    private string $lockFile;

    /**
     * @param string|null $configPath config.php to use (default: BRIDGE_CONFIG env, else <root>/config.php)
     * @throws \RuntimeException when the configuration is missing or unsafe
     */
    public function __construct(string $root, ?string $configPath = null)
    {
        $path = $configPath ?? (getenv('BRIDGE_CONFIG') ?: $root . '/config.php');
        $this->config = Config::load($path, $root);
        $dir = $this->config->dataDir();
        $this->log = new Logger($dir . '/bridge.log', $this->config->secrets());
        $this->store = new Store($dir . '/bridge.sqlite3');
        $this->api = new ApiClient($this->config->string('api_url'), $this->config->string('api_key'));
        $this->provisioner = new Provisioner(
            $this->api,
            $this->store,
            new Mailer($this->config),
            $this->log,
            [
                'shopify'      => $this->config->products('shopify'),
                'upmind'       => $this->config->products('upmind'),
                'invoiceninja' => $this->config->products('invoiceninja'),
                'generic'      => $this->config->products('generic'),
            ],
            $this->config->string('panel_url')
        );
        foreach ([new Shopify($this->config), new Upmind($this->config), new InvoiceNinja($this->config), new Generic($this->config)] as $adapter) {
            $this->adapters[$adapter->name()] = $adapter;
        }
        $this->lockFile = $dir . '/provision.lock';
    }

    /** @return array<string,Adapter> */
    public function adapters(): array
    {
        return $this->adapters;
    }

    /** Header array ("lower-case name" => value) from PHP's $_SERVER. */
    public static function headersFromServer(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (strncmp($key, 'HTTP_', 5) === 0) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $key => $name) {
            if (isset($server[$key])) {
                $headers[$name] = (string) $server[$key];
            }
        }
        return $headers;
    }

    /**
     * @param array<string,string> $headers lower-case names
     * @return array{0:int,1:array} HTTP status and JSON body
     */
    public function handleRequest(string $method, string $path, array $headers, string $rawBody): array
    {
        $path = '/' . trim((string) parse_url($path, PHP_URL_PATH), '/');

        if ($path === '/health') {
            return $method === 'GET' ? [200, ['ok' => true]] : [405, ['ok' => false, 'error' => 'method not allowed']];
        }
        if (strncmp($path, '/hook/', 6) !== 0 || !isset($this->adapters[substr($path, 6)])) {
            return [404, ['ok' => false, 'error' => 'not found']];
        }
        if ($method !== 'POST') {
            return [405, ['ok' => false, 'error' => 'method not allowed']];
        }
        $adapter = $this->adapters[substr($path, 6)];

        if (strlen($rawBody) > self::MAX_BODY) {
            return [413, ['ok' => false, 'error' => 'body too large']];
        }
        // Signature first, on the raw bytes, before anything is parsed.
        if (!$adapter->verify($headers, $rawBody)) {
            $this->log->warn('rejected a ' . $adapter->name() . ' webhook: signature missing or wrong, or no secret configured for it');
            return [401, ['ok' => false, 'error' => 'unauthorized']];
        }
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return [400, ['ok' => false, 'error' => 'invalid JSON']];
        }

        $events = $adapter->events($payload, $headers);
        $result = ['done' => 0, 'failed' => 0, 'duplicate' => 0];
        foreach ($events as $event) {
            $result[$this->processEvent($adapter->name(), $event)]++;
        }
        // Always 200 for an authentic event: a failure is stored and retried
        // by `bridge.php retry`; platforms disable endpoints that keep failing.
        return [200, ['ok' => true, 'events' => count($events)] + $result];
    }

    /**
     * Process an event at most once. Returns done | failed | duplicate.
     */
    public function processEvent(string $platform, array $event): string
    {
        $event['platform'] = $platform;
        if ($event['id'] === '' || !$this->store->claimEvent($platform, $event['id'], $event)) {
            return 'duplicate';
        }
        return $this->run($platform, $event);
    }

    /** Run the provisioner for an event row already registered; records the outcome. */
    private function run(string $platform, array $event): string
    {
        $tag = $platform . ' ' . $event['id'];
        $lock = fopen($this->lockFile, 'c');
        if ($lock !== false) {
            flock($lock, LOCK_EX); // one event at a time: no two orders race for the same customer account
        }
        try {
            $note = $this->provisioner->handle($platform, $event);
            $this->store->finishEvent($platform, $event['id'], 'done', $note);
            $this->log->info("event $tag done: $note");
            return 'done';
        } catch (ApiException | ProvisionException $e) {
            $this->store->finishEvent($platform, $event['id'], 'failed', $e->getMessage());
            $this->log->error("event $tag failed: " . $e->getMessage());
            return 'failed';
        } catch (\Throwable $e) {
            // Unexpected: keep the details in the log only, never in a response.
            $this->store->finishEvent($platform, $event['id'], 'failed', 'Unexpected error, see var/bridge.log.');
            $this->log->error("event $tag failed: " . get_class($e) . ': ' . $e->getMessage());
            return 'failed';
        } finally {
            if ($lock !== false) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * Try the failed events (and undelivered mails) again.
     *
     * @return array{retried:int,done:int,failed:int,mails:int}
     */
    public function retryFailed(): array
    {
        $out = ['retried' => 0, 'done' => 0, 'failed' => 0, 'mails' => 0];
        foreach ($this->store->retryable() as $row) {
            $out['retried']++;
            $out[$this->rerun($row)]++;
        }
        $out['mails'] = $this->provisioner->flushMails();
        return $out;
    }

    /** Run a stored event row again (retry and replay). Returns done | failed. */
    public function rerun(array $row): string
    {
        $event = json_decode((string) $row['event'], true);
        if (!is_array($event)) {
            $this->store->finishEvent($row['platform'], $row['event_id'], 'failed', 'The stored event is unreadable.');
            return 'failed';
        }
        $this->store->reopenEvent($row['platform'], $row['event_id']);
        return $this->run($row['platform'], $event);
    }
}
