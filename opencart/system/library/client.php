<?php
namespace Opencart\System\Library\Extension\Xtreampro;

/**
 * Small Reseller API client (curl, no dependencies).
 *
 * Talks to {base}/reseller/v1 with the reseller's API key in the X-API-Key
 * header. Reads are GET, mutations are POST with a form-urlencoded body.
 *
 * The API key and line passwords never reach the log callable: it receives one
 * line per request ("POST create_line -> STATUS_SUCCESS") and the error code of
 * a failure, nothing else.
 *
 * Platform independent: no OpenCart class is used here.
 */
class Client {
	/** Sent as "X-Connector: opencart/<version>" on every call; keep in step with install.json. */
	const VERSION = '1.1.0';

	const CONNECT_TIMEOUT = 5;
	const TIMEOUT = 20;

	private string $base;
	private string $api_key;
	/** @var callable|null */
	private $log;

	/**
	 * @param string        $base    Panel API address, e.g. https://api.example.com (no /reseller/v1)
	 * @param string        $api_key Reseller API key
	 * @param callable|null $log     function (string $message): void
	 */
	public function __construct(string $base, string $api_key, ?callable $log = null) {
		$base = rtrim(trim($base), '/');
		$parts = parse_url($base);

		if ($base === '' || trim($api_key) === '' || $parts === false || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
			throw new ApiException('CONFIG');
		}

		$this->base = $base;
		$this->api_key = trim($api_key);
		$this->log = $log;
	}

	/** Panel API address without a trailing slash. */
	public function getBase(): string {
		return $this->base;
	}

	// ---- generic -------------------------------------------------------------

	/** GET read action. Returns the decoded envelope. */
	public function get(string $action, array $query = []): array {
		return $this->send('GET', array_merge(['action' => $action], $query));
	}

	/** POST mutation. `action` is sent in the body (the panel requires it there). */
	public function post(string $action, array $fields = []): array {
		return $this->send('POST', array_merge(['action' => $action], $fields));
	}

	// ---- lines ---------------------------------------------------------------

	public function userInfo() {
		return $this->dataOf($this->get('user_info'));
	}

	public function packages(): array {
		$data = $this->dataOf($this->get('packages'));

		return is_array($data) ? $data : [];
	}

	/** Includes `links` (server, m3u, m3u_hls, xmltv, player_api, web_player). */
	public function getLine(int $id) {
		return $this->dataOf($this->get('get_line', ['id' => $id]));
	}

	public function createLine(int $package_id, bool $trial, string $username, string $password, string $request_id) {
		return $this->dataOf($this->post('create_line', [
			'package_id' => $package_id,
			'trial'      => $trial ? 1 : 0,
			'username'   => $username,
			'password'   => $password,
			'request_id' => $request_id
		]));
	}

	/** $action: enable_line | disable_line */
	public function lineAction(string $action, int $id) {
		return $this->dataOf($this->post($action, ['id' => $id]));
	}

	// ---- sub-reseller accounts -------------------------------------------------

	public function getUser(string $id) {
		return $this->dataOf($this->get('get_user', ['id' => $id]));
	}

	public function createUser(string $username, string $password, string $email, string $fullname, string $request_id) {
		return $this->dataOf($this->post('create_user', [
			'username'   => $username,
			'password'   => $password,
			'email'      => $email,
			'fullname'   => $fullname,
			'request_id' => $request_id
		]));
	}

	/** credits > 0 gives credits to the account, < 0 takes them back. */
	public function adjustCredits(string $id, int $credits, string $note, string $request_id) {
		return $this->dataOf($this->post('adjust_credits', [
			'id'         => $id,
			'credits'    => $credits,
			'note'       => substr($note, 0, 200),
			'request_id' => $request_id
		]));
	}

	/** $action: enable_user | disable_user */
	public function userAction(string $action, string $id) {
		return $this->dataOf($this->post($action, ['id' => $id]));
	}

	// ---- capabilities of the current Reseller API (sells, pricing, links) -------------------

	/**
	 * Whether a package of `packages` / `pricing` can be sold as a plain IPTV line. The panel lists what a package
	 * can be sold as in `sells` (a subset of line, mag, enigma); a panel too old to send it leaves the key out, and
	 * the package then counts as sellable (the sale itself is still checked by the panel).
	 */
	public static function sellsLine($package): bool {
		if (!is_array($package) || !isset($package['sells']) || !is_array($package['sells'])) {
			return true;
		}

		return in_array('line', $package['sells'], true);
	}

	/**
	 * What the reseller can sell and afford: credits, packages[] (with the reseller's price), sub_reseller and the
	 * line rules. Null when the panel is too old to know the action; the sale is then still decided by the panel.
	 */
	public function pricing(): ?array {
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
	public function assertCanSellPackage(int $packageId, bool $trial, bool $plainLine = true): void {
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
	public function assertCanCreateSubUser(int $creditsOnCreate): void {
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
	public function assertCanGiveCredits(int $credits): void {
		$pricing = $this->pricing();
		if ($pricing !== null) {
			$this->assertBalance($pricing, $credits);
		}
	}

	/** A balance of 0 sells nothing, not even a free package (the panel refuses it). */
	private function assertBalance(array $pricing, int $cost): void {
		$balance = (int) ($pricing['credits'] ?? 0);
		if ($balance <= 0 || $balance < $cost) {
			throw new ApiException('INSUFFICIENT_CREDITS', 402, sprintf('This needs %d credits, the reseller account has %d.', $cost, $balance));
		}
	}

	/**
	 * Play links of a get_line / create_line / renew_line answer: server, m3u, m3u_hls, xmltv, player_api,
	 * web_player. Null when the panel sent none (an older panel, or a line whose password is stored hashed).
	 */
	public static function linksOf($data): ?array {
		return (is_array($data) && isset($data['links']) && is_array($data['links'])) ? $data['links'] : null;
	}

	// ---- transport -------------------------------------------------------------

	private function dataOf(array $envelope) {
		return $envelope['data'] ?? null;
	}

	private function send(string $method, array $params): array {
		$url = $this->base . '/reseller/v1';

		$headers = [
			'Accept: application/json',
			'X-API-Key: ' . $this->api_key,
			// Names this connector in the panel's API call log (never parameters, never the key).
			'X-Connector: opencart/' . self::VERSION
		];

		$curl = curl_init();

		if ($method === 'POST') {
			$headers[] = 'Content-Type: application/x-www-form-urlencoded';

			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($params, '', '&', PHP_QUERY_RFC3986));
		} else {
			$url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
		}

		curl_setopt_array($curl, [
			CURLOPT_URL            => $url,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
			CURLOPT_TIMEOUT        => self::TIMEOUT,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			// Never follow redirects: they could carry the API key elsewhere.
			CURLOPT_FOLLOWLOCATION => false
		]);

		$body = curl_exec($curl);
		$http = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

		curl_close($curl);

		$label = $method . ' ' . $params['action'];

		if ($body === false) {
			$this->note($label . ' -> CONNECTION_FAILED');

			throw new ApiException('CONNECTION_FAILED');
		}

		$json = json_decode((string)$body, true);

		if (!is_array($json) || !isset($json['status'])) {
			$this->note($label . ' -> BAD_RESPONSE (HTTP ' . $http . ')');

			throw new ApiException('BAD_RESPONSE', $http);
		}

		if ($json['status'] !== 'STATUS_SUCCESS') {
			$code = isset($json['error']) && is_string($json['error']) && $json['error'] !== '' ? $json['error'] : 'SERVER_ERROR';

			$this->note($label . ' -> ' . $code);

			throw new ApiException($code, $http);
		}

		$this->note($label . ' -> STATUS_SUCCESS');

		return $json;
	}

	private function note(string $message): void {
		if ($this->log) {
			($this->log)($message);
		}
	}
}
