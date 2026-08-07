<?php
/* Copyright (C) 2010-2012	Regis Houssin			    <regis.houssin@inodbox.com>
 * Copyright (C) 2010-2014	Laurent Destailleur			<eldy@users.sourceforge.net>
 * Copyright (C) 2012-2013	Christophe Battarel			<christophe.battarel@altairis.fr>
 * Copyright (C) 2012       Cédric Salvador     		<csalvador@gpcsolutions.fr>
 * Copyright (C) 2014		Florian Henry			    <florian.henry@open-concept.pro>
 * Copyright (C) 2014       Raphaël Doursenaud  		<rdoursenaud@gpcsolutions.fr>
 * Copyright (C) 2015-2016	Marcos García			    <marcosgdf@gmail.com>
 * Copyright (C) 2018-2024  Frédéric France				<frederic.france@free.fr>
 * Copyright (C) 2018		Ferran Marcet			    <fmarcet@2byte.es>
 * Copyright (C) 2024		Vincent Maury			    <vmaury@timgroup.fr>
 * Copyright (C) 2024-2025	MDW						    <mdeweerd@users.noreply.github.com>
 * Copyright (C) 2025		Nick Fragoulis
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
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * Need to have the following variables defined:
 * $object (invoice, order, ...)
 * $conf
 * $langs
 * $forceall (0 by default, 1 for supplier invoices/orders)
 */

require_once DOL_DOCUMENT_ROOT."/product/class/html.formproduct.class.php";

/**
 * @var CommonObject $this
 * @var CommonObject $object
 * @var Form $form
 * @var Societe $buyer
 * @var Translate $langs
 */

// Protection to avoid direct call of template
if (empty($object) || !is_object($object)) {
	print "Error: this template page cannot be called directly as an URL";
	exit;
}

'
@phan-var-force CommonObject $this
@phan-var-force CommonObject $object
@phan-var-force Societe $buyer
';

global $forceall, $forcetoshowtitlelines, $filtertype;

if (empty($forceall)) {
	$forceall = 0;
}

if (empty($filtertype)) {
	$filtertype = 0;
}

$formproduct = new FormProduct($object->db);

// Define colspan for the button 'Add'
$colspan = 3;


// Lines for extrafield
$objectline = new ReceptionLineBatch($this->db);

print "<!-- BEGIN PHP TEMPLATE reception/tpl/objectline_create.tpl.php -->\n";

$nolinesbefore = (count($this->lines) == 0 || $forcetoshowtitlelines);

if ($nolinesbefore) {
	print '<tr class="liste_titre nodrag nodrop">';
	if (getDolGlobalString('MAIN_VIEW_LINE_NUMBER')) {
		print '<td class="linecolnum center"></td>';
	}
	print '<td class="linecoldescription minwidth500imp">';
	print '<div id="add"></div><span class="hideonsmartphone">'.$langs->trans('AddNewLine').'</span>';
	print '</td>';
	print '<td class="linecolcostprice right">'.$langs->trans('BuyingPrice').'</td>';
	print '<td class="linecolqty right">'.$langs->trans('Qty').'</td>';
	print '<td class="linecolwarehouse right">'.$langs->trans('Warehouse').'</td>';

	if (getDolGlobalInt('PRODUCT_USE_UNITS')) {
		print '<td class="linecoluseunit left">';
		print '<span id="title_units">';
		print $langs->trans('Unit');
		print '</span></td>';
	}

	print '</tr>';
}

print '<tr class="pair nodrag nodrop nohoverpair'.(($nolinesbefore || $object->element == 'contrat') ? '' : ' liste_titre_create').'">';
$coldisplay = 0;

// Adds a line numbering column
if (getDolGlobalString('MAIN_VIEW_LINE_NUMBER')) {
	$coldisplay++;
	echo '<td class="bordertop nobottom linecolnum center"></td>';
}

// Product
$coldisplay++;
print '<td class="bordertop nobottom linecoldescription line minwidth500imp">';

// Predefined product/service
if (isModEnabled("product")) {
	// PROTO-B: standard Dolibarr boxes (free line + predefined), purchase-oriented
	echo '<span class="prod_entry_mode_free nowraponall">';
	echo '<label for="prod_entry_mode_free"><input type="radio" class="prod_entry_mode_free" name="prod_entry_mode" id="prod_entry_mode_free" value="free"'.(GETPOST('prod_entry_mode', 'aZ09') == 'free' ? ' checked' : '').'></label> ';
	$form->select_type_of_lines(GETPOSTISSET("type") ? GETPOST("type", 'alpha', 2) : -1, 'type', 1, 1, $forceall);
	echo '</span>';
	echo '<br>';
	echo '<span class="prod_entry_mode_predef nowraponall">';
	echo '<label for="prod_entry_mode_predef"><input type="radio" class="prod_entry_mode_predef" name="prod_entry_mode" id="prod_entry_mode_predef" value="predef"'.(GETPOST('prod_entry_mode', 'aZ09') != 'free' ? ' checked' : '').'></label> ';
	$statustoshow = -1;
	if (!empty($object->socid) && $object->socid > 0) {
		// Supplier combo like supplier orders: searches supplier ref + supplier barcode, autofills buying price
		$ajaxoptions = array('update' => array('cost_price' => 'unitprice'));
		$form->select_produits_fournisseurs($object->socid, GETPOST('idprodfournprice'), 'idprodfournprice', '', '', $ajaxoptions, 1, 1, 'minwidth100 maxwidth500 widthcentpercentminusx', $langs->trans("PredefinedProductsAndServices"));
	} else {
		$form->select_produits(GETPOSTINT('idprod'), 'idprod', $filtertype, getDolGlobalInt('PRODUIT_LIMIT_SIZE'), 0, $statustoshow, 2, '', 1, array(), 0, '1', 0, 'maxwidth500 widthcentpercentminusx');
	}
	echo '</span>';
	// Description editor (standard)
	print '<br>';
	require_once DOL_DOCUMENT_ROOT.'/core/class/doleditor.class.php';
	$doleditor = new DolEditor('dp_desc', GETPOST('dp_desc', 'restricthtml'), '', 100, 'dolibarr_details', '', false, true, getDolGlobalString('FCKEDITOR_ENABLE_DETAILS'), 2, '98%');
	$doleditor->Create();
	// Toggle radios like the standard interface
	print '<script>
jQuery(document).ready(function() {
	jQuery("#select_type").change(function() { if (jQuery(this).val() >= 0) { jQuery("#prod_entry_mode_free").prop("checked", true); } });
	jQuery("#search_idprodfournprice, #idprodfournprice, #search_idprod, #idprod").on("focus click", function() { jQuery("#prod_entry_mode_predef").prop("checked", true); });
});
</script>';
}


if (!empty($extrafields)) {
	$temps = $objectline->showOptionals($extrafields, 'create', array(), '', '', '1', 'line');

	if (!empty($temps)) {
		print '<div style="padding-top: 10px" id="extrafield_lines_area_create" name="extrafield_lines_area_create">';
		print $temps;
		print '</div>';
	}
}
print '</td>';

// Qty
$coldisplay++;
print '<td class="bordertop nobottom linecolcostprice right"><input type="text" size="6" name="cost_price" id="cost_price" class="flat right" value="'.(GETPOSTISSET("cost_price") ? GETPOST("cost_price", 'alpha', 2) : '').'">';
print '</td>';
print '<td class="bordertop nobottom linecolqty right"><input type="text" size="2" name="qty" id="qty" class="flat right" value="'.(GETPOSTISSET("qty") ? GETPOST("qty", 'alpha', 2) : 1).'">';
print '</td>';
print '<td class="bordertop nobottom linecolwarehouse right">';
print $formproduct->selectWarehouses(GETPOSTINT('entrepot_id') > 0 ? GETPOSTINT('entrepot_id') : 'ifone', 'entrepot_id', '', 1, 0, 0, '', 1);
print '</td>';

// Unit
if (getDolGlobalInt('PRODUCT_USE_UNITS')) {
	$coldisplay++;
	print '<td class="nobottom linecoluseunit">';
	print '</td>';
}

$coldisplay += $colspan;
print '<td class="bordertop nobottom linecoledit right valignmiddle" colspan="' . $colspan . '">';
print '<input type="submit" class="button button-add small" name="addline" id="addline" value="' . $langs->trans('Add') . '">';
print '</td>';
print '</tr>';

?>

<script>

/* JQuery for product free or predefined select */
jQuery(document).ready(function() {
	/* When changing predefined product, we reload list of supplier prices required for margin combo */
	$("#idprod").change(function()
	{
		console.log("#idprod change triggered");

		  /* To set focus */
		  if (jQuery('#idprod').val() > 0)
			{
			/* focus work on a standard textarea but not if field was replaced with CKEDITOR */
			jQuery('#dp_desc').focus();
			/* focus if CKEDITOR */
			if (typeof CKEDITOR == "object" && typeof CKEDITOR.instances != "undefined")
			{
				var editor = CKEDITOR.instances['dp_desc'];
				   if (editor) { editor.focus(); }
			}
			}
	});
});

</script>

<!-- END PHP TEMPLATE objectline_create.tpl.php -->
