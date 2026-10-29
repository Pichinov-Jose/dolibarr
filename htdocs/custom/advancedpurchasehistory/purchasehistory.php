<?php
/* Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    advancedpurchasehistory/purchasehistory.php
 * \ingroup advancedpurchasehistory
 * \brief   Product tab: supplier price log, supplier invoices, supplier orders and receptions in one history.
 */

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}
require_once DOL_DOCUMENT_ROOT.'/core/lib/product.lib.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/dolgraph.class.php';
dol_include_once('/advancedpurchasehistory/class/purchasehistory.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('companies', 'products', 'suppliers', 'bills', 'orders', 'receptions', 'advancedpurchasehistory@advancedpurchasehistory'));

$id = GETPOSTINT('id');
$ref = GETPOST('ref', 'alpha');
$action = GETPOST('action', 'aZ09');

$object = new Product($db);
if ($object->fetch($id, $ref) <= 0) {
	accessforbidden();
}
restrictedArea($user, 'produit|service', $object->id, 'product&product');
if (!$user->hasRight('advancedpurchasehistory', 'read')) {
	accessforbidden();
}

$allsources = PurchaseHistory::sources();
$removefilter = (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter', 'alpha'));
$sources = array();
foreach ($allsources as $s) {
	if (GETPOSTINT('src_'.$s)) {
		$sources[] = $s;
	}
}
if (!GETPOSTINT('filtered') || empty($sources) || $removefilter) {
	$sources = $allsources;
}
$search_socid = ($removefilter ? 0 : GETPOSTINT('search_socid'));
$datefrom = ($removefilter ? '' : dol_mktime(0, 0, 0, GETPOSTINT('datefrommonth'), GETPOSTINT('datefromday'), GETPOSTINT('datefromyear')));
$dateto = ($removefilter ? '' : dol_mktime(23, 59, 59, GETPOSTINT('datetomonth'), GETPOSTINT('datetoday'), GETPOSTINT('datetoyear')));

$limit = (GETPOSTISSET('limit') ? GETPOSTINT('limit') : 100);
$page = (GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT('page'));
if (empty($page) || $page < 0) {
	$page = 0;
}

$history = new PurchaseHistory($db);
$allrows = $history->fetchRows($object->id);
if (!is_array($allrows)) {
	dol_print_error($db, $history->error);
	exit;
}
$rows = PurchaseHistory::filterRows($allrows, $sources, $search_socid, $datefrom, $dateto);

$sourcelabels = array();
foreach ($allsources as $s) {
	$sourcelabels[$s] = $langs->trans('PurchaseHistorySource'.ucfirst($s));
}


/*
 * Actions
 */

if ($action == 'exportcsv') {
	header('Content-Type: text/csv; charset=UTF-8');
	header('Content-Disposition: attachment; filename="'.dol_sanitizeFileName('purchase-history-'.$object->ref).'.csv"');
	$out = fopen('php://output', 'w');
	fwrite($out, "\xEF\xBB\xBF");
	fputcsv($out, array($langs->transnoentitiesnoconv('Date'), $langs->transnoentitiesnoconv('PurchaseHistorySources'), $langs->transnoentitiesnoconv('Supplier'),
		$langs->transnoentitiesnoconv('RefSupplier'), $langs->transnoentitiesnoconv('Document'), $langs->transnoentitiesnoconv('Qty'),
		$langs->transnoentitiesnoconv('PriceUHT'), $langs->transnoentitiesnoconv('ReductionShort'), $langs->transnoentitiesnoconv('PurchaseHistoryNetPrice'),
		$langs->transnoentitiesnoconv('PurchaseHistoryVariation'), $langs->transnoentitiesnoconv('Author')), ';');
	// spreadsheets opened in the user's locale expect its decimal separator
	$decimal = $langs->transnoentitiesnoconv('SeparatorDecimal');
	$num = function ($value) use ($decimal) {
		return ($value === '' ? '' : str_replace('.', $decimal, (string) $value));
	};
	foreach ($rows as $row) {
		fputcsv($out, array(
			dol_print_date($row['date'], 'day'),
			$langs->transnoentitiesnoconv('PurchaseHistorySource'.ucfirst($row['source'])),
			(isset($history->suppliers[$row['socid']]) ? $history->suppliers[$row['socid']]->name : ''),
			$row['ref_fourn'],
			$row['docref'],
			($row['qty'] === null ? '' : $num(price2num($row['qty']))),
			$num(price2num($row['pu'], 'MU')),
			($row['remise'] === null ? '' : $num(price2num($row['remise']))),
			$num(price2num($row['net'], 'MU')),
			($row['variation'] === null ? '' : $num(price2num($row['variation'], 2))),
			$row['author'],
		), ';');
	}
	fclose($out);
	exit;
}


/*
 * View
 */

$form = new Form($db);

$title = $langs->trans('PurchaseHistoryTab').' - '.$object->ref;
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-advancedpurchasehistory page-purchasehistory');

$head = product_prepare_head($object);
$picto = ($object->type == Product::TYPE_SERVICE ? 'service' : 'product');
print dol_get_fiche_head($head, 'purchasehistory', $langs->trans("CardProduct".$object->type), -1, $picto);

$linkback = '<a href="'.DOL_URL_ROOT.'/product/list.php?restore_lastsearch_values=1">'.$langs->trans("BackToList").'</a>';
$shownav = 1;
if ($user->socid && !in_array('product', explode(',', getDolGlobalString('MAIN_MODULES_FOR_EXTERNAL')))) {
	$shownav = 0;
}
dol_banner_tab($object, 'ref', $linkback, $shownav, 'ref');

print '<div class="fichecenter"><div class="underbanner clearboth"></div></div>';
print dol_get_fiche_end();

/**
 * Link to the document behind a row
 *
 * @param array<string,mixed> $row       History row
 * @param Product             $object    Product
 * @return string
 */
function purchasehistory_doclink($row, $object)
{
	if ($row['doctype'] == 'pricelog') {
		return '<a href="'.DOL_URL_ROOT.'/product/price_suppliers.php?id='.((int) $object->id).'">'.img_picto('', 'fa-history', 'class="pictofixedwidth"').dol_escape_htmltag($row['ref_fourn']).'</a>';
	}
	if (empty($row['docid'])) {
		return '';
	}
	$reffourn = ($row['ref_fourn'] !== '' ? ' <span class="opacitymedium small">'.dol_escape_htmltag($row['ref_fourn']).'</span>' : '');
	$urls = array(
		'supplier_invoice' => array('/fourn/facture/card.php?facid=', 'supplier_invoice'),
		'order_supplier' => array('/fourn/commande/card.php?id=', 'supplier_order'),
		'reception' => array('/reception/card.php?id=', 'dolly'),
	);
	if (!isset($urls[$row['doctype']])) {
		return dol_escape_htmltag($row['docref']).$reffourn;
	}
	return '<a href="'.DOL_URL_ROOT.$urls[$row['doctype']][0].((int) $row['docid']).'">'.img_picto('', $urls[$row['doctype']][1], 'class="pictofixedwidth"').dol_escape_htmltag($row['docref']).'</a>'.$reffourn;
}

/**
 * Coloured variation, red when the price rises
 *
 * @param float|null $variation Percent
 * @return string
 */
function purchasehistory_variation($variation)
{
	if ($variation === null || abs($variation) < 0.05) {
		return '';
	}
	$class = ($variation > 0 ? 'error' : 'ok');
	return '<span class="'.$class.'">'.($variation > 0 ? '+' : '').price(price2num($variation, 1), 0, '', 0, 1, 1).' %</span>';
}

if (empty($allrows) && empty($history->currentPrices)) {
	print '<div class="opacitymedium margintoponly">'.$langs->trans('PurchaseHistoryNoData').'</div>';
	llxFooter();
	$db->close();
	exit;
}

// Summary per supplier
$summary = $history->summary($rows);
print load_fiche_titre($langs->trans('PurchaseHistorySummary'), '', 'supplier');
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans('Supplier').'</th>';
print '<th class="right">'.$langs->trans('PurchaseHistoryCurrentPrice').'</th>';
print '<th class="right">'.$langs->trans('PurchaseHistoryLastPrice').'</th>';
print '<th class="center">'.$langs->trans('Date').'</th>';
print '<th class="right">'.$langs->trans('PurchaseHistoryMin').'</th>';
print '<th class="right">'.$langs->trans('PurchaseHistoryMax').'</th>';
print '<th class="right">'.$langs->trans('PurchaseHistoryNbEvents').'</th>';
print '<th class="right">'.$langs->trans('PurchaseHistoryQtyReceived').'</th>';
print '</tr>';
foreach ($summary as $socid => $s) {
	print '<tr class="oddeven">';
	print '<td class="tdoverflowmax250">'.(isset($history->suppliers[$socid]) ? $history->suppliers[$socid]->getNomUrl(1, 'supplier') : $langs->trans('Unknown')).'</td>';
	print '<td class="right nowraponall">'.(isset($history->currentPrices[$socid]) ? price($history->currentPrices[$socid], 0, $langs, 1, 2, 2, $conf->currency) : '').'</td>';
	print '<td class="right nowraponall"><strong>'.($s['last'] ? price($s['last']['net'], 0, $langs, 1, 2, 2, $conf->currency) : '').'</strong></td>';
	print '<td class="center nowraponall">'.($s['last'] ? dol_print_date($s['last']['date'], 'day').' <span class="opacitymedium small">'.$sourcelabels[$s['last']['source']].'</span>' : '').'</td>';
	print '<td class="right nowraponall">'.($s['min'] !== null ? price($s['min'], 0, $langs, 1, 2, 2) : '').'</td>';
	print '<td class="right nowraponall">'.($s['max'] !== null ? price($s['max'], 0, $langs, 1, 2, 2) : '').'</td>';
	print '<td class="right">'.$s['nb'].'</td>';
	print '<td class="right">'.($s['qtyreceived'] ? price2num($s['qtyreceived']) : '').'</td>';
	print '</tr>';
}
print '<tr class="liste_total"><td colspan="8">'.$langs->trans('PurchaseHistoryPMP').' : <strong>'.price($object->pmp, 0, $langs, 1, 2, 2, $conf->currency).'</strong></td></tr>';
print '</table></div>';

// Chart
list($chartdata, $chartlegend) = $history->chartData($rows);
if (count($chartdata) >= 2) {
	$px = new DolGraph();
	$px->SetData($chartdata);
	$px->SetLegend($chartlegend);
	$px->SetType(array_fill(0, count($chartlegend), 'lines'));
	$px->setShowLegend(1);
	$px->SetWidth('100%');
	$px->SetHeight(320);
	$px->SetYLabel($langs->trans('PurchaseHistoryNetPrice'));
	$px->SetTitle($langs->trans('PurchaseHistoryChart'));
	$px->draw('purchasehistorychart'.$object->id);
	print '<div class="fichecenter margintoponly">'.$px->show().'</div>';
}

// Filters and detailed list
$param = '&id='.((int) $object->id).'&filtered=1';
foreach ($sources as $s) {
	$param .= '&src_'.$s.'=1';
}
if ($search_socid > 0) {
	$param .= '&search_socid='.((int) $search_socid);
}
if ($datefrom !== '') {
	$param .= '&datefromday='.dol_print_date($datefrom, '%d').'&datefrommonth='.dol_print_date($datefrom, '%m').'&datefromyear='.dol_print_date($datefrom, '%Y');
}
if ($dateto !== '') {
	$param .= '&datetoday='.dol_print_date($dateto, '%d').'&datetomonth='.dol_print_date($dateto, '%m').'&datetoyear='.dol_print_date($dateto, '%Y');
}
if ($limit != 100) {
	$param .= '&limit='.((int) $limit);
}

$nbtotal = count($rows);
$pagerows = ($limit > 0 ? array_slice($rows, $page * $limit, $limit) : $rows);

$exportbutton = dolGetButtonTitle($langs->trans('Export'), '', 'fa fa-file-csv', $_SERVER['PHP_SELF'].'?action=exportcsv&token='.newToken().$param);

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="id" value="'.((int) $object->id).'">';
print '<input type="hidden" name="filtered" value="1">';
print_barre_liste($langs->trans('PurchaseHistoryLines'), $page, $_SERVER['PHP_SELF'], $param, '', '', '', count($pagerows), $nbtotal, 'fa-history', 0, $exportbutton, '', $limit, 0, 0, 1);

print '<div class="liste_titre liste_titre_bydiv centpercent marginbottomonly">';
print '<div class="divsearchfield">'.$langs->trans('PurchaseHistorySources').' : ';
foreach ($allsources as $s) {
	print '<label class="marginrightonly nowraponall"><input type="checkbox" name="src_'.$s.'" value="1"'.(in_array($s, $sources) ? ' checked' : '').'> '.$sourcelabels[$s].'</label>';
}
print '</div>';
$supplieroptions = array();
foreach ($history->suppliers as $socid => $soc) {
	$supplieroptions[$socid] = $soc->name;
}
asort($supplieroptions);
print '<div class="divsearchfield">'.$langs->trans('Supplier').' : '.$form->selectarray('search_socid', $supplieroptions, $search_socid, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth200').'</div>';
print '<div class="divsearchfield">'.$langs->trans('DateStart').' : '.$form->selectDate($datefrom, 'datefrom', 0, 0, 1, '', 1, 0).'</div>';
print '<div class="divsearchfield">'.$langs->trans('DateEnd').' : '.$form->selectDate($dateto, 'dateto', 0, 0, 1, '', 1, 0).'</div>';
print '<div class="divsearchfield">'.$form->showFilterButtons().'</div>';
print '</div>';

print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th class="center">'.$langs->trans('Date').'</th>';
print '<th>'.$langs->trans('PurchaseHistorySources').'</th>';
print '<th>'.$langs->trans('Supplier').'</th>';
print '<th>'.$langs->trans('Document').'</th>';
print '<th class="right">'.$langs->trans('Qty').'</th>';
print '<th class="right">'.$langs->trans('PriceUHT').'</th>';
print '<th class="right">'.$langs->trans('ReductionShort').'</th>';
print '<th class="right">'.$langs->trans('PurchaseHistoryNetPrice').'</th>';
print '<th class="right">'.$langs->trans('PurchaseHistoryVariation').'</th>';
print '<th>'.$langs->trans('Author').'</th>';
print '</tr>';
if (empty($pagerows)) {
	print '<tr class="oddeven"><td colspan="10"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
}
$badges = array('log' => 'status4', 'invoice' => 'status1', 'order' => 'status3', 'reception' => 'status6');
foreach ($pagerows as $row) {
	print '<tr class="oddeven">';
	print '<td class="center nowraponall">'.dol_print_date($row['date'], 'day').'</td>';
	print '<td class="nowraponall">'.dolGetBadge($sourcelabels[$row['source']], '', $badges[$row['source']]).'</td>';
	print '<td class="tdoverflowmax200">'.(isset($history->suppliers[$row['socid']]) ? $history->suppliers[$row['socid']]->getNomUrl(1, 'supplier') : '').'</td>';
	print '<td class="tdoverflowmax200">'.purchasehistory_doclink($row, $object).'</td>';
	print '<td class="right">'.($row['qty'] === null ? '' : price2num($row['qty'])).'</td>';
	print '<td class="right nowraponall">'.price($row['pu'], 0, $langs, 1, 2, 2).'</td>';
	print '<td class="right nowraponall">'.($row['remise'] ? price2num($row['remise']).' %' : '').'</td>';
	print '<td class="right nowraponall"><strong>'.price($row['net'], 0, $langs, 1, 2, 2).'</strong></td>';
	print '<td class="right nowraponall">'.purchasehistory_variation($row['variation']).'</td>';
	print '<td class="tdoverflowmax100">'.dol_escape_htmltag($row['author']).'</td>';
	print '</tr>';
}
print '</table></div>';
print '</form>';

llxFooter();
$db->close();
