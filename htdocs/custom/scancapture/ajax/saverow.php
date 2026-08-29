<?php
/* Copyright (C) 2026 Jose MARTINEZ <jose.martinez@pichinov.com> — GPL v3+ */
$res = 0;
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res && file_exists('../../../../main.inc.php')) $res = @include '../../../../main.inc.php';
if (!$res) die('Include of main fails');
require_once dol_buildpath('/scancapture/lib/scancapture.lib.php');
if (!$user->admin && !$user->hasRight('stock', 'creer')) accessforbidden();
top_httphead('application/json');

$codek = scNormalize(GETPOST('code_kezia', 'alphanohtml'));
$ean = scNormalize(GETPOST('ean', 'alphanohtml'));
$qty = (float) price2num(GETPOST('qty', 'alpha'), 'MS');
$fk_inventory = GETPOSTINT('fk_inventory');
$forced_product = GETPOSTINT('fk_product'); // when the operator resolved an ambiguity
if ($qty == 0) $qty = 1;
if ($codek === '' && $ean === '') {
	print json_encode(array('ok' => false, 'error' => 'no code'));
	exit;
}

$candidates = array();
foreach (array($codek, $ean) as $c) {
	if ($c === '') continue;
	foreach (scLookupCode($db, $c) as $cand) $candidates[$cand['rowid']] = $cand;
}
$candidates = array_values($candidates);

$fk_product = 0; $label = ''; $source = ''; $status = 'unknown';
if ($forced_product > 0) {
	foreach ($candidates as $c) if ($c['rowid'] == $forced_product) { $fk_product = $c['rowid']; $label = $c['label']; $source = $c['source']; }
	if (!$fk_product) { $fk_product = $forced_product; $source = 'forced'; }
	$status = 'matched';
} elseif (count($candidates) == 1) {
	$fk_product = $candidates[0]['rowid']; $label = $candidates[0]['label']; $source = $candidates[0]['source'];
	$status = 'matched';
} elseif (count($candidates) > 1) {
	$status = 'ambiguous';
}

$assoc = '';
$db->begin();
if ($status == 'matched' && $ean !== '' && $fk_product > 0) {
	$assoc = scAssocEan($db, $user, $fk_product, $ean);
}
$fed = 0;
if ($status == 'matched' && $fk_inventory > 0 && $fk_product > 0) {
	$fed = scFeedInventory($db, $fk_inventory, $fk_product, $qty);
}
$sql = "INSERT INTO ".MAIN_DB_PREFIX."scan_capture (datec, fk_user, code_kezia, ean, qty, fk_product, match_source, product_label, candidates, status, fk_inventory, import_key) VALUES (";
$sql .= "NOW(), ".((int) $user->id).", ".($codek !== '' ? "'".$db->escape($codek)."'" : "NULL").", ".($ean !== '' ? "'".$db->escape($ean)."'" : "NULL").", ".((float) $qty).", ";
$sql .= ($fk_product > 0 ? (int) $fk_product : "NULL").", ".($source !== '' ? "'".$db->escape($source)."'" : "NULL").", ".($label !== '' ? "'".$db->escape($label)."'" : "NULL").", ";
$sql .= (count($candidates) > 1 ? "'".$db->escape(json_encode($candidates))."'" : "NULL").", '".$db->escape($status)."', ".($fk_inventory > 0 ? (int) $fk_inventory : "NULL").", '".$db->escape(dol_print_date(dol_now(), '%Y%m%d') ? 'SCAN'.dol_print_date(dol_now(), '%y%m%d') : 'SCAN')."')";
$resql = $db->query($sql);
$rowid = $resql ? $db->last_insert_id(MAIN_DB_PREFIX.'scan_capture') : 0;
$db->commit();
print json_encode(array('ok' => (bool) $resql, 'rowid' => $rowid, 'status' => $status, 'label' => $label, 'fk_product' => $fk_product, 'assoc' => $assoc, 'fed' => $fed, 'candidates' => $candidates));
