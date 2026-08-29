<?php
/* Copyright (C) 2026 Jose MARTINEZ <jose.martinez@pichinov.com> — GPL v3+ */
$res = 0;
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res && file_exists('../../../../main.inc.php')) $res = @include '../../../../main.inc.php';
if (!$res) die('Include of main fails');
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
if (!$user->admin && !$user->hasRight('stock', 'creer')) accessforbidden();
top_httphead('application/json');

$rowid = GETPOSTINT('rowid');
$resql = $db->query("SELECT rowid, ean, ean_info FROM ".MAIN_DB_PREFIX."scan_capture WHERE rowid = ".((int) $rowid));
$row = $resql ? $db->fetch_object($resql) : null;
if (!$row || empty($row->ean)) { print json_encode(array('ok' => false, 'error' => 'row/ean not found')); exit; }
if (!empty($row->ean_info)) { print json_encode(array('ok' => true, 'cached' => true, 'info' => json_decode($row->ean_info))); exit; }

// daily quota for the UPCitemdb free tier
$kday = 'SCANCAPTURE_UPC_'.dol_print_date(dol_now(), '%Y%m%d');
$count = (int) getDolGlobalString($kday);
if ($count >= 95) { print json_encode(array('ok' => false, 'error' => 'quota')); exit; }
dolibarr_set_const($db, $kday, (string) ($count + 1), 'chaine', 0, '', 1);

$ch = curl_init('https://api.upcitemdb.com/prod/trial/lookup?upc='.urlencode($row->ean));
curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3));
$out = curl_exec($ch);
curl_close($ch);
$info = null;
if ($out) {
	$j = json_decode($out, true);
	if (!empty($j['items'][0])) {
		$it = $j['items'][0];
		$info = array('title' => $it['title'] ?? '', 'brand' => $it['brand'] ?? '', 'category' => $it['category'] ?? '', 'image' => (!empty($it['images'][0]) ? $it['images'][0] : ''));
	}
}
$db->query("UPDATE ".MAIN_DB_PREFIX."scan_capture SET ean_info = '".$db->escape(json_encode($info ?: array('title' => '')))."' WHERE rowid = ".((int) $rowid));
print json_encode(array('ok' => true, 'info' => $info));
