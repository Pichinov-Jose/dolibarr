<?php
/* Copyright (C) 2026 Jose Martinez <jose.martinez@pichinov.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    support.php
 * \ingroup takeposvendeur
 * \brief   Formulaire d'assistance autonome (sans licence) : le client écrit,
 *          le module envoie un e-mail à l'assistance Pichinov avec les diagnostics.
 */

// Load Dolibarr environment
$res = 0;
if (!$res && file_exists("../main.inc.php")) $res = include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = include "../../../main.inc.php";
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/takeposvendeur/lib/takeposvendeur.lib.php');
dol_include_once('/takeposvendeur/class/takeposvendeursupport.class.php');

$langs->loadLangs(array('admin', 'other', 'takeposvendeur@takeposvendeur'));

// Support envoie des données techniques hors ERP : réservé aux admins.
if (!$user->admin) {
	accessforbidden();
}

$action   = GETPOST('action', 'alpha');
$type     = GETPOST('type', 'alpha') ?: 'ISSUE';
$severity = GETPOST('severity', 'alpha') ?: 'NORMAL';
$subject  = GETPOST('subject', 'alphanohtml');
$message  = GETPOST('message', 'restricthtml');
$email    = GETPOST('email', 'alpha');

$support = new TakeposvendeurSupport($db);
$diagnostics = $support->collectDiagnostics();

if ($email === '' && !empty($user->email)) {
	$email = $user->email;
}

if ($action == 'send' && $user->admin) {
	$withdiag = GETPOSTINT('withdiag');
	$rc = $support->send($type, $severity, $subject, $message, $email, $withdiag ? $diagnostics : array());
	if ($rc > 0) {
		setEventMessages($langs->trans('TpvSupportSent', TakeposvendeurSupport::supportEmail()), null, 'mesgs');
		$subject = $message = '';
	} else {
		setEventMessages($langs->trans($support->error ? $support->error : 'Error'), $support->errors, 'errors');
	}
}

/*
 * View
 */
$title = $langs->trans("ModuleTakeposvendeurName");
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-takeposvendeur page-support');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($title, $linkback, 'title_setup');

$head = takeposvendeurAdminPrepareHead();
print dol_get_fiche_head($head, 'support', $title, -1, 'user');

print '<span class="opacitymedium">'.$langs->trans("TpvSupportIntro", TakeposvendeurSupport::supportEmail()).'</span><br><br>';

print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="send">';

print '<table class="border centpercent">';

print '<tr class="oddeven"><td class="titlefieldmiddle">'.$langs->trans("Module").'</td><td><b>'.dol_escape_htmltag($title).'</b> '.dol_escape_htmltag((string) $diagnostics['module_version']).'</td></tr>';

print '<tr class="oddeven"><td class="fieldrequired">'.$langs->trans("TpvSupportType").'</td><td>';
print '<label class="marginrightonly"><input type="radio" name="type" value="ISSUE"'.($type === 'ISSUE' ? ' checked' : '').'> '.$langs->trans("TpvSupportTypeIssue").'</label> ';
print '<label><input type="radio" name="type" value="REQUEST"'.($type === 'REQUEST' ? ' checked' : '').'> '.$langs->trans("TpvSupportTypeRequest").'</label>';
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("TpvSupportSeverity").'</td><td>';
print '<select name="severity">';
foreach (array('LOW', 'NORMAL', 'HIGH') as $sev) {
	print '<option value="'.$sev.'"'.($severity === $sev ? ' selected' : '').'>'.$langs->trans("TpvSeverity".ucfirst(strtolower($sev))).'</option>';
}
print '</select></td></tr>';

print '<tr class="oddeven"><td class="fieldrequired">'.$langs->trans("TpvSupportSubject").'</td><td><input type="text" name="subject" class="minwidth500" value="'.dol_escape_htmltag($subject).'"></td></tr>';

print '<tr class="oddeven"><td class="fieldrequired">'.$langs->trans("TpvSupportMessage").'</td><td>';
print '<textarea name="message" class="quatrevingtpercent" rows="6">'.dol_escape_htmltag($message).'</textarea></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("TpvSupportEmail").'</td><td><input type="text" name="email" class="minwidth300" value="'.dol_escape_htmltag($email).'"></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("TpvSupportDiag").'</td><td>';
print '<label><input type="checkbox" name="withdiag" value="1" checked> <b>'.$langs->trans("TpvSupportAttachDiag").'</b></label>';
print '<div class="opacitymedium small"><pre style="white-space:pre-wrap;margin:6px 0 0;">'.dol_escape_htmltag(TakeposvendeurSupport::diagnosticsToText($diagnostics)).'</pre></div>';
print '</td></tr>';

print '</table>';
print '<div class="center paddingtop"><input type="submit" class="button" value="'.$langs->trans("TpvSupportSend").'"></div>';
print '</form>';

print dol_get_fiche_end();

llxFooter();
$db->close();
