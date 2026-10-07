<?php
/**
 * The business logic of the bridge, independent of any shop platform: it
 * receives normalized events (see Adapter\Adapter) and carries them out on the
 * panel through the Reseller API.
 *
 *   paid      create one line per unit (IPTV line products) or create / top up
 *             the customer's sub-reseller account (reseller products)
 *   renewed   renew the lines of the order / give the renewal credits
 *   revoked   disable the lines, take unspent credits back, disable an account
 *             the order created
 *   restored  enable them again (credits are not given back automatically)
 *
 * Every panel call that costs credits carries a request id built from the
 * platform's own ids (br-<platform>-<order>-<item>-<unit>), so repeating an
 * event never charges twice. Anything that goes wrong is thrown as an
 * exception with a readable message; the caller stores it and retries later.
 */

declare(strict_types=1);

namespace XtreamPro\Bridge;

class Provisioner
{
    private ApiClient $api;
    private Store $store;
    private Mailer $mailer;
    private Logger $log;
    /** @var array<string,array<string,array>> platform => product key => settings */
    private array $products;
    private string $panelUrl;

    /**
     * @param array  $products platform => (product key => {type, package_id, trial, credits, renew_credits})
     * @param string $panelUrl optional dashboard address shown in the reseller mail
     */
    public function __construct(ApiClient $api, Store $store, Mailer $mailer, Logger $log, array $products, string $panelUrl = '')
    {
        $this->api = $api;
        $this->store = $store;
        $this->mailer = $mailer;
        $this->log = $log;
        $this->products = $products;
        $this->panelUrl = $panelUrl;
    }

    /**
     * Carry out one event.
     *
     * @return string short note for the log ("created 2 lines", "ignored: ...")
     * @throws \Throwable (ApiException, ProvisionException, ...) when it could not be done
     */
    public function handle(string $platform, array $e): string
    {
        switch ($e['type']) {
            case 'paid':
            case 'renewed':
                $cfg = $this->productFor($platform, $e);
                if ($cfg === null) {
                    return 'ignored: no product mapping for "' . $e['sku'] . '"';
                }
                if ($e['type'] === 'renewed') {
                    return $cfg['type'] === 'reseller' ? $this->renewCredits($platform, $e, $cfg) : $this->renewLines($platform, $e);
                }
                if ($e['email'] === '') {
                    throw new ProvisionException('The webhook carries no customer email address, so the credentials cannot be sent. Nothing was created.');
                }
                return $cfg['type'] === 'reseller' ? $this->provisionReseller($platform, $e, $cfg) : $this->provisionLines($platform, $e, $cfg);
            case 'revoked':
                return $this->revoke($platform, $e);
            case 'restored':
                return $this->restore($platform, $e);
        }
        throw new ProvisionException('Unknown event type.');
    }

    // ---- product map -----------------------------------------------------

    /** Settings of the mapped product, or null when this item is not ours. */
    private function productFor(string $platform, array $e): ?array
    {
        $map = $this->products[$platform] ?? [];
        foreach (array_merge([$e['sku']], $e['alt_keys']) as $key) {
            $key = (string) $key;
            if ($key !== '' && substr($key, -1) !== ':' && isset($map[$key]) && is_array($map[$key])) {
                return $this->normalizeProduct($map[$key], $key);
            }
        }
        return null;
    }

    private function normalizeProduct(array $p, string $key): array
    {
        $type = ($p['type'] ?? 'line') === 'reseller' ? 'reseller' : 'line';
        $out = [
            'type'          => $type,
            'package_id'    => (int) ($p['package_id'] ?? 0),
            'trial'         => !empty($p['trial']),
            'credits'       => max(0, (int) ($p['credits'] ?? 0)),
            'renew_credits' => max(0, (int) ($p['renew_credits'] ?? 0)),
        ];
        if ($type === 'line' && $out['package_id'] <= 0) {
            throw new ProvisionException('The product "' . $key . '" is mapped as an IPTV line without a package_id in config.php.');
        }
        return $out;
    }

    // ---- IPTV lines ------------------------------------------------------

    private function provisionLines(string $platform, array $e, array $cfg): string
    {
        $quantity = max(1, (int) $e['quantity']);
        $created = [];
        $failure = null;
        for ($n = 1; $n <= $quantity; $n++) {
            try {
                // Credentials are reserved first: a retry sends the very same ones.
                $row = $this->store->reserveLine(
                    $platform,
                    $e['order_id'],
                    $e['item_id'],
                    $n,
                    'b' . self::random(9, 'abcdefghijklmnopqrstuvwxyz0123456789'),
                    self::random(12, 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789')
                );
                if ((int) $row['line_id'] > 0) {
                    continue; // done by an earlier attempt
                }
                // Ask the panel first: too few credits, a package that is not on sale or one for boxes only
                // fail here with the amounts and nothing is created.
                $this->api->assertCanSellPackage($cfg['package_id'], $cfg['trial'], true);
                $data = $this->api->createLine(
                    $cfg['package_id'],
                    $cfg['trial'],
                    (string) $row['username'],
                    (string) $row['pending_password'],
                    $this->requestId($platform, $e['order_id'], $e['item_id'], (string) $n)
                );
                $line = is_array($data) && isset($data['line']) && is_array($data['line']) ? $data['line'] : [];
                if (!isset($line['id'])) {
                    throw new ApiException('BAD_RESPONSE');
                }
                // The reseller's group may have replaced our credentials: use what the panel says.
                $username = isset($line['username']) && $line['username'] !== '' ? (string) $line['username'] : (string) $row['username'];
                $password = isset($line['password']) && $line['password'] !== '' ? (string) $line['password']
                    : (isset($data['password']) && $data['password'] !== '' ? (string) $data['password'] : (string) $row['pending_password']);
                $this->store->confirmLine((int) $row['id'], (int) $line['id'], $username);
                $created[] = ['id' => (int) $line['id'], 'username' => $username, 'password' => $password];
            } catch (\Throwable $t) {
                $failure = $t;
                break;
            }
        }

        // Whatever was created is delivered, even when a later unit failed.
        if ($created) {
            $this->mailLines($e, $created);
        }
        if ($failure !== null) {
            throw $failure;
        }
        return 'created ' . count($created) . ' of ' . $quantity . ' line(s)';
    }

    private function renewLines(string $platform, array $e): string
    {
        $item = $e['item_id'] === '*' ? null : $e['item_id'];
        $rows = $this->store->lines($platform, $e['order_id'], $item);
        if (!$rows) {
            throw new ProvisionException('No lines were recorded for order ' . $e['order_id'] . ', so there is nothing to renew.');
        }
        $stamp = substr(hash('sha256', $e['id']), 0, 12);
        $done = 0;
        foreach ($rows as $row) {
            // Fails with the amounts when the balance cannot pay the period; nothing is sold then.
            $current = $this->api->getLine((int) $row['line_id']);
            if (is_array($current) && (int) ($current['package_id'] ?? 0) > 0) {
                $this->api->assertCanSellPackage((int) $current['package_id'], false, false);
            }
            $this->api->renewLine((int) $row['line_id'], $this->requestId($platform, $e['order_id'], $row['item_id'], $row['unit'] . '-r' . $stamp));
            $done++;
        }
        return 'renewed ' . $done . ' line(s)';
    }

    // ---- sub-reseller accounts -------------------------------------------

    private function provisionReseller(string $platform, array $e, array $cfg): string
    {
        $account = $this->ensureAccount($platform, $e, $cfg['credits'] * max(1, (int) $e['quantity']));
        $granted = 0;
        $failure = null;
        try {
            $granted = $this->grantCredits($platform, $e, $account['user_id'], $account['email'], $cfg['credits'] * max(1, (int) $e['quantity']), 'o' . $e['order_id'] . ':' . $e['item_id']);
        } catch (\Throwable $t) {
            $failure = $t;
        }
        // The password exists only now: send it even when the credit transfer failed.
        if ($account['password'] !== null || $granted > 0) {
            $this->mailAccount($e, $account, $granted);
        }
        if ($failure !== null) {
            throw $failure;
        }
        return ($account['password'] !== null ? 'account created' : 'account found') . ', ' . $granted . ' credits given';
    }

    private function renewCredits(string $platform, array $e, array $cfg): string
    {
        $credits = $cfg['renew_credits'] * max(1, (int) $e['quantity']);
        if ($credits <= 0) {
            return 'nothing to do: no renew_credits for this product';
        }
        $userId = '';
        $email = $e['email'];
        // The account that paid for this order first, else the one of the customer's email.
        foreach ($this->store->openGrants($platform, $e['order_id'], null) as $g) {
            $userId = (string) $g['user_id'];
            $email = (string) $g['email'];
            break;
        }
        if ($userId === '' && $e['email'] !== '') {
            $acct = $this->store->account($e['email']);
            $userId = $acct && $acct['user_id'] ? (string) $acct['user_id'] : '';
        }
        if ($userId === '') {
            throw new ProvisionException('No sub-reseller account was recorded for order ' . $e['order_id'] . ', so the renewal credits cannot be given.');
        }
        $stamp = substr(hash('sha256', $e['id']), 0, 12);
        $granted = $this->grantCredits($platform, $e, $userId, $email, $credits, 'o' . $e['order_id'] . ':' . $e['item_id'] . ':r' . $stamp);
        if ($granted > 0 && $email !== '') {
            $this->mailAccount($e, ['user_id' => $userId, 'username' => '', 'email' => $email, 'password' => null], $granted);
        }
        return $granted . ' renewal credits given';
    }

    /**
     * The customer's account: found by email, or created. One account per
     * customer email. Returns the password only for an account created now.
     *
     * @return array{user_id:string,username:string,email:string,password:?string}
     */
    private function ensureAccount(string $platform, array $e, int $credits = 0): array
    {
        $email = strtolower($e['email']);
        $row = $this->store->account($email);
        if ($row && $row['user_id']) {
            if ((int) $row['disabled'] === 1) {
                $this->api->subUserAction('enable_user', (string) $row['user_id']);
                $this->store->setAccountDisabled($email, false);
            }
            return ['user_id' => (string) $row['user_id'], 'username' => (string) $row['username'], 'email' => $email, 'password' => null];
        }

        // Reserve username + password first; a retry sends the same values.
        $row = $this->store->reserveAccount($email, self::subUsername($email), self::random(14, 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'), $platform . ':' . $e['order_id']);
        $fullname = $e['name'] !== '' ? $e['name'] : (string) $row['username'];
        // The price of the account plus the credits to hand over: checked before the account exists.
        $this->api->assertCanCreateSubUser($credits);
        $data = $this->api->createSubUser(
            (string) $row['username'],
            (string) $row['pending_password'],
            $email,
            mb_substr($fullname, 0, 128),
            // One request id per customer: any retry, from any order, gets the same account back.
            'br-acct-' . substr(hash('sha256', $email), 0, 40)
        );
        $user = is_array($data) && isset($data['user']) && is_array($data['user']) ? $data['user'] : [];
        if (!isset($user['id'])) {
            throw new ApiException('BAD_RESPONSE');
        }
        $username = isset($user['username']) && $user['username'] !== '' ? (string) $user['username'] : (string) $row['username'];
        $password = isset($data['password']) && $data['password'] !== '' ? (string) $data['password'] : (string) $row['pending_password'];
        $this->store->confirmAccount($email, (string) $user['id'], $username);
        return ['user_id' => (string) $user['id'], 'username' => $username, 'email' => $email, 'password' => $password];
    }

    /** Give credits once per grant key. Returns the credits given now (0 when already done). */
    private function grantCredits(string $platform, array $e, string $userId, string $email, int $credits, string $key): int
    {
        if ($credits <= 0 || $this->store->grant($platform, $key) !== null) {
            return 0;
        }
        $this->api->assertCanGiveCredits($credits);
        $this->api->adjustCredits($userId, $credits, ucfirst($platform) . ' order ' . $e['order_id'], $this->requestId($platform, $e['order_id'], $e['item_id'], 'cr' . substr(hash('sha256', $key), 0, 10)));
        $this->store->saveGrant($platform, $e['order_id'], $e['item_id'], $key, $email, $userId, $credits, max(1, (int) $e['quantity']));
        return $credits;
    }

    // ---- revoke / restore ------------------------------------------------

    private function revoke(string $platform, array $e): string
    {
        $item = $e['item_id'] === '*' ? null : $e['item_id'];
        $limit = $e['quantity'] === null ? null : (int) $e['quantity'];
        $disabled = 0;
        $taken = 0;

        $perItem = [];
        foreach ($this->store->lines($platform, $e['order_id'], $item) as $row) {
            if ((int) $row['disabled'] === 1) {
                continue;
            }
            $perItem[$row['item_id']] = ($perItem[$row['item_id']] ?? 0) + 1;
            if ($limit !== null && $perItem[$row['item_id']] > $limit) {
                continue;
            }
            try {
                $this->api->lineAction('disable_line', (int) $row['line_id']);
            } catch (ApiException $x) {
                if ($x->getErrorCode() !== 'RESOURCE_NOT_FOUND') { // gone from the panel = already revoked
                    throw $x;
                }
            }
            $this->store->setLineDisabled((int) $row['id'], true);
            $disabled++;
        }

        if ($e['take_back']) {
            foreach ($this->store->openGrants($platform, $e['order_id'], $item) as $g) {
                $taken += $this->takeBack($platform, $e, $g, $limit);
            }
        }

        // The account this order created is disabled when the whole order is revoked.
        $accounts = 0;
        if ($item === null && $limit === null) {
            foreach ($this->store->accountsCreatedBy($platform . ':' . $e['order_id']) as $a) {
                if ((int) $a['disabled'] === 0) {
                    $this->api->subUserAction('disable_user', (string) $a['user_id']);
                    $this->store->setAccountDisabled((string) $a['email'], true);
                    $accounts++;
                }
            }
        }
        return 'disabled ' . $disabled . ' line(s), took back ' . $taken . ' credits, disabled ' . $accounts . ' account(s)';
    }

    /** Take the unspent part of one grant back. Returns the credits moved. */
    private function takeBack(string $platform, array $e, array $g, ?int $limit): int
    {
        $remaining = (int) $g['units'] - (int) $g['taken_units'];
        $units = $limit === null ? $remaining : min($limit, $remaining);
        if ($units <= 0) {
            return 0;
        }
        $total = (int) $g['credits'];
        $before = (int) $g['taken_units'];
        $want = (int) (round($total * ($before + $units) / (int) $g['units']) - round($total * $before / (int) $g['units']));

        // Only what is still unspent can come back.
        $user = $this->api->getUser((string) $g['user_id']);
        $balance = is_array($user) && isset($user['credits']) ? (int) $user['credits'] : 0;
        $amount = max(0, min($want, $balance));
        if ($amount > 0) {
            $this->api->adjustCredits(
                (string) $g['user_id'],
                -$amount,
                ucfirst($platform) . ' order ' . $e['order_id'] . ' revoked',
                $this->requestId($platform, $e['order_id'], (string) $g['item_id'], 'x' . substr(hash('sha256', $g['grant_key']), 0, 8) . '-' . $before)
            );
        }
        $this->store->addTakenUnits((int) $g['id'], $units);
        return $amount;
    }

    private function restore(string $platform, array $e): string
    {
        $item = $e['item_id'] === '*' ? null : $e['item_id'];
        $enabled = 0;
        foreach ($this->store->lines($platform, $e['order_id'], $item) as $row) {
            if ((int) $row['disabled'] === 1) {
                $this->api->lineAction('enable_line', (int) $row['line_id']);
                $this->store->setLineDisabled((int) $row['id'], false);
                $enabled++;
            }
        }
        $accounts = 0;
        foreach ($this->store->accountsCreatedBy($platform . ':' . $e['order_id']) as $a) {
            if ((int) $a['disabled'] === 1) {
                $this->api->subUserAction('enable_user', (string) $a['user_id']);
                $this->store->setAccountDisabled((string) $a['email'], false);
                $accounts++;
            }
        }
        return 'enabled ' . $enabled . ' line(s) and ' . $accounts . ' account(s); credits taken back earlier are not given again';
    }

    // ---- mails to the buyer ----------------------------------------------

    private function mailLines(array $e, array $created): void
    {
        $text = "Thank you for your order. Here is your IPTV subscription.\n";
        foreach ($created as $i => $line) {
            $links = [];
            try {
                $full = $this->api->getLine($line['id']);
                $links = is_array($full) && isset($full['links']) && is_array($full['links']) ? $full['links'] : [];
            } catch (\Throwable $t) {
                // The links are a convenience; the credentials below are enough.
            }
            $text .= "\nLine " . ($i + 1) . "\n";
            $text .= 'Server URL: ' . ($links['server'] ?? $this->api->baseUrl()) . "\n";
            $text .= 'Username: ' . $line['username'] . "\n";
            $text .= 'Password: ' . $line['password'] . "\n";
            foreach (['m3u' => 'Playlist (M3U)', 'web_player' => 'Web player'] as $key => $label) {
                if (!empty($links[$key])) {
                    $text .= $label . ': ' . $links[$key] . "\n";
                }
            }
        }
        $text .= "\nKeep this message private: it contains your password.\n";
        $this->deliver($e['email'], 'Your IPTV subscription', $text);
    }

    private function mailAccount(array $e, array $account, int $granted): void
    {
        $text = "Thank you for your order.\n";
        if ($account['password'] !== null) {
            $text .= "\nYour reseller account has been created.\n";
            if ($this->panelUrl !== '') {
                $text .= 'Sign in: ' . $this->panelUrl . "\n";
            }
            $text .= 'Username: ' . $account['username'] . "\n";
            $text .= 'Password: ' . $account['password'] . "\n";
        } elseif ($account['username'] !== '') {
            $text .= "\nYour reseller account: " . $account['username'] . "\n";
        }
        if ($granted > 0) {
            $text .= "\nCredits added to your account: " . $granted . "\n";
        }
        if ($account['password'] !== null) {
            $text .= "\nKeep this message private: it contains your password.\n";
        }
        $this->deliver($e['email'], 'Your reseller account', $text);
    }

    /** Queue, then send. A failed send stays queued for `bridge.php retry`; it never fails the event. */
    private function deliver(string $to, string $subject, string $body): void
    {
        $id = $this->store->queueMail($to, $subject, $body);
        try {
            $this->mailer->send($to, $subject, $body);
            $this->store->mailSent($id);
        } catch (\Throwable $t) {
            $this->store->mailFailed($id, $t->getMessage());
            $this->log->warn('credentials mail #' . $id . ' could not be sent, kept for retry: ' . $t->getMessage());
        }
    }

    /** Send the mails that failed earlier. Returns how many went out. */
    public function flushMails(): int
    {
        $sent = 0;
        foreach ($this->store->pendingMails() as $m) {
            try {
                $this->mailer->send((string) $m['to_email'], (string) $m['subject'], (string) $m['body']);
                $this->store->mailSent((int) $m['id']);
                $sent++;
            } catch (\Throwable $t) {
                $this->store->mailFailed((int) $m['id'], $t->getMessage());
            }
        }
        return $sent;
    }

    // ---- helpers ---------------------------------------------------------

    /**
     * br-<platform>-<part>-<part>... limited to the panel's 64 characters:
     * a longer id is replaced by a hash of the same parts (still stable).
     */
    private function requestId(string ...$parts): string
    {
        $joined = implode('-', array_map(static fn ($p) => (string) preg_replace('/[^A-Za-z0-9._]/', '_', $p), $parts));
        $id = 'br-' . $joined;
        return strlen($id) <= 64 ? $id : 'br-' . substr(hash('sha256', $joined), 0, 40);
    }

    /** Panel username (3-32 of letters, digits, _ . -) from the email's local part. */
    private static function subUsername(string $email): string
    {
        $base = (string) preg_replace('/[^A-Za-z0-9_.-]/', '', (string) strstr($email, '@', true));
        if (strlen($base) < 2) {
            $base = 'r' . $base;
        }
        return substr(substr($base, 0, 26) . '-' . self::random(5, 'abcdefghijklmnopqrstuvwxyz0123456789'), 0, 32);
    }

    /** Random string from a fixed alphabet, using random_int (CSPRNG). */
    private static function random(int $length, string $alphabet): string
    {
        $out = '';
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }
}
