<?php

/**
 * Xtream UI Pro - small Reseller API client (curl based, no dependencies).
 *
 * Talks to {base}/reseller/v1 with the reseller's API key in the X-API-Key
 * header. Reads are GET, mutations are POST with a form-urlencoded body.
 *
 * @package blesta
 * @subpackage blesta.components.modules.xtreampro
 */

/**
 * Error raised for every failure: transport problem, bad response or an API
 * error code. The module turns getErrorCode() into a readable language string.
 */
class XtreamproApiException extends Exception
{
    /** @var string */
    private $errorCode;

    /** @var int */
    private $httpStatus;

    /** @var string */
    private $detail;

    /**
     * @param string $errorCode API (or client side) error code
     * @param int $httpStatus HTTP status of the answer, 0 when there was none
     * @param string $detail Sentence appended to the readable text, e.g. the amounts of a refused sale
     */
    public function __construct($errorCode, $httpStatus = 0, $detail = '')
    {
        $this->errorCode = (string) $errorCode;
        $this->httpStatus = (int) $httpStatus;
        $this->detail = (string) $detail;
        parent::__construct($this->errorCode);
    }

    public function getDetail()
    {
        return $this->detail;
    }

    public function getErrorCode()
    {
        return $this->errorCode;
    }

    public function getHttpStatus()
    {
        return $this->httpStatus;
    }
}

class XtreamproApi
{
    /** Sent as "X-Connector: blesta/<version>" on every call; keep in step with Xtreampro::VERSION and config.json. */
    const VERSION = '1.1.0';

    const CONNECT_TIMEOUT = 5;
    const TIMEOUT = 20;

    /** @var string */
    private $base;

    /** @var string */
    private $apiKey;

    /** @var array|null Last request (never the key, line passwords masked) for the module log. */
    public $lastRequest = null;

    /** @var string|null Last response body, line passwords masked, for the module log. */
    public $lastResponse = null;

    /**
     * @param string $host Host name or IP of the panel API (no scheme, no path)
     * @param mixed $port Port, empty or 0 for the default of the scheme
     * @param bool $use_ssl True for https
     * @param string $api_key Reseller API key
     */
    public function __construct($host, $port, $use_ssl, $api_key)
    {
        $host = trim((string) $host);
        $port = (int) $port;
        $secure = ($use_ssl === true || $use_ssl === 1 || $use_ssl === '1' || $use_ssl === 'true');

        if ($host === '' || trim((string) $api_key) === '' || !preg_match('/^[A-Za-z0-9.\-]+$|^\[[0-9A-Fa-f:.]+\]$/', $host)) {
            throw new XtreamproApiException('CONFIG');
        }
        if ($port < 0 || $port > 65535) {
            throw new XtreamproApiException('CONFIG');
        }

        $base = ($secure ? 'https' : 'http') . '://' . $host;
        if ($port > 0 && $port !== ($secure ? 443 : 80)) {
            $base .= ':' . $port;
        }
        $this->base = $base;
        $this->apiKey = trim((string) $api_key);
    }

    /** Panel base URL without trailing slash. */
    public function baseUrl()
    {
        return $this->base;
    }

    /** GET read action. Returns the decoded envelope. */
    public function get($action, array $query = [])
    {
        return $this->send('GET', array_merge(['action' => $action], $query));
    }

    /** POST mutation: `action` travels in the body, as the panel requires. */
    public function post($action, array $fields = [])
    {
        return $this->send('POST', array_merge(['action' => $action], $fields));
    }

    // ---- reseller -------------------------------------------------------

    public function userInfo()
    {
        return $this->dataOf($this->get('user_info'));
    }

    public function packages()
    {
        $data = $this->dataOf($this->get('packages'));

        return is_array($data) ? $data : [];
    }

    /**
     * Whether a package of `packages` / `pricing` can be sold as a plain IPTV line (`sells` contains "line").
     * A panel too old to send `sells` leaves the key out: the package then counts as sellable.
     */
    public static function sellsLine($package)
    {
        if (!is_array($package) || !array_key_exists('sells', $package) || !is_array($package['sells'])) {
            return true;
        }

        return in_array('line', $package['sells'], true);
    }

    /**
     * What the reseller can sell and afford (credits, packages[] with the reseller's prices, sub_reseller, lines).
     * Null when the panel is too old to know the action; the sale is then still decided by the panel.
     */
    public function pricing()
    {
        try {
            $data = $this->dataOf($this->get('pricing'));
        } catch (XtreamproApiException $e) {
            if ($e->getErrorCode() === 'UNKNOWN_ACTION') {
                return null;
            }
            throw $e;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * Fails before anything is sold when one official (or trial) period of the package is not on sale for this
     * reseller or costs more than the balance. $plainLine: the package is sold as a plain line (create, change
     * package); a renewal passes false.
     *
     * @throws XtreamproApiException INVALID_PACKAGE or INSUFFICIENT_CREDITS
     */
    public function assertCanSellPackage($packageId, $trial, $plainLine = true)
    {
        $pricing = $this->pricing();
        if ($pricing === null) {
            return;
        }
        $package = null;
        foreach (isset($pricing['packages']) && is_array($pricing['packages']) ? $pricing['packages'] : [] as $row) {
            if (is_array($row) && isset($row['id']) && (int) $row['id'] === (int) $packageId) {
                $package = $row;
                break;
            }
        }
        if ($package === null) {
            throw new XtreamproApiException('INVALID_PACKAGE', 400, 'Package #' . (int) $packageId . ' is not in the list of packages this reseller may sell.');
        }
        if ($plainLine && !self::sellsLine($package)) {
            throw new XtreamproApiException('INVALID_PACKAGE', 400, 'The package is for MAG / Enigma boxes only and cannot be sold as an IPTV line.');
        }
        if ($trial ? empty($package['is_trial']) : empty($package['is_official'])) {
            throw new XtreamproApiException('INVALID_PACKAGE', 400, 'The package is not offered as ' . ($trial ? 'a trial.' : 'an official period.'));
        }
        $cost = (int) ($trial ? ($package['trial_credits'] ?? 0) : ($package['official_credits'] ?? 0));
        $this->assertBalance($pricing, $cost);
    }

    /**
     * Fails before a sub-reseller account is created when the group may not create them or the balance does not
     * cover the account price plus the credits handed over at once.
     *
     * @throws XtreamproApiException FORBIDDEN or INSUFFICIENT_CREDITS
     */
    public function assertCanCreateSubUser($creditsOnCreate)
    {
        $pricing = $this->pricing();
        if ($pricing === null) {
            return;
        }
        $sub = (isset($pricing['sub_reseller']) && is_array($pricing['sub_reseller'])) ? $pricing['sub_reseller'] : [];
        if (empty($sub['can_create'])) {
            throw new XtreamproApiException('FORBIDDEN');
        }
        $this->assertBalance($pricing, (int) ($sub['price'] ?? 0) + (int) $creditsOnCreate);
    }

    /**
     * Fails when the balance cannot give $credits to a sub-reseller (a renewal top-up).
     *
     * @throws XtreamproApiException INSUFFICIENT_CREDITS
     */
    public function assertCanGiveCredits($credits)
    {
        $pricing = $this->pricing();
        if ($pricing !== null) {
            $this->assertBalance($pricing, (int) $credits);
        }
    }

    /** A balance of 0 sells nothing, not even a free package (the panel refuses it). */
    private function assertBalance(array $pricing, $cost)
    {
        $balance = (int) ($pricing['credits'] ?? 0);
        if ($balance <= 0 || $balance < $cost) {
            throw new XtreamproApiException('INSUFFICIENT_CREDITS', 402, sprintf('This needs %d credits, the reseller account has %d.', $cost, $balance));
        }
    }

    /**
     * What an upgrade / downgrade of a line to another package would do, asked without selling anything:
     * keeps_time_left, reason, time_left_seconds, time_lost_seconds, exp_date, new_exp_date, price, can_afford.
     * Null when the panel is too old to know the action.
     *
     * @throws XtreamproApiException INVALID_PACKAGE, RESOURCE_NOT_FOUND
     */
    public function packageCompatibility($lineId, $packageId)
    {
        try {
            $data = $this->dataOf($this->get('package_compatibility', ['id' => (int) $lineId, 'package_id' => (int) $packageId]));
        } catch (XtreamproApiException $e) {
            if ($e->getErrorCode() === 'UNKNOWN_ACTION') {
                return null;
            }
            throw $e;
        }

        return (is_array($data) && array_key_exists('keeps_time_left', $data)) ? $data : null;
    }

    /** One sentence for an admin: what the change does to the time left and what it costs. */
    public static function describeCompatibility(array $c)
    {
        $days = function ($seconds) {
            return (string) (int) round(max(0, (int) $seconds) / 86400);
        };
        if (!empty($c['keeps_time_left'])) {
            $time = (isset($c['time_left_seconds']) && $c['time_left_seconds'] !== null)
                ? 'the time left (about ' . $days($c['time_left_seconds']) . ' days) is kept and the new period is added to it'
                : 'the time left is kept and the new period is added to it';
        } else {
            $time = 'the new period starts today';
            if (isset($c['time_lost_seconds']) && (int) $c['time_lost_seconds'] > 0) {
                $time .= ' and the time left (about ' . $days($c['time_lost_seconds']) . ' days) is lost';
            }
        }

        return 'On the panel ' . $time . '; it costs ' . (int) ($c['price'] ?? 0) . ' credits.';
    }

    /**
     * Upgrade or downgrade: sells an official period of another package on the line and charges its price.
     * Returns the same data as renewLine(), including `links`.
     */
    public function changePackage($id, $packageId, $request_id)
    {
        return $this->dataOf($this->post('change_package', [
            'id' => (int) $id,
            'package_id' => (int) $packageId,
            'request_id' => (string) $request_id
        ]));
    }

    // ---- lines ----------------------------------------------------------

    public function getLine($id)
    {
        return $this->dataOf($this->get('get_line', ['id' => (int) $id]));
    }

    /** Exact username match (get_lines searches by substring). Line array or null. */
    public function findLineByUsername($username)
    {
        $rows = $this->dataOf($this->get('get_lines', ['search' => (string) $username, 'start' => 0, 'limit' => 500]));
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (is_array($row) && isset($row['username']) && (string) $row['username'] === (string) $username) {
                    return $row;
                }
            }
        }

        return null;
    }

    public function createLine($package_id, $trial, $username, $password, $request_id)
    {
        return $this->dataOf($this->post('create_line', [
            'package_id' => (int) $package_id,
            'trial' => $trial ? 1 : 0,
            'username' => (string) $username,
            'password' => (string) $password,
            'request_id' => (string) $request_id
        ]));
    }

    public function renewLine($id, $request_id)
    {
        return $this->dataOf($this->post('renew_line', ['id' => (int) $id, 'request_id' => (string) $request_id]));
    }

    /** $action: enable_line | disable_line | delete_line */
    public function lineAction($action, $id)
    {
        return $this->dataOf($this->post($action, ['id' => (int) $id]));
    }

    public function editLine($id, array $fields)
    {
        return $this->dataOf($this->post('edit_line', ['id' => (int) $id] + $fields));
    }

    // ---- sub-reseller accounts -------------------------------------------

    /** One account below the API key's reseller, or null when it is gone. */
    public function getUser($id)
    {
        try {
            $data = $this->dataOf($this->get('get_user', ['id' => (string) $id]));
        } catch (XtreamproApiException $e) {
            if ($e->getErrorCode() === 'RESOURCE_NOT_FOUND') {
                return null;
            }
            throw $e;
        }
        if (is_array($data) && isset($data['user']) && is_array($data['user'])) {
            $data = $data['user'];
        }

        return (is_array($data) && isset($data['id'])) ? $data : null;
    }

    /** Exact username match among the accounts below the reseller. Array or null. */
    public function findUserByUsername($username)
    {
        $rows = $this->dataOf($this->get('get_users', ['search' => (string) $username, 'start' => 0, 'limit' => 500]));
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (is_array($row) && isset($row['username']) && (string) $row['username'] === (string) $username) {
                    return $row;
                }
            }
        }

        return null;
    }

    public function createUser($username, $password, $email, $fullname, $request_id)
    {
        return $this->dataOf($this->post('create_user', [
            'username' => (string) $username,
            'password' => (string) $password,
            'email' => (string) $email,
            'fullname' => (string) $fullname,
            'request_id' => (string) $request_id
        ]));
    }

    /** credits > 0 gives credits to the account, < 0 takes them back. */
    public function adjustCredits($id, $credits, $note, $request_id)
    {
        return $this->dataOf($this->post('adjust_credits', [
            'id' => (string) $id,
            'credits' => (int) $credits,
            'note' => substr((string) $note, 0, 200),
            'request_id' => (string) $request_id
        ]));
    }

    /** $action: enable_user | disable_user | delete_user */
    public function userAction($action, $id)
    {
        return $this->dataOf($this->post($action, ['id' => (string) $id]));
    }

    public function editUser($id, array $fields)
    {
        return $this->dataOf($this->post('edit_user', ['id' => (string) $id] + $fields));
    }

    // ---- transport -------------------------------------------------------

    private function dataOf($envelope)
    {
        return isset($envelope['data']) ? $envelope['data'] : null;
    }

    private function send($method, array $params)
    {
        $url = $this->base . '/reseller/v1';
        $headers = [
            'Accept: application/json',
            'X-API-Key: ' . $this->apiKey,
            // Names this connector in the panel's API call log (never parameters, never the key).
            'X-Connector: blesta/' . self::VERSION
        ];

        $ch = curl_init();
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params, '', '&', PHP_QUERY_RFC3986));
        } else {
            $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Redirects could carry the API key somewhere else.
            CURLOPT_FOLLOWLOCATION => false
        ]);

        // The request as it is logged: no key, passwords masked.
        $logged = $params;
        foreach (['password'] as $secret) {
            if (isset($logged[$secret])) {
                $logged[$secret] = '********';
            }
        }
        $this->lastRequest = ['method' => $method, 'url' => $this->base . '/reseller/v1', 'params' => $logged];
        $this->lastResponse = null;

        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $failed = ($body === false);
        curl_close($ch);

        if ($failed) {
            $this->lastResponse = 'connection failed';
            throw new XtreamproApiException('CONNECTION_FAILED');
        }
        $this->lastResponse = $this->maskSecrets((string) $body);

        $json = json_decode((string) $body, true);
        if (!is_array($json) || json_last_error() !== JSON_ERROR_NONE || !isset($json['status'])) {
            throw new XtreamproApiException('BAD_RESPONSE', $http);
        }
        if ($json['status'] !== 'STATUS_SUCCESS') {
            $code = (isset($json['error']) && is_string($json['error']) && $json['error'] !== '')
                ? $json['error']
                : 'SERVER_ERROR';
            throw new XtreamproApiException($code, $http);
        }

        return $json;
    }

    /** Hide line passwords in the response copy kept for the module log. */
    private function maskSecrets($body)
    {
        $body = preg_replace('/("password"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/', '$1"********"', $body);

        // The play links of a line carry the password as a query parameter.
        return preg_replace('/(password=)[^&"\\\\]+/', '$1********', $body);
    }
}
