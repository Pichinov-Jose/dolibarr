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
if ($fk_inventory <= 0) { print json_encode(array('ok' => false, 'error' => 'no inventory')); exit; }

// all pending resolved lines, whatever target they were captured with
$resql = $db->query("SELECT rowid, fk_product, qty FROM ".MAIN_DB_PREFIX."scan_capture WHERE sent_to_inv IS NULL AND fk_product > 0 AND status IN ('matched', 'created')");
$rows = array();
while ($resql && ($o = $db->fetch_object($resql))) { $rows[] = $o; }
$db->begin();
$sent = 0; $ids = array();
foreach ($rows as $r) {
	if (scFeedInventory($db, $fk_inventory, (int) $r->fk_product, (float) $r->qty)) {
		$db->query("UPDATE ".MAIN_DB_PREFIX."scan_capture SET sent_to_inv = NOW(), fk_inventory = ".((int) $fk_inventory)." WHERE rowid = ".((int) $r->rowid));
		$sent++; $ids[] = (int) $r->rowid;
	}
}
$db->commit();
print json_encode(array('ok' => true, 'sent' => $sent, 'ids' => $ids));
