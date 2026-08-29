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
if (!empty($prev['ai'])) { print json_encode(array('ok' => true, 'cached' => true, 'info' => $prev)); exit; }

// daily budget guard
$kday = 'SCANCAPTURE_AI_'.dol_print_date(dol_now(), '%Y%m%d');
$count = (int) getDolGlobalString($kday);
if ($count >= (int) getDolGlobalString('SCANCAPTURE_AI_DAILY_MAX', '200')) { print json_encode(array('ok' => false, 'error' => 'quota')); exit; }
dolibarr_set_const($db, $kday, (string) ($count + 1), 'chaine', 0, '', 1);

$context = '';
if (strpos((string) $row->match_source, 'variantof:') === 0 && $row->product_label) {
	$context = " Contexte magasin : ce code appartient probablement a une variante de la famille \"".$row->product_label."\".";
}
$prompt = "Tu aides un magasin d'articles de peche francais a identifier un produit par son code-barres EAN ".$row->ean.".".$context."
Si tu peux faire une recherche web, cherche l'EAN puis l'EAN avec des mots-cles peche. Sinon appuie-toi sur ta connaissance (prefixe fabricant, gammes connues).
Reponds UNIQUEMENT avec un objet JSON (aucun texte autour, pas de balise markdown) de la forme :
{\"libelle\": \"nom commercial court en francais\", \"marque\": \"...\", \"description_courte\": \"1-2 phrases\", \"description_longue\": \"paragraphe detaille (matiere, usage, points forts)\", \"specs\": {\"cle\": \"valeur\"}, \"images\": [\"url https\"], \"confiance\": \"haute|moyenne|basse\", \"sources\": [\"url\"]}
Si tu n'identifies rien de fiable : {\"libelle\": \"\", \"confiance\": \"basse\"}.";

$ai = new Ai($db);
$result = $ai->generateContent($prompt, 'auto', 'textgeneration');
if (is_array($result) && !empty($result['error'])) {
	print json_encode(array('ok' => false, 'error' => 'ai: '.($result['message'] ?? 'unknown'), 'service' => ($result['service'] ?? '')));
	exit;
}
$text = is_array($result) ? (string) ($result['content'] ?? '') : (string) $result;
$info = null;
if (preg_match('/\{.*\}/s', $text, $m)) { $info = json_decode($m[0], true); }
if (!$info) { print json_encode(array('ok' => false, 'error' => 'parse', 'raw' => substr($text, 0, 300))); exit; }

$merged = array_merge($prev ?: array(), array(
	'ai' => 1,
	'title' => ($info['libelle'] ?? '') !== '' ? $info['libelle'] : ($prev['title'] ?? ''),
	'brand' => ($info['marque'] ?? '') !== '' ? $info['marque'] : ($prev['brand'] ?? ''),
	'desc_courte' => $info['description_courte'] ?? '',
	'desc_longue' => $info['description_longue'] ?? '',
	'specs' => $info['specs'] ?? array(),
	'images' => $info['images'] ?? array(),
	'confiance' => $info['confiance'] ?? '',
	'sources' => $info['sources'] ?? array(),
))
;
$db->query("UPDATE ".MAIN_DB_PREFIX."scan_capture SET ean_info = '".$db->escape(json_encode($merged))."' WHERE rowid = ".((int) $row->rowid));
print json_encode(array('ok' => true, 'info' => $merged));
