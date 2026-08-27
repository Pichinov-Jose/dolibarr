<?php
/* Copyright (C) 2026 Jose Martinez <jose.martinez@pichinov.com>
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
 * \file    ajax/updatepriceline.php
 * \ingroup advancedtakepos
 * \brief   Met a jour EN UNE REQUETE le prix unitaire TTC et la remise d'une ligne TakePOS.
 *          (Deux appels natifs chaines echouent sur le second ; un seul updateline regle tout.)
 *          Reproduit les controles du handler updateprice du coeur : permissions, prix minimum, code TVA.
 */

if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}

// Load Dolibarr environment
$res = 0;
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if ($i > 0 && !empty($tmp[$i - 1]) && $tmp[$i - 1] == '/' && $j > 0) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && !empty($tmp[$i]) && $tmp[$i] == '/') {
	$res = @include substr($tmp, 0, $i)."/main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';

global $conf, $db, $user, $mysoc, $langs;

top_httphead('application/json');
$out = array('ok' => false, 'error' => '');

$place = GETPOST('place', 'alpha');
$idline = GETPOSTINT('idline');
$number = price2num(GETPOST('number', 'alpha'), 'MU');
$remise = price2num(GETPOST('remise', 'alpha'), 2);
$term = isset($_SESSION['takeposterminal']) ? $_SESSION['takeposterminal'] : '';

if ($place === '' || $term === '' || $idline <= 0 || (float) $number <= 0 || (float) $remise < 0 || (float) $remise > 100) {
	$out['error'] = 'Bad parameters';
	echo json_encode($out);
	exit;
}
if (empty($user->id) || !$user->hasRight('takepos', 'run') || !$user->hasRight('takepos', 'editlines')) {
	$out['error'] = 'Forbidden';
	echo json_encode($out);
	exit;
}

$invoice = new Facture($db);
if ($invoice->fetch(0, '(PROV-POS'.$term.'-'.$place.')') <= 0 || (int) $invoice->statut != Facture::STATUS_DRAFT) {
	$out['error'] = 'Draft invoice not found';
	echo json_encode($out);
	exit;
}

$theline = null;
foreach ($invoice->lines as $line) {
	if ((int) $line->id == $idline) {
		$theline = $line;
		break;
	}
}
if ($theline === null) {
	$out['error'] = 'Line not found';
	echo json_encode($out);
	exit;
}
if (!$user->hasRight('takepos', 'editorderedlines') && (string) $theline->special_code == '4') {
	$out['error'] = 'Forbidden (ordered line)';
	echo json_encode($out);
	exit;
}

// Prix minimum (memes regles que le handler updateprice du coeur)
$vatratecleaned = $theline->tva_tx;
$reg = array();
if (preg_match('/^(.*)\s*\((.*)\)$/', (string) $theline->tva_tx, $reg)) {
	$vatratecleaned = trim($reg[1]);
}
$pu_ht = price2num((float) $number / (1 + ((float) $vatratecleaned / 100)), 'MU');
if ($theline->fk_product > 0) {
	$customer = new Societe($db);
	$customer->fetch($invoice->socid);
	$prod = new Product($db);
	$prod->fetch($theline->fk_product);
	$datap = $prod->getSellPrice($mysoc, $customer, 0);
	$price_min = $datap['price_min'];
	$usercancelpricemin = ((getDolGlobalString('MAIN_USE_ADVANCED_PERMS') && !$user->hasRight('produit', 'ignore_price_min_advance')) || (!getDolGlobalString('MAIN_USE_ADVANCED_PERMS') && !$user->hasRight('produit', 'ignore_price_min')));
	if ($usercancelpricemin && !empty($price_min) && ((float) $pu_ht * (1 - (float) $remise / 100) < (float) $price_min)) {
		$langs->load('products');
		$out['error'] = $langs->trans('CantBeLessThanMinPrice', price(price2num($price_min, 'MU'), 0, $langs, 0, 0, -1, $conf->currency));
		echo json_encode($out);
		exit;
	}
}

$vatratecode = $theline->tva_tx;
if (!empty($theline->vat_src_code) && strpos((string) $theline->tva_tx, '(') === false) {
	$vatratecode .= ' ('.$theline->vat_src_code.')';
}

// Un seul updateline : nouveau prix unitaire TTC + nouvelle remise (clone de l'appel du coeur).
$result = $invoice->updateline($theline->id, $theline->desc, $number, $theline->qty, $remise, $theline->date_start, $theline->date_end, $vatratecode, $theline->localtax1_tx, $theline->localtax2_tx, 'TTC', $theline->info_bits, $theline->product_type, $theline->fk_parent_line, 0, $theline->fk_fournprice, $theline->pa_ht, $theline->label, $theline->special_code, $theline->array_options, $theline->situation_percent, $theline->fk_unit);
if ($result > 0) {
	$out['ok'] = true;
} else {
	$out['error'] = $invoice->error ? $invoice->error : 'updateline failed';
}
echo json_encode($out);
