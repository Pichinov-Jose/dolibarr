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
// 'sandbox' while waiting for the production keyset (empty marketplace: proves the chain, finds nothing)
$env = getDolGlobalString('SCANCAPTURE_EBAY_ENV', 'production');
$apihost = ($env === 'sandbox' ? 'api.sandbox.ebay.com' : 'api.ebay.com');
$tokkey = 'SCANCAPTURE_EBAY_TOKEN'.($env === 'sandbox' ? '_SBX' : '');

$rowid = GETPOSTINT('rowid');
$resql = $db->query("SELECT rowid, ean, ean_info FROM ".MAIN_DB_PREFIX."scan_capture WHERE rowid = ".((int) $rowid));
$row = $resql ? $db->fetch_object($resql) : null;
if (!$row || empty($row->ean)) { print json_encode(array('ok' => false, 'error' => 'row/ean')); exit; }
$prev = $row->ean_info ? json_decode($row->ean_info, true) : array();
// cache version 2 = includes item specifics (localizedAspects); older caches re-fetch once
if (!empty($prev['ebay']) && $prev['ebay'] === $env && (int) ($prev['ebay_v'] ?? 0) >= 2) { print json_encode(array('ok' => true, 'cached' => true, 'info' => $prev)); exit; }

// daily guard (free tier allows 5000/day; stay well under)
$kday = 'SCANCAPTURE_EBAY_'.dol_print_date(dol_now(), '%Y%m%d');
$count = (int) getDolGlobalString($kday);
if ($count >= (int) getDolGlobalString('SCANCAPTURE_EBAY_DAILY_MAX', '500')) { print json_encode(array('ok' => false, 'error' => 'quota')); exit; }
dolibarr_set_const($db, $kday, (string) ($count + 1), 'chaine', 0, '', 1);

// OAuth2 client-credentials token, cached ~2h in a const
$token = '';
$tokraw = getDolGlobalString($tokkey);
if ($tokraw) {
	$tok = json_decode($tokraw, true);
	if (!empty($tok['t']) && !empty($tok['exp']) && $tok['exp'] > (dol_now() + 60)) { $token = $tok['t']; }
}
if ($token === '') {
	$ch = curl_init('https://'.$apihost.'/identity/v1/oauth2/token');
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
	dolibarr_set_const($db, $tokkey, json_encode(array('t' => $token, 'exp' => dol_now() + (int) ($j['expires_in'] ?? 7200) - 120)), 'chaine', 0, '', 1);
}

// GTIN search on the FR marketplace
$ch = curl_init('https://'.$apihost.'/buy/browse/v1/item_summary/search?gtin='.urlencode($row->ean).'&limit=5');
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
$mkt = 'EBAY_FR';
if (!count($items)) {
	// nothing on the FR marketplace: US brands (0-prefixed UPC-A and others) often only list on ebay.com
	$ch = curl_init('https://'.$apihost.'/buy/browse/v1/item_summary/search?gtin='.urlencode($row->ean).'&limit=5');
	curl_setopt_array($ch, array(
		CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
		CURLOPT_HTTPHEADER => array('Authorization: Bearer '.$token, 'X-EBAY-C-MARKETPLACE-ID: EBAY_US'),
	));
	$out = curl_exec($ch);
	curl_close($ch);
	$j2 = $out ? json_decode($out, true) : null;
	if (!empty($j2['itemSummaries'])) { $items = $j2['itemSummaries']; $mkt = 'EBAY_US'; }
}
$title = ''; $titles = array(); $images = array(); $prices = array(); $urls = array();
foreach ($items as $it) {
	if ($title === '' && !empty($it['title'])) { $title = $it['title']; }
	if (!empty($it['title']) && !in_array($it['title'], $titles)) { $titles[] = $it['title']; }
	if (!empty($it['image']['imageUrl'])) { $images[] = $it['image']['imageUrl']; }
	foreach (($it['additionalImages'] ?? array()) as $ai) { if (!empty($ai['imageUrl'])) { $images[] = $ai['imageUrl']; } }
	if (!empty($it['price']['value'])) { $prices[] = (float) $it['price']['value']; }
	if (!empty($it['itemWebUrl'])) { $urls[] = $it['itemWebUrl']; }
}
$images = array_slice(array_values(array_unique($images)), 0, 5);

// item specifics (localizedAspects) from the first listing: the seller-filled attribute/value pairs
$specs = array(); $mpn_ebay = '';
if (!empty($items[0]['itemId'])) {
	$ch = curl_init('https://'.$apihost.'/buy/browse/v1/item/'.urlencode($items[0]['itemId']));
	curl_setopt_array($ch, array(
		CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
		CURLOPT_HTTPHEADER => array('Authorization: Bearer '.$token, 'X-EBAY-C-MARKETPLACE-ID: '.$mkt, 'Accept-Language: fr-FR'),
	));
	$outit = curl_exec($ch);
	curl_close($ch);
	$ji = $outit ? json_decode($outit, true) : null;
	foreach (($ji['localizedAspects'] ?? array()) as $asp) {
		$an = trim((string) ($asp['name'] ?? '')); $av = trim((string) ($asp['value'] ?? ''));
		if ($an === '' || $av === '') { continue; }
		if (preg_match('/^(EAN|UPC|ISBN|GTIN)$/i', $an)) { continue; }
		if (preg_match('/^(MPN|Référence fabricant|Numéro de pièce fabricant)$/iu', $an)) { $mpn_ebay = $av; continue; }
		if (count($specs) < 15) { $specs[$an] = $av; }
	}
}
$merged = array_merge($prev ?: array(), array(
	'ebay' => $env,
	'ebay_v' => 2,
	'title' => ($prev['title'] ?? '') !== '' ? $prev['title'] : $title,
	'titles' => array_slice($titles, 0, 5),
	'images' => !empty($prev['images']) ? $prev['images'] : $images,
	'prix_marche' => $prices ? min($prices).' à '.max($prices).' '.((string) ($items[0]['price']['currency'] ?? 'EUR')).' ('.count($prices).' annonces'.($mkt == 'EBAY_US' ? ', eBay US' : '').')' : '',
	'sources' => array_slice($urls, 0, 3),
	'specs' => (!empty($prev['specs']) ? array_merge($specs, (array) $prev['specs']) : $specs),
	'mpn' => ($prev['mpn'] ?? '') !== '' ? $prev['mpn'] : $mpn_ebay,
));
if (($merged['brand'] ?? '') === '' && !empty($specs['Marque'])) { $merged['brand'] = $specs['Marque']; }
$db->query("UPDATE ".MAIN_DB_PREFIX."scan_capture SET ean_info = '".$db->escape(json_encode($merged))."' WHERE rowid = ".((int) $row->rowid));
print json_encode(array('ok' => true, 'found' => (int) count($items), 'info' => $merged));
