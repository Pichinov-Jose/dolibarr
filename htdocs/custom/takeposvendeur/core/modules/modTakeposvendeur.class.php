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
 * \file    core/modules/modTakeposvendeur.class.php
 * \ingroup takeposvendeur
 * \brief   Descripteur du module Takeposvendeur (choix du vendeur par vente TakePOS, facon Kezia).
 *
 * Le module etait un POC active par constantes ; ce descripteur le rend visible et
 * activable depuis l'interface Modules. L'activation cree aussi les 2 extrafields :
 *   - facture.fk_vendeur (sellist -> user) : vendeur enregistre sur la vente
 *   - user.vendeur (boolean "Vendeur (caisse)") : seuls les utilisateurs coches
 *     apparaissent dans la popup de selection.
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Descripteur du module Takeposvendeur
 */
class modTakeposvendeur extends DolibarrModules
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
		$this->numero = 500125;
		$this->rights_class = 'takeposvendeur';
		$this->family = "products";
		$this->module_position = '91';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = "Choix du vendeur par vente sur TakePOS (popup, vendeur sur le ticket)";
		$this->descriptionlong = "Ajoute sur TakePOS la selection du vendeur pour chaque vente (popup a l'ouverture, obligatoire tant qu'aucun vendeur n'est choisi), enregistre le vendeur sur la facture (extrafield fk_vendeur, filtrable en liste) et l'imprime sur le ticket. Les vendeurs sont les utilisateurs actifs coches \"Vendeur (caisse)\".";
		$this->editor_name = 'Pichinov';
		$this->editor_url = 'https://www.pichinov.com';
		$this->version = '1.1.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'user';

		// Hooks : entete facture TakePOS (popup + bouton) et frontend (ticket).
		$this->module_parts = array(
			'hooks' => array('takeposinvoice', 'takeposfrontend'),
		);

		$this->dirs = array();
		$this->config_page_url = array("setup.php@takeposvendeur");

		$this->depends = array('modTakePos');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array("takeposvendeur@takeposvendeur");

		$this->phpmin = array(7, 0);
		$this->need_dolibarr_version = array(18, 0);

		$this->const = array();
		$this->rights = array();
		$this->menu = array();
	}

	/**
	 * Activation : cree les extrafields necessaires puis enregistre le module.
	 *
	 * @param  string $options Options
	 * @return int             1 si OK, 0 si KO
	 */
	public function init($options = '')
	{
		global $conf;

		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		$extrafields = new ExtraFields($this->db);

		// facture.fk_vendeur : vendeur de la vente (sellist -> user), visible/filtrable en liste.
		$extrafields->addExtraField(
			'fk_vendeur',
			'Vendeur',
			'sellist',
			100,
			'',
			'facture',
			0,
			0,
			'',
			array('options' => array('user:login:rowid' => null)),
			1,
			'',
			1
		);

		// user.vendeur : case "Vendeur (caisse)" pour apparaitre dans la popup.
		$extrafields->addExtraField(
			'vendeur',
			'Vendeur (caisse)',
			'boolean',
			100,
			'',
			'user',
			0,
			0,
			'',
			'',
			1,
			'',
			1
		);

		return $this->_init(array(), $options);
	}

	/**
	 * Desactivation (les extrafields et les donnees sont conserves).
	 *
	 * @param  string $options Options
	 * @return int             1 si OK, 0 si KO
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
