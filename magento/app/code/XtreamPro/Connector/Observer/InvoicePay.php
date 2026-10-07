<?php
/**
 * sales_order_invoice_pay - the invoice has just been marked as paid.
 *
 * Only claims the units (database rows, no panel call): this event fires inside
 * the transaction that saves the invoice, and with online capture at checkout
 * the order items do not have ids yet. Provisioning itself happens in
 * InvoiceSaved, after the commit.
 */

namespace XtreamPro\Connector\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use XtreamPro\Connector\Model\OrderService;

class InvoicePay implements ObserverInterface
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
            $items = [];
            foreach ($invoice->getAllItems() as $invoiceItem) {
                if ($invoiceItem->getOrderItem()) {
                    $items[] = $invoiceItem->getOrderItem();
                }
            }
            $this->service->claim($invoice->getOrder(), $items);
        } catch (\Throwable $e) {
            // Never break a payment because of the IPTV connector.
            $this->logger->error('Xtream UI Pro: invoice_pay failed: ' . $e->getMessage());
        }
    }
}
