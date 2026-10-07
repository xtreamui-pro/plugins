<?php
namespace Opencart\Admin\Model\Extension\Xtreampro\Module;

/**
 * Install / uninstall of the extension (tables and events) and the cached
 * package list of the panel.
 *
 * @package Opencart\Admin\Model\Extension\Xtreampro\Module
 */
class Xtreampro extends \Opencart\System\Engine\Model {
	/** How long the package list is reused before it is asked from the panel again. */
	const PACKAGE_CACHE_SECONDS = 3600;

	/**
	 * Events the extension registers. Nothing of OpenCart's core is modified: the
	 * extension only listens to these.
	 *
	 * @return array [code => [description, trigger, action]]
	 */
	private function events(): array {
		return [
			'xtreampro_order_history' => ['Xtream UI Pro: provision or revoke when the order status changes', 'catalog/model/checkout/order/addHistory/after', 'extension/xtreampro/event/order.history'],
			'xtreampro_order_info'    => ['Xtream UI Pro: credentials on the customer order page', 'catalog/view/account/order_info/before', 'extension/xtreampro/event/order.info'],
			'xtreampro_account'       => ['Xtream UI Pro: link to the IPTV page on the customer account page', 'catalog/view/account/account/before', 'extension/xtreampro/event/order.account'],
			'xtreampro_product_form'  => ['Xtream UI Pro: tab on the product form', 'admin/view/catalog/product_form/after', 'extension/xtreampro/event/product.form'],
			'xtreampro_product_add'   => ['Xtream UI Pro: save the product tab (new product)', 'admin/model/catalog/product/addProduct/after', 'extension/xtreampro/event/product.add'],
			'xtreampro_product_edit'  => ['Xtream UI Pro: save the product tab (edited product)', 'admin/model/catalog/product/editProduct/after', 'extension/xtreampro/event/product.edit'],
			'xtreampro_product_delete' => ['Xtream UI Pro: forget the settings of a deleted product', 'admin/model/catalog/product/deleteProduct/after', 'extension/xtreampro/event/product.delete'],
			'xtreampro_order_block'   => ['Xtream UI Pro: block on the admin order page', 'admin/view/sale/order_info/after', 'extension/xtreampro/event/order.block']
		];
	}

	/**
	 * Create the tables and register the events.
	 */
	public function install(): void {
		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "xtreampro_product` (
			`product_id` int(11) NOT NULL,
			`kind` varchar(10) NOT NULL DEFAULT 'line',
			`package_id` int(11) NOT NULL DEFAULT '0',
			`trial` tinyint(1) NOT NULL DEFAULT '0',
			`credits` int(11) NOT NULL DEFAULT '0',
			PRIMARY KEY (`product_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "xtreampro_unit` (
			`order_product_id` int(11) NOT NULL,
			`unit` int(11) NOT NULL,
			`order_id` int(11) NOT NULL,
			`customer_id` int(11) NOT NULL DEFAULT '0',
			`kind` varchar(10) NOT NULL,
			`status` varchar(10) NOT NULL DEFAULT '',
			`panel_id` varchar(64) NOT NULL DEFAULT '',
			`username` varchar(128) NOT NULL DEFAULT '',
			`password` varchar(128) NOT NULL DEFAULT '',
			`created` tinyint(1) NOT NULL DEFAULT '0',
			`credits` int(11) NOT NULL DEFAULT '0',
			`generation` int(11) NOT NULL DEFAULT '0',
			`error` text NOT NULL,
			`date_added` datetime NOT NULL,
			`date_modified` datetime NOT NULL,
			PRIMARY KEY (`order_product_id`, `unit`),
			KEY `order_id` (`order_id`),
			KEY `customer_id` (`customer_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

		// A random token of this shop for the request ids sent to the panel (see
		// Provisioner). Created once and kept when the extension is uninstalled.
		$this->load->model('setting/setting');

		$instance = $this->model_setting_setting->getSetting('module_xtreampro_instance');

		if (empty($instance['module_xtreampro_instance'])) {
			$this->model_setting_setting->editSetting('module_xtreampro_instance', ['module_xtreampro_instance' => bin2hex(random_bytes(4))]);
		}

		$this->load->model('setting/event');

		foreach ($this->events() as $code => $event) {
			$this->model_setting_event->deleteEventByCode($code);

			$this->model_setting_event->addEvent([
				'code'        => $code,
				'description' => $event[0],
				'trigger'     => $event[1],
				'action'      => $event[2],
				'status'      => 1,
				'sort_order'  => 0
			]);
		}
	}

	/**
	 * Remove the events and the settings (the API key included). The tables and the
	 * shop token stay: they belong to the lines that were sold. Drop the tables by
	 * hand when you are sure (see the README).
	 */
	public function uninstall(): void {
		$this->load->model('setting/event');

		foreach (array_keys($this->events()) as $code) {
			$this->model_setting_event->deleteEventByCode($code);
		}

		$this->load->model('setting/setting');

		$this->model_setting_setting->deleteSetting('module_xtreampro');
		$this->model_setting_setting->deleteSetting('module_xtreampro_cache');
	}

	/**
	 * Packages of the panel for the product form: from the cache while it is
	 * fresh, otherwise asked from the panel with the saved settings. When the panel
	 * cannot be reached the old list is returned together with the error.
	 *
	 * @return array ['options' => [['id' => int, 'label' => string], ...], 'error' => string]
	 */
	public function getPackages(): array {
		$this->load->model('setting/setting');

		$cache = $this->model_setting_setting->getSetting('module_xtreampro_cache');

		$options = isset($cache['module_xtreampro_cache_packages']) && is_array($cache['module_xtreampro_cache_packages']) ? $cache['module_xtreampro_cache_packages'] : [];
		$time = isset($cache['module_xtreampro_cache_time']) ? (int)$cache['module_xtreampro_cache_time'] : 0;

		if ($options && (time() - $time) < self::PACKAGE_CACHE_SECONDS) {
			return ['options' => $options, 'error' => ''];
		}

		try {
			$client = new \Opencart\System\Library\Extension\Xtreampro\Client((string)$this->config->get('module_xtreampro_api_url'), (string)$this->config->get('module_xtreampro_api_key'));

			$options = (new \Opencart\System\Library\Extension\Xtreampro\Provisioner($client))->packageOptions();

			$this->storePackages($options);

			return ['options' => $options, 'error' => ''];
		} catch (\Opencart\System\Library\Extension\Xtreampro\ApiException $e) {
			return ['options' => $options, 'error' => $e->getMessage()];
		}
	}

	/**
	 * @param array $options list of ['id' => int, 'label' => string]
	 */
	public function storePackages(array $options): void {
		$this->load->model('setting/setting');

		$this->model_setting_setting->editSetting('module_xtreampro_cache', [
			'module_xtreampro_cache_packages' => $options,
			'module_xtreampro_cache_time'     => time()
		]);
	}
}
