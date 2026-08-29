<?php
/* Copyright (C) 2026 Jose MARTINEZ <jose.martinez@pichinov.com> — GPL v3+ */
$res = 0;
if (!$res && file_exists('../../main.inc.php')) $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');
require_once dol_buildpath('/scancapture/lib/scancapture.lib.php');
$langs->load('scancapture@scancapture');
if (!$user->admin && !$user->hasRight('stock', 'creer')) accessforbidden();

llxHeader('', $langs->trans('ScanCaptureMenu'));
print load_fiche_titre($langs->trans('ScanCaptureTitle'), '', 'barcode');

// open inventories (started = status 1)
$invs = array();
$resql = $db->query("SELECT i.rowid, i.ref, e.ref AS wh FROM ".MAIN_DB_PREFIX."inventory i LEFT JOIN ".MAIN_DB_PREFIX."entrepot e ON e.rowid = i.fk_warehouse WHERE i.status = 1 ORDER BY i.rowid DESC");
if ($resql) { while ($o = $db->fetch_object($resql)) { $invs[] = $o; } }

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent"><tr class="liste_titre"><td colspan="6">'.$langs->trans('ScanGrid').'</td></tr>';
print '<tr><td class="titlefieldcreate">'.$langs->trans('TargetInventory').'</td><td colspan="5"><select id="sc_inv" class="flat">';
print '<option value="0">'.$langs->trans('CaptureOnly').'</option>';
foreach ($invs as $i) { print '<option value="'.$i->rowid.'">'.dol_escape_htmltag($i->ref.' ('.$i->wh.')').'</option>'; }
print '</select> <span class="opacitymedium">'.$langs->trans('TargetInventoryHelp').'</span></td></tr>';
print '<tr><td>'.$langs->trans('DefaultQty').'</td><td colspan="5"><input type="number" id="sc_defqty" value="1" class="width50 right" step="any"></td></tr>';
print '<tr class="liste_titre"><td>'.$langs->trans('KeziaCode').'</td><td>'.$langs->trans('ProductEan').'</td><td>'.$langs->trans('Qty').'</td><td colspan="3">'.$langs->trans('LiveResult').'</td></tr>';
print '<tr><td><input type="text" id="sc_codek" class="minwidth150" autocomplete="off" autofocus></td>';
print '<td><input type="text" id="sc_ean" class="minwidth150" autocomplete="off"></td>';
print '<td><input type="number" id="sc_qty" class="width75 right" step="any"></td>';
print '<td colspan="3"><span id="sc_live" class="opacitymedium">'.$langs->trans('ScanHint').'</span></td></tr>';
print '</table></div>';

print '<br><div class="div-table-responsive-no-min"><table class="noborder centpercent" id="sc_rows">';
print '<tr class="liste_titre"><td>#</td><td>'.$langs->trans('KeziaCode').'</td><td>'.$langs->trans('ProductEan').'</td><td class="right">'.$langs->trans('Qty').'</td><td>'.$langs->trans('Product').'</td><td>'.$langs->trans('Status').'</td></tr>';
// today's rows
$resql = $db->query("SELECT sc.rowid, sc.code_kezia, sc.ean, sc.qty, sc.product_label, sc.status, sc.candidates FROM ".MAIN_DB_PREFIX."scan_capture sc WHERE sc.datec >= CURDATE() ORDER BY sc.rowid DESC LIMIT 100");
$nb = 0;
if ($resql) {
	while ($o = $db->fetch_object($resql)) {
		$nb++;
		print '<tr class="oddeven" data-row="'.$o->rowid.'"><td>'.$o->rowid.'</td><td>'.dol_escape_htmltag((string) $o->code_kezia).'</td><td>'.dol_escape_htmltag((string) $o->ean).'</td><td class="right">'.price2num($o->qty).'</td><td>'.dol_escape_htmltag((string) $o->product_label).'</td><td><span class="badge badge-status'.($o->status == 'matched' ? '4' : ($o->status == 'created' ? '4' : ($o->status == 'ambiguous' ? '1' : '8'))).' badge-status">'.dol_escape_htmltag($o->status).'</span></td></tr>';
	}
}
print '</table></div>';

?>
<script>
jQuery(function() {
	var base = '<?php print dol_buildpath('/scancapture/ajax/', 1); ?>';
	var token = '<?php print newToken(); ?>';
	function liveLookup() {
		var codes = [jQuery('#sc_codek').val(), jQuery('#sc_ean').val()].filter(function(c) { return c.trim() !== ''; });
		if (!codes.length) { jQuery('#sc_live').text(''); return; }
		var done = {}; var parts = [];
		codes.forEach(function(c) {
			jQuery.getJSON(base + 'lookup.php', {code: c, token: token}, function(r) {
				r.candidates.forEach(function(p) { if (!done[p.rowid]) { done[p.rowid] = 1; parts.push(p.ref + ' — ' + p.label + ' (stock ' + p.stock + ')'); } });
				jQuery('#sc_live').html(parts.length ? '<span style="color:green">&#10004; ' + parts.join(' | ') + (parts.length > 1 ? ' <b>[CHOIX REQUIS à la validation]</b>' : '') + '</span>' : '<span style="color:#b60">? inconnu — sera capturé</span>');
			});
		});
	}
	function submitRow(forced) {
		var q = jQuery('#sc_qty').val() || jQuery('#sc_defqty').val() || 1;
		var params = {code_kezia: jQuery('#sc_codek').val(), ean: jQuery('#sc_ean').val(), qty: q, fk_inventory: jQuery('#sc_inv').val(), token: token};
		if (forced) params.fk_product = forced;
		jQuery.getJSON(base + 'saverow.php', params, function(r) {
			if (!r.ok) { jQuery('#sc_live').html('<span style="color:red">Erreur</span>'); return; }
			if (r.status == 'ambiguous' && !forced) {
				var h = '<b>Plusieurs produits — cliquez le bon :</b> ';
				r.candidates.forEach(function(p) { h += '<button class="button smallpaddingimp sc_pick" data-id="' + p.rowid + '" data-row="' + r.rowid + '">' + p.ref + ' (' + p.label + ')</button> '; });
				jQuery('#sc_live').html(h);
				return;
			}
			var badge = r.status == 'matched' ? '<span style="color:green">&#10004; ' + r.label + (r.assoc && r.assoc != 'already' && r.assoc != 'none' ? ' [EAN associé → ' + r.assoc + ']' : '') + (r.fed ? ' [inventaire alimenté]' : '') + '</span>' : '<span style="color:#b60">Capturé (inconnu)</span>';
			jQuery('#sc_live').html(badge);
			jQuery('#sc_rows tr.liste_titre').after('<tr class="oddeven"><td>' + r.rowid + '</td><td>' + jQuery('#sc_codek').val() + '</td><td>' + jQuery('#sc_ean').val() + '</td><td class="right">' + q + '</td><td>' + (r.label || '') + '</td><td>' + r.status + '</td></tr>');
			if (r.status == 'unknown' && jQuery('#sc_ean').val().trim() !== '') { jQuery.getJSON(base + 'enrich.php', {rowid: r.rowid, token: token}); }
			jQuery('#sc_codek').val(''); jQuery('#sc_ean').val(''); jQuery('#sc_qty').val('');
			jQuery('#sc_codek').focus();
		});
	}
	jQuery(document).on('click', '.sc_pick', function() {
		// re-submit with the chosen product; remove the ambiguous stub row server-side is kept as history
		submitRow(jQuery(this).data('id'));
	});
	jQuery('#sc_codek').on('keydown', function(e) { if (e.key == 'Enter') { e.preventDefault(); liveLookup(); jQuery('#sc_ean').focus(); } });
	jQuery('#sc_ean').on('keydown', function(e) { if (e.key == 'Enter') { e.preventDefault(); liveLookup(); jQuery('#sc_qty').focus(); jQuery('#sc_qty').select(); } });
	jQuery('#sc_qty').on('keydown', function(e) { if (e.key == 'Enter') { e.preventDefault(); submitRow(0); } });
});
</script>
<?php
llxFooter();
$db->close();
