<?php
/**
 * Xtream UI Pro connector - builds the platform independent core from the
 * store configuration and connects its log to Magento's logger.
 */

namespace XtreamPro\Connector\Model;

use Psr\Log\LoggerInterface;
use XtreamPro\Connector\Core\ApiClient;
use XtreamPro\Connector\Core\ApiException;
use XtreamPro\Connector\Core\Provisioner;

class Gateway
{
    /** @var Config */
    private $config;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(Config $config, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * @throws ApiException CONFIG when the API URL or the key is missing.
     */
    public function provisioner(): Provisioner
    {
        return $this->provisionerFor($this->config->getApiUrl(), $this->config->getApiKey());
    }

    /**
     * @throws ApiException CONFIG when the URL or the key is not usable.
     */
    public function provisionerFor(string $apiUrl, string $apiKey): Provisioner
    {
        $logger = $this->logger;
        return new Provisioner(new ApiClient($apiUrl, $apiKey, function ($level, $message, array $context) use ($logger) {
            $logger->log($level, $message, $context);
        }));
    }
}
