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

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Reception screens on a small screen (Android terminal UROVO DT50, 412 px wide): one card per line on the
 * reception card and per reception in the list, add line form in one column, product creation popup in full
 * screen. Only a stylesheet and a script, active under 600 px wide on the reception pages: no core change.
 */
class modReceptionMobile extends DolibarrModules
{
	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->numero = 500136;
		$this->rights_class = 'receptionmobile';
		$this->family = 'pichinov';
		$this->familyinfo = array('pichinov' => array('position' => '001', 'label' => 'Pichinov'));
		$this->module_position = '94';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'Mobile : réceptions, commandes, factures, expéditions et produits adaptés aux petits écrans (terminal Android DT50, téléphone)';
		$this->descriptionlong = 'Sous 600 px de large, sur les pages de réception : une fiche par ligne de réception et par réception de la liste, formulaire d\'ajout de ligne en une colonne, popup de création de produit en plein écran, sélection automatique du produit scanné quand la recherche ne trouve qu\'un résultat. Aucune modification du cœur.';
		$this->editor_name = 'Pichinov';
		$this->editor_url = 'https://www.pichinov.com';
		$this->version = '1.2.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'dolly';
		$this->langfiles = array('receptionmobile@receptionmobile');
		$this->depends = array('modReception');
		// The version in the path makes browsers load the new files after an update (re-enable the module)
		// Common files first, then one file per object (one author each). The version in the path makes browsers
		// load the new files after an update (re-enable the module, or update the MAIN_MODULE_RECEPTIONMOBILE_CSS/_JS consts)
		$objects = array('product', 'order', 'supplierorder', 'invoice', 'supplierinvoice', 'shipment');
		$css = array('/receptionmobile/css/receptionmobile.css?v='.$this->version);
		$js = array('/receptionmobile/js/receptionmobile.js?v='.$this->version);
		foreach ($objects as $object) {
			$css[] = '/receptionmobile/css/rm-'.$object.'.css?v='.$this->version;
			$js[] = '/receptionmobile/js/rm-'.$object.'.js?v='.$this->version;
		}
		$this->module_parts = array(
			'css' => $css,
			'js' => $js,
		);
		$this->config_page_url = array();
		$this->rights = array();
		$this->menu = array();
	}

	/**
	 * Enable the module
	 *
	 * @param string $options Options when enabling module ('', 'noboxes')
	 * @return int            1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		return $this->_init(array(), $options);
	}

	/**
	 * Disable the module
	 *
	 * @param string $options Options when disabling module ('', 'noboxes')
	 * @return int            1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
