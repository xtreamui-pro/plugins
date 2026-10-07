<?php
/**
 * Xtream UI Pro for Dolibarr - helpers shared by the pages.
 */

/**
 * CSRF check for every state changing request of this module. Dolibarr checks the token
 * itself on most installations (MAIN_SECURITY_CSRF_WITH_TOKEN); this is the module's own
 * check, so a setup that turned the global one off is still protected.
 *
 * @return bool
 */
function xtreampro_token_ok()
{
	$given = (string) GETPOST('token', 'alphanohtml');
	if ($given === '') {
		return false;
	}
	$accepted = array();
	if (function_exists('currentToken')) {
		$accepted[] = (string) currentToken();
	}
	if (!empty($_SESSION['token'])) {
		$accepted[] = (string) $_SESSION['token'];
	}
	if (!empty($_SESSION['newtoken'])) {
		$accepted[] = (string) $_SESSION['newtoken'];
	}
	foreach ($accepted as $token) {
		if ($token !== '' && hash_equals($token, $given)) {
			return true;
		}
	}
	return false;
}

/**
 * Tabs of the setup page.
 *
 * @return array
 */
function xtreampro_admin_prepare_head()
{
	global $langs;
	$h = 0;
	$head = array();
	$head[$h][0] = dol_buildpath('/xtreampro/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('XtreamproSetup');
	$head[$h][2] = 'settings';
	return $head;
}

/** HTML escape for output. */
function xtreampro_h($value)
{
	return dol_escape_htmltag((string) $value);
}

/** Readable text of a status. */
function xtreampro_status_label($status)
{
	global $langs;
	$keys = array(
		'pending' => 'XtreamproStatusPending', 'active' => 'XtreamproStatusActive', 'suspended' => 'XtreamproStatusSuspended',
		'terminated' => 'XtreamproStatusTerminated', 'failed' => 'XtreamproStatusFailed',
	);
	return isset($keys[$status]) ? $langs->trans($keys[$status]) : (string) $status;
}

/** URL of a card page. */
function xtreampro_card_url($kind, $id)
{
	return dol_buildpath('/xtreampro/card.php', 1) . '?type=' . urlencode($kind) . '&id=' . ((int) $id);
}

/**
 * Print the list of lines and the list of sub-reseller accounts.
 * The password is never part of a list.
 *
 * @param DoliDB $db     Database
 * @param int    $socid  Show only this third party (0 = all)
 * @param string $status Show only this status ('' = all)
 * @param string $type   '' = both, line, reseller
 */
function xtreampro_print_lists($db, $socid = 0, $status = '', $type = '')
{
	global $langs;
	require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
	dol_include_once('/xtreampro/class/xtreamprostore.class.php');
	$store = new XtreamproStore($db);
	$societes = array();
	$thirdparty = function ($id) use ($db, &$societes) {
		if (!isset($societes[$id])) {
			$soc = new Societe($db);
			$societes[$id] = $soc->fetch($id) > 0 ? $soc->getNomUrl(1) : xtreampro_h('#' . $id);
		}
		return $societes[$id];
	};
	$invoiceUrl = DOL_URL_ROOT . '/compta/facture/card.php?facid=';

	if ($type === '' || $type === 'line') {
		$lines = $store->listLines($socid, 0, $status);
		print '<div class="div-table-responsive"><table class="tagtable liste">';
		print '<tr class="liste_titre"><th>' . $langs->trans('XtreamproLine') . '</th>';
		if (!$socid) {
			print '<th>' . $langs->trans('ThirdParty') . '</th>';
		}
		print '<th>' . $langs->trans('Invoice') . '</th><th>' . $langs->trans('XtreamproUsername') . '</th><th>' . $langs->trans('Status') . '</th><th>' . $langs->trans('XtreamproError') . '</th></tr>';
		if (!$lines) {
			print '<tr class="oddeven"><td colspan="' . ($socid ? 5 : 6) . '" class="opacitymedium">' . $langs->trans('XtreamproNone') . '</td></tr>';
		}
		foreach ($lines as $line) {
			print '<tr class="oddeven"><td><a href="' . xtreampro_h(xtreampro_card_url('line', $line['id'])) . '">'
				. xtreampro_h('#' . $line['id'] . ($line['panel_line_id'] > 0 ? ' (panel ' . $line['panel_line_id'] . ')' : '')) . '</a></td>';
			if (!$socid) {
				print '<td>' . $thirdparty($line['socid']) . '</td>';
			}
			print '<td><a href="' . xtreampro_h($invoiceUrl . (int) $line['invoice_id']) . '">' . xtreampro_h('#' . $line['invoice_id']) . '</a></td>';
			print '<td>' . xtreampro_h($line['username']) . '</td><td>' . xtreampro_h(xtreampro_status_label($line['status']) . ($line['revoked'] ? ' (' . $langs->transnoentities('XtreamproRevoked') . ')' : '')) . '</td>';
			print '<td class="small">' . xtreampro_h($line['error']) . '</td></tr>';
		}
		print '</table></div><br>';
	}

	if ($type === '' || $type === 'reseller') {
		$accounts = $store->listResellers($socid, $status);
		print '<div class="div-table-responsive"><table class="tagtable liste">';
		print '<tr class="liste_titre"><th>' . $langs->trans('XtreamproResellerAccount') . '</th>';
		if (!$socid) {
			print '<th>' . $langs->trans('ThirdParty') . '</th>';
		}
		print '<th>' . $langs->trans('XtreamproUsername') . '</th><th>' . $langs->trans('Status') . '</th><th>' . $langs->trans('XtreamproError') . '</th></tr>';
		if (!$accounts) {
			print '<tr class="oddeven"><td colspan="' . ($socid ? 4 : 5) . '" class="opacitymedium">' . $langs->trans('XtreamproNone') . '</td></tr>';
		}
		foreach ($accounts as $account) {
			print '<tr class="oddeven"><td><a href="' . xtreampro_h(xtreampro_card_url('reseller', $account['id'])) . '">' . xtreampro_h('#' . $account['id']) . '</a></td>';
			if (!$socid) {
				print '<td>' . $thirdparty($account['socid']) . '</td>';
			}
			print '<td>' . xtreampro_h($account['username']) . '</td><td>' . xtreampro_h(xtreampro_status_label($account['status']) . ($account['revoked'] ? ' (' . $langs->transnoentities('XtreamproRevoked') . ')' : '')) . '</td>';
			print '<td class="small">' . xtreampro_h($account['error']) . '</td></tr>';
		}
		print '</table></div>';
	}
}
