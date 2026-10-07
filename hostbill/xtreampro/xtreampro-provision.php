#!/usr/bin/env php
<?php
/**
 * Xtream UI Pro - provisioning script for HostBill (and any other system that
 * can run a command on billing events).
 *
 *   php xtreampro-provision.php <create|suspend|unsuspend|terminate|renew|change-package|info> --service=<id> [options]
 *
 * One service is one IPTV line (or, with --type=reseller, one sub-reseller
 * account) created through the panel's Reseller API. Create / renew are charged
 * to the reseller's credits in the panel.
 *
 * Prints one line of JSON on stdout and exits 0 (ok) or 1 (failed).
 *
 * @version 1.1.0
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

const XTREAMPRO_VERSION = '1.1.0';

ini_set('display_errors', 'stderr');
ini_set('zend.exception_ignore_args', '1');
error_reporting(E_ALL);

// ---------------------------------------------------------------------------
// API client (curl, no dependencies)
// ---------------------------------------------------------------------------

/**
 * Every failure: transport problem, bad response or an API error code.
 * getMessage() is a readable English sentence; the raw code is in getErrorCode().
 */
class XtreamProException extends Exception
{
    /** @var string */
    private $errorCode;

    /**
     * @param string $errorCode API (or client side) error code
     * @param int    $httpStatus unused here (kept for the shape of the other connectors)
     * @param string $detail    Sentence appended to the readable text, e.g. the amounts of a refused sale
     */
    public function __construct($errorCode, $httpStatus = 0, $detail = '')
    {
        $this->errorCode = (string) $errorCode;
        $text = self::describe($this->errorCode);
        parent::__construct($detail === '' ? $text : $text . ' ' . $detail);
    }

    public function getErrorCode()
    {
        return $this->errorCode;
    }

    public static function describe($code)
    {
        $messages = array(
            'INVALID_API_KEY'      => 'The panel rejected the API key. Check api_key in config.php (or XTREAMPRO_API_KEY).',
            'FORBIDDEN'            => 'The API key does not belong to a reseller account, or the reseller is not allowed to do this (for sub-reseller accounts: the reseller\'s group may not create sub-resellers, or the hierarchy is too deep).',
            'RESOURCE_NOT_FOUND'   => 'The line or sub-reseller account was not found in the panel (it may have been deleted there).',
            'INVALID_REQUEST'      => 'The panel rejected the request, for example a username or password shorter than the reseller group allows, or an invalid email address.',
            'INVALID_PACKAGE'      => 'The selected package does not exist, is not available to this reseller, or cannot be sold this way (for example a package for MAG / Enigma boxes only sold as a line).',
            'INSUFFICIENT_CREDITS' => 'The reseller account has not enough credits.',
            'CONFLICT'             => 'The username (or, for sub-reseller accounts, the email address) is already taken in the panel, or the request id was already used for another operation.',
            'REQUEST_ID_SPENT'     => 'This sale was already made and its line has since been deleted on the panel, so the same request id cannot sell another line. Terminate the service and create it again.',
            'READ_ONLY_KEY'        => 'The API key is read-only. Create a key that may change things on the panel\'s API key page.',
            'POST_REQUIRED'        => 'The panel refused the request because it was not sent as POST.',
            'RATE_LIMITED'         => 'The panel is rate limiting this API key. Try again in a minute.',
            'UNKNOWN_ACTION'       => 'The panel does not know this API action. Is the panel up to date?',
            'SERVER_ERROR'         => 'The panel reported an internal error.',
            'CONNECTION_FAILED'    => 'Could not connect to the panel. Check api_url (host, port, http or https).',
            'BAD_RESPONSE'         => 'The panel answered with something that is not a valid API response. Check api_url.',
            'CONFIG'               => 'The script is not configured correctly (api_url or api_key missing or invalid).',
        );
        return isset($messages[$code]) ? $messages[$code] : 'The panel returned an error: ' . $code;
    }
}

class XtreamProClient
{
    /** Sent as "X-Connector: hostbill/<version>" on every call; keep in step with XTREAMPRO_VERSION. */
    const VERSION = XTREAMPRO_VERSION;

    const CONNECT_TIMEOUT = 5;
    const TIMEOUT = 20;

    /** @var string */
    private $base;

    /** @var string */
    private $apiKey;

    /**
     * @param string $base   Panel API base URL, e.g. https://api.example.com:8443 (no /reseller/v1)
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
            throw new XtreamProException('CONFIG');
        }
        $this->base = $base;
        $this->apiKey = trim((string) $apiKey);
    }

    public function get($action, array $query = array())
    {
        return $this->send('GET', array_merge(array('action' => $action), $query));
    }

    /** `action` goes in the body: the panel requires it there. */
    public function post($action, array $fields = array())
    {
        return $this->send('POST', array_merge(array('action' => $action), $fields));
    }

    public function userInfo()
    {
        return $this->dataOf($this->get('user_info'));
    }

    public function getLine($id)
    {
        return $this->dataOf($this->get('get_line', array('id' => (int) $id)));
    }

    /** Exact username match (get_lines search is a substring match). */
    public function findLineByUsername($username)
    {
        $rows = $this->dataOf($this->get('get_lines', array('search' => $username, 'start' => 0, 'limit' => 500)));
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
        return $this->dataOf($this->post('renew_line', array('id' => (int) $id, 'request_id' => (string) $requestId)));
    }

    /** $action: enable_line | disable_line | delete_line */
    public function lineAction($action, $id)
    {
        return $this->dataOf($this->post($action, array('id' => (int) $id)));
    }

    public function subUsers($search = '', $start = 0, $limit = 500)
    {
        $data = $this->dataOf($this->get('get_users', array(
            'search' => (string) $search,
            'start'  => (int) $start,
            'limit'  => max(1, min(500, (int) $limit)),
        )));
        return is_array($data) ? $data : array();
    }

    /** There is no get-one action: search by username, match the UUID, fall back to the exact username. */
    public function findSubUser($id, $username = '')
    {
        $byName = null;
        foreach ($this->subUsers((string) $username, 0, 500) as $row) {
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

    public function createSubUser($username, $password, $email, $fullname, $requestId)
    {
        return $this->dataOf($this->post('create_user', array(
            'username'   => (string) $username,
            'password'   => (string) $password,
            'email'      => (string) $email,
            'fullname'   => (string) $fullname,
            'request_id' => (string) $requestId,
        )));
    }

    public function adjustCredits($id, $credits, $note, $requestId)
    {
        return $this->dataOf($this->post('adjust_credits', array(
            'id'         => (string) $id,
            'credits'    => (int) $credits,
            'note'       => substr((string) $note, 0, 200),
            'request_id' => (string) $requestId,
        )));
    }

    /** $action: enable_user | disable_user */
    public function subUserAction($action, $id)
    {
        return $this->dataOf($this->post($action, array('id' => (string) $id)));
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
        } catch (XtreamProException $e) {
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
     * @throws XtreamProException INVALID_PACKAGE or INSUFFICIENT_CREDITS
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
            throw new XtreamProException('INVALID_PACKAGE', 400, 'Package #' . (int) $packageId . ' is not in the list of packages this reseller may sell.');
        }
        if ($plainLine && !self::sellsLine($package)) {
            throw new XtreamProException('INVALID_PACKAGE', 400, 'The package is for MAG / Enigma boxes only and cannot be sold as an IPTV line.');
        }
        if ($trial ? empty($package['is_trial']) : empty($package['is_official'])) {
            throw new XtreamProException('INVALID_PACKAGE', 400, 'The package is not offered as ' . ($trial ? 'a trial.' : 'an official period.'));
        }
        $cost = (int) ($trial ? (isset($package['trial_credits']) ? $package['trial_credits'] : 0) : (isset($package['official_credits']) ? $package['official_credits'] : 0));
        $this->assertBalance($pricing, $cost);
    }

    /**
     * Fail before an account is created when the reseller's group may not create sub-resellers or the balance does
     * not cover the account price plus the credits handed over at once.
     *
     * @throws XtreamProException FORBIDDEN or INSUFFICIENT_CREDITS
     */
    public function assertCanCreateSubUser($creditsOnCreate)
    {
        $pricing = $this->pricing();
        if ($pricing === null) {
            return;
        }
        $sub = isset($pricing['sub_reseller']) && is_array($pricing['sub_reseller']) ? $pricing['sub_reseller'] : array();
        if (empty($sub['can_create'])) {
            throw new XtreamProException('FORBIDDEN');
        }
        $this->assertBalance($pricing, (isset($sub['price']) ? (int) $sub['price'] : 0) + (int) $creditsOnCreate);
    }

    /**
     * Fail when the balance cannot give $credits to a sub-reseller (a renewal top-up).
     *
     * @throws XtreamProException INSUFFICIENT_CREDITS
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
            throw new XtreamProException('INSUFFICIENT_CREDITS', 402, sprintf('This needs %d credits, the reseller account has %d.', $cost, $balance));
        }
    }

    /**
     * Upgrade or downgrade: sells an official period of another package on the line and charges its price (the
     * panel's "change_package"). Returns the same data as renewLine(), including `links`.
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
     * What an upgrade / downgrade of a line to another package would do, asked without selling anything (the
     * panel's "package_compatibility"): keeps_time_left, reason, time_left_seconds, time_lost_seconds, exp_date,
     * new_exp_date, price, can_afford. Null when the panel is too old to know the action.
     *
     * @throws XtreamProException INVALID_PACKAGE when the package cannot be sold on that line, RESOURCE_NOT_FOUND
     */
    public function packageCompatibility($lineId, $packageId)
    {
        try {
            $data = $this->dataOf($this->get('package_compatibility', array('id' => (int) $lineId, 'package_id' => (int) $packageId)));
        } catch (XtreamProException $e) {
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
            'X-Connector: hostbill/' . self::VERSION,
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
        $body = curl_exec($ch);
        curl_close($ch);

        if ($body === false) {
            throw new XtreamProException('CONNECTION_FAILED');
        }
        $json = json_decode((string) $body, true);
        if (!is_array($json) || json_last_error() !== JSON_ERROR_NONE || !isset($json['status'])) {
            throw new XtreamProException('BAD_RESPONSE');
        }
        if ($json['status'] !== 'STATUS_SUCCESS') {
            $code = isset($json['error']) && is_string($json['error']) && $json['error'] !== '' ? $json['error'] : 'SERVER_ERROR';
            throw new XtreamProException($code);
        }
        return $json;
    }
}

// ---------------------------------------------------------------------------
// Input: command line first, then XTREAMPRO_* environment variables
// ---------------------------------------------------------------------------

/** Options that are switches (no value needed). */
function xp_flag_options()
{
    return array('trial', 'keep-on-terminate', 'delete-on-terminate', 'no-secrets');
}

function xp_known_options()
{
    return array_merge(
        xp_flag_options(),
        array('service', 'type', 'package', 'username', 'password', 'email', 'name', 'credits', 'renew-credits', 'due')
    );
}

/** @return array array($action, $options) */
function xp_parse_args(array $argv)
{
    $action = '';
    $options = array();
    $flags = xp_flag_options();
    $known = xp_known_options();
    $args = array_slice($argv, 1);
    for ($i = 0; $i < count($args); $i++) {
        $arg = (string) $args[$i];
        if (strpos($arg, '--') !== 0 && strpos($arg, '=') === false) {
            if ($action !== '') {
                throw new RuntimeException('Unexpected argument "' . $arg . '".');
            }
            $action = strtolower($arg);
            continue;
        }
        $arg = preg_replace('/^--/', '', $arg);
        $eq = strpos($arg, '=');
        $key = strtolower($eq === false ? $arg : substr($arg, 0, $eq));
        if (!in_array($key, $known, true)) {
            throw new RuntimeException('Unknown option "' . $key . '".');
        }
        if ($eq !== false) {
            $options[$key] = substr($arg, $eq + 1);
        } elseif (in_array($key, $flags, true)) {
            $options[$key] = '1';
        } elseif ($i + 1 < count($args)) {
            $options[$key] = (string) $args[++$i];
        } else {
            throw new RuntimeException('Option "' . $key . '" needs a value.');
        }
    }
    return array($action, $options);
}

function xp_truthy($value)
{
    return in_array(strtolower(trim((string) $value)), array('1', 'on', 'yes', 'true', 'y'), true);
}

/** Option value: command line, else XTREAMPRO_<NAME>, else $default. */
function xp_opt(array $options, $key, $default = '')
{
    if (array_key_exists($key, $options)) {
        return (string) $options[$key];
    }
    $env = getenv('XTREAMPRO_' . strtoupper(str_replace('-', '_', $key)));
    return ($env === false) ? $default : (string) $env;
}

/**
 * API URL, API key and data directory: config.php next to this script (or the
 * file named by XTREAMPRO_CONFIG), each value overridable by XTREAMPRO_API_URL /
 * XTREAMPRO_API_KEY / XTREAMPRO_DATA_DIR. The key is never taken from the command line.
 */
function xp_load_config()
{
    $cfg = array('api_url' => '', 'api_key' => '', 'data_dir' => '');
    $file = getenv('XTREAMPRO_CONFIG');
    $file = ($file === false || $file === '') ? __DIR__ . '/config.php' : $file;
    if (is_file($file)) {
        $perms = fileperms($file);
        if ($perms !== false && DIRECTORY_SEPARATOR === '/' && ($perms & 0007) !== 0) {
            throw new RuntimeException('Refusing to run: ' . $file . ' is readable by other users. Run: chmod 600 ' . $file);
        }
        $loaded = include $file;
        if (is_array($loaded)) {
            foreach ($cfg as $k => $_) {
                if (isset($loaded[$k]) && is_string($loaded[$k])) {
                    $cfg[$k] = $loaded[$k];
                }
            }
        }
    }
    foreach (array('api_url' => 'XTREAMPRO_API_URL', 'api_key' => 'XTREAMPRO_API_KEY', 'data_dir' => 'XTREAMPRO_DATA_DIR') as $k => $name) {
        $v = getenv($name);
        if ($v !== false && $v !== '') {
            $cfg[$k] = $v;
        }
    }
    if ($cfg['data_dir'] === '') {
        $cfg['data_dir'] = __DIR__ . '/data';
    }
    return $cfg;
}

// ---------------------------------------------------------------------------
// State: service id -> panel line id / user id / generation (JSON, flock, 0600)
// ---------------------------------------------------------------------------

class XtreamProState
{
    private $dir;
    private $file;
    private $lock = null;
    private $data = array('services' => array());

    public function __construct($dir)
    {
        $this->dir = rtrim($dir, '/\\');
        $this->file = $this->dir . '/state.json';
    }

    /** Take the exclusive lock (serialises runs, so two creates never race) and read the file. */
    public function open()
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new RuntimeException('The data directory ' . $this->dir . ' cannot be created.');
        }
        $lockPath = $this->dir . '/state.lock';
        $this->lock = @fopen($lockPath, 'c');
        if ($this->lock === false || !flock($this->lock, LOCK_EX)) {
            throw new RuntimeException('The data directory ' . $this->dir . ' is not writable.');
        }
        @chmod($lockPath, 0600);
        if (is_file($this->file)) {
            $json = json_decode((string) file_get_contents($this->file), true);
            if (is_array($json) && isset($json['services']) && is_array($json['services'])) {
                $this->data = $json;
            }
        }
    }

    public function get($serviceId)
    {
        $row = isset($this->data['services'][$serviceId]) ? $this->data['services'][$serviceId] : array();
        return $row + array('line_id' => 0, 'user_id' => null, 'generation' => 0, 'pending' => null);
    }

    public function set($serviceId, array $row)
    {
        $this->data['services'][$serviceId] = $row;
        $tmp = $this->file . '.' . getmypid() . '.tmp';
        $fh = @fopen($tmp, 'w');
        if ($fh === false) {
            throw new RuntimeException('The data directory ' . $this->dir . ' is not writable.');
        }
        chmod($tmp, 0600);
        fwrite($fh, json_encode($this->data));
        fclose($fh);
        rename($tmp, $this->file);
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function xp_random($length, $alphabet)
{
    $out = '';
    $max = strlen($alphabet) - 1;
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

/** Whole number >= 0 from an option. */
function xp_credits($value, $label)
{
    $value = trim((string) $value);
    if ($value === '') {
        return 0;
    }
    if (!ctype_digit($value) || strlen($value) > 9) {
        throw new RuntimeException($label . ' must be a whole number of 0 or more.');
    }
    return (int) $value;
}

/** Due date as Ymd for renewal request ids; today when missing or unreadable. */
function xp_due_stamp($due)
{
    $due = trim((string) $due);
    if ($due !== '' && substr($due, 0, 4) !== '0000' && ($ts = strtotime($due)) !== false) {
        return date('Ymd', $ts);
    }
    return date('Ymd');
}

function xp_format_expiry($exp)
{
    if ($exp === null || $exp === '' || (int) $exp <= 0) {
        return 'Never expires';
    }
    return gmdate('Y-m-d H:i', (int) $exp) . ' UTC';
}

/** Hide the password inside a play link. */
function xp_mask_link($url)
{
    return preg_replace('/(password=)[^&]+/', '$1********', (string) $url);
}

function xp_out(array $payload, $code)
{
    fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    exit($code);
}

// ---- panel ids ---------------------------------------------------------------

function xp_resolve_line_id(XtreamProClient $client, XtreamProState $state, $serviceId, $username)
{
    $row = $state->get($serviceId);
    if ($row['line_id']) {
        return (int) $row['line_id'];
    }
    $username = trim($username);
    if ($username === '') {
        return null;
    }
    $line = $client->findLineByUsername($username);
    if ($line === null || !isset($line['id'])) {
        return null;
    }
    $row['line_id'] = (int) $line['id'];
    $state->set($serviceId, $row);
    return (int) $line['id'];
}

function xp_require_line_id(XtreamProClient $client, XtreamProState $state, $serviceId, $username)
{
    $id = xp_resolve_line_id($client, $state, $serviceId, $username);
    if ($id === null) {
        throw new XtreamProException('RESOURCE_NOT_FOUND');
    }
    return $id;
}

function xp_resolve_user_id(XtreamProClient $client, XtreamProState $state, $serviceId, $username)
{
    $row = $state->get($serviceId);
    if ($row['user_id']) {
        return (string) $row['user_id'];
    }
    $username = trim($username);
    if ($username === '') {
        return null;
    }
    $user = $client->findSubUser(null, $username);
    if ($user === null || !isset($user['id'])) {
        return null;
    }
    $row['user_id'] = (string) $user['id'];
    $state->set($serviceId, $row);
    return (string) $user['id'];
}

function xp_require_user_id(XtreamProClient $client, XtreamProState $state, $serviceId, $username)
{
    $id = xp_resolve_user_id($client, $state, $serviceId, $username);
    if ($id === null) {
        throw new XtreamProException('RESOURCE_NOT_FOUND');
    }
    return $id;
}

/** Forget the line / account of a terminated service; a higher generation makes the next create a new request. */
function xp_close_mapping(XtreamProState $state, $serviceId)
{
    $row = $state->get($serviceId);
    $state->set($serviceId, array(
        'line_id'    => 0,
        'user_id'    => null,
        'generation' => (int) $row['generation'] + 1,
        'pending'    => null,
    ));
}

// ---- output of create / info -------------------------------------------------

function xp_line_payload(XtreamProClient $client, $lineId, $noSecrets)
{
    $line = $client->getLine($lineId);
    if (!is_array($line)) {
        throw new XtreamProException('BAD_RESPONSE');
    }
    $links = array();
    if (isset($line['links']) && is_array($line['links'])) {
        foreach ($line['links'] as $name => $url) {
            if (is_string($name) && is_string($url)) {
                $links[$name] = $noSecrets ? xp_mask_link($url) : $url;
            }
        }
    }
    $out = array(
        'ok'              => true,
        'type'            => 'line',
        'line_id'         => (int) $lineId,
        'username'        => isset($line['username']) ? (string) $line['username'] : '',
        'status'          => isset($line['status']) ? (string) $line['status'] : '',
        'expiry'          => xp_format_expiry(isset($line['exp_date']) ? $line['exp_date'] : null),
        'max_connections' => isset($line['max_connections']) ? (int) $line['max_connections'] : 0,
    );
    if (!$noSecrets) {
        $out['password'] = isset($line['password']) ? (string) $line['password'] : '';
    }
    $out['links'] = (object) $links;
    return $out;
}

function xp_reseller_payload(XtreamProClient $client, $userId, $username, $password, $noSecrets)
{
    $user = $client->findSubUser($userId, $username);
    if ($user === null) {
        throw new XtreamProException('RESOURCE_NOT_FOUND');
    }
    $out = array(
        'ok'       => true,
        'type'     => 'reseller',
        'user_id'  => (string) $userId,
        'username' => isset($user['username']) ? (string) $user['username'] : (string) $username,
        'status'   => isset($user['status']) ? (string) $user['status'] : '',
        'credits'  => isset($user['credits']) ? (int) $user['credits'] : 0,
    );
    // The panel keeps only a hash of a sub-reseller's password: it is echoed
    // only when this call was given one.
    if (!$noSecrets && $password !== '') {
        $out['password'] = $password;
    }
    $out['links'] = new stdClass();
    return $out;
}

// ---- actions ----------------------------------------------------------------

function xp_create_line(XtreamProClient $client, XtreamProState $state, array $in)
{
    $serviceId = $in['service'];
    $packageId = (int) $in['package'];
    if ($packageId <= 0) {
        throw new RuntimeException('No package was given: pass --package=<panel package id>.');
    }
    $row = $state->get($serviceId);

    // Ask the panel first: too few credits, a package that is not on sale or one for boxes only fail here
    // with the amounts and nothing is created.
    $client->assertCanSellPackage($packageId, $in['trial'], true);

    // Blank values are generated by the panel. The reseller's group may also
    // ignore custom credentials, so the final ones are read back below.
    $data = $client->createLine(
        $packageId,
        $in['trial'],
        $in['username'],
        $in['password'],
        // The generation changes after a termination, so that creating the
        // service again sells a new line instead of replaying the deleted one.
        'hostbill-create-' . $serviceId . '-' . $row['generation']
    );
    $line = (is_array($data) && isset($data['line']) && is_array($data['line'])) ? $data['line'] : array();
    if (!isset($line['id'])) {
        throw new XtreamProException('BAD_RESPONSE');
    }
    $row['line_id'] = (int) $line['id'];
    $state->set($serviceId, $row);
    return xp_line_payload($client, (int) $line['id'], $in['no-secrets']);
}

function xp_create_reseller(XtreamProClient $client, XtreamProState $state, array $in)
{
    $serviceId = $in['service'];
    $row = $state->get($serviceId);
    $generation = $row['generation'];
    $creditsOnCreate = xp_credits($in['credits'], '--credits');
    if (!is_array($row['pending']) && empty($row['user_id'])) {
        // The price of the account plus the credits to hand over: checked before the account exists.
        $client->assertCanCreateSubUser($creditsOnCreate);
    }

    // A retry (timeout, failed credit transfer) must send the very same values,
    // not newly generated ones: the panel stores only a hash of the password.
    $pending = is_array($row['pending']) ? $row['pending'] : array();
    $username = isset($pending['username']) ? $pending['username'] : trim($in['username']);
    $password = isset($pending['password']) ? $pending['password'] : $in['password'];
    if ($username === '') {
        $username = 'r' . $serviceId . xp_random(6, 'abcdefghijklmnopqrstuvwxyz');
    }
    // Panel rule: letters, digits, "_ . -", 3 to 32 characters. A longer name is cut.
    $username = substr($username, 0, 32);
    if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $username)) {
        throw new RuntimeException('The username "' . $username . '" is not valid for a sub-reseller: use 3 to 32 letters, digits, "_", "." or "-".');
    }
    // The panel needs both username and password when a request id is sent.
    if ($password === '') {
        $password = xp_random(14, 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789');
    }
    $row['pending'] = array('username' => $username, 'password' => $password);
    $state->set($serviceId, $row);

    $data = $client->createSubUser($username, $password, $in['email'], $in['name'], 'hostbill-sub-' . $serviceId . '-' . $generation);
    $user = (is_array($data) && isset($data['user']) && is_array($data['user'])) ? $data['user'] : array();
    if (!isset($user['id'])) {
        throw new XtreamProException('BAD_RESPONSE');
    }
    $userId = (string) $user['id'];
    if (isset($user['username']) && $user['username'] !== '') {
        $username = (string) $user['username'];
    }
    if (isset($data['password']) && $data['password'] !== '') {
        $password = (string) $data['password'];
    }
    $row['user_id'] = $userId;
    $row['pending'] = array('username' => $username, 'password' => $password);
    $state->set($serviceId, $row);

    if ($creditsOnCreate > 0) {
        $client->adjustCredits($userId, $creditsOnCreate, 'HostBill service #' . $serviceId, 'hostbill-subc-' . $serviceId . '-' . $generation);
    }
    // Everything went through: the retry values are no longer needed.
    $row['pending'] = null;
    $state->set($serviceId, $row);
    return xp_reseller_payload($client, $userId, $username, $password, $in['no-secrets']);
}

/**
 * Upgrade / downgrade of a line: sells the new package on it (`change_package`, charged). Asks the panel first
 * (`pricing`, `package_compatibility`): a package that is not on sale, one for boxes only or too few credits fail
 * before anything is sold. A line already on the package is left alone (a repeated call).
 */
function xp_change_package(XtreamProClient $client, XtreamProState $state, array $in)
{
    $serviceId = $in['service'];
    $newPackage = (int) $in['package'];
    if ($newPackage <= 0) {
        throw new RuntimeException('No package was given: pass --package=<panel package id of the new package>.');
    }
    $lineId = xp_require_line_id($client, $state, $serviceId, trim($in['username']));
    $line = $client->getLine($lineId);
    $current = is_array($line) && isset($line['package_id']) ? (int) $line['package_id'] : 0;
    if ($current === $newPackage) {
        return array('ok' => true, 'message' => 'already on this package', 'line_id' => (int) $lineId);
    }
    $client->assertCanSellPackage($newPackage, false, true);
    $compat = $client->packageCompatibility($lineId, $newPackage);
    $sentence = '';
    if (is_array($compat)) {
        $sentence = XtreamProClient::describeCompatibility($compat);
        if (empty($compat['can_afford'])) {
            throw new XtreamProException('INSUFFICIENT_CREDITS', 402, 'The change costs ' . (int) (isset($compat['price']) ? $compat['price'] : 0) . ' credits.');
        }
    }
    $row = $state->get($serviceId);
    // One request id per line state: a retry of the same change is not charged twice.
    $exp = is_array($line) && isset($line['exp_date']) ? (int) $line['exp_date'] : 0;
    $client->changePackage($lineId, $newPackage, 'hostbill-chg-' . $serviceId . '-' . $row['generation'] . '-' . $newPackage . '-' . $exp);
    $out = array('ok' => true, 'message' => 'package changed', 'line_id' => (int) $lineId);
    if ($sentence !== '') {
        $out['panel'] = $sentence;
    }
    return $out;
}

function xp_run(array $in, $action)
{
    $cfg = xp_load_config();
    $client = new XtreamProClient($cfg['api_url'], $cfg['api_key']);
    $state = new XtreamProState($cfg['data_dir']);
    $state->open();

    $serviceId = $in['service'];
    $reseller = $in['type'] === 'reseller';
    $username = trim($in['username']);

    switch ($action) {
        case 'create':
            return $reseller ? xp_create_reseller($client, $state, $in) : xp_create_line($client, $state, $in);

        case 'info':
            if ($reseller) {
                $userId = xp_require_user_id($client, $state, $serviceId, $username);
                return xp_reseller_payload($client, $userId, $username, $in['password'], $in['no-secrets']);
            }
            return xp_line_payload($client, xp_require_line_id($client, $state, $serviceId, $username), $in['no-secrets']);

        case 'suspend':
        case 'unsuspend':
            if ($reseller) {
                $client->subUserAction($action === 'suspend' ? 'disable_user' : 'enable_user', xp_require_user_id($client, $state, $serviceId, $username));
            } else {
                $client->lineAction($action === 'suspend' ? 'disable_line' : 'enable_line', xp_require_line_id($client, $state, $serviceId, $username));
            }
            return array('ok' => true, 'message' => $action === 'suspend' ? 'suspended' : 'unsuspended');

        case 'change-package':
            if ($reseller) {
                throw new RuntimeException('A sub-reseller account has no package on the panel: nothing to change.');
            }
            return xp_change_package($client, $state, $in);

        case 'terminate':
            // The panel has no delete action for sub-resellers: they are disabled.
            // A line or account that no longer exists in the panel is already terminated.
            try {
                if ($reseller) {
                    $userId = xp_resolve_user_id($client, $state, $serviceId, $username);
                    if ($userId !== null) {
                        $client->subUserAction('disable_user', $userId);
                    }
                } else {
                    $lineId = xp_resolve_line_id($client, $state, $serviceId, $username);
                    if ($lineId !== null) {
                        // Disabling is the default; deleting is final on the panel and only happens with --delete-on-terminate.
                        $client->lineAction(($in['delete-on-terminate'] && !$in['keep-on-terminate']) ? 'delete_line' : 'disable_line', $lineId);
                    }
                }
            } catch (XtreamProException $e) {
                if ($e->getErrorCode() !== 'RESOURCE_NOT_FOUND') {
                    throw $e;
                }
            }
            xp_close_mapping($state, $serviceId);
            return array('ok' => true, 'message' => 'terminated');

        case 'renew':
            $stamp = xp_due_stamp($in['due']);
            if ($reseller) {
                // A renewal tops up credits (0 = nothing to do).
                $credits = xp_credits($in['renew-credits'], '--renew-credits');
                if ($credits > 0) {
                    $client->assertCanGiveCredits($credits);
                    $client->adjustCredits(
                        xp_require_user_id($client, $state, $serviceId, $username),
                        $credits,
                        'HostBill renewal, service #' . $serviceId,
                        'hostbill-subr-' . $serviceId . '-' . $stamp
                    );
                }
            } else {
                // The due date is in the request id, so a retry on the same
                // period returns the first result instead of charging twice.
                $lineId = xp_require_line_id($client, $state, $serviceId, $username);
                $current = $client->getLine($lineId);
                if (is_array($current) && isset($current['package_id']) && (int) $current['package_id'] > 0) {
                    // Fails with the amounts when the balance cannot pay the period; nothing is sold then.
                    $client->assertCanSellPackage((int) $current['package_id'], false, false);
                }
                $client->renewLine($lineId, 'hostbill-renew-' . $serviceId . '-' . $stamp);
            }
            return array('ok' => true, 'message' => 'renewed');
    }
    throw new RuntimeException('Unknown action "' . $action . '". Use create, suspend, unsuspend, terminate, renew, change-package or info.');
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

try {
    list($action, $options) = xp_parse_args($argv);
    if ($action === '') {
        throw new RuntimeException('Usage: php xtreampro-provision.php <create|suspend|unsuspend|terminate|renew|change-package|info> --service=<id> [options] (version ' . XTREAMPRO_VERSION . ')');
    }
    $service = trim(xp_opt($options, 'service'));
    if (!preg_match('/^[0-9]{1,12}$/', $service)) {
        throw new RuntimeException('--service must be the numeric id of the service (XTREAMPRO_SERVICE).');
    }
    $type = strtolower(trim(xp_opt($options, 'type', 'line')));
    if ($type === '') {
        $type = 'line';
    }
    if ($type !== 'line' && $type !== 'reseller') {
        throw new RuntimeException('--type must be "line" or "reseller".');
    }
    $in = array(
        'service'           => $service,
        'type'              => $type,
        'package'           => trim(xp_opt($options, 'package')),
        'trial'             => xp_truthy(xp_opt($options, 'trial')),
        'keep-on-terminate' => xp_truthy(xp_opt($options, 'keep-on-terminate')),
        'delete-on-terminate' => xp_truthy(xp_opt($options, 'delete-on-terminate')),
        'no-secrets'        => xp_truthy(xp_opt($options, 'no-secrets')),
        'username'          => xp_opt($options, 'username'),
        'password'          => xp_opt($options, 'password'),
        'email'             => trim(xp_opt($options, 'email')),
        'name'              => trim(xp_opt($options, 'name')),
        'credits'           => xp_opt($options, 'credits'),
        'renew-credits'     => xp_opt($options, 'renew-credits'),
        'due'               => xp_opt($options, 'due'),
    );
    xp_out(xp_run($in, $action), 0);
} catch (XtreamProException $e) {
    xp_out(array('ok' => false, 'error' => $e->getMessage()), 1);
} catch (Throwable $e) {
    // RuntimeException texts are written for the operator; anything else is
    // reduced to a generic message so nothing sensitive can leak.
    xp_out(array('ok' => false, 'error' => $e instanceof RuntimeException ? $e->getMessage() : 'Unexpected error in the provisioning script.'), 1);
}
