<?php
namespace Opencart\Admin\Controller\Extension\Xtreampro\Event;

/**
 * Admin order page: adds an "Xtream UI Pro" tab next to History (what was
 * created for the order, the errors, and a "Provision again" button).
 *
 * @package Opencart\Admin\Controller\Extension\Xtreampro\Event
 */
class Order extends \Opencart\System\Engine\Controller {
	/**
	 * Event admin/view/sale/order_info/after
	 *
	 * @param string $route
	 * @param array  $data
	 * @param string $output the rendered order page
	 *
	 * @return void
	 */
	public function block(string &$route, array &$data, string &$output): void {
		$order_id = (int)($data['order_id'] ?? 0);

		if (!$this->config->get('module_xtreampro_status') || $order_id <= 0) {
			return;
		}

		// Inserted before the "Additional" tab button and its pane.
		$nav_marker = '<li class="nav-item"><a href="#tab-additional"';
		$pane_marker = '<div id="tab-additional" class="tab-pane">';

		$nav_position = strpos($output, $nav_marker);
		$pane_position = strpos($output, $pane_marker);

		if ($nav_position === false || $pane_position === false || $nav_position > $pane_position) {
			$this->log->write('Xtream UI Pro: the order page has no Additional tab, the Xtream UI Pro tab was not added.');

			return;
		}

		$this->load->language('extension/xtreampro/module/xtreampro', '', 'en-gb');
		$this->load->language('extension/xtreampro/module/xtreampro');

		$token = 'user_token=' . $this->session->data['user_token'];

		$view['order_id'] = $order_id;
		$view['units_html'] = $this->load->controller('extension/xtreampro/module/xtreampro.unitsHtml', $order_id);
		$view['provision_url'] = $this->url->link('extension/xtreampro/module/xtreampro.provision', $token);
		$view['units_url'] = $this->url->link('extension/xtreampro/module/xtreampro.units', $token . '&order_id=' . $order_id);

		$pane = $this->load->view('extension/xtreampro/event/order_block', $view);

		$nav = '<li class="nav-item"><a href="#tab-xtreampro" data-bs-toggle="tab" class="nav-link">' . htmlspecialchars($this->language->get('xp_tab'), ENT_QUOTES, 'UTF-8') . '</a></li>';

		$output = substr_replace($output, $pane, $pane_position, 0);
		$output = substr_replace($output, $nav, $nav_position, 0);
	}
}
