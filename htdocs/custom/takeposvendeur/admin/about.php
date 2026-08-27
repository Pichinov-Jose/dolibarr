<?php
/* Copyright (C) 2026 Jose Martinez <jose.martinez@pichinov.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    admin/about.php
 * \ingroup takeposvendeur
 * \brief   Page « À propos » du module Takeposvendeur (autonome, sans licence).
 */

// Load Dolibarr environment
$res = 0;
if (!$res && file_exists("../../main.inc.php")) $res = include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = include "../../../main.inc.php";
if (!$res && file_exists("../../../../main.inc.php")) $res = include "../../../../main.inc.php";
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/takeposvendeur/lib/takeposvendeur.lib.php');
dol_include_once('/takeposvendeur/class/takeposvendeurupdater.class.php');
dol_include_once('/takeposvendeur/core/modules/modTakeposvendeur.class.php');

$langs->loadLangs(array('admin', 'takeposvendeur@takeposvendeur'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'alpha');
$updater = new TakeposvendeurUpdater($db);
$upd = null;
if ($action == 'checkupdate') {
	$upd = $updater->checkForUpdate();
	if (!is_array($upd)) {
		setEventMessages($langs->trans($updater->error ? $updater->error : 'Error'), null, 'errors');
		$upd = null;
	}
}

/*
 * View
 */
$descriptor = new modTakeposvendeur($db);
$title = $descriptor->getName();

llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-takeposvendeur page-admin_about');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($title, $linkback, 'title_setup');

$head = takeposvendeurAdminPrepareHead();
print dol_get_fiche_head($head, 'about', $title, -1, 'user');

print '<table class="border centpercent">';
print '<tr><td class="titlefieldmiddle">'.$langs->trans("Module").'</td><td><b>'.dol_escape_htmltag($title).'</b></td></tr>';
print '<tr><td>'.$langs->trans("Version").'</td><td>'.dol_escape_htmltag($descriptor->version).'</td></tr>';
print '<tr><td>'.$langs->trans("Publisher").'</td><td><a href="https://www.pichinov.com" target="_blank" rel="noopener">Pichinov</a></td></tr>';
print '<tr><td>'.$langs->trans("Description").'</td><td>'.dol_escape_htmltag($descriptor->descriptionlong).'</td></tr>';
print '<tr><td>'.$langs->trans("TpvRepo").'</td><td><a href="'.TakeposvendeurUpdater::repoUrl().'" target="_blank" rel="noopener">'.dol_escape_htmltag(TakeposvendeurUpdater::repo()).'</a></td></tr>';
print '</table>';

// Vérification de mise à jour (GitHub)
print '<br>';
print load_fiche_titre($langs->trans("TpvUpdateCheck"), '', '');
print '<div class="paddingbottom">';
if (is_array($upd)) {
	print $langs->trans("TpvInstalledVersion").' : <b>'.dol_escape_htmltag($upd['installed'] ?: '?').'</b><br>';
	print $langs->trans("TpvLatestVersion").' : <b>'.dol_escape_htmltag($upd['latest']).'</b><br>';
	if ($upd['has_update']) {
		print '<div class="warning">'.$langs->trans("TpvUpdateAvailable", $upd['latest']).' — <a href="'.$upd['url'].'" target="_blank" rel="noopener">'.$langs->trans("TpvOpenRepo").'</a></div>';
	} else {
		print '<div class="ok">'.$langs->trans("TpvUpToDate").'</div>';
	}
} else {
	print '<span class="opacitymedium">'.$langs->trans("TpvUpdateCheckHelp").'</span><br><br>';
	print '<a class="button" href="'.$_SERVER["PHP_SELF"].'?action=checkupdate&token='.newToken().'">'.$langs->trans("TpvCheckNow").'</a>';
}
print '</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
