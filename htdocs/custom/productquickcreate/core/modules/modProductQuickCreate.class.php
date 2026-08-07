<?php
/* Copyright (C) 2026 Jose MARTINEZ <jose.martinez@pichinov.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Module to enrich the product creation form: optional supplier/buying price block
 * and collapsible sections (mobile friendly). No core file modified.
 */
class modProductQuickCreate extends DolibarrModules
{
	public function __construct($db)
	{
		global $langs, $conf;

		$this->db = $db;
		$this->numero = 104980;
		$this->rights_class = 'productquickcreate';
		$this->family = 'pichinov';
		$this->familyinfo = array('pichinov' => array('position' => '001', 'label' => 'Pichinov'));
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'Product creation form: supplier ref/buying price block + collapsible sections';
		$this->descriptionlong = 'Adds an optional supplier block (supplier, supplier ref, buying price, min qty) on the product creation form and collapses optional sections to keep the form short on mobile.';
		$this->editor_name = 'Pichinov';
		$this->editor_url = 'https://www.pichinov.com';
		$this->version = '1.1';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'product';
		$this->config_page_url = array('setup.php@productquickcreate');

		$this->module_parts = array(
			'hooks' => array('productcard'),
			'triggers' => 1,
		);

		$this->langfiles = array('productquickcreate@productquickcreate');

		// Collapsible sections on by default when module is enabled (set to 0 to keep full form)
		$this->const = array(
			array('PRODUCT_CREATE_COLLAPSE_SECTIONS', 'chaine', '1', 'Collapse optional sections of the product creation form', 0, 'current', 0),
			array('PRODUCTQUICKCREATE_SUPPLIER_BLOCK', 'chaine', '1', 'Show the supplier/buying price block on the product creation form', 0, 'current', 0),
		);

		$this->rights = array();
		$this->menu = array();
	}

	public function init($options = '')
	{
		$result = $this->_load_tables('');
		if ($result < 0) {
			return -1;
		}
		return $this->_init(array(), $options);
	}
}
