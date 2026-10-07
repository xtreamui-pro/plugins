<?php
/**
 * Xtream UI Pro connector - database access for the two module tables.
 *
 * Plain rows (arrays) in and out, so the order logic reads like the core's.
 * Passwords are encrypted here with Magento's encryptor and returned in clear
 * only by the methods that say so.
 */

namespace XtreamPro\Connector\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Encryption\EncryptorInterface;

class Storage
{
    const STATUS_PENDING = 'pending';
    const STATUS_FAILED = 'failed';
    const STATUS_PROVISIONED = 'provisioned';
    const STATUS_REVOKING = 'revoking';
    const STATUS_REVOKED = 'revoked';

    /** @var ResourceConnection */
    private $resource;

    /** @var EncryptorInterface */
    private $encryptor;

    public function __construct(ResourceConnection $resource, EncryptorInterface $encryptor)
    {
        $this->resource = $resource;
        $this->encryptor = $encryptor;
    }

    private function db(): AdapterInterface
    {
        return $this->resource->getConnection();
    }

    private function unitTable(): string
    {
        return $this->resource->getTableName('xtreampro_connector_unit');
    }

    private function resellerTable(): string
    {
        return $this->resource->getTableName('xtreampro_connector_reseller');
    }

    // ---- units -----------------------------------------------------------

    /** Units (alias u) with the item name and the order number, for display. */
    private function unitSelect(): \Magento\Framework\DB\Select
    {
        return $this->db()->select()
            ->from(['u' => $this->unitTable()])
            ->joinLeft(['i' => $this->resource->getTableName('sales_order_item')], 'i.item_id = u.order_item_id', ['item_name' => 'name'])
            ->joinLeft(['o' => $this->resource->getTableName('sales_order')], 'o.entity_id = u.order_id', ['increment_id']);
    }

    /**
     * Make sure units 1..$quantity of an order item exist. Existing units are
     * left alone, so this can run on every event.
     *
     * @param array $row order_id, order_item_id, customer_id, kind, package_id, trial, credits
     */
    public function claimUnits(array $row, int $quantity): void
    {
        $existing = array_map('intval', $this->db()->fetchCol(
            $this->db()->select()->from($this->unitTable(), 'unit')->where('order_item_id = ?', (int) $row['order_item_id'])
        ));
        for ($unit = 1; $unit <= $quantity; $unit++) {
            if (in_array($unit, $existing, true)) {
                continue;
            }
            // Two events can claim at the same moment: the unique key lets one win,
            // "update order_id to itself" turns the loser into a no-op.
            $this->db()->insertOnDuplicate($this->unitTable(), [
                'order_id'      => (int) $row['order_id'],
                'order_item_id' => (int) $row['order_item_id'],
                'unit'          => $unit,
                'customer_id'   => $row['customer_id'] ? (int) $row['customer_id'] : null,
                'kind'          => (string) $row['kind'],
                'package_id'    => $row['package_id'] ? (int) $row['package_id'] : null,
                'trial'         => empty($row['trial']) ? 0 : 1,
                'credits'       => (int) $row['credits'],
                'status'        => self::STATUS_PENDING,
            ], ['order_id']);
        }
    }

    /** @return array[] Units of an order, in order of item and unit. */
    public function unitsOfOrder(int $orderId): array
    {
        return $this->units($this->unitSelect()
            ->where('u.order_id = ?', $orderId)->order(['u.order_item_id ASC', 'u.unit ASC']));
    }

    /** @return array[] All units of a customer, newest first. */
    public function unitsOfCustomer(int $customerId): array
    {
        return $this->units($this->unitSelect()
            ->where('u.customer_id = ?', $customerId)->order('u.unit_id DESC'));
    }

    /**
     * Units the cron may work on: not done, not tried in the last five minutes,
     * tried fewer than $maxAttempts times.
     *
     * @return array[]
     */
    public function retryable(int $maxAttempts, int $limit): array
    {
        return $this->units($this->unitSelect()
            ->where('u.status IN (?)', [self::STATUS_PENDING, self::STATUS_FAILED, self::STATUS_REVOKING])
            ->where('u.attempts < ?', $maxAttempts)
            ->where('u.updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)')
            ->order('u.unit_id ASC')->limit($limit));
    }

    /** Units of an order that still need an action (used to decide whether to offer the retry button). */
    public function hasOpenUnits(int $orderId): bool
    {
        return (bool) $this->db()->fetchOne(
            $this->db()->select()->from($this->unitTable(), 'unit_id')
                ->where('order_id = ?', $orderId)
                ->where('status IN (?)', [self::STATUS_PENDING, self::STATUS_FAILED, self::STATUS_REVOKING])
                ->limit(1)
        );
    }

    public function hasUnits(int $orderId): bool
    {
        return (bool) $this->db()->fetchOne(
            $this->db()->select()->from($this->unitTable(), 'unit_id')->where('order_id = ?', $orderId)->limit(1)
        );
    }

    /**
     * Update columns of a unit. 'password' is given in clear and stored encrypted.
     *
     * @param array $changes Column => value.
     */
    public function updateUnit(int $unitId, array $changes): void
    {
        if (array_key_exists('password', $changes) && $changes['password'] !== null) {
            $changes['password'] = $this->encryptor->encrypt((string) $changes['password']);
        }
        if (isset($changes['links']) && is_array($changes['links'])) {
            $changes['links'] = json_encode($changes['links']);
        }
        $this->db()->update($this->unitTable(), $changes, ['unit_id = ?' => $unitId]);
    }

    /** Record a failed attempt. */
    public function failUnit(int $unitId, string $message, string $status = self::STATUS_FAILED): void
    {
        $this->db()->update($this->unitTable(), [
            'status'     => $status,
            'last_error' => substr($message, 0, 1000),
            'attempts'   => new Expression('attempts + 1'),
        ], ['unit_id = ?' => $unitId]);
    }

    /**
     * @return array[] Rows with 'password' decrypted and 'links' decoded.
     */
    private function units(\Magento\Framework\DB\Select $select): array
    {
        $rows = $this->db()->fetchAll($select);
        foreach ($rows as &$row) {
            $row['password'] = $row['password'] === null || $row['password'] === '' ? '' : (string) $this->encryptor->decrypt($row['password']);
            $links = $row['links'] ? json_decode($row['links'], true) : [];
            $row['links'] = is_array($links) ? $links : [];
        }
        return $rows;
    }

    // ---- sub-reseller account of a customer --------------------------------

    /**
     * @return array|null {entity_id, customer_id, panel_user_id, username, password(clear)}
     */
    public function resellerOf(int $customerId): ?array
    {
        $row = $this->db()->fetchRow(
            $this->db()->select()->from($this->resellerTable())->where('customer_id = ?', $customerId)
        );
        if (!$row) {
            return null;
        }
        $row['password'] = (string) $this->encryptor->decrypt($row['password']);
        return $row;
    }

    /**
     * Store the account details of a customer before the panel is called. If a
     * row exists already (another event was first) it is left untouched; read
     * it back with resellerOf().
     */
    public function claimReseller(int $customerId, string $username, string $password): void
    {
        $this->db()->insertOnDuplicate($this->resellerTable(), [
            'customer_id' => $customerId,
            'username'    => $username,
            'password'    => $this->encryptor->encrypt($password),
        ], ['customer_id']);
    }

    /** Remember the panel id (and the credentials the panel really used). */
    public function saveReseller(int $customerId, string $panelUserId, string $username, string $password): void
    {
        $this->db()->update($this->resellerTable(), [
            'panel_user_id' => $panelUserId,
            'username'      => $username,
            'password'      => $this->encryptor->encrypt($password),
        ], ['customer_id = ?' => $customerId]);
    }

    /** Units of this customer's account that are provisioned and not revoked, excluding one unit. */
    public function activeResellerUnits(int $customerId, int $exceptUnitId): int
    {
        return (int) $this->db()->fetchOne(
            $this->db()->select()->from($this->unitTable(), 'COUNT(*)')
                ->where('customer_id = ?', $customerId)
                ->where('kind = ?', 'reseller')
                ->where('status = ?', self::STATUS_PROVISIONED)
                ->where('unit_id <> ?', $exceptUnitId)
        );
    }
}
