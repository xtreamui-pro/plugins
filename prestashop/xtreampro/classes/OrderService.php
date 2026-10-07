<?php
/**
 * PrestaShop glue between shop events and the core provisioner: reads orders,
 * order details and customers, builds the "jobs" the provisioner understands,
 * and prepares what the pages show. No business rule lives here.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class XtreamproOrderService
{
    /** @var XtreamproDbStore */
    private $store;

    public function __construct()
    {
        $this->store = new XtreamproDbStore();
    }

    public function store()
    {
        return $this->store;
    }

    // ---- shop events ---------------------------------------------------------------

    /**
     * An order got a new status. Paid statuses provision, revoked statuses take
     * things back, every other status is none of our business.
     */
    public function onStatusChange($orderId, $newStateId)
    {
        $paid = in_array((int) $newStateId, XtreamproConfig::paidStates(), true);
        $revoked = in_array((int) $newStateId, XtreamproConfig::revokedStates(), true);
        if (!$paid && !$revoked) {
            return;
        }
        $order = new Order((int) $orderId);
        if (!Validate::isLoadedObject($order)) {
            return;
        }
        if ($paid) {
            $this->provisionOrder($order);
        } else {
            $this->revokeOrder($order);
        }
    }

    /**
     * Provision every unit of every Xtream UI Pro product of the order.
     *
     * @return array[] list of array('ok' => bool, 'message' => string)
     */
    public function provisionOrder(Order $order)
    {
        $items = $this->orderItems($order);
        if (!$items) {
            return array();
        }
        if (!XtreamproConfig::isConfigured()) {
            PrestaShopLogger::addLog('Xtream UI Pro: order ' . (int) $order->id . ' was not provisioned, the module is not configured.', 3, null, 'Order', (int) $order->id, true);
            return array(array('ok' => false, 'message' => 'The module is not configured: set the API URL and the API key.'));
        }

        $lockName = 'order' . (int) $order->id;
        if (!XtreamproDbStore::lock($lockName)) {
            return array(array('ok' => false, 'message' => 'The order is being processed already. Try again in a moment.'));
        }
        $results = array();
        try {
            $provisioner = XtreamproConfig::provisioner();
            $customer = $this->customerData($order);
            foreach ($items as $item) {
                for ($n = 1; $n <= $item['quantity']; $n++) {
                    $result = $provisioner->provision(array(
                        'detail_id'  => $item['detail_id'],
                        'unit'       => $n,
                        'order_id'   => (int) $order->id,
                        'order_ref'  => '#' . $order->reference,
                        'kind'       => $item['config']['kind'],
                        'package_id' => $item['config']['package_id'],
                        'trial'      => $item['config']['trial'],
                        'credits'    => $item['config']['credits'],
                        'customer'   => $customer,
                    ));
                    $results[] = $result;
                    if (!$result['ok']) {
                        // The next unit would fail the same way (no credits, bad package...).
                        break;
                    }
                }
            }
        } catch (Exception $e) {
            $results[] = array('ok' => false, 'message' => $e->getMessage());
        } finally {
            XtreamproDbStore::unlock($lockName);
        }
        foreach ($results as $r) {
            if (!$r['ok']) {
                PrestaShopLogger::addLog('Xtream UI Pro: order ' . (int) $order->id . ': ' . $r['message'], 3, null, 'Order', (int) $order->id, true);
            }
        }
        return $results;
    }

    /** Take back everything the order provisioned. @return array[] */
    public function revokeOrder(Order $order)
    {
        $units = $this->store->unitsOfOrders(array((int) $order->id));
        if (!$units) {
            return array();
        }
        if (!XtreamproConfig::isConfigured()) {
            PrestaShopLogger::addLog('Xtream UI Pro: order ' . (int) $order->id . ' was not revoked, the module is not configured.', 3, null, 'Order', (int) $order->id, true);
            return array(array('ok' => false, 'message' => 'The module is not configured: set the API URL and the API key.'));
        }
        $lockName = 'order' . (int) $order->id;
        if (!XtreamproDbStore::lock($lockName)) {
            return array(array('ok' => false, 'message' => 'The order is being processed already. Try again in a moment.'));
        }
        $results = array();
        try {
            $provisioner = XtreamproConfig::provisioner();
            foreach ($units as $u) {
                $results[] = $provisioner->revoke(array(
                    'detail_id' => $u['detail_id'],
                    'unit'      => $u['unit_no'],
                    'order_ref' => '#' . $order->reference,
                ));
            }
        } catch (Exception $e) {
            $results[] = array('ok' => false, 'message' => $e->getMessage());
        } finally {
            XtreamproDbStore::unlock($lockName);
        }
        foreach ($results as $r) {
            if (!$r['ok']) {
                PrestaShopLogger::addLog('Xtream UI Pro: order ' . (int) $order->id . ': ' . $r['message'], 3, null, 'Order', (int) $order->id, true);
            }
        }
        return $results;
    }

    // ---- manual actions of the admin order page -------------------------------------------

    /** "Provision again": only for an order that is in a paid status. @return array[] */
    public function adminProvision($orderId)
    {
        $order = new Order((int) $orderId);
        if (!Validate::isLoadedObject($order)) {
            return array(array('ok' => false, 'message' => 'Order not found.'));
        }
        if (!in_array((int) $order->current_state, XtreamproConfig::paidStates(), true)) {
            return array(array('ok' => false, 'message' => 'The order is not in one of the paid statuses set in the module settings.'));
        }
        $results = $this->provisionOrder($order);
        return $results ? $results : array(array('ok' => false, 'message' => 'The order has no Xtream UI Pro product.'));
    }

    /** renew | suspend | resume of one unit of the order. @return array array('ok', 'message') */
    public function adminUnitAction($action, $orderId, $detailId, $unit)
    {
        $row = $this->store->getUnit((int) $detailId, (int) $unit);
        // The unit must belong to the order the employee is looking at.
        if ($row === null || (int) $row['order_id'] !== (int) $orderId) {
            return array('ok' => false, 'message' => 'Item not found on this order.');
        }
        if (!XtreamproConfig::isConfigured()) {
            return array('ok' => false, 'message' => 'The module is not configured: set the API URL and the API key.');
        }
        $lockName = 'order' . (int) $orderId;
        if (!XtreamproDbStore::lock($lockName)) {
            return array('ok' => false, 'message' => 'The order is being processed already. Try again in a moment.');
        }
        try {
            $provisioner = XtreamproConfig::provisioner();
            if ($action === 'renew') {
                return $provisioner->renew($detailId, $unit);
            }
            if ($action === 'suspend') {
                return $provisioner->suspend($detailId, $unit);
            }
            if ($action === 'resume') {
                return $provisioner->unsuspend($detailId, $unit);
            }
            return array('ok' => false, 'message' => 'Unknown action.');
        } catch (Exception $e) {
            return array('ok' => false, 'message' => $e->getMessage());
        } finally {
            XtreamproDbStore::unlock($lockName);
        }
    }

    // ---- what the pages show ------------------------------------------------------------------

    /** Cards of one order for the buyer (or, with $forAdmin, with error texts). */
    public function cardsForOrders(array $orderIds, array $labels, $forAdmin = false)
    {
        $units = $this->store->unitsOfOrders($orderIds);
        return XtreamproPresenter::cards(
            $units,
            $this->productNames($units),
            XtreamproConfig::panelLoginUrl(),
            XtreamproConfig::apiUrl(),
            $labels,
            $forAdmin
        );
    }

    /**
     * The "My IPTV" page: every unit of the customer, with fresh status, expiry
     * and credit balance from the panel (at most 25 calls; a failing call just
     * leaves the stored data).
     */
    public function cardsForCustomer($customerId, array $labels)
    {
        $units = $this->store->unitsOfCustomer((int) $customerId);
        $live = array();
        if ($units && XtreamproConfig::isConfigured()) {
            try {
                $client = XtreamproConfig::client();
                $calls = 0;
                foreach ($units as $u) {
                    if ($calls >= 25 || $u['status'] !== 'ok' || (string) $u['panel_id'] === '') {
                        continue;
                    }
                    $calls++;
                    try {
                        $data = $u['kind'] === 'reseller' ? $client->getSubUser($u['panel_id']) : $client->getLine((int) $u['panel_id']);
                        if (is_array($data)) {
                            $live[$u['detail_id'] . '-' . $u['unit_no']] = $data;
                        }
                    } catch (Exception $e) {
                        // The stored data is shown instead.
                    }
                }
            } catch (Exception $e) {
                // not configured properly: stored data only
            }
        }
        return XtreamproPresenter::cards(
            $units,
            $this->productNames($units),
            XtreamproConfig::panelLoginUrl(),
            XtreamproConfig::apiUrl(),
            $labels,
            false,
            $live
        );
    }

    /**
     * Rows of the admin order panel: the card data plus what the buttons need.
     */
    public function adminRows($orderId, array $labels)
    {
        $units = $this->store->unitsOfOrders(array((int) $orderId));
        $cards = XtreamproPresenter::cards($units, $this->productNames($units), '', '', $labels, true);
        $rows = array();
        foreach ($units as $i => $u) {
            $username = '';
            foreach ($cards[$i]['fields'] as $f) {
                if ($f['label'] === $labels['username']) {
                    $username = $f['value'];
                }
            }
            $rows[] = array(
                'title'        => $cards[$i]['title'],
                'kind'         => $u['kind'],
                'status_label' => $cards[$i]['status_label'],
                'username'     => $username,
                'panel_id'     => $u['panel_id'],
                'error'        => $u['error'],
                'detail_id'    => $u['detail_id'],
                'unit_no'      => $u['unit_no'],
                'can_renew'    => $u['kind'] === 'line' && $u['status'] === 'ok',
                'can_suspend'  => $u['status'] === 'ok',
                'can_resume'   => $u['status'] === 'suspended',
            );
        }
        return $rows;
    }

    /** Does the order contain a product sold through the module? */
    public function orderHasXtreamProducts(Order $order)
    {
        return (bool) $this->orderItems($order);
    }

    /**
     * Order ids behind the "order name" of a mail ("ABCDEFGHI" or "ABCDEFGHI#2").
     *
     * @return int[]
     */
    public function orderIdsByReference($orderName)
    {
        $reference = strtok((string) $orderName, '#');
        if ($reference === false || $reference === '') {
            return array();
        }
        $ids = array();
        foreach (Order::getByReference($reference) as $order) {
            $ids[] = (int) $order->id;
        }
        return $ids;
    }

    // ---- internals ---------------------------------------------------------------------------------

    /**
     * Order lines that are sold through the module.
     *
     * @return array[] array(detail_id, quantity, config)
     */
    private function orderItems(Order $order)
    {
        $items = array();
        foreach ($order->getOrderDetailList() as $detail) {
            $config = $this->store->getProduct((int) $detail['product_id']);
            if ($config === null) {
                continue;
            }
            $items[] = array(
                'detail_id' => (int) $detail['id_order_detail'],
                'quantity'  => max(1, (int) $detail['product_quantity']),
                'config'    => $config,
            );
        }
        return $items;
    }

    private function customerData(Order $order)
    {
        $customer = new Customer((int) $order->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            return array('id' => 0, 'email' => '', 'fullname' => '', 'login_hint' => '', 'is_guest' => true);
        }
        $at = strpos($customer->email, '@');
        return array(
            'id'         => (int) $customer->id,
            'email'      => (string) $customer->email,
            'fullname'   => trim($customer->firstname . ' ' . $customer->lastname),
            'login_hint' => $at === false ? '' : substr($customer->email, 0, $at),
            'is_guest'   => (bool) $customer->is_guest,
        );
    }

    /** @return string[] product name by order detail id */
    private function productNames(array $units)
    {
        $ids = array();
        foreach ($units as $u) {
            $ids[(int) $u['detail_id']] = true;
        }
        if (!$ids) {
            return array();
        }
        $rows = Db::getInstance()->executeS(
            'SELECT `id_order_detail`, `product_name` FROM `' . _DB_PREFIX_ . 'order_detail` WHERE `id_order_detail` IN (' . implode(',', array_keys($ids)) . ')',
            true,
            false
        );
        $names = array();
        foreach ((array) $rows as $row) {
            $names[(int) $row['id_order_detail']] = (string) $row['product_name'];
        }
        return $names;
    }
}
