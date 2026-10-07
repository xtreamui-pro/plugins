<?php
/**
 * Receives the buttons of the Xtream UI Pro panel on the back office order page
 * (provision again, renew, suspend, resume) and sends the employee back to the order.
 *
 * Protected twice: the URL carries the usual back office token, and the form
 * posts a second token that is compared here in constant time. Only POST works,
 * and only for an employee who may edit orders.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminXtreamproOrdersController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function postProcess()
    {
        $orderId = (int) Tools::getValue('id_order');
        $action = (string) Tools::getValue('xp_action');
        $d = 'Modules.Xtreampro.Admin';

        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $orderId <= 0 || $action === '') {
            $this->back($orderId);
        }
        if (!hash_equals(Tools::getAdminTokenLite('AdminXtreamproOrders'), (string) Tools::getValue('xp_token'))) {
            $this->flash($this->trans('The security token is not valid. Reload the order page and try again.', array(), $d));
            $this->back($orderId);
        }
        if (!$this->canEditOrders()) {
            $this->flash($this->trans('You are not allowed to edit orders.', array(), $d));
            $this->back($orderId);
        }

        $service = new XtreamproOrderService();
        if ($action === 'provision') {
            $results = $service->adminProvision($orderId);
        } else {
            $results = array($service->adminUnitAction(
                $action,
                $orderId,
                (int) Tools::getValue('detail_id'),
                (int) Tools::getValue('unit_no')
            ));
        }

        $messages = array();
        foreach ($results as $r) {
            $messages[] = ($r['ok'] ? '' : $this->trans('Failed:', array(), $d) . ' ') . $r['message'];
        }
        $this->flash(implode(' | ', array_unique($messages)));
        $this->back($orderId);
    }

    /** Employees who may edit orders (the module has its own hidden tab, so ask about the Orders tab). */
    private function canEditOrders()
    {
        try {
            $employee = $this->context->employee;
            if (!Validate::isLoadedObject($employee)) {
                return false;
            }
            if ($employee->isSuperAdmin()) {
                return true;
            }
            $access = Profile::getProfileAccess((int) $employee->id_profile, (int) Tab::getIdFromClassName('AdminOrders'));
            return is_array($access) && !empty($access['edit']);
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Shown once on the order page (the employee's cookie keeps it across the redirect). */
    private function flash($message)
    {
        $this->context->cookie->xtreampro_flash = substr((string) $message, 0, 400);
    }

    private function back($orderId)
    {
        try {
            if ($orderId <= 0) {
                throw new RuntimeException('no order');
            }
            $url = $this->context->link->getAdminLink('AdminOrders', true, array('route' => 'admin_orders_view', 'orderId' => (int) $orderId));
        } catch (Throwable $e) {
            $url = $this->context->link->getAdminLink('AdminOrders');
        }
        Tools::redirectAdmin($url);
    }
}
