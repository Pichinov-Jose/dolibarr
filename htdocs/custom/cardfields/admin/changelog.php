<?php
/* Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    cardfields/admin/changelog.php
 * \brief   Changelog tab of the module Champs par fiche
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
require_once dol_buildpath('/cardfields/core/modules/modCardFields.class.php');

if (!$user->admin) {
	accessforbidden();
}

$langs->loadLangs(array('admin', 'cardfields@cardfields'));

llxHeader('', $langs->trans('CfChangelog'));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('CfSetupTitle'), $linkback, 'title_setup');

$head = cardfieldsAdminPrepareHead();
print dol_get_fiche_head($head, 'changelog', $langs->trans('CfModuleName'), -1, 'list');

$moduleobj = new modCardFields($db);
print '<span class="opacitymedium">'.$langs->trans('Version').' : </span><strong>'.$moduleobj->version.'</strong><br><br>';

$changelogfile = dol_buildpath('/cardfields/ChangeLog.md');
if (file_exists($changelogfile)) {
	// Minimal markdown rendering: headings and list items
	foreach (explode("\n", (string) file_get_contents($changelogfile)) as $line) {
		$line = rtrim($line);
		$m = array();
		if (preg_match('/^##\s+(.*)$/', $line, $m)) {
			print '<h3 class="paddingtop">'.dol_escape_htmltag($m[1]).'</h3>';
		} elseif (preg_match('/^#\s+(.*)$/', $line, $m)) {
			print '<h2>'.dol_escape_htmltag($m[1]).'</h2>';
		} elseif (preg_match('/^[-*]\s+(.*)$/', $line, $m)) {
			print '<li>'.dol_escape_htmltag($m[1]).'</li>';
		} elseif (preg_match('/^\s+(.*)$/', $line, $m)) {
			print ' '.dol_escape_htmltag($m[1]);
		} elseif ($line !== '') {
			print '<p>'.dol_escape_htmltag($line).'</p>';
		}
	}
} else {
	print '<span class="opacitymedium">'.$langs->trans('None').'</span>';
}

print dol_get_fiche_end();

llxFooter();
$db->close();
