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

$invs = array();
$resql = $db->query("SELECT i.rowid, i.ref, e.ref AS wh FROM ".MAIN_DB_PREFIX."inventory i LEFT JOIN ".MAIN_DB_PREFIX."entrepot e ON e.rowid = i.fk_warehouse WHERE i.status = 1 ORDER BY i.rowid DESC");
if ($resql) { while ($o = $db->fetch_object($resql)) { $invs[] = $o; } }
$preinv = GETPOSTINT('fk_inventory');

$nbToday = $nbUnknown = 0; $nbPending = 0;
$resql = $db->query("SELECT COUNT(*) AS n FROM ".MAIN_DB_PREFIX."scan_capture WHERE sent_to_inv IS NULL AND fk_product > 0 AND status IN ('matched', 'created')");
if ($resql && ($o = $db->fetch_object($resql))) { $nbPending = (int) $o->n; }
$resql = $db->query("SELECT COUNT(*) AS n, SUM(status = 'unknown') AS u FROM ".MAIN_DB_PREFIX."scan_capture WHERE datec >= CURDATE()");
if ($resql && ($o = $db->fetch_object($resql))) { $nbToday = (int) $o->n; $nbUnknown = (int) $o->u; }
?>
<script>document.documentElement.classList.add('scfs');</script>
<style>
div.phpdebugbar, div.phpdebugbar-openhandler { display: none !important; }
#sc_app { max-width: 1100px; }
#sc_head { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
#sc_head h2 { margin: 0; font-size: 1.25em; flex: 1; }
.sc_chip { background: #eef2f7; border-radius: 14px; padding: 5px 12px; font-size: 0.95em; white-space: nowrap; }
.sc_chip.inv { background: #e3f2fd; font-weight: bold; }
.sc_chip.warn { background: #ffe0b2; }
#sc_gear { font-size: 1.7em; color: #555; text-decoration: none; padding: 6px 10px; }
#sc_fields { display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; }
#sc_fields .sc_field { flex: 1 1 260px; margin-bottom: 8px; }
#sc_fields .sc_field.sc_qtyf { flex: 0 1 130px; }
.sc_field label { display: block; font-weight: bold; margin-bottom: 3px; color: #444; }
.sc_field input { width: 100% !important; box-sizing: border-box !important; font-size: 1.3em !important; padding: 10px 12px !important; height: 52px !important; border: 1.5px solid #bbb !important; border-radius: 8px !important; background: #fff; }
.sc_field input:focus { border-color: #1976d2; outline: none; box-shadow: 0 0 0 2px rgba(25,118,210,0.2); }
#sc_live { display: block; min-height: 2.2em; padding: 12px; margin: 8px 0; border-radius: 8px; background: #f4f4f4; font-size: 1.15em; }
#sc_live.ok { background: #c8e6c9; }
#sc_live.unknown { background: #ffe0b2; }
#sc_live.multi { background: #ffebee; }
#sc_livewrap { position: sticky; bottom: 64px; z-index: 99; max-width: 1100px; }
#sc_bar { position: fixed; left: 0; right: 0; bottom: 0; z-index: 110; background: #263238; display: flex; justify-content: space-around; padding: 4px 0 max(4px, env(safe-area-inset-bottom)); box-shadow: 0 -2px 8px rgba(0,0,0,0.25); }
#sc_bar a { flex: 1; text-align: center; color: #b0bec5; text-decoration: none; font-size: 0.72em; padding: 4px 0; position: relative; }
#sc_bar a .fa { display: block; font-size: 1.9em; margin-bottom: 2px; }
#sc_bar a.primary { color: #fff; }
#sc_bar a.primary .fa { color: #4fc3f7; }
#sc_bar a:active { color: #fff; }
#sc_bar .badge { position: absolute; top: -2px; right: 18%; background: #e53935; color: #fff; border-radius: 10px; padding: 1px 6px; font-size: 0.85em; font-weight: bold; }
#sc_bar .badge.zero { display: none; }
body { padding-bottom: 76px !important; }
.sc_btn { font-size: 1.25em !important; padding: 15px 10px !important; border-radius: 8px !important; }
#sc_filter { max-width: 1100px; margin: 10px 0 6px; display: flex; gap: 8px; }
#sc_search { flex: 1; font-size: 1.05em; padding: 9px; border: 1.5px solid #ccc; border-radius: 8px; box-sizing: border-box; }
.sc_edit, .sc_del { font-size: 1.25em; text-decoration: none; padding: 6px; }
.sc_actdis { font-size: 1.25em; padding: 6px; color: #bbb; }
.sc_pick { font-size: 1.1em !important; padding: 10px !important; margin: 4px 4px 0 0; display: inline-block; }
.sc_modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; }
.sc_modal .box { position: absolute; left: 50%; top: 50%; transform: translate(-50%,-50%); background: #fff; border-radius: 12px; padding: 18px; width: min(92vw, 420px); box-shadow: 0 6px 24px rgba(0,0,0,0.4); }
.sc_modal h3 { margin: 0 0 12px; }
.sc_modal .row { margin-bottom: 12px; }
.sc_modal label { font-weight: bold; display: block; margin-bottom: 4px; }
.sc_modal select, .sc_modal input[type=number] { width: 100%; font-size: 1.15em; padding: 9px; box-sizing: border-box; }
.sc_modal .close { float: right; font-size: 1.3em; text-decoration: none; color: #666; }
#sc_numpad .val { font-size: 2em; text-align: right; border: 1px solid #ccc; border-radius: 6px; padding: 8px; margin-bottom: 10px; min-height: 1.2em; }
#sc_numpad .keys { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
#sc_numpad .keys button { font-size: 1.6em; padding: 16px 0; border: 1px solid #bbb; border-radius: 8px; background: #f5f5f5; }
#sc_numpad .ok { grid-column: span 2; background: #2e7d32 !important; color: #fff; }
html.scfs #id-top, html.scfs #id-left, html.scfs .side-nav, html.scfs #tmenu_tooltip, html.scfs .tmenudiv { display: none !important; }
html.scfs #id-right, html.scfs .fiche { padding: 10px !important; margin: 0 !important; width: auto !important; }
html.scfs #id-container { width: 100% !important; }
#sc_deskactions { display: none; }
@media (min-width: 769px) {
	#sc_bar { display: none; }
	#sc_deskactions { display: block; }
	#sc_livewrap { position: static; }
	body { padding-bottom: 10px !important; }
}
@media (max-width: 768px) {
	#sc_fields { display: block; }
	#sc_rows .sc_hidemobile { display: none; }
	.sc_field { margin-bottom: 12px; }
	.sc_field label { font-size: 1.1em; }
	.sc_field input { font-size: 1.8em !important; padding: 14px !important; height: 64px !important; }
	#sc_head h2 { font-size: 1.05em; }
	#sc_live { font-size: 1.25em; }
}
</style>
<div id="sc_app">
<div id="sc_head">
	<h2><span class="fa fa-barcode paddingright"></span><?php print $langs->trans('ScanCaptureTitle2'); ?></h2>
	<span class="sc_chip inv" id="sc_invchip"></span>
	<span class="sc_chip" id="sc_count"><?php print $nbToday; ?> scans</span>
	<span class="sc_chip warn" id="sc_unknowncount" <?php print $nbUnknown ? '' : 'style="display:none"'; ?>><a href="<?php print dol_buildpath('/scancapture/review.php', 1); ?>" style="text-decoration:none"><?php print $nbUnknown; ?> <?php print $langs->trans('UnknownShort'); ?></a></span>
	<a href="<?php print DOL_URL_ROOT; ?>/index.php?mainmenu=home" id="sc_home" title="Dolibarr" style="font-size:1.7em;color:#555;text-decoration:none;padding:6px 4px"><span class="fa fa-home"></span></a>
	<a href="#" id="sc_fs" title="<?php print $langs->trans('FullScreen'); ?>" style="font-size:1.7em;color:#555;text-decoration:none;padding:6px 4px"><span class="fa fa-expand"></span></a>
	<a href="#" id="sc_gear" title="<?php print $langs->trans('Settings'); ?>"><span class="fa fa-cog"></span></a>
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

<div id="sc_settings" class="sc_modal"><div class="box">
	<a href="#" class="close" id="sc_set_close"><span class="fa fa-times"></span></a>
	<h3><span class="fa fa-cog paddingright"></span><?php print $langs->trans('Settings'); ?></h3>
	<div class="row"><label><?php print $langs->trans('TargetInventory'); ?></label>
	<select id="sc_inv"><option value="0"><?php print $langs->trans('CaptureOnly'); ?></option>
	<?php foreach ($invs as $i) { print '<option value="'.$i->rowid.'"'.($preinv == $i->rowid ? ' selected' : '').'>'.dol_escape_htmltag($i->ref.' ('.$i->wh.')').'</option>'; } ?>
	</select></div>
	<div class="row"><button type="button" class="button smallpaddingimp" id="sc_newinv" style="width:100%"><span class="fa fa-plus paddingright"></span><?php print $langs->trans('CreateInventory'); ?></button></div>
	<div class="row"><label style="display:inline"><input type="checkbox" id="sc_autocreate"> <?php print $langs->trans('AutoCreateUnknown'); ?></label></div>
	<div class="row"><label><?php print $langs->trans('DefaultQty'); ?></label>
	<input type="number" id="sc_defqty" value="1" step="any"></div>
	<div class="row"><label style="display:inline"><input type="checkbox" id="sc_auto" checked> <?php print $langs->trans('AutoSubmitAfterEan'); ?></label></div>
	<div class="row"><label style="display:inline"><input type="checkbox" id="sc_kbdchk"> <?php print $langs->trans('ManualKeyboardHelp'); ?></label></div>
	<div class="center"><button type="button" class="button sc_btn" id="sc_set_ok" style="width:100%"><?php print $langs->trans('ScCloseSettings'); ?></button></div>
</div></div>

<div id="sc_numpad" class="sc_modal"><div class="box" style="width:290px">
	<div class="val" id="sc_np_val"></div>
	<div class="keys">
		<button type="button" data-k="7">7</button><button type="button" data-k="8">8</button><button type="button" data-k="9">9</button>
		<button type="button" data-k="4">4</button><button type="button" data-k="5">5</button><button type="button" data-k="6">6</button>
		<button type="button" data-k="1">1</button><button type="button" data-k="2">2</button><button type="button" data-k="3">3</button>
		<button type="button" data-k=".">.</button><button type="button" data-k="0">0</button><button type="button" data-k="C">C</button>
		<button type="button" class="ok" data-k="OK">OK</button><button type="button" data-k="X"><span class="fa fa-times"></span></button>
	</div>
</div></div>

<div id="sc_livewrap"><span id="sc_live"><?php print $langs->trans('ScanHint'); ?></span></div>

<div id="sc_deskactions" class="tabsAction">
	<a href="#" class="butAction sc_a_submit"><?php print $langs->trans('ValidateLine'); ?></a>
	<a href="#" class="butAction sc_a_clear"><?php print $langs->trans('ClearLine'); ?></a>
	<a href="#" class="butAction sc_a_send"><?php print $langs->trans('SendToInventory'); ?> (<span class="sc_pending_mirror"><?php print $nbPending; ?></span>)</a>
</div>

<div id="sc_bar">
	<a href="#" id="sc_submit" class="primary"><span class="fa fa-check"></span><?php print $langs->trans('ValidateLine'); ?></a>
	<a href="#" id="sc_clear"><span class="fa fa-eraser"></span><?php print $langs->trans('ClearLine'); ?></a>
	<a href="#" id="sc_send" class="primary"><span class="fa fa-upload"></span><?php print $langs->trans('SendShort'); ?><span class="badge<?php print $nbPending ? '' : ' zero'; ?>" id="sc_pending"><?php print $nbPending; ?></span></a>
	<a href="<?php print dol_buildpath('/scancapture/review.php', 1); ?>"><span class="fa fa-list-alt"></span><?php print $langs->trans('ReviewShort'); ?><span class="badge<?php print $nbUnknown ? '' : ' zero'; ?>" id="sc_unkbadge"><?php print $nbUnknown; ?></span></a>
	<a href="#" id="sc_gear2"><span class="fa fa-cog"></span><?php print $langs->trans('Settings'); ?></a>
</div>

<div id="sc_filter">
	<input type="text" id="sc_search" placeholder="<?php print $langs->trans('FilterRows'); ?>">
	<button type="button" class="button smallpaddingimp sc_fstat" data-st="">Tous</button>
	<button type="button" class="button smallpaddingimp sc_fstat" data-st="matched"><span class="fa fa-check"></span></button>
	<button type="button" class="button smallpaddingimp sc_fstat" data-st="unknown">?</button>
</div>
<div class="div-table-responsive-no-min"><table class="noborder centpercent" id="sc_rows">
<tr class="liste_titre"><td class="sc_hidemobile">#</td><td><?php print $langs->trans('KeziaCode'); ?></td><td><?php print $langs->trans('ProductEan'); ?></td><td class="right"><?php print $langs->trans('Qty'); ?></td><td><?php print $langs->trans('Product'); ?></td><td><?php print $langs->trans('Status'); ?></td><td class="right"></td></tr>
<?php
$resql = $db->query("SELECT sc.rowid, sc.code_kezia, sc.ean, sc.qty, sc.product_label, sc.status, sc.sent_to_inv, sc.fk_product FROM ".MAIN_DB_PREFIX."scan_capture sc WHERE sc.datec >= CURDATE() ORDER BY sc.rowid DESC LIMIT 200");
if ($resql) {
	while ($o = $db->fetch_object($resql)) {
		print '<tr class="oddeven" data-id="'.$o->rowid.'"><td class="sc_hidemobile">'.$o->rowid.'</td><td>'.dol_escape_htmltag((string) $o->code_kezia).'</td><td>'.dol_escape_htmltag((string) $o->ean).'</td><td class="right">'.price2num($o->qty).'</td><td>'.dol_escape_htmltag((string) $o->product_label).'</td><td>'.dol_escape_htmltag($o->status).($o->sent_to_inv ? ' <span class="fa fa-check-circle" style="color:#2e7d32" title="envoy&eacute;"></span>' : ($o->fk_product ? ' <span class="fa fa-clock-o" style="color:#b26a00" title="en attente"></span>' : '')).'</td><td class="right nowrap">'.($o->sent_to_inv ? '<span class="fa fa-edit sc_actdis"></span>&nbsp;<span class="fa fa-trash sc_actdis"></span>' : '<a href="#" class="sc_edit" data-row="'.$o->rowid.'" data-qty="'.price2num($o->qty).'"><span class="fa fa-edit"></span></a>&nbsp;<a href="#" class="sc_del" data-row="'.$o->rowid.'"><span class="fa fa-trash" style="color:#b71c1c"></span></a>').'</td></tr>';
	}
}
?>
</table></div>
<script>
jQuery(function() {
	var base = '<?php print dol_buildpath('/scancapture/ajax/', 1); ?>';
	var token = '<?php print newToken(); ?>';
	function setLive(cls, html) { jQuery('#sc_live').attr('class', cls).html(html); }
	function refreshChips() {
		var t = jQuery('#sc_inv option:selected').text();
		jQuery('#sc_invchip').text(jQuery('#sc_inv').val() > 0 ? t : '<?php print dol_escape_js($langs->trans('CaptureOnlyShort')); ?>');
	}
	function bumpCount(unknown) {
		var c = jQuery('#sc_count'); c.text((parseInt(c.text()) + 1) + ' scans');
		if (unknown) { var u = jQuery('#sc_unknowncount'); var a = u.find('a'); var n = (parseInt(a.text()) || 0) + 1; a.text(n + ' <?php print dol_escape_js($langs->trans('UnknownShort')); ?>'); u.show(); }
	}
	// browser fullscreen only (the Dolibarr chrome is always hidden: terminal mode)
	jQuery('#sc_fs').on('click', function(ev) {
		ev.preventDefault();
		try {
			if (!document.fullscreenElement) { document.documentElement.requestFullscreen(); jQuery('#sc_fs span').attr('class', 'fa fa-compress'); }
			else { document.exitFullscreen(); jQuery('#sc_fs span').attr('class', 'fa fa-expand'); }
		} catch (err) {}
		jQuery('#sc_codek').focus();
	});
	document.addEventListener('fullscreenchange', function() { jQuery('#sc_fs span').attr('class', document.fullscreenElement ? 'fa fa-compress' : 'fa fa-expand'); });
	// settings modal
	jQuery('#sc_gear').on('click', function(e) { e.preventDefault(); jQuery('#sc_settings').show(); });
	jQuery('#sc_gear2').on('click', function(e) { e.preventDefault(); jQuery('#sc_settings').show(); });
	jQuery('#sc_set_close, #sc_set_ok').on('click', function(e) { e.preventDefault(); jQuery('#sc_settings').hide(); refreshChips(); jQuery('#sc_codek').focus(); });
	jQuery('#sc_inv').on('change', refreshChips);
	jQuery('#sc_newinv').on('click', function(e) {
		e.preventDefault();
		if (!confirm('<?php print dol_escape_js($langs->trans('ConfirmCreateInv')); ?>')) return;
		jQuery.getJSON(base + 'createinv.php', {token: token}, function(r) {
			if (!r.ok) { alert('Erreur : ' + (r.error || '')); return; }
			jQuery('#sc_inv').append(new Option(r.label, r.id, true, true)).trigger('change');
		});
	});
	jQuery('#sc_defqty').on('change', function() { jQuery('#sc_qty').val(jQuery(this).val() || 1); });
	jQuery('#sc_kbdchk').on('change', function() { jQuery('#sc_codek,#sc_ean').attr('inputmode', this.checked ? 'text' : 'none'); });
	refreshChips();
	// numpad
	var npCb = null;
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
	// lookup + submit
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
					if (parts.length) setLive(parts.length > 1 ? 'multi' : 'ok', '<span class="fa fa-check"></span> ' + parts.join('<br>'));
					else setLive('unknown', '? <?php print dol_escape_js($langs->trans('UnknownWillCapture')); ?>');
					if (cb) cb();
				}
			});
		});
	}
	function pickBtns(list, stub) {
		var h = '';
		list.forEach(function(p) { h += '<button type="button" class="button sc_pick" data-id="' + p.rowid + '" data-stub="' + stub + '">' + p.ref + '<br><small>' + p.label + '</small></button> '; });
		return h;
	}
	function submitRow(forced, replaceRow) {
		var q = jQuery('#sc_qty').val() || jQuery('#sc_defqty').val() || 1;
		var params = {code_kezia: jQuery('#sc_codek').val(), ean: jQuery('#sc_ean').val(), qty: q, fk_inventory: jQuery('#sc_inv').val(), token: token};
		if (!params.code_kezia.trim() && !params.ean.trim()) return;
		if (forced) params.fk_product = forced;
		if (replaceRow) params.replace_row = replaceRow;
		jQuery.getJSON(base + 'saverow.php', params).fail(function() {
			setLive('multi', '<?php print dol_escape_js($langs->trans('AjaxFailed')); ?>');
		}).done(function(r) {
			if (!r.ok) { setLive('multi', 'Erreur'); return; }
			if (r.status == 'ambiguous' && !forced) {
				setLive('multi', '<b><?php print dol_escape_js($langs->trans('PickProduct')); ?></b><br>' + pickBtns(r.candidates, r.rowid));
				return;
			}
			if (r.status == 'mismatch' && !forced) {
				var h = '<b style="color:#b71c1c"><span class="fa fa-warning"></span> <?php print dol_escape_js($langs->trans('LabelMismatch')); ?></b><div style="display:flex;gap:8px;margin-top:6px;flex-wrap:wrap">';
				h += '<div style="flex:1;min-width:170px;border:2px solid #b71c1c;border-radius:8px;padding:8px"><b><?php print dol_escape_js($langs->trans('KeziaCode')); ?></b><br>' + pickBtns(r.group_kezia, r.rowid) + '</div>';
				h += '<div style="flex:1;min-width:170px;border:2px solid #b71c1c;border-radius:8px;padding:8px"><b><?php print dol_escape_js($langs->trans('ProductEan')); ?></b><br>' + pickBtns(r.group_ean, r.rowid) + '</div></div>';
				setLive('multi', h);
				return;
			}
			var extra = (r.assoc && r.assoc != 'already' && r.assoc != 'none' && r.assoc != '' ? ' &middot; EAN&rarr;' + r.assoc : '') + (r.kassoc ? ' &middot; <?php print dol_escape_js($langs->trans('KeziaCodeLearned')); ?>' : '');
			setLive(r.status == 'matched' ? 'ok' : 'unknown', r.status == 'matched' ? '<span class="fa fa-check"></span> ' + r.label + extra : (r.variant_of ? '<span class="fa fa-code-fork"></span> <?php print dol_escape_js($langs->trans('VariantCandidate')); ?> ' + r.variant_of : '<?php print dol_escape_js($langs->trans('CapturedUnknown')); ?>'));
			jQuery('#sc_rows tr.liste_titre').after('<tr class="oddeven" data-id="' + r.rowid + '"><td class="sc_hidemobile">' + r.rowid + '</td><td>' + params.code_kezia + '</td><td>' + params.ean + '</td><td class="right">' + q + '</td><td>' + (r.label || '') + '</td><td>' + r.status + (r.status == 'matched' ? ' <span class=\'fa fa-clock-o\' style=\'color:#b26a00\'></span>' : '') + '</td><td class="right nowrap"><a href="#" class="sc_edit" data-row="' + r.rowid + '" data-qty="' + q + '"><span class="fa fa-edit"></span></a>&nbsp;<a href="#" class="sc_del" data-row="' + r.rowid + '"><span class="fa fa-trash" style="color:#b71c1c"></span></a></td></tr>');
			bumpCount(r.status == 'unknown');
			if (scFilterStat && scFilterStat !== r.status) { scFilterStat = ''; jQuery('.sc_fstat').removeClass('butActionRefused'); jQuery('.sc_fstat[data-st=""]').addClass('butActionRefused'); }
			if (r.status == 'matched') { var pb = jQuery('#sc_pending'); pb.text((parseInt(pb.text()) || 0) + 1).removeClass('zero'); jQuery('.sc_pending_mirror').text(pb.text()); }
			if (r.status == 'unknown' && params.ean.trim() !== '') { jQuery.getJSON(base + 'enrich.php', {rowid: r.rowid, token: token}); }
			applyFilter();
			jQuery('#sc_codek').val(''); jQuery('#sc_ean').val(''); jQuery('#sc_qty').val(jQuery('#sc_defqty').val() || 1);
			jQuery('#sc_codek').focus();
		});
	}
	jQuery(document).on('click', '.sc_pick', function() { submitRow(jQuery(this).data('id'), jQuery(this).data('stub') || 0); });
	jQuery('#sc_submit, .sc_a_submit').on('click', function(e) { e.preventDefault(); submitRow(0); });
	jQuery('#sc_send, .sc_a_send').on('click', function(e) { e.preventDefault();
		var inv = jQuery('#sc_inv').val();
		if (!(inv > 0)) { jQuery('#sc_settings').show(); setLive('multi', '<?php print dol_escape_js($langs->trans('PickInventoryFirst')); ?>'); return; }
		var n = jQuery('#sc_pending').text();
		if (!confirm('<?php print dol_escape_js($langs->trans('ConfirmSendToInv')); ?>'.replace('%s', n).replace('%i', jQuery('#sc_inv option:selected').text()))) return;
		jQuery.getJSON(base + 'sendtoinv.php', {fk_inventory: inv, autocreate: (jQuery('#sc_autocreate').is(':checked') ? 1 : 0), token: token}).fail(function() {
			setLive('multi', '<?php print dol_escape_js($langs->trans('AjaxFailed')); ?>');
		}).done(function(r) {
			if (!r.ok) { setLive('multi', 'Erreur : ' + (r.error || '')); return; }
			r.ids.forEach(function(id) {
				var tr = jQuery('#sc_rows tr[data-id="' + id + '"]');
				tr.find('.fa-clock-o').attr('class', 'fa fa-check-circle').css('color', '#2e7d32');
				tr.find('td').last().html('<span class="fa fa-edit sc_actdis"></span>&nbsp;<span class="fa fa-trash sc_actdis"></span>');
			});
			jQuery('#sc_pending').text('0').addClass('zero'); jQuery('.sc_pending_mirror').text('0');
			setLive('ok', '<span class="fa fa-check"></span> ' + r.sent + ' <?php print dol_escape_js($langs->trans('LinesSent')); ?>' + (r.created ? ' &middot; ' + r.created + ' <?php print dol_escape_js($langs->trans('ProductsAutoCreated')); ?>' : ''));
		});
	});
	jQuery('#sc_clear, .sc_a_clear').on('click', function(e) { e.preventDefault(); jQuery('#sc_codek,#sc_ean').val(''); jQuery('#sc_qty').val(jQuery('#sc_defqty').val() || 1); setLive('', ''); jQuery('#sc_codek').focus(); });
	jQuery('#sc_codek').on('keydown', function(e) { if (e.key == 'Enter') { e.preventDefault(); liveLookup(); jQuery('#sc_ean').focus(); } });
	jQuery('#sc_ean').on('keydown', function(e) {
		if (e.key == 'Enter') {
			e.preventDefault();
			if (jQuery('#sc_auto').is(':checked')) { liveLookup(function() { submitRow(0); }); } else { liveLookup(); jQuery('#sc_qty').focus().select(); }
		}
	});
	jQuery('#sc_qty').on('keydown', function(e) { if (e.key == 'Enter') { e.preventDefault(); submitRow(0); } });
	// row edit/delete
	jQuery(document).on('click', '.sc_del', function(ev) {
		ev.preventDefault();
		var row = jQuery(this).data('row'); var tr = jQuery(this).closest('tr');
		if (!confirm('<?php print dol_escape_js($langs->trans('ConfirmDeleteLine')); ?>')) return;
		jQuery.getJSON(base + 'updaterow.php', {what: 'del', rowid: row, token: token}, function(r) { if (r.ok) { tr.remove(); } else if (r.error == 'sent') { setLive('multi', '<?php print dol_escape_js($langs->trans('CantDeleteSent')); ?>'); } });
	});
	jQuery(document).on('click', '.sc_edit', function(ev) {
		ev.preventDefault();
		var a = jQuery(this); var row = a.data('row'); var tr = a.closest('tr');
		openPad(a.data('qty') + '', function(nq) {
			jQuery.getJSON(base + 'updaterow.php', {what: 'qty', rowid: row, qty: nq, token: token}, function(r) {
				if (r.ok) { tr.find('td').eq(3).text(r.qty); a.data('qty', r.qty); }
			});
		});
	});
	// filter
	var scFilterText = ''; var scFilterStat = '';
	function applyFilter() {
		jQuery('#sc_rows tr').not('.liste_titre').each(function() {
			var tr = jQuery(this);
			var okT = !scFilterText || tr.text().toLowerCase().indexOf(scFilterText) !== -1;
			var okS = !scFilterStat || tr.find('td').eq(5).text().trim() === scFilterStat;
			tr.toggle(okT && okS);
		});
	}
	jQuery('#sc_search').on('input', function() { scFilterText = jQuery(this).val().toLowerCase(); applyFilter(); });
	jQuery(document).on('click', '.sc_fstat', function() { scFilterStat = jQuery(this).data('st'); jQuery('.sc_fstat').removeClass('butActionRefused'); jQuery(this).addClass('butActionRefused'); applyFilter(); });
});
</script>
<?php
llxFooter();
$db->close();
