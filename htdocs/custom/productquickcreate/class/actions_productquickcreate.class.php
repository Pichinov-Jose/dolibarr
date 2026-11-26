<?php
/* Copyright (C) 2026 Jose MARTINEZ <jose.martinez@pichinov.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * Hooks for the productquickcreate module (context productcard)
 */
class ActionsProductQuickCreate
{
	/**
	 * Hook contexts of the supplier documents whose line form has the "New product" button: back on the document
	 * after the creation, the line is prefilled from the supplier price of the new product
	 */
	const SUPPLIER_LINE_CONTEXTS = array('receptioncard', 'ordersuppliercard', 'invoicesuppliercard');

	/**
	 * Hook contexts of the customer documents whose line form has the "New product" button: back on the document
	 * after the creation, the product is chosen again like by a click, so Dolibarr fills the line (price of the
	 * customer level, VAT, description)
	 */
	const CUSTOMER_LINE_CONTEXTS = array('invoicecard', 'propalcard', 'ordercard');

	/**
	 * @var DoliDB Database handler
	 */
	public $db;

	/**
	 * @var string Error
	 */
	public $error = '';

	/**
	 * @var string[] Errors
	 */
	public $errors = array();

	/**
	 * @var string[] Warnings
	 */
	public $warnings = array();

	/**
	 * @var array<string,mixed> Hook results
	 */
	public $results = array();

	/**
	 * @var string Hook output
	 */
	public $resprints;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Before the product is created, check the supplier block: a supplier ref or an EAN already used by
	 * another product, or a supplier ref / EAN without buying price, would be lost. In that case the
	 * creation is refused and the form is displayed again with the typed values and the reason.
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param CommonObject $object Current object
	 * @param string $action Current action
	 * @param HookManager $hookmanager Hook manager
	 * @return int 0 to go on, <0 to refuse the creation
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		$contexts = explode(':', (string) $parameters['currentcontext']);
		if (count(array_intersect($contexts, self::SUPPLIER_LINE_CONTEXTS))) {
			return $this->prefillSupplierLineAfterQuickCreate($object, $action);
		}
		if (in_array('productcard', $contexts) && $action == 'create') {
			$this->preselectDefaultWarehouse();
			$this->preselectDefaultCategories();
			$this->preselectSupplierFromBacktopage();
			return 0;
		}
		if (!in_array('productcard', $contexts) || $action != 'add') {
			return 0;
		}

		// Mandatory fields chosen in the module setup
		$missing = $this->getMissingRequiredFields();
		if (count($missing)) {
			$langs->load('productquickcreate@productquickcreate');
			$this->errors[] = $langs->trans('PqcRequiredFieldsMissing', implode(', ', $missing));
			$action = 'create';	// Display the creation form again, with the typed values
			return -1;
		}

		// Selling price of level 1: refuse a value that is not a positive number instead of ignoring it
		$sellprice = trim(GETPOST('pqc_sell_price', 'alpha'));
		if (getDolGlobalString('PRODUIT_MULTIPRICES') && $sellprice !== '') {
			// price2num() turns any text into 0, so check the typed format itself (digits, one decimal separator)
			$sellpriceclean = str_replace(array(' ', "\xc2\xa0", "\xe2\x80\xaf"), '', $sellprice);
			if (!preg_match('/^[0-9]+([.,][0-9]+)?$/', $sellpriceclean)) {
				$langs->load('productquickcreate@productquickcreate');
				$this->errors[] = $langs->trans('PqcSellPriceInvalid', $sellprice);
				$action = 'create';
				return -1;
			}
		}

		if (!getDolGlobalString('PRODUCTQUICKCREATE_SUPPLIER_BLOCK') || GETPOSTINT('fourn_socid') <= 0) {
			return 0;
		}

		$reffourn = trim(GETPOST('fourn_ref', 'alphanohtml'));
		$barcode = (isModEnabled('barcode') ? trim(GETPOST('fourn_barcode', 'alphanohtml')) : '');
		$priceht = (float) price2num(GETPOST('fourn_price', 'alpha'), 'MU');
		if ($reffourn === '' && $barcode === '' && $priceht <= 0) {
			return 0;	// Supplier block left empty: nothing to create
		}

		$langs->load('productquickcreate@productquickcreate');

		if ($priceht <= 0) {
			$this->errors[] = $langs->trans('PqcSupplierPriceRequired');
		}

		if ($reffourn !== '') {
			$sql = "SELECT p.ref, p.label, s.nom as socname";
			$sql .= " FROM ".$this->db->prefix()."product_fournisseur_price as pfp";
			$sql .= " INNER JOIN ".$this->db->prefix()."product as p ON p.rowid = pfp.fk_product";
			$sql .= " INNER JOIN ".$this->db->prefix()."societe as s ON s.rowid = pfp.fk_soc";
			$sql .= " WHERE pfp.fk_soc = ".((int) GETPOSTINT('fourn_socid'));
			$sql .= " AND pfp.ref_fourn = '".$this->db->escape($reffourn)."'";
			$sql .= " AND pfp.entity IN (".getEntity('productsupplierprice').")";
			$sql .= " ORDER BY p.rowid";
			$sql .= $this->db->plimit(1);
			$resql = $this->db->query($sql);
			if ($resql && ($obj = $this->db->fetch_object($resql))) {
				$this->errors[] = $langs->trans('PqcSupplierRefAlreadyUsed', $reffourn, $obj->socname, $obj->ref, $obj->label);
			}
		}

		if ($barcode !== '') {
			// An EAN identifies one article: refuse it if a supplier price or a product already carries it
			$sql = "SELECT p.ref, p.label";
			$sql .= " FROM ".$this->db->prefix()."product_fournisseur_price as pfp";
			$sql .= " INNER JOIN ".$this->db->prefix()."product as p ON p.rowid = pfp.fk_product";
			$sql .= " WHERE pfp.barcode = '".$this->db->escape($barcode)."'";
			$sql .= " AND pfp.entity IN (".getEntity('productsupplierprice').")";
			$sql .= " UNION";
			$sql .= " SELECT p.ref, p.label";
			$sql .= " FROM ".$this->db->prefix()."product as p";
			$sql .= " WHERE p.barcode = '".$this->db->escape($barcode)."'";
			$sql .= " AND p.entity IN (".getEntity('product').")";
			$sql .= $this->db->plimit(1);
			$resql = $this->db->query($sql);
			if ($resql && ($obj = $this->db->fetch_object($resql))) {
				$this->errors[] = $langs->trans('PqcSupplierBarcodeAlreadyUsed', $barcode, $obj->ref, $obj->label);
			}
		}

		if (count($this->errors)) {
			$action = 'create';	// Display the creation form again, with the typed values
			return -1;
		}

		return 0;
	}

	/**
	 * Labels of the mandatory fields (module setup) left empty in the posted creation form.
	 * Only the fields really displayed by the form are checked (list posted by the creation form script),
	 * so a mandatory field that a configuration or the product type removes from the form never blocks.
	 *
	 * @return string[] Translated labels of the missing fields
	 */
	private function getMissingRequiredFields()
	{
		global $langs;

		dol_include_once('/productquickcreate/lib/productquickcreate.lib.php');
		$required = productquickcreateConfiguredFields('required');
		if (!count($required)) {
			return array();
		}
		$langs->load('productquickcreate@productquickcreate');
		$catalog = productquickcreateFieldCatalog();
		$present = (GETPOSTISSET('pqc_present_fields') ? array_filter(explode(',', GETPOST('pqc_present_fields', 'aZ09comma'))) : null);
		$isservice = (GETPOSTINT('type') == 1);

		$missing = array();
		foreach ($required as $key) {
			$field = $catalog[$key];
			if (($isservice && !empty($field['productonly'])) || (is_array($present) && !in_array($key, $present))) {
				continue;
			}
			$filled = false;
			foreach ($field['post'] as $name) {
				if ($key == 'categories') {
					$values = GETPOST($name, 'array');
					$filled = (is_array($values) && count(array_filter($values, function ($v) {
						return (int) $v > 0;
					})) > 0);
					continue;
				}
				$ishtml = (in_array($key, array('desc', 'note_private')) || !empty($field['extrafield']));
				$value = trim((string) GETPOST($name, $ishtml ? 'restricthtml' : 'alphanohtml'));
				if ($ishtml) {
					$value = trim(dol_string_nohtmltag($value));
				}
				if ($value !== '' && !in_array($value, $field['empty'], true)) {
					$filled = true;
				}
			}
			if (!$filled) {
				$missing[] = $langs->transnoentitiesnoconv($field['label']);	// Raw text: the message is encoded once by trans()
			}
		}

		return $missing;
	}

	/**
	 * On a fresh creation form, preselect the default categories chosen in the module setup. The form reads
	 * them with GETPOSTISARRAY('categories') (GET first). Not done when the form comes back after a refused
	 * creation (POST): the categories the user removed must not come back.
	 *
	 * @return int 0
	 */
	private function preselectDefaultCategories()
	{
		if (!isModEnabled('category') || GETPOSTISSET('categories') || (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] != 'GET')) {
			return 0;
		}
		$ids = array_filter(array_map('intval', explode(',', getDolGlobalString('PRODUCTQUICKCREATE_DEFAULT_CATEGORIES'))));
		if (!count($ids)) {
			return 0;
		}
		// Keep only existing product categories (a category may have been deleted since the setup)
		$sql = "SELECT rowid FROM ".$this->db->prefix()."categorie";
		$sql .= " WHERE type = 0 AND rowid IN (".$this->db->sanitize(implode(',', $ids)).")";
		$sql .= " AND entity IN (".getEntity('category').")";
		$resql = $this->db->query($sql);
		$ok = array();
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$ok[] = (string) $obj->rowid;
			}
		}
		if (count($ok)) {
			$_GET['categories'] = $ok;
		}
		return 0;
	}

	/**
	 * On the creation form, preselect the default warehouse of the product: the warehouse of the
	 * constant MAIN_DEFAULT_WAREHOUSE if it is open, else the only open warehouse if there is one.
	 * The form reads the field with GETPOSTINT('fk_default_warehouse'), so a choice already posted is kept.
	 *
	 * @return int 0
	 */
	private function preselectDefaultWarehouse()
	{
		if (!isModEnabled('stock') || GETPOSTISSET('fk_default_warehouse')) {
			return 0;
		}
		$sql = "SELECT rowid FROM ".$this->db->prefix()."entrepot";
		$sql .= " WHERE statut = 1 AND entity IN (".getEntity('stock').")";
		$resql = $this->db->query($sql);
		if (!$resql) {
			return 0;
		}
		$open = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$open[] = (int) $obj->rowid;
		}
		$default = getDolGlobalInt('MAIN_DEFAULT_WAREHOUSE');
		if ($default > 0 && in_array($default, $open)) {
			$_GET['fk_default_warehouse'] = $default;
		} elseif (count($open) == 1) {
			$_GET['fk_default_warehouse'] = $open[0];
		}

		return 0;
	}

	/**
	 * On a fresh creation form opened by the "New product" button of a supplier order or supplier invoice line,
	 * preselect the supplier of that document in the supplier block. The button of the reception gives the
	 * supplier in the URL (fourn_socid), the others do not: the document is found from the return URL.
	 * The form reads the field with GETPOSTINT('fourn_socid') and opens the block when it is set.
	 *
	 * @return int 0
	 */
	private function preselectSupplierFromBacktopage()
	{
		global $user;

		if (!getDolGlobalString('PRODUCTQUICKCREATE_SUPPLIER_BLOCK') || GETPOSTISSET('fourn_socid') || (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] != 'GET')) {
			return 0;
		}
		$reg = array();
		if (!preg_match('#/fourn/(commande|facture)/card\.php\?(.*)$#', (string) GETPOST('backtopage', 'alpha'), $reg)) {
			return 0;
		}
		$query = array();
		parse_str(html_entity_decode($reg[2], ENT_QUOTES), $query);
		$docid = (isset($query['id']) && is_numeric($query['id']) ? (int) $query['id'] : 0);
		if ($docid <= 0) {
			return 0;
		}
		if ($reg[1] == 'commande') {
			$canread = ($user->hasRight('fournisseur', 'commande', 'lire') || $user->hasRight('supplier_order', 'lire'));
			$sql = "SELECT fk_soc FROM ".$this->db->prefix()."commande_fournisseur WHERE rowid = ".((int) $docid)." AND entity IN (".getEntity('supplier_order').")";
		} else {
			$canread = ($user->hasRight('fournisseur', 'facture', 'lire') || $user->hasRight('supplier_invoice', 'lire'));
			$sql = "SELECT fk_soc FROM ".$this->db->prefix()."facture_fourn WHERE rowid = ".((int) $docid)." AND entity IN (".getEntity('supplier_invoice').")";
		}
		if (!$canread) {
			return 0;
		}
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql)) && (int) $obj->fk_soc > 0) {
			$_GET['fourn_socid'] = (int) $obj->fk_soc;
		}

		return 0;
	}

	/**
	 * Back on the supplier document (reception, supplier order, supplier invoice) after a product was created
	 * from the "New product" button of the line, the line form preselects the product as "idprod_ID" (the
	 * return URL is built before the product exists, so it cannot point to its supplier price): the supplier
	 * ref and buying price of the line stay empty. Prefill them from the supplier price of the product at the
	 * supplier of the document.
	 *
	 * @param CommonObject $object Reception, supplier order or supplier invoice
	 * @param string $action Current action
	 * @return int 0
	 */
	private function prefillSupplierLineAfterQuickCreate($object, $action)
	{
		if (!empty($action) || GETPOSTISSET('price_ht') || GETPOSTISSET('fourn_ref')) {
			return 0;
		}
		$obj = $this->fetchSupplierPriceOfReturnedProduct($object);
		if ($obj) {
			// The line form reads these fields with GETPOST(..., 2) = POST only: a value put into $_GET is ignored.
			// This is the fallback without javascript; formConfirm() then validates the product search field.
			if ((float) $obj->unitprice > 0) {
				$_POST['price_ht'] = price(price2num($obj->unitprice, 'MU'));
			}
			if ((string) $obj->ref_fourn !== '') {
				$_POST['fourn_ref'] = $obj->ref_fourn;
			}
		}

		return 0;
	}

	/**
	 * Supplier price (at the supplier of the document) of the product given back by the "New product" button
	 * in the URL (idprodfournprice=idprod_ID), or null
	 *
	 * @param CommonObject $object Reception, supplier order or supplier invoice
	 * @return ?object Row with pfpid, ref_fourn, unitprice, productref
	 */
	private function fetchSupplierPriceOfReturnedProduct($object)
	{
		if (!getDolGlobalString('PRODUCTQUICKCREATE_SUPPLIER_BLOCK') || empty($object->socid) || (int) $object->socid <= 0) {
			return null;
		}
		if (isset($object->status) && (int) $object->status != 0) {
			return null;	// Lines can be added on a draft only
		}
		$reg = array();
		if (!preg_match('/^idprod_([0-9]+)$/', (string) GETPOST('idprodfournprice', 'alpha'), $reg)) {
			return null;
		}

		$sql = "SELECT pfp.rowid as pfpid, pfp.ref_fourn, pfp.unitprice, p.ref as productref";
		$sql .= " FROM ".$this->db->prefix()."product_fournisseur_price as pfp";
		$sql .= " INNER JOIN ".$this->db->prefix()."product as p ON p.rowid = pfp.fk_product";
		$sql .= " WHERE pfp.fk_product = ".((int) $reg[1]);
		$sql .= " AND pfp.fk_soc = ".((int) $object->socid);
		$sql .= " AND pfp.entity IN (".getEntity('productsupplierprice').")";
		$sql .= " AND (pfp.status IS NULL OR pfp.status = 1)";
		$sql .= " ORDER BY pfp.quantity ASC, pfp.rowid DESC";
		$sql .= $this->db->plimit(1);
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return $obj;
		}

		return null;
	}

	/**
	 * Product given back by the "New product" button of a customer document line (URL prod_entry_mode=predef and
	 * idprod=ID), when the document is a draft, or null
	 *
	 * @param CommonObject $object Customer invoice, proposal or order
	 * @return ?object Row with rowid, ref
	 */
	private function fetchReturnedCustomerProduct($object)
	{
		if (isset($object->status) && (int) $object->status != 0) {
			return null;	// Lines can be added on a draft only
		}
		if (GETPOST('prod_entry_mode', 'aZ09') != 'predef' || GETPOSTINT('idprod') <= 0) {
			return null;
		}
		$sql = "SELECT rowid, ref FROM ".$this->db->prefix()."product";
		$sql .= " WHERE rowid = ".((int) GETPOSTINT('idprod'));
		$sql .= " AND entity IN (".getEntity('product').")";
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return $obj;
		}

		return null;
	}

	/**
	 * Back on the document after the "New product" button of a line: choose the new product again in the line
	 * form as if the user had picked it, so Dolibarr fills the line itself exactly like after a manual choice.
	 * Supplier documents: the supplier price of the new product (buying price, supplier ref, VAT, minimum quantity).
	 * Customer documents: the product (price of the customer level, VAT, description), the URL only preselects it
	 * without filling the price. The formConfirm hook is used because these cards always print its output.
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param CommonObject $object Current object
	 * @param string $action Current action
	 * @param HookManager $hookmanager Hook manager
	 * @return int 0
	 */
	public function formConfirm($parameters, &$object, &$action, $hookmanager)
	{
		$contexts = explode(':', (string) $parameters['currentcontext']);
		$issupplier = (count(array_intersect($contexts, self::SUPPLIER_LINE_CONTEXTS)) > 0);
		$iscustomer = (!$issupplier && count(array_intersect($contexts, self::CUSTOMER_LINE_CONTEXTS)) > 0);
		if ((!$issupplier && !$iscustomer) || !empty($action) || empty($object->id)) {
			return 0;
		}
		if ($issupplier) {
			$obj = $this->fetchSupplierPriceOfReturnedProduct($object);
			if (!$obj) {
				return 0;
			}
			$field = 'idprodfournprice';
			$itemid = (int) $obj->pfpid;
			$searchkey = (string) $obj->productref;
		} else {
			$obj = $this->fetchReturnedCustomerProduct($object);
			if (!$obj) {
				return 0;
			}
			$field = 'idprod';
			$itemid = (int) $obj->rowid;
			$searchkey = (string) $obj->ref;
		}

		$this->resprints = '<script>
		jQuery(function() {
			var pqcField = "'.$field.'";
			var pqcItemId = "'.$itemid.'";
			var pqcSearchKey = "'.dol_escape_js($searchkey).'";
			var pqcIsCustomer = '.($iscustomer ? 'true' : 'false').';
			var pqcTries = 0;
			function pqcStillReturned() {
				// The URL preselects the new product: the product id for a customer document, "idprod_ID" for a supplier
				// one (a combo list without this choice, the product having a supplier price, shows its empty choice)
				var current = String(jQuery("#" + pqcField).val() || "");
				if (pqcIsCustomer) {
					return current === pqcItemId;
				}
				return current === "" || current === "-1" || current === "0" || current.indexOf("idprod_") === 0;
			}
			function pqcValidateProductSearch() {
				var field = jQuery("#" + pqcField);
				if (!field.length || !pqcStillReturned()) {
					return;	// No line form, or another product was chosen meanwhile
				}
				var input = jQuery("#search_" + pqcField);
				if (!input.length) {
					// Product combo list (no search field): pick the option like a user would
					if (field.is("select") && field.find("option[value=\'" + pqcItemId + "\']").length) {
						console.log("productquickcreate: choose " + pqcField + " = " + pqcItemId + " in the product list of the line");
						field.val(pqcItemId).trigger("change");
					}
					return;
				}
				var inst = null;
				try { inst = input.autocomplete("instance"); } catch (e) { inst = null; }
				if (!inst) {
					if (++pqcTries < 50) { setTimeout(pqcValidateProductSearch, 100); }
					return;
				}
				input.one("autocompleteresponse", function(event, ui) {
					var found = null;
					jQuery.each(ui.content || [], function(i, item) {
						if (String(item.id) === pqcItemId) { found = item; return false; }
					});
					if (found) {
						console.log("productquickcreate: validate the product search field with " + pqcField + " = " + pqcItemId);
						inst._trigger("select", event, { item: found });
						input.val(found.value);
					} else {
						console.log("productquickcreate: " + pqcField + " = " + pqcItemId + " not found in the search results of " + pqcSearchKey);
					}
					setTimeout(function() { inst.close(); }, 0);
				});
				inst.search(pqcSearchKey);
			}
			setTimeout(pqcValidateProductSearch, 0);
		});
		</script>';

		return 0;
	}

	/**
	 * Add the optional supplier block and the collapsible-sections script on the product creation form
	 *
	 * @param array<string,mixed> $parameters Hook parameters
	 * @param CommonObject $object Current object
	 * @param string $action Current action
	 * @param HookManager $hookmanager Hook manager
	 * @return int 0 on success
	 */
	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $form, $conf;

		$contexts = explode(':', (string) $parameters['currentcontext']);
		if (!in_array('productcard', $contexts) || $action != 'create') {
			return 0;
		}
		if (empty($form) || !is_object($form)) {
			require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
			$form = new Form($this->db ?? $object->db);
		}

		$langs->load('productquickcreate@productquickcreate');

		$out = '';

		// Phone and handheld terminal (DT50, 412 px): under 600 px, each label goes above its field, fields, lists and
		// select2 take the whole width (44 px high, 16 px text so that the phone does not zoom on focus) and the
		// Create / Cancel buttons too. Native fields of the form and fields of this module alike; also in the popup of
		// the receptions, which opens this same page. Nothing changes above 600 px.
		$out .= '<style>
		@media only screen and (max-width: 600px) {
			form[name=formprod] table.border, form[name=formprod] table.border > tbody { display: block; width: 100%; }
			form[name=formprod] table.border > tbody > tr:not(.hideobject) { display: block; height: auto !important; padding: 6px 0; }
			form[name=formprod] table.border > tbody > tr > td { display: block; height: auto !important; width: auto !important; min-width: 0 !important; max-width: none !important; padding: 2px 0 !important; border: none !important; }
			form[name=formprod] table.border > tbody > tr > td:first-child:not([colspan]) { padding-bottom: 4px !important; white-space: normal; }
			form[name=formprod] table.border > tbody > tr > td + td { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
			form[name=formprod] table.border > tbody > tr > td + td > input:not([type=checkbox]):not([type=radio]):not([type=hidden]):not([type=submit]):not([type=button]),
			form[name=formprod] table.border > tbody > tr > td + td > select:not(.select2-hidden-accessible),
			form[name=formprod] table.border > tbody > tr > td + td > textarea,
			form[name=formprod] table.border > tbody > tr > td + td > .select2-container,
			form[name=formprod] table.border > tbody > tr > td + td > span[class*=multiselectarray],
			form[name=formprod] table.border > tbody > tr > td + td > div { flex: 1 1 0; min-width: 0 !important; width: auto !important; max-width: none !important; box-sizing: border-box; }
			form[name=formprod] table.border > tbody > tr > td + td > div { flex-basis: 100%; }
			form[name=formprod] table.border > tbody > tr > td + td > input:not([type=checkbox]):not([type=radio]):not([type=hidden]),
			form[name=formprod] table.border > tbody > tr > td + td > select:not(.select2-hidden-accessible) { min-height: 44px; font-size: 16px; }
			form[name=formprod] table.border > tbody > tr > td + td > textarea { font-size: 16px; }
			form[name=formprod] table.border > tbody > tr > td + td > span[class*=multiselectarray] > .select2-container { width: 100% !important; }
			form[name=formprod] select[name$=price_base_type] { flex: 0 0 auto !important; width: auto !important; }
			form[name=formprod] input[name=fourn_barcode] { flex-basis: 100% !important; }
			form[name=formprod] table.border > tbody > tr > td + td .select2-container .select2-selection { width: auto !important; max-width: none !important; text-align: left; }
			form[name=formprod] .select2-container .select2-selection--single, form[name=formprod] .select2-container .select2-selection--multiple { min-height: 44px; display: flex; align-items: center; }
			form[name=formprod] .select2-container .select2-selection__rendered { font-size: 16px; line-height: 1.4 !important; flex: 1 1 auto; }
			form[name=formprod] .select2-container .select2-selection__arrow { height: 42px !important; }
			form[name=formprod] .select2-container .select2-search__field, .select2-dropdown .select2-search__field { font-size: 16px !important; }
			.select2-dropdown .select2-results__option { font-size: 16px; padding: 10px 8px; }
			form[name=formprod] div.center { display: flex; flex-direction: column; gap: 8px; }
			form[name=formprod] div.center > input.button { width: 100%; min-height: 44px; font-size: 16px; margin: 0 !important; }
		}
		</style>';

		// Selling price of level 1: with multiprices enabled, Dolibarr shows no price at creation (prices tab only)
		if (getDolGlobalString('PRODUIT_MULTIPRICES') && !getDolGlobalString('PRODUCT_DISABLE_PRICES')) {
			$labellevel1 = getDolGlobalString('PRODUIT_MULTIPRICES_LABEL1');
			$out .= '<tr class="trpqcsellprice"><td class="titlefieldcreate">'.$langs->trans("PqcSellPriceLevel1").($labellevel1 ? ' ('.dol_escape_htmltag($langs->trans($labellevel1)).')' : '').'</td><td>';
			$out .= '<input type="text" name="pqc_sell_price" class="width75 right" value="'.dol_escape_htmltag(GETPOST('pqc_sell_price', 'alpha')).'"> ';
			$out .= $form->selectPriceBaseType(GETPOSTISSET('pqc_sell_price_base_type') ? GETPOST('pqc_sell_price_base_type', 'aZ09') : 'TTC', 'pqc_sell_price_base_type');
			$out .= '</td></tr>';
		}

		// Optional block: supplier & buying price (created with the product)
		if ((isModEnabled('fournisseur') || isModEnabled('supplier_order')) && getDolGlobalString('PRODUCTQUICKCREATE_SUPPLIER_BLOCK')) {
			$out .= '<tr class="trsupplierblocktitle"><td colspan="2" class="cursorpointer" id="supplierblocktoggle">';
			$out .= img_picto('', 'company', 'class="pictofixedwidth"').'<strong>'.$langs->trans("Supplier").' / '.$langs->trans("BuyingPrice").'</strong> <span id="supplierblockchevron" class="fa fa-chevron-down paddingleft"></span>';
			$out .= '</td></tr>';
			// Dolibarr >= 24 requires the universal search filter syntax; older versions accept raw SQL
			$filterfourn = version_compare(DOL_VERSION, '24.0', '>=') ? '(s.fournisseur:=:1)' : 's.fournisseur = 1';
			$out .= '<tr class="trsupplierblock hideobject"><td class="titlefieldcreate">'.$langs->trans("Supplier").'</td><td>';
			$out .= img_picto('', 'company', 'class="pictofixedwidth"').$form->select_company(GETPOSTINT('fourn_socid'), 'fourn_socid', $filterfourn, 'SelectThirdParty', 0, 0, array(), 0, 'minwidth200');
			$out .= '</td></tr>';
			$out .= '<tr class="trsupplierblock hideobject"><td>'.$langs->trans("SupplierRef").'</td><td><input type="text" name="fourn_ref" class="minwidth150" value="'.dol_escape_htmltag(GETPOST('fourn_ref', 'alphanohtml')).'"></td></tr>';
			$out .= '<tr class="trsupplierblock hideobject"><td>'.$langs->trans("PqcUnitBuyingPrice").'</td><td><input type="text" name="fourn_price" class="width75 right" value="'.dol_escape_htmltag(GETPOST('fourn_price', 'alpha')).'"> '.$langs->trans("HT").'</td></tr>';
			$out .= '<tr class="trsupplierblock hideobject"><td>'.$langs->trans("QtyMin").'</td><td><input type="text" name="fourn_qty" class="width50 right" value="'.dol_escape_htmltag(GETPOSTISSET('fourn_qty') ? GETPOST('fourn_qty', 'alpha') : '1').'"></td></tr>';
			// Supplier EAN, saved on the buying price like in the "Buying prices" tab (field names differ from
			// the product's own barcode fields of the same form)
			if (isModEnabled('barcode')) {
				require_once DOL_DOCUMENT_ROOT.'/core/class/html.formbarcode.class.php';
				$formbarcode = new FormBarCode($this->db ?? $object->db);
				$out .= '<tr class="trsupplierblock hideobject"><td>'.$langs->trans("PqcSupplierBarcode").'</td><td>';
				$out .= img_picto('', 'barcode', 'class="pictofixedwidth"');
				$out .= $formbarcode->selectBarcodeType(GETPOSTISSET('fourn_fk_barcode_type') ? GETPOSTINT('fourn_fk_barcode_type') : getDolGlobalInt('PRODUIT_DEFAULT_BARCODE_TYPE'), 'fourn_fk_barcode_type', 1);
				$out .= ' <input type="text" name="fourn_barcode" class="minwidth150" value="'.dol_escape_htmltag(GETPOST('fourn_barcode', 'alphanohtml')).'">';
				$out .= '</td></tr>';
			}
			$out .= '<script>
			jQuery(document).ready(function() {
				jQuery("#supplierblocktoggle").on("click", function() { jQuery(".trsupplierblock").not(".pqchiddenrow").not(".pqcrequiredrow").toggleClass("hideobject"); jQuery("#supplierblockchevron").toggleClass("fa-chevron-down fa-chevron-up"); });
				'.(GETPOSTINT('fourn_socid') > 0 ? 'jQuery(".trsupplierblock").removeClass("hideobject"); jQuery("#supplierblockchevron").toggleClass("fa-chevron-down fa-chevron-up");' : '').'
			});
			</script>';
		}

		// Barcode first: the barcode rows of Dolibarr (type, then value) go just under the label, out of the
		// collapsible sections, so that the EAN is typed or scanned first. The mandatory / hidden fields script
		// below finds its rows by field name, so the new position changes nothing for it.
		if (isModEnabled('barcode')) {
			$out .= '<script>
			jQuery(document).ready(function() {
				var anchor = jQuery("input[name=label]").first().closest("tr");
				if (!anchor.length) { return; }
				jQuery.each(["select[name=fk_barcode_type]", "input[name=barcode]"], function(i, sel) {
					var tr = jQuery(sel).first().closest("tr");
					if (tr.length && !tr.is(anchor)) { tr.insertAfter(anchor); anchor = tr; }
				});
			});
			</script>';
		}

		// Mandatory and hidden fields chosen in the module setup. Runs before the collapsible sections script,
		// which leaves these rows out of the folded sections: a mandatory field always stays visible.
		dol_include_once('/productquickcreate/lib/productquickcreate.lib.php');
		$catalog = productquickcreateFieldCatalog();
		$selectors = array();
		foreach ($catalog as $key => $field) {
			if (!empty($field['enabled'])) {
				$selectors[$key] = $field['sel'];
			}
		}
		$out .= '<tr class="hideobject"><td colspan="2"><input type="hidden" name="pqc_present_fields" value=""></td></tr>';
		$out .= '<script>
		jQuery(document).ready(function() {
			var pqcSelectors = '.json_encode($selectors).';
			var pqcRequired = '.json_encode(array_values(productquickcreateConfiguredFields('required'))).';
			var pqcHidden = '.json_encode(array_values(productquickcreateConfiguredFields('hidden'))).';
			// Without a barcode numbering module, Dolibarr shows the barcode label in red although an empty code is accepted
			var pqcIgnoreCoreMark = '.json_encode(array_key_exists('barcode', productquickcreateCoreRequiredFields()) ? array() : array('barcode')).';
			var pqcPresent = [];
			jQuery.each(pqcSelectors, function(key, sels) {
				var rows = jQuery();
				jQuery.each(sels, function(i, sel) {
					jQuery(sel).each(function() { var tr = jQuery(this).closest("tr"); if (tr.length) { rows = rows.add(tr); } });
				});
				if (!rows.length) { return; }
				pqcPresent.push(key);
				if (pqcHidden.indexOf(key) >= 0) {
					rows.each(function() {
						// Never hide a field that Dolibarr itself requires (its label has the class fieldrequired)
						if (jQuery(this).children("td.fieldrequired").length && pqcIgnoreCoreMark.indexOf(key) < 0) { return; }
						jQuery(this).addClass("hideobject pqchiddenrow");	// Still in the form: its default value is posted
					});
				}
				if (pqcRequired.indexOf(key) >= 0) {
					rows.addClass("pqcrequiredrow");
					// Mark the label cell just before the field to fill (first selector), else the first cell of the row
					var labelcell = jQuery(sels[0]).first().closest("td").prev("td");
					if (!labelcell.length) { labelcell = rows.first().children("td").first(); }
					labelcell.addClass("fieldrequired");
					if (rows.hasClass("trsupplierblock") && jQuery(".trsupplierblock.hideobject").not(".pqchiddenrow").length) {
						jQuery(".trsupplierblock").not(".pqchiddenrow").removeClass("hideobject");
						jQuery("#supplierblockchevron").removeClass("fa-chevron-down").addClass("fa-chevron-up");
					}
				}
			});
			jQuery("input[name=pqc_present_fields]").val(pqcPresent.join(","));
		});
		</script>';

		// Collapsible sections to keep the creation form short (mobile friendly)
		if (getDolGlobalString('PRODUCT_CREATE_COLLAPSE_SECTIONS')) {
			$out .= '<script>
			jQuery(document).ready(function() {
				var groups = [
					{id: "grp_codes", label: "'.dol_escape_js($langs->trans("PqcBatchSection")).'", sel: ["select[name=status_batch]","select[name=sell_or_eat_by_mandatory]", "input[name=batch_mask]", "#field_mask"]},
					{id: "grp_dims", label: "'.dol_escape_js($langs->trans("PqcWeightAndDimensions")).'", sel: ["input[name=weight]", "input[name=size]", "input[name=surface]", "input[name=volume]", "input[name=sizewidth]", "input[name=sizeheight]", "select[name=units]", "input[name=customcode]", "select[name=country_id]", "select[name=state_id]", "#state_id"]},
					{id: "grp_desc", label: "'.dol_escape_js($langs->trans("PqcDescriptionAndNotes")).'", sel: ["#desc", "textarea[name=desc]", "input[name=url]", "textarea[name=note_private]", "textarea[name=note]"]},
					{id: "grp_compta", label: "'.dol_escape_js($langs->trans("Accountancy")).'", sel: ["[name=accountancy_code_sell]", "[name=accountancy_code_sell_intra]", "[name=accountancy_code_sell_export]", "[name=accountancy_code_buy]", "[name=accountancy_code_buy_intra]", "[name=accountancy_code_buy_export]"]},
					{id: "grp_extrafields", label: "'.dol_escape_js($langs->trans("PqcExtrafields")).'", sel: ["tr.trextrafields", "tr.trextrafieldseparator"]}
				];
				groups.forEach(function(g) {
					var rows = jQuery();
					g.sel.forEach(function(sel) {
						jQuery(sel).each(function() {
							var tr = jQuery(this).closest("tr");
							if (tr.length && !tr.hasClass("trsupplierblock") && !tr.hasClass("trsupplierblocktitle") && !tr.hasClass("pqchiddenrow") && !tr.hasClass("pqcrequiredrow")) { rows = rows.add(tr); }
						});
					});
					if (!rows.length) { return; }
					var opened = (localStorage.getItem("prodcreate_" + g.id) == "1");
					var header = jQuery("<tr class=\'cursorpointer\' id=\'" + g.id + "\'><td colspan=\'2\'><strong><span class=\'fa " + (opened ? "fa-chevron-up" : "fa-chevron-down") + " paddingright\'></span>" + g.label + "</strong></td></tr>");
					header.insertBefore(rows.first());
					if (!opened) { rows.addClass("hideobject"); }
					header.on("click", function() {
						rows.toggleClass("hideobject");
						var open = !rows.first().hasClass("hideobject");
						jQuery(this).find("span.fa").toggleClass("fa-chevron-down fa-chevron-up");
						localStorage.setItem("prodcreate_" + g.id, open ? "1" : "0");
					});
				});
			});
			</script>';
		}

		$this->resprints = $out;
		return 0;
	}
}
