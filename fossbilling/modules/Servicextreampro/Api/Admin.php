<?php

declare(strict_types=1);
/**
 * Xtream UI Pro - FOSSBilling admin API (api/admin/servicextreampro/...).
 *
 * @version 1.1.0
 */

namespace Box\Mod\Servicextreampro\Api;

use FOSSBilling\Validation\Api\RequiredParams;

class Admin extends \FOSSBilling\Api\AbstractApi
{
    /** Connection settings. An empty api_key keeps the stored key. */
    #[RequiredParams(['panel_url' => 'The panel URL is required'])]
    public function save_settings($data): bool
    {
        $this->checkPermissions('servicextreampro', 'manage_settings');

        return $this->getService()->saveSettings((string) $data['panel_url'], (string) ($data['api_key'] ?? ''));
    }

    /** Live connection test: the reseller account, its credits and the packages it can sell. Panel errors come back as text. */
    public function status($data = []): array
    {
        $this->checkPermissions('servicextreampro', 'manage_settings');

        return $this->getService()->getStatus();
    }

    /** Live line / sub-reseller data of an order (for the admin order page). */
    #[RequiredParams(['order_id' => 'The order ID is required'])]
    public function details($data): array
    {
        $this->checkPermissions('servicextreampro', 'manage_settings');

        return $this->getService()->detailsForOrder((int) $data['order_id']);
    }
}
