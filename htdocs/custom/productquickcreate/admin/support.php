<?php
/* Copyright (C) 2026 Jose MARTINEZ <jose.martinez@pichinov.com>
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
require_once dol_buildpath('/productquickcreate/lib/productquickcreate.lib.php');

if (!$user->admin) {
	accessforbidden();
}

$langs->loadLangs(array('admin', 'help', 'productquickcreate@productquickcreate'));

llxHeader('', $langs->trans('Support'));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('PqcSetupTitle'), $linkback, 'title_setup');

$head = productquickcreateAdminPrepareHead();
print dol_get_fiche_head($head, 'support', $langs->trans('PqcModuleName'), -1, 'product');

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('Support').'</td></tr>';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('Editor').'</td><td>Pichinov</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('Contact').'</td><td><a href="mailto:jose.martinez@pichinov.com">jose.martinez@pichinov.com</a></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('PqcRepository').'</td><td><a href="https://github.com/Pichinov/productquickcreate" target="_blank" rel="noopener">github.com/Pichinov/productquickcreate</a></td></tr>';
print '</table>';

print dol_get_fiche_end();

llxFooter();
$db->close();
