<?php
/* Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * Associations code → produit apprises par le scanner (llx_scan_assoc) —
 * liste Dolibarr standard : filtres, tri, pagination. Lecture seule.
 */
$res = 0;
if (!$res && file_exists('../../main.inc.php')) $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
$langs->load('scancapture@scancapture');
if (!$user->admin && !$user->hasRight('stock', 'creer')) accessforbidden();

$form = new Form($db);

$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT('page');
if (empty($page) || $page < 0) { $page = 0; }
if (!$sortfield) { $sortfield = 'sa.rowid'; }
if (!$sortorder) { $sortorder = 'DESC'; }
$offset = $limit * $page;

$search_code = GETPOST('search_code', 'alphanohtml');
$search_product = GETPOST('search_product', 'alphanohtml');
$search_written = GETPOST('search_written', 'alpha');

if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$search_code = ''; $search_product = ''; $search_written = '';
}

// Cibles d'écriture réellement présentes en base
$writtens = array();
$r2 = $db->query("SELECT DISTINCT written_to FROM ".MAIN_DB_PREFIX."scan_assoc WHERE written_to IS NOT NULL AND written_to <> '' ORDER BY written_to");
if ($r2) { while ($o = $db->fetch_object($r2)) { $writtens[$o->written_to] = $o->written_to; } }

$sqlfields = "SELECT sa.rowid, sa.datec, sa.code, sa.fk_product, sa.written_to, u.login AS user_login, p.ref AS product_ref, p.label AS product_label";
$sqlbase = " FROM ".MAIN_DB_PREFIX."scan_assoc sa";
$sqlbase .= " LEFT JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = sa.fk_product";
$sqlbase .= " LEFT JOIN ".MAIN_DB_PREFIX."user u ON u.rowid = sa.fk_user";
$sqlwhere = " WHERE 1 = 1";
if ($search_code !== '') {
	$sqlwhere .= natural_search(array('sa.code'), $search_code);
}
if ($search_product !== '') {
	$sqlwhere .= natural_search(array('p.ref', 'p.label'), $search_product);
}
if ($search_written !== '' && isset($writtens[$search_written])) {
	$sqlwhere .= " AND sa.written_to = '".$db->escape($search_written)."'";
}

$nbtotalofrecords = 0;
$resql = $db->query("SELECT COUNT(*) AS nb".$sqlbase.$sqlwhere);
if ($resql && ($o = $db->fetch_object($resql))) { $nbtotalofrecords = (int) $o->nb; }

$sql = $sqlfields.$sqlbase.$sqlwhere.$db->order($sortfield, $sortorder).$db->plimit($limit + 1, $offset);
$resql = $db->query($sql);
if (!$resql) { dol_print_error($db); exit; }
$num = $db->num_rows($resql);

llxHeader('', $langs->trans('ScanAssocTitle'));

$param = '';
if ($limit > 0 && $limit != $conf->liste_limit) { $param .= '&limit='.((int) $limit); }
if ($search_code !== '') { $param .= '&search_code='.urlencode($search_code); }
if ($search_product !== '') { $param .= '&search_product='.urlencode($search_product); }
if ($search_written !== '') { $param .= '&search_written='.urlencode($search_written); }

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" name="scassoc">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="sortfield" value="'.dol_escape_htmltag($sortfield).'">';
print '<input type="hidden" name="sortorder" value="'.dol_escape_htmltag($sortorder).'">';

print_barre_liste($langs->trans('ScanAssocTitle'), $page, $_SERVER['PHP_SELF'], $param, $sortfield, $sortorder, '', $num, $nbtotalofrecords, 'barcode', 0, '', '', $limit, 0, 0, 1);

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste centpercent">';

print '<tr class="liste_titre_filter">';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth125" name="search_code" value="'.dol_escape_htmltag($search_code).'"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth150" name="search_product" value="'.dol_escape_htmltag($search_product).'"></td>';
print '<td class="liste_titre">'.$form->selectarray('search_written', $writtens, $search_written, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth100').'</td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre center">'.$form->showFilterButtons().'</td>';
print '</tr>';

print '<tr class="liste_titre">';
print getTitleFieldOfList('#', 0, $_SERVER['PHP_SELF'], 'sa.rowid', '', $param, '', $sortfield, $sortorder);
print getTitleFieldOfList($langs->trans('Date'), 0, $_SERVER['PHP_SELF'], 'sa.datec', '', $param, '', $sortfield, $sortorder, 'center ');
print getTitleFieldOfList($langs->trans('ScanAssocCode'), 0, $_SERVER['PHP_SELF'], 'sa.code', '', $param, '', $sortfield, $sortorder);
print getTitleFieldOfList($langs->trans('Product'), 0, $_SERVER['PHP_SELF'], 'p.ref', '', $param, '', $sortfield, $sortorder);
print getTitleFieldOfList($langs->trans('WrittenTo'), 0, $_SERVER['PHP_SELF'], 'sa.written_to', '', $param, '', $sortfield, $sortorder);
print getTitleFieldOfList($langs->trans('User'), 0, $_SERVER['PHP_SELF'], 'u.login', '', $param, '', $sortfield, $sortorder);
print getTitleFieldOfList('', 0, $_SERVER['PHP_SELF']);
print '</tr>';

$i = 0;
while ($i < min($num, $limit)) {
	$o = $db->fetch_object($resql);
	print '<tr class="oddeven">';
	print '<td>'.((int) $o->rowid).'</td>';
	print '<td class="center nowraponall">'.dol_print_date($db->jdate($o->datec), 'dayhour', 'tzuserrel').'</td>';
	print '<td class="nowraponall">'.dol_escape_htmltag((string) $o->code).'</td>';
	if ($o->fk_product > 0) {
		print '<td class="tdoverflowmax300"><a href="'.DOL_URL_ROOT.'/product/card.php?id='.((int) $o->fk_product).'" class="classforajaxtooltip" data-params=\'{"id":'.((int) $o->fk_product).',"objecttype":"product"}\'><span class="fa fa-cube paddingright" style="color:#4a6785"></span>'.dol_escape_htmltag((string) $o->product_ref.' — '.(string) $o->product_label).'</a></td>';
	} else {
		print '<td><span class="opacitymedium">'.$langs->trans('ProductDeleted').'</span></td>';
	}
	print '<td>'.dol_escape_htmltag((string) $o->written_to).'</td>';
	print '<td>'.dol_escape_htmltag((string) $o->user_login).'</td>';
	print '<td></td>';
	print '</tr>';
	$i++;
}
if (!$num) {
	print '<tr><td colspan="7"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
}

print '</table>';
print '</div>';
print '</form>';

llxFooter();
$db->close();
