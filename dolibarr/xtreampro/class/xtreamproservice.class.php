<?php
/**
 * Xtream UI Pro for Dolibarr - glue between Dolibarr and the platform independent core.
 *
 * Reads Dolibarr objects (invoices, products, third parties), decides what the
 * provisioner has to do, stores the result in the module's tables and sends the
 * credentials mail. The rules themselves are in lib/core/XtreamproProvisioner.php.
 */

dol_include_once('/xtreampro/lib/core/XtreamproProvisioner.php');
dol_include_once('/xtreampro/class/xtreamprostore.class.php');

class XtreamproService
{
	/** @var DoliDB */
	private $db;

	/** @var XtreamproStore */
	public $store;

	/** @var XtreamproProvisioner|null */
	private $provisioner = null;

	/** @var XtreamproApiClient|null */
	private $client = null;

	/** @var array product id => config */
	private $productCache = array();

	public function __construct($db)
	{
		$this->db = $db;
		$this->store = new XtreamproStore($db);
	}

	// ---- settings -----------------------------------------------------------

	public static function apiUrl()
	{
		return trim((string) getDolGlobalString('XTREAMPRO_API_URL'));
	}

	/** The API key is kept encrypted in the constant; dolDecrypt() returns a value that was never encrypted unchanged. */
	public static function apiKey()
	{
		$stored = (string) getDolGlobalString('XTREAMPRO_API_KEY');
		return $stored === '' ? '' : trim((string) dolDecrypt($stored));
	}

	/** Short id of this Dolibarr installation, part of every request id sent to the panel. */
	public static function instanceId()
	{
		global $conf;
		$unique = isset($conf->file->instance_unique_id) ? (string) $conf->file->instance_unique_id : '';
		return substr(md5($unique !== '' ? $unique : DOL_MAIN_URL_ROOT), 0, 10);
	}

	public function isConfigured()
	{
		return self::apiUrl() !== '' && self::apiKey() !== '';
	}

	/** @throws XtreamproApiException when the URL or the key is missing */
	public function client()
	{
		if ($this->client === null) {
			$this->client = new XtreamproApiClient(self::apiUrl(), self::apiKey());
		}
		return $this->client;
	}

	/** @throws XtreamproApiException */
	public function provisioner()
	{
		if ($this->provisioner === null) {
			$store = $this->store;
			$this->provisioner = new XtreamproProvisioner(
				$this->client(),
				function ($kind, array $record) use ($store) {
					return $store->save($kind, $record);
				},
				function ($message) {
					dol_syslog($message, LOG_INFO);
				},
				self::instanceId()
			);
		}
		return $this->provisioner;
	}

	// ---- product configuration (extrafields) -----------------------------------

	/**
	 * What the product sells, from its extrafields. Null when it is not an Xtream UI Pro product.
	 *
	 * @return array|null kind (line|reseller), package_id, trial, delete_on_terminate, credits
	 */
	private function productConfig($productId)
	{
		$productId = (int) $productId;
		if ($productId <= 0) {
			return null;
		}
		if (!array_key_exists($productId, $this->productCache)) {
			require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
			$product = new Product($this->db);
			$cfg = null;
			if ($product->fetch($productId) > 0) {
				$product->fetch_optionals();
				$o = is_array($product->array_options) ? $product->array_options : array();
				$kind = isset($o['options_xtreampro_kind']) ? (string) $o['options_xtreampro_kind'] : '';
				if ($kind === 'line' || $kind === 'reseller') {
					$cfg = array(
						'kind'                => $kind,
						'package_id'          => (int) (isset($o['options_xtreampro_package_id']) ? $o['options_xtreampro_package_id'] : 0),
						'trial'               => !empty($o['options_xtreampro_trial']) ? 1 : 0,
						// Unset means only disable (deleting is final on the panel and has to be asked for).
						'delete_on_terminate' => !empty($o['options_xtreampro_delete']) ? 1 : 0,
						'credits'             => max(0, (int) (isset($o['options_xtreampro_credits']) ? $o['options_xtreampro_credits'] : 0)),
					);
				}
			}
			$this->productCache[$productId] = $cfg;
		}
		return $this->productCache[$productId];
	}

	/** True when a line of the invoice carries a product that sells through this module. */
	public function invoiceSellsXtreampro($invoice)
	{
		if (empty($invoice->lines)) {
			$invoice->fetch_lines();
		}
		foreach ($invoice->lines as $line) {
			if ($this->productConfig($line->fk_product) !== null) {
				return true;
			}
		}
		return false;
	}

	// ---- invoice events --------------------------------------------------------

	/**
	 * A paid invoice: create the lines (one per unit), the customer's sub-reseller account and
	 * its credits. Safe to call again (a retry): what exists is left alone, what failed is tried
	 * again. Returns human readable notes (also useful as a result message).
	 *
	 * @param Facture $invoice Paid invoice
	 * @return string[] notes, one per thing done or failed
	 */
	public function provisionInvoice($invoice)
	{
		$notes = array();
		if ((int) $invoice->type === 2 || (int) $invoice->type === 3) {
			return $notes;   // credit notes and deposit invoices sell nothing
		}
		if (empty($invoice->lines)) {
			$invoice->fetch_lines();
		}
		$work = array();
		foreach ($invoice->lines as $line) {
			$cfg = $this->productConfig($line->fk_product);
			if ($cfg !== null && (float) $line->qty > 0) {
				$work[] = array($line, $cfg);
			}
		}
		if (!$work) {
			return $notes;
		}
		if (!$this->isConfigured()) {
			dol_syslog('Xtream UI Pro: not configured, invoice ' . $invoice->ref . ' was not provisioned', LOG_WARNING);
			return array('Xtream UI Pro is not configured (API URL and API key), nothing was provisioned.');
		}

		$socid = (int) $invoice->socid;
		$customer = $this->customer($invoice);

		try {
			$p = $this->provisioner();
		} catch (XtreamproApiException $e) {
			return array($e->getMessage());
		}

		foreach ($work as $pair) {
			$line = $pair[0];
			$cfg = $pair[1];
			$label = $invoice->ref . ' / ' . ($line->product_label ?: ($line->product_ref ?: ('#' . $line->fk_product)));

			if ($cfg['kind'] === 'line') {
				$units = max(1, (int) floor((float) $line->qty));
				for ($n = 1; $n <= $units; $n++) {
					$record = $this->store->fetchLineByUnit($line->rowid, $n);
					if ($record === null) {
						$record = $this->store->save('line', array(
							'socid' => $socid, 'invoice_id' => (int) $invoice->id, 'invoice_line_id' => (int) $line->rowid, 'unit' => $n,
							'package_id' => $cfg['package_id'], 'trial' => $cfg['trial'], 'delete_on_terminate' => $cfg['delete_on_terminate'],
							'status' => 'pending',
						));
						if ($record === false) {
							$notes[] = $label . ': the line could not be recorded in the database.';
							break;
						}
					} elseif ($record['status'] === 'terminated') {
						continue;   // terminated by hand: only the "retry" action sells it again
					}
					$done = $p->provisionLine($record);
					$notes[] = $done['error'] === ''
						? sprintf('%s unit %d: line #%d (%s) is ready.', $label, $n, $done['panel_line_id'], $done['username'])
						: sprintf('%s unit %d: not provisioned: %s', $label, $n, $done['error']);
				}
				continue;
			}

			// Sub-reseller account (one per third party) + credits of this invoice line.
			$account = $this->store->fetchResellerBySoc($socid);
			if ($account !== null && $account['status'] === 'terminated' && $this->store->fetchCreditByLine($line->rowid) !== null) {
				continue;   // this invoice line was already handled and the account terminated by hand
			}
			if ($account === null) {
				$account = $this->store->save('reseller', array('socid' => $socid, 'invoice_id' => (int) $invoice->id, 'status' => 'pending'));
			} elseif ($account['status'] === 'terminated') {
				$account['invoice_id'] = (int) $invoice->id;   // a new account is created for this invoice
			}
			if ($account === false) {
				$notes[] = $label . ': the account could not be recorded in the database.';
				continue;
			}
			$account = $p->provisionReseller($account, $customer);
			$notes[] = $account['error'] === ''
				? sprintf('%s: sub-reseller account %s is ready.', $label, $account['username'])
				: sprintf('%s: account not created: %s', $label, $account['error']);

			$credit = $this->store->fetchCreditByLine($line->rowid);
			if ($credit === null) {
				$credit = $this->store->save('credit', array(
					'socid' => $socid, 'invoice_id' => (int) $invoice->id, 'invoice_line_id' => (int) $line->rowid,
					'credits' => $cfg['credits'] * max(1, (int) floor((float) $line->qty)),
				));
			}
			if ($credit === false) {
				$notes[] = $label . ': the credits could not be recorded in the database.';
				continue;
			}
			if ($account['error'] === '' && $credit['credits'] > 0) {
				$credit = $p->topUp($credit, $account);
				$notes[] = $credit['error'] === ''
					? sprintf('%s: %d credits given.', $label, $credit['credited'] - $credit['revoked'])
					: sprintf('%s: credits not given: %s', $label, $credit['error']);
			}
		}
		return $notes;
	}

	/**
	 * The invoice was reversed (unpaid, cancelled or fully credited): disable its lines, take
	 * back its credits and disable the account the invoice created. Never deletes anything.
	 *
	 * @param Facture $invoice Reversed invoice
	 * @return string[] notes
	 */
	public function revokeInvoice($invoice)
	{
		$notes = array();
		$lines = $this->store->listLines(0, (int) $invoice->id);
		$credits = $this->store->listCredits(0, (int) $invoice->id);
		if (!$lines && !$credits) {
			return $notes;
		}
		try {
			$p = $this->provisioner();
		} catch (XtreamproApiException $e) {
			return array($e->getMessage());
		}
		foreach ($lines as $line) {
			$done = $p->revokeLine($line);
			$notes[] = $done['error'] === '' ? sprintf('Line #%d disabled.', $done['panel_line_id']) : sprintf('Line #%d not disabled: %s', $done['panel_line_id'], $done['error']);
		}
		foreach ($credits as $credit) {
			$account = $this->store->fetchResellerBySoc($credit['socid']);
			if ($account === null) {
				continue;
			}
			$done = $p->revokeCredits($credit, $account);
			$notes[] = $done['error'] === ''
				? 'Credits of the invoice taken back.'
				: 'Credits not taken back (they may already be spent): ' . $done['error'];
			if ($account['invoice_id'] === (int) $invoice->id && $account['status'] !== 'terminated') {
				$acc = $p->revokeReseller($account);
				$notes[] = $acc['error'] === '' ? 'Sub-reseller account disabled.' : 'Sub-reseller account not disabled: ' . $acc['error'];
			}
		}
		return $notes;
	}

	/** True for a credit note that cancels the whole of its source invoice. */
	public function creditNoteCoversSource($creditNote)
	{
		if ((int) $creditNote->type !== 2 || (int) $creditNote->fk_facture_source <= 0) {
			return false;
		}
		require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
		$source = new Facture($this->db);
		if ($source->fetch((int) $creditNote->fk_facture_source) <= 0) {
			return false;
		}
		return abs((float) $creditNote->total_ttc) + 0.005 >= (float) $source->total_ttc;
	}

	private function customer($invoice)
	{
		if (empty($invoice->thirdparty) || !is_object($invoice->thirdparty)) {
			$invoice->fetch_thirdparty();
		}
		$soc = $invoice->thirdparty;
		return array('email' => is_object($soc) ? (string) $soc->email : '', 'name' => is_object($soc) ? (string) $soc->name : '');
	}

	// ---- actions from the pages ---------------------------------------------------

	/**
	 * Run an action on a line record: renew, suspend, unsuspend, terminate or retry.
	 *
	 * @return array the updated record (`error` is empty on success), or array('error' => ...) when it does not exist
	 */
	public function lineAction($id, $action)
	{
		$record = $this->store->fetchLine($id);
		if ($record === null) {
			return array('error' => 'Line not found.');
		}
		try {
			$p = $this->provisioner();
		} catch (XtreamproApiException $e) {
			return array_merge($record, array('error' => $e->getMessage()));
		}
		switch ($action) {
			case 'renew':
				return $p->renewLine($record);
			case 'suspend':
				return $p->suspendLine($record);
			case 'unsuspend':
				return $p->unsuspendLine($record);
			case 'terminate':
				return $p->terminateLine($record);
			case 'retry':
				return $p->provisionLine($record);
		}
		return array_merge($record, array('error' => 'Unknown action.'));
	}

	/** Run an action on a sub-reseller account record: suspend, unsuspend, terminate or retry (also tries pending credits again). */
	public function resellerAction($id, $action)
	{
		$record = $this->store->fetchReseller($id);
		if ($record === null) {
			return array('error' => 'Account not found.');
		}
		try {
			$p = $this->provisioner();
		} catch (XtreamproApiException $e) {
			return array_merge($record, array('error' => $e->getMessage()));
		}
		switch ($action) {
			case 'suspend':
				return $p->suspendReseller($record);
			case 'unsuspend':
				return $p->unsuspendReseller($record);
			case 'terminate':
				return $p->terminateReseller($record);
			case 'retry':
				$done = $p->provisionReseller($record, $this->customerOfThirdparty($record['socid']));
				if ($done['error'] === '') {
					foreach ($this->store->listCredits($record['socid']) as $credit) {
						if ($credit['credited'] - $credit['revoked'] < $credit['credits'] && $credit['revoked'] === 0) {
							$c = $p->topUp($credit, $done);
							if ($c['error'] !== '') {
								$done['error'] = $c['error'];
							}
						}
					}
				}
				return $done;
		}
		return array_merge($record, array('error' => 'Unknown action.'));
	}

	private function customerOfThirdparty($socid)
	{
		require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
		$soc = new Societe($this->db);
		if ($soc->fetch((int) $socid) > 0) {
			return array('email' => (string) $soc->email, 'name' => (string) $soc->name);
		}
		return array('email' => '', 'name' => '');
	}

	// ---- credentials mail -----------------------------------------------------------

	/**
	 * Email the credentials of a line or sub-reseller account to the third party's address.
	 * Uses Dolibarr's mail class. Nothing is written to the agenda / event log, so the
	 * password only travels in the mail itself.
	 *
	 * @param string $kind line|reseller
	 * @return string error text, '' when the mail was handed over
	 */
	public function sendCredentials($kind, $id)
	{
		global $langs;
		require_once DOL_DOCUMENT_ROOT . '/core/class/CMailFile.class.php';

		$record = $kind === 'line' ? $this->store->fetchLine($id) : $this->store->fetchReseller($id);
		if ($record === null) {
			return 'Not found.';
		}
		$customer = $this->customerOfThirdparty($record['socid']);
		if ($customer['email'] === '') {
			return 'The third party has no email address.';
		}
		$from = (string) getDolGlobalString('MAIN_MAIL_EMAIL_FROM');
		if ($from === '') {
			return 'No sender address is configured in Home - Setup - Emails.';
		}

		$body = array('Hello ' . $customer['name'] . ',', '');
		if ($kind === 'line') {
			if ($record['panel_line_id'] <= 0 || $record['password'] === '') {
				return 'This line has no credentials to send.';
			}
			$body[] = 'Your IPTV line is ready.';
			$body[] = 'Username: ' . $record['username'];
			$body[] = 'Password: ' . $record['password'];
			try {
				$live = $this->provisioner()->describeLine($record);
			} catch (XtreamproApiException $e) {
				$live = null;   // the mail still goes out with the credentials
			}
			$links = is_array($live) && isset($live['links']) && is_array($live['links']) ? $live['links'] : array();
			foreach (array('server' => 'Server', 'm3u' => 'Playlist (M3U)', 'xmltv' => 'Programme guide (XMLTV)', 'web_player' => 'Web player') as $key => $title) {
				if (!empty($links[$key]) && is_string($links[$key])) {
					$body[] = $title . ': ' . $links[$key];
				}
			}
		} else {
			if ($record['panel_user_id'] === '' || $record['password'] === '') {
				return 'This account has no credentials to send.';
			}
			$body[] = 'Your reseller account is ready.';
			$body[] = 'Username: ' . $record['username'];
			$body[] = 'Password: ' . $record['password'];
			$body[] = 'Panel: ' . self::apiUrl();
		}
		$body[] = '';
		$body[] = 'Keep this message private.';

		$mail = new CMailFile('Your Xtream UI Pro details', $customer['email'], $from, implode("\n", $body), array(), array(), array(), '', '', 0, 0, '', '', 'xtreampro' . $kind . $id);
		if (!$mail->sendfile()) {
			return 'The mail could not be sent: ' . (string) $mail->error;
		}
		dol_syslog('Xtream UI Pro: credentials of ' . $kind . ' ' . ((int) $id) . ' mailed', LOG_INFO);
		return '';
	}
}
