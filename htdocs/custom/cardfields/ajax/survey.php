<?php
/* Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    cardfields/ajax/survey.php
 * \brief   Records the fields found on a card by the survey (admin only)
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}

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

dol_include_once('/cardfields/lib/cardfields.lib.php');

top_httphead('application/json');

if (!isModEnabled('cardfields') || !$user->admin || $_SERVER['REQUEST_METHOD'] != 'POST') {
	http_response_code(403);
	print json_encode(array('ok' => 0, 'message' => 'Forbidden'));
	exit;
}

$langs->load('cardfields@cardfields');

$objkey = GETPOST('objkey', 'aZ09');
$mode = GETPOST('mode', 'aZ09');
$objects = cardfieldsObjects();
if (empty($objects[$objkey]) || !in_array($mode, cardfieldsModes())) {
	http_response_code(400);
	print json_encode(array('ok' => 0, 'message' => 'Bad object or mode'));
	exit;
}

$list = json_decode((string) GETPOST('fields', 'none'), true);
if (!is_array($list)) {
	http_response_code(400);
	print json_encode(array('ok' => 0, 'message' => 'Bad fields'));
	exit;
}

$db->begin();
$nb = 0;
$position = 0;
$error = 0;
foreach (array_slice($list, 0, 200) as $item) {
	if (!is_array($item)) {
		continue;
	}
	$label = dol_trunc(trim(dol_string_nohtmltag((string) (isset($item['label']) ? $item['label'] : ''))), 250, 'right', 'UTF-8', 1);
	$names = array();
	foreach ((isset($item['names']) && is_array($item['names']) ? $item['names'] : array()) as $n) {
		if (is_string($n) && preg_match('/^[A-Za-z0-9_\-]+(\[\])?$/', $n)) {
			$names[] = $n;
		}
	}
	$empty = array();
	foreach ((isset($item['empty']) && is_array($item['empty']) ? $item['empty'] : array()) as $v) {
		if (is_scalar($v) && strlen((string) $v) <= 64) {
			$empty[] = (string) $v;
		}
	}
	$norm = cardfieldsNormLabel($label);
	if ($norm === '' && !count($names)) {
		continue;
	}
	// Same key in every mode for the same label: one line per field in the setup
	$fieldkey = ($norm !== '' ? 's'.substr(md5($objkey.'|'.$norm), 0, 12) : 'n'.substr(md5($objkey.'|'.implode(',', $names)), 0, 12));
	$data = array('names' => array_slice($names, 0, 20), 'empty' => array_slice($empty, 0, 20), 'canrequire' => (empty($item['canrequire']) ? 0 : 1), 'core' => (empty($item['core']) ? 0 : 1));

	$sql = "DELETE FROM ".$db->prefix()."cardfields_survey WHERE entity = ".((int) $conf->entity);
	$sql .= " AND objectkey = '".$db->escape($objkey)."' AND mode = '".$db->escape($mode)."' AND fieldkey = '".$db->escape($fieldkey)."'";
	if (!$db->query($sql)) {
		$error++;
		break;
	}
	$sql = "INSERT INTO ".$db->prefix()."cardfields_survey (entity, objectkey, mode, fieldkey, label, data, position, datec)";
	$sql .= " VALUES (".((int) $conf->entity).", '".$db->escape($objkey)."', '".$db->escape($mode)."', '".$db->escape($fieldkey)."',";
	$sql .= " '".$db->escape($label)."', '".$db->escape(json_encode($data))."', ".((int) $position).", '".$db->idate(dol_now())."')";
	if (!$db->query($sql)) {
		$error++;
		break;
	}
	$position++;
	$nb++;
}

if ($error) {
	$db->rollback();
	http_response_code(500);
	print json_encode(array('ok' => 0, 'message' => $langs->transnoentitiesnoconv('CfSurveyFailed').' '.$db->lasterror()));
	exit;
}
$db->commit();

$modes = array('create' => 'CfModeCreate', 'edit' => 'CfModeEdit', 'view' => 'CfModeView');
print json_encode(array('ok' => 1, 'count' => $nb, 'message' => $langs->transnoentitiesnoconv('CfSurveySaved', $nb, $langs->transnoentitiesnoconv($objects[$objkey]['label']), $langs->transnoentitiesnoconv($modes[$mode]))));
