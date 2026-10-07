<?php
/**
 * Cron job (every 5 minutes): try again the units that failed, that never got
 * their panel call (for example when the panel was down at payment time) and
 * the refunds that could not be passed on. At most 50 units per run, each
 * unit at most 8 times; after that the admin button "provision again" is the
 * way forward.
 */

namespace XtreamPro\Connector\Cron;

use Psr\Log\LoggerInterface;
use XtreamPro\Connector\Model\Config;
use XtreamPro\Connector\Model\OrderService;
use XtreamPro\Connector\Model\Storage;

class RetryFailed
{
    /** @var Storage */
    private $storage;

    /** @var OrderService */
    private $service;

    /** @var Config */
    private $config;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(Storage $storage, OrderService $service, Config $config, LoggerInterface $logger)
    {
        $this->storage = $storage;
        $this->service = $service;
        $this->config = $config;
        $this->logger = $logger;
    }

    public function execute(): void
    {
        if (!$this->config->isConfigured()) {
            return;
        }
        $orderIds = [];
        foreach ($this->storage->retryable(OrderService::MAX_ATTEMPTS, 50) as $unit) {
            $orderIds[(int) $unit['order_id']] = true;
        }
        foreach (array_keys($orderIds) as $orderId) {
            try {
                $this->service->processById($orderId);
            } catch (\Throwable $e) {
                $this->logger->error('Xtream UI Pro: retry of order ' . $orderId . ' failed: ' . $e->getMessage());
            }
        }
    }
}
