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
 * \file    core/modules/modAdvancedTakepos.class.php
 * \ingroup advancedtakepos
 * \brief   Descripteur du module AdvancedTakepos.
 *
 * Porte, par HOOKS uniquement, les personnalisations TakePOS de Serious qui vivaient
 * jusqu'ici en MODIF cœur (effacées par une montée de version) :
 *   - stock du produit sur la vignette (hook completeAjaxReturnArray + completeJSProductDisplay)
 *   - prix de vente TTC + prix public TTC + % d'écart
 *   - largeur pleine des produits quand les catégories sont masquées (bug v24)
 *   - niveau de prix courant + son nom dans la barre de titre
 *
 * Chaque comportement est gouverné par une constante, pour activer/désactiver sans toucher
 * au code. Aucun fichier du cœur n'est modifié.
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Descripteur du module AdvancedTakepos
 */
class modAdvancedTakepos extends DolibarrModules
{
	/**
	 * Constructeur
	 *
	 * @param DoliDB $db Handler base de donnees
	 */
	public function __construct($db)
	{
		global $langs, $conf;

		$this->db = $db;
		$this->numero = 500124;
		$this->rights_class = 'advancedtakepos';
		$this->family = "products";
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = "Personnalisations TakePOS (stock, prix TTC + %, largeur produits, niveau de prix) par hooks";
		$this->descriptionlong = "Porte par hooks les personnalisations TakePOS de Serious sans modifier le cœur : stock sur les vignettes, prix de vente TTC avec prix public TTC et % d'écart, produits en pleine largeur quand les catégories sont masquées, et affichage du niveau de prix courant et de son nom dans la barre de titre. Chaque fonction est activable par constante.";
		$this->editor_name = 'Pichinov';
		$this->editor_url = 'https://www.pichinov.com';
		$this->version = '1.0.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'cash-register';

		// Hooks : ajax recherche produit (données) + frontend TakePOS (rendu JS) + invoice (niveau dynamique).
		$this->module_parts = array(
			'hooks' => array('takeposproductsearch', 'takeposfrontend', 'takeposinvoice', 'takepospay', 'poslist', 'thirdpartycard'),
			'triggers' => 1,
		);

		$this->dirs = array();
		$this->config_page_url = array('setup.php@advancedtakepos');

		$this->depends = array('modTakePos');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('advancedtakepos@advancedtakepos');

		$this->phpmin = array(7, 0);
		$this->need_dolibarr_version = array(18, 0);

		// Réglages : chaque personnalisation est opt-in par constante.
		$this->const = array(
			array('ADVANCEDTAKEPOS_SHOW_STOCK', 'chaine', '1', 'Afficher le stock sur les vignettes produit', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_SHOW_PUBLIC_PRICE', 'chaine', '1', 'Afficher le prix public (niveau 1) a cote du prix de vente', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_SHOW_PRICE_DIFF', 'chaine', '1', 'Afficher le pourcentage d ecart vente/public', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_SHOW_LEVEL_IN_TITLE', 'chaine', '1', 'Afficher le niveau de prix et son nom dans la barre de titre', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_FULLWIDTH_NO_CAT', 'chaine', '1', 'Produits en pleine largeur quand les categories sont masquees', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_SMALLER_FONT', 'chaine', '1', 'Reduire la police des vignettes produit', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_TILES_NO_IMAGE', 'chaine', '0', 'Vignettes sans image : le libelle occupe toute la vignette', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_TILE_LINES', 'chaine', '4', 'Lignes de libelle sur les vignettes sans image (2 a 6)', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_LINE_DESC_EDIT', 'chaine', '0', 'Bouton Description : texte libre sur la ligne selectionnee', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_RECEIPT_LINE_DESC', 'chaine', '0', 'Imprimer la description saisie sous chaque ligne du ticket', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_SEND_BUTTON', 'chaine', '0', 'Bouton Envoyer : facture PDF ou ticket par e-mail apres reglement', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_CREDIT_HELP', 'chaine', '0', 'Avoirs appliques en caisse : bandeau dans le panier et validation d une vente couverte', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_HEADER_REMAIN', 'chaine', '0', 'En-tete du panier : Total TTC restant du au lieu du Total TTC', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_SERVICE_BADGE', 'chaine', '0', 'Badge Service au lieu du stock pour les articles sans gestion de stock', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_LOCK_PAID_LINES', 'chaine', '0', 'Boutons de ligne grises et message sur une vente deja reglee', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_CASHCONTROL_TERMINAL', 'chaine', '0', 'Ouverture de caisse depuis TakePOS : terminal de la caisse preselectionne et seul propose', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_POPUP_FIT', 'chaine', '0', 'Fenetres du controle de caisse ajustees a leur contenu', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_POPUP_ESC', 'chaine', '0', 'Echap ferme les fenetres de la caisse', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_CASH_STATUS', 'chaine', '0', 'Statut de la caisse du jour dans l en-tete', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_CUSTOMER_BUTTON', 'chaine', '0', 'Bouton client en pastille pleine dans l en-tete', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_PRODUCT_CARD_TAB', 'chaine', 'card', 'Onglet d ouverture de la fiche produit en popup', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_THIRDPARTY_CARD_TAB', 'chaine', 'card', 'Onglet d ouverture de la fiche tiers en popup', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_C_RESETS_LINE', 'chaine', '0', 'Bouton C : remettre la ligne par defaut', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_CATEGORY_COLORS', 'chaine', '0', 'Couleur des categories sur leurs vignettes', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_CATEGORY_BREADCRUMB', 'chaine', '0', 'Fil d Ariane et sous-categories dans la zone des categories', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_SEARCH_COUNT', 'chaine', '0', 'Nombre de produits trouves par la recherche', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_SEARCH_NO_SOFT_KEYBOARD', 'chaine', '0', 'Pas de clavier virtuel sur le champ de recherche (ecran tactile)', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_SEARCH_SUPPLIER', 'chaine', '0', 'Recherche TakePOS : aussi dans le nom du fournisseur', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_SEARCH_ATTRIBUTES', 'chaine', '0', 'Recherche TakePOS : aussi dans les valeurs des attributs de variante', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_SEARCH_FEATURES', 'chaine', '0', 'Recherche TakePOS : aussi dans les caracteristiques (module productsfeatures)', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_SPLIT_TAB', 'chaine', '0', 'Onglet orange pour le panier fractionne en attente', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_RESTORE_CATEGORY', 'chaine', '0', 'Reafficher la categorie en cours quand la recherche est videe (retour apres reglement)', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_GIFTVOUCHER', 'chaine', '0', 'Reglement par bon d achat scanne en caisse (module GiftVoucher)', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_GV_ALLOW_EXPIRED', 'chaine', '0', 'Bon d achat expire accepte en caisse apres confirmation', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_PAY_CONFIRM', 'chaine', '0', 'Fenetre de confirmation avant chaque encaissement', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_PAY_CANCEL', 'chaine', '0', 'Bouton Annuler le dernier reglement tant que la vente n est pas soldee', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_ALT_BARCODES', 'chaine', '', 'Sources de codes secondaires pour la recherche (pfp, ef:<champ>, tbl:<table>:<code>:<produit>)', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_HIDE_TERMINAL_DATE', 'chaine', '0', 'Masquer la date apres le nom du terminal', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_HIDE_CURRENCY', 'chaine', '0', 'Masquer le selecteur de Devise dans la barre de titre', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_CART_CUSTOMER', 'chaine', '1', 'Afficher le nom du client dans l info-bulle des paniers', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_DELETE_SALE', 'chaine', '1', 'Bouton Supprimer la vente dans la barre d actions', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_COMPACT_ACTIONS', 'chaine', '1', 'Boutons d action a la largeur des touches du pave', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_LIGHT_HEADER', 'chaine', '1', 'Header leger : entrepot masque, recherche etiree', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_EDIT_POPUP', 'chaine', '1', 'Popup de saisie Qte / Prix / Remise ligne', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_PAGER', 'chaine', '1', 'Boutons de pagination produits (recherche et categories)', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_PAGER_POS', 'chaine', '0', 'Pager : 0 flottant sur produits, 1 sous les boutons d action', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_DESKTOP_PRESET', 'chaine', '1', 'Preset grille desktop : 1=A 24 produits, 2=B 30, 3=C natif 22, 0=manuel', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_INPUT_MODE', 'chaine', '2', 'Saisie ligne : 0 natif, 1 natif+perspective, 2 popup, 3 popup sans clavier', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_PRICE_DISCOUNT_MODE', 'chaine', '1', 'Prix saisi : 0=garder la remise (natif), 1=remise a zero, 2=convertir en remise', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_PUBLIC_PRICE_LEVEL', 'chaine', '1', 'Niveau de prix servant de reference au prix public affiche', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_LINE_AT_PUBLIC_PRICE', 'chaine', '0', 'Facturer la ligne au prix de reference et porter l ecart en remise', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_LINE_PUBLIC_PRICE_COL', 'chaine', '0', 'Colonne du prix de reference (TTC) sur les lignes du ticket', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_UNIT_PRICE_TTC', 'chaine', '0', 'Colonne native du prix unitaire affichee en TTC au lieu du HT', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_PROMO_LEVEL', 'chaine', '0', 'Niveau promo : ecart vers ce niveau porte en remise a l ajout de la ligne (0=inactif)', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_MAXPRODUCT_MOBILE', 'chaine', '12', 'Cases de grille produits sur mobile (0=ne pas gerer)', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_MAXPRODUCT_TABLET', 'chaine', '18', 'Cases de grille produits sur tablette 768-1024 (0=ne pas gerer)', 0, 'current', 1),
			array('ADVANCEDTAKEPOS_MAXPRODUCT_DESKTOP', 'chaine', '0', 'Cases de grille produits sur desktop/tablette (0=ne pas gerer)', 0, 'current', 1),
		);

		$this->rights = array();
		$this->menu = array();
	}

	/**
	 * Activation
	 *
	 * @param  string $options Options
	 * @return int             1 si OK, 0 si KO
	 */
	public function init($options = '')
	{
		return $this->_init(array(), $options);
	}

	/**
	 * Desactivation
	 *
	 * @param  string $options Options
	 * @return int             1 si OK, 0 si KO
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
