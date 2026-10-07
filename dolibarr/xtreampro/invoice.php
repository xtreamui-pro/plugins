<?php
/**
 * Xtream UI Pro for Dolibarr - what a (paid) invoice provisioned, and a button to provision it
 * again (retry after a failure, or for an invoice that was paid before the module was enabled).
 * Reached from the "Xtream UI Pro" button on the invoice card.
 */

// Load Dolibarr environment (the module lives in htdocs/custom/xtreampro)
$res = 0;
if (!$res && !empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
	$res = @include $_SERVER['CONTEXT_DOCUMENT_ROOT'] . '/main.inc.php';
}
if (!$res && file_exists('../main.inc.php')) {
	$res = @include '../main.inc.php';
}
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
dol_include_once('/xtreampro/lib/xtreampro.lib.php');
dol_include_once('/xtreampro/class/xtreamproservice.class.php');

$langs->loadLangs(array('companies', 'bills', 'xtreampro@xtreampro'));

if (!isModEnabled('xtreampro') || !$user->hasRight('xtreampro', 'read') || !$user->hasRight('facture', 'lire') || $user->socid > 0) {
	accessforbidden();
}

$id = (int) GETPOST('id', 'int');
$invoice = new Facture($db);
if ($id <= 0 || $invoice->fetch($id) <= 0) {
	accessforbidden();
}
$action = GETPOST('action', 'aZ09');
$notes = array();

if ($action === 'provision') {
	if (!$user->hasRight('xtreampro', 'write')) {
		accessforbidden();
	}
	if (!xtreampro_token_ok()) {
		setEventMessages($langs->trans('XtreamproInvalidToken'), null, 'errors');
	} elseif ((int) $invoice->statut !== Facture::STATUS_CLOSED) {
		setEventMessages($langs->trans('XtreamproInvoiceNotPaid'), null, 'errors');
	} else {
		$service = new XtreamproService($db);
		$notes = $service->provisionInvoice($invoice);
		if (!$notes) {
			$notes[] = $langs->transnoentities('XtreamproNothingToProvision');
		}
	}
}

llxHeader('', $langs->trans('XtreamproModuleName'), '', '', 0, 0, '', '', '', 'mod-xtreampro page-invoice');
print load_fiche_titre($langs->trans('XtreamproModuleName') . ' - ' . xtreampro_h($invoice->ref), '', 'generic');
print '<a href="' . xtreampro_h(DOL_URL_ROOT . '/compta/facture/card.php?facid=' . $id) . '">' . $langs->trans('Invoice') . ' ' . xtreampro_h($invoice->ref) . '</a><br><br>';

if ($notes) {
	print '<div class="info"><ul>';
	foreach ($notes as $note) {
		print '<li>' . xtreampro_h($note) . '</li>';
	}
	print '</ul></div>';
}

$store = new XtreamproStore($db);
print '<table class="noborder centpercent"><tr class="liste_titre"><td>' . $langs->trans('XtreamproLine') . '</td><td>' . $langs->trans('Status') . '</td><td>' . $langs->trans('XtreamproError') . '</td></tr>';
foreach ($store->listLines(0, $id) as $line) {
	print '<tr class="oddeven"><td><a href="' . xtreampro_h(xtreampro_card_url('line', $line['id'])) . '">#' . ((int) $line['id']) . ' ' . xtreampro_h($line['username']) . '</a></td><td>'
		. xtreampro_h(xtreampro_status_label($line['status'])) . '</td><td class="small">' . xtreampro_h($line['error']) . '</td></tr>';
}
foreach ($store->listCredits(0, $id) as $credit) {
	print '<tr class="oddeven"><td>' . $langs->trans('XtreamproCredits') . ' ' . ((int) $credit['credited'] - (int) $credit['revoked']) . ' / ' . ((int) $credit['credits']) . '</td><td></td><td class="small">' . xtreampro_h($credit['error']) . '</td></tr>';
}
print '</table>';

if ($user->hasRight('xtreampro', 'write') && (int) $invoice->statut === Facture::STATUS_CLOSED) {
	print '<div class="tabsAction"><form method="POST" action="' . xtreampro_h($_SERVER['PHP_SELF']) . '">';
	print '<input type="hidden" name="token" value="' . newToken() . '"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="action" value="provision">';
	print '<input type="submit" class="butAction" value="' . xtreampro_h($langs->trans('XtreamproProvisionNow')) . '"></form></div>';
}

llxFooter();
$db->close();
