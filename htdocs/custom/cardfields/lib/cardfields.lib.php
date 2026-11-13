<?php
/* Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    cardfields/lib/cardfields.lib.php
 * \brief   Objects, field catalogs and setup of the module Champs par fiche (cardfields)
 *
 * A field is located on a card by:
 * - names: names of the visible inputs of its value cell (create and edit forms)
 * - sel: jQuery selectors of a row (tr) or of a value cell (td)
 * - labels: text of its label cell (consultation, where there is no input)
 * The script of the module pairs the cells of the header tables of the card (label cell, value cell) and
 * hides or marks the pairs of a field; a row whose cells are all hidden is hidden as a whole.
 */

/**
 * Tabs of the setup pages
 *
 * @return array<array{0:string,1:string,2:string}> Tabs
 */
function cardfieldsAdminPrepareHead()
{
	global $langs;

	$langs->load('cardfields@cardfields');

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath('/cardfields/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('Settings');
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath('/cardfields/admin/support.php', 1);
	$head[$h][1] = $langs->trans('Support');
	$head[$h][2] = 'support';
	$h++;

	$head[$h][0] = dol_buildpath('/cardfields/admin/changelog.php', 1);
	$head[$h][1] = $langs->trans('CfChangelog');
	$head[$h][2] = 'changelog';
	$h++;

	return $head;
}

/**
 * Modes of a card
 *
 * @return string[] Modes
 */
function cardfieldsModes()
{
	return array('create', 'edit', 'view');
}

/**
 * Objects whose cards can be set.
 * - editform: the card has a modification form (action=edit, saved by action=update); otherwise fields are
 *   modified one by one on the consultation page (pencils), and only "mandatory in modification" applies
 * - createsave / editsave: actions that save the creation / modification form
 *
 * @return array<string,array<string,mixed>> Objects by key
 */
function cardfieldsObjects()
{
	$o = array();
	$o['reception'] = array('label' => 'CfObjReception', 'picto' => 'dollyrevert', 'context' => 'receptioncard', 'page' => '/reception/card.php', 'idparam' => 'id', 'element' => 'reception', 'table_element' => 'reception', 'table' => 'reception', 'editform' => false, 'createsave' => array('add'), 'editsave' => array(), 'enabled' => isModEnabled('reception'));
	$o['thirdparty'] = array('label' => 'CfObjThirdparty', 'picto' => 'company', 'context' => 'thirdpartycard', 'page' => '/societe/card.php', 'idparam' => 'socid', 'element' => 'societe', 'table_element' => 'societe', 'table' => 'societe', 'editform' => true, 'createsave' => array('add'), 'editsave' => array('update'), 'enabled' => isModEnabled('societe'));
	$o['contact'] = array('label' => 'CfObjContact', 'picto' => 'contact', 'context' => 'contactcard', 'page' => '/contact/card.php', 'idparam' => 'id', 'element' => 'contact', 'table_element' => 'socpeople', 'table' => 'socpeople', 'editform' => true, 'createsave' => array('add'), 'editsave' => array('update'), 'enabled' => isModEnabled('societe'));
	$o['product'] = array('label' => 'CfObjProduct', 'picto' => 'product', 'context' => 'productcard', 'page' => '/product/card.php', 'idparam' => 'id', 'element' => 'product', 'table_element' => 'product', 'table' => 'product', 'editform' => true, 'createsave' => array('add'), 'editsave' => array('update'), 'enabled' => (isModEnabled('product') || isModEnabled('service')));
	$o['propal'] = array('label' => 'CfObjPropal', 'picto' => 'propal', 'context' => 'propalcard', 'page' => '/comm/propal/card.php', 'idparam' => 'id', 'element' => 'propal', 'table_element' => 'propal', 'table' => 'propal', 'editform' => false, 'createsave' => array('add'), 'editsave' => array(), 'enabled' => isModEnabled('propal'));
	$o['order'] = array('label' => 'CfObjOrder', 'picto' => 'order', 'context' => 'ordercard', 'page' => '/commande/card.php', 'idparam' => 'id', 'element' => 'commande', 'table_element' => 'commande', 'table' => 'commande', 'editform' => false, 'createsave' => array('add'), 'editsave' => array(), 'enabled' => isModEnabled('order'));
	$o['shipment'] = array('label' => 'CfObjShipment', 'picto' => 'dolly', 'context' => 'expeditioncard', 'page' => '/expedition/card.php', 'idparam' => 'id', 'element' => 'shipping', 'table_element' => 'expedition', 'table' => 'expedition', 'editform' => false, 'createsave' => array('add'), 'editsave' => array(), 'enabled' => isModEnabled('shipping'));
	$o['invoice'] = array('label' => 'CfObjInvoice', 'picto' => 'bill', 'context' => 'invoicecard', 'page' => '/compta/facture/card.php', 'idparam' => 'id', 'element' => 'facture', 'table_element' => 'facture', 'table' => 'facture', 'editform' => false, 'createsave' => array('add'), 'editsave' => array(), 'enabled' => isModEnabled('invoice'));
	$o['supplierorder'] = array('label' => 'CfObjSupplierOrder', 'picto' => 'supplier_order', 'context' => 'ordersuppliercard', 'page' => '/fourn/commande/card.php', 'idparam' => 'id', 'element' => 'order_supplier', 'table_element' => 'commande_fournisseur', 'table' => 'commande_fournisseur', 'editform' => false, 'createsave' => array('add'), 'editsave' => array(), 'enabled' => isModEnabled('supplier_order'));
	$o['supplierinvoice'] = array('label' => 'CfObjSupplierInvoice', 'picto' => 'supplier_invoice', 'context' => 'invoicesuppliercard', 'page' => '/fourn/facture/card.php', 'idparam' => 'id', 'element' => 'invoice_supplier', 'table_element' => 'facture_fourn', 'table' => 'facture_fourn', 'editform' => false, 'createsave' => array('add'), 'editsave' => array(), 'enabled' => isModEnabled('supplier_invoice'));
	$o['project'] = array('label' => 'CfObjProject', 'picto' => 'project', 'context' => 'projectcard', 'page' => '/projet/card.php', 'idparam' => 'id', 'element' => 'project', 'table_element' => 'projet', 'table' => 'projet', 'editform' => true, 'createsave' => array('add'), 'editsave' => array('update'), 'enabled' => isModEnabled('project'));

	return $o;
}

/**
 * Hook contexts of the cards handled by the module (module descriptor)
 *
 * @return string[] Contexts
 */
function cardfieldsHookContexts()
{
	return array('receptioncard', 'thirdpartycard', 'contactcard', 'productcard', 'propalcard', 'ordercard', 'expeditioncard', 'invoicecard', 'ordersuppliercard', 'invoicesuppliercard', 'projectcard');
}

/**
 * Key of the object of a hook context
 *
 * @param string $context Hook context
 * @return string Object key or ''
 */
function cardfieldsObjectOfContext($context)
{
	foreach (cardfieldsObjects() as $key => $def) {
		if ($def['context'] == $context) {
			return $key;
		}
	}
	return '';
}

/**
 * The creation form of products is set in the module Création rapide de produit when it is enabled:
 * its setup (mandatory and hidden fields of the creation form) is not duplicated here.
 *
 * @param string $objkey Object key
 * @param string $mode   Mode
 * @return bool True when this mode of this object is left to another module
 */
function cardfieldsModeDelegated($objkey, $mode)
{
	return ($objkey == 'product' && $mode == 'create' && isModEnabled('productquickcreate'));
}

/**
 * Normalize a label the same way as the script does (spaces, final colon or star, case)
 *
 * @param string $text Label
 * @return string Normalized label
 */
function cardfieldsNormLabel($text)
{
	$text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$text = str_replace("\xc2\xa0", ' ', $text);
	$text = trim(preg_replace('/\s+/u', ' ', $text));
	$text = preg_replace('/[\s:*]+$/u', '', $text);
	return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

/**
 * Fields written by hand for the reception card (immediate need: header of the reception on the terminal).
 *
 * @return array<string,array<string,mixed>> Fields
 */
function cardfieldsStaticReception()
{
	global $langs;

	$langs->loadLangs(array('main', 'other', 'receptions', 'sendings', 'products', 'stocks', 'orders', 'incoterm', 'projects', 'companies'));
	$t = function ($key) use ($langs) {
		return $langs->transnoentitiesnoconv($key);
	};
	$f = array();
	$f['ref_supplier'] = array('label' => $t('RefSupplier'),
		'modes' => array('create' => array('names' => array('ref_supplier')), 'edit' => array()),
		'post' => array('ref_supplier'), 'inline' => array('setref_supplier' => array('names' => array('ref_supplier'), 'back' => 'editref_supplier')));
	$f['fk_warehouse'] = array('label' => $t('Warehouse'), 'enabled' => isModEnabled('stock'),
		'modes' => array('create' => array('names' => array('fk_warehouse')), 'edit' => array(), 'view' => array('labels' => array($t('Warehouse')))),
		'post' => array('fk_warehouse'), 'empty' => array('', '0', '-1'), 'inline' => array('setwarehouse' => array('names' => array('warehouse_id'), 'back' => 'editwarehouse')));
	$f['projectid'] = array('label' => $t('Project'), 'enabled' => isModEnabled('project'),
		'modes' => array('create' => array('names' => array('projectid'))),
		'post' => array('projectid'), 'empty' => array('', '0', '-1'));
	$f['date_creation'] = array('label' => $t('DateCreation'),
		'modes' => array('view' => array('labels' => array($t('DateCreation')))), 'canrequire' => false);
	$f['date_reception'] = array('label' => $t('DateReception'),
		'modes' => array('create' => array('names' => array('date_reception')), 'edit' => array(), 'view' => array('labels' => array($t('DateReception')))),
		'post' => array('date_receptionday'), 'inline' => array('setdate_reception' => array('names' => array('recep_day'), 'back' => 'editdate_reception')));
	$f['date_delivery'] = array('label' => $t('DateDeliveryPlanned'),
		'modes' => array('create' => array('names' => array('date_delivery')), 'edit' => array(), 'view' => array('labels' => array($t('DateDeliveryPlanned')))),
		'post' => array('date_deliveryday'), 'inline' => array('setdate_livraison' => array('names' => array('liv_day'), 'back' => 'editdate_livraison')));
	$f['weight'] = array('label' => $t('Weight'),
		'modes' => array('create' => array('names' => array('weight')), 'edit' => array(), 'view' => array('labels' => array($t('Weight')))),
		'post' => array('weight'), 'inline' => array('settrueWeight' => array('names' => array('trueWeight'), 'back' => 'edittrueWeight')));
	// One row "Width x Height x Depth" in the creation form, three rows on the card
	$f['dimensions'] = array('label' => $t('Width').' × '.$t('Height').' × '.$t('Depth'),
		'modes' => array('create' => array('names' => array('sizeW', 'trueWidth')), 'edit' => array(), 'view' => array('labels' => array($t('Width'), $t('Height'), $t('Depth')))),
		'post' => array(array('sizeW', 'trueWidth'), array('sizeH', 'trueHeight'), array('sizeS', 'trueDepth')), 'postall' => true,
		'inline' => array('settrueWidth' => array('names' => array('trueWidth'), 'back' => 'edittrueWidth'), 'settrueHeight' => array('names' => array('trueHeight'), 'back' => 'edittrueHeight'), 'settrueDepth' => array('names' => array('trueDepth'), 'back' => 'edittrueDepth')));
	$f['volume'] = array('label' => $t('Volume'),
		'modes' => array('view' => array('labels' => array($t('Volume')))), 'canrequire' => false);
	$f['shipping_method_id'] = array('label' => $t('ReceptionMethod'),
		'modes' => array('create' => array('names' => array('shipping_method_id')), 'edit' => array(), 'view' => array('labels' => array($t('ReceptionMethod')))),
		'post' => array('shipping_method_id'), 'empty' => array('', '0', '-1'), 'inline' => array('setshipping_method_id' => array('names' => array('shipping_method_id'), 'back' => 'editshipping_method_id')));
	$f['tracking_number'] = array('label' => $t('TrackingNumber'),
		'modes' => array('create' => array('names' => array('tracking_number')), 'edit' => array(), 'view' => array('labels' => array($t('TrackingNumber')))),
		'post' => array('tracking_number'), 'inline' => array('settracking_number' => array('names' => array('tracking_number'), 'back' => 'edittracking_number')));
	$f['incoterm'] = array('label' => $t('IncotermLabel'), 'enabled' => isModEnabled('incoterm'),
		'modes' => array('create' => array('names' => array('incoterm_id', 'location_incoterms')), 'edit' => array(), 'view' => array('labels' => array($t('IncotermLabel')))),
		'post' => array('incoterm_id'), 'empty' => array('', '0', '-1'), 'inline' => array('set_incoterms' => array('names' => array('incoterm_id'), 'back' => 'editincoterm')));
	$f['model'] = array('label' => $t('Model'),
		'modes' => array('create' => array('names' => array('model'))), 'canrequire' => false);
	$f['note_public'] = array('label' => $t('NotePublic'),
		'modes' => array('create' => array('names' => array('note_public'))), 'post' => array('note_public'), 'html' => true);
	$f['note_private'] = array('label' => $t('NotePrivate'),
		'modes' => array('create' => array('names' => array('note_private'))), 'post' => array('note_private'), 'html' => true);
	$f['origin'] = array('label' => $t('SupplierOrder'),
		'modes' => array('view' => array('labels' => array($t('SupplierOrder'), $t('RefOrder')))), 'canrequire' => false);

	return $f;
}

/**
 * Fields required by Dolibarr itself, shown locked in the setup (never hidden, always mandatory)
 *
 * @param string $objkey Object key
 * @return array<string,array{label:string,modes:string[]}> Fields
 */
function cardfieldsCoreFields($objkey)
{
	global $langs;

	$core = array();
	if ($objkey == 'reception') {
		$langs->loadLangs(array('companies', 'orders'));
		$core['socid'] = array('label' => $langs->transnoentitiesnoconv('ThirdParty'), 'modes' => array('create'));
	}
	return $core;
}

/**
 * Fields of an object: written by hand (reception), extrafields, then the rows found by the survey.
 * Each field: label, source, modes (create/edit/view => locator: names, sel, labels), post, postall, empty, html,
 * canrequire, canhide, core (mode => true when Dolibarr already requires it), inline (save action => names, back).
 *
 * @param string $objkey   Object key
 * @param int    $objectid Id of the shown object (selectors of extrafield separators carry it on the card)
 * @return array<string,array<string,mixed>> Fields
 */
function cardfieldsFieldCatalog($objkey, $objectid = 0)
{
	$objects = cardfieldsObjects();
	if (empty($objects[$objkey])) {
		return array();
	}
	$fields = array();
	if ($objkey == 'reception') {
		foreach (cardfieldsStaticReception() as $key => $field) {
			if (isset($field['enabled']) && empty($field['enabled'])) {
				continue;
			}
			$field['source'] = 'static';
			$fields[$key] = $field;
		}
	}
	foreach (cardfieldsExtrafieldCatalog($objkey, $objectid) as $key => $field) {
		$fields[$key] = $field;
	}
	// Fields required by Dolibarr are shown apart (locked): the survey does not list them again
	$known = $fields;
	foreach (cardfieldsCoreFields($objkey) as $key => $core) {
		$known['core_'.$key] = array('label' => $core['label'], 'modes' => array_fill_keys($core['modes'], array()));
	}
	foreach (cardfieldsSurveyCatalog($objkey, $known) as $key => $field) {
		$fields[$key] = $field;
	}
	// Defaults
	foreach ($fields as $key => $field) {
		$fields[$key] = array_merge(array('post' => array(), 'postall' => false, 'empty' => array(''), 'html' => false, 'canrequire' => true, 'canhide' => true, 'core' => array(), 'inline' => array(), 'modes' => array()), $field);
	}
	return $fields;
}

/**
 * Extrafields of the object, with the visibility rules of Dolibarr for each mode.
 * Setting them here does not change their own setup (required, visibility).
 *
 * @param string $objkey   Object key
 * @param int    $objectid Id of the shown object
 * @return array<string,array<string,mixed>> Fields with key ef_<name>
 */
function cardfieldsExtrafieldCatalog($objkey, $objectid = 0)
{
	global $db, $langs;

	$objects = cardfieldsObjects();
	$def = $objects[$objkey];
	$out = array();
	if (empty($db)) {
		return $out;
	}
	require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
	$extrafields = new ExtraFields($db);
	$extrafields->fetch_name_optionals_label($def['table_element']);
	$attrs = (isset($extrafields->attributes[$def['table_element']]) ? $extrafields->attributes[$def['table_element']] : array());
	if (empty($attrs['label']) || !is_array($attrs['label'])) {
		return $out;
	}
	foreach ($attrs['label'] as $name => $label) {
		$enabled = 1;
		if (isset($attrs['enabled'][$name]) && (string) $attrs['enabled'][$name] !== '') {
			$enabled = (int) dol_eval((string) $attrs['enabled'][$name], 1, 1, '2');
		}
		if (empty($enabled)) {
			continue;
		}
		$visibility = 1;
		if (isset($attrs['list'][$name])) {
			$visibility = abs((int) dol_eval((string) $attrs['list'][$name], 1, 1, '2'));
		}
		$type = (string) (isset($attrs['type'][$name]) ? $attrs['type'][$name] : '');
		$isseparator = ($type == 'separate');
		if (!empty($attrs['langfile'][$name])) {
			$langs->load($attrs['langfile'][$name]);
		}
		$textlabel = $langs->transnoentitiesnoconv((string) $label);

		$sepsel = array('tr#trextrafieldseparator'.$name);
		if ($objectid > 0) {
			$sepsel[] = 'tr#trextrafieldseparator'.$name.'_'.((int) $objectid);
		}
		$modes = array();
		if (in_array($visibility, array(1, 3))) {
			$modes['create'] = array('sel' => ($isseparator ? $sepsel : array('tr.field_options_'.$name)));
		}
		if (in_array($visibility, array(1, 3, 4))) {
			// Modification form, or modification of the field alone on the consultation page (pencil)
			$modes['edit'] = ($def['editform'] ? array('sel' => ($isseparator ? $sepsel : array('tr.field_options_'.$name))) : array());
		}
		if (in_array($visibility, array(1, 3, 4, 5))) {
			$modes['view'] = array('sel' => ($isseparator ? $sepsel : array('td.'.$def['element'].'_extras_'.$name)));
		}
		if (!count($modes)) {
			continue;
		}
		$post = array('options_'.$name);
		if (in_array($type, array('date', 'datetime'))) {
			$post = array('options_'.$name.'day');
		}
		// A checkbox that is not ticked posts nothing: it cannot be told apart from a forgotten field
		$checkbox = in_array($type, array('boolean', 'checkbox', 'chkbxlst', 'radio'));
		$requirednative = !empty($attrs['required'][$name]);
		$field = array(
			'label' => $textlabel,
			'source' => 'extrafield',
			'modes' => $modes,
			'post' => ($isseparator ? array() : $post),
			'empty' => (in_array($type, array('link', 'select', 'sellist')) ? array('', '0', '-1') : array('')),
			'html' => true,
			'canrequire' => (!$isseparator && !$checkbox),
			'whynot' => ($isseparator ? 'CfSeparatorHideOnly' : ($checkbox ? 'CfCheckboxNoRequire' : '')),
			'canhide' => true,
			'core' => ($requirednative ? array('create' => true, 'edit' => true) : array()),
			'inline' => ($isseparator ? array() : array('update_extras' => array('names' => $post, 'back' => 'edit_extras', 'attribute' => $name))),
		);
		$out['ef_'.$name] = $field;
	}
	return $out;
}

/**
 * Rows recorded by the survey of the cards (table cardfields_survey), merged by label across the modes.
 * Rows already known (written by hand, extrafields) are left out.
 *
 * @param string $objkey Object key
 * @param array<string,array<string,mixed>> $known Fields already in the catalog
 * @return array<string,array<string,mixed>> Fields with key s<hash>
 */
function cardfieldsSurveyCatalog($objkey, $known)
{
	global $db, $conf;

	$out = array();
	if (empty($db)) {
		return $out;
	}
	// Labels and names already known, by mode
	$knownlabels = array();
	$knownnames = array();
	foreach ($known as $field) {
		foreach ($field['modes'] as $mode => $loc) {
			$knownlabels[$mode][cardfieldsNormLabel($field['label'])] = 1;
			foreach ((isset($loc['labels']) ? $loc['labels'] : array()) as $l) {
				$knownlabels[$mode][cardfieldsNormLabel($l)] = 1;
			}
			foreach ((isset($loc['names']) ? $loc['names'] : array()) as $n) {
				$knownnames[$mode][$n] = 1;
			}
		}
		foreach ((isset($field['post']) ? $field['post'] : array()) as $p) {
			foreach ((array) $p as $n) {
				foreach (cardfieldsModes() as $mode) {
					$knownnames[$mode][$n] = 1;
				}
			}
		}
	}

	$sql = "SELECT fieldkey, mode, label, data FROM ".$db->prefix()."cardfields_survey";
	$sql .= " WHERE entity = ".((int) $conf->entity)." AND objectkey = '".$db->escape($objkey)."'";
	$sql .= " ORDER BY position, rowid";
	$resql = $db->query($sql);
	if (!$resql) {
		return $out;	// Table not created yet
	}
	while ($obj = $db->fetch_object($resql)) {
		$mode = (string) $obj->mode;
		if (!in_array($mode, cardfieldsModes())) {
			continue;
		}
		$data = json_decode((string) $obj->data, true);
		if (!is_array($data)) {
			$data = array();
		}
		$names = (isset($data['names']) && is_array($data['names']) ? $data['names'] : array());
		$norm = cardfieldsNormLabel($obj->label);
		if (($norm !== '' && !empty($knownlabels[$mode][$norm])) || count(array_intersect($names, array_keys(isset($knownnames[$mode]) ? $knownnames[$mode] : array())))) {
			continue;
		}
		$key = (string) $obj->fieldkey;
		if (!isset($out[$key])) {
			// A row without label (ex. nature prospect / customer / supplier) is shown with the names of its inputs
			$shown = ((string) $obj->label !== '' ? (string) $obj->label : '('.implode(', ', $names).')');
			$out[$key] = array('label' => $shown, 'source' => 'survey', 'modes' => array(), 'post' => array(), 'empty' => array('', '-1'), 'html' => true, 'canrequire' => false, 'canhide' => true, 'core' => array(), 'inline' => array(), 'postbymode' => array());
		}
		$loc = array();
		if (count($names)) {
			$loc['names'] = $names;
		}
		if ($norm !== '') {
			$loc['labels'] = array((string) $obj->label);
		}
		$out[$key]['modes'][$mode] = $loc;
		if (in_array($mode, array('create', 'edit'))) {
			$out[$key]['postbymode'][$mode] = $names;
			if (!empty($data['canrequire']) && count($names)) {
				$out[$key]['canrequire'] = true;
				$out[$key]['canrequiremodes'][$mode] = true;
			}
			if (!empty($data['empty']) && is_array($data['empty'])) {
				$out[$key]['empty'] = array_values(array_unique(array_merge($out[$key]['empty'], array_map('strval', $data['empty']))));
			}
		}
		if (!empty($data['core'])) {
			$out[$key]['core'][$mode] = true;
		}
	}
	foreach ($out as $key => $field) {
		$post = array();
		foreach ($field['postbymode'] as $names) {
			$post = array_merge($post, $names);
		}
		$out[$key]['post'] = array_values(array_unique($post));
	}
	return $out;
}

/**
 * Can a field be mandatory / hidden in a mode of an object
 *
 * @param array<string,mixed> $field  Field of the catalog
 * @param string              $objkey Object key
 * @param string              $mode   create, edit or view
 * @param string              $use    'required' or 'hidden'
 * @return bool
 */
function cardfieldsCan($field, $objkey, $mode, $use)
{
	$objects = cardfieldsObjects();
	$def = $objects[$objkey];
	if (cardfieldsModeDelegated($objkey, $mode) || !isset($field['modes'][$mode])) {
		return false;
	}
	if (!empty($field['core'][$mode])) {
		return false;	// Locked: already mandatory, never hidden
	}
	if ($mode == 'view') {
		return ($use == 'hidden' && !empty($field['canhide']));
	}
	if ($mode == 'edit' && empty($def['editform'])) {
		// Modification field by field on the consultation page: only the check when the field is saved
		return ($use == 'required' && !empty($field['canrequire']) && !empty($field['inline']));
	}
	if ($use == 'hidden') {
		return !empty($field['canhide']);
	}
	if (empty($field['canrequire']) || !count($field['post'])) {
		return false;
	}
	if ($field['source'] == 'survey' && empty($field['canrequiremodes'][$mode])) {
		return false;
	}
	return true;
}

/**
 * Name of the setup constant of an object, a mode and a use
 *
 * @param string $objkey Object key
 * @param string $mode   Mode
 * @param string $use    'required' or 'hidden'
 * @return string Constant name
 */
function cardfieldsConstName($objkey, $mode, $use)
{
	return 'CARDFIELDS_'.strtoupper($objkey).'_'.strtoupper($mode).'_'.($use == 'required' ? 'REQUIRED' : 'HIDDEN');
}

/**
 * Fields set as mandatory or hidden for a mode of an object, restricted to what is allowed.
 * A field mandatory in a mode is never hidden in this mode.
 *
 * @param string $objkey  Object key
 * @param string $mode    Mode
 * @param string $use     'required' or 'hidden'
 * @param ?array<string,array<string,mixed>> $catalog Catalog (built when null)
 * @return string[] Field keys
 */
function cardfieldsConfigured($objkey, $mode, $use, $catalog = null)
{
	if ($catalog === null) {
		$catalog = cardfieldsFieldCatalog($objkey);
	}
	$read = function ($u) use ($objkey, $mode, $catalog) {
		$keys = array();
		foreach (array_filter(array_map('trim', explode(',', getDolGlobalString(cardfieldsConstName($objkey, $mode, $u))))) as $key) {
			if (isset($catalog[$key]) && cardfieldsCan($catalog[$key], $objkey, $mode, $u)) {
				$keys[] = $key;
			}
		}
		return $keys;
	};
	$required = $read('required');
	if ($use == 'required') {
		return $required;
	}
	return array_values(array_diff($read('hidden'), $required));
}

/**
 * Is a posted value filled
 *
 * @param string              $name  Posted name (name[] for an array)
 * @param array<string,mixed> $field Field
 * @return bool
 */
function cardfieldsPostedFilled($name, $field)
{
	if (substr($name, -2) == '[]') {
		$values = GETPOST(substr($name, 0, -2), 'array');
		if (!is_array($values)) {
			return false;
		}
		foreach ($values as $v) {
			if (!is_array($v) && !in_array(trim((string) $v), array('', '0', '-1'), true)) {
				return true;
			}
		}
		return false;
	}
	$value = GETPOST($name, !empty($field['html']) ? 'restricthtml' : 'alphanohtml');
	if (is_array($value)) {
		return (count(array_filter($value, function ($v) {
			return !is_array($v) && trim((string) $v) !== '';
		})) > 0);
	}
	$value = (string) $value;
	if (!empty($field['html'])) {
		$value = dol_string_nohtmltag($value);
	}
	$value = trim(str_replace("\xc2\xa0", ' ', $value));
	return ($value !== '' && !in_array($value, $field['empty'], true));
}

/**
 * Is a field filled in the posted form. post entries are names or arrays of alternative names (one filled is
 * enough); with postall, every entry must be filled, else one entry is enough.
 *
 * @param array<string,mixed> $field Field
 * @param ?string[]           $names Names to check instead of the post entries of the field
 * @return bool
 */
function cardfieldsFieldFilled($field, $names = null)
{
	$entries = ($names !== null ? $names : $field['post']);
	if (!count($entries)) {
		return true;
	}
	$all = ($names === null && !empty($field['postall']));
	$nbfilled = 0;
	foreach ($entries as $entry) {
		$ok = false;
		foreach ((array) $entry as $name) {
			if (cardfieldsPostedFilled($name, $field)) {
				$ok = true;
				break;
			}
		}
		if ($ok) {
			$nbfilled++;
		}
	}
	return ($all ? $nbfilled == count($entries) : $nbfilled > 0);
}

/**
 * Id of the last object of a type (links of the survey)
 *
 * @param string $objkey Object key
 * @return int Id or 0
 */
function cardfieldsLastObjectId($objkey)
{
	global $db;

	$objects = cardfieldsObjects();
	if (empty($objects[$objkey])) {
		return 0;
	}
	$sql = "SELECT MAX(rowid) as id FROM ".$db->prefix().$objects[$objkey]['table'];
	$sql .= " WHERE entity IN (".getEntity($objects[$objkey]['element']).")";
	$resql = $db->query($sql);
	if ($resql && ($obj = $db->fetch_object($resql))) {
		return (int) $obj->id;
	}
	return 0;
}
