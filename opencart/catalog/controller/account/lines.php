<?php
namespace Opencart\Catalog\Controller\Extension\Xtreampro\Account;

use Opencart\System\Library\Extension\Xtreampro\CredentialsView;
use Opencart\System\Library\Extension\Xtreampro\OrderService;

/**
 * Customer account page "My IPTV": every line the customer bought and the
 * sub-reseller account, with the live status from the panel.
 *
 * Route: extension/xtreampro/account/lines
 *
 * @package Opencart\Catalog\Controller\Extension\Xtreampro\Account
 */
class Lines extends \Opencart\System\Engine\Controller {
	/**
	 * @return void
	 */
	public function index(): void {
		// English first, then the shop language on top: a missing translation shows English.
		$this->load->language('extension/xtreampro/xtreampro', '', 'en-gb');
		$this->load->language('extension/xtreampro/xtreampro');

		$language = 'language=' . $this->config->get('config_language');

		// Same sign-in rule as OpenCart's own account pages.
		if (!$this->customer->isLogged() || (!isset($this->request->get['customer_token']) || !isset($this->session->data['customer_token']) || ($this->request->get['customer_token'] != $this->session->data['customer_token']))) {
			$this->session->data['redirect'] = $this->url->link('extension/xtreampro/account/lines', $language);

			$this->response->redirect($this->url->link('account/login', $language));
		}

		$this->document->setTitle($this->language->get('xp_heading'));

		$data['heading_title'] = $this->language->get('xp_heading');

		$data['breadcrumbs'] = [];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/home', $language)
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('xp_text_account'),
			'href' => $this->url->link('account/account', $language . '&customer_token=' . $this->session->data['customer_token'])
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('xp_heading'),
			'href' => $this->url->link('extension/xtreampro/account/lines', $language . '&customer_token=' . $this->session->data['customer_token'])
		];

		$data['credentials'] = '';
		$data['empty'] = true;

		if ($this->config->get('module_xtreampro_status')) {
			$service = new OrderService($this->db, DB_PREFIX, OrderService::settingsFrom($this->config), function (string $message): void {
				$this->log->write('Xtream UI Pro: ' . $message);
			});

			try {
				$view = $service->getAccountView((int)$this->customer->getId());

				if ($view['lines'] || $view['accounts']) {
					$data['empty'] = false;

					$format = $this->language->get('date_format_short');

					if ($format === 'date_format_short') {
						$format = 'Y-m-d';
					}

					$token = $this->session->data['customer_token'];

					// The same block as on the order page.
					$data['credentials'] = $this->load->view('extension/xtreampro/account/credentials', CredentialsView::build($view, $format, function (int $order_id) use ($language, $token): string {
						return $order_id > 0 ? $this->url->link('account/order.info', $language . '&customer_token=' . $token . '&order_id=' . $order_id) : '';
					}));
				}
			} catch (\Throwable $e) {
				$this->log->write('Xtream UI Pro: account page: ' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
			}
		}

		$data['continue'] = $this->url->link('account/account', $language . '&customer_token=' . $this->session->data['customer_token']);

		$data['column_left'] = $this->load->controller('common/column_left');
		$data['column_right'] = $this->load->controller('common/column_right');
		$data['content_top'] = $this->load->controller('common/content_top');
		$data['content_bottom'] = $this->load->controller('common/content_bottom');
		$data['footer'] = $this->load->controller('common/footer');
		$data['header'] = $this->load->controller('common/header');

		$this->response->setOutput($this->load->view('extension/xtreampro/account/lines', $data));
	}
}
