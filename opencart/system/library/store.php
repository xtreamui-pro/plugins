<?php
namespace Opencart\System\Library\Extension\Xtreampro;

/**
 * The extension's own tables.
 *
 *   xtreampro_product  settings per product (what buying it sells)
 *   xtreampro_unit     result per bought unit, keyed by order product id + unit number
 *
 * OpenCart glue: it only needs an object with query(), escape() and getLastId()
 * (OpenCart's $this->db). The tables are created by the admin model on install.
 */
class Store {
	private $db;
	private string $prefix;

	public function __construct($db, string $prefix) {
		$this->db = $db;
		$this->prefix = $prefix;
	}

	// ---- product settings --------------------------------------------------------------------

	/**
	 * @return array|null ['kind' => 'line'|'reseller', 'package_id' => int, 'trial' => bool, 'credits' => int]
	 *                    or null when the product sells nothing
	 */
	public function getProduct(int $product_id): ?array {
		$query = $this->db->query("SELECT * FROM `" . $this->prefix . "xtreampro_product` WHERE `product_id` = '" . $product_id . "'");

		if (!$query->num_rows) {
			return null;
		}

		$row = $query->row;

		if ($row['kind'] !== 'line' && $row['kind'] !== 'reseller') {
			return null;
		}

		return [
			'kind'       => $row['kind'],
			'package_id' => (int)$row['package_id'],
			'trial'      => (bool)$row['trial'],
			'credits'    => (int)$row['credits']
		];
	}

	/**
	 * @param string $kind '' removes the settings (the product sells nothing)
	 */
	public function saveProduct(int $product_id, string $kind, int $package_id, bool $trial, int $credits): void {
		$this->deleteProduct($product_id);

		if ($kind === 'line' || $kind === 'reseller') {
			$this->db->query("INSERT INTO `" . $this->prefix . "xtreampro_product` SET `product_id` = '" . $product_id . "', `kind` = '" . $this->db->escape($kind) . "', `package_id` = '" . max(0, $package_id) . "', `trial` = '" . (int)$trial . "', `credits` = '" . max(0, $credits) . "'");
		}
	}

	public function deleteProduct(int $product_id): void {
		$this->db->query("DELETE FROM `" . $this->prefix . "xtreampro_product` WHERE `product_id` = '" . $product_id . "'");
	}

	// ---- units ---------------------------------------------------------------------------------

	public function getUnit(int $order_product_id, int $unit): ?array {
		$query = $this->db->query("SELECT * FROM `" . $this->prefix . "xtreampro_unit` WHERE `order_product_id` = '" . $order_product_id . "' AND `unit` = '" . $unit . "'");

		return $query->num_rows ? $this->cast($query->row) : null;
	}

	/**
	 * All units of an order, with the product name, in order.
	 */
	public function getUnitsByOrder(int $order_id): array {
		$query = $this->db->query("SELECT u.*, op.`name` AS `product_name` FROM `" . $this->prefix . "xtreampro_unit` u LEFT JOIN `" . $this->prefix . "order_product` op ON (op.`order_product_id` = u.`order_product_id`) WHERE u.`order_id` = '" . $order_id . "' ORDER BY u.`order_product_id`, u.`unit`");

		return array_map([$this, 'cast'], $query->rows);
	}

	/**
	 * Lines of a customer (all orders), newest first.
	 */
	public function getLinesByCustomer(int $customer_id, int $limit = 50): array {
		$query = $this->db->query("SELECT u.*, op.`name` AS `product_name` FROM `" . $this->prefix . "xtreampro_unit` u LEFT JOIN `" . $this->prefix . "order_product` op ON (op.`order_product_id` = u.`order_product_id`) WHERE u.`customer_id` = '" . $customer_id . "' AND u.`kind` = 'line' AND u.`panel_id` <> '' ORDER BY u.`date_added` DESC, u.`order_product_id` DESC, u.`unit` DESC LIMIT " . max(1, $limit));

		return array_map([$this, 'cast'], $query->rows);
	}

	/**
	 * The sub-reseller account of a customer: the oldest reseller unit that holds
	 * one, other than $except (the unit being worked on).
	 *
	 * @return array ['panel_id' => string, 'username' => string], or [] when the customer has none
	 */
	public function getAccount(int $customer_id, int $except_order_product_id = 0, int $except_unit = 0): array {
		$query = $this->db->query("SELECT `panel_id`, `username` FROM `" . $this->prefix . "xtreampro_unit` WHERE `customer_id` = '" . $customer_id . "' AND `kind` = 'reseller' AND `panel_id` <> '' AND NOT (`order_product_id` = '" . $except_order_product_id . "' AND `unit` = '" . $except_unit . "') ORDER BY `date_added` ASC, `order_product_id` ASC LIMIT 1");

		return $query->num_rows ? ['panel_id' => (string)$query->row['panel_id'], 'username' => (string)$query->row['username']] : [];
	}

	/**
	 * Insert or update one unit (the key is order product id + unit number).
	 */
	public function saveUnit(array $unit): void {
		$this->db->query("INSERT INTO `" . $this->prefix . "xtreampro_unit` SET `order_product_id` = '" . (int)$unit['order_product_id'] . "', `unit` = '" . (int)$unit['unit'] . "', `order_id` = '" . (int)$unit['order_id'] . "', `customer_id` = '" . (int)$unit['customer_id'] . "', `kind` = '" . $this->db->escape($unit['kind']) . "', `status` = '" . $this->db->escape($unit['status']) . "', `panel_id` = '" . $this->db->escape($unit['panel_id']) . "', `username` = '" . $this->db->escape($unit['username']) . "', `password` = '" . $this->db->escape($unit['password']) . "', `created` = '" . (int)$unit['created'] . "', `credits` = '" . (int)$unit['credits'] . "', `generation` = '" . (int)$unit['generation'] . "', `error` = '" . $this->db->escape($unit['error']) . "', `date_added` = NOW(), `date_modified` = NOW() ON DUPLICATE KEY UPDATE `customer_id` = VALUES(`customer_id`), `status` = VALUES(`status`), `panel_id` = VALUES(`panel_id`), `username` = VALUES(`username`), `password` = VALUES(`password`), `created` = VALUES(`created`), `credits` = VALUES(`credits`), `generation` = VALUES(`generation`), `error` = VALUES(`error`), `date_modified` = NOW()");
	}

	// ---- order level lock -------------------------------------------------------------------------

	/**
	 * Only one request provisions an order at a time (a payment callback and the
	 * customer's return can arrive together). The lock is per database connection
	 * and is released with releaseOrder().
	 */
	public function lockOrder(int $order_id): bool {
		$query = $this->db->query("SELECT GET_LOCK('xtreampro_order_" . $order_id . "', 0) AS `locked`");

		return (bool)$query->row['locked'];
	}

	public function releaseOrder(int $order_id): void {
		$this->db->query("SELECT RELEASE_LOCK('xtreampro_order_" . $order_id . "')");
	}

	private function cast(array $row): array {
		foreach (['order_id', 'order_product_id', 'unit', 'customer_id', 'created', 'credits', 'generation'] as $key) {
			$row[$key] = (int)$row[$key];
		}

		return $row;
	}
}
