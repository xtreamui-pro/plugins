<?php
/**
 * sales_order_creditmemo_save_commit_after - a refund is saved and committed.
 *
 * (The brief for this connector names sales_order_creditmemo_save_after; the
 * commit_after variant is used so that the panel is not called inside the
 * refund's database transaction.)
 *
 * Units that are no longer paid for are revoked: lines are disabled, credits
 * are taken back, an account without any paid unit left is disabled.
 */

namespace XtreamPro\Connector\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use XtreamPro\Connector\Model\OrderService;

class CreditmemoSaved implements ObserverInterface
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
            $this->service->reconcile($observer->getEvent()->getCreditmemo()->getOrder());
        } catch (\Throwable $e) {
            $this->logger->error('Xtream UI Pro: creditmemo_save_commit_after failed: ' . $e->getMessage());
        }
    }
}
