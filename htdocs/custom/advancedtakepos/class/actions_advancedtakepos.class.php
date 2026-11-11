<?php
/* Copyright (C) 2026 Jose Martinez <jose.martinez@pichinov.com>
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
 * \file    class/actions_advancedtakepos.class.php
 * \ingroup advancedtakepos
 * \brief   Hooks TakePOS : stock, prix public TTC + %, largeur produits, niveau de prix, police.
 *
 * Reproduit par hooks le rendu du port coeur (commit 45491d4, "NEW TakePOS can show
 * the stock and the public price"), sans modifier le coeur, plus quelques extras Serious
 * (% d'ecart, niveau de prix dans le titre, police reduite, pleine largeur sans categories).
 */

/**
 * Class ActionsAdvancedTakepos
 */
class ActionsAdvancedTakepos
{
	/** @var string Lu par le HookManager (PHP 8.2 : plus de propriete dynamique) */
	public $error = '';
	/** @var string[] Idem */
	public $warnings = array();
	/** @var DoliDB */
	public $db;
	/** @var string Lu par HookManager::executeHooks() (hooks 'output') */
	public $resprints;
	/** @var array<string,mixed> Lu par HookManager::executeHooks() pour fusionner dans resArray */
	public $results = array();
	/** @var string[] */
	public $errors = array();

	/** @var array<int,float|null> Cache du prix TTC de reference par produit (evite une requete par ligne) */
	private static $refpricecache = array();

	/** @var array<int,int> Cache niveau de prix par tiers (evite un fetch par ligne) */
	private static $levelcache = array();

	/** Nombre de produits au-dela duquel un mot de la recherche etendue reste en sous-requete */
	const EXTENDED_SEARCH_MAX_IDS = 5000;

	/** @var int[] Produits trouves par un code secondaire pour la recherche en cours (printFieldListWhere) */
	private $altBarcodeIds = array();

	/** @var string Code cherche quand il a trouve des produits par un code secondaire */
	private $altBarcodeTerm = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->overrideTerminalSettings();
	}

	/**
	 * Reglages propres a CET appareil et a CETTE caisse, poses en memoire pour la requete en cours.
	 *
	 * Le coeur ne connait qu'une valeur globale de TAKEPOS_NB_MAXPRODUCT et de TAKEPOS_ROOT_CATEGORY_ID, et
	 * takepos/index.php les lit juste apres initHooks('takeposfrontend'), sans hook entre les deux : on les
	 * surcharge donc a l'instanciation de la classe, dans $conf->global seulement (rien n'est ecrit en base).
	 * - taille de grille : cookie advtpgrid pose par le JS selon la classe d'appareil, sur toutes les pages
	 *   TakePOS (le CSS du pager, rendu aussi par invoice.php, doit suivre la meme valeur) ;
	 * - categorie racine : ADVANCEDTAKEPOS_ROOT_CATEGORY_ID<n> de la caisse n, sur l'ecran de caisse
	 *   seulement (la recherche de ajax.php reste ouverte a tout le catalogue).
	 *
	 * @return void
	 */
	private function overrideTerminalSettings()
	{
		global $conf;

		$script = isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '';
		if (strpos($script, '/takepos/') === false) {
			return;
		}
		$gridmanaged = getDolGlobalInt('ADVANCEDTAKEPOS_MAXPRODUCT_MOBILE') > 0 || getDolGlobalInt('ADVANCEDTAKEPOS_MAXPRODUCT_TABLET') > 0 || getDolGlobalInt('ADVANCEDTAKEPOS_MAXPRODUCT_DESKTOP') > 0;
		$grid = isset($_COOKIE['advtpgrid']) ? (int) $_COOKIE['advtpgrid'] : 0;
		if ($gridmanaged && $grid >= 6 && $grid <= 60) {
			$conf->global->TAKEPOS_NB_MAXPRODUCT = $grid;
		}
		if (!preg_match('/\/takepos\/index\.php$/', $script)) {
			return;
		}
		// Meme ordre que takepos/index.php : terminal demande, sinon celui de la session, sinon le cookie.
		$term = GETPOSTINT('setterminal');
		if ($term <= 0 && !empty($_SESSION['takeposterminal'])) {
			$term = (int) $_SESSION['takeposterminal'];
		}
		if ($term <= 0 && !empty($_COOKIE['takeposterminal'])) {
			$term = (int) $_COOKIE['takeposterminal'];
		}
		$root = $term > 0 ? getDolGlobalInt('ADVANCEDTAKEPOS_ROOT_CATEGORY_ID'.$term) : 0;
		if ($root > 0) {
			$conf->global->TAKEPOS_ROOT_CATEGORY_ID = $root;
		}
	}

	/**
	 * Niveau de prix du CLIENT de la vente en cours.
	 *
	 * En contexte ajax, le client courant est passe via thirdpartyid (il surcharge le
	 * client par defaut du terminal) ; sinon on retombe sur le client par defaut du terminal.
	 * C'est ce niveau qui gouverne les prix affiches, donc c'est lui qu'il faut refleter.
	 *
	 * @return int Niveau de prix (>=1)
	 */
	private function getCustomerLevel()
	{
		$socid = GETPOSTINT('thirdpartyid');
		if ($socid <= 0) {
			$socid = $this->terminalDefaultSocid();
		}
		return $this->priceLevelOfSocid($socid);
	}

	/**
	 * Niveau de prix servant de reference "prix public" (prix affiche a cote et % d'ecart).
	 * Par defaut le niveau 1 ; configurable par ADVANCEDTAKEPOS_PUBLIC_PRICE_LEVEL quand le prix
	 * public conseille ne vit pas sur le niveau 1. Borne au nombre de niveaux actifs.
	 *
	 * @return int niveau de prix (>= 1)
	 */
	private function publicPriceLevel()
	{
		$level = getDolGlobalInt('ADVANCEDTAKEPOS_PUBLIC_PRICE_LEVEL', 1);
		$max = getDolGlobalInt('PRODUIT_MULTIPRICES_LIMIT');
		if ($max < 1) {
			$max = 1;
		}
		if ($level < 1 || $level > $max) {
			$level = 1;
		}
		return $level;
	}

	/**
	 * Hook completeTakePosAddLine : facturer la ligne au prix de reference (le PPC) et porter
	 * l'ecart en remise, pour que le ticket lise "PPC - remise = net" au lieu du seul net.
	 * Le montant net est rigoureusement inchange : seule la presentation change, et les
	 * statistiques de remise deviennent exploitables.
	 *
	 * Le coeur (takepos/invoice.php) remplace sa ligne par notre tableau quand le hook rend 0
	 * et que resArray n'est pas vide ; on renseigne donc $this->results.
	 *
	 * @param  array<string,mixed>  $parameters  Contient 'prod' et 'line'
	 * @param  CommonObject         $object      La facture en cours
	 * @param  string               $action      Action
	 * @param  HookManager          $hookmanager Gestionnaire de hooks
	 * @return int                               0 (on laisse le coeur ajouter la ligne)
	 */
	public function completeTakePosAddLine($parameters, &$object, &$action, $hookmanager)
	{
		if (getDolGlobalInt('ADVANCEDTAKEPOS_LINE_AT_PUBLIC_PRICE') != 1 && getDolGlobalInt('ADVANCEDTAKEPOS_PROMO_LEVEL') <= 0) {
			return 0;
		}
		$prod = isset($parameters['prod']) ? $parameters['prod'] : null;
		$line = isset($parameters['line']) ? $parameters['line'] : null;
		if (!is_object($prod) || !is_array($line)) {
			return 0;
		}
		// Prix promo (ADVANCEDTAKEPOS_PROMO_LEVEL = niveau promo, 0 = inactif) : la ligne reste
		// AU PRIX APPLIQUE (le prix boutique du niveau du client) et l'ecart vers le niveau promo
		// est porte en remise quand ce niveau existe et est inferieur -> le ticket lit
		// "prix boutique - remise = prix promo". Une remise deja posee n'est jamais ecrasee.
		$promolevel = getDolGlobalInt('ADVANCEDTAKEPOS_PROMO_LEVEL');
		if ($promolevel > 0 && empty($line['remise_percent'])) {
			$promoht  = empty($prod->multiprices[$promolevel]) ? 0 : (float) $prod->multiprices[$promolevel];
			$promottc = empty($prod->multiprices_ttc[$promolevel]) ? 0 : (float) $prod->multiprices_ttc[$promolevel];
			$netht  = (float) $line['price'];
			$netttc = (float) $line['price_ttc'];
			$base = 0; $cible = 0;
			if ($promottc > 0 && $netttc > 0 && $promottc < $netttc) {
				$base = $netttc; $cible = $promottc;
			} elseif ($promoht > 0 && $netht > 0 && $promoht < $netht) {
				$base = $netht; $cible = $promoht;
			}
			if ($base > 0) {
				$remise = (1 - $cible / $base) * 100;
				$arrondi = round($remise, 2);
				$line['remise_percent'] = (abs($base * (1 - $arrondi / 100) - $cible) < 0.005) ? $arrondi : round($remise, 4);
				$this->results = $line;
				return 0;
			}
		}
		if (getDolGlobalInt('ADVANCEDTAKEPOS_LINE_AT_PUBLIC_PRICE') != 1) {
			return 0;
		}
		// Niveau du client de la vente : la facture porte le tiers, plus sur que le parametre d'URL.
		$level = (is_object($object) && !empty($object->socid)) ? $this->priceLevelOfSocid($object->socid) : $this->getCustomerLevel();
		$publevel = $this->publicPriceLevel();
		if ($publevel == $level) {
			return 0;	// rien a montrer : le prix de reference EST le prix applique
		}
		// Une remise deja posee (ligne libre, promotion) n'est jamais ecrasee.
		if (!empty($line['remise_percent'])) {
			return 0;
		}
		$pubht  = empty($prod->multiprices[$publevel]) ? 0 : (float) $prod->multiprices[$publevel];
		$pubttc = empty($prod->multiprices_ttc[$publevel]) ? 0 : (float) $prod->multiprices_ttc[$publevel];
		$netht  = (float) $line['price'];
		$netttc = (float) $line['price_ttc'];
		// On calcule le taux sur la base qui porte le prix, et seulement si le PPC est au-dessus.
		if ($pubttc > 0 && $netttc > 0) {
			if ($pubttc <= $netttc) {
				return 0;
			}
			$remise = (1 - $netttc / $pubttc) * 100;
		} elseif ($pubht > 0 && $netht > 0) {
			if ($pubht <= $netht) {
				return 0;
			}
			$remise = (1 - $netht / $pubht) * 100;
		} else {
			return 0;	// pas de prix de reference sur ce produit : on ne touche a rien
		}

		$line['price'] = $pubht > 0 ? $pubht : $line['price'];
		$line['price_ttc'] = $pubttc > 0 ? $pubttc : $line['price_ttc'];
		// Un taux a deux decimales se lit sur un ecran de caisse, quatre non. Mais le net encaisse
		// prime sur la presentation : on ne garde l'arrondi que s'il redonne exactement le meme
		// montant, sinon on conserve la precision.
		$base = ($pubttc > 0 && $netttc > 0) ? $pubttc : $pubht;
		$cible = ($pubttc > 0 && $netttc > 0) ? $netttc : $netht;
		$arrondi = round($remise, 2);
		$line['remise_percent'] = (abs($base * (1 - $arrondi / 100) - $cible) < 0.005) ? $arrondi : round($remise, 4);

		$this->results = $line;
		return 0;
	}

	/**
	 * Onglets proposes pour la fiche produit ouverte depuis la caisse : cle => debut d'URL, a completer par l'id.
	 *
	 * @return array<string,string>
	 */
	public static function productCardTabs()
	{
		return array(
			'card' => '/product/card.php?id=',
			'stock' => '/product/stock/product.php?id=',
			'price' => '/product/price.php?id=',
			'suppliers' => '/product/price_suppliers.php?id=',
			'referers' => '/product/stats/facture.php?showmessage=1&id=',
			'stats' => '/product/stats/index.php?id=',
			'documents' => '/product/document.php?id=',
			'agenda' => '/product/agenda.php?id=',
		);
	}

	/**
	 * Onglets proposes pour la fiche tiers ouverte depuis la caisse : cle => debut d'URL, a completer par le socid.
	 *
	 * @return array<string,string>
	 */
	public static function thirdpartyCardTabs()
	{
		return array(
			'card' => '/societe/card.php?socid=',
			'customer' => '/comm/card.php?socid=',
			'contact' => '/societe/contact.php?socid=',
			'consumption' => '/societe/consumption.php?socid=',
			'note' => '/societe/note.php?socid=',
			'documents' => '/societe/document.php?socid=',
			'agenda' => '/societe/agenda.php?socid=',
		);
	}

	/**
	 * Debut d'URL de la fiche produit, sur l'onglet choisi dans la configuration (ADVANCEDTAKEPOS_PRODUCT_CARD_TAB).
	 *
	 * @return string URL a completer par l'id du produit
	 */
	private function productCardUrlPrefix()
	{
		$tabs = self::productCardTabs();
		$tab = getDolGlobalString('ADVANCEDTAKEPOS_PRODUCT_CARD_TAB', 'card');
		return DOL_URL_ROOT.(isset($tabs[$tab]) ? $tabs[$tab] : $tabs['card']);
	}

	/**
	 * Tiers par defaut du terminal courant.
	 *
	 * @return int socid (0 si aucun)
	 */
	private function terminalDefaultSocid()
	{
		$term = empty($_SESSION['takeposterminal']) ? 1 : (int) $_SESSION['takeposterminal'];
		return getDolGlobalInt('CASHDESK_ID_THIRDPARTY'.$term);
	}

	/**
	 * Niveau de prix d'un tiers (mis en cache).
	 *
	 * @param  int $socid Id tiers
	 * @return int        Niveau de prix (>=1)
	 */
	private function priceLevelOfSocid($socid)
	{
		$socid = (int) $socid;
		if ($socid <= 0) {
			return 1;
		}
		if (isset(self::$levelcache[$socid])) {
			return self::$levelcache[$socid];
		}
		$level = 1;
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		$tp = new Societe($this->db);
		if ($tp->fetch($socid) > 0 && (int) $tp->price_level > 0) {
			$level = (int) $tp->price_level;
		}
		self::$levelcache[$socid] = $level;
		return $level;
	}

	/**
	 * Libelle "Niveau N - Nom" pour un niveau de prix donne.
	 *
	 * @param  int    $level Niveau de prix
	 * @return string        Libelle
	 */
	private function levelText($level)
	{
		// Format "N - Nom" (ex. "1 - Prix Public") ; sans prefixe "Niveau" (inutile).
		$name = getDolGlobalString('PRODUIT_MULTIPRICES_LABEL'.$level);
		return $level.($name !== '' ? ' - '.$name : '');
	}

	/**
	 * Entrepot du terminal courant.
	 *
	 * @return int fk_entrepot (0 si aucun)
	 */
	private function getTerminalWarehouse()
	{
		$term = empty($_SESSION['takeposterminal']) ? 1 : (int) $_SESSION['takeposterminal'];
		return getDolGlobalInt('CASHDESK_ID_WAREHOUSE'.$term);
	}

	/**
	 * Somme du stock reel d'un produit (tous entrepots, ou un entrepot precis).
	 * Deux sous-requetes plutot que load_stock() qui ferait une requete par produit.
	 *
	 * @param  int      $fk_product  Id produit
	 * @param  int      $fk_entrepot 0 = tous entrepots
	 * @return float|null            Somme, ou null si aucune ligne
	 */
	private function stockSum($fk_product, $fk_entrepot = 0)
	{
		$sql = "SELECT SUM(reel) as reel FROM ".MAIN_DB_PREFIX."product_stock";
		$sql .= " WHERE fk_product = ".((int) $fk_product);
		if ($fk_entrepot > 0) {
			$sql .= " AND fk_entrepot = ".((int) $fk_entrepot);
		}
		$resql = $this->db->query($sql);
		if (!$resql) {
			return null;
		}
		$obj = $this->db->fetch_object($resql);
		if (!$obj || $obj->reel === null) {
			return null;
		}
		return (float) $obj->reel;
	}

	/**
	 * Le stock de cet article est-il gere ? Non pour un service (sauf STOCK_SUPPORTS_SERVICES) et pour un article
	 * non stockable (case « Produit stockable » decochee). Resultat garde pour la requete.
	 *
	 * @param int $fk_product Produit
	 * @return bool
	 */
	private function isStockManaged($fk_product)
	{
		static $cache = array();
		$fk_product = (int) $fk_product;
		if (!isset($cache[$fk_product])) {
			$managed = true;
			$resql = $this->db->query("SELECT fk_product_type, stockable_product FROM ".MAIN_DB_PREFIX."product WHERE rowid = ".$fk_product);
			$o = $resql ? $this->db->fetch_object($resql) : null;
			if ($o) {
				if (((int) $o->fk_product_type == 1 && !getDolGlobalString('STOCK_SUPPORTS_SERVICES')) || (isset($o->stockable_product) && (int) $o->stockable_product == 0)) {
					$managed = false;
				}
			}
			$cache[$fk_product] = $managed;
		}
		return $cache[$fk_product];
	}

	/**
	 * Hook data : enrichit chaque ligne produit renvoyee par l'ajax TakePOS
	 * (contexte takeposproductsearch) avec le prix public (niveau de reference configurable) + % d'ecart et le stock
	 * (local a l'entrepot du terminal + total tous entrepots).
	 *
	 * @param  array<string,mixed>  $parameters  Contient 'row' et 'obj'
	 * @param  CommonObject         $object      Objet courant
	 * @param  string               $action      Action
	 * @param  HookManager          $hookmanager Gestionnaire de hooks
	 * @return int                               1 pour remplacer la ligne enrichie, 0 sinon
	 */
	public function takeposCompleteProductOrCategory($parameters, &$object, &$action, $hookmanager)
	{
		// Nom retenu par le coeur a partir de la 25.0 (PR Dolibarr #40810) : l ancien
		// completeAjaxReturnArray ne disait rien de son contexte. On garde les deux methodes,
		// le module devant servir les versions anterieures comme les suivantes.
		return $this->completeAjaxReturnArray($parameters, $object, $action, $hookmanager);
	}

	public function completeAjaxReturnArray($parameters, &$object, &$action, $hookmanager)
	{
		if (empty($parameters['context']) || strpos($parameters['context'], 'takeposproductsearch') === false) {
			return 0;
		}
		global $conf, $langs;

		$row = isset($parameters['row']) ? $parameters['row'] : array();
		$obj = isset($parameters['obj']) ? $parameters['obj'] : null;

		// Ne traiter que les vrais produits (les categories passent aussi par ici).
		if (empty($row) || !is_object($obj) || empty($obj->rowid) || (isset($row['object']) && $row['object'] !== 'product')) {
			return 0;
		}

		// Nombre total de produits trouves (ADVANCEDTAKEPOS_SEARCH_COUNT), calcule par la requete de recherche.
		if (isset($obj->advtp_total)) {
			$row['advtp_total'] = (int) $obj->advtp_total;
		}

		// Produit trouve par un code secondaire : on renvoie le code scanne comme code-barres, pour que TakePOS
		// l'ajoute directement au panier comme sur un code-barres de fiche (resultat unique egal a la saisie).
		$altchanged = false;
		if ($this->altBarcodeTerm !== '' && in_array((int) $obj->rowid, $this->altBarcodeIds) && (string) $row['barcode'] !== $this->altBarcodeTerm) {
			$row['barcode'] = $this->altBarcodeTerm;
			$altchanged = true;
		}

		$showpublic = (getDolGlobalInt('ADVANCEDTAKEPOS_SHOW_PUBLIC_PRICE') == 1);
		$showstock  = (getDolGlobalInt('ADVANCEDTAKEPOS_SHOW_STOCK') == 1);
		if (!$showpublic && !$showstock) {
			if ($altchanged || isset($row['advtp_total'])) {
				$this->results = $row;
				return 1;
			}
			return 0;
		}

		// Prix public : necessite les multiprices du produit.
		if ($showpublic) {
			require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
			$p = new Product($this->db);
			if ($p->fetch((int) $obj->rowid) > 0) {
				$level = $this->getCustomerLevel();
				// Le "prix public" de reference n'est pas forcement le niveau 1 : le niveau 1 est le defaut
				// de tous les tiers, il peut donc porter le tarif courant (Internet) et le prix public
				// conseille vivre sur un autre niveau. D'ou un niveau de reference configurable.
				$publevel = $this->publicPriceLevel();
				$pubttc  = !empty($p->multiprices_ttc[$publevel]) ? $p->multiprices_ttc[$publevel] : $p->price_ttc;
				$pubht   = !empty($p->multiprices[$publevel]) ? $p->multiprices[$publevel] : $p->price;
				$salettc = !empty($p->multiprices_ttc[$level]) ? $p->multiprices_ttc[$level] : $p->price_ttc;
				// Mode promo : la vignette montre le prix applique (magasin) BARRE et le prix
				// promo dessous, a la place du prix de reference (PPC).
				$promolevel = getDolGlobalInt('ADVANCEDTAKEPOS_PROMO_LEVEL');
				$promottc = ($promolevel > 0 && !empty($p->multiprices_ttc[$promolevel])) ? (float) $p->multiprices_ttc[$promolevel] : 0;
				$promoht  = ($promolevel > 0 && !empty($p->multiprices[$promolevel])) ? (float) $p->multiprices[$promolevel] : 0;
				if ($promottc > 0 && (float) $salettc > 0 && $promottc < (float) $salettc - 0.0001) {
					$pctsuffix = '';
					if (getDolGlobalInt('ADVANCEDTAKEPOS_SHOW_PRICE_DIFF') == 1) {
						$pct = round((((float) $salettc - $promottc) / (float) $salettc) * 100);
						$pctsuffix = ' (-'.abs($pct).'%)';
					}
					$row['public_price_formated'] = price(price2num($promoht, 'MT'), 1, $langs, 1, -1, -1, $conf->currency).$pctsuffix;
					$row['public_price_ttc_formated'] = price(price2num($promottc, 'MT'), 1, $langs, 1, -1, -1, $conf->currency).$pctsuffix;
					$row['advtp_mainstrike'] = 1;
				} elseif ((float) $pubttc > 0 && abs((float) $pubttc - (float) $salettc) > 0.0001) {
					// suffixe % d'ecart (extra Serious, gate a part)
					$pctsuffix = '';
					if (getDolGlobalInt('ADVANCEDTAKEPOS_SHOW_PRICE_DIFF') == 1) {
						$pct = round((((float) $pubttc - (float) $salettc) / (float) $pubttc) * 100);
						$pctsuffix = ' ('.($pct >= 0 ? '-' : '+').abs($pct).'%)';
					}
					$row['public_price_formated'] = price(price2num($pubht, 'MT'), 1, $langs, 1, -1, -1, $conf->currency).$pctsuffix;
					$row['public_price_ttc_formated'] = price(price2num($pubttc, 'MT'), 1, $langs, 1, -1, -1, $conf->currency).$pctsuffix;
				}
			}
		}

		// Stock : deux sous-requetes legeres (local entrepot terminal + total).
		if ($showstock) {
			if (getDolGlobalInt('ADVANCEDTAKEPOS_SERVICE_BADGE') == 1 && !$this->isStockManaged((int) $obj->rowid)) {
				// Pas de stock gere : badge « Service » au lieu d'un stock a 0 qui ne veut rien dire.
				$row['advtp_service'] = 1;
				$row['stock_local'] = null;
				$row['stock_total'] = null;
			} else {
				$fkwh = $this->getTerminalWarehouse();
				$row['stock_local'] = $fkwh > 0 ? $this->stockSum((int) $obj->rowid, $fkwh) : null;
				$total = $this->stockSum((int) $obj->rowid, 0);
				$row['stock_total'] = ($total === null) ? 0 : $total;
			}
		}

		$this->results = $row;
		return 1; // mode remplacement : la ligne enrichie remplace l'originale
	}

	/**
	 * Recherche TakePOS (ajax.php, contexte takeposproductsearch) : ajoute le nombre total de produits trouves a chaque
	 * ligne (COUNT(*) OVER (), calcule avant la limite de la page), pour l'afficher a cote du champ de recherche.
	 * Avec TAKEPOS_PRODUCT_IN_STOCK sans entrepot de caisse, le coeur repete ce hook dans son GROUP BY : rien n'est
	 * ajoute dans ce cas (pas de compteur).
	 *
	 * @param array<string,mixed> $parameters  Hook parameters
	 * @param CommonObject|null   $object      Non utilise
	 * @param string              $action      Action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int
	 */
	public function printFieldListSelect($parameters, &$object, &$action, $hookmanager)
	{
		$this->resprints = '';
		if (getDolGlobalInt('ADVANCEDTAKEPOS_SEARCH_COUNT') != 1 || empty($parameters['context']) || strpos($parameters['context'], 'takeposproductsearch') === false
			|| GETPOST('action', 'aZ09') != 'search') {
			return 0;
		}
		$term = empty($_SESSION['takeposterminal']) ? 1 : (int) $_SESSION['takeposterminal'];
		if (getDolGlobalInt('TAKEPOS_PRODUCT_IN_STOCK') == 1 && !getDolGlobalInt('CASHDESK_ID_WAREHOUSE'.$term)) {
			return 0;
		}
		$this->resprints = ', COUNT(*) OVER () as advtp_total';
		return 0;
	}

	/**
	 * Recherche TakePOS (ajax.php, contexte takeposproductsearch) : trouve aussi les produits par leurs codes
	 * secondaires (ADVANCEDTAKEPOS_ALT_BARCODES), et, mot par mot, par leurs references fournisseur, le nom de leur
	 * fournisseur, les valeurs de leurs attributs de variante ou leurs caracteristiques (module productsfeatures).
	 * Le coeur ne cherche que dans la reference, le libelle et le code-barres de la fiche.
	 *
	 * Le coeur ajoute la sortie de ce hook juste apres sa clause natural_search, sans parentheses : on y ajoute
	 * donc une branche OR complete, qui reprend ses filtres (entite, en vente, categorie racine, stock).
	 *
	 * @param array<string,mixed> $parameters  Hook parameters
	 * @param CommonObject|null   $object      Non utilise
	 * @param string              $action      Action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int
	 */
	public function printFieldListWhere($parameters, &$object, &$action, $hookmanager)
	{
		if (empty($parameters['context']) || strpos($parameters['context'], 'takeposproductsearch') === false) {
			return 0;
		}
		global $filteroncategids;

		$this->resprints = '';
		$this->altBarcodeIds = array();
		$this->altBarcodeTerm = '';
		$term = trim(GETPOST('search_term', 'alphanohtml'));
		if ($term === '') {
			return 0;
		}
		// Codes (egalite stricte, ajout direct au panier) et references (jetons ref:..., cherchees mot par mot).
		$codesources = array();
		$refsources = array();
		foreach (explode(',', getDolGlobalString('ADVANCEDTAKEPOS_ALT_BARCODES')) as $src) {
			$src = trim($src);
			if (strpos($src, 'ref:') === 0) {
				$refsources[] = substr($src, 4);
			} elseif ($src !== '') {
				$codesources[] = $src;
			}
		}
		$ids = array();
		// Un code se scanne d'un bloc : pas d'espace, au moins 6 caracteres (la recherche part a chaque touche).
		if ($codesources && preg_match('/^[0-9A-Za-z._-]{6,64}$/', $term)) {
			$ids = $this->altBarcodeProductIds(self::barcodeVariants($term), implode(',', $codesources));
		}
		$words = $this->extendedSearchSql($term, $refsources);
		if (empty($ids) && $words['where'] === '') {
			return 0;
		}

		$base = 'p.entity IN ('.getEntity('product').') AND p.tosell = 1';
		if (!empty($filteroncategids)) {
			$base .= ' AND EXISTS (SELECT cp.fk_product FROM '.MAIN_DB_PREFIX.'categorie_product as cp WHERE cp.fk_product = p.rowid AND cp.fk_categorie IN ('.$this->db->sanitize($filteroncategids).'))';
		}
		$term_wh = empty($_SESSION['takeposterminal']) ? 0 : getDolGlobalInt('CASHDESK_ID_WAREHOUSE'.$_SESSION['takeposterminal']);
		if (getDolGlobalInt('TAKEPOS_PRODUCT_IN_STOCK') == 1 && $term_wh > 0) {
			$base .= ' AND ps.reel > 0';
		}
		$branches = array();
		if ($ids) {
			$branches[] = 'p.rowid IN ('.implode(',', $ids).')';
		}
		if ($words['where'] !== '') {
			$branches[] = $words['where'];
		}

		$this->altBarcodeIds = $ids;
		$this->altBarcodeTerm = $ids ? $term : '';
		// La branche etendue contient deja tout ce que trouve la clause du coeur (reference, libelle et code-barres y
		// sont cherches mot par mot) : « AND 1 = 0 » eteint la branche du coeur, que MariaDB/MySQL elimine a la
		// preparation, et les LIKE ne sont evalues qu'une fois par produit. Elle eteint aussi, pour un !mot, les
		// produits que le coeur garderait alors qu'une source etendue les designe.
		$this->resprints = ($words['where'] !== '' ? ' AND 1 = 0' : '').' OR ('.$base.' AND ('.implode(' OR ', $branches).'))';
		return 0;
	}

	/**
	 * Sources cherchees mot par mot en plus de la reference, du libelle et du code-barres : chacune est une
	 * sous-requete qui rend des identifiants de produits (colonne id), et les colonnes ou chercher le mot.
	 *
	 * @param string[] $refsources Sources de references (jetons ref: de ADVANCEDTAKEPOS_ALT_BARCODES, sans le prefixe)
	 * @return array<int,array{sql:string,fields:string[]}> Le texte __WHERE__ de sql recoit la clause du mot
	 */
	private function extendedSearchSources($refsources)
	{
		$sources = array();
		$pfp = MAIN_DB_PREFIX.'product_fournisseur_price';
		// Reference fournisseur et nom du fournisseur : une seule lecture des prix fournisseurs (voir la fusion plus bas).
		$pfpsql = 'SELECT pfp.fk_product as id FROM '.$pfp.' as pfp LEFT JOIN '.MAIN_DB_PREFIX.'societe as s ON s.rowid = pfp.fk_soc'
			.' WHERE pfp.fk_product IS NOT NULL AND pfp.entity IN ('.getEntity('productsupplierprice').') AND __WHERE__';
		foreach ($refsources as $src) {
			$m = array();
			if ($src === 'pfp') {
				$sources[] = array('sql' => $pfpsql, 'fields' => array('pfp.ref_fourn'));
			} elseif (preg_match('/^ef:([a-zA-Z0-9_]+)$/', $src, $m) && $this->columnExists(MAIN_DB_PREFIX.'product_extrafields', $m[1])) {
				$sources[] = array('sql' => 'SELECT ef.fk_object as id FROM '.MAIN_DB_PREFIX.'product_extrafields as ef WHERE ef.fk_object IS NOT NULL AND __WHERE__', 'fields' => array('ef.'.$m[1]));
			}
		}
		if (getDolGlobalInt('ADVANCEDTAKEPOS_SEARCH_SUPPLIER')) {
			$sources[] = array('sql' => $pfpsql, 'fields' => array('s.nom', 's.name_alias'));
		}
		if (getDolGlobalInt('ADVANCEDTAKEPOS_SEARCH_ATTRIBUTES') && isModEnabled('variants')) {
			$sources[] = array('sql' => 'SELECT pac.fk_product_child as id FROM '.MAIN_DB_PREFIX.'product_attribute_combination as pac'
				.' INNER JOIN '.MAIN_DB_PREFIX.'product_attribute_combination2val as pac2v ON pac2v.fk_prod_combination = pac.rowid'
				.' INNER JOIN '.MAIN_DB_PREFIX.'product_attribute_value as pav ON pav.rowid = pac2v.fk_prod_attr_val'
				.' WHERE pac.fk_product_child IS NOT NULL AND pac.entity IN ('.getEntity('product').') AND __WHERE__', 'fields' => array('pav.value'));
		}
		if (getDolGlobalInt('ADVANCEDTAKEPOS_SEARCH_FEATURES') && isModEnabled('productsfeatures') && $this->columnExists(MAIN_DB_PREFIX.'productsfeatures_product', 'fk_feature_value')) {
			$sources[] = array('sql' => 'SELECT pfe.fk_product as id FROM '.MAIN_DB_PREFIX.'productsfeatures_product as pfe'
				.' LEFT JOIN '.MAIN_DB_PREFIX.'productsfeatures_value as pfv ON pfv.rowid = pfe.fk_feature_value'
				.' WHERE pfe.fk_product IS NOT NULL AND pfe.entity IN ('.getEntity('product').') AND __WHERE__', 'fields' => array('pfv.label', 'pfe.custom_value'));
		}
		// Deux sources sur la meme requete n'en font qu'une, avec leurs colonnes reunies.
		$merged = array();
		foreach ($sources as $src) {
			if (isset($merged[$src['sql']])) {
				$merged[$src['sql']]['fields'] = array_merge($merged[$src['sql']]['fields'], $src['fields']);
			} else {
				$merged[$src['sql']] = $src;
			}
		}
		return array_values($merged);
	}

	/**
	 * Recherche mot par mot, avec la syntaxe de natural_search : espace = tous les mots, | = l'un ou l'autre,
	 * !mot = sans ce mot, ^ et $ = debut et fin, * = n'importe quelle suite. Chaque mot peut se trouver dans la
	 * fiche (reference, libelle, code-barres) ou dans l'une des sources etendues.
	 *
	 * Un mot de moins de 2 caracteres (hors operateurs) n'est cherche que dans la fiche, sauf s'il est ancre des
	 * deux cotes (^L$ = valeur exactement L).
	 *
	 * @param string   $term       Texte saisi
	 * @param string[] $refsources Sources de references
	 * @return array{where:string} where = condition de la branche etendue ('' si aucun mot ne touche une source)
	 */
	private function extendedSearchSql($term, $refsources)
	{
		$out = array('where' => '');
		$sources = $this->extendedSearchSources($refsources);
		if (empty($sources)) {
			return $out;
		}
		$value = preg_replace('/\s*\|\s*/', '|', $term);
		$parts = array();
		$extended = false;
		foreach (explode(' ', $value) as $word) {
			if ($word === '') {
				continue;
			}
			$core = natural_search(array('p.ref', 'p.label', 'p.barcode'), $word, 0, 1);
			$neg = (strpos($word, '!') === 0);
			$pos = $neg ? substr($word, 1) : $word;
			$bare = str_replace(array('|', '*', '^', '$', '%', '!'), '', $pos);
			$in = '';
			if (dol_strlen($bare) >= 2 || (dol_strlen($bare) >= 1 && preg_match('/^\^[^|]+\$$/', $pos))) {
				$in = $this->extendedWordIn($sources, $pos);
			}
			if ($in === '') {
				$parts[] = $core;
				continue;
			}
			$extended = true;
			if ($neg) {
				$parts[] = '('.$core.' AND NOT ('.$in.'))';
			} else {
				$parts[] = '('.$core.' OR '.$in.')';
			}
		}
		if ($extended && $parts) {
			$out['where'] = '('.implode(' AND ', $parts).')';
		}
		return $out;
	}

	/**
	 * Condition sur p.rowid pour un mot cherche dans les sources etendues. Les produits trouves sont lus d'abord
	 * (une requete UNION par mot) et passes en liste : une sous-requete placee dans un OR serait reevaluee pour
	 * chaque produit du catalogue. Au-dela de EXTENDED_SEARCH_MAX_IDS produits, on garde la sous-requete.
	 *
	 * @param array<int,array{sql:string,fields:string[]}> $sources Sources (extendedSearchSources)
	 * @param string                                       $word    Mot sans le ! d'exclusion
	 * @return string                                               'p.rowid IN (...)', ou '' si aucun produit
	 */
	private function extendedWordIn($sources, $word)
	{
		$subs = array();
		foreach ($sources as $src) {
			$clause = natural_search($src['fields'], $word, 0, 1);
			if ($clause !== '') {
				$subs[] = str_replace('__WHERE__', $clause, $src['sql']);
			}
		}
		if (empty($subs)) {
			return '';
		}
		$union = 'SELECT u.id FROM ('.implode(' UNION ', $subs).') as u';
		$resql = $this->db->query($union.$this->db->plimit(self::EXTENDED_SEARCH_MAX_IDS + 1));
		if (!$resql) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_WARNING);
			return '';
		}
		$ids = array();
		while ($o = $this->db->fetch_object($resql)) {
			if ((int) $o->id > 0) {
				$ids[(int) $o->id] = (int) $o->id;
			}
		}
		if (count($ids) > self::EXTENDED_SEARCH_MAX_IDS) {
			return 'p.rowid IN ('.$union.')';
		}
		return $ids ? 'p.rowid IN ('.implode(',', $ids).')' : '';
	}

	/**
	 * Produits portant l'un des codes $terms dans l'une des sources de codes secondaires, ou dans le code-barres
	 * de la fiche sous une autre forme (UPC-A a 12 chiffres et EAN-13 a zero de tete designent le meme article).
	 *
	 * Sources (liste separee par des virgules) :
	 * - pfp : code-barres des prix fournisseurs ;
	 * - ef:<champ> : champ complementaire produit ;
	 * - tbl:<table>:<colonne code>:<colonne produit> : table de correspondance (prefixe llx_ remplace par
	 *   celui de l'instance). Une table ou une colonne absente est ignoree.
	 *
	 * @param string[] $terms   Code saisi ou scanne, suivi de ses autres formes
	 * @param string   $sources Liste des sources
	 * @return int[]            Identifiants de produits (au plus 20 par source)
	 */
	private function altBarcodeProductIds($terms, $sources)
	{
		$ids = array();
		$in = "'".implode("','", array_map(array($this->db, 'escape'), $terms))."'";
		$queries = array();
		if (count($terms) > 1) {
			// Le code-barres de la fiche sous une autre forme (la recherche du coeur ne le compare qu'en LIKE).
			$others = "'".implode("','", array_map(array($this->db, 'escape'), array_slice($terms, 1)))."'";
			$queries[] = "SELECT rowid as id FROM ".MAIN_DB_PREFIX."product WHERE barcode IN (".$others.")";
		}
		foreach (explode(',', $sources) as $src) {
			$src = trim($src);
			$m = array();
			$sql = '';
			if ($src === 'pfp') {
				$sql = "SELECT fk_product as id FROM ".MAIN_DB_PREFIX."product_fournisseur_price WHERE barcode IN (".$in.")";
			} elseif (preg_match('/^ef:([a-zA-Z0-9_]+)$/', $src, $m)) {
				if ($this->columnExists(MAIN_DB_PREFIX.'product_extrafields', $m[1])) {
					$sql = "SELECT fk_object as id FROM ".MAIN_DB_PREFIX."product_extrafields WHERE ".$m[1]." IN (".$in.")";
				}
			} elseif (preg_match('/^tbl:([a-zA-Z0-9_]+):([a-zA-Z0-9_]+):([a-zA-Z0-9_]+)$/', $src, $m)) {
				$table = preg_replace('/^llx_/', MAIN_DB_PREFIX, $m[1]);
				if ($this->columnExists($table, $m[2]) && $this->columnExists($table, $m[3])) {
					$sql = "SELECT ".$m[3]." as id FROM ".$table." WHERE ".$m[2]." IN (".$in.")";
				}
			}
			if ($sql !== '') {
				$queries[] = $sql;
			}
		}
		foreach ($queries as $sql) {
			$resql = $this->db->query($sql.$this->db->plimit(20));
			if ($resql) {
				while ($o = $this->db->fetch_object($resql)) {
					if ((int) $o->id > 0) {
						$ids[(int) $o->id] = (int) $o->id;
					}
				}
			}
		}
		return array_values($ids);
	}

	/**
	 * Le code et ses autres formes : un lecteur rend un UPC-A sur 12 chiffres ou en EAN-13 avec un zero de tete
	 * selon son reglage, et un GTIN-14 de carton commence souvent par 0.
	 *
	 * @param string $term Code saisi ou scanne
	 * @return string[]    Le code d'abord, puis ses variantes
	 */
	private static function barcodeVariants($term)
	{
		$variants = array($term);
		if (preg_match('/^\d{12}$/', $term)) {
			$variants[] = '0'.$term;
		}
		if (preg_match('/^0\d{12,13}$/', $term)) {
			$variants[] = substr($term, 1);
		}
		return array_values(array_unique($variants));
	}

	/**
	 * Colonne presente dans la table (resultat garde pour la requete).
	 *
	 * @param string $table  Nom complet de la table
	 * @param string $column Nom de la colonne
	 * @return bool
	 */
	private function columnExists($table, $column)
	{
		static $cache = array();
		$key = $table.'.'.$column;
		if (!isset($cache[$key])) {
			$resql = $this->db->DDLDescTable($table, $column);
			$cache[$key] = ($resql && $this->db->num_rows($resql) > 0);
		}
		return $cache[$key];
	}

	/**
	 * Hook rendu : JS injecte dans les boucles d'affichage produit (contexte takeposfrontend).
	 * Variables JS en scope : caller 'loadProducts' -> ishow/parseInt(idata) ; 'search2' -> i/i.
	 *
	 * @param  array<string,mixed>  $parameters  Contient 'caller'
	 * @param  CommonObject         $object      Objet courant
	 * @param  string               $action      Action
	 * @param  HookManager          $hookmanager Gestionnaire de hooks
	 * @return int                               0 (ajout via resprints)
	 */
	public function completeJSProductDisplay($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $conf;
		$langs->load('advancedtakepos@advancedtakepos');

		$caller = isset($parameters['caller']) ? $parameters['caller'] : '';
		if ($caller === 'search2') {
			$el = 'i';
			$dx = 'i';
		} else {
			$el = 'ishow';
			$dx = 'parseInt(idata)';
		}
		// Le prix affiche par le coeur suit TAKEPOS_CHANGE_PRICE_HT ; on aligne le prix public dessus.
		$pubkey = getDolGlobalInt('TAKEPOS_CHANGE_PRICE_HT') ? 'public_price_formated' : 'public_price_ttc_formated';

		$js = '';

		// HOTFIX bug coeur (bande <768px) : Search2/MoreProducts remplissent les vignettes sans retirer la
		// classe divempty, que le CSS mobile masque -> les resultats de recherche restent invisibles.
		$js .= "if(data[$dx]){\$('#prodiv'+$el).removeClass('divempty');}\n";

		// --- Stock : badge dedie, reproduit takeposSetStock() du port ---
		if (getDolGlobalInt('ADVANCEDTAKEPOS_SHOW_STOCK') == 1) {
			$sttitle = dol_escape_js($langs->trans('AdvTakeposStockHelp'));
			$svclabel = dol_escape_js($langs->transnoentitiesnoconv('AdvTakeposServiceBadge'));
			$svctitle = dol_escape_js($langs->transnoentitiesnoconv('AdvTakeposServiceBadgeHelp'));
			$js .= "(function(){var d=data[$dx],pos=$el;";
			$js .= " if(d&&d['advtp_service']==1){if(\$('#prostock'+pos).length===0){\$('#proprice'+pos).after('<div id=\"prostock'+pos+'\"></div>');}\$('#prostock'+pos).attr('class','productstock advtakeposstock advtp-svcbadge').attr('title','".$svctitle."').text('".$svclabel."');return;}";
			$js .= " if(!d||typeof d['stock_total']==='undefined'||d['stock_total']===null){\$('#prostock'+pos).attr('class','').attr('title','').html('');return;}";
			// Reutilise l'element #prostock du coeur s'il existe (port applique), sinon le cree apres le prix.
			$js .= " if(\$('#prostock'+pos).length===0){\$('#proprice'+pos).after('<div id=\"prostock'+pos+'\"></div>');}";
			$js .= " var total=d['stock_total'],local=d['stock_local'];";
			// Le stock suit le NOMBRE DE LIGNES du prix : 2 lignes si le prix public s'affiche (prix sur 2 lignes),
			// sinon 1 ligne -- pour que les deux badges gardent la meme hauteur.
			$js .= " var hastwo=(d['$pubkey']!==undefined&&d['$pubkey']!==null&&d['$pubkey']!=='');";
			$js .= " var html;";
			$js .= " if(typeof local==='undefined'||local===null||local===total){html=String(total);}";
			$js .= " else if(hastwo){html=local+'<span class=\"advtp-stocktotal\">'+total+'</span>';}";
			$js .= " else{html=local+' <span class=\"advtp-stockinline\">/ '+total+'</span>';}";
			// Pas de prefixe 'Stock:' (l'info-bulle l'explique) pour rester compact et ne pas chevaucher le prix.
			$js .= " \$('#prostock'+pos).attr('class','productstock advtakeposstock').attr('title','".$sttitle."').html(html);";
			$js .= "})();\n";
		}

		// --- Prix public (+ % eventuel deja dans la donnee) : reproduit takeposAppendPublicPrice() ---
		if (getDolGlobalInt('ADVANCEDTAKEPOS_SHOW_PUBLIC_PRICE') == 1) {
			$js .= "(function(){var d=data[$dx],pos=$el; if(!d)return;";
			$js .= " var pub=d['$pubkey']; if(typeof pub==='undefined'||pub===null||pub==='')return;";
			$js .= " var el=\$('#proprice'+pos); if(el.length===0||el.html()===pub)return;";
			$js .= " if(el.find('.productpublicprice').length>0)return;";
			$js .= " if(d['advtp_mainstrike']==1){el.wrapInner('<span class=\"advtp-mainprice advtp-strike\"></span>');}";
			$js .= " el.append(' <span class=\"productpublicprice'+(d['advtp_mainstrike']==1?' advtp-promoline':'')+'\">'+pub+'</span>');";
			$js .= "})();\n";
		}

		// --- Fiche produit en popup : icone (i) sur chaque vignette, ouvre product/card.php en modale ---
		if (getDolGlobalInt('ADVANCEDTAKEPOS_PRODUCT_CARD_POPUP') == 1) {
			$cardurl = dol_escape_js($this->productCardUrlPrefix());
			$js .= "(function(){var d=data[$dx],pos=$el;var pd=\$('#prodiv'+pos);if(!pd.length)return;";
			$js .= " if(!d||!d.rowid||pd.attr('data-iscat')=='1'){pd.find('.advtp-pinfo').remove();return;}";
			$js .= " var ic=pd.find('.advtp-pinfo');";
			$js .= " if(ic.length===0){pd.append('<span class=\"advtp-pinfo advtp-cardlink fa fa-info-circle\"></span>');ic=pd.find('.advtp-pinfo');}";
			$js .= " ic.attr('data-advtp-url','".$cardurl."'+d.rowid).attr('data-advtp-title',d.label||'');";
			$js .= "})();\n";
		}

		// (Le niveau de prix est mis a jour cote invoice.php via completeTakePosInvoiceHeader, qui se recharge
		//  a chaque Refresh() — donc a chaque changement de panier/client. Rien a faire par vignette ici.)

		// --- Bloc execute une seule fois (aussi pose par l'en-tete du panier, voir onceJs()) ---
		$js .= $this->onceJs();

		$this->resprints = $js;
		return 0;
	}

	/**
	 * Hook invoice.php (contexte takeposinvoice) : met a jour le badge "Niveau" de la barre de titre
	 * avec le niveau de prix du CLIENT de la vente en cours. invoice.php est recharge par Refresh() a
	 * chaque changement de panier / de client, donc le niveau suit dynamiquement (a l'entree ET aux bascules).
	 *
	 * @param  array<string,mixed>  $parameters  Contexte
	 * @param  CommonObject         $object      La facture TakePOS en cours ($invoice)
	 * @param  string               $action      Action
	 * @param  HookManager          $hookmanager Gestionnaire de hooks
	 * @return int                               0 (ajout via resprints)
	 */
	/**
	 * Prix TTC du niveau de reference pour un produit, mis en cache.
	 * Lecture directe de product_price : on evite un Product::fetch complet par ligne du ticket,
	 * qui declencherait une requete par niveau de prix.
	 *
	 * @param  int        $fk_product  Identifiant produit
	 * @return float|null              Prix TTC, ou null si le produit n a pas de prix sur ce niveau
	 */
	private function refPriceTtc($fk_product)
	{
		return $this->levelPriceTtc($fk_product, $this->publicPriceLevel());
	}

	/**
	 * Prix TTC d un niveau donne pour un produit, mis en cache.
	 *
	 * @param  int        $fk_product  Identifiant produit
	 * @param  int        $level       Niveau de prix
	 * @return float|null              Prix TTC, ou null si le produit n a pas de prix sur ce niveau
	 */
	private function levelPriceTtc($fk_product, $level)
	{
		$fk_product = (int) $fk_product;
		$level = (int) $level;
		if ($fk_product <= 0 || $level <= 0) {
			return null;
		}
		$cle = $level.'-'.$fk_product;
		if (array_key_exists($cle, self::$refpricecache)) {
			return self::$refpricecache[$cle];
		}
		$sql = "SELECT price_ttc FROM ".MAIN_DB_PREFIX."product_price";
		$sql .= " WHERE fk_product = ".$fk_product." AND price_level = ".((int) $level);
		$sql .= " AND entity IN (".getEntity('productprice').")";
		$sql .= " ORDER BY date_price DESC, rowid DESC LIMIT 1";	// meme regle que Product::fetch
		$val = null;
		$res = $this->db->query($sql);
		if ($res && ($o = $this->db->fetch_object($res)) && (float) $o->price_ttc > 0) {
			$val = (float) $o->price_ttc;
		}
		self::$refpricecache[$cle] = $val;
		return $val;
	}

	/**
	 * JS execute une seule fois par page TakePOS (garde window.__advtakeposOnce) : styles du module, fil d'Ariane,
	 * compteur et options de la recherche, couleurs des categories, pager, popups de saisie...
	 * Pose a la fois par le rendu des vignettes produit (completeJSProductDisplay) et par l'en-tete du panier
	 * (completeTakePosInvoiceHeader) : le coeur n'imprime le premier que dans la boucle des articles, il ne s'executait
	 * donc pas tant qu'aucun article n'etait affiche (premiere categorie sans article, avec seulement des sous-categories).
	 *
	 * @return string JS
	 */
	private function onceJs()
	{
		global $langs, $conf;
		$langs->load('advancedtakepos@advancedtakepos');
		$once = '';
		$css = $this->buildCss();
		$once .= "if(\$('#advtakepos-style').length===0){\$('head').append('<style id=\"advtakepos-style\">".$css."</style>');}\n";
		// Nos badges #prostock ne sont pas connus du coeur : on nettoie UNIQUEMENT ceux des vignettes qui
		// ne montrent plus un produit (categorie ou case vide) - jamais ceux des produits encore affiches.
		if (getDolGlobalInt('ADVANCEDTAKEPOS_SHOW_STOCK') == 1) {
			$once .= "window.advtpCleanBadges=function(){\$('[id^=prostock]').each(function(){var pos=this.id.replace('prostock','');var pd=\$('#prodiv'+pos);if(!pd.length)return;if(pd.attr('data-iscat')=='1'||!pd.attr('data-rowid')){\$(this).empty().attr('class','').attr('title','');}});};\n";
			// Point deterministe : ajaxComplete se declenche APRES les callbacks de rendu de la recherche
			// et du chargement de categorie - plus aucune course avec des minuteries.
			$once .= "jQuery(document).ajaxComplete(function(e,x,st){if(st&&st.url&&(st.url.indexOf('action=search')>=0||st.url.indexOf('action=getProducts')>=0)){window.advtpCleanBadges();}});\n";
		}
		// Meme principe pour les icones fiche produit : retirees des vignettes qui ne montrent plus un produit.
		if (getDolGlobalInt('ADVANCEDTAKEPOS_PRODUCT_CARD_POPUP') == 1) {
			$once .= "window.advtpCleanPinfo=function(){\$('.advtp-pinfo').each(function(){var pd=\$(this).closest('[id^=prodiv]');if(!pd.length)return;if(pd.attr('data-iscat')=='1'||!pd.attr('data-rowid')){\$(this).remove();}});};\n";
			$once .= "jQuery(document).ajaxComplete(function(e,x,st){if(st&&st.url&&(st.url.indexOf('action=search')>=0||st.url.indexOf('action=getProducts')>=0)){window.advtpCleanPinfo();}});\n";
		}
		// Couleur des categories (ADVANCEDTAKEPOS_CATEGORY_COLORS) : le coeur n'utilise que l'image. Le fond des
		// vignettes de categorie (zone des categories) et de sous-categorie (zone produits) prend la couleur saisie
		// sur la categorie ; sans image (TILES_NO_IMAGE), le texte passe en noir ou blanc selon le contraste.
		// Repeint apres PrintCategories (pagination des categories) et apres chaque chargement de la zone produits.
		if (getDolGlobalInt('ADVANCEDTAKEPOS_CATEGORY_COLORS') == 1) {
			$noimg = (getDolGlobalInt('ADVANCEDTAKEPOS_TILES_NO_IMAGE') == 1) ? 1 : 0;
			$once .= "window.advtpCatColor=function(){var m={};";
			$once .= "\$.each((typeof categories!=='undefined'&&categories)||{},function(i,v){if(v&&v.color){m[v.rowid]=v.color;}});";
			$once .= "\$.each((typeof subcategories!=='undefined'&&subcategories)||{},function(i,v){if(v&&v.color){m[v.rowid]=v.color;}});";
			$once .= "var paint=function(el,col){var c=col?String(col).replace('#',''):'';if(!/^[0-9a-fA-F]{6}\$/.test(c)){el.style.removeProperty('background-color');el.style.removeProperty('color');el.classList.remove('advtp-catcol');return;}";
			$once .= "var l=(0.299*parseInt(c.substr(0,2),16)+0.587*parseInt(c.substr(2,2),16)+0.114*parseInt(c.substr(4,2),16))/255;";
			$once .= "el.style.setProperty('background-color','#'+c,'important');if(".$noimg."){el.style.setProperty('color',l>0.5?'#1b1f26':'#fff','important');}el.classList.add('advtp-catcol');};";
			$once .= "\$('.div4 [id^=catdiv]').each(function(){if(!/^catdiv\\d+\$/.test(this.id))return;var id=\$(this).attr('data-rowid');paint(this,(String(this.className).indexOf('divempty')<0&&id)?m[id]:null);});";
			$once .= "\$('.div5 [id^=prodiv]').each(function(){if(!/^prodiv\\d+\$/.test(this.id))return;var id=\$(this).attr('data-rowid');paint(this,(\$(this).attr('data-iscat')=='1'&&id)?m[id]:null);});};\n";
			$once .= "if(typeof PrintCategories==='function'&&!PrintCategories.__advtpCol){var advtpPC=PrintCategories;window.PrintCategories=function(){var r=advtpPC.apply(this,arguments);window.advtpCatColor();return r;};window.PrintCategories.__advtpCol=1;}\n";
			// Fleches de pagination des categories : MoreCategories reecrit les vignettes sans passer par PrintCategories.
			$once .= "if(typeof MoreCategories==='function'&&!MoreCategories.__advtpCol){var advtpMC=MoreCategories;window.MoreCategories=function(){var r=advtpMC.apply(this,arguments);window.advtpCatColor();return r;};window.MoreCategories.__advtpCol=1;}\n";
			$once .= "jQuery(document).ajaxComplete(function(e,x,st){if(st&&st.url&&(st.url.indexOf('action=search')>=0||st.url.indexOf('action=getProducts')>=0)){window.advtpCatColor();}});\n";
			$once .= "window.advtpCatColor();\n";
		}
		// Fil d'Ariane des categories (ADVANCEDTAKEPOS_CATEGORY_BREADCRUMB) : js/catnav.js.php.
		if (getDolGlobalInt('ADVANCEDTAKEPOS_CATEGORY_BREADCRUMB') == 1) {
			$once .= "jQuery.getScript('".dol_buildpath('/advancedtakepos/js/catnav.js.php', 1).'?v='.((int) @filemtime(__DIR__.'/../js/catnav.js.php'))."');\n";
		}
		// Nombre de produits trouves par la recherche (ADVANCEDTAKEPOS_SEARCH_COUNT) : pastille apres le champ de
		// recherche. Le total vient de la requete de recherche elle-meme (printFieldListSelect, advtp_total).
		if (getDolGlobalInt('ADVANCEDTAKEPOS_SEARCH_COUNT') == 1) {
			$langs->load('advancedtakepos@advancedtakepos');
			$one = dol_escape_js($langs->transnoentitiesnoconv('AdvTakeposSearchCountOne'));
			// %s remplace par un repere : transnoentitiesnoconv() passe toujours par sprintf.
			$many = dol_escape_js($langs->transnoentitiesnoconv('AdvTakeposSearchCountMany', '__N__'));
			$once .= "if(\$('#advtpSearchCount').length===0&&\$('#search').length){\$('#search').after('<span id=\"advtpSearchCount\" class=\"advtp-searchcount\"></span>');}\n";
			$once .= "window.advtpSearchCountHide=function(){\$('#advtpSearchCount').hide();};\n";
			$once .= "jQuery(document).ajaxComplete(function(e,x,st){if(!st||!st.url){return;}";
			$once .= "if(st.url.indexOf('action=search')>=0){var d=x&&x.responseJSON;if(!d){try{d=JSON.parse(x.responseText);}catch(er){d=null;}}";
			$once .= "var n=null;if(\$.isArray(d)){if(d.length===0){n=0;}\$.each(d,function(i,r){if(r&&r.advtp_total!==undefined){n=Math.max(n||0,parseInt(r.advtp_total,10)||0);}});}";
			$once .= "var b=\$('#advtpSearchCount');if(n===null||\$('#search').val()===''){b.hide();}else{b.text(n===1?'".$one."':'".$many."'.replace('__N__',n)).show();}}";
			$once .= "else if(st.url.indexOf('action=getProducts')>=0){window.advtpSearchCountHide();}});\n";
			$once .= "\$(document).on('input','#search',function(){if(this.value===''){window.advtpSearchCountHide();}});\n";
			// ClearSearch(false) (croix du champ, ajout d'un produit trouve) vide le champ mais garde les resultats : la pastille
			// reste ; ClearSearch(true) vide aussi la grille.
			$once .= "if(typeof ClearSearch==='function'&&!ClearSearch.__advtpCnt){var advtpCS=ClearSearch;window.ClearSearch=function(c){if(c){window.advtpSearchCountHide();}return advtpCS.apply(this,arguments);};window.ClearSearch.__advtpCnt=1;}\n";
		}
		// Ecran tactile vu comme un ordinateur (Chrome de l'ELO : UA X11, layout classic) : le coeur remet le focus sur
		// #search apres chaque clic (ClearSearch -> setFocusOnSearchField), Android ouvre alors son clavier et sa barre de
		// navigation (ADVANCEDTAKEPOS_SEARCH_NO_SOFT_KEYBOARD). inputmode="none" garde le focus, donc la douchette, sans
		// clavier ; un tap volontaire dans le champ rend le clavier, un tap ailleurs le range (le blur est voulu : le coeur
		// remet ensuite le focus, sans clavier). Postes sans ecran tactile : rien ne change.
		if (getDolGlobalInt('ADVANCEDTAKEPOS_SEARCH_NO_SOFT_KEYBOARD') == 1) {
			$once .= "(function(){var s=document.getElementById('search');if(!s||s.dataset.advtpNoIme){return;}";
			$once .= "if(!((window.matchMedia&&window.matchMedia('(pointer: coarse)').matches)||navigator.maxTouchPoints>0)){return;}";
			$once .= "s.dataset.advtpNoIme='1';s.setAttribute('inputmode','none');";
			$once .= "if(document.activeElement===s){s.blur();setTimeout(function(){s.focus();},0);}";
			$once .= "s.addEventListener('pointerdown',function(){if(s.getAttribute('inputmode')==='none'){s.removeAttribute('inputmode');if(document.activeElement===s){s.blur();setTimeout(function(){s.focus();},0);}}});";
			$once .= "document.addEventListener('pointerdown',function(e){if(e.target===s||s.getAttribute('inputmode')==='none'){return;}s.setAttribute('inputmode','none');if(document.activeElement===s){s.blur();}},true);})();\n";
		}
		// (Masquage date + Devise + nom client agrandi + badge niveau : geres cote invoice.php par
		//  completeTakePosInvoiceHeader, qui tourne DES LE CHARGEMENT et a chaque changement de panier.)
		// Pager produits : deux boutons flottants < > sur la zone produits ; MoreProducts() pagine les
		// categories ET delegue a Search2 en mode recherche (les tuiles-fleches natives sont vidées par
		// la boucle de reset de Search2 : inutilisables en recherche).
		if (getDolGlobalInt('ADVANCEDTAKEPOS_PAGER') == 1) {
			$pagerhost = (getDolGlobalInt('ADVANCEDTAKEPOS_PAGER_POS') == 1) ? '.div3' : '.div5';
			$once .= "if(\$('#advtp-pager').length===0&&\$('".$pagerhost."').length){\$('".$pagerhost."').append('<div id=\"advtp-pager\"><button type=\"button\" class=\"advtp-pgbtn\" id=\"advtp-pgprev\">&lsaquo;</button><button type=\"button\" class=\"advtp-pgbtn\" id=\"advtp-pgnext\">&rsaquo;</button></div>');";
			unset($pagerhost);
			$once .= "\$('#advtp-pgprev').on('click',function(){if(typeof MoreProducts==='function'){MoreProducts('less');}});";
			$once .= "\$('#advtp-pgnext').on('click',function(){if(typeof MoreProducts==='function'){MoreProducts('more');}});}\n";
		}

		// Taille de grille par appareil : le coeur ne connait qu'un TAKEPOS_NB_MAXPRODUCT global. La taille voulue
		// pour CET appareil part dans un cookie, que overrideTerminalSettings() applique en memoire aux requetes
		// suivantes ; si la grille rendue differe, on recharge (une fois). La constante en base n'est plus touchee,
		// donc un telephone ne change plus la grille des grands ecrans.
		$gm = getDolGlobalInt('ADVANCEDTAKEPOS_MAXPRODUCT_MOBILE');
		$gt = getDolGlobalInt('ADVANCEDTAKEPOS_MAXPRODUCT_TABLET');
		$gd = getDolGlobalInt('ADVANCEDTAKEPOS_MAXPRODUCT_DESKTOP');
		if ($gm > 0 || $gt > 0 || $gd > 0) {
			$once .= "(function(){var want=window.matchMedia('(max-width:767px)').matches?".$gm.":(window.matchMedia('(max-width:1024px)').matches?".$gt.":".$gd.");";
			$once .= "if(want<=0){document.cookie='advtpgrid=;path=/;max-age=0';return;}";
			$once .= "document.cookie='advtpgrid='+want+';path=/;max-age=31536000;SameSite=Lax';";
			$once .= "var slots=0;jQuery('.div5 [id^=prodiv]').each(function(){if(/^prodiv\\d+\$/.test(this.id))slots++;});";
			$once .= "if(slots>0&&slots!==want&&sessionStorage.getItem('advtpGrid')!=String(want)){sessionStorage.setItem('advtpGrid',String(want));location.reload();}})();\n";
		}

		// Fenetre Description de la ligne (bouton Description de la barre d'actions).
		if (getDolGlobalInt('ADVANCEDTAKEPOS_LINE_DESC_EDIT') == 1) {
			$once .= "jQuery.getScript('".dol_buildpath('/advancedtakepos/js/linedesc.js.php', 1).'?v='.((int) @filemtime(__DIR__.'/../js/linedesc.js.php'))."');\n";
		}
		// Bouton Envoyer : popup de choix facture PDF / ticket pour la vente affichee (placeid, pose par invoice.php).
		if (getDolGlobalInt('ADVANCEDTAKEPOS_SEND_BUTTON') == 1) {
			$sendurl = dol_buildpath('/advancedtakepos/ajax/sendchoice.php', 1);
			$sendtitle = dol_escape_js($langs->transnoentitiesnoconv('AdvTakeposSend'));
			$once .= "window.advtpSend=function(){var id=(typeof placeid!=='undefined')?parseInt(placeid,10):0;if(!id){return;}";
			$once .= "jQuery.colorbox({href:'".$sendurl."?facid='+id,width:'70%',height:'60%',transition:'none',iframe:true,title:'".$sendtitle."'});};\n";
		}

		// Mode de saisie : 0 natif brut ; 1 clavier natif + badge en perspective sur la ligne ;
		// 2 popup + clavier complet ; 3 popup seule (clavier reduit aux boutons d'action).
		$inputmode = getDolGlobalInt('ADVANCEDTAKEPOS_INPUT_MODE', 2);
		if ($inputmode >= 2) {
			$once .= "jQuery.getScript('".dol_buildpath('/advancedtakepos/js/editpopup.js.php', 1).'?v='.((int) @filemtime(__DIR__.'/../js/editpopup.js.php'))."');\n";
		} elseif ($inputmode == 1) {
			$once .= "jQuery.getScript('".dol_buildpath('/advancedtakepos/js/editinline.js.php', 1)."');\n";
		}
		return "if(typeof window.__advtakeposOnce==='undefined'){window.__advtakeposOnce=1;\n".$once."}\n";
	}

	/**
	 * Hook completeTakePosInvoiceLine : colonne du prix de reference (PPC) en TTC sur chaque ligne.
	 * La cellule est TOUJOURS emise quand l option est active, meme vide : une cellule manquante
	 * decalerait les colonnes du ticket.
	 *
	 * @param  array<string,mixed>  $parameters  Contient 'line'
	 * @param  CommonObject         $object      Objet courant
	 * @param  string               $action      Action
	 * @param  HookManager          $hookmanager Gestionnaire de hooks
	 * @return int                               0
	 */
	public function completeTakePosInvoiceLine($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $conf;

		if (getDolGlobalInt('ADVANCEDTAKEPOS_LINE_PUBLIC_PRICE_COL') != 1) {
			$this->resprints = '';
			return 0;
		}
		$line = isset($parameters['line']) ? $parameters['line'] : null;
		$ttc = is_object($line) && !empty($line->fk_product) ? $this->refPriceTtc($line->fk_product) : null;

		// Remise "par defaut" : celle deduite du prix de reference par LINE_AT_PUBLIC_PRICE. On la
		// reconnait a ce que le prix unitaire de la ligne EST le prix de reference. Une remise saisie
		// par le caissier s'applique, elle, sur un prix unitaire quelconque.
		$auto = 0;
		if ($ttc !== null && is_object($line) && !empty($line->remise_percent) && !empty($line->fk_product)) {
			$puttc = (float) $line->subprice * (1 + (float) $line->tva_tx / 100);
			$net = $puttc * (1 - (float) $line->remise_percent / 100);
			$niveau1 = $this->levelPriceTtc($line->fk_product, 1);
			// deux conditions : le prix unitaire EST le prix de reference, et le net retombe
			// exactement sur le niveau applique. Une remise accordee a la main descend plus bas.
			$auto = ($niveau1 !== null && abs($puttc - (float) $ttc) < 0.011 && abs($net - (float) $niveau1) < 0.011) ? 1 : 0;
		}
		// PU TTC de la ligne, embarque pour la reecriture de la colonne native (P.U. HT -> TTC)
		$puttcattr = '';
		if (getDolGlobalInt('ADVANCEDTAKEPOS_UNIT_PRICE_TTC') == 1 && is_object($line)) {
			$v = (float) $line->subprice * (1 + (float) $line->tva_tx / 100);
			$puttcattr = ' data-advtp-puttc="'.dol_escape_htmltag(price(price2num($v, 'MT'), 0, $langs, 1, -1, -1).' '.$langs->getCurrencySymbol($conf->currency)).'"';
		}
		// Tarif initial de la ligne (bouton reset du popup) : prix catalogue du niveau du client
		// + prix promo eventuel -- le JS recalcule "PU = catalogue, remise = ecart vers la promo".
		if (is_object($line) && !empty($line->fk_product)) {
			$lvl = (is_object($object) && !empty($object->socid)) ? $this->priceLevelOfSocid($object->socid) : $this->getCustomerLevel();
			$basettc = $this->levelPriceTtc((int) $line->fk_product, (int) $lvl);
			$prttc = null;
			$promolvl = getDolGlobalInt('ADVANCEDTAKEPOS_PROMO_LEVEL');
			if ($promolvl > 0) {
				$prttc = $this->levelPriceTtc((int) $line->fk_product, $promolvl);
			}
			// Sans promo effective, et quand la ligne est facturee au prix de reference
			// (LINE_AT_PUBLIC_PRICE), le tarif initial est celui que pose l'ajout de la ligne :
			// PU = PPC, remise = ecart vers le prix du client. Le PPC devient la base et le prix du
			// client la cible, sinon le bouton remettrait la ligne au net sans remise.
			$promoeffective = ($prttc !== null && $prttc > 0 && $basettc !== null && $prttc < $basettc);
			if (!$promoeffective && getDolGlobalInt('ADVANCEDTAKEPOS_LINE_AT_PUBLIC_PRICE') == 1 && $basettc !== null && $basettc > 0) {
				$publevel = $this->publicPriceLevel();
				if ($publevel != $lvl) {
					$pubttc = $this->levelPriceTtc((int) $line->fk_product, $publevel);
					if ($pubttc !== null && $pubttc > $basettc) {
						$prttc = $basettc;
						$basettc = $pubttc;
					}
				}
			}
			if ($basettc !== null && $basettc > 0) { $puttcattr .= ' data-advtp-basettc="'.price2num($basettc, 'MT').'"'; }
			if ($prttc !== null && $prttc > 0) { $puttcattr .= ' data-advtp-promottc="'.price2num($prttc, 'MT').'"'; }
		}
		$out = '<td class="linecolqty right advtpppc nowraponall" data-advtp-auto="'.$auto.'"'.$puttcattr.'>';
		if ($ttc !== null) {
			// price2num('MT') d'abord : sans arrondi a la devise, un prix de niveau a plus de deux
			// decimales sortirait tel quel (35,375 EUR). Meme traitement que price_ttc_formated du coeur.
			$out .= '<span class="advtpppcval">'.price(price2num($ttc, 'MT'), 1, $langs, 1, -1, -1, $conf->currency).'</span>';
		} else {
			$out .= '<span class="opacitymedium">-</span>';
		}
		$out .= '</td>';

		$this->resprints = $out;
		return 0;
	}

	public function completeTakePosInvoiceHeader($parameters, &$object, &$action, $hookmanager)
	{
		if (empty($parameters['context']) || strpos($parameters['context'], 'takeposinvoice') === false) {
			return 0;
		}
		global $langs;
		$showlevel = (getDolGlobalInt('ADVANCEDTAKEPOS_SHOW_LEVEL_IN_TITLE') == 1);
		$showcart  = (getDolGlobalInt('ADVANCEDTAKEPOS_CART_CUSTOMER') == 1);
		$hidedate  = (getDolGlobalInt('ADVANCEDTAKEPOS_HIDE_TERMINAL_DATE') == 1);
		$hidecurr  = (getDolGlobalInt('ADVANCEDTAKEPOS_HIDE_CURRENCY') == 1);

		$js = '';
		// Bloc une fois du module (styles compris) des le chargement de la page, sans attendre l'affichage d'un article :
		// les vignettes ne tournent pas a l'entree quand la premiere categorie n'a pas d'article.
		$js .= $this->onceJs();

		// Mode 3 : bouton Description de ligne dans la colonne Qte / Prix / Remise ligne, sous Remise ligne.
		// La fenetre (js/linedesc.js.php) est chargee au premier clic si les vignettes ne l'ont pas encore chargee.
		if (getDolGlobalInt('ADVANCEDTAKEPOS_LINE_DESC_EDIT') == 1 && getDolGlobalInt('ADVANCEDTAKEPOS_INPUT_MODE', 2) == 3) {
			$langs->load('advancedtakepos@advancedtakepos');
			$descurl = dol_buildpath('/advancedtakepos/js/linedesc.js.php', 1).'?v='.((int) @filemtime(__DIR__.'/../js/linedesc.js.php'));
			$desclabel = dol_escape_js(dol_escape_htmltag($langs->transnoentitiesnoconv('AdvTakeposLineDesc')));
			$js .= "if(jQuery('#advtpDescBtn').length===0&&jQuery('#reduction').length){";
			$js .= "jQuery('#reduction').after('<button type=\"button\" id=\"advtpDescBtn\" class=\"calcbutton2\">".$desclabel."</button>');";
			$js .= "jQuery('#advtpDescBtn').on('click',function(){if(window.advtpEditDesc){advtpEditDesc();}else{jQuery.getScript('".$descurl."',function(){if(window.advtpEditDesc){advtpEditDesc();}});}});}";
		}

		// Masquage Devise + date, et report de la largeur LIBEREE sur la zone client.
		// On MESURE la largeur reelle de chaque champ masque (dans le vrai navigateur) puis on l'ajoute a la zone
		// client. Mesure/masquage une seule fois (window.__advtpFreed) ; report a CHAQUE Refresh (zone client recreee).
		if ($hidecurr || $hidedate) {
			$measure = '';
			$hideops = '';
			if ($hidecurr) {
				$measure .= "var mc=jQuery('#multicurrency');if(mc.length&&mc.is(':visible')){freed+=mc.outerWidth(true)||0;}";
				$hideops .= "jQuery('#multicurrency').hide();";
			}
			if ($hidedate) {
				$measure .= "jQuery('.topnav-terminalhour span').each(function(){if(/^\\s*-\\s*\\d/.test(jQuery(this).text())){freed+=jQuery(this).outerWidth(true)||0;}});";
				$hideops .= "jQuery('.topnav-terminalhour span').each(function(){if(/^\\s*-\\s*\\d/.test(jQuery(this).text())){jQuery(this).remove();}});";
			}
			// setTimeout : attendre le layout de la barre. Mesure/masquage une seule fois (et re-mesure si 0, au cas ou
			// le layout n'etait pas pret). Report a chaque Refresh via setProperty !important (sinon tdoverflowmax100 gagne).
			$js .= "setTimeout(function(){";
			$js .= "if(typeof window.__advtpFreed==='undefined'||window.__advtpFreed===0){var freed=0;".$measure."if(freed>0){".$hideops."window.__advtpFreed=Math.round(freed);console.log('advtp freed='+window.__advtpFreed);}}";
			$js .= "if(window.__advtpFreed>0 && window.matchMedia('(min-width:1025px)').matches){var cz=jQuery('#customerandsales a').first();if(cz.length){var base=parseInt(getComputedStyle(cz[0]).maxWidth)||100;cz[0].style.setProperty('max-width',(base+window.__advtpFreed)+'px','important');}}";
			$js .= "},60);";
		}

		// Bouton client : classe advtp-cust-set quand la vente n'est pas sur le client par defaut du terminal.
		if (getDolGlobalInt('ADVANCEDTAKEPOS_CUSTOMER_BUTTON') == 1) {
			$custsoc = (is_object($object) && !empty($object->socid)) ? (int) $object->socid : 0;
			$custset = ($custsoc > 0 && $custsoc != $this->terminalDefaultSocid());
			$js .= "jQuery('#customerandsales').toggleClass('advtp-cust-set',".($custset ? 'true' : 'false').");";
		}

		// Niveau de prix du client de la vente : cree/maj le badge a chaque Refresh() (entree + changement de panier).
		if ($showlevel) {
			$socid = (is_object($object) && !empty($object->socid)) ? (int) $object->socid : $this->terminalDefaultSocid();
			$txt = dol_escape_js($this->levelText($this->priceLevelOfSocid($socid)));
			$js .= "var lb=jQuery('#advtakepos-level');";
			$js .= "if(lb.length===0){jQuery('#customerandsales').after('<span id=\"advtakepos-level\" class=\"advtakepos-level\"></span>');lb=jQuery('#advtakepos-level');}";
			$js .= "lb.text('".$txt."');";
		}

		// Entrepot : remplace le bloc texte par une icone compacte, nom en info-bulle et au clic (comme le terminal).
		if (getDolGlobalInt('ADVANCEDTAKEPOS_LIGHT_HEADER') == 1) {
			$js .= "setTimeout(function(){var iw=jQuery('#infowarehouse');if(iw.length){var wt=iw.text().replace(/\\s+/g,' ').trim().replace(/^Entrep[o\\u00f4]t ?/i,'');";
			$js .= "if(jQuery('#advtp-wh').length===0&&jQuery('#advtakepos-level').length){jQuery('#advtakepos-level').after('<span id=\"advtp-wh\" class=\"advtp-wh fa fa-warehouse\"></span>');}";
			$js .= "jQuery('#advtp-wh').attr('title',wt);}},200);";
		}

		// Popup de saisie Qte / Prix / Remise : chargee d'ICI (le hook invoice tourne des le chargement
		// et a chaque Refresh), car le bloc 'une fois' des vignettes ne court que si des produits s'affichent.
		$inputmode = getDolGlobalInt('ADVANCEDTAKEPOS_INPUT_MODE', 2);
		if ($inputmode >= 2) {
			$js .= "if(!window.__advtpEditPopupLoaded){jQuery.getScript('".dol_buildpath('/advancedtakepos/js/editpopup.js.php', 1).'?v='.((int) @filemtime(__DIR__.'/../js/editpopup.js.php'))."');}";
		} elseif ($inputmode == 1) {
			$js .= "if(!window.__advtpEditInlineLoaded){jQuery.getScript('".dol_buildpath('/advancedtakepos/js/editinline.js.php', 1)."');}";
		}

		// Categories masquees : le coeur n'auto-charge aucun produit au demarrage (il attend un clic
		// categorie qui n'existe plus) -> ecran vide. On declenche une recherche '*' si la grille est vide.
		if (getDolGlobalInt('TAKEPOS_HIDE_CATEGORIES') == 1 && getDolGlobalInt('ADVANCEDTAKEPOS_FULLWIDTH_NO_CAT') == 1) {
			$js .= "if(!window.__advtpAutoload){window.__advtpAutoload=1;setTimeout(function(){";
			$js .= "var filled=jQuery('.div5 [id^=prodiv]').filter(function(){return /^prodiv\\d+\$/.test(this.id)&&(jQuery(this).attr('data-rowid')||'')!=='';}).length;";
			$js .= "if(filled===0&&jQuery('#search').length&&jQuery('#search').val()===''){jQuery('#search').val('*');if(typeof Search2==='function'){Search2('',null);}}";
			$js .= "},700);}";
		}

		// Nom du client dans l'info-bulle des paniers.
		if ($showcart) {
			$js .= $this->cartCustomerJs();
		}
		// Panier fractionne en attente : le coeur ne l'affiche jamais parmi les paniers.
		if (getDolGlobalInt('ADVANCEDTAKEPOS_SPLIT_TAB') == 1) {
			$js .= $this->splitTabJs(is_object($object) ? (int) $object->id : 0);
		}
		// En-tete : « Total TTC » devient le reste du (total moins reglements et avoirs imputes). Meme montant sur un
		// brouillon, le reste apres un reglement partiel, 0 sur une vente soldee.
		if (getDolGlobalInt('ADVANCEDTAKEPOS_HEADER_REMAIN') == 1 && is_object($object) && !empty($object->id)) {
			global $conf;
			$langs->load('advancedtakepos@advancedtakepos');
			$flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
			$remain = price(price2num($object->getRemainToPay(), 'MT'), 1, '', 1, -1, -1, $conf->currency);
			$js .= "setTimeout(function(){var lb=".json_encode($langs->transnoentitiesnoconv('TotalTTCShort'), $flags).";";
			$js .= "jQuery('td.linecolht').each(function(){var l=jQuery(this).find('span.opacitymedium').first();if(!l.length||jQuery.trim(l.text())!==lb){return;}";
			$js .= "l.text(".json_encode($langs->transnoentitiesnoconv('AdvTakeposTotalRemain'), $flags).").css('white-space','normal');";
			$js .= "jQuery(this).find('#linecolht-span-total').first().html(".json_encode($remain, $flags).");});},0);";
		}
		// Lignes d'articles sans stock gere : le coeur affiche « (stock 0) » pour toute ligne de produit.
		if (getDolGlobalInt('ADVANCEDTAKEPOS_SERVICE_BADGE') == 1 && is_object($object) && !empty($object->lines)) {
			$svcids = array();
			foreach ($object->lines as $line) {
				if ((int) $line->fk_product > 0 && !$this->isStockManaged((int) $line->fk_product)) {
					$svcids[] = (int) $line->id;
				}
			}
			if ($svcids) {
				$langs->load('advancedtakepos@advancedtakepos');
				$flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
				$js .= "setTimeout(function(){var ids=".json_encode($svcids).",st=".json_encode($langs->transnoentitiesnoconv('Stock'), $flags).",lb=".json_encode($langs->transnoentitiesnoconv('AdvTakeposServiceBadge'), $flags).";";
				$js .= "ids.forEach(function(id){var tr=document.getElementById(String(id));if(!tr){return;}";
				$js .= "jQuery(tr).find('span.opacitylow').filter(function(){return (this.getAttribute('title')||'').indexOf(st)===0;}).each(function(){jQuery(this).replaceWith(jQuery('<span class=\"advtp-svcline\"></span>').text(lb));});});},0);";
			}
		}
		// Avoirs appliques sur un brouillon : ils baissent le total mais ne valident pas la vente. Un bandeau le
		// rappelle tant que la vente n'est pas passee par Reglement.
		if (getDolGlobalInt('ADVANCEDTAKEPOS_CREDIT_HELP') == 1) {
			$notice = '';
			if (is_object($object) && !empty($object->id) && (int) $object->status == Facture::STATUS_DRAFT && self::countCreditLines($object) > 0) {
				$langs->load('advancedtakepos@advancedtakepos');
				$notice = $langs->transnoentitiesnoconv(abs((float) $object->total_ttc) < 0.005 ? 'AdvTakeposCreditNoticeZero' : 'AdvTakeposCreditNotice');
			}
			$flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
			$js .= "setTimeout(function(){jQuery('.advtp-creditnotice').remove();var t=".json_encode($notice, $flags).";if(!t){return;}";
			$js .= "var n=jQuery('<div class=\"advtp-creditnotice\"></div>').text(t);var tb=jQuery('#poslines table').first();if(tb.length){tb.before(n);}else{jQuery('#poslines').prepend(n);}},0);";
		}
		// Vente deja validee (reglee) : le coeur refuse en silence de modifier ses lignes (Facture::updateline ne
		// traite que les brouillons). Les boutons de ligne sont grises et un clic explique pourquoi.
		if (getDolGlobalInt('ADVANCEDTAKEPOS_LOCK_PAID_LINES') == 1) {
			$js .= $this->lockedLinesJs($object);
		}

		// Ouverture de caisse lancee depuis la caisse : terminal de la session preselectionne et seul propose.
		if (getDolGlobalInt('ADVANCEDTAKEPOS_CASHCONTROL_TERMINAL') == 1 && !empty($_SESSION['takeposterminal'])) {
			$js .= $this->cashControlTerminalJs((int) $_SESSION['takeposterminal']);
		}
		// Fenetres de la caisse : taille ajustee au contenu (controle de caisse) et fermeture par Echap.
		$popupfit = (getDolGlobalInt('ADVANCEDTAKEPOS_POPUP_FIT') == 1);
		$popupesc = (getDolGlobalInt('ADVANCEDTAKEPOS_POPUP_ESC') == 1);
		if ($popupfit || $popupesc) {
			$js .= $this->popupJs($popupfit, $popupesc);
		}
		// Statut de la caisse du jour dans l'en-tete, cliquable (ouvrir, rapport, fiche pour rouvrir).
		if (getDolGlobalInt('ADVANCEDTAKEPOS_CASH_STATUS') == 1 && !empty($_SESSION['takeposterminal'])) {
			$js .= $this->cashStatusJs((int) $_SESSION['takeposterminal']);
		}

		// Fiche produit / fiche tiers en popup (pattern quicklistobjectview, en autonome).
		$prodpopup = (getDolGlobalInt('ADVANCEDTAKEPOS_PRODUCT_CARD_POPUP') == 1);
		$socpopup  = (getDolGlobalInt('ADVANCEDTAKEPOS_THIRDPARTY_CARD_POPUP') == 1);
		if ($prodpopup || $socpopup) {
			$js .= $this->cardPopupJs($object, $prodpopup, $socpopup);
		}

		// Lignes du panier : le stock natif "( picto N )" dans la colonne Qte wrappe sur 3 lignes quand la
		// colonne est etroite. Le bloc stock devient une unite insecable (inline-block nowrap) mais la cellule
		// reste cassable entre qty et le bloc : jamais 3 lignes, et pas d'elargissement de table (pas de scroll).
		$js .= "jQuery('#poslines td span.opacitylow').css({'white-space':'nowrap','display':'inline-block'});";

		// Rafraichit la vue produits quand le CLIENT change (les prix affiches dependent de son niveau) :
		// les vignettes ne sont pas rechargees par Refresh(), donc leurs prix resteraient ceux de l'ancien client.
		// #thirdpartyid vient d'etre reecrit par invoice.php ; Search2/MoreProducts le lisent a la requete.
		$cursoc = (is_object($object) && !empty($object->socid)) ? (int) $object->socid : $this->terminalDefaultSocid();
		$js .= "if(window.__advtpLastSoc===undefined){window.__advtpLastSoc=".$cursoc.";}";
		$js .= "else if(window.__advtpLastSoc!==".$cursoc."){window.__advtpLastSoc=".$cursoc.";";
		$js .= "setTimeout(function(){var sv=jQuery('#search').val();";
		$js .= "if(sv&&sv!==''){if(typeof Search2==='function'){Search2('','');}}";
		$js .= "else if(typeof currentcat!=='undefined'&&currentcat!==undefined&&typeof MoreProducts==='function'){MoreProducts(0);}";
		$js .= "},150);}";

		// Recherche videe (retour apres reglement : invoice.php appelle ClearSearch(true) ; saisie effacee) : le coeur
		// vide les vignettes sans requete ajax, donc nos badges de stock et icones fiche restaient sur des cases vides.
		// On les retire, et en option on reaffiche la categorie en cours au lieu d'une grille vide.
		$restorecat = (getDolGlobalInt('ADVANCEDTAKEPOS_RESTORE_CATEGORY') == 1);
		$js .= "if(typeof window.Search2==='function'&&!window.advtpS2){window.advtpS2=1;var advtpS2o=window.Search2;";
		$js .= "window.Search2=function(k,m){var r=advtpS2o.apply(this,arguments);";
		$js .= "if((m===null||typeof m==='undefined')&&jQuery('#search').val()===''){";
		$js .= "if(window.advtpCleanBadges){window.advtpCleanBadges();}if(window.advtpCleanPinfo){window.advtpCleanPinfo();}";
		if ($restorecat) {
			$js .= "if(typeof currentcat!=='undefined'&&currentcat!==undefined&&currentcat!=='supplements'&&typeof LoadProducts==='function'){";
			$js .= "var cp=-1;jQuery('[id^=catdiv]').each(function(){if(/^catdiv\\d+\$/.test(this.id)&&String(jQuery(this).data('rowid'))===String(currentcat)){cp=parseInt(this.id.replace('catdiv',''),10);return false;}});";
			// Sous-categorie : sa vignette a ete remplacee par la recherche, LoadProducts(n, true) relit l'id sur #prodiv<n>.
			$js .= "if(cp>=0){LoadProducts(cp);}else{jQuery('#prodiv0').data('rowid',currentcat);LoadProducts(0,true);}}";
		}
		$js .= "}return r;};}";

		// Bon de reliquat cree au reglement par bon d'achat (trigger du module) : son ticket s'ouvre a l'impression
		// des que TakePOS reaffiche la vente, une seule fois.
		if (!empty($_SESSION['advtp_gv_print']) && is_array($_SESSION['advtp_gv_print']) && isModEnabled('giftvoucher')) {
			$ids = array_map('intval', $_SESSION['advtp_gv_print']);
			unset($_SESSION['advtp_gv_print']);
			$resql = $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."giftvoucher WHERE rowid IN (".implode(',', $ids).") AND status = 1");
			$printurl = dol_buildpath('/giftvoucher/giftvoucher_print.php', 1);
			while ($resql && ($o = $this->db->fetch_object($resql))) {
				// Impression directe de la caisse (module takeposdirectprint) si elle sait imprimer une page, sinon
				// boite d'impression du navigateur dans une colorbox.
				$url = $printurl."?id=".((int) $o->rowid);
				$js .= "(function(){var u='".dol_escape_js($url)."';var fb=function(){jQuery.colorbox({href:u,width:'420px',height:'90%',transition:'none',iframe:true});};";
				$js .= "if(window.TPDP&&typeof TPDP.printUrl==='function'){TPDP.printUrl(u,fb);}else{fb();}})();";
			}
		}

		// Fonction JS de suppression du panier courant (appelee par le bouton ajoute via le hook ActionButtons).
		// Definie ici car invoice.php tourne des le chargement (le bouton peut etre clique avant l'affichage produits).
		if (getDolGlobalInt('ADVANCEDTAKEPOS_DELETE_SALE') == 1) {
			$langs->load('advancedtakepos@advancedtakepos');
			$confirm = dol_escape_js($langs->transnoentitiesnoconv('ConfirmDeletionOfThisPOSSale'));
			$url = dol_escape_js(dol_buildpath('/advancedtakepos/ajax/deletesale.php', 1));
			$tok = newToken();
			// Vraie suppression du brouillon (l'onglet disparait), puis bascule sur le panier principal.
			$js .= "window.advtpDeleteSale=function(){if(typeof place==='undefined')return;";
			$js .= "var advtpDoDel=function(){jQuery.getJSON('".$url."?token=".$tok."&place='+encodeURIComponent(place),function(d){";
			$js .= "if(d&&d.ok){place='0';invoiceid=0;if(typeof ClearSearch==='function'){ClearSearch(false);}jQuery('#idcustomer').val('');if(typeof Refresh==='function'){Refresh();}}";
			$js .= "else{alert((d&&d.error)?d.error:'Erreur suppression du panier');}});};";
			// Confirmation via la modale stylee si dispo (pattern vendeur), sinon confirm() natif.
			$js .= "if(window.advtpConfirm){window.advtpConfirm('".$confirm."',advtpDoDel);}else if(confirm('".$confirm."')){advtpDoDel();}};";
		}

		// La remise deduite du prix de reference est mise en gras, pour la distinguer d'une remise
		// accordee a la main. On repere la colonne par son libelle dans l'en-tete, comme le fait la
		// popup de saisie, puis on stylise la cellule de MEME INDEX sur les lignes marquees.
		if (getDolGlobalInt('ADVANCEDTAKEPOS_LINE_PUBLIC_PRICE_COL') == 1) {
			$lblred = dol_escape_js($langs->transnoentitiesnoconv('ReductionShort'));
			$js .= "window.advtpBoldAuto=function(){";
			$js .= "var hs=jQuery('#poslines table tr:first td'),idx=-1;";
			$js .= "hs.each(function(i){if(idx<0&&jQuery(this).text().indexOf('".$lblred."')>=0){idx=i;}});";
			$js .= "if(idx<0)return;";
			$js .= "jQuery('#poslines tr.posinvoiceline').each(function(){";
			$js .= "var f=jQuery(this).find('td.advtpppc').attr('data-advtp-auto');";
			$js .= "var c=this.cells[idx];if(!c)return;";
			$js .= "c.style.fontWeight=(f==='1')?'bold':'';});};";
			$js .= "window.advtpBoldAuto();";
		}

		// Colonne native du prix unitaire en TTC (option) : l en-tete PriceUHT est repere par son
		// libelle (meme technique que la colonne Reduction) et chaque cellule prend le PU TTC
		// embarque par completeTakePosInvoiceLine. Rejoue a chaque rechargement du panier.
		if (getDolGlobalInt('ADVANCEDTAKEPOS_UNIT_PRICE_TTC') == 1) {
			$js .= "window.advtpPuTtc=function(){var lbl=".json_encode($langs->trans('PriceUHT')).";var nl=".json_encode($langs->trans('PriceUTTC')).";";
			$js .= "var idx=-1;jQuery('#poslines tr.liste_titre td').each(function(i){var t=jQuery(this).text().trim();if(t===lbl||t===nl){idx=i;jQuery(this).html('<span class=\"opacitymedium small\">'+nl+'</span>');return false;}});";
			$js .= "if(idx<0){return;}jQuery('#poslines tr.posinvoiceline').each(function(){var v=jQuery(this).find('td.advtpppc').attr('data-advtp-puttc');var c=this.cells[idx];if(c&&v){c.textContent=v;}});};";
			$js .= "window.advtpPuTtc();";
		}

		// Entete de la colonne du prix de reference : le libelle du niveau s il est nomme, sinon "PPC".
		$head = '';
		if (getDolGlobalInt('ADVANCEDTAKEPOS_LINE_PUBLIC_PRICE_COL') == 1) {
			$lab = getDolGlobalString('PRODUIT_MULTIPRICES_LABEL'.$this->publicPriceLevel());
			if ($lab === '') {
				$lab = $langs->trans('AdvTakeposPublicPriceShort');
			}
			$head = '<td class="linecolqty right advtpppc"><span class="opacitymedium small">'.dol_escape_htmltag($lab).'</span></td>';
		}

		// invoice.php est charge via jQuery.load() qui execute les <script> inline.
		$this->resprints = $head."<script>if(window.jQuery){".$js."}</script>";
		return 0;
	}

	/**
	 * Hook ActionButtons (contexte takeposfrontend) : ajoute un bouton "Supprimer la vente" a la barre d'actions.
	 * Le coeur ajoute les boutons renvoyes dans results (tableau de tableaux de boutons) quand le hook renvoie 0.
	 * Le bouton appelle advtpDeleteSale() (definie par completeTakePosInvoiceHeader), qui reutilise l'action=delete
	 * native avec sa confirmation. En attendant la PR coeur qui offrira ce bouton nativement (TAKEPOS_SHOW_DELETE_SALE).
	 *
	 * @param  array<string,mixed>  $parameters  Contient 'menus'
	 * @param  CommonObject         $object      Objet courant
	 * @param  string               $action      Action
	 * @param  HookManager          $hookmanager Gestionnaire de hooks
	 * @return int                               0 (ajout)
	 */
	public function ActionButtons($parameters, &$object, &$action, $hookmanager)
	{
		if (empty($parameters['context']) || strpos($parameters['context'], 'takeposfrontend') === false) {
			return 0;
		}
		global $langs;
		$langs->load('advancedtakepos@advancedtakepos');
		$buttons = array();
		// Envoyer la facture (PDF) ou le ticket par e-mail, une fois la vente reglee.
		if (getDolGlobalInt('ADVANCEDTAKEPOS_SEND_BUTTON') == 1) {
			$buttons[] = array(
				'title'  => '<span class="fa fa-envelope paddingrightonly"></span><div class="trunc">'.dol_escape_htmltag($langs->trans('AdvTakeposSend')).'</div>',
				'action' => 'advtpSend();',
			);
		}
		// Description (texte libre) de la ligne selectionnee. En mode 3 (pave reduit aux boutons de ligne), le bouton
		// est place sous Remise ligne par completeTakePosInvoiceHeader : il ne figure pas dans la barre d'actions.
		if (getDolGlobalInt('ADVANCEDTAKEPOS_LINE_DESC_EDIT') == 1 && getDolGlobalInt('ADVANCEDTAKEPOS_INPUT_MODE', 2) != 3) {
			$buttons[] = array(
				'title'  => '<span class="fa fa-align-left paddingrightonly"></span><div class="trunc">'.dol_escape_htmltag($langs->trans('AdvTakeposLineDesc')).'</div>',
				'action' => 'if(window.advtpEditDesc){advtpEditDesc();}',
			);
		}
		if ($this->showDeleteSaleButton($parameters)) {
			$buttons[] = array(
				'title'  => '<span class="fa fa-trash-alt paddingrightonly"></span><div class="trunc">'.dol_escape_htmltag($langs->trans('AdvTakeposDeleteSale')).'</div>',
				'action' => 'advtpDeleteSale();',
				// !important : le theme TakePOS "colorful" impose background:#FFB100 !important sur .actionbutton.
				'style'  => 'background-color:#d9534f !important;color:#000 !important;',
			);
		}
		if ($buttons) {
			$this->results = array($buttons);
		}
		return 0;
	}

	/**
	 * Bouton Supprimer la vente : seulement si l'option est active et que le coeur ne l'affiche pas deja.
	 *
	 * @param array<string,mixed> $parameters Parametres du hook ActionButtons (barre recue dans 'menus')
	 * @return bool
	 */
	private function showDeleteSaleButton($parameters)
	{
		if (getDolGlobalInt('ADVANCEDTAKEPOS_DELETE_SALE') != 1) {
			return false;
		}
		// Si la PR coeur (#39745) est appliquee, le bouton natif existe deja : on evite le doublon.
		// La version mergee du coeur n'est PAS gardee par une constante : on detecte le bouton
		// lui-meme dans la barre recue par le hook, quel que soit son mode d'activation.
		if (getDolGlobalInt('TAKEPOS_SHOW_DELETE_SALE') == 1) {
			return false;
		}
		if (!empty($parameters['menus']) && is_array($parameters['menus'])) {
			foreach ($parameters['menus'] as $m) {
				if (!empty($m['action']) && strpos((string) $m['action'], 'DeleteSale();') === 0) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Ticket (hook TakeposReceipt) : imprime la description saisie sous le libelle de chaque ligne.
	 *
	 * takepos/receipt.php imprime SEULEMENT la sortie de ce hook quand elle n'est pas vide (le hook sert a
	 * remplacer le ticket) : on n'ecrit donc rien dans resprints. On complete en memoire le libelle des
	 * lignes de la facture, que le ticket standard imprime tel quel juste apres. La description n'est
	 * reprise que si elle differe du libelle et de la description de la fiche produit (texte du caissier).
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param Facture             $object     Facture du ticket
	 * @param string              $action     Action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int
	 */
	public function TakeposReceipt($parameters, &$object, &$action, $hookmanager)
	{
		if (getDolGlobalInt('ADVANCEDTAKEPOS_RECEIPT_LINE_DESC') != 1 || !is_object($object) || empty($object->lines)) {
			return 0;
		}
		foreach ($object->lines as $line) {
			$desc = trim((string) $line->desc);
			if (empty($line->product_label) || $desc === '' || $desc === trim((string) $line->product_label)) {
				continue;
			}
			if (isset($line->product_desc) && $desc === trim((string) $line->product_desc)) {
				continue;
			}
			// keepn=1 : garder les vrais retours a la ligne pour nl2br (sinon ils deviennent un "\n" litteral).
			$line->product_label .= '<br><small>'.nl2br(dol_escape_htmltag($desc, 0, 1)).'</small>';
		}
		return 0;
	}

	/**
	 * Fenetre Reglement (takepos/pay.php, contexte takepospay) : bouton Annuler le dernier reglement.
	 *
	 * @param array<string,mixed> $parameters  Hook parameters ('action_buttons' = boutons du coeur)
	 * @param Facture             $object      Facture a regler
	 * @param string              $action      Action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int
	 */
	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		if (empty($parameters['context']) || strpos($parameters['context'], 'takepospay') === false || !is_object($object) || empty($object->id)) {
			return 0;
		}
		global $user, $langs;
		$check = self::checkCancelLastPayment($this->db, $user, $object);
		if (!$check['ok']) {
			return 0;
		}
		$langs->load('advancedtakepos@advancedtakepos');
		$this->results = array(array(
			'function' => 'advtpPayCancel();',
			'span' => '',
			'text' => '<span class="fa fa-2x fa-undo iconwithlabel"></span><br>'.dol_escape_htmltag($langs->trans('AdvTakeposPayCancel')),
			'class' => '',
		));
		return 0;
	}

	/**
	 * Liste des clients de la caisse (societe/list.php, contexte poslist) : la ligne entiere choisit le client,
	 * le nom n'y est donc pas un lien. Colonne supplementaire avec un crayon vers la fiche en edition, dans la
	 * meme fenetre ; l'enregistrement ramene a la liste (comme le bouton Nouveau tiers natif).
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @return bool
	 */
	private function posListEditEnabled($parameters)
	{
		global $user;
		return getDolGlobalInt('ADVANCEDTAKEPOS_THIRDPARTY_CARD_POPUP') == 1 && !empty($parameters['context'])
			&& in_array('poslist', explode(':', $parameters['context'])) && $user->hasRight('societe', 'creer');
	}

	/**
	 * @param array<string,mixed> $parameters  Hook parameters
	 * @param CommonObject|null   $object      Object
	 * @param string              $action      Action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int
	 */
	public function printFieldListTitle($parameters, &$object, &$action, $hookmanager)
	{
		if (!$this->posListEditEnabled($parameters)) {
			return 0;
		}
		$this->resprints = '<th class="liste_titre"></th>';
		return 0;
	}

	/**
	 * @param array<string,mixed> $parameters  Hook parameters
	 * @param CommonObject|null   $object      Object
	 * @param string              $action      Action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int
	 */
	public function printFieldListOption($parameters, &$object, &$action, $hookmanager)
	{
		if (!$this->posListEditEnabled($parameters)) {
			return 0;
		}
		$this->resprints = '<td class="liste_titre"></td>';
		return 0;
	}

	/**
	 * @param array<string,mixed> $parameters  Hook parameters
	 * @param CommonObject|null   $object      Object
	 * @param string              $action      Action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int
	 */
	public function printFieldListValue($parameters, &$object, &$action, $hookmanager)
	{
		if (!$this->posListEditEnabled($parameters) || empty($parameters['obj']->rowid)) {
			return 0;
		}
		global $langs;
		$langs->load('advancedtakepos@advancedtakepos');
		$back = DOL_URL_ROOT.'/societe/list.php?type=t&contextpage=poslist&nomassaction=1&optioncss=print&place='.urlencode(GETPOST('place', 'aZ09'));
		$url = DOL_URL_ROOT.'/societe/card.php?socid='.((int) $parameters['obj']->rowid).'&action=edit&contextpage=poslist&optioncss=print&advtpback=1&backtopage='.urlencode($back);
		$this->resprints = '<td class="center nowraponall"><a href="'.dol_escape_htmltag($url).'" onclick="event.stopPropagation();" title="'.dol_escape_htmltag($langs->trans('AdvTakeposEditThirdparty')).'"';
		$this->resprints .= ' style="display:inline-block;padding:6px 12px;font-size:1.4em;">'.img_edit($langs->trans('AdvTakeposEditThirdparty')).'</a></td>';
		if (empty($parameters['i']) && isset($parameters['totalarray']['nbfield'])) {
			$parameters['totalarray']['nbfield']++;
		}
		return 0;
	}

	/**
	 * Fiche tiers ouverte en edition depuis la caisse (marqueur advtpback) : le formulaire d'edition du coeur ne
	 * transmet pas backtopage et perd l'affichage sans menus. On les reporte en champs caches, pour que
	 * l'enregistrement (ou l'annulation) revienne a la liste de la caisse ou a la fiche en popup.
	 *
	 * @param array<string,mixed> $parameters  Hook parameters
	 * @param CommonObject|null   $object      Object
	 * @param string              $action      Action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int
	 */
	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		if (empty($parameters['context']) || !in_array('thirdpartycard', explode(':', $parameters['context'])) || $action !== 'edit'
			|| GETPOSTINT('advtpback') != 1 || getDolGlobalInt('ADVANCEDTAKEPOS_THIRDPARTY_CARD_POPUP') != 1) {
			return 0;
		}
		$back = GETPOST('backtopage', 'alpha');
		if ($back === '' || !preg_match('#^'.preg_quote(DOL_URL_ROOT.'/societe/', '#').'(list|card)\.php\?#', $back)) {
			return 0;
		}
		$out = '<input type="hidden" name="backtopage" value="'.dol_escape_htmltag($back).'">';
		$out .= '<input type="hidden" name="backtopageforcancel" value="'.dol_escape_htmltag($back).'">';
		foreach (array('optioncss' => 'aZ09', 'dol_openinpopup' => 'aZ09', 'contextpage' => 'aZ09') as $k => $f) {
			if (GETPOST($k, $f) !== '') {
				$out .= '<input type="hidden" name="'.$k.'" value="'.dol_escape_htmltag(GETPOST($k, $f)).'">';
			}
		}
		$this->resprints = '<tr class="hideobject"><td colspan="4">'.$out.'</td></tr>';
		return 0;
	}

	/**
	 * Fenetre Reglement (takepos/pay.php, contexte takepospay) : confirmation avant chaque encaissement, avec
	 * les reglements deja enregistres, et fenetre d'annulation du dernier reglement partiel.
	 *
	 * Le coeur encaisse des le clic sur un mode de paiement (fonction JS Validate). La fonction est remplacee
	 * par une fenetre qui rappelle le mode, le montant, le rendu et le reste, puis appelle l'originale.
	 *
	 * @param array<string,mixed> $parameters  Hook parameters
	 * @param Facture             $object      Facture a regler
	 * @param string              $action      Action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int
	 */
	public function completePayment($parameters, &$object, &$action, $hookmanager)
	{
		if (empty($parameters['context']) || strpos($parameters['context'], 'takepospay') === false || !is_object($object) || empty($object->id)) {
			return 0;
		}
		global $user, $langs, $conf;
		$confirm = (getDolGlobalInt('ADVANCEDTAKEPOS_PAY_CONFIRM') == 1);
		$cancel = self::checkCancelLastPayment($this->db, $user, $object);
		$gvcode = self::giftVoucherPaymentCode($this->db);
		$credit0 = (getDolGlobalInt('ADVANCEDTAKEPOS_CREDIT_HELP') == 1 && self::isDraftCoveredByCredits($object));
		if (!$confirm && !$cancel['ok'] && $gvcode === '' && !$credit0) {
			return 0;
		}
		$langs->load('advancedtakepos@advancedtakepos');

		$data = array(
			'confirm' => $confirm,
			'cancel' => $cancel['ok'],
			'payments' => self::invoicePayments($this->db, (int) $object->id),
			'modes' => self::paymentModeLabels($this->db),
			'remain' => (float) $object->getRemainToPay(),
			'total' => (float) $object->total_ttc,
			'type' => (int) $object->type,
			'cur' => $langs->getCurrencySymbol($conf->currency),
			'facid' => (int) $object->id,
			'url' => dol_buildpath('/advancedtakepos/ajax/cancelpayment.php', 1),
			'token' => currentToken(),
			'credit0' => $credit0,
			'gv' => ($gvcode === '' ? null : array(
				'code' => $gvcode,
				'url' => dol_buildpath('/advancedtakepos/ajax/giftvoucher.php', 1),
			)),
		);
		$texts = array();
		foreach (array('title' => 'AdvTakeposPayConfirmTitle', 'titleRefund' => 'AdvTakeposPayConfirmRefund', 'already' => 'AdvTakeposPayAlready',
			'change' => 'AdvTakeposPayChange', 'remainAfter' => 'AdvTakeposPayRemainAfter', 'paidAfter' => 'AdvTakeposPayPaidAfter',
			'delayed' => 'AdvTakeposPayDelayed', 'remain' => 'AdvTakeposPayRemain', 'ok' => 'AdvTakeposPayOk', 'no' => 'AdvTakeposPayBack',
			'cancelTitle' => 'AdvTakeposPayCancelTitle', 'cancelHint' => 'AdvTakeposPayCancelHint', 'cancelOk' => 'AdvTakeposPayCancelOk', 'keep' => 'AdvTakeposPayCancelKeep',
			'gvTitle' => 'AdvTakeposGvTitle', 'gvHint' => 'AdvTakeposGvHint', 'gvValid' => 'AdvTakeposGvValid', 'gvApply' => 'AdvTakeposGvApply',
			'gvRest' => 'AdvTakeposGvRest', 'gvOk' => 'AdvTakeposGvOk', 'gvForce' => 'AdvTakeposGvForce', 'gvClose' => 'AdvTakeposGvClose',
			'credit0' => 'AdvTakeposCreditCovered') as $k => $key) {
			$texts[$k] = $langs->transnoentitiesnoconv($key);
		}
		$flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;

		$js = <<<'JS'
(function (D, T) {
	if (window.advtpPayInit || typeof jQuery === 'undefined') { return; }
	window.advtpPayInit = 1;
	var baseValidate = window.Validate;
	// Vente entierement couverte par des avoirs (total 0) : validation sans encaissement. Le mode differe n'exige
	// aucun compte bancaire ; avec un reste du nul, TakePOS classe la vente payee.
	if (D.credit0 && typeof baseValidate === 'function') {
		jQuery(function () {
			var b = jQuery('<button type="button" id="advtpCredit0"></button>').text(T.credit0)
				.css({display: 'block', width: '96%', margin: '8px auto 12px auto', padding: '18px', fontSize: '1.4em', fontWeight: 'bold', background: '#2e7d32', color: '#fff', border: '0', borderRadius: '8px', cursor: 'pointer'})
				.on('click', function () { jQuery(this).prop('disabled', true); baseValidate('delayed'); });
			var anchor = jQuery('.paymentbordline').first();
			if (anchor.length) { anchor.before(b); } else { jQuery('body').prepend(b); }
		});
	}
	var $ = jQuery;
	function fmt(n) { return (Math.round(Math.abs(n) * 100) / 100).toFixed(2).replace('.', ',') + ' ' + D.cur; }
	function esc(s) { return $('<div>').text(String(s)).html(); }
	$('head').append('<style>'
		+ '#advtpPayOv{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:100000;display:none;align-items:center;justify-content:center;}'
		+ '#advtpPayOv .advtpPayCard{background:#fff;color:#222;border-radius:10px;padding:18px 22px;min-width:300px;max-width:92vw;font-size:1.15em;text-align:center;box-shadow:0 6px 24px rgba(0,0,0,.4);}'
		+ '#advtpPayOv .advtpPayTitle{font-weight:bold;margin-bottom:10px;}'
		+ '#advtpPayOv .advtpPayBig{font-size:1.5em;font-weight:bold;margin:10px 0;}'
		+ '#advtpPayOv .advtpPayRemain{color:#c62828;margin:6px 0;}'
		+ '#advtpPayOv .advtpPayDone{color:#2e7d32;margin:6px 0;font-weight:bold;}'
		+ '#advtpPayOv .advtpPayChange{margin:6px 0;}'
		+ '#advtpPayOv table{margin:0 auto 6px auto;border-collapse:collapse;font-size:.9em;}'
		+ '#advtpPayOv td,#advtpPayOv th{padding:2px 8px;text-align:left;} #advtpPayOv td.r{text-align:right;}'
		+ '#advtpPayOv .advtpPayBtns{margin-top:14px;display:flex;gap:12px;justify-content:center;}'
		+ '#advtpPayOv .advtpPayBtns button{font-size:1.1em;padding:14px 22px;border:0;border-radius:6px;min-width:130px;cursor:pointer;}'
		+ '#advtpPayOv .advtpPayOk{background:#2e7d32;color:#fff;} #advtpPayOv .advtpPayDanger{background:#c62828;color:#fff;} #advtpPayOv .advtpPayNo{background:#ddd;color:#222;}'
		+ '.advtpPayCompo{font-size:.9em;}'
		+ '</style>');
	$('body').append('<div id="advtpPayOv"><div class="advtpPayCard"><div id="advtpPayBody"></div>'
		+ '<div class="advtpPayBtns"><button type="button" id="advtpPayGo"></button><button type="button" id="advtpPayNo" class="advtpPayNo"></button></div></div></div>');
	var pending = null;
	function ask(html, okText, okClass, cb, noText) {
		$('#advtpPayBody').html(html);
		$('#advtpPayGo').text(okText).attr('class', okClass).prop('disabled', false).show();
		$('#advtpPayNo').text(noText || T.no);
		pending = cb;
		$('#advtpPayOv').css('display', 'flex');
	}
	function close() { $('#advtpPayOv').hide(); pending = null; }
	$('#advtpPayNo').on('click', close);
	$('#advtpPayGo').on('click', function () {
		var cb = pending;
		if (!cb) { return; }
		pending = null;
		$(this).prop('disabled', true);
		cb();
	});
	function compo() {
		if (!D.payments.length) { return ''; }
		var h = '<table><tr><th colspan="2">' + esc(T.already) + '</th></tr>';
		$.each(D.payments, function (i, p) {
			h += '<tr><td>' + esc(p.label) + ' <span class="opacitymedium">' + esc(p.time) + '</span></td><td class="r">' + fmt(p.amount) + '</td></tr>';
		});
		return h + '</table>';
	}
	function modeLabel(code) {
		if (D.modes && D.modes[code]) { return D.modes[code]; }
		var b = $('button').filter(function () { return (this.getAttribute('onclick') || '') === "Validate('" + code + "')"; }).first();
		var t = b.length ? $.trim(b.text()) : '';
		return t !== '' ? t : code;
	}
	// Composition visible dans la fenetre de reglement elle-meme.
	if (D.payments.length) {
		var line = [];
		$.each(D.payments, function (i, p) { line.push(esc(p.label) + ' ' + fmt(p.amount)); });
		var block = '<div class="paymentbordline center advtpPayCompo"><span class="takepospay colorwhite">' + esc(T.already) + ' : ' + line.join(' + ') + '</span></div>';
		var anchor = $('.paymentbordlinereceived').first();
		if (anchor.length) { anchor.before(block); }
	}
	// Bon d'achat : le bouton du mode BONACH ouvre la fenetre de scan ; le reglement TakePOS part apres la
	// verification et la mise en reserve du bon (ajax/giftvoucher.php), au montant min(bon, reste).
	function gvDialog(orig) {
		var last = null;
		var h = '<div class="advtpPayTitle">' + esc(T.gvTitle) + '</div>'
			+ '<input type="text" id="advtpGvCode" autocomplete="off" style="font-size:1.3em;padding:8px;width:90%;text-align:center;" placeholder="' + esc(T.gvHint) + '">'
			+ '<div id="advtpGvRes" style="margin-top:10px;min-height:3em;"></div>';
		function arm() {
			if (!last) { return; }
			$.post(D.gv.url, {action: 'arm', token: D.token, facid: D.facid, code: last.ref, force: (last.state === 'expired' ? 1 : 0)}, function (r) {
				if (r && r.ok) {
					close();
					$('#change1').val(r.apply);
					$('#change2').val(0);
					orig(D.gv.code);
				} else {
					$('#advtpGvRes').html('<div class="advtpPayRemain">' + esc((r && (r.error || r.message)) ? (r.error || r.message) : 'Error') + '</div>');
					$('#advtpPayGo').prop('disabled', true);
				}
			}, 'json').fail(function () { $('#advtpGvRes').html('<div class="advtpPayRemain">Error</div>'); });
		}
		ask(h, T.gvOk, 'advtpPayOk', arm, T.gvClose);
		pending = null;
		$('#advtpPayGo').prop('disabled', true).hide();
		function check() {
			var code = $.trim($('#advtpGvCode').val());
			if (code === '') { return; }
			last = null;
			$('#advtpPayGo').prop('disabled', true).hide();
			$('#advtpGvRes').html('...');
			$.getJSON(D.gv.url, {action: 'check', facid: D.facid, code: code}, function (r) {
				if (!r || !r.ref) {
					$('#advtpGvRes').html('<div class="advtpPayRemain">' + esc(r && (r.message || r.error) ? (r.message || r.error) : 'Error') + '</div>');
				} else if (r.state === 'valid' || (r.state === 'expired' && r.canforce)) {
					last = r;
					var html = '<div><b>' + esc(r.ref) + '</b> : ' + fmt(r.amount) + (r.validity ? ' &mdash; ' + esc(T.gvValid) + ' ' + esc(r.validity) : '') + '</div>';
					if (r.state === 'expired') { html += '<div class="advtpPayRemain">' + esc(r.message) + '</div>'; }
					html += '<div class="advtpPayBig">' + esc(T.gvApply) + ' : ' + fmt(r.apply) + '</div>';
					html += (r.rest > 0.004) ? '<div class="advtpPayChange">' + esc(T.gvRest) + ' : <b>' + fmt(r.rest) + '</b></div>'
						: ((r.remain - r.apply > 0.004) ? '<div class="advtpPayRemain">' + esc(T.remainAfter) + ' : <b>' + fmt(r.remain - r.apply) + '</b></div>' : '<div class="advtpPayDone">' + esc(T.paidAfter) + '</div>');
					$('#advtpGvRes').html(html);
					pending = arm;
					$('#advtpPayGo').text(r.state === 'expired' ? T.gvForce : T.gvOk).prop('disabled', false).show();
				} else {
					$('#advtpGvRes').html('<div><b>' + esc(r.ref) + '</b> : ' + fmt(r.amount) + '</div><div class="advtpPayRemain">' + esc(r.message || '') + '</div>');
				}
				$('#advtpGvCode').val('').focus();
			}).fail(function () { $('#advtpGvRes').html('<div class="advtpPayRemain">Error</div>'); });
		}
		// Douchette : le code arrive d'un bloc, suivi ou non de Entree.
		var timer = null;
		$('#advtpGvCode').on('keydown', function (e) { if (e.keyCode === 13) { e.preventDefault(); clearTimeout(timer); check(); } })
			.on('input', function () { clearTimeout(timer); if ($.trim($(this).val()).length >= 6) { timer = setTimeout(check, 500); } }).focus();
	}
	if ((D.confirm || D.gv) && typeof window.Validate === 'function') {
		var orig = window.Validate;
		window.Validate = function (payment) {
			if (D.gv && payment === D.gv.code) { gvDialog(orig); return true; }
			if (!D.confirm) { return orig(payment); }
			var remain = Math.abs(D.remain);
			var rec = parseFloat($('#change1').val()) || 0;
			var amt = rec;
			if (amt > Math.abs(D.total)) { amt = Math.abs(D.total); }
			if (amt <= 0 || amt > remain) { amt = remain; }
			var change = parseFloat($('#change2').val()) || 0;
			var h;
			if (payment === 'delayed') {
				h = '<div class="advtpPayBig">' + esc(T.delayed) + '</div>' + compo() + '<div class="advtpPayRemain">' + esc(T.remain) + ' : <b>' + fmt(remain) + '</b></div>';
			} else {
				var after = Math.round((remain - amt) * 100) / 100;
				h = '<div class="advtpPayTitle">' + esc(D.type === 2 ? T.titleRefund : T.title) + '</div>' + compo();
				h += '<div class="advtpPayBig">' + esc(modeLabel(payment)) + ' : ' + fmt(amt) + '</div>';
				if (change > 0 && payment === 'LIQ') { h += '<div class="advtpPayChange">' + esc(T.change) + ' : <b>' + fmt(change) + '</b></div>'; }
				h += (after > 0.004) ? '<div class="advtpPayRemain">' + esc(T.remainAfter) + ' : <b>' + fmt(after) + '</b></div>' : '<div class="advtpPayDone">' + esc(T.paidAfter) + '</div>';
			}
			ask(h, T.ok, 'advtpPayOk', function () { close(); orig(payment); });
			return true;
		};
	}
	if (D.cancel) {
		window.advtpPayCancel = function () {
			var p = D.payments[D.payments.length - 1];
			if (!p) { return; }
			var h = '<div class="advtpPayTitle">' + esc(T.cancelTitle) + '</div>'
				+ '<div class="advtpPayBig">' + esc(p.label) + ' : ' + fmt(p.amount) + ' <span class="opacitymedium">' + esc(p.time) + '</span></div>'
				+ '<div>' + esc(T.cancelHint) + '</div>';
			ask(h, T.cancelOk, 'advtpPayDanger', function () {
				$.post(D.url, {token: D.token, facid: D.facid, paymentid: p.id}, function (r) {
					if (r && r.ok) {
						try { if (window.parent && typeof window.parent.Refresh === 'function') { window.parent.Refresh(); } } catch (e) {}
						location.reload();
					} else {
						close();
						alert((r && r.error) ? r.error : 'Error');
					}
				}, 'json').fail(function () { close(); alert('Error'); });
			}, T.keep);
		};
	}
})
JS;
		$this->resprints = '<script>'.$js.'('.json_encode($data, $flags).', '.json_encode($texts, $flags).');</script>';
		return 0;
	}

	/**
	 * Code du mode de paiement Bon d'achat (GIFTVOUCHER_PAYMENT_MODE_ID) quand le reglement par bon en caisse est
	 * actif, chaine vide sinon.
	 *
	 * @param DoliDB $db Database handler
	 * @return string
	 */
	private static function giftVoucherPaymentCode($db)
	{
		$modeid = getDolGlobalInt('GIFTVOUCHER_PAYMENT_MODE_ID');
		if (getDolGlobalInt('ADVANCEDTAKEPOS_GIFTVOUCHER') != 1 || !isModEnabled('giftvoucher') || $modeid <= 0) {
			return '';
		}
		$resql = $db->query("SELECT code FROM ".MAIN_DB_PREFIX."c_paiement WHERE id = ".((int) $modeid));
		$o = $resql ? $db->fetch_object($resql) : null;
		return $o ? (string) $o->code : '';
	}

	/**
	 * Brouillon de caisse dont des avoirs (lignes de credit) ramenent le total a zero.
	 *
	 * @param Facture $invoice Facture
	 * @return bool
	 */
	private static function isDraftCoveredByCredits($invoice)
	{
		if (!is_object($invoice) || empty($invoice->id) || (int) $invoice->status != Facture::STATUS_DRAFT || abs((float) $invoice->total_ttc) >= 0.005) {
			return false;
		}
		return self::countCreditLines($invoice) > 0;
	}

	/**
	 * Nombre de lignes d'avoir (credit applique, fk_remise_except) de la facture.
	 *
	 * @param Facture $invoice Facture
	 * @return int
	 */
	private static function countCreditLines($invoice)
	{
		$n = 0;
		if (!empty($invoice->lines)) {
			foreach ($invoice->lines as $line) {
				if (!empty($line->fk_remise_except)) {
					$n++;
				}
			}
		}
		return $n;
	}

	/**
	 * Libelle court de chaque mode de paiement, par code (les boutons de la fenetre Reglement peuvent n'avoir
	 * qu'une icone).
	 *
	 * @param DoliDB $db Database handler
	 * @return array<string,string>
	 */
	private static function paymentModeLabels($db)
	{
		global $langs;
		$out = array();
		$resql = $db->query("SELECT code, libelle FROM ".MAIN_DB_PREFIX."c_paiement WHERE entity IN (".getEntity('c_paiement').")");
		if ($resql) {
			while ($o = $db->fetch_object($resql)) {
				$label = $langs->transnoentitiesnoconv('PaymentTypeShort'.$o->code);
				$out[$o->code] = ($label === 'PaymentTypeShort'.$o->code) ? (string) $o->libelle : $label;
			}
		}
		return $out;
	}

	/**
	 * Reglements d'une facture, du plus ancien au plus recent.
	 *
	 * @param DoliDB $db    Database handler
	 * @param int    $facid Facture
	 * @return array<int,array<string,mixed>> id, label (mode), amount, time (heure de saisie)
	 */
	public static function invoicePayments($db, $facid)
	{
		global $langs;
		$out = array();
		$sql = "SELECT p.rowid, p.datec, pf.amount, c.code, c.libelle";
		$sql .= " FROM ".MAIN_DB_PREFIX."paiement_facture as pf";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."paiement as p ON p.rowid = pf.fk_paiement";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."c_paiement as c ON c.id = p.fk_paiement";
		$sql .= " WHERE pf.fk_facture = ".((int) $facid);
		$sql .= " ORDER BY p.datec ASC, p.rowid ASC";
		$resql = $db->query($sql);
		if ($resql) {
			while ($o = $db->fetch_object($resql)) {
				$label = $langs->transnoentitiesnoconv('PaymentTypeShort'.$o->code);
				if ($label === 'PaymentTypeShort'.$o->code) {
					$label = (string) $o->libelle;
				}
				$out[] = array(
					'id' => (int) $o->rowid,
					'label' => $label,
					'amount' => (float) $o->amount,
					'time' => dol_print_date($db->jdate($o->datec), 'hour', 'tzuserrel'),
				);
			}
		}
		return $out;
	}

	/**
	 * Le dernier reglement de cette vente TakePOS peut-il etre annule depuis la caisse ?
	 *
	 * Seulement pour corriger une erreur de saisie avant que la vente soit soldee : option active, droits de
	 * caisse et de reglement, vente TakePOS standard validee et non payee, reglement du jour propre a cette
	 * vente, ligne bancaire non rapprochee, caisse du terminal non cloturee aujourd'hui.
	 *
	 * @param DoliDB  $db      Database handler
	 * @param User    $user    Utilisateur
	 * @param Facture $invoice Facture
	 * @return array{ok:bool,error:string,paymentid:int}
	 */
	public static function checkCancelLastPayment($db, $user, $invoice)
	{
		global $langs;
		require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
		$ko = function ($msg) {
			return array('ok' => false, 'error' => $msg, 'paymentid' => 0);
		};
		if (getDolGlobalInt('ADVANCEDTAKEPOS_PAY_CANCEL') != 1) {
			return $ko('Disabled');
		}
		if (empty($user->id) || !$user->hasRight('takepos', 'run') || !$user->hasRight('facture', 'paiement')) {
			return $ko($langs->trans('NotEnoughPermissions'));
		}
		if ($invoice->module_source !== 'takepos' || (int) $invoice->type != Facture::TYPE_STANDARD
			|| (int) $invoice->status != Facture::STATUS_VALIDATED || !empty($invoice->paye)) {
			return $ko($langs->trans('AdvTakeposPayCancelNotOpen'));
		}
		$sql = "SELECT p.rowid, p.datec, p.fk_bank, (SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."paiement_facture as pf2 WHERE pf2.fk_paiement = p.rowid) as nbinv";
		$sql .= " FROM ".MAIN_DB_PREFIX."paiement_facture as pf";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."paiement as p ON p.rowid = pf.fk_paiement";
		$sql .= " WHERE pf.fk_facture = ".((int) $invoice->id);
		$sql .= " ORDER BY p.datec DESC, p.rowid DESC";
		$sql .= $db->plimit(1);
		$resql = $db->query($sql);
		$o = $resql ? $db->fetch_object($resql) : null;
		if (!$o) {
			return $ko($langs->trans('AdvTakeposPayCancelNone'));
		}
		if ((int) $o->nbinv != 1 || dol_print_date($db->jdate($o->datec), '%Y%m%d') != dol_print_date(dol_now(), '%Y%m%d')) {
			return $ko($langs->trans('AdvTakeposPayCancelNotToday'));
		}
		if ((int) $o->fk_bank > 0) {
			$resql = $db->query("SELECT rappro FROM ".MAIN_DB_PREFIX."bank WHERE rowid = ".((int) $o->fk_bank));
			$b = $resql ? $db->fetch_object($resql) : null;
			if ($b && !empty($b->rappro)) {
				return $ko($langs->trans('AdvTakeposPayCancelReconciled'));
			}
		}
		// Une caisse cloturee a deja compte ce reglement : on ne le retire plus.
		$sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."pos_cash_fence";
		$sql .= " WHERE entity = ".((int) $invoice->entity)." AND posmodule = 'takepos' AND posnumber = '".$db->escape((string) $invoice->pos_source)."'";
		$sql .= " AND status = 1 AND date_creation > '".$db->idate(dol_get_first_hour(dol_now()))."'";
		$resql = $db->query($sql);
		$f = $resql ? $db->fetch_object($resql) : null;
		if ($f && (int) $f->nb > 0) {
			return $ko($langs->trans('AdvTakeposPayCancelFenceClosed'));
		}
		return array('ok' => true, 'error' => '', 'paymentid' => (int) $o->rowid);
	}

	/**
	 * JS qui ajoute le nom du client a l'info-bulle de chaque onglet panier.
	 * Reproduit cote module la liste des factures POS ouvertes du terminal (meme WHERE qu'invoice.php),
	 * jointe au tiers pour le nom, puis mappe par invoiceid sur les onglets deja rendus.
	 *
	 * @return string JS (vide si rien a faire)
	 */
	private function cartCustomerJs()
	{
		$term = empty($_SESSION['takeposterminal']) ? 1 : (int) $_SESSION['takeposterminal'];
		$defsoc = $this->terminalDefaultSocid(); // client par defaut du terminal (panier "vide")
		$sql = "SELECT f.rowid, f.fk_soc, s.nom as customer, f.datec FROM ".MAIN_DB_PREFIX."facture as f";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = f.fk_soc";
		$sql .= " WHERE f.entity IN (".getEntity('invoice').")";
		if (!getDolGlobalString('TAKEPOS_CAN_EDIT_IF_ALREADY_VALIDATED')) {
			$sql .= " AND f.ref LIKE '(PROV-POS".$this->db->escape((string) $term)."-%'";
		} else {
			$sql .= " AND f.pos_source = '".$this->db->escape((string) $term)."' AND f.module_source = 'takepos'";
		}
		$map = array();
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($o = $this->db->fetch_object($resql)) {
				$map[(int) $o->rowid] = array(
					'n' => (string) $o->customer,
					'd' => dol_print_date($this->db->jdate($o->datec), 'dayhour'),
					// def=1 : client par defaut du terminal (panier neuf/vide) -> on garde l'heure, pas le nom.
					'def' => ((int) $o->fk_soc === (int) $defsoc) ? 1 : 0,
				);
			}
		}
		if (empty($map)) {
			return '';
		}
		$json = json_encode($map, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
		// setTimeout : les onglets #shoppingcart sont construits par un autre script ; on agit APRES qu'ils existent.
		// Onglet = NOM DU CLIENT (l'heure seule n'a pas de sens pour un panier vieux de plusieurs jours) ;
		// info-bulle = nom complet + jour/heure + reference.
		$js  = "var advtpCart=".$json.";";
		$js .= "setTimeout(function(){jQuery('#shoppingcart a').each(function(){";
		$js .= "var oc=jQuery(this).attr('onclick')||'';var mm=oc.match(/invoiceid=\\'?(\\d+)/);if(!mm||!advtpCart[mm[1]])return;";
		$js .= "var c=advtpCart[mm[1]];var name=(c.n||'').replace(/\\s*\\[\\d+\\]\\s*\$/,'');";
		$js .= "var oldtitle=jQuery(this).attr('title')||'';var refm=oldtitle.match(/\\(PROV[^)]*\\)/);var ref=refm?refm[0]:'';";
		$js .= "jQuery(this).attr('title',(c.n?c.n+' \\u2014 ':'')+c.d+(ref?' \\u2014 '+ref:''));";
		// Nom sur l'onglet UNIQUEMENT si un vrai client est choisi (pas le client par defaut du terminal) ; sinon on garde l'heure.
		$js .= "if(name&&!c.def){var sp=jQuery(this).find('.basketselected,.basketnotselected').first();if(sp.length){sp.contents().filter(function(){return this.nodeType===3;}).remove();var disp=name.length>16?name.slice(0,15)+'\\u2026':name;sp.append(document.createTextNode(' '+disp));}}";
		$js .= "});},80);";
		return $js;
	}

	/**
	 * JS de la fenetre d'ouverture de caisse (cashcontrol_card.php?action=create&contextpage=takepos). Avec plusieurs
	 * terminaux, le coeur n'y preselectionne aucun terminal ; une ouverture saisie pour un autre terminal ne compte pas
	 * pour cette caisse, qui rouvre alors la fenetre a chaque chargement, en boucle. Le terminal de la session est passe
	 * dans l'URL (preselection native par GETPOST) et c'est le seul laisse dans la liste.
	 *
	 * L'ouverture automatique part au chargement de index.php, avant ce script (charge avec le panier) : l'iframe deja
	 * ouverte est corrigee a la volee, les ouvertures suivantes passent par l'evenement cbox_complete de colorbox.
	 *
	 * @param int $term Terminal de la session TakePOS
	 * @return string   JS
	 */
	private function cashControlTerminalJs($term)
	{
		$js = "window.advtpCashTerm=".((int) $term).";";
		$js .= "if(!window.advtpCashFix){window.advtpCashFix=function(){";
		$js .= "var f=jQuery('#colorbox iframe').get(0);if(!f||!/cashcontrol_card\\.php/.test(f.src||''))return;";
		$js .= "if(!f.advtpCashLock){f.advtpCashLock=1;f.addEventListener('load',function(){window.advtpCashLockSel(f);});}";
		// Formulaire de depart sans terminal : recharge avec celui de la caisse, que le coeur preselectionne (GETPOST).
		$js .= "if(/[?&]action=create/.test(f.src)&&!/[?&]posnumber=/.test(f.src)){f.src=f.src+'&posmodule=takepos&posnumber='+window.advtpCashTerm;return;}";
		$js .= "window.advtpCashLockSel(f);};";
		// Seul le terminal de la caisse reste dans la liste (l'etape suivante n'a plus de liste : rien a faire).
		$js .= "window.advtpCashLockSel=function(f){try{var d=f.contentDocument,s=d&&d.getElementById('posnumber');if(!s||s.tagName!=='SELECT')return;";
		$js .= "var t=String(window.advtpCashTerm);if(!s.querySelector('option[value=\"'+t+'\"]'))return;";
		$js .= "Array.prototype.slice.call(s.options).forEach(function(o){if(o.value!==t){o.parentNode.removeChild(o);}});s.value=t;";
		$js .= "var w=f.contentWindow;if(w&&w.jQuery){w.jQuery(s).trigger('change');}}catch(e){}};";
		$js .= "jQuery(document).on('cbox_complete',function(){window.advtpCashFix();});}";
		$js .= "window.advtpCashFix();";
		return $js;
	}

	/**
	 * JS des fenetres de la caisse (colorbox en iframe, tailles fixes en % dans le coeur).
	 * - $fit : les fenetres du controle de caisse (ouverture, cloture, rapport) prennent la taille de leur contenu, a
	 *   chaque chargement de l'iframe (etape suivante comprise), dans la limite de 95 % x 90 % de l'ecran ;
	 * - $esc : Echap ferme la fenetre meme quand le clavier est dans l'iframe (colorbox ne l'ecoute que sur la page).
	 *   Une touche deja traitee dans l'iframe (liste deroulante, boite de confirmation : preventDefault) est laissee.
	 * Pose une fois ; la fenetre deja ouverte au chargement (ouverture de caisse automatique) est traitee aussitot.
	 *
	 * @param bool $fit Ajuster la taille des fenetres du controle de caisse
	 * @param bool $esc Fermer par Echap
	 * @return string   JS
	 */
	private function popupJs($fit, $esc)
	{
		$js = "window.advtpPopFit=".($fit ? 1 : 0).";window.advtpPopEsc=".($esc ? 1 : 0).";";
		$js .= "if(!window.advtpPopBound){window.advtpPopBound=1;";
		$js .= "window.advtpPopResize=function(f){try{var d=f.contentDocument,b=d&&d.body;if(!b)return;";
		// Les menus de Dolibarr sont encore affiches au chargement de l'iframe : le coeur ne les masque qu'ensuite
		// (lib_head.js.php, page dans une iframe). Ils sont masques le temps des mesures pour ne pas les compter.
		$js .= "var hide=function(){var l=[].slice.call(d.querySelectorAll('.side-nav-vert,.side-nav,.websitebar'));l.forEach(function(e){e.advtpOd=e.style.display;e.style.display='none';});";
		$js .= "return function(){l.forEach(function(e){e.style.display=e.advtpOd;});};};";
		// Largeur naturelle du contenu : le corps en inline-block se reduit a son contenu le temps de la mesure.
		$js .= "var rs=hide(),od=b.style.display;b.style.display='inline-block';var w=Math.ceil(b.getBoundingClientRect().width)+30;b.style.display=od;rs();";
		$js .= "var mw=Math.round(window.innerWidth*0.95),mh=Math.round(window.innerHeight*0.9);w=Math.min(mw,Math.max(480,w));";
		$js .= "jQuery.colorbox.resize({innerWidth:w});";
		$js .= "setTimeout(function(){var rs=hide(),h=Math.ceil(d.documentElement.scrollHeight)+10;rs();jQuery.colorbox.resize({innerWidth:w,innerHeight:Math.min(mh,Math.max(160,h))});},60);";
		$js .= "}catch(e){}};";
		$js .= "window.advtpPopAttach=function(f){var d,p='';try{d=f.contentDocument;p=f.contentWindow.location.pathname;}catch(e){return;}if(!d||!d.documentElement)return;";
		$js .= "if(window.advtpPopEsc&&!d.advtpEsc){d.advtpEsc=1;d.addEventListener('keydown',function(e){if((e.key==='Escape'||e.keyCode===27)&&!e.defaultPrevented){jQuery.colorbox.close();}});}";
		$js .= "if(window.advtpPopFit&&/\\/compta\\/cashcontrol\\/(cashcontrol_card|report)\\.php$/.test(p)){window.advtpPopResize(f);}};";
		$js .= "window.advtpPopHook=function(){var f=jQuery('#colorbox iframe').get(0);if(!f)return;";
		$js .= "if(!f.advtpPopLoad){f.advtpPopLoad=1;f.addEventListener('load',function(){window.advtpPopAttach(f);});}";
		$js .= "if(f.contentDocument&&f.contentDocument.readyState==='complete'){window.advtpPopAttach(f);}};";
		$js .= "jQuery(document).on('cbox_complete',function(){window.advtpPopHook();});}";
		$js .= "window.advtpPopHook();";
		return $js;
	}

	/**
	 * JS de l'indicateur de statut de la caisse du jour, a droite du nom du terminal : « non ouverte » (clic = ouvrir la
	 * caisse), « ouverte » (clic = rapport de caisse) ou « cloturee » (clic = fiche du controle de caisse, ou se trouve le
	 * bouton Rouvrir du coeur). Recalcule a chaque rendu du panier. La derniere ouverture du jour fait foi.
	 *
	 * @param int $term Terminal de la session TakePOS
	 * @return string   JS
	 */
	private function cashStatusJs($term)
	{
		global $conf, $langs;
		require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php'; // dol_get_first_hour(), pas toujours charge par invoice.php
		$langs->load('advancedtakepos@advancedtakepos');
		$rowid = 0;
		$status = -1;
		$sql = "SELECT rowid, status FROM ".MAIN_DB_PREFIX."pos_cash_fence";
		$sql .= " WHERE entity = ".((int) $conf->entity)." AND posnumber = '".$this->db->escape((string) $term)."'";
		$sql .= " AND date_creation > '".$this->db->idate(dol_get_first_hour(dol_now()))."'";
		$sql .= " ORDER BY rowid DESC";
		$resql = $this->db->query($sql.$this->db->plimit(1));
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			$rowid = (int) $obj->rowid;
			$status = (int) $obj->status;
		}
		if ($status < 0) {
			$state = 'none';
			$label = $langs->transnoentitiesnoconv('AdvTakeposCashStatusNone');
			$title = $langs->transnoentitiesnoconv('AdvTakeposCashStatusNoneHelp');
			$onclick = 'ControlCashOpening();';
			$icon = 'fa-exclamation-triangle';
		} elseif ($status == 0) {
			$state = 'open';
			$label = $langs->transnoentitiesnoconv('AdvTakeposCashStatusOpen');
			$title = $langs->transnoentitiesnoconv('AdvTakeposCashStatusOpenHelp');
			$onclick = 'CashReport('.$rowid.');';
			$icon = 'fa-lock-open';
		} else {
			$state = 'closed';
			$label = $langs->transnoentitiesnoconv('AdvTakeposCashStatusClosed');
			$title = $langs->transnoentitiesnoconv('AdvTakeposCashStatusClosedHelp');
			$onclick = 'CloseCashFence('.$rowid.');';
			$icon = 'fa-lock';
		}
		$flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
		// Place apres le bloc du terminal, pas dans ce bloc : le lien du terminal y flotte et le badge se calait en haut
		// de la barre. Hors du bloc, il est centre dans la barre comme les autres elements.
		$js = "(function(){var th=jQuery('a.topnav-terminalhour').first();if(!th.length){return;}";
		$js .= "var b=jQuery('#advtpCashState');if(!b.length){b=jQuery('<span id=\"advtpCashState\" class=\"advtp-cashstate\" role=\"button\"></span>');var hb=th.parent('.inline-block');(hb.length?hb:th).after(b);}";
		$js .= "b.attr('class','advtp-cashstate advtp-cashstate-".$state."').attr('title',".json_encode($title, $flags).").empty()";
		$js .= ".append(jQuery('<span class=\"fa ".$icon."\"></span>'),jQuery('<span></span>').text(".json_encode($label, $flags)."));";
		$js .= "b.off('click.advtp').on('click.advtp',function(e){e.preventDefault();e.stopPropagation();".$onclick."});})();";
		return $js;
	}

	/**
	 * JS du verrou des boutons de ligne (colonne .div2 : Qte, Prix, Remise ligne, Description, C, corbeille) quand la
	 * vente affichee n'est plus un brouillon. Ecouteur pose une fois en phase de capture : il arrete le clic avant
	 * les onclick du coeur et des popups du module ; l'etat est remis a jour a chaque rendu d'invoice.php.
	 *
	 * @param Facture|null $object Vente affichee
	 * @return string              JS
	 */
	private function lockedLinesJs($object)
	{
		global $langs;
		$locked = (is_object($object) && !empty($object->id) && (int) $object->status != Facture::STATUS_DRAFT);
		$msg = '';
		if ($locked) {
			$langs->load('advancedtakepos@advancedtakepos');
			$msg = $langs->transnoentitiesnoconv('AdvTakeposLockedSale', $object->ref);
		}
		$flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
		$js = "window.advtpLocked=".($locked ? 'true' : 'false').";window.advtpLockedMsg=".json_encode($msg, $flags).";";
		$js .= "jQuery('.div2').toggleClass('advtp-locked',window.advtpLocked);";
		$js .= "if(!window.advtpLockGuard){window.advtpLockGuard=1;";
		$js .= "document.addEventListener('click',function(e){if(!window.advtpLocked||!e.target||!e.target.closest){return;}";
		$js .= "var b=e.target.closest('.div2 button');if(!b){return;}e.preventDefault();e.stopPropagation();";
		$js .= "var ov=document.getElementById('advtpLockBox');if(!ov){jQuery('body').append('<div id=\"advtpLockBox\" class=\"advtp-overlay\"><div class=\"advtp-card\"><div id=\"advtpLockTxt\" style=\"font-size:1.15em;margin-bottom:14px;\"></div><button type=\"button\" class=\"advtp-num advtp-ok\" id=\"advtpLockOk\">OK</button></div></div>');";
		$js .= "jQuery(document).on('click','#advtpLockOk,#advtpLockBox',function(ev){if(ev.target===this){jQuery('#advtpLockBox').hide();}});}";
		$js .= "jQuery('#advtpLockTxt').text(window.advtpLockedMsg);jQuery('#advtpLockBox').css('display','flex');},true);}";
		return $js;
	}

	/**
	 * JS qui ajoute un onglet orange pour le panier fractionne de la caisse ((PROV-POS<n>-SPLIT)) quand il contient
	 * des lignes. « Fractionner la vente » y deplace des lignes, mais le coeur ne liste que les paniers (PROV-POS<n>-0...) :
	 * une vente fractionnee abandonnee devient invisible, pour tous les utilisateurs de la caisse.
	 *
	 * @param int $currentid Facture affichee (onglet selectionne si c'est le panier fractionne)
	 * @return string        JS (vide si rien a afficher)
	 */
	private function splitTabJs($currentid)
	{
		global $langs;
		$term = empty($_SESSION['takeposterminal']) ? '' : (string) $_SESSION['takeposterminal'];
		if ($term === '') {
			return '';
		}
		$sql = "SELECT f.rowid, f.datec, f.total_ttc, (SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."facturedet as d WHERE d.fk_facture = f.rowid) as nb";
		$sql .= " FROM ".MAIN_DB_PREFIX."facture as f";
		$sql .= " WHERE f.entity IN (".getEntity('invoice').") AND f.fk_statut = 0";
		$sql .= " AND f.ref = '(PROV-POS".$this->db->escape($term)."-SPLIT)'";
		$resql = $this->db->query($sql);
		$o = $resql ? $this->db->fetch_object($resql) : null;
		if (!$o || (int) $o->nb == 0) {
			return '';
		}
		$langs->load('advancedtakepos@advancedtakepos');
		global $conf;
		$label = price(price2num($o->total_ttc, 'MT'), 0, $langs, 1, -1, -1, $conf->currency);
		$title = $langs->transnoentitiesnoconv('AdvTakeposSplitTabTitle', dol_print_date($this->db->jdate($o->datec), 'dayhour', 'tzuser'), (int) $o->nb);
		$cls = ((int) $o->rowid == (int) $currentid) ? 'basketselected' : 'basketnotselected';
		$html = '<a class="valignmiddle advtp-splitcart" title="'.dol_escape_htmltag($title).'" onclick="place=\'SPLIT\'; invoiceid=\''.((int) $o->rowid).'\'; Refresh();">';
		$html .= '<span class="'.$cls.' advtp-splittab"><span class="fa fa-cut paddingright"></span>'.dol_escape_htmltag($label).'</span></a>';
		// setTimeout : apres le script d'invoice.php qui reconstruit #shoppingcart ; avant le bouton « nouveau panier ».
		$js = "setTimeout(function(){var sc=jQuery('#shoppingcart');if(!sc.length||sc.find('.advtp-splitcart').length){return;}";
		$js .= "var h=".json_encode($html, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP).";var plus=sc.find('a').filter(function(){return /invoiceid=0/.test(jQuery(this).attr('onclick')||'');}).last();";
		$js .= "if(plus.length){plus.before(h);}else{sc.append(h);}},90);";
		return $js;
	}

	/**
	 * JS des popups fiche produit / fiche tiers : ouverture de la vraie fiche Dolibarr dans le
	 * colorbox natif de TakePOS (celui de FreeZone), en mode popup du coeur (dol_openinpopup :
	 * la fiche se rend sans menu haut ni menu gauche). Autonome : aucune dependance a un autre module.
	 *
	 * @param  CommonObject|null $object    La facture TakePOS en cours
	 * @param  bool              $prodpopup Icones produit (vignettes + lignes + touche F2)
	 * @param  bool              $socpopup  Icone tiers (uniquement si un vrai client est choisi)
	 * @return string                       JS
	 */
	private function cardPopupJs($object, $prodpopup, $socpopup)
	{
		$js = '';

		// Bloc une fois : ouverture + ecouteur de clic + touche F2.
		$js .= "if(!window.advtpOpenCard){window.advtpOpenCard=function(u,t,onclosed){";
		$js .= "u+=(u.indexOf('?')>=0?'&':'?')+'dol_hide_topmenu=1&dol_hide_leftmenu=1&dol_openinpopup=advtpcard';";
		$js .= "if(window.jQuery&&jQuery.colorbox){jQuery.colorbox({href:u,width:'92%',height:'94%',transition:'none',iframe:true,title:t||'',onClosed:onclosed||null});}";
		$js .= "else{window.open(u,'_blank');}};";
		// Ecouteur en phase CAPTURE : il court AVANT les onclick natifs (ClickProduct de la vignette,
		// selection de la ligne) ; un delegue classique arriverait apres et le produit serait ajoute.
		$js .= "document.addEventListener('click',function(e){var t=e.target;";
		$js .= "while(t&&t!==document){if(t.classList&&t.classList.contains('advtp-cardlink'))break;t=t.parentNode;}";
		$js .= "if(!t||t===document)return;e.preventDefault();e.stopPropagation();";
		$js .= "var u=t.getAttribute('data-advtp-url');if(u){window.advtpOpenCard(u,t.getAttribute('data-advtp-title')||'',";
		$js .= "t.getAttribute('data-advtp-refresh')?function(){if(typeof Refresh==='function'){Refresh();}}:null);}},true);";
		if ($prodpopup) {
			// F2 : fiche du produit de la ligne selectionnee (selectedline est le global TakePOS).
			$js .= "document.addEventListener('keydown',function(e){if(e.key!=='F2')return;";
			$js .= "if(typeof selectedline==='undefined'||!selectedline)return;";
			$js .= "var m=window.advtpLineProd&&window.advtpLineProd[selectedline];";
			$js .= "if(m){e.preventDefault();window.advtpOpenCard(m.u,m.t);}});";
		}
		$js .= "window.__advtpCardReady=1;}";

		// Lignes du ticket : mappe rowid de ligne -> fiche produit, puis pose l'icone dans la description.
		// La carte sert aussi a F2 ; reconstruite a chaque Refresh (les lignes viennent d'etre re-rendues).
		if ($prodpopup) {
			$map = array();
			if (is_object($object) && !empty($object->id)) {
				$sql = "SELECT d.rowid, d.fk_product, p.label FROM ".MAIN_DB_PREFIX."facturedet as d";
				$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = d.fk_product";
				$sql .= " WHERE d.fk_facture = ".((int) $object->id)." AND d.fk_product > 0";
				$resql = $this->db->query($sql);
				if ($resql) {
					while ($o = $this->db->fetch_object($resql)) {
						$map[(int) $o->rowid] = array(
							'u' => $this->productCardUrlPrefix().(int) $o->fk_product,
							't' => (string) $o->label,
						);
					}
				}
			}
			$js .= "window.advtpLineProd=".json_encode($map, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP).";";
			// Pas d'icone ajoutee : la REFERENCE (le <b> de la cellule description) devient cliquable, et le
			// picto info NATIF (classfortooltip) ouvre la popup au clic — son info-bulle de survol est conservee.
			// Le reste de la ligne garde son role natif : selectionner.
			// Avec TAKEPOS_SHOW_N_FIRST_LINES, le coeur ecrit la ligne autrement : picto produit puis libelle en texte
			// simple, sans reference en gras ; le picto info qui peut suivre est celui de la DESCRIPTION. Le lien se
			// pose alors sur le picto produit et sur le libelle.
			$nfirstlines = getDolGlobalString('TAKEPOS_SHOW_N_FIRST_LINES') ? 1 : 0;
			$js .= "setTimeout(function(){for(var id in window.advtpLineProd){var tr=document.getElementById(id);if(!tr)continue;";
			$js .= "var td=jQuery(tr).find('td').first();if(!td.length||td.find('.advtp-cardlink').length)continue;";
			$js .= "var m=window.advtpLineProd[id];";
			if ($nfirstlines) {
				$js .= "var pic=null,lab=null,first=null,ns=td[0].childNodes;";
				$js .= "for(var k=0;k<ns.length;k++){var n=ns[k];if(n.nodeType===1&&n.tagName==='BR')break;";
				$js .= "if(n.nodeType===1&&!pic&&/(^|\\s)fa-/.test(n.className||'')){pic=n;continue;}";
				$js .= "if(pic&&n.nodeType===3&&n.nodeValue.trim()!==''){if(!first)first=n;if(n.nodeValue.trim()===(m.t||'').trim()){lab=n;break;}}}";
				$js .= "lab=lab||first;";
				$js .= "if(pic){jQuery(pic).addClass('advtp-cardlink advtp-natinfo').attr('data-advtp-url',m.u).attr('data-advtp-title',m.t||'');}";
				$js .= "if(lab){var sp=document.createElement('span');sp.className='advtp-cardlink advtp-ref';sp.setAttribute('data-advtp-url',m.u);sp.setAttribute('data-advtp-title',m.t||'');";
				$js .= "lab.parentNode.insertBefore(sp,lab);sp.appendChild(lab);}";
			} else {
				$js .= "var ref=td.find('b').first();if(ref.length){ref.addClass('advtp-cardlink advtp-ref').attr('data-advtp-url',m.u).attr('data-advtp-title',m.t||'');}";
				$js .= "var ni=td.find('.classfortooltip').first();if(ni.length){ni.addClass('advtp-cardlink advtp-natinfo').attr('data-advtp-url',m.u).attr('data-advtp-title',m.t||'');}";
			}
			$js .= "}},60);";
		}

		// Tiers : icone apres la zone client, UNIQUEMENT si un vrai client est choisi (pas le client
		// par defaut du terminal). Retiree puis re-posee a chaque Refresh : elle disparait d'elle-meme
		// quand la vente revient au client par defaut.
		if ($socpopup) {
			$js .= "jQuery('#advtp-sinfo').remove();";
			$realsoc = (is_object($object) && !empty($object->socid)) ? (int) $object->socid : 0;
			if ($realsoc > 0 && $realsoc != $this->terminalDefaultSocid()) {
				$sname = '';
				$sclient = 0;
				$resql = $this->db->query("SELECT nom, client FROM ".MAIN_DB_PREFIX."societe WHERE rowid = ".((int) $realsoc));
				if ($resql && ($o = $this->db->fetch_object($resql))) {
					$sname = (string) $o->nom;
					$sclient = (int) $o->client;
				}
				global $user, $langs;
				$langs->load('advancedtakepos@advancedtakepos');
				// Onglet d'ouverture (ADVANCEDTAKEPOS_THIRDPARTY_CARD_TAB). L'onglet Client n'existe que pour un client ou
				// un prospect : sinon, fiche du tiers.
				$stabs = self::thirdpartyCardTabs();
				$stab = getDolGlobalString('ADVANCEDTAKEPOS_THIRDPARTY_CARD_TAB', 'card');
				if (!isset($stabs[$stab]) || ($stab == 'customer' && !in_array($sclient, array(1, 2, 3)))) {
					$stab = 'card';
				}
				$surl = DOL_URL_ROOT.$stabs[$stab].$realsoc;
				if ($stab == 'card' && $user->hasRight('societe', 'creer')) {
					// Fiche en edition ; le formulaire renvoie ensuite sur la fiche, toujours sans menus (formObjectOptions).
					$surl .= '&action=edit&advtpback=1&backtopage='.urlencode(DOL_URL_ROOT.'/societe/card.php?socid='.$realsoc.'&dol_openinpopup=advtpcard');
				}
				$flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
				$js .= "setTimeout(function(){if(jQuery('#advtp-sinfo').length===0){";
				$sbtn = dol_escape_js(dol_escape_htmltag($langs->transnoentitiesnoconv('AdvTakeposCustomerCardBtn')));
				$js .= "jQuery('#customerandsales').after('<span id=\"advtp-sinfo\" class=\"advtp-sinfo advtp-cardlink\" role=\"button\" data-advtp-refresh=\"1\"><span class=\"fa fa-address-card\"></span><span class=\"advtp-sinfo-txt\">".$sbtn."</span></span>');";
				// Icone seule : « Fiche » et le nom du client en info-bulle (et pour les lecteurs d'ecran).
				$stitle = $langs->transnoentitiesnoconv('AdvTakeposCustomerCardBtn').' : '.$sname;
				$js .= "jQuery('#advtp-sinfo').attr('data-advtp-url',".json_encode($surl, $flags).").attr('data-advtp-title',".json_encode($sname, $flags).").attr('title',".json_encode($stitle, $flags).").attr('aria-label',".json_encode($stitle, $flags).");}},80);";
			}
		}

		return $js;
	}

	/**
	 * CSS injecte une fois : styles du port (productstock/productpublicprice) + police reduite
	 * + niveau dans le titre + correctif pleine largeur quand les categories sont masquees.
	 *
	 * @return string CSS sur une ligne (echappe pour insertion JS)
	 */
	private function buildCss()
	{
		$css = '';
		// Badge stock : coin haut-gauche, 2 lignes (local / total), compact, borne a 45% : jamais de chevauchement.
		// Polices ALIGNEES sur celles du prix : ligne 1 (local) = taille du prix de vente ; ligne 2 (total) = taille du prix public.
		// Badge stock ALIGNE sur le prix (2em) : ligne 1 (local) = 2em comme le prix de vente ; ligne 2 (total)
		// = 0.85em, comme le prix public. Meme structure => meme HAUTEUR que le badge prix.
		// Meme padding (4px 6px) et meme line-height (1.05) que le badge prix ci-dessous => hauteurs identiques.
		$css .= '.productstock{position:absolute;top:5px;left:5px;max-width:46%;overflow:hidden;text-align:center;line-height:1.05;white-space:nowrap;background:var(--colorbackhmenu1);color:var(--colortextbackhmenu);font-size:2em;padding:4px 6px;border-radius:2px;opacity:0.9;z-index:5;}';
		// Cas 2 lignes : display:block, STRICTEMENT la meme structure que .productpublicprice (2e ligne du prix) ;
		// cas 1 ligne (prix sans public) : span INLINE, tout reste sur la ligne 1 comme le prix.
		$css .= '.productstock .advtp-stocktotal{display:block;opacity:0.9;font-size:0.85em;}';
		$css .= '.productstock .advtp-stockinline{opacity:0.9;font-size:0.85em;}';
		// Prix (coin haut-droite) : meme line-height que le stock ; borne a 55% (44+55 => pas de chevauchement).
		$css .= '.productprice{max-width:55%;text-align:right;line-height:1.05;}';
		// En disposition etroite la vignette devient une ligne : le badge prix, en position absolue a
		// droite, recouvrait la fin du libelle (et l icone de fiche produit qui le suit). On borne donc
		// le libelle pour lui reserver la gouttiere du badge, avec des points de suspension au besoin.
		$css .= '@media screen and (max-width:767px){[id^="prodivdesc"]{max-width:calc(100% - 165px);overflow:hidden;text-overflow:ellipsis;}}';
		// Prix public + % : 2e ligne sous le prix de vente, sur UNE SEULE ligne (nowrap) pour que le badge prix
		// ait exactement 2 lignes, comme le badge stock => meme hauteur par construction (pas de mesure au pixel).
		$css .= '.productpublicprice{display:block;font-size:0.85em;opacity:0.95;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}';
		$css .= '.advtp-strike{text-decoration:line-through;opacity:0.8;}';
		// Statut de la caisse du jour, a droite du nom du terminal (option ADVANCEDTAKEPOS_CASH_STATUS).
		// Pastille de la hauteur du bouton client, centree dans la barre, icone + libelle.
		$css .= '.advtp-cashstate{display:inline-block;vertical-align:middle;box-sizing:border-box;height:30px;line-height:28px;margin:0 6px 0 2px;padding:0 12px 0 10px;border:1px solid rgba(255,255,255,.35);border-radius:15px;font-size:0.9em;font-weight:bold;color:#fff;cursor:pointer;white-space:nowrap;}';
		$css .= '.advtp-cashstate .fa{margin-right:6px;}.advtp-cashstate:hover{filter:brightness(1.12);}';
		// Fil d'Ariane des categories : bandeau en tete de la zone des categories, vignettes reduites d'autant.
		if (getDolGlobalInt('ADVANCEDTAKEPOS_CATEGORY_BREADCRUMB') == 1) {
			$css .= '.advtp-crumb{box-sizing:border-box;height:34px;line-height:34px;padding:0 8px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;background:var(--colorbackhmenu1);color:var(--colortextbackhmenu);font-size:15px;}';
			$css .= '.advtp-crumb-seg{cursor:pointer;padding:0 3px;}.advtp-crumb-seg:hover{text-decoration:underline;}.advtp-crumb-cur{font-weight:bold;}.advtp-crumb-leaf{cursor:default;text-decoration:none !important;}';
			$css .= '.advtp-crumb-sep{opacity:.6;margin:0 3px;font-size:.75em;}';
			$css .= '@media screen and (min-width:768px){.div4.advtp-hascrumb .wrapper{height:calc((100% - 34px) / 3) !important;}}';
			$css .= '.div4 .wrapper.advtp-catactive{box-shadow:inset 0 0 0 4px var(--colorbackhmenu1);}';
		}
		// Pastille du nombre de produits trouves, apres le champ de recherche.
		$css .= '.advtp-searchcount{display:none;flex:0 0 auto;margin:0 4px;padding:2px 10px;border-radius:12px;background:#fff3e0;color:#5d4037;font-size:0.85em;font-weight:bold;white-space:nowrap;line-height:1.6;}';
		// Vignettes peintes a la couleur de leur categorie : le libelle suit la couleur du texte de la vignette.
		$css .= '.advtp-catcol .description_content,.advtp-catcol .catwatermark{color:inherit !important;}';
		$css .= '.advtp-cashstate-open{background:#2e7d32;}.advtp-cashstate-closed{background:#c62828;}.advtp-cashstate-none{background:#ef6c00;}';
		// Bouton client en pastille pleine (option ADVANCEDTAKEPOS_CUSTOMER_BUTTON) : bleu tant que la vente est sur le
		// client par defaut du terminal, blanc avec le nom quand un client est choisi. Meme hauteur que le statut de caisse.
		// Largeur bornee a 240 px (100 px dans le coeur, padding compris ici) : le nom reste lisible.
		if (getDolGlobalInt('ADVANCEDTAKEPOS_CUSTOMER_BUTTON') == 1) {
			$css .= '#customerandsales #customer,#customerandsales #contact{box-sizing:border-box;height:30px;line-height:28px;margin:10px 5px !important;padding:0 14px 0 12px !important;border:1px solid rgba(255,255,255,.35);border-radius:15px;background:#1976d2;color:#fff;font-weight:bold;max-width:240px !important;}';
			$css .= '#customerandsales.advtp-cust-set #customer,#customerandsales.advtp-cust-set #contact{background:#fff;color:var(--colorbackhmenu1);border-color:#fff;}';
			$css .= '#customerandsales #customer:hover,#customerandsales #contact:hover{filter:brightness(1.1);}';
		}
		$css .= '.advtp-promoline{white-space:normal;line-height:1.15;font-weight:bold;}';
		// (Masquage Devise + elargissement dynamique de la zone client : geres en JS dans completeTakePosInvoiceHeader,
		//  car on doit MESURER la largeur liberee par les champs masques avant de la reporter sur la zone client.)
		// Niveau de prix dans la barre de titre : couleur claire du menu (le bandeau est sombre) sinon invisible.
		// Titres des colonnes du ticket centres. La cellule de description est exclue : elle porte le
		// bloc vendeur, qui doit rester a gauche. Selecteur plus specifique que la classe .right du
		// coeur, donc pas besoin de !important. Les valeurs des lignes gardent leur propre alignement.
		$css .= '#poslines tr.liste_titre > td:not(.linecoldescription){text-align:center;}';
		// Stock affiche dans la colonne quantite des lignes : le coeur met l icone en pictofixedwidth,
		// soit 19 px de largeur imposee plus 3 px de marge pour un glyphe minuscule, ce qui etire la
		// parenthese. On lui rend sa largeur naturelle : la parenthese passe de 35 a environ 22 px.
		$css .= '#poslines tr.posinvoiceline .opacitylow .pictofixedwidth{width:auto;padding-right:2px;}';
		$css .= '.advtakepos-level{display:inline-block;vertical-align:middle;padding:0 8px;font-weight:bold;color:var(--colortextbackhmenu);opacity:0.9;}';
		// Police ADAPTATIVE sur les vignettes : suit la largeur du viewport (donc de la vignette),
		// bornee pour rester lisible sans deborder. clamp(min, prefere-en-vw, max).
		if (getDolGlobalInt('ADVANCEDTAKEPOS_SMALLER_FONT') == 1) {
			$css .= '[id^="prodesc"]{font-size:clamp(0.62rem,0.82vw,0.95rem) !important;line-height:1.15;overflow-wrap:anywhere;}';
		}
		// Vignettes sans photo : quand le catalogue n a pas d images, le coeur affiche un appareil photo barre
		// qui occupe la vignette et ne laisse au libelle qu une bande de 2 lignes en bas. On masque l image et
		// le libelle occupe toute la vignette, centre, sur ADVANCEDTAKEPOS_TILE_LINES lignes (4 par defaut),
		// sous les badges stock et prix. Desktop et tablette : en disposition etroite la vignette est une ligne.
		if (getDolGlobalInt('ADVANCEDTAKEPOS_TILES_NO_IMAGE') == 1) {
			$lines = getDolGlobalInt('ADVANCEDTAKEPOS_TILE_LINES', 4);
			$css .= '@media screen and (min-width:768px){';
			$css .= '.div4 .wrapper .imgwrapper,.div5 .wrapper2 .imgwrapper{display:none !important;}';
			$css .= '.div4 .wrapper .description,.div5 .wrapper2 .description{top:0;bottom:0;display:flex;align-items:center;justify-content:center;padding:2.6em 4px 4px;box-sizing:border-box;background:none;}';
			$css .= '.div4 .wrapper .description{padding-top:4px;}';
			$css .= '.div4 .description_content,.div5 .description_content{-webkit-line-clamp:'.$lines.';line-height:1.2;font-weight:bold;}';
			$css .= '}';
		}
		// Boutons d'action compacts : 3 colonnes a la largeur d'une touche du pave (8,5% ecran chacune),
		// la colonne actions (.div3) retrecit de 33% a 25,5% et la largeur LIBEREE va au panneau des
		// lignes de vente (.div1 : 34% -> 41,5%). Desktop uniquement (les media queries <=1024 gardent
		// leur mise en page responsive).
		if (getDolGlobalInt('ADVANCEDTAKEPOS_COMPACT_ACTIONS') == 1) {
			$css .= '@media screen and (min-width:1025px){';
			$css .= '.div1{width:41.5% !important;}';
			$css .= '.div3{width:25.5% !important;}';
			$css .= '.div3 .actionbutton{width:calc(33.33% - 2px) !important;}';
			$css .= '}';
		}
		// Header leger : entrepot masque (sans interet en caisse) + barre de recherche etiree au maximum,
		// icones (effacer, accueil, plein ecran, utilisateur) poussees sur la droite.
		if (getDolGlobalInt('ADVANCEDTAKEPOS_LIGHT_HEADER') == 1) {
			$css .= '#infowarehouse{display:none !important;}';
			$css .= '.topnav{display:flex;align-items:center;flex-wrap:wrap;}';
			$css .= '.topnav-left{float:none;flex:0 1 auto;min-width:0;}';
			$css .= '#topnav-right{float:none;display:flex;align-items:center;flex:1 1 240px;min-width:0;}';
			$css .= '#topnav-right .login_block_other.takepos{display:flex;align-items:center;flex:1 1 auto;min-width:0;gap:2px;}';
			$css .= '#topnav-right #search{flex:1 1 auto;min-width:110px;width:auto;max-width:none !important;}';
			$css .= '.advtp-wh{color:var(--colortextbackhmenu);opacity:0.85;padding:0 6px;vertical-align:middle;cursor:pointer;}';
		}
		// Modale de saisie (pattern vendeur) : voile sombre, carte blanche centree, gros boutons.
		$css .= '.advtp-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:100000;align-items:center;justify-content:center;}';
		$css .= '.advtp-card{background:#fff;padding:24px;border-radius:10px;max-width:420px;width:92%;max-height:85%;overflow:auto;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,.4);}';
		$css .= '.advtp-num{margin:4px;padding:14px 0;font-size:1.25em;border:1px solid #ccc;border-radius:6px;background:#eee;cursor:pointer;min-width:84px;}';
		$css .= '.advtp-num.advtp-ok{background:#4caf50;color:#fff;border-color:#43a047;}';
		$css .= '.advtp-display{font-size:1.7em;font-weight:bold;border:1px solid #ddd;border-radius:6px;padding:8px;margin:10px auto;min-height:1.4em;max-width:290px;}';
		// Pager produits flottant.
		$css .= '.div5{position:relative;}';
		$css .= '#advtp-pager{position:absolute;bottom:10px;right:10px;z-index:8;display:none;gap:8px;}';
		if (getDolGlobalInt('ADVANCEDTAKEPOS_PAGER_POS') == 1) {
			$css .= '.div3 #advtp-pager{position:static;display:flex !important;justify-content:center;margin-top:8px;}';
		}
		$css .= '.advtp-pgbtn{width:48px;height:48px;border-radius:50%;border:1px solid #bbb;background:var(--colorbackhmenu1);color:var(--colortextbackhmenu);font-size:1.6em;line-height:1;cursor:pointer;opacity:.92;box-shadow:0 2px 8px rgba(0,0,0,.25);}';
		// Bande <=1024px (mobile + tablette) : header compact -> les paniers tiennent sur la rangee du haut,
		// a droite de l'icone entrepot (2 rangees de header au lieu de 3).
		$css .= '@media screen and (max-width:1024px){';
		$css .= '.topnav a{margin:2px 3px !important;padding:3px 4px !important;}';
		$css .= '.topnav .inline-block{vertical-align:middle;}';
		$css .= '#topnav-right a{margin:2px !important;padding:3px !important;}';
		$css .= '#search{margin:2px 0 2px 4px !important;}';
		$css .= '#shoppingcart span.basketselected,#shoppingcart span.basketnotselected{max-width:76px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:inline-block;vertical-align:middle;}';
		$css .= '#shoppingcart span.advtp-splittab{max-width:none;background:#ef6c00;color:#fff;border-radius:4px;padding:0 6px;}';
		$css .= '.div2.advtp-locked button{opacity:.4;}';
		$css .= '.advtp-creditnotice{background:#fff3e0;border:1px solid #ef6c00;color:#5d4037;border-radius:6px;padding:6px 10px;margin:4px 2px 6px 2px;font-weight:bold;}';
		$css .= '.productstock.advtp-svcbadge{background:#8d8d8d;color:#fff;}';
		$css .= '.advtp-svcline{font-size:.8em;background:#8d8d8d;color:#fff;border-radius:3px;padding:0 5px;margin-left:6px;white-space:nowrap;}';
		$css .= '.advtakepos-level{font-size:0.9em;padding:0 5px;}';
		$css .= '.advtp-cashstate{height:26px;line-height:24px;padding:0 9px 0 8px;font-size:0.85em;}';
		if (getDolGlobalInt('ADVANCEDTAKEPOS_CUSTOMER_BUTTON') == 1) {
			$css .= '#customerandsales #customer,#customerandsales #contact{height:26px;line-height:24px;margin:2px 3px !important;padding:0 10px !important;max-width:160px !important;}';
		}
		$css .= '}';
		// Bande mobile (<768px) : header allege (badge niveau compact, noms des paniers tronques),
		// lignes de vente compactes (padding reduit, description cassable) pour eviter le scroll horizontal.
		$css .= '@media screen and (max-width:767px){';
		$css .= '#advtp-pager{display:flex;}';
		// Rangees produit mobile : badge stock DANS le flux (float gauche) au lieu d'absolu, pour ne
		// jamais recouvrir le libelle quelle que soit la largeur du badge (1/434, 100/5842...).
		$css .= '.div5 .productstock{position:static;float:left;transform:none;margin:6px 8px 6px 4px;max-width:none;}';
		$css .= '.topnav{position:relative;}';
		$css .= '.advtp-wh{position:absolute;top:10px;right:8px;padding:0;}';
		$css .= '#customerandsales a,#customerandsales #customer,#customerandsales #contact{max-width:none !important;}';
		$css .= '#poslines td{padding-left:2px;padding-right:2px;}';
		$css .= '#poslines .linecoldescription{overflow-wrap:anywhere;}';
		$css .= '#poslines td span.opacitylow{font-size:0.85em;}';
		$css .= '}';
		// Badge de saisie en perspective (mode 1) : carte ancree au bord droit de la ligne selectionnee.
		$css .= '#advtpInline{display:none;position:absolute;transform:translate(-100%,-50%);z-index:9000;background:#fff;border:2px solid var(--colorbackhmenu1);border-radius:8px;padding:8px 16px;box-shadow:0 4px 16px rgba(0,0,0,.35);white-space:nowrap;}';
		$css .= '#advtpInline span{font-size:0.9em;color:#555;margin-right:8px;}';
		$css .= '#advtpInline b{font-size:1.6em;color:#1B1F26;font-variant-numeric:tabular-nums;}';
		// Mode 3 : la popup gere la saisie -> clavier reduit aux boutons d'action (Qte, Prix, Remise, C, corbeille).
		if (getDolGlobalInt('ADVANCEDTAKEPOS_INPUT_MODE', 2) == 3) {
			// Colonne des boutons de ligne : Qte / Prix / Remise ligne pleine largeur, C a cote de la corbeille. Toute la
			// hauteur de la rangee (comme le pave natif), donc alignee en bas sur le bloc des boutons d'action.
			$css .= '.div2 button.calcbutton:not(.poscolorblue){display:none !important;}';
			$css .= '.div2{display:grid !important;grid-template-columns:1fr 1fr;grid-template-rows:repeat(4,1fr);gap:3px;height:100% !important;padding:2px;box-sizing:border-box;}';
			$css .= '.div2 button.calcbutton2,.div2 button.calcbutton.poscolorblue{width:auto !important;height:auto !important;font-size:1.05rem !important;margin:0 !important;}';
			$css .= '#qty{grid-column:1/3;grid-row:1;}#price{grid-column:1/3;grid-row:2;}#reduction{grid-column:1/3;grid-row:3;white-space:normal;line-height:1.15;}';
			$css .= '.div2 button.calcbutton.poscolorblue{grid-column:1;grid-row:4;}#delete{grid-column:2;grid-row:4;}';
			if (getDolGlobalInt('ADVANCEDTAKEPOS_LINE_DESC_EDIT') == 1) {
				// Bouton Description de ligne sous Remise ligne (meme style, pleine largeur) : 5 rangees, C et corbeille en 5e.
				$css .= '.div2{grid-template-rows:repeat(5,1fr) !important;}#advtpDescBtn{grid-column:1/3;grid-row:4;white-space:normal;line-height:1.15;}';
				$css .= '.div2 button.calcbutton.poscolorblue{grid-row:5;}#delete{grid-row:5;}';
			}
			if (getDolGlobalInt('ADVANCEDTAKEPOS_COMPACT_ACTIONS') == 1) {
				// La colonne pavee retrecit (rangee de 4 lisible), la place va aux lignes (50.5 + 24 + 25.5 = 100).
				$css .= '@media screen and (min-width:1025px){.div1{width:60.5% !important;}.div2{width:14% !important;}}';
			}
			// Tablette : meme retrecissement (la colonne garde sinon ses 33% natifs et tronque les lignes).
			{
				$css .= '@media screen and (min-width:768px) and (max-width:1024px){.div1{width:54% !important;}.div2{width:12% !important;}}';
			}
		}
		// Desktop : remplacer les fleches natives EN GRILLE par le pager flottant (liberer 2 cases -> N-2
		// produits en rangees pleines), et/ou changer le nombre de vignettes par rangee (natif 12,5% = 8).
		if (getDolGlobalInt('ADVANCEDTAKEPOS_DESKTOP_PAGER') == 1) {
			$n = getDolGlobalInt('TAKEPOS_NB_MAXPRODUCT', 24);
			$css .= '@media screen and (min-width:1025px){#prodiv'.($n - 2).',#prodiv'.($n - 1).'{display:none !important;}#advtp-pager{display:flex;}}';
		}
		$tpr = getDolGlobalInt('ADVANCEDTAKEPOS_TILES_PER_ROW_DESKTOP');
		if ($tpr >= 4 && $tpr <= 16) {
			$css .= '@media screen and (min-width:1025px){.div5 .wrapper2{width:'.round(100 / $tpr, 4).'% !important;}}';
		}
		// Pleine largeur des produits quand les categories sont masquees (bug CSS v24).
		if (getDolGlobalInt('ADVANCEDTAKEPOS_FULLWIDTH_NO_CAT') == 1 && getDolGlobalInt('TAKEPOS_HIDE_CATEGORIES') == 1) {
			$css .= '.div5.centpercent{width:100% !important;}';
		}
		// Icones fiche produit / fiche tiers (popups) : vignette (coin bas-droit, sous le prix),
		// ligne du ticket (apres la description) et barre de titre (apres la zone client).
		if (getDolGlobalInt('ADVANCEDTAKEPOS_PRODUCT_CARD_POPUP') == 1 || getDolGlobalInt('ADVANCEDTAKEPOS_THIRDPARTY_CARD_POPUP') == 1) {
			$css .= '.advtp-pinfo{position:absolute;bottom:3px;right:3px;z-index:6;font-size:3em;line-height:1;color:var(--colorbackhmenu1);background:rgba(255,255,255,.85);border-radius:50%;cursor:pointer;padding:2px;}';
			// En disposition etroite la vignette devient une ligne d environ 40 px de haut : le coin
			// bas-droit tombe alors DANS le badge prix, qui occupe toute la hauteur a droite. On sort
			// donc l icone du positionnement absolu pour qu elle suive le libelle du produit.
			$css .= '@media screen and (max-width:767px){.advtp-pinfo{position:static;bottom:auto;right:auto;margin-left:6px;vertical-align:middle;background:none;}}';
			$css .= '.advtp-ref{cursor:pointer;}.advtp-ref:hover{text-decoration:underline;}';
			$css .= '.advtp-natinfo,.advtp-natinfo span{cursor:pointer !important;}';
			$css .= '.advtp-sinfo{display:inline-block;vertical-align:middle;cursor:pointer;color:var(--colortextbackhmenu);opacity:.9;padding:0 5px;font-size:1.1em;}';
			$css .= '.advtp-sinfo-txt{display:none;}';
			// Avec le bouton client en pastille (ADVANCEDTAKEPOS_CUSTOMER_BUTTON) : bouton rond de la meme hauteur, icone seule
			// (« Fiche » en info-bulle), juste apres le nom (le nom, lui, ouvre la liste pour changer de client).
			if (getDolGlobalInt('ADVANCEDTAKEPOS_CUSTOMER_BUTTON') == 1) {
				$css .= '.advtp-sinfo{box-sizing:border-box;width:30px;height:30px;line-height:28px;margin:0 6px 0 0;padding:0;text-align:center;border:1px solid rgba(255,255,255,.6);border-radius:15px;background:rgba(255,255,255,.14);color:#fff;opacity:1;font-size:0.95em;white-space:nowrap;}';
				$css .= '.advtp-sinfo:hover{background:rgba(255,255,255,.28);}';
				$css .= '@media screen and (max-width:1024px){.advtp-sinfo{width:26px;height:26px;line-height:24px;font-size:0.85em;}}';
			}
		}
		return $css;
	}
}
