<?php

namespace Paymenter\Extensions\Servers\XtreamPro;

use Exception;
use Illuminate\Support\Facades\Http;

/**
 * Error raised for every failure: transport problem, bad response or an API
 * error code. getMessage() is always a readable English sentence; the raw code
 * (e.g. INSUFFICIENT_CREDITS) is in getErrorCode().
 */
class ApiException extends Exception
{
    private string $errorCode;

    private int $httpStatus;

    /**
     * @param  string  $detail  Sentence appended to the readable text, e.g. the amounts of a refused sale
     */
    public function __construct(string $errorCode, int $httpStatus = 0, string $detail = '')
    {
        $this->errorCode = $errorCode;
        $this->httpStatus = $httpStatus;
        $text = self::describe($errorCode);
        parent::__construct($detail === '' ? $text : $text . ' ' . $detail);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /** Readable text for an API (or client side) error code. */
    public static function describe(string $code): string
    {
        $messages = [
            'INVALID_API_KEY' => 'The panel rejected the API key. Check the API key of the Xtream UI Pro server in Paymenter.',
            'FORBIDDEN' => 'The API key does not belong to a reseller account, or the reseller is not allowed to do this (for sub-reseller accounts: the reseller\'s group may not create sub-resellers, or the hierarchy is too deep).',
            'RESOURCE_NOT_FOUND' => 'The line or sub-reseller account was not found in the panel (it may have been deleted there).',
            'INVALID_REQUEST' => 'The panel rejected the request, for example a username or password shorter than the reseller group allows, or an invalid email address.',
            'INVALID_PACKAGE' => 'The selected package does not exist, is not available to this reseller, or cannot be sold this way (for example a package for MAG / Enigma boxes only sold as a line).',
            'INSUFFICIENT_CREDITS' => 'The reseller account has not enough credits.',
            'CONFLICT' => 'The username (or, for sub-reseller accounts, the email address) is already taken in the panel, or the request id was already used for another operation.',
            'REQUEST_ID_SPENT' => 'This sale was already made and its line has since been deleted on the panel, so the same request id cannot sell another line. Terminate the service and create it again.',
            'READ_ONLY_KEY' => 'The API key is read-only. Create a key that may change things on the panel\'s API key page.',
            'POST_REQUIRED' => 'The panel refused the request because it was not sent as POST.',
            'RATE_LIMITED' => 'The panel is rate limiting this API key. Try again in a minute.',
            'UNKNOWN_ACTION' => 'The panel does not know this API action. Is the panel up to date?',
            'SERVER_ERROR' => 'The panel reported an internal error.',
            'CONNECTION_FAILED' => 'Could not connect to the panel. Check the API URL and that the certificate is valid.',
            'BAD_RESPONSE' => 'The panel answered with something that is not a valid API response. Check the API URL.',
            'CONFIG' => 'The Xtream UI Pro server in Paymenter is not configured correctly (API URL or API key missing).',
        ];

        return $messages[$code] ?? ('The panel returned an error: ' . $code);
    }
}

/**
 * Small Reseller API client. Talks to {base}/reseller/v1 with the reseller's
 * API key in the X-API-Key header. Reads are GET, mutations are POST with a
 * form-urlencoded body.
 */
class Client
{
    /** Sent as "X-Connector: paymenter/<version>" on every call; keep in step with XtreamPro::VERSION. */
    public const VERSION = '1.1.0';

    public const CONNECT_TIMEOUT = 5;

    public const TIMEOUT = 20;

    private string $base;

    private string $apiKey;

    /**
     * @param  string  $base  Panel API base URL, e.g. https://api.example.com:8443 (no /reseller/v1)
     * @param  string  $apiKey  Reseller API key
     */
    public function __construct($base, $apiKey)
    {
        $base = rtrim(trim((string) $base), '/');
        $parts = parse_url($base);
        if (
            $base === '' || trim((string) $apiKey) === '' || $parts === false
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
        ) {
            throw new ApiException('CONFIG');
        }
        $this->base = $base;
        $this->apiKey = trim((string) $apiKey);
    }

    public function baseUrl(): string
    {
        return $this->base;
    }

    public function get(string $action, array $query = []): array
    {
        return $this->send('GET', array_merge(['action' => $action], $query));
    }

    /** `action` is sent in the body (the panel requires it there). */
    public function post(string $action, array $fields = []): array
    {
        return $this->send('POST', array_merge(['action' => $action], $fields));
    }

    // ---- lines -----------------------------------------------------------

    public function userInfo()
    {
        return $this->dataOf($this->get('user_info'));
    }

    public function packages(): array
    {
        $data = $this->dataOf($this->get('packages'));

        return is_array($data) ? $data : [];
    }

    public function getLine(int $id)
    {
        return $this->dataOf($this->get('get_line', ['id' => $id]));
    }

    /** Exact username match (get_lines search is a substring match), or null. */
    public function findLineByUsername(string $username): ?array
    {
        $rows = $this->dataOf($this->get('get_lines', ['search' => $username, 'start' => 0, 'limit' => 500]));
        if (!is_array($rows)) {
            return null;
        }
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['username']) && (string) $row['username'] === $username) {
                return $row;
            }
        }

        return null;
    }

    public function createLine(int $packageId, bool $trial, string $username, string $password, string $requestId)
    {
        return $this->dataOf($this->post('create_line', [
            'package_id' => $packageId,
            'trial' => $trial ? 1 : 0,
            'username' => $username,
            'password' => $password,
            'request_id' => $requestId,
        ]));
    }

    public function renewLine(int $id, string $requestId)
    {
        return $this->dataOf($this->post('renew_line', ['id' => $id, 'request_id' => $requestId]));
    }

    /** $action: enable_line | disable_line | delete_line */
    public function lineAction(string $action, int $id)
    {
        return $this->dataOf($this->post($action, ['id' => $id]));
    }

    // ---- sub-reseller accounts -------------------------------------------

    public function getUser(string $id)
    {
        return $this->dataOf($this->get('get_user', ['id' => $id]));
    }

    public function findUserByUsername(string $username): ?array
    {
        $rows = $this->dataOf($this->get('get_users', ['search' => $username, 'start' => 0, 'limit' => 500]));
        if (!is_array($rows)) {
            return null;
        }
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['username']) && (string) $row['username'] === $username) {
                return $row;
            }
        }

        return null;
    }

    public function createUser(string $username, string $password, string $email, string $fullname, string $requestId)
    {
        return $this->dataOf($this->post('create_user', [
            'username' => $username,
            'password' => $password,
            'email' => $email,
            'fullname' => $fullname,
            'request_id' => $requestId,
        ]));
    }

    /** credits > 0 gives credits to the account, < 0 takes them back. */
    public function adjustCredits(string $id, int $credits, string $note, string $requestId)
    {
        return $this->dataOf($this->post('adjust_credits', [
            'id' => $id,
            'credits' => $credits,
            'note' => substr($note, 0, 200),
            'request_id' => $requestId,
        ]));
    }

    /** $action: enable_user | disable_user */
    public function userAction(string $action, string $id)
    {
        return $this->dataOf($this->post($action, ['id' => $id]));
    }

    // ---- capabilities of the current Reseller API (sells, pricing, change_package, links) -------------------

    /**
     * Whether a package of `packages` / `pricing` can be sold as a plain IPTV line. The panel lists what a package
     * can be sold as in `sells` (a subset of line, mag, enigma); a panel too old to send it leaves the key out, and
     * the package then counts as sellable (the sale itself is still checked by the panel).
     */
    public static function sellsLine($package): bool
    {
        if (!is_array($package) || !isset($package['sells']) || !is_array($package['sells'])) {
            return true;
        }

        return in_array('line', $package['sells'], true);
    }

    /**
     * What the reseller can sell and afford: credits, packages[] (with the reseller's price), sub_reseller and the
     * line rules. Null when the panel is too old to know the action; the sale is then still decided by the panel.
     */
    public function pricing(): ?array
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
     * when the line is created. $plainLine: the package is sold as a plain line (create); a renewal passes false.
     *
     * @throws ApiException INVALID_PACKAGE or INSUFFICIENT_CREDITS
     */
    public function assertCanSellPackage(int $packageId, bool $trial, bool $plainLine = true): void
    {
        $pricing = $this->pricing();
        if ($pricing === null) {
            return;
        }
        $package = null;
        foreach (is_array($pricing['packages'] ?? null) ? $pricing['packages'] : [] as $row) {
            if (is_array($row) && isset($row['id']) && (int) $row['id'] === $packageId) {
                $package = $row;
                break;
            }
        }
        if ($package === null) {
            throw new ApiException('INVALID_PACKAGE', 400, 'Package #' . $packageId . ' is not in the list of packages this reseller may sell.');
        }
        if ($plainLine && !self::sellsLine($package)) {
            throw new ApiException('INVALID_PACKAGE', 400, 'The package is for MAG / Enigma boxes only and cannot be sold as an IPTV line.');
        }
        if ($trial ? empty($package['is_trial']) : empty($package['is_official'])) {
            throw new ApiException('INVALID_PACKAGE', 400, 'The package is not offered as ' . ($trial ? 'a trial.' : 'an official period.'));
        }
        $this->assertBalance($pricing, (int) ($trial ? ($package['trial_credits'] ?? 0) : ($package['official_credits'] ?? 0)));
    }

    /**
     * Fail before an account is created when the reseller's group may not create sub-resellers or the balance does
     * not cover the account price plus the credits handed over at once.
     *
     * @throws ApiException FORBIDDEN or INSUFFICIENT_CREDITS
     */
    public function assertCanCreateSubUser(int $creditsOnCreate): void
    {
        $pricing = $this->pricing();
        if ($pricing === null) {
            return;
        }
        $sub = is_array($pricing['sub_reseller'] ?? null) ? $pricing['sub_reseller'] : [];
        if (empty($sub['can_create'])) {
            throw new ApiException('FORBIDDEN');
        }
        $this->assertBalance($pricing, (int) ($sub['price'] ?? 0) + $creditsOnCreate);
    }

    /**
     * Fail when the balance cannot give $credits to a sub-reseller (a renewal top-up).
     *
     * @throws ApiException INSUFFICIENT_CREDITS
     */
    public function assertCanGiveCredits(int $credits): void
    {
        $pricing = $this->pricing();
        if ($pricing !== null) {
            $this->assertBalance($pricing, $credits);
        }
    }

    /** A balance of 0 sells nothing, not even a free package (the panel refuses it). */
    private function assertBalance(array $pricing, int $cost): void
    {
        $balance = (int) ($pricing['credits'] ?? 0);
        if ($balance <= 0 || $balance < $cost) {
            throw new ApiException('INSUFFICIENT_CREDITS', 402, sprintf('This needs %d credits, the reseller account has %d.', $cost, $balance));
        }
    }

    /**
     * Play links of a get_line / create_line / renew_line answer: server, m3u, m3u_hls, xmltv, player_api,
     * web_player. Null when the panel sent none (an older panel, or a line whose password is stored hashed).
     */
    public static function linksOf($data): ?array
    {
        return (is_array($data) && isset($data['links']) && is_array($data['links'])) ? $data['links'] : null;
    }

    /**
     * What an upgrade / downgrade of a line to another package would do, asked without selling anything (the
     * panel's "package_compatibility"): keeps_time_left, reason, time_left_seconds, time_lost_seconds, exp_date,
     * new_exp_date, price, can_afford. Null when the panel is too old to know the action.
     *
     * @throws ApiException INVALID_PACKAGE when the package cannot be sold on that line, RESOURCE_NOT_FOUND
     */
    public function packageCompatibility(int $lineId, int $packageId): ?array
    {
        try {
            $data = $this->dataOf($this->get('package_compatibility', ['id' => $lineId, 'package_id' => $packageId]));
        } catch (ApiException $e) {
            if ($e->getErrorCode() === 'UNKNOWN_ACTION') {
                return null;
            }
            throw $e;
        }

        return (is_array($data) && array_key_exists('keeps_time_left', $data)) ? $data : null;
    }

    /** One sentence for an admin: what the change does to the time left and what it costs. */
    public static function describeCompatibility(array $c): string
    {
        $days = fn ($seconds): string => (string) (int) round(max(0, (int) $seconds) / 86400);
        if (!empty($c['keeps_time_left'])) {
            $time = isset($c['time_left_seconds'])
                ? 'the time left (about ' . $days($c['time_left_seconds']) . ' days) is kept and the new period is added to it'
                : 'the time left is kept and the new period is added to it';
        } else {
            $time = 'the new period starts today';
            if ((int) ($c['time_lost_seconds'] ?? 0) > 0) {
                $time .= ' and the time left (about ' . $days($c['time_lost_seconds']) . ' days) is lost';
            }
        }

        return 'On the panel ' . $time . '; it costs ' . (int) ($c['price'] ?? 0) . ' credits.';
    }

    /**
     * Upgrade or downgrade: sells an official period of another package on the line and charges its price (the
     * panel's "change_package"). Returns the same data as renewLine(), including `links`.
     */
    public function changePackage(int $id, int $packageId, string $requestId)
    {
        return $this->dataOf($this->post('change_package', ['id' => $id, 'package_id' => $packageId, 'request_id' => $requestId]));
    }

    // ---- transport -------------------------------------------------------

    private function dataOf(array $envelope)
    {
        return $envelope['data'] ?? null;
    }

    private function send(string $method, array $params): array
    {
        $url = $this->base . '/reseller/v1';

        $http = Http::withHeaders([
            'Accept' => 'application/json',
            'X-API-Key' => $this->apiKey,
            // Names this connector in the panel's API call log (never parameters, never the key).
            'X-Connector' => 'paymenter/' . self::VERSION,
        ])
            ->withOptions([
                // Never follow redirects: they could carry the API key elsewhere.
                'allow_redirects' => false,
                'verify' => true,
                'connect_timeout' => self::CONNECT_TIMEOUT,
                'timeout' => self::TIMEOUT,
            ]);

        try {
            $response = $method === 'POST'
                ? $http->asForm()->post($url, $params)
                : $http->get($url, $params);
        } catch (Exception $e) {
            // The exception text may contain the URL of the request: not shown.
            throw new ApiException('CONNECTION_FAILED');
        }

        $json = json_decode((string) $response->body(), true);
        if (!is_array($json) || !isset($json['status'])) {
            throw new ApiException('BAD_RESPONSE', $response->status());
        }
        if ($json['status'] !== 'STATUS_SUCCESS') {
            $code = isset($json['error']) && is_string($json['error']) && $json['error'] !== ''
                ? $json['error'] : 'SERVER_ERROR';
            throw new ApiException($code, $response->status());
        }

        return $json;
    }
}
