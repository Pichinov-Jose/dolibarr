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
			// scanner-created variant: the EAN product is a child (variant_parent_ref) of the code-side family — no conflict
			$resql = $db->query("SELECT variant_parent_ref FROM ".MAIN_DB_PREFIX."product_extrafields WHERE fk_object = ".((int) $fk_product));
			$vpr = ($resql && ($x = $db->fetch_object($resql))) ? trim((string) $x->variant_parent_ref) : '';
			if ($vpr !== '') {
				foreach ($ck as $c) if (!strcasecmp((string) $c['ref'], $vpr)) { $ok = 1; }
			}
		}
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

// duplicate scan detection (same product, or same code+EAN pair, still pending today):
// announce and let the operator merge quantities or force a new row
$force = GETPOSTINT('force');
$merge_row = GETPOSTINT('merge_row');
if ($merge_row > 0) {
	$db->query("UPDATE ".MAIN_DB_PREFIX."scan_capture SET qty = qty + ".((float) $qty)." WHERE rowid = ".((int) $merge_row)." AND sent_to_inv IS NULL");
	$resql = $db->query("SELECT rowid, qty, product_label, stock_before FROM ".MAIN_DB_PREFIX."scan_capture WHERE rowid = ".((int) $merge_row));
	$m = $resql ? $db->fetch_object($resql) : null;
	print json_encode(array('ok' => (bool) $m, 'merged' => (int) $merge_row, 'qty' => ($m ? price2num($m->qty) : 0), 'label' => ($m ? $m->product_label : ''), 'status' => 'merged', 'stock_before' => ($m && $m->stock_before !== null ? (float) $m->stock_before : null)));
	exit;
}
if (!$force && !$replace_row && in_array($status, array('matched', 'unknown'))) {
	if ($fk_product > 0) {
		$dupsql = "SELECT rowid, qty, product_label FROM ".MAIN_DB_PREFIX."scan_capture WHERE sent_to_inv IS NULL AND fk_product = ".((int) $fk_product)." ORDER BY rowid DESC LIMIT 1";
	} else {
		$dupsql = "SELECT rowid, qty, product_label FROM ".MAIN_DB_PREFIX."scan_capture WHERE sent_to_inv IS NULL AND ".($codek !== '' ? "code_kezia = '".$db->escape($codek)."'" : "code_kezia IS NULL")." AND ".($ean !== '' ? "ean = '".$db->escape($ean)."'" : "ean IS NULL")." ORDER BY rowid DESC LIMIT 1";
	}
	$resql = $db->query($dupsql);
	if ($resql && ($d = $db->fetch_object($resql))) {
		print json_encode(array('ok' => true, 'dup' => (int) $d->rowid, 'dup_qty' => price2num($d->qty), 'label' => ($label !== '' ? $label : (string) $d->product_label), 'status' => 'dup'));
		exit;
	}
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
// timestamped snapshot of the theoretical stock at scan time (target warehouse when an inventory is set)
$stock_before = null;
if ($fk_product > 0 && $status == 'matched') {
	$wh = 0;
	if ($fk_inventory > 0) {
		$resql = $db->query("SELECT fk_warehouse FROM ".MAIN_DB_PREFIX."inventory WHERE rowid = ".((int) $fk_inventory));
		if ($resql && ($x = $db->fetch_object($resql))) { $wh = (int) $x->fk_warehouse; }
	}
	$resql = $db->query("SELECT SUM(reel) AS s FROM ".MAIN_DB_PREFIX."product_stock WHERE fk_product = ".((int) $fk_product).($wh > 0 ? " AND fk_entrepot = ".$wh : ""));
	$stock_before = ($resql && ($x = $db->fetch_object($resql)) && $x->s !== null) ? (float) $x->s : 0.0;
}
$sql = "INSERT INTO ".MAIN_DB_PREFIX."scan_capture (datec, fk_user, code_kezia, ean, qty, fk_product, match_source, product_label, candidates, status, fk_inventory, stock_before, import_key) VALUES (";
$sql .= "NOW(), ".((int) $user->id).", ".($codek !== '' ? "'".$db->escape($codek)."'" : "NULL").", ".($ean !== '' ? "'".$db->escape($ean)."'" : "NULL").", ".((float) $qty).", ";
$sql .= ($fk_product > 0 ? (int) $fk_product : "NULL").", ".($source !== '' ? "'".$db->escape($source)."'" : "NULL").", ".($label !== '' ? "'".$db->escape($label)."'" : "NULL").", ";
$sql .= (count($candidates) > 1 || $status == 'mismatch' ? "'".$db->escape(json_encode($candidates))."'" : "NULL").", '".$db->escape($status)."', ".($fk_inventory > 0 ? (int) $fk_inventory : "NULL").", ".($stock_before === null ? "NULL" : (float) $stock_before).", '".$db->escape(dol_print_date(dol_now(), '%Y%m%d') ? 'SCAN'.dol_print_date(dol_now(), '%y%m%d') : 'SCAN')."')";
$resql = $db->query($sql);
$rowid = $resql ? $db->last_insert_id(MAIN_DB_PREFIX.'scan_capture') : 0;
$db->commit();
print json_encode(array('ok' => (bool) $resql, 'rowid' => $rowid, 'status' => $status, 'label' => $label, 'fk_product' => $fk_product, 'assoc' => $assoc, 'fed' => $fed, 'mismatch' => $mismatch, 'candidates' => $candidates, 'group_kezia' => $ck, 'group_ean' => $ce, 'variant_of' => (strpos($source, 'variantof:') === 0 ? substr($source, 10) : ''), 'kassoc' => $kassoc, 'stock_before' => $stock_before));
