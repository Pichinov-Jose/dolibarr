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

$invs = array();
$resql = $db->query("SELECT i.rowid, i.ref, e.ref AS wh FROM ".MAIN_DB_PREFIX."inventory i LEFT JOIN ".MAIN_DB_PREFIX."entrepot e ON e.rowid = i.fk_warehouse WHERE i.status = 1 ORDER BY i.rowid DESC");
if ($resql) { while ($o = $db->fetch_object($resql)) { $invs[] = $o; } }
?>
<style>
#sc_zone { max-width: 1100px; }
#sc_fields { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
#sc_fields .sc_field { flex: 1 1 260px; margin-bottom: 8px; }
#sc_fields .sc_field.sc_qtyf { flex: 0 1 130px; }
#sc_zone .sc_field { margin-bottom: 8px; }
#sc_zone label { display: block; font-weight: bold; margin-bottom: 2px; }
#sc_zone input[type=text], #sc_zone input[type=number], #sc_zone select { width: 100%; box-sizing: border-box; font-size: 1.25em; padding: 10px; }
#sc_live { display: block; min-height: 2.2em; padding: 10px; margin: 8px 0; border-radius: 6px; background: #f0f0f0; font-size: 1.1em; }
#sc_live.ok { background: #c8e6c9; }
#sc_live.unknown { background: #ffe0b2; }
#sc_live.multi { background: #ffcdd2; }
.sc_btn { font-size: 1.2em !important; padding: 14px 10px !important; width: 49%; box-sizing: border-box; }
#sc_opts { margin: 6px 0; font-size: 0.95em; }
#sc_rows td { padding: 6px 8px; }
@media (min-width: 769px) {
	#sc_actions { position: static; border-top: none; }
	#sc_actions .sc_btnrow { max-width: 420px; }
}
@media (max-width: 768px) {
	#sc_fields { display: block; }
	#sc_rows .sc_hidemobile { display: none; }
	#sc_zone input[type=text], #sc_zone input[type=number] { font-size: 1.5em; }
	.sc_btn { font-size: 1.35em !important; }
}
.sc_edit, .sc_del { font-size: 1.5em; text-decoration: none; padding: 6px; }
.sc_pick { font-size: 1.15em !important; padding: 10px !important; margin: 4px 4px 0 0; display: inline-block; }
div.phpdebugbar, div.phpdebugbar-openhandler { display: none !important; }
#sc_actions { position: sticky; bottom: 0; z-index: 100; background: #fff; padding: 8px 0; border-top: 2px solid #ccc; max-width: 1100px; }
#sc_actions .sc_btnrow { display: flex; gap: 8px; }
#sc_actions .sc_btn { flex: 1; width: auto; }
#sc_numpad { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; }
#sc_numpad .pad { position: absolute; left: 50%; top: 50%; transform: translate(-50%,-50%); background: #fff; border-radius: 10px; padding: 14px; width: 290px; box-shadow: 0 4px 20px rgba(0,0,0,0.4); }
#sc_numpad .val { font-size: 2em; text-align: right; border: 1px solid #ccc; border-radius: 6px; padding: 8px; margin-bottom: 10px; min-height: 1.2em; }
#sc_numpad .keys { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
#sc_numpad .keys button { font-size: 1.6em; padding: 16px 0; border: 1px solid #bbb; border-radius: 8px; background: #f5f5f5; }
#sc_numpad .ok { grid-column: span 2; background: #2e7d32 !important; color: #fff; }
</style>
<div id="sc_zone">
	<div class="sc_field"><label><?php print $langs->trans('TargetInventory'); ?></label>
	<?php $preinv = GETPOSTINT('fk_inventory'); ?><select id="sc_inv"><option value="0"><?php print $langs->trans('CaptureOnly'); ?></option>
	<?php foreach ($invs as $i) { print '<option value="'.$i->rowid.'"'.($preinv == $i->rowid ? ' selected' : '').'>'.dol_escape_htmltag($i->ref.' ('.$i->wh.')').'</option>'; } ?>
	</select></div>
	<div id="sc_opts">
		<div style="margin-bottom:6px"><label style="display:inline"><input type="checkbox" id="sc_auto" checked> <?php print $langs->trans('AutoSubmitAfterEan'); ?></label></div>
		<div><b><?php print $langs->trans('DefaultQty'); ?></b> <input type="number" id="sc_defqty" value="1" style="width:80px;font-size:1.2em;padding:6px" step="any">
		&nbsp; <a href="#" id="sc_kbd" class="button smallpaddingimp">&#128290; <?php print $langs->trans('ManualKeyboard'); ?></a></div>
	</div>
	<div id="sc_fields">
	<div class="sc_field"><label><?php print $langs->trans('KeziaCode'); ?></label>
	<input type="text" id="sc_codek" autocomplete="off" inputmode="none" autofocus placeholder="<?php print $langs->trans('ScanHere'); ?>"></div>
	<div class="sc_field"><label><?php print $langs->trans('ProductEan'); ?></label>
	<input type="text" id="sc_ean" autocomplete="off" inputmode="none" placeholder="<?php print $langs->trans('ScanOrSkip'); ?>"></div>
	<div class="sc_field sc_qtyf"><label><?php print $langs->trans('Qty'); ?></label>
	<input type="number" id="sc_qty" step="any" inputmode="decimal" value="1"></div>
	</div>
</div>
<div id="sc_numpad"><div class="pad">
	<div class="val" id="sc_np_val"></div>
	<div class="keys">
		<button type="button" data-k="7">7</button><button type="button" data-k="8">8</button><button type="button" data-k="9">9</button>
		<button type="button" data-k="4">4</button><button type="button" data-k="5">5</button><button type="button" data-k="6">6</button>
		<button type="button" data-k="1">1</button><button type="button" data-k="2">2</button><button type="button" data-k="3">3</button>
		<button type="button" data-k=".">.</button><button type="button" data-k="0">0</button><button type="button" data-k="C">C</button>
		<button type="button" class="ok" data-k="OK">OK</button><button type="button" data-k="X">&#10006;</button>
	</div>
</div></div>
<div id="sc_actions">
	<span id="sc_live"><?php print $langs->trans('ScanHint'); ?></span>
	<div class="sc_btnrow">
	<button type="button" id="sc_submit" class="button sc_btn">&#10004; <?php print $langs->trans('ValidateLine'); ?></button>
	<button type="button" id="sc_clear" class="button button-cancel sc_btn"><?php print $langs->trans('ClearLine'); ?></button>
	</div>
</div>
<br>
<div id="sc_filter" style="max-width:1100px;margin-bottom:6px">
	<input type="text" id="sc_search" placeholder="<?php print $langs->trans('FilterRows'); ?>" style="width:55%;font-size:1.1em;padding:8px;box-sizing:border-box">
	<button type="button" class="button smallpaddingimp sc_fstat" data-st="">Tous</button>
	<button type="button" class="button smallpaddingimp sc_fstat" data-st="matched">&#10004;</button>
	<button type="button" class="button smallpaddingimp sc_fstat" data-st="unknown">?</button>
</div>
<div class="div-table-responsive-no-min"><table class="noborder centpercent" id="sc_rows">
<tr class="liste_titre"><td></td><td class="sc_hidemobile">#</td><td><?php print $langs->trans('KeziaCode'); ?></td><td><?php print $langs->trans('ProductEan'); ?></td><td class="right"><?php print $langs->trans('Qty'); ?></td><td><?php print $langs->trans('Product'); ?></td><td><?php print $langs->trans('Status'); ?></td></tr>
<?php
$resql = $db->query("SELECT sc.rowid, sc.code_kezia, sc.ean, sc.qty, sc.product_label, sc.status FROM ".MAIN_DB_PREFIX."scan_capture sc WHERE sc.datec >= CURDATE() ORDER BY sc.rowid DESC LIMIT 200");
if ($resql) {
	while ($o = $db->fetch_object($resql)) {
		print '<tr class="oddeven"><td class="nowrap"><a href="#" class="sc_edit" data-row="'.$o->rowid.'" data-qty="'.price2num($o->qty).'"><span class="fa fa-pencil"></span></a>&nbsp;<a href="#" class="sc_del" data-row="'.$o->rowid.'"><span class="fa fa-trash" style="color:#b71c1c"></span></a></td><td class="sc_hidemobile">'.$o->rowid.'</td><td>'.dol_escape_htmltag((string) $o->code_kezia).'</td><td>'.dol_escape_htmltag((string) $o->ean).'</td><td class="right">'.price2num($o->qty).'</td><td>'.dol_escape_htmltag((string) $o->product_label).'</td><td>'.dol_escape_htmltag($o->status).'</td></tr>';
	}
}
?>
</table></div>
<script>
jQuery(function() {
	var base = '<?php print dol_buildpath('/scancapture/ajax/', 1); ?>';
	var token = '<?php print newToken(); ?>';
	function setLive(cls, html) { jQuery('#sc_live').attr('class', cls).html(html); }
	function liveLookup(cb) {
		var codes = [jQuery('#sc_codek').val(), jQuery('#sc_ean').val()].filter(function(c) { return c.trim() !== ''; });
		if (!codes.length) { setLive('', ''); if (cb) cb(); return; }
		var done = {}; var parts = []; var pend = codes.length;
		codes.forEach(function(c) {
			jQuery.getJSON(base + 'lookup.php', {code: c, token: token}, function(r) {
				r.candidates.forEach(function(p) { if (!done[p.rowid]) { done[p.rowid] = 1; parts.push(p.ref + ' — ' + p.label + ' (stock ' + p.stock + ')'); } });
			}).always(function() {
				pend--;
				if (!pend) {
					if (parts.length) setLive(parts.length > 1 ? 'multi' : 'ok', '&#10004; ' + parts.join('<br>'));
					else setLive('unknown', '? <?php print dol_escape_js($langs->trans('UnknownWillCapture')); ?>');
					if (cb) cb();
				}
			});
		});
	}
	function submitRow(forced, replaceRow) {
		var q = jQuery('#sc_qty').val() || jQuery('#sc_defqty').val() || 1;
		var params = {code_kezia: jQuery('#sc_codek').val(), ean: jQuery('#sc_ean').val(), qty: q, fk_inventory: jQuery('#sc_inv').val(), token: token};
		if (!params.code_kezia.trim() && !params.ean.trim()) return;
		if (forced) params.fk_product = forced;
		if (replaceRow) params.replace_row = replaceRow;
		jQuery.getJSON(base + 'saverow.php', params, function(r) {
			if (!r.ok) { setLive('multi', 'Erreur'); return; }
			if (r.status == 'ambiguous' && !forced) {
				var h = '<b><?php print dol_escape_js($langs->trans('PickProduct')); ?></b><br>';
				r.candidates.forEach(function(p) { h += '<button type="button" class="button sc_pick" data-id="' + p.rowid + '" data-stub="' + r.rowid + '">' + p.ref + '<br><small>' + p.label + '</small></button>'; });
				setLive('multi', h);
				return;
			}
			if (r.status == 'mismatch' && !forced) {
				var h = '<b style="color:#b71c1c">&#9888; <?php print dol_escape_js($langs->trans('LabelMismatch')); ?></b><div style="display:flex;gap:8px;margin-top:6px">';
				h += '<div style="flex:1;border:2px solid #b71c1c;border-radius:6px;padding:6px"><b><?php print dol_escape_js($langs->trans('KeziaCode')); ?></b><br>';
				r.group_kezia.forEach(function(p) { h += '<button type="button" class="button sc_pick" data-id="' + p.rowid + '" data-stub="' + r.rowid + '">' + p.ref + '<br><small>' + p.label + '</small></button> '; });
				h += '</div><div style="flex:1;border:2px solid #b71c1c;border-radius:6px;padding:6px"><b><?php print dol_escape_js($langs->trans('ProductEan')); ?></b><br>';
				r.group_ean.forEach(function(p) { h += '<button type="button" class="button sc_pick" data-id="' + p.rowid + '" data-stub="' + r.rowid + '">' + p.ref + '<br><small>' + p.label + '</small></button> '; });
				h += '</div></div>';
				setLive('multi', h);
				return;
			}
			var extra = (r.assoc && r.assoc != 'already' && r.assoc != 'none' && r.assoc != '' ? ' &middot; EAN&rarr;' + r.assoc : '') + (r.fed ? ' &middot; inventaire +' + q : '') + (r.mismatch ? ' <b>&#9888; <?php print dol_escape_js($langs->trans('LabelMismatch')); ?></b>' : '');
			setLive(r.status == 'matched' ? 'ok' : 'unknown', r.status == 'matched' ? '&#10004; ' + r.label + extra : '<?php print dol_escape_js($langs->trans('CapturedUnknown')); ?>');
			jQuery('#sc_rows tr.liste_titre').after('<tr class="oddeven"><td class="nowrap"><a href="#" class="sc_edit" data-row="' + r.rowid + '" data-qty="' + q + '"><span class="fa fa-pencil"></span></a>&nbsp;<a href="#" class="sc_del" data-row="' + r.rowid + '"><span class="fa fa-trash" style="color:#b71c1c"></span></a></td><td class="sc_hidemobile">' + r.rowid + '</td><td>' + params.code_kezia + '</td><td>' + params.ean + '</td><td class="right">' + q + '</td><td>' + (r.label || '') + '</td><td>' + r.status + '</td></tr>');
			if (r.status == 'unknown' && params.ean.trim() !== '') { jQuery.getJSON(base + 'enrich.php', {rowid: r.rowid, token: token}); }
			applyFilter();
			jQuery('#sc_codek').val(''); jQuery('#sc_ean').val(''); jQuery('#sc_qty').val(jQuery('#sc_defqty').val() || 1);
			jQuery('#sc_codek').focus();
		});
	}
	jQuery(document).on('click', '.sc_pick', function() { submitRow(jQuery(this).data('id'), jQuery(this).data('stub') || 0); });
	var npTarget = null; var npCb = null;
	function openPad(initial, cb) { npCb = cb; jQuery('#sc_np_val').text(initial || ''); jQuery('#sc_numpad').show(); }
	jQuery('#sc_numpad .keys button').on('click', function() {
		var k = jQuery(this).data('k') + ''; var v = jQuery('#sc_np_val').text();
		if (k == 'C') { jQuery('#sc_np_val').text(''); return; }
		if (k == 'X') { jQuery('#sc_numpad').hide(); return; }
		if (k == 'OK') { jQuery('#sc_numpad').hide(); if (npCb && v !== '') npCb(v); return; }
		if (k == '.' && v.indexOf('.') !== -1) return;
		jQuery('#sc_np_val').text(v + k);
	});
	jQuery('#sc_qty').on('click', function() { var me = jQuery(this); openPad('', function(v) { me.val(v); }); });
	var scFilterText = ''; var scFilterStat = '';
	function applyFilter() {
		jQuery('#sc_rows tr').not('.liste_titre').each(function() {
			var tr = jQuery(this);
			var okT = !scFilterText || tr.text().toLowerCase().indexOf(scFilterText) !== -1;
			var okS = !scFilterStat || tr.find('td').eq(6).text().trim() === scFilterStat;
			tr.toggle(okT && okS);
		});
	}
	jQuery('#sc_search').on('input', function() { scFilterText = jQuery(this).val().toLowerCase(); applyFilter(); });
	jQuery(document).on('click', '.sc_fstat', function() { scFilterStat = jQuery(this).data('st'); jQuery('.sc_fstat').removeClass('butActionRefused'); jQuery(this).addClass('butActionRefused'); applyFilter(); });
	jQuery(document).on('click', '.sc_del', function(ev) {
		ev.preventDefault();
		var row = jQuery(this).data('row'); var tr = jQuery(this).closest('tr');
		if (!confirm('<?php print dol_escape_js($langs->trans('ConfirmDeleteLine')); ?>')) return;
		jQuery.getJSON(base + 'updaterow.php', {what: 'del', rowid: row, token: token}, function(r) { if (r.ok) tr.remove(); });
	});
	jQuery(document).on('click', '.sc_edit', function(ev) {
		ev.preventDefault();
		var a = jQuery(this); var row = a.data('row'); var tr = a.closest('tr');
		openPad(a.data('qty') + '', function(nq) {
			jQuery.getJSON(base + 'updaterow.php', {what: 'qty', rowid: row, qty: nq, token: token}, function(r) {
				if (r.ok) { tr.find('td').eq(4).text(r.qty); a.data('qty', r.qty); }
			});
		});
	});
	jQuery('#sc_submit').on('click', function() { submitRow(0); });
	jQuery('#sc_clear').on('click', function() { jQuery('#sc_codek,#sc_ean').val(''); jQuery('#sc_qty').val(jQuery('#sc_defqty').val() || 1); setLive('', ''); jQuery('#sc_codek').focus(); });
	jQuery('#sc_defqty').on('change', function() { jQuery('#sc_qty').val(jQuery(this).val() || 1); });
	jQuery('#sc_kbd').on('click', function(ev) {
		ev.preventDefault();
		var cur = jQuery('#sc_codek').attr('inputmode') == 'none' ? 'text' : 'none';
		jQuery('#sc_codek,#sc_ean').attr('inputmode', cur);
		jQuery(this).toggleClass('butActionRefused');
		jQuery('#sc_codek').blur().focus();
	});
	jQuery('#sc_codek').on('keydown', function(e) { if (e.key == 'Enter') { e.preventDefault(); liveLookup(); jQuery('#sc_ean').focus(); } });
	jQuery('#sc_ean').on('keydown', function(e) {
		if (e.key == 'Enter') {
			e.preventDefault();
			if (jQuery('#sc_auto').is(':checked')) { liveLookup(function() { submitRow(0); }); } else { liveLookup(); jQuery('#sc_qty').focus().select(); }
		}
	});
	jQuery('#sc_qty').on('keydown', function(e) { if (e.key == 'Enter') { e.preventDefault(); submitRow(0); } });
});
</script>
<?php
llxFooter();
$db->close();
