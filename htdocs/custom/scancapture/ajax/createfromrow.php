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
$resql = $db->query("SELECT rowid, ean, qty, status, sent_to_inv, match_source, ean_info, product_label FROM ".MAIN_DB_PREFIX."scan_capture WHERE rowid = ".((int) $rowid));
$row = $resql ? $db->fetch_object($resql) : null;
if (!$row || $row->status != 'unknown' || $row->sent_to_inv) { print json_encode(array('ok' => false, 'error' => 'bad row')); exit; }
if ($label === '') { print json_encode(array('ok' => false, 'error' => 'label')); exit; }

// family product (known Kezia code): inherit selling price/VAT and supplier buying price
$parentref = (strpos((string) $row->match_source, 'variantof:') === 0) ? substr($row->match_source, 10) : '';
$parent = null;
if ($parentref !== '') {
	$resql = $db->query("SELECT rowid, price_ttc, price_base_type, tva_tx FROM ".MAIN_DB_PREFIX."product WHERE ref = '".$db->escape($parentref)."'");
	$parent = $resql ? $db->fetch_object($resql) : null;
}
$db->begin();
$ref = scMakeRef($db, $label);
$eanOk = (!empty($row->ean) && scIsValidEan13($row->ean) && count(scLookupCode($db, $row->ean)) == 0);
$p = new Product($db);
$p->ref = $ref; $p->label = $label; $p->type = 0; $p->status = 1; $p->status_buy = 1;
$p->price_base_type = 'TTC';
$p->price_ttc = ($price > 0 ? $price : ($parent ? (float) $parent->price_ttc : 0));
$p->tva_tx = ($parent ? (float) $parent->tva_tx : 20);
if ($eanOk) { $p->barcode = $row->ean; $p->barcode_type = 2; }
$pid = $p->create($user);
if ($pid <= 0) { $db->rollback(); print json_encode(array('ok' => false, 'error' => $p->error)); exit; }
$db->query("UPDATE ".MAIN_DB_PREFIX."product SET import_key = 'SCAN".$db->escape(dol_print_date(dol_now(), '%y%m%d'))."' WHERE rowid = ".((int) $pid));
if (!$eanOk && !empty($row->ean)) {
	$db->query("INSERT INTO ".MAIN_DB_PREFIX."product_extrafields (fk_object, ean_kezia) VALUES (".((int) $pid).", '".$db->escape($row->ean)."') ON DUPLICATE KEY UPDATE ean_kezia = VALUES(ean_kezia)");
}
if ($parentref !== '') {
	$db->query("INSERT INTO ".MAIN_DB_PREFIX."product_extrafields (fk_object, variant_parent_ref) VALUES (".((int) $pid).", '".$db->escape($parentref)."') ON DUPLICATE KEY UPDATE variant_parent_ref = VALUES(variant_parent_ref)");
	if ($parent) {
		scInheritFromParent($db, $user, (int) $pid, (int) $parent->rowid, $ref);
	}
}
$db->query("UPDATE ".MAIN_DB_PREFIX."scan_capture SET fk_product = ".((int) $pid).", status = 'created', product_label = '".$db->escape($label)."' WHERE rowid = ".((int) $rowid));
$db->commit();
print json_encode(array('ok' => true, 'fk_product' => $pid, 'ref' => $ref, 'label' => $label, 'family' => $parentref));
