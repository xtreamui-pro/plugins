<?php
/**
 * sales_order_invoice_save_commit_after - a paid invoice is saved and committed.
 *
 * Claims the units (again, in case the pay event could not) and provisions
 * them. Panel calls happen here, outside the database transaction, so a slow
 * panel never holds the order tables. A failure is kept on the unit and tried
 * again by the cron job and by the "provision again" button.
 */

namespace XtreamPro\Connector\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Invoice;
use Psr\Log\LoggerInterface;
use XtreamPro\Connector\Model\OrderService;

class InvoiceSaved implements ObserverInterface
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
            $invoice = $observer->getEvent()->getInvoice();
            if ((int) $invoice->getState() !== Invoice::STATE_PAID) {
                return;
            }
            $order = $invoice->getOrder();
            $items = [];
            foreach ($invoice->getAllItems() as $invoiceItem) {
                if ($invoiceItem->getOrderItem()) {
                    $items[] = $invoiceItem->getOrderItem();
                }
            }
            $this->service->claim($order, $items);
            $this->service->process($order);
        } catch (\Throwable $e) {
            $this->logger->error('Xtream UI Pro: invoice_save_commit_after failed: ' . $e->getMessage());
        }
    }
}
