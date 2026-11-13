<?php
/* Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * Product enrichment service.
 *
 * Looks up an EAN in UPCitemdb, then eBay (Browse API), then on demand an AI web search
 * (Anthropic Messages API with the web_search server tool). The logic of the three sources comes from
 * the module scancapture (ajax/enrich.php, enrich_ebay.php, enrich_ai.php) and uses the same settings
 * (eBay keys, AI model) and the same daily quota counters, so both modules share the limits.
 *
 * Results are cached by EAN in llx_productenrichment_cache; the cache of the scanner
 * (llx_scan_capture.ean_info) is read too, the richest result wins (AI > eBay > UPCitemdb).
 *
 * The data found is only applied to EMPTY fields of a product: description, brand ref (extrafield
 * ref_marque), manufacturer page (extrafield supplier_url), barcode, selling price of level 1,
 * photos and characteristics (module productcharacteristics). Variants are only shown, never created.
 */
class ProductEnrichment
{
	const VERSION = '1.1.1';
	const MAX_IMAGES = 3;
	const UPC_DAILY_MAX = 95;	// UPCitemdb free tier: 100 requests a day

	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var string[] */
	public $errors = array();

	/** @var string Output of the scheduled job */
	public $output = '';

	/** @var ?bool */
	private $hasscantable = null;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/*
	 * EAN and cache
	 */

	/**
	 * Normalize a code: trim, remove spaces, strip the leading zero of a GTIN-14
	 *
	 * @param string $code Code
	 * @return string      Normalized code, '' if it cannot be an EAN/UPC
	 */
	public static function normalizeEan($code)
	{
		$code = preg_replace('/\s+/', '', trim((string) $code));
		if (preg_match('/^0\d{13}$/', $code)) {
			$code = substr($code, 1);
		}
		return (preg_match('/^[0-9A-Za-z\-]{6,32}$/', $code) ? $code : '');
	}

	/**
	 * Score of a result, to keep the richest: AI > eBay > UPCitemdb with a title > nothing
	 *
	 * @param mixed $info Result
	 * @return int        Score
	 */
	public static function score($info)
	{
		if (!is_array($info)) {
			return -1;
		}
		return (!empty($info['ai']) ? 4 : 0) + (!empty($info['ebay']) ? 2 : 0) + ((string) ($info['title'] ?? '') !== '' ? 1 : 0);
	}

	/**
	 * Is there a scanner table to read its cache from
	 *
	 * @return bool
	 */
	private function hasScanTable()
	{
		if ($this->hasscantable === null) {
			$resql = $this->db->query("SHOW TABLES LIKE '".$this->db->escape(MAIN_DB_PREFIX)."scan_capture'");
			$this->hasscantable = ($resql && $this->db->num_rows($resql) > 0);
		}
		return $this->hasscantable;
	}

	/**
	 * Richest known result for an EAN (cache of the module, else cache of the scanner)
	 *
	 * @param string $ean Normalized EAN
	 * @return ?array<string,mixed> Result, null if never enriched
	 */
	public function getCached($ean)
	{
		global $conf;

		if ($ean === '') {
			return null;
		}
		$best = null;
		$resql = $this->db->query("SELECT info FROM ".MAIN_DB_PREFIX."productenrichment_cache WHERE ean = '".$this->db->escape($ean)."' AND entity = ".((int) $conf->entity));
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			$best = json_decode((string) $obj->info, true);
		}
		if ($this->hasScanTable()) {
			// Separate query, no join: the tables do not share the same collation
			$resql = $this->db->query("SELECT ean_info FROM ".MAIN_DB_PREFIX."scan_capture WHERE ean = '".$this->db->escape($ean)."' AND ean_info IS NOT NULL AND ean_info <> '' ORDER BY rowid DESC LIMIT 20");
			if ($resql) {
				while ($obj = $this->db->fetch_object($resql)) {
					$info = json_decode((string) $obj->ean_info, true);
					if (self::score($info) > self::score($best)) {
						$best = $info;
					}
				}
			}
		}
		return (is_array($best) ? $best : null);
	}

	/**
	 * Save a result into the cache of the module
	 *
	 * @param string              $ean  Normalized EAN
	 * @param array<string,mixed> $info Result
	 * @return void
	 */
	public function saveCache($ean, $info)
	{
		global $conf;

		if ($ean === '' || !is_array($info)) {
			return;
		}
		$sources = implode(',', array_keys(array_filter(array('upc' => !empty($info['upc']) || (string) ($info['title'] ?? '') !== '', 'ebay' => !empty($info['ebay']), 'ai' => !empty($info['ai'])))));
		$json = $this->db->escape(json_encode($info));
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."productenrichment_cache (entity, ean, info, sources, datec)";
		$sql .= " VALUES (".((int) $conf->entity).", '".$this->db->escape($ean)."', '".$json."', '".$this->db->escape($sources)."', '".$this->db->idate(dol_now())."')";
		$sql .= " ON DUPLICATE KEY UPDATE info = '".$json."', sources = '".$this->db->escape($sources)."'";
		if (!$this->db->query($sql)) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_WARNING);
		}
	}

	/**
	 * EAN of a product: its barcode, else the barcode of one of its supplier prices
	 *
	 * @param Product $product Product
	 * @return string          Normalized EAN or ''
	 */
	public function productEan($product)
	{
		$ean = self::normalizeEan((string) $product->barcode);
		if ($ean !== '') {
			return $ean;
		}
		$resql = $this->db->query("SELECT barcode FROM ".MAIN_DB_PREFIX."product_fournisseur_price WHERE fk_product = ".((int) $product->id)." AND barcode IS NOT NULL AND barcode <> '' ORDER BY rowid DESC LIMIT 1");
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return self::normalizeEan((string) $obj->barcode);
		}
		return '';
	}

	/*
	 * Daily quotas, shared with the module scancapture (same constants)
	 */

	/**
	 * Maximum and name of the counter of a source
	 *
	 * @param string $source upc, ebay or ai
	 * @return array{0:string,1:int} Constant name of today, maximum
	 */
	private static function quotaKey($source)
	{
		$day = dol_print_date(dol_now(), '%Y%m%d');
		if ($source == 'ebay') {
			return array('SCANCAPTURE_EBAY_'.$day, (int) getDolGlobalString('SCANCAPTURE_EBAY_DAILY_MAX', '500'));
		}
		if ($source == 'ai') {
			return array('SCANCAPTURE_AI_'.$day, (int) getDolGlobalString('SCANCAPTURE_AI_DAILY_MAX', '200'));
		}
		return array('SCANCAPTURE_UPC_'.$day, self::UPC_DAILY_MAX);
	}

	/**
	 * Use one request of the daily quota of a source
	 *
	 * @param string $source upc, ebay or ai
	 * @return bool          false when the quota of the day is reached
	 */
	private function takeQuota($source)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		list($key, $max) = self::quotaKey($source);
		$count = (int) getDolGlobalString($key);
		if ($count >= $max) {
			return false;
		}
		dolibarr_set_const($this->db, $key, (string) ($count + 1), 'chaine', 0, '', 1);
		return true;
	}

	/**
	 * Use of the quotas today
	 *
	 * @return array<string,array{used:int,max:int}>
	 */
	public static function quotaStatus()
	{
		$out = array();
		foreach (array('upc', 'ebay', 'ai') as $source) {
			list($key, $max) = self::quotaKey($source);
			$out[$source] = array('used' => (int) getDolGlobalString($key), 'max' => $max);
		}
		return $out;
	}

	/**
	 * Which sources are configured
	 *
	 * @return array<string,bool>
	 */
	public static function sourcesConfigured()
	{
		return array(
			'upc' => true,
			'ebay' => (getDolGlobalString('SCANCAPTURE_EBAY_APP_ID') !== '' && getDolGlobalString('SCANCAPTURE_EBAY_CERT_ID') !== ''),
			'ai' => (isModEnabled('ai') && (getDolGlobalString('AI_API_ANTHROPIC_KEY') !== '' || getDolGlobalString('AI_API_SERVICE', 'anthropic') !== 'anthropic')),
		);
	}

	/*
	 * Sources
	 */

	/**
	 * GET or POST with curl
	 *
	 * @param string   $url     Url
	 * @param string[] $headers Headers
	 * @param ?string  $post    Body to post, null for a GET
	 * @param int      $timeout Timeout in seconds
	 * @return array{0:int,1:string} HTTP code, body
	 */
	private function http($url, $headers = array(), $post = null, $timeout = 10)
	{
		$ch = curl_init($url);
		$opts = array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_HTTPHEADER => $headers);
		if ($post !== null) {
			$opts[CURLOPT_POST] = true;
			$opts[CURLOPT_POSTFIELDS] = $post;
		}
		curl_setopt_array($ch, $opts);
		$out = curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		return array($code, (string) $out);
	}

	/**
	 * UPCitemdb (free tier). Port of scancapture/ajax/enrich.php.
	 *
	 * @param string              $ean  EAN
	 * @param array<string,mixed> $prev Known result
	 * @return array{status:string,info:array<string,mixed>} status ok, notfound, quota, error
	 */
	public function lookupUpc($ean, $prev)
	{
		if (!$this->takeQuota('upc')) {
			return array('status' => 'quota', 'info' => $prev);
		}
		list($code, $out) = $this->http('https://api.upcitemdb.com/prod/trial/lookup?upc='.urlencode($ean), array(), null, 5);
		$info = array('title' => '');
		$j = ($out !== '' ? json_decode($out, true) : null);
		if (!empty($j['items'][0])) {
			$it = $j['items'][0];
			$info = array('title' => $it['title'] ?? '', 'brand' => $it['brand'] ?? '', 'category' => $it['category'] ?? '', 'image' => (!empty($it['images'][0]) ? $it['images'][0] : ''), 'desc_courte' => (string) ($it['description'] ?? ''), 'mpn' => (string) (!empty($it['mpn']) ? $it['mpn'] : ($it['model'] ?? '')));
			if (!empty($it['images']) && is_array($it['images'])) {
				$info['images'] = array_slice(array_values($it['images']), 0, 5);
			}
			$specs = array();
			if (!empty($it['color'])) {
				$specs['Couleur'] = $it['color'];
			}
			if (!empty($it['size'])) {
				$specs['Taille'] = $it['size'];
			}
			if (!empty($it['model'])) {
				$specs['Modèle'] = $it['model'];
			}
			if (!empty($it['weight'])) {
				$specs['Poids'] = $it['weight'];
			}
			if (!empty($it['dimension'])) {
				$specs['Dimensions'] = $it['dimension'];
			}
			if ($specs) {
				$info['specs'] = $specs;
			}
			$lo = (float) ($it['lowest_recorded_price'] ?? 0);
			$hi = (float) ($it['highest_recorded_price'] ?? 0);
			if ($lo > 0) {
				$info['prix_marche'] = $lo.($hi > $lo ? ' à '.$hi : '').' '.($it['currency'] ?? 'USD').' (constaté)';
			}
		} elseif ($code != 200 && $code != 404) {
			return array('status' => 'error', 'info' => $prev, 'detail' => 'upc '.$code);
		}
		$info['upc'] = 1;
		$merged = array_merge($info, array_filter((array) $prev, function ($v) {
			return $v !== '' && $v !== null && $v !== array();
		}));
		return array('status' => ((string) $info['title'] !== '' ? 'ok' : 'notfound'), 'info' => $merged);
	}

	/**
	 * eBay Browse API, search by GTIN on EBAY_FR then EBAY_US. Port of scancapture/ajax/enrich_ebay.php.
	 *
	 * @param string              $ean  EAN
	 * @param array<string,mixed> $prev Known result
	 * @return array{status:string,info:array<string,mixed>} status ok, notfound, nokey, quota, error
	 */
	public function lookupEbay($ean, $prev)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		$appid = getDolGlobalString('SCANCAPTURE_EBAY_APP_ID');
		$certid = getDolGlobalString('SCANCAPTURE_EBAY_CERT_ID');
		if ($appid === '' || $certid === '') {
			return array('status' => 'nokey', 'info' => $prev);
		}
		$env = getDolGlobalString('SCANCAPTURE_EBAY_ENV', 'production');
		$apihost = ($env === 'sandbox' ? 'api.sandbox.ebay.com' : 'api.ebay.com');
		$tokkey = 'SCANCAPTURE_EBAY_TOKEN'.($env === 'sandbox' ? '_SBX' : '');
		if (!$this->takeQuota('ebay')) {
			return array('status' => 'quota', 'info' => $prev);
		}

		// OAuth2 client-credentials token, cached ~2h in a constant shared with the scanner
		$token = '';
		$tokraw = getDolGlobalString($tokkey);
		if ($tokraw) {
			$tok = json_decode($tokraw, true);
			if (!empty($tok['t']) && !empty($tok['exp']) && $tok['exp'] > (dol_now() + 60)) {
				$token = $tok['t'];
			}
		}
		if ($token === '') {
			list($code, $out) = $this->http('https://'.$apihost.'/identity/v1/oauth2/token', array('Content-Type: application/x-www-form-urlencoded', 'Authorization: Basic '.base64_encode($appid.':'.$certid)), 'grant_type=client_credentials&scope='.urlencode('https://api.ebay.com/oauth/api_scope'));
			$j = ($out !== '' ? json_decode($out, true) : null);
			if ($code != 200 || empty($j['access_token'])) {
				return array('status' => 'error', 'info' => $prev, 'detail' => 'oauth '.$code);
			}
			$token = $j['access_token'];
			dolibarr_set_const($this->db, $tokkey, json_encode(array('t' => $token, 'exp' => dol_now() + (int) ($j['expires_in'] ?? 7200) - 120)), 'chaine', 0, '', 1);
		}

		list($code, $out) = $this->http('https://'.$apihost.'/buy/browse/v1/item_summary/search?gtin='.urlencode($ean).'&limit=5', array('Authorization: Bearer '.$token, 'X-EBAY-C-MARKETPLACE-ID: EBAY_FR', 'Accept-Language: fr-FR'));
		if ($code != 200) {
			return array('status' => 'error', 'info' => $prev, 'detail' => 'ebay '.$code);
		}
		$j = json_decode($out, true);
		$items = $j['itemSummaries'] ?? array();
		$mkt = 'EBAY_FR';
		if (!count($items)) {
			// Nothing on the FR marketplace: US brands often only list on ebay.com
			list($code2, $out2) = $this->http('https://'.$apihost.'/buy/browse/v1/item_summary/search?gtin='.urlencode($ean).'&limit=5', array('Authorization: Bearer '.$token, 'X-EBAY-C-MARKETPLACE-ID: EBAY_US'));
			$j2 = ($out2 !== '' ? json_decode($out2, true) : null);
			if (!empty($j2['itemSummaries'])) {
				$items = $j2['itemSummaries'];
				$mkt = 'EBAY_US';
			}
		}
		$title = '';
		$titles = $images = $prices = $urls = array();
		foreach ($items as $it) {
			if ($title === '' && !empty($it['title'])) {
				$title = $it['title'];
			}
			if (!empty($it['title']) && !in_array($it['title'], $titles)) {
				$titles[] = $it['title'];
			}
			if (!empty($it['image']['imageUrl'])) {
				$images[] = $it['image']['imageUrl'];
			}
			foreach (($it['additionalImages'] ?? array()) as $ai) {
				if (!empty($ai['imageUrl'])) {
					$images[] = $ai['imageUrl'];
				}
			}
			if (!empty($it['price']['value'])) {
				$prices[] = (float) $it['price']['value'];
			}
			if (!empty($it['itemWebUrl'])) {
				$urls[] = $it['itemWebUrl'];
			}
		}
		$images = array_slice(array_values(array_unique($images)), 0, 5);

		// Item specifics (localizedAspects) of the first listing
		$specs = array();
		$mpn = '';
		if (!empty($items[0]['itemId'])) {
			list($codei, $outi) = $this->http('https://'.$apihost.'/buy/browse/v1/item/'.urlencode($items[0]['itemId']), array('Authorization: Bearer '.$token, 'X-EBAY-C-MARKETPLACE-ID: '.$mkt, 'Accept-Language: fr-FR'));
			$ji = ($outi !== '' ? json_decode($outi, true) : null);
			foreach (($ji['localizedAspects'] ?? array()) as $asp) {
				$an = trim((string) ($asp['name'] ?? ''));
				$av = trim((string) ($asp['value'] ?? ''));
				if ($an === '' || $av === '' || preg_match('/^(EAN|UPC|ISBN|GTIN)$/i', $an)) {
					continue;
				}
				if (preg_match('/^(MPN|Référence fabricant|Numéro de pièce fabricant)$/iu', $an)) {
					$mpn = $av;
					continue;
				}
				if (count($specs) < 15) {
					$specs[$an] = $av;
				}
			}
		}
		$prev = (array) $prev;
		$merged = array_merge($prev, array(
			'ebay' => $env,
			'ebay_v' => 2,
			'title' => ($prev['title'] ?? '') !== '' ? $prev['title'] : $title,
			'titles' => array_slice($titles, 0, 5),
			'images' => !empty($prev['images']) ? $prev['images'] : $images,
			'prix_marche' => $prices ? min($prices).' à '.max($prices).' '.((string) ($items[0]['price']['currency'] ?? 'EUR')).' ('.count($prices).' annonces'.($mkt == 'EBAY_US' ? ', eBay US' : '').')' : ($prev['prix_marche'] ?? ''),
			'sources' => !empty($urls) ? array_slice($urls, 0, 3) : ($prev['sources'] ?? array()),
			'specs' => (!empty($prev['specs']) ? array_merge($specs, (array) $prev['specs']) : $specs),
			'mpn' => ($prev['mpn'] ?? '') !== '' ? $prev['mpn'] : $mpn,
		));
		if (($merged['brand'] ?? '') === '' && !empty($specs['Marque'])) {
			$merged['brand'] = $specs['Marque'];
		}
		return array('status' => (count($items) ? 'ok' : 'notfound'), 'info' => $merged);
	}

	/**
	 * Prompt of the AI web search. Port of scancapture/ajax/enrich_ai.php.
	 *
	 * @param string              $ean     EAN
	 * @param array<string,mixed> $context label (shop label), socid (supplier), fk_product, blind
	 * @return string                      Prompt
	 */
	public function buildAiPrompt($ean, $context)
	{
		$hint = '';
		if (!empty($context['label'])) {
			$hint .= " Contexte magasin : ce produit est connu sous le libellé \"".$context['label']."\" (libellé interne, souvent abrégé — sers-t'en comme indice de marque/modèle).";
		}
		$sql = '';
		if (!empty($context['socid'])) {
			$sql = "SELECT s.nom, s.url FROM ".MAIN_DB_PREFIX."societe as s WHERE s.rowid = ".((int) $context['socid']);
		} elseif (!empty($context['fk_product'])) {
			$sql = "SELECT s.nom, s.url FROM ".MAIN_DB_PREFIX."product_fournisseur_price as pfp INNER JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = pfp.fk_soc WHERE pfp.fk_product = ".((int) $context['fk_product'])." ORDER BY pfp.quantity ASC, pfp.rowid ASC LIMIT 1";
		}
		if ($sql) {
			$resql = $this->db->query($sql);
			if ($resql && ($sup = $this->db->fetch_object($resql))) {
				$hint .= " Fournisseur : ".$sup->nom.(!empty($sup->url) ? " — consulte EN PRIORITE son site ".$sup->url : "").".";
			}
		}
		// Blind re-identification: no shop context, it can be the error itself
		if (!empty($context['blind'])) {
			$hint = " Identifie le produit UNIQUEMENT a partir de son EAN : ne suppose aucune marque ni aucun modele a priori.";
		}
		return "Trouve un maximum d'informations sur ce produit a partir de son code-barres EAN ".$ean." (magasin d'articles de peche francais).".$hint."
Cherche sur le web : l'EAN seul, puis l'EAN avec des mots-cles peche, puis marque+modele une fois identifies (prefixe GS1 = pays, prefixe entreprise = fabricant). Attention : chez beaucoup de fabricants chaque declinaison (coloris, taille, poids...) a son propre EAN, la reference fabricant etant commune — precise l'attribut qui distingue la declinaison (code coloris, taille) quand tu le trouves.
IMPORTANT : fais tes recherches puis reponds avec UNIQUEMENT l'objet JSON ci-dessous — aucun rapport, aucune analyse, aucun texte ni markdown avant ou apres, ta reponse visible EST le JSON :
{\"libelle\": \"nom commercial court en francais avec la valeur d'attribut de la declinaison (coloris/taille)\", \"nom_famille\": \"nom generique du modele SANS valeur d'attribut, tel qu'on nommerait la famille regroupant toutes ses declinaisons, ex 'Sufix Clear Mono 1000m'\", \"marque\": \"...\", \"reference_fabricant\": \"reference/MPN du fabricant + code declinaison si trouve, sinon vide\", \"description_courte\": \"1-2 phrases\", \"description_longue\": \"paragraphe detaille (matiere, usage, points forts, specs)\", \"specs\": {\"cle\": \"valeur\"}, \"prix_public_ttc_eur\": \"prix public conseille ou constate en France, ex 12.90, sinon vide\", \"prix_achat_ht_eur\": \"estimation du prix d'achat revendeur HT, ex 6.50, sinon vide\", \"declinaisons\": [{\"code\": \"code declinaison fabricant\", \"libelle\": \"valeur d'attribut (coloris, taille...)\", \"ean\": \"JAN/EAN de cette declinaison si connu sinon vide\"}], \"declinaison_scannee\": \"code de la declinaison correspondant a l'EAN recherche si determinable, sinon vide\", \"images\": [\"url https directes\"], \"page_fabricant\": \"url de la fiche produit sur le site du fabricant si trouvee, sinon vide\", \"confiance\": \"haute|moyenne|basse\", \"sources\": [\"url\"]}
Le champ declinaisons doit lister TOUTES les declinaisons du modele trouvees sur le site du fabricant (jusqu'a 20).
Si tu n'identifies rien de fiable : {\"libelle\": \"\", \"confiance\": \"basse\"}.";
	}

	/**
	 * Read the JSON answer of the AI (the visible text can contain citations)
	 *
	 * @param string $text Text of the answer
	 * @return ?array<string,mixed> Decoded object, null if none
	 */
	public static function parseAiText($text)
	{
		$info = null;
		$p = strrpos($text, '{"libelle"');
		if ($p === false) {
			$p = strpos($text, '{');
		}
		if ($p !== false) {
			$q = strrpos($text, '}');
			if ($q !== false && $q > $p) {
				$info = json_decode(substr($text, $p, $q - $p + 1), true);
			}
		}
		if (!is_array($info) && preg_match('/\{.*\}/s', $text, $m)) {
			$info = json_decode($m[0], true);
		}
		if (!is_array($info)) {
			return null;
		}
		// Web search citation tags must never reach product data
		foreach (array('libelle', 'nom_famille', 'marque', 'reference_fabricant', 'description_courte', 'description_longue') as $k) {
			if (!empty($info[$k])) {
				$info[$k] = trim(preg_replace('/<\/?cite[^>]*>/', '', (string) $info[$k]));
			}
		}
		return $info;
	}

	/**
	 * Merge an AI answer into a known result
	 *
	 * @param array<string,mixed> $prev Known result
	 * @param array<string,mixed> $info AI answer
	 * @return array<string,mixed>      Merged result
	 */
	public static function mergeAi($prev, $info)
	{
		$prev = (array) $prev;
		$sources = (array) ($info['sources'] ?? array());
		return array_merge($prev, array(
			'ai' => 1,
			'title' => ($info['libelle'] ?? '') !== '' ? $info['libelle'] : ($prev['title'] ?? ''),
			'brand' => ($info['marque'] ?? '') !== '' ? $info['marque'] : ($prev['brand'] ?? ''),
			'mpn' => ($info['reference_fabricant'] ?? '') !== '' ? $info['reference_fabricant'] : ($prev['mpn'] ?? ''),
			'desc_courte' => $info['description_courte'] ?? ($prev['desc_courte'] ?? ''),
			'desc_longue' => $info['description_longue'] ?? ($prev['desc_longue'] ?? ''),
			'specs' => !empty($info['specs']) ? (array) $info['specs'] : ($prev['specs'] ?? array()),
			'prix_public' => (string) ($info['prix_public_ttc_eur'] ?? ($prev['prix_public'] ?? '')),
			'prix_achat' => (string) ($info['prix_achat_ht_eur'] ?? ($prev['prix_achat'] ?? '')),
			'famille' => (string) ($info['nom_famille'] ?? ($prev['famille'] ?? '')),
			'declinaisons' => (array) ($info['declinaisons'] ?? ($prev['declinaisons'] ?? array())),
			'declinaison_scannee' => (string) ($info['declinaison_scannee'] ?? ''),
			'images' => !empty($info['images']) ? (array) $info['images'] : ($prev['images'] ?? array()),
			'page_fabricant' => (string) ($info['page_fabricant'] ?? ($prev['page_fabricant'] ?? '')),
			'confiance' => $info['confiance'] ?? '',
			'sources' => !empty($sources) ? $sources : ($prev['sources'] ?? array()),
		));
	}

	/**
	 * AI web search (on demand only: it is the only source that costs money). Port of scancapture/ajax/enrich_ai.php.
	 *
	 * @param string              $ean     EAN
	 * @param array<string,mixed> $prev    Known result
	 * @param array<string,mixed> $context See buildAiPrompt()
	 * @return array{status:string,info:array<string,mixed>} status ok, notfound, nokey, quota, error
	 */
	public function lookupAi($ean, $prev, $context = array())
	{
		$conf = self::sourcesConfigured();
		if (empty($conf['ai'])) {
			return array('status' => 'nokey', 'info' => $prev);
		}
		if (!$this->takeQuota('ai')) {
			return array('status' => 'quota', 'info' => $prev);
		}
		$prompt = $this->buildAiPrompt($ean, $context);
		$text = '';
		$service = getDolGlobalString('AI_API_SERVICE', 'anthropic');
		$apikey = getDolGlobalString('AI_API_ANTHROPIC_KEY');
		if ($service === 'anthropic' && $apikey !== '') {
			// Direct Messages API call to enable the server-side web_search tool (the Ai class cannot)
			$model = getDolGlobalString('SCANCAPTURE_AI_MODEL', getDolGlobalString('AI_API_ANTHROPIC_MODEL_TEXT', 'claude-sonnet-5'));
			$payload = json_encode(array(
				'model' => $model,
				'max_tokens' => 4000,
				'tools' => array(array('type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => (int) getDolGlobalString('SCANCAPTURE_AI_MAX_SEARCHES', '3'))),
				'messages' => array(array('role' => 'user', 'content' => $prompt)),
			));
			list($code, $out) = $this->http('https://api.anthropic.com/v1/messages', array('Content-Type: application/json', 'x-api-key: '.$apikey, 'anthropic-version: 2023-06-01'), $payload, 90);
			$j = ($out !== '' ? json_decode($out, true) : null);
			if ($code != 200 || empty($j['content'])) {
				return array('status' => 'error', 'info' => $prev, 'detail' => 'ai '.$code);
			}
			foreach ($j['content'] as $block) {
				if (($block['type'] ?? '') === 'text') {
					$text .= $block['text'];
				}
			}
		} else {
			require_once DOL_DOCUMENT_ROOT.'/ai/class/ai.class.php';
			$ai = new Ai($this->db);
			$result = $ai->generateContent($prompt, 'auto', 'textgeneration');
			if (is_array($result) && !empty($result['error'])) {
				return array('status' => 'error', 'info' => $prev, 'detail' => 'ai: '.($result['message'] ?? 'unknown'));
			}
			$text = is_array($result) ? (string) ($result['content'] ?? '') : (string) $result;
		}
		$info = self::parseAiText($text);
		if (!$info) {
			return array('status' => 'error', 'info' => $prev, 'detail' => 'parse');
		}
		$merged = self::mergeAi($prev, $info);
		return array('status' => ((string) ($info['libelle'] ?? '') !== '' ? 'ok' : 'notfound'), 'info' => $merged);
	}

	/**
	 * Cascade UPCitemdb then eBay (sources already in the cache are not called again), then AI if asked
	 *
	 * @param string              $ean     Normalized EAN
	 * @param bool                $withai  Also run the AI web search
	 * @param array<string,mixed> $context See buildAiPrompt()
	 * @return array{info:array<string,mixed>,steps:array<string,string>,stop:bool} stop = a quota is reached
	 */
	public function enrich($ean, $withai = false, $context = array())
	{
		$info = $this->getCached($ean);
		$info = (is_array($info) ? $info : array());
		$steps = array();
		$stop = false;
		$env = getDolGlobalString('SCANCAPTURE_EBAY_ENV', 'production');

		// UPCitemdb: done once any result exists (same rule as the scanner)
		if (empty($info)) {
			$r = $this->lookupUpc($ean, $info);
			$steps['upc'] = $r['status'];
			$info = $r['info'];
			$stop = $stop || ($r['status'] == 'quota');
			if ($r['status'] != 'quota' && $r['status'] != 'error') {
				$this->saveCache($ean, $info);
			}
		} else {
			$steps['upc'] = 'cached';
		}
		// eBay: cache version 2 = with item specifics
		if (!empty($info['ebay']) && $info['ebay'] === $env && (int) ($info['ebay_v'] ?? 0) >= 2) {
			$steps['ebay'] = 'cached';
		} else {
			$r = $this->lookupEbay($ean, $info);
			$steps['ebay'] = $r['status'];
			$info = $r['info'];
			$stop = $stop || ($r['status'] == 'quota');
			if ($r['status'] == 'ok' || $r['status'] == 'notfound') {
				$this->saveCache($ean, $info);
			}
		}
		// AI: only on demand, never twice for the same EAN once it found something
		if ($withai) {
			if (!empty($info['ai']) && (string) ($info['title'] ?? '') !== '') {
				$steps['ai'] = 'cached';
			} else {
				$r = $this->lookupAi($ean, $info, $context);
				$steps['ai'] = $r['status'];
				$info = $r['info'];
				$stop = $stop || ($r['status'] == 'quota');
				if ($r['status'] == 'ok' || $r['status'] == 'notfound') {
					$this->saveCache($ean, $info);
				}
			}
		}
		return array('info' => $info, 'steps' => $steps, 'stop' => $stop);
	}

	/*
	 * Characteristics (module productcharacteristics)
	 */

	/**
	 * Is the module productcharacteristics usable
	 *
	 * @return bool
	 */
	public static function characteristicsEnabled()
	{
		return isModEnabled('productcharacteristics') && file_exists(dol_buildpath('/productcharacteristics/class/productcharacteristic.class.php', 0));
	}

	/**
	 * Attributes usable as characteristics
	 *
	 * @return array<int,string> Label by attribute id
	 */
	public function featureAttributes()
	{
		if (!self::characteristicsEnabled()) {
			return array();
		}
		dol_include_once('/productcharacteristics/class/productcharacteristic.class.php');
		$pc = new ProductCharacteristic($this->db);
		$attributes = $pc->fetchFeatureAttributes();
		$out = array();
		if (is_array($attributes)) {
			foreach ($attributes as $id => $a) {
				$out[(int) $id] = ($a['label'] !== '' ? $a['label'] : $a['ref']);
			}
		}
		return $out;
	}

	/**
	 * Words of a name of attribute or of characteristic, to compare them: lower case without accents, French
	 * and English synonyms of the enrichment sources, without empty words nor units, plural removed.
	 * "Profondeur de nage en mètre" and "maximum diving depth" both give profondeur, nage.
	 *
	 * @param string $s Name
	 * @return string[] Words
	 */
	public static function nameWords($s)
	{
		$s = strtolower(dol_string_unaccent((string) $s));
		$s = trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', $s)));
		// Whole names first (eBay names are English, AI names are French)
		$phrases = array(
			'maximum diving depth' => 'profondeur nage', 'diving depth' => 'profondeur nage', 'running depth' => 'profondeur nage', 'profondeur' => 'profondeur nage',
			'breaking strain' => 'resistance', 'breaking strength' => 'resistance', 'line strength' => 'resistance', 'resistance' => 'resistance',
			'line thickness' => 'diametre', 'line diameter' => 'diametre', 'diameter' => 'diametre',
			'casting weight' => 'plage lance', 'lure weight' => 'plage lance', 'puissance lancer' => 'plage lance', 'poids de lancer' => 'plage lance', 'poids lancer' => 'plage lance', 'plage de lancer' => 'plage lance',
			'gear ratio' => 'ratio', 'line retrieve' => 'recuperation', 'retrieve' => 'recuperation', 'max drag' => 'frein', 'drag' => 'frein', 'frein max' => 'frein',
			'ball bearings' => 'roulements', 'bearings' => 'roulements', 'sections' => 'brins', 'number of sections' => 'brins', 'pieces' => 'brins',
			'puissance lbs' => 'puissance livres', 'line rating' => 'puissance livres', 'transport length' => 'encombrement',
			'material' => 'matiere', 'materiau' => 'matiere', 'materiaux' => 'matiere', 'buoyancy' => 'densite', 'flottabilite' => 'densite', 'flottaison' => 'densite',
			'sound' => 'sonorite', 'bruit' => 'sonorite', 'finish' => 'finition', 'brand' => 'marque',
			'couleur' => 'coloris', 'color' => 'coloris', 'colour' => 'coloris', 'size' => 'taille', 'weight' => 'poids', 'item weight' => 'poids', 'length' => 'longueur', 'item length' => 'longueur', 'model' => 'modele',
		);
		if (isset($phrases[$s])) {
			$s = $phrases[$s];
		}
		$empty = array('de', 'du', 'des', 'd', 'en', 'la', 'le', 'les', 'l', 'a', 'au', 'aux', 'et', 'max', 'maximum', 'mm', 'cm', 'm', 'metre', 'metres', 'mtr', 'g', 'gr', 'grs', 'gramme', 'grammes', 'kg', 'lb');
		$words = array();
		foreach (explode(' ', $s) as $w) {
			if ($w === '' || in_array($w, $empty)) {
				continue;
			}
			if (strlen($w) > 3 && substr($w, -1) == 's') {
				$w = substr($w, 0, -1);
			}
			$words[] = $w;
		}
		return array_values(array_unique($words));
	}

	/**
	 * Characteristics found (and brand) matched with the attributes usable as characteristics: same words, else
	 * all the words of one in the other ("brins" finds "Nombre de brins")
	 *
	 * @param array<string,mixed> $info       Result
	 * @param ?array<int,string>  $attributes Attributes, null to load them
	 * @return array<int,array{key:string,value:string,fk_attribute:int,attribute:string}>
	 */
	public function mapSpecs($info, $attributes = null)
	{
		if ($attributes === null) {
			$attributes = $this->featureAttributes();
		}
		$attrwords = array();
		foreach ($attributes as $id => $label) {
			$attrwords[$id] = self::nameWords($label);
		}
		$specs = (array) ($info['specs'] ?? array());
		if (!empty($info['brand']) && !isset($specs['Marque'])) {
			$specs = array('Marque' => $info['brand']) + $specs;
		}
		$out = array();
		foreach ($specs as $key => $value) {
			if (!is_scalar($value) || trim((string) $value) === '' || trim((string) $key) === '') {
				continue;
			}
			$kw = self::nameWords(str_replace('_', ' ', (string) $key));
			$found = 0;
			$best = 0;
			foreach ($attrwords as $id => $aw) {
				if (!count($kw) || !count($aw)) {
					continue;
				}
				$score = 0;
				if (!array_diff($kw, $aw) && !array_diff($aw, $kw)) {
					$score = 100;
				} elseif (!array_diff($kw, $aw)) {
					$score = 50 + count($kw);
				} elseif (!array_diff($aw, $kw)) {
					$score = 40 + count($aw);
				}
				if ($score > $best) {
					$best = $score;
					$found = $id;
				}
			}
			// Keys of the AI are technical (puissance_lancer): shown as "Puissance lancer"
			$label = ucfirst(trim(str_replace('_', ' ', (string) $key)));
			$out[] = array('key' => $label, 'value' => trim((string) $value), 'fk_attribute' => $found, 'attribute' => ($found ? $attributes[$found] : ''));
		}
		return $out;
	}

	/**
	 * Attributes already set as characteristics on a product
	 *
	 * @param int $fk_product Product id
	 * @return int[]          Attribute ids
	 */
	public function productCharacteristicAttributes($fk_product)
	{
		if (!self::characteristicsEnabled()) {
			return array();
		}
		$out = array();
		$resql = $this->db->query("SELECT DISTINCT fk_product_attribute FROM ".MAIN_DB_PREFIX."productcharacteristics_value WHERE fk_product = ".((int) $fk_product));
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$out[] = (int) $obj->fk_product_attribute;
			}
		}
		return $out;
	}

	/**
	 * Set a characteristic on a product (the value of the attribute is created when needed)
	 *
	 * @param User   $user         User
	 * @param int    $fk_product   Product id
	 * @param int    $fk_attribute Attribute id
	 * @param string $value        Value
	 * @return int                 1 if set, 0 if already set, <0 if KO
	 */
	public function addCharacteristic($user, $fk_product, $fk_attribute, $value)
	{
		if (!self::characteristicsEnabled() || !array_key_exists((int) $fk_attribute, $this->featureAttributes())) {
			return -1;
		}
		dol_include_once('/productcharacteristics/class/productcharacteristic.class.php');
		$pc = new ProductCharacteristic($this->db);
		$fk_value = $pc->findOrCreateValue($user, (int) $fk_attribute, dol_trunc((string) $value, 250, 'right', 'UTF-8', 1));
		if ($fk_value <= 0) {
			$this->error = $pc->error;
			return -1;
		}
		return $pc->addValue($user, (int) $fk_product, $fk_value);
	}

	/*
	 * Photos
	 */

	/**
	 * Images of the result (only https)
	 *
	 * @param array<string,mixed> $info Result
	 * @return string[]                 Urls
	 */
	public static function infoImages($info)
	{
		$urls = (array) ($info['images'] ?? array());
		if (!empty($info['image'])) {
			array_unshift($urls, $info['image']);
		}
		$out = array();
		foreach ($urls as $u) {
			if (is_string($u) && preg_match('/^https:\/\//i', $u) && !in_array($u, $out)) {
				$out[] = $u;
			}
		}
		return array_slice($out, 0, 8);
	}

	/**
	 * Directory of the photos of a product
	 *
	 * @param Product $product Product
	 * @return string          Directory
	 */
	public static function productPhotoDir($product)
	{
		global $conf;
		$entity = (!empty($product->entity) ? $product->entity : $conf->entity);
		return $conf->product->multidir_output[$entity].'/'.dol_sanitizeFileName($product->ref);
	}

	/**
	 * Number of photos of a product
	 *
	 * @param Product $product Product
	 * @return int             Number of photos
	 */
	public static function countPhotos($product)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
		$dir = self::productPhotoDir($product);
		if (!is_dir($dir)) {
			return 0;
		}
		return count(dol_dir_list($dir, 'files', 0, '\.(jpe?g|png|gif|webp)$'));
	}

	/**
	 * Download photos into the directory of a product: https only, public hosts only, real images only
	 *
	 * @param Product  $product Product
	 * @param string[] $urls    Urls
	 * @return int              Number of photos saved
	 */
	public function downloadPhotos($product, $urls)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
		$dir = self::productPhotoDir($product);
		dol_mkdir($dir);
		$n = 0;
		foreach (array_slice((array) $urls, 0, (int) getDolGlobalString('PRODUCTENRICHMENT_MAX_IMAGES', (string) self::MAX_IMAGES)) as $u) {
			if (!preg_match('/^https:\/\//i', (string) $u)) {
				continue;
			}
			// Never let a result make the server fetch a private address
			$host = (string) parse_url($u, PHP_URL_HOST);
			$ip = ($host !== '' ? gethostbyname($host) : '');
			if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
				continue;
			}
			$ch = curl_init($u);
			curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'Mozilla/5.0'));
			$data = curl_exec($ch);
			$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);
			if (!$data || $code >= 400 || strlen($data) < 2000 || strlen($data) > 8000000) {
				continue;
			}
			$size = @getimagesizefromstring($data);
			$exts = array(IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp');
			if (!$size || empty($exts[$size[2]])) {
				continue;
			}
			$fn = $dir.'/'.dol_sanitizeFileName($product->ref.'-enrich-'.($n + 1).'-'.substr(md5($u), 0, 6).'.'.$exts[$size[2]]);
			if (file_put_contents($fn, $data)) {
				@chmod($fn, 0664);
				$n++;
			}
		}
		return $n;
	}

	/*
	 * Proposals and application to a product
	 */

	/**
	 * First number of a price text ("12,90 €", "12.90", "12 à 15 EUR")
	 *
	 * @param mixed $text Price text
	 * @return float      Price, 0 if none
	 */
	public static function parsePrice($text)
	{
		if (preg_match('/(\d+(?:[.,]\d{1,2})?)/', (string) $text, $m)) {
			return (float) str_replace(',', '.', $m[1]);
		}
		return 0;
	}

	/**
	 * Description proposed: long description, else short one
	 *
	 * @param array<string,mixed> $info Result
	 * @return string                   Description
	 */
	public static function proposedDescription($info)
	{
		$desc = trim((string) ($info['desc_longue'] ?? ''));
		if ($desc === '') {
			$desc = trim((string) ($info['desc_courte'] ?? ''));
		}
		return $desc;
	}

	/**
	 * Manufacturer page proposed: page found by the AI, else first source that is not an eBay listing
	 *
	 * @param array<string,mixed> $info Result
	 * @return string                   Url
	 */
	public static function proposedManufacturerPage($info)
	{
		$page = trim((string) ($info['page_fabricant'] ?? ''));
		if (preg_match('/^https?:\/\//i', $page)) {
			return $page;
		}
		foreach ((array) ($info['sources'] ?? array()) as $u) {
			if (is_string($u) && preg_match('/^https?:\/\//i', $u) && !preg_match('/ebay\./i', $u)) {
				return $u;
			}
		}
		return '';
	}

	/**
	 * Is a barcode already used by another product
	 *
	 * @param string $ean        Barcode
	 * @param int    $fk_product Product to ignore
	 * @return string            Ref of the other product, '' if free
	 */
	public function barcodeUsedBy($ean, $fk_product)
	{
		$resql = $this->db->query("SELECT ref FROM ".MAIN_DB_PREFIX."product WHERE barcode = '".$this->db->escape($ean)."' AND rowid <> ".((int) $fk_product)." AND entity IN (".getEntity('product').") LIMIT 1");
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return (string) $obj->ref;
		}
		return '';
	}

	/**
	 * What can be applied to an existing product: only its EMPTY fields
	 *
	 * @param Product             $product Product (with its extrafields fetched)
	 * @param array<string,mixed> $info    Result
	 * @param string              $ean     EAN looked up
	 * @return array<string,array{proposed:mixed,current:string,applicable:bool,why:string}>
	 */
	public function proposalsForProduct($product, $info, $ean)
	{
		$p = array();
		$desc = self::proposedDescription($info);
		$cur = trim(dol_string_nohtmltag((string) $product->description));
		$p['description'] = array('proposed' => $desc, 'current' => $cur, 'applicable' => ($desc !== '' && $cur === ''), 'why' => ($cur !== '' ? 'filled' : ($desc === '' ? 'none' : '')));

		$mpn = trim((string) ($info['mpn'] ?? ''));
		$cur = trim((string) ($product->array_options['options_ref_marque'] ?? ''));
		$p['ref_marque'] = array('proposed' => $mpn, 'current' => $cur, 'applicable' => ($mpn !== '' && $cur === '' && $this->extrafieldExists('ref_marque')), 'why' => ($cur !== '' ? 'filled' : ($mpn === '' ? 'none' : '')));

		$page = self::proposedManufacturerPage($info);
		$cur = trim((string) ($product->array_options['options_supplier_url'] ?? ''));
		$p['supplier_url'] = array('proposed' => $page, 'current' => $cur, 'applicable' => ($page !== '' && $cur === '' && $this->extrafieldExists('supplier_url')), 'why' => ($cur !== '' ? 'filled' : ($page === '' ? 'none' : '')));

		$cur = trim((string) $product->barcode);
		$isean = (bool) preg_match('/^(\d{8}|\d{13})$/', $ean);
		$usedby = ($isean && $cur === '' ? $this->barcodeUsedBy($ean, $product->id) : '');
		$p['barcode'] = array('proposed' => $ean, 'current' => $cur, 'applicable' => ($isean && $cur === '' && $usedby === ''), 'why' => ($cur !== '' ? 'filled' : ($usedby !== '' ? 'usedby:'.$usedby : (!$isean ? 'notean' : ''))));

		$price = self::parsePrice($info['prix_public'] ?? '');
		$curprice = (getDolGlobalString('PRODUIT_MULTIPRICES') ? (float) ($product->multiprices_ttc[1] ?? 0) : (float) $product->price_ttc);
		$p['sell_price'] = array('proposed' => ($price > 0 ? price2num($price, 'MU') : ''), 'current' => ($curprice > 0 ? price($curprice) : ''), 'applicable' => ($price > 0 && $curprice <= 0), 'why' => ($curprice > 0 ? 'filled' : ($price <= 0 ? 'none' : '')));

		$images = self::infoImages($info);
		$nbphotos = self::countPhotos($product);
		$p['images'] = array('proposed' => $images, 'current' => ($nbphotos ? (string) $nbphotos : ''), 'applicable' => (count($images) > 0 && $nbphotos == 0), 'why' => ($nbphotos ? 'filled' : (!count($images) ? 'none' : '')));

		$specs = $this->mapSpecs($info);
		$already = $this->productCharacteristicAttributes($product->id);
		foreach ($specs as $k => $s) {
			$specs[$k]['already'] = ($s['fk_attribute'] && in_array($s['fk_attribute'], $already));
		}
		$p['characteristics'] = array('proposed' => $specs, 'current' => '', 'applicable' => self::characteristicsEnabled() && count($specs) > 0, 'why' => (!self::characteristicsEnabled() ? 'nomodule' : ''));

		return $p;
	}

	/**
	 * Does a product extrafield exist
	 *
	 * @param string $name Name of the extrafield
	 * @return bool
	 */
	public function extrafieldExists($name)
	{
		static $cache = array();
		if (!isset($cache[$name])) {
			$resql = $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."extrafields WHERE elementtype = 'product' AND name = '".$this->db->escape($name)."'");
			$cache[$name] = ($resql && $this->db->num_rows($resql) > 0);
		}
		return $cache[$name];
	}

	/**
	 * Apply a result to the EMPTY fields of an existing product (each field is checked again here)
	 *
	 * @param User                $user       User
	 * @param Product             $product    Product
	 * @param array<string,mixed> $info       Result
	 * @param string              $ean        EAN looked up
	 * @param string[]            $selection  Keys to apply: description, ref_marque, supplier_url, barcode, sell_price, images, characteristics
	 * @param ?array<int,array{key:string,fk_attribute:int,value:string}> $chosenspecs Characteristics chosen by the user, null = the automatic matching
	 * @param ?string[]           $chosenimages Photos chosen by the user, null = the first ones
	 * @param string              $origin     card, mass or form
	 * @param bool                $writejournal Write a line in the journal (the queue updates its own line)
	 * @return array{applied:array<string,mixed>,errors:string[]}
	 */
	public function applyToProduct($user, $product, $info, $ean, $selection, $chosenspecs = null, $chosenimages = null, $origin = 'card', $writejournal = true)
	{
		$product->fetch_optionals();
		$proposals = $this->proposalsForProduct($product, $info, $ean);
		$applied = array();
		$errors = array();
		$modified = false;

		// Characteristics that found no attribute go to the description, when it is written now
		$specs = ($chosenspecs !== null ? $chosenspecs : array());
		if ($chosenspecs === null) {
			foreach ($proposals['characteristics']['proposed'] as $s) {
				if ($s['fk_attribute'] && empty($s['already'])) {
					$specs[] = array('key' => $s['key'], 'fk_attribute' => $s['fk_attribute'], 'value' => $s['value']);
				}
			}
		}

		if (in_array('description', $selection) && $proposals['description']['applicable']) {
			$desc = $proposals['description']['proposed'];
			if (getDolGlobalString('PRODUCTENRICHMENT_SPECS_IN_DESC', '1')) {
				// Characteristics that will not be set as characteristics are written in the description
				$mappedkeys = array();
				if (in_array('characteristics', $selection) && $proposals['characteristics']['applicable']) {
					foreach ($specs as $s) {
						if ((int) ($s['fk_attribute'] ?? 0) > 0) {
							$mappedkeys[] = (string) ($s['key'] ?? '');
						}
					}
				}
				$lines = array();
				foreach ($proposals['characteristics']['proposed'] as $s) {
					if (!in_array($s['key'], $mappedkeys)) {
						$lines[] = $s['key'].' : '.$s['value'];
					}
				}
				if ($lines) {
					$desc .= "\n\n".implode("\n", $lines);
				}
			}
			if (getDolGlobalString('FCKEDITOR_ENABLE_PRODUCTDESC')) {
				$desc = dol_nl2br(dol_escape_htmltag($desc));
			}
			if ($product->setValueFrom('description', $desc, '', null, 'text', '', $user) > 0) {
				$applied['description'] = dol_trunc($desc, 80);
				$modified = true;
			} else {
				$errors[] = 'description: '.$product->error;
			}
		}

		if (in_array('barcode', $selection) && $proposals['barcode']['applicable']) {
			$ok = ($product->setValueFrom('barcode', $ean, '', null, 'text', '', $user) > 0);
			if ($ok && empty($product->barcode_type)) {
				$product->setValueFrom('fk_barcode_type', (strlen($ean) == 8 ? 1 : getDolGlobalInt('PRODUIT_DEFAULT_BARCODE_TYPE', 2)), '', null, 'int', '', $user);
			}
			if ($ok) {
				$applied['barcode'] = $ean;
				$modified = true;
			} else {
				$errors[] = 'barcode: '.$product->error;
			}
		}

		$extra = false;
		foreach (array('ref_marque', 'supplier_url') as $key) {
			if (in_array($key, $selection) && $proposals[$key]['applicable']) {
				$product->array_options['options_'.$key] = dol_trunc((string) $proposals[$key]['proposed'], 250, 'right', 'UTF-8', 1);
				$applied[$key] = $product->array_options['options_'.$key];
				$extra = true;
			}
		}
		if ($extra) {
			if ($product->insertExtraFields('', $user) < 0) {
				$errors[] = 'extrafields: '.$product->error;
				unset($applied['ref_marque'], $applied['supplier_url']);
			} else {
				$modified = true;
			}
		}

		if (in_array('sell_price', $selection) && $proposals['sell_price']['applicable']) {
			$level = (getDolGlobalString('PRODUIT_MULTIPRICES') ? 1 : 0);
			if ($product->updatePrice((float) $proposals['sell_price']['proposed'], 'TTC', $user, $product->tva_tx, 0, $level) > 0) {
				$applied['sell_price'] = $proposals['sell_price']['proposed'];
			} else {
				$errors[] = 'sell_price: '.$product->error;
			}
		}

		if (in_array('images', $selection) && $proposals['images']['applicable']) {
			$allowed = $proposals['images']['proposed'];
			$urls = ($chosenimages !== null ? array_values(array_intersect((array) $chosenimages, $allowed)) : $allowed);
			$n = $this->downloadPhotos($product, $urls);
			if ($n) {
				$applied['images'] = $n;
			}
		}

		if (in_array('characteristics', $selection) && $proposals['characteristics']['applicable']) {
			$already = $this->productCharacteristicAttributes($product->id);
			$n = 0;
			foreach ($specs as $s) {
				$fk = (int) ($s['fk_attribute'] ?? 0);
				if ($fk <= 0 || in_array($fk, $already) || trim((string) ($s['value'] ?? '')) === '') {
					continue;
				}
				$r = $this->addCharacteristic($user, $product->id, $fk, (string) $s['value']);
				if ($r > 0) {
					$n++;
					$already[] = $fk;
				} elseif ($r < 0) {
					$errors[] = 'characteristic: '.$this->error;
				}
			}
			if ($n) {
				$applied['characteristics'] = $n;
			}
		}

		// One modification event for the synchronisation modules (the price has its own event)
		if ($modified) {
			$product->fetch($product->id);
			$product->call_trigger('PRODUCT_MODIFY', $user);
		}
		if ($writejournal) {
			$this->journal($user, $product->id, $ean, $origin, (count($applied) ? 'done' : 'nothing'), array(), $applied, implode(' ; ', $errors));
		}

		return array('applied' => $applied, 'errors' => $errors);
	}

	/*
	 * Journal and queue
	 */

	/**
	 * Write a line of the journal
	 *
	 * @param User                $user       User
	 * @param int                 $fk_product Product
	 * @param string              $ean        EAN
	 * @param string              $origin     card, mass, form
	 * @param string              $status     Status
	 * @param array<string,mixed> $options    Options
	 * @param array<string,mixed> $applied    Fields applied
	 * @param string              $message    Message
	 * @return int                            Id of the line
	 */
	public function journal($user, $fk_product, $ean, $origin, $status, $options = array(), $applied = array(), $message = '')
	{
		global $conf;
		$now = $this->db->idate(dol_now());
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."productenrichment_job (entity, fk_product, ean, origin, status, options, applied, message, datec, date_done, fk_user_creat)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $fk_product).", '".$this->db->escape($ean)."', '".$this->db->escape($origin)."', '".$this->db->escape($status)."',";
		$sql .= " '".$this->db->escape(json_encode($options))."', '".$this->db->escape(json_encode($applied))."', '".$this->db->escape(dol_trunc($message, 2000))."', '".$now."', ".($status == 'pending' ? 'NULL' : "'".$now."'").", ".((int) $user->id).")";
		if (!$this->db->query($sql)) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_WARNING);
			return -1;
		}
		return (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'productenrichment_job');
	}

	/**
	 * Put products in the queue of the mass enrichment (a product already waiting is not added twice)
	 *
	 * @param User                $user    User
	 * @param int[]               $ids     Product ids
	 * @param array<string,mixed> $options ai (bool), fields (string[])
	 * @return int                         Number of products added
	 */
	public function enqueue($user, $ids, $options)
	{
		global $conf;
		$pending = array();
		$resql = $this->db->query("SELECT fk_product FROM ".MAIN_DB_PREFIX."productenrichment_job WHERE status = 'pending' AND entity = ".((int) $conf->entity));
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$pending[(int) $obj->fk_product] = 1;
			}
		}
		$n = 0;
		foreach (array_unique(array_map('intval', (array) $ids)) as $id) {
			if ($id > 0 && empty($pending[$id])) {
				if ($this->journal($user, $id, '', 'mass', 'pending', $options) > 0) {
					$n++;
				}
			}
		}
		return $n;
	}

	/**
	 * Number of products waiting in the queue
	 *
	 * @return int
	 */
	public function countPending()
	{
		global $conf;
		$resql = $this->db->query("SELECT COUNT(*) as n FROM ".MAIN_DB_PREFIX."productenrichment_job WHERE status = 'pending' AND entity = ".((int) $conf->entity));
		$obj = ($resql ? $this->db->fetch_object($resql) : null);
		return ($obj ? (int) $obj->n : 0);
	}

	/**
	 * Process the oldest product of the queue
	 *
	 * @param User $user User
	 * @return array{status:string,fk_product:int,message:string,remaining:int} status empty when the queue is empty, quota when a daily limit stops the queue
	 */
	public function processNext($user)
	{
		global $conf;
		require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

		$resql = $this->db->query("SELECT rowid, fk_product, options FROM ".MAIN_DB_PREFIX."productenrichment_job WHERE status = 'pending' AND entity = ".((int) $conf->entity)." ORDER BY rowid ASC LIMIT 1");
		$job = ($resql ? $this->db->fetch_object($resql) : null);
		if (!$job) {
			return array('status' => 'empty', 'fk_product' => 0, 'message' => '', 'remaining' => 0);
		}
		$options = json_decode((string) $job->options, true);
		$options = (is_array($options) ? $options : array());
		$fields = (!empty($options['fields']) ? (array) $options['fields'] : array('description', 'ref_marque', 'supplier_url', 'barcode', 'images', 'characteristics'));
		$fields = array_values(array_diff($fields, array('sell_price')));	// A mass enrichment never sets a price

		$product = new Product($this->db);
		$status = 'error';
		$message = '';
		$applied = array();
		$ean = '';
		if ($product->fetch((int) $job->fk_product) <= 0) {
			$message = 'product not found';
		} else {
			$ean = $this->productEan($product);
			if ($ean === '') {
				$status = 'noean';
			} else {
				$r = $this->enrich($ean, !empty($options['ai']), array('label' => $product->label, 'fk_product' => $product->id));
				if ($r['stop'] && self::score($r['info']) <= 0) {
					// A daily quota stops the queue: the product stays waiting
					return array('status' => 'quota', 'fk_product' => (int) $product->id, 'message' => json_encode($r['steps']), 'remaining' => $this->countPending());
				}
				if (self::score($r['info']) <= 0 || (string) ($r['info']['title'] ?? '') === '') {
					$status = 'notfound';
					$message = json_encode($r['steps']);
				} else {
					$res = $this->applyToProduct($user, $product, $r['info'], $ean, $fields, null, null, 'mass', false);
					$applied = $res['applied'];
					$message = implode(' ; ', $res['errors']);
					$status = (count($applied) ? 'done' : 'nothing');
				}
			}
		}
		$sql = "UPDATE ".MAIN_DB_PREFIX."productenrichment_job SET status = '".$this->db->escape($status)."', ean = '".$this->db->escape($ean)."',";
		$sql .= " applied = '".$this->db->escape(json_encode($applied))."', message = '".$this->db->escape(dol_trunc($message, 2000))."', date_done = '".$this->db->idate(dol_now())."'";
		$sql .= " WHERE rowid = ".((int) $job->rowid);
		$this->db->query($sql);

		return array('status' => $status, 'fk_product' => (int) $job->fk_product, 'message' => $message, 'remaining' => $this->countPending());
	}

	/**
	 * Scheduled job: process a batch of the queue, stops at the first daily quota reached
	 *
	 * @return int 0 if OK
	 */
	public function runQueue()
	{
		global $user;
		$batch = max(1, (int) getDolGlobalString('PRODUCTENRICHMENT_CRON_BATCH', '10'));
		$counts = array();
		for ($i = 0; $i < $batch; $i++) {
			$r = $this->processNext($user);
			if ($r['status'] == 'empty' || $r['status'] == 'quota') {
				$counts[$r['status']] = 1;
				break;
			}
			$counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
		}
		$this->output = 'Enrichment queue: '.json_encode($counts).', remaining '.$this->countPending();
		return 0;
	}
}
