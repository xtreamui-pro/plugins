<?php
/**
 * Xtream UI Pro for Dolibarr - hook actions.
 *
 * Hook contexts (declared in the module descriptor): invoicecard. The "Xtream UI Pro" tab of the
 * third-party card is declared in the descriptor ($this->tabs) and drawn by tab_thirdparty.php.
 */

class ActionsXtreampro
{
	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var string[] */
	public $errors = array();

	/** @var array */
	public $results = array();

	/** @var string */
	public $resprints = '';

	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Button on the invoice card: opens the page that shows what the invoice provisioned and
	 * can provision it again. Only drawn when the invoice carries a product of this module.
	 *
	 * @param array        $parameters Hook parameters
	 * @param CommonObject $object     The invoice
	 * @param string       $action     Current action
	 * @param HookManager  $hookmanager Hook manager
	 * @return int 0 = go on with the standard buttons
	 */
	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $user, $langs;

		if (!is_object($object) || $object->element !== 'facture' || empty($parameters['currentcontext']) || strpos($parameters['currentcontext'], 'invoicecard') === false) {
			return 0;
		}
		if (!isModEnabled('xtreampro') || !$user->hasRight('xtreampro', 'read')) {
			return 0;
		}
		// Only for invoices that sold something through this module (or already have records).
		dol_include_once('/xtreampro/class/xtreamproservice.class.php');
		$service = new XtreamproService($this->db);
		$hasRecords = $service->store->listLines(0, (int) $object->id) || $service->store->listCredits(0, (int) $object->id);
		if (!$hasRecords && !$service->invoiceSellsXtreampro($object)) {
			return 0;
		}
		$langs->load('xtreampro@xtreampro');
		print dolGetButtonAction('', $langs->trans('XtreamproModuleName'), 'default', dol_buildpath('/xtreampro/invoice.php', 1) . '?id=' . ((int) $object->id), '', true);
		return 0;
	}
}
