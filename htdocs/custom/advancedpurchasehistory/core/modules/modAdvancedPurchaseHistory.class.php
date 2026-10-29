<?php
/* Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
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
 * \file    advancedpurchasehistory/core/modules/modAdvancedPurchaseHistory.class.php
 * \ingroup advancedpurchasehistory
 * \brief   Descriptor of the AdvancedPurchaseHistory module.
 */
require_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * "Purchase history" tab on the product card: supplier price log, supplier invoices,
 * supplier orders and receptions merged in one chronological view, with a price chart per supplier.
 */
class modAdvancedPurchaseHistory extends DolibarrModules
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;

		$this->numero = 500131;
		$this->rights_class = 'advancedpurchasehistory';
		$this->family = 'products';
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'Purchase history tab on the product card';
		$this->descriptionlong = 'Merges the supplier price log, supplier invoices, supplier orders and receptions of a product, with a purchase price chart per supplier.';
		$this->editor_name = 'Pichinov';
		$this->editor_url = 'https://www.pichinov.com';
		$this->version = '1.0.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'fa-history';

		$this->module_parts = array();
		$this->dirs = array();
		$this->config_page_url = array();
		$this->langfiles = array('advancedpurchasehistory@advancedpurchasehistory');

		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(20, 0);
		$this->depends = array('modProduct', 'modFournisseur');
		$this->requiredby = array();
		$this->conflictwith = array();

		$this->const = array();

		// Purchase prices are confidential (sellers must not see margins): the tab needs an explicit right
		$this->tabs = array(
			array('data' => 'product:+purchasehistory:PurchaseHistoryTab:advancedpurchasehistory@advancedpurchasehistory:$user->hasRight("advancedpurchasehistory", "read"):/advancedpurchasehistory/purchasehistory.php?id=__ID__'),
		);

		$this->rights = array();
		$this->rights[0][0] = 5001311;
		$this->rights[0][1] = 'Read the purchase history of products';
		$this->rights[0][3] = 0;
		$this->rights[0][4] = 'read';

		$this->menu = array();
	}

	/**
	 * Function called when module is enabled.
	 *
	 * @param string $options Options when enabling module ('', 'noboxes')
	 * @return int<-1,1>      1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		return $this->_init(array(), $options);
	}

	/**
	 * Function called when module is disabled.
	 *
	 * @param string $options Options when disabling module ('', 'noboxes')
	 * @return int<-1,1>      1 if OK, <=0 if KO
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
