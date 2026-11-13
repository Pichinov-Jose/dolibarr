<?php
/* Copyright (C) 2026 Jose MARTINEZ <jose.martinez@pichinov.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * Prepare admin pages header tabs for productquickcreate
 *
 * @return array<array{0:string,1:string,2:string}> Tabs
 */
function productquickcreateAdminPrepareHead()
{
	global $langs;

	$langs->load('productquickcreate@productquickcreate');
	$langs->load('admin');

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath('/productquickcreate/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('Settings');
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath('/productquickcreate/admin/support.php', 1);
	$head[$h][1] = $langs->trans('Support');
	$head[$h][2] = 'support';
	$h++;

	$head[$h][0] = dol_buildpath('/productquickcreate/admin/changelog.php', 1);
	$head[$h][1] = $langs->trans('PqcChangelog');
	$head[$h][2] = 'changelog';
	$h++;

	return $head;
}

/**
 * Fields of the product creation form that can be made mandatory or hidden from the module setup.
 *
 * - sel: jQuery selectors of the inputs, the rows (tr) holding them are the ones made mandatory or hidden
 * - post: names of the posted values checked when the field is mandatory (one non empty value is enough)
 * - empty: posted values that mean "nothing chosen" (selects post -1 or 0 for their empty choice)
 * - canrequire / canhide: what the setup allows (a field that always has a value cannot be mandatory)
 * - productonly: not in the form of a service
 *
 * Fields required by Dolibarr itself (ref, label, statuses) are not in this list: see productquickcreateCoreRequiredFields().
 * Extrafields are not in this list either: their own setup already has "required" and "visibility".
 *
 * @return array<string,array{label:string,group:string,sel:string[],post:string[],empty:string[],canrequire:bool,canhide:bool,productonly:bool,enabled:bool}>
 */
function productquickcreateFieldCatalog()
{
	$supplierblock = (bool) getDolGlobalString('PRODUCTQUICKCREATE_SUPPLIER_BLOCK') && (isModEnabled('fournisseur') || isModEnabled('supplier_order'));
	$f = array();
	$f['barcode'] = array('label' => 'PqcFieldBarcode', 'group' => 'PqcBarcodeAndBatch', 'sel' => array('input[name=barcode]', 'select[name=fk_barcode_type]'), 'post' => array('barcode'), 'empty' => array(''), 'canrequire' => true, 'canhide' => true, 'productonly' => false, 'enabled' => isModEnabled('barcode'));
	$f['batch'] = array('label' => 'PqcFieldBatch', 'group' => 'PqcBarcodeAndBatch', 'sel' => array('select[name=status_batch]', 'select[name=sell_or_eat_by_mandatory]', 'input[name=batch_mask]', '#field_mask'), 'post' => array(), 'empty' => array(), 'canrequire' => false, 'canhide' => true, 'productonly' => true, 'enabled' => isModEnabled('productbatch'));
	$f['desc'] = array('label' => 'PqcFieldDesc', 'group' => 'PqcDescriptionAndNotes', 'sel' => array('textarea[name=desc]', '#desc'), 'post' => array('desc'), 'empty' => array(''), 'canrequire' => true, 'canhide' => true, 'productonly' => false, 'enabled' => true);
	$f['url'] = array('label' => 'PqcFieldUrl', 'group' => 'PqcDescriptionAndNotes', 'sel' => array('input[name=url]'), 'post' => array('url'), 'empty' => array(''), 'canrequire' => true, 'canhide' => true, 'productonly' => false, 'enabled' => true);
	$f['note_private'] = array('label' => 'PqcFieldNotePrivate', 'group' => 'PqcDescriptionAndNotes', 'sel' => array('textarea[name=note_private]', '#note_private'), 'post' => array('note_private'), 'empty' => array(''), 'canrequire' => true, 'canhide' => true, 'productonly' => false, 'enabled' => true);
	$f['fk_default_warehouse'] = array('label' => 'PqcFieldWarehouse', 'group' => 'PqcStock', 'sel' => array('select[name=fk_default_warehouse]'), 'post' => array('fk_default_warehouse'), 'empty' => array('', '0', '-1'), 'canrequire' => true, 'canhide' => true, 'productonly' => true, 'enabled' => isModEnabled('stock'));
	$f['seuil_stock_alerte'] = array('label' => 'PqcFieldStockAlert', 'group' => 'PqcStock', 'sel' => array('input[name=seuil_stock_alerte]'), 'post' => array('seuil_stock_alerte'), 'empty' => array(''), 'canrequire' => true, 'canhide' => true, 'productonly' => true, 'enabled' => isModEnabled('stock'));
	$f['desiredstock'] = array('label' => 'PqcFieldDesiredStock', 'group' => 'PqcStock', 'sel' => array('input[name=desiredstock]'), 'post' => array('desiredstock'), 'empty' => array(''), 'canrequire' => true, 'canhide' => true, 'productonly' => true, 'enabled' => isModEnabled('stock'));
	$f['finished'] = array('label' => 'PqcFieldNature', 'group' => 'PqcStock', 'sel' => array('select[name=finished]'), 'post' => array('finished'), 'empty' => array('', '-1'), 'canrequire' => true, 'canhide' => true, 'productonly' => true, 'enabled' => true);
	$f['weight'] = array('label' => 'PqcFieldWeight', 'group' => 'PqcWeightAndDimensions', 'sel' => array('input[name=weight]'), 'post' => array('weight'), 'empty' => array(''), 'canrequire' => true, 'canhide' => true, 'productonly' => true, 'enabled' => !getDolGlobalString('PRODUCT_DISABLE_WEIGHT'));
	$f['size'] = array('label' => 'PqcFieldSize', 'group' => 'PqcWeightAndDimensions', 'sel' => array('input[name=size]', 'input[name=sizewidth]', 'input[name=sizeheight]'), 'post' => array('size', 'sizewidth', 'sizeheight'), 'empty' => array(''), 'canrequire' => true, 'canhide' => true, 'productonly' => true, 'enabled' => !getDolGlobalString('PRODUCT_DISABLE_SIZE'));
	$f['surface'] = array('label' => 'PqcFieldSurface', 'group' => 'PqcWeightAndDimensions', 'sel' => array('input[name=surface]'), 'post' => array('surface'), 'empty' => array(''), 'canrequire' => true, 'canhide' => true, 'productonly' => true, 'enabled' => !getDolGlobalString('PRODUCT_DISABLE_SURFACE'));
	$f['volume'] = array('label' => 'PqcFieldVolume', 'group' => 'PqcWeightAndDimensions', 'sel' => array('input[name=volume]'), 'post' => array('volume'), 'empty' => array(''), 'canrequire' => true, 'canhide' => true, 'productonly' => true, 'enabled' => !getDolGlobalString('PRODUCT_DISABLE_VOLUME'));
	$f['customcode'] = array('label' => 'PqcFieldCustomCode', 'group' => 'PqcWeightAndDimensions', 'sel' => array('input[name=customcode]'), 'post' => array('customcode'), 'empty' => array(''), 'canrequire' => true, 'canhide' => true, 'productonly' => false, 'enabled' => !getDolGlobalString('PRODUCT_DISABLE_CUSTOM_INFO'));
	$f['country_id'] = array('label' => 'PqcFieldCountry', 'group' => 'PqcWeightAndDimensions', 'sel' => array('select[name=country_id]', 'select[name=state_id]', '#state_id'), 'post' => array('country_id'), 'empty' => array('', '0', '-1'), 'canrequire' => true, 'canhide' => true, 'productonly' => false, 'enabled' => !getDolGlobalString('PRODUCT_DISABLE_CUSTOM_INFO'));
	$f['units'] = array('label' => 'PqcFieldUnits', 'group' => 'PqcOther', 'sel' => array('select[name=units]'), 'post' => array('units'), 'empty' => array('', '0', '-1'), 'canrequire' => true, 'canhide' => true, 'productonly' => false, 'enabled' => (bool) getDolGlobalString('PRODUCT_USE_UNITS'));
	$f['categories'] = array('label' => 'PqcFieldCategories', 'group' => 'PqcOther', 'sel' => array('select[name="categories[]"]'), 'post' => array('categories'), 'empty' => array(), 'canrequire' => true, 'canhide' => true, 'productonly' => false, 'enabled' => isModEnabled('category'));
	$f['accountancy'] = array('label' => 'PqcFieldAccountancy', 'group' => 'Accountancy', 'sel' => array('[name=accountancy_code_sell]', '[name=accountancy_code_sell_intra]', '[name=accountancy_code_sell_export]', '[name=accountancy_code_buy]', '[name=accountancy_code_buy_intra]', '[name=accountancy_code_buy_export]'), 'post' => array(), 'empty' => array(), 'canrequire' => false, 'canhide' => true, 'productonly' => false, 'enabled' => !getDolGlobalString('PRODUCT_DISABLE_ACCOUNTING'));
	$f['pqc_sell_price'] = array('label' => 'PqcSellPriceLevel1', 'group' => 'PqcModuleFields', 'sel' => array('input[name=pqc_sell_price]'), 'post' => array('pqc_sell_price'), 'empty' => array(''), 'canrequire' => true, 'canhide' => true, 'productonly' => false, 'enabled' => getDolGlobalString('PRODUIT_MULTIPRICES') && !getDolGlobalString('PRODUCT_DISABLE_PRICES'));
	$f['fourn_socid'] = array('label' => 'PqcFieldSupplier', 'group' => 'PqcModuleFields', 'sel' => array('select[name=fourn_socid]'), 'post' => array('fourn_socid'), 'empty' => array('', '0', '-1'), 'canrequire' => true, 'canhide' => false, 'productonly' => false, 'enabled' => $supplierblock);
	$f['fourn_ref'] = array('label' => 'PqcFieldSupplierRef', 'group' => 'PqcModuleFields', 'sel' => array('input[name=fourn_ref]'), 'post' => array('fourn_ref'), 'empty' => array(''), 'canrequire' => true, 'canhide' => true, 'productonly' => false, 'enabled' => $supplierblock);
	$f['fourn_price'] = array('label' => 'PqcUnitBuyingPrice', 'group' => 'PqcModuleFields', 'sel' => array('input[name=fourn_price]'), 'post' => array('fourn_price'), 'empty' => array(''), 'canrequire' => true, 'canhide' => true, 'productonly' => false, 'enabled' => $supplierblock);
	$f['fourn_qty'] = array('label' => 'PqcFieldSupplierQty', 'group' => 'PqcModuleFields', 'sel' => array('input[name=fourn_qty]'), 'post' => array(), 'empty' => array(), 'canrequire' => false, 'canhide' => true, 'productonly' => false, 'enabled' => $supplierblock);
	$f['fourn_barcode'] = array('label' => 'PqcSupplierBarcode', 'group' => 'PqcModuleFields', 'sel' => array('input[name=fourn_barcode]', 'select[name=fourn_fk_barcode_type]'), 'post' => array('fourn_barcode'), 'empty' => array(''), 'canrequire' => true, 'canhide' => true, 'productonly' => false, 'enabled' => $supplierblock && isModEnabled('barcode'));

	return array_merge($f, productquickcreateExtrafieldCatalog());
}

/**
 * Extrafields of products shown by Dolibarr on the creation form (enabled, visibility 1 or 3), as entries
 * of the field catalog with the key ef_<name>. The setup made here applies to the creation form only:
 * the extrafield setup of Dolibarr (required, visibility) is not changed.
 *
 * @return array<string,array<string,mixed>> Catalog entries
 */
function productquickcreateExtrafieldCatalog()
{
	global $db;
	static $cache = null;

	if ($cache !== null) {
		return $cache;
	}
	$cache = array();
	if (empty($db)) {
		return $cache;
	}
	require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
	$extrafields = new ExtraFields($db);
	$extrafields->fetch_name_optionals_label('product');
	$attrs = (isset($extrafields->attributes['product']) ? $extrafields->attributes['product'] : array());
	if (empty($attrs['label']) || !is_array($attrs['label'])) {
		return $cache;
	}
	foreach ($attrs['label'] as $name => $label) {
		// Same tests as CommonObject::showOptionals() in create mode
		$enabled = 1;
		if (isset($attrs['enabled'][$name])) {
			$enabled = (int) dol_eval((string) $attrs['enabled'][$name], 1, 1, '2');
		}
		$visibility = 1;
		if (isset($attrs['list'][$name])) {
			$visibility = (int) dol_eval((string) $attrs['list'][$name], 1, 1, '2');
		}
		if (empty($enabled) || !in_array(abs($visibility), array(1, 3))) {
			continue;
		}
		$type = (string) (isset($attrs['type'][$name]) ? $attrs['type'][$name] : '');
		$isseparator = ($type == 'separate');
		$post = array('options_'.$name);
		if (in_array($type, array('date', 'datetime'))) {
			$post[] = 'options_'.$name.'year';
		}
		// A checkbox that is not ticked posts nothing: it cannot be told apart from a forgotten field
		$checkbox = in_array($type, array('boolean', 'checkbox', 'chkbxlst'));
		$cache['ef_'.$name] = array(
			'label' => (string) $label,
			'group' => 'PqcExtrafields',
			'sel' => array($isseparator ? 'tr.trextrafieldseparator'.$name : 'tr.field_options_'.$name),
			'post' => ($isseparator ? array() : $post),
			'empty' => (in_array($type, array('link', 'select', 'sellist')) ? array('', '0', '-1') : array('')),
			'canrequire' => (!$isseparator && !$checkbox),
			'whynot' => ($isseparator ? 'PqcFieldSeparator' : 'PqcFieldCheckboxNoRequire'),
			'canhide' => true,
			'productonly' => false,
			'enabled' => true,
			'extrafield' => 1,
			'requirednative' => !empty($attrs['required'][$name]),
		);
	}
	return $cache;
}

/**
 * Fields always required by Dolibarr on the product creation form: shown locked in the setup, never hidden.
 * The barcode joins them when a barcode numbering module refuses an empty code.
 *
 * @return array<string,string> key => label key
 */
function productquickcreateCoreRequiredFields()
{
	$core = array('ref' => 'PqcFieldRef', 'label' => 'PqcFieldLabel', 'statut' => 'PqcFieldStatusSell', 'statut_buy' => 'PqcFieldStatusBuy');
	if (isModEnabled('barcode') && getDolGlobalString('BARCODE_PRODUCT_ADDON_NUM')) {
		$core['barcode'] = 'PqcFieldBarcodeNumbering';
	}
	// Extrafields set as required in their own setup
	foreach (productquickcreateExtrafieldCatalog() as $key => $field) {
		if (!empty($field['requirednative'])) {
			$core[$key] = $field['label'];
		}
	}
	return $core;
}

/**
 * Keys of the catalog stored in a setup constant (comma separated), restricted to the enabled fields
 * allowed for this use (mandatory or hidden). A mandatory field is never hidden.
 *
 * @param string $use 'required' or 'hidden'
 * @return string[] Field keys
 */
function productquickcreateConfiguredFields($use)
{
	$catalog = productquickcreateFieldCatalog();
	$core = productquickcreateCoreRequiredFields();
	$required = array();
	foreach (array_filter(array_map('trim', explode(',', getDolGlobalString('PRODUCTQUICKCREATE_REQUIRED_FIELDS')))) as $key) {
		// Only the fields that can really be mandatory: another key listed by hand must not block hiding
		if (!empty($catalog[$key]['enabled']) && !empty($catalog[$key]['canrequire'])) {
			$required[] = $key;
		}
	}
	$keys = ($use == 'required' ? $required : array_filter(array_map('trim', explode(',', getDolGlobalString('PRODUCTQUICKCREATE_HIDDEN_FIELDS')))));
	$out = array();
	foreach ($keys as $key) {
		if (empty($catalog[$key]) || empty($catalog[$key]['enabled'])) {
			continue;
		}
		if ($use == 'required' && !empty($catalog[$key]['canrequire'])) {
			$out[] = $key;
		}
		if ($use == 'hidden' && !empty($catalog[$key]['canhide']) && !in_array($key, $required) && empty($core[$key])) {
			$out[] = $key;
		}
	}
	return $out;
}
