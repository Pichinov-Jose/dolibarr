<?php
/* Copyright (C) 2026 Jose MARTINEZ <jose.martinez@pichinov.com> — GPL v3+ */
$res = 0;
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res && file_exists('../../../../main.inc.php')) $res = @include '../../../../main.inc.php';
if (!$res) die('Include of main fails');
require_once dol_buildpath('/scancapture/lib/scancapture.lib.php');
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
if (!$user->admin && !$user->hasRight('stock', 'creer')) accessforbidden();
top_httphead('application/json');

$rowid = GETPOSTINT('rowid');
$label = trim(GETPOST('label', 'alphanohtml'));
$price = (float) price2num(GETPOST('price', 'alpha'), 'MU');
$buyprice = (float) price2num(GETPOST('buyprice', 'alpha'), 'MU');
$mpn = trim(GETPOST('mpn', 'alphanohtml'));
$parent_mode = GETPOST('parent_mode', 'aZ09') ?: 'family';
$parent_label = trim(GETPOST('parent_label', 'alphanohtml'));
$resql = $db->query("SELECT rowid, ean, code_kezia, qty, status, sent_to_inv, match_source, ean_info, product_label FROM ".MAIN_DB_PREFIX."scan_capture WHERE rowid = ".((int) $rowid));
$row = $resql ? $db->fetch_object($resql) : null;
if (!$row || $row->status != 'unknown' || $row->sent_to_inv) { print json_encode(array('ok' => false, 'error' => 'bad row')); exit; }
if ($label === '') { print json_encode(array('ok' => false, 'error' => 'label')); exit; }

// family product (known Kezia code): inherit selling price/VAT and supplier buying price
$parentref = (strpos((string) $row->match_source, 'variantof:') === 0) ? substr($row->match_source, 10) : '';
$parent = null;
if ($parent_mode === 'none') {
	$parentref = '';
} elseif ($parentref !== '' && $parent_mode !== 'new') {
	$resql = $db->query("SELECT rowid, price_ttc, price_base_type, tva_tx FROM ".MAIN_DB_PREFIX."product WHERE ref = '".$db->escape($parentref)."'");
	$parent = $resql ? $db->fetch_object($resql) : null;
}
$db->begin();
// user asked to attach the variant to a brand-new parent product (Kezia pseudo-parents being phased out)
if ($parent_mode === 'new') {
	if ($parent_label === '') { $db->rollback(); print json_encode(array('ok' => false, 'error' => 'parent_label')); exit; }
	$np = new Product($db);
	$np->ref = scMakeRef($db, $parent_label);
	// scanner-born family: CREATE suffix distinguishes it from Kezia-migrated families (Jose 08/09)
	if (!preg_match('/\bCREATE$/', strtoupper($parent_label))) { $parent_label .= ' CREATE'; }
	$np->label = $parent_label;
	$np->type = 0; $np->status = 1; $np->status_buy = 1;
	$np->tva_tx = 20;
	$npid = $np->create($user);
	if ($npid <= 0) { $db->rollback(); print json_encode(array('ok' => false, 'error' => 'parent: '.$np->error)); exit; }
	$db->query("UPDATE ".MAIN_DB_PREFIX."product SET import_key = 'SCAN".$db->escape(dol_print_date(dol_now(), '%y%m%d'))."' WHERE rowid = ".((int) $npid));
	scTagToUpdate($db, $user, (int) $npid);
	$parentref = $np->ref;
	$parent = null;
}
$ref = scMakeRef($db, $label);
$eanOk = (!empty($row->ean) && scIsValidEan13($row->ean) && count(scLookupCode($db, $row->ean)) == 0);
$p = new Product($db);
$info = $row->ean_info ? json_decode($row->ean_info, true) : array();
$p->ref = $ref; $p->label = $label; $p->type = 0; $p->status = 1; $p->status_buy = 1;
if (!empty($info['desc_longue'])) { $p->description = $info['desc_longue']; } elseif (!empty($info['desc_courte'])) { $p->description = $info['desc_courte']; }
// attribute/value pairs the operator ticked to keep on the card
$specs_keep = trim(GETPOST('specs_keep', 'alphawithlgt'));
if ($specs_keep !== '') {
	$p->description = trim((string) $p->description."\n\nCaractéristiques :\n- ".implode("\n- ", array_filter(array_map('trim', explode('||', $specs_keep)))));
}
$p->price_base_type = 'TTC';
$p->price_ttc = ($price > 0 ? $price : ($parent ? (float) $parent->price_ttc : 0));
$p->tva_tx = ($parent ? (float) $parent->tva_tx : 20);
if ($eanOk) { $p->barcode = $row->ean; $p->barcode_type = 2; }
$pid = $p->create($user);
if ($pid <= 0) { $db->rollback(); print json_encode(array('ok' => false, 'error' => $p->error)); exit; }
$db->query("UPDATE ".MAIN_DB_PREFIX."product SET import_key = 'SCAN".$db->escape(dol_print_date(dol_now(), '%y%m%d'))."' WHERE rowid = ".((int) $pid));
scTagToUpdate($db, $user, (int) $pid);
if (!$eanOk && !empty($row->ean)) {
	$db->query("INSERT INTO ".MAIN_DB_PREFIX."product_extrafields (fk_object, ean_kezia) VALUES (".((int) $pid).", '".$db->escape($row->ean)."') ON DUPLICATE KEY UPDATE ean_kezia = VALUES(ean_kezia)");
}
if ($parentref !== '') {
	$db->query("INSERT INTO ".MAIN_DB_PREFIX."product_extrafields (fk_object, variant_parent_ref) VALUES (".((int) $pid).", '".$db->escape($parentref)."') ON DUPLICATE KEY UPDATE variant_parent_ref = VALUES(variant_parent_ref)");
	// clickable pseudo-parent (link-type extrafield rendered with getNomUrl on the product card)
	$resql = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."product WHERE ref = '".$db->escape($parentref)."'");
	if ($resql && ($pp = $db->fetch_object($resql))) {
		$db->query("UPDATE ".MAIN_DB_PREFIX."product_extrafields SET variant_parent_link = ".((int) $pp->rowid)." WHERE fk_object = ".((int) $pid));
	}
	if ($parent) {
		scInheritFromParent($db, $user, (int) $pid, (int) $parent->rowid, $ref);
	}
}
// unknown Kezia label learned at creation: the next declination of the same family will resolve directly
if (!empty($row->code_kezia) && !count(scLookupCode($db, $row->code_kezia))) {
	$db->query("INSERT INTO ".MAIN_DB_PREFIX."scan_assoc (datec, code, fk_product, fk_user, written_to) VALUES (NOW(), '".$db->escape(scNormalize($row->code_kezia))."', ".((int) $pid).", ".((int) $user->id).", 'kezia_code')");
}
// user overrides from the popup: manufacturer ref becomes the supplier product ref, buy price replaces the inherited one
$warning = '';
if ($mpn !== '') {
	$resupd = $db->query("UPDATE ".MAIN_DB_PREFIX."product_fournisseur_price SET ref_fourn = '".$db->escape($mpn)."' WHERE fk_product = ".((int) $pid));
	if (!$resupd) {
		// unique key (ref_fourn, fk_soc, quantity, entity): another product (often the family parent) already uses it
		$warning = "Réf fournisseur \"".$mpn."\" déjà prise chez ce fournisseur (famille ?) — réf technique conservée";
	}
}
if ($buyprice > 0) {
	$db->query("UPDATE ".MAIN_DB_PREFIX."product_fournisseur_price SET price = ".((float) $buyprice)." * quantity, unitprice = ".((float) $buyprice)." WHERE fk_product = ".((int) $pid));
	$db->query("UPDATE ".MAIN_DB_PREFIX."product SET cost_price = ".((float) $buyprice)." WHERE rowid = ".((int) $pid));
}
// keep the manufacturer/supplier product page for future updates (skip volatile ebay listing URLs when possible)
$srcurl = '';
foreach ((array) ($info['sources'] ?? array()) as $u) {
	if (!is_string($u) || !preg_match('#^https?://#i', $u)) { continue; }
	if (stripos($u, 'ebay.') === false) { $srcurl = $u; break; }
	if ($srcurl === '') { $srcurl = $u; }
}
if ($srcurl !== '') {
	$db->query("INSERT INTO ".MAIN_DB_PREFIX."product_extrafields (fk_object, supplier_url) VALUES (".((int) $pid).", '".$db->escape($srcurl)."') ON DUPLICATE KEY UPDATE supplier_url = IF(supplier_url IS NULL OR supplier_url = '', VALUES(supplier_url), supplier_url)");
}
$db->query("UPDATE ".MAIN_DB_PREFIX."scan_capture SET fk_product = ".((int) $pid).", status = 'created', product_label = '".$db->escape($label)."', stock_before = 0 WHERE rowid = ".((int) $rowid));
$db->commit();
$nbimg = 0;
if (!empty($info['images'])) { $nbimg = scAttachImages($conf, $ref, $info['images']); }
print json_encode(array('ok' => true, 'fk_product' => $pid, 'ref' => $ref, 'label' => $label, 'family' => $parentref, 'images' => $nbimg, 'warning' => $warning));
