<?php
namespace Opencart\System\Library\Extension\Xtreampro;

/**
 * All the business rules of the connector, independent of OpenCart: what is
 * created, renewed, disabled and credited for one bought unit, and how a retry
 * stays harmless. The OpenCart glue (events, controllers, tables) only calls
 * these methods and stores the unit arrays they return.
 *
 * A "unit" is one bought piece: one IPTV line (a product bought with quantity 3
 * has three units) or one sub-reseller order item. It is a plain array:
 *
 *   kind            'line' | 'reseller'
 *   order_id, order_product_id, unit (1 based), customer_id (0 = guest)
 *   status          '' (new) | 'done' | 'failed' | 'revoked'
 *   panel_id        line id / sub-reseller UUID in the panel ('' = not created yet)
 *   username, password   what the panel finally holds (the panel may replace chosen ones)
 *   created         reseller units: 1 when this order created the account
 *   credits         reseller units: credits handed over by this unit and not taken back
 *   generation      counter in the request ids: bumped when work is undone, so redoing it is a new sale
 *   error           last readable error ('' = none)
 *
 * Every method returns the updated unit and never throws for a panel error:
 * the readable message is in $unit['error'] and the status says what is left to
 * do. A retry sends the same request ids, so the panel never charges twice.
 *
 * Nothing secret is logged: only ids, statuses and error codes.
 */
class Provisioner {
	const LINE = 'line';
	const RESELLER = 'reseller';

	const DONE = 'done';
	const FAILED = 'failed';
	const REVOKED = 'revoked';

	private Client $client;
	/** @var callable|null */
	private $log;
	private string $request_prefix;

	/**
	 * @param callable|null $log            function (string $message): void
	 * @param string        $request_prefix start of every request id. It carries a random token of this
	 *                                      shop, so two shops (or a reinstalled one) using the same
	 *                                      reseller key never replay each other's orders.
	 */
	public function __construct(Client $client, ?callable $log = null, string $request_prefix = 'oc') {
		$this->client = $client;
		$this->log = $log;
		$this->request_prefix = $request_prefix;
	}

	public function getClient(): Client {
		return $this->client;
	}

	/**
	 * A unit that has not been worked on yet.
	 */
	public static function newUnit(string $kind, int $order_id, int $order_product_id, int $unit, int $customer_id): array {
		return [
			'kind'             => $kind,
			'order_id'         => $order_id,
			'order_product_id' => $order_product_id,
			'unit'             => $unit,
			'customer_id'      => $customer_id,
			'status'           => '',
			'panel_id'         => '',
			'username'         => '',
			'password'         => '',
			'created'          => 0,
			'credits'          => 0,
			'generation'       => 0,
			'error'            => ''
		];
	}

	// ---- settings page and product form ----------------------------------------------

	/**
	 * Name and credit balance of the reseller behind the API key. Throws ApiException.
	 */
	public function testConnection(): array {
		$info = $this->client->userInfo();

		if (!is_array($info)) {
			throw new ApiException('BAD_RESPONSE');
		}

		return [
			'username' => isset($info['username']) ? (string)$info['username'] : '',
			'credits'  => isset($info['credits']) ? (int)$info['credits'] : 0
		];
	}

	/**
	 * Packages for the product form: list of ['id' => int, 'label' => string].
	 * Throws ApiException.
	 */
	public function packageOptions(): array {
		$options = [];

		foreach ($this->client->packages() as $package) {
			// A package for MAG / Enigma boxes only (`sells` without "line") cannot be sold as a line.
			if (!is_array($package) || !isset($package['id']) || !Client::sellsLine($package)) {
				continue;
			}

			$name = isset($package['name']) ? (string)$package['name'] : '#' . $package['id'];

			if (!empty($package['is_official'])) {
				$detail = (isset($package['official_credits']) ? $package['official_credits'] : '?') . ' credits, ' . (isset($package['official_duration']) ? $package['official_duration'] : '?') . ' ' . (isset($package['official_duration_in']) ? $package['official_duration_in'] : '');
			} else {
				$detail = 'trial only';
			}

			$options[] = ['id' => (int)$package['id'], 'label' => $name . ' (' . trim($detail) . ')'];
		}

		return $options;
	}

	// ---- IPTV lines -----------------------------------------------------------------------

	/**
	 * The order is paid: make sure this unit has an active line.
	 *
	 * @param array $cfg ['package_id' => int, 'trial' => bool]
	 */
	public function provisionLine(array $unit, array $cfg): array {
		if ($unit['status'] === self::DONE) {
			return $unit;
		}

		try {
			// Paid again after a refund: switch the line back on.
			if ($unit['status'] === self::REVOKED && $unit['panel_id'] !== '') {
				try {
					$this->client->lineAction('enable_line', (int)$unit['panel_id']);

					$unit['status'] = self::DONE;
					$unit['error'] = '';

					$this->note($unit, 'line ' . $unit['panel_id'] . ' enabled');

					return $unit;
				} catch (ApiException $e) {
					if ($e->getErrorCode() !== 'RESOURCE_NOT_FOUND') {
						throw $e;
					}

					// The line was deleted in the panel: sell a new one. The new
					// generation gives it a new request id.
					$unit['generation']++;
					$unit['panel_id'] = '';
					$unit['username'] = '';
					$unit['password'] = '';
					$unit['status'] = '';

					$this->note($unit, 'old line is gone, creating a new one');
				}
			}

			if ((int)$cfg['package_id'] <= 0) {
				throw new ApiException('NO_PACKAGE');
			}

			// Ask the panel first: too few credits, a package that is not on sale or one for boxes only fail here
			// with the amounts and nothing is created.
			$this->client->assertCanSellPackage((int)$cfg['package_id'], !empty($cfg['trial']), true);

			// Blank credentials are generated by the panel. The reseller's group may
			// ignore chosen ones anyway, so the final ones are read back below.
			$data = $this->client->createLine((int)$cfg['package_id'], !empty($cfg['trial']), '', '', $this->requestId('line', $unit, true));

			$line = is_array($data) && isset($data['line']) && is_array($data['line']) ? $data['line'] : [];

			if (!isset($line['id'])) {
				throw new ApiException('BAD_RESPONSE');
			}

			$password = isset($line['password']) ? (string)$line['password'] : '';

			if ($password === '' && isset($data['password'])) {
				$password = (string)$data['password'];
			}

			$unit['panel_id'] = (string)(int)$line['id'];
			$unit['username'] = isset($line['username']) ? (string)$line['username'] : '';
			$unit['password'] = $password;
			$unit['status'] = self::DONE;
			$unit['error'] = '';

			$this->note($unit, 'line ' . $unit['panel_id'] . ' created');

			return $unit;
		} catch (ApiException $e) {
			return $this->fail($unit, $e);
		}
	}

	/**
	 * The order is cancelled or refunded: disable the line (it is never deleted).
	 */
	public function revokeLine(array $unit): array {
		if ($unit['panel_id'] === '' || ($unit['status'] === self::REVOKED && $unit['error'] === '')) {
			return $unit;
		}

		try {
			try {
				$this->client->lineAction('disable_line', (int)$unit['panel_id']);
			} catch (ApiException $e) {
				// A line that no longer exists is as good as disabled.
				if ($e->getErrorCode() !== 'RESOURCE_NOT_FOUND') {
					throw $e;
				}
			}

			$unit['status'] = self::REVOKED;
			$unit['error'] = '';

			$this->note($unit, 'line ' . $unit['panel_id'] . ' disabled');

			return $unit;
		} catch (ApiException $e) {
			// Stays as it was, so the next revoke tries again.
			$unit['error'] = $e->getMessage();

			$this->note($unit, 'disabling line ' . $unit['panel_id'] . ' failed: ' . $e->getErrorCode());

			return $unit;
		}
	}

	// ---- sub-reseller accounts ----------------------------------------------------------

	/**
	 * The order is paid: make sure the customer has a sub-reseller account and
	 * that this unit's credits were handed over.
	 *
	 * The first paid sub-reseller order of a customer creates the account; later
	 * ones top up the same account.
	 *
	 * @param array    $cfg      ['credits' => int] credits of the whole item (per unit x quantity)
	 * @param array    $account  the customer's account from an earlier order: ['panel_id' => string, 'username' => string], or []
	 * @param array    $customer ['username_seed' => string, 'email' => string, 'fullname' => string]
	 * @param callable $save     function (array $unit): void, called before the account is created
	 *                           so a retry sends the same credentials
	 */
	public function provisionReseller(array $unit, array $cfg, array $account, array $customer, callable $save): array {
		if ($unit['status'] === self::DONE) {
			return $unit;
		}

		try {
			$credits = $cfg['credits'];

			if (!is_int($credits) || $credits < 0) {
				throw new ApiException('NO_CREDITS_SET');
			}

			if ($unit['customer_id'] <= 0) {
				throw new ApiException('GUEST_NOT_ALLOWED');
			}

			if ($unit['panel_id'] === '') {
				if (!empty($account['panel_id'])) {
					$unit = $this->attachAccount($unit, $account);
				} else {
					// The price of the account plus the credits to hand over: checked before the account exists.
					$this->client->assertCanCreateSubUser($credits);

					$unit = $this->createAccount($unit, $customer, $save);
				}
			} elseif ($unit['created'] && $unit['status'] === self::REVOKED) {
				// Paid again after a refund: the account was disabled by the refund.
				$this->client->userAction('enable_user', $unit['panel_id']);

				$unit['status'] = '';

				$this->note($unit, 'account ' . $unit['panel_id'] . ' enabled');
			} elseif (!$unit['created']) {
				$this->requireActive($unit['panel_id']);
			}

			if ($credits > 0 && (int)$unit['credits'] === 0) {
				$this->client->assertCanGiveCredits($credits);
				$this->client->adjustCredits($unit['panel_id'], $credits, 'Order #' . $unit['order_id'], $this->requestId('subc', $unit, false));

				$unit['credits'] = $credits;

				$this->note($unit, $credits . ' credits given to account ' . $unit['panel_id']);
			}

			$unit['status'] = self::DONE;
			$unit['error'] = '';

			return $unit;
		} catch (ApiException $e) {
			return $this->fail($unit, $e);
		}
	}

	/**
	 * The order is cancelled or refunded: take the unspent credits back and
	 * disable the account when this order created it. The panel has no
	 * "delete" for a sub-reseller the connector would want to use.
	 */
	public function revokeReseller(array $unit): array {
		if ($unit['panel_id'] === '' || ($unit['status'] === self::REVOKED && (int)$unit['credits'] === 0 && $unit['error'] === '')) {
			return $unit;
		}

		$errors = [];

		if ((int)$unit['credits'] > 0) {
			try {
				$this->client->adjustCredits($unit['panel_id'], -(int)$unit['credits'], 'Order #' . $unit['order_id'] . ' refunded', $this->requestId('subx', $unit, false));

				$this->note($unit, $unit['credits'] . ' credits taken back from account ' . $unit['panel_id']);

				$unit['credits'] = 0;
				// The next payment of this unit hands the credits over again.
				$unit['generation']++;
			} catch (ApiException $e) {
				$errors[] = 'Could not take the credits back (they may already be spent): ' . $e->getMessage();

				$this->note($unit, 'taking credits back failed: ' . $e->getErrorCode());
			}
		}

		if ($unit['created']) {
			try {
				$this->client->userAction('disable_user', $unit['panel_id']);

				$this->note($unit, 'account ' . $unit['panel_id'] . ' disabled');
			} catch (ApiException $e) {
				if ($e->getErrorCode() !== 'RESOURCE_NOT_FOUND') {
					$errors[] = 'Could not disable the account: ' . $e->getMessage();

					$this->note($unit, 'disabling account failed: ' . $e->getErrorCode());
				}
			}
		}

		$unit['status'] = self::REVOKED;
		$unit['error'] = implode(' ', $errors);

		return $unit;
	}

	// ---- what the customer and the admin see ----------------------------------------------

	/**
	 * Live view of a line: status, expiry, connections and the play links the
	 * panel returns. Never throws: when the panel cannot be asked, 'live' is
	 * false and 'error' says why (the stored username and password remain valid).
	 */
	public function lineDetails(array $unit): array {
		$details = ['live' => false, 'status' => '', 'expires' => 0, 'max_connections' => 0, 'links' => [], 'error' => ''];

		if ($unit['panel_id'] === '') {
			return $details;
		}

		try {
			$line = $this->client->getLine((int)$unit['panel_id']);

			if (!is_array($line)) {
				throw new ApiException('BAD_RESPONSE');
			}

			$details['live'] = true;
			$details['status'] = isset($line['status']) ? (string)$line['status'] : '';
			$details['expires'] = isset($line['exp_date']) ? (int)$line['exp_date'] : 0;
			$details['max_connections'] = isset($line['max_connections']) ? (int)$line['max_connections'] : 0;
			$details['links'] = isset($line['links']) && is_array($line['links']) ? $line['links'] : [];
		} catch (ApiException $e) {
			$details['error'] = $e->getMessage();
			$details['code'] = $e->getErrorCode();
		}

		return $details;
	}

	/**
	 * lineDetails() for several units; after a failed connection the rest are
	 * not asked (each would wait for the timeout).
	 *
	 * @return array[] details in the order of $units
	 */
	public function lineDetailsList(array $units): array {
		$out = [];
		$down = false;

		foreach ($units as $key => $unit) {
			if ($down) {
				$out[$key] = ['live' => false, 'status' => '', 'expires' => 0, 'max_connections' => 0, 'links' => [], 'error' => ApiException::describe('CONNECTION_FAILED')];

				continue;
			}

			$out[$key] = $this->lineDetails($unit);

			if (isset($out[$key]['code']) && $out[$key]['code'] === 'CONNECTION_FAILED') {
				$down = true;
			}
		}

		return $out;
	}

	/**
	 * Live view of a sub-reseller account: username, status and credit balance.
	 * Never throws; see lineDetails().
	 */
	public function accountDetails(string $panel_id): array {
		$details = ['live' => false, 'username' => '', 'status' => '', 'credits' => 0, 'error' => ''];

		try {
			$user = $this->client->getUser($panel_id);

			if (!is_array($user)) {
				throw new ApiException('BAD_RESPONSE');
			}

			$details['live'] = true;
			$details['username'] = isset($user['username']) ? (string)$user['username'] : '';
			$details['status'] = isset($user['status']) ? (string)$user['status'] : '';
			$details['credits'] = isset($user['credits']) ? (int)$user['credits'] : 0;
		} catch (ApiException $e) {
			$details['error'] = $e->getMessage();
		}

		return $details;
	}

	// ---- internals ---------------------------------------------------------------------------

	/**
	 * Request id of a panel operation, from the stable ids of the shop so a retry
	 * repeats it exactly. Lines add the unit number; the generation makes redoing
	 * undone work a new sale.
	 */
	private function requestId(string $kind, array $unit, bool $per_unit): string {
		$id = $this->request_prefix . '-' . $kind . '-' . (int)$unit['order_id'] . '-' . (int)$unit['order_product_id'];

		if ($per_unit) {
			$id .= '-' . (int)$unit['unit'];
		}

		return $id . '-g' . (int)$unit['generation'];
	}

	private function attachAccount(array $unit, array $account): array {
		$user = $this->requireActive($account['panel_id']);

		$unit['panel_id'] = (string)$user['id'];
		$unit['username'] = isset($user['username']) ? (string)$user['username'] : (string)$account['username'];
		$unit['password'] = '';
		$unit['created'] = 0;

		return $unit;
	}

	/**
	 * The account has to exist and be active: topping up a disabled account
	 * would hand credits to somebody who cannot use them.
	 */
	private function requireActive(string $panel_id): array {
		$user = $this->client->getUser($panel_id);

		if (!is_array($user) || !isset($user['id'])) {
			throw new ApiException('BAD_RESPONSE');
		}

		if (!isset($user['status']) || $user['status'] !== 'active') {
			throw new ApiException('ACCOUNT_DISABLED');
		}

		return $user;
	}

	private function createAccount(array $unit, array $customer, callable $save): array {
		if ($unit['username'] === '' || $unit['password'] === '') {
			$unit['username'] = $this->generateUsername($customer['username_seed']);
			$unit['password'] = $this->randomChars(14, 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789');

			// Stored before the call: a retry must send the same ones.
			$save($unit);
		}

		$fullname = trim((string)$customer['fullname']);

		if ($fullname === '') {
			$fullname = $unit['username'];
		}

		$data = $this->client->createUser($unit['username'], $unit['password'], (string)$customer['email'], substr($fullname, 0, 128), $this->requestId('sub', $unit, false));

		$created = is_array($data) && isset($data['user']) && is_array($data['user']) ? $data['user'] : [];

		if (empty($created['id'])) {
			throw new ApiException('BAD_RESPONSE');
		}

		if (!empty($created['username'])) {
			$unit['username'] = (string)$created['username'];
		}

		if (!empty($data['password'])) {
			$unit['password'] = (string)$data['password'];
		}

		$unit['panel_id'] = (string)$created['id'];
		$unit['created'] = 1;

		$this->note($unit, 'account ' . $unit['panel_id'] . ' created');

		$save($unit);

		return $unit;
	}

	/**
	 * Panel username (3 to 32 letters, digits and _ . -) from the customer's name
	 * plus 4 random characters.
	 */
	private function generateUsername(string $seed): string {
		$base = preg_replace('/[^A-Za-z0-9_.-]/', '', $seed);

		if ($base === '') {
			$base = 'reseller';
		}

		return substr(substr($base, 0, 27) . $this->randomChars(4, 'abcdefghijklmnopqrstuvwxyz0123456789'), 0, 32);
	}

	/**
	 * Characters from a CSPRNG.
	 */
	private function randomChars(int $length, string $alphabet): string {
		$out = '';

		for ($i = 0; $i < $length; $i++) {
			$out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
		}

		return $out;
	}

	private function fail(array $unit, ApiException $e): array {
		$unit['error'] = $e->getMessage();

		// A revoked unit stays revoked: its line or account is still switched off.
		if ($unit['status'] !== self::REVOKED) {
			$unit['status'] = self::FAILED;
		}

		$this->note($unit, 'failed: ' . $e->getErrorCode());

		return $unit;
	}

	private function note(array $unit, string $message): void {
		if ($this->log) {
			($this->log)('order ' . (int)$unit['order_id'] . ' item ' . (int)$unit['order_product_id'] . ' unit ' . (int)$unit['unit'] . ': ' . $message);
		}
	}
}
