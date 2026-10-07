<?php
/**
 * Xtream UI Pro for Dolibarr - triggers.
 *
 *   BILL_PAYED     provision the lines / sub-reseller account / credits of the paid invoice
 *   BILL_UNPAYED   disable them and take the unspent credits back
 *   BILL_CANCEL    same (invoice abandoned)
 *   BILL_VALIDATE  a credit note that cancels the whole source invoice: same
 *
 * A provisioning problem never blocks the payment: it is recorded on the line / account (see the
 * Xtream UI Pro tab of the third party and the "Xtream UI Pro" button of the invoice) and the
 * trigger still returns 0.
 */

require_once DOL_DOCUMENT_ROOT . '/core/triggers/dolibarrtriggers.class.php';

class InterfaceXtreamproTriggers extends DolibarrTriggers
{
	/** @var DoliDB */
	protected $db;

	public function __construct($db)
	{
		$this->db = $db;
		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = 'financial';
		$this->description = 'Xtream UI Pro triggers: provision and revoke IPTV lines and sub-reseller accounts with invoices.';
		$this->version = '1.0.0';
		$this->picto = 'generic';
	}

	public function getName()
	{
		return $this->name;
	}

	public function getDesc()
	{
		return $this->description;
	}

	/**
	 * @param string       $action Event code
	 * @param CommonObject $object The invoice
	 * @param User         $user   Acting user
	 * @param Translate    $langs  Language object
	 * @param Conf         $conf   Configuration
	 * @return int 0 = nothing to do or done; never < 0 (a payment must not fail because of the panel)
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('xtreampro')) {
			return 0;
		}
		if (!in_array($action, array('BILL_PAYED', 'BILL_UNPAYED', 'BILL_CANCEL', 'BILL_VALIDATE'), true)) {
			return 0;
		}

		try {
			dol_include_once('/xtreampro/class/xtreamproservice.class.php');
			$service = new XtreamproService($this->db);
			$notes = array();

			if ($action === 'BILL_PAYED') {
				if (!getDolGlobalString('XTREAMPRO_AUTO_PROVISION', '1')) {
					return 0;
				}
				$notes = $service->provisionInvoice($object);
			} elseif ($action === 'BILL_UNPAYED' || $action === 'BILL_CANCEL') {
				$notes = $service->revokeInvoice($object);
			} elseif ($object->type == 2 && $service->creditNoteCoversSource($object)) {
				// BILL_VALIDATE of a credit note that cancels the whole invoice it refers to.
				$source = new Facture($this->db);
				if ($source->fetch((int) $object->fk_facture_source) > 0) {
					$notes = $service->revokeInvoice($source);
				}
			}

			foreach ($notes as $note) {
				dol_syslog('Xtream UI Pro: ' . $note, LOG_INFO);
			}
			if ($notes && function_exists('setEventMessages')) {
				setEventMessages('Xtream UI Pro: ' . implode(' ', $notes), null, 'mesgs');
			}
		} catch (Throwable $e) {
			dol_syslog('Xtream UI Pro trigger ' . $action . ' failed: ' . $e->getMessage(), LOG_ERR);
		}
		return 0;
	}
}
