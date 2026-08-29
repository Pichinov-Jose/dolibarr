<?php
/* Copyright (C) 2026 Jose MARTINEZ <jose.martinez@pichinov.com> — GPL v3+ */

/**
 * Normalize a scanned code: trim, strip GTIN-14 leading zero.
 *
 * @param string $code Raw scanned code
 * @return string Normalized code
 */
function scNormalize($code)
{
	$code = trim($code);
	if (preg_match('/^0\d{13}$/', $code)) {
		$code = substr($code, 1);
	}
	return $code;
}

/**
 * Look up a scanned code through all sources.
 *
 * @param DoliDB $db DB
 * @param string $code Scanned code
 * @return array<int,array{rowid:int,ref:string,label:string,stock:float,source:string}> Candidates
 */
function scLookupCode($db, $code)
{
	$code = scNormalize($code);
	if ($code === '') {
		return array();
	}
	$e = $db->escape($code);
	$out = array();
	$queries = array(
		'barcode' => "SELECT p.rowid, p.ref, p.label, p.stock FROM ".MAIN_DB_PREFIX."product p WHERE p.barcode = '".$e."' AND p.entity IN (1)",
		'ean_kezia' => "SELECT p.rowid, p.ref, p.label, p.stock FROM ".MAIN_DB_PREFIX."product p JOIN ".MAIN_DB_PREFIX."product_extrafields pe ON pe.fk_object = p.rowid WHERE pe.ean_kezia = '".$e."'",
		'multicode' => "SELECT p.rowid, p.ref, p.label, p.stock FROM ".MAIN_DB_PREFIX."product p JOIN ".MAIN_DB_PREFIX."stg_multicode mc ON mc.fk_product = p.rowid WHERE mc.code = '".$e."'",
		'assoc' => "SELECT p.rowid, p.ref, p.label, p.stock FROM ".MAIN_DB_PREFIX."product p JOIN ".MAIN_DB_PREFIX."scan_assoc sa ON sa.fk_product = p.rowid WHERE sa.code = '".$e."'",
		'pfp' => "SELECT p.rowid, p.ref, p.label, p.stock FROM ".MAIN_DB_PREFIX."product p JOIN ".MAIN_DB_PREFIX."product_fournisseur_price pfp ON pfp.fk_product = p.rowid WHERE pfp.barcode = '".$e."'",
	);
	foreach ($queries as $source => $sql) {
		$resql = $db->query($sql);
		if (!$resql) {
			continue; // stg_multicode may not exist on some instances
		}
		while ($obj = $db->fetch_object($resql)) {
			if (!isset($out[$obj->rowid])) {
				$out[$obj->rowid] = array('rowid' => (int) $obj->rowid, 'ref' => $obj->ref, 'label' => $obj->label, 'stock' => (float) $obj->stock, 'source' => $source);
			}
		}
	}
	return array_values($out);
}

/**
 * Generate a product ref from a label: uppercase, strip accents, strip non-alnum,
 * strip vowels, cut to 8; on collision trim base and add numeric suffix.
 *
 * @param DoliDB $db DB
 * @param string $label Product label
 * @return string Free ref
 */
function scMakeRef($db, $label)
{
	$s = strtoupper(dol_string_unaccent($label));
	$s = preg_replace('/[^A-Z0-9]/', '', $s);
	$s = preg_replace('/[AEIOU]/', '', $s);
	$base = substr($s, 0, 8);
	if ($base === '') {
		$base = 'SCAN';
	}
	$try = $base;
	$n = 0;
	while (true) {
		$resql = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."product WHERE ref = '".$db->escape($try)."' LIMIT 1");
		if ($resql && !$db->fetch_object($resql)) {
			return $try;
		}
		$n++;
		$suffix = (string) $n;
		$try = substr($base, 0, max(1, 8 - strlen($suffix))).$suffix;
	}
}

/**
 * Check EAN13 checksum validity.
 *
 * @param string $ean Code
 * @return bool True if valid EAN13
 */
function scIsValidEan13($ean)
{
	if (!preg_match('/^\d{13}$/', $ean)) {
		return false;
	}
	$sum = 0;
	for ($i = 0; $i < 12; $i++) {
		$sum += ((int) $ean[$i]) * ($i % 2 ? 3 : 1);
	}
	return ((10 - ($sum % 10)) % 10) == (int) $ean[12];
}

/**
 * Associate a scanned EAN to an existing product following the agreed write rules:
 * never overwrite a non-empty code; valid+free EAN13 -> product.barcode (type 2);
 * else supplier price barcode if empty; else ean_kezia if empty. Always log in llx_scan_assoc.
 *
 * @param DoliDB $db DB
 * @param User $user User
 * @param int $fk_product Product id
 * @param string $ean Scanned EAN
 * @return string Where it was written ('barcode'|'pfp'|'ean_kezia'|'assoc_only'|'already')
 */
function scAssocEan($db, $user, $fk_product, $ean)
{
	$ean = scNormalize($ean);
	if ($ean === '' || $fk_product <= 0) {
		return 'none';
	}
	// already known for this product?
	$known = scLookupCode($db, $ean);
	foreach ($known as $k) {
		if ($k['rowid'] == $fk_product) {
			return 'already';
		}
	}
	$e = $db->escape($ean);
	$written = 'assoc_only';
	$eanFreeGlobally = (count($known) == 0);
	if (scIsValidEan13($ean) && $eanFreeGlobally) {
		$resql = $db->query("SELECT barcode FROM ".MAIN_DB_PREFIX."product WHERE rowid = ".((int) $fk_product));
		$obj = $resql ? $db->fetch_object($resql) : null;
		if ($obj && ($obj->barcode === null || $obj->barcode === '')) {
			$db->query("UPDATE ".MAIN_DB_PREFIX."product SET barcode = '".$e."', fk_barcode_type = 2 WHERE rowid = ".((int) $fk_product)." AND (barcode IS NULL OR barcode = '')");
			$written = 'barcode';
		}
	}
	if ($written == 'assoc_only') {
		$resql = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."product_fournisseur_price WHERE fk_product = ".((int) $fk_product)." AND (barcode IS NULL OR barcode = '') ORDER BY rowid LIMIT 1");
		$obj = $resql ? $db->fetch_object($resql) : null;
		if ($obj) {
			$db->query("UPDATE ".MAIN_DB_PREFIX."product_fournisseur_price SET barcode = '".$e."', fk_barcode_type = 2 WHERE rowid = ".((int) $obj->rowid));
			$written = 'pfp';
		}
	}
	if ($written == 'assoc_only') {
		$db->query("INSERT INTO ".MAIN_DB_PREFIX."product_extrafields (fk_object, ean_kezia) VALUES (".((int) $fk_product).", '".$e."') ON DUPLICATE KEY UPDATE ean_kezia = IF(ean_kezia IS NULL OR ean_kezia = '', VALUES(ean_kezia), ean_kezia)");
		$written = 'ean_kezia';
	}
	$db->query("INSERT INTO ".MAIN_DB_PREFIX."scan_assoc (datec, code, fk_product, fk_user, written_to) VALUES (NOW(), '".$e."', ".((int) $fk_product).", ".((int) $user->id).", '".$db->escape($written)."')");
	return $written;
}

/**
 * Add (increment) a counted qty on an inventory line for a product, creating the line if needed.
 *
 * @param DoliDB $db DB
 * @param int $fk_inventory Inventory id
 * @param int $fk_product Product id
 * @param float $qty Counted qty to add
 * @return int 1 if done, 0 if inventory invalid
 */
function scFeedInventory($db, $fk_inventory, $fk_product, $qty)
{
	$resql = $db->query("SELECT rowid, fk_warehouse, status FROM ".MAIN_DB_PREFIX."inventory WHERE rowid = ".((int) $fk_inventory));
	$inv = $resql ? $db->fetch_object($resql) : null;
	if (!$inv || $inv->status != 1) {
		return 0; // only on validated/started inventories
	}
	$wh = (int) $inv->fk_warehouse;
	$resql = $db->query("SELECT rowid, qty_view FROM ".MAIN_DB_PREFIX."inventorydet WHERE fk_inventory = ".((int) $fk_inventory)." AND fk_product = ".((int) $fk_product)." AND fk_warehouse = ".$wh." AND (batch IS NULL OR batch = '') LIMIT 1");
	$line = $resql ? $db->fetch_object($resql) : null;
	if ($line) {
		$db->query("UPDATE ".MAIN_DB_PREFIX."inventorydet SET qty_view = ".(float) (((float) $line->qty_view) + $qty)." WHERE rowid = ".((int) $line->rowid));
	} else {
		$stock = 0;
		$resql = $db->query("SELECT reel FROM ".MAIN_DB_PREFIX."product_stock WHERE fk_product = ".((int) $fk_product)." AND fk_entrepot = ".$wh);
		if ($resql && ($o = $db->fetch_object($resql))) {
			$stock = (float) $o->reel;
		}
		$db->query("INSERT INTO ".MAIN_DB_PREFIX."inventorydet (datec, fk_inventory, fk_warehouse, fk_product, qty_stock, qty_view) VALUES (NOW(), ".((int) $fk_inventory).", ".$wh.", ".((int) $fk_product).", ".(float) $stock.", ".(float) $qty.")");
	}
	return 1;
}
