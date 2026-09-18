<?php
/* Copyright (C) 2026  Jose MARTINEZ <jose.martinez@pichinov.com>
 * GPL v3+ — see modGiftvoucher.class.php
 */

/**
 * \file    giftvoucher_list.php
 * \ingroup giftvoucher
 * \brief   Liste des bons d'achat avec filtres (statut dérivé expiré inclus) et totaux.
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

if (!isModEnabled('giftvoucher')) {
	accessforbidden('Module not enabled');
}
if (!$user->hasRight('giftvoucher', 'read')) {
	accessforbidden();
}

$search_code = trim(GETPOST('search_code', 'alphanohtml'));
$search_status = GETPOST('search_status', 'aZ09'); // open|used|expired|canceled|''
$search_type = GETPOST('search_type', 'aZ09');
$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT('page');
if ($page < 0) {
	$page = 0;
}
$offset = $limit * $page;
$sortfield = GETPOST('sortfield', 'aZ09comma') ? GETPOST('sortfield', 'aZ09comma') : 'gv.rowid';
$sortorder = GETPOST('sortorder', 'aZ09comma') ? GETPOST('sortorder', 'aZ09comma') : 'DESC';
if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$search_code = '';
	$search_status = '';
	$search_type = '';
}

$sql = "SELECT gv.rowid, gv.ref, gv.amount, gv.type_voucher, gv.date_emission, gv.date_validite, gv.date_exerce, gv.status, gv.import_key";
$sql .= " FROM ".MAIN_DB_PREFIX."giftvoucher as gv";
$sql .= " WHERE gv.entity IN (".getEntity('giftvoucher').")";
if ($search_code !== '') {
	$sql .= natural_search('gv.ref', $search_code);
}
if ($search_type === GiftVoucher::TYPE_GIFT || $search_type === GiftVoucher::TYPE_CREDIT) {
	$sql .= " AND gv.type_voucher = '".$db->escape($search_type)."'";
}
if ($search_status === 'open') {
	$sql .= " AND gv.status = ".GiftVoucher::STATUS_OPEN." AND (gv.date_validite IS NULL OR gv.date_validite >= '".$db->idate(dol_now())."')";
} elseif ($search_status === 'expired') {
	$sql .= " AND gv.status = ".GiftVoucher::STATUS_OPEN." AND gv.date_validite < '".$db->idate(dol_now())."'";
} elseif ($search_status === 'used') {
	$sql .= " AND gv.status = ".GiftVoucher::STATUS_USED;
} elseif ($search_status === 'canceled') {
	$sql .= " AND gv.status = ".GiftVoucher::STATUS_CANCELED;
}

$sqlcount = preg_replace('/^SELECT .* FROM/', 'SELECT COUNT(*) as nb, SUM(gv.amount) as total FROM', $sql);
$nbtotalofrecords = 0;
$totalamount = 0;
$resql = $db->query($sqlcount);
if ($resql) {
	$objc = $db->fetch_object($resql);
	$nbtotalofrecords = (int) $objc->nb;
	$totalamount = (float) $objc->total;
}

$sql .= $db->order($sortfield, $sortorder);
$sql .= $db->plimit($limit + 1, $offset);
$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
	exit;
}
$num = $db->num_rows($resql);

llxHeader('', $langs->trans('GiftVouchers'), '', '', 0, 0, '', '', '', 'mod-giftvoucher page-list');

$form = new Form($db);

$param = '';
foreach (array('search_code' => $search_code, 'search_status' => $search_status, 'search_type' => $search_type) as $k => $v) {
	if ($v !== '') {
		$param .= '&'.$k.'='.urlencode($v);
	}
}

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="sortfield" value="'.dol_escape_htmltag($sortfield).'">';
print '<input type="hidden" name="sortorder" value="'.dol_escape_htmltag($sortorder).'">';

print_barre_liste($langs->trans('GiftVouchers').' <span class="opacitymedium">('.$nbtotalofrecords.' — '.price($totalamount, 0, $langs, 1, -1, -1, $conf->currency).')</span>', $page, $_SERVER['PHP_SELF'], $param, $sortfield, $sortorder, '', $num, $nbtotalofrecords, 'fa-gift', 0, '', '', $limit);

print '<div class="div-table-responsive">';
print '<table class="tagtable liste">';
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_code" value="'.dol_escape_htmltag($search_code).'"></td>';
print '<td class="liste_titre">';
print $form->selectarray('search_type', array('' => '', GiftVoucher::TYPE_GIFT => $langs->trans('GiftVoucherTypeGift'), GiftVoucher::TYPE_CREDIT => $langs->trans('GiftVoucherTypeCredit')), $search_type, 0, 0, 0, '', 0, 0, 0, '', 'maxwidth125');
print '</td>';
print '<td class="liste_titre"></td><td class="liste_titre"></td><td class="liste_titre"></td><td class="liste_titre"></td>';
print '<td class="liste_titre right">';
print $form->selectarray('search_status', array('' => '', 'open' => $langs->trans('GiftVoucherStatusOpen'), 'used' => $langs->trans('GiftVoucherStatusUsed'), 'expired' => $langs->trans('GiftVoucherStatusExpired'), 'canceled' => $langs->trans('GiftVoucherStatusCanceled')), $search_status, 0, 0, 0, '', 0, 0, 0, '', 'maxwidth125');
print '</td>';
print '<td class="liste_titre center maxwidthsearch">'.$form->showFilterButtons().'</td>';
print '</tr>';
print '<tr class="liste_titre">';
print_liste_field_titre('GiftVoucherCode', $_SERVER['PHP_SELF'], 'gv.ref', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('Type', $_SERVER['PHP_SELF'], 'gv.type_voucher', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('Amount', $_SERVER['PHP_SELF'], 'gv.amount', '', $param, 'class="right"', $sortfield, $sortorder);
print_liste_field_titre('GiftVoucherDateEmission', $_SERVER['PHP_SELF'], 'gv.date_emission', '', $param, 'class="center"', $sortfield, $sortorder);
print_liste_field_titre('GiftVoucherDateValidite', $_SERVER['PHP_SELF'], 'gv.date_validite', '', $param, 'class="center"', $sortfield, $sortorder);
print_liste_field_titre('GiftVoucherDateExerce', $_SERVER['PHP_SELF'], 'gv.date_exerce', '', $param, 'class="center"', $sortfield, $sortorder);
print_liste_field_titre('Status', $_SERVER['PHP_SELF'], 'gv.status', '', $param, 'class="right"', $sortfield, $sortorder);
print_liste_field_titre('', $_SERVER['PHP_SELF'], '', '', $param, '', $sortfield, $sortorder, 'center maxwidthsearch ');
print '</tr>';

$voucher = new GiftVoucher($db);
$i = 0;
while ($i < min($num, $limit)) {
	$obj = $db->fetch_object($resql);
	$voucher->id = (int) $obj->rowid;
	$voucher->ref = $obj->ref;
	$voucher->status = (int) $obj->status;
	$voucher->date_validite = $db->jdate($obj->date_validite);
	print '<tr class="oddeven">';
	print '<td>'.$voucher->getNomUrl(1).($obj->import_key ? ' <span class="opacitymedium small">(Kezia)</span>' : '').'</td>';
	print '<td>'.$langs->trans($obj->type_voucher == GiftVoucher::TYPE_CREDIT ? 'GiftVoucherTypeCredit' : 'GiftVoucherTypeGift').'</td>';
	print '<td class="right">'.price($obj->amount).'</td>';
	print '<td class="center">'.dol_print_date($db->jdate($obj->date_emission), 'day').'</td>';
	print '<td class="center">'.dol_print_date($db->jdate($obj->date_validite), 'day').'</td>';
	print '<td class="center">'.dol_print_date($db->jdate($obj->date_exerce), 'day').'</td>';
	print '<td class="right">'.$voucher->getLibStatut(5).'</td>';
	print '<td></td>';
	print '</tr>';
	$i++;
}
if (!$num) {
	print '<tr><td colspan="8"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
}
print '</table></div>';
print '</form>';

llxFooter();
$db->close();
