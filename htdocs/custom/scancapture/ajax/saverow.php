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
$replace_row = GETPOSTINT('replace_row'); // provisional ambiguous/mismatch row to purge once resolved
if ($qty == 0) $qty = 1;
if ($codek === '' && $ean === '') {
	print json_encode(array('ok' => false, 'error' => 'no code'));
	exit;
}

// Resolve each code separately: the EAN identifies the exact product (child level),
// the Kezia code (often shared by a parent/children family) is only a consistency check.
$ck = ($codek !== '') ? scLookupCode($db, $codek) : array();
$ce = ($ean !== '') ? scLookupCode($db, $ean) : array();
$candidates = array();
foreach (array_merge($ce, $ck) as $cand) $candidates[$cand['rowid']] = $cand; // EAN candidates first
$candidates = array_values($candidates);

$fk_product = 0; $label = ''; $source = ''; $status = 'unknown'; $mismatch = 0; $eanIsKnown = (count($ce) > 0);
if ($forced_product > 0) {
	foreach ($candidates as $c) if ($c['rowid'] == $forced_product) { $fk_product = $c['rowid']; $label = $c['label']; $source = $c['source']; }
	if (!$fk_product) { $fk_product = $forced_product; $source = 'forced'; }
	$status = 'matched';
} elseif (count($ce) == 1) {
	// EAN wins: its product name is the one reported
	$fk_product = $ce[0]['rowid']; $label = $ce[0]['label']; $source = $ce[0]['source'];
	$status = 'matched';
	if (count($ck)) {
		$ok = 0;
		foreach ($ck as $c) if ($c['rowid'] == $fk_product) $ok = 1;
		if (!$ok) {
			// labels disagree: let the operator choose between both products (red two-column alert)
			$mismatch = 1; $status = 'mismatch'; $fk_product = 0; $label = ''; $source = '';
		}
	}
} elseif (count($ce) > 1) {
	$status = 'ambiguous'; $candidates = $ce;
} elseif (count($ck) == 1) {
	if ($ean !== '' && count($ce) == 0 && scProductHasEan($db, (int) $ck[0]['rowid'])) {
		// family code + brand-new EAN while the product already carries one:
		// treat as a NEW flat variant to create, linked to this family (variant_parent_ref)
		$status = 'unknown'; $label = $ck[0]['label']; $source = 'variantof:'.$ck[0]['ref'];
	} else {
		$fk_product = $ck[0]['rowid']; $label = $ck[0]['label']; $source = $ck[0]['source'];
		$status = 'matched';
	}
} elseif (count($ck) > 1) {
	$status = 'ambiguous'; $candidates = $ck;
}

$assoc = ''; $kassoc = '';
$db->begin();
// reverse association: EAN identified the product but the scanned Kezia label is unknown
// (typical Presta-born products): remember the label so future label scans resolve directly
if ($status == 'matched' && $codek !== '' && $fk_product > 0 && count($ck) == 0) {
	$db->query("INSERT INTO ".MAIN_DB_PREFIX."scan_assoc (datec, code, fk_product, fk_user, written_to) VALUES (NOW(), '".$db->escape($codek)."', ".((int) $fk_product).", ".((int) $user->id).", 'kezia_code')");
	$kassoc = 'kezia';
}
if ($replace_row > 0 && $forced_product > 0) {
	$db->query("DELETE FROM ".MAIN_DB_PREFIX."scan_capture WHERE rowid = ".((int) $replace_row)." AND status IN ('ambiguous', 'mismatch')");
}
if ($status == 'matched' && $ean !== '' && $fk_product > 0 && !$eanIsKnown) {
	$assoc = scAssocEan($db, $user, $fk_product, $ean);
}
$fed = 0; // inventory is now fed in one shot by ajax/sendtoinv.php after the operator validates the list
$sql = "INSERT INTO ".MAIN_DB_PREFIX."scan_capture (datec, fk_user, code_kezia, ean, qty, fk_product, match_source, product_label, candidates, status, fk_inventory, import_key) VALUES (";
$sql .= "NOW(), ".((int) $user->id).", ".($codek !== '' ? "'".$db->escape($codek)."'" : "NULL").", ".($ean !== '' ? "'".$db->escape($ean)."'" : "NULL").", ".((float) $qty).", ";
$sql .= ($fk_product > 0 ? (int) $fk_product : "NULL").", ".($source !== '' ? "'".$db->escape($source)."'" : "NULL").", ".($label !== '' ? "'".$db->escape($label)."'" : "NULL").", ";
$sql .= (count($candidates) > 1 || $status == 'mismatch' ? "'".$db->escape(json_encode($candidates))."'" : "NULL").", '".$db->escape($status)."', ".($fk_inventory > 0 ? (int) $fk_inventory : "NULL").", '".$db->escape(dol_print_date(dol_now(), '%Y%m%d') ? 'SCAN'.dol_print_date(dol_now(), '%y%m%d') : 'SCAN')."')";
$resql = $db->query($sql);
$rowid = $resql ? $db->last_insert_id(MAIN_DB_PREFIX.'scan_capture') : 0;
$db->commit();
print json_encode(array('ok' => (bool) $resql, 'rowid' => $rowid, 'status' => $status, 'label' => $label, 'fk_product' => $fk_product, 'assoc' => $assoc, 'fed' => $fed, 'mismatch' => $mismatch, 'candidates' => $candidates, 'group_kezia' => $ck, 'group_ean' => $ce, 'variant_of' => (strpos($source, 'variantof:') === 0 ? substr($source, 10) : ''), 'kassoc' => $kassoc));
