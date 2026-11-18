<?php
/* Copyright (C) 2026 Jose Martinez <jose.martinez@pichinov.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    stats.php
 * \ingroup takeposvendeur
 * \brief   Ventes par vendeur : tickets, CA, avoirs, panier moyen et marge par
 *          vendeur choisi en caisse, filtres periode / caisse / vendeur, export CSV.
 */

// Load Dolibarr environment
$res = 0;
if (!$res && file_exists("../main.inc.php")) $res = include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = include "../../../main.inc.php";
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
dol_include_once('/takeposvendeur/class/takeposvendeurstats.class.php');

$langs->loadLangs(array('bills', 'companies', 'margins', 'takeposvendeur@takeposvendeur'));

if (!isModEnabled('takeposvendeur') || !$user->hasRight('takeposvendeur', 'stats', 'read')) {
	accessforbidden();
}

// La marge suit le droit natif « voir les marges ».
$showmargin = isModEnabled('margin') && $user->hasRight('margins', 'liretous');

$action    = GETPOST('action', 'aZ09');
$preset    = GETPOST('preset', 'aZ09');
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = strtoupper(GETPOST('sortorder', 'aZ09')) == 'ASC' ? 'ASC' : 'DESC';
$caisses   = GETPOST('caisses', 'array:alphanohtml');
$vendeurs  = GETPOST('vendeurs', 'array:int');
if (!is_array($caisses)) $caisses = array();
if (!is_array($vendeurs)) $vendeurs = array();
// Comparaison avec la meme periode des annees precedentes (0 a 3 ans, defaut N-1).
$compare = GETPOSTISSET('compare') ? (int) GETPOST('compare', 'int') : 1;
$compare = max(0, min(3, $compare));

$date_start = dol_mktime(0, 0, 0, GETPOST('date_startmonth', 'int'), GETPOST('date_startday', 'int'), GETPOST('date_startyear', 'int'));
$date_end   = dol_mktime(23, 59, 59, GETPOST('date_endmonth', 'int'), GETPOST('date_endday', 'int'), GETPOST('date_endyear', 'int'));

// Raccourcis de periode (et periode par defaut : le mois en cours).
if ($preset === '' && empty($date_start) && empty($date_end)) {
	$preset = 'month';
}
if ($preset !== '') {
	$now = dol_getdate(dol_now(), true);
	$y = $now['year'];
	$m = $now['mon'];
	$d = $now['mday'];
	switch ($preset) {
		case 'today':
			$date_start = dol_mktime(0, 0, 0, $m, $d, $y);
			$date_end = dol_mktime(23, 59, 59, $m, $d, $y);
			break;
		case 'yesterday':
			$t = dol_time_plus_duree(dol_mktime(12, 0, 0, $m, $d, $y), -1, 'd');
			$date_start = dol_mktime(0, 0, 0, dol_print_date($t, '%m'), dol_print_date($t, '%d'), dol_print_date($t, '%Y'));
			$date_end = dol_mktime(23, 59, 59, dol_print_date($t, '%m'), dol_print_date($t, '%d'), dol_print_date($t, '%Y'));
			break;
		case 'week':
			$w = dol_get_first_day_week($d, $m, $y);
			$date_start = dol_mktime(0, 0, 0, $w['first_month'], $w['first_day'], $w['first_year']);
			$date_end = dol_mktime(23, 59, 59, $m, $d, $y);
			break;
		case 'lastmonth':
			$pm = dol_get_prev_month($m, $y);
			$date_start = dol_get_first_day($pm['year'], $pm['month']);
			$date_end = dol_mktime(23, 59, 59, $pm['month'], (int) dol_print_date(dol_get_last_day($pm['year'], $pm['month']), '%d'), $pm['year']);
			break;
		case 'quarter':
			$qm = 3 * (int) floor(($m - 1) / 3) + 1;
			$date_start = dol_get_first_day($y, $qm);
			$date_end = dol_mktime(23, 59, 59, $m, $d, $y);
			break;
		case 'lastquarter':
			$qm = 3 * (int) floor(($m - 1) / 3) + 1 - 3;
			$qy = $y;
			if ($qm < 1) {
				$qm += 12;
				$qy--;
			}
			$date_start = dol_get_first_day($qy, $qm);
			$date_end = dol_get_last_day($qy, $qm + 2);
			break;
		case 'year':
			$date_start = dol_get_first_day($y, 1);
			$date_end = dol_mktime(23, 59, 59, $m, $d, $y);
			break;
		case 'lastyear':
			$date_start = dol_get_first_day($y - 1, 1);
			$date_end = dol_mktime(23, 59, 59, 12, 31, $y - 1);
			break;
		case 'month':
		default:
			$preset = 'month';
			$date_start = dol_get_first_day($y, $m);
			$date_end = dol_mktime(23, 59, 59, $m, $d, $y);
			break;
	}
}

$sortable = array('name', 'nb_tickets', 'ventes_ttc', 'nb_avoirs', 'avoirs_ttc', 'ca_ht', 'ca_ttc', 'part', 'panier', 'marge', 'taux');
for ($k = 1; $k <= $compare; $k++) {
	$sortable[] = 'cmp'.$k;
	$sortable[] = 'evo'.$k;
}
if (!in_array($sortfield, $sortable, true)) {
	$sortfield = 'ca_ttc';
}

$filters = array('date_start' => $date_start, 'date_end' => $date_end, 'caisses' => $caisses, 'vendeurs' => $vendeurs);

$stats = new TakeposvendeurStats($db);
$result = $stats->compute($filters, $showmargin);
if (!is_array($result)) {
	setEventMessages($stats->error, null, 'errors');
	$result = array('rows' => array(), 'total' => array());
}
$rows = $result['rows'];
$total = $result['total'];

// Memes periodes decalees de 1 a 3 ans, memes filtres (sans la marge : requete legere).
$cmp = array();
$cmplabel = array();
$cmptip = array();
for ($k = 1; $k <= $compare; $k++) {
	$fk = $filters;
	$fk['date_start'] = $date_start ? dol_time_plus_duree($date_start, -$k, 'y') : '';
	$fk['date_end'] = $date_end ? dol_time_plus_duree($date_end, -$k, 'y') : '';
	$rk = $stats->compute($fk, false);
	if (!is_array($rk)) {
		setEventMessages($stats->error, null, 'errors');
		$rk = array('rows' => array(), 'total' => $stats->blankRow());
	}
	$cmp[$k] = $rk;
	$ys = $fk['date_start'] ? dol_print_date($fk['date_start'], '%Y') : '';
	$ye = $fk['date_end'] ? dol_print_date($fk['date_end'], '%Y') : '';
	$cmplabel[$k] = ($ys == $ye || $ys === '' || $ye === '') ? ($ys !== '' ? $ys : $ye) : $ys.'-'.substr($ye, 2);
	$cmptip[$k] = ($fk['date_start'] ? dol_print_date($fk['date_start'], 'day') : '...').' - '.($fk['date_end'] ? dol_print_date($fk['date_end'], 'day') : '...');
	// Un vendeur present seulement sur une periode comparee apparait quand meme (a 0 sur la periode).
	foreach (array_keys($rk['rows']) as $vid) {
		if (!isset($rows[$vid])) {
			$rows[$vid] = $stats->blankRow();
		}
	}
}
if (empty($total)) {
	$total = $stats->blankRow();
}
/**
 * Evolution en % de $n par rapport a $p (null si $p nul).
 */
$evolution = function ($n, $p) {
	return ($p == 0) ? null : ($n - $p) / abs($p) * 100;
};
$cmpval = function ($k, $vid) use ($cmp) {
	return isset($cmp[$k]['rows'][$vid]) ? $cmp[$k]['rows'][$vid] : null;
};

// Noms des vendeurs.
$names = array();
$userobjs = array();
foreach (array_keys($rows) as $vid) {
	if ($vid > 0) {
		$u = new User($db);
		if ($u->fetch($vid) > 0) {
			$userobjs[$vid] = $u;
			$names[$vid] = $u->getFullName($langs);
		} else {
			$names[$vid] = '#'.$vid;
		}
	} else {
		$names[$vid] = $langs->trans('TpvNoVendorOnSale');
	}
}

// Tri.
$sortvalue = function ($vid) use ($rows, $sortfield, $cmpval, $evolution) {
	$reg = array();
	if (preg_match('/^(cmp|evo)(\d)$/', $sortfield, $reg)) {
		$c = $cmpval((int) $reg[2], $vid);
		$p = $c ? $c['ca_ttc'] : 0;
		if ($reg[1] == 'cmp') {
			return (float) $p;
		}
		$e = $evolution($rows[$vid]['ca_ttc'], $p);
		return ($e === null) ? -INF : $e;
	}
	return isset($rows[$vid][$sortfield]) ? (float) $rows[$vid][$sortfield] : 0;
};
uksort($rows, function ($a, $b) use ($names, $sortfield, $sortorder, $sortvalue) {
	if ($sortfield == 'name') {
		$c = strcoll(dol_string_unaccent($names[$a]), dol_string_unaccent($names[$b]));
	} else {
		$va = $sortvalue($a);
		$vb = $sortvalue($b);
		$c = ($va == $vb) ? 0 : (($va < $vb) ? -1 : 1);
	}
	return $sortorder == 'ASC' ? $c : -$c;
});

$costsources = implode(', ', TakeposvendeurStats::costSources());

/*
 * Export CSV
 */
if ($action == 'export_csv') {
	$sep = ';';
	$num = function ($v, $dec = 2) {
		return ($v === null) ? '' : number_format((float) $v, $dec, ',', '');
	};
	$cell = function ($s) use ($sep) {
		$s = (string) $s;
		if (strpbrk($s, $sep."\"\n\r") !== false) {
			$s = '"'.str_replace('"', '""', $s).'"';
		}
		return $s;
	};
	$head = array($langs->transnoentities('TpvVendor'), $langs->transnoentities('TpvTickets'), $langs->transnoentities('TpvSalesTTC'),
		$langs->transnoentities('TpvCreditNotesNb'), $langs->transnoentities('TpvCreditNotesTTC'),
		$langs->transnoentities('TpvNetHT'), $langs->transnoentities('TpvNetTTC'), $langs->transnoentities('TpvShare').' (%)',
		$langs->transnoentities('TpvAvgBasket'));
	if ($showmargin) {
		$head[] = $langs->transnoentities('TpvMarginHT');
		$head[] = $langs->transnoentities('TpvMarkRate').' (%)';
		$head[] = $langs->transnoentities('TpvCostCoverage').' (%)';
	}
	for ($k = 1; $k <= $compare; $k++) {
		$head[] = $langs->transnoentities('TpvTickets').' '.$cmplabel[$k];
		$head[] = $langs->transnoentities('TpvNetTTC').' '.$cmplabel[$k];
		$head[] = $langs->transnoentities('TpvEvolution').' '.$cmplabel[$k].' (%)';
	}
	$line = function ($label, $r, $past) use ($num, $cell, $showmargin, $compare, $evolution) {
		$a = array($cell($label), $r['nb_tickets'], $num($r['ventes_ttc']), $r['nb_avoirs'], $num($r['avoirs_ttc']),
			$num($r['ca_ht']), $num($r['ca_ttc']), $num($r['part'], 1), $num($r['panier']));
		if ($showmargin) {
			$a[] = $num($r['marge']);
			$a[] = $num($r['taux'], 1);
			$a[] = $num($r['couverture'], 1);
		}
		for ($k = 1; $k <= $compare; $k++) {
			$p = isset($past[$k]) ? $past[$k] : null;
			$a[] = $p ? $p['nb_tickets'] : 0;
			$a[] = $num($p ? $p['ca_ttc'] : 0);
			$a[] = $num($evolution($r['ca_ttc'], $p ? $p['ca_ttc'] : 0), 1);
		}
		return implode(';', $a)."\r\n";
	};
	$filename = 'ventes_par_vendeur_'.dol_print_date($date_start, '%Y%m%d').'_'.dol_print_date($date_end, '%Y%m%d').'.csv';
	header('Content-Type: text/csv; charset=UTF-8');
	header('Content-Disposition: attachment; filename="'.$filename.'"');
	print "\xEF\xBB\xBF"; // BOM pour Excel
	print implode($sep, array_map($cell, $head))."\r\n";
	foreach ($rows as $vid => $r) {
		$past = array();
		for ($k = 1; $k <= $compare; $k++) {
			$past[$k] = $cmpval($k, $vid);
		}
		print $line($names[$vid], $r, $past);
	}
	if (!empty($rows)) {
		$past = array();
		for ($k = 1; $k <= $compare; $k++) {
			$past[$k] = $cmp[$k]['total'];
		}
		print $line($langs->transnoentities('Total'), $total, $past);
	}
	exit;
}

/*
 * View
 */
$form = new Form($db);
$title = $langs->trans('TpvStatsTitle');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-takeposvendeur page-stats');

// Parametres communs (tri, liens).
$param = '';
if ($date_start) {
	$param .= '&date_startday='.dol_print_date($date_start, '%d').'&date_startmonth='.dol_print_date($date_start, '%m').'&date_startyear='.dol_print_date($date_start, '%Y');
}
if ($date_end) {
	$param .= '&date_endday='.dol_print_date($date_end, '%d').'&date_endmonth='.dol_print_date($date_end, '%m').'&date_endyear='.dol_print_date($date_end, '%Y');
}
$paramfilters = '';
foreach ($caisses as $c) {
	$paramfilters .= '&caisses[]='.urlencode($c);
}
foreach ($vendeurs as $v) {
	$paramfilters .= '&vendeurs[]='.((int) $v);
}
$paramfilters .= '&compare='.$compare;
$param .= $paramfilters;

$exportlink = $_SERVER['PHP_SELF'].'?action=export_csv&token='.newToken().$param.'&sortfield='.$sortfield.'&sortorder='.$sortorder;
$morehtmlright = dolGetButtonTitle($langs->trans('TpvExportCsv'), '', 'fa fa-file-csv', $exportlink, '', 1);
print load_fiche_titre($title, $morehtmlright, 'user');

// Filtres.
print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" name="tpvstats">';
print '<input type="hidden" name="sortfield" value="'.dol_escape_htmltag($sortfield).'">';
print '<input type="hidden" name="sortorder" value="'.dol_escape_htmltag($sortorder).'">';

print '<div class="fichecenter"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('Filters').'</td></tr>';

$presets = array('today' => 'TpvToday', 'yesterday' => 'TpvYesterday', 'week' => 'TpvThisWeek', 'month' => 'TpvThisMonth',
	'lastmonth' => 'TpvLastMonth', 'quarter' => 'TpvThisQuarter', 'lastquarter' => 'TpvLastQuarter', 'year' => 'TpvThisYear', 'lastyear' => 'TpvLastYear');
print '<tr class="oddeven"><td class="titlefieldmiddle">'.$langs->trans('Period').'</td><td>';
print $form->selectDate($date_start, 'date_start', 0, 0, 0, 'tpvstats', 1, 0);
print ' &nbsp;&rarr;&nbsp; ';
print $form->selectDate($date_end, 'date_end', 0, 0, 0, 'tpvstats', 1, 0);
print '<div class="paddingtop">';
$links = array();
foreach ($presets as $k => $lab) {
	$links[] = '<a class="'.($preset == $k ? 'tpvpreset tpvpreset-on' : 'tpvpreset').'" href="'.$_SERVER['PHP_SELF'].'?preset='.$k.$paramfilters.'&sortfield='.$sortfield.'&sortorder='.$sortorder.'">'.$langs->trans($lab).'</a>';
}
print implode(' · ', $links);
print '</div></td></tr>';

$arraycaisses = array();
foreach ($stats->listCaisses() as $k => $lab) {
	$arraycaisses[$k] = ($k === TakeposvendeurStats::CAISSE_NONE) ? $langs->trans($lab) : $lab;
}
print '<tr class="oddeven"><td>'.$langs->trans('TpvPos').'</td><td>';
print $form->multiselectarray('caisses', $arraycaisses, $caisses, 0, 0, 'minwidth300 maxwidth500', 0, 0, '', '', $langs->trans('TpvAllSales'));
print '</td></tr>';

$arrayvendeurs = array();
foreach ($stats->listVendeurs() as $id => $o) {
	$lab = trim($o->firstname.' '.$o->lastname);
	if ($lab === '') $lab = $o->login;
	if (empty($o->statut)) $lab .= ' ('.$langs->trans('Disabled').')';
	$arrayvendeurs[$id] = $lab;
}
$arrayvendeurs[TakeposvendeurStats::VENDEUR_NONE] = $langs->trans('TpvNoVendorOnSale');
print '<tr class="oddeven"><td>'.$langs->trans('TpvVendor').'</td><td>';
print $form->multiselectarray('vendeurs', $arrayvendeurs, $vendeurs, 0, 0, 'minwidth300 maxwidth500', 0, 0, '', '', $langs->trans('TpvAllVendors'));
print '</td></tr>';

$arraycompare = array(0 => $langs->trans('TpvCompareNone'), 1 => $langs->trans('TpvCompare1'), 2 => $langs->trans('TpvCompare2'), 3 => $langs->trans('TpvCompare3'));
print '<tr class="oddeven"><td>'.$langs->trans('TpvCompareWith').'</td><td>';
print $form->selectarray('compare', $arraycompare, $compare, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';

print '<tr class="oddeven"><td></td><td><input type="submit" class="button small" value="'.dol_escape_htmltag($langs->trans('Refresh')).'"></td></tr>';
print '</table></div>';
print '</form><br>';

// Tableau.
$tpvgreen = getDolGlobalInt('TAKEPOSVENDEUR_MARGIN_GREEN', 30);
$tpvorange = getDolGlobalInt('TAKEPOSVENDEUR_MARGIN_ORANGE', 20);
$colgreen = getDolGlobalString('TAKEPOSVENDEUR_COLOR_GREEN', '#c8e6c9');
$colorange = getDolGlobalString('TAKEPOSVENDEUR_COLOR_ORANGE', '#ffe0b2');
$colred = getDolGlobalString('TAKEPOSVENDEUR_COLOR_RED', '#ffcdd2');

print '<style>
.tpvpreset{padding:2px 6px;border-radius:4px;}
.tpvpreset-on{background:var(--colorbacktitle1,#e0e0e0);font-weight:bold;}
.tpvbar{display:inline-block;height:6px;border-radius:3px;background:#5c8fc7;vertical-align:middle;margin-right:6px;}
.tpvrate{display:inline-block;min-width:52px;padding:1px 6px;border-radius:4px;text-align:center;}
.tpvup{color:#2e7d32;}
.tpvdown{color:#c62828;}
td.tpvcmp,th.tpvcmp{background:rgba(92,143,199,0.06);}
</style>';

$sumparam = $param;
print '<div class="div-table-responsive" id="tpvtablewrap">';
print '<table class="noborder centpercent liste">';
print '<tr class="liste_titre">';
print_liste_field_titre('TpvVendor', $_SERVER['PHP_SELF'], 'name', '', $sumparam, '', $sortfield, $sortorder);
print_liste_field_titre('TpvTickets', $_SERVER['PHP_SELF'], 'nb_tickets', '', $sumparam, '', $sortfield, $sortorder, 'right ');
print_liste_field_titre('TpvSalesTTC', $_SERVER['PHP_SELF'], 'ventes_ttc', '', $sumparam, '', $sortfield, $sortorder, 'right ');
print_liste_field_titre('TpvCreditNotesNb', $_SERVER['PHP_SELF'], 'nb_avoirs', '', $sumparam, '', $sortfield, $sortorder, 'right ');
print_liste_field_titre('TpvCreditNotesTTC', $_SERVER['PHP_SELF'], 'avoirs_ttc', '', $sumparam, '', $sortfield, $sortorder, 'right ');
print_liste_field_titre('TpvNetHT', $_SERVER['PHP_SELF'], 'ca_ht', '', $sumparam, '', $sortfield, $sortorder, 'right ');
print_liste_field_titre('TpvNetTTC', $_SERVER['PHP_SELF'], 'ca_ttc', '', $sumparam, '', $sortfield, $sortorder, 'right ');
for ($k = 1; $k <= $compare; $k++) {
	print_liste_field_titre($cmplabel[$k], $_SERVER['PHP_SELF'], 'cmp'.$k, '', $sumparam, '', $sortfield, $sortorder, 'right tpvcmp ', $langs->trans('TpvNetTTC').' : '.$cmptip[$k]);
	print_liste_field_titre($langs->trans('TpvEvolution').' '.$cmplabel[$k], $_SERVER['PHP_SELF'], 'evo'.$k, '', $sumparam, '', $sortfield, $sortorder, 'right tpvcmp ', $langs->trans('TpvEvolutionHelp', $cmptip[$k]));
}
print_liste_field_titre('TpvShare', $_SERVER['PHP_SELF'], 'part', '', $sumparam, '', $sortfield, $sortorder, 'left ');
print_liste_field_titre('TpvAvgBasket', $_SERVER['PHP_SELF'], 'panier', '', $sumparam, '', $sortfield, $sortorder, 'right ');
if ($showmargin) {
	print_liste_field_titre('TpvMarginHT', $_SERVER['PHP_SELF'], 'marge', '', $sumparam, '', $sortfield, $sortorder, 'right ');
	print_liste_field_titre('TpvMarkRate', $_SERVER['PHP_SELF'], 'taux', '', $sumparam, '', $sortfield, $sortorder, 'center ', $langs->trans('TpvMarkRateHelp'));
}
print '</tr>';

$ratecell = function ($r) use ($langs, $tpvgreen, $tpvorange, $colgreen, $colorange, $colred) {
	if ($r['taux'] === null) {
		return '<span class="opacitymedium">-</span>';
	}
	$pct = $r['taux'];
	$bg = ($pct >= $tpvgreen) ? $colgreen : (($pct >= $tpvorange) ? $colorange : $colred);
	$tip = $langs->trans('TpvCostCoverage').' : '.($r['couverture'] === null ? '-' : price(round($r['couverture'], 1)).' %');
	$out = '<span class="tpvrate classfortooltip" style="background:'.dol_escape_htmltag($bg).'" title="'.dol_escape_htmltag($tip).'">'.price(round($pct, 1)).' %</span>';
	if ($r['couverture'] !== null && $r['couverture'] < 95) {
		$out .= ' '.img_warning($tip);
	}
	return $out;
};

$evocell = function ($n, $p) use ($evolution) {
	$e = $evolution($n, $p);
	if ($e === null) {
		return '<span class="opacitymedium">-</span>';
	}
	return '<span class="'.($e >= 0 ? 'tpvup' : 'tpvdown').'">'.($e >= 0 ? '+' : '').price(round($e, 1)).' %</span>';
};
$cmpcells = function ($r, $past) use ($compare, $langs, $evocell) {
	$out = '';
	for ($k = 1; $k <= $compare; $k++) {
		$p = isset($past[$k]) ? $past[$k] : null;
		$tip = $p ? $langs->trans('TpvTickets').' : '.$p['nb_tickets'].' - '.$langs->trans('TpvAvgBasket').' : '.price($p['panier'], 0, $langs, 1, -1, 2) : '';
		$out .= '<td class="right nowraponall tpvcmp"'.($tip ? ' title="'.dol_escape_htmltag($tip).'"' : '').'>'.($p && $p['ca_ttc'] != 0 ? price($p['ca_ttc'], 0, $langs, 1, -1, 2) : '<span class="opacitymedium">-</span>').'</td>';
		$out .= '<td class="right nowraponall tpvcmp">'.$evocell($r['ca_ttc'], $p ? $p['ca_ttc'] : 0).'</td>';
	}
	return $out;
};

$maxpart = 0;
foreach ($rows as $r) {
	$maxpart = max($maxpart, abs($r['part']));
}

$listurl = DOL_URL_ROOT.'/compta/facture/list.php?search_date_startday='.dol_print_date($date_start, '%d').'&search_date_startmonth='.dol_print_date($date_start, '%m').'&search_date_startyear='.dol_print_date($date_start, '%Y')
	.'&search_date_endday='.dol_print_date($date_end, '%d').'&search_date_endmonth='.dol_print_date($date_end, '%m').'&search_date_endyear='.dol_print_date($date_end, '%Y');
if (count($caisses) == 1 && $caisses[0] !== TakeposvendeurStats::CAISSE_NONE) {
	$listurl .= '&search_pos_source='.urlencode($caisses[0]);
}

if (empty($rows)) {
	$colspan = ($showmargin ? 11 : 9) + 2 * $compare;
	print '<tr class="oddeven"><td colspan="'.$colspan.'"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
}
foreach ($rows as $vid => $r) {
	print '<tr class="oddeven">';
	print '<td class="tdoverflowmax250">';
	if (isset($userobjs[$vid])) {
		print $userobjs[$vid]->getNomUrl(-1);
	} else {
		print '<span class="opacitymedium"><em>'.dol_escape_htmltag($names[$vid]).'</em></span>';
	}
	print '</td>';
	print '<td class="right">';
	if ($vid > 0) {
		print '<a href="'.$listurl.'&search_options_fk_vendeur='.((int) $vid).'" title="'.dol_escape_htmltag($langs->trans('TpvSeeInvoices')).'">'.$r['nb_tickets'].'</a>';
	} else {
		print $r['nb_tickets'];
	}
	print '</td>';
	print '<td class="right nowraponall">'.price($r['ventes_ttc'], 0, $langs, 1, -1, 2).'</td>';
	print '<td class="right">'.($r['nb_avoirs'] ? $r['nb_avoirs'] : '<span class="opacitymedium">0</span>').'</td>';
	print '<td class="right nowraponall">'.($r['avoirs_ttc'] != 0 ? price($r['avoirs_ttc'], 0, $langs, 1, -1, 2) : '<span class="opacitymedium">-</span>').'</td>';
	print '<td class="right nowraponall">'.price($r['ca_ht'], 0, $langs, 1, -1, 2).'</td>';
	print '<td class="right nowraponall"><strong>'.price($r['ca_ttc'], 0, $langs, 1, -1, 2).'</strong></td>';
	$past = array();
	for ($k = 1; $k <= $compare; $k++) {
		$past[$k] = $cmpval($k, $vid);
	}
	print $cmpcells($r, $past);
	$w = ($maxpart > 0) ? max(1, round(abs($r['part']) / $maxpart * 80)) : 0;
	print '<td class="nowraponall"><span class="tpvbar" style="width:'.$w.'px"></span>'.price(round($r['part'], 1)).' %</td>';
	print '<td class="right nowraponall">'.price($r['panier'], 0, $langs, 1, -1, 2).'</td>';
	if ($showmargin) {
		print '<td class="right nowraponall">'.($r['taux'] === null ? '<span class="opacitymedium">-</span>' : price($r['marge'], 0, $langs, 1, -1, 2)).'</td>';
		print '<td class="center nowraponall">'.$ratecell($r).'</td>';
	}
	print '</tr>';
}

if (!empty($rows)) {
	print '<tr class="liste_total">';
	print '<td>'.$langs->trans('Total').'</td>';
	print '<td class="right">'.$total['nb_tickets'].'</td>';
	print '<td class="right nowraponall">'.price($total['ventes_ttc'], 0, $langs, 1, -1, 2).'</td>';
	print '<td class="right">'.$total['nb_avoirs'].'</td>';
	print '<td class="right nowraponall">'.price($total['avoirs_ttc'], 0, $langs, 1, -1, 2).'</td>';
	print '<td class="right nowraponall">'.price($total['ca_ht'], 0, $langs, 1, -1, 2).'</td>';
	print '<td class="right nowraponall">'.price($total['ca_ttc'], 0, $langs, 1, -1, 2).'</td>';
	$past = array();
	for ($k = 1; $k <= $compare; $k++) {
		$past[$k] = $cmp[$k]['total'];
	}
	print $cmpcells($total, $past);
	print '<td>100 %</td>';
	print '<td class="right nowraponall">'.price($total['panier'], 0, $langs, 1, -1, 2).'</td>';
	if ($showmargin) {
		print '<td class="right nowraponall">'.($total['taux'] === null ? '-' : price($total['marge'], 0, $langs, 1, -1, 2)).'</td>';
		print '<td class="center nowraponall">'.$ratecell($total).'</td>';
	}
	print '</tr>';
}
print '</table>';
print '</div>';
// Avec les colonnes de comparaison le tableau peut depasser l'ecran : on borne sa largeur a
// l'espace visible pour qu'il defile seul, sinon la cellule de mise en page elargit toute la page.
print '<script>
(function () {
	function tpvFit() {
		var w = document.getElementById("tpvtablewrap");
		if (!w) return;
		var avail = document.documentElement.clientWidth - w.getBoundingClientRect().left - 40;
		w.style.maxWidth = Math.max(280, avail) + "px";
	}
	tpvFit();
	window.addEventListener("resize", tpvFit);
})();
</script>';

// Mode de calcul.
print '<br><div class="opacitymedium small">';
print $langs->trans('TpvStatsHowVendor').'<br>';
print $langs->trans('TpvStatsHowScope').'<br>';
print $langs->trans('TpvStatsHowCredit').'<br>';
print $langs->trans('TpvStatsHowBasket').'<br>';
if ($compare) {
	print $langs->trans('TpvStatsHowCompare').'<br>';
}
if ($showmargin) {
	print $langs->trans('TpvStatsHowMargin', dol_escape_htmltag($costsources)).'<br>';
}
print '</div>';

llxFooter();
$db->close();
