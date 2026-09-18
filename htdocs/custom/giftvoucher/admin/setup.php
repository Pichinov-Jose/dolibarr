<?php
/* Copyright (C) 2026  Jose MARTINEZ <jose.martinez@pichinov.com>
 * GPL v3+ — see modGiftvoucher.class.php
 */

/**
 * \file    admin/setup.php
 * \ingroup giftvoucher
 * \brief   Configuration du module GiftVoucher.
 */
$res = 0;
if (file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/giftvoucher/lib/giftvoucher.lib.php');

$langs->loadLangs(array('giftvoucher@giftvoucher', 'admin'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

if ($action == 'update') {
	$error = 0;
	$consts = array(
		'GIFTVOUCHER_CODE_MASK' => GETPOST('GIFTVOUCHER_CODE_MASK', 'alphanohtml'),
		'GIFTVOUCHER_VALIDITY_MONTHS_GIFT' => GETPOSTINT('GIFTVOUCHER_VALIDITY_MONTHS_GIFT'),
		'GIFTVOUCHER_VALIDITY_MONTHS_CREDIT' => GETPOSTINT('GIFTVOUCHER_VALIDITY_MONTHS_CREDIT'),
		'GIFTVOUCHER_PAYMENT_MODE_ID' => GETPOSTINT('GIFTVOUCHER_PAYMENT_MODE_ID'),
		'GIFTVOUCHER_PRODUCT_ID' => GETPOSTINT('GIFTVOUCHER_PRODUCT_ID'),
	);
	foreach ($consts as $key => $value) {
		if (dolibarr_set_const($db, $key, $value, 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}
	}
	if (!$error) {
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('Error'), null, 'errors');
	}
}

llxHeader('', $langs->trans('GiftVoucherSetup'), '', '', 0, 0, '', '', '', 'mod-giftvoucher page-admin');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('GiftVoucherSetup'), $linkback, 'title_setup');

$head = giftvoucherAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans('GiftVouchers'), -1, 'fa-gift');

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('GiftVoucherCodeMask').'<br><span class="opacitymedium small">'.$langs->trans('GenericMaskCodes', $langs->transnoentities('GiftVoucher'), $langs->transnoentities('GiftVoucher')).'</span></td>';
print '<td><input type="text" class="flat minwidth200" name="GIFTVOUCHER_CODE_MASK" value="'.dol_escape_htmltag(getDolGlobalString('GIFTVOUCHER_CODE_MASK', 'BA{yy}{mm}-{0000}')).'"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('GiftVoucherValidityGift').'</td>';
print '<td><input type="number" class="flat width50" name="GIFTVOUCHER_VALIDITY_MONTHS_GIFT" value="'.getDolGlobalInt('GIFTVOUCHER_VALIDITY_MONTHS_GIFT', 12).'"> '.$langs->trans('DurationMonth').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('GiftVoucherValidityCredit').'</td>';
print '<td><input type="number" class="flat width50" name="GIFTVOUCHER_VALIDITY_MONTHS_CREDIT" value="'.getDolGlobalInt('GIFTVOUCHER_VALIDITY_MONTHS_CREDIT', 6).'"> '.$langs->trans('DurationMonth').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('GiftVoucherPaymentModeId').'<br><span class="opacitymedium small">'.$langs->trans('GiftVoucherPaymentModeIdHelp').'</span></td>';
print '<td><input type="number" class="flat width75" name="GIFTVOUCHER_PAYMENT_MODE_ID" value="'.getDolGlobalInt('GIFTVOUCHER_PAYMENT_MODE_ID', 109).'"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('GiftVoucherProductId').'<br><span class="opacitymedium small">'.$langs->trans('GiftVoucherProductIdHelp').'</span></td>';
print '<td><input type="number" class="flat width75" name="GIFTVOUCHER_PRODUCT_ID" value="'.getDolGlobalInt('GIFTVOUCHER_PRODUCT_ID', 0).'"></td></tr>';
print '</table>';

print '<div class="center" style="margin-top:10px;"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"></div>';
print '</form>';

print dol_get_fiche_end();
llxFooter();
$db->close();
