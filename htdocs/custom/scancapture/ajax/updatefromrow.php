<?php
/* Copyright (C) 2026 Jose MARTINEZ <jose.martinez@pichinov.com> — GPL v3+ */
$res = 0;
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res && file_exists('../../../../main.inc.php')) $res = @include '../../../../main.inc.php';
if (!$res) die('Include of main fails');
require_once dol_buildpath('/scancapture/lib/scancapture.lib.php');
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
if (!$user->admin && !$user->hasRight('produit', 'creer')) accessforbidden();
top_httphead('application/json');

$rowid = GETPOSTINT('rowid');
$label = trim(GETPOST('label', 'alphanohtml'));
$price = (float) price2num(GETPOST('price', 'alpha'), 'MU');
$buyprice = (float) price2num(GETPOST('buyprice', 'alpha'), 'MU');
$mpn = trim(GETPOST('mpn', 'alphanohtml'));

$resql = $db->query("SELECT rowid, ean, status, sent_to_inv, fk_product, ean_info FROM ".MAIN_DB_PREFIX."scan_capture WHERE rowid = ".((int) $rowid));
$row = $resql ? $db->fetch_object($resql) : null;
if (!$row || !$row->fk_product) { print json_encode(array('ok' => false, 'error' => 'bad row')); exit; }

$p = new Product($db);
if ($p->fetch((int) $row->fk_product) <= 0) { print json_encode(array('ok' => false, 'error' => 'product')); exit; }
$info = $row->ean_info ? json_decode($row->ean_info, true) : array();

$done = array();
$db->begin();
// label: the enriched declination name replaces the old (often family-level) label
if ($label !== '' && $label !== $p->label) {
	$db->query("UPDATE ".MAIN_DB_PREFIX."product SET label = '".$db->escape($label)."' WHERE rowid = ".((int) $p->id));
	$done[] = 'libellé';
}
// descriptions from enrichment fill an empty card, never overwrite manual text
$specs_keep = trim(GETPOST('specs_keep', 'alphawithlgt'));
$specblock = '';
if ($specs_keep !== '') {
	$specblock = "Caractéristiques :\n- ".implode("\n- ", array_filter(array_map('trim', explode('||', $specs_keep))));
}
if (empty($p->description)) {
	$desc = '';
	if (!empty($info['desc_longue'])) { $desc = $info['desc_longue']; } elseif (!empty($info['desc_courte'])) { $desc = $info['desc_courte']; }
	if ($specblock !== '') { $desc = trim($desc."\n\n".$specblock); }
	if ($desc !== '') {
		$db->query("UPDATE ".MAIN_DB_PREFIX."product SET description = '".$db->escape($desc)."' WHERE rowid = ".((int) $p->id));
		$done[] = 'description';
	}
} elseif ($specblock !== '' && strpos((string) $p->description, 'Caractéristiques :') === false) {
	$db->query("UPDATE ".MAIN_DB_PREFIX."product SET description = CONCAT(description, '".$db->escape("\n\n".$specblock)."') WHERE rowid = ".((int) $p->id));
	$done[] = 'caractéristiques';
}
// selling price entered in the popup
if ($price > 0) {
	$p->updatePrice($price, 'TTC', $user);
	$done[] = 'prix vente';
}
// buy price: first supplier line + cost price
if ($buyprice > 0) {
	$db->query("UPDATE ".MAIN_DB_PREFIX."product_fournisseur_price SET price = ".((float) $buyprice)." * quantity, unitprice = ".((float) $buyprice)." WHERE fk_product = ".((int) $p->id));
	$db->query("UPDATE ".MAIN_DB_PREFIX."product SET cost_price = ".((float) $buyprice)." WHERE rowid = ".((int) $p->id));
	$done[] = "prix d'achat";
}
// no supplier line yet + supplier chosen in the popup → create the first supplier price line
$fk_supplier = GETPOSTINT('fk_supplier');
$resql = $db->query("SELECT COUNT(*) AS n FROM ".MAIN_DB_PREFIX."product_fournisseur_price WHERE fk_product = ".((int) $p->id));
$hasPfp = ($resql && ($x = $db->fetch_object($resql))) ? ((int) $x->n > 0) : false;
if (!$hasPfp && $fk_supplier > 0) {
	$db->query("INSERT INTO ".MAIN_DB_PREFIX."product_fournisseur_price (datec, tms, fk_product, fk_soc, ref_fourn, price, quantity, unitprice, tva_tx, entity, fk_user)
		VALUES (NOW(), NOW(), ".((int) $p->id).", ".((int) $fk_supplier).", '".$db->escape($mpn)."', ".((float) $buyprice).", 1, ".((float) $buyprice).", 20, 1, ".((int) $user->id).")");
	$done[] = 'fournisseur + prix d\'achat créés';
	$hasPfp = true; $mpn = ''; $buyprice = 0; // consumed
}

// manufacturer ref becomes the supplier product ref (unique per supplier: warn when taken)
$warning = '';
if ($mpn !== '') {
	$resupd = $db->query("UPDATE ".MAIN_DB_PREFIX."product_fournisseur_price SET ref_fourn = '".$db->escape($mpn)."' WHERE fk_product = ".((int) $p->id)." AND ref_fourn != '".$db->escape($mpn)."'");
	if ($resupd) { $done[] = 'réf fournisseur'; }
	else { $warning = "Réf fournisseur \"".$mpn."\" déjà prise chez ce fournisseur — inchangée"; }
}
// attach to a family: create the missing parent on demand (Jose 08/09 — enrich mode covers products born parentless)
if (GETPOST('parent_mode', 'aZ09') === 'new') {
	$parent_label = trim(GETPOST('parent_label', 'alphanohtml'));
	if ($parent_label !== '') {
		$np = new Product($db);
		$np->ref = scMakeRef($db, $parent_label);
		if (!preg_match('/\bCREATE$/', strtoupper($parent_label))) { $parent_label .= ' CREATE'; }
		$np->label = $parent_label;
		$np->type = 0; $np->status = 1; $np->status_buy = 1; $np->tva_tx = 20;
		$npid = $np->create($user);
		if ($npid > 0) {
			$db->query("UPDATE ".MAIN_DB_PREFIX."product SET import_key = 'SCAN".$db->escape(dol_print_date(dol_now(), '%y%m%d'))."' WHERE rowid = ".((int) $npid));
			scTagToUpdate($db, $user, (int) $npid);
			$db->query("INSERT INTO ".MAIN_DB_PREFIX."product_extrafields (fk_object, variant_parent_ref, variant_parent_link) VALUES (".((int) $p->id).", '".$db->escape($np->ref)."', ".((int) $npid).") ON DUPLICATE KEY UPDATE variant_parent_ref = '".$db->escape($np->ref)."', variant_parent_link = ".((int) $npid));
			$done[] = 'parent '.$np->ref.' créé et rattaché';
		} else {
			$warning = trim($warning.' Création du parent échouée : '.$np->error);
		}
	}
}
// scanned EAN associated by the standard non-destructive rules
$assoc = '';
if (!empty($row->ean)) {
	$assoc = scAssocEan($db, $user, (int) $p->id, $row->ean);
	if ($assoc && $assoc != 'already' && $assoc != 'none') { $done[] = 'EAN ('.$assoc.')'; }
}
// manufacturer/supplier product page for future updates (only when not already set)
$srcurl = '';
foreach ((array) ($info['sources'] ?? array()) as $u) {
	if (!is_string($u) || !preg_match('#^https?://#i', $u)) { continue; }
	if (stripos($u, 'ebay.') === false) { $srcurl = $u; break; }
	if ($srcurl === '') { $srcurl = $u; }
}
if ($srcurl !== '') {
	$resupd = $db->query("INSERT INTO ".MAIN_DB_PREFIX."product_extrafields (fk_object, supplier_url) VALUES (".((int) $p->id).", '".$db->escape($srcurl)."') ON DUPLICATE KEY UPDATE supplier_url = IF(supplier_url IS NULL OR supplier_url = '', VALUES(supplier_url), supplier_url)");
	if ($resupd && $db->affected_rows($resupd) > 0) { $done[] = 'URL fournisseur'; }
}
// remember the operator's retained declination in the row cache (shown back on reopen)
$declm = trim(GETPOST('decl', 'alphanohtml'));
if ($declm !== '') {
	$einfo = is_array($info) ? $info : array();
	$einfo['decl_retenue'] = $declm;
	$db->query("UPDATE ".MAIN_DB_PREFIX."scan_capture SET ean_info = '".$db->escape(json_encode($einfo, JSON_UNESCAPED_UNICODE))."' WHERE rowid = ".((int) $row->rowid));
}
scTagToUpdate($db, $user, (int) $p->id);
$db->commit();
$nbimg = 0;
if (!empty($info['images'])) { $nbimg = scAttachImages($conf, $p->ref, $info['images']); if ($nbimg) { $done[] = $nbimg.' photo(s)'; } }
print json_encode(array('ok' => true, 'fk_product' => (int) $p->id, 'ref' => $p->ref, 'label' => ($label !== '' ? $label : $p->label), 'done' => $done, 'warning' => $warning));
