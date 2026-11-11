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
 * \file    ajax/resetline.php
 * \ingroup advancedtakepos
 * \brief   Bouton C de la caisse : remet la ligne selectionnee a ses valeurs d'ajout (quantite 1, prix et remise
 *          calcules comme a l'ajout du produit, hooks completeTakePosAddLine compris, description du produit).
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

global $conf, $db, $user, $mysoc, $langs, $hookmanager;

top_httphead('application/json');
$langs->load('advancedtakepos@advancedtakepos');
$out = array('ok' => false, 'error' => '');

$place = GETPOST('place', 'alpha');
$idline = GETPOSTINT('idline');
$term = isset($_SESSION['takeposterminal']) ? $_SESSION['takeposterminal'] : '';

if (getDolGlobalInt('ADVANCEDTAKEPOS_C_RESETS_LINE') != 1 || $place === '' || $term === '' || $idline <= 0) {
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
if (empty($theline->fk_product)) {
	$out['error'] = $langs->transnoentitiesnoconv('AdvTakeposResetFreeLine');
	echo json_encode($out);
	exit;
}

// Valeurs d'ajout : meme calcul que l'action addline de takepos/invoice.php.
$customer = new Societe($db);
$customer->fetch($invoice->socid);
$prod = new Product($db);
if ($prod->fetch($theline->fk_product) <= 0) {
	$out['error'] = 'Product not found';
	echo json_encode($out);
	exit;
}
$datap = $prod->getSellPrice($mysoc, $customer, 0);
$price_base_type = empty($datap['price_base_type']) ? 'HT' : $datap['price_base_type'];
$tva_tx = $datap['tva_tx'];
$tva_npr = (int) $datap['tva_npr'];
$newline = array(
	'description' => $prod->description,
	'price' => $datap['pu_ht'],
	'tva_tx' => $tva_tx,
	'localtax1_tx' => get_localtax($tva_tx, 1, $customer, $mysoc, $tva_npr),
	'localtax2_tx' => get_localtax($tva_tx, 2, $customer, $mysoc, $tva_npr),
	'remise_percent' => $customer->remise_percent,
	'price_ttc' => $datap['pu_ttc'],
	'array_options' => array(),
);
// Hooks de l'ajout de ligne (prix de reference + remise, niveau promo...) : la ligne retrouve sa presentation d'origine.
$hookmanager->initHooks(array('takeposinvoice'));
$parameters = array('prod' => $prod, 'line' => $newline);
$action = 'addline';
$reshook = $hookmanager->executeHooks('completeTakePosAddLine', $parameters, $invoice, $action);
if ($reshook < 0) {
	$out['error'] = $hookmanager->error ? $hookmanager->error : 'Hook error';
	echo json_encode($out);
	exit;
}
if (empty($reshook) && !empty($hookmanager->resArray)) {
	$newline = $hookmanager->resArray;
}

$pu = ($price_base_type == 'TTC') ? $newline['price_ttc'] : $newline['price'];
$result = $invoice->updateline($theline->id, $newline['description'], $pu, 1, (float) $newline['remise_percent'], $theline->date_start, $theline->date_end, $newline['tva_tx'], $newline['localtax1_tx'], $newline['localtax2_tx'], $price_base_type, $theline->info_bits, $theline->product_type, $theline->fk_parent_line, 0, $theline->fk_fournprice, $theline->pa_ht, $theline->label, $theline->special_code, $theline->array_options, $theline->situation_percent, $theline->fk_unit);
if ($result > 0) {
	$out['ok'] = true;
} else {
	$out['error'] = $invoice->error ? $invoice->error : 'updateline failed';
}
echo json_encode($out);
