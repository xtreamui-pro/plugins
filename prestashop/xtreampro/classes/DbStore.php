<?php
/**
 * The module's three tables, and XtreamproStore on top of them.
 *
 *   xtreampro_product   per product: what it sells (line / sub-reseller), package, trial, credits
 *   xtreampro_unit      per order detail id + unit number: what was provisioned, or why not
 *   xtreampro_account   per customer: the one sub-reseller account
 *
 * All SQL is written out here with explicit escaping (pSQL with $html_ok = true
 * so that nothing is stripped from a stored value, casts for numbers).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class XtreamproDbStore implements XtreamproStore
{
    // Column map: field name in a unit row => column.
    private static $unitColumns = array(
        'order_id'    => 'id_order',
        'customer_id' => 'id_customer',
        'kind'        => 'kind',
        'status'      => 'status',
        'panel_id'    => 'panel_id',
        'username'    => 'username',
        'password'    => 'password',
        'links'       => 'links',
        'credits'     => 'credits',
        'generation'  => 'generation',
        'renewals'    => 'renewals',
        'error'       => 'error',
    );
    private static $unitInts = array('order_id', 'customer_id', 'credits', 'generation', 'renewals');

    private static $accountColumns = array(
        'panel_user_id' => 'panel_user_id',
        'username'      => 'username',
        'password'      => 'password',
        'origin'        => 'origin',
        'disabled'      => 'disabled',
    );

    // ---- schema ------------------------------------------------------------------

    /** CREATE TABLE IF NOT EXISTS, so installing again keeps the data. */
    public static function createTables()
    {
        $engine = defined('_MYSQL_ENGINE_') ? _MYSQL_ENGINE_ : 'InnoDB';
        $tail = ' ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4';
        $queries = array(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'xtreampro_product` (
                `id_product` INT UNSIGNED NOT NULL,
                `kind` VARCHAR(16) NOT NULL DEFAULT \'\',
                `package_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `trial` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `credits` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id_product`)
            )' . $tail,
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'xtreampro_unit` (
                `id_order_detail` INT UNSIGNED NOT NULL,
                `unit_no` INT UNSIGNED NOT NULL,
                `id_order` INT UNSIGNED NOT NULL DEFAULT 0,
                `id_customer` INT UNSIGNED NOT NULL DEFAULT 0,
                `kind` VARCHAR(16) NOT NULL DEFAULT \'\',
                `status` VARCHAR(16) NOT NULL DEFAULT \'error\',
                `panel_id` VARCHAR(40) NOT NULL DEFAULT \'\',
                `username` VARCHAR(128) NOT NULL DEFAULT \'\',
                `password` VARCHAR(255) NOT NULL DEFAULT \'\',
                `links` TEXT NULL,
                `credits` INT UNSIGNED NOT NULL DEFAULT 0,
                `generation` INT UNSIGNED NOT NULL DEFAULT 0,
                `renewals` INT UNSIGNED NOT NULL DEFAULT 0,
                `error` TEXT NULL,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_order_detail`, `unit_no`),
                KEY `id_order` (`id_order`),
                KEY `id_customer` (`id_customer`)
            )' . $tail,
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'xtreampro_account` (
                `id_customer` INT UNSIGNED NOT NULL,
                `panel_user_id` VARCHAR(40) NOT NULL DEFAULT \'\',
                `username` VARCHAR(128) NOT NULL DEFAULT \'\',
                `password` VARCHAR(255) NOT NULL DEFAULT \'\',
                `origin` VARCHAR(40) NOT NULL DEFAULT \'\',
                `disabled` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_customer`)
            )' . $tail,
        );
        foreach ($queries as $sql) {
            if (!Db::getInstance()->execute($sql)) {
                return false;
            }
        }
        return true;
    }

    // ---- small helpers -------------------------------------------------------------

    private static function str($value)
    {
        return '\'' . pSQL((string) $value, true) . '\'';
    }

    private static function run($sql)
    {
        if (!Db::getInstance()->execute($sql)) {
            throw new RuntimeException('Database error: ' . Db::getInstance()->getMsgError());
        }
    }

    /** Serialises work on one order (two status changes at the same moment). */
    public static function lock($name)
    {
        return (bool) Db::getInstance()->getValue('SELECT GET_LOCK(' . self::str('xtreampro_' . $name) . ', 10)', false);
    }

    public static function unlock($name)
    {
        Db::getInstance()->getValue('SELECT RELEASE_LOCK(' . self::str('xtreampro_' . $name) . ')', false);
    }

    // ---- XtreamproStore: units -------------------------------------------------------

    public function getUnit($detailId, $unit)
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'xtreampro_unit` WHERE `id_order_detail` = ' . (int) $detailId . ' AND `unit_no` = ' . (int) $unit,
            false
        );
        return $row ? self::unitFromRow($row) : null;
    }

    public function saveUnit($detailId, $unit, array $fields)
    {
        $set = array();
        foreach (self::$unitColumns as $field => $column) {
            if (!array_key_exists($field, $fields)) {
                continue;
            }
            $value = $fields[$field];
            if ($field === 'links') {
                $value = json_encode(is_array($value) ? $value : array());
            }
            $set[$column] = in_array($field, self::$unitInts, true) ? (string) (int) $value : self::str($value);
        }
        $now = self::str(date('Y-m-d H:i:s'));
        $columns = array('`id_order_detail`', '`unit_no`', '`date_add`', '`date_upd`');
        $values = array((int) $detailId, (int) $unit, $now, $now);
        $updates = array('`date_upd` = ' . $now);
        foreach ($set as $column => $literal) {
            $columns[] = '`' . $column . '`';
            $values[] = $literal;
            $updates[] = '`' . $column . '` = ' . $literal;
        }
        self::run(
            'INSERT INTO `' . _DB_PREFIX_ . 'xtreampro_unit` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ') '
            . 'ON DUPLICATE KEY UPDATE ' . implode(', ', $updates)
        );
    }

    private static function unitFromRow(array $row)
    {
        $links = json_decode((string) $row['links'], true);
        return array(
            'detail_id'   => (int) $row['id_order_detail'],
            'unit_no'     => (int) $row['unit_no'],
            'order_id'    => (int) $row['id_order'],
            'customer_id' => (int) $row['id_customer'],
            'kind'        => (string) $row['kind'],
            'status'      => (string) $row['status'],
            'panel_id'    => (string) $row['panel_id'],
            'username'    => (string) $row['username'],
            'password'    => (string) $row['password'],
            'links'       => is_array($links) ? $links : array(),
            'credits'     => (int) $row['credits'],
            'generation'  => (int) $row['generation'],
            'renewals'    => (int) $row['renewals'],
            'error'       => (string) $row['error'],
        );
    }

    /** @return array[] units of the given orders, in order of purchase */
    public function unitsOfOrders(array $orderIds)
    {
        $orderIds = array_filter(array_map('intval', $orderIds));
        if (!$orderIds) {
            return array();
        }
        return $this->unitsWhere('`id_order` IN (' . implode(',', $orderIds) . ')');
    }

    /** @return array[] */
    public function unitsOfCustomer($customerId)
    {
        return $this->unitsWhere('`id_customer` = ' . (int) $customerId);
    }

    private function unitsWhere($where)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'xtreampro_unit` WHERE ' . $where . ' ORDER BY `id_order`, `id_order_detail`, `unit_no`',
            true,
            false
        );
        $out = array();
        foreach ((array) $rows as $row) {
            $out[] = self::unitFromRow($row);
        }
        return $out;
    }

    // ---- XtreamproStore: accounts ----------------------------------------------------

    public function getAccount($customerId)
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'xtreampro_account` WHERE `id_customer` = ' . (int) $customerId,
            false
        );
        if (!$row) {
            return null;
        }
        return array(
            'customer_id'   => (int) $row['id_customer'],
            'panel_user_id' => (string) $row['panel_user_id'],
            'username'      => (string) $row['username'],
            'password'      => (string) $row['password'],
            'origin'        => (string) $row['origin'],
            'disabled'      => (int) $row['disabled'],
        );
    }

    public function saveAccount($customerId, array $fields)
    {
        $now = self::str(date('Y-m-d H:i:s'));
        $columns = array('`id_customer`', '`date_add`', '`date_upd`');
        $values = array((int) $customerId, $now, $now);
        $updates = array('`date_upd` = ' . $now);
        foreach (self::$accountColumns as $field => $column) {
            if (!array_key_exists($field, $fields)) {
                continue;
            }
            $literal = $field === 'disabled' ? (string) (int) $fields[$field] : self::str($fields[$field]);
            $columns[] = '`' . $column . '`';
            $values[] = $literal;
            $updates[] = '`' . $column . '` = ' . $literal;
        }
        self::run(
            'INSERT INTO `' . _DB_PREFIX_ . 'xtreampro_account` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ') '
            . 'ON DUPLICATE KEY UPDATE ' . implode(', ', $updates)
        );
    }

    // ---- products ----------------------------------------------------------------------

    /** @return array|null array(kind, package_id, trial, credits); null = not sold through Xtream UI Pro */
    public function getProduct($productId)
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'xtreampro_product` WHERE `id_product` = ' . (int) $productId,
            false
        );
        if (!$row || ($row['kind'] !== 'line' && $row['kind'] !== 'reseller')) {
            return null;
        }
        return array(
            'kind'       => (string) $row['kind'],
            'package_id' => (int) $row['package_id'],
            'trial'      => (int) $row['trial'],
            'credits'    => (int) $row['credits'],
        );
    }

    public function saveProduct($productId, $kind, $packageId, $trial, $credits)
    {
        if ($kind !== 'line' && $kind !== 'reseller') {
            self::run('DELETE FROM `' . _DB_PREFIX_ . 'xtreampro_product` WHERE `id_product` = ' . (int) $productId);
            return;
        }
        self::run(
            'REPLACE INTO `' . _DB_PREFIX_ . 'xtreampro_product` (`id_product`, `kind`, `package_id`, `trial`, `credits`) VALUES ('
            . (int) $productId . ', ' . self::str($kind) . ', ' . max(0, (int) $packageId) . ', ' . ($trial ? 1 : 0) . ', ' . max(0, (int) $credits) . ')'
        );
    }

    public function deleteProduct($productId)
    {
        self::run('DELETE FROM `' . _DB_PREFIX_ . 'xtreampro_product` WHERE `id_product` = ' . (int) $productId);
    }
}
