<?php
/**
 * Xtream UI Pro connector for Magento 2 - the business logic, without Magento.
 *
 * The Magento side (observers, cron, controllers, blocks) only translates shop
 * events into calls on this class and stores what it returns. Every method
 * either returns plain arrays or throws ApiException with a readable message.
 *
 * Idempotency: create / renew / credit transfers carry a request id derived
 * from stable shop ids (see the *RequestId() methods). The panel returns the
 * first result for a repeated request id instead of charging again, so a retry
 * after a timeout never sells twice. The "generation" argument is part of the
 * id: raise it to sell again for the same shop ids (the shop never needs this,
 * an order item is sold once; the harness uses it to prove the behaviour).
 *
 * @version 1.1.0
 */

namespace XtreamPro\Connector\Core;

class Provisioner
{
    /** Letters and digits without look-alikes (0/O, 1/l/I) for generated passwords. */
    const PASSWORD_ALPHABET = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    const LINK_KEYS = array('server', 'm3u', 'm3u_hls', 'xmltv', 'player_api', 'web_player');

    /** @var ApiClient */
    private $api;

    public function __construct(ApiClient $api)
    {
        $this->api = $api;
    }

    // ---- request ids (max 64 characters, from stable shop ids) ----------

    /** One IPTV line per order item unit. */
    public static function lineRequestId($orderItemId, $unit, $generation = 0)
    {
        return self::requestId('mage-l', array((int) $orderItemId, (int) $unit), $generation);
    }

    /** One sub-reseller account per customer. */
    public static function accountRequestId($customerId, $generation = 0)
    {
        return self::requestId('mage-s', array((int) $customerId), $generation);
    }

    /** Credits handed over for one order item unit. */
    public static function creditRequestId($orderItemId, $unit, $generation = 0)
    {
        return self::requestId('mage-c', array((int) $orderItemId, (int) $unit), $generation);
    }

    /** Credits taken back for one order item unit. */
    public static function takeBackRequestId($orderItemId, $unit, $generation = 0)
    {
        return self::requestId('mage-x', array((int) $orderItemId, (int) $unit), $generation);
    }

    /** Renewal of a line; $period names the period paid for (for example the due date as Ymd). */
    public static function renewRequestId($lineId, $period)
    {
        return substr('mage-r-' . (int) $lineId . '-' . preg_replace('/[^A-Za-z0-9]/', '', (string) $period), 0, 64);
    }

    private static function requestId($prefix, array $ids, $generation)
    {
        $id = $prefix . '-' . implode('-', $ids);
        if ((int) $generation > 0) {
            $id .= '-g' . (int) $generation;
        }
        return substr($id, 0, 64);
    }

    // ---- generated credentials (CSPRNG) ---------------------------------

    /**
     * Panel username (3 to 32 of letters, digits, "_", ".", "-") from a seed
     * such as the local part of an email address, plus 4 random letters.
     */
    public static function newUsername($seed)
    {
        $base = preg_replace('/[^A-Za-z0-9_.-]/', '', (string) $seed);
        if ($base === '') {
            $base = 'r';
        }
        return substr(substr($base, 0, 27) . self::random(4, 'abcdefghijklmnopqrstuvwxyz'), 0, 32);
    }

    public static function newPassword($length = 14)
    {
        return self::random(max(8, (int) $length), self::PASSWORD_ALPHABET);
    }

    private static function random($length, $alphabet)
    {
        $out = '';
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }

    // ---- connection and catalogue ---------------------------------------

    /** Asks the panel who the API key belongs to. Throws when the key or the URL is wrong. */
    public function testConnection()
    {
        $info = $this->api->userInfo();
        return is_array($info) ? $info : array();
    }

    /**
     * Packages the reseller may sell, as list of {id, name, label}.
     *
     * @return array[]
     */
    public function packages()
    {
        $out = array();
        foreach ($this->api->packages() as $pkg) {
            // A package for MAG / Enigma boxes only (`sells` without "line") cannot be sold as a line.
            if (!is_array($pkg) || !isset($pkg['id']) || !ApiClient::sellsLine($pkg)) {
                continue;
            }
            $name = isset($pkg['name']) ? (string) $pkg['name'] : ('#' . $pkg['id']);
            if (!empty($pkg['is_official'])) {
                $detail = (isset($pkg['official_credits']) ? $pkg['official_credits'] : '?') . ' credits, '
                    . (isset($pkg['official_duration']) ? $pkg['official_duration'] : '?') . ' '
                    . (isset($pkg['official_duration_in']) ? $pkg['official_duration_in'] : '');
            } else {
                $detail = 'trial only';
            }
            $out[] = array(
                'id'    => (int) $pkg['id'],
                'name'  => $name,
                'label' => $name . ' (' . trim($detail) . ')',
            );
        }
        return $out;
    }

    // ---- lines ----------------------------------------------------------

    /**
     * Sell one line. The panel generates username and password (the
     * reseller's group may ignore custom ones anyway), so what it answers is
     * always the truth.
     *
     * @return array{id:int,username:string,password:string,links:array}
     */
    public function createLine($packageId, $trial, $requestId)
    {
        if ((int) $packageId <= 0) {
            throw new ApiException('INVALID_PACKAGE');
        }
        // Ask the panel first: too few credits, a package that is not on sale or one for boxes only fail here
        // with the amounts and nothing is created.
        $this->api->assertCanSellPackage((int) $packageId, (bool) $trial, true);
        $data = $this->api->createLine((int) $packageId, (bool) $trial, '', '', $requestId);
        return $this->lineFromResult($data);
    }

    /** Extend a line by the package's official duration. Charged. */
    public function renewLine($lineId, $requestId)
    {
        // Fails with the amounts when the balance cannot pay the period; nothing is sold then.
        $current = $this->api->getLine((int) $lineId);
        if (is_array($current) && isset($current['package_id']) && (int) $current['package_id'] > 0) {
            $this->api->assertCanSellPackage((int) $current['package_id'], false, false);
        }
        $data = $this->api->renewLine((int) $lineId, $requestId);
        $line = $this->lineFromResult($data);
        if ($line['id'] <= 0) {
            $line['id'] = (int) $lineId;
        }
        return $line;
    }

    /**
     * Current state of a line, read live.
     *
     * @return array{id:int,username:string,password:string,status:string,exp_date:mixed,max_connections:mixed,links:array}
     */
    public function readLine($lineId)
    {
        $data = $this->api->getLine((int) $lineId);
        $row = (is_array($data) && isset($data['line']) && is_array($data['line'])) ? $data['line'] : $data;
        if (!is_array($row) || !isset($row['id'])) {
            throw new ApiException('BAD_RESPONSE');
        }
        $line = $this->lineFromRow($row, is_array($data) ? $data : array());
        $line['status'] = isset($row['status']) ? (string) $row['status'] : '';
        $line['exp_date'] = isset($row['exp_date']) ? $row['exp_date'] : null;
        $line['max_connections'] = isset($row['max_connections']) ? $row['max_connections'] : null;
        return $line;
    }

    public function suspendLine($lineId)
    {
        $this->api->lineAction('disable_line', (int) $lineId);
    }

    public function unsuspendLine($lineId)
    {
        $this->api->lineAction('enable_line', (int) $lineId);
    }

    /**
     * Take the line away: only disable it (default) or, with $delete true,
     * delete it (FINAL on the panel). A line that is already gone counts as terminated.
     *
     * @return bool false when the line was already gone
     */
    public function terminateLine($lineId, $delete = false)
    {
        try {
            $this->api->lineAction($delete ? 'delete_line' : 'disable_line', (int) $lineId);
        } catch (ApiException $e) {
            if ($e->getErrorCode() === 'RESOURCE_NOT_FOUND') {
                return false;
            }
            throw $e;
        }
        return true;
    }

    // ---- sub-reseller accounts ------------------------------------------

    /**
     * Create the account. Username and password must be chosen (and kept)
     * BEFORE the call: the panel keeps only a hash, and a retry with the same
     * request id has to send the very same values.
     *
     * @param array $account username, password, email, fullname
     * @param int   $creditsOnCreate credits handed over right after: the balance must cover them plus the account price
     * @return array{id:string,username:string,password:string}
     */
    public function createSubReseller(array $account, $requestId, $creditsOnCreate = 0)
    {
        $username = isset($account['username']) ? (string) $account['username'] : '';
        $password = isset($account['password']) ? (string) $account['password'] : '';
        if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $username)) {
            throw new ApiException('INVALID_REQUEST', 0, 'The username must have 3 to 32 letters, digits, "_", "." or "-".');
        }
        if (strlen($password) < 8) {
            throw new ApiException('INVALID_REQUEST', 0, 'The password must have at least 8 characters.');
        }
        // The price of the account plus the credits to hand over: checked before the account exists.
        $this->api->assertCanCreateSubUser((int) $creditsOnCreate);
        $data = $this->api->createSubUser(
            $username,
            $password,
            isset($account['email']) ? $account['email'] : '',
            isset($account['fullname']) ? substr((string) $account['fullname'], 0, 128) : '',
            $requestId
        );
        $user = (is_array($data) && isset($data['user']) && is_array($data['user'])) ? $data['user'] : array();
        if (!isset($user['id'])) {
            throw new ApiException('BAD_RESPONSE');
        }
        // The panel may answer with other credentials (a replayed request).
        return array(
            'id'       => (string) $user['id'],
            'username' => !empty($user['username']) ? (string) $user['username'] : $username,
            'password' => !empty($data['password']) ? (string) $data['password'] : $password,
        );
    }

    /**
     * Current state of the account, read live.
     *
     * @return array{id:string,username:string,status:string,credits:int}
     */
    public function readSubReseller($userId)
    {
        $user = $this->api->getUser((string) $userId);
        if (!is_array($user) || !isset($user['id'])) {
            throw new ApiException('BAD_RESPONSE');
        }
        return array(
            'id'       => (string) $user['id'],
            'username' => isset($user['username']) ? (string) $user['username'] : '',
            'status'   => isset($user['status']) ? (string) $user['status'] : '',
            'credits'  => isset($user['credits']) ? (int) $user['credits'] : 0,
        );
    }

    /** Hand credits from the reseller to the account. Charged to the reseller's balance. */
    public function giveCredits($userId, $credits, $note, $requestId)
    {
        if ((int) $credits <= 0) {
            throw new ApiException('INVALID_REQUEST', 0, 'The number of credits must be above zero.');
        }
        $this->api->assertCanGiveCredits((int) $credits);
        $this->api->adjustCredits((string) $userId, (int) $credits, $note, $requestId);
    }

    /** Take credits back from the account into the reseller's balance (fails when they are spent). */
    public function takeBackCredits($userId, $credits, $note, $requestId)
    {
        if ((int) $credits <= 0) {
            throw new ApiException('INVALID_REQUEST', 0, 'The number of credits must be above zero.');
        }
        $this->api->adjustCredits((string) $userId, -((int) $credits), $note, $requestId);
    }

    public function suspendSubReseller($userId)
    {
        $this->api->subUserAction('disable_user', (string) $userId);
    }

    public function unsuspendSubReseller($userId)
    {
        $this->api->subUserAction('enable_user', (string) $userId);
    }

    /**
     * The panel has no delete for accounts below a reseller through this
     * connector: terminating one disables it. Already gone counts as done.
     *
     * @return bool false when the account was already gone
     */
    public function terminateSubReseller($userId)
    {
        try {
            $this->api->subUserAction('disable_user', (string) $userId);
        } catch (ApiException $e) {
            if ($e->getErrorCode() === 'RESOURCE_NOT_FOUND') {
                return false;
            }
            throw $e;
        }
        return true;
    }

    // ---- result parsing --------------------------------------------------

    /** Credentials and links from a create_line / renew_line answer. */
    private function lineFromResult($data)
    {
        $data = is_array($data) ? $data : array();
        $row = isset($data['line']) && is_array($data['line']) ? $data['line'] : array();
        if (!isset($row['id'])) {
            throw new ApiException('BAD_RESPONSE');
        }
        return $this->lineFromRow($row, $data);
    }

    private function lineFromRow(array $row, array $envelope)
    {
        $password = '';
        if (isset($row['password']) && (string) $row['password'] !== '') {
            $password = (string) $row['password'];
        } elseif (isset($envelope['password'])) {
            $password = (string) $envelope['password'];
        }
        $links = array();
        if (isset($envelope['links']) && is_array($envelope['links'])) {
            foreach (self::LINK_KEYS as $key) {
                if (isset($envelope['links'][$key]) && is_string($envelope['links'][$key]) && $envelope['links'][$key] !== '') {
                    $links[$key] = $envelope['links'][$key];
                }
            }
        }
        return array(
            'id'       => (int) $row['id'],
            'username' => isset($row['username']) ? (string) $row['username'] : '',
            'password' => $password,
            'links'    => $links,
        );
    }
}
