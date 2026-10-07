<?php
/**
 * Xtream UI Pro for Dolibarr - provisioner: all the business rules, no Dolibarr.
 *
 * The Dolibarr side (triggers, pages, database tables) only turns events into
 * calls on this class and saves what comes back. Everything here works on plain
 * arrays ("records") and returns the updated record:
 *
 *   line record     id, socid, invoice_id, invoice_line_id, unit, package_id, trial,
 *                   delete_on_terminate, panel_line_id, username, password, status,
 *                   revoked, generation, renewals, error
 *   reseller record id, socid, invoice_id (the invoice that created it), panel_user_id,
 *                   username, password, email, status, revoked, generation, error
 *   credit record   id, socid, invoice_id, invoice_line_id, credits (wanted), credited,
 *                   revoked, cycles, error
 *
 * status is one of pending, active, suspended, terminated, failed.
 * Every method returns the record; a non-empty `error` means the action failed and
 * holds a readable sentence (the state of the record is then unchanged apart from
 * what is needed to retry safely). Methods never throw for panel errors.
 *
 * $persist is called as $persist($kind, $record) with kind line|reseller|credit and must
 * return the saved record (with its `id`). It is called BEFORE a panel call whose result
 * could be lost (a new sub-reseller's credentials) and after every change.
 *
 * Request ids make create / renew / credit moves idempotent in the panel. They are built
 * from the ids of the records plus a "generation" (how often the service was terminated)
 * and a "cycle" counter, so a retry never charges twice while a deliberate second
 * purchase (create after terminate, renew again, credits after a refund) is a new request.
 */

require_once __DIR__ . '/XtreamproApiClient.php';

class XtreamproProvisioner
{
	/** @var XtreamproApiClient */
	private $api;

	/** @var callable */
	private $persist;

	/** @var callable|null */
	private $logger;

	/** @var string Start of every request id: identifies this Dolibarr installation. */
	private $requestPrefix;

	/**
	 * @param XtreamproApiClient $api     Panel client
	 * @param callable           $persist function (string $kind, array $record): array
	 * @param callable|null      $logger  function (string $message): void - receives only masked text
	 * @param string             $instance Short id of this installation (letters, digits). Row ids start at 1 in
	 *                                     every database, so without it a restored database or a second
	 *                                     Dolibarr using the same reseller key would replay old requests.
	 */
	public function __construct(XtreamproApiClient $api, $persist, $logger = null, $instance = '')
	{
		$this->api = $api;
		$this->persist = $persist;
		$this->logger = $logger;
		$instance = substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $instance), 0, 12);
		$this->requestPrefix = 'dol' . ($instance !== '' ? '-' . $instance : '') . '-';
	}

	// ====================================================================
	// IPTV lines
	// ====================================================================

	/**
	 * Create the line in the panel (once). Calling it again for a line that exists does
	 * nothing, except that a line which was disabled because its invoice was reversed
	 * (revoked) is enabled again.
	 */
	public function provisionLine(array $line)
	{
		$line = $this->lineDefaults($line);

		if ($line['panel_line_id'] > 0) {
			if ($line['revoked']) {
				return $this->setLineEnabled($line, true, 'enable_line');
			}
			return $line;
		}
		if ($line['package_id'] <= 0) {
			return $this->fail('line', $line, 'No package is selected on the product.', 'failed');
		}

		try {
			// Ask the panel first: too few credits, a package that is not on sale or one for boxes only fail here
			// with the amounts and nothing is created.
			$this->api->assertCanSellPackage($line['package_id'], $line['trial'], true);
			$this->logCall('pricing');

			// Blank username / password: the panel generates them (and the reseller's group
			// may ignore chosen ones anyway), so the final ones are read back below.
			$data = $this->api->createLine(
				$line['package_id'],
				$line['trial'],
				'',
				'',
				$this->requestPrefix . 'line-' . $line['invoice_line_id'] . '-' . $line['unit'] . '-g' . $line['generation']
			);
			$this->logCall('create_line');
			$created = (is_array($data) && isset($data['line']) && is_array($data['line'])) ? $data['line'] : array();
			if (!isset($created['id'])) {
				throw new XtreamproApiException('BAD_RESPONSE');
			}
			$line['panel_line_id'] = (int) $created['id'];
			$line['username'] = isset($created['username']) ? (string) $created['username'] : '';
			$line['password'] = isset($created['password']) ? (string) $created['password'] : '';
			if ($line['password'] === '' && isset($data['password'])) {
				$line['password'] = (string) $data['password'];
			}
			if ($line['password'] === '') {
				// A replayed request does not return the password again: read the line.
				$full = $this->api->getLine($line['panel_line_id']);
				$this->logCall('get_line');
				if (is_array($full) && isset($full['password'])) {
					$line['password'] = (string) $full['password'];
				}
			}
			$line['status'] = 'active';
			$line['revoked'] = 0;
			$line['error'] = '';
			return $this->save('line', $line);
		} catch (XtreamproApiException $e) {
			$this->logCall('create_line failed');
			return $this->fail('line', $line, $e->getMessage(), 'failed');
		}
	}

	/**
	 * Sell one more period on the line. Costs reseller credits. The request id carries the
	 * number of renewals already done: retrying a failed renewal replays the same request,
	 * renewing again after a success is a new one.
	 */
	public function renewLine(array $line)
	{
		$line = $this->lineDefaults($line);
		if ($line['panel_line_id'] <= 0) {
			return $this->fail('line', $line, 'This line has not been created in the panel yet.');
		}
		try {
			if ($line['package_id'] > 0) {
				// Fails with the amounts when the balance cannot pay the period; nothing is sold then.
				$this->api->assertCanSellPackage($line['package_id'], false, false);
				$this->logCall('pricing');
			}
			$this->api->renewLine($line['panel_line_id'], $this->requestPrefix . 'renew-' . $line['id'] . '-g' . $line['generation'] . '-' . ($line['renewals'] + 1));
			$this->logCall('renew_line');
			$line['renewals']++;
			$line['error'] = '';
			return $this->save('line', $line);
		} catch (XtreamproApiException $e) {
			$this->logCall('renew_line failed');
			return $this->fail('line', $line, $e->getMessage());
		}
	}

	public function suspendLine(array $line)
	{
		return $this->setLineEnabled($this->lineDefaults($line), false, 'disable_line');
	}

	public function unsuspendLine(array $line)
	{
		return $this->setLineEnabled($this->lineDefaults($line), true, 'enable_line');
	}

	/**
	 * The invoice was reversed (unpaid, cancelled, credit note): disable, never delete.
	 * Remembered in `revoked` so that paying the invoice again enables the line again.
	 */
	public function revokeLine(array $line)
	{
		$line = $this->lineDefaults($line);
		if ($line['panel_line_id'] <= 0 || $line['status'] === 'terminated') {
			return $line;
		}
		$line = $this->setLineEnabled($line, false, 'disable_line');
		if ($line['error'] === '') {
			$line['revoked'] = 1;
			$line = $this->save('line', $line);
		}
		return $line;
	}

	/**
	 * Delete (or only disable, per product setting) the line and forget it. The
	 * generation goes up so that a later "retry" sells a new line.
	 */
	public function terminateLine(array $line)
	{
		$line = $this->lineDefaults($line);
		if ($line['panel_line_id'] <= 0 && $line['status'] === 'terminated') {
			return $line;
		}
		try {
			if ($line['panel_line_id'] > 0) {
				$action = $line['delete_on_terminate'] ? 'delete_line' : 'disable_line';
				try {
					$this->api->lineAction($action, $line['panel_line_id']);
					$this->logCall($action);
				} catch (XtreamproApiException $e) {
					// A line that is already gone from the panel is terminated.
					if ($e->getErrorCode() !== 'RESOURCE_NOT_FOUND') {
						throw $e;
					}
				}
			}
		} catch (XtreamproApiException $e) {
			$this->logCall('terminate failed');
			return $this->fail('line', $line, $e->getMessage());
		}
		$line['panel_line_id'] = 0;
		$line['password'] = '';
		$line['status'] = 'terminated';
		$line['revoked'] = 0;
		$line['renewals'] = 0;
		$line['generation']++;
		$line['error'] = '';
		return $this->save('line', $line);
	}

	/**
	 * Live data of the line from the panel (status, expiry, links...).
	 *
	 * @return array|null null when the line has no panel id
	 * @throws XtreamproApiException
	 */
	public function describeLine(array $line)
	{
		$line = $this->lineDefaults($line);
		if ($line['panel_line_id'] <= 0) {
			return null;
		}
		$data = $this->api->getLine($line['panel_line_id']);
		$this->logCall('get_line');
		return is_array($data) ? $data : null;
	}

	private function setLineEnabled(array $line, $enable, $action)
	{
		if ($line['panel_line_id'] <= 0) {
			return $this->fail('line', $line, 'This line has not been created in the panel yet.');
		}
		try {
			$this->api->lineAction($action, $line['panel_line_id']);
			$this->logCall($action);
		} catch (XtreamproApiException $e) {
			$this->logCall($action . ' failed');
			return $this->fail('line', $line, $e->getMessage());
		}
		$line['status'] = $enable ? 'active' : 'suspended';
		if ($enable) {
			$line['revoked'] = 0;
		}
		$line['error'] = '';
		return $this->save('line', $line);
	}

	// ====================================================================
	// Sub-reseller accounts (one per customer) and their credits
	// ====================================================================

	/**
	 * Create the customer's sub-reseller account (once).
	 *
	 * @param array $account  reseller record
	 * @param array $customer email, name
	 */
	public function provisionReseller(array $account, array $customer)
	{
		$account = $this->resellerDefaults($account);

		if ($account['panel_user_id'] !== '') {
			if ($account['revoked']) {
				return $this->setResellerEnabled($account, true, 'enable_user');
			}
			return $account;
		}

		$email = trim(isset($customer['email']) ? (string) $customer['email'] : '');
		if ($email === '' || strpos($email, '@') === false) {
			return $this->fail('reseller', $account, 'The third party has no valid email address, which the panel needs for a sub-reseller account.', 'failed');
		}
		// The panel keeps emails unique and the disabled account of a terminated service still
		// holds its address: a new generation uses a tagged address (user+g1@host).
		if ($account['generation'] > 0) {
			$at = strrpos($email, '@');
			$email = substr($email, 0, $at) . '+g' . $account['generation'] . substr($email, $at);
		}
		$name = trim(isset($customer['name']) ? (string) $customer['name'] : '');

		if ($account['username'] === '') {
			$account['username'] = 'r' . $account['socid'] . self::random(6, 'abcdefghijklmnopqrstuvwxyz');
		}
		$account['username'] = substr($account['username'], 0, 32);
		if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $account['username'])) {
			return $this->fail('reseller', $account, 'The username "' . $account['username'] . '" is not valid for a sub-reseller: use 3 to 32 letters, digits, "_", "." or "-".', 'failed');
		}
		if ($account['password'] === '') {
			$account['password'] = self::random(14, 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789');
		}
		$account['email'] = $email;
		// Keep the credentials BEFORE the panel is called: the panel stores only a hash, so a
		// repeated call (timeout, failed credit move) must send the very same values.
		$account = $this->save('reseller', $account);

		try {
			// The reseller's group must be allowed to create sub-resellers and the balance must cover the account price.
			$this->api->assertCanCreateSubUser(0);
			$this->logCall('pricing');
			$data = $this->api->createSubUser(
				$account['username'],
				$account['password'],
				$email,
				$name !== '' ? substr($name, 0, 128) : $account['username'],
				$this->requestPrefix . 'sub-' . $account['socid'] . '-g' . $account['generation']
			);
			$this->logCall('create_user');
			$user = (is_array($data) && isset($data['user']) && is_array($data['user'])) ? $data['user'] : array();
			if (!isset($user['id'])) {
				throw new XtreamproApiException('BAD_RESPONSE');
			}
			$account['panel_user_id'] = (string) $user['id'];
			if (!empty($user['username'])) {
				$account['username'] = (string) $user['username'];
			}
			if (!empty($data['password'])) {
				$account['password'] = (string) $data['password'];
			}
			$account['status'] = 'active';
			$account['revoked'] = 0;
			$account['error'] = '';
			return $this->save('reseller', $account);
		} catch (XtreamproApiException $e) {
			$this->logCall('create_user failed');
			return $this->fail('reseller', $account, $e->getMessage(), 'failed');
		}
	}

	/**
	 * Give the credits of one invoice line to the account (once; idempotent). Returns the
	 * credit record.
	 */
	public function topUp(array $credit, array $account)
	{
		$credit = $this->creditDefaults($credit);
		$account = $this->resellerDefaults($account);
		if ($credit['credits'] <= 0) {
			return $credit;
		}
		$net = $credit['credited'] - $credit['revoked'];
		if ($net >= $credit['credits']) {
			return $credit;
		}
		if ($account['panel_user_id'] === '') {
			return $this->fail('credit', $credit, 'The sub-reseller account does not exist in the panel yet.');
		}
		$amount = $credit['credits'] - $net;
		try {
			$this->api->assertCanGiveCredits($amount);
			$this->logCall('pricing');
			$this->api->adjustCredits(
				$account['panel_user_id'],
				$amount,
				'Dolibarr invoice line #' . $credit['invoice_line_id'],
				$this->requestPrefix . 'subc-' . $credit['id'] . '-g' . $account['generation'] . '-c' . $credit['cycles']
			);
			$this->logCall('adjust_credits');
			$credit['credited'] += $amount;
			$credit['error'] = '';
			return $this->save('credit', $credit);
		} catch (XtreamproApiException $e) {
			$this->logCall('adjust_credits failed');
			return $this->fail('credit', $credit, $e->getMessage());
		}
	}

	/**
	 * Take back the credits that were given for an invoice line. When the account has
	 * already spent them the panel refuses (INSUFFICIENT_CREDITS) and the error is kept.
	 */
	public function revokeCredits(array $credit, array $account)
	{
		$credit = $this->creditDefaults($credit);
		$account = $this->resellerDefaults($account);
		$net = $credit['credited'] - $credit['revoked'];
		if ($net <= 0) {
			return $credit;
		}
		if ($account['panel_user_id'] === '') {
			return $this->fail('credit', $credit, 'The sub-reseller account does not exist in the panel any more.');
		}
		try {
			$this->api->adjustCredits(
				$account['panel_user_id'],
				-$net,
				'Dolibarr invoice line #' . $credit['invoice_line_id'] . ' reversed',
				$this->requestPrefix . 'subx-' . $credit['id'] . '-g' . $account['generation'] . '-c' . $credit['cycles']
			);
			$this->logCall('adjust_credits');
			$credit['revoked'] += $net;
			$credit['cycles']++;
			$credit['error'] = '';
			return $this->save('credit', $credit);
		} catch (XtreamproApiException $e) {
			$this->logCall('adjust_credits failed');
			return $this->fail('credit', $credit, $e->getMessage());
		}
	}

	public function suspendReseller(array $account)
	{
		return $this->setResellerEnabled($this->resellerDefaults($account), false, 'disable_user');
	}

	public function unsuspendReseller(array $account)
	{
		return $this->setResellerEnabled($this->resellerDefaults($account), true, 'enable_user');
	}

	/** The invoice that created the account was reversed: disable it (remembered in `revoked`). */
	public function revokeReseller(array $account)
	{
		$account = $this->resellerDefaults($account);
		if ($account['panel_user_id'] === '' || $account['status'] === 'terminated') {
			return $account;
		}
		$account = $this->setResellerEnabled($account, false, 'disable_user');
		if ($account['error'] === '') {
			$account['revoked'] = 1;
			$account = $this->save('reseller', $account);
		}
		return $account;
	}

	/**
	 * Disable the account (the panel's delete is final and hands everything to the reseller,
	 * so it is never used) and forget it: a later invoice creates a new account.
	 */
	public function terminateReseller(array $account)
	{
		$account = $this->resellerDefaults($account);
		if ($account['panel_user_id'] === '' && $account['status'] === 'terminated') {
			return $account;
		}
		if ($account['panel_user_id'] !== '') {
			try {
				$this->api->subUserAction('disable_user', $account['panel_user_id']);
				$this->logCall('disable_user');
			} catch (XtreamproApiException $e) {
				if ($e->getErrorCode() !== 'RESOURCE_NOT_FOUND') {
					$this->logCall('disable_user failed');
					return $this->fail('reseller', $account, $e->getMessage());
				}
			}
		}
		$account['panel_user_id'] = '';
		$account['password'] = '';
		$account['username'] = '';
		$account['status'] = 'terminated';
		$account['revoked'] = 0;
		$account['generation']++;
		$account['error'] = '';
		return $this->save('reseller', $account);
	}

	/**
	 * Live data of the account from the panel (status, credits...).
	 *
	 * @return array|null null when the account has no panel id
	 * @throws XtreamproApiException
	 */
	public function describeReseller(array $account)
	{
		$account = $this->resellerDefaults($account);
		if ($account['panel_user_id'] === '') {
			return null;
		}
		$data = $this->api->getUser($account['panel_user_id']);
		$this->logCall('get_user');
		return is_array($data) ? $data : null;
	}

	private function setResellerEnabled(array $account, $enable, $action)
	{
		if ($account['panel_user_id'] === '') {
			return $this->fail('reseller', $account, 'The sub-reseller account does not exist in the panel yet.');
		}
		try {
			$this->api->subUserAction($action, $account['panel_user_id']);
			$this->logCall($action);
		} catch (XtreamproApiException $e) {
			$this->logCall($action . ' failed');
			return $this->fail('reseller', $account, $e->getMessage());
		}
		$account['status'] = $enable ? 'active' : 'suspended';
		if ($enable) {
			$account['revoked'] = 0;
		}
		$account['error'] = '';
		return $this->save('reseller', $account);
	}

	// ====================================================================
	// Internals
	// ====================================================================

	private function lineDefaults(array $line)
	{
		$line += array(
			'id' => 0, 'socid' => 0, 'invoice_id' => 0, 'invoice_line_id' => 0, 'unit' => 1,
			'package_id' => 0, 'trial' => 0, 'delete_on_terminate' => 0, 'panel_line_id' => 0,
			'username' => '', 'password' => '', 'status' => 'pending', 'revoked' => 0,
			'generation' => 0, 'renewals' => 0, 'error' => '',
		);
		foreach (array('id', 'socid', 'invoice_id', 'invoice_line_id', 'unit', 'package_id', 'panel_line_id', 'revoked', 'generation', 'renewals') as $k) {
			$line[$k] = (int) $line[$k];
		}
		$line['trial'] = !empty($line['trial']);
		$line['delete_on_terminate'] = !empty($line['delete_on_terminate']);
		return $line;
	}

	private function resellerDefaults(array $account)
	{
		$account += array(
			'id' => 0, 'socid' => 0, 'invoice_id' => 0, 'panel_user_id' => '', 'username' => '', 'password' => '',
			'email' => '', 'status' => 'pending', 'revoked' => 0, 'generation' => 0, 'error' => '',
		);
		foreach (array('id', 'socid', 'invoice_id', 'revoked', 'generation') as $k) {
			$account[$k] = (int) $account[$k];
		}
		$account['panel_user_id'] = (string) $account['panel_user_id'];
		return $account;
	}

	private function creditDefaults(array $credit)
	{
		$credit += array(
			'id' => 0, 'socid' => 0, 'invoice_id' => 0, 'invoice_line_id' => 0,
			'credits' => 0, 'credited' => 0, 'revoked' => 0, 'cycles' => 0, 'error' => '',
		);
		foreach (array('id', 'socid', 'invoice_id', 'invoice_line_id', 'credits', 'credited', 'revoked', 'cycles') as $k) {
			$credit[$k] = (int) $credit[$k];
		}
		return $credit;
	}

	private function save($kind, array $record)
	{
		$saved = call_user_func($this->persist, $kind, $record);
		return is_array($saved) ? $saved : $record;
	}

	/** Record the error text on the record and keep it. */
	private function fail($kind, array $record, $message, $status = null)
	{
		$record['error'] = (string) $message;
		if ($status !== null) {
			$record['status'] = $status;
		}
		return $this->save($kind, $record);
	}

	/** Log the last API call: the client already masked the key and every password. */
	private function logCall($what)
	{
		if ($this->logger === null) {
			return;
		}
		$request = $this->api->lastRequest;
		call_user_func(
			$this->logger,
			'Xtream UI Pro ' . $what . ': ' . json_encode($request === null ? array() : $request['params'])
			. ' -> ' . (string) $this->api->lastResponse
		);
	}

	/** Random string from a fixed alphabet, using random_int. */
	private static function random($length, $alphabet)
	{
		$out = '';
		$max = strlen($alphabet) - 1;
		for ($i = 0; $i < $length; $i++) {
			$out .= $alphabet[random_int(0, $max)];
		}
		return $out;
	}
}
