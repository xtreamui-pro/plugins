<?php
/**
 * Xtream UI Pro - Reseller API client (curl based, no dependencies).
 *
 * PLATFORM INDEPENDENT: this file does not use any PrestaShop class, so it can
 * be run from the command line (see plugins/e2e/prestashop-harness.php).
 *
 * Talks to {base}/reseller/v1 with the reseller's API key in the X-API-Key
 * header. Reads are GET, mutations are POST with a form-urlencoded body.
 *
 * Written for PHP 7.1 and newer (PrestaShop 1.7.7 / 8.x).
 */

/**
 * Error raised for every failure: transport problem, bad response or an API
 * error code. getMessage() is always a readable English sentence; the raw code
 * (for example INSUFFICIENT_CREDITS) is in getErrorCode().
 */
class XtreamproApiException extends Exception
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
            'INVALID_API_KEY'      => 'The panel rejected the API key. Check the API key in the module settings.',
            'FORBIDDEN'            => 'The API key does not belong to a reseller account, or the reseller is not allowed to do this (for sub-reseller accounts: the reseller\'s group may not create sub-resellers, or the hierarchy is too deep).',
            'RESOURCE_NOT_FOUND'   => 'The line or sub-reseller account was not found in the panel (it may have been deleted there).',
            'INVALID_REQUEST'      => 'The panel rejected the request, for example a username or password shorter than the reseller group allows, or an invalid email address.',
            'INVALID_PACKAGE'      => 'The selected package does not exist, is not available to this reseller, or cannot be sold this way (for example a package for MAG / Enigma boxes only sold as a line).',
            'INSUFFICIENT_CREDITS' => 'The reseller account has not enough credits.',
            'CONFLICT'             => 'The username (or, for sub-reseller accounts, the email address) is already taken in the panel, or the request id was already used for another operation.',
            'REQUEST_ID_SPENT'     => 'This sale was already made and its line has since been deleted on the panel, so the same request id cannot sell another line. Cancel the order and place a new one.',
            'READ_ONLY_KEY'        => 'The API key is read-only. Create a key that may change things on the panel\'s API key page.',
            'POST_REQUIRED'        => 'The panel refused the request because it was not sent as POST.',
            'RATE_LIMITED'         => 'The panel is rate limiting this API key. Try again in a minute.',
            'UNKNOWN_ACTION'       => 'The panel does not know this API action. Is the panel up to date?',
            'SERVER_ERROR'         => 'The panel reported an internal error.',
            'REDIRECT'             => 'The API address redirects somewhere else. Use the final address (usually https://) as API URL.',
            'CONNECTION_FAILED'    => 'Could not connect to the panel. Check the API URL, the port and the TLS certificate.',
            'BAD_RESPONSE'         => 'The panel answered with something that is not a valid API response. Check the API URL.',
            'CONFIG'               => 'The API URL or the API key is missing or invalid in the module settings.',
        );

        if (isset($messages[$code])) {
            return $messages[$code];
        }
        return 'The panel returned an error: ' . $code;
    }
}

class XtreamproApiClient
{
    /** Sent as "X-Connector: prestashop/<version>" on every call; keep in step with the version of the connector. */
    const VERSION = '1.1.0';

    const CONNECT_TIMEOUT = 5;
    const TIMEOUT = 20;

    /** @var string */
    private $base;

    /** @var string */
    private $apiKey;

    /** @var callable|null function ($message) called for every request, secrets already masked */
    private $logger;

    /**
     * @param string        $base     Panel API base URL, e.g. https://api.example.com:8443 (without /reseller/v1)
     * @param string        $apiKey   Reseller API key
     * @param callable|null $logger   Receives one masked line per API call
     */
    public function __construct($base, $apiKey, $logger = null)
    {
        $base = rtrim(trim((string) $base), '/');
        $parts = parse_url($base);
        if (
            $base === '' || trim((string) $apiKey) === '' || $parts === false
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), array('http', 'https'), true)
        ) {
            throw new XtreamproApiException('CONFIG');
        }
        $this->base = $base;
        $this->apiKey = trim((string) $apiKey);
        $this->logger = $logger;
    }

    /** Panel base URL without trailing slash. */
    public function baseUrl()
    {
        return $this->base;
    }

    /**
     * Hide line passwords: in JSON ("password":"..."), and inside the play
     * links of a line, which carry the password as a query parameter.
     */
    public static function maskSecrets($text)
    {
        $text = preg_replace('/("password"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/', '$1"********"', (string) $text);
        $text = preg_replace('/(password(?:=|%3D))[^&"\\\\\s]+/i', '$1********', $text);
        return $text;
    }

    // ---- reads --------------------------------------------------------------

    /** The reseller the key belongs to: id, username, credits, ... */
    public function userInfo()
    {
        return $this->dataOf($this->send('GET', array('action' => 'user_info')));
    }

    /** Packages the reseller may sell. Returns a list of arrays. */
    public function packages()
    {
        $data = $this->dataOf($this->send('GET', array('action' => 'packages')));
        return is_array($data) ? $data : array();
    }

    /** One line with its play links (data.links). */
    public function getLine($id)
    {
        return $this->dataOf($this->send('GET', array('action' => 'get_line', 'id' => (int) $id)));
    }

    /** One sub-reseller account below the reseller. */
    public function getSubUser($id)
    {
        return $this->dataOf($this->send('GET', array('action' => 'get_user', 'id' => (string) $id)));
    }

    // ---- lines --------------------------------------------------------------

    /**
     * Blank username / password are generated by the panel. The reseller's group
     * may also ignore custom credentials, so always read the final ones back.
     */
    public function createLine($packageId, $trial, $username, $password, $requestId)
    {
        $fields = array(
            'package_id' => (int) $packageId,
            'trial'      => $trial ? 1 : 0,
            'request_id' => (string) $requestId,
        );
        if ((string) $username !== '') {
            $fields['username'] = (string) $username;
        }
        if ((string) $password !== '') {
            $fields['password'] = (string) $password;
        }
        return $this->dataOf($this->send('POST', array_merge(array('action' => 'create_line'), $fields)));
    }

    public function renewLine($id, $requestId)
    {
        return $this->dataOf($this->send('POST', array(
            'action'     => 'renew_line',
            'id'         => (int) $id,
            'request_id' => (string) $requestId,
        )));
    }

    /** $action: enable_line | disable_line | delete_line (delete is final) */
    public function lineAction($action, $id)
    {
        return $this->dataOf($this->send('POST', array('action' => $action, 'id' => (int) $id)));
    }

    // ---- sub-reseller accounts ----------------------------------------------

    /** With a request id both username and password MUST be sent. */
    public function createSubUser($username, $password, $email, $fullname, $requestId)
    {
        return $this->dataOf($this->send('POST', array(
            'action'     => 'create_user',
            'username'   => (string) $username,
            'password'   => (string) $password,
            'email'      => (string) $email,
            'fullname'   => (string) $fullname,
            'request_id' => (string) $requestId,
        )));
    }

    /** credits > 0 gives credits to the account, < 0 takes them back. */
    public function adjustCredits($id, $credits, $note, $requestId)
    {
        return $this->dataOf($this->send('POST', array(
            'action'     => 'adjust_credits',
            'id'         => (string) $id,
            'credits'    => (int) $credits,
            'note'       => substr((string) $note, 0, 200),
            'request_id' => (string) $requestId,
        )));
    }

    /** $action: enable_user | disable_user */
    public function subUserAction($action, $id)
    {
        return $this->dataOf($this->send('POST', array('action' => $action, 'id' => (string) $id)));
    }

    /** GET read action. Returns the decoded envelope. */
    private function get($action, array $query = array())
    {
        return $this->send('GET', array_merge(array('action' => $action), $query));
    }

    /** POST mutation: `action` travels in the body, as the panel requires. */
    private function post($action, array $fields = array())
    {
        return $this->send('POST', array_merge(array('action' => $action), $fields));
    }

    // ---- capabilities of the current Reseller API (sells, pricing, package_compatibility, change_package, links) -----

    /**
     * Whether a package of `packages` / `pricing` can be sold as a plain IPTV line. The panel lists what a package
     * can be sold as in `sells` (a subset of line, mag, enigma); a panel too old to send it leaves the key out, and
     * the package then counts as sellable (the sale itself is still checked by the panel).
     */
    public static function sellsLine($package)
    {
        if (!is_array($package) || !array_key_exists('sells', $package) || !is_array($package['sells'])) {
            return true;
        }
        return in_array('line', $package['sells'], true);
    }

    /**
     * What the reseller can sell and afford: credits, packages[] (with the reseller's price), sub_reseller and the
     * line rules. Null when the panel is too old to know the action; the sale is then still decided by the panel.
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
     * Fail before anything is created when one official (or trial) period of the package is not on sale for this
     * reseller or costs more than the balance. The answer is only a look: the panel charges again, atomically,
     * when the line is created. $plainLine: the package is sold as a plain line (create, change package); a
     * renewal of an existing line passes false.
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
        foreach (isset($pricing['packages']) && is_array($pricing['packages']) ? $pricing['packages'] : array() as $row) {
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
        $cost = (int) ($trial ? (isset($package['trial_credits']) ? $package['trial_credits'] : 0) : (isset($package['official_credits']) ? $package['official_credits'] : 0));
        $this->assertBalance($pricing, $cost);
    }

    /**
     * Fail before an account is created when the reseller's group may not create sub-resellers or the balance does
     * not cover the account price plus the credits handed over at once.
     *
     * @throws XtreamproApiException FORBIDDEN or INSUFFICIENT_CREDITS
     */
    public function assertCanCreateSubUser($creditsOnCreate)
    {
        $pricing = $this->pricing();
        if ($pricing === null) {
            return;
        }
        $sub = isset($pricing['sub_reseller']) && is_array($pricing['sub_reseller']) ? $pricing['sub_reseller'] : array();
        if (empty($sub['can_create'])) {
            throw new XtreamproApiException('FORBIDDEN');
        }
        $this->assertBalance($pricing, (isset($sub['price']) ? (int) $sub['price'] : 0) + (int) $creditsOnCreate);
    }

    /**
     * Fail when the balance cannot give $credits to a sub-reseller (a renewal top-up).
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
        $balance = isset($pricing['credits']) ? (int) $pricing['credits'] : 0;
        if ($balance <= 0 || $balance < $cost) {
            throw new XtreamproApiException('INSUFFICIENT_CREDITS', 402, sprintf('This needs %d credits, the reseller account has %d.', $cost, $balance));
        }
    }

    /**
     * Play links of a get_line / create_line / renew_line / change_package answer: server, m3u, m3u_hls, xmltv,
     * player_api, web_player. Null when the panel sent none (an older panel, or a line whose password is stored
     * hashed); callers then fall back to assembling a link themselves.
     */
    public static function linksOf($data)
    {
        if (is_array($data) && isset($data['links']) && is_array($data['links'])) {
            return $data['links'];
        }
        return null;
    }

    // ---- transport ------------------------------------------------------------

    private function dataOf($envelope)
    {
        return isset($envelope['data']) ? $envelope['data'] : null;
    }

    private function log($message)
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, self::maskSecrets($message));
        }
    }

    /**
     * Send one request. The action is part of $params. Returns the decoded
     * envelope, throws XtreamproApiException on any failure.
     */
    private function send($method, array $params)
    {
        $url = $this->base . '/reseller/v1';
        $headers = array(
            'Accept: application/json',
            'X-API-Key: ' . $this->apiKey,
            // Names this connector in the panel's API call log (never parameters, never the key).
            'X-Connector: prestashop/' . self::VERSION,
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

        // What is logged: the action and its parameters, never the key.
        $logged = $params;
        if (isset($logged['password'])) {
            $logged['password'] = '********';
        }
        $action = isset($params['action']) ? $params['action'] : '?';
        unset($logged['action']);
        $summary = $method . ' ' . $action . ' ' . http_build_query($logged, '', '&');

        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $failed = $body === false;
        curl_close($ch);

        if ($failed) {
            $this->log($summary . ' -> connection failed');
            throw new XtreamproApiException('CONNECTION_FAILED');
        }
        if ($http >= 300 && $http < 400) {
            $this->log($summary . ' -> HTTP ' . $http . ' (redirect refused)');
            throw new XtreamproApiException('REDIRECT', $http);
        }

        $json = json_decode((string) $body, true);
        if (!is_array($json) || json_last_error() !== JSON_ERROR_NONE || !isset($json['status'])) {
            $this->log($summary . ' -> HTTP ' . $http . ' (not an API response)');
            throw new XtreamproApiException('BAD_RESPONSE', $http);
        }
        if ($json['status'] !== 'STATUS_SUCCESS') {
            $code = isset($json['error']) && is_string($json['error']) && $json['error'] !== ''
                ? $json['error'] : 'SERVER_ERROR';
            $this->log($summary . ' -> ' . $code);
            throw new XtreamproApiException($code, $http);
        }
        $this->log($summary . ' -> ok ' . self::maskSecrets((string) $body));
        return $json;
    }
}
