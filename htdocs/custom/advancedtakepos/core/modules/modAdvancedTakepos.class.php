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
			'hooks' => array('takeposproductsearch', 'takeposfrontend', 'takeposinvoice'),
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
