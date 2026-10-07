<?php
namespace Opencart\Catalog\Controller\Extension\Xtreampro\Event;

use Opencart\System\Library\Extension\Xtreampro\CredentialsView;
use Opencart\System\Library\Extension\Xtreampro\OrderService;

/**
 * Storefront side of the extension, all through events (no core file is
 * changed):
 *
 *  - history(): after every order history entry, provision or revoke
 *  - info():    credentials block on the customer's order page
 *  - account(): link to the IPTV page on the customer's account page
 *
 * An error here must never break checkout or the page, so everything is caught
 * and logged without its message (database errors contain the SQL, which can
 * contain a line password).
 *
 * @package Opencart\Catalog\Controller\Extension\Xtreampro\Event
 */
class Order extends \Opencart\System\Engine\Controller {
	/**
	 * Event catalog/model/checkout/order/addHistory/after
	 *
	 * @param string $route
	 * @param array  $args   order_id, order_status_id, comment, notify, override
	 * @param mixed  $output
	 *
	 * @return void
	 */
	public function history(string &$route, array &$args, mixed &$output): void {
		if (!$this->config->get('module_xtreampro_status') || !isset($args[0])) {
			return;
		}

		try {
			$this->service()->handleStatus((int)$args[0]);
		} catch (\Throwable $e) {
			$this->logError('order ' . (int)$args[0], $e);
		}
	}

	/**
	 * Event catalog/view/account/order_info/before: appended to content_bottom,
	 * the module position under the order. Only the customer the order belongs to
	 * can open this page, and the lines are matched to that customer again.
	 *
	 * @param string $route
	 * @param array  $data
	 *
	 * @return void
	 */
	public function info(string &$route, array &$data): void {
		if (!$this->config->get('module_xtreampro_status') || !$this->customer->isLogged() || empty($data['order_id'])) {
			return;
		}

		try {
			$view = $this->service()->getOrderView((int)$data['order_id'], (int)$this->customer->getId());

			if (!$view['lines'] && !$view['accounts']) {
				return;
			}

			$this->loadLanguage();

			$data['content_bottom'] = (string)($data['content_bottom'] ?? '') . $this->load->view('extension/xtreampro/account/credentials', $this->credentialsData($view));
		} catch (\Throwable $e) {
			$this->logError('order page ' . (int)$data['order_id'], $e);
		}
	}

	/**
	 * Event catalog/view/account/account/before: a link under the account menu.
	 * Shown only to customers who have something (a line or an account).
	 *
	 * @param string $route
	 * @param array  $data
	 *
	 * @return void
	 */
	public function account(string &$route, array &$data): void {
		if (!$this->config->get('module_xtreampro_status') || !$this->customer->isLogged() || !isset($this->session->data['customer_token'])) {
			return;
		}

		try {
			$store = $this->service()->getStore();
			$customer_id = (int)$this->customer->getId();

			if (!$store->getLinesByCustomer($customer_id, 1) && !$store->getAccount($customer_id)) {
				return;
			}

			$this->loadLanguage();

			$view['link'] = $this->url->link('extension/xtreampro/account/lines', 'language=' . $this->config->get('config_language') . '&customer_token=' . $this->session->data['customer_token']);

			$data['content_bottom'] = (string)($data['content_bottom'] ?? '') . $this->load->view('extension/xtreampro/account/link', $view);
		} catch (\Throwable $e) {
			$this->logError('account page', $e);
		}
	}

	/**
	 * English first, then the shop language on top: a missing translation shows English.
	 */
	private function loadLanguage(): void {
		$this->load->language('extension/xtreampro/xtreampro', '', 'en-gb');
		$this->load->language('extension/xtreampro/xtreampro');
	}

	private function service(): OrderService {
		return new OrderService($this->db, DB_PREFIX, OrderService::settingsFrom($this->config), function (string $message): void {
			$this->log->write('Xtream UI Pro: ' . $message);
		});
	}

	/**
	 * Template data of the credentials block.
	 */
	private function credentialsData(array $view): array {
		$format = $this->language->get('date_format_short');

		if ($format === 'date_format_short') {
			$format = 'Y-m-d';
		}

		return CredentialsView::build($view, $format, function (int $order_id): string {
			if ($order_id <= 0 || !isset($this->session->data['customer_token'])) {
				return '';
			}

			return $this->url->link('account/order.info', 'language=' . $this->config->get('config_language') . '&customer_token=' . $this->session->data['customer_token'] . '&order_id=' . $order_id);
		});
	}

	private function logError(string $where, \Throwable $e): void {
		$this->log->write('Xtream UI Pro: ' . $where . ': ' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
	}
}
