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
 * Product enrichment: from the EAN of a product, look up its data (UPCitemdb, eBay, AI web search)
 * and fill the empty fields of the product: label, description, brand ref, manufacturer page,
 * barcode, selling price, photos and characteristics (module productcharacteristics).
 * Available on the creation form, on the product card and as a mass action of the product list.
 */
class modProductEnrichment extends DolibarrModules
{
	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->numero = 500135;
		$this->rights_class = 'productenrichment';
		$this->family = 'pichinov';
		$this->familyinfo = array('pichinov' => array('position' => '001', 'label' => 'Pichinov'));
		$this->module_position = '94';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'Enrichissement produit : à partir de l\'EAN, recherche UPCitemdb, eBay et IA, puis remplit les champs vides (libellé, description, réf. marque, photos, caractéristiques…)';
		$this->descriptionlong = 'Bouton Enrichir sur le formulaire de création de produit (popup des réceptions comprise) et sur la fiche produit, action de masse sur la liste des produits avec file d\'attente.';
		$this->editor_name = 'Pichinov';
		$this->editor_url = 'https://www.pichinov.com';
		$this->version = '1.2.2';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'product';
		$this->langfiles = array('productenrichment@productenrichment');
		$this->config_page_url = array('setup.php@productenrichment');
		$this->module_parts = array(
			'hooks' => array('productcard', 'productservicelist', 'receptioncard'),
			'triggers' => 1,
		);

		// Characteristics that match no attribute are written in the description (switch of the setup page)
		$this->const = array(
			0 => array('PRODUCTENRICHMENT_SPECS_IN_DESC', 'chaine', '1', 'Characteristics without attribute go in the description', 0, 'current', 0),
		);
		$this->rights = array();

		// Enrichment tab of the product card: stored data of its EAN and journal of what was applied
		$this->tabs = array(
			array('data' => 'product:+peenrichment:PeTab:productenrichment@productenrichment:isModEnabled("productenrichment") && $user->hasRight("produit", "lire"):/productenrichment/tab.php?id=__ID__'),
		);

		// Queue of the mass enrichment (also processed from the queue page when the scheduler does not run)
		$this->cronjobs = array(
			0 => array(
				'label' => 'PeCronLabel',
				'jobtype' => 'method',
				'class' => '/productenrichment/class/productenrichment.class.php',
				'objectname' => 'ProductEnrichment',
				'method' => 'runQueue',
				'parameters' => '',
				'comment' => 'PeCronComment',
				'frequency' => 15,
				'unitfrequency' => 60,
				'status' => 1,
				'test' => 'isModEnabled("productenrichment")',
				'priority' => 50,
			),
		);

		$this->menu = array();
		$r = 0;
		$this->menu[$r] = array(
			'fk_menu' => 'fk_mainmenu=products,fk_leftmenu=product',
			'type' => 'left', 'titre' => 'PeQueueMenu', 'mainmenu' => 'products', 'leftmenu' => 'productenrichment',
			'url' => '/productenrichment/queue.php', 'langs' => 'productenrichment@productenrichment', 'position' => 1100,
			'enabled' => 'isModEnabled("productenrichment")', 'perms' => '$user->hasRight("produit", "creer")', 'target' => '', 'user' => 0,
		);
		$r++;
		// Every stored result (scanner and module), with the coherence check
		$this->menu[$r] = array(
			'fk_menu' => 'fk_mainmenu=products,fk_leftmenu=product',
			'type' => 'left', 'titre' => 'PeDataMenu', 'mainmenu' => 'products', 'leftmenu' => 'productenrichmentdata',
			'url' => '/productenrichment/data.php', 'langs' => 'productenrichment@productenrichment', 'position' => 1101,
			'enabled' => 'isModEnabled("productenrichment")', 'perms' => '$user->hasRight("produit", "lire")', 'target' => '', 'user' => 0,
		);
	}

	/**
	 * Enable the module: tables, scheduled job, menu.
	 *
	 * @param string $options Options when enabling module ('', 'noboxes')
	 * @return int            1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/productenrichment/sql/');
		if ($result < 0) {
			return -1;
		}

		return $this->_init(array(), $options);
	}

	/**
	 * Disable the module. The cache and the journal are kept.
	 *
	 * @param string $options Options when disabling module ('', 'noboxes')
	 * @return int            1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
