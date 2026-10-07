<?php

declare(strict_types=1);
/**
 * Xtream UI Pro - FOSSBilling service module.
 *
 * Each FOSSBilling order of a product of the type "Xtreampro" is one IPTV line
 * (or, with service_type=reseller, one sub-reseller account) created through
 * the panel's Reseller API. Create and renew are charged to the reseller's
 * credits in the panel.
 *
 * FOSSBilling calls create, activate, renew, suspend, unsuspend, cancel,
 * uncancel and delete on this class (the service module contract). The
 * connection settings (panel URL, API key) are saved on Extensions > Xtream UI
 * Pro and stored encrypted by FOSSBilling.
 *
 * @version 1.1.0
 */

namespace Box\Mod\Servicextreampro;

use FOSSBilling\Config;
use FOSSBilling\InformationException;

class Service implements \FOSSBilling\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function getModulePermissions(): array
    {
        return [
            'manage_settings' => [],
        ];
    }

    /** Own table; kept when the module is removed (it records which panel line belongs to which order). */
    public function install(): bool
    {
        $this->di['dbal']->executeStatement('
            CREATE TABLE IF NOT EXISTS `service_xtreampro` (
                `id` bigint(20) NOT NULL AUTO_INCREMENT,
                `client_id` bigint(20) DEFAULT NULL,
                `service_type` varchar(10) NOT NULL DEFAULT \'line\',
                `package_id` int(11) NOT NULL DEFAULT 0,
                `trial` tinyint(1) NOT NULL DEFAULT 0,
                `delete_on_cancel` tinyint(1) NOT NULL DEFAULT 0,
                `credits_on_creation` int(11) NOT NULL DEFAULT 0,
                `credits_per_renewal` int(11) NOT NULL DEFAULT 0,
                `line_id` bigint(20) NOT NULL DEFAULT 0,
                `user_id` varchar(36) DEFAULT NULL,
                `username` varchar(64) DEFAULT NULL,
                `password_enc` text DEFAULT NULL,
                `generation` int(11) NOT NULL DEFAULT 0,
                `created_at` varchar(35) DEFAULT NULL,
                `updated_at` varchar(35) DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `client_id_idx` (`client_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');

        return true;
    }

    // ------------------------------------------------------------------
    // Service module contract (called by the Order module)
    // ------------------------------------------------------------------

    /** Product options are frozen on the service, so editing the product later does not change running orders. */
    public function create($order, $service = null)
    {
        $opts = $this->productOptions((int) $order->product_id);
        if ($opts['service_type'] === 'line' && $opts['package_id'] <= 0) {
            throw new InformationException('No package is selected for this product. Choose one on the product\'s Configuration tab.');
        }

        $model = $this->di['db']->dispense('service_xtreampro');
        $model->client_id = $order->client_id;
        $model->service_type = $opts['service_type'];
        $model->package_id = $opts['package_id'];
        $model->trial = $opts['trial'] ? 1 : 0;
        $model->delete_on_cancel = $opts['delete_on_cancel'] ? 1 : 0;
        $model->credits_on_creation = $opts['credits_on_creation'];
        $model->credits_per_renewal = $opts['credits_per_renewal'];
        $model->line_id = 0;
        $model->generation = 0;
        $model->created_at = date('Y-m-d H:i:s');
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);

        return $model;
    }

    public function activate($order, $service = null): bool
    {
        $this->provision($order, $this->requireService($service));

        return true;
    }

    public function renew($order, $service = null): bool
    {
        $model = $this->requireService($service);
        $client = $this->client();
        $orderId = (int) $order->id;
        // Renewal runs before FOSSBilling moves expires_at, so the current expiry
        // identifies this renewal: a retry sends the same request id.
        $stamp = $this->dateStamp($order->expires_at ?? null);
        $suspended = ($order->status ?? '') === 'suspended';

        if ($model->service_type === 'reseller') {
            $userId = $this->requireUserId($model);
            if ((int) $model->credits_per_renewal > 0) {
                $client->assertCanGiveCredits((int) $model->credits_per_renewal);
                $client->adjustCredits($userId, (int) $model->credits_per_renewal, 'FOSSBilling renewal, order #' . $orderId, $this->requestId('s', $orderId, $stamp));
            }
            if ($suspended) {
                $client->subUserAction('enable_user', $userId);
            }

            return true;
        }

        $lineId = $this->requireLineId($model);
        if ((int) $model->package_id > 0) {
            // Fails with the amounts when the balance cannot pay the period; nothing is sold then.
            $client->assertCanSellPackage((int) $model->package_id, false, false);
        }
        $client->renewLine($lineId, $this->requestId('r', $orderId, $stamp));
        // An unpaid (suspended) line is only disabled in the panel: renewing the order enables it again.
        if ($suspended) {
            $client->lineAction('enable_line', $lineId);
        }
        $this->di['logger']->info('Xtream UI Pro: renewed line %s for order #%s', $lineId, $orderId);

        return true;
    }

    public function suspend($order, $service = null): bool
    {
        $model = $this->requireService($service);
        if ($model->service_type === 'reseller') {
            $this->client()->subUserAction('disable_user', $this->requireUserId($model));
        } else {
            $this->client()->lineAction('disable_line', $this->requireLineId($model));
        }

        return true;
    }

    public function unsuspend($order, $service = null): bool
    {
        $model = $this->requireService($service);
        if ($model->service_type === 'reseller') {
            $this->client()->subUserAction('enable_user', $this->requireUserId($model));
        } else {
            $this->client()->lineAction('enable_line', $this->requireLineId($model));
        }

        return true;
    }

    public function cancel($order, $service = null): bool
    {
        $this->terminate($order, $this->requireService($service));

        return true;
    }

    /** The order is active again: bring the line back, or sell a new one when it was deleted. */
    public function uncancel($order, $service = null): bool
    {
        $model = $this->requireService($service);
        if ($model->service_type === 'reseller') {
            $this->client()->subUserAction('enable_user', $this->requireUserId($model));
        } elseif ((int) $model->line_id > 0) {
            $this->client()->lineAction('enable_line', (int) $model->line_id);
        } else {
            $this->provision($order, $model);
        }

        return true;
    }

    /** An admin deleted the order: end the service like a cancellation (unless it already is one). */
    public function delete($order, $service = null): bool
    {
        if ($service !== null && ($order->status ?? '') !== 'canceled') {
            $this->terminate($order, $service);
        }
        if ($service !== null) {
            $this->di['db']->trash($service);
        }

        return true;
    }

    /** Database-only view of the service (no secrets); the live data comes from details(). */
    public function toApiArray($model, $deep = false, $identity = null): array
    {
        return [
            'id' => (int) $model->id,
            'client_id' => (int) $model->client_id,
            'service_type' => (string) $model->service_type,
            'package_id' => (int) $model->package_id,
            'line_id' => (int) $model->line_id,
            'user_id' => (string) $model->user_id,
            'username' => (string) $model->username,
            'created_at' => $model->created_at,
            'updated_at' => $model->updated_at,
        ];
    }

    // ------------------------------------------------------------------
    // Customer / admin view
    // ------------------------------------------------------------------

    /**
     * Live data of an order's line or sub-reseller account. With $clientId the
     * order must belong to that client and be active (the customer area). A
     * panel error is returned as text in `error`, never thrown.
     */
    public function detailsForOrder(int $orderId, ?int $clientId = null): array
    {
        if ($clientId !== null) {
            $order = $this->di['db']->findOne('ClientOrder', 'id = :id AND client_id = :client', [':id' => $orderId, ':client' => $clientId]);
            if (!$order instanceof \Model_ClientOrder) {
                throw new InformationException('Order not found');
            }
            if ($order->status !== \Model_ClientOrder::STATUS_ACTIVE) {
                throw new InformationException('Order is not activated');
            }
        } else {
            $order = $this->di['db']->getExistingModelById('ClientOrder', $orderId, 'Order not found');
        }
        $model = $this->di['mod_service']('order')->getOrderService($order);
        if (!is_object($model) || !isset($model->service_type)) {
            throw new InformationException('Order has no Xtream UI Pro service');
        }

        $out = ['type' => (string) $model->service_type, 'error' => ''];
        try {
            $client = $this->client();
            if ($model->service_type === 'reseller') {
                $user = ((string) $model->user_id !== '') ? $client->getUser((string) $model->user_id) : null;
                if (!is_array($user)) {
                    throw new InformationException('The account is not available yet.');
                }
                $out['username'] = (string) ($user['username'] ?? $model->username);
                $out['password'] = $this->storedPassword($model);
                $out['status'] = (string) ($user['status'] ?? '');
                $out['credits'] = (string) ($user['credits'] ?? '');

                return $out;
            }

            if ((int) $model->line_id <= 0) {
                throw new InformationException('The line is not available yet.');
            }
            $line = $client->getLine((int) $model->line_id);
            if (!is_array($line)) {
                throw new ApiException('BAD_RESPONSE');
            }
            $out['username'] = (string) ($line['username'] ?? '');
            $out['password'] = (string) ($line['password'] ?? '');
            $out['status'] = (string) ($line['status'] ?? '');
            $exp = $line['exp_date'] ?? null;
            $out['expiry'] = ($exp === null || $exp === '' || (int) $exp <= 0) ? 'Never expires' : gmdate('Y-m-d H:i', (int) $exp) . ' UTC';
            $out['max_connections'] = (string) ($line['max_connections'] ?? '');
            $links = is_array($line['links'] ?? null) ? $line['links'] : [];
            $out['links'] = [];
            foreach (['server', 'm3u', 'm3u_hls', 'xmltv', 'player_api', 'web_player'] as $name) {
                if (!empty($links[$name]) && is_string($links[$name])) {
                    $out['links'][$name] = $links[$name];
                }
            }
        } catch (\Exception $e) {
            $out['error'] = $e->getMessage();
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Settings and panel status (admin page)
    // ------------------------------------------------------------------

    /** Stored settings, never including the API key itself. */
    public function getSettings(): array
    {
        $cfg = $this->di['mod_service']('extension')->getConfig('mod_servicextreampro');

        return [
            'panel_url' => (string) ($cfg['panel_url'] ?? ''),
            'api_key_set' => !empty($cfg['api_key']),
        ];
    }

    /** An empty API key keeps the stored one. */
    public function saveSettings(string $panelUrl, string $apiKey): bool
    {
        $panelUrl = rtrim(trim($panelUrl), '/');
        $parts = parse_url($panelUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new InformationException('The panel URL must start with http:// or https://, for example https://api.example.com');
        }
        if (isset($parts['path']) && trim($parts['path'], '/') !== '') {
            throw new InformationException('Enter only the address of the panel API (scheme, host and port), without a path.');
        }

        $extension = $this->di['mod_service']('extension');
        $cfg = $extension->getConfig('mod_servicextreampro');
        $cfg['panel_url'] = $panelUrl;
        if (trim($apiKey) !== '') {
            $cfg['api_key'] = trim($apiKey);
        }
        if (empty($cfg['instance'])) {
            // Part of every request id: two FOSSBilling installs sharing one reseller never collide.
            $cfg['instance'] = bin2hex(random_bytes(4));
        }
        $cfg['ext'] = 'mod_servicextreampro';

        return $extension->setConfig($cfg);
    }

    /**
     * Live look at the panel for the admin pages; never throws.
     *
     * @return array{ok: bool, error: string, username: string, credits: string, packages: array}
     */
    public function getStatus(): array
    {
        $status = ['ok' => false, 'error' => '', 'username' => '', 'credits' => '', 'packages' => []];
        $cfg = $this->di['mod_service']('extension')->getConfig('mod_servicextreampro');
        if (empty($cfg['panel_url']) || empty($cfg['api_key'])) {
            return $status;
        }
        try {
            $client = $this->client();
            $info = $client->userInfo();
            $status['username'] = (string) ($info['username'] ?? '');
            $status['credits'] = (string) ($info['credits'] ?? '');
            foreach ($client->packages() as $pkg) {
                // A package for MAG / Enigma boxes only (`sells` without "line") cannot be sold as a line.
                if (!is_array($pkg) || !isset($pkg['id']) || !ApiClient::sellsLine($pkg)) {
                    continue;
                }
                $status['packages'][] = [
                    'id' => (int) $pkg['id'],
                    'name' => (string) ($pkg['name'] ?? ('#' . $pkg['id'])),
                    'detail' => !empty($pkg['is_official'])
                        ? ((string) ($pkg['official_credits'] ?? '?') . ' credits, ' . (string) ($pkg['official_duration'] ?? '?') . ' ' . (string) ($pkg['official_duration_in'] ?? ''))
                        : 'trial only',
                ];
            }
            $status['ok'] = true;
        } catch (\Exception $e) {
            $status['error'] = $e->getMessage();
        }

        return $status;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function provision($order, $model): void
    {
        $orderId = (int) $order->id;
        $client = $this->client();
        $generation = (int) $model->generation;

        if ($model->service_type === 'reseller') {
            $this->provisionSubReseller($client, $order, $model, $orderId, $generation);

            return;
        }

        // Already sold for this order (an activation retried after the order status failed to save).
        if ((int) $model->line_id > 0) {
            return;
        }

        // Optional customer-chosen credentials from an order form; blank ones are generated by
        // the panel, and the reseller's group may ignore custom ones anyway.
        $form = json_decode((string) ($order->config ?? ''), true);
        $form = is_array($form) ? $form : [];
        // Ask the panel first: too few credits, a package that is not on sale or one for boxes only fail here
        // with the amounts and nothing is created.
        $client->assertCanSellPackage((int) $model->package_id, (bool) $model->trial, true);
        $data = $client->createLine(
            (int) $model->package_id,
            (bool) $model->trial,
            (string) ($form['username'] ?? ''),
            (string) ($form['password'] ?? ''),
            // The generation changes when the line is deleted, so selling the order again
            // creates a new line instead of replaying the deleted one.
            $this->requestId('c', $orderId, (string) $generation)
        );
        $line = (is_array($data) && isset($data['line']) && is_array($data['line'])) ? $data['line'] : [];
        if (!isset($line['id'])) {
            throw new ApiException('BAD_RESPONSE');
        }

        $model->line_id = (int) $line['id'];
        $model->username = (string) ($line['username'] ?? '');
        $this->save($model);
        $this->di['logger']->info('Xtream UI Pro: created line %s for order #%s', (int) $line['id'], $orderId);
    }

    private function provisionSubReseller(ApiClient $client, $order, $model, int $orderId, int $generation): void
    {
        if ((string) $model->user_id === '') {
            $username = trim((string) $model->username);
            $password = $this->storedPassword($model);
            if ($username === '') {
                $username = 'r' . $orderId . $this->random(6, 'abcdefghijklmnopqrstuvwxyz');
            }
            $username = substr($username, 0, 32);
            if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $username)) {
                throw new InformationException('The username is not valid for a sub-reseller: use 3 to 32 letters, digits, "_", "." or "-".');
            }
            if ($password === '') {
                $password = $this->random(14, 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789');
            }
            // Keep the credentials BEFORE the panel is called: the panel stores only a hash,
            // so a repeated call (timeout, failed credit transfer) must send the very same
            // values, not newly generated ones.
            $model->username = $username;
            $model->password_enc = $this->encrypt($password);
            $this->save($model);

            // The price of the account plus the credits to hand over: checked before the account exists.
            $client->assertCanCreateSubUser((int) $model->credits_on_creation);

            $person = $this->di['db']->getExistingModelById('Client', $order->client_id, 'Client not found');
            $data = $client->createSubUser(
                $username,
                $password,
                (string) $person->email,
                trim((string) $person->first_name . ' ' . (string) $person->last_name),
                $this->requestId('u', $orderId, (string) $generation)
            );
            $user = (is_array($data) && isset($data['user']) && is_array($data['user'])) ? $data['user'] : [];
            if (!isset($user['id'])) {
                throw new ApiException('BAD_RESPONSE');
            }
            $model->user_id = (string) $user['id'];
            if (!empty($user['username'])) {
                $model->username = (string) $user['username'];
            }
            if (!empty($data['password'])) {
                // The panel answered with other credentials (a replayed request).
                $model->password_enc = $this->encrypt((string) $data['password']);
            }
            $this->save($model);
            $this->di['logger']->info('Xtream UI Pro: created sub-reseller %s for order #%s', (string) $user['id'], $orderId);
        }

        if ((int) $model->credits_on_creation > 0) {
            $client->adjustCredits(
                (string) $model->user_id,
                (int) $model->credits_on_creation,
                'FOSSBilling order #' . $orderId,
                $this->requestId('v', $orderId, (string) $generation)
            );
        }
    }

    private function terminate($order, $model): void
    {
        $orderId = (int) $order->id;
        $client = $this->client();

        // Deleting a sub-reseller hands its credits and lines to the reseller, so an ended
        // order only disables the account.
        if ($model->service_type === 'reseller') {
            if ((string) $model->user_id !== '') {
                try {
                    $client->subUserAction('disable_user', (string) $model->user_id);
                } catch (ApiException $e) {
                    if ($e->getErrorCode() !== 'RESOURCE_NOT_FOUND') {
                        throw $e;
                    }
                }
            }

            return;
        }

        $lineId = (int) $model->line_id;
        if ($lineId <= 0) {
            return;
        }
        $delete = (bool) $model->delete_on_cancel;
        try {
            $client->lineAction($delete ? 'delete_line' : 'disable_line', $lineId);
        } catch (ApiException $e) {
            // A line that no longer exists in the panel is already terminated.
            if ($e->getErrorCode() !== 'RESOURCE_NOT_FOUND') {
                throw $e;
            }
        }
        if ($delete) {
            $model->line_id = 0;
            $model->generation = (int) $model->generation + 1;
            $this->save($model);
        }
        $this->di['logger']->info('Xtream UI Pro: %s line %s for order #%s', $delete ? 'deleted' : 'disabled', $lineId, $orderId);
    }

    /** The Custom Parameters of the product, normalised (the product's Configuration tab writes them). */
    private function productOptions(int $productId): array
    {
        $cfg = [];
        if ($productId > 0) {
            $product = $this->di['mod_service']('product')->findProductById($productId);
            $json = ($product !== null && method_exists($product, 'getConfig')) ? $product->getConfig() : null;
            $cfg = json_decode((string) $json, true);
            $cfg = is_array($cfg) ? $cfg : [];
        }

        return [
            'service_type' => (($cfg['service_type'] ?? 'line') === 'reseller') ? 'reseller' : 'line',
            'package_id' => (int) ($cfg['package_id'] ?? 0),
            'trial' => in_array(strtolower(trim((string) ($cfg['trial'] ?? '0'))), ['1', 'yes', 'true', 'on'], true),
            // Default off: an ended order only disables the line (it can be enabled again). Deleting is final on the
            // panel and only happens when the product says so explicitly.
            'delete_on_cancel' => in_array(strtolower(trim((string) ($cfg['delete_on_cancel'] ?? '0'))), ['1', 'yes', 'true', 'on'], true),
            'credits_on_creation' => $this->credits($cfg['credits_on_creation'] ?? '0', 'Credits on creation'),
            'credits_per_renewal' => $this->credits($cfg['credits_per_renewal'] ?? '0', 'Credits per renewal'),
        ];
    }

    private function credits($value, string $label): int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return 0;
        }
        if (!ctype_digit($value) || strlen($value) > 9) {
            throw new InformationException($label . ' in the product configuration must be a whole number of 0 or more.');
        }

        return (int) $value;
    }

    private function client(): ApiClient
    {
        $cfg = $this->di['mod_service']('extension')->getConfig('mod_servicextreampro');

        return new ApiClient((string) ($cfg['panel_url'] ?? ''), (string) ($cfg['api_key'] ?? ''));
    }

    private function requireService($service)
    {
        if (!is_object($service) || !isset($service->service_type)) {
            throw new InformationException('The order has no Xtream UI Pro service.');
        }

        return $service;
    }

    private function requireLineId($model): int
    {
        $id = (int) $model->line_id;
        if ($id <= 0) {
            throw new ApiException('RESOURCE_NOT_FOUND');
        }

        return $id;
    }

    private function requireUserId($model): string
    {
        $id = trim((string) $model->user_id);
        if ($id === '') {
            throw new ApiException('RESOURCE_NOT_FOUND');
        }

        return $id;
    }

    private function save($model): void
    {
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);
    }

    /**
     * Idempotency key (max 64 chars): the install's id (so two FOSSBilling installs
     * sharing a reseller never collide), the operation, the order and a counter or date.
     */
    private function requestId(string $op, int $orderId, string $suffix): string
    {
        $cfg = $this->di['mod_service']('extension')->getConfig('mod_servicextreampro');

        return substr('fb' . (string) ($cfg['instance'] ?? '') . '-' . $op . '-' . $orderId . '-' . $suffix, 0, 64);
    }

    /** Ymd of a FOSSBilling date, or today when the order does not expire. */
    private function dateStamp($date): string
    {
        $time = ($date !== null && $date !== '') ? strtotime((string) $date) : false;

        return gmdate('Ymd', $time !== false ? $time : time());
    }

    private function encrypt(string $plain): string
    {
        return $this->di['crypt']->encrypt($plain, Config::getProperty('info.salt'));
    }

    private function storedPassword($model): string
    {
        $enc = (string) $model->password_enc;
        if ($enc === '') {
            return '';
        }
        $plain = $this->di['crypt']->decrypt($enc, Config::getProperty('info.salt'));

        return is_string($plain) ? $plain : '';
    }

    private function random(int $length, string $alphabet): string
    {
        $out = '';
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < $length; ++$i) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }
}
