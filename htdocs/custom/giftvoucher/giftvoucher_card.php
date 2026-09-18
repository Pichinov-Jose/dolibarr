<?php
/* Copyright (C) 2026  Jose MARTINEZ <jose.martinez@pichinov.com>
 * GPL v3+ — see modGiftvoucher.class.php
 */

/**
 * \file    giftvoucher_card.php
 * \ingroup giftvoucher
 * \brief   Fiche bon d'achat : création, consultation, consommation, annulation.
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
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formcompany.class.php';

$langs->loadLangs(array('giftvoucher@giftvoucher', 'bills', 'companies'));

$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('id');
$refcode = trim(GETPOST('ref', 'alphanohtml'));

if (!isModEnabled('giftvoucher')) {
	accessforbidden('Module not enabled');
}
$permissiontoread = $user->hasRight('giftvoucher', 'read');
$permissiontoadd = $user->hasRight('giftvoucher', 'write');
$permissiontodelete = $user->hasRight('giftvoucher', 'delete');
if (!$permissiontoread) {
	accessforbidden();
}

$object = new GiftVoucher($db);
if ($id > 0 || $refcode !== '') {
	$result = $object->fetch($id, $refcode);
	if ($result <= 0 && $action != 'add' && $action != 'create') {
		recordNotFound('', 0);
	}
}

$form = new Form($db);

/*
 * Actions
 */
if ($action == 'add' && $permissiontoadd) {
	$object = new GiftVoucher($db);
	$object->ref = trim(GETPOST('code', 'alphanohtml'));
	$object->amount = price2num(GETPOST('amount', 'alphanohtml'), 'MT');
	$object->type_voucher = (GETPOST('type_voucher', 'aZ09') == GiftVoucher::TYPE_CREDIT) ? GiftVoucher::TYPE_CREDIT : GiftVoucher::TYPE_GIFT;
	$object->date_emission = dol_mktime(12, 0, 0, GETPOSTINT('emissionmonth'), GETPOSTINT('emissionday'), GETPOSTINT('emissionyear'));
	if (empty($object->date_emission)) {
		$object->date_emission = dol_now();
	}
	$datevalidite = dol_mktime(12, 0, 0, GETPOSTINT('validitemonth'), GETPOSTINT('validiteday'), GETPOSTINT('validiteyear'));
	if (!empty($datevalidite)) {
		$object->date_validite = $datevalidite;
	}
	$object->fk_soc = GETPOSTINT('socid');
	$object->note_public = GETPOST('note_public', 'restricthtml');
	if (!($object->amount > 0)) {
		setEventMessages($langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('Amount')), null, 'errors');
		$action = 'create';
	} else {
		$result = $object->create($user);
		if ($result > 0) {
			setEventMessages($langs->trans('RecordCreatedSuccessfully'), null, 'mesgs');
			header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
			exit;
		}
		setEventMessages($object->error, $object->errors, 'errors');
		$action = 'create';
	}
}
if ($action == 'confirm_redeem' && GETPOST('confirm') == 'yes' && $permissiontoadd && $object->id > 0) {
	$result = $object->redeem($user, 0, GETPOSTINT('force'));
	if ($result > 0) {
		setEventMessages($langs->trans('GiftVoucherRedeemed', $object->ref, price($object->amount)), null, 'mesgs');
	} else {
		setEventMessages($langs->trans($object->error), null, 'errors');
	}
}
if ($action == 'confirm_cancel' && GETPOST('confirm') == 'yes' && $permissiontoadd && $object->id > 0) {
	$object->setStatusSimple($user, GiftVoucher::STATUS_CANCELED);
}
if ($action == 'confirm_reopen' && GETPOST('confirm') == 'yes' && $permissiontoadd && $object->id > 0) {
	$object->setStatusSimple($user, GiftVoucher::STATUS_OPEN);
}
if ($action == 'confirm_delete' && GETPOST('confirm') == 'yes' && $permissiontodelete && $object->id > 0) {
	$result = $object->delete($user);
	if ($result > 0) {
		header('Location: '.dol_buildpath('/giftvoucher/giftvoucher_list.php', 1));
		exit;
	}
	setEventMessages($object->error, $object->errors, 'errors');
}

/*
 * View
 */
llxHeader('', $langs->trans('GiftVoucher'), '', '', 0, 0, '', '', '', 'mod-giftvoucher page-card');

if ($action == 'create') {
	print load_fiche_titre($langs->trans('NewGiftVoucher'), '', 'fa-gift');
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="add">';
	print dol_get_fiche_head();
	print '<table class="border centpercent tableforfieldcreate">';
	print '<tr><td class="titlefieldcreate">'.$langs->trans('GiftVoucherCode').'</td><td><input type="text" name="code" class="flat minwidth200" value="" placeholder="'.dol_escape_htmltag($langs->trans('GiftVoucherCodeAuto')).'"></td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('Amount').'</td><td><input type="text" name="amount" class="flat width75" value=""> '.$langs->getCurrencySymbol($conf->currency).'</td></tr>';
	print '<tr><td>'.$langs->trans('Type').'</td><td>';
	print $form->selectarray('type_voucher', array(GiftVoucher::TYPE_GIFT => $langs->trans('GiftVoucherTypeGift'), GiftVoucher::TYPE_CREDIT => $langs->trans('GiftVoucherTypeCredit')), GETPOST('type_voucher', 'aZ09'));
	print '</td></tr>';
	print '<tr><td>'.$langs->trans('GiftVoucherDateEmission').'</td><td>'.$form->selectDate(dol_now(), 'emission', 0, 0, 0, '', 1, 1).'</td></tr>';
	print '<tr><td>'.$langs->trans('GiftVoucherDateValidite').'</td><td>'.$form->selectDate(-1, 'validite', 0, 0, 1, '', 1, 0).' <span class="opacitymedium">('.$langs->trans('GiftVoucherValidityAuto').')</span></td></tr>';
	print '<tr><td>'.$langs->trans('Customer').'</td><td>'.$form->select_company(GETPOSTINT('socid'), 'socid', '', 1).'</td></tr>';
	print '<tr><td>'.$langs->trans('NotePublic').'</td><td><input type="text" name="note_public" class="flat quatrevingtpercent" value=""></td></tr>';
	print '</table>';
	print dol_get_fiche_end();
	print $form->buttonsSaveCancel('Create');
	print '</form>';
} elseif ($object->id > 0) {
	$formconfirm = '';
	if ($action == 'redeem') {
		$text = $langs->trans('GiftVoucherConfirmRedeem', $object->ref, price($object->amount));
		$formquestion = array();
		if (!$object->isRedeemable() && $object->status == GiftVoucher::STATUS_OPEN) {
			$text .= '<br><b>'.$langs->trans('GiftVoucherStatusExpired').'</b>';
			$formquestion[] = array('type' => 'hidden', 'name' => 'force', 'value' => 1);
		}
		$formconfirm = $form->formconfirm($_SERVER['PHP_SELF'].'?id='.$object->id, $langs->trans('GiftVoucherRedeem'), $text, 'confirm_redeem', $formquestion, '', 1);
	}
	if ($action == 'cancel') {
		$formconfirm = $form->formconfirm($_SERVER['PHP_SELF'].'?id='.$object->id, $langs->trans('Cancel'), $langs->trans('GiftVoucherConfirmCancel', $object->ref), 'confirm_cancel', '', '', 1);
	}
	if ($action == 'reopen') {
		$formconfirm = $form->formconfirm($_SERVER['PHP_SELF'].'?id='.$object->id, $langs->trans('ReOpen'), $langs->trans('GiftVoucherConfirmReopen', $object->ref), 'confirm_reopen', '', '', 1);
	}
	if ($action == 'delete') {
		$formconfirm = $form->formconfirm($_SERVER['PHP_SELF'].'?id='.$object->id, $langs->trans('Delete'), $langs->trans('ConfirmDeleteObject'), 'confirm_delete', '', '', 1);
	}
	print $formconfirm;

	print load_fiche_titre($langs->trans('GiftVoucher').' '.$object->ref, '', 'fa-gift');
	print dol_get_fiche_head();
	print '<div class="fichecenter"><div class="fichehalfleft">';
	print '<table class="border centpercent tableforfield">';
	print '<tr><td class="titlefield">'.$langs->trans('GiftVoucherCode').'</td><td><b>'.dol_escape_htmltag($object->ref).'</b></td></tr>';
	print '<tr><td>'.$langs->trans('Amount').'</td><td><b>'.price($object->amount, 0, $langs, 1, -1, -1, $conf->currency).'</b></td></tr>';
	print '<tr><td>'.$langs->trans('Type').'</td><td>'.$langs->trans($object->type_voucher == GiftVoucher::TYPE_CREDIT ? 'GiftVoucherTypeCredit' : 'GiftVoucherTypeGift').'</td></tr>';
	print '<tr><td>'.$langs->trans('Status').'</td><td>'.$object->getLibStatut(4).'</td></tr>';
	print '<tr><td>'.$langs->trans('GiftVoucherDateEmission').'</td><td>'.dol_print_date($object->date_emission, 'day').'</td></tr>';
	print '<tr><td>'.$langs->trans('GiftVoucherDateValidite').'</td><td>'.dol_print_date($object->date_validite, 'day').'</td></tr>';
	if ($object->status == GiftVoucher::STATUS_USED) {
		print '<tr><td>'.$langs->trans('GiftVoucherDateExerce').'</td><td>'.dol_print_date($object->date_exerce, 'dayhour').'</td></tr>';
		if ($object->fk_facture > 0) {
			require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
			$fac = new Facture($db);
			if ($fac->fetch($object->fk_facture) > 0) {
				print '<tr><td>'.$langs->trans('Invoice').'</td><td>'.$fac->getNomUrl(1).'</td></tr>';
			}
		}
	}
	if ($object->fk_soc > 0) {
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		$soc = new Societe($db);
		if ($soc->fetch($object->fk_soc) > 0) {
			print '<tr><td>'.$langs->trans('Customer').'</td><td>'.$soc->getNomUrl(1).'</td></tr>';
		}
	}
	if ($object->note_public) {
		print '<tr><td>'.$langs->trans('NotePublic').'</td><td>'.dol_escape_htmltag($object->note_public).'</td></tr>';
	}
	if ($object->import_key) {
		print '<tr><td>'.$langs->trans('ImportId').'</td><td class="opacitymedium">'.dol_escape_htmltag($object->import_key).'</td></tr>';
	}
	print '</table>';
	print '</div><div class="fichehalfright">';
	// Code-barres C128 du code du bon (générateur natif Dolibarr)
	print '<div class="center" style="padding-top:20px;">';
	print '<img src="'.DOL_URL_ROOT.'/viewimage.php?modulepart=barcode&generator=tcpdfbarcode&encoding=C128&readable=1&code='.urlencode($object->ref).'" title="'.dol_escape_htmltag($object->ref).'" style="max-width:90%;">';
	print '</div>';
	print '</div></div>';
	print dol_get_fiche_end();

	print '<div class="tabsAction">';
	print dolGetButtonAction('', $langs->trans('PrintButton'), 'default', dol_buildpath('/giftvoucher/giftvoucher_print.php', 1).'?id='.$object->id, '', true, array('attr' => array('target' => '_blank')));
	if ($object->status == GiftVoucher::STATUS_OPEN && $permissiontoadd) {
		print dolGetButtonAction('', $langs->trans('GiftVoucherRedeem'), 'default', $_SERVER['PHP_SELF'].'?id='.$object->id.'&action=redeem&token='.newToken(), '', true);
		print dolGetButtonAction('', $langs->trans('Cancel'), 'delete', $_SERVER['PHP_SELF'].'?id='.$object->id.'&action=cancel&token='.newToken(), '', true);
	}
	if ($object->status != GiftVoucher::STATUS_OPEN && $permissiontoadd) {
		print dolGetButtonAction('', $langs->trans('ReOpen'), 'default', $_SERVER['PHP_SELF'].'?id='.$object->id.'&action=reopen&token='.newToken(), '', true);
	}
	if ($permissiontodelete) {
		print dolGetButtonAction('', $langs->trans('Delete'), 'delete', $_SERVER['PHP_SELF'].'?id='.$object->id.'&action=delete&token='.newToken(), '', true);
	}
	print '</div>';
}

llxFooter();
$db->close();
