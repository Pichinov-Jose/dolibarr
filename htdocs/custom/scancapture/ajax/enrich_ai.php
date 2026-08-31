<?php
/* Copyright (C) 2026 Jose MARTINEZ <jose.martinez@pichinov.com> — GPL v3+ */
$res = 0;
if (!$res && file_exists('../../../main.inc.php')) $res = @include '../../../main.inc.php';
if (!$res && file_exists('../../../../main.inc.php')) $res = @include '../../../../main.inc.php';
if (!$res) die('Include of main fails');
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
if (!$user->admin && !$user->hasRight('stock', 'creer')) accessforbidden();
top_httphead('application/json');

// rely on the Dolibarr v24 native AI module configuration (service, key, model set in admin)
if (!isModEnabled('ai')) { print json_encode(array('ok' => false, 'error' => 'ai module disabled')); exit; }
require_once DOL_DOCUMENT_ROOT.'/ai/class/ai.class.php';

$rowid = GETPOSTINT('rowid');
$resql = $db->query("SELECT rowid, ean, code_kezia, product_label, match_source, ean_info FROM ".MAIN_DB_PREFIX."scan_capture WHERE rowid = ".((int) $rowid));
$row = $resql ? $db->fetch_object($resql) : null;
if (!$row || empty($row->ean)) { print json_encode(array('ok' => false, 'error' => 'row/ean')); exit; }
$prev = $row->ean_info ? json_decode($row->ean_info, true) : array();
if (!empty($prev['ai']) && ($prev['title'] ?? '') !== '') { print json_encode(array('ok' => true, 'cached' => true, 'info' => $prev)); exit; }

// daily budget guard
$kday = 'SCANCAPTURE_AI_'.dol_print_date(dol_now(), '%Y%m%d');
$count = (int) getDolGlobalString($kday);
if ($count >= (int) getDolGlobalString('SCANCAPTURE_AI_DAILY_MAX', '200')) { print json_encode(array('ok' => false, 'error' => 'quota')); exit; }
dolibarr_set_const($db, $kday, (string) ($count + 1), 'chaine', 0, '', 1);

$context = '';
if (strpos((string) $row->match_source, 'variantof:') === 0 && $row->product_label) {
	$context = " Contexte magasin : ce code appartient probablement a une variante de la famille \"".$row->product_label."\".";
}
$prompt = "Trouve un maximum d'informations sur ce produit a partir de son code-barres EAN ".$row->ean." (magasin d'articles de peche francais).".$context."
Cherche sur le web : l'EAN seul, puis l'EAN avec des mots-cles peche, puis marque+modele une fois identifies (prefixe GS1 = pays, prefixe entreprise = fabricant). Attention : chez beaucoup de fabricants chaque coloris/taille a son propre EAN, la reference fabricant etant commune — precise le discriminant de declinaison (code coloris, taille) quand tu le trouves.
IMPORTANT : fais tes recherches puis reponds avec UNIQUEMENT l'objet JSON ci-dessous — aucun rapport, aucune analyse, aucun texte ni markdown avant ou apres, ta reponse visible EST le JSON :
{\"libelle\": \"nom commercial court en francais avec le coloris/la taille\", \"marque\": \"...\", \"reference_fabricant\": \"reference/MPN du fabricant + code declinaison si trouve, sinon vide\", \"description_courte\": \"1-2 phrases\", \"description_longue\": \"paragraphe detaille (matiere, usage, points forts, specs)\", \"specs\": {\"cle\": \"valeur\"}, \"prix_public_ttc_eur\": \"prix public conseille ou constate en France, ex 12.90, sinon vide\", \"prix_achat_ht_eur\": \"estimation du prix d'achat revendeur HT, ex 6.50, sinon vide\", \"images\": [\"url https directes\"], \"confiance\": \"haute|moyenne|basse\", \"sources\": [\"url\"]}
Si tu n'identifies rien de fiable : {\"libelle\": \"\", \"confiance\": \"basse\"}.";

$text = '';
$service = getDolGlobalString('AI_API_SERVICE', 'anthropic');
$apikey = getDolGlobalString('AI_API_ANTHROPIC_KEY');
if ($service === 'anthropic' && $apikey !== '') {
	// direct Messages API call so we can enable the server-side web_search tool (the Ai class cannot)
	$model = getDolGlobalString('SCANCAPTURE_AI_MODEL', getDolGlobalString('AI_API_ANTHROPIC_MODEL_TEXT', 'claude-opus-4-8'));
	$payload = json_encode(array(
		'model' => $model,
		'max_tokens' => 4000,
		'tools' => array(array('type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 4)),
		'messages' => array(array('role' => 'user', 'content' => $prompt)),
	));
	$ch = curl_init('https://api.anthropic.com/v1/messages');
	curl_setopt_array($ch, array(
		CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_POST => true,
		CURLOPT_POSTFIELDS => $payload,
		CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'x-api-key: '.$apikey, 'anthropic-version: 2023-06-01'),
	));
	$out = curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	$j = $out ? json_decode($out, true) : null;
	if ($code != 200 || empty($j['content'])) {
		print json_encode(array('ok' => false, 'error' => 'ai api '.$code, 'detail' => substr((string) $out, 0, 300)));
		exit;
	}
	foreach ($j['content'] as $block) {
		if (($block['type'] ?? '') === 'text') { $text .= $block['text']; }
	}
} else {
	$ai = new Ai($db);
	$result = $ai->generateContent($prompt, 'auto', 'textgeneration');
	if (is_array($result) && !empty($result['error'])) {
		print json_encode(array('ok' => false, 'error' => 'ai: '.($result['message'] ?? 'unknown'), 'service' => ($result['service'] ?? '')));
		exit;
	}
	$text = is_array($result) ? (string) ($result['content'] ?? '') : (string) $result;
}
$info = null;
$p = strrpos($text, '{"libelle"');
if ($p === false) { $p = strpos($text, '{'); }
if ($p !== false) {
	$q = strrpos($text, '}');
	if ($q !== false && $q > $p) { $info = json_decode(substr($text, $p, $q - $p + 1), true); }
}
if (!$info && preg_match('/\{.*\}/s', $text, $m)) { $info = json_decode($m[0], true); }
if (!$info) { print json_encode(array('ok' => false, 'error' => 'parse', 'raw' => substr($text, 0, 300))); exit; }
// web-search citation tags must never reach product data
foreach (array('libelle', 'marque', 'reference_fabricant', 'description_courte', 'description_longue') as $k) {
	if (!empty($info[$k])) { $info[$k] = trim(preg_replace('/<\/?cite[^>]*>/', '', (string) $info[$k])); }
}

$merged = array_merge($prev ?: array(), array(
	'ai' => 1,
	'title' => ($info['libelle'] ?? '') !== '' ? $info['libelle'] : ($prev['title'] ?? ''),
	'brand' => ($info['marque'] ?? '') !== '' ? $info['marque'] : ($prev['brand'] ?? ''),
	'mpn' => ($info['reference_fabricant'] ?? '') !== '' ? $info['reference_fabricant'] : ($prev['mpn'] ?? ''),
	'desc_courte' => $info['description_courte'] ?? '',
	'desc_longue' => $info['description_longue'] ?? '',
	'specs' => $info['specs'] ?? array(),
	'prix_public' => (string) ($info['prix_public_ttc_eur'] ?? ''),
	'prix_achat' => (string) ($info['prix_achat_ht_eur'] ?? ''),
	'images' => $info['images'] ?? array(),
	'confiance' => $info['confiance'] ?? '',
	'sources' => $info['sources'] ?? array(),
))
;
$db->query("UPDATE ".MAIN_DB_PREFIX."scan_capture SET ean_info = '".$db->escape(json_encode($merged))."' WHERE rowid = ".((int) $row->rowid));
print json_encode(array('ok' => true, 'info' => $merged));
