<?php
/* Copyright (C) 2026  Jose MARTINEZ <jose.martinez@pichinov.com>
 * GPL v3+ — see modGiftvoucher.class.php
 */

/**
 * \file    giftvoucher_scan.php
 * \ingroup giftvoucher
 * \brief   Poste de scan : douchette -> état du bon -> consommation en un clic.
 */
$res = 0;
if (file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}
dol_include_once('/giftvoucher/class/giftvoucher.class.php');

$langs->loadLangs(array('giftvoucher@giftvoucher', 'bills'));

$action = GETPOST('action', 'aZ09');
$code = trim(GETPOST('code', 'alphanohtml'));
$id = GETPOSTINT('id');
$facnum = trim(GETPOST('facnum', 'alphanohtml'));

if (!isModEnabled('giftvoucher')) {
	accessforbidden('Module not enabled');
}
if (!$user->hasRight('giftvoucher', 'write')) {
	accessforbidden();
}

$object = new GiftVoucher($db);
$found = 0;
$message = '';

/*
 * Actions
 */
if ($action == 'redeem' && $id > 0 && $user->hasRight('giftvoucher', 'write')) {
	$object->fetch($id);
	$fk_facture = 0;
	if ($facnum !== '') {
		$sqlf = "SELECT rowid FROM ".MAIN_DB_PREFIX."facture WHERE ref = '".$db->escape($facnum)."' AND entity IN (".getEntity('invoice').")";
		$resf = $db->query($sqlf);
		if ($resf && ($objf = $db->fetch_object($resf))) {
			$fk_facture = (int) $objf->rowid;
		}
	}
	$result = $object->redeem($user, $fk_facture, GETPOSTINT('force'));
	if ($result > 0) {
		setEventMessages($langs->trans('GiftVoucherRedeemed', $object->ref, price($object->amount)), null, 'mesgs');
	} else {
		setEventMessages($langs->trans($object->error), null, 'errors');
	}
	$code = $object->ref; // réafficher l'état après action
}

if ($code !== '') {
	$found = $object->fetch(0, $code);
}

/*
 * View
 */
llxHeader('', $langs->trans('GiftVoucherScan'), '', '', 0, 0, '', '', '', 'mod-giftvoucher page-scan');

print load_fiche_titre($langs->trans('GiftVoucherScan'), '', 'fa-gift');

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<div class="center" style="margin: 20px 0;">';
print '<input type="text" id="code" name="code" class="flat quatrevingtpercent" style="max-width:420px; font-size:1.6em; text-align:center;" placeholder="'.dol_escape_htmltag($langs->trans('GiftVoucherScanPlaceholder')).'" value="'.dol_escape_htmltag($code).'" autofocus autocomplete="off">';
print '<br><br><input type="submit" class="button" value="'.$langs->trans('Search').'">';
print '</div>';
print '</form>';

if ($code !== '') {
	if ($found <= 0) {
		print '<div class="error center" style="font-size:1.4em; padding:20px;">'.$langs->trans('GiftVoucherNotFound', dol_escape_htmltag($code)).'</div>';
	} else {
		$redeemable = $object->isRedeemable();
		print '<div class="center" style="border:2px solid '.($object->status == GiftVoucher::STATUS_USED ? '#c00' : ($redeemable ? '#0a0' : '#c60')).'; border-radius:8px; max-width:520px; margin:auto; padding:16px;">';
		print '<div style="font-size:1.3em;">'.$object->getNomUrl(1).' — '.$langs->trans($object->type_voucher == GiftVoucher::TYPE_CREDIT ? 'GiftVoucherTypeCredit' : 'GiftVoucherTypeGift').'</div>';
		print '<div style="font-size:2.4em; font-weight:bold; margin:10px 0;">'.price($object->amount, 0, $langs, 1, -1, -1, $conf->currency).'</div>';
		print '<div style="font-size:1.5em; margin-bottom:8px;">'.$object->getLibStatut(4).'</div>';
		print '<table class="centpercent" style="text-align:left;">';
		print '<tr><td>'.$langs->trans('GiftVoucherDateEmission').'</td><td>'.dol_print_date($object->date_emission, 'day').'</td></tr>';
		print '<tr><td>'.$langs->trans('GiftVoucherDateValidite').'</td><td>'.dol_print_date($object->date_validite, 'day').'</td></tr>';
		if ($object->status == GiftVoucher::STATUS_USED) {
			print '<tr><td>'.$langs->trans('GiftVoucherDateExerce').'</td><td><b>'.dol_print_date($object->date_exerce, 'dayhour').'</b></td></tr>';
			if ($object->fk_facture > 0) {
				require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
				$fac = new Facture($db);
				if ($fac->fetch($object->fk_facture) > 0) {
					print '<tr><td>'.$langs->trans('Invoice').'</td><td>'.$fac->getNomUrl(1).'</td></tr>';
				}
			}
		}
		print '</table>';

		if ($object->status == GiftVoucher::STATUS_OPEN) {
			print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="margin-top:12px;">';
			print '<input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="redeem">';
			print '<input type="hidden" name="id" value="'.$object->id.'">';
			print '<input type="text" name="facnum" class="flat" placeholder="'.dol_escape_htmltag($langs->trans('GiftVoucherInvoiceRefOptional')).'" autocomplete="off"> ';
			if ($redeemable) {
				print '<input type="submit" class="button butAction" value="'.$langs->trans('GiftVoucherRedeem').'">';
			} else {
				print '<input type="hidden" name="force" value="1">';
				print '<input type="submit" class="button butActionDelete" value="'.$langs->trans('GiftVoucherRedeemExpired').'">';
			}
			print '</form>';
		}
		print '</div>';
	}
}

print '<script>document.getElementById("code").select();</script>';

llxFooter();
$db->close();
