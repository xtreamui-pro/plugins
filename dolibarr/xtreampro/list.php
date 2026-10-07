<?php
/**
 * Xtream UI Pro for Dolibarr - all IPTV lines and sub-reseller accounts (menu Xtream UI Pro).
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

dol_include_once('/xtreampro/lib/xtreampro.lib.php');

$langs->loadLangs(array('companies', 'bills', 'xtreampro@xtreampro'));

if (!isModEnabled('xtreampro') || !$user->hasRight('xtreampro', 'read') || $user->socid > 0) {
	accessforbidden();
}

$type = GETPOST('type', 'aZ09');
if ($type !== 'line' && $type !== 'reseller') {
	$type = '';
}
$status = GETPOST('search_status', 'aZ09');
if (!in_array($status, array('pending', 'active', 'suspended', 'terminated', 'failed'), true)) {
	$status = '';
}

llxHeader('', $langs->trans('XtreamproModuleName'), '', '', 0, 0, '', '', '', 'mod-xtreampro page-list');
print load_fiche_titre($langs->trans('XtreamproModuleName'), '', 'generic');

print '<form method="GET" action="' . xtreampro_h($_SERVER['PHP_SELF']) . '"><input type="hidden" name="type" value="' . xtreampro_h($type) . '">';
print $langs->trans('Status') . ' <select name="search_status" class="flat"><option value=""></option>';
foreach (array('pending', 'active', 'suspended', 'terminated', 'failed') as $s) {
	print '<option value="' . xtreampro_h($s) . '"' . ($s === $status ? ' selected' : '') . '>' . xtreampro_h(xtreampro_status_label($s)) . '</option>';
}
print '</select> <input type="submit" class="button small" value="' . xtreampro_h($langs->trans('Search')) . '"></form><br>';

xtreampro_print_lists($db, 0, $status, $type);

llxFooter();
$db->close();
