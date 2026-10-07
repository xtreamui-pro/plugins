<?php
/**
 * Xtream UI Pro - the business logic of the connector. PLATFORM INDEPENDENT.
 *
 * It knows nothing about PrestaShop: it gets plain arrays ("jobs") from the
 * module glue, calls the Reseller API through XtreamproApiClient, and keeps
 * what it did in an XtreamproStore. The PrestaShop module only translates
 * shop events (order paid, order cancelled, button pressed) into calls here.
 *
 * What one "unit" is: one bought item, identified by order detail id + unit
 * number. A customer who orders quantity 3 of a product gets units 1, 2, 3.
 *
 *   IPTV line product   every unit is its own line.
 *   Sub-reseller        a customer has ONE sub-reseller account (created by
 *   product             the first unit). Every unit hands the credits of the
 *                       product to that account.
 *
 * Idempotency: every call that costs credits carries a request id built from
 * stable ids (shop instance, order detail id, unit number, counters). Asking
 * twice returns the first result instead of charging twice, so a retry after a
 * timeout, a double click or a repeated shop event is safe.
 *
 * Written for PHP 7.1 and newer.
 */
class XtreamproProvisioner
{
    const KIND_LINE = 'line';
    const KIND_RESELLER = 'reseller';

    /** @var XtreamproApiClient */
    private $api;

    /** @var XtreamproStore */
    private $store;

    /** @var string Makes request ids unique per shop, so two shops on one reseller key never collide. */
    private $prefix;

    /** @var callable|null function ($message) */
    private $logger;

    /**
     * @param XtreamproApiClient $api
     * @param XtreamproStore     $store
     * @param string             $instance Short random token of this shop (letters and digits)
     * @param callable|null      $logger
     */
    public function __construct(XtreamproApiClient $api, XtreamproStore $store, $instance, $logger = null)
    {
        $this->api = $api;
        $this->store = $store;
        $this->prefix = 'xp' . preg_replace('/[^A-Za-z0-9]/', '', (string) $instance) . '-';
        $this->logger = $logger;
    }

    // =======================================================================
    // Order paid: create (or give back) what the customer bought
    // =======================================================================

    /**
     * Provision one unit.
     *
     * $job: detail_id, unit, order_id, order_ref, kind ('line' | 'reseller'),
     *       package_id, trial (lines), credits (resellers), customer =>
     *       array(id, email, fullname, login_hint, is_guest)
     *
     * Running it again for a unit that is done does nothing. A unit that failed
     * before is retried with the same request id, so it is never charged twice.
     * Returns array('ok' => bool, 'message' => string). Never throws.
     */
    public function provision(array $job)
    {
        $detail = (int) $job['detail_id'];
        $unit = (int) $job['unit'];
        $row = null;
        try {
            $row = $this->store->getUnit($detail, $unit);
            if ($job['kind'] === self::KIND_RESELLER) {
                $message = $this->provisionReseller($job, $row);
            } elseif ($job['kind'] === self::KIND_LINE) {
                $message = $this->provisionLine($job, $row);
            } else {
                throw new RuntimeException('Unknown product type "' . $job['kind'] . '".');
            }
            $this->log('provision ' . $job['kind'] . ' detail=' . $detail . ' unit=' . $unit . ': ' . $message);
            return array('ok' => true, 'message' => $message);
        } catch (Exception $e) {
            $this->recordFailure($job, $row, $e->getMessage());
            $this->log('provision ' . $job['kind'] . ' detail=' . $detail . ' unit=' . $unit . ' failed: ' . $e->getMessage());
            return array('ok' => false, 'message' => $e->getMessage());
        }
    }

    private function provisionLine(array $job, $row)
    {
        $detail = (int) $job['detail_id'];
        $unit = (int) $job['unit'];

        if ($row !== null && in_array($row['status'], array('ok', 'suspended'), true)) {
            return 'already provisioned';
        }

        // The order was cancelled and is paid again: give the same line back.
        if ($row !== null && $row['status'] === 'revoked' && (string) $row['panel_id'] !== '') {
            $this->api->lineAction('enable_line', (int) $row['panel_id']);
            $this->store->saveUnit($detail, $unit, array('status' => 'ok', 'error' => ''));
            return 'line #' . $row['panel_id'] . ' enabled again';
        }

        $packageId = (int) $job['package_id'];
        if ($packageId <= 0) {
            throw new RuntimeException('No package is selected for this product. Choose one in the product settings, then provision again.');
        }

        // Ask the panel first: too few credits, a package that is not on sale or one for boxes only fail here
        // with the amounts and nothing is created.
        $this->api->assertCanSellPackage($packageId, !empty($job['trial']), true);

        // Blank credentials: the panel generates them. The reseller's group may
        // also replace custom ones, so the final values are read from the answer.
        $data = $this->api->createLine(
            $packageId,
            !empty($job['trial']),
            '',
            '',
            $this->prefix . 'line-' . $detail . '-' . $unit
        );
        $line = (is_array($data) && isset($data['line']) && is_array($data['line'])) ? $data['line'] : array();
        if (!isset($line['id'])) {
            throw new XtreamproApiException('BAD_RESPONSE');
        }
        $password = isset($line['password']) ? (string) $line['password'] : '';
        if ($password === '' && isset($data['password'])) {
            $password = (string) $data['password'];
        }

        $this->store->saveUnit($detail, $unit, array(
            'order_id'    => (int) $job['order_id'],
            'customer_id' => (int) $job['customer']['id'],
            'kind'        => self::KIND_LINE,
            'status'      => 'ok',
            'panel_id'    => (string) (int) $line['id'],
            'username'    => isset($line['username']) ? (string) $line['username'] : '',
            'password'    => $password,
            'links'       => isset($data['links']) && is_array($data['links']) ? $data['links'] : array(),
            'error'       => '',
        ));
        return 'line #' . (int) $line['id'] . ' created';
    }

    private function provisionReseller(array $job, $row)
    {
        $detail = (int) $job['detail_id'];
        $unit = (int) $job['unit'];
        $customer = $job['customer'];

        if (!empty($customer['is_guest']) || (int) $customer['id'] <= 0) {
            throw new RuntimeException('A sub-reseller account needs a registered customer: guest checkout is not supported for this product.');
        }
        $credits = max(0, (int) $job['credits']);

        if ($row !== null && in_array($row['status'], array('ok', 'suspended'), true)) {
            return 'already provisioned';
        }
        // After a revocation the generation is higher, so the credits are handed over again.
        $generation = $row !== null ? (int) $row['generation'] : 0;

        $account = $this->ensureAccount($job, $credits);

        // Keep what is known before the credit transfer: when it fails the
        // account stays attached to this unit and a retry only repeats the transfer.
        $createdHere = $account['origin'] === $detail . '-' . $unit;
        $this->store->saveUnit($detail, $unit, array(
            'order_id'    => (int) $job['order_id'],
            'customer_id' => (int) $customer['id'],
            'kind'        => self::KIND_RESELLER,
            'status'      => 'error',
            'panel_id'    => $account['panel_user_id'],
            'username'    => $account['username'],
            'password'    => $createdHere ? $account['password'] : '',
        ));

        if ($credits > 0) {
            $this->api->assertCanGiveCredits($credits);
            $this->api->adjustCredits(
                $account['panel_user_id'],
                $credits,
                'Order ' . $job['order_ref'],
                $this->prefix . 'subc-' . $detail . '-' . $unit . '-' . $generation
            );
        }
        $this->store->saveUnit($detail, $unit, array('status' => 'ok', 'credits' => $credits, 'error' => ''));
        return ($createdHere ? 'account ' . $account['username'] . ' created, ' : 'account ' . $account['username'] . ', ')
            . $credits . ' credits handed over';
    }

    /**
     * The customer's sub-reseller account: created on first use, enabled again
     * when this module had disabled it. The credentials are saved BEFORE the
     * panel is called: the panel keeps only a hash of the password, so a repeated
     * call (timeout) must send the very same values.
     */
    private function ensureAccount(array $job, $credits = 0)
    {
        $customer = $job['customer'];
        $customerId = (int) $customer['id'];
        $account = $this->store->getAccount($customerId);

        if ($account === null || (string) $account['panel_user_id'] === '') {
            // The price of the account plus the credits to hand over: checked before the account exists.
            $this->api->assertCanCreateSubUser((int) $credits);
            $username = $account !== null && (string) $account['username'] !== ''
                ? (string) $account['username'] : $this->makeUsername($customer);
            $password = $account !== null && (string) $account['password'] !== ''
                ? (string) $account['password'] : $this->randomString(14, 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789');
            $this->store->saveAccount($customerId, array(
                'panel_user_id' => '',
                'username'      => $username,
                'password'      => $password,
                'origin'        => (int) $job['detail_id'] . '-' . (int) $job['unit'],
                'disabled'      => 0,
            ));

            $fullname = trim((string) $customer['fullname']);
            $data = $this->api->createSubUser(
                $username,
                $password,
                (string) $customer['email'],
                $fullname !== '' ? $fullname : $username,
                $this->prefix . 'sub-' . $customerId
            );
            $user = (is_array($data) && isset($data['user']) && is_array($data['user'])) ? $data['user'] : array();
            if (!isset($user['id'])) {
                throw new XtreamproApiException('BAD_RESPONSE');
            }
            // The panel may answer with other credentials (a replayed request).
            if (!empty($user['username'])) {
                $username = (string) $user['username'];
            }
            if (!empty($data['password'])) {
                $password = (string) $data['password'];
            }
            $this->store->saveAccount($customerId, array(
                'panel_user_id' => (string) $user['id'],
                'username'      => $username,
                'password'      => $password,
            ));
        } elseif (!empty($account['disabled'])) {
            $this->api->subUserAction('enable_user', $account['panel_user_id']);
            $this->store->saveAccount($customerId, array('disabled' => 0));
        }
        return $this->store->getAccount($customerId);
    }

    /** 3 to 32 characters of letters, digits and "_ . -": the panel's rule. */
    private function makeUsername(array $customer)
    {
        $base = preg_replace('/[^A-Za-z0-9_.-]/', '', (string) $customer['login_hint']);
        $base = substr($base, 0, 24);
        if ($base === '') {
            $base = 'c' . (int) $customer['id'];
        }
        return $base . $this->randomString(5, 'abcdefghijklmnopqrstuvwxyz0123456789');
    }

    private function randomString($length, $alphabet)
    {
        $out = '';
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }

    // =======================================================================
    // Order cancelled / refunded
    // =======================================================================

    /**
     * Take a unit back: a line is disabled (never deleted: deleting is final),
     * a sub-reseller unit gets its credits taken back, and the account is
     * disabled when this unit created it. Credits that were already spent cannot
     * be taken back: that is reported and the unit stays as it was, so
     * revoking again retries it.
     *
     * $job: detail_id, unit, order_ref
     */
    public function revoke(array $job)
    {
        $detail = (int) $job['detail_id'];
        $unit = (int) $job['unit'];
        try {
            $row = $this->store->getUnit($detail, $unit);
            if ($row === null) {
                return array('ok' => true, 'message' => 'nothing to revoke');
            }
            if ($row['status'] === 'revoked') {
                return array('ok' => true, 'message' => 'already revoked');
            }
            // Never provisioned (the order failed): nothing in the panel to undo.
            if ($row['status'] === 'error' && (string) $row['panel_id'] === '') {
                $this->store->saveUnit($detail, $unit, array('status' => 'revoked', 'error' => ''));
                return array('ok' => true, 'message' => 'nothing was provisioned');
            }

            if ($row['kind'] === self::KIND_LINE) {
                $this->ignoreMissing(function () use ($row) {
                    $this->api->lineAction('disable_line', (int) $row['panel_id']);
                });
                $this->store->saveUnit($detail, $unit, array('status' => 'revoked', 'error' => ''));
                $this->log('revoke line detail=' . $detail . ' unit=' . $unit);
                return array('ok' => true, 'message' => 'line #' . $row['panel_id'] . ' disabled');
            }

            $problem = '';
            $generation = (int) $row['generation'];
            $credits = (int) $row['credits'];
            if ($credits > 0 && in_array($row['status'], array('ok', 'suspended'), true)) {
                try {
                    $this->api->adjustCredits(
                        $row['panel_id'],
                        -$credits,
                        'Order ' . $job['order_ref'] . ' cancelled',
                        $this->prefix . 'subx-' . $detail . '-' . $unit . '-' . $generation
                    );
                } catch (Exception $e) {
                    $problem = 'Could not take the ' . $credits . ' credits back (they may be spent already): ' . $e->getMessage();
                }
            }
            $account = $this->store->getAccount((int) $row['customer_id']);
            if ($account !== null && $account['origin'] === $detail . '-' . $unit && empty($account['disabled']) && (string) $account['panel_user_id'] !== '') {
                try {
                    $this->ignoreMissing(function () use ($account) {
                        $this->api->subUserAction('disable_user', $account['panel_user_id']);
                    });
                    $this->store->saveAccount((int) $row['customer_id'], array('disabled' => 1));
                } catch (Exception $e) {
                    $problem .= ($problem !== '' ? ' ' : '') . 'Could not disable the account: ' . $e->getMessage();
                }
            }
            if ($problem !== '') {
                $this->store->saveUnit($detail, $unit, array('error' => $problem));
                return array('ok' => false, 'message' => $problem);
            }
            $this->store->saveUnit($detail, $unit, array('status' => 'revoked', 'generation' => $generation + 1, 'error' => ''));
            $this->log('revoke reseller detail=' . $detail . ' unit=' . $unit);
            return array('ok' => true, 'message' => $credits . ' credits taken back');
        } catch (Exception $e) {
            $this->recordProblem($detail, $unit, $e->getMessage());
            return array('ok' => false, 'message' => $e->getMessage());
        }
    }

    // =======================================================================
    // Manual actions
    // =======================================================================

    /** Disable a unit without cancelling it (a line, or the sub-reseller account). */
    public function suspend($detailId, $unit)
    {
        return $this->setEnabled((int) $detailId, (int) $unit, false);
    }

    /** Enable a suspended unit again. */
    public function unsuspend($detailId, $unit)
    {
        return $this->setEnabled((int) $detailId, (int) $unit, true);
    }

    private function setEnabled($detail, $unit, $enable)
    {
        try {
            $row = $this->store->getUnit($detail, $unit);
            $from = $enable ? 'suspended' : 'ok';
            if ($row === null || $row['status'] !== $from || (string) $row['panel_id'] === '') {
                throw new RuntimeException($enable ? 'This item is not suspended.' : 'Only an active item can be suspended.');
            }
            if ($row['kind'] === self::KIND_LINE) {
                $this->api->lineAction($enable ? 'enable_line' : 'disable_line', (int) $row['panel_id']);
            } else {
                $this->api->subUserAction($enable ? 'enable_user' : 'disable_user', $row['panel_id']);
                $this->store->saveAccount((int) $row['customer_id'], array('disabled' => $enable ? 0 : 1));
            }
            $this->store->saveUnit($detail, $unit, array('status' => $enable ? 'ok' : 'suspended', 'error' => ''));
            return array('ok' => true, 'message' => $enable ? 'enabled' : 'suspended');
        } catch (Exception $e) {
            $this->recordProblem($detail, $unit, $e->getMessage());
            return array('ok' => false, 'message' => $e->getMessage());
        }
    }

    /**
     * Renew a line: costs credits, extends it by the package's duration.
     * $number is the renewal's number; leave it out for "the next one". Asking
     * for the same number again does not charge again (idempotent request id).
     * Sub-reseller units are not renewed: order the credits product again.
     */
    public function renew($detailId, $unit, $number = null)
    {
        $detail = (int) $detailId;
        $unit = (int) $unit;
        try {
            $row = $this->store->getUnit($detail, $unit);
            if ($row === null || $row['status'] !== 'ok' || (string) $row['panel_id'] === '') {
                throw new RuntimeException('Only an active item can be renewed.');
            }
            if ($row['kind'] !== self::KIND_LINE) {
                throw new RuntimeException('Only IPTV lines are renewed. To give a sub-reseller account more credits, order the credits product again.');
            }
            $n = $number !== null ? (int) $number : (int) $row['renewals'] + 1;
            // Fails with the amounts when the balance cannot pay the period; nothing is sold then.
            $current = $this->api->getLine((int) $row['panel_id']);
            if (is_array($current) && isset($current['package_id']) && (int) $current['package_id'] > 0) {
                $this->api->assertCanSellPackage((int) $current['package_id'], false, false);
            }
            $data = $this->api->renewLine((int) $row['panel_id'], $this->prefix . 'renew-' . $detail . '-' . $unit . '-' . $n);

            $fields = array('renewals' => max((int) $row['renewals'], $n), 'error' => '');
            if (is_array($data) && isset($data['links']) && is_array($data['links'])) {
                $fields['links'] = $data['links'];
            }
            $this->store->saveUnit($detail, $unit, $fields);
            $this->log('renew line detail=' . $detail . ' unit=' . $unit . ' number=' . $n);
            return array('ok' => true, 'message' => 'line #' . $row['panel_id'] . ' renewed');
        } catch (Exception $e) {
            $this->recordProblem($detail, $unit, $e->getMessage());
            return array('ok' => false, 'message' => $e->getMessage());
        }
    }

    // =======================================================================
    // Helpers
    // =======================================================================

    /** Run $call; a "not found" answer means the thing is gone already, which is the goal. */
    private function ignoreMissing($call)
    {
        try {
            $call();
        } catch (XtreamproApiException $e) {
            if ($e->getErrorCode() !== 'RESOURCE_NOT_FOUND') {
                throw $e;
            }
        }
    }

    /** A provisioning failure: remember the text; an unfinished unit gets status "error". */
    private function recordFailure(array $job, $row, $message)
    {
        $fields = array('error' => $message);
        if ($row === null) {
            $fields += array(
                'order_id'    => (int) $job['order_id'],
                'customer_id' => (int) $job['customer']['id'],
                'kind'        => $job['kind'],
                'status'      => 'error',
            );
        }
        try {
            $this->store->saveUnit((int) $job['detail_id'], (int) $job['unit'], $fields);
        } catch (Exception $e) {
            $this->log('could not record the failure of detail=' . (int) $job['detail_id'] . ': ' . $e->getMessage());
        }
    }

    /** A problem with a unit that exists: remember the text, keep the status. */
    private function recordProblem($detail, $unit, $message)
    {
        try {
            if ($this->store->getUnit($detail, $unit) !== null) {
                $this->store->saveUnit($detail, $unit, array('error' => $message));
            }
        } catch (Exception $e) {
            $this->log('could not record the problem of detail=' . $detail . ': ' . $e->getMessage());
        }
    }

    private function log($message)
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, $message);
        }
    }
}
