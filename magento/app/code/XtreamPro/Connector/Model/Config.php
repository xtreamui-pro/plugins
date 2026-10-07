<?php
/**
 * Xtream UI Pro connector - settings from Stores > Configuration > Services > Xtream UI Pro.
 */

namespace XtreamPro\Connector\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

class Config
{
    const PATH_API_URL = 'xtreampro_connector/api/api_url';
    const PATH_API_KEY = 'xtreampro_connector/api/api_key';
    const PATH_PANEL_URL = 'xtreampro_connector/api/panel_url';

    /** @var ScopeConfigInterface */
    private $scopeConfig;

    /** @var EncryptorInterface */
    private $encryptor;

    public function __construct(ScopeConfigInterface $scopeConfig, EncryptorInterface $encryptor)
    {
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
    }

    /** Panel API base address without trailing slash. */
    public function getApiUrl(): string
    {
        return rtrim(trim((string) $this->scopeConfig->getValue(self::PATH_API_URL)), '/');
    }

    /** The API key, decrypted. Never log it, never print it. */
    public function getApiKey(): string
    {
        $stored = (string) $this->scopeConfig->getValue(self::PATH_API_KEY);
        return $stored === '' ? '' : trim((string) $this->encryptor->decrypt($stored));
    }

    /** Dashboard address for sub-resellers, empty when not set or not http(s). */
    public function getPanelUrl(): string
    {
        $url = rtrim(trim((string) $this->scopeConfig->getValue(self::PATH_PANEL_URL)), '/');
        return preg_match('#^https?://#i', $url) ? $url : '';
    }

    public function isConfigured(): bool
    {
        return $this->getApiUrl() !== '' && $this->getApiKey() !== '';
    }
}
