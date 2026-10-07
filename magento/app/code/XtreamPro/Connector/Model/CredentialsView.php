<?php
/**
 * Xtream UI Pro connector - what a customer may see about their units.
 *
 * Returns plain rows for the templates. Only ever called with the customer
 * (or the order) the caller has already checked. Nothing here exposes error
 * texts or panel ids: those are for the admin.
 */

namespace XtreamPro\Connector\Model;

use XtreamPro\Connector\Core\ApiException;
use XtreamPro\Connector\Model\Config\Source\Kind;

class CredentialsView
{
    /** @var Storage */
    private $storage;

    /** @var Gateway */
    private $gateway;

    /** @var Config */
    private $config;

    public function __construct(Storage $storage, Gateway $gateway, Config $config)
    {
        $this->storage = $storage;
        $this->gateway = $gateway;
        $this->config = $config;
    }

    /** @return array[] Rows of the units of one order. */
    public function forOrder(int $orderId): array
    {
        return $this->rows($this->storage->unitsOfOrder($orderId));
    }

    /** @return array[] Rows of all units of one customer. */
    public function forCustomer(int $customerId): array
    {
        return $this->rows($this->storage->unitsOfCustomer($customerId));
    }

    /** Sign-in link of the panel for sub-resellers, '' when the address is not configured. */
    public function panelLoginUrl(): string
    {
        $base = $this->config->getPanelUrl();
        return $base === '' ? '' : $base . '/login';
    }

    /**
     * Current credit balance of the customer's sub-reseller account, read live.
     *
     * @return int|null null when the customer has no account or the panel does not answer
     */
    public function balance(int $customerId): ?int
    {
        $account = $this->storage->resellerOf($customerId);
        if ($account === null || empty($account['panel_user_id'])) {
            return null;
        }
        try {
            return $this->gateway->provisioner()->readSubReseller($account['panel_user_id'])['credits'];
        } catch (ApiException $e) {
            return null;
        }
    }

    /**
     * @param array[] $units
     * @return array[] {kind, name, order, unit, state, username, password, links, credits}; state is active, pending or ended
     */
    private function rows(array $units): array
    {
        $accounts = [];
        $rows = [];
        foreach ($units as $unit) {
            $state = 'pending';
            if ($unit['status'] === Storage::STATUS_PROVISIONED) {
                $state = 'active';
            } elseif ($unit['status'] === Storage::STATUS_REVOKING || $unit['status'] === Storage::STATUS_REVOKED) {
                $state = 'ended';
            }
            $row = [
                'kind'     => $unit['kind'],
                'name'     => (string) $unit['item_name'],
                'order'    => (string) $unit['increment_id'],
                'unit'     => (int) $unit['unit'],
                'state'    => $state,
                'username' => '',
                'password' => '',
                'links'    => [],
                'credits'  => (int) $unit['credits_given'],
            ];
            if ($state === 'active') {
                $row['username'] = (string) $unit['username'];
                if ($unit['kind'] === Kind::RESELLER) {
                    // The password lives on the customer's one account, not on the unit.
                    $customerId = (int) $unit['customer_id'];
                    if (!array_key_exists($customerId, $accounts)) {
                        $accounts[$customerId] = $this->storage->resellerOf($customerId);
                    }
                    $row['password'] = $accounts[$customerId] ? (string) $accounts[$customerId]['password'] : '';
                } else {
                    $row['password'] = (string) $unit['password'];
                    $row['links'] = $unit['links'];
                }
            }
            $rows[] = $row;
        }
        return $rows;
    }
}
