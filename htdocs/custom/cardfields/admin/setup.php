<?php
/* Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    cardfields/admin/setup.php
 * \brief   Setup of the module Champs par fiche: mandatory and hidden fields of each card and mode
 */

$res = 0;
if (!$res && file_exists('../../../main.inc.php')) {
	$res = @include '../../../main.inc.php';
}
if (!$res && file_exists('../../../../main.inc.php')) {
	$res = @include '../../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once dol_buildpath('/cardfields/lib/cardfields.lib.php');

if (!$user->admin) {
	accessforbidden();
}

$langs->loadLangs(array('admin', 'cardfields@cardfields'));

$action = GETPOST('action', 'aZ09');
$objects = array_filter(cardfieldsObjects(), function ($def) {
	return !empty($def['enabled']);
});
$objkey = GETPOST('obj', 'aZ09');
if (empty($objects[$objkey])) {
	$objkey = (isset($objects['reception']) ? 'reception' : (string) key($objects));
}
$def = $objects[$objkey];
$modes = cardfieldsModes();
$modelabels = array('create' => 'CfModeCreate', 'edit' => 'CfModeEdit', 'view' => 'CfModeView');

/*
 * Actions
 */

if ($action == 'savefields' && $objkey !== '') {	// Test on permission already done (admin only page)
	$catalog = cardfieldsFieldCatalog($objkey);
	$conflicts = array();
	foreach ($modes as $mode) {
		$required = array();
		$hidden = array();
		foreach ($catalog as $key => $field) {
			$isrequired = (cardfieldsCan($field, $objkey, $mode, 'required') && GETPOST('r_'.$mode.'_'.$key, 'alpha') == '1');
			$ishidden = (cardfieldsCan($field, $objkey, $mode, 'hidden') && GETPOST('h_'.$mode.'_'.$key, 'alpha') == '1');
			if ($isrequired) {
				$required[] = $key;
			}
			if ($ishidden && $isrequired) {
				$conflicts[] = $field['label'].' ('.$langs->transnoentitiesnoconv($modelabels[$mode]).')';
			} elseif ($ishidden) {
				$hidden[] = $key;
			}
		}
		dolibarr_set_const($db, cardfieldsConstName($objkey, $mode, 'required'), implode(',', $required), 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, cardfieldsConstName($objkey, $mode, 'hidden'), implode(',', $hidden), 'chaine', 0, '', $conf->entity);
	}
	setEventMessages($langs->trans('CfFieldsSaved', $langs->transnoentitiesnoconv($def['label'])), null, 'mesgs');
	if (count($conflicts)) {
		setEventMessages($langs->trans('CfFieldsConflict', implode(', ', $conflicts)), null, 'warnings');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?obj='.urlencode($objkey));
	exit;
}

if ($action == 'clearsurvey' && $objkey !== '') {	// Test on permission already done (admin only page)
	$sql = "DELETE FROM ".$db->prefix()."cardfields_survey WHERE entity = ".((int) $conf->entity)." AND objectkey = '".$db->escape($objkey)."'";
	if ($db->query($sql)) {
		setEventMessages($langs->trans('CfSurveyCleared', $langs->transnoentitiesnoconv($def['label'])), null, 'mesgs');
	} else {
		setEventMessages($db->lasterror(), null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?obj='.urlencode($objkey));
	exit;
}

/*
 * View
 */

$page_name = 'CfSetupTitle';
llxHeader('', $langs->trans($page_name), '', '', 0, 0, '', '', '', 'mod-cardfields page-admin-setup');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

$head = cardfieldsAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans('CfModuleName'), -1, 'list');

print '<span class="opacitymedium">'.$langs->trans('CfSetupDesc').'</span><br><br>';

// General options
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td class="center width100">'.$langs->trans('Status').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('CfHideEmptyBlocks').'<br><span class="opacitymedium small">'.$langs->trans('CfHideEmptyBlocksDesc').'</span></td>';
print '<td class="center">'.ajax_constantonoff('CARDFIELDS_HIDE_EMPTY_BLOCKS').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('CfSurveyAlways').'<br><span class="opacitymedium small">'.$langs->trans('CfSurveyAlwaysDesc').'</span></td>';
print '<td class="center">'.ajax_constantonoff('CARDFIELDS_SURVEY').'</td></tr>';
print '</table>';
print '<br>';

// Choice of the card
print load_fiche_titre($langs->trans('CfChooseCard'), '', '');
print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';
print img_picto('', $def['picto'], 'class="pictofixedwidth"');
print '<select name="obj" id="cardfieldsobj" class="minwidth300" onchange="this.form.submit()">';
foreach ($objects as $key => $o) {
	$nbr = 0;
	$nbh = 0;
	foreach ($modes as $mode) {
		$nbr += count(array_filter(explode(',', getDolGlobalString(cardfieldsConstName($key, $mode, 'required')))));
		$nbh += count(array_filter(explode(',', getDolGlobalString(cardfieldsConstName($key, $mode, 'hidden')))));
	}
	$count = (($nbr || $nbh) ? ' — '.$langs->transnoentitiesnoconv('CfCountTitle', $nbr, $nbh) : '');
	print '<option value="'.dol_escape_htmltag($key).'"'.($key == $objkey ? ' selected' : '').'>'.dol_escape_htmltag($langs->transnoentitiesnoconv($o['label']).$count).'</option>';
}
print '</select>';
print ajax_combobox('cardfieldsobj');
print ' <noscript><input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('Show')).'"></noscript>';
print '</form><br>';

$lastid = cardfieldsLastObjectId($objkey);
$catalog = cardfieldsFieldCatalog($objkey);
$core = cardfieldsCoreFields($objkey);

print load_fiche_titre(img_picto('', $def['picto'], 'class="pictofixedwidth"').$langs->trans('CfFieldsOf', $langs->transnoentitiesnoconv($def['label'])), '', '');

if (empty($def['editform'])) {
	print '<div class="opacitymedium">'.$langs->trans('CfNoEditForm').'</div>';
}
if (cardfieldsModeDelegated($objkey, 'create')) {
	print '<div class="warning">'.$langs->trans('CfProductCreateDelegated', dol_buildpath('/productquickcreate/admin/setup.php', 1)).'</div>';
}

// Survey of the card
$cardurl = dol_buildpath($def['page'], 1);
$links = array();
if (!cardfieldsModeDelegated($objkey, 'create')) {
	$links[] = '<a href="'.$cardurl.'?action=create&cardfields_survey=1" target="_blank" rel="noopener">'.$langs->trans('CfModeCreate').'</a>';
}
if ($lastid > 0) {
	if (!empty($def['editform'])) {
		$links[] = '<a href="'.$cardurl.'?'.$def['idparam'].'='.$lastid.'&action=edit&cardfields_survey=1" target="_blank" rel="noopener">'.$langs->trans('CfModeEdit').'</a>';
	}
	$links[] = '<a href="'.$cardurl.'?'.$def['idparam'].'='.$lastid.'&cardfields_survey=1" target="_blank" rel="noopener">'.$langs->trans('CfModeView').'</a>';
}
$nbsurvey = 0;
$sql = "SELECT COUNT(*) as nb FROM ".$db->prefix()."cardfields_survey WHERE entity = ".((int) $conf->entity)." AND objectkey = '".$db->escape($objkey)."'";
$resql = $db->query($sql);
if ($resql && ($obj = $db->fetch_object($resql))) {
	$nbsurvey = (int) $obj->nb;
}
print '<div class="info">';
print $langs->trans('CfSurveyDesc').'<br>';
print '<strong>'.$langs->trans('CfSurveyOpen').'</strong> '.implode(' · ', $links);
if ($nbsurvey) {
	print '<br><span class="opacitymedium">'.$langs->trans('CfSurveyCount', $nbsurvey).'</span>';
	print ' <a class="reposition" href="'.$_SERVER['PHP_SELF'].'?obj='.urlencode($objkey).'&action=clearsurvey&token='.newToken().'">'.img_picto('', 'delete', 'class="pictofixedwidth"').$langs->trans('CfSurveyClear').'</a>';
}
print '</div>';

// Fields
$requiredset = array();
$hiddenset = array();
foreach ($modes as $mode) {
	$requiredset[$mode] = cardfieldsConfigured($objkey, $mode, 'required', $catalog);
	$hiddenset[$mode] = cardfieldsConfigured($objkey, $mode, 'hidden', $catalog);
}
$sources = array('static' => 'CfSourceCard', 'extrafield' => 'CfSourceExtrafield', 'survey' => 'CfSourceSurvey');

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?obj='.urlencode($objkey).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="savefields">';
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td rowspan="2">'.$langs->trans('Field').'</td><td rowspan="2">'.$langs->trans('CfSource').'</td>';
print '<td colspan="2" class="center">'.$langs->trans('CfModeCreate').'</td>';
print '<td colspan="2" class="center">'.$langs->trans('CfModeEdit').'</td>';
print '<td class="center">'.$langs->trans('CfModeView').'</td>';
print '</tr>';
print '<tr class="liste_titre">';
print '<td class="center">'.$langs->trans('CfRequired').'</td><td class="center">'.$langs->trans('CfHidden').'</td>';
print '<td class="center">'.$langs->trans('CfRequired').'</td><td class="center">'.$langs->trans('CfHidden').'</td>';
print '<td class="center">'.$langs->trans('CfHidden').'</td>';
print '</tr>';

// Fields required by Dolibarr itself: locked
foreach ($core as $key => $c) {
	print '<tr class="oddeven"><td>'.dol_escape_htmltag($c['label']).'</td><td><span class="opacitymedium">'.$langs->trans('CfSourceCore').'</span></td>';
	foreach ($modes as $mode) {
		$in = in_array($mode, $c['modes']);
		if ($mode != 'view') {
			print '<td class="center">'.($in ? '<input type="checkbox" checked disabled title="'.dol_escape_htmltag($langs->trans('CfCoreRequired')).'">' : '').'</td>';
		}
		print '<td class="center">'.($in ? '<input type="checkbox" disabled title="'.dol_escape_htmltag($langs->trans('CfCoreRequired')).'">' : '').'</td>';
	}
	print '</tr>';
}

if (!count($catalog)) {
	print '<tr class="oddeven"><td colspan="7"><span class="opacitymedium">'.$langs->trans('CfNoField').'</span></td></tr>';
}

/**
 * Cell of a checkbox of the setup table
 *
 * @param array<string,mixed> $field  Field
 * @param string $key    Field key
 * @param string $objkey Object key
 * @param string $mode   Mode
 * @param string $use    required or hidden
 * @param bool   $checked Checked
 * @return string HTML
 */
function cardfieldsSetupCell($field, $key, $objkey, $mode, $use, $checked)
{
	global $langs;

	if (!isset($field['modes'][$mode])) {
		return '<td class="center"><span class="opacitymedium" title="'.dol_escape_htmltag($langs->trans('CfNotInMode')).'">·</span></td>';
	}
	if (!empty($field['core'][$mode])) {
		return '<td class="center"><input type="checkbox"'.($use == 'required' ? ' checked' : '').' disabled title="'.dol_escape_htmltag($langs->trans('CfCoreRequired')).'"></td>';
	}
	if (!cardfieldsCan($field, $objkey, $mode, $use)) {
		$why = 'CfNotAllowed';
		if ($use == 'required' && !empty($field['whynot'])) {
			$why = $field['whynot'];
		} elseif ($use == 'required' && $mode == 'view') {
			$why = 'CfViewNoRequire';
		} elseif ($use == 'hidden' && $mode == 'edit') {
			$why = 'CfInlineNoHide';
		} elseif ($use == 'required' && $mode == 'edit') {
			$why = 'CfInlineNoRequire';
		} elseif ($use == 'required') {
			$why = 'CfAlwaysFilled';
		}
		if (cardfieldsModeDelegated($objkey, $mode)) {
			$why = 'CfDelegated';
		}
		return '<td class="center"><span class="opacitymedium" title="'.dol_escape_htmltag($langs->trans($why)).'">-</span></td>';
	}
	$name = ($use == 'required' ? 'r_' : 'h_').$mode.'_'.$key;
	return '<td class="center"><input type="checkbox" class="cf'.$use.'" data-mode="'.$mode.'" data-key="'.dol_escape_htmltag($key).'" name="'.dol_escape_htmltag($name).'" value="1"'.($checked ? ' checked' : '').'></td>';
}

foreach ($catalog as $key => $field) {
	print '<tr class="oddeven"><td>'.dol_escape_htmltag($field['label']).'</td>';
	print '<td><span class="opacitymedium">'.$langs->trans($sources[$field['source']]).'</span></td>';
	foreach ($modes as $mode) {
		if ($mode != 'view') {
			print cardfieldsSetupCell($field, $key, $objkey, $mode, 'required', in_array($key, $requiredset[$mode]));
		}
		print cardfieldsSetupCell($field, $key, $objkey, $mode, 'hidden', in_array($key, $hiddenset[$mode]));
	}
	print '</tr>';
}
print '</table>';
print '</div>';
print '<span class="opacitymedium small">'.$langs->trans('CfFieldsNote').'</span><br>';
print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Save')).'"></div>';
print '</form>';

// A field cannot be both mandatory and hidden in the same mode: checking one box unchecks the other
print '<script>
jQuery(function() {
	jQuery("input.cfrequired").on("change", function() { if (this.checked) { jQuery("input.cfhidden[data-mode=\'" + jQuery(this).data("mode") + "\'][data-key=\'" + jQuery(this).data("key") + "\']").prop("checked", false); } });
	jQuery("input.cfhidden").on("change", function() { if (this.checked) { jQuery("input.cfrequired[data-mode=\'" + jQuery(this).data("mode") + "\'][data-key=\'" + jQuery(this).data("key") + "\']").prop("checked", false); } });
});
</script>';

print dol_get_fiche_end();

llxFooter();
$db->close();
