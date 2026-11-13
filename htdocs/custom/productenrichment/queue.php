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

/**
 * Queue of the mass enrichment and journal of the enrichments
 */
$res = 0;
if (!$res && file_exists('../main.inc.php')) {
	$res = @include '../main.inc.php';
}
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}
dol_include_once('/productenrichment/class/productenrichment.class.php');

if (!isModEnabled('productenrichment') || !$user->hasRight('produit', 'creer')) {
	accessforbidden();
}
$langs->loadLangs(array('products', 'productenrichment@productenrichment'));

$action = GETPOST('action', 'aZ09');
$filter = GETPOST('status', 'aZ09');
$pe = new ProductEnrichment($db);
$entity = (int) $conf->entity;

/*
 * Actions (the token is checked by main.inc.php)
 */
if ($action == 'retry') {
	$db->query("UPDATE ".MAIN_DB_PREFIX."productenrichment_job SET status = 'pending', date_done = NULL, message = NULL WHERE origin = 'mass' AND status IN ('error', 'notfound', 'mismatch') AND entity = ".$entity);
	setEventMessages($langs->trans('PeRetried', $db->affected_rows(true)), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}
if ($action == 'cancel') {
	$db->query("DELETE FROM ".MAIN_DB_PREFIX."productenrichment_job WHERE status = 'pending' AND entity = ".$entity);
	setEventMessages($langs->trans('PeCancelled', $db->affected_rows(true)), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}
if ($action == 'purge') {
	$db->query("DELETE FROM ".MAIN_DB_PREFIX."productenrichment_job WHERE status <> 'pending' AND entity = ".$entity);
	setEventMessages($langs->trans('PePurged', $db->affected_rows(true)), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

/*
 * View
 */
llxHeader('', $langs->trans('PeQueueTitle'), '', '', 0, 0, '', '', '', 'mod-productenrichment page-queue');
print load_fiche_titre($langs->trans('PeQueueTitle'), '', 'product');

// Counts by status
$counts = array();
$resql = $db->query("SELECT status, COUNT(*) as n FROM ".MAIN_DB_PREFIX."productenrichment_job WHERE entity = ".$entity." GROUP BY status");
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$counts[$obj->status] = (int) $obj->n;
	}
}
$pending = (int) ($counts['pending'] ?? 0);
print '<div class="info">';
$parts = array();
foreach (array('pending', 'done', 'nothing', 'notfound', 'mismatch', 'noean', 'error') as $st) {
	$parts[] = '<a href="'.$_SERVER['PHP_SELF'].'?status='.$st.'">'.$langs->trans('PeStatus_'.$st).' : <strong>'.((int) ($counts[$st] ?? 0)).'</strong></a>';
}
print implode(' &nbsp;·&nbsp; ', $parts);
$q = ProductEnrichment::quotaStatus();
print '<br><span class="opacitymedium">'.$langs->trans('PeQuotaToday').' : UPCitemdb '.$q['upc']['used'].'/'.$q['upc']['max'].', eBay '.$q['ebay']['used'].'/'.$q['ebay']['max'].', '.$langs->trans('PeAi').' '.$q['ai']['used'].'/'.$q['ai']['max'].'</span>';
print '</div>';

// The scheduler of Dolibarr does not run on every instance: say it, the button below does the work
$resql = $db->query("SELECT datelastrun FROM ".MAIN_DB_PREFIX."cronjob WHERE objectname = 'ProductEnrichment' AND methodename = 'runQueue' AND entity IN (0, ".$entity.")");
$cron = ($resql ? $db->fetch_object($resql) : null);
if ($pending && (!$cron || empty($cron->datelastrun))) {
	print '<div class="warning">'.$langs->trans('PeCronNeverRan').'</div>';
}

// Buttons
print '<div class="tabsAction">';
if ($pending) {
	print '<a class="butAction" href="#" id="pe_process">'.img_picto('', 'fa-play', 'class="pictofixedwidth"').$langs->trans('PeProcessNow').'</a>';
	print '<a class="butAction" href="#" id="pe_stop" style="display:none">'.img_picto('', 'fa-stop', 'class="pictofixedwidth"').$langs->trans('PeStop').'</a>';
	print '<a class="butActionDelete" href="'.$_SERVER['PHP_SELF'].'?action=cancel&token='.newToken().'" onclick="return confirm(\''.dol_escape_js($langs->transnoentitiesnoconv('PeConfirmCancel')).'\');">'.$langs->trans('PeCancelQueue').'</a>';
}
if (!empty($counts['error']) || !empty($counts['notfound'])) {
	print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=retry&token='.newToken().'">'.$langs->trans('PeRetry').'</a>';
}
print '<a class="butActionDelete" href="'.$_SERVER['PHP_SELF'].'?action=purge&token='.newToken().'" onclick="return confirm(\''.dol_escape_js($langs->transnoentitiesnoconv('PeConfirmPurge')).'\');">'.$langs->trans('PePurge').'</a>';
print '</div>';
print '<div id="pe_progress" class="opacitymedium" style="display:none"></div>';

// Journal
$sql = "SELECT j.rowid, j.fk_product, j.ean, j.origin, j.status, j.options, j.applied, j.message, j.datec, j.date_done, j.fk_user_creat, p.ref, p.label, u.login";
$sql .= " FROM ".MAIN_DB_PREFIX."productenrichment_job as j";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = j.fk_product";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user as u ON u.rowid = j.fk_user_creat";
$sql .= " WHERE j.entity = ".$entity;
if ($filter !== '') {
	$sql .= " AND j.status = '".$db->escape($filter)."'";
}
$sql .= " ORDER BY j.rowid DESC";
$sql .= $db->plimit(300);
$resql = $db->query($sql);

print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Date').'</td><td>'.$langs->trans('PeOrigin').'</td><td>'.$langs->trans('Product').'</td><td>EAN</td><td>'.$langs->trans('Status').'</td><td>'.$langs->trans('PeApplied').'</td><td>'.$langs->trans('PeMessage').'</td><td>'.$langs->trans('User').'</td></tr>';
$nb = 0;
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$nb++;
		$applied = json_decode((string) $obj->applied, true);
		$txt = array();
		foreach ((is_array($applied) ? $applied : array()) as $k => $v) {
			$txt[] = $langs->trans('PeField_'.$k).(is_numeric($v) && in_array($k, array('images', 'characteristics')) ? ' ('.((int) $v).')' : '');
		}
		$options = json_decode((string) $obj->options, true);
		$badge = array('pending' => 'status0', 'done' => 'status4', 'nothing' => 'status1', 'notfound' => 'status8', 'mismatch' => 'status1', 'noean' => 'status8', 'error' => 'status8');
		print '<tr class="oddeven">';
		print '<td class="nowraponall">'.dol_print_date($db->jdate($obj->date_done ? $obj->date_done : $obj->datec), 'dayhour').'</td>';
		print '<td>'.$langs->trans('PeOrigin_'.$obj->origin).(!empty($options['ai']) ? ' + '.$langs->trans('PeAi') : '').'</td>';
		print '<td>'.($obj->ref ? '<a href="'.DOL_URL_ROOT.'/product/card.php?id='.((int) $obj->fk_product).'">'.img_picto('', 'product', 'class="pictofixedwidth"').dol_escape_htmltag($obj->ref).'</a> <span class="opacitymedium">'.dol_escape_htmltag(dol_trunc($obj->label, 40)).'</span>' : '#'.((int) $obj->fk_product)).'</td>';
		print '<td>'.dol_escape_htmltag((string) $obj->ean).'</td>';
		print '<td>'.dolGetStatus($langs->trans('PeStatus_'.$obj->status), '', '', $badge[$obj->status] ?? 'status0', 5).'</td>';
		print '<td>'.dol_escape_htmltag(implode(', ', $txt)).'</td>';
		print '<td class="small">'.dol_escape_htmltag(dol_trunc((string) $obj->message, 120)).'</td>';
		print '<td>'.dol_escape_htmltag((string) $obj->login).'</td>';
		print '</tr>';
	}
}
if (!$nb) {
	print '<tr class="oddeven"><td colspan="8"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
}
print '</table></div>';

// Processing from the page, one product per call (the scheduler may not run on this instance)
if ($pending) {
	print '<script>
	jQuery(function () {
		var stop = false, n = 0;
		function step() {
			jQuery.post("'.dol_escape_js(dol_buildpath('/productenrichment/ajax/process.php', 1)).'", {token: "'.dol_escape_js(currentToken()).'"}, function (r) {
				if (!r || !r.ok) { jQuery("#pe_progress").text("'.dol_escape_js($langs->transnoentitiesnoconv('PeJs_Error')).'"); return; }
				if (r.status !== "empty" && r.status !== "quota") { n++; }
				jQuery("#pe_progress").text("'.dol_escape_js($langs->transnoentitiesnoconv('PeProgress')).'".replace("{n}", n).replace("{r}", r.remaining));
				if (r.status === "quota") { jQuery("#pe_progress").append(" — '.dol_escape_js($langs->transnoentitiesnoconv('PeQuotaReached')).'"); }
				if (r.status === "empty" || r.status === "quota" || stop) { setTimeout(function () { window.location.reload(); }, 1500); return; }
				step();
			}, "json").fail(function (x) { jQuery("#pe_progress").text("HTTP " + x.status); });
		}
		jQuery("#pe_process").on("click", function (e) {
			e.preventDefault();
			jQuery(this).hide(); jQuery("#pe_stop").show(); jQuery("#pe_progress").show().text("…");
			step();
		});
		jQuery("#pe_stop").on("click", function (e) { e.preventDefault(); stop = true; jQuery(this).hide(); });
	});
	</script>';
}

llxFooter();
$db->close();
