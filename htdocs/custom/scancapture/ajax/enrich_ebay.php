<?php
/* Copyright (C) 2026 Jose MARTINEZ <jose.martinez@pichinov.com> — GPL v3+ */
$res = 0;
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res && file_exists('../../../../main.inc.php')) $res = @include '../../../../main.inc.php';
if (!$res) die('Include of main fails');
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
if (!$user->admin && !$user->hasRight('stock', 'creer')) accessforbidden();
top_httphead('application/json');

// eBay Browse API — official GTIN lookup. Needs a (free) eBay developer keyset:
// consts SCANCAPTURE_EBAY_APP_ID + SCANCAPTURE_EBAY_CERT_ID (production keyset).
$appid = getDolGlobalString('SCANCAPTURE_EBAY_APP_ID');
$certid = getDolGlobalString('SCANCAPTURE_EBAY_CERT_ID');
if ($appid === '' || $certid === '') { print json_encode(array('ok' => false, 'error' => 'nokey')); exit; }

$rowid = GETPOSTINT('rowid');
$resql = $db->query("SELECT rowid, ean, ean_info FROM ".MAIN_DB_PREFIX."scan_capture WHERE rowid = ".((int) $rowid));
$row = $resql ? $db->fetch_object($resql) : null;
if (!$row || empty($row->ean)) { print json_encode(array('ok' => false, 'error' => 'row/ean')); exit; }
$prev = $row->ean_info ? json_decode($row->ean_info, true) : array();
if (!empty($prev['ebay'])) { print json_encode(array('ok' => true, 'cached' => true, 'info' => $prev)); exit; }

// daily guard (free tier allows 5000/day; stay well under)
$kday = 'SCANCAPTURE_EBAY_'.dol_print_date(dol_now(), '%Y%m%d');
$count = (int) getDolGlobalString($kday);
if ($count >= (int) getDolGlobalString('SCANCAPTURE_EBAY_DAILY_MAX', '500')) { print json_encode(array('ok' => false, 'error' => 'quota')); exit; }
dolibarr_set_const($db, $kday, (string) ($count + 1), 'chaine', 0, '', 1);

// OAuth2 client-credentials token, cached ~2h in a const
$token = '';
$tokraw = getDolGlobalString('SCANCAPTURE_EBAY_TOKEN');
if ($tokraw) {
	$tok = json_decode($tokraw, true);
	if (!empty($tok['t']) && !empty($tok['exp']) && $tok['exp'] > (dol_now() + 60)) { $token = $tok['t']; }
}
if ($token === '') {
	$ch = curl_init('https://api.ebay.com/identity/v1/oauth2/token');
	curl_setopt_array($ch, array(
		CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_POST => true,
		CURLOPT_POSTFIELDS => 'grant_type=client_credentials&scope='.urlencode('https://api.ebay.com/oauth/api_scope'),
		CURLOPT_HTTPHEADER => array('Content-Type: application/x-www-form-urlencoded', 'Authorization: Basic '.base64_encode($appid.':'.$certid)),
	));
	$out = curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	$j = $out ? json_decode($out, true) : null;
	if ($code != 200 || empty($j['access_token'])) {
		print json_encode(array('ok' => false, 'error' => 'oauth '.$code, 'detail' => substr((string) $out, 0, 200)));
		exit;
	}
	$token = $j['access_token'];
	dolibarr_set_const($db, 'SCANCAPTURE_EBAY_TOKEN', json_encode(array('t' => $token, 'exp' => dol_now() + (int) ($j['expires_in'] ?? 7200) - 120)), 'chaine', 0, '', 1);
}

// GTIN search on the FR marketplace
$ch = curl_init('https://api.ebay.com/buy/browse/v1/item_summary/search?gtin='.urlencode($row->ean).'&limit=5');
curl_setopt_array($ch, array(
	CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
	CURLOPT_HTTPHEADER => array('Authorization: Bearer '.$token, 'X-EBAY-C-MARKETPLACE-ID: EBAY_FR', 'Accept-Language: fr-FR'),
));
$out = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$j = $out ? json_decode($out, true) : null;
if ($code != 200) { print json_encode(array('ok' => false, 'error' => 'api '.$code, 'detail' => substr((string) $out, 0, 200))); exit; }

$items = $j['itemSummaries'] ?? array();
$title = ''; $images = array(); $prices = array(); $urls = array();
foreach ($items as $it) {
	if ($title === '' && !empty($it['title'])) { $title = $it['title']; }
	if (!empty($it['image']['imageUrl'])) { $images[] = $it['image']['imageUrl']; }
	foreach (($it['additionalImages'] ?? array()) as $ai) { if (!empty($ai['imageUrl'])) { $images[] = $ai['imageUrl']; } }
	if (!empty($it['price']['value'])) { $prices[] = (float) $it['price']['value']; }
	if (!empty($it['itemWebUrl'])) { $urls[] = $it['itemWebUrl']; }
}
$images = array_slice(array_values(array_unique($images)), 0, 5);
$merged = array_merge($prev ?: array(), array(
	'ebay' => 1,
	'title' => ($prev['title'] ?? '') !== '' ? $prev['title'] : $title,
	'images' => !empty($prev['images']) ? $prev['images'] : $images,
	'prix_marche' => $prices ? min($prices).' à '.max($prices).' EUR ('.count($prices).' annonces)' : '',
	'sources' => array_slice($urls, 0, 3),
));
$db->query("UPDATE ".MAIN_DB_PREFIX."scan_capture SET ean_info = '".$db->escape(json_encode($merged))."' WHERE rowid = ".((int) $row->rowid));
print json_encode(array('ok' => true, 'found' => (int) count($items), 'info' => $merged));
