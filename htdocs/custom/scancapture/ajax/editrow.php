<?php
/* Copyright (C) 2026 Jose Martinez <jose.martinez@pichinov.com> - GPL v3+ */
/**
 * Edition complete d'une ligne de scan, envoyee ou non.
 * action=get    : etat de la ligne (+ inventaire, verrou)
 * action=save   : code_kezia, ean, qty, fk_product ; si la ligne est dans un inventaire OUVERT,
 *                 les lignes d'inventaire concernees sont recalees (scSyncInvLine)
 * action=cancel : annule la ligne (suppression) ; si envoyee, son comptage est retire de l'inventaire
 * action=search : recherche produit (ref, libelle, code-barres) pour changer le produit
 * Inventaire clos : quantite/produit/annulation refuses (le comptage est fige).
 */
$res = 0;
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res && file_exists('../../../../main.inc.php')) $res = @include '../../../../main.inc.php';
if (!$res) die('Include of main fails');
require_once dol_buildpath('/scancapture/lib/scancapture.lib.php');
if (!$user->admin && !$user->hasRight('stock', 'creer')) accessforbidden();
top_httphead('application/json');

$action = GETPOST('action', 'aZ09');
$rowid = GETPOSTINT('rowid');

if ($action === 'search') {
	$q = trim(GETPOST('q', 'alphanohtml'));
	$out = array();
	if (dol_strlen($q) >= 2) {
		$e = $db->escape($q);
		$resql = $db->query("SELECT p.rowid, p.ref, p.label, p.barcode FROM ".MAIN_DB_PREFIX."product p WHERE p.entity IN (".getEntity('product').") AND (p.ref LIKE '%".$e."%' OR p.label LIKE '%".$e."%' OR p.barcode = '".$e."') ORDER BY (p.barcode = '".$e."') DESC, (p.ref = '".$e."') DESC, p.label LIMIT 12");
		while ($resql && ($o = $db->fetch_object($resql))) { $out[] = array('id' => (int) $o->rowid, 'ref' => $o->ref, 'label' => $o->label, 'barcode' => (string) $o->barcode); }
	}
	print json_encode(array('ok' => true, 'products' => $out));
	exit;
}

$resql = $db->query("SELECT sc.rowid, sc.code_kezia, sc.ean, sc.qty, sc.fk_product, sc.fk_inventory, sc.sent_to_inv, sc.status, sc.fk_user".(scHasColumn($db, 'scan_capture', 'code_saisi') ? ", sc.code_saisi" : "").",
	p.ref AS pref, p.label AS plabel, i.ref AS iref, i.status AS istatus
	FROM ".MAIN_DB_PREFIX."scan_capture sc
	LEFT JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = sc.fk_product
	LEFT JOIN ".MAIN_DB_PREFIX."inventory i ON i.rowid = sc.fk_inventory
	WHERE sc.rowid = ".((int) $rowid));
$row = $resql ? $db->fetch_object($resql) : null;
if (!$row) { print json_encode(array('ok' => false, 'error' => 'Ligne introuvable')); exit; }
// un operateur ne modifie que ses propres scans ; un admin tout
if (!$user->admin && (int) $row->fk_user !== (int) $user->id) { print json_encode(array('ok' => false, 'error' => 'Ligne d\'un autre opérateur')); exit; }
$sent = !empty($row->sent_to_inv);
$closed = ($sent && (int) $row->istatus != 1);

if ($action === 'get') {
	print json_encode(array('ok' => true, 'row' => array(
		'rowid' => (int) $row->rowid, 'code_kezia' => (string) $row->code_kezia, 'ean' => (string) $row->ean, 'qty' => price2num($row->qty),
		'fk_product' => (int) $row->fk_product, 'product' => ($row->fk_product ? $row->pref.' — '.$row->plabel : ''), 'status' => $row->status,
		'sent' => $sent, 'sent_date' => ($sent ? dol_print_date($db->jdate($row->sent_to_inv), 'dayhour') : ''), 'inventory' => (string) $row->iref, 'closed' => $closed)));
	exit;
}

if ($action === 'ident') {
	// ce que disent la boutique et Kezia de l'EAN et du code de la ligne (l'enrichissement suit cote client)
	$famCands = array();
	$kz = function ($code) use ($db, &$famCands) {
		$out = array();
		if ($code === '') { return $out; }
		foreach (scLookupCode($db, $code) as $c) {
			if ($c['source'] === 'assoc') { continue; }
			$q = $db->query("SELECT p.rowid, p.ref, p.label FROM ".MAIN_DB_PREFIX."product p JOIN ".MAIN_DB_PREFIX."product_extrafields pe ON pe.fk_object = p.rowid WHERE p.rowid = ".((int) $c['rowid'])." AND pe.kezia_idart IS NOT NULL");
			if ($q && ($x = $db->fetch_object($q))) { $out[] = $x->ref.' — '.$x->label; $famCands[(int) $x->rowid] = array('id' => (int) $x->rowid, 'ref' => $x->ref, 'label' => $x->label); }
		}
		return array_values(array_unique($out));
	};
	// re-identification = repartir de zero : le cache d'enrichissement de la ligne peut contenir
	// l'erreur a corriger (il est sinon ressorti tel quel au lieu d'une vraie nouvelle recherche)
	$db->query("UPDATE ".MAIN_DB_PREFIX."scan_capture SET ean_info = NULL WHERE rowid = ".((int) $row->rowid));
	$code = (string) ($row->code_saisi ?? '') !== '' ? (string) $row->code_saisi : (string) $row->code_kezia;
	$kc = $kz($code); $ke = $kz((string) $row->ean);
	// la fiche de la ligne peut-elle etre corrigee ? (jamais un article Kezia d'origine)
	$fiche = null;
	if ($row->fk_product) {
		$q = $db->query("SELECT p.rowid, p.ref, p.label, pe.kezia_idart, pe.variant_parent_link FROM ".MAIN_DB_PREFIX."product p LEFT JOIN ".MAIN_DB_PREFIX."product_extrafields pe ON pe.fk_object = p.rowid WHERE p.rowid = ".((int) $row->fk_product));
		$fiche = $q ? $db->fetch_object($q) : null;
	}
	$editable = ($fiche && empty($fiche->kezia_idart));
	$fams = array();
	foreach ($famCands as $fid => $f) { if ($editable && $fid != (int) $fiche->variant_parent_link && $fid != (int) $fiche->rowid) { $fams[] = $f; } }
	print json_encode(array('ok' => true, 'boutique' => scPsCatalogEan((string) $row->ean), 'kezia_code' => $kc, 'kezia_ean' => $ke, 'code' => $code, 'ean' => (string) $row->ean,
		'editable' => $editable, 'fiche' => ($fiche ? $fiche->ref.' — '.$fiche->label : ''), 'familles' => $fams));
	exit;
}

if ($action === 'attachfamily' || $action === 'useboutique') {
	if (!$row->fk_product) { print json_encode(array('ok' => false, 'error' => 'Ligne sans produit')); exit; }
	$q = $db->query("SELECT p.rowid, p.ref, p.label, p.barcode, pe.kezia_idart, pe.variant_parent_link FROM ".MAIN_DB_PREFIX."product p LEFT JOIN ".MAIN_DB_PREFIX."product_extrafields pe ON pe.fk_object = p.rowid WHERE p.rowid = ".((int) $row->fk_product));
	$pr = $q ? $db->fetch_object($q) : null;
	if (!$pr || !empty($pr->kezia_idart)) { print json_encode(array('ok' => false, 'error' => 'Fiche article Kezia d\'origine : non modifiable ici')); exit; }
	$done = array();
	$db->begin();
	if ($action === 'attachfamily') {
		$fid = GETPOSTINT('fam');
		$q = $db->query("SELECT p.rowid, p.ref, p.label, p.barcode, p.price, p.price_ttc, p.price_base_type, p.tva_tx, pe.kezia_idart FROM ".MAIN_DB_PREFIX."product p JOIN ".MAIN_DB_PREFIX."product_extrafields pe ON pe.fk_object = p.rowid WHERE p.rowid = ".$fid." AND pe.kezia_idart IS NOT NULL");
		$fa = $q ? $db->fetch_object($q) : null;
		if (!$fa) { $db->rollback(); print json_encode(array('ok' => false, 'error' => 'Famille Kezia introuvable')); exit; }
		$oldfam = (int) $pr->variant_parent_link;
		$suffix = $pr->barcode ? ' (EAN '.substr($pr->barcode, -5).')' : '';
		// fiche : famille, libelle, prix ; ligne de prix niveau 1 ; achat du fournisseur de la famille
		$db->query("INSERT INTO ".MAIN_DB_PREFIX."product_extrafields (fk_object, variant_parent_ref, variant_parent_link) VALUES (".((int) $pr->rowid).", '".$db->escape($fa->ref)."', ".((int) $fa->rowid).") ON DUPLICATE KEY UPDATE variant_parent_ref = VALUES(variant_parent_ref), variant_parent_link = VALUES(variant_parent_link)");
		$db->query("UPDATE ".MAIN_DB_PREFIX."product SET label = '".$db->escape($fa->label.$suffix)."', price = ".((float) $fa->price).", price_ttc = ".((float) $fa->price_ttc).", price_base_type = '".$db->escape($fa->price_base_type ?: 'TTC')."', tva_tx = ".((float) $fa->tva_tx)." WHERE rowid = ".((int) $pr->rowid));
		if ((float) $fa->price_ttc > 0) {
			$db->query("INSERT INTO ".MAIN_DB_PREFIX."product_price (entity, fk_product, date_price, price_level, price, price_ttc, price_base_type, tva_tx, fk_user_author, import_key) VALUES (1, ".((int) $pr->rowid).", NOW(), 1, ".((float) $fa->price).", ".((float) $fa->price_ttc).", '".$db->escape($fa->price_base_type ?: 'TTC')."', ".((float) $fa->tva_tx).", ".((int) $user->id).", 'SCANFAM')");
		}
		$qf = $db->query("SELECT fk_soc, unitprice, tva_tx FROM ".MAIN_DB_PREFIX."product_fournisseur_price WHERE fk_product = ".((int) $fa->rowid)." ORDER BY rowid LIMIT 1");
		$ff = $qf ? $db->fetch_object($qf) : null;
		if ($ff) {
			$qx = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."product_fournisseur_price WHERE fk_product = ".((int) $pr->rowid)." ORDER BY rowid LIMIT 1");
			$px = $qx ? $db->fetch_object($qx) : null;
			if ($px) { $db->query("UPDATE ".MAIN_DB_PREFIX."product_fournisseur_price SET fk_soc = ".((int) $ff->fk_soc).", price = ".((float) $ff->unitprice).", unitprice = ".((float) $ff->unitprice).", tva_tx = ".((float) $ff->tva_tx)." WHERE rowid = ".((int) $px->rowid)); }
			else { $db->query("INSERT INTO ".MAIN_DB_PREFIX."product_fournisseur_price (datec, tms, fk_product, fk_soc, ref_fourn, price, quantity, unitprice, tva_tx, entity, fk_user, barcode, fk_barcode_type, import_key) VALUES (NOW(), NOW(), ".((int) $pr->rowid).", ".((int) $ff->fk_soc).", '".$db->escape($pr->ref)."', ".((float) $ff->unitprice).", 1, ".((float) $ff->unitprice).", ".((float) $ff->tva_tx).", 1, ".((int) $user->id).", ".($pr->barcode ? "'".$db->escape($pr->barcode)."'" : "NULL").", 2, 'SCANFAM')"); }
		}
		// scans de la fiche : bonne etiquette affichee, code saisi garde dessous, plus de marqueur
		$famcode = scKeziaLabelCode($fa->barcode, $fa->kezia_idart);
		$hasCols = scHasColumn($db, 'scan_capture', 'code_saisi');
		$db->query("UPDATE ".MAIN_DB_PREFIX."scan_capture SET ".($hasCols ? "code_saisi = IF(code_saisi IS NULL OR code_saisi = '', code_kezia, code_saisi), code_inconnu = 0, " : "")."code_kezia = '".$db->escape($famcode)."', product_label = '".$db->escape($fa->label.$suffix)."' WHERE fk_product = ".((int) $pr->rowid));
		// l'association apprise qui envoyait ces codes vers l'ancienne famille est fausse
		if ($oldfam > 0) {
			$qc = $db->query("SELECT DISTINCT ".($hasCols ? "code_saisi" : "code_kezia")." AS c FROM ".MAIN_DB_PREFIX."scan_capture WHERE fk_product = ".((int) $pr->rowid));
			while ($qc && ($oc = $db->fetch_object($qc))) {
				if (empty($oc->c)) { continue; }
				$e = $db->escape($oc->c);
				$db->query("DELETE FROM ".MAIN_DB_PREFIX."scan_assoc WHERE fk_product = ".$oldfam." AND code IN ('".$e."', '0".$e."')");
			}
			$db->query("UPDATE ".MAIN_DB_PREFIX."product_fournisseur_price SET barcode = NULL WHERE fk_product = ".$oldfam." AND barcode IS NOT NULL AND barcode = '".$db->escape((string) $pr->barcode)."'");
		}
		$done[] = 'rattachée à '.$fa->ref;
		$db->commit();
		foreach (array_filter(array($oldfam, (int) $fa->rowid)) as $f) { scFamilyRecap($db, $f); }
	} else {
		$ps = scPsCatalogEan((string) $pr->barcode !== '' ? (string) $pr->barcode : (string) $row->ean);
		if (!$ps) { $db->rollback(); print json_encode(array('ok' => false, 'error' => 'EAN absent de la boutique')); exit; }
		$label = $ps['name'].($ps['ref_combi'] !== '' ? ' ('.$ps['ref_combi'].')' : '');
		$db->query("UPDATE ".MAIN_DB_PREFIX."product SET label = '".$db->escape($label)."' WHERE rowid = ".((int) $pr->rowid));
		$done[] = 'libellé boutique';
		if ($ps['ref_combi'] !== '' && $ps['ref_combi'] !== $pr->ref) {
			$q = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."product WHERE ref = '".$db->escape($ps['ref_combi'])."'");
			if ($q && !$db->fetch_object($q)) { $db->query("UPDATE ".MAIN_DB_PREFIX."product SET ref = '".$db->escape($ps['ref_combi'])."' WHERE rowid = ".((int) $pr->rowid)); $done[] = 'réf '.$ps['ref_combi']; }
			else { $done[] = 'réf '.$ps['ref_combi'].' déjà prise : réf inchangée'; }
		}
		$db->query("UPDATE ".MAIN_DB_PREFIX."scan_capture SET product_label = '".$db->escape($label)."' WHERE fk_product = ".((int) $pr->rowid));
		$db->commit();
	}
	print json_encode(array('ok' => true, 'done' => $done));
	exit;
}

if ($action === 'unsend') {
	// sortir la ligne de l'inventaire en la gardant dans le scan : elle redevient « a envoyer »
	if (!$sent) { print json_encode(array('ok' => false, 'error' => 'Ligne pas encore envoyée')); exit; }
	if ($closed) { print json_encode(array('ok' => false, 'error' => 'Inventaire '.$row->iref.' clôturé : comptage figé')); exit; }
	$db->begin();
	$db->query("UPDATE ".MAIN_DB_PREFIX."scan_capture SET sent_to_inv = NULL WHERE rowid = ".((int) $row->rowid));
	$newq = $row->fk_product ? scSyncInvLine($db, (int) $row->fk_inventory, (int) $row->fk_product) : 0;
	$db->commit();
	if ($row->fk_product) { $par = scFamilyParentOf($db, (int) $row->fk_product); if ($par > 0) { scFamilyRecap($db, $par); } }
	print json_encode(array('ok' => true, 'rowid' => (int) $row->rowid, 'inventory' => (string) $row->iref, 'inventory_qty' => $newq));
	exit;
}

if ($action === 'cancel') {
	if ($closed) { print json_encode(array('ok' => false, 'error' => 'Inventaire '.$row->iref.' clôturé : comptage figé')); exit; }
	$db->begin();
	$db->query("DELETE FROM ".MAIN_DB_PREFIX."scan_capture WHERE rowid = ".((int) $row->rowid));
	$newq = 0;
	if ($sent && $row->fk_product) { $newq = scSyncInvLine($db, (int) $row->fk_inventory, (int) $row->fk_product); }
	$db->commit();
	if ($row->fk_product) { $par = scFamilyParentOf($db, (int) $row->fk_product); if ($par > 0) { scFamilyRecap($db, $par); } }
	print json_encode(array('ok' => true, 'cancelled' => (int) $row->rowid, 'inventory_qty' => $newq, 'sent' => $sent));
	exit;
}

if ($action === 'save') {
	$code = trim(GETPOST('code_kezia', 'alphanohtml'));
	$ean = trim(GETPOST('ean', 'alphanohtml'));
	$qty = (float) price2num(GETPOST('qty', 'alpha'), 'MS');
	$newprod = GETPOSTINT('fk_product');
	if ($qty < 0) { print json_encode(array('ok' => false, 'error' => 'Quantité négative')); exit; }
	$prodChanged = ($newprod > 0 && $newprod != (int) $row->fk_product);
	$qtyChanged = (abs($qty - (float) $row->qty) > 0.00001);
	if ($closed && ($prodChanged || $qtyChanged)) { print json_encode(array('ok' => false, 'error' => 'Inventaire '.$row->iref.' clôturé : quantité et produit figés')); exit; }
	$set = array("code_kezia = ".($code !== '' ? "'".$db->escape($code)."'" : "NULL"), "ean = ".($ean !== '' ? "'".$db->escape($ean)."'" : "NULL"), "qty = ".$qty);
	if ($prodChanged) {
		$r2 = $db->query("SELECT label FROM ".MAIN_DB_PREFIX."product WHERE rowid = ".$newprod);
		$np = $r2 ? $db->fetch_object($r2) : null;
		if (!$np) { print json_encode(array('ok' => false, 'error' => 'Produit introuvable')); exit; }
		$set[] = "fk_product = ".$newprod;
		$set[] = "product_label = '".$db->escape($np->label)."'";
		$set[] = "match_source = 'manuel'";
		if (!in_array($row->status, array('matched', 'created'))) { $set[] = "status = 'matched'"; }
		elseif ($row->status === 'created') { $set[] = "status = 'matched'"; }
	}
	$db->begin();
	$db->query("UPDATE ".MAIN_DB_PREFIX."scan_capture SET ".implode(', ', $set)." WHERE rowid = ".((int) $row->rowid));
	if ($sent && !$closed) {
		if ($row->fk_product) { scSyncInvLine($db, (int) $row->fk_inventory, (int) $row->fk_product); }
		if ($prodChanged) { scSyncInvLine($db, (int) $row->fk_inventory, $newprod); }
	}
	$db->commit();
	foreach (array_unique(array_filter(array((int) $row->fk_product, $prodChanged ? $newprod : 0))) as $pid) { $par = scFamilyParentOf($db, $pid); if ($par > 0) { scFamilyRecap($db, $par); } }
	print json_encode(array('ok' => true, 'rowid' => (int) $row->rowid, 'qty' => price2num($qty), 'product_changed' => $prodChanged, 'inventory_synced' => ($sent && !$closed)));
	exit;
}
print json_encode(array('ok' => false, 'error' => 'action inconnue'));
