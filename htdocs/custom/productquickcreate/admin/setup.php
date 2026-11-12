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

$action = GETPOST('action', 'aZ09');

/*
 * Actions
 */

// Mandatory and hidden fields of the creation form
if ($action == 'savefields') {	// Test on permission already done (admin only page)
	$catalog = productquickcreateFieldCatalog();
	$required = array();
	$hidden = array();
	$conflicts = array();
	foreach ($catalog as $key => $field) {
		$isrequired = (!empty($field['canrequire']) && GETPOST('required_'.$key, 'alpha') == '1');
		$ishidden = (!empty($field['canhide']) && GETPOST('hidden_'.$key, 'alpha') == '1');
		if ($isrequired) {
			$required[] = $key;
		}
		if ($ishidden && $isrequired) {
			$conflicts[] = $langs->transnoentitiesnoconv($field['label']);	// A mandatory field stays visible (raw text: the message is encoded once)
		} elseif ($ishidden) {
			$hidden[] = $key;
		}
	}
	dolibarr_set_const($db, 'PRODUCTQUICKCREATE_REQUIRED_FIELDS', implode(',', $required), 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'PRODUCTQUICKCREATE_HIDDEN_FIELDS', implode(',', $hidden), 'chaine', 0, '', $conf->entity);
	setEventMessages($langs->trans('PqcFieldsSaved'), null, 'mesgs');
	if (count($conflicts)) {
		setEventMessages($langs->trans('PqcFieldsConflict', implode(', ', $conflicts)), null, 'warnings');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

/*
 * View
 */

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

// Mandatory and hidden fields of the creation form
print '<br>';
print load_fiche_titre($langs->trans('PqcFieldsTitle'), '', '');
print '<span class="opacitymedium">'.$langs->trans('PqcFieldsDesc').'</span><br><br>';

$catalog = productquickcreateFieldCatalog();
$core = productquickcreateCoreRequiredFields();
$requiredset = productquickcreateConfiguredFields('required');
$hiddenset = productquickcreateConfiguredFields('hidden');

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="savefields">';
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Field').'</td><td>'.$langs->trans('PqcFieldSection').'</td><td class="center">'.$langs->trans('PqcFieldRequired').'</td><td class="center">'.$langs->trans('PqcFieldHidden').'</td></tr>';

// Fields required by Dolibarr itself: locked
foreach ($core as $key => $labelkey) {
	print '<tr class="oddeven"><td>'.$langs->trans($labelkey).'</td><td><span class="opacitymedium">'.$langs->trans('PqcFieldCoreSection').'</span></td>';
	print '<td class="center"><input type="checkbox" checked disabled title="'.dol_escape_htmltag($langs->trans('PqcFieldCoreRequired')).'"></td>';
	print '<td class="center"><input type="checkbox" disabled title="'.dol_escape_htmltag($langs->trans('PqcFieldCoreRequired')).'"></td></tr>';
}
foreach ($catalog as $key => $field) {
	if (empty($field['enabled']) || !empty($core[$key])) {
		continue;
	}
	print '<tr class="oddeven"><td>'.$langs->trans($field['label']).'</td><td><span class="opacitymedium">'.$langs->trans($field['group']).'</span></td>';
	print '<td class="center">';
	if (!empty($field['canrequire'])) {
		print '<input type="checkbox" class="pqcrequired" data-key="'.$key.'" name="required_'.$key.'" value="1"'.(in_array($key, $requiredset) ? ' checked' : '').'>';
	} else {
		print '<span class="opacitymedium" title="'.dol_escape_htmltag($langs->trans('PqcFieldAlwaysFilled')).'">-</span>';
	}
	print '</td><td class="center">';
	if (!empty($field['canhide'])) {
		print '<input type="checkbox" class="pqchidden" data-key="'.$key.'" name="hidden_'.$key.'" value="1"'.(in_array($key, $hiddenset) ? ' checked' : '').'>';
	} else {
		print '<span class="opacitymedium">-</span>';
	}
	print '</td></tr>';
}
print '</table>';
print '</div>';
print '<span class="opacitymedium small">'.$langs->trans('PqcFieldsNote').'</span><br>';
print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Save')).'"></div>';
print '</form>';
// A mandatory field cannot be hidden: checking one box unchecks the other
print '<script>
jQuery(function() {
	jQuery("input.pqcrequired").on("change", function() { if (this.checked) { jQuery("input.pqchidden[data-key=\'" + jQuery(this).data("key") + "\']").prop("checked", false); } });
	jQuery("input.pqchidden").on("change", function() { if (this.checked) { jQuery("input.pqcrequired[data-key=\'" + jQuery(this).data("key") + "\']").prop("checked", false); } });
});
</script>';

print dol_get_fiche_end();

llxFooter();
$db->close();
