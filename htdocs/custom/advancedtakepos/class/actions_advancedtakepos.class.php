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

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
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

		$showpublic = (getDolGlobalInt('ADVANCEDTAKEPOS_SHOW_PUBLIC_PRICE') == 1);
		$showstock  = (getDolGlobalInt('ADVANCEDTAKEPOS_SHOW_STOCK') == 1);
		if (!$showpublic && !$showstock) {
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
			$fkwh = $this->getTerminalWarehouse();
			$row['stock_local'] = $fkwh > 0 ? $this->stockSum((int) $obj->rowid, $fkwh) : null;
			$total = $this->stockSum((int) $obj->rowid, 0);
			$row['stock_total'] = ($total === null) ? 0 : $total;
		}

		$this->results = $row;
		return 1; // mode remplacement : la ligne enrichie remplace l'originale
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
			$js .= "(function(){var d=data[$dx],pos=$el;";
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
			$cardurl = DOL_URL_ROOT.'/product/card.php?id=';
			$js .= "(function(){var d=data[$dx],pos=$el;var pd=\$('#prodiv'+pos);if(!pd.length)return;";
			$js .= " if(!d||!d.rowid||pd.attr('data-iscat')=='1'){pd.find('.advtp-pinfo').remove();return;}";
			$js .= " var ic=pd.find('.advtp-pinfo');";
			$js .= " if(ic.length===0){pd.append('<span class=\"advtp-pinfo advtp-cardlink fa fa-info-circle\"></span>');ic=pd.find('.advtp-pinfo');}";
			$js .= " ic.attr('data-advtp-url','".$cardurl."'+d.rowid).attr('data-advtp-title',d.label||'');";
			$js .= "})();\n";
		}

		// (Le niveau de prix est mis a jour cote invoice.php via completeTakePosInvoiceHeader, qui se recharge
		//  a chaque Refresh() — donc a chaque changement de panier/client. Rien a faire par vignette ici.)

		// --- Bloc execute une seule fois : styles + nettoyage + creation du badge niveau dans le titre ---
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

		// Taille de grille par appareil : le coeur ne connait qu'un TAKEPOS_NB_MAXPRODUCT global. Si la taille
		// voulue pour CET appareil differe de la grille rendue, on ajuste la constante puis on recharge (une fois).
		$gm = getDolGlobalInt('ADVANCEDTAKEPOS_MAXPRODUCT_MOBILE');
		$gt = getDolGlobalInt('ADVANCEDTAKEPOS_MAXPRODUCT_TABLET');
		$gd = getDolGlobalInt('ADVANCEDTAKEPOS_MAXPRODUCT_DESKTOP');
		if ($gm > 0 || $gt > 0 || $gd > 0) {
			$gridurl = dol_buildpath('/advancedtakepos/ajax/setgridsize.php', 1);
			$once .= "(function(){var want=window.matchMedia('(max-width:767px)').matches?".$gm.":(window.matchMedia('(max-width:1024px)').matches?".$gt.":".$gd.");if(want<=0)return;";
			$once .= "var slots=0;jQuery('.div5 [id^=prodiv]').each(function(){if(/^prodiv\\d+\$/.test(this.id))slots++;});";
			$once .= "if(slots>0&&slots!==want&&sessionStorage.getItem('advtpGrid')!=String(want)){";
			$once .= "jQuery.getJSON('".$gridurl."?token=".newToken()."&value='+want,function(d){if(d&&d.ok){sessionStorage.setItem('advtpGrid',String(want));location.reload();}});}})();\n";
		}

		// Mode de saisie : 0 natif brut ; 1 clavier natif + badge en perspective sur la ligne ;
		// 2 popup + clavier complet ; 3 popup seule (clavier reduit aux boutons d'action).
		$inputmode = getDolGlobalInt('ADVANCEDTAKEPOS_INPUT_MODE', 2);
		if ($inputmode >= 2) {
			$once .= "jQuery.getScript('".dol_buildpath('/advancedtakepos/js/editpopup.js.php', 1).'?v='.((int) @filemtime(__DIR__.'/../js/editpopup.js.php'))."');\n";
		} elseif ($inputmode == 1) {
			$once .= "jQuery.getScript('".dol_buildpath('/advancedtakepos/js/editinline.js.php', 1)."');\n";
		}
		$js .= "if(typeof window.__advtakeposOnce==='undefined'){window.__advtakeposOnce=1;\n".$once."}\n";

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
		// CSS du module present DES LE CHARGEMENT (les vignettes ne tournent pas a l'entree) : couleur du badge
		// niveau, taille des vignettes, etc.
		$css = $this->buildCss();
		$js .= "if(jQuery('#advtakepos-style').length===0){jQuery('head').append('<style id=\"advtakepos-style\">".$css."</style>');}";

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
		if (getDolGlobalInt('ADVANCEDTAKEPOS_DELETE_SALE') != 1) {
			return 0;
		}
		// Si la PR coeur (#39745) est appliquee, le bouton natif existe deja : on evite le doublon.
		// La version mergee du coeur n'est PAS gardee par une constante : on detecte le bouton
		// lui-meme dans la barre recue par le hook, quel que soit son mode d'activation.
		if (getDolGlobalInt('TAKEPOS_SHOW_DELETE_SALE') == 1) {
			return 0;
		}
		if (!empty($parameters['menus']) && is_array($parameters['menus'])) {
			foreach ($parameters['menus'] as $m) {
				if (!empty($m['action']) && strpos((string) $m['action'], 'DeleteSale();') === 0) {
					return 0;
				}
			}
		}
		global $langs;
		$langs->load('advancedtakepos@advancedtakepos');
		$button = array(
			'title'  => '<span class="fa fa-trash-alt paddingrightonly"></span><div class="trunc">'.dol_escape_htmltag($langs->trans('AdvTakeposDeleteSale')).'</div>',
			'action' => 'advtpDeleteSale();',
			// !important : le theme TakePOS "colorful" impose background:#FFB100 !important sur .actionbutton.
			'style'  => 'background-color:#d9534f !important;color:#000 !important;',
		);
		$this->results = array(array($button));
		return 0;
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
		$js .= "if(!window.advtpOpenCard){window.advtpOpenCard=function(u,t){";
		$js .= "u+=(u.indexOf('?')>=0?'&':'?')+'dol_hide_topmenu=1&dol_hide_leftmenu=1&dol_openinpopup=advtpcard';";
		$js .= "if(window.jQuery&&jQuery.colorbox){jQuery.colorbox({href:u,width:'92%',height:'94%',transition:'none',iframe:true,title:t||''});}";
		$js .= "else{window.open(u,'_blank');}};";
		// Ecouteur en phase CAPTURE : il court AVANT les onclick natifs (ClickProduct de la vignette,
		// selection de la ligne) ; un delegue classique arriverait apres et le produit serait ajoute.
		$js .= "document.addEventListener('click',function(e){var t=e.target;";
		$js .= "while(t&&t!==document){if(t.classList&&t.classList.contains('advtp-cardlink'))break;t=t.parentNode;}";
		$js .= "if(!t||t===document)return;e.preventDefault();e.stopPropagation();";
		$js .= "var u=t.getAttribute('data-advtp-url');if(u){window.advtpOpenCard(u,t.getAttribute('data-advtp-title')||'');}},true);";
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
							'u' => DOL_URL_ROOT.'/product/card.php?id='.(int) $o->fk_product,
							't' => (string) $o->label,
						);
					}
				}
			}
			$js .= "window.advtpLineProd=".json_encode($map, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP).";";
			// Pas d'icone ajoutee : la REFERENCE (le <b> de la cellule description) devient cliquable, et le
			// picto info NATIF (classfortooltip) ouvre la popup au clic — son info-bulle de survol est conservee.
			// Le reste de la ligne garde son role natif : selectionner.
			$js .= "setTimeout(function(){for(var id in window.advtpLineProd){var tr=document.getElementById(id);if(!tr)continue;";
			$js .= "var td=jQuery(tr).find('td').first();if(!td.length||td.find('.advtp-cardlink').length)continue;";
			$js .= "var m=window.advtpLineProd[id];";
			$js .= "var ref=td.find('b').first();if(ref.length){ref.addClass('advtp-cardlink advtp-ref').attr('data-advtp-url',m.u).attr('data-advtp-title',m.t||'');}";
			$js .= "var ni=td.find('.classfortooltip').first();if(ni.length){ni.addClass('advtp-cardlink advtp-natinfo').attr('data-advtp-url',m.u).attr('data-advtp-title',m.t||'');}";
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
				$resql = $this->db->query("SELECT nom FROM ".MAIN_DB_PREFIX."societe WHERE rowid = ".((int) $realsoc));
				if ($resql && ($o = $this->db->fetch_object($resql))) {
					$sname = (string) $o->nom;
				}
				$surl = dol_escape_js(DOL_URL_ROOT.'/societe/card.php?socid='.$realsoc);
				$snamejs = dol_escape_js($sname);
				$js .= "setTimeout(function(){if(jQuery('#advtp-sinfo').length===0){";
				$js .= "jQuery('#customerandsales').after('<span id=\"advtp-sinfo\" class=\"advtp-sinfo advtp-cardlink fa fa-address-card\"></span>');";
				$js .= "jQuery('#advtp-sinfo').attr('data-advtp-url','".$surl."').attr('data-advtp-title','".$snamejs."').attr('title','".$snamejs."');}},80);";
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
		$css .= '.advtakepos-level{font-size:0.9em;padding:0 5px;}';
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
			// Une rangee de 4 : Qte / Prix / Remise en double hauteur, 4e colonne C au-dessus de la corbeille.
			$css .= '.div2 button.calcbutton:not(.poscolorblue){display:none !important;}';
			$css .= '.div2{display:grid !important;grid-template-columns:1fr 1fr;grid-template-rows:repeat(4,1fr);gap:3px;height:75% !important;padding:2px;box-sizing:border-box;}';
			$css .= '.div2 button.calcbutton2,.div2 button.calcbutton.poscolorblue{width:auto !important;height:auto !important;font-size:1.05rem !important;margin:0 !important;}';
			$css .= '#qty{grid-column:1/3;grid-row:1;}#price{grid-column:1/3;grid-row:2;}#reduction{grid-column:1/3;grid-row:3;white-space:normal;line-height:1.15;}';
			$css .= '.div2 button.calcbutton.poscolorblue{grid-column:1;grid-row:4;}#delete{grid-column:2;grid-row:4;}';
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
			$css .= '.advtp-pinfo{position:absolute;bottom:4px;right:4px;z-index:6;font-size:1.5em;line-height:1;color:var(--colorbackhmenu1);background:rgba(255,255,255,.85);border-radius:50%;cursor:pointer;}';
			// En disposition etroite la vignette devient une ligne d environ 40 px de haut : le coin
			// bas-droit tombe alors DANS le badge prix, qui occupe toute la hauteur a droite. On sort
			// donc l icone du positionnement absolu pour qu elle suive le libelle du produit.
			$css .= '@media screen and (max-width:767px){.advtp-pinfo{position:static;bottom:auto;right:auto;margin-left:6px;vertical-align:middle;background:none;}}';
			$css .= '.advtp-ref{cursor:pointer;}.advtp-ref:hover{text-decoration:underline;}';
			$css .= '.advtp-natinfo,.advtp-natinfo span{cursor:pointer !important;}';
			$css .= '.advtp-sinfo{display:inline-block;vertical-align:middle;cursor:pointer;color:var(--colortextbackhmenu);opacity:.9;padding:0 5px;font-size:1.1em;}';
		}
		return $css;
	}
}
