<?php
/* Copyright (C) 2026 Jose MARTINEZ <jose.martinez@pichinov.com> — GPL v3+ */
$res = 0;
if (!$res && file_exists('../../main.inc.php')) $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res) die('Include of main fails');
require_once dol_buildpath('/scancapture/lib/scancapture.lib.php');
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
$langs->load('scancapture@scancapture');
if (!$user->admin && !$user->hasRight('stock', 'creer')) accessforbidden();

$action = GETPOST('action', 'aZ09');
$msg = '';

if ($action == 'masscreate') {
	$ids = GETPOST('sel', 'array:int');
	$created = 0; $errors = array();
	foreach ($ids as $id) {
		$label = trim(GETPOST('label'.$id, 'alphanohtml'));
		$price = (float) price2num(GETPOST('price'.$id, 'alpha'), 'MU');
		$resql = $db->query("SELECT rowid, ean, qty, fk_inventory, match_source FROM ".MAIN_DB_PREFIX."scan_capture WHERE rowid = ".((int) $id)." AND status = 'unknown'");
		$row = $resql ? $db->fetch_object($resql) : null;
		if (!$row || $label === '') { continue; }
		$db->begin();
		$ref = scMakeRef($db, $label);
		$eanOk = (!empty($row->ean) && scIsValidEan13($row->ean) && count(scLookupCode($db, $row->ean)) == 0);
		$p = new Product($db);
		$p->ref = $ref; $p->label = $label; $p->type = 0; $p->status = 1; $p->status_buy = 1;
		$p->price_base_type = 'TTC'; $p->price_ttc = $price; $p->tva_tx = 20;
		if ($eanOk) { $p->barcode = $row->ean; $p->barcode_type = 2; }
		$p->import_key = 'SCAN'.dol_print_date(dol_now(), '%y%m%d');
		$pid = $p->create($user);
		if ($pid > 0) {
			// Product::create does not persist import_key from the property
			$db->query("UPDATE ".MAIN_DB_PREFIX."product SET import_key = '".$db->escape($p->import_key)."' WHERE rowid = ".((int) $pid));
			if (!$eanOk && !empty($row->ean)) {
				$db->query("INSERT INTO ".MAIN_DB_PREFIX."product_extrafields (fk_object, ean_kezia) VALUES (".((int) $pid).", '".$db->escape($row->ean)."') ON DUPLICATE KEY UPDATE ean_kezia = VALUES(ean_kezia)");
			}
			$parentref = (strpos((string) $row->match_source, 'variantof:') === 0) ? substr($row->match_source, 10) : '';
			if ($parentref !== '') {
				$db->query("INSERT INTO ".MAIN_DB_PREFIX."product_extrafields (fk_object, variant_parent_ref) VALUES (".((int) $pid).", '".$db->escape($parentref)."') ON DUPLICATE KEY UPDATE variant_parent_ref = VALUES(variant_parent_ref)");
			}
			$db->query("UPDATE ".MAIN_DB_PREFIX."scan_capture SET fk_product = ".((int) $pid).", status = 'created', product_label = '".$db->escape($label)."' WHERE rowid = ".((int) $id));
			// feed inventory with all captured qty of this EAN if a target inventory was set
			$resql2 = $db->query("SELECT rowid, qty, fk_inventory FROM ".MAIN_DB_PREFIX."scan_capture WHERE rowid = ".((int) $id)." AND fk_inventory IS NOT NULL");
			if ($resql2 && ($r2 = $db->fetch_object($resql2))) { scFeedInventory($db, (int) $r2->fk_inventory, $pid, (float) $r2->qty); }
			$db->commit(); $created++;
		} else {
			$db->rollback(); $errors[] = $ref.': '.$p->error;
		}
	}
	$msg = '<div class="ok">'.$created.' '.$langs->trans('ProductsCreated').'</div>'.($errors ? '<div class="error">'.dol_escape_htmltag(implode(' | ', $errors)).'</div>' : '');
}

llxHeader('', $langs->trans('ScanReviewMenu'));
print load_fiche_titre($langs->trans('ScanReviewTitle'), '', 'barcode');
print $msg;

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="masscreate">';
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td></td><td>'.$langs->trans('KeziaCode').'</td><td>'.$langs->trans('ProductEan').'</td><td class="right">'.$langs->trans('Qty').'</td><td>'.$langs->trans('InternetInfo').'</td><td>'.$langs->trans('Label').'</td><td>'.$langs->trans('PriceTTC').'</td></tr>';
$resql = $db->query("SELECT rowid, code_kezia, ean, qty, ean_info, match_source, product_label FROM ".MAIN_DB_PREFIX."scan_capture WHERE status = 'unknown' ORDER BY rowid DESC LIMIT 500");
$nb = 0;
if ($resql) {
	while ($o = $db->fetch_object($resql)) {
		$nb++;
		$info = $o->ean_info ? json_decode($o->ean_info, true) : null;
		$title = $info && !empty($info['title']) ? $info['title'] : '';
		$parentref = (strpos((string) $o->match_source, 'variantof:') === 0) ? substr($o->match_source, 10) : '';
		if ($title === '' && $parentref !== '' && $o->product_label) { $title = $o->product_label.' (EAN '.substr((string) $o->ean, -5).')'; }
		$brand = $info && !empty($info['brand']) ? $info['brand'] : '';
		print '<tr class="oddeven">';
		print '<td><input type="checkbox" name="sel[]" value="'.$o->rowid.'"></td>';
		print '<td>'.dol_escape_htmltag((string) $o->code_kezia).'</td><td>'.dol_escape_htmltag((string) $o->ean).'</td><td class="right">'.price2num($o->qty).'</td>';
		print '<td>'.($parentref !== '' ? '<span class="badge badge-status4 badge-status">famille '.dol_escape_htmltag($parentref).'</span> ' : '').dol_escape_htmltag(trim($brand.' '.$title)).($info === null ? '<span class="opacitymedium"> (pas encore interrogé)</span>' : ($title === '' ? '<span class="opacitymedium">aucun résultat</span>' : '')).'</td>';
		print '<td><input type="text" name="label'.$o->rowid.'" class="minwidth300" value="'.dol_escape_htmltag($title !== '' ? trim($brand.' '.$title) : '').'"></td>';
		print '<td><input type="text" name="price'.$o->rowid.'" class="width75 right" value=""></td>';
		print '</tr>';
	}
}
if (!$nb) print '<tr><td colspan="7"><span class="opacitymedium">'.$langs->trans('NoUnknownRows').'</span></td></tr>';
print '</table></div>';
if ($nb) print '<br><div class="center"><input type="submit" class="button" value="'.$langs->trans('MassCreateSelected').'"></div>';
print '</form>';
llxFooter();
$db->close();
