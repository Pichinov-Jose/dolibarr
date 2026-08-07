<?php
/* Copyright (C) 2026 Jose MARTINEZ <jose.martinez@pichinov.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

/**
 * On PRODUCT_CREATE, create the supplier buying price posted by the optional
 * supplier block of the creation form (fourn_socid / fourn_ref / fourn_price / fourn_qty).
 */
class InterfaceProductQuickCreateTriggers extends DolibarrTriggers
{
	public function __construct($db)
	{
		$this->db = $db;
		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = 'products';
		$this->description = 'Create the supplier price posted by the product creation form supplier block';
		$this->version = '1.0';
		$this->picto = 'product';
	}

	/**
	 * @param string $action Trigger name
	 * @param CommonObject $object Object concerned
	 * @param User $user User
	 * @param Translate $langs Lang object
	 * @param Conf $conf Conf object
	 * @return int Greater than 0 on success, 0 if nothing done, lower than 0 on error
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if ($action != 'PRODUCT_CREATE') {
			return 0;
		}
		if (GETPOSTINT('fourn_socid') <= 0) {
			return 0;
		}
		$priceht = (float) price2num(GETPOST('fourn_price', 'alpha'), 'MU');
		if ($priceht <= 0) {
			return 0;
		}

		require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.product.class.php';
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

		$prodfourn = new ProductFournisseur($this->db);
		if ($prodfourn->fetch($object->id) <= 0) {
			return 0;
		}
		$fournsoc = new Societe($this->db);
		if ($fournsoc->fetch(GETPOSTINT('fourn_socid')) <= 0) {
			return 0;
		}
		$qtyfourn = (float) price2num(GETPOST('fourn_qty', 'alpha'), 'MS');
		if ($qtyfourn <= 0) {
			$qtyfourn = 1;
		}
		$reffourn = GETPOST('fourn_ref', 'alphanohtml');

		$retf = $prodfourn->add_fournisseur($user, $fournsoc->id, $reffourn, $qtyfourn);
		if ($retf < 0) {
			return 0;
		}
		// With the multicurrency module on, update_buyprice always derives the price from the
		// multicurrency one: pass it also in company currency so it is not reset to 0
		$ret = $prodfourn->update_buyprice($qtyfourn, $priceht, $user, 'HT', $fournsoc, 0, $reffourn, (float) $object->tva_tx, 0, 0, 0, 0, 0, '', array(), '', $priceht, 'HT', 1, $conf->currency, '', '', '', array());

		return ($ret >= 0 ? 1 : 0);
	}
}
