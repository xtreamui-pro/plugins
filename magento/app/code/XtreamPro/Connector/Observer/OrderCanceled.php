<?php
/**
 * order_cancel_after - an order was cancelled.
 *
 * Magento only lets you cancel what is not invoiced, so normally there is
 * nothing to revoke. When the order ends up cancelled as a whole, every unit
 * of it is revoked.
 */

namespace XtreamPro\Connector\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use XtreamPro\Connector\Model\OrderService;

class OrderCanceled implements ObserverInterface
{
    /** @var OrderService */
    private $service;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(OrderService $service, LoggerInterface $logger)
    {
        $this->service = $service;
        $this->logger = $logger;
    }

    public function execute(Observer $observer)
    {
        try {
            $order = $observer->getEvent()->getOrder();
            $this->service->reconcile($order, $order->getState() === Order::STATE_CANCELED);
        } catch (\Throwable $e) {
            $this->logger->error('Xtream UI Pro: order_cancel_after failed: ' . $e->getMessage());
        }
    }
}
