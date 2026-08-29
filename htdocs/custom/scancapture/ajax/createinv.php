<?php
/* Copyright (C) 2026 Jose MARTINEZ <jose.martinez@pichinov.com> — GPL v3+ */
$res = 0;
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res && file_exists('../../../../main.inc.php')) $res = @include '../../../../main.inc.php';
if (!$res) die('Include of main fails');
if (!$user->admin && !$user->hasRight('stock', 'creer')) accessforbidden();
top_httphead('application/json');

$fk_warehouse = GETPOSTINT('fk_warehouse');
if ($fk_warehouse <= 0) {
	$resql = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."entrepot WHERE statut = 1 ORDER BY rowid LIMIT 1");
	$o = $resql ? $db->fetch_object($resql) : null;
	$fk_warehouse = $o ? (int) $o->rowid : 0;
}
if ($fk_warehouse <= 0) { print json_encode(array('ok' => false, 'error' => 'no warehouse')); exit; }

// ref auto INV-SCAN-yymmdd(-n)
$base = 'INV-SCAN-'.dol_print_date(dol_now(), '%y%m%d');
$ref = $base; $n = 1;
while (true) {
	$resql = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."inventory WHERE ref = '".$db->escape($ref)."'");
	if ($resql && !$db->fetch_object($resql)) break;
	$n++; $ref = $base.'-'.$n;
}
$title = GETPOST('title', 'alphanohtml');
if ($title === '') $title = 'Inventaire scan du '.dol_print_date(dol_now(), '%d/%m/%Y');
$sql = "INSERT INTO ".MAIN_DB_PREFIX."inventory (ref, entity, status, fk_warehouse, date_inventory, date_creation, fk_user_creat, title)";
$sql .= " VALUES ('".$db->escape($ref)."', 1, 1, ".((int) $fk_warehouse).", NOW(), NOW(), ".((int) $user->id).", '".$db->escape($title)."')";
$resql = $db->query($sql);
$id = $resql ? $db->last_insert_id(MAIN_DB_PREFIX.'inventory') : 0;
$wh = '';
$resql = $db->query("SELECT ref FROM ".MAIN_DB_PREFIX."entrepot WHERE rowid = ".((int) $fk_warehouse));
if ($resql && ($o = $db->fetch_object($resql))) $wh = $o->ref;
print json_encode(array('ok' => (bool) $id, 'id' => $id, 'ref' => $ref, 'label' => $ref.' ('.$wh.')'));
