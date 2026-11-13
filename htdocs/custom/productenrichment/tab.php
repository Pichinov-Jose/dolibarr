<?php
/* Copyright (C) 2026	Jose Martinez			<jose.martinez@pichinov.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * Enrichment tab of the product card: data stored for its EAN(s) and journal of what was applied
 */
$res = 0;
if (!$res && file_exists('../main.inc.php')) {
	$res = @include '../main.inc.php';
}
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/product.lib.php';
dol_include_once('/productenrichment/class/productenrichment.class.php');
dol_include_once('/productenrichment/lib/productenrichment_view.lib.php');

$langs->loadLangs(array('products', 'categories', 'productenrichment@productenrichment'));

$id = GETPOSTINT('id');
$ref = GETPOST('ref', 'alpha');
$object = new Product($db);
if ($id > 0 || $ref !== '') {
	$object->fetch($id, $ref);
}
if (!isModEnabled('productenrichment') || empty($object->id)) {
	accessforbidden();
}
$fieldtype = (!empty($ref) ? 'ref' : 'rowid');
restrictedArea($user, 'produit|service', $object->id, 'product&product', '', '', $fieldtype);

$pe = new ProductEnrichment($db);

/*
 * View
 */
llxHeader('', $langs->trans('PeEnrichment').' - '.$object->ref, '', '', 0, 0, '', '', '', 'mod-productenrichment page-tab');
print peViewCss();

$head = product_prepare_head($object);
print dol_get_fiche_head($head, 'peenrichment', $langs->trans($object->isService() ? 'Service' : 'Product'), -1, $object->picto);
$linkback = '<a href="'.DOL_URL_ROOT.'/product/list.php?restore_lastsearch_values=1&type='.((int) $object->type).'">'.$langs->trans('BackToList').'</a>';
dol_banner_tab($object, 'ref', $linkback, ($user->socid ? 0 : 1), 'ref');
print '<div class="underbanner clearboth"></div>';

// EANs of the product: its barcode and the barcodes of its supplier prices
$eans = array();
$main = ProductEnrichment::normalizeEan((string) $object->barcode);
if ($main !== '') {
	$eans[$main] = $langs->trans('PeVia_barcode');
}
$resql = $db->query("SELECT DISTINCT pfp.barcode FROM ".MAIN_DB_PREFIX."product_fournisseur_price as pfp WHERE pfp.fk_product = ".((int) $object->id)." AND pfp.barcode IS NOT NULL AND pfp.barcode <> ''");
while ($resql && ($obj = $db->fetch_object($resql))) {
	$e = ProductEnrichment::normalizeEan((string) $obj->barcode);
	if ($e !== '' && !isset($eans[$e])) {
		$eans[$e] = $langs->trans('PeVia_supplier');
	}
}

print '<div class="fichecenter">';
if (!count($eans)) {
	print '<div class="opacitymedium margintoponly">'.$langs->trans('PeProductNoEan').'</div>';
}
foreach ($eans as $e => $via) {
	$info = $pe->getCached($e);
	$morehtml = '<a href="'.dol_buildpath('/productenrichment/data.php', 1).'?ean='.urlencode($e).'">'.img_picto('', 'fa-database', 'class="pictofixedwidth"').$langs->trans('PeOpenData').'</a>';
	print load_fiche_titre('EAN '.dol_escape_htmltag($e).' <span class="opacitymedium small">('.$via.')</span>', $morehtml, '');
	if (!$info) {
		print '<div class="opacitymedium">'.$langs->trans('PeNeverEnriched').'</div><br>';
		continue;
	}
	$anom = ProductEnrichment::anomalies($info, array(array('id' => (int) $object->id, 'ref' => (string) $object->ref, 'label' => (string) $object->label, 'via' => 'barcode')));
	print '<table class="border centpercent tableforfield">';
	print '<tr><td class="titlefield">'.$langs->trans('PeSources').'</td><td>'.peViewSourceBadges($info).'</td></tr>';
	print '<tr><td>'.$langs->trans('PeCoherence').'</td><td>'.(count(array_diff($anom, array('ebay_rejected'))) ? peViewAnomalyBadges($anom) : '<span class="pe-badge pe-b-ok">'.$langs->trans('PeCoherent').'</span>').'</td></tr>';
	print '</table>';
	print peViewInfo($info);
	print '<br>';
}
print '</div>';

print dol_get_fiche_end();

// Buttons
print '<div class="tabsAction">';
if ($user->hasRight('produit', 'creer')) {
	print '<a class="butAction" href="'.DOL_URL_ROOT.'/product/card.php?id='.((int) $object->id).'&peopen=1">'.img_picto('', 'fa-magic', 'class="pictofixedwidth"').$langs->trans('PeEnrich').'</a>';
}
print '</div>';

// Journal of the enrichments of this product
print load_fiche_titre($langs->trans('PeHistory'), '', '');
print peViewJournal($db, (int) $object->id);

llxFooter();
$db->close();
