<?php
/**
 * Xtream UI Pro for Dolibarr - setup page (API URL, API key, test connection).
 *
 * The API key is stored encrypted (dolEncrypt) in the constant XTREAMPRO_API_KEY and is never
 * written back into the page: the field is always empty, and an empty field keeps the saved key.
 */

// Load Dolibarr environment (the module lives in htdocs/custom/xtreampro/admin)
$res = 0;
if (!$res && !empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
	$res = @include $_SERVER['CONTEXT_DOCUMENT_ROOT'] . '/main.inc.php';
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . '/main.inc.php')) {
	$res = @include substr($tmp, 0, ($i + 1)) . '/main.inc.php';
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . '/main.inc.php')) {
	$res = @include dirname(substr($tmp, 0, ($i + 1))) . '/main.inc.php';
}
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
	$res = @include '../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
dol_include_once('/xtreampro/lib/xtreampro.lib.php');
dol_include_once('/xtreampro/class/xtreamproservice.class.php');

$langs->loadLangs(array('admin', 'xtreampro@xtreampro'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$packages = null;
$packagesError = '';

if ($action === 'save' || $action === 'test') {
	if (!xtreampro_token_ok()) {
		setEventMessages($langs->trans('XtreamproInvalidToken'), null, 'errors');
		$action = '';
	}
}

if ($action === 'save') {
	$url = rtrim(trim((string) GETPOST('api_url', 'alphanohtml')), '/');
	$key = trim((string) GETPOST('api_key', 'none'));   // a secret: no HTML filtering, it is never printed
	$auto = GETPOST('auto_provision', 'int') ? 1 : 0;
	$parts = parse_url($url);
	if ($url !== '' && ($parts === false || empty($parts['host']) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), array('http', 'https'), true))) {
		setEventMessages($langs->trans('XtreamproBadUrl'), null, 'errors');
	} else {
		$error = 0;
		$error += dolibarr_set_const($db, 'XTREAMPRO_API_URL', $url, 'chaine', 0, '', $conf->entity) > 0 ? 0 : 1;
		$error += dolibarr_set_const($db, 'XTREAMPRO_AUTO_PROVISION', (string) $auto, 'chaine', 0, '', $conf->entity) > 0 ? 0 : 1;
		// Empty or the mask = keep the key that is saved.
		if ($key !== '' && $key !== '********') {
			$error += dolibarr_set_const($db, 'XTREAMPRO_API_KEY', dolEncrypt($key), 'chaine', 0, '', $conf->entity) > 0 ? 0 : 1;
		}
		setEventMessages($error ? $langs->trans('Error') : $langs->trans('SetupSaved'), null, $error ? 'errors' : 'mesgs');
	}
	header('Location: ' . $_SERVER['PHP_SELF']);
	exit;
}

if ($action === 'test') {
	try {
		$service = new XtreamproService($db);
		$info = $service->client()->userInfo();
		$credits = is_array($info) && isset($info['credits']) ? (int) $info['credits'] : 0;
		setEventMessages($langs->trans('XtreamproTestOk', $credits), null, 'mesgs');
		$packages = $service->client()->packages();
	} catch (XtreamproApiException $e) {
		setEventMessages($e->getMessage(), null, 'errors');
	}
}

llxHeader('', $langs->trans('XtreamproSetup'), '', '', 0, 0, '', '', '', 'mod-xtreampro page-admin');

print load_fiche_titre($langs->trans('XtreamproSetup'), '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1">' . $langs->trans('BackToModuleList') . '</a>', 'title_setup');
print dol_get_fiche_head(xtreampro_admin_prepare_head(), 'settings', $langs->trans('XtreamproModuleName'), -1, 'generic');

print '<span class="opacitymedium">' . $langs->trans('XtreamproSetupIntro') . '</span><br><br>';

$url = XtreamproService::apiUrl();
if (strpos($url, 'http://') === 0) {
	print '<div class="warning">' . $langs->trans('XtreamproHttpWarning') . '</div>';
}

print '<form method="POST" action="' . xtreampro_h($_SERVER['PHP_SELF']) . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="save">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>' . $langs->trans('Parameter') . '</td><td>' . $langs->trans('Value') . '</td></tr>';
print '<tr class="oddeven"><td>' . $langs->trans('XtreamproApiUrl') . '<br><span class="opacitymedium small">' . $langs->trans('XtreamproApiUrlHelp') . '</span></td>';
print '<td><input type="url" name="api_url" class="minwidth400" value="' . xtreampro_h($url) . '" placeholder="https://api.example.com"></td></tr>';
print '<tr class="oddeven"><td>' . $langs->trans('XtreamproApiKey') . '<br><span class="opacitymedium small">' . $langs->trans('XtreamproApiKeyHelp') . '</span></td>';
// The key is never printed back: only whether one is saved.
print '<td><input type="password" name="api_key" class="minwidth400" value="" autocomplete="new-password" placeholder="'
	. xtreampro_h(XtreamproService::apiKey() !== '' ? $langs->transnoentities('XtreamproApiKeySaved') : '') . '"></td></tr>';
print '<tr class="oddeven"><td>' . $langs->trans('XtreamproAutoProvision') . '<br><span class="opacitymedium small">' . $langs->trans('XtreamproAutoProvisionHelp') . '</span></td>';
print '<td><input type="checkbox" name="auto_provision" value="1"' . (getDolGlobalString('XTREAMPRO_AUTO_PROVISION', '1') ? ' checked' : '') . '></td></tr>';
print '</table>';
print '<div class="center"><input type="submit" class="button button-save" value="' . xtreampro_h($langs->trans('Save')) . '"></div>';
print '</form>';

print '<br><form method="POST" action="' . xtreampro_h($_SERVER['PHP_SELF']) . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="test">';
print '<div class="center"><input type="submit" class="button" value="' . xtreampro_h($langs->trans('XtreamproTestConnection')) . '"></div>';
print '</form>';

if (is_array($packages)) {
	print '<br>' . load_fiche_titre($langs->trans('XtreamproPackages'), '', '');
	print '<div class="opacitymedium">' . $langs->trans('XtreamproPackagesHelp') . '</div>';
	print '<table class="noborder centpercent"><tr class="liste_titre"><td>ID</td><td>' . $langs->trans('Label') . '</td><td>' . $langs->trans('XtreamproPackageKind') . '</td></tr>';
	foreach ($packages as $pkg) {
		// A package for MAG / Enigma boxes only (`sells` without "line") cannot be sold as a line: not listed.
		if (!is_array($pkg) || !isset($pkg['id']) || !XtreamproApiClient::sellsLine($pkg)) {
			continue;
		}
		$detail = !empty($pkg['is_official'])
			? (isset($pkg['official_credits']) ? $pkg['official_credits'] : '?') . ' credits, ' . (isset($pkg['official_duration']) ? $pkg['official_duration'] : '?') . ' ' . (isset($pkg['official_duration_in']) ? $pkg['official_duration_in'] : '')
			: 'trial only';
		print '<tr class="oddeven"><td>' . xtreampro_h($pkg['id']) . '</td><td>' . xtreampro_h(isset($pkg['name']) ? $pkg['name'] : '') . '</td><td>' . xtreampro_h(trim($detail)) . '</td></tr>';
	}
	print '</table>';
}

print dol_get_fiche_end();
llxFooter();
$db->close();
