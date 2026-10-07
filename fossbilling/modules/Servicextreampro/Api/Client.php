<?php

declare(strict_types=1);
/**
 * Xtream UI Pro - FOSSBilling client API (api/client/servicextreampro/...).
 *
 * @version 1.1.0
 */

namespace Box\Mod\Servicextreampro\Api;

use FOSSBilling\Validation\Api\RequiredParams;

class Client extends \FOSSBilling\Api\AbstractApi
{
    /** Credentials and play links of one of the customer's own active orders. */
    #[RequiredParams(['order_id' => 'The order ID is required'])]
    public function details($data): array
    {
        return $this->getService()->detailsForOrder((int) $data['order_id'], (int) $this->getIdentity()->id);
    }
}
