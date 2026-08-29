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
	// UPC-A handling: a 12-digit scan may be stored as 0-prefixed EAN13 and vice versa
	$codes = array($code);
	if (preg_match('/^\d{12}$/', $code)) {
		$codes[] = '0'.$code;
	} elseif (preg_match('/^0\d{12}$/', $code)) {
		$codes[] = substr($code, 1);
	}
	$in = "'".implode("','", array_map(array($db, 'escape'), $codes))."'";
	$e = $db->escape($code);
	$out = array();
	$queries = array(
		'barcode' => "SELECT p.rowid, p.ref, p.label, p.stock FROM ".MAIN_DB_PREFIX."product p WHERE p.barcode IN (".$in.") AND p.entity IN (1)",
		'ean_kezia' => "SELECT p.rowid, p.ref, p.label, p.stock FROM ".MAIN_DB_PREFIX."product p JOIN ".MAIN_DB_PREFIX."product_extrafields pe ON pe.fk_object = p.rowid WHERE pe.ean_kezia IN (".$in.")",
		'multicode' => "SELECT p.rowid, p.ref, p.label, p.stock FROM ".MAIN_DB_PREFIX."product p JOIN ".MAIN_DB_PREFIX."stg_multicode mc ON mc.fk_product = p.rowid WHERE mc.code IN (".$in.")",
		'assoc' => "SELECT p.rowid, p.ref, p.label, p.stock FROM ".MAIN_DB_PREFIX."product p JOIN ".MAIN_DB_PREFIX."scan_assoc sa ON sa.fk_product = p.rowid WHERE sa.code IN (".$in.")",
		'pfp' => "SELECT p.rowid, p.ref, p.label, p.stock FROM ".MAIN_DB_PREFIX."product p JOIN ".MAIN_DB_PREFIX."product_fournisseur_price pfp ON pfp.fk_product = p.rowid WHERE pfp.barcode IN (".$in.")",
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
		$resup = $db->query("UPDATE ".MAIN_DB_PREFIX."inventorydet SET qty_view = ".(float) (((float) $line->qty_view) + $qty)." WHERE rowid = ".((int) $line->rowid));
		if (!$resup) {
			dol_syslog("scFeedInventory UPDATE failed: ".$db->lasterror(), LOG_ERR);
			return 0;
		}
	} else {
		$stock = 0;
		$resql = $db->query("SELECT reel FROM ".MAIN_DB_PREFIX."product_stock WHERE fk_product = ".((int) $fk_product)." AND fk_entrepot = ".$wh);
		if ($resql && ($o = $db->fetch_object($resql))) {
			$stock = (float) $o->reel;
		}
		$resin = $db->query("INSERT INTO ".MAIN_DB_PREFIX."inventorydet (datec, fk_inventory, fk_warehouse, fk_product, qty_stock, qty_view) VALUES (NOW(), ".((int) $fk_inventory).", ".$wh.", ".((int) $fk_product).", ".(float) $stock.", ".(float) $qty.")");
		if (!$resin) {
			dol_syslog("scFeedInventory INSERT failed: ".$db->lasterror(), LOG_ERR);
			return 0;
		}
	}
	return 1;
}

/**
 * Does this product already carry at least one manufacturer EAN
 * (valid EAN13 barcode, supplier price barcode, or scan association)?
 *
 * @param DoliDB $db DB
 * @param int $pid Product id
 * @return bool
 */
function scProductHasEan($db, $pid)
{
	$resql = $db->query("SELECT barcode FROM ".MAIN_DB_PREFIX."product WHERE rowid = ".((int) $pid));
	$o = $resql ? $db->fetch_object($resql) : null;
	if ($o && $o->barcode && scIsValidEan13($o->barcode)) {
		return true;
	}
	$resql = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."product_fournisseur_price WHERE fk_product = ".((int) $pid)." AND barcode IS NOT NULL AND barcode != '' LIMIT 1");
	if ($resql && $db->fetch_object($resql)) {
		return true;
	}
	$resql = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."scan_assoc WHERE fk_product = ".((int) $pid)." LIMIT 1");
	if ($resql && $db->fetch_object($resql)) {
		return true;
	}
	return false;
}

/**
 * Copy "everything" from the family product onto a freshly created variant:
 * categories, weight/dimensions/units, description, accountancy codes, default warehouse,
 * cost price and the kezia_cump extrafield (margin cascade), plus the first supplier price.
 * Selling price/VAT are handled by the caller (may be overridden by the operator).
 *
 * @param DoliDB $db DB
 * @param User $user User
 * @param int $pid New product id
 * @param int $parent_id Family product id
 * @param string $newref New product ref (used as unique supplier ref)
 * @return void
 */
function scInheritFromParent($db, $user, $pid, $parent_id, $newref)
{
	// physical + accounting + misc columns
	$db->query("UPDATE ".MAIN_DB_PREFIX."product p JOIN ".MAIN_DB_PREFIX."product par ON par.rowid = ".((int) $parent_id)."
		SET p.weight = par.weight, p.weight_units = par.weight_units,
			p.length = par.length, p.length_units = par.length_units,
			p.width = par.width, p.width_units = par.width_units,
			p.height = par.height, p.height_units = par.height_units,
			p.surface = par.surface, p.surface_units = par.surface_units,
			p.volume = par.volume, p.volume_units = par.volume_units,
			p.description = par.description,
			p.accountancy_code_sell = par.accountancy_code_sell,
			p.accountancy_code_sell_intra = par.accountancy_code_sell_intra,
			p.accountancy_code_sell_export = par.accountancy_code_sell_export,
			p.accountancy_code_buy = par.accountancy_code_buy,
			p.accountancy_code_buy_intra = par.accountancy_code_buy_intra,
			p.accountancy_code_buy_export = par.accountancy_code_buy_export,
			p.fk_default_warehouse = par.fk_default_warehouse,
			p.cost_price = par.cost_price,
			p.fk_unit = par.fk_unit
		WHERE p.rowid = ".((int) $pid));
	// categories
	$db->query("INSERT IGNORE INTO ".MAIN_DB_PREFIX."categorie_product (fk_categorie, fk_product)
		SELECT cp.fk_categorie, ".((int) $pid)." FROM ".MAIN_DB_PREFIX."categorie_product cp WHERE cp.fk_product = ".((int) $parent_id));
	// kezia_cump extrafield (margin cascade source)
	$db->query("INSERT INTO ".MAIN_DB_PREFIX."product_extrafields (fk_object, kezia_cump)
		SELECT ".((int) $pid).", pe.kezia_cump FROM ".MAIN_DB_PREFIX."product_extrafields pe WHERE pe.fk_object = ".((int) $parent_id)." AND pe.kezia_cump IS NOT NULL
		ON DUPLICATE KEY UPDATE kezia_cump = VALUES(kezia_cump)");
	// first supplier price, technical unique supplier ref = new product ref
	$db->query("INSERT INTO ".MAIN_DB_PREFIX."product_fournisseur_price (datec, tms, fk_product, fk_soc, ref_fourn, price, quantity, unitprice, tva_tx, entity, fk_user, multicurrency_price, multicurrency_unitprice, multicurrency_tx, multicurrency_code)
		SELECT NOW(), NOW(), ".((int) $pid).", pfp.fk_soc, '".$db->escape($newref)."', pfp.price, pfp.quantity, pfp.unitprice, pfp.tva_tx, 1, ".((int) $user->id).", pfp.multicurrency_price, pfp.multicurrency_unitprice, pfp.multicurrency_tx, pfp.multicurrency_code
		FROM ".MAIN_DB_PREFIX."product_fournisseur_price pfp WHERE pfp.fk_product = ".((int) $parent_id)." ORDER BY pfp.quantity ASC, pfp.rowid ASC LIMIT 1");
}

/**
 * Tag a scanner-created product with the "Mettre à jour" category (created once if missing)
 * so incomplete products are easy to list and finish later.
 *
 * @param DoliDB $db DB
 * @param User $user User
 * @param int $pid Product id
 * @return void
 */
function scTagToUpdate($db, $user, $pid)
{
	$catid = 0;
	$resql = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."categorie WHERE type = 0 AND label = 'Mettre à jour' AND entity = 1");
	if ($resql && ($o = $db->fetch_object($resql))) {
		$catid = (int) $o->rowid;
	} else {
		$db->query("INSERT INTO ".MAIN_DB_PREFIX."categorie (entity, fk_parent, label, type, visible, date_creation, fk_user_creat) VALUES (1, 0, 'Mettre à jour', 0, 1, NOW(), ".((int) $user->id).")");
		$catid = (int) $db->last_insert_id(MAIN_DB_PREFIX.'categorie');
	}
	if ($catid > 0) {
		$db->query("INSERT IGNORE INTO ".MAIN_DB_PREFIX."categorie_product (fk_categorie, fk_product) VALUES (".$catid.", ".((int) $pid).")");
	}
}

/**
 * Download up to 3 remote images into the product photo directory.
 *
 * @param Conf $conf Conf
 * @param string $ref Product ref
 * @param string[] $urls Image URLs
 * @return int Number of images attached
 */
function scAttachImages($conf, $ref, $urls)
{
	require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
	require_once DOL_DOCUMENT_ROOT.'/core/lib/images.lib.php';
	$dir = $conf->product->multidir_output[1].'/'.dol_sanitizeFileName($ref);
	dol_mkdir($dir);
	$n = 0;
	foreach (array_slice((array) $urls, 0, 3) as $u) {
		if (!preg_match('/^https:\/\//', $u)) continue;
		$ext = strtolower(pathinfo(parse_url($u, PHP_URL_PATH), PATHINFO_EXTENSION));
		if (!in_array($ext, array('jpg', 'jpeg', 'png', 'webp'))) $ext = 'jpg';
		$ch = curl_init($u);
		curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_USERAGENT => 'Mozilla/5.0'));
		$data = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if (!$data || $code >= 400 || strlen($data) < 2000 || strlen($data) > 8000000) continue;
		$fn = $dir.'/'.dol_sanitizeFileName($ref.'-scan-'.($n + 1).'.'.$ext);
		if (file_put_contents($fn, $data)) {
			@chmod($fn, 0664);
			$n++;
		}
	}
	return $n;
}
