<?php
/* Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
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
 * Enrichment data: every result stored by EAN (cache of the scanner and of the module), coherence check,
 * detail of an EAN with its actions (look up a source again, set eBay aside, delete).
 * The cache of the scanner is never changed: a correction is stored in the cache of the module, which then wins.
 */
$res = 0;
if (!$res && file_exists('../main.inc.php')) {
	$res = @include '../main.inc.php';
}
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}
dol_include_once('/productenrichment/class/productenrichment.class.php');
dol_include_once('/productenrichment/lib/productenrichment_view.lib.php');

if (!isModEnabled('productenrichment') || !$user->hasRight('produit', 'lire')) {
	accessforbidden();
}
$langs->loadLangs(array('products', 'categories', 'productenrichment@productenrichment'));

$action = GETPOST('action', 'aZ09');
$ean = ProductEnrichment::normalizeEan(GETPOST('ean', 'alphanohtml'));
$canwrite = $user->hasRight('produit', 'creer');
$pe = new ProductEnrichment($db);

// Filters of the list (kept when going to the detail and back)
$search = trim(GETPOST('search_all', 'alphanohtml'));
$fsource = GETPOST('f_source', 'aZ09');
$fanomaly = GETPOST('f_anomaly', 'aZ09');
$flinked = GETPOST('f_linked', 'aZ09');
$ffound = GETPOST('f_found', 'aZ09');
if (!GETPOSTISSET('f_found') && !GETPOSTISSET('search_all')) {
	$ffound = 'found';	// The scans for which nothing was ever found are many: hidden at first
}
$forigin = GETPOST('f_origin', 'aZ09');
$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : 50;
$page = max(0, GETPOSTINT('page'));
$filters = array('search_all' => $search, 'f_source' => $fsource, 'f_anomaly' => $fanomaly, 'f_linked' => $flinked, 'f_found' => $ffound, 'f_origin' => $forigin);
$listparams = http_build_query(array_filter($filters, 'strlen') + ($ffound === '' ? array('f_found' => '') : array()));

/*
 * Actions on one EAN (POST, the token is checked by main.inc.php)
 */
if ($ean !== '' && in_array($action, array('refresh', 'rejectebay', 'delete')) && $_SERVER['REQUEST_METHOD'] == 'POST') {
	if (!$canwrite) {
		accessforbidden();
	}
	$back = $_SERVER['PHP_SELF'].'?ean='.urlencode($ean).($listparams !== '' ? '&'.$listparams : '');
	if ($action == 'refresh') {
		$source = GETPOST('source', 'aZ09');
		if (!in_array($source, array('upc', 'ebay', 'ai'))) {
			accessforbidden();
		}
		$context = array();
		$linked = $pe->productsByEan(array($ean));
		if (!empty($linked[$ean])) {
			$first = reset($linked[$ean]);
			$context = array('label' => $first['label'], 'fk_product' => $first['id']);
		}
		$r = $pe->refreshSource($ean, $source, $context);
		$msg = $langs->trans('PeRefreshed', $langs->transnoentitiesnoconv('PeSrc_'.$source), $langs->transnoentitiesnoconv('PeJs_Step_'.$r['status']));
		setEventMessages($msg.(!empty($r['detail']) ? ' ('.$r['detail'].')' : ''), null, in_array($r['status'], array('ok')) ? 'mesgs' : 'warnings');
	} elseif ($action == 'rejectebay') {
		if ($pe->rejectEbay($ean)) {
			setEventMessages($langs->trans('PeEbaySetAside'), null, 'mesgs');
		}
	} elseif ($action == 'delete') {
		$pe->deleteEntry($ean);
		setEventMessages($langs->trans('PeEntryDeleted', $ean), null, 'mesgs');
		$back = $_SERVER['PHP_SELF'].($listparams !== '' ? '?'.$listparams : '');
	}
	header('Location: '.$back);
	exit;
}

/*
 * View
 */
$title = $langs->trans('PeDataTitle');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-productenrichment page-data');
print peViewCss();

// Detail of one EAN
if ($ean !== '') {
	$info = $pe->getCached($ean);
	$all = $pe->allEntries();
	$entry = $all[$ean] ?? null;
	$linked = $pe->productsByEan(array($ean), array($ean => ($entry ? $entry['scanproducts'] : array())));
	$products = $linked[$ean] ?? array();

	$backlink = '<a href="'.$_SERVER['PHP_SELF'].($listparams !== '' ? '?'.$listparams : '').'">'.img_picto('', 'fa-arrow-left', 'class="pictofixedwidth"').$langs->trans('PeBackToList').'</a>';
	print load_fiche_titre($title.' — EAN '.dol_escape_htmltag($ean), $backlink, 'product');

	if (!$info) {
		print '<div class="warning">'.$langs->trans('PeUnknownEan').'</div>';
		llxFooter();
		$db->close();
		exit;
	}

	print '<div class="fichecenter"><table class="border centpercent tableforfield">';
	print '<tr><td class="titlefield">EAN</td><td><strong>'.dol_escape_htmltag($ean).'</strong></td></tr>';
	print '<tr><td>'.$langs->trans('PeSources').'</td><td>'.peViewSourceBadges($info).'</td></tr>';
	$origin = ($entry ? $entry['origin'] : 'module');
	$otxt = $langs->trans('PeOrigin_'.$origin);
	if ($entry && $entry['scans']) {
		$otxt .= ' — '.$langs->trans('PeScans', $entry['scans']);
	}
	if ($entry && $entry['date']) {
		$otxt .= ' — '.dol_print_date($entry['date'], 'dayhour');
	}
	print '<tr><td>'.$langs->trans('PeStoredIn').'</td><td>'.$otxt.'</td></tr>';
	if ($entry && count($entry['kezia'])) {
		print '<tr><td>'.$langs->trans('PeKeziaCodes').'</td><td>'.dol_escape_htmltag(implode(', ', $entry['kezia'])).'</td></tr>';
	}
	print '<tr><td>'.$langs->trans('PeLinkedProducts').'</td><td>'.peViewProducts($products, $info, true);
	if (!count($products) && $user->hasRight('produit', 'creer')) {
		print ' &nbsp; <a class="button smallpaddingimp" href="'.DOL_URL_ROOT.'/product/card.php?action=create&type=0&barcode='.urlencode($ean).'">'.img_picto('', 'fa-plus', 'class="pictofixedwidth"').$langs->trans('PeCreateFromEan').'</a>';
	}
	print '</td></tr>';
	$anom = ProductEnrichment::anomalies($info, $products);
	print '<tr><td>'.$langs->trans('PeCoherence').'</td><td>'.(count(array_diff($anom, array('ebay_rejected'))) ? peViewAnomalyBadges($anom) : '<span class="pe-badge pe-b-ok">'.$langs->trans('PeCoherent').'</span>').'</td></tr>';
	print '</table></div>';

	print '<br>'.load_fiche_titre($langs->trans('PeStoredData'), '', '');
	print peViewInfo($info);

	// Raw data, for the cases the table does not show
	print '<details class="margintoponly"><summary class="opacitymedium cursorpointer">'.$langs->trans('PeRawData').'</summary>';
	print '<pre class="small" style="white-space: pre-wrap; word-break: break-word; max-height: 400px; overflow: auto;">'.dol_escape_htmltag(json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)).'</pre></details>';

	// Actions
	if ($canwrite) {
		$q = ProductEnrichment::quotaStatus();
		$sources = ProductEnrichment::sourcesConfigured();
		$form = function ($act, $label, $picto, $more = '', $confirm = '', $class = 'butAction') use ($ean, $listparams) {
			$h = '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?ean='.urlencode($ean).($listparams !== '' ? '&'.dol_escape_htmltag($listparams) : '').'" class="inline-block"'.($confirm !== '' ? ' onsubmit="return confirm(\''.dol_escape_js($confirm).'\');"' : '').'>';
			$h .= '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="'.$act.'">'.$more;
			$h .= '<button type="submit" class="'.$class.' reposition">'.img_picto('', $picto, 'class="pictofixedwidth"').$label.'</button></form>';
			return $h;
		};
		print '<div class="tabsAction">';
		print $form('refresh', $langs->trans('PeRefreshSource', 'UPCitemdb'), 'fa-sync', '<input type="hidden" name="source" value="upc">');
		if (!empty($sources['ebay'])) {
			print $form('refresh', $langs->trans('PeRefreshSource', 'eBay'), 'fa-sync', '<input type="hidden" name="source" value="ebay">');
		}
		if (!empty($sources['ai'])) {
			print $form('refresh', $langs->trans('PeRefreshSource', $langs->transnoentitiesnoconv('PeAi')), 'fa-robot', '<input type="hidden" name="source" value="ai">', $langs->transnoentitiesnoconv('PeConfirmAi', $q['ai']['used'], $q['ai']['max']));
		}
		if (count((array) ($info['titles'] ?? array())) || ($info['images_from'] ?? '') === 'ebay') {
			print $form('rejectebay', $langs->trans('PeRejectEbay'), 'fa-ban', '', $langs->transnoentitiesnoconv('PeConfirmRejectEbay'));
		}
		print $form('delete', $langs->trans('PeDeleteEntry'), 'fa-trash', '', $langs->transnoentitiesnoconv('PeConfirmDeleteEntry'), 'butActionDelete');
		print '</div>';
		print '<div class="opacitymedium small">'.$langs->trans('PeQuotaToday').' : UPCitemdb '.$q['upc']['used'].'/'.$q['upc']['max'].', eBay '.$q['ebay']['used'].'/'.$q['ebay']['max'].', '.$langs->trans('PeAi').' '.$q['ai']['used'].'/'.$q['ai']['max'].'. '.$langs->trans('PeScannerUntouched').'</div>';
	}

	llxFooter();
	$db->close();
	exit;
}

// List with the coherence check
$all = $pe->allEntries();
$scanproducts = array();
foreach ($all as $e => $entry) {
	if (count($entry['scanproducts'])) {
		$scanproducts[$e] = $entry['scanproducts'];
	}
}
$linked = $pe->productsByEan(array_keys($all), $scanproducts);

$counts = array('all' => 0, 'found' => 0, 'notfound' => 0, 'src_upc' => 0, 'src_ebay' => 0, 'src_ai' => 0, 'linked' => 0, 'unlinked' => 0, 'ebay_rejected' => 0, 'ebay_unrelated' => 0, 'label_unrelated' => 0, 'coherent' => 0, 'corrected' => 0, 'unlinked_found' => 0);
$rows = array();
$needle = dol_string_unaccent(mb_strtolower($search));
foreach ($all as $e => $entry) {
	$info = $entry['info'];
	$products = $linked[$e] ?? array();
	$found = peViewHasData($info);
	$anom = ProductEnrichment::anomalies($info, $products);
	$src = array(
		'upc' => (!empty($info['upc']) || !empty($info['category']) || !empty($info['image']) || ((string) ($info['title'] ?? '') !== '' && empty($info['ebay']) && empty($info['ai']))),
		'ebay' => (!empty($info['ebay']) && count((array) ($info['titles'] ?? array()))),
		'ai' => !empty($info['ai']),
	);
	$problems = array_diff($anom, array('ebay_rejected'));

	$counts['all']++;
	$counts[$found ? 'found' : 'notfound']++;
	foreach ($src as $k => $v) {
		if ($v) {
			$counts['src_'.$k]++;
		}
	}
	$counts[count($products) ? 'linked' : 'unlinked']++;
	if ($found && !count($products)) {
		$counts['unlinked_found']++;
	}
	foreach ($anom as $a) {
		$counts[$a]++;
	}
	if ($found && !count($problems)) {
		$counts['coherent']++;
	}
	if (!empty($info['pe_override'])) {
		$counts['corrected']++;
	}

	// Filters
	if ($ffound === 'found' && !$found) {
		continue;
	}
	if ($ffound === 'notfound' && $found) {
		continue;
	}
	if ($fsource !== '' && ($fsource === 'none' ? ($src['upc'] || $src['ebay'] || $src['ai']) : empty($src[$fsource]))) {
		continue;
	}
	if ($fanomaly === 'any' && !count($problems)) {
		continue;
	}
	if ($fanomaly === 'none' && count($problems)) {
		continue;
	}
	if (in_array($fanomaly, array('ebay_rejected', 'ebay_unrelated', 'label_unrelated')) && !in_array($fanomaly, $anom)) {
		continue;
	}
	if ($flinked === 'linked' && !count($products)) {
		continue;
	}
	if ($flinked === 'unlinked' && count($products)) {
		continue;
	}
	if ($forigin === 'corrected' && empty($info['pe_override'])) {
		continue;
	}
	if (in_array($forigin, array('scanner', 'module', 'both')) && $entry['origin'] !== $forigin) {
		continue;
	}
	if ($needle !== '') {
		$hay = array($e, (string) ($info['title'] ?? ''), (string) ($info['brand'] ?? ''), (string) ($info['mpn'] ?? ''), (string) ($info['famille'] ?? ''), implode(' ', $entry['kezia']), implode(' ', (array) ($info['titles'] ?? array())));
		foreach ($products as $p) {
			$hay[] = $p['ref'].' '.$p['label'];
		}
		if (strpos(dol_string_unaccent(mb_strtolower(implode(' ', $hay))), $needle) === false) {
			continue;
		}
	}
	$rows[] = array('ean' => $e, 'entry' => $entry, 'products' => $products, 'anom' => $anom);
}
usort($rows, function ($a, $b) {
	return $b['entry']['date'] <=> $a['entry']['date'];
});
$nbtotal = count($rows);
if ($page * $limit >= $nbtotal) {
	$page = 0;
}

print load_fiche_titre($title, '', 'product');

// Coherence check of everything stored: each number opens the matching list
$link = function ($params, $label, $n) {
	return '<a href="'.$_SERVER['PHP_SELF'].'?'.http_build_query($params).'">'.$label.' : <strong>'.((int) $n).'</strong></a>';
};
$sep = ' &nbsp;·&nbsp; ';
print '<div class="info">';
print '<strong>'.$langs->trans('PeCoherenceCheck').'</strong> — '.$langs->trans('PeKnownEans', $counts['all']).'<br>';
print $langs->trans('PeResults').' : '.$link(array('f_found' => 'found'), $langs->trans('PeFound'), $counts['found']).$sep;
print $link(array('f_found' => 'notfound'), $langs->trans('PeNothingFound'), $counts['notfound']).'<br>';
print $langs->trans('PeSources').' : '.$link(array('f_found' => '', 'f_source' => 'upc'), 'UPCitemdb', $counts['src_upc']).$sep;
print $link(array('f_found' => '', 'f_source' => 'ebay'), $langs->trans('PeEbayWithListings'), $counts['src_ebay']).$sep;
print $link(array('f_found' => '', 'f_source' => 'ai'), $langs->trans('PeAi'), $counts['src_ai']).$sep;
print $link(array('f_found' => '', 'f_origin' => 'corrected'), $langs->trans('PeCorrected'), $counts['corrected']).'<br>';
print $langs->trans('PeProducts').' : '.$link(array('f_found' => '', 'f_linked' => 'linked'), $langs->trans('PeLinked'), $counts['linked']).$sep;
print $link(array('f_found' => 'found', 'f_linked' => 'unlinked'), $langs->trans('PeUnlinkedFound'), $counts['unlinked_found']).'<br>';
print $langs->trans('PeCoherence').' : '.$link(array('f_found' => 'found', 'f_anomaly' => 'none'), $langs->trans('PeCoherent'), $counts['coherent']).$sep;
print '<span class="pe-badge pe-b-warn">'.$link(array('f_found' => '', 'f_anomaly' => 'label_unrelated'), $langs->trans('PeAnomaly_label_unrelated'), $counts['label_unrelated']).'</span>';
print '<span class="pe-badge pe-b-warn">'.$link(array('f_found' => '', 'f_anomaly' => 'ebay_unrelated'), $langs->trans('PeAnomaly_ebay_unrelated'), $counts['ebay_unrelated']).'</span>';
print '<span class="pe-badge pe-b-info">'.$link(array('f_found' => '', 'f_anomaly' => 'ebay_rejected'), $langs->trans('PeEbayRejected'), $counts['ebay_rejected']).'</span>';
print '<br><span class="opacitymedium small">'.$langs->trans('PeCoherenceHelp').'</span>';
print '</div>';

// Filters
$sel = function ($name, $value, $options) {
	$h = '<select name="'.$name.'" class="flat minwidth100">';
	foreach ($options as $k => $l) {
		$h .= '<option value="'.dol_escape_htmltag($k).'"'.((string) $value === (string) $k ? ' selected' : '').'>'.dol_escape_htmltag($l).'</option>';
	}
	return $h.'</select>';
};
print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" class="margintoponly marginbottomonly">';
print '<input type="text" name="search_all" class="flat minwidth200" placeholder="'.dol_escape_htmltag($langs->trans('PeSearchPlaceholder')).'" value="'.dol_escape_htmltag($search).'"> ';
print $sel('f_found', $ffound, array('' => $langs->trans('PeAllResults'), 'found' => $langs->trans('PeFound'), 'notfound' => $langs->trans('PeNothingFound'))).' ';
print $sel('f_source', $fsource, array('' => $langs->trans('PeAllSources'), 'upc' => 'UPCitemdb', 'ebay' => $langs->trans('PeEbayWithListings'), 'ai' => $langs->trans('PeAi'), 'none' => $langs->trans('PeNoSource'))).' ';
print $sel('f_anomaly', $fanomaly, array('' => $langs->trans('PeAllCoherence'), 'any' => $langs->trans('PeAnyAnomaly'), 'none' => $langs->trans('PeCoherent'), 'label_unrelated' => $langs->trans('PeAnomaly_label_unrelated'), 'ebay_unrelated' => $langs->trans('PeAnomaly_ebay_unrelated'), 'ebay_rejected' => $langs->trans('PeEbayRejected'))).' ';
print $sel('f_linked', $flinked, array('' => $langs->trans('PeAllProducts'), 'linked' => $langs->trans('PeLinked'), 'unlinked' => $langs->trans('PeUnlinked'))).' ';
print $sel('f_origin', $forigin, array('' => $langs->trans('PeAllOrigins'), 'scanner' => $langs->trans('PeOrigin_scanner'), 'module' => $langs->trans('PeOrigin_module'), 'both' => $langs->trans('PeOrigin_both'), 'corrected' => $langs->trans('PeCorrected'))).' ';
print '<button type="submit" class="button small">'.img_picto('', 'search', 'class="pictofixedwidth"').$langs->trans('Search').'</button> ';
print '<a href="'.$_SERVER['PHP_SELF'].'?f_found=" class="small">'.$langs->trans('PeResetFilters').'</a>';
print '</form>';

// List
$pageurl = $_SERVER['PHP_SELF'].'?'.($listparams !== '' ? $listparams.'&' : '').'limit='.$limit;
$nbpages = max(1, (int) ceil($nbtotal / $limit));
$nav = '';
if ($nbpages > 1) {
	$nav = '<span class="opacitymedium">'.$langs->trans('Page').' '.($page + 1).' / '.$nbpages.'</span> ';
	if ($page > 0) {
		$nav .= '<a class="button smallpaddingimp" href="'.$pageurl.'&page='.($page - 1).'">&lsaquo;</a> ';
	}
	if ($page + 1 < $nbpages) {
		$nav .= '<a class="button smallpaddingimp" href="'.$pageurl.'&page='.($page + 1).'">&rsaquo;</a>';
	}
}
print '<div class="right marginbottomonly"><span class="opacitymedium">'.$langs->trans('PeNbEans', $nbtotal).'</span> &nbsp; '.$nav.'</div>';

print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>EAN</td><td></td><td>'.$langs->trans('PeJs_Label').'</td><td>'.$langs->trans('PeSources').'</td><td>'.$langs->trans('PeLinkedProducts').'</td><td>'.$langs->trans('PeCoherence').'</td><td>'.$langs->trans('PeStoredIn').'</td><td>'.$langs->trans('Date').'</td></tr>';
foreach (array_slice($rows, $page * $limit, $limit) as $row) {
	$info = $row['entry']['info'];
	$imgs = ProductEnrichment::infoImages($info);
	$detail = $_SERVER['PHP_SELF'].'?ean='.urlencode($row['ean']).($listparams !== '' ? '&'.$listparams : '');
	$titletxt = (string) ($info['title'] ?? '');
	if ($titletxt === '' && count((array) ($info['titles'] ?? array()))) {
		$titletxt = (string) reset($info['titles']);
	}
	print '<tr class="oddeven">';
	print '<td class="nowraponall"><a href="'.dol_escape_htmltag($detail).'">'.dol_escape_htmltag($row['ean']).'</a></td>';
	print '<td>'.(count($imgs) ? '<img class="pe-small-photo" src="'.dol_escape_htmltag($imgs[0]).'" alt="" loading="lazy" referrerpolicy="no-referrer">' : '').'</td>';
	print '<td class="tdoverflowmax300" title="'.dol_escape_htmltag($titletxt).'">'.($titletxt !== '' ? dol_escape_htmltag($titletxt) : '<span class="opacitymedium">'.$langs->trans('PeNothingFound').'</span>').((string) ($info['brand'] ?? '') !== '' ? ' <span class="opacitymedium">— '.dol_escape_htmltag((string) $info['brand']).'</span>' : '').'</td>';
	print '<td>'.peViewSourceBadges($info).'</td>';
	print '<td class="tdoverflowmax250">'.(count($row['products']) ? peViewProducts(array_slice($row['products'], 0, 2, true), $info, true).(count($row['products']) > 2 ? ' <span class="opacitymedium">+'.(count($row['products']) - 2).'</span>' : '') : '<span class="opacitymedium">—</span>').'</td>';
	print '<td>'.peViewAnomalyBadges($row['anom']).'</td>';
	print '<td class="small">'.$langs->trans('PeOrigin_'.$row['entry']['origin']).($row['entry']['scans'] > 1 ? ' <span class="opacitymedium">('.((int) $row['entry']['scans']).')</span>' : '').'</td>';
	print '<td class="nowraponall small">'.($row['entry']['date'] ? dol_print_date($row['entry']['date'], 'day') : '').'</td>';
	print '</tr>';
}
if (!$nbtotal) {
	print '<tr class="oddeven"><td colspan="8"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
}
print '</table></div>';
if ($nav !== '') {
	print '<div class="right margintoponly">'.$nav.'</div>';
}

llxFooter();
$db->close();
