<?php
/* Copyright (C) 2026 Jose MARTINEZ <jose.martinez@pichinov.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

// Load Dolibarr environment
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

$langs->loadLangs(array('admin', 'products', 'productquickcreate@productquickcreate'));

$page_name = 'PqcSetupTitle';

llxHeader('', $langs->trans($page_name));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

$head = productquickcreateAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans('PqcModuleName'), -1, 'product');

print '<span class="opacitymedium">'.$langs->trans('PqcSetupDesc').'</span><br><br>';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td class="center">'.$langs->trans('Status').'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('PqcSupplierBlock').'<br><span class="opacitymedium small">'.$langs->trans('PqcSupplierBlockDesc').'</span></td>';
print '<td class="center">'.ajax_constantonoff('PRODUCTQUICKCREATE_SUPPLIER_BLOCK').'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('PqcCollapseSections').'<br><span class="opacitymedium small">'.$langs->trans('PqcCollapseSectionsDesc').'</span></td>';
print '<td class="center">'.ajax_constantonoff('PRODUCT_CREATE_COLLAPSE_SECTIONS').'</td></tr>';

print '</table>';

print dol_get_fiche_end();

llxFooter();
$db->close();
