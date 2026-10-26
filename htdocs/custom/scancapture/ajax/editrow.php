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

$resql = $db->query("SELECT sc.rowid, sc.code_kezia, sc.ean, sc.qty, sc.fk_product, sc.fk_inventory, sc.sent_to_inv, sc.status, sc.fk_user,
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
