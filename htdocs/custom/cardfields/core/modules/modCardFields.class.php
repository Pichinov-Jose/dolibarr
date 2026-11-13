<?php
/* Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    cardfields/core/modules/modCardFields.class.php
 * \brief   Descriptor of the module Champs par fiche (cardfields)
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Mandatory and hidden fields of the cards (creation, modification, consultation), chosen in the setup.
 * No core file modified.
 */
class modCardFields extends DolibarrModules
{
	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->numero = 500137;
		$this->rights_class = 'cardfields';
		$this->family = 'pichinov';
		$this->familyinfo = array('pichinov' => array('position' => '001', 'label' => 'Pichinov'));
		$this->module_position = '91';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'Mandatory and hidden fields of the cards (creation, modification, consultation)';
		$this->descriptionlong = 'For each card (reception, third party, contact, product, orders, invoices, proposal, shipment, project) and each mode (creation, modification, consultation), choose the mandatory fields and the hidden fields, extrafields included.';
		$this->editor_name = 'Pichinov';
		$this->editor_url = 'https://www.pichinov.com';
		$this->version = '1.0.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'list';
		$this->config_page_url = array('setup.php@cardfields');

		dol_include_once('/cardfields/lib/cardfields.lib.php');
		$this->module_parts = array(
			'hooks' => (function_exists('cardfieldsHookContexts') ? cardfieldsHookContexts() : array('receptioncard')),
			'triggers' => 0,
		);

		$this->langfiles = array('cardfields@cardfields');

		$this->const = array(
			array('CARDFIELDS_HIDE_EMPTY_BLOCKS', 'chaine', '1', 'Hide a block of the consultation page whose fields are all hidden', 0, 'current', 0),
		);

		$this->rights = array();
		$this->menu = array();
	}

	/**
	 * Enable the module: tables of the survey
	 *
	 * @param string $options Options
	 * @return int 1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/cardfields/sql/');
		if ($result < 0) {
			return -1;
		}
		return $this->_init(array(), $options);
	}

	/**
	 * Disable the module (setup and survey are kept)
	 *
	 * @param string $options Options
	 * @return int 1 if OK, <=0 if KO
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
