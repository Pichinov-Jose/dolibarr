<?php
/* Copyright (C) 2026 Jose Martinez <jose.martinez@pichinov.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    admin/setup.php
 * \ingroup takeposvendeur
 * \brief   Page de configuration du module Takeposvendeur.
 */

// Load Dolibarr environment
$res = 0;
if (!$res && file_exists("../../main.inc.php")) $res = include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = include "../../../main.inc.php";
if (!$res && file_exists("../../../../main.inc.php")) $res = include "../../../../main.inc.php";
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/ajax.lib.php';
dol_include_once('/takeposvendeur/lib/takeposvendeur.lib.php');

$langs->loadLangs(array('admin', 'takeposvendeur@takeposvendeur'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'alpha');

/*
 * Actions
 */
if ($action == 'setvalue' && $user->admin) {
	$db->begin();
	$ok = 1;
	$ok = $ok && dolibarr_set_const($db, "TAKEPOSVENDEUR_MARGIN_GREEN", GETPOSTINT('TAKEPOSVENDEUR_MARGIN_GREEN'), 'chaine', 0, '', $conf->entity) >= 0;
	$ok = $ok && dolibarr_set_const($db, "TAKEPOSVENDEUR_MARGIN_ORANGE", GETPOSTINT('TAKEPOSVENDEUR_MARGIN_ORANGE'), 'chaine', 0, '', $conf->entity) >= 0;
	$ok = $ok && dolibarr_set_const($db, "TAKEPOSVENDEUR_COLOR_GREEN", GETPOST('TAKEPOSVENDEUR_COLOR_GREEN', 'alpha'), 'chaine', 0, '', $conf->entity) >= 0;
	$ok = $ok && dolibarr_set_const($db, "TAKEPOSVENDEUR_COLOR_ORANGE", GETPOST('TAKEPOSVENDEUR_COLOR_ORANGE', 'alpha'), 'chaine', 0, '', $conf->entity) >= 0;
	$ok = $ok && dolibarr_set_const($db, "TAKEPOSVENDEUR_COLOR_RED", GETPOST('TAKEPOSVENDEUR_COLOR_RED', 'alpha'), 'chaine', 0, '', $conf->entity) >= 0;
	// Cascade de sources de coût : construite depuis les priorités (0 = désactivée)
	$cand = array(
		'pmp'          => GETPOSTINT('prio_pmp'),
		'cost_price'   => GETPOSTINT('prio_cost_price'),
		'supplier_min' => GETPOSTINT('prio_supplier_min'),
	);
	$efname = preg_replace('/[^a-zA-Z0-9_]/', '', GETPOST('cost_ef_name', 'aZ09'));
	$efprio = GETPOSTINT('prio_ef');
	if ($efname !== '' && $efprio > 0) {
		$cand['ef:'.$efname] = $efprio;
	}
	$cand = array_filter($cand, function ($p) {
		return $p > 0;
	});
	asort($cand); // tri par priorité croissante
	$csvsources = implode(',', array_keys($cand));
	$ok = $ok && dolibarr_set_const($db, "TAKEPOSVENDEUR_COST_SOURCES", $csvsources, 'chaine', 0, '', $conf->entity) >= 0;

	if ($ok) {
		$db->commit();
		setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
	} else {
		$db->rollback();
		setEventMessages($langs->trans("Error"), null, 'errors');
	}
}

/*
 * View
 */
$form = new Form($db);

llxHeader('', $langs->trans("ModuleTakeposvendeurName"), '', '', 0, 0, '', '', '', 'mod-takeposvendeur page-admin');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans("ModuleTakeposvendeurName"), $linkback, 'title_setup');

$head = takeposvendeurAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans("ModuleTakeposvendeurName"), -1, 'user');

// ---- Paramètres de marge ----
$gr = getDolGlobalInt('TAKEPOSVENDEUR_MARGIN_GREEN', 30);
$or = getDolGlobalInt('TAKEPOSVENDEUR_MARGIN_ORANGE', 20);
$cg = getDolGlobalString('TAKEPOSVENDEUR_COLOR_GREEN', '#c8e6c9');
$co = getDolGlobalString('TAKEPOSVENDEUR_COLOR_ORANGE', '#ffe0b2');
$cr = getDolGlobalString('TAKEPOSVENDEUR_COLOR_RED', '#ffcdd2');
// Sources de coût courantes -> priorités (position dans le CSV)
$curSources = getDolGlobalString('TAKEPOSVENDEUR_COST_SOURCES', '');
if ($curSources === '') {
	$legacy = getDolGlobalString('TAKEPOSVENDEUR_COST_BASIS', '');
	$curSources = ($legacy == 'cost') ? 'cost_price,pmp,supplier_min' : (($legacy == 'supplier') ? 'supplier_min,pmp,cost_price' : 'pmp,cost_price,supplier_min');
}
$order = array();
$i = 1;
foreach (explode(',', $curSources) as $s) {
	$s = trim($s);
	if ($s !== '') { $order[$s] = $i; $i++; }
}
$curEf = '';
foreach (array_keys($order) as $s) {
	if (strncmp($s, 'ef:', 3) === 0) { $curEf = substr($s, 3); break; }
}

print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="setvalue">';

print load_fiche_titre($langs->trans("TpvMarginParams"), '', '');
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans("Parameter").'</td><td>'.$langs->trans("Value").'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("TpvThresholdGreen").'<br><span class="opacitymedium small">'.$langs->trans("TpvThresholdGreenHelp").'</span></td>';
print '<td><input type="number" min="0" max="100" name="TAKEPOSVENDEUR_MARGIN_GREEN" value="'.$gr.'"> %</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("TpvThresholdOrange").'<br><span class="opacitymedium small">'.$langs->trans("TpvThresholdOrangeHelp").'</span></td>';
print '<td><input type="number" min="0" max="100" name="TAKEPOSVENDEUR_MARGIN_ORANGE" value="'.$or.'"> %</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("TpvColorGreen").'</td><td><input type="color" name="TAKEPOSVENDEUR_COLOR_GREEN" value="'.dol_escape_htmltag($cg).'"> <span class="opacitymedium small">'.dol_escape_htmltag($cg).'</span></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans("TpvColorOrange").'</td><td><input type="color" name="TAKEPOSVENDEUR_COLOR_ORANGE" value="'.dol_escape_htmltag($co).'"> <span class="opacitymedium small">'.dol_escape_htmltag($co).'</span></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans("TpvColorRed").'</td><td><input type="color" name="TAKEPOSVENDEUR_COLOR_RED" value="'.dol_escape_htmltag($cr).'"> <span class="opacitymedium small">'.dol_escape_htmltag($cr).'</span></td></tr>';

print '</table>';

// ---- Cascade de sources de coût (priorité = ordre ; 0 = désactivée) ----
print '<br>';
print load_fiche_titre($langs->trans("TpvCostSources"), '', '');
print '<div class="opacitymedium small paddingbottom">'.$langs->trans("TpvCostSourcesHelp").'</div>';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans("TpvCostSource").'</td><td class="right">'.$langs->trans("TpvPriority").'</td></tr>';
$fixedsrc = array('pmp' => 'TpvCostPmp', 'cost_price' => 'TpvCostCostPrice', 'supplier_min' => 'TpvCostSupplier');
foreach ($fixedsrc as $tok => $lk) {
	$pv = isset($order[$tok]) ? $order[$tok] : 0;
	print '<tr class="oddeven"><td>'.$langs->trans($lk).'</td><td class="right"><input type="number" min="0" max="9" name="prio_'.$tok.'" value="'.$pv.'" style="width:60px"></td></tr>';
}
$efpv = ($curEf !== '' && isset($order['ef:'.$curEf])) ? $order['ef:'.$curEf] : 0;
print '<tr class="oddeven"><td>'.$langs->trans("TpvCostExtrafield").' <input type="text" name="cost_ef_name" value="'.dol_escape_htmltag($curEf).'" placeholder="kezia_cump" class="width150">';
print '<br><span class="opacitymedium small">'.$langs->trans("TpvCostExtrafieldHelp").'</span></td>';
print '<td class="right"><input type="number" min="0" max="9" name="prio_ef" value="'.$efpv.'" style="width:60px"></td></tr>';
print '</table>';

print '<div class="center paddingtop"><input type="submit" class="button" value="'.$langs->trans("Save").'"></div>';
print '</form>';

// ---- Options d'affichage (bascules) ----
print '<br>';
print load_fiche_titre($langs->trans("TpvDisplayOptions"), '', '');
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans("Parameter").'</td><td class="center">'.$langs->trans("Status").'</td></tr>';

$toggles = array(
	'TAKEPOSVENDEUR_HIDE_MARGIN'    => 'TpvOptHideMargin',
	'TAKEPOSVENDEUR_VENDOR_OPTIONAL'=> 'TpvOptVendorOptional',
	'TAKEPOSVENDEUR_HIDE_ON_RECEIPT'=> 'TpvOptHideOnReceipt',
);
foreach ($toggles as $const => $key) {
	print '<tr class="oddeven"><td>'.$langs->trans($key).'<br><span class="opacitymedium small">'.$langs->trans($key.'Help').'</span></td><td class="center">';
	if (!empty($conf->use_javascript_ajax)) {
		print ajax_constantonoff($const);
	} else {
		print $form->selectarray($const, array('0' => $langs->trans("Disabled"), '1' => $langs->trans("Enabled")), getDolGlobalInt($const));
	}
	print '</td></tr>';
}
print '</table>';

print dol_get_fiche_end();

llxFooter();
$db->close();
