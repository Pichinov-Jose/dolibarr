<?php
/* Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * Historique complet des scans (llx_scan_capture) — liste Dolibarr standard :
 * filtres, tri, pagination, totaux sur le filtre courant. Lecture seule.
 */
$res = 0;
if (!$res && file_exists('../../main.inc.php')) $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
$langs->load('scancapture@scancapture');
$langs->load('stocks');
if (!$user->admin && !$user->hasRight('stock', 'creer')) accessforbidden();

$form = new Form($db);

// Paramètres de liste
$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT('page');
if (empty($page) || $page < 0) { $page = 0; }
if (!$sortfield) { $sortfield = 'sc.rowid'; }
if (!$sortorder) { $sortorder = 'DESC'; }
$offset = $limit * $page;

// Filtres
$search_all = GETPOST('search_all', 'alphanohtml');
$search_status = GETPOST('search_status', 'alpha');
$search_sent = GETPOST('search_sent', 'alpha');
$search_inv = GETPOSTINT('search_inv');
$search_date_start = dol_mktime(0, 0, 0, GETPOSTINT('search_date_startmonth'), GETPOSTINT('search_date_startday'), GETPOSTINT('search_date_startyear'));
$search_date_end = dol_mktime(23, 59, 59, GETPOSTINT('search_date_endmonth'), GETPOSTINT('search_date_endday'), GETPOSTINT('search_date_endyear'));

if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$search_all = ''; $search_status = ''; $search_sent = ''; $search_inv = 0;
	$search_date_start = ''; $search_date_end = '';
}

$statuses = array(
	'matched' => $langs->trans('ScanStatusMatched'),
	'created' => $langs->trans('ScanStatusCreated'),
	'unknown' => $langs->trans('ScanStatusUnknown'),
	'ambiguous' => $langs->trans('ScanStatusAmbiguous'),
	'mismatch' => $langs->trans('ScanStatusMismatch'),
);
$sentchoices = array(
	'pending' => $langs->trans('SentPending'),
	'sent' => $langs->trans('SentDone'),
	'captureonly' => $langs->trans('SentCaptureOnly'),
);

// Requête
$sqlfields = "SELECT sc.rowid, sc.datec, sc.code_kezia, sc.code_saisi, sc.ean, sc.qty, sc.fk_product, sc.match_source,";
$sqlfields .= " sc.product_label, sc.status, sc.fk_inventory, sc.sent_to_inv, sc.stock_before, sc.fk_user,";
$sqlfields .= " p.ref AS product_ref, u.login AS user_login, i.ref AS inv_ref";
$sqlbase = " FROM ".MAIN_DB_PREFIX."scan_capture sc";
$sqlbase .= " LEFT JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = sc.fk_product";
$sqlbase .= " LEFT JOIN ".MAIN_DB_PREFIX."user u ON u.rowid = sc.fk_user";
$sqlbase .= " LEFT JOIN ".MAIN_DB_PREFIX."inventory i ON i.rowid = sc.fk_inventory";
$sqlwhere = " WHERE 1 = 1";
if ($search_all !== '') {
	$sqlwhere .= natural_search(array('sc.code_kezia', 'sc.code_saisi', 'sc.ean', 'sc.product_label', 'p.ref'), $search_all);
}
if ($search_status !== '' && isset($statuses[$search_status])) {
	$sqlwhere .= " AND sc.status = '".$db->escape($search_status)."'";
}
if ($search_sent === 'pending') {
	$sqlwhere .= " AND sc.sent_to_inv IS NULL AND sc.fk_product > 0 AND sc.status IN ('matched', 'created')";
} elseif ($search_sent === 'sent') {
	$sqlwhere .= " AND sc.sent_to_inv IS NOT NULL";
} elseif ($search_sent === 'captureonly') {
	$sqlwhere .= " AND (sc.fk_inventory IS NULL OR sc.fk_inventory = 0)";
}
if ($search_inv > 0) {
	$sqlwhere .= " AND sc.fk_inventory = ".((int) $search_inv);
}
if ($search_date_start) {
	$sqlwhere .= " AND sc.datec >= '".$db->idate($search_date_start)."'";
}
if ($search_date_end) {
	$sqlwhere .= " AND sc.datec <= '".$db->idate($search_date_end)."'";
}

// Nombre total + totaux sur le filtre courant (toutes pages)
$nbtotalofrecords = 0; $totqty = 0;
$resql = $db->query("SELECT COUNT(*) AS nb, SUM(sc.qty) AS tq".$sqlbase.$sqlwhere);
if ($resql && ($o = $db->fetch_object($resql))) { $nbtotalofrecords = (int) $o->nb; $totqty = (float) $o->tq; }

$sql = $sqlfields.$sqlbase.$sqlwhere.$db->order($sortfield, $sortorder).$db->plimit($limit + 1, $offset);
$resql = $db->query($sql);
if (!$resql) { dol_print_error($db); exit; }
$num = $db->num_rows($resql);

// Inventaires pour le filtre (ceux référencés par des scans)
$invs = array();
$r2 = $db->query("SELECT DISTINCT i.rowid, i.ref FROM ".MAIN_DB_PREFIX."scan_capture sc INNER JOIN ".MAIN_DB_PREFIX."inventory i ON i.rowid = sc.fk_inventory ORDER BY i.rowid DESC");
if ($r2) { while ($o = $db->fetch_object($r2)) { $invs[$o->rowid] = $o->ref; } }

llxHeader('', $langs->trans('ScanListTitle'));

$param = '';
if ($limit > 0 && $limit != $conf->liste_limit) { $param .= '&limit='.((int) $limit); }
if ($search_all !== '') { $param .= '&search_all='.urlencode($search_all); }
if ($search_status !== '') { $param .= '&search_status='.urlencode($search_status); }
if ($search_sent !== '') { $param .= '&search_sent='.urlencode($search_sent); }
if ($search_inv > 0) { $param .= '&search_inv='.((int) $search_inv); }
if ($search_date_start) { $param .= '&search_date_startday='.dol_print_date($search_date_start, '%d').'&search_date_startmonth='.dol_print_date($search_date_start, '%m').'&search_date_startyear='.dol_print_date($search_date_start, '%Y'); }
if ($search_date_end) { $param .= '&search_date_endday='.dol_print_date($search_date_end, '%d').'&search_date_endmonth='.dol_print_date($search_date_end, '%m').'&search_date_endyear='.dol_print_date($search_date_end, '%Y'); }

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" name="sclist">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="sortfield" value="'.dol_escape_htmltag($sortfield).'">';
print '<input type="hidden" name="sortorder" value="'.dol_escape_htmltag($sortorder).'">';

print_barre_liste($langs->trans('ScanListTitle'), $page, $_SERVER['PHP_SELF'], $param, $sortfield, $sortorder, '', $num, $nbtotalofrecords, 'barcode', 0, '', '', $limit, 0, 0, 1);

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste centpercent">';

// Ligne de filtres
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre"></td>'; // #
print '<td class="liste_titre center">'.$form->selectDate($search_date_start ? $search_date_start : -1, 'search_date_start', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', '', 'tzuserrel').'<br>'.$form->selectDate($search_date_end ? $search_date_end : -1, 'search_date_end', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', '', 'tzuserrel').'</td>';
print '<td class="liste_titre" colspan="3"><input type="text" class="flat maxwidth150" name="search_all" value="'.dol_escape_htmltag($search_all).'" placeholder="'.dol_escape_htmltag($langs->trans('FilterRows')).'"></td>';
print '<td class="liste_titre"></td>'; // qty
print '<td class="liste_titre"></td>'; // stock avant
print '<td class="liste_titre"></td>'; // écart
print '<td class="liste_titre">'.$form->selectarray('search_status', $statuses, $search_status, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth100').'</td>';
print '<td class="liste_titre">'.$form->selectarray('search_sent', $sentchoices, $search_sent, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth100').'</td>';
print '<td class="liste_titre">'.$form->selectarray('search_inv', $invs, $search_inv, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth100').'</td>';
print '<td class="liste_titre"></td>'; // user
print '<td class="liste_titre center">';
print $form->showFilterButtons();
print '</td>';
print '</tr>';

// Titres de colonnes
print '<tr class="liste_titre">';
print getTitleFieldOfList('#', 0, $_SERVER['PHP_SELF'], 'sc.rowid', '', $param, '', $sortfield, $sortorder);
print getTitleFieldOfList($langs->trans('Date'), 0, $_SERVER['PHP_SELF'], 'sc.datec', '', $param, '', $sortfield, $sortorder, 'center ');
print getTitleFieldOfList($langs->trans('KeziaCode'), 0, $_SERVER['PHP_SELF'], 'sc.code_kezia', '', $param, '', $sortfield, $sortorder);
print getTitleFieldOfList($langs->trans('ProductEan'), 0, $_SERVER['PHP_SELF'], 'sc.ean', '', $param, '', $sortfield, $sortorder);
print getTitleFieldOfList($langs->trans('Product'), 0, $_SERVER['PHP_SELF'], 'sc.product_label', '', $param, '', $sortfield, $sortorder);
print getTitleFieldOfList($langs->trans('Qty'), 0, $_SERVER['PHP_SELF'], 'sc.qty', '', $param, '', $sortfield, $sortorder, 'right ');
print getTitleFieldOfList($langs->trans('StockBefore'), 0, $_SERVER['PHP_SELF'], 'sc.stock_before', '', $param, '', $sortfield, $sortorder, 'right ');
print getTitleFieldOfList($langs->trans('ScanGap'), 0, $_SERVER['PHP_SELF'], '', '', $param, '', $sortfield, $sortorder, 'right ');
print getTitleFieldOfList($langs->trans('Status'), 0, $_SERVER['PHP_SELF'], 'sc.status', '', $param, '', $sortfield, $sortorder);
print getTitleFieldOfList($langs->trans('SentAtCol'), 0, $_SERVER['PHP_SELF'], 'sc.sent_to_inv', '', $param, '', $sortfield, $sortorder, 'center ');
print getTitleFieldOfList($langs->trans('Inventory'), 0, $_SERVER['PHP_SELF'], 'sc.fk_inventory', '', $param, '', $sortfield, $sortorder);
print getTitleFieldOfList($langs->trans('User'), 0, $_SERVER['PHP_SELF'], 'u.login', '', $param, '', $sortfield, $sortorder);
print getTitleFieldOfList('', 0, $_SERVER['PHP_SELF']);
print '</tr>';

$statuscss = array('matched' => 'badge badge-status4 badge-status', 'created' => 'badge badge-status4 badge-status', 'unknown' => 'badge badge-status1 badge-status', 'ambiguous' => 'badge badge-status8 badge-status', 'mismatch' => 'badge badge-status8 badge-status');

$i = 0;
while ($i < min($num, $limit)) {
	$o = $db->fetch_object($resql);
	print '<tr class="oddeven">';
	print '<td>'.((int) $o->rowid).'</td>';
	print '<td class="center nowraponall">'.dol_print_date($db->jdate($o->datec), 'dayhour', 'tzuserrel').'</td>';
	$ck = dol_escape_htmltag((string) $o->code_kezia);
	if (!empty($o->code_saisi) && $o->code_saisi != $o->code_kezia) {
		$ck .= ' <span class="opacitymedium classfortooltip" title="'.dol_escape_htmltag($langs->trans('ScannedCode').' : '.$o->code_saisi).'">*</span>';
	}
	print '<td class="nowraponall">'.$ck.'</td>';
	print '<td class="nowraponall">'.dol_escape_htmltag((string) $o->ean).'</td>';
	if ($o->fk_product > 0) {
		print '<td class="tdoverflowmax200"><a href="'.DOL_URL_ROOT.'/product/card.php?id='.((int) $o->fk_product).'" class="classforajaxtooltip" data-params=\'{"id":'.((int) $o->fk_product).',"objecttype":"product"}\'><span class="fa fa-cube paddingright" style="color:#4a6785"></span>'.dol_escape_htmltag((string) ($o->product_ref ? $o->product_ref.' — ' : '').(string) $o->product_label).'</a></td>';
	} else {
		print '<td class="tdoverflowmax200">'.dol_escape_htmltag((string) $o->product_label).'</td>';
	}
	print '<td class="right">'.price2num($o->qty).'</td>';
	print '<td class="right">'.($o->stock_before !== null ? price2num($o->stock_before) : '<span class="opacitymedium">—</span>').'</td>';
	if ($o->stock_before !== null) {
		$e = (float) $o->qty - (float) $o->stock_before;
		$c = ($e == 0 ? '#2e7d32' : ($e > 0 ? '#b26a00' : '#b71c1c'));
		print '<td class="right"><span style="color:'.$c.';font-weight:bold">'.($e > 0 ? '+' : '').price2num($e).'</span></td>';
	} else {
		print '<td class="right"><span class="opacitymedium">—</span></td>';
	}
	$st = (string) $o->status;
	print '<td><span class="'.(isset($statuscss[$st]) ? $statuscss[$st] : 'badge badge-status0 badge-status').'" title="'.dol_escape_htmltag($st.($o->match_source ? ' · '.$langs->trans('MatchSourceCol').' : '.$o->match_source : '')).'">'.dol_escape_htmltag(isset($statuses[$st]) ? $statuses[$st] : $st).'</span></td>';
	print '<td class="center nowraponall">'.($o->sent_to_inv ? dol_print_date($db->jdate($o->sent_to_inv), 'dayhour', 'tzuserrel') : ($o->fk_product > 0 && in_array($st, array('matched', 'created')) ? '<span class="fa fa-clock-o" style="color:#b26a00" title="'.dol_escape_htmltag($langs->trans('SentPending')).'"></span>' : '<span class="opacitymedium">—</span>')).'</td>';
	print '<td class="nowraponall">'.($o->fk_inventory > 0 ? '<a href="'.DOL_URL_ROOT.'/product/inventory/card.php?id='.((int) $o->fk_inventory).'">'.dol_escape_htmltag((string) ($o->inv_ref ? $o->inv_ref : '#'.$o->fk_inventory)).'</a>' : '<span class="opacitymedium">'.$langs->trans('SentCaptureOnly').'</span>').'</td>';
	print '<td>'.dol_escape_htmltag((string) $o->user_login).'</td>';
	print '<td></td>';
	print '</tr>';
	$i++;
}
if (!$num) {
	print '<tr><td colspan="13"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
}

// Totaux du filtre courant (toutes pages)
if ($nbtotalofrecords) {
	print '<tr class="liste_total">';
	print '<td colspan="5">'.$langs->trans('Total').' — '.$nbtotalofrecords.' '.$langs->trans('ScanLinesShort').'</td>';
	print '<td class="right">'.price2num($totqty).'</td>';
	print '<td colspan="7"></td>';
	print '</tr>';
}

print '</table>';
print '</div>';
print '</form>';

llxFooter();
$db->close();
