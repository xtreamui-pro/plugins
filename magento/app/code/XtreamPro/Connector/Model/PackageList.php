<?php
/**
 * Xtream UI Pro connector - the panel's package list for the product form.
 *
 * Cached for 10 minutes. A second copy is kept for 30 days and used when the
 * panel cannot be reached, so that saving a product while the panel is down
 * does not wipe the package that was selected on it.
 */

namespace XtreamPro\Connector\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\SerializerInterface;
use XtreamPro\Connector\Core\ApiException;

class PackageList
{
    const FRESH_SECONDS = 600;
    const STALE_SECONDS = 2592000;

    /** @var Config */
    private $config;

    /** @var Gateway */
    private $gateway;

    /** @var CacheInterface */
    private $cache;

    /** @var SerializerInterface */
    private $serializer;

    public function __construct(Config $config, Gateway $gateway, CacheInterface $cache, SerializerInterface $serializer)
    {
        $this->config = $config;
        $this->gateway = $gateway;
        $this->cache = $cache;
        $this->serializer = $serializer;
    }

    /**
     * @return array[] Entries of {id, name, label}; empty when never loaded and the panel is not reachable.
     */
    public function all(): array
    {
        if (!$this->config->isConfigured()) {
            return [];
        }
        // The key part keeps the lists of two different panels apart.
        $suffix = hash('sha256', $this->config->getApiUrl());
        $fresh = $this->read('xtreampro_pkg_' . $suffix);
        if ($fresh !== null) {
            return $fresh;
        }
        try {
            $list = $this->gateway->provisioner()->packages();
        } catch (ApiException $e) {
            return $this->read('xtreampro_pkg_stale_' . $suffix) ?? [];
        }
        $data = $this->serializer->serialize($list);
        $this->cache->save($data, 'xtreampro_pkg_' . $suffix, [], self::FRESH_SECONDS);
        $this->cache->save($data, 'xtreampro_pkg_stale_' . $suffix, [], self::STALE_SECONDS);
        return $list;
    }

    private function read(string $id): ?array
    {
        $raw = $this->cache->load($id);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $list = $this->serializer->unserialize($raw);
        return is_array($list) ? $list : null;
    }
}
