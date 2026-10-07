<?php
/**
 * Xtream UI Pro for Dolibarr - tab "Xtream UI Pro" on the third-party card:
 * the lines and the sub-reseller account of this customer.
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

require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/company.lib.php';
dol_include_once('/xtreampro/lib/xtreampro.lib.php');

$langs->loadLangs(array('companies', 'bills', 'xtreampro@xtreampro'));

if (!isModEnabled('xtreampro') || !$user->hasRight('xtreampro', 'read') || $user->socid > 0) {
	accessforbidden();
}

$socid = (int) GETPOST('socid', 'int');
$object = new Societe($db);
if ($socid <= 0 || $object->fetch($socid) <= 0) {
	accessforbidden();
}

llxHeader('', $langs->trans('XtreamproModuleName'), '', '', 0, 0, '', '', '', 'mod-xtreampro page-tab');
$head = societe_prepare_head($object);
print dol_get_fiche_head($head, 'xtreampro', $langs->trans('ThirdParty'), -1, 'company');
dol_banner_tab($object, 'socid', '', ($user->socid ? 0 : 1), 'rowid', 'nom');
print '<div class="fichecenter">';

xtreampro_print_lists($db, $socid);

print '</div>';
print dol_get_fiche_end();
llxFooter();
$db->close();
