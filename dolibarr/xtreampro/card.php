<?php
/**
 * Xtream UI Pro for Dolibarr - card of one IPTV line or sub-reseller account, with the actions
 * renew / suspend / unsuspend / terminate / retry / mail the credentials.
 *
 * Every action is a POST with a CSRF token, needs the module's "write" right, and terminate
 * asks for confirmation first.
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

require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
dol_include_once('/xtreampro/lib/xtreampro.lib.php');
dol_include_once('/xtreampro/class/xtreamproservice.class.php');

$langs->loadLangs(array('companies', 'bills', 'xtreampro@xtreampro'));

if (!isModEnabled('xtreampro') || !$user->hasRight('xtreampro', 'read') || $user->socid > 0) {
	accessforbidden();
}

$type = GETPOST('type', 'aZ09') === 'reseller' ? 'reseller' : 'line';
$id = (int) GETPOST('id', 'int');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'aZ09');
$canWrite = $user->hasRight('xtreampro', 'write');

$service = new XtreamproService($db);
$record = $type === 'line' ? $service->store->fetchLine($id) : $service->store->fetchReseller($id);
if ($record === null) {
	accessforbidden();
}

$allowed = $type === 'line'
	? array('renew', 'suspend', 'unsuspend', 'terminate', 'retry', 'sendcreds')
	: array('suspend', 'unsuspend', 'terminate', 'retry', 'sendcreds');

// ---- actions ----------------------------------------------------------------------
$base = strpos($action, 'confirm_') === 0 ? substr($action, 8) : $action;
$askConfirm = false;
if ($action !== '' && in_array($base, $allowed, true)) {
	if (!$canWrite) {
		accessforbidden();
	}
	if (!xtreampro_token_ok()) {
		setEventMessages($langs->trans('XtreamproInvalidToken'), null, 'errors');
	} elseif ($action === 'terminate') {
		$askConfirm = true;
	} elseif ($action === 'confirm_terminate' && $confirm !== 'yes') {
		// The confirmation was declined: show the card again.
		$action = '';
	} else {
		if ($base === 'sendcreds') {
			$error = $service->sendCredentials($type, $id);
			if ($error === '') {
				setEventMessages($langs->trans('XtreamproMailSent'), null, 'mesgs');
			} else {
				setEventMessages($error, null, 'errors');
			}
		} else {
			$result = $type === 'line' ? $service->lineAction($id, $base) : $service->resellerAction($id, $base);
			if ($result['error'] === '') {
				setEventMessages($langs->trans('XtreamproActionDone'), null, 'mesgs');
			} else {
				setEventMessages($result['error'], null, 'errors');
			}
		}
		header('Location: ' . xtreampro_card_url($type, $id));
		exit;
	}
}

// ---- view ---------------------------------------------------------------------------
$soc = new Societe($db);
$soc->fetch($record['socid']);
$form = new Form($db);

// Live data from the panel; a failure is shown as a text, never breaks the card.
$live = null;
$liveError = '';
if ($service->isConfigured()) {
	try {
		$p = $service->provisioner();
		$live = $type === 'line' ? $p->describeLine($record) : $p->describeReseller($record);
	} catch (XtreamproApiException $e) {
		$liveError = $e->getMessage();
	}
}

llxHeader('', $langs->trans('XtreamproModuleName'), '', '', 0, 0, '', '', '', 'mod-xtreampro page-card');
print load_fiche_titre($langs->trans($type === 'line' ? 'XtreamproLine' : 'XtreamproResellerAccount') . ' #' . $record['id'], '', 'generic');

if ($askConfirm) {
	print $form->formconfirm(
		$_SERVER['PHP_SELF'] . '?type=' . urlencode($type) . '&id=' . $id,
		$langs->trans('XtreamproTerminate'),
		$langs->trans($type === 'line' ? 'XtreamproConfirmTerminateLine' : 'XtreamproConfirmTerminateReseller'),
		'confirm_terminate',
		'',
		0,
		1
	);
}

print dol_get_fiche_head(array(), '', '', -1, 'generic');
print '<table class="border centpercent tableforfield">';
print '<tr><td class="titlefield">' . $langs->trans('ThirdParty') . '</td><td>' . $soc->getNomUrl(1) . '</td></tr>';
if ($record['invoice_id'] > 0) {
	print '<tr><td>' . $langs->trans('Invoice') . '</td><td><a href="' . xtreampro_h(DOL_URL_ROOT . '/compta/facture/card.php?facid=' . (int) $record['invoice_id']) . '">#' . ((int) $record['invoice_id']) . '</a></td></tr>';
}
print '<tr><td>' . $langs->trans('Status') . '</td><td>' . xtreampro_h(xtreampro_status_label($record['status'])) . ($record['revoked'] ? ' (' . xtreampro_h($langs->transnoentities('XtreamproRevoked')) . ')' : '') . '</td></tr>';
print '<tr><td>' . $langs->trans('XtreamproUsername') . '</td><td>' . xtreampro_h($record['username']) . '</td></tr>';
if ($canWrite) {
	// Only users who may manage the customer's service see the password.
	print '<tr><td>' . $langs->trans('XtreamproPassword') . '</td><td>' . xtreampro_h($record['password']) . '</td></tr>';
}
if ($type === 'line') {
	print '<tr><td>' . $langs->trans('XtreamproPanelLineId') . '</td><td>' . ($record['panel_line_id'] > 0 ? ((int) $record['panel_line_id']) : '') . '</td></tr>';
	print '<tr><td>' . $langs->trans('XtreamproPackageId') . '</td><td>' . ((int) $record['package_id']) . ($record['trial'] ? ' (' . xtreampro_h($langs->transnoentities('XtreamproTrial')) . ')' : '') . '</td></tr>';
	print '<tr><td>' . $langs->trans('XtreamproRenewals') . '</td><td>' . ((int) $record['renewals']) . '</td></tr>';
	if (is_array($live)) {
		print '<tr><td>' . $langs->trans('XtreamproPanelStatus') . '</td><td>' . xtreampro_h(isset($live['status']) ? $live['status'] : '') . '</td></tr>';
		if (!empty($live['exp_date'])) {
			print '<tr><td>' . $langs->trans('XtreamproExpires') . '</td><td>' . xtreampro_h(dol_print_date((int) $live['exp_date'], 'dayhour', 'gmt')) . ' UTC</td></tr>';
		}
		if (isset($live['max_connections'])) {
			print '<tr><td>' . $langs->trans('XtreamproMaxConnections') . '</td><td>' . ((int) $live['max_connections']) . '</td></tr>';
		}
		if ($canWrite && isset($live['links']) && is_array($live['links'])) {
			foreach (array('server' => 'Server', 'm3u' => 'M3U', 'xmltv' => 'XMLTV', 'web_player' => 'Web player') as $key => $title) {
				if (!empty($live['links'][$key]) && is_string($live['links'][$key])) {
					print '<tr><td>' . xtreampro_h($title) . '</td><td class="small wordbreak">' . xtreampro_h($live['links'][$key]) . '</td></tr>';
				}
			}
		}
	}
} else {
	print '<tr><td>' . $langs->trans('XtreamproPanelAccountId') . '</td><td>' . xtreampro_h($record['panel_user_id']) . '</td></tr>';
	print '<tr><td>' . $langs->trans('Email') . '</td><td>' . xtreampro_h($record['email']) . '</td></tr>';
	if (is_array($live)) {
		print '<tr><td>' . $langs->trans('XtreamproPanelStatus') . '</td><td>' . xtreampro_h(isset($live['status']) ? $live['status'] : '') . '</td></tr>';
		print '<tr><td>' . $langs->trans('XtreamproCredits') . '</td><td>' . xtreampro_h(isset($live['credits']) ? $live['credits'] : '') . '</td></tr>';
	}
}
if ($liveError !== '') {
	print '<tr><td>' . $langs->trans('XtreamproPanel') . '</td><td class="error">' . xtreampro_h($liveError) . '</td></tr>';
}
if ($record['error'] !== '') {
	print '<tr><td>' . $langs->trans('XtreamproError') . '</td><td class="error">' . xtreampro_h($record['error']) . '</td></tr>';
}
print '</table>';

if ($type === 'reseller') {
	print '<br><div class="opacitymedium">' . $langs->trans('XtreamproCreditEntries') . '</div>';
	print '<table class="noborder centpercent"><tr class="liste_titre"><td>' . $langs->trans('Invoice') . '</td><td class="right">' . $langs->trans('XtreamproCreditsWanted') . '</td><td class="right">' . $langs->trans('XtreamproCreditsGiven') . '</td><td class="right">' . $langs->trans('XtreamproCreditsTakenBack') . '</td><td>' . $langs->trans('XtreamproError') . '</td></tr>';
	foreach ($service->store->listCredits($record['socid']) as $credit) {
		print '<tr class="oddeven"><td><a href="' . xtreampro_h(DOL_URL_ROOT . '/compta/facture/card.php?facid=' . (int) $credit['invoice_id']) . '">#' . ((int) $credit['invoice_id']) . '</a></td>'
			. '<td class="right">' . ((int) $credit['credits']) . '</td><td class="right">' . ((int) $credit['credited']) . '</td><td class="right">' . ((int) $credit['revoked']) . '</td><td class="small">' . xtreampro_h($credit['error']) . '</td></tr>';
	}
	print '</table>';
}
print dol_get_fiche_end();

// ---- buttons: one POST form each ------------------------------------------------------
if ($canWrite) {
	print '<div class="tabsAction">';
	$buttons = array(
		'renew'     => array('XtreamproRenew', $type === 'line' && $record['panel_line_id'] > 0),
		'suspend'   => array('XtreamproSuspend', $record['status'] === 'active'),
		'unsuspend' => array('XtreamproUnsuspend', $record['status'] === 'suspended'),
		'retry'     => array('XtreamproRetry', true),
		'sendcreds' => array('XtreamproSendCredentials', ($type === 'line' ? $record['panel_line_id'] > 0 : $record['panel_user_id'] !== '') && $record['password'] !== ''),
		'terminate' => array('XtreamproTerminate', $record['status'] !== 'terminated'),
	);
	foreach ($buttons as $name => $def) {
		if (!$def[1]) {
			continue;
		}
		print '<form method="POST" action="' . xtreampro_h($_SERVER['PHP_SELF']) . '" class="inline-block">';
		print '<input type="hidden" name="token" value="' . newToken() . '">';
		print '<input type="hidden" name="type" value="' . xtreampro_h($type) . '"><input type="hidden" name="id" value="' . $id . '">';
		print '<input type="hidden" name="action" value="' . xtreampro_h($name) . '">';
		print '<input type="submit" class="butAction' . ($name === 'terminate' ? ' butActionDelete' : '') . '" value="' . xtreampro_h($langs->trans($def[0])) . '">';
		print '</form>';
	}
	print '</div>';
}

llxFooter();
$db->close();
