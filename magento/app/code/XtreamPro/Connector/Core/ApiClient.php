<?php
/**
 * Xtream UI Pro connector for Magento 2 - Reseller API client (plain curl).
 *
 * Talks to {base}/reseller/v1 with the reseller's API key in the X-API-Key
 * header. Reads are GET, mutations are POST with a form-urlencoded body.
 *
 * Security rules kept here: TLS verification on, 5 s connect / 20 s total
 * timeout, redirects are never followed, only http(s) is allowed, the API key
 * is never logged and line passwords are masked in everything that is logged.
 *
 * @version 1.1.0
 */

namespace XtreamPro\Connector\Core;

class ApiClient
{
    /** Sent as "X-Connector: magento/<version>" on every call; keep in step with composer.json. */
    const VERSION = '1.1.0';

    const CONNECT_TIMEOUT = 5;
    const TIMEOUT = 20;

    /** @var string */
    private $base;

    /** @var string */
    private $apiKey;

    /** @var callable|null function (string $level, string $message, array $context) */
    private $logger;

    /**
     * @param string        $base    Panel base URL, e.g. https://api.example.com:8443 (no /reseller/v1).
     * @param string        $apiKey  Reseller API key.
     * @param callable|null $logger  Receives one entry per API call. Never gets the key or a clear password.
     * @throws ApiException CONFIG when the URL or the key is missing or not usable.
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
            throw new ApiException('CONFIG');
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

    // ---- reads -----------------------------------------------------------

    public function userInfo()
    {
        return $this->dataOf($this->send('GET', array('action' => 'user_info')));
    }

    /** @return array[] Packages the reseller may sell. */
    public function packages()
    {
        $data = $this->dataOf($this->send('GET', array('action' => 'packages')));
        return is_array($data) ? $data : array();
    }

    public function getLine($id)
    {
        return $this->dataOf($this->send('GET', array('action' => 'get_line', 'id' => (int) $id)));
    }

    /** One sub-reseller account below the API key's reseller. The id is a UUID string. */
    public function getUser($id)
    {
        return $this->dataOf($this->send('GET', array('action' => 'get_user', 'id' => (string) $id)));
    }

    // ---- line mutations --------------------------------------------------

    /**
     * @param int    $packageId
     * @param bool   $trial
     * @param string $username   Empty = the panel generates one.
     * @param string $password   Empty = the panel generates one.
     * @param string $requestId  Idempotency key (max 64 characters).
     */
    public function createLine($packageId, $trial, $username, $password, $requestId)
    {
        $fields = array(
            'action'     => 'create_line',
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
        return $this->dataOf($this->send('POST', $fields));
    }

    public function renewLine($id, $requestId)
    {
        return $this->dataOf($this->send('POST', array(
            'action'     => 'renew_line',
            'id'         => (int) $id,
            'request_id' => (string) $requestId,
        )));
    }

    /** @param string $action enable_line | disable_line | delete_line (delete cannot be undone) */
    public function lineAction($action, $id)
    {
        return $this->dataOf($this->send('POST', array('action' => $action, 'id' => (int) $id)));
    }

    // ---- sub-reseller mutations ------------------------------------------

    /** With a request id the panel needs both username and password. */
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

    /** @param string $action enable_user | disable_user */
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
        } catch (ApiException $e) {
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
     * Fail before an account is created when the reseller's group may not create sub-resellers or the balance does
     * not cover the account price plus the credits handed over at once.
     *
     * @throws ApiException FORBIDDEN or INSUFFICIENT_CREDITS
     */
    public function assertCanCreateSubUser($creditsOnCreate)
    {
        $pricing = $this->pricing();
        if ($pricing === null) {
            return;
        }
        $sub = isset($pricing['sub_reseller']) && is_array($pricing['sub_reseller']) ? $pricing['sub_reseller'] : array();
        if (empty($sub['can_create'])) {
            throw new ApiException('FORBIDDEN');
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

    // ---- transport -------------------------------------------------------

    private function dataOf($envelope)
    {
        return isset($envelope['data']) ? $envelope['data'] : null;
    }

    /**
     * One API call. $params carries `action`; for GET they go in the query,
     * for POST in the form body (the panel wants the action there).
     */
    private function send($method, array $params)
    {
        $url = $this->base . '/reseller/v1';
        $headers = array(
            'Accept: application/json',
            'X-API-Key: ' . $this->apiKey,
            // Names this connector in the panel's API call log (never parameters, never the key).
            'X-Connector: magento/' . self::VERSION,
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
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ));

        // The request as it is logged: no key, line passwords masked.
        $logged = $params;
        if (isset($logged['password'])) {
            $logged['password'] = '********';
        }

        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_errno($ch) !== 0 ? curl_error($ch) : '';
        curl_close($ch);

        if ($body === false) {
            $this->log('error', 'Reseller API call failed', array('request' => $logged, 'curl_error' => $curlError));
            throw new ApiException('CONNECTION_FAILED');
        }
        $masked = $this->maskSecrets((string) $body);
        $context = array('request' => $logged, 'http' => $http, 'response' => substr($masked, 0, 1000));

        if ($http >= 300 && $http < 400) {
            $this->log('error', 'Reseller API call redirected', $context);
            throw new ApiException('REDIRECT', $http);
        }
        $json = json_decode((string) $body, true);
        if (!is_array($json) || json_last_error() !== JSON_ERROR_NONE || !isset($json['status'])) {
            $this->log('error', 'Reseller API answered with something that is not an API response', $context);
            throw new ApiException('BAD_RESPONSE', $http);
        }
        if ($json['status'] !== 'STATUS_SUCCESS') {
            $code = isset($json['error']) && is_string($json['error']) && $json['error'] !== ''
                ? $json['error'] : 'SERVER_ERROR';
            $this->log('warning', 'Reseller API call refused: ' . $code, $context);
            throw new ApiException($code, $http);
        }
        $this->log('info', 'Reseller API call ' . (isset($params['action']) ? $params['action'] : ''), $context);
        return $json;
    }

    /** Hide line passwords in a response copy that is going to a log. */
    private function maskSecrets($body)
    {
        $body = preg_replace('/("password"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/', '$1"********"', $body);
        // The play links of a line carry the password as a query parameter.
        return preg_replace('/(password=)[^&"\\\\]+/', '$1********', $body);
    }

    private function log($level, $message, array $context)
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, $level, 'Xtream UI Pro: ' . $message, $context);
        }
    }
}
