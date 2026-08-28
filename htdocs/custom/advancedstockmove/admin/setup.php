<?php
/* Copyright (C) 2026  Jose MARTINEZ <jose.martinez@pichinov.com>
 * GPL v3+
 */

/**
 * \file    admin/setup.php
 * \ingroup advancedstockmove
 * \brief   Page de reglages du module AdvancedStockMove.
 */
$res = 0;
if (!$res && file_exists('../../main.inc.php')) {
	$res = include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
	$res = include '../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/advancedstockmove/lib/advancedstockmove.lib.php');

$langs->loadLangs(array('admin', 'stocks', 'advancedstockmove@advancedstockmove'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

if ($action == 'setprefixes') {
	$ok = 1;
	$ok = $ok && dolibarr_set_const($db, 'STOCK_CORRECTION_CODE_PREFIX', GETPOST('corprefix', 'alphanohtml'), 'chaine', 0, '', $conf->entity) > 0;
	$ok = $ok && dolibarr_set_const($db, 'STOCK_TRANSFER_CODE_PREFIX', GETPOST('traprefix', 'alphanohtml'), 'chaine', 0, '', $conf->entity) > 0;
	$ok = $ok && dolibarr_set_const($db, 'STOCK_MASSSTOCKMOVE_CODE_PREFIX', GETPOST('msmprefix', 'alphanohtml'), 'chaine', 0, '', $conf->entity) > 0;
	if ($ok) {
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('Error'), null, 'errors');
	}
}

llxHeader('', $langs->trans('AdvancedStockMoveSetup'));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('AdvancedStockMoveSetup'), $linkback, 'title_setup');

$head = advancedstockmoveAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans('AdvancedStockMove'), -1, 'stock');

print '<span class="opacitymedium">'.$langs->trans('AdvancedStockMoveSetupHelp').'</span><br><br>';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td class="center">'.$langs->trans('Status').'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('WrapCorrectionsIntoInventory').'</td><td class="center">';
print ajax_constantonoff('ADVANCEDSTOCKMOVE_WRAP_CORRECTIONS');
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('WrapTransfersIntoStockTransfer');
if (!isModEnabled('stocktransfer')) {
	print ' <span class="error">('.$langs->trans('WarningModuleStockTransferDisabled').')</span>';
}
print '</td><td class="center">';
print ajax_constantonoff('ADVANCEDSTOCKMOVE_WRAP_TRANSFERS');
print '</td></tr>';

print '</table><br>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="setprefixes">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('BatchCodePrefixes').'</td><td>'.$langs->trans('Value').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('CorrectionPrefix').'</td><td><input name="corprefix" class="maxwidth100" value="'.dol_escape_htmltag(getDolGlobalString('STOCK_CORRECTION_CODE_PREFIX', 'COR-')).'"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('TransferPrefix').'</td><td><input name="traprefix" class="maxwidth100" value="'.dol_escape_htmltag(getDolGlobalString('STOCK_TRANSFER_CODE_PREFIX', 'TRA-')).'"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('MassTransferPrefix').'</td><td><input name="msmprefix" class="maxwidth100" value="'.dol_escape_htmltag(getDolGlobalString('STOCK_MASSSTOCKMOVE_CODE_PREFIX', 'MSM-')).'"></td></tr>';
print '</table>';
print '<div class="center"><input type="submit" class="button" value="'.$langs->trans('Save').'"></div>';
print '</form>';

print dol_get_fiche_end();
llxFooter();
$db->close();
