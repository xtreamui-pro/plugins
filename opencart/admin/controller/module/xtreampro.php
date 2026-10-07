<?php
namespace Opencart\Admin\Controller\Extension\Xtreampro\Module;

use Opencart\System\Library\Extension\Xtreampro\ApiException;
use Opencart\System\Library\Extension\Xtreampro\Client;
use Opencart\System\Library\Extension\Xtreampro\OrderService;
use Opencart\System\Library\Extension\Xtreampro\Provisioner;

/**
 * Xtream UI Pro, OpenCart 4 extension: settings page (Extensions > Modules),
 * install / uninstall, and the admin actions of the extension ("test
 * connection", "provision again", the order block). Every URL of the extension
 * lives here so the permission OpenCart grants on install covers all of them.
 *
 * @version 1.1.0
 *
 * @package Opencart\Admin\Controller\Extension\Xtreampro\Module
 */
class Xtreampro extends \Opencart\System\Engine\Controller {
	const VERSION = '1.1.0';

	/** Route of this controller (permission and URLs). */
	const ROUTE = 'extension/xtreampro/module/xtreampro';

	/**
	 * Settings page.
	 *
	 * @return void
	 */
	public function index(): void {
		$this->loadLanguage();

		$this->document->setTitle($this->language->get('heading_title'));

		$token = 'user_token=' . $this->session->data['user_token'];

		$data['breadcrumbs'] = [];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', $token)
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('xp_text_extension'),
			'href' => $this->url->link('marketplace/extension', $token . '&type=module')
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link(self::ROUTE, $token)
		];

		$data['save'] = $this->url->link(self::ROUTE . '.save', $token);
		$data['test'] = $this->url->link(self::ROUTE . '.test', $token);
		$data['back'] = $this->url->link('marketplace/extension', $token . '&type=module');

		$data['module_xtreampro_status'] = (int)$this->config->get('module_xtreampro_status');
		$data['module_xtreampro_api_url'] = (string)$this->config->get('module_xtreampro_api_url');
		$data['module_xtreampro_panel_url'] = (string)$this->config->get('module_xtreampro_panel_url');

		// The key is never sent back to the browser, only whether one is saved.
		$data['has_key'] = (string)$this->config->get('module_xtreampro_api_key') !== '';

		$this->load->model('localisation/order_status');

		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

		$paid = $this->config->get('module_xtreampro_paid_statuses');
		$revoked = $this->config->get('module_xtreampro_revoked_statuses');

		// Before the first save: processing and complete count as paid, the
		// usual "undone" statuses as revoked.
		if ($paid === null) {
			$paid = array_merge((array)$this->config->get('config_processing_status'), (array)$this->config->get('config_complete_status'));
		}

		if ($revoked === null) {
			$revoked = [];

			foreach ($data['order_statuses'] as $order_status) {
				if (in_array(strtolower($order_status['name']), ['canceled', 'cancelled', 'refunded', 'reversed', 'chargeback', 'voided', 'denied'], true)) {
					$revoked[] = $order_status['order_status_id'];
				}
			}
		}

		$data['module_xtreampro_paid_statuses'] = array_map('intval', (array)$paid);
		$data['module_xtreampro_revoked_statuses'] = array_map('intval', (array)$revoked);

		$data['version'] = self::VERSION;

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/xtreampro/module/xtreampro', $data));
	}

	/**
	 * Save the settings. A blank API key field keeps the saved key.
	 *
	 * @return void
	 */
	public function save(): void {
		$this->loadLanguage();

		$json = [];

		if (!$this->user->hasPermission('modify', self::ROUTE)) {
			$json['error']['warning'] = $this->language->get('xp_error_permission');
		}

		$status = !empty($this->request->post['module_xtreampro_status']);
		$api_url = $this->decode($this->request->post['module_xtreampro_api_url'] ?? '');
		$panel_url = $this->decode($this->request->post['module_xtreampro_panel_url'] ?? '');
		$api_key = $this->decode($this->request->post['module_xtreampro_api_key'] ?? '');
		$paid = $this->statusIds($this->request->post['module_xtreampro_paid_statuses'] ?? []);
		$revoked = $this->statusIds($this->request->post['module_xtreampro_revoked_statuses'] ?? []);

		$saved_key = (string)$this->config->get('module_xtreampro_api_key');

		if ($api_key === '') {
			$api_key = $saved_key;
		}

		if ($api_url !== '' && !$this->isHttpUrl($api_url)) {
			$json['error']['api_url'] = $this->language->get('xp_error_api_url');
		}

		if ($panel_url !== '' && !$this->isHttpUrl($panel_url)) {
			$json['error']['panel_url'] = $this->language->get('xp_error_panel_url');
		}

		if ($status && $api_url === '') {
			$json['error']['api_url'] = $this->language->get('xp_error_api_url');
		}

		if ($status && $api_key === '') {
			$json['error']['api_key'] = $this->language->get('xp_error_api_key');
		}

		if (array_intersect($paid, $revoked)) {
			$json['error']['warning'] = $this->language->get('xp_error_status_overlap');
		}

		if (!$json) {
			$this->load->model('setting/setting');

			$api_url = rtrim($api_url, '/');

			$this->model_setting_setting->editSetting('module_xtreampro', [
				'module_xtreampro_status'           => (int)$status,
				'module_xtreampro_api_url'          => $api_url,
				'module_xtreampro_api_key'          => $api_key,
				'module_xtreampro_panel_url'        => rtrim($panel_url, '/'),
				'module_xtreampro_paid_statuses'    => $paid,
				'module_xtreampro_revoked_statuses' => $revoked
			]);

			// The package list belongs to the old URL / key.
			if ($api_url !== (string)$this->config->get('module_xtreampro_api_url') || $api_key !== $saved_key) {
				$this->model_setting_setting->deleteSetting('module_xtreampro_cache');
			}

			$json['success'] = $this->language->get('xp_text_success');
		}

		$this->json($json);
	}

	/**
	 * Test connection with the values in the form (a blank key field uses the
	 * saved key). When they are the saved ones the package list is refreshed too.
	 *
	 * @return void
	 */
	public function test(): void {
		$this->loadLanguage();

		$json = [];

		if (!$this->user->hasPermission('modify', self::ROUTE)) {
			$json['error'] = $this->language->get('xp_error_permission');
		}

		if (!$json) {
			$api_url = rtrim($this->decode($this->request->post['module_xtreampro_api_url'] ?? ''), '/');
			$api_key = $this->decode($this->request->post['module_xtreampro_api_key'] ?? '');

			if ($api_url === '') {
				$api_url = (string)$this->config->get('module_xtreampro_api_url');
			}

			if ($api_key === '') {
				$api_key = (string)$this->config->get('module_xtreampro_api_key');
			}

			try {
				$provisioner = new Provisioner(new Client($api_url, $api_key));

				$info = $provisioner->testConnection();
				$options = $provisioner->packageOptions();

				if ($api_url === rtrim((string)$this->config->get('module_xtreampro_api_url'), '/') && $api_key === (string)$this->config->get('module_xtreampro_api_key')) {
					$this->load->model('extension/xtreampro/module/xtreampro');

					$this->model_extension_xtreampro_module_xtreampro->storePackages($options);
				}

				$json['success'] = sprintf($this->language->get('xp_text_connected'), $info['username'], $info['credits'], count($options));
			} catch (ApiException $e) {
				$json['error'] = $e->getMessage();
			}
		}

		$this->json($json);
	}

	/**
	 * Admin order page: provision the order again (units already done are left
	 * alone, failed ones are retried).
	 *
	 * @return void
	 */
	public function provision(): void {
		$this->loadLanguage();

		$json = [];

		if (!$this->user->hasPermission('modify', self::ROUTE)) {
			$json['error'] = $this->language->get('xp_error_permission');
		}

		$order_id = isset($this->request->post['order_id']) ? (int)$this->request->post['order_id'] : 0;

		if (!$json && $order_id <= 0) {
			$json['error'] = $this->language->get('xp_error_order');
		}

		if (!$json) {
			$result = $this->orderService()->provisionAgain($order_id);

			if ($result['ok']) {
				$json['success'] = $result['message'];
			} else {
				$json['error'] = $result['message'];
			}
		}

		$this->json($json);
	}

	/**
	 * HTML of the units table of an order (reloaded after "provision again").
	 *
	 * @return void
	 */
	public function units(): void {
		$order_id = isset($this->request->get['order_id']) ? (int)$this->request->get['order_id'] : 0;

		$this->response->setOutput($this->unitsHtml($order_id));
	}

	/**
	 * The units table, also used by the order block event.
	 *
	 * @param int $order_id
	 *
	 * @return string
	 */
	public function unitsHtml(int $order_id = 0): string {
		$this->loadLanguage();

		$statuses = [
			''        => $this->language->get('xp_status_new'),
			'done'    => $this->language->get('xp_status_done'),
			'failed'  => $this->language->get('xp_status_failed'),
			'revoked' => $this->language->get('xp_status_revoked')
		];

		$data['units'] = [];

		foreach ($this->orderService()->getStore()->getUnitsByOrder($order_id) as $unit) {
			$data['units'][] = [
				'name'     => (string)$unit['product_name'],
				'unit'     => $unit['unit'],
				'kind'     => $unit['kind'] === 'reseller' ? $this->language->get('xp_kind_reseller') : $this->language->get('xp_kind_line'),
				'status'   => $statuses[$unit['status']] ?? $unit['status'],
				'panel_id' => $unit['panel_id'],
				'username' => $unit['username'],
				'password' => $unit['kind'] === 'reseller' && !$unit['created'] ? '' : $unit['password'],
				'credits'  => $unit['credits'],
				'error'    => $unit['error']
			];
		}

		return $this->load->view('extension/xtreampro/event/order_units', $data);
	}

	/**
	 * Called by OpenCart when the module is installed (Extensions > Modules).
	 *
	 * @return void
	 */
	public function install(): void {
		if ($this->user->hasPermission('modify', 'extension/module')) {
			$this->load->model('extension/xtreampro/module/xtreampro');

			$this->model_extension_xtreampro_module_xtreampro->install();
		}
	}

	/**
	 * Called by OpenCart when the module is uninstalled.
	 *
	 * @return void
	 */
	public function uninstall(): void {
		if ($this->user->hasPermission('modify', 'extension/module')) {
			$this->load->model('extension/xtreampro/module/xtreampro');

			$this->model_extension_xtreampro_module_xtreampro->uninstall();
		}
	}

	// ---- helpers ---------------------------------------------------------------------------------

	/**
	 * English first, then the language of the admin on top, so a missing
	 * translation shows the English text instead of the key.
	 */
	private function loadLanguage(): void {
		$this->load->language('extension/xtreampro/module/xtreampro', '', 'en-gb');
		$this->load->language('extension/xtreampro/module/xtreampro');
	}

	private function orderService(): OrderService {
		return new OrderService($this->db, DB_PREFIX, OrderService::settingsFrom($this->config), function (string $message): void {
			$this->log->write('Xtream UI Pro: ' . $message);
		});
	}

	private function json(array $json): void {
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * OpenCart escapes request values for HTML; the URL and the key are stored as typed.
	 */
	private function decode($value): string {
		return trim(html_entity_decode((string)$value, ENT_QUOTES, 'UTF-8'));
	}

	private function isHttpUrl(string $url): bool {
		$parts = parse_url($url);

		return filter_var($url, FILTER_VALIDATE_URL) !== false && isset($parts['scheme']) && in_array(strtolower($parts['scheme']), ['http', 'https'], true);
	}

	/**
	 * @return int[]
	 */
	private function statusIds($value): array {
		return array_values(array_unique(array_filter(array_map('intval', (array)$value))));
	}
}
