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
	 * @var string Hook output
	 */
	public $resprints;

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
			$out .= '<tr class="trsupplierblock hideobject"><td class="titlefieldcreate">'.$langs->trans("Supplier").'</td><td>';
			$out .= img_picto('', 'company', 'class="pictofixedwidth"').$form->select_company(GETPOSTINT('fourn_socid'), 'fourn_socid', 's.fournisseur = 1', 'SelectThirdParty', 0, 0, array(), 0, 'minwidth200');
			$out .= '</td></tr>';
			$out .= '<tr class="trsupplierblock hideobject"><td>'.$langs->trans("SupplierRef").'</td><td><input type="text" name="fourn_ref" class="minwidth150" value="'.dol_escape_htmltag(GETPOST('fourn_ref', 'alphanohtml')).'"></td></tr>';
			$out .= '<tr class="trsupplierblock hideobject"><td>'.$langs->trans("BuyingPrice").'</td><td><input type="text" name="fourn_price" class="width75 right" value="'.dol_escape_htmltag(GETPOST('fourn_price', 'alpha')).'"> '.$langs->trans("HT").'</td></tr>';
			$out .= '<tr class="trsupplierblock hideobject"><td>'.$langs->trans("QtyMin").'</td><td><input type="text" name="fourn_qty" class="width50 right" value="'.dol_escape_htmltag(GETPOSTISSET('fourn_qty') ? GETPOST('fourn_qty', 'alpha') : '1').'"></td></tr>';
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
