<?php
/**
 * Admin: "Test connection" button of Stores > Configuration > Services > Xtream UI Pro.
 *
 * POST with the form key. Uses the values typed in the form; an empty or
 * masked API key means "the saved one". The key is never sent back.
 */

namespace XtreamPro\Connector\Controller\Adminhtml\Connection;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use XtreamPro\Connector\Core\ApiException;
use XtreamPro\Connector\Model\Config;
use XtreamPro\Connector\Model\Gateway;

class Test extends Action implements HttpPostActionInterface
{
    const ADMIN_RESOURCE = 'XtreamPro_Connector::config';

    /** @var JsonFactory */
    private $json;

    /** @var Config */
    private $config;

    /** @var Gateway */
    private $gateway;

    public function __construct(Context $context, JsonFactory $json, Config $config, Gateway $gateway)
    {
        parent::__construct($context);
        $this->json = $json;
        $this->config = $config;
        $this->gateway = $gateway;
    }

    public function execute(): ResultInterface
    {
        $url = trim((string) $this->getRequest()->getParam('api_url'));
        $key = trim((string) $this->getRequest()->getParam('api_key'));
        if ($url === '') {
            $url = $this->config->getApiUrl();
        }
        // An obscure field shows asterisks for a saved key: that means "keep the saved one".
        if ($key === '' || preg_match('/^\*+$/', $key)) {
            $key = $this->config->getApiKey();
        }
        try {
            $info = $this->gateway->provisionerFor($url, $key)->testConnection();
            $name = isset($info['username']) ? (string) $info['username'] : '';
            $credits = isset($info['credits']) ? (string) (int) $info['credits'] : '?';
            $result = ['success' => true, 'message' => (string) __('Connected as reseller "%1" with %2 credits.', $name, $credits)];
        } catch (ApiException $e) {
            $result = ['success' => false, 'message' => $e->getMessage()];
        }
        return $this->json->create()->setData($result);
    }
}
