<?php
/**
 * The bridge's own memory: a SQLite file (var/bridge.sqlite3, mode 0600).
 *
 *  events    every webhook event, once (unique platform + event id), with its
 *            outcome; failed ones are kept for `bridge.php retry`
 *  lines     the panel line of every purchased unit
 *  accounts  customer email -> sub-reseller account
 *  grants    credits handed to an account by an order (so a refund can take
 *            them back)
 *  outbox    credential mails not yet delivered (deleted once sent)
 *
 * Every write runs inside a transaction.
 */

declare(strict_types=1);

namespace XtreamPro\Bridge;

class Store
{
    private \PDO $pdo;

    public function __construct(string $file)
    {
        $isNew = !is_file($file);
        if ($isNew) {
            // Create it private from the first byte.
            $old = umask(0077);
            touch($file);
            umask($old);
            @chmod($file, 0600);
        }
        $this->pdo = new \PDO('sqlite:' . $file, null, null, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->migrate();
    }

    private function migrate(): void
    {
        $this->transaction(function (): void {
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS events (
                platform TEXT NOT NULL, event_id TEXT NOT NULL, status TEXT NOT NULL,
                event TEXT NOT NULL, error TEXT, attempts INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
                UNIQUE (platform, event_id))');
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS lines (
                id INTEGER PRIMARY KEY AUTOINCREMENT, platform TEXT NOT NULL, order_id TEXT NOT NULL,
                item_id TEXT NOT NULL, unit INTEGER NOT NULL, line_id INTEGER NOT NULL,
                username TEXT NOT NULL DEFAULT "", pending_password TEXT, disabled INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                UNIQUE (platform, order_id, item_id, unit))');
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS accounts (
                email TEXT PRIMARY KEY, user_id TEXT, username TEXT NOT NULL, pending_password TEXT,
                created_by TEXT NOT NULL, disabled INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL)');
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS grants (
                id INTEGER PRIMARY KEY AUTOINCREMENT, platform TEXT NOT NULL, order_id TEXT NOT NULL,
                item_id TEXT NOT NULL, grant_key TEXT NOT NULL, email TEXT NOT NULL, user_id TEXT NOT NULL,
                credits INTEGER NOT NULL, units INTEGER NOT NULL, taken_units INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL, UNIQUE (platform, grant_key))');
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS outbox (
                id INTEGER PRIMARY KEY AUTOINCREMENT, to_email TEXT NOT NULL, subject TEXT NOT NULL,
                body TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, error TEXT, created_at TEXT NOT NULL)');
        });
    }

    /** Run $fn in a transaction (immediate, so concurrent writers queue up). */
    public function transaction(callable $fn)
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $fn();
            $this->pdo->exec('COMMIT');
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    private function run(string $sql, array $args = []): \PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($args);
        return $st;
    }

    // ---- events ----------------------------------------------------------

    /**
     * Register an event. Returns true when it is new (the caller must process
     * it), false when this platform + event id was seen before.
     */
    public function claimEvent(string $platform, string $eventId, array $event): bool
    {
        return $this->transaction(function () use ($platform, $eventId, $event): bool {
            $st = $this->run(
                'INSERT OR IGNORE INTO events (platform, event_id, status, event, attempts, created_at, updated_at)
                 VALUES (?, ?, "processing", ?, 1, ?, ?)',
                [$platform, $eventId, json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), self::now(), self::now()]
            );
            return $st->rowCount() === 1;
        });
    }

    /** status: done | failed. */
    public function finishEvent(string $platform, string $eventId, string $status, string $note = ''): void
    {
        $this->transaction(function () use ($platform, $eventId, $status, $note): void {
            $this->run(
                'UPDATE events SET status = ?, error = ?, updated_at = ? WHERE platform = ? AND event_id = ?',
                [$status, $note === '' ? null : substr($note, 0, 500), self::now(), $platform, $eventId]
            );
        });
    }

    public function event(string $platform, string $eventId): ?array
    {
        $row = $this->run('SELECT * FROM events WHERE platform = ? AND event_id = ?', [$platform, $eventId])->fetch();
        return $row ?: null;
    }

    /** Events to retry: failed ones, and "processing" ones left behind by a crash. */
    public function retryable(): array
    {
        $stale = gmdate('Y-m-d H:i:s', time() - 600);
        return $this->run(
            'SELECT * FROM events WHERE status = "failed" OR (status = "processing" AND updated_at < ?) ORDER BY created_at, rowid',
            [$stale]
        )->fetchAll();
    }

    /** Put an event back to "processing" for another attempt. */
    public function reopenEvent(string $platform, string $eventId): void
    {
        $this->transaction(function () use ($platform, $eventId): void {
            $this->run(
                'UPDATE events SET status = "processing", attempts = attempts + 1, updated_at = ? WHERE platform = ? AND event_id = ?',
                [self::now(), $platform, $eventId]
            );
        });
    }

    public function failedCount(): int
    {
        return (int) $this->run('SELECT COUNT(*) FROM events WHERE status = "failed"')->fetchColumn();
    }

    // ---- lines -----------------------------------------------------------

    /**
     * Remember the credentials chosen for a unit BEFORE the panel is called:
     * a retry (after a crash or timeout) must send exactly the same ones.
     * line_id stays 0 until confirmLine(). An existing row wins.
     */
    public function reserveLine(string $platform, string $order, string $item, int $unit, string $username, string $password): array
    {
        return $this->transaction(function () use ($platform, $order, $item, $unit, $username, $password): array {
            $this->run(
                'INSERT OR IGNORE INTO lines (platform, order_id, item_id, unit, line_id, username, pending_password, created_at)
                 VALUES (?, ?, ?, ?, 0, ?, ?, ?)',
                [$platform, $order, $item, $unit, $username, $password, self::now()]
            );
            return $this->run(
                'SELECT * FROM lines WHERE platform = ? AND order_id = ? AND item_id = ? AND unit = ?',
                [$platform, $order, $item, $unit]
            )->fetch();
        });
    }

    public function confirmLine(int $rowId, int $lineId, string $username): void
    {
        $this->transaction(function () use ($rowId, $lineId, $username): void {
            $this->run('UPDATE lines SET line_id = ?, username = ?, pending_password = NULL WHERE id = ?', [$lineId, $username, $rowId]);
        });
    }

    /** Created lines of an order (optionally one item), highest unit first. */
    public function lines(string $platform, string $order, ?string $item = null): array
    {
        $sql = 'SELECT * FROM lines WHERE line_id > 0 AND platform = ? AND order_id = ?';
        $args = [$platform, $order];
        if ($item !== null) {
            $sql .= ' AND item_id = ?';
            $args[] = $item;
        }
        return $this->run($sql . ' ORDER BY item_id, unit DESC', $args)->fetchAll();
    }

    public function setLineDisabled(int $rowId, bool $disabled): void
    {
        $this->transaction(function () use ($rowId, $disabled): void {
            $this->run('UPDATE lines SET disabled = ? WHERE id = ?', [$disabled ? 1 : 0, $rowId]);
        });
    }

    // ---- accounts --------------------------------------------------------

    public function account(string $email): ?array
    {
        $row = $this->run('SELECT * FROM accounts WHERE email = ?', [strtolower($email)])->fetch();
        return $row ?: null;
    }

    /**
     * Remember the credentials chosen for a new account BEFORE the panel is
     * called: a retry must send exactly the same ones. Returns the stored row
     * (an existing pending row wins over the new values).
     */
    public function reserveAccount(string $email, string $username, string $password, string $createdBy): array
    {
        return $this->transaction(function () use ($email, $username, $password, $createdBy): array {
            $this->run(
                'INSERT OR IGNORE INTO accounts (email, username, pending_password, created_by, created_at) VALUES (?, ?, ?, ?, ?)',
                [strtolower($email), $username, $password, $createdBy, self::now()]
            );
            return $this->run('SELECT * FROM accounts WHERE email = ?', [strtolower($email)])->fetch();
        });
    }

    public function confirmAccount(string $email, string $userId, string $username): void
    {
        $this->transaction(function () use ($email, $userId, $username): void {
            $this->run(
                'UPDATE accounts SET user_id = ?, username = ?, pending_password = NULL WHERE email = ?',
                [$userId, $username, strtolower($email)]
            );
        });
    }

    public function setAccountDisabled(string $email, bool $disabled): void
    {
        $this->transaction(function () use ($email, $disabled): void {
            $this->run('UPDATE accounts SET disabled = ? WHERE email = ?', [$disabled ? 1 : 0, strtolower($email)]);
        });
    }

    /** Accounts an order created (normally zero or one). */
    public function accountsCreatedBy(string $createdBy): array
    {
        return $this->run('SELECT * FROM accounts WHERE created_by = ? AND user_id IS NOT NULL', [$createdBy])->fetchAll();
    }

    // ---- grants ----------------------------------------------------------

    public function grant(string $platform, string $key): ?array
    {
        $row = $this->run('SELECT * FROM grants WHERE platform = ? AND grant_key = ?', [$platform, $key])->fetch();
        return $row ?: null;
    }

    public function saveGrant(string $platform, string $order, string $item, string $key, string $email, string $userId, int $credits, int $units): void
    {
        $this->transaction(function () use ($platform, $order, $item, $key, $email, $userId, $credits, $units): void {
            $this->run(
                'INSERT OR IGNORE INTO grants (platform, order_id, item_id, grant_key, email, user_id, credits, units, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$platform, $order, $item, $key, strtolower($email), $userId, $credits, max(1, $units), self::now()]
            );
        });
    }

    /** Grants of an order (optionally one item) that still hold credits. */
    public function openGrants(string $platform, string $order, ?string $item = null): array
    {
        $sql = 'SELECT * FROM grants WHERE platform = ? AND order_id = ? AND taken_units < units';
        $args = [$platform, $order];
        if ($item !== null) {
            $sql .= ' AND item_id = ?';
            $args[] = $item;
        }
        return $this->run($sql . ' ORDER BY id', $args)->fetchAll();
    }

    public function addTakenUnits(int $grantId, int $units): void
    {
        $this->transaction(function () use ($grantId, $units): void {
            $this->run('UPDATE grants SET taken_units = taken_units + ? WHERE id = ?', [$units, $grantId]);
        });
    }

    // ---- outbox ----------------------------------------------------------

    public function queueMail(string $to, string $subject, string $body): int
    {
        return $this->transaction(function () use ($to, $subject, $body): int {
            $this->run('INSERT INTO outbox (to_email, subject, body, created_at) VALUES (?, ?, ?, ?)', [$to, $subject, $body, self::now()]);
            return (int) $this->pdo->lastInsertId();
        });
    }

    public function pendingMails(): array
    {
        return $this->run('SELECT * FROM outbox ORDER BY id')->fetchAll();
    }

    public function mailSent(int $id): void
    {
        $this->transaction(function () use ($id): void {
            $this->run('DELETE FROM outbox WHERE id = ?', [$id]);
        });
    }

    public function mailFailed(int $id, string $error): void
    {
        $this->transaction(function () use ($id, $error): void {
            $this->run('UPDATE outbox SET attempts = attempts + 1, error = ? WHERE id = ?', [substr($error, 0, 300), $id]);
        });
    }
}
