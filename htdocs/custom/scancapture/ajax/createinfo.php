<?php
/* Copyright (C) 2026 Jose MARTINEZ <jose.martinez@pichinov.com> — GPL v3+ */
$res = 0;
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res && file_exists('../../../../main.inc.php')) $res = @include '../../../../main.inc.php';
if (!$res) die('Include of main fails');
if (!$user->admin && !$user->hasRight('stock', 'creer')) accessforbidden();
top_httphead('application/json');

$rowid = GETPOSTINT('rowid');
$resql = $db->query("SELECT rowid, code_kezia, ean, match_source, ean_info, fk_product, status, candidates, datec FROM ".MAIN_DB_PREFIX."scan_capture WHERE rowid = ".((int) $rowid));
$row = $resql ? $db->fetch_object($resql) : null;
if (!$row) { print json_encode(array('ok' => false, 'error' => 'bad row')); exit; }

$out = array('ok' => true, 'ean' => (string) $row->ean, 'code_kezia' => (string) $row->code_kezia, 'family' => null, 'product' => null, 'info' => ($row->ean_info ? json_decode($row->ean_info) : null), 'status' => (string) $row->status, 'candidates' => ($row->candidates ? json_decode($row->candidates) : null), 'datec' => dol_print_date($db->jdate($row->datec), 'dayhour'));

// matched product (enrich-existing mode): current values shown and prefilled in the popup
if (!empty($row->fk_product)) {
	$resql = $db->query("SELECT p.rowid, p.ref, p.label, p.description, p.price_ttc, p.tva_tx, p.pmp, p.cost_price, pe.variant_parent_ref
		FROM ".MAIN_DB_PREFIX."product p LEFT JOIN ".MAIN_DB_PREFIX."product_extrafields pe ON pe.fk_object = p.rowid WHERE p.rowid = ".((int) $row->fk_product));
	if ($resql && ($pr = $db->fetch_object($resql))) {
		$pr->buy_price = 0; $pr->ref_fourn = ''; $pr->supplier = '';
		$resql = $db->query("SELECT pfp.unitprice, pfp.ref_fourn, pfp.fk_soc AS supplier_id, s.nom AS supplier
			FROM ".MAIN_DB_PREFIX."product_fournisseur_price pfp
			LEFT JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = pfp.fk_soc
			WHERE pfp.fk_product = ".((int) $pr->rowid)." ORDER BY pfp.quantity ASC, pfp.rowid ASC LIMIT 1");
		if ($resql && ($bp = $db->fetch_object($resql))) {
			$pr->buy_price = (float) $bp->unitprice; $pr->ref_fourn = (string) $bp->ref_fourn; $pr->supplier = (string) $bp->supplier; $pr->supplier_id = (int) $bp->supplier_id;
		}
		$out['product'] = array(
			'id' => (int) $pr->rowid,
			'ref' => $pr->ref,
			'label' => $pr->label,
			'url' => DOL_URL_ROOT.'/product/card.php?id='.((int) $pr->rowid),
			'has_desc' => (int) !empty($pr->description),
			'price_ttc' => price((float) $pr->price_ttc),
			'tva_tx' => price2num($pr->tva_tx),
			'pmp' => ((float) $pr->pmp > 0 ? price((float) $pr->pmp) : ''),
			'buy_price' => ((float) $pr->buy_price > 0 ? price((float) $pr->buy_price) : ''),
			'supplier' => (string) $pr->supplier,
			'supplier_id' => (int) (isset($pr->supplier_id) ? $pr->supplier_id : 0),
			'ref_fourn' => (string) $pr->ref_fourn,
			'parent_ref' => trim((string) $pr->variant_parent_ref)
		);
	}
}

// family pseudo-parent (known Kezia code shared by the family): everything the new product will inherit
$parentref = (strpos((string) $row->match_source, 'variantof:') === 0) ? substr($row->match_source, 10) : '';
if ($parentref !== '') {
	$resql = $db->query("SELECT p.rowid, p.ref, p.label, p.price_ttc, p.tva_tx, p.pmp, p.cost_price, e.ref AS warehouse
		FROM ".MAIN_DB_PREFIX."product p
		LEFT JOIN ".MAIN_DB_PREFIX."entrepot e ON e.rowid = p.fk_default_warehouse
		WHERE p.ref = '".$db->escape($parentref)."'");
	if ($resql && ($par = $db->fetch_object($resql))) {
		$par->buy_price = 0; $par->ref_fourn = ''; $par->supplier = '';
		$resql = $db->query("SELECT pfp.unitprice, pfp.ref_fourn, pfp.fk_soc AS supplier_id, s.nom AS supplier
			FROM ".MAIN_DB_PREFIX."product_fournisseur_price pfp
			LEFT JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = pfp.fk_soc
			WHERE pfp.fk_product = ".((int) $par->rowid)." ORDER BY pfp.quantity ASC, pfp.rowid ASC LIMIT 1");
		if ($resql && ($bp = $db->fetch_object($resql))) {
			$par->buy_price = (float) $bp->unitprice; $par->ref_fourn = (string) $bp->ref_fourn; $par->supplier = (string) $bp->supplier; $par->supplier_id = (int) $bp->supplier_id;
		}
		$nbcat = 0;
		$resql = $db->query("SELECT COUNT(*) nb FROM ".MAIN_DB_PREFIX."categorie_product WHERE fk_product = ".((int) $par->rowid));
		if ($resql && ($o = $db->fetch_object($resql))) { $nbcat = (int) $o->nb; }
		$out['family'] = array(
			'ref' => $par->ref,
			'label' => $par->label,
			'url' => DOL_URL_ROOT.'/product/card.php?id='.((int) $par->rowid),
			'price_ttc' => price((float) $par->price_ttc),
			'tva_tx' => price2num($par->tva_tx),
			'pmp' => ((float) $par->pmp > 0 ? price((float) $par->pmp) : ''),
			'cost_price' => ((float) $par->cost_price > 0 ? price((float) $par->cost_price) : ''),
			'buy_price' => ((float) $par->buy_price > 0 ? price((float) $par->buy_price) : ''),
			'supplier' => (string) $par->supplier,
			'supplier_id' => (int) (isset($par->supplier_id) ? $par->supplier_id : 0),
			'ref_fourn' => (string) $par->ref_fourn,
			'warehouse' => (string) $par->warehouse,
			'nbcat' => $nbcat
		);
	}
}
print json_encode($out);
