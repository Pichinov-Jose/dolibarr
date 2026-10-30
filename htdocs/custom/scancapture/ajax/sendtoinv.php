<?php
/* Copyright (C) 2026 Jose MARTINEZ <jose.martinez@pichinov.com> — GPL v3+ */
$res = 0;
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res && file_exists('../../../../main.inc.php')) $res = @include '../../../../main.inc.php';
if (!$res) die('Include of main fails');
require_once dol_buildpath('/scancapture/lib/scancapture.lib.php');
if (!$user->admin && !$user->hasRight('stock', 'creer')) accessforbidden();
top_httphead('application/json');

$fk_inventory = GETPOSTINT('fk_inventory');
// verrou anti-course : deux envois simultanes (2 scannettes, double appui) lisaient les memes
// lignes non envoyees et alimentaient l inventaire deux fois (928 unites sur-comptees le 22-24/10)
$resql = $db->query("SELECT GET_LOCK('scancapture_send_".((int) $fk_inventory)."', 8) AS l");
$lck = $resql ? $db->fetch_object($resql) : null;
if (!$lck || (int) $lck->l != 1) { print json_encode(array('ok' => false, 'error' => 'envoi deja en cours, reessayez')); exit; }
register_shutdown_function(function () use ($db, $fk_inventory) { $db->query("SELECT RELEASE_LOCK('scancapture_send_".((int) $fk_inventory)."')"); });
if ($fk_inventory <= 0) { print json_encode(array('ok' => false, 'error' => 'no inventory')); exit; }

$autocreate = GETPOSTINT('autocreate');
$created = 0;
if ($autocreate) {
	// create products for unknown rows carrying an EAN, using internet info when available
	require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
	// only this inventory's rows (capture-only rows, fk_inventory NULL, follow the chosen target) —
	// several inventories can run in parallel without sweeping each other's scans
	$resql = $db->query("SELECT rowid, ean, code_kezia, qty, ean_info, match_source, product_label FROM ".MAIN_DB_PREFIX."scan_capture WHERE sent_to_inv IS NULL AND status = 'unknown' AND ean IS NOT NULL AND ean != '' AND (fk_inventory = ".((int) $fk_inventory)." OR fk_inventory IS NULL)".($user->admin ? "" : " AND fk_user = ".((int) $user->id)));
	$unk = array();
	while ($resql && ($o = $db->fetch_object($resql))) { $unk[] = $o; }
	foreach ($unk as $u) {
		$info = $u->ean_info ? json_decode($u->ean_info, true) : null;
		$parentref = (strpos((string) $u->match_source, 'variantof:') === 0) ? substr($u->match_source, 10) : '';
		$label = ($info && !empty($info['title'])) ? trim(($info['brand'] ?? '').' '.$info['title']) : ($parentref !== '' && $u->product_label ? $u->product_label.' (EAN '.substr($u->ean, -5).')' : 'A COMPLETER EAN '.$u->ean);
		$db->begin();
		$ref = scMakeRef($db, ($info && !empty($info['title'])) ? $label : 'SCAN'.substr($u->ean, -6));
		$eanOk = (scIsValidEan13($u->ean) && count(scLookupCode($db, $u->ean)) == 0);
		$p = new Product($db);
		$p->ref = $ref; $p->label = $label; $p->type = 0; $p->status = 1; $p->status_buy = 1;
		$p->price_base_type = 'TTC'; $p->price_ttc = 0; $p->tva_tx = 20;
		if ($eanOk) { $p->barcode = $u->ean; $p->barcode_type = 2; }
		$pid = $p->create($user);
		if ($pid > 0) {
			$db->query("UPDATE ".MAIN_DB_PREFIX."product SET import_key = 'SCAN".$db->escape(dol_print_date(dol_now(), '%y%m%d'))."' WHERE rowid = ".((int) $pid));
			scTagToUpdate($db, $user, (int) $pid);
			if (!$eanOk) {
				$db->query("INSERT INTO ".MAIN_DB_PREFIX."product_extrafields (fk_object, ean_kezia) VALUES (".((int) $pid).", '".$db->escape($u->ean)."') ON DUPLICATE KEY UPDATE ean_kezia = VALUES(ean_kezia)");
			}
			if ($parentref !== '') {
				$db->query("INSERT INTO ".MAIN_DB_PREFIX."product_extrafields (fk_object, variant_parent_ref) VALUES (".((int) $pid).", '".$db->escape($parentref)."') ON DUPLICATE KEY UPDATE variant_parent_ref = VALUES(variant_parent_ref)");
				$resqlp = $db->query("SELECT rowid, price_ttc, tva_tx FROM ".MAIN_DB_PREFIX."product WHERE ref = '".$db->escape($parentref)."'");
				if ($resqlp && ($parob = $db->fetch_object($resqlp))) {
					$db->query("UPDATE ".MAIN_DB_PREFIX."product SET price_ttc = ".((float) $parob->price_ttc).", price = ".((float) $parob->price_ttc)." / (1 + ".((float) $parob->tva_tx)." / 100), tva_tx = ".((float) $parob->tva_tx)." WHERE rowid = ".((int) $pid)." AND price_ttc = 0");
					scInheritFromParent($db, $user, (int) $pid, (int) $parob->rowid, $ref);
				}
			}
			$db->query("UPDATE ".MAIN_DB_PREFIX."scan_capture SET fk_product = ".((int) $pid).", status = 'created', product_label = '".$db->escape($label)."' WHERE rowid = ".((int) $u->rowid));
			$db->commit(); $created++;
		} else {
			$db->rollback();
		}
	}
}

// all pending resolved lines, whatever target they were captured with
$resql = $db->query("SELECT rowid, fk_product, qty FROM ".MAIN_DB_PREFIX."scan_capture WHERE sent_to_inv IS NULL AND fk_product > 0 AND status IN ('matched', 'created') AND qty <= ".getDolGlobalInt('SCANCAPTURE_MAX_QTY', 999)." AND (fk_inventory = ".((int) $fk_inventory)." OR fk_inventory IS NULL)".($user->admin ? "" : " AND fk_user = ".((int) $user->id)));
$rows = array();
while ($resql && ($o = $db->fetch_object($resql))) { $rows[] = $o; }
$db->begin();
$sent = 0; $ids = array();
foreach ($rows as $r) {
	// la ligne d'inventaire est RECALCULEE (somme des scans envoyes), jamais incrementee : une ligne
	// restee d'une reparation ou d'un renvoi ne peut plus doubler un comptage
	$resup = $db->query("UPDATE ".MAIN_DB_PREFIX."scan_capture SET sent_to_inv = NOW(), fk_inventory = ".((int) $fk_inventory)." WHERE rowid = ".((int) $r->rowid)." AND sent_to_inv IS NULL");
	if (!$resup || $db->affected_rows($resup) < 1) {
		continue; // deja envoye par l'autre scannette entre la lecture et ici
	}
	if (scSyncInvLine($db, $fk_inventory, (int) $r->fk_product) < 0) {
		$db->query("UPDATE ".MAIN_DB_PREFIX."scan_capture SET sent_to_inv = NULL WHERE rowid = ".((int) $r->rowid));
		continue;
	}
	$sent++; $ids[] = (int) $r->rowid;
}
$db->commit();
print json_encode(array('ok' => true, 'sent' => $sent, 'ids' => $ids, 'created' => $created));
