<?php
/* Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
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
require_once dol_buildpath('/productenrichment/lib/productenrichment.lib.php');
require_once dol_buildpath('/productenrichment/core/modules/modProductEnrichment.class.php');

if (!$user->admin) {
	accessforbidden();
}

$langs->loadLangs(array('admin', 'productenrichment@productenrichment'));

llxHeader('', $langs->trans('PeChangelog'));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('PeSetupTitle'), $linkback, 'title_setup');

$head = productenrichmentAdminPrepareHead();
print dol_get_fiche_head($head, 'changelog', $langs->trans('PeModuleName'), -1, 'product');

$moduleobj = new modProductEnrichment($db);
print '<span class="opacitymedium">'.$langs->trans('Version').' : </span><strong>'.$moduleobj->version.'</strong><br><br>';

$changelogfile = dol_buildpath('/productenrichment/ChangeLog.md');
if (file_exists($changelogfile)) {
	$content = file_get_contents($changelogfile);
	// Minimal markdown rendering: headings + list items
	$lines = explode("\n", $content);
	foreach ($lines as $line) {
		$line = rtrim($line);
		if (preg_match('/^##\s+(.*)$/', $line, $m)) {
			print '<h3 class="paddingtop">'.dol_escape_htmltag($m[1]).'</h3>';
		} elseif (preg_match('/^#\s+(.*)$/', $line, $m)) {
			print '<h2>'.dol_escape_htmltag($m[1]).'</h2>';
		} elseif (preg_match('/^[-*]\s+(.*)$/', $line, $m)) {
			print '<li>'.dol_escape_htmltag($m[1]).'</li>';
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
