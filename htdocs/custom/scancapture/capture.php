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
$resql = $db->query("SELECT COUNT(*) AS n, SUM(status = 'unknown') AS u FROM ".MAIN_DB_PREFIX."scan_capture WHERE datec >= CURDATE() OR sent_to_inv IS NULL");
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
.sc_modal button.sc_btn { background: var(--butactionbg, #79609b) !important; color: var(--butactiontextcolor, #fff) !important; border: none !important; cursor: pointer; font-weight: bold; text-transform: uppercase; }
.sc_modal button.sc_btn:hover { opacity: 0.9; }
#sc_filter { max-width: 1100px; margin: 10px 0 6px; display: flex; gap: 8px; }
#sc_search { flex: 1; font-size: 1.05em; padding: 9px; border: 1.5px solid #ccc; border-radius: 8px; box-sizing: border-box; }
.sc_edit, .sc_del { font-size: 1.25em; text-decoration: none; padding: 6px; }
.sc_actdis { font-size: 1.25em; padding: 6px; color: #bbb; }
.sc_pick, .sc_dupbtn { font-size: 1.1em !important; padding: 10px !important; margin: 4px 4px 0 0; display: inline-block; }
.sc_modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; }
.sc_modal .box { position: absolute; left: 50%; top: 50%; transform: translate(-50%,-50%); background: #fff; border-radius: 12px; padding: 18px; width: min(92vw, 420px); box-shadow: 0 6px 24px rgba(0,0,0,0.4); max-height: 92vh; max-height: 92dvh; overflow-y: auto; overscroll-behavior: contain; -webkit-overflow-scrolling: touch; box-sizing: border-box; }
.sc_modal h3 { margin: 0 0 12px; }
.sc_modal .row { margin-bottom: 12px; }
.sc_modal label { font-weight: bold; display: block; margin-bottom: 4px; }
.sc_modal select, .sc_modal input[type=number] { width: 100%; font-size: 1.15em; padding: 9px; box-sizing: border-box; }
.sc_modal .close { float: right; font-size: 1.3em; text-decoration: none; color: #666; }
.sc_cr_block { background: #f5f5f5; border-radius: 8px; padding: 8px 10px; font-size: 0.95em; line-height: 1.5; }
.sc_cr_block .tit { font-weight: bold; display: block; margin-bottom: 2px; }
.sc_cr_block img { max-height: 46px; max-width: 46px; border-radius: 4px; vertical-align: middle; margin: 2px 4px 2px 0; }
.sc_cand { display: block; width: 100%; text-align: left; margin: 3px 0; padding: 7px 10px; border: 1px solid #b9a5e3; background: #f4f0fc; border-radius: 8px; cursor: pointer; font-size: 0.95em; box-sizing: border-box; }
.sc_cand:hover { background: #e6dcf7; }
.sc_cand .src { float: right; color: #7a6aa5; font-size: 0.85em; margin-left: 8px; }
.sc_src { font-weight: normal; font-size: 0.85em; }
.sc_spec { display: flex; align-items: center; gap: 6px; padding: 4px 6px; border-bottom: 1px solid #eee; font-size: 0.92em; }
.sc_spec .kv { flex: 1; }
.sc_spec input[type=checkbox] { width: 18px; height: 18px; }
.sc_decl { display: inline-block; margin: 3px 4px 0 0; padding: 6px 10px; border: 1px solid #90caf9; background: #e3f2fd; border-radius: 14px; cursor: pointer; font-size: 0.9em; }
.sc_decl.sel { background: #1976d2; border-color: #1976d2; color: #fff; font-weight: bold; }
.sc_decl:hover { border-color: #1976d2; }
#sc_numpad .val { font-size: 2em; text-align: right; border: 1px solid #ccc; border-radius: 6px; padding: 8px; margin-bottom: 10px; min-height: 1.2em; }
#sc_numpad .keys { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
#sc_numpad .keys button { font-size: 1.6em; padding: 16px 0; border: 1px solid #bbb; border-radius: 8px; background: #f5f5f5; }
#sc_numpad .ok { grid-column: span 2; background: #2e7d32 !important; color: #fff; }
html.scfs #id-top, html.scfs #id-left, html.scfs .side-nav, html.scfs #tmenu_tooltip, html.scfs .tmenudiv { display: none !important; }
html.scfs #id-right { padding: 10px !important; margin: 0 !important; width: 100% !important; display: block; box-sizing: border-box; }
html.scfs .fiche { padding: 0 !important; margin: 0 !important; width: auto !important; }
html.scfs #id-container { width: 100% !important; }
#sc_deskactions { display: none; }
@media (min-width: 769px) {
	#sc_bar { display: none; }
	#sc_livewrap { position: static; }
	body { padding-bottom: 10px !important; }
	/* one clean centered column, fluid with the viewport (92vw, capped at 1500px) */
	#sc_app, #sc_livewrap, #sc_filter, .div-table-responsive-no-min { max-width: min(92vw, 1500px); margin-left: auto; margin-right: auto; }
	#sc_deskactions { display: flex; gap: 10px; max-width: min(92vw, 1500px); margin: 2px auto 14px !important; padding: 0 !important; text-align: left; }
	#sc_deskactions .butAction { margin: 0 !important; }
	#sc_deskactions .sc_a_send { margin-left: auto !important; }
	#sc_live { margin: 10px 0 0; }
	#sc_rows td { padding-top: 7px; padding-bottom: 7px; }
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

<div id="sc_create" class="sc_modal"><div class="box">
	<a href="#" class="close" id="sc_cr_close"><span class="fa fa-times"></span></a>
	<h3><span class="fa fa-plus-circle paddingright" style="color:#2e7d32" id="sc_cr_picto"></span><span id="sc_cr_title"><?php print $langs->trans('CreateProduct'); ?></span></h3>
	<div class="row"><span class="opacitymedium" id="sc_cr_ean"></span></div>
	<div class="row sc_cr_block" id="sc_cr_fam" style="display:none"></div>
	<div class="row sc_cr_block" id="sc_cr_enrich" style="display:none"></div>
	<div class="row" id="sc_cr_decl_wrap" style="display:none"><label>Déclinaison (site fabricant)</label><div id="sc_cr_decls"></div></div>
	<div class="row" id="sc_cr_parent_wrap">
		<label>Rattacher la variante à</label>
		<label style="font-weight:normal;display:block" id="sc_cr_pfam_opt"><input type="radio" name="sc_cr_parent" value="family" checked> Famille <b id="sc_cr_pfam"></b></label>
		<label style="font-weight:normal;display:block"><input type="radio" name="sc_cr_parent" value="new"> Nouveau parent à créer</label>
		<input type="text" id="sc_cr_parent_label" placeholder="libellé du nouveau parent" style="display:none;width:100%;font-size:1.05em;padding:8px;box-sizing:border-box;margin-top:4px">
		<label style="font-weight:normal;display:block"><input type="radio" name="sc_cr_parent" value="none"> Aucun (produit isolé)</label>
	</div>
	<div class="row" id="sc_cr_cands_wrap" style="display:none"><label>Libellés possibles (EAN)</label><div id="sc_cr_cands"></div></div>
	<div class="row" id="sc_cr_specs_wrap" style="display:none"><label>Caractéristiques <span class="opacitymedium sc_src" id="sc_cr_specs_src"></span> <span class="opacitymedium" style="font-weight:normal;float:right">garder · décliner</span></label><div id="sc_cr_specs"></div></div>
	<div class="row"><label><?php print $langs->trans('Label'); ?> <span class="opacitymedium sc_src" id="sc_cr_label_src"></span></label><input type="text" id="sc_cr_label" style="width:100%;font-size:1.15em;padding:9px;box-sizing:border-box"></div>
	<div class="row"><label><?php print $langs->trans('PriceTTC'); ?> <span class="opacitymedium sc_src" id="sc_cr_price_src"></span></label><input type="number" id="sc_cr_price" step="any" inputmode="decimal" placeholder="<?php print $langs->trans('PriceFromFamily'); ?>" style="width:100%;font-size:1.15em;padding:9px;box-sizing:border-box"></div>
	<div class="row"><label>Prix d'achat HT <span class="opacitymedium sc_src" id="sc_cr_buy_src"></span></label><input type="number" id="sc_cr_buyprice" step="any" inputmode="decimal" placeholder="vide = prix d'achat de la famille" style="width:100%;font-size:1.15em;padding:9px;box-sizing:border-box"></div>
	<div class="row"><label>Réf fabricant <span class="opacitymedium sc_src" id="sc_cr_mpn_src"></span></label><input type="text" id="sc_cr_mpn" placeholder="réf produit fournisseur (MPN)" style="width:100%;font-size:1.15em;padding:9px;box-sizing:border-box"></div>
	<div style="display:flex;gap:10px"><button type="button" class="button sc_btn" id="sc_cr_ok" style="flex:2"><span id="sc_cr_oktxt"><?php print $langs->trans('Create'); ?></span></button><button type="button" class="button sc_btn" id="sc_cr_cancel" style="flex:1;background:#90a4ae !important"><?php print $langs->trans('Cancel'); ?></button></div>
</div></div>

<div id="sc_numpad" class="sc_modal"><div class="box" style="width:min(290px,86vw)">
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
<tr class="liste_titre"><td class="sc_hidemobile">#</td><td><?php print $langs->trans('KeziaCode'); ?></td><td><?php print $langs->trans('ProductEan'); ?></td><td class="right"><?php print $langs->trans('Qty'); ?></td><td><?php print $langs->trans('Product'); ?></td><td><?php print $langs->trans('Status'); ?></td><td class="right sc_hidemobile" title="Stock théorique au moment du scan">Stock</td><td class="right" title="Compté − théorique">Écart</td><td class="right"></td></tr>
<?php
function scEcartCell($qty, $sb)
{
	if ($sb === null) { return '<span class="opacitymedium">—</span>'; }
	$e = (float) $qty - (float) $sb;
	$c = ($e == 0 ? '#2e7d32' : ($e > 0 ? '#b26a00' : '#b71c1c'));
	return '<span style="color:'.$c.';font-weight:bold" class="sc_ecart">'.($e > 0 ? '+' : '').price2num($e).'</span>';
}
$resql = $db->query("SELECT sc.rowid, sc.code_kezia, sc.ean, sc.qty, sc.product_label, sc.status, sc.sent_to_inv, sc.fk_product, sc.stock_before FROM ".MAIN_DB_PREFIX."scan_capture sc WHERE sc.datec >= CURDATE() OR sc.sent_to_inv IS NULL ORDER BY sc.rowid DESC LIMIT 200");
if ($resql) {
	while ($o = $db->fetch_object($resql)) {
		print '<tr class="oddeven" data-id="'.$o->rowid.'" data-sb="'.($o->stock_before !== null ? price2num($o->stock_before) : '').'"><td class="sc_hidemobile">'.$o->rowid.'</td><td>'.dol_escape_htmltag((string) $o->code_kezia).'</td><td>'.dol_escape_htmltag((string) $o->ean).'</td><td class="right">'.price2num($o->qty).'</td><td>'.dol_escape_htmltag((string) $o->product_label).'</td><td>'.dol_escape_htmltag($o->status).($o->sent_to_inv ? ' <span class="fa fa-check-circle" style="color:#2e7d32" title="envoy&eacute;"></span>' : ($o->fk_product ? ' <span class="fa fa-clock-o" style="color:#b26a00" title="en attente"></span>' : '')).'</td><td class="right sc_hidemobile">'.($o->stock_before !== null ? price2num($o->stock_before) : '<span class="opacitymedium">—</span>').'</td><td class="right">'.scEcartCell($o->qty, $o->stock_before).'</td><td class="right nowrap">'.($o->sent_to_inv ? '<span class="fa fa-edit sc_actdis"></span>&nbsp;<span class="fa fa-trash sc_actdis"></span>' : ($o->status == 'unknown' ? '<a href="#" class="sc_create" data-row="'.$o->rowid.'" data-ean="'.dol_escape_htmltag((string) $o->ean).'" data-label="'.dol_escape_htmltag((string) $o->product_label).'"><span class="fa fa-plus-circle" style="color:#2e7d32"></span></a>&nbsp;' : '').(in_array($o->status, array('matched', 'created')) && $o->fk_product ? '<a href="#" class="sc_enrich" title="Enrichir le produit" data-row="'.$o->rowid.'" data-ean="'.dol_escape_htmltag((string) $o->ean).'" data-label="'.dol_escape_htmltag((string) $o->product_label).'"><span class="fa fa-magic" style="color:#7b1fa2"></span></a>&nbsp;' : '').(in_array($o->status, array('mismatch', 'ambiguous')) ? '<a href="#" class="sc_resolve" title="Résoudre : choisir le bon produit" data-row="'.$o->rowid.'" data-ck="'.dol_escape_htmltag((string) $o->code_kezia).'" data-ean="'.dol_escape_htmltag((string) $o->ean).'" data-qty="'.price2num($o->qty).'"><span class="fa fa-question-circle" style="color:#b71c1c"></span></a>&nbsp;' : '').'<a href="#" class="sc_edit" data-row="'.$o->rowid.'" data-qty="'.price2num($o->qty).'"><span class="fa fa-edit"></span></a>&nbsp;<a href="#" class="sc_del" data-row="'.$o->rowid.'"><span class="fa fa-trash" style="color:#b71c1c"></span></a>').'</td></tr>';
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
	function openPad(initial, cb) {
		// close any OS keyboard first: our pad and Gboard must never stack on the DT50
		if (document.activeElement && document.activeElement.blur) { document.activeElement.blur(); }
		npCb = cb; jQuery('#sc_np_val').text(initial || ''); jQuery('#sc_numpad').show();
	}
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
	function scEcartHtml(q, sb) {
		if (sb === null || sb === undefined || sb === '') { return '<span class="opacitymedium">—</span>'; }
		var e = parseFloat(q) - parseFloat(sb);
		var c = (e == 0 ? '#2e7d32' : (e > 0 ? '#b26a00' : '#b71c1c'));
		return '<span style="color:' + c + ';font-weight:bold" class="sc_ecart">' + (e > 0 ? '+' : '') + (Math.round(e * 100) / 100) + '</span>';
	}
	function pickBtns(list, stub) {
		var h = '';
		list.forEach(function(p) { h += '<button type="button" class="button sc_pick" data-id="' + p.rowid + '" data-stub="' + stub + '">' + p.ref + (p.origin ? ' <span style="background:#eef2f7;color:#455a64;border-radius:9px;padding:1px 7px;font-size:0.78em;font-weight:normal">' + p.origin + '</span>' : '') + '<br><small>' + p.label + (p.stock !== undefined ? ' · stock ' + p.stock : '') + '</small></button> '; });
		return h;
	}
	var scDupPending = null;
	jQuery(document).on('click', '.sc_dup_merge', function(ev) {
		ev.preventDefault();
		if (!scDupPending) { return; }
		var p = jQuery.extend({}, scDupPending.params, {merge_row: scDupPending.dup});
		jQuery.getJSON(base + 'saverow.php', p).done(function(r) {
			if (!r.ok) { setLive('multi', 'Erreur fusion'); return; }
			var tr = jQuery('#sc_rows tr[data-id="' + r.merged + '"]');
			tr.find('td').eq(3).text(r.qty);
			tr.find('td').eq(7).html(scEcartHtml(r.qty, r.stock_before !== undefined && r.stock_before !== null ? r.stock_before : tr.attr('data-sb')));
			setLive('ok', '<span class="fa fa-compress"></span> ' + (r.label || '') + ' &mdash; quantit&eacute; cumul&eacute;e : <b>' + r.qty + '</b> (ligne ' + r.merged + ')');
			scDupPending = null;
			jQuery('#sc_codek').focus();
		});
	});
	jQuery(document).on('click', '.sc_dup_new', function(ev) {
		ev.preventDefault();
		if (!scDupPending) { return; }
		var p = scDupPending; scDupPending = null;
		submitRowParams(jQuery.extend({}, p.params, {force: 1}));
	});
	function submitRow(forced, replaceRow) {
		var q = jQuery('#sc_qty').val() || jQuery('#sc_defqty').val() || 1;
		var params = {code_kezia: jQuery('#sc_codek').val(), ean: jQuery('#sc_ean').val(), qty: q, fk_inventory: jQuery('#sc_inv').val(), token: token};
		if (!params.code_kezia.trim() && !params.ean.trim()) return;
		if (forced) params.fk_product = forced;
		if (replaceRow) params.replace_row = replaceRow;
		submitRowParams(params, forced);
	}
	function submitRowParams(params, forced) {
		var q = params.qty;
		jQuery.getJSON(base + 'saverow.php', params).fail(function() {
			setLive('multi', '<?php print dol_escape_js($langs->trans('AjaxFailed')); ?>');
		}).done(function(r) {
			if (!r.ok) { setLive('multi', 'Erreur'); return; }
			if (r.status == 'dup') {
				scDupPending = {dup: r.dup, params: params};
				setLive('multi', '<b><span class="fa fa-copy"></span> D&eacute;j&agrave; scann&eacute; aujourd\'hui</b> : ' + (r.label || '') + ' (ligne ' + r.dup + ', qt&eacute; ' + r.dup_qty + ')' +
					'<div style="display:flex;gap:8px;margin-top:6px"><a href="#" class="button sc_dupbtn sc_dup_merge"><span class="fa fa-compress paddingright"></span>Fusionner (+' + q + ')</a>' +
					'<a href="#" class="button sc_dupbtn sc_dup_new"><span class="fa fa-plus paddingright"></span>Nouvelle ligne</a></div>');
				clearFields();
				return;
			}
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
			jQuery('#sc_rows tr.liste_titre').after('<tr class="oddeven" data-id="' + r.rowid + '" data-sb="' + (r.stock_before === null || r.stock_before === undefined ? '' : r.stock_before) + '"><td class="sc_hidemobile">' + r.rowid + '</td><td>' + params.code_kezia + '</td><td>' + params.ean + '</td><td class="right">' + q + '</td><td>' + (r.label || '') + '</td><td>' + r.status + (r.status == 'matched' ? ' <span class=\'fa fa-clock-o\' style=\'color:#b26a00\'></span>' : '') + '</td><td class="right sc_hidemobile">' + (r.stock_before === null || r.stock_before === undefined ? '—' : r.stock_before) + '</td><td class="right">' + scEcartHtml(q, r.stock_before) + '</td><td class="right nowrap">' + (r.status == 'unknown' ? '<a href="#" class="sc_create" data-row="' + r.rowid + '" data-ean="' + params.ean + '" data-label="' + (r.label || '') + '"><span class="fa fa-plus-circle" style="color:#2e7d32"></span></a>&nbsp;' : '') + (r.status == 'matched' && r.fk_product ? '<a href="#" class="sc_enrich" title="Enrichir le produit" data-row="' + r.rowid + '" data-ean="' + params.ean + '" data-label="' + (r.label || '') + '"><span class="fa fa-magic" style="color:#7b1fa2"></span></a>&nbsp;' : '') + '<a href="#" class="sc_edit" data-row="' + r.rowid + '" data-qty="' + q + '"><span class="fa fa-edit"></span></a>&nbsp;<a href="#" class="sc_del" data-row="' + r.rowid + '"><span class="fa fa-trash" style="color:#b71c1c"></span></a></td></tr>');
			bumpCount(r.status == 'unknown');
			if (scFilterStat && scFilterStat !== r.status) { scFilterStat = ''; jQuery('.sc_fstat').removeClass('butActionRefused'); jQuery('.sc_fstat[data-st=""]').addClass('butActionRefused'); }
			if (r.status == 'matched') { var pb = jQuery('#sc_pending'); pb.text((parseInt(pb.text()) || 0) + 1).removeClass('zero'); jQuery('.sc_pending_mirror').text(pb.text()); }
			if (r.status == 'unknown' && params.ean.trim() !== '') {
				jQuery.getJSON(base + 'enrich.php', {rowid: r.rowid, token: token}, function(re) {
					if (re && re.ok && re.info && re.info.title) return;
					jQuery.getJSON(base + 'enrich_ebay.php', {rowid: r.rowid, token: token}, function(re2) {
						if (re2 && re2.ok && re2.info && re2.info.title) return;
						jQuery.getJSON(base + 'enrich_ai.php', {rowid: r.rowid, token: token});
					});
				});
			}
			applyFilter();
			clearFields();
		});
	}
	function clearFields() {
		jQuery('#sc_codek').val(''); jQuery('#sc_ean').val(''); jQuery('#sc_qty').val(jQuery('#sc_defqty').val() || 1);
		jQuery('#sc_codek').focus();
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
	var crRow = 0;
	function scEsc(s) { return jQuery('<span>').text(s == null ? '' : String(s)).html(); }
	function scRenderFam(f) {
		if (!f) { jQuery('#sc_cr_fam').hide(); return; }
		var h = '<span class="tit"><span class="fa fa-sitemap paddingright"></span>Famille : <a href="' + f.url + '" target="_blank">' + scEsc(f.ref) + '</a> — ' + scEsc(f.label) + '</span>';
		h += 'Vente hérité : <b>' + scEsc(f.price_ttc) + ' TTC</b> · TVA ' + scEsc(f.tva_tx) + ' %';
		if (f.buy_price) { h += '<br>Achat hérité : <b>' + scEsc(f.buy_price) + ' HT</b>' + (f.supplier ? ' chez ' + scEsc(f.supplier) : '') + (f.ref_fourn ? ' (réf. ' + scEsc(f.ref_fourn) + ')' : ''); }
		var extra = [];
		if (f.pmp) { extra.push('PMP ' + scEsc(f.pmp)); }
		if (f.cost_price) { extra.push('coût ' + scEsc(f.cost_price)); }
		if (f.warehouse) { extra.push('entrepôt ' + scEsc(f.warehouse)); }
		if (f.nbcat) { extra.push(f.nbcat + ' catégorie(s)'); }
		if (extra.length) { h += '<br><span class="opacitymedium">Aussi hérité : ' + extra.join(' · ') + '</span>'; }
		jQuery('#sc_cr_fam').html(h).show();
		if (f.price_ttc) { jQuery('#sc_cr_price').attr('placeholder', 'vide = ' + f.price_ttc + ' TTC (famille ' + f.ref + ')'); scSetSrc('sc_cr_price_src', 'famille ' + f.ref); }
		if (f.buy_price) { jQuery('#sc_cr_buyprice').attr('placeholder', 'vide = ' + f.buy_price + ' HT (famille' + (f.supplier ? ', ' + f.supplier : '') + ')'); scSetSrc('sc_cr_buy_src', 'famille' + (f.supplier ? ', ' + f.supplier : '')); }
	}
	function scFullName(info) {
		var t = (info.title || '').trim(), b = (info.brand || '').trim();
		if (!t) { return b; }
		return (b && t.toLowerCase().indexOf(b.toLowerCase()) < 0) ? b + ' ' + t : t;
	}
	function scRenderInfo(info, source) {
		if (!info) { return; }
		var h = '<span class="tit"><span class="fa fa-search paddingright"></span>Enrichissement (' + scEsc(source) + ')</span>';
		var name = scFullName(info);
		if (name) { h += 'Produit identifié : <b>' + scEsc(name) + '</b>'; } else { h += '<span class="opacitymedium">Rien d\'identifié pour cet EAN</span>'; }
		if (info.mpn) { h += '<br>Réf fabricant : <b>' + scEsc(info.mpn) + '</b>'; }
		if (info.desc_courte) { h += '<br>' + scEsc(info.desc_courte); }
		if (info.desc_longue && info.desc_longue != info.desc_courte) {
			var dl = String(info.desc_longue);
			h += '<br><span class="opacitymedium">' + scEsc(dl.length > 220 ? dl.substring(0, 220) + '…' : dl) + '</span>';
		}
		var px = [];
		if (info.prix_public) { px.push('Prix public : <b>' + scEsc(info.prix_public) + ' € TTC</b>'); }
		if (info.prix_achat) { px.push('Achat estimé : <b>' + scEsc(info.prix_achat) + ' € HT</b>'); }
		if (info.prix_marche) { px.push('Marché : ' + scEsc(info.prix_marche)); }
		if (px.length) { h += '<br>' + px.join(' · '); }
		var badges = [];
		if (info.confiance) { badges.push('confiance ' + scEsc(info.confiance)); }
		if (info.category) { badges.push(scEsc(info.category)); }
		if (info.sources && info.sources.length) { badges.push(info.sources.length + ' source(s)'); }
		if (badges.length) { h += '<br><span class="opacitymedium">' + badges.join(' · ') + '</span>'; }
		var imgs = info.images || (info.image ? [info.image] : []);
		if (imgs.length) {
			h += '<br>';
			for (var i = 0; i < Math.min(3, imgs.length); i++) { h += '<img src="' + scEsc(imgs[i]) + '" alt="">'; }
			h += '<span class="opacitymedium"> ' + imgs.length + ' photo(s) — jointes à la création</span>';
		}
		jQuery('#sc_cr_enrich').html(h).show();
	}
	var scCands = [];
	function scAddCand(label, source) {
		label = (label || '').trim();
		if (!label) { return; }
		for (var i = 0; i < scCands.length; i++) { if (scCands[i].l.toLowerCase() == label.toLowerCase()) { return; } }
		scCands.push({l: label, s: source});
		var b = jQuery('<button type="button" class="sc_cand"></button>').text(label).append(jQuery('<span class="src"></span>').text(source));
		jQuery('#sc_cr_cands').append(b);
		jQuery('#sc_cr_cands_wrap').show();
	}
	jQuery(document).on('click', '.sc_cand', function() {
		jQuery('#sc_cr_label').val(jQuery(this).clone().children().remove().end().text());
		scSetSrc('sc_cr_label_src', jQuery(this).find('.src').text());
		jQuery('#sc_cr_price').focus();
	});
	var crLabelBase = '', crMpnBase = '', crDecl = null;
	function scSetSrc(id, s) { jQuery('#' + id).text(s ? '(' + s + ')' : ''); }
	function scRenderSpecs(info, source) {
		var sp = info.specs || {};
		var keys = Object.keys(sp);
		if (!keys.length) { return; }
		var box = jQuery('#sc_cr_specs').empty();
		for (var i = 0; i < keys.length; i++) {
			var k = keys[i], v = sp[k];
			if (v === null || v === '' || typeof v === 'object') { continue; }
			var axis = /coloris|couleur|taille|size|color/i.test(k);
			var row = jQuery('<div class="sc_spec"></div>')
				.append(jQuery('<span class="kv"></span>').html('<b>' + scEsc(k) + '</b> : ' + scEsc(v)))
				.append(jQuery('<input type="checkbox" class="sc_spec_keep" checked title="Garder sur la fiche produit">'))
				.append(jQuery('<input type="checkbox" class="sc_spec_axis" title="Axe de déclinaison (ajouté au libellé)">').prop('checked', axis))
				.attr('data-k', k).attr('data-v', v);
			box.append(row);
		}
		if (box.children().length) { scSetSrc('sc_cr_specs_src', source); jQuery('#sc_cr_specs_wrap').show(); }
	}
	function scRenderDecls(info) {
		var ds = info.declinaisons || [];
		if (!ds.length) { return; }
		var box = jQuery('#sc_cr_decls').empty();
		for (var i = 0; i < ds.length; i++) {
			var d = ds[i];
			if (!d || (!d.code && !d.libelle)) { continue; }
			var b = jQuery('<span class="sc_decl"></span>').text((d.code ? d.code + ' — ' : '') + (d.libelle || ''))
				.attr('data-code', d.code || '').attr('data-lib', d.libelle || '');
			if (d.ean && info.__ean && d.ean == info.__ean) { b.addClass('sel'); }
			else if (info.declinaison_scannee && d.code == info.declinaison_scannee) { b.addClass('sel'); }
			box.append(b);
		}
		if (box.children().length) {
			jQuery('#sc_cr_decl_wrap').show();
			var sel = box.children('.sel').first();
			if (sel.length) { scPickDecl(sel); }
		}
	}
	function scPickDecl(el) {
		jQuery('#sc_cr_decls .sc_decl').removeClass('sel'); el.addClass('sel');
		crDecl = {code: el.attr('data-code'), lib: el.attr('data-lib')};
		if (crLabelBase === '') { crLabelBase = jQuery('#sc_cr_label').val(); }
		if (crMpnBase === '') { crMpnBase = jQuery('#sc_cr_mpn').val(); }
		var lb = crLabelBase;
		if (crDecl.lib && lb.toLowerCase().indexOf(crDecl.lib.toLowerCase()) < 0) { lb += ' ' + crDecl.lib; }
		if (crDecl.code && lb.toLowerCase().indexOf(crDecl.code.toLowerCase()) < 0) { lb += ' (' + crDecl.code + ')'; }
		jQuery('#sc_cr_label').val(lb.replace(/\s*\(coloris non identifi[^)]*\)\s*/i, ' ').trim());
		if (crDecl.code) {
			var mp = crMpnBase;
			if (mp.toLowerCase().indexOf(crDecl.code.toLowerCase()) < 0) { mp = (mp ? mp + ' ' : '') + crDecl.code; }
			jQuery('#sc_cr_mpn').val(mp);
		}
	}
	jQuery(document).on('click', '.sc_decl', function() { scPickDecl(jQuery(this)); });
	jQuery(document).on('change', 'input[name=sc_cr_parent]', function() {
		jQuery('#sc_cr_parent_label').toggle(jQuery(this).val() == 'new');
	});
	function scApplyInfo(r, source) {
		if (!(r && r.ok && r.info)) { return false; }
		var src = r.cached ? (r.info.ai ? 'IA' : source) + ', cache' : source;
		if (r.info.title) { scAddCand(scFullName(r.info), src); }
		if (r.info.titles && r.info.titles.length) { for (var i = 0; i < r.info.titles.length; i++) { scAddCand(r.info.titles[i], 'eBay'); } }
		if (r.info.title && !jQuery('#sc_cr_label').val()) { jQuery('#sc_cr_label').val(scFullName(r.info)); scSetSrc('sc_cr_label_src', src); }
		if (r.info.mpn && (!jQuery('#sc_cr_mpn').val() || jQuery('#sc_cr_mpn').attr('data-src') == 'family')) { jQuery('#sc_cr_mpn').val(r.info.mpn).removeAttr('data-src'); scSetSrc('sc_cr_mpn_src', src); }
		scRenderInfo(r.info, src);
		scRenderDecls(r.info);
		scRenderSpecs(r.info, src);
		return !!(r.info.title);
	}
	var crMode = 'create';
	function scOpenPopup(a, mode) {
		crRow = a.data('row'); crMode = mode;
		jQuery('#sc_cr_title').html(mode == 'update' ? 'Enrichir le produit' : '<?php print dol_escape_js($langs->trans('CreateProduct')); ?>');
		jQuery('#sc_cr_picto').attr('class', mode == 'update' ? 'fa fa-magic paddingright' : 'fa fa-plus-circle paddingright');
		jQuery('#sc_cr_oktxt').html(mode == 'update' ? 'Mettre à jour' : '<?php print dol_escape_js($langs->trans('Create')); ?>');
		jQuery('#sc_cr_ean').text('EAN : ' + (a.data('ean') || '—'));
		jQuery('#sc_cr_label').val(a.data('label') || '');
		jQuery('#sc_cr_price').val('').attr('placeholder', mode == 'update' ? 'vide = prix inchangé' : '<?php print dol_escape_js($langs->trans('PriceFromFamily')); ?>');
		jQuery('#sc_cr_buyprice').val('').attr('placeholder', mode == 'update' ? "vide = prix d'achat inchangé" : "vide = prix d'achat de la famille");
		jQuery('#sc_cr_mpn').val('').removeAttr('data-src').removeAttr('title');
		jQuery('#sc_cr_fam').hide().empty();
		jQuery('#sc_cr_enrich').hide().empty();
		scCands = []; jQuery('#sc_cr_cands').empty(); jQuery('#sc_cr_cands_wrap').hide();
		crLabelBase = ''; crMpnBase = ''; crDecl = null;
		jQuery('#sc_cr_specs').empty(); jQuery('#sc_cr_specs_wrap').hide();
		jQuery('.sc_src').text('');
		jQuery('#sc_cr_decls').empty(); jQuery('#sc_cr_decl_wrap').hide();
		jQuery('#sc_cr_pfam').text(''); jQuery('#sc_cr_pfam_opt').hide();
		jQuery('input[name=sc_cr_parent][value=none]').prop('checked', true);
		jQuery('#sc_cr_parent_label').val('').hide();
		jQuery('#sc_cr_parent_wrap').toggle(mode != 'update');
		jQuery('#sc_create').show();
		if (a.data('label')) { scAddCand(String(a.data('label')), mode == 'update' ? 'actuel' : 'scan'); scSetSrc('sc_cr_label_src', mode == 'update' ? 'actuel' : 'scan'); }
		jQuery.getJSON(base + 'createinfo.php', {rowid: crRow, token: token}, function(ci) {
			if (!(ci && ci.ok)) { return; }
			jQuery('#sc_cr_ean').text('EAN : ' + (ci.ean || '—') + (ci.code_kezia ? ' · Code Kezia : ' + ci.code_kezia : '') + (ci.datec ? ' · scanné le ' + ci.datec : ''));
			if (crMode == 'update' && ci.product) {
				var p = ci.product;
				var h = '<span class="tit"><span class="fa fa-cube paddingright"></span>Produit : <a href="' + p.url + '" target="_blank">' + scEsc(p.ref) + '</a> — ' + scEsc(p.label) + '</span>';
				h += 'Vente actuelle : <b>' + scEsc(p.price_ttc) + ' TTC</b> · TVA ' + scEsc(p.tva_tx) + ' %';
				if (p.buy_price) { h += '<br>Achat actuel : <b>' + scEsc(p.buy_price) + ' HT</b>' + (p.supplier ? ' chez ' + scEsc(p.supplier) : '') + (p.ref_fourn ? ' (réf. ' + scEsc(p.ref_fourn) + ')' : ''); }
				if (p.pmp) { h += '<br><span class="opacitymedium">PMP ' + scEsc(p.pmp) + (p.has_desc ? ' · description en place (conservée)' : ' · description vide (sera remplie)') + '</span>'; }
				jQuery('#sc_cr_fam').html(h).show();
				if (!jQuery('#sc_cr_label').val()) { jQuery('#sc_cr_label').val(p.label); scSetSrc('sc_cr_label_src', 'actuel'); }
				if (p.price_ttc) { jQuery('#sc_cr_price').attr('placeholder', 'vide = inchangé (' + p.price_ttc + ' TTC actuel)'); scSetSrc('sc_cr_price_src', 'actuel'); }
				if (p.buy_price) { jQuery('#sc_cr_buyprice').attr('placeholder', 'vide = inchangé (' + p.buy_price + ' HT actuel)'); scSetSrc('sc_cr_buy_src', 'actuel' + (p.supplier ? ', ' + p.supplier : '')); }
				if (p.ref_fourn && !jQuery('#sc_cr_mpn').val()) { jQuery('#sc_cr_mpn').val(p.ref_fourn).attr('data-src', 'family'); scSetSrc('sc_cr_mpn_src', 'produit actuel'); }
				return;
			}
			scRenderFam(ci.family);
			if (ci.family && ci.family.label) { scAddCand(ci.family.label, 'famille ' + ci.family.ref); }
			if (ci.family && ci.family.ref) {
				jQuery('#sc_cr_pfam').text(ci.family.ref + ' — ' + (ci.family.label || ''));
				jQuery('#sc_cr_pfam_opt').show();
				jQuery('input[name=sc_cr_parent][value=family]').prop('checked', true);
			}
			if (ci.family && ci.family.ref_fourn && !jQuery('#sc_cr_mpn').val()) {
				jQuery('#sc_cr_mpn').val(ci.family.ref_fourn).attr('data-src', 'family')
					.attr('title', 'Réf de la famille — à ajuster pour cette déclinaison');
				scSetSrc('sc_cr_mpn_src', 'famille ' + ci.family.ref + ', à ajuster');
			}
		});
		jQuery.getJSON(base + 'enrich.php', {rowid: crRow, token: token}, function(r) {
			var upcFound = scApplyInfo(r, 'base UPC');
			jQuery.getJSON(base + 'enrich_ebay.php', {rowid: crRow, token: token}, function(r2) {
				var ebayFound = scApplyInfo(r2, 'eBay');
				if (upcFound || ebayFound) { return; }
				jQuery.getJSON(base + 'enrich_ai.php', {rowid: crRow, token: token}, function(r3) { scApplyInfo(r3, 'IA'); });
			});
		});
		jQuery('#sc_cr_label').focus();
	}
	jQuery(document).on('click', '.sc_create', function(ev) { ev.preventDefault(); scOpenPopup(jQuery(this), 'create'); });
	jQuery(document).on('click', '.sc_enrich', function(ev) { ev.preventDefault(); scOpenPopup(jQuery(this), 'update'); });
	jQuery(document).on('click', '.sc_resolve', function(ev) {
		ev.preventDefault();
		var a = jQuery(this);
		// refill the scan fields so the pick flow can resubmit, then reopen the chooser from the stored candidates
		jQuery('#sc_codek').val(a.data('ck') || '');
		jQuery('#sc_ean').val(a.data('ean') || '');
		jQuery('#sc_qty').val(a.data('qty') || 1);
		jQuery.getJSON(base + 'createinfo.php', {rowid: a.data('row'), token: token}, function(ci) {
			if (ci && ci.ok && ci.candidates && ci.candidates.length) {
				setLive('multi', '<b><?php print dol_escape_js($langs->trans('PickProduct')); ?></b> (ligne ' + a.data('row') + (ci.datec ? ', scanné le ' + ci.datec : '') + ')<br>' + pickBtns(ci.candidates, a.data('row')));
				jQuery('html, body').animate({scrollTop: 0}, 200);
			} else {
				setLive('multi', 'Pas de candidats mémorisés — supprime la ligne et re-scanne.');
			}
		});
	});
	jQuery('#sc_cr_close, #sc_cr_cancel').on('click', function(ev) { ev.preventDefault(); jQuery('#sc_create').hide(); jQuery('#sc_codek').focus(); });
	jQuery('#sc_cr_ok').on('click', function() {
		var ep = (crMode == 'update') ? 'updatefromrow.php' : 'createfromrow.php';
		var specsKeep = [];
		jQuery('#sc_cr_specs .sc_spec').each(function() {
			var k = jQuery(this).attr('data-k'), v = jQuery(this).attr('data-v');
			if (jQuery(this).find('.sc_spec_keep').is(':checked')) { specsKeep.push(k + ' : ' + v); }
			if (jQuery(this).find('.sc_spec_axis').is(':checked')) {
				var lb = jQuery('#sc_cr_label');
				if (lb.val().toLowerCase().indexOf(String(v).toLowerCase()) < 0) { lb.val((lb.val() + ' ' + v).trim()); }
			}
		});
		jQuery.getJSON(base + ep, {rowid: crRow, label: jQuery('#sc_cr_label').val(), price: jQuery('#sc_cr_price').val(), buyprice: jQuery('#sc_cr_buyprice').val(), mpn: jQuery('#sc_cr_mpn').val(), parent_mode: jQuery('input[name=sc_cr_parent]:checked').val() || 'none', parent_label: jQuery('#sc_cr_parent_label').val(), specs_keep: specsKeep.join('||'), token: token}).fail(function() {
			setLive('multi', '<?php print dol_escape_js($langs->trans('AjaxFailed')); ?>');
		}).done(function(r) {
			jQuery('#sc_create').hide();
			if (!r.ok) { setLive('multi', 'Erreur : ' + (r.error || '')); return; }
			var tr = jQuery('#sc_rows tr[data-id="' + crRow + '"]');
			tr.find('td').eq(4).text(r.label);
			if (crMode == 'update') {
				setLive(r.warning ? 'multi' : 'ok', '<span class="fa fa-magic"></span> ' + r.ref + ' &mdash; ' + r.label + ' &middot; mis à jour : ' + ((r.done && r.done.length) ? r.done.join(', ') : 'rien à changer') + (r.warning ? '<br><span class="fa fa-exclamation-triangle"></span> ' + r.warning : ''));
				jQuery('#sc_codek').focus();
				return;
			}
			tr.find('td').eq(5).html('created <span class="fa fa-clock-o" style="color:#b26a00"></span>');
			tr.find('a.sc_create').replaceWith('<a href="#" class="sc_enrich" title="Enrichir le produit" data-row="' + crRow + '" data-ean="' + (jQuery('#sc_cr_ean').text().match(/EAN : (\S+)/) || ['',''])[1].replace('—','') + '" data-label="' + (r.label || '') + '"><span class="fa fa-magic" style="color:#7b1fa2"></span></a>');
			var pb = jQuery('#sc_pending'); pb.text((parseInt(pb.text()) || 0) + 1).removeClass('zero'); jQuery('.sc_pending_mirror').text(pb.text());
			var u = jQuery('#sc_unkbadge'); var n = Math.max(0, (parseInt(u.find('a').text()) || 1) - 1); u.find('a').text(n + ' inconnus'); if (!n) u.addClass('zero');
			setLive(r.warning ? 'multi' : 'ok', '<span class="fa fa-check"></span> ' + r.ref + ' &mdash; ' + r.label + (r.family ? ' [famille ' + r.family + ']' : '') + ' &middot; <?php print dol_escape_js($langs->trans('CreatedPending')); ?>' + (r.warning ? '<br><span class="fa fa-exclamation-triangle"></span> ' + r.warning : ''));
			jQuery('#sc_codek').focus();
		});
	});
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
				if (r.ok) { tr.find('td').eq(3).text(r.qty); tr.find('td').eq(7).html(scEcartHtml(r.qty, tr.attr('data-sb'))); a.data('qty', r.qty); }
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
