<?php

declare(strict_types=1);
/**
 * Xtream UI Pro - small Reseller API client (curl based, no dependencies).
 *
 * Talks to {base}/reseller/v1 with the reseller's API key in the X-API-Key
 * header. Reads are GET, mutations are POST with a form-urlencoded body.
 *
 * @version 1.1.0
 */

namespace Box\Mod\Servicextreampro;

class ApiClient
{
    /** Sent as "X-Connector: fossbilling/<version>" on every call; keep in step with manifest.json. */
    public const VERSION = '1.1.0';

    public const CONNECT_TIMEOUT = 5;
    public const TIMEOUT = 20;

    private string $base;
    private string $apiKey;

    /**
     * @param string $base   Panel API base URL, e.g. https://api.example.com:8443 (no /reseller/v1)
     * @param string $apiKey Reseller API key
     */
    public function __construct(string $base, string $apiKey)
    {
        $base = rtrim(trim($base), '/');
        $parts = parse_url($base);
        if (
            $base === '' || trim($apiKey) === '' || $parts === false
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
        ) {
            throw new ApiException('CONFIG');
        }
        $this->base = $base;
        $this->apiKey = trim($apiKey);
    }

    /** GET read action. Returns the decoded envelope. */
    public function get(string $action, array $query = []): array
    {
        return $this->send('GET', array_merge(['action' => $action], $query));
    }

    /** POST mutation. `action` is sent in the body (the panel requires it there). */
    public function post(string $action, array $fields = []): array
    {
        return $this->send('POST', array_merge(['action' => $action], $fields));
    }

    // ---- convenience wrappers ------------------------------------------

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

    /** One account below the API key's reseller. */
    public function getUser(string $id)
    {
        return $this->dataOf($this->get('get_user', ['id' => $id]));
    }

    public function createSubUser(string $username, string $password, string $email, string $fullname, string $requestId)
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
    public function subUserAction(string $action, string $id)
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

    // ---- transport -------------------------------------------------------

    private function dataOf(array $envelope)
    {
        return $envelope['data'] ?? null;
    }

    private function send(string $method, array $params): array
    {
        $url = $this->base . '/reseller/v1';
        $headers = [
            'Accept: application/json',
            'X-API-Key: ' . $this->apiKey,
            // Names this connector in the panel's API call log (never parameters, never the key).
            'X-Connector: fossbilling/' . self::VERSION,
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
            // Never follow redirects: they could carry the API key elsewhere.
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if ($body === false) {
            throw new ApiException('CONNECTION_FAILED');
        }

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
}
