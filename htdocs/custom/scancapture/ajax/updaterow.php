<?php
/* Copyright (C) 2026 Jose MARTINEZ <jose.martinez@pichinov.com> — GPL v3+ */
$res = 0;
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res && file_exists('../../../../main.inc.php')) $res = @include '../../../../main.inc.php';
if (!$res) die('Include of main fails');
require_once dol_buildpath('/scancapture/lib/scancapture.lib.php');
if (!$user->admin && !$user->hasRight('stock', 'creer')) accessforbidden();
top_httphead('application/json');

$rowid = GETPOSTINT('rowid');
$what = GETPOST('what', 'aZ09');
$resql = $db->query("SELECT rowid, qty, fk_product, fk_inventory, status, sent_to_inv FROM ".MAIN_DB_PREFIX."scan_capture WHERE rowid = ".((int) $rowid));
$row = $resql ? $db->fetch_object($resql) : null;
if (!$row) { print json_encode(array('ok' => false, 'error' => 'row not found')); exit; }

$db->begin();
if ($what == 'del') {
	// a line already sent to the inventory must not be deleted (fix it via qty edit or in the inventory)
	if ($row->sent_to_inv) {
		$db->rollback();
		print json_encode(array('ok' => false, 'error' => 'sent'));
		exit;
	}
	$db->query("DELETE FROM ".MAIN_DB_PREFIX."scan_capture WHERE rowid = ".((int) $rowid));
	$db->commit();
	print json_encode(array('ok' => true, 'deleted' => $rowid));
	exit;
}
if ($what == 'qty') {
	$newqty = (float) price2num(GETPOST('qty', 'alpha'), 'MS');
	$delta = $newqty - (float) $row->qty;
	if ($row->sent_to_inv && $row->fk_inventory && $row->fk_product && $row->status == 'matched' && $delta != 0) {
		scFeedInventory($db, (int) $row->fk_inventory, (int) $row->fk_product, $delta);
	}
	$db->query("UPDATE ".MAIN_DB_PREFIX."scan_capture SET qty = ".((float) $newqty)." WHERE rowid = ".((int) $rowid));
	$db->commit();
	print json_encode(array('ok' => true, 'rowid' => $rowid, 'qty' => $newqty));
	exit;
}
$db->rollback();
print json_encode(array('ok' => false, 'error' => 'bad action'));
