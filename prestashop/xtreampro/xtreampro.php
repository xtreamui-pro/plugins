<?php
/**
 * Xtream UI Pro - PrestaShop module.
 *
 * Sells IPTV lines and sub-reseller accounts: when an order reaches a "paid"
 * status the module creates the lines (one per unit bought) or hands credits to
 * the customer's sub-reseller account, through the Reseller API of your
 * Xtream UI Pro panel, with your own reseller API key.
 *
 * Layout: src/ is the platform independent core (API client + provisioner), the
 * rest is PrestaShop glue. See README.md.
 *
 * @version 1.1.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/src/ApiClient.php';
require_once __DIR__ . '/src/Store.php';
require_once __DIR__ . '/src/Provisioner.php';
require_once __DIR__ . '/classes/Presenter.php';
require_once __DIR__ . '/classes/Config.php';
require_once __DIR__ . '/classes/DbStore.php';
require_once __DIR__ . '/classes/OrderService.php';

class Xtreampro extends Module
{
    /** Hooks the module registers. */
    private static $hooks = array(
        'actionOrderStatusPostUpdate',
        'displayAdminProductsExtra',
        'actionProductUpdate',
        'actionProductSave',
        'actionProductDelete',
        'displayOrderConfirmation',
        'displayOrderDetail',
        'displayAdminOrderMain',
        'displayCustomerAccount',
        'actionGetExtraMailTemplateVars',
    );

    public function __construct()
    {
        $this->name = 'xtreampro';
        $this->tab = 'administration';
        $this->version = '1.1.0';
        $this->author = 'Xtream UI Pro';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = array('min' => '1.7.7.0', 'max' => _PS_VERSION_);

        parent::__construct();

        $this->displayName = $this->trans('Xtream UI Pro', array(), 'Modules.Xtreampro.Admin');
        $this->description = $this->trans('Sell IPTV lines and sub-reseller accounts through the Reseller API of your Xtream UI Pro panel.', array(), 'Modules.Xtreampro.Admin');
        $this->confirmUninstall = $this->trans('Your settings are removed. The orders, lines and credentials already recorded stay in the database.', array(), 'Modules.Xtreampro.Admin');
    }

    // =========================================================================
    // Install / uninstall
    // =========================================================================

    public function install()
    {
        if (!parent::install() || !XtreamproDbStore::createTables() || !$this->installTab()) {
            return false;
        }
        foreach (self::$hooks as $hook) {
            if (!$this->registerHook($hook)) {
                return false;
            }
        }
        // Defaults: the payment-accepted status provisions, cancelled and refunded revoke.
        XtreamproConfig::saveIds(XtreamproConfig::PAID_STATES, array((int) Configuration::get('PS_OS_PAYMENT')));
        XtreamproConfig::saveIds(XtreamproConfig::REVOKED_STATES, array((int) Configuration::get('PS_OS_CANCELED'), (int) Configuration::get('PS_OS_REFUND')));
        XtreamproConfig::instance();
        return true;
    }

    /**
     * The settings are removed. The three tables are kept on purpose: they hold
     * the credentials customers were given (see README for how to drop them).
     */
    public function uninstall()
    {
        foreach (XtreamproConfig::keys() as $key) {
            Configuration::deleteByName($key);
        }
        return $this->uninstallTab() && parent::uninstall();
    }

    /** Hidden back office controller that receives the buttons of the order page. */
    private function installTab()
    {
        if (Tab::getIdFromClassName('AdminXtreamproOrders')) {
            return true;
        }
        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = 'AdminXtreamproOrders';
        $tab->module = $this->name;
        $tab->id_parent = -1; // hidden from the menu
        $tab->name = array();
        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[$lang['id_lang']] = 'Xtream UI Pro';
        }
        return (bool) $tab->add();
    }

    private function uninstallTab()
    {
        $id = (int) Tab::getIdFromClassName('AdminXtreamproOrders');
        if ($id) {
            $tab = new Tab($id);
            return (bool) $tab->delete();
        }
        return true;
    }

    // =========================================================================
    // Texts shared by the pages (literal trans() calls so the translation tool finds them)
    // =========================================================================

    /** @return array texts for XtreamproPresenter */
    public function labels()
    {
        $d = 'Modules.Xtreampro.Shop';
        return array(
            'title_lines'      => $this->trans('Your IPTV subscription', array(), $d),
            'title_reseller'   => $this->trans('Your reseller account', array(), $d),
            'server'           => $this->trans('Server URL', array(), $d),
            'username'         => $this->trans('Username', array(), $d),
            'password'         => $this->trans('Password', array(), $d),
            'playlist'         => $this->trans('Playlist URL', array(), $d),
            'player'           => $this->trans('Web player', array(), $d),
            'credits_added'    => $this->trans('Credits added by this order', array(), $d),
            'signin'           => $this->trans('Sign in', array(), $d),
            'st_ok'            => $this->trans('Active', array(), $d),
            'st_error'         => $this->trans('Not set up', array(), $d),
            'st_revoked'       => $this->trans('Cancelled', array(), $d),
            'st_suspended'     => $this->trans('Suspended', array(), $d),
            'notice_revoked'   => $this->trans('This item was cancelled or refunded.', array(), $d),
            'notice_error'     => $this->trans('This item could not be set up yet. Please contact us.', array(), $d),
            'notice_suspended' => $this->trans('This item is suspended. Please contact us.', array(), $d),
            'status'           => $this->trans('Status', array(), $d),
            'expires'          => $this->trans('Expires', array(), $d),
            'never_expires'    => $this->trans('Never expires', array(), $d),
            'connections'      => $this->trans('Max connections', array(), $d),
            'balance'          => $this->trans('Credit balance', array(), $d),
        );
    }

    // =========================================================================
    // Configuration page
    // =========================================================================

    public function getContent()
    {
        $output = '';
        if (Tools::isSubmit('submitXtreamproSettings') || Tools::isSubmit('submitXtreamproTest')) {
            $output .= $this->saveSettings();
            if (Tools::isSubmit('submitXtreamproTest') && !count($this->context->controller->errors)) {
                $output .= $this->testConnection();
            }
        }
        return $output . $this->renderSettingsForm();
    }

    /** @return string HTML messages */
    private function saveSettings()
    {
        $errors = array();

        $url = rtrim(trim((string) Tools::getValue(XtreamproConfig::API_URL)), '/');
        if (!preg_match('#^https?://[^\s/$.?\#].[^\s]*$#i', $url)) {
            $errors[] = $this->trans('The API URL must start with http:// or https://, for example https://api.example.com', array(), 'Modules.Xtreampro.Admin');
        }
        $panel = rtrim(trim((string) Tools::getValue(XtreamproConfig::PANEL_URL)), '/');
        if ($panel !== '' && !preg_match('#^https?://[^\s]+$#i', $panel)) {
            $errors[] = $this->trans('The panel address must start with http:// or https://, or stay empty.', array(), 'Modules.Xtreampro.Admin');
        }

        $paid = $this->postedStates(XtreamproConfig::PAID_STATES);
        $revoked = $this->postedStates(XtreamproConfig::REVOKED_STATES);
        if (!$paid) {
            $errors[] = $this->trans('Choose at least one order status that counts as paid.', array(), 'Modules.Xtreampro.Admin');
        }
        if (array_intersect($paid, $revoked)) {
            $errors[] = $this->trans('An order status cannot be both paid and revoked.', array(), 'Modules.Xtreampro.Admin');
        }

        if ($errors) {
            $out = '';
            foreach ($errors as $e) {
                $out .= $this->displayError($e);
            }
            // The test button must not run on settings that were refused.
            $this->context->controller->errors[] = $errors[0];
            return $out;
        }

        Configuration::updateValue(XtreamproConfig::API_URL, $url);
        Configuration::updateValue(XtreamproConfig::PANEL_URL, $panel);
        // The key field is never filled in: empty (or the mask) keeps the saved key.
        $key = trim((string) Tools::getValue(XtreamproConfig::API_KEY));
        if ($key !== '' && $key !== '********') {
            Configuration::updateValue(XtreamproConfig::API_KEY, $key);
            Configuration::deleteByName(XtreamproConfig::PACKAGES_AT);
        }
        XtreamproConfig::saveIds(XtreamproConfig::PAID_STATES, $paid);
        XtreamproConfig::saveIds(XtreamproConfig::REVOKED_STATES, $revoked);
        return $this->displayConfirmation($this->trans('Settings saved.', array(), 'Modules.Xtreampro.Admin'));
    }

    /** @return int[] */
    private function postedStates($key)
    {
        $posted = Tools::getValue($key);
        $ids = array();
        $known = array();
        foreach (OrderState::getOrderStates((int) $this->context->language->id) as $state) {
            $known[(int) $state['id_order_state']] = true;
        }
        foreach ((array) $posted as $id) {
            if (isset($known[(int) $id])) {
                $ids[] = (int) $id;
            }
        }
        return array_values(array_unique($ids));
    }

    /** Calls user_info with the saved settings. @return string HTML message */
    private function testConnection()
    {
        try {
            $info = XtreamproConfig::client()->userInfo();
            $text = $this->trans(
                'Connected. Reseller account "%username%" with %credits% credits.',
                array(
                    '%username%' => isset($info['username']) ? (string) $info['username'] : '?',
                    '%credits%'  => isset($info['credits']) ? (string) (int) $info['credits'] : '?',
                ),
                'Modules.Xtreampro.Admin'
            );
            XtreamproConfig::packages(true);
            return $this->displayConfirmation($text);
        } catch (Exception $e) {
            return $this->displayError($this->trans('Connection test failed: %message%', array('%message%' => $e->getMessage()), 'Modules.Xtreampro.Admin'));
        }
    }

    private function renderSettingsForm()
    {
        $states = OrderState::getOrderStates((int) $this->context->language->id);
        $keySaved = XtreamproConfig::apiKey() !== '';
        $d = 'Modules.Xtreampro.Admin';

        $form = array(
            'form' => array(
                'legend' => array('title' => $this->trans('Xtream UI Pro settings', array(), $d), 'icon' => 'icon-cogs'),
                'input' => array(
                    array(
                        'type'  => 'text',
                        'label' => $this->trans('API URL', array(), $d),
                        'name'  => XtreamproConfig::API_URL,
                        'required' => true,
                        'desc'  => $this->trans('Address of the panel API (cmd/api), for example https://api.example.com. The certificate is always verified.', array(), $d),
                    ),
                    array(
                        'type'  => 'password',
                        'label' => $this->trans('API key', array(), $d),
                        'name'  => XtreamproConfig::API_KEY,
                        'desc'  => $keySaved
                            ? $this->trans('A key is saved. It is never shown again: type a new key only to replace it, leave the field empty to keep it.', array(), $d)
                            : $this->trans('The API key of your reseller account (panel: /api-key). Only reseller accounts work.', array(), $d),
                        'autocomplete' => false,
                    ),
                    array(
                        'type'  => 'text',
                        'label' => $this->trans('Panel address (optional)', array(), $d),
                        'name'  => XtreamproConfig::PANEL_URL,
                        'desc'  => $this->trans('Address of the dashboard. Customers who buy a sub-reseller account get a sign-in link to it. Empty = no link.', array(), $d),
                    ),
                    array(
                        'type'     => 'select',
                        'label'    => $this->trans('Paid order statuses', array(), $d),
                        'name'     => XtreamproConfig::PAID_STATES . '[]',
                        'multiple' => true,
                        'options'  => array('query' => $states, 'id' => 'id_order_state', 'name' => 'name'),
                        'desc'     => $this->trans('When an order gets one of these statuses its lines are created (or credits handed over). Hold Ctrl / Cmd to choose several.', array(), $d),
                    ),
                    array(
                        'type'     => 'select',
                        'label'    => $this->trans('Revoked order statuses', array(), $d),
                        'name'     => XtreamproConfig::REVOKED_STATES . '[]',
                        'multiple' => true,
                        'options'  => array('query' => $states, 'id' => 'id_order_state', 'name' => 'name'),
                        'desc'     => $this->trans('When an order gets one of these statuses its lines are disabled and unspent credits are taken back.', array(), $d),
                    ),
                ),
                'submit'  => array('title' => $this->trans('Save', array(), $d)),
                'buttons' => array(
                    array(
                        'type'  => 'submit',
                        'name'  => 'submitXtreamproTest',
                        'title' => $this->trans('Save and test connection', array(), $d),
                        'icon'  => 'process-icon-refresh',
                        'class' => 'pull-right',
                    ),
                ),
            ),
        );

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->module = $this;
        $helper->table = $this->table;
        $helper->identifier = $this->identifier;
        $helper->name_controller = $this->name;
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->submit_action = 'submitXtreamproSettings';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        // The API key is never written back into the form.
        $helper->fields_value = array(
            XtreamproConfig::API_URL            => Tools::getValue(XtreamproConfig::API_URL, XtreamproConfig::apiUrl()),
            XtreamproConfig::API_KEY            => '',
            XtreamproConfig::PANEL_URL          => Tools::getValue(XtreamproConfig::PANEL_URL, XtreamproConfig::panelUrl()),
            XtreamproConfig::PAID_STATES . '[]'    => Tools::getIsset(XtreamproConfig::PAID_STATES) ? (array) Tools::getValue(XtreamproConfig::PAID_STATES) : XtreamproConfig::paidStates(),
            XtreamproConfig::REVOKED_STATES . '[]' => Tools::getIsset(XtreamproConfig::REVOKED_STATES) ? (array) Tools::getValue(XtreamproConfig::REVOKED_STATES) : XtreamproConfig::revokedStates(),
        );
        return $helper->generateForm(array($form));
    }

    // =========================================================================
    // Product page (back office)
    // =========================================================================

    public function hookDisplayAdminProductsExtra($params)
    {
        $productId = isset($params['id_product']) ? (int) $params['id_product'] : (int) Tools::getValue('id_product');
        $config = $productId ? (new XtreamproDbStore())->getProduct($productId) : null;
        $config = $config ? $config : array('kind' => '', 'package_id' => 0, 'trial' => 0, 'credits' => 0);

        $result = XtreamproConfig::packages();
        $packages = array();
        foreach ($result['list'] as $pkg) {
            $packages[(int) $pkg['id']] = XtreamproConfig::packageLabel($pkg);
        }
        // A package chosen earlier that the panel does not list any more stays selectable.
        if ($config['package_id'] > 0 && !isset($packages[$config['package_id']])) {
            $packages[$config['package_id']] = '#' . $config['package_id'];
        }

        $this->context->smarty->assign(array(
            'xp_kind'          => $config['kind'],
            'xp_package_id'    => (int) $config['package_id'],
            'xp_trial'         => (int) $config['trial'],
            'xp_credits'       => (int) $config['credits'],
            'xp_packages'      => $packages,
            'xp_packages_error' => $result['error'],
        ));
        return $this->display(__FILE__, 'views/templates/admin/product_extra.tpl');
    }

    public function hookActionProductUpdate($params)
    {
        $this->saveProductSettings($params);
    }

    public function hookActionProductSave($params)
    {
        $this->saveProductSettings($params);
    }

    public function hookActionProductDelete($params)
    {
        if (!empty($params['id_product'])) {
            (new XtreamproDbStore())->deleteProduct((int) $params['id_product']);
        }
    }

    /**
     * Both save hooks call this. It only acts when the module's form was part of
     * the request (the hidden field), so saves from elsewhere never wipe the settings.
     */
    private function saveProductSettings($params)
    {
        $productId = isset($params['id_product']) ? (int) $params['id_product'] : 0;
        if ($productId <= 0 || !Tools::getIsset('xtreampro_form')) {
            return;
        }
        $kind = (string) Tools::getValue('xtreampro_kind');
        (new XtreamproDbStore())->saveProduct(
            $productId,
            $kind,
            (int) Tools::getValue('xtreampro_package_id'),
            (int) Tools::getValue('xtreampro_trial') === 1,
            max(0, (int) Tools::getValue('xtreampro_credits'))
        );
    }

    // =========================================================================
    // Orders
    // =========================================================================

    /** The order has a paid or revoked status: provision or take back. Must never break the order flow. */
    public function hookActionOrderStatusPostUpdate($params)
    {
        $state = isset($params['newOrderStatus']) && is_object($params['newOrderStatus']) ? (int) $params['newOrderStatus']->id : 0;
        $orderId = isset($params['id_order']) ? (int) $params['id_order'] : 0;
        if ($state <= 0 || $orderId <= 0) {
            return;
        }
        try {
            (new XtreamproOrderService())->onStatusChange($orderId, $state);
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('Xtream UI Pro: order ' . $orderId . ': ' . $e->getMessage(), 3, null, 'Order', $orderId, true);
        }
    }

    public function hookDisplayOrderConfirmation($params)
    {
        return $this->renderCustomerOrder($params);
    }

    public function hookDisplayOrderDetail($params)
    {
        return $this->renderCustomerOrder($params);
    }

    /** Credentials on the order pages: only to the customer the order belongs to. */
    private function renderCustomerOrder($params)
    {
        $order = isset($params['order']) ? $params['order'] : (isset($params['objOrder']) ? $params['objOrder'] : null);
        $customer = $this->context->customer;
        if (!$order instanceof Order || !Validate::isLoadedObject($customer) || (int) $customer->id !== (int) $order->id_customer) {
            return '';
        }
        $cards = (new XtreamproOrderService())->cardsForOrders(array((int) $order->id), $this->labels());
        if (!$cards) {
            return '';
        }
        $this->context->smarty->assign('xp_cards', $cards);
        return $this->display(__FILE__, 'views/templates/hook/credentials.tpl');
    }

    /** Panel on the back office order page. */
    public function hookDisplayAdminOrderMain($params)
    {
        $orderId = isset($params['id_order']) ? (int) $params['id_order'] : 0;
        if ($orderId <= 0) {
            return '';
        }
        $order = new Order($orderId);
        $service = new XtreamproOrderService();
        if (!Validate::isLoadedObject($order)) {
            return '';
        }
        $rows = $service->adminRows($orderId, $this->labels());
        if (!$rows && !$service->orderHasXtreamProducts($order)) {
            return '';
        }

        $cookie = $this->context->cookie;
        $flash = isset($cookie->xtreampro_flash) ? (string) $cookie->xtreampro_flash : '';
        if ($flash !== '') {
            unset($cookie->xtreampro_flash);
        }

        $this->context->smarty->assign(array(
            'xp_rows'     => $rows,
            'xp_order_id' => $orderId,
            'xp_flash'    => $flash,
            'xp_action'   => $this->context->link->getAdminLink('AdminXtreamproOrders'),
            'xp_token'    => Tools::getAdminTokenLite('AdminXtreamproOrders'),
        ));
        return $this->display(__FILE__, 'views/templates/admin/order_panel.tpl');
    }

    // =========================================================================
    // Customer account
    // =========================================================================

    public function hookDisplayCustomerAccount($params)
    {
        $customer = $this->context->customer;
        if (!Validate::isLoadedObject($customer) || !(new XtreamproDbStore())->unitsOfCustomer((int) $customer->id)) {
            return '';
        }
        $this->context->smarty->assign('xp_link', $this->context->link->getModuleLink($this->name, 'iptv'));
        return $this->display(__FILE__, 'views/templates/hook/account_link.tpl');
    }

    // =========================================================================
    // E-mail
    // =========================================================================

    /**
     * Adds {xtreampro_credentials} (HTML) and {xtreampro_credentials_txt} (text) to
     * the order confirmation and payment mails. They show only where the shop
     * owner put the placeholder into the mail template (see README).
     */
    public function hookActionGetExtraMailTemplateVars($params)
    {
        $template = isset($params['template']) ? (string) $params['template'] : '';
        if (!in_array($template, array('order_conf', 'payment'), true) || !isset($params['extra_template_vars'])) {
            return;
        }
        $html = '';
        $text = '';
        try {
            $vars = isset($params['template_vars']) && is_array($params['template_vars']) ? $params['template_vars'] : array();
            $service = new XtreamproOrderService();
            if (!empty($vars['{id_order}'])) {
                $orderIds = array((int) $vars['{id_order}']);
            } else {
                $orderIds = isset($vars['{order_name}']) ? $service->orderIdsByReference($vars['{order_name}']) : array();
            }
            // When the mail names its recipient, it must be the customer of the order.
            $orderIds = $this->ordersOfRecipient($orderIds, isset($vars['{email}']) ? (string) $vars['{email}'] : '');
            if ($orderIds) {
                $cards = $service->cardsForOrders($orderIds, $this->labels());
                $html = XtreamproPresenter::mailHtml($cards, $this->labels());
                $text = XtreamproPresenter::mailText($cards, $this->labels());
            }
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('Xtream UI Pro: mail variables: ' . $e->getMessage(), 3);
        }
        $params['extra_template_vars']['{xtreampro_credentials}'] = $html;
        $params['extra_template_vars']['{xtreampro_credentials_txt}'] = $text;
    }

    /** @return int[] */
    private function ordersOfRecipient(array $orderIds, $email)
    {
        if ($email === '') {
            return $orderIds;
        }
        $out = array();
        foreach ($orderIds as $id) {
            $order = new Order((int) $id);
            if (!Validate::isLoadedObject($order)) {
                continue;
            }
            $customer = new Customer((int) $order->id_customer);
            if (Validate::isLoadedObject($customer) && strcasecmp($customer->email, $email) === 0) {
                $out[] = (int) $id;
            }
        }
        return $out;
    }
}
