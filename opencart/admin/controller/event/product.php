<?php
namespace Opencart\Admin\Controller\Extension\Xtreampro\Event;

use Opencart\System\Library\Extension\Xtreampro\Store;

/**
 * Product form of the admin: adds an "Xtream UI Pro" tab to the form (the
 * rendered page is changed by an event, no template of OpenCart is touched)
 * and saves it through model events.
 *
 * @package Opencart\Admin\Controller\Extension\Xtreampro\Event
 */
class Product extends \Opencart\System\Engine\Controller {
	/**
	 * Event admin/view/catalog/product_form/after
	 *
	 * @param string $route
	 * @param array  $data
	 * @param string $output the rendered form
	 *
	 * @return void
	 */
	public function form(string &$route, array &$data, string &$output): void {
		if (!$this->config->get('module_xtreampro_status')) {
			return;
		}

		// The two places the tab is inserted before: the "Report" tab button and
		// its pane. When a future OpenCart renames them, the form stays untouched.
		$nav_marker = '<li class="nav-item"><a href="#tab-report"';
		$pane_marker = '<div id="tab-report" class="tab-pane">';

		$nav_position = strpos($output, $nav_marker);
		$pane_position = strpos($output, $pane_marker);

		if ($nav_position === false || $pane_position === false || $nav_position > $pane_position) {
			$this->log->write('Xtream UI Pro: the product form has no Report tab, the Xtream UI Pro tab was not added.');

			return;
		}

		$this->load->language('extension/xtreampro/module/xtreampro', '', 'en-gb');
		$this->load->language('extension/xtreampro/module/xtreampro');

		$settings = (new Store($this->db, DB_PREFIX))->getProduct((int)($data['product_id'] ?? 0));

		$this->load->model('extension/xtreampro/module/xtreampro');

		$packages = $this->model_extension_xtreampro_module_xtreampro->getPackages();

		$options = $packages['options'];
		$package_id = $settings ? $settings['package_id'] : 0;

		// A package that is no longer in the list stays selectable, so saving does not drop it.
		if ($package_id > 0 && !in_array($package_id, array_column($options, 'id'), true)) {
			$options[] = ['id' => $package_id, 'label' => '#' . $package_id];
		}

		$view['kind'] = $settings ? $settings['kind'] : '';
		$view['package_id'] = $package_id;
		$view['trial'] = $settings ? $settings['trial'] : false;
		$view['credits'] = $settings ? $settings['credits'] : 0;
		$view['packages'] = $options;
		$view['package_error'] = $packages['error'];

		$tab = $this->load->view('extension/xtreampro/event/product_tab', $view);

		$nav = '<li class="nav-item"><a href="#tab-xtreampro" data-bs-toggle="tab" class="nav-link">' . htmlspecialchars($this->language->get('xp_tab'), ENT_QUOTES, 'UTF-8') . '</a></li>';

		// Pane first (it is later in the page), so the nav position stays valid.
		$output = substr_replace($output, $tab, $pane_position, 0);
		$output = substr_replace($output, $nav, $nav_position, 0);
	}

	/**
	 * Event admin/model/catalog/product/addProduct/after
	 * (args: the posted form; output: the new product id)
	 *
	 * @param string $route
	 * @param array  $args
	 * @param mixed  $output
	 *
	 * @return void
	 */
	public function add(string &$route, array &$args, mixed &$output): void {
		if (isset($args[0]) && is_array($args[0])) {
			$this->saveSettings((int)$output, $args[0]);
		}
	}

	/**
	 * Event admin/model/catalog/product/editProduct/after
	 * (args: product id, the posted form)
	 *
	 * @param string $route
	 * @param array  $args
	 *
	 * @return void
	 */
	public function edit(string &$route, array &$args): void {
		if (isset($args[0], $args[1]) && is_array($args[1])) {
			$this->saveSettings((int)$args[0], $args[1]);
		}
	}

	/**
	 * Event admin/model/catalog/product/deleteProduct/after
	 *
	 * @param string $route
	 * @param array  $args
	 *
	 * @return void
	 */
	public function delete(string &$route, array &$args): void {
		if (isset($args[0])) {
			(new Store($this->db, DB_PREFIX))->deleteProduct((int)$args[0]);
		}
	}

	/**
	 * Store the tab of the form. A form without the tab (it could not be added)
	 * leaves the saved settings alone.
	 */
	private function saveSettings(int $product_id, array $post): void {
		if ($product_id <= 0 || !isset($post['xtreampro']) || !is_array($post['xtreampro'])) {
			return;
		}

		$tab = $post['xtreampro'];

		$kind = isset($tab['kind']) ? (string)$tab['kind'] : '';

		(new Store($this->db, DB_PREFIX))->saveProduct(
			$product_id,
			$kind,
			isset($tab['package_id']) ? (int)$tab['package_id'] : 0,
			!empty($tab['trial']),
			isset($tab['credits']) ? (int)$tab['credits'] : 0
		);
	}
}
