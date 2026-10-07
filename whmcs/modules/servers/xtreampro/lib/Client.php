<?php
/**
 * Xtream UI Pro - small Reseller API client (curl based, no dependencies).
 *
 * Talks to {base}/reseller/v1 with the reseller's API key in the X-API-Key
 * header. Reads are GET, mutations are POST with a form-urlencoded body.
 */

namespace XtreamPro\Whmcs;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Error raised for every failure: transport problem, bad response or an
 * API error code. getMessage() is always a readable English sentence; the
 * raw code (e.g. INSUFFICIENT_CREDITS) is in getErrorCode().
 */
class ApiException extends \Exception
{
    /** @var string */
    private $errorCode;

    /** @var int */
    private $httpStatus;

    /**
     * @param string $errorCode  API (or client side) error code
     * @param int    $httpStatus HTTP status of the answer, 0 when there was none
     * @param string $detail     Sentence appended to the readable text, e.g. the amounts of a refused sale
     */
    public function __construct($errorCode, $httpStatus = 0, $detail = '')
    {
        $this->errorCode = (string) $errorCode;
        $this->httpStatus = (int) $httpStatus;
        $text = self::describe($this->errorCode);
        parent::__construct($detail === '' ? $text : $text . ' ' . $detail);
    }

    public function getErrorCode()
    {
        return $this->errorCode;
    }

    public function getHttpStatus()
    {
        return $this->httpStatus;
    }

    /**
     * Readable text for an API (or client side) error code.
     */
    public static function describe($code)
    {
        $messages = array(
            'INVALID_API_KEY'      => 'The panel rejected the API key. Check the Password field of the server in WHMCS.',
            'FORBIDDEN'            => 'The API key does not belong to a reseller account, or the reseller is not allowed to do this (for sub-reseller accounts: the reseller\'s group may not create sub-resellers, or the hierarchy is too deep).',
            'RESOURCE_NOT_FOUND'   => 'The line or sub-reseller account was not found in the panel (it may have been deleted there).',
            'INVALID_REQUEST'      => 'The panel rejected the request, for example a username or password shorter than the reseller group allows, or an invalid email address.',
            'INVALID_PACKAGE'      => 'The selected package does not exist, is not available to this reseller, or cannot be sold this way (for example a trial-only package sold as an official period).',
            'INSUFFICIENT_CREDITS' => 'The reseller account has not enough credits.',
            'CONFLICT'             => 'The username (or, for sub-reseller accounts, the email address) is already taken in the panel, or the request id was already used for another operation.',
            'REQUEST_ID_SPENT'     => 'This sale was already made and its line has since been deleted on the panel, so the same request id cannot sell another line. Terminate the service and create it again.',
            'POST_REQUIRED'        => 'The panel refused the request because it was not sent as POST.',
            'READ_ONLY_KEY'        => 'The API key is read-only. Create a key that may change things on the panel\'s API key page.',
            'TOO_MANY_WEBHOOKS'    => 'The reseller account already has the most webhook endpoints the panel allows. Remove one on the panel\'s API key page.',
            'RATE_LIMITED'         => 'The panel is rate limiting this API key. Try again in a minute.',
            'UNKNOWN_ACTION'       => 'The panel does not know this API action. Is the panel up to date?',
            'SERVER_ERROR'         => 'The panel reported an internal error.',
            'CONNECTION_FAILED'    => 'Could not connect to the panel. Check hostname, port and the Secure setting of the server.',
            'BAD_RESPONSE'         => 'The panel answered with something that is not a valid API response. Check the server address.',
            'CONFIG'               => 'The server in WHMCS is not configured correctly (hostname or API key missing).',
        );

        if (isset($messages[$code])) {
            return $messages[$code];
        }
        return 'The panel returned an error: ' . $code;
    }
}

class Client
{
    /** Sent as "X-Connector: whmcs/<version>" on every call; keep in step with the @version of xtreampro.php. */
    const VERSION = '1.1.1';

    const CONNECT_TIMEOUT = 5;
    const TIMEOUT = 20;

    /** @var string */
    private $base;

    /** @var string */
    private $apiKey;

    /** @var array|null Last request (action + params, never the key) for the module log. */
    public $lastRequest = null;

    /** @var string|null Last raw response body for the module log. */
    public $lastResponse = null;

    /**
     * @param string $base   Panel base URL, e.g. https://api.example.com:8443 (no /reseller/v1)
     * @param string $apiKey Reseller API key
     */
    public function __construct($base, $apiKey)
    {
        $base = rtrim(trim((string) $base), '/');
        $parts = parse_url($base);
        if (
            $base === '' || trim((string) $apiKey) === '' || $parts === false
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), array('http', 'https'), true)
        ) {
            throw new ApiException('CONFIG');
        }
        $this->base = $base;
        $this->apiKey = trim((string) $apiKey);
    }

    /** Panel base URL without trailing slash. */
    public function baseUrl()
    {
        return $this->base;
    }

    /**
     * GET read action. Returns the decoded envelope (keys: status, data, ...).
     */
    public function get($action, array $query = array())
    {
        $query = array_merge(array('action' => $action), $query);
        return $this->send('GET', $query);
    }

    /**
     * POST mutation. `action` is sent in the body (the panel requires it there).
     */
    public function post($action, array $fields = array())
    {
        $fields = array_merge(array('action' => $action), $fields);
        return $this->send('POST', $fields);
    }

    // ---- convenience wrappers ------------------------------------------

    public function userInfo()
    {
        return $this->dataOf($this->get('user_info'));
    }

    public function packages()
    {
        $data = $this->dataOf($this->get('packages'));
        return is_array($data) ? $data : array();
    }

    /**
     * Whether a package of `packages` / `pricing` can be sold as a plain IPTV line. The panel lists what a
     * package can be sold as in `sells` (a subset of line, mag, enigma); a panel too old to send it leaves
     * the key out, and the package then counts as sellable (the sale itself is still checked by the panel).
     */
    public static function sellsLine($package)
    {
        if (!is_array($package) || !array_key_exists('sells', $package) || !is_array($package['sells'])) {
            return true;
        }
        return in_array('line', $package['sells'], true);
    }

    public function getLine($id)
    {
        return $this->dataOf($this->get('get_line', array('id' => (int) $id)));
    }

    /**
     * Find a line by exact username (get_lines search is a substring match).
     * Returns the line array or null.
     */
    public function findLineByUsername($username)
    {
        $envelope = $this->get('get_lines', array('search' => $username, 'start' => 0, 'limit' => 500));
        $rows = $this->dataOf($envelope);
        if (!is_array($rows)) {
            return null;
        }
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['username']) && (string) $row['username'] === (string) $username) {
                return $row;
            }
        }
        return null;
    }

    public function createLine($packageId, $trial, $username, $password, $requestId)
    {
        return $this->dataOf($this->post('create_line', array(
            'package_id' => (int) $packageId,
            'trial'      => $trial ? 1 : 0,
            'username'   => (string) $username,
            'password'   => (string) $password,
            'request_id' => (string) $requestId,
        )));
    }

    public function renewLine($id, $requestId)
    {
        return $this->dataOf($this->post('renew_line', array(
            'id'         => (int) $id,
            'request_id' => (string) $requestId,
        )));
    }

    /**
     * Upgrade or downgrade: sells an official period of another package on the
     * line and charges its price (the panel's "change_package"). Returns the
     * same data as renewLine(), including `links`.
     */
    public function changePackage($id, $packageId, $requestId)
    {
        return $this->dataOf($this->post('change_package', array(
            'id'         => (int) $id,
            'package_id' => (int) $packageId,
            'request_id' => (string) $requestId,
        )));
    }

    /**
     * What an upgrade / downgrade of a line to another package would do, asked without selling anything
     * (the panel's "package_compatibility"): keeps_time_left, reason, time_left_seconds, time_lost_seconds,
     * exp_date, new_exp_date, price, can_afford. Null when the panel is too old to know the action; the
     * change itself is then still decided by the panel when it is sold.
     *
     * @throws ApiException INVALID_PACKAGE when the package cannot be sold on that line, RESOURCE_NOT_FOUND
     */
    public function packageCompatibility($lineId, $packageId)
    {
        try {
            $data = $this->dataOf($this->get('package_compatibility', array('id' => (int) $lineId, 'package_id' => (int) $packageId)));
        } catch (ApiException $e) {
            if ($e->getErrorCode() === 'UNKNOWN_ACTION') {
                return null;
            }
            throw $e;
        }
        return is_array($data) && array_key_exists('keeps_time_left', $data) ? $data : null;
    }

    /** One sentence for an admin: what the change does to the time left and what it costs. */
    public static function describeCompatibility(array $c)
    {
        $days = function ($seconds) {
            return (string) (int) round(max(0, (int) $seconds) / 86400);
        };
        if (!empty($c['keeps_time_left'])) {
            $time = isset($c['time_left_seconds']) && $c['time_left_seconds'] !== null
                ? 'the time left (about ' . $days($c['time_left_seconds']) . ' days) is kept and the new period is added to it'
                : 'the time left is kept and the new period is added to it';
        } else {
            $time = 'the new period starts today';
            if (isset($c['time_lost_seconds']) && (int) $c['time_lost_seconds'] > 0) {
                $time .= ' and the time left (about ' . $days($c['time_lost_seconds']) . ' days) is lost';
            }
        }
        return 'On the panel ' . $time . '; it costs ' . (isset($c['price']) ? (int) $c['price'] : 0) . ' credits.';
    }

    /**
     * What the reseller can sell and afford: credits, packages[] (with the
     * reseller's price), sub_reseller (can_create, price, groups, can_delete)
     * and the line rules. Null when the panel is too old to know the action;
     * the sale itself is then still refused by the panel if credits are short.
     */
    public function pricing()
    {
        try {
            $data = $this->dataOf($this->get('pricing'));
        } catch (ApiException $e) {
            if ($e->getErrorCode() === 'UNKNOWN_ACTION') {
                return null;
            }
            throw $e;
        }
        return is_array($data) ? $data : null;
    }

    /**
     * Fail before anything is sold when one official (or trial) period of the
     * package is not on sale for this reseller or costs more than the balance.
     * The pricing answer is only a look: the panel charges again, atomically,
     * when the line is created, so a sale racing another one can still be refused.
     *
     * $plainLine: the package is sold as a plain line (create, change package): a package the panel
     * says is for MAG / Enigma boxes only (`sells` without "line") is refused here. A renewal of an
     * existing line passes false.
     *
     * @throws ApiException INVALID_PACKAGE or INSUFFICIENT_CREDITS
     */
    public function assertCanSellPackage($packageId, $trial, $plainLine = true)
    {
        $pricing = $this->pricing();
        if ($pricing === null) {
            return;
        }
        $package = null;
        foreach (isset($pricing['packages']) && is_array($pricing['packages']) ? $pricing['packages'] : array() as $row) {
            if (is_array($row) && isset($row['id']) && (int) $row['id'] === (int) $packageId) {
                $package = $row;
                break;
            }
        }
        if ($package === null) {
            throw new ApiException('INVALID_PACKAGE', 400, 'Package #' . (int) $packageId . ' is not in the list of packages this reseller may sell.');
        }
        if ($plainLine && !self::sellsLine($package)) {
            throw new ApiException('INVALID_PACKAGE', 400, 'The package is for MAG / Enigma boxes only and cannot be sold as an IPTV line.');
        }
        if ($trial ? empty($package['is_trial']) : empty($package['is_official'])) {
            throw new ApiException('INVALID_PACKAGE', 400, 'The package is not offered as ' . ($trial ? 'a trial.' : 'an official period.'));
        }
        $cost = (int) ($trial ? (isset($package['trial_credits']) ? $package['trial_credits'] : 0) : (isset($package['official_credits']) ? $package['official_credits'] : 0));
        $this->assertBalance($pricing, $cost);
    }

    /**
     * Fail before an account is created when the reseller's group may not
     * create sub-resellers, the chosen group is not allowed, or the balance
     * does not cover the account price plus the credits handed over at once.
     *
     * @throws ApiException FORBIDDEN, INVALID_REQUEST or INSUFFICIENT_CREDITS
     */
    public function assertCanCreateSubUser($groupId, $creditsOnCreate)
    {
        $pricing = $this->pricing();
        if ($pricing === null) {
            return;
        }
        $sub = isset($pricing['sub_reseller']) && is_array($pricing['sub_reseller']) ? $pricing['sub_reseller'] : array();
        if (empty($sub['can_create'])) {
            throw new ApiException('FORBIDDEN');
        }
        if ((int) $groupId > 0) {
            $found = false;
            foreach (isset($sub['groups']) && is_array($sub['groups']) ? $sub['groups'] : array() as $group) {
                if (is_array($group) && isset($group['id']) && (int) $group['id'] === (int) $groupId) {
                    $found = true;
                }
            }
            if (!$found) {
                throw new ApiException('INVALID_REQUEST', 400, 'The sub-reseller group chosen in the product (#' . (int) $groupId . ') is not one this reseller may use.');
            }
        }
        $this->assertBalance($pricing, (isset($sub['price']) ? (int) $sub['price'] : 0) + (int) $creditsOnCreate);
    }

    /**
     * Fail when the balance cannot give $credits to a sub-reseller (a renewal top-up).
     *
     * @throws ApiException INSUFFICIENT_CREDITS
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
        $balance = isset($pricing['credits']) ? (int) $pricing['credits'] : 0;
        if ($balance <= 0 || $balance < $cost) {
            throw new ApiException('INSUFFICIENT_CREDITS', 402, sprintf('This needs %d credits, the reseller account has %d.', $cost, $balance));
        }
    }

    /** $action: enable_line | disable_line | delete_line (deleting is final on the panel) */
    public function lineAction($action, $id)
    {
        return $this->dataOf($this->post($action, array('id' => (int) $id)));
    }

    // ---- sub-reseller accounts -------------------------------------------

    /** Accounts below the API key's reseller (all levels). Returns the data array. */
    public function subUsers($search = '', $start = 0, $limit = 500)
    {
        $data = $this->dataOf($this->get('get_users', array(
            'search' => (string) $search,
            'start'  => (int) $start,
            'limit'  => max(1, min(500, (int) $limit)),
        )));
        return is_array($data) ? $data : array();
    }

    /**
     * Read one sub-reseller (there is no get-one action): search by username,
     * match the UUID, fall back to the exact username. Returns the array or null.
     */
    public function findSubUser($id, $username = '')
    {
        $rows = $this->subUsers((string) $username, 0, 500);
        $byName = null;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($id !== null && $id !== '' && isset($row['id']) && (string) $row['id'] === (string) $id) {
                return $row;
            }
            if ($byName === null && $username !== '' && isset($row['username']) && (string) $row['username'] === (string) $username) {
                $byName = $row;
            }
        }
        return $byName;
    }

    /** $groupId 0 = the first group the caller's group allows. */
    public function createSubUser($username, $password, $email, $fullname, $requestId, $groupId = 0)
    {
        $fields = array(
            'username'   => (string) $username,
            'password'   => (string) $password,
            'email'      => (string) $email,
            'fullname'   => (string) $fullname,
            'request_id' => (string) $requestId,
        );
        if ((int) $groupId > 0) {
            $fields['group_id'] = (int) $groupId;
        }
        return $this->dataOf($this->post('create_user', $fields));
    }

    /** credits > 0 gives credits to the account, < 0 takes them back. */
    public function adjustCredits($id, $credits, $note, $requestId)
    {
        return $this->dataOf($this->post('adjust_credits', array(
            'id'         => (string) $id,
            'credits'    => (int) $credits,
            'note'       => substr((string) $note, 0, 200),
            'request_id' => (string) $requestId,
        )));
    }

    /** $action: enable_user | disable_user | delete_user (deleting is final: credits, lines and sub-accounts go to the reseller) */
    public function subUserAction($action, $id)
    {
        return $this->dataOf($this->post($action, array('id' => (string) $id)));
    }

    // ---- webhooks pushed by the panel -----------------------------------------

    /**
     * Registers an https address of this WHMCS; the panel answers with the id
     * and, this once, the secret that signs every delivery (data.secret).
     * $includeSubResellers also sends the events of lines owned by accounts below the reseller
     * (the panel's default, and this method's, is off).
     */
    public function createWebhook($url, $events, $includeSubResellers = false)
    {
        $fields = array('url' => (string) $url, 'events' => (string) $events);
        if ($includeSubResellers) {
            $fields['include_sub_resellers'] = 1;
        }
        return $this->dataOf($this->post('create_webhook', $fields));
    }

    public function deleteWebhook($id)
    {
        return $this->dataOf($this->post('delete_webhook', array('id' => (string) $id)));
    }

    /** Sends a ping event to the endpoint (and switches it back on if failures had). */
    public function testWebhook($id)
    {
        return $this->dataOf($this->post('test_webhook', array('id' => (string) $id)));
    }

    /**
     * Play links of a get_line / create_line / renew_line / change_package
     * answer: server, m3u, m3u_hls, xmltv, player_api, web_player. Null when the
     * panel sent none (an older panel, or a line whose password is stored hashed).
     */
    public static function linksOf($data)
    {
        if (is_array($data) && isset($data['links']) && is_array($data['links'])) {
            return $data['links'];
        }
        return null;
    }

    // ---- transport -------------------------------------------------------

    private function dataOf($envelope)
    {
        return isset($envelope['data']) ? $envelope['data'] : null;
    }

    private function send($method, array $params)
    {
        $url = $this->base . '/reseller/v1';
        $headers = array(
            'Accept: application/json',
            'X-API-Key: ' . $this->apiKey,
            // Names this connector in the panel's API call log (never parameters, never the key).
            'X-Connector: whmcs/' . self::VERSION,
        );

        $ch = curl_init();
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params, '', '&', PHP_QUERY_RFC3986));
        } else {
            $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }

        curl_setopt_array($ch, array(
            CURLOPT_URL            => $url,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Never follow redirects: they could carry the API key elsewhere.
            CURLOPT_FOLLOWLOCATION => false,
        ));

        // Request as it is logged: no key, line passwords masked.
        $logged = $params;
        if (isset($logged['password'])) {
            $logged['password'] = '********';
        }
        $this->lastRequest = array('method' => $method, 'url' => $this->base . '/reseller/v1', 'params' => $logged);
        $this->lastResponse = null;

        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_errno($ch) !== 0 ? curl_error($ch) : '';
        curl_close($ch);

        if ($body === false) {
            $this->lastResponse = 'curl error: ' . $curlError;
            throw new ApiException('CONNECTION_FAILED');
        }
        $this->lastResponse = $this->maskSecrets((string) $body);

        $json = json_decode((string) $body, true);
        if (!is_array($json) || json_last_error() !== JSON_ERROR_NONE || !isset($json['status'])) {
            throw new ApiException('BAD_RESPONSE', $http);
        }
        if ($json['status'] !== 'STATUS_SUCCESS') {
            $code = isset($json['error']) && is_string($json['error']) && $json['error'] !== ''
                ? $json['error'] : 'SERVER_ERROR';
            throw new ApiException($code, $http);
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
