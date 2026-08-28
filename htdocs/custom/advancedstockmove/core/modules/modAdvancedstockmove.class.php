<?php
/* Copyright (C) 2026  Jose MARTINEZ <jose.martinez@pichinov.com>
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
 */

/**
 * \file    core/modules/modAdvancedstockmove.class.php
 * \ingroup advancedstockmove
 * \brief   Descripteur du module AdvancedStockMove.
 *
 * Habille les corrections et transferts de stock manuels d'un document d'origine :
 * - une correction devient un document Inventaire (code de lot COR-...),
 * - un transfert (fiche produit ou transfert de masse) devient un document
 *   Transfert de stock (module coeur stocktransfer) — codes TRA-/MSM-.
 * Chaque mouvement recoit ainsi fk_origin/origintype, visibles et filtrables
 * dans la liste des mouvements.
 */
require_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modAdvancedstockmove extends DolibarrModules
{
	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs, $conf;

		$this->db = $db;
		$this->numero = 500126;
		$this->rights_class = 'advancedstockmove';
		$this->family = 'products';
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'AdvancedStockMoveDescription';
		$this->descriptionlong = 'AdvancedStockMoveDescriptionLong';
		$this->editor_name = 'Pichinov';
		$this->editor_url = 'https://www.pichinov.com';
		$this->version = '1.1.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'stock';

		$this->module_parts = array('triggers' => 1);

		$this->config_page_url = array('setup.php@advancedstockmove');

		$this->hidden = false;
		$this->depends = array('modStock');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('advancedstockmove@advancedstockmove');
		$this->phpmin = array(7, 1);

		// Comportements actives par defaut a l'activation du module
		$this->const = array(
			0 => array('ADVANCEDSTOCKMOVE_WRAP_CORRECTIONS', 'chaine', '1', 'Wrap manual stock corrections into an Inventory document', 0, 'current', 0),
			1 => array('ADVANCEDSTOCKMOVE_WRAP_TRANSFERS', 'chaine', '1', 'Wrap manual stock transfers into a StockTransfer document', 0, 'current', 0),
		);

		$this->rights = array();
		$this->menu = array();
	}

	/**
	 * @param string $options Options when enabling module
	 * @return int 1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		$sql = array();
		return $this->_init($sql, $options);
	}
}
