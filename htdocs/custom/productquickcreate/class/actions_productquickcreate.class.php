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
		if (in_array('receptioncard', $contexts)) {
			return $this->prefillReceptionLineAfterQuickCreate($object, $action);
		}
		if (!in_array('productcard', $contexts) || $action != 'add') {
			return 0;
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
	 * Back on the reception after a product was created from the "New product" popup, the line form
	 * preselects the product as "idprod_ID" (the return URL is built before the product exists, so it
	 * cannot point to its supplier price): the supplier ref and buying price of the line stay empty.
	 * Prefill them from the supplier price of the product at the supplier of the reception.
	 *
	 * @param CommonObject $object Reception
	 * @param string $action Current action
	 * @return int 0
	 */
	private function prefillReceptionLineAfterQuickCreate($object, $action)
	{
		if (!empty($action) || !getDolGlobalString('PRODUCTQUICKCREATE_SUPPLIER_BLOCK')) {
			return 0;
		}
		$reg = array();
		if (!preg_match('/^idprod_([0-9]+)$/', (string) GETPOST('idprodfournprice', 'alpha'), $reg)) {
			return 0;
		}
		if (GETPOSTISSET('price_ht') || GETPOSTISSET('fourn_ref') || empty($object->socid) || (int) $object->socid <= 0) {
			return 0;
		}

		$sql = "SELECT pfp.ref_fourn, pfp.unitprice";
		$sql .= " FROM ".$this->db->prefix()."product_fournisseur_price as pfp";
		$sql .= " WHERE pfp.fk_product = ".((int) $reg[1]);
		$sql .= " AND pfp.fk_soc = ".((int) $object->socid);
		$sql .= " AND pfp.entity IN (".getEntity('productsupplierprice').")";
		$sql .= " AND (pfp.status IS NULL OR pfp.status = 1)";
		$sql .= " ORDER BY pfp.quantity ASC, pfp.rowid DESC";
		$sql .= $this->db->plimit(1);
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			// The line form reads these values with GETPOST (fields price_ht and fourn_ref of the reception line)
			if ((float) $obj->unitprice > 0) {
				$_GET['price_ht'] = price(price2num($obj->unitprice, 'MU'));
			}
			if ((string) $obj->ref_fourn !== '') {
				$_GET['fourn_ref'] = $obj->ref_fourn;
			}
		}

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
				jQuery("#supplierblocktoggle").on("click", function() { jQuery(".trsupplierblock").toggleClass("hideobject"); jQuery("#supplierblockchevron").toggleClass("fa-chevron-down fa-chevron-up"); });
				'.(GETPOSTINT('fourn_socid') > 0 ? 'jQuery(".trsupplierblock").removeClass("hideobject"); jQuery("#supplierblockchevron").toggleClass("fa-chevron-down fa-chevron-up");' : '').'
			});
			</script>';
		}

		// Collapsible sections to keep the creation form short (mobile friendly)
		if (getDolGlobalString('PRODUCT_CREATE_COLLAPSE_SECTIONS')) {
			$out .= '<script>
			jQuery(document).ready(function() {
				var groups = [
					{id: "grp_codes", label: "'.dol_escape_js($langs->trans("PqcBarcodeAndBatch")).'", sel: ["select[name=fk_barcode_type]", "input[name=barcode]", "select[name=status_batch]", "select[name=sell_or_eat_by_mandatory]", "input[name=batch_mask]", "#field_mask"]},
					{id: "grp_dims", label: "'.dol_escape_js($langs->trans("PqcWeightAndDimensions")).'", sel: ["input[name=weight]", "input[name=size]", "input[name=surface]", "input[name=volume]", "input[name=sizewidth]", "input[name=sizeheight]", "select[name=units]", "input[name=customcode]", "select[name=country_id]", "select[name=state_id]", "#state_id"]},
					{id: "grp_desc", label: "'.dol_escape_js($langs->trans("PqcDescriptionAndNotes")).'", sel: ["#desc", "textarea[name=desc]", "input[name=url]", "textarea[name=note_private]", "textarea[name=note]"]},
					{id: "grp_compta", label: "'.dol_escape_js($langs->trans("Accountancy")).'", sel: ["[name=accountancy_code_sell]", "[name=accountancy_code_sell_intra]", "[name=accountancy_code_sell_export]", "[name=accountancy_code_buy]", "[name=accountancy_code_buy_intra]", "[name=accountancy_code_buy_export]"]}
				];
				groups.forEach(function(g) {
					var rows = jQuery();
					g.sel.forEach(function(sel) {
						jQuery(sel).each(function() {
							var tr = jQuery(this).closest("tr");
							if (tr.length && !tr.hasClass("trsupplierblock") && !tr.hasClass("trsupplierblocktitle")) { rows = rows.add(tr); }
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
