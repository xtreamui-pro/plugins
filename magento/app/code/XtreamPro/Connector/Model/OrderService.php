<?php
/**
 * Xtream UI Pro connector - what happens to the units of an order.
 *
 * This is the glue between Magento events and the platform independent core
 * (XtreamPro\Connector\Core\Provisioner). It decides WHEN to call the core and
 * stores WHAT the core returns; the panel logic itself is in the core.
 *
 * Life of a unit (one purchased quantity of an IPTV product):
 *
 *   claimed (pending) -> provisioned            payment seen, panel call done
 *                     -> failed                 panel call failed; the cron and the
 *                                               admin button try again
 *   provisioned -> revoking -> revoked          refund / cancel; the panel call is
 *                                               retried until it works
 *
 * Every panel call carries a request id built from the order item id and the
 * unit number, so repeating a step after a timeout never sells twice.
 */

namespace XtreamPro\Connector\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item;
use Psr\Log\LoggerInterface;
use XtreamPro\Connector\Core\ApiException;
use XtreamPro\Connector\Core\Provisioner;
use XtreamPro\Connector\Model\Config\Source\Kind;

class OrderService
{
    /** After this many failed attempts only the admin button tries again. */
    const MAX_ATTEMPTS = 8;

    /** Panel errors that say "try again later" (the rest are final until someone fixes the setup). */
    const TRANSIENT = ['CONNECTION_FAILED', 'BAD_RESPONSE', 'SERVER_ERROR', 'RATE_LIMITED', 'REDIRECT'];

    /** @var Storage */
    private $storage;

    /** @var Gateway */
    private $gateway;

    /** @var Config */
    private $config;

    /** @var ProductRepositoryInterface */
    private $products;

    /** @var OrderRepositoryInterface */
    private $orders;

    /** @var OrderStatusHistoryRepositoryInterface */
    private $history;

    /** @var LockManagerInterface */
    private $locks;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        Storage $storage,
        Gateway $gateway,
        Config $config,
        ProductRepositoryInterface $products,
        OrderRepositoryInterface $orders,
        OrderStatusHistoryRepositoryInterface $history,
        LockManagerInterface $locks,
        LoggerInterface $logger
    ) {
        $this->storage = $storage;
        $this->gateway = $gateway;
        $this->config = $config;
        $this->products = $products;
        $this->orders = $orders;
        $this->history = $history;
        $this->locks = $locks;
        $this->logger = $logger;
    }

    // ---- claiming ------------------------------------------------------------

    /**
     * Record one unit per invoiced quantity of every IPTV item. Database only,
     * safe to run inside the invoice transaction and to repeat.
     *
     * @param Order  $order
     * @param Item[] $items Order items to look at (the invoice's, or all of the order's).
     */
    public function claim(Order $order, iterable $items): void
    {
        if (!$order->getId()) {
            return; // not saved yet; the "invoice saved" event claims later
        }
        foreach ($items as $item) {
            if (!$item->getId() || $item->getParentItemId()) {
                continue;
            }
            $quantity = (int) floor((float) $item->getQtyInvoiced());
            $cfg = $quantity > 0 ? $this->productConfig($item) : null;
            if ($cfg === null) {
                continue;
            }
            $this->storage->claimUnits([
                'order_id'      => (int) $order->getId(),
                'order_item_id' => (int) $item->getId(),
                'customer_id'   => $order->getCustomerId(),
                'kind'          => $cfg['kind'],
                'package_id'    => $cfg['package_id'],
                'trial'         => $cfg['trial'],
                'credits'       => $cfg['credits'],
            ], $quantity);
        }
    }

    /**
     * IPTV settings of the product of an order item.
     *
     * @return array{kind:string,package_id:int,trial:bool,credits:int}|null null = not an IPTV product
     */
    private function productConfig(Item $item): ?array
    {
        if (!in_array($item->getProductType(), ['simple', 'virtual'], true)) {
            return null;
        }
        try {
            $product = $this->products->getById((int) $item->getProductId());
        } catch (NoSuchEntityException $e) {
            return null;
        }
        $kind = (string) $product->getData('xtreampro_kind');
        if ($kind !== Kind::LINE && $kind !== Kind::RESELLER) {
            return null;
        }
        return [
            'kind'       => $kind,
            'package_id' => $kind === Kind::LINE ? (int) $product->getData('xtreampro_package_id') : 0,
            'trial'      => $kind === Kind::LINE && (int) $product->getData('xtreampro_trial') === 1,
            'credits'    => $kind === Kind::RESELLER ? max(0, (int) $product->getData('xtreampro_credits')) : 0,
        ];
    }

    /** Does any item of the order belong to an IPTV product? */
    public function hasIptvItems(Order $order): bool
    {
        foreach ($order->getAllItems() as $item) {
            if (!$item->getParentItemId() && $this->productConfig($item) !== null) {
                return true;
            }
        }
        return false;
    }

    /** Should the admin order view offer "Provision Xtream UI Pro lines again"? */
    public function needsAttention(Order $order): bool
    {
        $id = (int) $order->getId();
        if ($id <= 0 || !$order->hasInvoices()) {
            return false;
        }
        if ($this->storage->hasUnits($id)) {
            return $this->storage->hasOpenUnits($id);
        }
        return $this->hasIptvItems($order);
    }

    // ---- provisioning --------------------------------------------------------

    /**
     * Provision (and revoke) every open unit of an order.
     *
     * @param bool $force true from the admin button: ignore the attempt limit.
     * @return array{done:int,failed:int,errors:string[]}
     */
    public function process(Order $order, bool $force = false): array
    {
        $result = ['done' => 0, 'failed' => 0, 'errors' => []];
        if (!$this->config->isConfigured()) {
            $result['errors'][] = (string) __('The Xtream UI Pro connector is not configured (Stores > Configuration > Services > Xtream UI Pro).');
            return $result;
        }
        $lock = 'xtreampro_order_' . (int) $order->getId();
        if (!$this->locks->lock($lock, 10)) {
            $result['errors'][] = (string) __('Another process is working on this order. Try again in a minute.');
            return $result;
        }
        try {
            foreach ($this->storage->unitsOfOrder((int) $order->getId()) as $unit) {
                if (!in_array($unit['status'], [Storage::STATUS_PENDING, Storage::STATUS_FAILED, Storage::STATUS_REVOKING], true)) {
                    continue;
                }
                if (!$force && (int) $unit['attempts'] >= self::MAX_ATTEMPTS) {
                    continue;
                }
                $error = $unit['status'] === Storage::STATUS_REVOKING
                    ? $this->revokeUnit($order, $unit)
                    : $this->provisionUnit($order, $unit, $force);
                if ($error === '') {
                    $result['done']++;
                } else {
                    $result['failed']++;
                    $result['errors'][] = $error;
                }
            }
        } finally {
            $this->locks->unlock($lock);
        }
        return $result;
    }

    /** Cron: look at an order by id. */
    public function processById(int $orderId): void
    {
        try {
            $this->process($this->orders->get($orderId));
        } catch (NoSuchEntityException $e) {
            $this->logger->warning('Xtream UI Pro: order ' . $orderId . ' no longer exists');
        }
    }

    /**
     * @return string '' on success, the error text otherwise
     */
    private function provisionUnit(Order $order, array $unit, bool $force): string
    {
        $item = $order->getItemById((int) $unit['order_item_id']);
        $name = $item ? $item->getName() : ('#' . $unit['order_item_id']);
        try {
            // Nothing was sold yet: use what the product says now (the admin may have fixed it).
            if ($force && $item && $unit['panel_id'] === null) {
                $unit = $this->refreshConfig($unit, $item);
            }
            if ($unit['kind'] === Kind::LINE) {
                $this->provisionLine($order, $unit, $name);
            } else {
                $this->provisionReseller($order, $unit, $name);
            }
            return '';
        } catch (ApiException $e) {
            return $this->fail($order, $unit, $name, $e->getMessage(), in_array($e->getErrorCode(), self::TRANSIENT, true));
        } catch (\RuntimeException $e) {
            // Setup problems found by this class (no package, guest order): final until the admin acts.
            return $this->fail($order, $unit, $name, $e->getMessage(), false);
        } catch (\Throwable $e) {
            $this->logger->error('Xtream UI Pro: unexpected error while provisioning', ['exception' => $e->getMessage(), 'unit' => $unit['unit_id']]);
            return $this->fail($order, $unit, $name, (string) __('Unexpected error, see var/log/xtreampro.log.'), true);
        }
    }

    private function refreshConfig(array $unit, Item $item): array
    {
        $cfg = $this->productConfig($item);
        if ($cfg === null) {
            return $unit;
        }
        $changes = ['kind' => $cfg['kind'], 'package_id' => $cfg['package_id'] ?: null, 'trial' => $cfg['trial'] ? 1 : 0, 'credits' => $cfg['credits']];
        $this->storage->updateUnit((int) $unit['unit_id'], $changes);
        return $changes + $unit;
    }

    private function provisionLine(Order $order, array $unit, string $name): void
    {
        if ((int) $unit['package_id'] <= 0) {
            throw new \RuntimeException((string) __('No Xtream UI Pro package is selected on the product.'));
        }
        $line = $this->gateway->provisioner()->createLine(
            (int) $unit['package_id'],
            (bool) $unit['trial'],
            Provisioner::lineRequestId($unit['order_item_id'], $unit['unit'])
        );
        $this->storage->updateUnit((int) $unit['unit_id'], [
            'status'     => Storage::STATUS_PROVISIONED,
            'panel_id'   => (string) $line['id'],
            'username'   => $line['username'],
            'password'   => $line['password'],
            'links'      => $line['links'],
            'last_error' => null,
        ]);
        $this->comment($order, (string) __('Xtream UI Pro: line #%1 (%2) created for "%3", unit %4.', $line['id'], $line['username'], $name, $unit['unit']));
    }

    private function provisionReseller(Order $order, array $unit, string $name): void
    {
        $customerId = (int) $unit['customer_id'];
        if ($customerId <= 0) {
            throw new \RuntimeException((string) __('A sub-reseller account needs a registered customer. The customer has to order while signed in.'));
        }
        $core = $this->gateway->provisioner();

        // Choose and keep the credentials BEFORE the panel is called: the panel stores only a hash,
        // so a retry has to send the very same ones.
        $account = $this->storage->resellerOf($customerId);
        if ($account === null) {
            $email = (string) $order->getCustomerEmail();
            $this->storage->claimReseller($customerId, Provisioner::newUsername(strstr($email, '@', true) ?: ''), Provisioner::newPassword());
            $account = $this->storage->resellerOf($customerId);
        }
        if (empty($account['panel_user_id'])) {
            $created = $core->createSubReseller([
                'username' => $account['username'],
                'password' => $account['password'],
                'email'    => (string) $order->getCustomerEmail(),
                'fullname' => $this->customerName($order, $account['username']),
            ], Provisioner::accountRequestId($customerId), (int) $unit['credits']);
            $this->storage->saveReseller($customerId, $created['id'], $created['username'], $created['password']);
            $account = $this->storage->resellerOf($customerId);
            $this->comment($order, (string) __('Xtream UI Pro: reseller account %1 created for "%2".', $account['username'], $name));
        }
        $this->storage->updateUnit((int) $unit['unit_id'], ['panel_id' => $account['panel_user_id'], 'username' => $account['username']]);

        if ((int) $unit['credits'] > 0 && (int) $unit['credits_given'] === 0) {
            $core->giveCredits(
                $account['panel_user_id'],
                (int) $unit['credits'],
                'Order #' . $order->getIncrementId() . ' unit ' . $unit['unit'],
                Provisioner::creditRequestId($unit['order_item_id'], $unit['unit'])
            );
            $this->storage->updateUnit((int) $unit['unit_id'], ['credits_given' => (int) $unit['credits']]);
            $this->comment($order, (string) __('Xtream UI Pro: %1 credits given to the reseller account for "%2", unit %3.', $unit['credits'], $name, $unit['unit']));
        }
        $this->storage->updateUnit((int) $unit['unit_id'], ['status' => Storage::STATUS_PROVISIONED, 'last_error' => null]);
    }

    private function customerName(Order $order, string $fallback): string
    {
        $name = trim($order->getCustomerFirstname() . ' ' . $order->getCustomerLastname());
        return $name === '' ? $fallback : $name;
    }

    /**
     * Record a failed attempt, write the order comment, return the text.
     * A final error (not transient) uses up the attempts so the cron leaves it to the admin.
     */
    private function fail(Order $order, array $unit, string $name, string $message, bool $transient): string
    {
        $this->storage->failUnit((int) $unit['unit_id'], $message);
        if (!$transient) {
            $this->storage->updateUnit((int) $unit['unit_id'], ['attempts' => self::MAX_ATTEMPTS]);
        }
        $this->comment($order, (string) __('Xtream UI Pro: could not provision "%1" (unit %2): %3', $name, $unit['unit'], $message));
        return $message;
    }

    // ---- revoking ------------------------------------------------------------

    /**
     * Refund or cancel: mark the units that are no longer paid for as "revoking"
     * and run the panel calls. Units are paid for up to invoiced minus refunded
     * quantity; the highest unit numbers go first.
     *
     * @param bool $all revoke every unit (the order was cancelled as a whole)
     */
    public function reconcile(Order $order, bool $all = false): void
    {
        if (!$order->getId()) {
            return;
        }
        $perItem = [];
        foreach ($this->storage->unitsOfOrder((int) $order->getId()) as $unit) {
            if (in_array($unit['status'], [Storage::STATUS_PENDING, Storage::STATUS_FAILED, Storage::STATUS_PROVISIONED], true)) {
                $perItem[(int) $unit['order_item_id']][] = $unit;
            }
        }
        $touched = false;
        foreach ($perItem as $itemId => $units) {
            $item = $order->getItemById($itemId);
            $keep = 0;
            if ($item && !$all) {
                $keep = max(0, (int) floor((float) $item->getQtyInvoiced()) - (int) floor((float) $item->getQtyRefunded()));
            }
            foreach (array_slice($units, $keep) as $unit) {
                $this->storage->updateUnit((int) $unit['unit_id'], ['status' => Storage::STATUS_REVOKING]);
                $touched = true;
            }
        }
        if ($touched) {
            $this->process($order);
        }
    }

    /**
     * @return string '' on success, the error text otherwise
     */
    private function revokeUnit(Order $order, array $unit): string
    {
        $item = $order->getItemById((int) $unit['order_item_id']);
        $name = $item ? $item->getName() : ('#' . $unit['order_item_id']);
        try {
            // Nothing was sold for this unit: there is nothing to take back.
            if (empty($unit['panel_id'])) {
                $this->storage->updateUnit((int) $unit['unit_id'], ['status' => Storage::STATUS_REVOKED]);
                return '';
            }
            $core = $this->gateway->provisioner();
            if ($unit['kind'] === Kind::LINE) {
                // Refunded lines are disabled, never deleted: deleting cannot be undone.
                $found = $core->terminateLine((int) $unit['panel_id'], false);
                $this->comment($order, (string) __('Xtream UI Pro: line #%1 %2.', $unit['panel_id'], $found ? __('disabled') : __('was already gone from the panel')));
            } else {
                $this->revokeCredits($order, $core, $unit, $name);
            }
            $this->storage->updateUnit((int) $unit['unit_id'], ['status' => Storage::STATUS_REVOKED, 'last_error' => null]);
            return '';
        } catch (ApiException $e) {
            $message = $e->getMessage();
            $this->storage->failUnit((int) $unit['unit_id'], $message, Storage::STATUS_REVOKING);
            $this->comment($order, (string) __('Xtream UI Pro: could not revoke "%1" (unit %2), it will be tried again: %3', $name, $unit['unit'], $message));
            return $message;
        } catch (\Throwable $e) {
            $this->logger->error('Xtream UI Pro: unexpected error while revoking', ['exception' => $e->getMessage(), 'unit' => $unit['unit_id']]);
            $this->storage->failUnit((int) $unit['unit_id'], 'Unexpected error', Storage::STATUS_REVOKING);
            return (string) __('Unexpected error, see var/log/xtreampro.log.');
        }
    }

    /**
     * Take the credits of a refunded unit back; disable the account when no
     * other paid unit of the customer is left. Credits that are already spent
     * cannot be taken back: that is final and only noted on the order.
     *
     * @throws ApiException for errors worth a retry
     */
    private function revokeCredits(Order $order, Provisioner $core, array $unit, string $name): void
    {
        $owed = (int) $unit['credits_given'] - (int) $unit['credits_taken_back'];
        if ($owed > 0) {
            try {
                $core->takeBackCredits(
                    $unit['panel_id'],
                    $owed,
                    'Order #' . $order->getIncrementId() . ' refunded',
                    Provisioner::takeBackRequestId($unit['order_item_id'], $unit['unit'])
                );
                $this->storage->updateUnit((int) $unit['unit_id'], ['credits_taken_back' => (int) $unit['credits_taken_back'] + $owed]);
                $this->comment($order, (string) __('Xtream UI Pro: %1 credits taken back for "%2".', $owed, $name));
            } catch (ApiException $e) {
                if (in_array($e->getErrorCode(), self::TRANSIENT, true)) {
                    throw $e;
                }
                $this->comment($order, (string) __('Xtream UI Pro: could not take back %1 credits for "%2" (they may already be spent): %3', $owed, $name, $e->getMessage()));
            }
        }
        if ($this->storage->activeResellerUnits((int) $unit['customer_id'], (int) $unit['unit_id']) === 0) {
            $found = $core->terminateSubReseller($unit['panel_id']);
            $this->comment($order, (string) ($found ? __('Xtream UI Pro: reseller account disabled.') : __('Xtream UI Pro: reseller account was already gone from the panel.')));
        }
    }

    // ---- order comments --------------------------------------------------------

    /** Note on the order (admin only). Never contains a password. A failure here is only logged. */
    private function comment(Order $order, string $text): void
    {
        try {
            $this->history->save($order->addCommentToStatusHistory($text));
        } catch (\Throwable $e) {
            $this->logger->warning('Xtream UI Pro: order comment could not be saved: ' . $e->getMessage());
        }
    }
}
