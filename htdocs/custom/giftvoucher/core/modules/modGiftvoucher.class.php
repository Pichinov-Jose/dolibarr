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
 * \file    core/modules/modGiftvoucher.class.php
 * \ingroup giftvoucher
 * \brief   Descripteur du module GiftVoucher (bons d'achat / chèques cadeaux au porteur).
 *
 * Bons au porteur avec code-barres : émission (vendu ou avoir), encaissement scanné,
 * blocage du double emploi, suivi consommation/expiration. Reprise de l'historique Kezia.
 */
require_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modGiftvoucher extends DolibarrModules
{
	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs, $conf;

		$this->db = $db;
		$this->numero = 500127;
		$this->rights_class = 'giftvoucher';
		$this->family = 'products';
		$this->module_position = '91';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'GiftVoucherDescription';
		$this->descriptionlong = 'GiftVoucherDescriptionLong';
		$this->editor_name = 'Pichinov';
		$this->editor_url = 'https://www.pichinov.com';
		$this->version = '1.0.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'fa-gift';

		$this->module_parts = array('triggers' => 1);
		$this->config_page_url = array('setup.php@giftvoucher');

		$this->hidden = false;
		$this->depends = array();
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('giftvoucher@giftvoucher');
		$this->phpmin = array(7, 1);

		$this->const = array(
			0 => array('GIFTVOUCHER_CODE_MASK', 'chaine', 'BA{yy}{mm}-{0000}', 'Mask for gift voucher codes', 0, 'current', 0),
			1 => array('GIFTVOUCHER_VALIDITY_MONTHS_GIFT', 'chaine', '12', 'Validity in months for sold gift vouchers', 0, 'current', 0),
			2 => array('GIFTVOUCHER_VALIDITY_MONTHS_CREDIT', 'chaine', '6', 'Validity in months for return credit vouchers', 0, 'current', 0),
			3 => array('GIFTVOUCHER_PAYMENT_MODE_ID', 'chaine', '109', 'llx_c_paiement id used when a voucher is redeemed (BONACH)', 0, 'current', 0),
		);

		// Droits
		$this->rights = array();
		$r = 0;
		$this->rights[$r][0] = 5001271;
		$this->rights[$r][1] = 'Read gift vouchers';
		$this->rights[$r][4] = 'read';
		$r++;
		$this->rights[$r][0] = 5001272;
		$this->rights[$r][1] = 'Create/consume gift vouchers';
		$this->rights[$r][4] = 'write';
		$r++;
		$this->rights[$r][0] = 5001273;
		$this->rights[$r][1] = 'Delete gift vouchers';
		$this->rights[$r][4] = 'delete';

		// Menus : entrée gauche sous Produits|Services
		$this->menu = array();
		$r = 0;
		$this->menu[$r] = array(
			'fk_menu' => 'fk_mainmenu=products',
			'type' => 'left', 'titre' => 'GiftVouchers', 'prefix' => img_picto('', 'fa-gift', 'class="paddingright pictofixedwidth"'),
			'mainmenu' => 'products', 'leftmenu' => 'giftvoucher',
			'url' => '/giftvoucher/giftvoucher_list.php',
			'langs' => 'giftvoucher@giftvoucher', 'position' => 1000,
			'enabled' => 'isModEnabled("giftvoucher")', 'perms' => '$user->hasRight("giftvoucher","read")',
			'target' => '', 'user' => 2,
		);
		$r++;
		$this->menu[$r] = array(
			'fk_menu' => 'fk_mainmenu=products,fk_leftmenu=giftvoucher',
			'type' => 'left', 'titre' => 'GiftVoucherScan',
			'mainmenu' => 'products', 'leftmenu' => 'giftvoucher_scan',
			'url' => '/giftvoucher/giftvoucher_scan.php',
			'langs' => 'giftvoucher@giftvoucher', 'position' => 1001,
			'enabled' => 'isModEnabled("giftvoucher")', 'perms' => '$user->hasRight("giftvoucher","write")',
			'target' => '', 'user' => 2,
		);
		$r++;
		$this->menu[$r] = array(
			'fk_menu' => 'fk_mainmenu=products,fk_leftmenu=giftvoucher',
			'type' => 'left', 'titre' => 'NewGiftVoucher',
			'mainmenu' => 'products', 'leftmenu' => 'giftvoucher_new',
			'url' => '/giftvoucher/giftvoucher_card.php?action=create',
			'langs' => 'giftvoucher@giftvoucher', 'position' => 1002,
			'enabled' => 'isModEnabled("giftvoucher")', 'perms' => '$user->hasRight("giftvoucher","write")',
			'target' => '', 'user' => 2,
		);
	}

	/**
	 * @param string $options Options when enabling module
	 * @return int 1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/giftvoucher/sql/');
		if ($result < 0) {
			return -1;
		}
		$sql = array();
		return $this->_init($sql, $options);
	}
}
