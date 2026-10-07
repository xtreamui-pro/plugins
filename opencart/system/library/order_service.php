<?php
namespace Opencart\System\Library\Extension\Xtreampro;

/**
 * Glue between an OpenCart order and the Provisioner: reads the order and the
 * product settings, runs the provisioner for every unit and stores what it
 * returns. Used by the catalog event (order reaches a paid / revoked status),
 * the admin "provision again" button and the pages that show credentials.
 *
 * It needs the OpenCart database object and the extension settings, nothing
 * else, so the catalog and the admin side share it.
 */
class OrderService {
	private $db;
	private string $prefix;
	private array $settings;
	/** @var callable|null */
	private $log;
	private Store $store;
	private ?Provisioner $provisioner = null;

	/**
	 * @param object        $db       OpenCart's $this->db
	 * @param string        $prefix   DB_PREFIX
	 * @param array         $settings result of settingsFrom()
	 * @param callable|null $log      function (string $message): void
	 */
	public function __construct($db, string $prefix, array $settings, ?callable $log = null) {
		$this->db = $db;
		$this->prefix = $prefix;
		$this->settings = $settings;
		$this->log = $log;
		$this->store = new Store($db, $prefix);
	}

	/**
	 * The extension settings out of OpenCart's $this->config.
	 */
	public static function settingsFrom($config): array {
		return [
			'status'           => (bool)$config->get('module_xtreampro_status'),
			'api_url'          => (string)$config->get('module_xtreampro_api_url'),
			'api_key'          => (string)$config->get('module_xtreampro_api_key'),
			'panel_url'        => (string)$config->get('module_xtreampro_panel_url'),
			'instance'         => preg_replace('/[^a-z0-9]/', '', strtolower((string)$config->get('module_xtreampro_instance'))),
			'paid_statuses'    => array_map('intval', (array)$config->get('module_xtreampro_paid_statuses')),
			'revoked_statuses' => array_map('intval', (array)$config->get('module_xtreampro_revoked_statuses'))
		];
	}

	public function getStore(): Store {
		return $this->store;
	}

	public function isEnabled(): bool {
		return $this->settings['status'];
	}

	/**
	 * Sign-in address of the panel's dashboard for sub-resellers, '' when not set.
	 */
	public function getPanelLoginUrl(): string {
		$url = rtrim(trim($this->settings['panel_url']), '/');

		return $url === '' ? '' : $url . '/login';
	}

	/**
	 * The provisioner, or an ApiException('CONFIG') when URL or key are missing.
	 */
	public function getProvisioner(): Provisioner {
		if ($this->provisioner === null) {
			$log = $this->log;

			$this->provisioner = new Provisioner(new Client($this->settings['api_url'], $this->settings['api_key'], $log), $log, 'oc' . ($this->settings['instance'] ?? ''));
		}

		return $this->provisioner;
	}

	// ---- order status changes ------------------------------------------------------------------------

	/**
	 * Called after every order history entry: provision when the order is now in a
	 * paid status, revoke when it is in a revoked one, otherwise do nothing.
	 * Repeating it is harmless.
	 */
	public function handleStatus(int $order_id): void {
		$order = $this->getOrder($order_id);

		if (!$order) {
			return;
		}

		if (in_array((int)$order['order_status_id'], $this->settings['paid_statuses'], true)) {
			$this->provision($order);
		} elseif (in_array((int)$order['order_status_id'], $this->settings['revoked_statuses'], true)) {
			$this->revoke($order);
		}
	}

	/**
	 * "Provision again" of the admin: only for an order that is in a paid status.
	 *
	 * @return array ['ok' => bool, 'message' => string]
	 */
	public function provisionAgain(int $order_id): array {
		$order = $this->getOrder($order_id);

		if (!$order) {
			return ['ok' => false, 'message' => 'The order does not exist.'];
		}

		if (!in_array((int)$order['order_status_id'], $this->settings['paid_statuses'], true)) {
			return ['ok' => false, 'message' => 'The order is not in a status that counts as paid (see the extension settings).'];
		}

		if (!$this->provision($order)) {
			return ['ok' => false, 'message' => 'The order is being provisioned right now. Try again in a moment.'];
		}

		$failed = 0;
		$total = 0;

		foreach ($this->store->getUnitsByOrder($order_id) as $unit) {
			$total++;

			if ($unit['status'] !== Provisioner::DONE) {
				$failed++;
			}
		}

		if ($total === 0) {
			return ['ok' => true, 'message' => 'The order contains no Xtream UI Pro product.'];
		}

		if ($failed) {
			return ['ok' => false, 'message' => $failed . ' of ' . $total . ' items are still not provisioned. See the errors in the table.'];
		}

		return ['ok' => true, 'message' => 'All ' . $total . ' items are provisioned.'];
	}

	/**
	 * Create / enable what the order bought. Returns false when another request
	 * is already doing it.
	 */
	public function provision(array $order): bool {
		$work = $this->plan($order);

		if (!$work) {
			return true;
		}

		$order_id = (int)$order['order_id'];

		if (!$this->store->lockOrder($order_id)) {
			return false;
		}

		try {
			$provisioner = null;
			$config_error = null;

			try {
				$provisioner = $this->getProvisioner();
			} catch (ApiException $e) {
				// Not configured: every unit records why, so the admin sees it.
				$config_error = $e;
			}

			foreach ($work as $item) {
				$cfg = $item['cfg'];

				if ($cfg['kind'] === Provisioner::RESELLER) {
					$this->provisionResellerItem($provisioner, $config_error, $order, $item);

					continue;
				}

				for ($n = 1; $n <= $item['quantity']; $n++) {
					$unit = $this->store->getUnit($item['order_product_id'], $n) ?? Provisioner::newUnit(Provisioner::LINE, $order_id, $item['order_product_id'], $n, (int)$order['customer_id']);

					if ($provisioner) {
						$unit = $provisioner->provisionLine($unit, $cfg);
					} elseif ($unit['status'] !== Provisioner::DONE) {
						$unit['status'] = Provisioner::FAILED;
						$unit['error'] = $config_error->getMessage();
					}

					$this->store->saveUnit($unit);
				}
			}
		} finally {
			$this->store->releaseOrder($order_id);
		}

		return true;
	}

	/**
	 * Disable the lines and take back the credits of an order that was cancelled
	 * or refunded.
	 */
	public function revoke(array $order): void {
		$units = $this->store->getUnitsByOrder((int)$order['order_id']);

		if (!$units) {
			return;
		}

		$order_id = (int)$order['order_id'];

		if (!$this->store->lockOrder($order_id)) {
			return;
		}

		try {
			$provisioner = $this->getProvisioner();

			foreach ($units as $unit) {
				if ($unit['kind'] === Provisioner::RESELLER) {
					$unit = $provisioner->revokeReseller($unit);
				} else {
					$unit = $provisioner->revokeLine($unit);
				}

				$this->store->saveUnit($unit);
			}
		} catch (ApiException $e) {
			$this->note('order ' . $order_id . ': could not revoke: ' . $e->getErrorCode());
		} finally {
			$this->store->releaseOrder($order_id);
		}
	}

	// ---- what the customer sees --------------------------------------------------------------------------

	/**
	 * Credentials of an order for its owner. Empty for anybody else (and for guest orders).
	 *
	 * @return array ['lines' => [...], 'accounts' => [...]]; see describeLines() and describeAccounts()
	 */
	public function getOrderView(int $order_id, int $customer_id): array {
		$units = [];

		if ($customer_id > 0) {
			foreach ($this->store->getUnitsByOrder($order_id) as $unit) {
				if ($unit['customer_id'] === $customer_id && $unit['panel_id'] !== '') {
					$units[] = $unit;
				}
			}
		}

		return $this->describe($units, true);
	}

	/**
	 * Every line and the sub-reseller account of a customer, for the account page.
	 */
	public function getAccountView(int $customer_id): array {
		if ($customer_id <= 0) {
			return ['lines' => [], 'accounts' => []];
		}

		$units = $this->store->getLinesByCustomer($customer_id);
		$account = $this->store->getAccount($customer_id);

		if ($account) {
			$units[] = [
				'kind'        => Provisioner::RESELLER,
				'panel_id'    => $account['panel_id'],
				'username'    => $account['username'],
				'password'    => '',
				'status'      => Provisioner::DONE,
				'credits'     => 0,
				'created'     => 0,
				'error'       => '',
				'order_id'    => 0,
				'product_name' => ''
			];
		}

		return $this->describe($units, false);
	}

	/**
	 * Stored data plus the live view of the panel.
	 *
	 * @param bool $show_password show a sub-reseller's password (only the order that created the account)
	 */
	private function describe(array $units, bool $show_password): array {
		$view = ['lines' => [], 'accounts' => []];

		if (!$units) {
			return $view;
		}

		$provisioner = null;

		try {
			$provisioner = $this->getProvisioner();
		} catch (ApiException $e) {
			// Without a connection only the stored data is shown.
		}

		$line_units = [];

		foreach ($units as $key => $unit) {
			if ($unit['kind'] === Provisioner::LINE) {
				$line_units[$key] = $unit;
			}
		}

		$live = $provisioner ? $provisioner->lineDetailsList($line_units) : [];

		foreach ($units as $key => $unit) {
			if ($unit['kind'] === Provisioner::LINE) {
				$details = $live[$key] ?? ['live' => false, 'status' => '', 'expires' => 0, 'max_connections' => 0, 'links' => [], 'error' => ''];

				$view['lines'][] = [
					'name'            => (string)($unit['product_name'] ?? ''),
					'order_id'        => (int)$unit['order_id'],
					'panel_id'        => $unit['panel_id'],
					'username'        => $unit['username'],
					'password'        => $unit['password'],
					'revoked'         => $unit['status'] === Provisioner::REVOKED,
					'live'            => $details['live'],
					'status'          => $details['status'],
					'expires'         => $details['expires'],
					'max_connections' => $details['max_connections'],
					'links'           => $details['links'],
					'server'          => isset($details['links']['server']) ? $details['links']['server'] : rtrim($this->settings['api_url'], '/'),
					'error'           => $details['error']
				];

				continue;
			}

			$account = $provisioner ? $provisioner->accountDetails($unit['panel_id']) : ['live' => false, 'username' => '', 'status' => '', 'credits' => 0, 'error' => ''];

			$view['accounts'][] = [
				'name'     => (string)($unit['product_name'] ?? ''),
				'order_id' => (int)$unit['order_id'],
				'username' => $account['username'] !== '' ? $account['username'] : $unit['username'],
				'password' => $show_password && $unit['created'] ? $unit['password'] : '',
				'credits'  => $unit['credits'],
				'revoked'  => $unit['status'] === Provisioner::REVOKED,
				'live'     => $account['live'],
				'status'   => $account['status'],
				'balance'  => $account['credits'],
				'login'    => $this->getPanelLoginUrl(),
				'error'    => $account['error']
			];
		}

		return $view;
	}

	// ---- internals ---------------------------------------------------------------------------------------------

	private function getOrder(int $order_id): ?array {
		$query = $this->db->query("SELECT `order_id`, `customer_id`, `order_status_id`, `email`, `firstname`, `lastname` FROM `" . $this->prefix . "order` WHERE `order_id` = '" . $order_id . "'");

		return $query->num_rows ? $query->row : null;
	}

	/**
	 * The items of the order that sell something: order product id, quantity and
	 * the settings of the product (a variant uses its master's when it has none).
	 */
	private function plan(array $order): array {
		$work = [];

		$query = $this->db->query("SELECT `order_product_id`, `product_id`, `master_id`, `quantity` FROM `" . $this->prefix . "order_product` WHERE `order_id` = '" . (int)$order['order_id'] . "' ORDER BY `order_product_id`");

		foreach ($query->rows as $row) {
			$cfg = $this->store->getProduct((int)$row['product_id']);

			if ($cfg === null && (int)$row['master_id'] > 0) {
				$cfg = $this->store->getProduct((int)$row['master_id']);
			}

			if ($cfg !== null) {
				$work[] = ['order_product_id' => (int)$row['order_product_id'], 'quantity' => max(1, (int)$row['quantity']), 'cfg' => $cfg];
			}
		}

		return $work;
	}

	/**
	 * One sub-reseller item: one unit, credits = per unit x quantity.
	 */
	private function provisionResellerItem(?Provisioner $provisioner, ?ApiException $config_error, array $order, array $item): void {
		$order_id = (int)$order['order_id'];
		$customer_id = (int)$order['customer_id'];

		$unit = $this->store->getUnit($item['order_product_id'], 1) ?? Provisioner::newUnit(Provisioner::RESELLER, $order_id, $item['order_product_id'], 1, $customer_id);

		if ($provisioner) {
			$store = $this->store;

			$unit = $provisioner->provisionReseller(
				$unit,
				['credits' => $item['cfg']['credits'] * $item['quantity']],
				$store->getAccount($customer_id, $item['order_product_id'], 1),
				['username_seed' => trim($order['firstname'] . $order['lastname']), 'email' => $order['email'], 'fullname' => trim($order['firstname'] . ' ' . $order['lastname'])],
				function (array $partial) use ($store): void {
					$store->saveUnit($partial);
				}
			);
		} elseif ($unit['status'] !== Provisioner::DONE) {
			$unit['status'] = Provisioner::FAILED;
			$unit['error'] = $config_error->getMessage();
		}

		$this->store->saveUnit($unit);
	}

	private function note(string $message): void {
		if ($this->log) {
			($this->log)($message);
		}
	}
}
