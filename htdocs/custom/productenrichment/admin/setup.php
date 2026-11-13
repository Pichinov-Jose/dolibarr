<?php
/* Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
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
require_once dol_buildpath('/productenrichment/lib/productenrichment.lib.php');
dol_include_once('/productenrichment/class/productenrichment.class.php');

if (!$user->admin) {
	accessforbidden();
}

$langs->loadLangs(array('admin', 'products', 'cron', 'productenrichment@productenrichment'));

$action = GETPOST('action', 'aZ09');

/*
 * Actions
 */
if ($action == 'save') {	// Test on permission already done (admin only page)
	$maximages = min(5, max(0, GETPOSTINT('PRODUCTENRICHMENT_MAX_IMAGES')));
	$batch = min(50, max(1, GETPOSTINT('PRODUCTENRICHMENT_CRON_BATCH')));
	dolibarr_set_const($db, 'PRODUCTENRICHMENT_MAX_IMAGES', (string) $maximages, 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'PRODUCTENRICHMENT_CRON_BATCH', (string) $batch, 'chaine', 0, '', $conf->entity);
	setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

/*
 * View
 */
llxHeader('', $langs->trans('PeSetupTitle'));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('PeSetupTitle'), $linkback, 'title_setup');

$head = productenrichmentAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans('PeModuleName'), -1, 'product');

print '<span class="opacitymedium">'.$langs->trans('PeSetupDesc').'</span><br><br>';

// Sources: never show a key or a token, only whether it is set
$sources = ProductEnrichment::sourcesConfigured();
$quota = ProductEnrichment::quotaStatus();
$yes = '<span class="badge badge-status4 badge-status">'.$langs->trans('PeConfigured').'</span>';
$no = '<span class="badge badge-status8 badge-status">'.$langs->trans('PeNotConfigured').'</span>';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('PeSource').'</td><td>'.$langs->trans('Status').'</td><td>'.$langs->trans('PeQuotaToday').'</td><td>'.$langs->trans('Description').'</td></tr>';
print '<tr class="oddeven"><td>UPCitemdb</td><td>'.$yes.'</td><td>'.$quota['upc']['used'].' / '.$quota['upc']['max'].'</td><td class="small opacitymedium">'.$langs->trans('PeSourceUpcDesc').'</td></tr>';
print '<tr class="oddeven"><td>eBay</td><td>'.($sources['ebay'] ? $yes.' <span class="opacitymedium small">('.dol_escape_htmltag(getDolGlobalString('SCANCAPTURE_EBAY_ENV', 'production')).')</span>' : $no).'</td><td>'.$quota['ebay']['used'].' / '.$quota['ebay']['max'].'</td><td class="small opacitymedium">'.$langs->trans('PeSourceEbayDesc').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('PeAi').'</td><td>'.($sources['ai'] ? $yes.' <span class="opacitymedium small">('.dol_escape_htmltag(getDolGlobalString('SCANCAPTURE_AI_MODEL', getDolGlobalString('AI_API_ANTHROPIC_MODEL_TEXT', 'claude-sonnet-5'))).')</span>' : $no).'</td><td>'.$quota['ai']['used'].' / '.$quota['ai']['max'].'</td><td class="small opacitymedium">'.$langs->trans('PeSourceAiDesc').'</td></tr>';
print '</table>';
print '<span class="opacitymedium small">'.$langs->trans('PeSourcesShared').'</span><br><br>';

// Options
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('PeSpecsInDesc').'<br><span class="opacitymedium small">'.$langs->trans('PeSpecsInDescDesc').'</span></td><td>'.ajax_constantonoff('PRODUCTENRICHMENT_SPECS_IN_DESC', array(), null, 0, 0, 0, 2, 0, 1).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('PeMaxImages').'<br><span class="opacitymedium small">'.$langs->trans('PeMaxImagesDesc').'</span></td><td><input type="number" min="0" max="5" class="width50" name="PRODUCTENRICHMENT_MAX_IMAGES" value="'.((int) getDolGlobalString('PRODUCTENRICHMENT_MAX_IMAGES', (string) ProductEnrichment::MAX_IMAGES)).'"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('PeCronBatch').'<br><span class="opacitymedium small">'.$langs->trans('PeCronBatchDesc').'</span></td><td><input type="number" min="1" max="50" class="width50" name="PRODUCTENRICHMENT_CRON_BATCH" value="'.((int) getDolGlobalString('PRODUCTENRICHMENT_CRON_BATCH', '10')).'"></td></tr>';
print '</table>';
print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Save')).'"></div>';
print '</form><br>';

// Characteristics and scheduled job
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('PeLinks').'</td></tr>';
$pe = new ProductEnrichment($db);
$nbattr = count($pe->featureAttributes());
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('PeCharacteristicsModule').'</td><td>';
if (ProductEnrichment::characteristicsEnabled()) {
	print $yes.' — '.$langs->trans('PeFeatureAttributes', $nbattr);
	if (!$nbattr) {
		print '<br><span class="warning">'.$langs->trans('PeJs_NoFeatureAttribute').'</span>';
	}
	print ' <a href="'.dol_buildpath('/productcharacteristics/admin/setup.php', 1).'">'.$langs->trans('Setup').'</a>';
} else {
	print $no.' <span class="opacitymedium">'.$langs->trans('PeJs_NoCharacteristicsModule').'</span>';
}
print '</td></tr>';
$resql = $db->query("SELECT rowid, status, datelastrun FROM ".MAIN_DB_PREFIX."cronjob WHERE objectname = 'ProductEnrichment' AND methodename = 'runQueue'");
$cron = ($resql ? $db->fetch_object($resql) : null);
print '<tr class="oddeven"><td>'.$langs->trans('PeCronLabel').'</td><td>';
if ($cron) {
	print ($cron->status ? $yes : $no).' — '.$langs->trans('PeLastRun').' : '.($cron->datelastrun ? dol_print_date($db->jdate($cron->datelastrun), 'dayhour') : '<span class="warning">'.$langs->trans('PeNever').'</span>');
	print ' <a href="'.DOL_URL_ROOT.'/cron/card.php?id='.((int) $cron->rowid).'">'.$langs->trans('Show').'</a>';
}
print ' — <a href="'.dol_buildpath('/productenrichment/queue.php', 1).'">'.$langs->trans('PeQueueTitle').'</a>';
print '</td></tr>';
print '</table>';

print dol_get_fiche_end();

llxFooter();
$db->close();
