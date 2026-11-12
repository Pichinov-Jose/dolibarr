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
 * supplier block of the creation form (fourn_socid / fourn_ref / fourn_price / fourn_qty / fourn_barcode).
 *
 * The values are checked before the creation by ActionsProductQuickCreate::doActions(). If the supplier
 * price still cannot be saved here, the trigger fails so the product creation is rolled back and the
 * form is displayed again with the reason: the supplier data typed by the user is never lost silently.
 */
class InterfaceProductQuickCreateTriggers extends DolibarrTriggers
{
	public function __construct($db)
	{
		$this->db = $db;
		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = 'products';
		$this->description = 'Create the supplier price posted by the product creation form supplier block';
		$this->version = '1.2.1';
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
		$langs->load('productquickcreate@productquickcreate');

		$prodfourn = new ProductFournisseur($this->db);
		if ($prodfourn->fetch($object->id) <= 0) {
			$this->errors[] = $langs->trans('PqcSupplierPriceNotSaved', $prodfourn->error);
			return -1;
		}
		$fournsoc = new Societe($this->db);
		if ($fournsoc->fetch(GETPOSTINT('fourn_socid')) <= 0) {
			$this->errors[] = $langs->trans('PqcSupplierPriceNotSaved', $langs->trans('ErrorRecordNotFound'));
			return -1;
		}
		$qtyfourn = (float) price2num(GETPOST('fourn_qty', 'alpha'), 'MS');
		if ($qtyfourn <= 0) {
			$qtyfourn = 1;
		}
		$reffourn = trim(GETPOST('fourn_ref', 'alphanohtml'));
		$barcode = '';
		$fk_barcode_type = 0;
		if (isModEnabled('barcode')) {
			$barcode = trim(GETPOST('fourn_barcode', 'alphanohtml'));
			$fk_barcode_type = ($barcode !== '' ? GETPOSTINT('fourn_fk_barcode_type') : 0);
		}

		$retf = $prodfourn->add_fournisseur($user, $fournsoc->id, $reffourn, $qtyfourn);
		if ($retf == -3) {
			// The supplier ref is already used by another product of this supplier
			$other = new Product($this->db);
			$other->fetch((int) $prodfourn->product_id_already_linked);
			$this->errors[] = $langs->trans('PqcSupplierRefAlreadyUsed', $reffourn, $fournsoc->name, $other->ref, $other->label);
			return -1;
		}
		if ($retf < 0) {
			$this->errors[] = $langs->trans('PqcSupplierPriceNotSaved', $prodfourn->error);
			return -1;
		}
		// The block asks for a UNIT buying price, while update_buyprice() expects the price of the minimum
		// quantity (like the "Buying prices" tab): convert, so 5 pieces at 5 each give 25 for 5 (unit price 5)
		$totalht = (float) price2num($priceht * $qtyfourn, 'MU');
		// With the multicurrency module on, update_buyprice always derives the price from the
		// multicurrency one: pass it also in company currency so it is not reset to 0
		$ret = $prodfourn->update_buyprice($qtyfourn, $totalht, $user, 'HT', $fournsoc, 0, $reffourn, (float) $object->tva_tx, 0, 0, 0, 0, 0, '', array(), '', $totalht, 'HT', 1, $conf->currency, '', $barcode, $fk_barcode_type, array());
		if ($ret < 0) {
			$this->errors[] = $langs->trans('PqcSupplierPriceNotSaved', $prodfourn->error ? $prodfourn->error : implode(', ', $prodfourn->errors));
			return -1;
		}

		return 1;
	}
}
